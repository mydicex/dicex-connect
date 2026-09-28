<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security alerts built on WordPress's own hooks.
 *
 * Deliberately not built on Wordfence or All In One WP Security: their event
 * hooks are either undocumented internals or absent, so anything resting on
 * them breaks when those plugins update. These four are core WordPress, work on
 * a site with no security plugin at all, and have been stable for years:
 *
 *   wp_login_failed( $username, $error )     — $error added in WP 5.4
 *   wp_login( $user_login, $user )
 *   user_register( $user_id, $userdata )     — $userdata added in WP 5.8
 *   after_password_reset( $user, $new_pass )
 *
 * Only the parameters that have always been there are used, so the callbacks
 * are safe on older WordPress too.
 *
 * Note on after_password_reset: WordPress fires it only for the "lost password"
 * flow. A password changed from inside a profile goes through profile_update
 * instead, which is a different event and is not covered here.
 */
class Dicex_Connect_Integration_Core_Security extends Dicex_Connect_Integration_Base {

	protected $slug = 'core-security';

	/** Default failures from one address before a warning; the card can change it. */
	const FAILED_THRESHOLD = 5;

	/** How long that counter lives: 15 minutes, written out so the constant does not depend on WordPress being loaded yet. */
	const FAILED_WINDOW = 900;

	/** Keeps a hostile username from turning into a very long SMS. */
	const MAX_LOGIN_LENGTH = 60;

	protected function register_hooks() {
		$events = $this->enabled_events();

		// Each event is hooked only when it is switched on, so an unwanted one
		// costs nothing at all.
		if ( in_array( 'failed_login', $events, true ) ) {
			add_action( 'wp_login_failed', array( $this, 'on_login_failed' ) );
		}

		if ( in_array( 'admin_login', $events, true ) ) {
			add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		}

		if ( in_array( 'new_user', $events, true ) ) {
			add_action( 'user_register', array( $this, 'on_user_register' ) );
		}

		if ( in_array( 'password_reset', $events, true ) ) {
			add_action( 'after_password_reset', array( $this, 'on_password_reset' ), 10, 2 );
		}
	}

	/**
	 * @return array
	 */
	private function enabled_events() {
		$settings = $this->settings();

		return ( isset( $settings['security_events'] ) && is_array( $settings['security_events'] ) )
			? $settings['security_events']
			: array();
	}

	/**
	 * One alert per IP per window, sent on the attempt that crosses the
	 * threshold. Without that, a brute-force run would send a message per
	 * guess — which is the attack doing the site owner's texting for them.
	 *
	 * @param string $username The login that was tried.
	 */
	public function on_login_failed( $username ) {
		$ip = $this->client_ip();

		if ( '' === $ip ) {
			return;
		}

		$threshold = $this->failed_threshold();
		$key       = 'dicex_connect_failed_' . md5( $ip );
		$stored    = get_transient( $key );

		/*
		 * The window keeps a count and whether it has already warned. Firing on
		 * "count equals threshold" was wrong: attempts already on the clock, or a
		 * threshold lowered afterwards, left the count past the mark and the warning
		 * never came. Now it fires the moment the count reaches or passes it, once.
		 */
		$state = is_array( $stored )
			? $stored
			: array(
				'count'   => is_numeric( $stored ) ? (int) $stored : 0,
				'warned'  => false,
			);

		$state['count'] = (int) $state['count'] + 1;
		$warn           = ( $state['count'] >= $threshold && empty( $state['warned'] ) );

		if ( $warn ) {
			$state['warned'] = true;
		}

		set_transient( $key, $state, self::FAILED_WINDOW );

		/*
		 * Counting is worth seeing in the log, otherwise somebody who tries one wrong
		 * password and gets nothing cannot tell "not there yet" from "broken".
		 * Logging stops once the warning has gone out, so a brute-force run cannot
		 * fill the log with its own noise.
		 */
		if ( ! $state['warned'] || $warn ) {
			Dicex_Connect_Logger::log( sprintf( 'LOGIN FAILED (%d/%d) from %s', $state['count'], $threshold, $ip ) );
		}

		if ( ! $warn ) {
			return;
		}

		$this->fire(
			sprintf(
				/* translators: %s: how many failed attempts were counted */
				_n(
					'%s failed login in a row',
					'%s failed logins in a row',
					$state['count'],
					'dicex-connect'
				),
				number_format_i18n( $state['count'] )
			),
			$username
		);
	}

	/**
	 * How many failures from one address it takes before a warning goes out.
	 *
	 * @return int At least one.
	 */
	private function failed_threshold() {
		$settings = $this->settings();
		$chosen   = isset( $settings['failed_threshold'] ) ? (int) $settings['failed_threshold'] : self::FAILED_THRESHOLD;

		return max( 1, $chosen );
	}

	/**
	 * @param string  $user_login
	 * @param WP_User $user
	 */
	public function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		// Only an administrator signing in is worth an alert.
		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) ) {
			return;
		}

		$this->fire(
			__( 'Administrator signed in', 'dicex-connect' ),
			$user->user_login,
			Dicex_Connect_Recipients::get_user_number( $user->ID )
		);
	}

	/**
	 * @param int $user_id
	 */
	public function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->fire(
			__( 'New user registered', 'dicex-connect' ),
			$user->user_login,
			Dicex_Connect_Recipients::get_user_number( $user_id )
		);
	}

	/**
	 * @param WP_User $user     The user whose password was reset.
	 * @param string  $new_pass The new password — never read, never sent.
	 */
	public function on_password_reset( $user, $new_pass = '' ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->fire(
			__( 'Password reset', 'dicex-connect' ),
			$user->user_login,
			Dicex_Connect_Recipients::get_user_number( $user->ID )
		);
	}

	/**
	 * @param string $event          Human-readable event name for the {event} tag.
	 * @param string $user_login     Login involved; may be attacker-supplied.
	 * @param string $subject_number Number of the person the event is about, when there is one.
	 */
	private function fire( $event, $user_login, $subject_number = '' ) {
		$this->notify(
			array(
				'event'      => $event,
				'user_login' => $this->safe_login( $user_login ),
				'ip'         => $this->client_ip(),
			),
			$subject_number
		);
	}

	/**
	 * A failed-login username comes straight from whoever is knocking, so it is
	 * sanitized and cut short before it is allowed anywhere near a message.
	 *
	 * @param string $user_login
	 * @return string
	 */
	private function safe_login( $user_login ) {
		$user_login = sanitize_text_field( (string) $user_login );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $user_login, 0, self::MAX_LOGIN_LENGTH );
		}

		return substr( $user_login, 0, self::MAX_LOGIN_LENGTH );
	}

	/**
	 * @return string Client IP, or '' when it cannot be established.
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for security alerts and for counting
		 * failed logins.
		 *
		 * REMOTE_ADDR is the only address a visitor cannot forge, so it is what
		 * this plugin trusts by default. A site behind a CDN or reverse proxy
		 * sees the proxy's address instead and can supply the real one here.
		 * Reading X-Forwarded-For automatically would let anyone put any address
		 * in the alert — and reset somebody else's failed-login counter — so it
		 * is left as a deliberate opt-in.
		 *
		 * @param string $ip Address from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'dicex_connect_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
