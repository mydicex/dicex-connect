<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time login codes: issuing, storing and checking them.
 *
 * Core layer — this class knows nothing about forms, wp-login.php or sending.
 * It hands back a code for somebody else to deliver, and later says whether a
 * submitted code was the right one.
 *
 * Two records, deliberately separate:
 *
 *   the session  who is half way through signing in. Lives fifteen minutes and
 *                survives the code expiring, which is the only way a new code
 *                can be sent to the right person after the first one runs out.
 *   the code     the hash of the digits themselves. Lives two minutes.
 *
 * How a code is kept safe:
 *
 *   - The code itself is never stored. Only a hash of it is, checked with
 *     wp_check_password(), so a database leak does not hand over live codes.
 *   - Both records are addressed by one random 32-character token, not by user
 *     ID or phone number, so one visitor cannot reach another's pending login.
 *   - Five wrong guesses end the attempt, and so do three resends.
 *   - A user cannot be sent a code more than once a minute, whichever session
 *     asks — the limit is per user, not per session, so starting over does not
 *     reset it.
 */
class Dicex_Connect_Otp {

	/*
	 * How long a code is now comes from Dicex_Connect_Send_Options, because the
	 * admin can change it. This constant remains as the value anything reading it
	 * before the setting existed would have got.
	 */
	const LENGTH          = 6;
	const CODE_TTL        = 120;
	const SESSION_TTL     = 900;
	const MAX_ATTEMPTS    = 5;
	const MAX_RESENDS     = 3;
	const RESEND_INTERVAL = 60;

	const SESSION_PREFIX = 'dicex_connect_login_';
	const CODE_PREFIX    = 'dicex_connect_otp_';
	const RATE_PREFIX    = 'dicex_connect_otp_rl_';

