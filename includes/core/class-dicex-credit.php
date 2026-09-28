<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credit balance, the sender-line lists, and the online top-up.
 *
 * The top-up asks the gateway to open a payment session and gets back a
 * SMAR_RemoteRedirectModel for the bank. Sending the browser there is the admin
 * layer's job; this only returns the model.
 *
 * Which bank is asked for is the admin's choice, sent as paymentProvider and
 * checked here against the gateway's own enum rather than against whatever
 * arrived in the request.
 */
class Dicex_Connect_Credit {

	/**
	 * The Iranian minimum, kept as the fallback a region with no figure of its
	 * own lands on. The live number comes from Dicex_Connect_Region::min_charge(),
	 * because five is a sane smallest top-up in rial and six million is not.
	 */
	const MIN_CHARGE_AMOUNT = 6000000;

	/** Used when the admin has not picked one, where the region offers it. */
	const DEFAULT_PROVIDER = 'Saman';

	/**
	 * Exactly what Account/ChargeAccount's paymentProvider enum accepts, read
	 * from the live Swagger on 2026-09-06. A region may offer a provider that is
	 * not in here — neither Thawani nor Paymob is — and the Credit tab shows it
	 * disabled rather than sending a request the gateway will refuse.
	 *
	 * TODO: DiceX API — Paymob and Thawani are being onboarded for the Gulf. Both
	 * stay out of this list until the enum actually carries them; adding a value
	 * the gateway does not know turns a working screen into a rejected request.
	 */
	const GATEWAY_ACCEPTS = array( 'Saman', 'Sepehr', 'Digipay' );

	/**
	 * Every provider this plugin knows a name for, spelled the way
	 * Account/ChargeAccount's paymentProvider enum spells them — Digipay has a
	 * lowercase p, and sending DigiPay is a rejected request, not a fallback.
	 *
	 * Being named here does not mean it can be charged; see GATEWAY_ACCEPTS.
	 *
	 * @return array Provider value => label to show.
	 */
	public static function all_providers() {
		return array(
			'Saman'   => __( 'Saman', 'dicex-connect' ),
			'Sepehr'  => __( 'Sepehr', 'dicex-connect' ),
			'Digipay' => __( 'Digipay credit payment', 'dicex-connect' ),
			'Paymob'  => __( 'Paymob', 'dicex-connect' ),
			'Thawani' => __( 'Thawani', 'dicex-connect' ),
		);
	}

	/**
	 * The providers the current region offers, in the order it lists them. This
	 * is what the Credit tab shows; some of it may not be chargeable yet, which
	 * is what is_chargeable() answers.
	 *
	 * @return array Provider value => label.
	 */
	public static function payment_providers() {
		$known = self::all_providers();
		$out   = array();

		foreach ( Dicex_Connect_Region::gateways() as $provider ) {
			if ( isset( $known[ $provider ] ) ) {
				$out[ $provider ] = $known[ $provider ];
			}
		}

		return $out;
	}

	/**
	 * @param string $provider
	 * @return bool Whether Account/ChargeAccount will take this one today.
	 */
	public static function is_chargeable( $provider ) {
		return in_array( (string) $provider, self::GATEWAY_ACCEPTS, true );
	}

