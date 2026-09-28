<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight ring-buffer log used for the admin-facing debug log screen.
 */
class Dicex_Connect_Logger {

	const OPTION_KEY  = 'dicex_connect_logs';
	const MAX_ENTRIES = 50;

	public static function log( $message ) {
		$logs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		array_unshift( $logs, '[' . current_time( 'H:i:s' ) . '] ' . $message );

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, 0, self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}

	public static function get_logs() {
		$logs = get_option( self::OPTION_KEY, array() );
		return is_array( $logs ) ? $logs : array();
	}

	public static function clear() {
		update_option( self::OPTION_KEY, array(), false );
	}
}
