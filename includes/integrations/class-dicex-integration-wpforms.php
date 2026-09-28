<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms: send a message when a form is submitted successfully.
 *
 * Hook: wpforms_process_complete( $fields, $entry, $form_data, $entry_id ),
 * documented as firing at the very end of processing and only for a submission
 * that had no errors — so a failed or spam-blocked submission never notifies.
 *
 * $entry_id is 0 on WPForms Lite and wherever entry storage is switched off;
 * the {entry_id} tag is left empty rather than printing a meaningless zero.
 */
class Dicex_Connect_Integration_Wpforms extends Dicex_Connect_Integration_Base {

	protected $slug = 'wpforms';

	protected function register_hooks() {
		add_action( 'wpforms_process_complete', array( $this, 'on_submit' ), 10, 4 );
	}

	/**
	 * @param array $fields    Sanitized field values, keyed by field ID.
	 * @param array $entry     Raw submission.
	 * @param array $form_data Form configuration.
	 * @param int   $entry_id  Stored entry ID, or 0.
	 */
	public function on_submit( $fields, $entry, $form_data, $entry_id ) {
		$title = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '';

		$this->notify(
			array(
				'form_title' => $title,
				'entry_id'   => $entry_id ? $entry_id : '',
			),
			Dicex_Connect_Mobile::find_in( $this->candidates( $fields ) )
		);
	}

	/**
	 * Flattens the submission into label => value pairs so that a field actually
	 * labelled "موبایل" outranks any other value that happens to look like a
	 * phone number. The field ID is appended to keep the keys unique.
	 *
	 * @param array $fields
	 * @return array
	 */
	private function candidates( $fields ) {
		$candidates = array();

		foreach ( (array) $fields as $id => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
				continue;
			}

			$label = isset( $field['name'] ) ? (string) $field['name'] : '';
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';

			$candidates[ trim( $label . ' ' . $type . ' ' . $id ) ] = $field['value'];
		}

		return $candidates;
	}
}
