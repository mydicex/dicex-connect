<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What every integration inherits.
 *
 * The contract, in full:
 *
 *   1. Only ever registers hooks the host plugin documents for third parties.
 *   2. Only ever reads. Nothing an integration does changes the host plugin's
 *      data or replaces its own notifications.
 *   3. Sends exclusively through Dicex_Connect_Sender::send(), by way of
 *      Dicex_Connect_Outbox, which waits until the visitor has their page.
 *   4. Never interrupts the host. A failed send is a log line, not an error on
 *      someone's checkout or contact form — nor a wait in front of one.
 *
 * Subclasses implement register_hooks() and call notify() from their callbacks.
 */
abstract class Dicex_Connect_Integration_Base {

	/** Registry slug; every subclass sets this. */
	protected $slug = '';

	public function __construct() {
		// Dicex_Connect_Integration_Registry::boot() has already checked this, but an
		// integration must also be safe to construct directly.
		if ( '' === $this->slug || ! Dicex_Connect_Integration_Registry::is_enabled( $this->slug ) ) {
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Register the host plugin's hooks. Called only when this integration is
	 * switched on and its host plugin is present.
	 */
	abstract protected function register_hooks();

	protected function settings() {
		return Dicex_Connect_Integration_Registry::get_settings( $this->slug );
	}

	/**
	 * The channels this card sends over, in the order they should be tried.
	 *
	 * @return array
	 */
	protected function channels() {
		$settings = $this->settings();

		return ( isset( $settings['channels'] ) && is_array( $settings['channels'] ) && ! empty( $settings['channels'] ) )
			? $settings['channels']
			: array( 'sms' );
	}

	protected function template() {
		$settings = $this->settings();

		return isset( $settings['template'] ) ? $settings['template'] : '';
	}

	protected function recipient_types() {
		$settings = $this->settings();

		return isset( $settings['recipients'] ) && is_array( $settings['recipients'] )
			? $settings['recipients']
			: array();
	}

	/**
	 * Placeholders every integration offers, merged under its own.
	 *
	 * @return array
	 */
	protected function base_vars() {
		return array(
			'site_name' => Dicex_Connect_Branding::sender_name(),
		);
	}

	/**
	 * Builds the message from the saved template and sends it to everyone the
	 * admin picked for this integration.
	 *
	 * @param array  $vars           Placeholder values, without the braces.
	 * @param string $subject_number Number of the person the event is about; used only if that recipient type is on.
	 * @return void
	 */
	protected function notify( $vars = array(), $subject_number = '' ) {
		$template = trim( $this->template() );

		if ( '' === $template ) {
			Dicex_Connect_Logger::log( 'INTEGRATION ' . $this->slug . ': skipped, message template is empty' );
			return;
		}

		$numbers = Dicex_Connect_Recipients::resolve( $this->recipient_types(), $subject_number );

		if ( empty( $numbers ) ) {
			Dicex_Connect_Logger::log( 'INTEGRATION ' . $this->slug . ': skipped, no valid recipient' );
			return;
		}

		$channels = $this->channels();
		$vars     = array_merge( $this->base_vars(), (array) $vars );

		/*
		 * An alert that arrives an hour late reads exactly like one that arrived
		 * on time, so it says when the thing happened. Added to the template
		 * before the tags are resolved — the line carries none of its own, so it
		 * passes through untouched — and only when the admin has left the extra
		 * detail switched on.
		 */
		$template = Dicex_Connect_Message::decorate(
			$template,
			array( Dicex_Connect_Message::happened_at() )
		);

		// Worked out now, while the event is fresh; sent once the visitor's page is out.
		foreach ( $numbers as $number ) {
			Dicex_Connect_Outbox::send_later( $this->slug, $channels, $number, $template, $vars );
		}
	}
}
