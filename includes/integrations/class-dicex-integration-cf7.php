<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact Form 7: send a message when a submission's mail goes out.
 *
 * Hook: wpcf7_mail_sent( $contact_form ), which fires only after Contact Form 7
 * has successfully sent its own mail — so validation failures and spam never
 * reach here. The counterpart wpcf7_mail_failed is deliberately not used: a
 * mail server problem is not a reason to text somebody.
 *
 * The submitted values are not passed to the hook; they are read from
 * WPCF7_Submission, which is the way Contact Form 7 exposes them.
 */
class Dicex_Connect_Integration_Cf7 extends Dicex_Connect_Integration_Base {

	protected $slug = 'cf7';

	protected function register_hooks() {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ) );
	}

	/**
	 * @param WPCF7_ContactForm $contact_form
	 */
	public function on_mail_sent( $contact_form ) {
		$title = ( is_object( $contact_form ) && method_exists( $contact_form, 'title' ) )
			? $contact_form->title()
			: '';

		$this->notify(
			array( 'form_title' => $title ),
			Dicex_Connect_Mobile::find_in( $this->posted_data() )
		);
	}

	/**
	 * @return array Field name => submitted value; empty when the submission is gone.
	 */
	private function posted_data() {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return array();
		}

		$submission = WPCF7_Submission::get_instance();

		if ( ! $submission || ! method_exists( $submission, 'get_posted_data' ) ) {
			return array();
		}

		return (array) $submission->get_posted_data();
	}
}
