<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-step login over DiceX.
 *
 * Two modes, chosen on the card:
 *
 *   second_factor  the usual username and password, then a code by SMS
 *   passwordless   a mobile number and a code, no password at all
 *   both           the password form also offers "sign in with your mobile"
 *
 * The interception follows the pattern the official WordPress Two-Factor plugin
 * uses, because this is the one place where getting it wrong locks people out
 * of their own site: the `authenticate` filter switches off `send_auth_cookies`
 * before WordPress can issue one, and then `wp_login` — which runs after the
 * password was accepted — destroys the session WordPress had already opened,
 * clears any cookie, and renders the code step instead of finishing the login.
 *
 * Escape hatch: define DICEX_CONNECT_DISABLE_LOGIN_OTP as true in wp-config.php
 * and the whole module stands down. That is the way back in if the gateway is
 * unreachable or somebody has lost their phone, and it is what makes it safe
 * for a failed send to refuse the login rather than wave it through.
 */
class Dicex_Connect_Login {

	const SLUG          = 'otp-login';
	const ACTION_VERIFY = 'dicex_otp';
	const ACTION_MOBILE = 'dicex_mobile';
	const ACTION_RESEND = 'dicex_resend';
	const NONCE_ACTION  = 'dicex_connect_login_otp';

	/**
	 * The session WordPress opened during the password step, so exactly that one
	 * can be destroyed — rather than signing the user out of every other device.
	 *
	 * @var string
	 */
	private $password_session_token = '';

