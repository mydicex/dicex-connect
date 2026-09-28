<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where this site's DiceX account operates, and what that makes available.
 *
 * DiceX does not sell the same things everywhere. SMS runs on an operator
 * contract that exists in Iran and not yet in the Gulf; the payment gateways in
 * the Credit tab are Iranian banks and mean nothing to a customer paying by card
 * in Oman. Offering all of it to everyone produces buttons that fail at the
 * gateway instead of settings that say what is going on.
 *
 * The gateway itself has no idea about any of this. Read against the live
 * Swagger on 2026-09-06: there is no region, country or locale field anywhere in
 * the API, and the only currency-shaped endpoint is Currency/GetDollarRate. So a
 * region is a plugin-side statement about what this account can use, and picking
 * one never changes a request body. Since 1.1.0 its key is named in the
 * User-Agent of every request, which the account owner asked for; nothing reads it
 * back.
 *
 * It follows the same shape as Dicex_Connect_Lines' CHANNELS / SUPPORTED_CHANNELS pair: a
 * region may name something the gateway cannot carry yet — GCC names Thawani,
 * which is not in Account/ChargeAccount's paymentProvider enum — and that
 * renders visible but disabled rather than silently missing or, worse, silently
 * swapped for an Iranian bank.
 *
 * Adding a region is one row below. Nothing outside this file has a list of
 * regions in it.
 */
class Dicex_Connect_Region {

	/** What an install that has never chosen gets, so nothing changes under an existing site. */
	const DEFAULT_REGION = 'ir';

	/**
	 * Iran's calling code, digits only. Written once, here: the Iran row below
	 * uses it, and so does Dicex_Connect_Mobile::for_channel(), which takes it off
	 * an SMS number. A plain constant rather than a lookup in regions(), whose
	 * labels are translated and must not be read before WordPress is ready.
	 */
	const IRAN_DIAL = '98';

	/**
	 * Regions folded into another, old key => new key.
	 *
	 * Europe and the United States became World Wide in 1.7.0 — the name the
	 * product page gives the third region, with WhatsApp and Telegram. A site that
	 * chose either keeps what it had, or for the United States gains those two
	 * channels, instead of falling back to Iran, which is what an unknown key
	 * would otherwise do.
	 */
	const RENAMED = array(
		'eu' => 'world',
		'us' => 'world',
	);

	/**
	 * Every region, and what DiceX can do in it.
	 *
	 * `channels`  channel keys from Dicex_Connect_Lines::SUPPORTED_CHANNELS. Empty means no
	 *             messaging at all here yet, which is a real state, not a bug.
	 * `gateways`  payment providers offered here, named the way
	 *             Account/ChargeAccount spells them. One the gateway does not
	 *             accept yet still belongs in the list — Dicex_Connect_Credit shows it
	 *             disabled and never sends it.
	 * `currency`  what amounts on the Credit tab are counted in, '' when unknown.
	 * `note`      shown on the screens the region switches off, so the reason is
	 *             on the page rather than in a changelog.
	 *
	 * @return array Region key => definition.
	 */
	public static function regions() {
		return array(
			'ir'  => array(
				'label'    => __( 'Iran', 'dicex-connect' ),
				'channels' => Dicex_Connect_Lines::SUPPORTED_CHANNELS,
				'gateways' => array( 'Saman', 'Sepehr', 'Digipay' ),
				'currency' => __( 'IRR', 'dicex-connect' ),
				/*
				 * One country, so a number typed without its country code can be
				 * completed. 'national' is what an Iranian mobile looks like once
				 * the trunk zero is off, and it is also what keeps a landline or an
				 * order total from being mistaken for a phone number on a form.
				 */
				'dial'     => self::IRAN_DIAL,
				'national' => '/^9[0-9]{9}$/',
				'min'      => 6000000,
				'amounts'  => array( 6000000, 10000000, 20000000, 50000000 ),
				'note'     => '',
			),
			'gcc' => array(
				'label'    => __( 'GCC', 'dicex-connect' ),
				'channels' => array( 'whatsapp', 'telegram' ),
				'gateways' => array( 'Paymob', 'Thawani' ),
				/*
				 * Both Gulf gateways price in Omani rial — stated by the account
				 * owner on 2026-09-12, which settles what the old TODO here could
				 * not.
				 *
				 * TODO: DiceX API — what remains unconfirmed is the unit.
				 * Account/ChargeAccount takes a bare int64 amount with no currency
				 * field, and the rial has a minor unit of 1000 baisa. Whether 5
				 * rial goes out as 5 or as 5000 has to come from the gateway before
				 * either provider is moved into GATEWAY_ACCEPTS; guessing wrong
				 * charges a thousandth of the intended amount, or a thousand times
				 * it.
				 */
				'currency' => __( 'OMR', 'dicex-connect' ),
				'min'      => 5,
				'amounts'  => array( 5, 10, 20, 50 ),
				'note'     => __( 'DiceX has no international SMS operator for this region yet. WhatsApp and Telegram are available; SMS and voice are not.', 'dicex-connect' ),
			),
			'world' => array(
				/* translators: A region name. DiceX's product page calls it World Wide and keeps those two words in English in every language. */
				'label'    => __( 'World Wide', 'dicex-connect' ),
				'channels' => array( 'whatsapp', 'telegram' ),
				'gateways' => array(),
				'currency' => '',
				'note'     => __( 'WhatsApp and Telegram are available for this region. DiceX does not offer SMS, voice or payment here yet.', 'dicex-connect' ),
			),
		);
	}

