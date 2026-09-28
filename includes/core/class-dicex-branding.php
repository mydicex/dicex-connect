<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The name this plugin puts in the messages it sends.
 *
 * The {site_name} tag used to read straight from get_bloginfo( 'name' ), the
 * WordPress site title. That sounds right — a message wants to say which site it
 * came from — but the title is the site owner's own data, so a site whose title
 * still carries an old company name had that name going out in every alert with
 * no way to change it short of renaming the whole site.
 *
 * The default is now the plugin's own name, and one setting changes it for every
 * card and for the two-step login at once.
 */
class Dicex_Connect_Branding {

	/** Longest override accepted; a name is a name, not a message. */
	const MAX_LENGTH = 60;

	/** What messages are signed with until somebody chooses otherwise. */
	const DEFAULT_NAME = 'DiceX WP';

	/**
	 * What {site_name} resolves to.
	 *
	 * @return string Never empty: falls back to DEFAULT_NAME.
	 */
	public static function sender_name() {
		$options  = get_option( 'dicex_connect_options', array() );
		$override = isset( $options['message_name'] ) ? trim( (string) $options['message_name'] ) : '';

		return ( '' !== $override ) ? $override : self::DEFAULT_NAME;
	}

	/**
	 * @return string The stored override, or '' when the site title is being used.
	 */
	public static function get_override() {
		$options = get_option( 'dicex_connect_options', array() );

		return isset( $options['message_name'] ) ? (string) $options['message_name'] : '';
	}

	/**
	 * @param string $name An override, or '' to go back to the site title.
	 * @return string What was actually stored.
	 */
	public static function save( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = function_exists( 'mb_substr' )
			? mb_substr( $name, 0, self::MAX_LENGTH )
			: substr( $name, 0, self::MAX_LENGTH );
		$name = trim( $name );

		$options                 = get_option( 'dicex_connect_options', array() );
		$options['message_name'] = $name;
		update_option( 'dicex_connect_options', $options, false );

		return $name;
	}
}
