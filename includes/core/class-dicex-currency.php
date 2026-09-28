<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roughly what a top-up is worth in a currency the reader already thinks in.
 *
 * The Gulf gateways price in Omani rial, and a number like 20 means nothing to
 * somebody who has never held one. This turns it into dollars and euros so the
 * amount lands before the payment page does.
 *
 * Two rates, and they are not the same kind of thing:
 *
 * **The dollar figure is exact.** The Omani rial is pegged, not floated: the
 * Central Bank of Oman has held 1 OMR = 2.6008 USD since 1986 and publishes it
 * as a fixed peg. It does not drift, so it does not go stale, and it needs no
 * request to anyone.
 *
 * **The euro figure is an approximation with a date on it.** The euro floats
 * against the dollar, so the number below was true on the day it was read and
 * is only near-enough afterwards. It is shown as an approximation and never as
 * a price — the charge is in rial, and that is the figure that binds.
 *
 * Deliberately no live lookup. The plugin promises in its readme that it
 * contacts no host but gateway.dicex.me, and a currency API would break that
 * promise for a line of helper text.
 */
class Dicex_Connect_Currency {

	/**
	 * United States dollars to one Omani rial.
	 *
	 * Central Bank of Oman, fixed peg, unchanged since 1986:
	 * https://cbo.gov.om/Pages/FixedPeg.aspx
	 */
	const USD_PER_OMR = 2.6008;

	/**
	 * United States dollars to one euro.
	 *
	 * European Central Bank euro reference rate, read on 2026-09-11. Update it
	 * when it has drifted enough to mislead; a few percent either way does not
	 * change what the reader takes from the line.
	 */
	const USD_PER_EUR = 1.1592;

	/** When USD_PER_EUR was read, so the screen can say how fresh it is. */
	const EUR_RATE_READ = '2026-09-11';

	/**
	 * Which currencies this can convert from. Iranian rial is absent on purpose:
	 * it has no peg and no single defensible rate, and inventing one would be
	 * worse than saying nothing.
	 *
	 * @return array
	 */
	public static function convertible() {
		return array( 'OMR' );
	}

	/**
	 * @param string $currency Currency code, as Dicex_Connect_Region spells it.
	 * @return bool
	 */
	public static function can_convert( $currency ) {
		return in_array( strtoupper( (string) $currency ), self::convertible(), true );
	}

	/**
	 * How much one unit of a currency is worth elsewhere.
	 *
	 * Handed to the admin script as well as used here, so the figure under the
	 * amount field can follow what somebody types without a round trip.
	 *
	 * @param string $currency Currency code.
	 * @return array Code => units per one of $currency. Empty when unconvertible.
	 */
	public static function rates( $currency ) {
		if ( ! self::can_convert( $currency ) ) {
			return array();
		}

		return array(
			'USD' => self::USD_PER_OMR,
			'EUR' => self::USD_PER_OMR / self::USD_PER_EUR,
		);
	}

	/**
	 * The converted amounts, already rounded for display.
	 *
	 * Returns strings rather than floats because the caller is about to print
	 * them, and two decimal places is the only shape money takes here.
	 *
	 * @param float  $amount   How much, in $currency.
	 * @param string $currency Currency code.
	 * @return array Code => formatted amount. Empty when there is nothing to say.
	 */
	public static function equivalents( $amount, $currency ) {
		$amount = (float) $amount;

		if ( $amount <= 0 ) {
			return array();
		}

		$out = array();

		foreach ( self::rates( $currency ) as $code => $rate ) {
			$out[ $code ] = number_format_i18n( round( $amount * $rate, 2 ), 2 );
		}

		return $out;
	}
}