	public function __construct() {
		if ( defined( 'DICEX_CONNECT_DISABLE_LOGIN_OTP' ) && DICEX_CONNECT_DISABLE_LOGIN_OTP ) {
			return;
		}

		if ( ! Dicex_Connect_Integration_Registry::is_enabled( self::SLUG ) ) {
			return;
		}

		/*
		 * Nothing this card is set to send over can send. Registering the hooks
		 * anyway would interrupt every sign-in to deliver a code that cannot
		 * leave the building, and this module refuses a login when a code does
		 * not arrive — which is correct for a gateway that is down and a lockout
		 * when the configuration itself is impossible.
		 *
		 * Standing down is the safe half of that choice: the site falls back to
		 * an ordinary password login, the card says why on the Integrations tab,
		 * and nobody has to edit wp-config.php from another machine.
		 */
		if ( empty( $this->usable_channels() ) ) {
			return;
		}

		$mode = $this->mode();

		if ( 'second_factor' === $mode || 'both' === $mode ) {
			add_filter( 'authenticate', array( $this, 'hold_auth_cookies' ), 31 );
			add_action( 'wp_login', array( $this, 'interrupt_login' ), PHP_INT_MAX, 2 );
		}

		if ( 'passwordless' === $mode || 'both' === $mode ) {
			add_action( 'login_form', array( $this, 'render_mobile_link' ) );
			add_action( 'login_form_' . self::ACTION_MOBILE, array( $this, 'handle_mobile_step' ) );
		}

		add_action( 'login_form_' . self::ACTION_VERIFY, array( $this, 'handle_verify_step' ) );
		add_action( 'login_form_' . self::ACTION_RESEND, array( $this, 'handle_resend_step' ) );
		add_filter( 'login_message', array( $this, 'wrong_door_message' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	private function settings() {
		return Dicex_Connect_Integration_Registry::get_settings( self::SLUG );
	}

	private function mode() {
		$settings = $this->settings();

		return isset( $settings['login_mode'] ) ? $settings['login_mode'] : 'second_factor';
	}

	/**
	 * Whether a code could actually reach this person right now.
	 *
	 * Two questions the plugin can answer on its own: is there a channel that can
	 * send at all, and is this person's number one the gateway could be asked to
	 * deliver to. Whether it then succeeds is the gateway's to answer.
	 *
	 * @param WP_User $user
	 * @return bool
	 */
	private function can_deliver_to( $user ) {
		if ( empty( $this->usable_channels() ) ) {
			return false;
		}

		$number = Dicex_Connect_Recipients::get_user_number( $user->ID );

		return Dicex_Connect_Mobile::is_valid( Dicex_Connect_Mobile::normalize( $number ) );
	}

	/**
	 * The card's channels, narrowed to the ones that could actually carry a code.
	 *
	 * @return array
	 */
	private function usable_channels() {
		$settings = $this->settings();
		$channels = ( isset( $settings['channels'] ) && is_array( $settings['channels'] ) && ! empty( $settings['channels'] ) )
			? $settings['channels']
			: array( 'sms' );

		return Dicex_Connect_Sender::sendable( $channels );
	}

	/**
	 * Everyone with a mobile number on their profile gets the second step.
	 * Somebody without one signs in exactly as before, so switching this on can
	 * never lock a site's own users out.
	 */
	private function requires_otp( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return '' !== Dicex_Connect_Recipients::get_user_number( $user->ID );
	}

	/* ---------------------------------------------------------------------
	 * Second factor
	 * ------------------------------------------------------------------ */

	/**
	 * Runs after WordPress has accepted the password. Stopping the cookie here
	 * is what makes the interruption real — by the time wp_login fires, the
	 * cookie would already have gone out.
	 *
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null Unchanged.
	 */
	public function hold_auth_cookies( $user ) {
		if ( $this->requires_otp( $user ) ) {
			add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
			add_action( 'set_logged_in_cookie', array( $this, 'capture_session_token' ), 10, 6 );
		}

		return $user;
	}

	/**
	 * wp_set_auth_cookie() opens a session before it checks send_auth_cookies,
	 * so one exists even though no cookie was sent. This action fires before
	 * that check and carries the token, which is the only way to destroy that
	 * one session and leave the user's other devices alone.
	 *
	 * @param string $cookie
	 * @param int    $expire
	 * @param int    $expiration
	 * @param int    $user_id
	 * @param string $scheme
	 * @param string $token Session token; absent on very old WordPress.
	 */
	public function capture_session_token( $cookie, $expire, $expiration, $user_id, $scheme, $token = '' ) {
		$this->password_session_token = (string) $token;
	}

	/**
	 * @param string  $user_login
	 * @param WP_User $user
	 */
	public function interrupt_login( $user_login, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( ! $this->requires_otp( $user ) ) {
			// The commonest reason the second step never appears, and previously the
			// most invisible one.
			Dicex_Connect_Logger::log( 'LOGIN OTP SKIPPED: no mobile number on the profile of ' . $user->user_login );

			return;
		}

		/*
		 * Asked before the session is touched, and that ordering is the whole
		 * point. Everything below this line takes the sign-in apart in order to
		 * rebuild it around a code; if the code was never going to arrive, taking
		 * it apart is how somebody ends up locked out of their own site with the
		 * plugin's own settings as the cause.
		 *
		 * Only the failures this plugin can see in advance count here — a channel
		 * the region does not carry, a channel with no line, a number with no
		 * country code. A gateway that refuses a well-formed request is a
		 * different thing and still fails closed below.
		 */
		if ( ! $this->can_deliver_to( $user ) ) {
			Dicex_Connect_Logger::log(
				'LOGIN OTP SKIPPED: nothing could carry a code to ' . $user->user_login . ' with the current settings'
			);

			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user->ID );

		if ( '' !== $this->password_session_token ) {
			$manager->destroy( $this->password_session_token );
		} else {
			// Older WordPress did not pass the token; better to end every session
			// than to leave a half-authenticated one open.
			$manager->destroy_all();
		}

		wp_clear_auth_cookie();

		/*
		 * The code step can only be drawn on wp-login.php. A sign-in from a theme
		 * or from WooCommerce's account page fires wp_login somewhere those
		 * functions do not exist, so the login is refused and the person is sent
		 * to the real login page — never quietly let through without the second
		 * step, and never a fatal error on somebody's account page.
		 */
		if ( ! function_exists( 'login_header' ) ) {
			wp_safe_redirect( add_query_arg( 'dicex_otp_required', '1', wp_login_url() ) );
			exit;
		}

		$token = Dicex_Connect_Otp::start( $user->ID );

		if ( is_wp_error( $token ) ) {
			$this->render_form( '', $token->get_error_message(), true );
		}

		$sent = $this->deliver_code( $token );

		if ( is_wp_error( $sent ) ) {
			$message = $sent->get_error_message();
			$data    = $sent->get_error_data();

			// An administrator is the person who can fix a gateway refusal, so
			// they get to read it rather than hunt for it in the log.
			if ( isset( $data['reason'] ) && user_can( $user, 'manage_options' ) ) {
				$message .= ' ' . $data['reason'];
			}

			$this->render_form( '', $message, true );
		}

		$this->render_form( $token );
	}

	/**
	 * Explains the redirect above, on the page it lands on.
	 *
	 * @param string $message
	 * @return string
	 */
	public function wrong_door_message( $message ) {
		if ( empty( $_GET['dicex_otp_required'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			return $message;
		}

		return $message . '<p class="message">' .
			esc_html__( 'You need to confirm an SMS code to sign in to this account. Please sign in from this page.', 'dicex-connect' ) .
			'</p>';
	}

	/* ---------------------------------------------------------------------
	 * Passwordless
	 * ------------------------------------------------------------------ */

	public function render_mobile_link() {
		printf(
			'<p class="dicex-connect-login-alt"><a href="%1$s">%2$s</a></p>',
			esc_url( add_query_arg( 'action', self::ACTION_MOBILE, wp_login_url() ) ),
			esc_html__( 'Sign in with your mobile number', 'dicex-connect' )
		);
	}

	public function handle_mobile_step() {
		if ( ! $this->is_post() ) {
			$this->render_mobile_form();
		}

		check_admin_referer( self::NONCE_ACTION );

		// normalize() keeps digits only, which is stricter than any sanitizer.
		$mobile  = isset( $_POST['dicex_mobile'] ) ? Dicex_Connect_Mobile::normalize( wp_unslash( $_POST['dicex_mobile'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_id = Dicex_Connect_Mobile::is_valid( $mobile ) ? $this->user_by_mobile( $mobile ) : 0;

		/*
		 * An unknown number gets exactly the same screen as a known one, and
		 * nothing is sent. Answering differently would turn this form into a way
		 * of asking the site which mobile numbers have accounts.
		 */
		if ( $user_id <= 0 ) {
			$this->render_form( '' );
		}

		$token = Dicex_Connect_Otp::start( $user_id );

		if ( is_wp_error( $token ) ) {
			$this->render_mobile_form( $token->get_error_message() );
		}

		$sent = $this->deliver_code( $token );

		if ( is_wp_error( $sent ) ) {
			$this->render_mobile_form( $sent->get_error_message() );
		}

		$this->render_form( $token );
	}

	/**
	 * @param string $mobile Normalized number.
	 * @return int User ID, or 0 when there is no single unambiguous match.
	 */
	private function user_by_mobile( $mobile ) {
		$users = get_users(
			array(
				'meta_key'   => Dicex_Connect_Recipients::USER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $mobile,                       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 2,
				'fields'     => array( 'ID' ),
			)
		);

		// Two accounts sharing one number is ambiguous, so nobody is signed in.
		return ( 1 === count( $users ) ) ? (int) $users[0]->ID : 0;
	}

	/* ---------------------------------------------------------------------
	 * The code step
	 * ------------------------------------------------------------------ */

	public function handle_verify_step() {
		if ( ! $this->is_post() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		check_admin_referer( self::NONCE_ACTION );

		$token = isset( $_POST['dicex_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dicex_token'] ) ) : '';
		$code  = isset( $_POST['dicex_code'] ) ? sanitize_text_field( wp_unslash( $_POST['dicex_code'] ) ) : '';

		$user_id = Dicex_Connect_Otp::verify( $token, $code );

		if ( is_wp_error( $user_id ) ) {
			$this->render_form( $token, $user_id->get_error_message() );
		}

		$remember = ! empty( $_POST['dicex_remember'] );
		$user     = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			$this->render_form( '', __( 'That code is wrong or has expired.', 'dicex-connect' ) );
		}

		wp_set_auth_cookie( $user_id, $remember );

		/*
		 * Other plugins — including this one's own security card — listen for
		 * wp_login. Firing it keeps them working, and this module's own listener
		 * is taken off first so the login cannot interrupt itself forever.
		 */
		remove_action( 'wp_login', array( $this, 'interrupt_login' ), PHP_INT_MAX );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Completing core's own login flow after intercepting it; this is WordPress's hook, not one of ours.
		do_action( 'wp_login', $user->user_login, $user );

		$redirect = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';

		wp_safe_redirect( '' !== $redirect ? $redirect : admin_url() );
		exit;
	}

	/**
	 * Sends a fresh code for a sign-in already under way.
	 *
	 * The pending login outlives the code on purpose — that is the only reason
	 * this can still tell who to text after the first code has expired.
	 */
	public function handle_resend_step() {
		if ( ! $this->is_post() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		check_admin_referer( self::NONCE_ACTION );

		$token = isset( $_POST['dicex_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dicex_token'] ) ) : '';
		$sent  = $this->deliver_code( $token );

		if ( is_wp_error( $sent ) ) {
			$this->render_form( $token, $sent->get_error_message() );
		}

		$this->render_form( $token, __( 'A new code is on its way.', 'dicex-connect' ) );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Issues a code for a pending login and texts it.
	 *
	 * @param string $token
	 * @return array|WP_Error What was issued, or why it could not be sent.
	 */
	private function deliver_code( $token ) {
		$issued = Dicex_Connect_Otp::issue_code( $token );

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$user = get_userdata( $issued['user_id'] );

		if ( ! $user instanceof WP_User ) {
			Dicex_Connect_Otp::discard( $token );

			return new WP_Error( 'dicex_connect_login_no_user', __( 'Invalid user.', 'dicex-connect' ) );
		}

		$settings = $this->settings();
		$channels = ( isset( $settings['channels'] ) && is_array( $settings['channels'] ) && ! empty( $settings['channels'] ) )
			? $settings['channels']
			: array( 'sms' );

		$channels = $this->rotate_past( $channels, Dicex_Connect_Otp::status( $token )['channel'] );

		/*
		 * "Valid until 14:32" answers the question the person actually has when
		 * the code does not arrive at once: is this one still good, or should I
		 * ask for another. The expiry comes from the code that was just issued,
		 * not from a fresh clock reading, so it is the real deadline.
		 */
		$expires = isset( $issued['expires'] ) ? (int) $issued['expires'] : ( time() + Dicex_Connect_Otp::CODE_TTL );

		$template = Dicex_Connect_Message::decorate(
			isset( $settings['template'] ) ? $settings['template'] : '',
			array( Dicex_Connect_Message::valid_until( $expires ) )
		);

		$result = Dicex_Connect_Sender::send_chain(
			$channels,
			Dicex_Connect_Recipients::get_user_number( $user->ID ),
			$template,
			array(
				'code'      => $issued['code'],
				'site_name' => Dicex_Connect_Branding::sender_name(),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Fail closed: an undelivered code must never become a way past the
			// second step. DICEX_CONNECT_DISABLE_LOGIN_OTP is the way back in.
			Dicex_Connect_Otp::discard( $token );
			Dicex_Connect_Logger::log( 'LOGIN OTP FAILED: ' . $result->get_error_message() );

			/*
			 * The gateway's own words travel with the error but are not shown to
			 * everybody: this screen appears to anyone who got the password
			 * right, and a refusal can name a line number. interrupt_login()
			 * decides, and only an administrator sees it — the one person who can
			 * act on "you dont have permission for use this line number" instead
			 * of guessing at "could not be sent".
			 */
			return new WP_Error(
				'dicex_connect_login_send_failed',
				__( 'The login code could not be sent. Try again, or contact the site administrator.', 'dicex-connect' ),
				array( 'reason' => $result->get_error_message() )
			);
		}

		/*
		 * Remember which channel answered. Empty when the gateway replied with
		 * something other than an array, in which case the screen names no
		 * channel rather than naming the wrong one.
		 */
		if ( is_array( $result ) && isset( $result['dicex_channel'] ) ) {
			Dicex_Connect_Otp::remember_channel( $token, $result['dicex_channel'] );
		}

		return $issued;
	}

	/**
	 * Puts the channel that already carried a code at the back of the list.
	 *
	 * The chain only falls through to the second channel when the first one
	 * *refuses* — no line set for it, region does not allow it, the gateway
	 * rejects it, the network fails. It stops the moment the gateway accepts the
	 * message, because that is the last thing the plugin ever hears about it:
	 * there is no delivery receipt, so an accepted message that never arrives
	 * looks exactly like one that did.
	 *
	 * Somebody pressing "send it again" is the only signal that ever contradicts
	 * that, and repeating the channel they just told you did not work is the one
	 * thing least likely to help. So a resend starts at the next channel and
	 * wraps around, which means nothing is skipped and a single-channel card
	 * still simply tries again.
	 *
	 * The first send passes '' here and the list comes back untouched.
	 *
	 * @param array  $channels Ordered channel keys from the card's settings.
	 * @param string $last     Channel a code already went out on, '' if none.
	 * @return array
	 */
	private function rotate_past( $channels, $last ) {
		$channels = array_values( array_filter( (array) $channels ) );

		if ( '' === $last || count( $channels ) < 2 ) {
			return $channels;
		}

		$at = array_search( $last, $channels, true );

		if ( false === $at ) {
			return $channels;
		}

		return array_merge(
			array_slice( $channels, $at + 1 ),
			array_slice( $channels, 0, $at + 1 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	private function is_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		return 'POST' === strtoupper( $method );
	}

	/**
	 * Hands the countdown the two moments it needs, plus the server's own clock,
	 * so a wrong clock on the visitor's machine cannot stretch or shrink it.
	 *
	 * Enqueued before login_header(), which is where WordPress prints the login
	 * page's head scripts; the script itself is deferred to login_footer().
	 *
	 * @param array $status From Dicex_Connect_Otp::status().
	 */
	private function enqueue_timer( $status ) {
		wp_enqueue_style(
			'dicex-connect-login',
			DICEX_CONNECT_URL . 'assets/css/login.css',
			array(),
			DICEX_CONNECT_VERSION
		);

		wp_enqueue_script(
			'dicex-connect-login',
			DICEX_CONNECT_URL . 'assets/js/login.js',
			array(),
			DICEX_CONNECT_VERSION,
			true
		);

		/* translators: %s: a countdown such as 1:45 */
		$valid_for = __( 'This code is valid for another %s', 'dicex-connect' );

		/*
		 * Naming the channel the next code will take, not the one the last one
		 * took: a resend deliberately moves on to the next route, so telling
		 * somebody to watch the app the last one failed to arrive in would be
		 * exactly wrong.
		 */
		$next_channel = '';
		$settings     = $this->settings();
		$channels     = ( isset( $settings['channels'] ) && is_array( $settings['channels'] ) && ! empty( $settings['channels'] ) )
			? $settings['channels']
			: array( 'sms' );
		$rotated      = $this->rotate_past( $channels, $status['channel'] );

		if ( ! empty( $rotated ) ) {
			$next_channel = Dicex_Connect_Lines::label_for( $rotated[0] );
		}

		/*
		 * The countdown is handed over with its placeholders intact, and the
		 * channel separately, because the script fills each one into its own
		 * <strong>. Baking the channel in here would leave the script no way to
		 * tell the two apart.
		 */
		if ( '' !== $next_channel ) {
			/*
			 * Kept short on purpose. The WordPress login form is 320px wide, and
			 * the previous sentence wrapped onto a second line there — with a
			 * bolded channel name stranded on it.
			 */
			/* translators: 1: a countdown such as 0:56, 2: a channel name such as WhatsApp */
			$resend_in    = __( 'Next code in %1$s, by %2$s', 'dicex-connect' );
			$resend_ready = sprintf(
				/* translators: %s: a channel name such as WhatsApp */
				__( 'Send a new code by %s', 'dicex-connect' ),
				$next_channel
			);
		} else {
			/* translators: %1$s: a countdown such as 0:56 */
			$resend_in    = __( 'Next code in %1$s', 'dicex-connect' );
			$resend_ready = __( 'Send me a new code', 'dicex-connect' );
		}

		wp_localize_script(
			'dicex-connect-login',
			'DiceXLogin',
			array(
				'now'      => time(),
				/*
				 * Top level, not inside i18n. The script reads data.channel, and a
				 * bag of translated strings is the wrong home for a value that is
				 * not one — nested in there it arrived undefined and the sentence
				 * ended on a dangling "your".
				 */
				'channel'  => $next_channel,
				'expires'  => (int) $status['expires'],
				'resendAt' => (int) $status['resend_at'],
				'i18n'     => array(
					'validFor'    => $valid_for,
					'expired'     => __( 'The code has expired. Ask for a new one.', 'dicex-connect' ),
					'resendIn'    => $resend_in,
					'resendReady' => $resend_ready,
				),
			)
		);
	}

	/**
	 * Renders the code step and ends the request.
	 *
	 * @param string $token   Pending token; an empty one renders a form that can only fail.
	 * @param string $message Notice shown above the field.
	 * @param bool   $fatal   True when there is nothing to submit, only a message.
	 */
	private function render_form( $token, $message = '', $fatal = false ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- these are carried through the form, not acted on.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$remember = ! empty( $_REQUEST['rememberme'] ) || ! empty( $_REQUEST['dicex_remember'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$status = Dicex_Connect_Otp::status( $token );

		if ( ! $fatal ) {
			$this->enqueue_timer( $status );
		}

		login_header( __( 'Confirm sign-in', 'dicex-connect' ), '', new WP_Error() );

		if ( '' !== $message ) {
			echo '<div id="login_error">' . esc_html( $message ) . '</div>';
		}

		if ( $fatal ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( wp_login_url() ),
				esc_html__( 'Back to the login page', 'dicex-connect' )
			);
			login_footer();
			exit;
		}
		?>
		<form name="dicexotp" id="loginform" method="post" dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>"
			action="<?php echo esc_url( add_query_arg( 'action', self::ACTION_VERIFY, wp_login_url() ) ); ?>">
			<p>
				<?php
				/*
				 * Cards send over an ordered list of channels and stop at the
				 * first one that gets through, so "texted to you" was only right
				 * by accident. Naming the channel is the difference between
				 * someone checking their SMS inbox and someone checking the app
				 * the code is actually sitting in.
				 */
				$channel_label = '' === $status['channel'] ? '' : Dicex_Connect_Lines::label_for( $status['channel'] );

				if ( 'voice' === $status['channel'] ) {
					// A voice call is heard, not read, and it arrives rather than
					// sits somewhere waiting to be opened. "Sent to you by Voice
					// call" would send somebody looking through their messages.
					esc_html_e( 'You are being called with the code. Answer the call and enter what you hear.', 'dicex-connect' );
				} elseif ( '' !== $channel_label ) {
					printf(
						/* translators: %s: the channel the code was sent through, such as SMS, WhatsApp or Telegram Bot */
						esc_html__( 'Enter the code sent to you by %s.', 'dicex-connect' ),
						'<strong>' . esc_html( $channel_label ) . '</strong>'
					);
				} else {
					esc_html_e( 'Enter the code that was sent to you.', 'dicex-connect' );
				}
				?>
			</p>
			<p>
				<label for="dicex_code"><?php esc_html_e( 'Login code', 'dicex-connect' ); ?></label>
				<input type="text" name="dicex_code" id="dicex_code" class="input" value="" size="20"
					inputmode="numeric" autocomplete="one-time-code" dir="ltr" autofocus>
			</p>
			<p class="dicex-connect-otp-timer" id="dicex-connect-otp-timer" role="status" aria-live="polite"></p>
			<input type="hidden" name="dicex_token" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
			<?php if ( $remember ) : ?>
				<input type="hidden" name="dicex_remember" value="1">
			<?php endif; ?>
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<p class="submit">
				<input type="submit" class="button button-primary button-large"
					value="<?php esc_attr_e( 'Confirm and sign in', 'dicex-connect' ); ?>">
			</p>
		</form>

		<?php if ( $status['sends_left'] > 0 ) : ?>
			<form name="dicexresend" id="dicex-connect-resend-form" method="post" dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>"
				action="<?php echo esc_url( add_query_arg( 'action', self::ACTION_RESEND, wp_login_url() ) ); ?>">
				<input type="hidden" name="dicex_token" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
				<?php if ( $remember ) : ?>
					<input type="hidden" name="dicex_remember" value="1">
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<p class="dicex-connect-resend-status" id="dicex-connect-resend-status" role="status" aria-live="polite"></p>
				<p>
					<button type="submit" class="button" id="dicex-connect-resend" disabled>
						<?php esc_html_e( 'Send me a new code', 'dicex-connect' ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>
		<?php
		login_footer();
		exit;
	}

	/**
	 * Renders the mobile-number step and ends the request.
	 *
	 * @param string $message
	 */
	private function render_mobile_form( $message = '' ) {
		wp_enqueue_style( 'dicex-connect-login', DICEX_CONNECT_URL . 'assets/css/login.css', array(), DICEX_CONNECT_VERSION );

		login_header( __( 'Sign in with mobile', 'dicex-connect' ), '', new WP_Error() );

		if ( '' !== $message ) {
			echo '<div id="login_error">' . esc_html( $message ) . '</div>';
		}
		?>
		<form name="dicexmobile" id="loginform" method="post" dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>"
			action="<?php echo esc_url( add_query_arg( 'action', self::ACTION_MOBILE, wp_login_url() ) ); ?>">
			<p>
				<label for="dicex_mobile"><?php esc_html_e( 'Mobile number', 'dicex-connect' ); ?></label>
				<input type="text" name="dicex_mobile" id="dicex_mobile" class="input" value="" size="20"
					inputmode="tel" autocomplete="tel" dir="ltr" autofocus>
			</p>
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<p class="submit">
				<input type="submit" class="button button-primary button-large"
					value="<?php esc_attr_e( 'Send code', 'dicex-connect' ); ?>">
			</p>
		</form>
		<p class="dicex-connect-login-alt">
			<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Sign in with a username and password', 'dicex-connect' ); ?></a>
		</p>
		<?php
		login_footer();
		exit;
	}
}
