<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX behind the Support tab: sends the form to DiceX support.
 *
 * The tab's markup lives in views/tab-support.php, and the rules — one fixed
 * address, the limits, what goes with the message — in Dicex_Connect_Support.
 * Nothing here echoes.
 */
class Dicex_Connect_Support_Page {

	public function __construct() {
		add_action( 'wp_ajax_dicex_connect_support_send', array( $this, 'ajax_send' ) );
	}

	/**
	 * Nonce plus capability, first line of every handler — same guard the
	 * settings page uses. There is deliberately no wp_ajax_nopriv_ handler:
	 * somebody who is not signed in as an administrator cannot reach this.
	 */
	private function guard() {
		check_ajax_referer( 'dicex_connect_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'dicex-connect' ), 403 );
		}
	}

	public function ajax_send() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$log     = isset( $_POST['attach_log'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['attach_log'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = Dicex_Connect_Support::send( $email, $subject, $message, $log );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		if ( $log && ! $result['attached'] ) {
			$text = sprintf(
				/* translators: %s: the email address the answer goes to */
				__( 'Sent, but without the log: your site\'s mail does not take attachments this way. DiceX support will answer at %s.', 'dicex-connect' ),
				$email
			);
		} else {
			$text = sprintf(
				/* translators: %s: the email address the answer goes to */
				__( 'Sent. DiceX support will answer at %s.', 'dicex-connect' ),
				$email
			);
		}

		wp_send_json_success( array( 'text' => $text ) );
	}
}
