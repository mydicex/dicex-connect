<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out who a notification actually goes to.
 *
 * Three sources, chosen per integration in the Integrations tab:
 *
 *   admin_list  numbers typed into the plugin's own settings
 *   wp_admins   users with the administrator role who filled in the mobile
 *               field this plugin adds to their WordPress profile
 *   subject     the person the event is about — the customer on an order, the
 *               visitor who submitted a form — supplied by the integration
 *
 * Core layer: returns numbers, never echoes and never renders.
 */
class Dicex_Connect_Recipients {

	/** Key inside the single dicex_connect_options array. */
	const ADMIN_LIST_KEY = 'admin_recipients';

	/** User meta holding a WordPress user's mobile number. */
	const USER_META_KEY = 'dicex_connect_mobile';

	const TYPE_ADMIN_LIST = 'admin_list';
	const TYPE_WP_ADMINS  = 'wp_admins';
	const TYPE_SUBJECT    = 'subject';

	public static function types() {
		return array( self::TYPE_ADMIN_LIST, self::TYPE_WP_ADMINS, self::TYPE_SUBJECT );
	}

	/**
	 * @return array List of normalized mobile numbers.
	 */
	public static function get_admin_list() {
		$options = get_option( 'dicex_connect_options', array() );
		$list    = isset( $options[ self::ADMIN_LIST_KEY ] ) ? $options[ self::ADMIN_LIST_KEY ] : array();

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Accepts whatever the admin typed — one number per line, or separated by
	 * commas, Persian commas, spaces or semicolons — and keeps only the valid
	 * Mobile numbers this region can deliver to, normalized and de-duplicated.
	 *
	 * @param string $raw Raw textarea content.
	 * @return array The numbers that were stored.
	 */
	public static function save_admin_list( $raw ) {
		$parts   = preg_split( '/[\s,،;]+/u', (string) $raw );
		$numbers = array();

		foreach ( (array) $parts as $part ) {
			if ( '' === trim( $part ) ) {
				continue;
			}

			// from_input() rather than normalize(): this is somebody typing, and a
			// number that does not say which country it belongs to is a mistake to
			// refuse now rather than a delivery failure to find out about later.
			$number = Dicex_Connect_Mobile::from_input( $part );

			if ( Dicex_Connect_Mobile::is_valid( $number ) && ! in_array( $number, $numbers, true ) ) {
				$numbers[] = $number;
			}
		}

		$options = get_option( 'dicex_connect_options', array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$options[ self::ADMIN_LIST_KEY ] = $numbers;
		update_option( 'dicex_connect_options', $options, false );

		return $numbers;
	}

	/**
	 * Mobile numbers of administrator-role users who filled the profile field.
	 *
	 * Only users who actually have the meta are queried, so a site with many
	 * users does not pay for a full user list on every notification.
	 *
	 * @return array
	 */
	public static function get_wp_admin_numbers() {
		$users = get_users(
			array(
				'role'         => 'administrator',
				'meta_key'     => self::USER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'fields'       => array( 'ID' ),
			)
		);

		$numbers = array();

		foreach ( $users as $user ) {
			$number = self::get_user_number( $user->ID );

			if ( '' !== $number && ! in_array( $number, $numbers, true ) ) {
				$numbers[] = $number;
			}
		}

		return $numbers;
	}

	/**
	 * @param int $user_id
	 * @return string Normalized number, or '' when unset or invalid.
	 */
	public static function get_user_number( $user_id ) {
		$stored = get_user_meta( (int) $user_id, self::USER_META_KEY, true );

		if ( empty( $stored ) ) {
			return '';
		}

		$number = Dicex_Connect_Mobile::normalize( $stored );

		return Dicex_Connect_Mobile::is_valid( $number ) ? $number : '';
	}

	/**
	 * Turns the recipient types saved for an integration into real numbers.
	 *
	 * @param array  $types           Any of self::types().
	 * @param string $subject_number  The number of the person the event is about; ignored unless TYPE_SUBJECT was chosen.
	 * @return array De-duplicated, valid, normalized numbers.
	 */
	public static function resolve( $types, $subject_number = '' ) {
		$types   = is_array( $types ) ? $types : array();
		$numbers = array();

		if ( in_array( self::TYPE_ADMIN_LIST, $types, true ) ) {
			$numbers = array_merge( $numbers, self::get_admin_list() );
		}

		if ( in_array( self::TYPE_WP_ADMINS, $types, true ) ) {
			$numbers = array_merge( $numbers, self::get_wp_admin_numbers() );
		}

		if ( in_array( self::TYPE_SUBJECT, $types, true ) && '' !== $subject_number ) {
			$subject = Dicex_Connect_Mobile::normalize( $subject_number );

			if ( Dicex_Connect_Mobile::is_valid( $subject ) ) {
				$numbers[] = $subject;
			}
		}

		return array_values( array_unique( array_filter( $numbers ) ) );
	}
}
