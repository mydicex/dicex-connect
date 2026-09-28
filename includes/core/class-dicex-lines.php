<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-channel sender line/ID selection.
 *
 * Two sources feed this, both checked against the live gateway on 2026-09-01:
 * `Sms/GetLineNumbers` (no parameters) for the SMS list, and `Social/GetProviders`
 * plus `Social/GetLineNumbers?providerName=…` for every other channel. The
 * provider list is the gateway's own spelling — WhatsApp, Voip, Safir,
 * TelegramBot, BaleBot — and PROVIDER_CHANNELS maps each onto the channel key the
 * rest of the plugin already uses, so Dicex_Connect_Sender and the integrations never
 * have to learn what a provider is.
 *
 * There is still no endpoint listing the lines permitted for Otp/Send, where the
 * sms, whatsapp and voice sends go, so a line offered here can still be refused
 * at send time. See class-dicex-api-client.php.
 *
 * CHANNELS is the full product vision shown in the admin UI; SUPPORTED_CHANNELS
 * is what can actually be sent today, and it now covers all six because there
 * are two transports rather than one: Otp/Send carries sms, whatsapp and voice
 * (its sendType enum stops there), and Social/SendMessage carries the
 * provider-backed rest — telegram, bale and safir. Dicex_Connect_Sender picks the
 * transport per channel; a channel listed here that the account has no provider
 * for is refused by the gateway, not by this plugin.
 */
class Dicex_Connect_Lines {

	const CHANNELS = array( 'sms', 'whatsapp', 'voice', 'telegram', 'bale', 'safir' );

	const SUPPORTED_CHANNELS = array( 'sms', 'whatsapp', 'voice', 'telegram', 'bale', 'safir' );

	/**
	 * Channels DiceX runs a shared line for.
	 *
	 * A shared line is one DiceX operates on everyone's behalf, so a send with an
	 * empty lineNumber still goes out. The three messenger channels have no such
	 * line: until the account holder activates one for that channel in the DiceX
	 * panel there is nothing for the gateway to fall back to, and a send with no
	 * line cannot work.
	 *
	 * Stated by the account owner on 2026-09-10, correcting the flat "an empty
	 * line always falls back" note that shipped in 1.0.16. The Swagger says
	 * nothing either way — lineNumber is only ever a nullable string there.
	 */
	const SHARED_LINE_CHANNELS = array( 'sms', 'voice', 'safir' );

	/**
	 * Provider name exactly as Social/GetProviders spells it => channel key.
	 * A channel the gateway adds later is one row here, plus one row in
	 * Dicex_Connect_Sender's $send_type_map once it can actually be sent through.
	 */
	const PROVIDER_CHANNELS = array(
		'WhatsApp'    => 'whatsapp',
		'Voip'        => 'voice',
		'TelegramBot' => 'telegram',
		'BaleBot'     => 'bale',
		'Safir'       => 'safir',
	);

	/**
	 * What each channel is called on screen.
	 *
	 * This lived in two view files, which is how 'Bale' and 'Telegram bot' came
	 * to disagree with the gateway: Social/GetProviders spells them BaleBot and
	 * TelegramBot, and a person reading the DiceX panel should see the same
	 * product name here. One list, next to the provider map it has to agree with.
	 *
	 * @return array Channel key => label, in the order the product presents them.
	 */
	public static function labels() {
		return array(
			'sms'      => __( 'SMS', 'dicex-connect' ),
			'whatsapp' => __( 'WhatsApp', 'dicex-connect' ),
			'voice'    => __( 'Voice call', 'dicex-connect' ),
			'telegram' => __( 'Telegram Bot', 'dicex-connect' ),
			'bale'     => __( 'Bale Bot', 'dicex-connect' ),
			'safir'    => __( 'Safir', 'dicex-connect' ),
		);
	}

	/**
	 * @param string $channel Channel key.
	 * @return string Its label, or the key itself when there is no label for it.
	 */
	public static function label_for( $channel ) {
		$labels = self::labels();

		return isset( $labels[ $channel ] ) ? $labels[ $channel ] : (string) $channel;
	}

	public static function is_supported( $channel ) {
		return in_array( strtolower( $channel ), self::SUPPORTED_CHANNELS, true );
	}

	/**
	 * Whether this channel can send without a line of its own.
	 *
	 * The one test in the plugin — the sender, the Lines tab and the admin script
	 * all read it from here rather than keeping a list each.
	 *
	 * @param string $channel Channel key.
	 * @return bool
	 */
	public static function has_shared_line( $channel ) {
		return in_array( strtolower( (string) $channel ), self::SHARED_LINE_CHANNELS, true );
	}

	/**
	 * @param string $provider Provider name as the gateway spells it.
	 * @return string Channel key, or '' when this plugin has no channel for it.
	 */
	public static function channel_for_provider( $provider ) {
		$provider = (string) $provider;
		return isset( self::PROVIDER_CHANNELS[ $provider ] ) ? self::PROVIDER_CHANNELS[ $provider ] : '';
	}

	/**
	 * The reverse of channel_for_provider(): Dicex_Connect_Sender needs the gateway's own
	 * provider spelling to fill providerName on a Social/SendMessage body.
	 *
	 * @param string $channel Channel key.
	 * @return string Provider name, or '' when the channel is not provider-backed.
	 */
	public static function provider_for_channel( $channel ) {
		$provider = array_search( strtolower( (string) $channel ), self::PROVIDER_CHANNELS, true );
		return false === $provider ? '' : $provider;
	}

	public static function get_selected( $channel ) {
		$options = get_option( 'dicex_connect_options', array() );
		return isset( $options['lines'][ $channel ] ) ? $options['lines'][ $channel ] : '';
	}

	/**
	 * The name the gateway gave the selected line, stored only so the Lines tab
	 * can show "سفیر دوم — 9123110979" without re-querying the API on every load.
	 */
	public static function get_label( $channel ) {
		$options = get_option( 'dicex_connect_options', array() );
		return isset( $options['line_labels'][ $channel ] ) ? $options['line_labels'][ $channel ] : '';
	}

	public static function save_selected( $channel, $value, $label = '' ) {
		$options = get_option( 'dicex_connect_options', array() );

		if ( ! isset( $options['lines'] ) || ! is_array( $options['lines'] ) ) {
			$options['lines'] = array();
		}
		if ( ! isset( $options['line_labels'] ) || ! is_array( $options['line_labels'] ) ) {
			$options['line_labels'] = array();
		}

		$options['lines'][ $channel ]       = sanitize_text_field( $value );
		$options['line_labels'][ $channel ] = sanitize_text_field( $label );

		update_option( 'dicex_connect_options', $options, false );
	}
}