	/**
	 * @return string The stored region, or DEFAULT_REGION when nothing valid is stored.
	 */
	public static function get() {
		$options = get_option( 'dicex_connect_options', array() );
		$region  = self::renamed( isset( $options['region'] ) ? (string) $options['region'] : '' );

		return array_key_exists( $region, self::regions() ) ? $region : self::DEFAULT_REGION;
	}

	/**
	 * @param string $region
	 * @return string The key a region is known by now.
	 */
	private static function renamed( $region ) {
		return array_key_exists( $region, self::RENAMED ) ? self::RENAMED[ $region ] : $region;
	}

	/**
	 * @param string $region A key from regions().
	 * @return string What was actually stored.
	 */
	public static function save( $region ) {
		$region = self::renamed( (string) $region );
		$region = array_key_exists( $region, self::regions() ) ? $region : self::DEFAULT_REGION;

		$options           = get_option( 'dicex_connect_options', array() );
		$options['region'] = $region;
		update_option( 'dicex_connect_options', $options, false );

		return $region;
	}

	/**
	 * @return array The current region's definition.
	 */
	public static function current() {
		$regions = self::regions();

		return $regions[ self::get() ];
	}

	/**
	 * @param string $region Optional key; the current region by default.
	 * @return string
	 */
	public static function label( $region = '' ) {
		$regions = self::regions();
		$region  = ( '' === $region ) ? self::get() : $region;

		return isset( $regions[ $region ] ) ? $regions[ $region ]['label'] : $region;
	}

	/**
	 * Channels usable here: what the region allows, narrowed to what the gateway
	 * can actually carry. Both have to agree before a channel is offered.
	 *
	 * @return array
	 */
	public static function channels() {
		$current = self::current();

		return array_values( array_intersect( $current['channels'], Dicex_Connect_Lines::SUPPORTED_CHANNELS ) );
	}

	/**
	 * @param string $channel
	 * @return bool
	 */
	public static function allows_channel( $channel ) {
		return in_array( strtolower( (string) $channel ), self::channels(), true );
	}

	/**
	 * @return array Payment providers this region offers, including any the gateway cannot take yet.
	 */
	public static function gateways() {
		$current = self::current();

		return $current['gateways'];
	}

	/**
	 * @return string Currency amounts are counted in, or '' when that is not settled.
	 */
	public static function currency() {
		$current = self::current();

		return $current['currency'];
	}

	/**
	 * The smallest top-up this region takes, in its own currency.
	 *
	 * A region with no figure of its own falls back to the Iranian one, which is
	 * the only place a number was ever meaningful before regions existed.
	 *
	 * @return int
	 */
	public static function min_charge() {
		$current = self::current();

		return isset( $current['min'] ) ? (int) $current['min'] : 6000000;
	}

	/**
	 * The one-tap amounts offered above the free-entry field, in this region's
	 * own currency. Empty means offer none and let somebody type.
	 *
	 * @return array
	 */
	public static function quick_amounts() {
		$current = self::current();

		return isset( $current['amounts'] ) ? (array) $current['amounts'] : array();
	}

	/**
	 * The country calling code, for a region that is one country.
	 *
	 * Empty everywhere else — the Gulf is several countries and World Wide is all the rest, so
	 * there is nothing to complete a bare national number with, and guessing is
	 * how a number silently becomes undeliverable.
	 *
	 * @return string Digits, no plus.
	 */
	public static function dial() {
		$current = self::current();

		return isset( $current['dial'] ) ? (string) $current['dial'] : '';
	}

	/**
	 * What a mobile number looks like in this country, with the country code and
	 * the trunk zero removed.
	 *
	 * This is also what keeps a landline, a national ID or an order total from
	 * being mistaken for a phone number in a submitted form.
	 *
	 * @return string A regex, or '' when the region is not one country.
	 */
	public static function national_pattern() {
		$current = self::current();

		return isset( $current['national'] ) ? (string) $current['national'] : '';
	}

	/**
	 * @return string Why this region has things switched off, or '' when it has not.
	 */
	public static function note() {
		$current = self::current();

		return $current['note'];
	}
}