	/**
	 * What actually goes out as paymentProvider.
	 *
	 * A choice counts only if the region offers it and the gateway accepts it.
	 * Otherwise it falls to the region's first chargeable provider, never to a
	 * global default: quietly charging an Iranian bank for a site that said it
	 * operates in the Gulf is worse than refusing. '' means this region has
	 * nothing chargeable, and request_charge() says so rather than guessing.
	 *
	 * @param string $provider Whatever was chosen, from anywhere.
	 * @return string A value the gateway accepts, or '' when there is none.
	 */
	public static function clean_provider( $provider ) {
		$offered  = self::payment_providers();
		$provider = (string) $provider;

		if ( isset( $offered[ $provider ] ) && self::is_chargeable( $provider ) ) {
			return $provider;
		}

		foreach ( array_keys( $offered ) as $candidate ) {
			if ( self::is_chargeable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * The bank's mark, when one has been placed in assets/images/gateways/.
	 *
	 * Shipping the logos is the site owner's call, not something to invent: the
	 * tile falls back to the bank's name on its own when the file is not there.
	 *
	 * @param string $provider A value from payment_providers().
	 * @return string URL, or '' when there is no file for it.
	 */
	public static function provider_logo( $provider ) {
		$slug = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $provider ) );

		if ( '' === $slug ) {
			return '';
		}

		foreach ( array( 'svg', 'png' ) as $extension ) {
			$relative = 'assets/images/gateways/' . $slug . '.' . $extension;

			if ( is_readable( DICEX_CONNECT_DIR . $relative ) ) {
				return DICEX_CONNECT_URL . $relative;
			}
		}

		return '';
	}

	/**
	 * The smallest top-up, said in the currency the region counts in.
	 *
	 * The figure and the unit both come from the region, so a Gulf account is
	 * told five rial rather than six million of something unnamed. A region whose
	 * currency is still unsettled gets the number bare rather than a wrong unit.
	 *
	 * @return string
	 */
	public static function minimum_message() {
		$amount   = number_format_i18n( Dicex_Connect_Region::min_charge() );
		$currency = Dicex_Connect_Region::currency();

		if ( '' === $currency ) {
			return sprintf(
				/* translators: %s: minimum chargeable amount, formatted */
				__( 'The minimum top-up is %s.', 'dicex-connect' ),
				$amount
			);
		}

		return sprintf(
			/* translators: 1: minimum chargeable amount, formatted. 2: currency such as IRR */
			__( 'The minimum top-up is %1$s %2$s.', 'dicex-connect' ),
			$amount,
			$currency
		);
	}

	public static function get_balance() {
		$info = Dicex_Connect_Account::get_info();
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		return Dicex_Connect_Account::get_credit( $info );
	}

	public static function request_charge( $amount, $callback_url, $provider = '' ) {
		$amount = (int) $amount;

		// Whether there is anywhere to pay comes first: telling somebody in a region
		// with no gateway that their amount is too small answers a question they did
		// not ask.
		$provider = self::clean_provider( $provider );

		if ( '' === $provider ) {
			return new WP_Error(
				'dicex_connect_no_gateway',
				sprintf(
					/* translators: %s: region name such as Iran, GCC */
					__( 'No payment gateway is available for the %s region yet.', 'dicex-connect' ),
					Dicex_Connect_Region::label()
				)
			);
		}

		if ( $amount < Dicex_Connect_Region::min_charge() ) {
			return new WP_Error(
				'dicex_connect_min_amount',
				self::minimum_message()
			);
		}

		return Dicex_Connect_Api_Client::post(
			Dicex_Connect_Api_Client::PATH_CHARGE,
			array(
				'amount'          => $amount,
				'backUrl'         => $callback_url,
				'paymentProvider' => $provider,
			)
		);
	}

	public static function get_sms_lines() {
		$response = Dicex_Connect_Api_Client::get( Dicex_Connect_Api_Client::PATH_SMS_LINES );
		return self::extract_list( $response );
	}

	public static function get_social_providers() {
		$response = Dicex_Connect_Api_Client::get( Dicex_Connect_Api_Client::PATH_PROVIDERS );
		return self::extract_list( $response );
	}

	public static function get_social_lines( $provider ) {
		// Query-parameter name is providerName, confirmed from the live Swagger on 2026-09-01.
		$response = Dicex_Connect_Api_Client::get( Dicex_Connect_Api_Client::PATH_SOCIAL_LINES, array( 'providerName' => $provider ) );
		return self::extract_social_lines( $response );
	}

	private static function extract_list( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( isset( $response['value'] ) && is_array( $response['value'] ) ) {
			return array_values( array_filter( $response['value'], 'is_string' ) );
		}
		return is_array( $response ) ? array_values( array_filter( $response, 'is_string' ) ) : array();
	}

	/**
	 * Social lines come back as objects — { "lineNumber": "9123110979", "name": "سفیر دوم" } —
	 * even though the Swagger declares List<string> for this endpoint. Confirmed against a
	 * real account on 2026-09-01, so the live shape wins and both are accepted here.
	 *
	 * @return array|WP_Error List of array( 'lineNumber' => string, 'name' => string ).
	 */
	private static function extract_social_lines( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = ( isset( $response['value'] ) && is_array( $response['value'] ) ) ? $response['value'] : $response;
		if ( ! is_array( $items ) ) {
			return array();
		}

		$lines = array();

		foreach ( $items as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$lines[] = array(
					'lineNumber' => $item,
					'name'       => '',
				);
				continue;
			}

			if ( is_array( $item ) && ! empty( $item['lineNumber'] ) ) {
				$lines[] = array(
					'lineNumber' => (string) $item['lineNumber'],
					'name'       => isset( $item['name'] ) ? trim( (string) $item['name'] ) : '',
				);
			}
		}

		return $lines;
	}
}
