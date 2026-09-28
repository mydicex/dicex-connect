<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dicex_Connect_Settings_Page {

	public function __construct() {
		add_action( 'wp_ajax_dicex_connect_verify_key', array( $this, 'ajax_verify_key' ) );
		add_action( 'wp_ajax_dicex_connect_save_line', array( $this, 'ajax_save_line' ) );
		add_action( 'wp_ajax_dicex_connect_save_social_line', array( $this, 'ajax_save_social_line' ) );
		add_action( 'wp_ajax_dicex_connect_test_send', array( $this, 'ajax_test_send' ) );
		add_action( 'wp_ajax_dicex_connect_request_charge', array( $this, 'ajax_request_charge' ) );
		add_action( 'wp_ajax_dicex_connect_load_tab', array( $this, 'ajax_load_tab' ) );
		add_action( 'wp_ajax_dicex_connect_social_lines', array( $this, 'ajax_social_lines' ) );
		add_action( 'wp_ajax_dicex_connect_save_message_name', array( $this, 'ajax_save_message_name' ) );
		add_action( 'wp_ajax_dicex_connect_save_region', array( $this, 'ajax_save_region' ) );
		add_action( 'wp_ajax_dicex_connect_save_send_options', array( $this, 'ajax_save_send_options' ) );
	}

	public function render() {
		$tabs = array(
			'welcome'      => __( 'Getting started', 'dicex-connect' ),
			'connection'   => __( 'Connection', 'dicex-connect' ),
			'lines'        => __( 'Sender lines', 'dicex-connect' ),
			'integrations' => __( 'Integrations', 'dicex-connect' ),
			'club'         => __( 'Customer Club', 'dicex-connect' ),
			'credit'       => __( 'Credit', 'dicex-connect' ),
			'support'      => __( 'Support', 'dicex-connect' ),
		);

		// The menu always opens on Getting started. Every other tab is a click away
		// and keeps its own ?tab= address, so a bookmark still lands where it points.
		$active_tab = ( isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( wp_unslash( $_GET['tab'] ) ) ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to show; nothing is written, and the page itself requires manage_options.
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to show; nothing is written, and the page itself requires manage_options.
			: 'welcome';

		require DICEX_CONNECT_DIR . 'includes/admin/views/settings-page.php';
	}

	/**
	 * The two buttons that move a channel up or down its list — the way to reorder
	 * it without a mouse, beside the handle that drags it. admin.js moves the row
	 * and says where it went. Printed by the Integrations and Customer Club views.
	 *
	 * @param string $label The channel's name, as the list shows it.
	 */
	public static function channel_order_buttons( $label ) {
		?>
		<span class="dicex-connect-channel-order">
			<button type="button" class="button-link dicex-connect-channel-up">
				<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: a channel name, such as WhatsApp */
					echo esc_html( sprintf( __( 'Move %s up', 'dicex-connect' ), $label ) );
					?>
				</span>
			</button>
			<button type="button" class="button-link dicex-connect-channel-down">
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: a channel name, such as WhatsApp */
					echo esc_html( sprintf( __( 'Move %s down', 'dicex-connect' ), $label ) );
					?>
				</span>
			</button>
		</span>
		<?php
	}

	/**
	 * Verifies the AJAX nonce and admin capability for every handler below.
	 * Fixes a real bug found in the old plugin, where the equivalent handler
	 * had neither check and any logged-in user could overwrite the API key.
	 */
	private function guard() {
		check_ajax_referer( 'dicex_connect_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'dicex-connect' ), 403 );
		}
	}

	public function ajax_verify_key() {
		$this->guard();

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'Please enter your API key.', 'dicex-connect' ) );
		}

		$info = Dicex_Connect_Account::verify_key( $api_key );
		if ( is_wp_error( $info ) ) {
			wp_send_json_error( $info->get_error_message() );
		}

		wp_send_json_success(
			array(
				'credit' => Dicex_Connect_Account::format_credit( Dicex_Connect_Account::get_credit( $info ) ),
			)
		);
	}

	/**
	 * The name the plugin signs its messages with. Empty means "use the
	 * WordPress site title", which is what {site_name} always did before.
	 */
	public function ajax_save_message_name() {
		$this->guard();

		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$stored = Dicex_Connect_Branding::save( $name );

		wp_send_json_success( array( 'name' => $stored ) );
	}

	/**
	 * Where the account operates. Dicex_Connect_Region::save() is the allowlist; anything
	 * it does not recognise is stored as the default rather than kept.
	 */
	/**
	 * The three settings that shape what a message looks like when it leaves.
	 *
	 * One handler for all three because the screen saves whichever one changed
	 * and the validation lives in Dicex_Connect_Send_Options either way — a
	 * length outside the range or a language the gateway cannot speak comes back
	 * as the default rather than being stored.
	 */
	public function ajax_save_send_options() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$saved = array();

		if ( isset( $_POST['otp_length'] ) ) {
			$saved['otp_length'] = Dicex_Connect_Send_Options::save_code_length(
				sanitize_text_field( wp_unslash( $_POST['otp_length'] ) )
			);
		}

		if ( isset( $_POST['message_lang'] ) ) {
			$saved['message_lang'] = Dicex_Connect_Send_Options::save_message_lang(
				sanitize_text_field( wp_unslash( $_POST['message_lang'] ) )
			);
		}

		if ( isset( $_POST['voice_lang'] ) ) {
			$saved['voice_lang'] = Dicex_Connect_Send_Options::save_voice_lang(
				sanitize_text_field( wp_unslash( $_POST['voice_lang'] ) )
			);
		}

		if ( isset( $_POST['rich_messages'] ) ) {
			$saved['rich_messages'] = Dicex_Connect_Send_Options::save_rich_messages(
				'1' === sanitize_text_field( wp_unslash( $_POST['rich_messages'] ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $saved ) ) {
			wp_send_json_error( __( 'Nothing to save.', 'dicex-connect' ) );
		}

		Dicex_Connect_Logger::log( 'SEND OPTIONS: ' . wp_json_encode( $saved ) );

		wp_send_json_success( $saved );
	}

	public function ajax_save_region() {
		$this->guard();

		$region = isset( $_POST['region'] ) ? sanitize_key( wp_unslash( $_POST['region'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$stored = Dicex_Connect_Region::save( $region );

		Dicex_Connect_Logger::log( 'REGION: ' . $stored );

		wp_send_json_success( array( 'region' => $stored ) );
	}

	public function ajax_save_line() {
		$this->guard();

		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$line    = isset( $_POST['line'] ) ? sanitize_text_field( wp_unslash( $_POST['line'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( ! in_array( $channel, Dicex_Connect_Lines::CHANNELS, true ) ) {
			wp_send_json_error( __( 'Invalid channel.', 'dicex-connect' ) );
		}

		Dicex_Connect_Lines::save_selected( $channel, $line );
		wp_send_json_success();
	}


	/**
	 * Saves the line picked for a social provider as that channel's default.
	 * The provider name is mapped to a channel key by Dicex_Connect_Lines so the rest of
	 * the plugin keeps dealing in channels only. One line per channel, by design.
	 */
	public function ajax_save_social_line() {
		$this->guard();

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$line     = isset( $_POST['line'] ) ? sanitize_text_field( wp_unslash( $_POST['line'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		$channel = Dicex_Connect_Lines::channel_for_provider( $provider );
		if ( '' === $channel ) {
			wp_send_json_error( __( 'Lines cannot be chosen for that network.', 'dicex-connect' ) );
		}

		Dicex_Connect_Lines::save_selected( $channel, $line, $label );

		wp_send_json_success(
			array(
				'channel' => $channel,
				'line'    => Dicex_Connect_Lines::get_selected( $channel ),
				'label'   => Dicex_Connect_Lines::get_label( $channel ),
			)
		);
	}
	public function ajax_test_send() {
		$this->guard();

		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : 'sms'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( empty( $target ) ) {
			wp_send_json_error( __( 'Enter a destination.', 'dicex-connect' ) );
		}

		// Check if channel is supported
		if ( ! Dicex_Connect_Lines::is_supported( $channel ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: channel key such as sms, telegram */
					__( 'The %s channel cannot be used for a test send.', 'dicex-connect' ),
					$channel
				)
			);
		}

		/*
		 * Deliberately no check for a chosen line here. Whether an empty one can
		 * work depends on the channel, and Dicex_Connect_Sender already knows
		 * which: SMS, voice and Safir go out on the DiceX shared line, and the
		 * rest come back with an error naming the panel. A test send is exactly
		 * where somebody finds that out, so let it through and report what
		 * happened.
		 */

		$result = Dicex_Connect_Sender::send( $channel, $target, __( 'This is a test message from DiceX Connect.', 'dicex-connect' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Test message sent.', 'dicex-connect' ) );
	}

	/**
	 * Requests a bank-gateway redirect for a credit top-up.
	 *
	 * UNVERIFIED: exact ChargeAccount request/response field names weren't
	 * confirmed from the live Swagger (schemas section was truncated by the
	 * fetch tool) — this mirrors the old plugin's working call against the
	 * same backend family. Test with a real account before relying on it.
	 */
	public function ajax_request_charge() {
		$this->guard();

		$amount = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		// Whatever came in, Dicex_Connect_Credit::clean_provider() decides: a value the
		// gateway's enum does not have becomes the default rather than a rejected
		// request.
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		$callback_url = add_query_arg(
			array(
				'page'   => 'dicex-connect',
				'tab'    => 'credit',
				'status' => 'verify',
			),
			admin_url( 'admin.php' )
		);

		$result = Dicex_Connect_Credit::request_charge( $amount, $callback_url, $provider );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		/*
		 * The gateway wraps this in its usual { isSuccess, message, value } envelope
		 * and the redirect lives in `value` — confirmed against SM_ApiResult<
		 * RemoteRedirectModel> in the live Swagger. Reading `data` here meant the
		 * check never passed and every top-up ended on the error branch.
		 */
		$redirect = ( isset( $result['value'] ) && is_array( $result['value'] ) ) ? $result['value'] : array();

		if ( empty( $redirect['url'] ) ) {
			$message = ( isset( $result['message'] ) && '' !== $result['message'] )
				? $result['message']
				: __( 'Unknown error from the DiceX gateway.', 'dicex-connect' );

			Dicex_Connect_Logger::log( 'CHARGE FAILED (' . Dicex_Connect_Credit::clean_provider( $provider ) . '): ' . $message );
			wp_send_json_error( $message );
		}

		wp_send_json_success( $redirect );
	}

	public function ajax_load_tab() {
		$this->guard();
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$allowed = array( 'welcome', 'connection', 'lines', 'integrations', 'club', 'credit', 'support' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			wp_send_json_error( __( 'That tab is not valid.', 'dicex-connect' ) );
		}

		ob_start();
		$view_file = DICEX_CONNECT_DIR . 'includes/admin/views/tab-' . $tab . '.php';
		require $view_file;
		wp_send_json_success( ob_get_clean() );
	}

	public function ajax_social_lines() {
		$this->guard();
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		if ( empty( $provider ) ) {
			wp_send_json_error( __( 'No provider was chosen.', 'dicex-connect' ) );
		}

		$lines = Dicex_Connect_Credit::get_social_lines( $provider );
		if ( is_wp_error( $lines ) ) {
			wp_send_json_error( $lines->get_error_message() );
		}
		wp_send_json_success( $lines );
	}
}
