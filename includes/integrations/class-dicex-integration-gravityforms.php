<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gravity Forms: send a message when an entry is created.
 *
 * Hook: gform_after_submission( $entry, $form ), which Gravity Forms documents
 * as running at the end of the submission process, after validation,
 * notifications and entry creation.
 *
 * That hook also runs for entries Gravity Forms marked as spam, which is not
 * something anybody wants a text message about — those are skipped here.
 */
class Dicex_Connect_Integration_Gravityforms extends Dicex_Connect_Integration_Base {

	protected $slug = 'gravityforms';

	protected function register_hooks() {
		add_action( 'gform_after_submission', array( $this, 'on_submit' ), 10, 2 );
	}

	/**
	 * @param array $entry Entry object; field values are keyed by field ID.
	 * @param array $form  Form object.
	 */
	public function on_submit( $entry, $form ) {
		if ( ! is_array( $entry ) ) {
			return;
		}

		if ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
			return;
		}

		$this->notify(
			array(
				'form_title' => isset( $form['title'] ) ? $form['title'] : '',
				'entry_id'   => isset( $entry['id'] ) ? $entry['id'] : '',
			),
			Dicex_Connect_Mobile::find_in( $this->candidates( $entry, $form ) )
		);
	}

	/**
	 * Gravity Forms keys entry values by field ID, so on its own the entry says
	 * nothing about which field is a phone number. Pairing each value with its
	 * field's label and type restores that — a field of type "phone" or one
	 * labelled "موبایل" then wins over any other number-shaped value.
	 *
	 * @param array $entry
	 * @param array $form
	 * @return array
	 */
	private function candidates( $entry, $form ) {
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $entry;
		}

		$candidates = array();

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->id ) ) {
				continue;
			}

			$id    = (string) $field->id;
			$label = isset( $field->label ) ? (string) $field->label : '';
			$type  = isset( $field->type ) ? (string) $field->type : '';
			$value = isset( $entry[ $id ] ) ? $entry[ $id ] : '';

			$candidates[ trim( $label . ' ' . $type . ' ' . $id ) ] = $value;
		}

		// The raw entry is kept alongside, so a value stored against a sub-input
		// (Gravity Forms writes those as "1.3") is still considered.
		return $candidates + (array) $entry;
	}
}