	/**
	 * Opens a pending login for a user.
	 *
	 * @param int $user_id
	 * @return string|WP_Error The token the form carries.
	 */
	public static function start( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'dicex_connect_otp_no_user', __( 'Invalid user.', 'dicex-connect' ) );
		}

		$token = wp_generate_password( 32, false );

		set_transient(
			self::SESSION_PREFIX . $token,
			array(
				'user_id' => $user_id,
				'sends'   => 0,
			),
			self::SESSION_TTL
		);

		return $token;
	}

	/**
	 * Creates a code for a pending login, replacing any code already issued for
	 * it.
	 *
	 * @param string $token
	 * @return array|WP_Error array( 'code', 'expires', 'resend_at', 'user_id' )
	 */
	public static function issue_code( $token ) {
		$token   = self::clean_token( $token );
		$session = ( '' === $token ) ? false : get_transient( self::SESSION_PREFIX . $token );

		if ( ! is_array( $session ) || empty( $session['user_id'] ) ) {
			return new WP_Error(
				'dicex_connect_otp_no_session',
				__( 'This sign-in has expired. Please start again.', 'dicex-connect' )
			);
		}

		if ( (int) $session['sends'] >= self::MAX_RESENDS ) {
			return new WP_Error(
				'dicex_connect_otp_too_many',
				__( 'Too many codes have been sent for this sign-in. Please start again.', 'dicex-connect' )
			);
		}

		$user_id = (int) $session['user_id'];
		$waiting = get_transient( self::RATE_PREFIX . $user_id );

		if ( false !== $waiting ) {
			$seconds = max( 1, (int) $waiting - time() );

			return new WP_Error(
				'dicex_connect_otp_too_soon',
				sprintf(
					/* translators: %s: number of seconds to wait */
					__( 'Wait %s seconds before asking for a new code.', 'dicex-connect' ),
					number_format_i18n( $seconds )
				)
			);
		}

		$code      = self::random_code();
		$expires   = time() + self::CODE_TTL;
		$resend_at = time() + self::RESEND_INTERVAL;

		set_transient(
			self::CODE_PREFIX . $token,
			array(
				'hash'     => wp_hash_password( $code ),
				'attempts' => 0,
				'expires'  => $expires,
			),
			self::CODE_TTL
		);

		$session['sends'] = (int) $session['sends'] + 1;
		set_transient( self::SESSION_PREFIX . $token, $session, self::SESSION_TTL );

		set_transient( self::RATE_PREFIX . $user_id, $resend_at, self::RESEND_INTERVAL );

		return array(
			'code'      => $code,
			'expires'   => $expires,
			'resend_at' => $resend_at,
			'user_id'   => $user_id,
		);
	}

	/**
	 * What the screen needs to draw a countdown: when this code dies and when a
	 * new one may be asked for.
	 *
	 * @param string $token
	 * @return array array( 'expires', 'resend_at', 'sends_left' ); zeros when there is nothing pending.
	 */
	public static function status( $token ) {
		$token   = self::clean_token( $token );
		$session = ( '' === $token ) ? false : get_transient( self::SESSION_PREFIX . $token );
		$code    = ( '' === $token ) ? false : get_transient( self::CODE_PREFIX . $token );

		if ( ! is_array( $session ) ) {
			return array(
				'expires'    => 0,
				'resend_at'  => 0,
				'sends_left' => 0,
				'channel'    => '',
			);
		}

		$resend_at = get_transient( self::RATE_PREFIX . (int) $session['user_id'] );

		return array(
			'expires'    => is_array( $code ) && isset( $code['expires'] ) ? (int) $code['expires'] : 0,
			'resend_at'  => false === $resend_at ? time() : (int) $resend_at,
			'sends_left' => max( 0, self::MAX_RESENDS - (int) $session['sends'] ),
			'channel'    => isset( $session['channel'] ) ? (string) $session['channel'] : '',
		);
	}

	/**
	 * Remembers which channel carried the code, so the screen asking for it can
	 * say where to look. Written after the send, because until then nobody knows
	 * which channel in the chain will answer.
	 *
	 * @param string $token
	 * @param string $channel Channel key, or '' to record nothing.
	 * @return void
	 */
	public static function remember_channel( $token, $channel ) {
		$token   = self::clean_token( $token );
		$channel = strtolower( trim( (string) $channel ) );

		if ( '' === $token || ! Dicex_Connect_Lines::is_supported( $channel ) ) {
			return;
		}

		$session = get_transient( self::SESSION_PREFIX . $token );

		if ( ! is_array( $session ) ) {
			return;
		}

		$session['channel'] = $channel;

		// Keep the remaining life the session already had rather than extending
		// it: this is a note about the login, not a reason for it to live longer.
		set_transient( self::SESSION_PREFIX . $token, $session, self::SESSION_TTL );
	}

	/**
	 * Checks a submitted code against a pending login.
	 *
	 * Every failure answers with the same message, so nothing about the token —
	 * whether it exists, whose it is, how many tries are left — leaks out.
	 *
	 * @param string $token
	 * @param string $code
	 * @return int|WP_Error User ID on success.
	 */
	public static function verify( $token, $code ) {
		$token   = self::clean_token( $token );
		$session = ( '' === $token ) ? false : get_transient( self::SESSION_PREFIX . $token );
		$record  = ( '' === $token ) ? false : get_transient( self::CODE_PREFIX . $token );

		$failure = new WP_Error(
			'dicex_connect_otp_invalid',
			__( 'That code is wrong or has expired.', 'dicex-connect' )
		);

		if ( ! is_array( $session ) || empty( $session['user_id'] ) || ! is_array( $record ) || empty( $record['hash'] ) ) {
			return $failure;
		}

		// Persian digits are perfectly normal to type into a code box.
		$code = strtr( trim( (string) $code ), Dicex_Connect_Mobile::DIGIT_MAP );

		if ( ! wp_check_password( $code, $record['hash'] ) ) {
			$record['attempts']++;

			if ( $record['attempts'] >= self::MAX_ATTEMPTS ) {
				self::discard( $token );
			} else {
				// Keep whatever life the record had left; a wrong guess must not
				// extend the window it is valid for.
				$remaining = max( 1, (int) $record['expires'] - time() );
				set_transient( self::CODE_PREFIX . $token, $record, $remaining );
			}

			return $failure;
		}

		$user_id = (int) $session['user_id'];
		self::discard( $token );

		return $user_id;
	}

	/**
	 * @param string $token
	 */
	public static function discard( $token ) {
		$token = self::clean_token( $token );

		if ( '' !== $token ) {
			delete_transient( self::CODE_PREFIX . $token );
			delete_transient( self::SESSION_PREFIX . $token );
		}
	}

	/**
	 * @param string $token
	 * @return string A token shaped the way wp_generate_password() makes them, or ''.
	 */
	private static function clean_token( $token ) {
		$token = sanitize_text_field( (string) $token );

		return preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * @return string A zero-padded numeric code.
	 */
	private static function random_code() {
		$length = Dicex_Connect_Send_Options::code_length();
		$max    = (int) str_repeat( '9', $length );

		/*
		 * Padding is what keeps a short draw the right length: wp_rand( 0, 999999 )
		 * can return 42, and a six-digit code that arrives as "42" is not a
		 * six-digit code. Leading zeros are as valid as any other digit here.
		 */
		return str_pad( (string) wp_rand( 0, $max ), $length, '0', STR_PAD_LEFT );
	}
}
