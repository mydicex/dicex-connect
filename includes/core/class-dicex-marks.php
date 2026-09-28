<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the artwork for a channel or a host plugin lives.
 *
 * One place, so every screen showing a channel shows the same mark, and so
 * adding a channel means dropping a file in beside the others rather than
 * editing a view.
 *
 * Host plugin logos are not here: an integration card already takes a 'logo' in
 * its registry row, and the registry is where a card is defined. Two mechanisms
 * for one job is how they drift apart.
 *
 * Core layer: returns URLs, renders nothing.
 */
class Dicex_Connect_Marks {

	const CHANNEL_DIR = 'assets/images/channels/';

	/**
	 * The DiceX marks are drawn in this project. The host plugin marks are each
	 * project's own, taken from the source it publishes — used to say "this works
	 * with that", which is what they are for, and never on a banner or a hero
	 * where they would read as an endorsement.
	 *
	 * Bale and Safir share a file deliberately: two services, one brand behind
	 * them.
	 */
	const CHANNELS = array(
		'sms'      => 'sms.svg',
		'whatsapp' => 'whatsapp.svg',
		'voice'    => 'voice.svg',
		'telegram' => 'telegram.svg',
		'bale'     => 'bale.png',
		'safir'    => 'safir.png',
	);

	/**
	 * @param string $channel Channel key.
	 * @return string URL, or '' when there is no artwork for it.
	 */
	public static function channel( $channel ) {
		return self::url( self::CHANNEL_DIR, self::CHANNELS, $channel );
	}

	/**
	 * Returning '' for a missing file is what lets a caller fall back to its
	 * Dashicon: a new integration renders correctly before anyone has sourced its
	 * logo, and a file that fails to ship does not leave a broken image behind.
	 *
	 * @param string $dir
	 * @param array  $map
	 * @param string $key
	 * @return string
	 */
	private static function url( $dir, $map, $key ) {
		$key = strtolower( trim( (string) $key ) );

		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}

		if ( ! is_readable( DICEX_CONNECT_DIR . $dir . $map[ $key ] ) ) {
			return '';
		}

		return DICEX_CONNECT_URL . $dir . $map[ $key ];
	}
}
