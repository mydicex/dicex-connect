<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX behind the Integrations tab: the card switches, each card's settings,
 * and the shared list of admin numbers.
 *
 * The tab's markup lives in views/tab-integrations.php. Nothing here echoes.
 */
class Dicex_Connect_Integrations_Page {

	public function __construct() {
		add_action( 'wp_ajax_dicex_connect_toggle_integration', array( $this, 'ajax_toggle_integration' ) );
		add_action( 'wp_ajax_dicex_connect_save_integration', array( $this, 'ajax_save_integration' ) );
		add_action( 'wp_ajax_dicex_connect_save_admin_recipients', array( $this, 'ajax_save_admin_recipients' ) );
	}

	/**
	 * Nonce plus capability, first line of every handler — same guard the
	 * settings page uses.
	 */
	private function guard() {
		check_ajax_referer( 'dicex_connect_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'dicex-connect' ), 403 );
		}
	}

	/**
	 * @return string A slug that exists in the registry; sends a JSON error and exits otherwise.
	 */
	private function require_slug() {
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( ! Dicex_Connect_Integration_Registry::exists( $slug ) ) {
			wp_send_json_error( __( 'That integration was not recognised.', 'dicex-connect' ) );
		}

		return $slug;
	}

	public function ajax_toggle_integration() {
		$this->guard();

		$slug    = $this->require_slug();
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( $enabled && ! Dicex_Connect_Integration_Registry::is_available( $slug ) ) {
			wp_send_json_error( __( 'The plugin this card needs is not active on this site.', 'dicex-connect' ) );
		}

		// The disabled switch on the screen is a courtesy; this is the rule.
		$blocker = Dicex_Connect_Integration_Registry::enable_blocker();

		if ( $enabled && ! empty( $blocker ) ) {
			wp_send_json_error( $blocker['text'] );
		}

		Dicex_Connect_Integration_Registry::set_enabled( $slug, $enabled );

		Dicex_Connect_Logger::log( 'INTEGRATION ' . $slug . ': ' . ( $enabled ? 'enabled' : 'disabled' ) );

		wp_send_json_success(
			array(
				'enabled' => $enabled,
				'notes'   => $enabled ? Dicex_Connect_Integration_Registry::status_notes( $slug ) : array(),
			)
		);
	}

	public function ajax_save_integration() {
		$this->guard();

		$slug = $this->require_slug();

		/*
		 * The order the list came back in is the order they will be tried, so it is
		 * kept — array_intersect() preserves the first array's order — while anything
		 * that is not a channel this plugin can send over is dropped.
		 */
		$submitted_channels = isset( $_POST['channels'] ) ? (array) wp_unslash( $_POST['channels'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized and allowlisted on the next line. Nonce and capability are verified by $this->guard(), the first statement of this handler.

		$channels = array_values(
			array_unique(
				array_intersect(
					array_map( 'sanitize_key', array_filter( $submitted_channels, 'is_scalar' ) ),
					Dicex_Connect_Lines::SUPPORTED_CHANNELS
				)
			)
		);

		if ( empty( $channels ) ) {
			wp_send_json_error( __( 'Choose at least one channel to send over.', 'dicex-connect' ) );
		}

		$submitted  = isset( $_POST['recipients'] ) ? (array) wp_unslash( $_POST['recipients'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce and capability are verified by $this->guard(), the first statement of this handler. sanitized and allowlisted on the following lines.
		$recipients = array_values(
			array_intersect(
				array_map( 'sanitize_key', $submitted ),
				Dicex_Connect_Recipients::types()
			)
		);

		$definition = Dicex_Connect_Integration_Registry::get( $slug );
		$template   = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( ! empty( $definition['hide_template'] ) ) {
			/*
			 * The card has no message box because the host plugin writes the text
			 * itself — Digits does. Keep whatever is stored instead of failing on an
			 * empty field the form never showed.
			 */
			$stored   = Dicex_Connect_Integration_Registry::get_settings( $slug );
			$template = isset( $stored['template'] ) ? $stored['template'] : '';
		} elseif ( '' === trim( $template ) ) {
			wp_send_json_error( __( 'The message text cannot be empty.', 'dicex-connect' ) );
		}

		$settings = array(
			'channels'   => $channels,
			'recipients' => $recipients,
			'template'   => $template,
		);

		/*
		 * Extra fields the integration declared for itself. Each submitted value is
		 * kept only if it appears in that field's own option list, so the allowlist
		 * comes from the registry rather than from the request.
		 */
		$submitted_fields = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- each value is sanitized and allowlisted below. Nonce and capability are verified by $this->guard(), the first statement of this handler.

		foreach ( Dicex_Connect_Integration_Registry::fields( $slug ) as $field_key => $field ) {
			if ( 'select' === $field['type'] ) {
				$value = ( isset( $submitted_fields[ $field_key ] ) && is_scalar( $submitted_fields[ $field_key ] ) )
					? sanitize_text_field( $submitted_fields[ $field_key ] )
					: '';

				// Anything not in the field's own option list falls back to its default.
				$settings[ $field_key ] = array_key_exists( $value, $field['options'] ) ? $value : $field['default'];

				continue;
			}

			if ( 'checkboxes' !== $field['type'] ) {
				continue;
			}

			$values = isset( $submitted_fields[ $field_key ] ) ? (array) $submitted_fields[ $field_key ] : array();

			$settings[ $field_key ] = array_values(
				array_intersect(
					array_map( 'sanitize_text_field', array_filter( $values, 'is_scalar' ) ),
					array_map( 'strval', array_keys( $field['options'] ) )
				)
			);
		}

		Dicex_Connect_Integration_Registry::save_settings( $slug, $settings );

		wp_send_json_success( Dicex_Connect_Integration_Registry::get_settings( $slug ) );
	}

	/**
	 * The shared admin number list. Stored inside the single options array, so
	 * every integration that picks "admin list" reads the same numbers.
	 */
	public function ajax_save_admin_recipients() {
		$this->guard();

		$raw     = isset( $_POST['numbers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['numbers'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$numbers = Dicex_Connect_Recipients::save_admin_list( $raw );

		wp_send_json_success(
			array(
				'numbers' => $numbers,
				'message' => sprintf(
					/* translators: %s: how many admin numbers were stored */
					_n( '%s number saved.', '%s numbers saved.', count( $numbers ), 'dicex-connect' ),
					number_format_i18n( count( $numbers ) )
				),
			)
		);
	}
}
