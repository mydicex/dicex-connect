<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place a phone number is cleaned up and judged.
 *
 * It was Iran-only until regions arrived; the old codebase had the same logic
 * copied into three files, which is why it lives here alone now.
 *
 * The region decides the shape. Iran keeps the national 09xxxxxxxxx form every
 * stored number already uses and every send has ever used. A region whose
 * numbers are international has no national format to assume, so the number
 * carries its own country code and is reduced to E.164 digits.
 */
class Dicex_Connect_Mobile {

	/**
	 * Persian and Arabic-Indic digits mapped to ASCII. Numbers may be displayed
	 * in either script, but everything stored, sent to the API or compared has to
	 * be ASCII — so the conversion happens here and nowhere else.
	 */
	const DIGIT_MAP = array(
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
	);

	/**
	 * Words that mark a form field as holding a phone number, in both scripts.
	 */
	const PHONE_HINT = '/(mobile|phone|tel|cell|موبایل|همراه|تلفن|شماره)/iu';

	/**
	 * Canonical form of a number, in whatever shape the region works in.
	 *
	 * @param string $number
	 * @return string
	 */
	public static function normalize( $number ) {
		$digits = strtr( (string) $number, self::DIGIT_MAP );
		$digits = preg_replace( '/[^0-9]/', '', (string) $digits );

		if ( '' === $digits ) {
			return '';
		}

		$dial     = Dicex_Connect_Region::dial();
		$national = Dicex_Connect_Region::national_pattern();

		// 00 is the international access code. What follows it is the country
		// code already, so dropping the zeros leaves exactly what we want.
		if ( 0 === strpos( $digits, '00' ) ) {
			return '+' . ltrim( $digits, '0' );
		}

		// A single leading zero is a national trunk prefix. It can only be
		// completed where the region names one country.
		if ( 0 === strpos( $digits, '0' ) ) {
			$rest = ltrim( $digits, '0' );

			if ( '' !== $dial && '' !== $national && preg_match( $national, $rest ) ) {
				return '+' . $dial . $rest;
			}

			return '';
		}

		// A national number typed without its trunk zero, in a region that knows
		// which country that is. Checked against the country's own shape so an
		// Omani number typed on an Iranian site is not given a 98 it never had.
		if ( '' !== $dial && '' !== $national && 0 !== strpos( $digits, $dial ) && preg_match( $national, $digits ) ) {
			return '+' . $dial . $digits;
		}

		return '+' . $digits;
	}

	/**
	 * The number as a particular channel wants to receive it.
	 *
	 * Everything takes the stored form, plus and all — a real WhatsApp send was
	 * read off the wire on 2026-09-13 carrying the number with its plus, so the
	 * plus is not decoration the gateway strips for you.
	 *
	 * SMS is the exception, and a temporary one. It is an Iranian-only channel
	 * today and the endpoint still wants the national form there, a leading 0 in
	 * place of the country code, so the code comes back off on the way out.
	 *
	 * TODO: DiceX API — remove the SMS branch once the gateway accepts the
	 * international form, with its plus, on Otp/Send with sendType Sms. The
	 * account owner expects that in a later phase; until then this is the shape
	 * confirmed to work.
	 *
	 * @param string $number  Anything normalize() accepts.
	 * @param string $channel Channel key the send is going out on.
	 * @return string
	 */
	public static function for_channel( $number, $channel ) {
		$number = self::normalize( $number );

		if ( '' === $number || 'sms' !== strtolower( (string) $channel ) ) {
			return $number;
		}

		// Only Iran has SMS today, so its code is the only one that can be here. A
		// number from anywhere else goes out as it is stored rather than having a
		// zero put in front of a code it still needs.
		$iran = '+' . Dicex_Connect_Region::IRAN_DIAL;

		if ( 0 === strpos( $number, $iran ) ) {
			return '0' . substr( $number, strlen( $iran ) );
		}

		return $number;
	}

	/**
	 * A number as somebody just typed it, or '' when it is not one we can use.
	 *
	 * Stricter than normalize() on purpose. A number has to say which country it
	 * belongs to — a plus or a leading 00 — unless the region is a single country,
	 * where the national 09… form means exactly one thing and completing it is a
	 * kindness rather than a guess.
	 *
	 * Without this, an Iranian number typed without its leading zero on a Gulf
	 * site — it starts 91 — would be read as country code 91 and quietly sent to
	 * India.
	 *
	 * @param string $raw Whatever was typed.
	 * @return string Canonical digits, or '' to refuse.
	 */
	public static function from_input( $raw ) {
		$raw    = trim( strtr( (string) $raw, self::DIGIT_MAP ) );
		$digits = preg_replace( '/[^0-9]/', '', $raw );

		if ( '' === $digits ) {
			return '';
		}

		$states_country = ( 0 === strpos( $raw, '+' ) ) || ( 0 === strpos( $digits, '00' ) );

		if ( ! $states_country && '' === Dicex_Connect_Region::dial() ) {
			return '';
		}

		return self::normalize( $raw );
	}

	/**
	 * The canonical form of a number whose country is known from somewhere else.
	 *
	 * A WooCommerce billing phone is free text: an Omani shopper types 91234567,
	 * not +96891234567. normalize() cannot finish that outside a one-country
	 * region, and in the Gulf it reads the leading 91 as India's country code.
	 * The billing address says which country the number belongs to, so a caller
	 * holding one passes its calling code here instead.
	 *
	 * Additive: nothing that already calls normalize() goes through this.
	 *
	 * @param string $number       What was typed.
	 * @param string $calling_code The country's calling code, such as +968.
	 * @return string The canonical number, or '' when there is no code to finish it with.
	 */
	public static function normalize_for_country( $number, $calling_code ) {
		$raw    = trim( strtr( (string) $number, self::DIGIT_MAP ) );
		$digits = preg_replace( '/[^0-9]/', '', $raw );
		$code   = preg_replace( '/[^0-9]/', '', (string) $calling_code );

		if ( '' === $digits ) {
			return '';
		}

		// Written in full already; the country it names wins over the address.
		if ( 0 === strpos( $raw, '+' ) ) {
			return '+' . $digits;
		}

		if ( 0 === strpos( $digits, '00' ) ) {
			return '+' . ltrim( $digits, '0' );
		}

		if ( '' === $code ) {
			return '';
		}

		$national = ltrim( $digits, '0' );

		// Where the region is this very country, its own mobile shape settles it.
		if ( $code === Dicex_Connect_Region::dial() && '' !== Dicex_Connect_Region::national_pattern() && preg_match( Dicex_Connect_Region::national_pattern(), $national ) ) {
			return '+' . $code . $national;
		}

		// The country code typed without its plus. Never after a trunk zero — a
		// number that starts with one is national by definition, which is what keeps
		// a German 0491 area code from being read as country 49. And seven digits is
		// shorter than any full national number, so a local number that merely
		// starts with the same digits as the code is not mistaken for one carrying it.
		$trunk = ( 0 === strpos( $digits, '0' ) );

		if ( ! $trunk && 0 === strpos( $national, $code ) && strlen( $national ) - strlen( $code ) >= 7 ) {
			return '+' . $national;
		}

		return '+' . $code . $national;
	}

	/**
	 * The canonical number as a person should see and type it: a plus, the
	 * country code, then the number.
	 *
	 * @param string $number Output of normalize(), or anything normalize() accepts.
	 * @return string
	 */
	public static function display( $number ) {
		return self::normalize( $number );
	}

	/*
	 * TODO: DiceX API — every send now carries the country code, Iranian numbers
	 * included, rather than the national form with its leading 0. That is the owner's call,
	 * made on 2026-09-13, and it is the only way one format can hold across
	 * regions. But the one Iranian send ever confirmed working used the 09 form,
	 * and the Swagger types `mobile` only as a string — so this is a decision,
	 * not a verified contract. One real Iranian test send settles it.
	 */

	/**
	 * Whether a normalized number is one this region can be asked to deliver to.
	 *
	 * @param string $number Output of normalize().
	 * @return bool
	 */
	public static function is_valid( $number ) {
		// The canonical form carries a plus; the digits are what the shape tests
		// below are about.
		$number = ltrim( (string) $number, '+' );

		// E.164 allows at most fifteen digits including the country code, and no
		// country code starts with a zero. Eight is short enough to admit the
		// smallest national numbering plans without letting an order total through.
		if ( ! preg_match( '/^[1-9][0-9]{7,14}$/', $number ) ) {
			return false;
		}

		$dial     = Dicex_Connect_Region::dial();
		$national = Dicex_Connect_Region::national_pattern();

		/*
		 * Where the region is one country, a number on that country's code has to
		 * be one of its mobiles. That is the check that keeps a landline, a
		 * national ID or an order total out of the number a form picks up.
		 */
		if ( '' !== $dial && '' !== $national && 0 === strpos( $number, $dial ) ) {
			return (bool) preg_match( $national, substr( $number, strlen( $dial ) ) );
		}

		return true;
	}

	/**
	 * An example of the shape every number takes — a plus, then the country code
	 * — for placeholders and for the error somebody sees when their number was
	 * refused. The same Omani number in every region (the owner's choice,
	 * 2026-09-19): what it teaches is the format, and it works as an example for
	 * any country.
	 *
	 * @return string
	 */
	public static function example() {
		return '+96891234567';
	}

	/**
	 * @return string What to tell somebody whose number was not accepted.
	 */
	public static function format_hint() {
		return sprintf(
			/* translators: %s: an example mobile number, such as +96891234567 */
			__( 'Write every number with a plus and its country code, like %s.', 'dicex-connect' ),
			self::example()
		);
	}

	public static function is_valid_ir_mobile( $number ) {
		return (bool) preg_match( '/^989[0-9]{9}$/', ltrim( (string) $number, '+' ) );
	}

	/**
	 * Finds the mobile number inside submitted form data, without asking the site
	 * owner to map a field for every form they own.
	 *
	 * A value only counts if it normalizes to a number this region can deliver to,
	 * rules out national IDs, landlines and order totals. When several values
	 * qualify, one whose key looks like a phone field wins over the rest.
	 *
	 * @param array $values Field key/label => submitted value; may nest.
	 * @return string Canonical number, or '' when nothing qualifies.
	 */
	public static function find_in( $values ) {
		$fallback = '';

		foreach ( (array) $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$nested = self::find_in( $value );

				if ( '' !== $nested && '' === $fallback ) {
					$fallback = $nested;
				}

				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$number = self::normalize( $value );

			if ( ! self::is_valid( $number ) ) {
				continue;
			}

			if ( preg_match( self::PHONE_HINT, (string) $key ) ) {
				return $number;
			}

			if ( '' === $fallback ) {
				$fallback = $number;
			}
		}

		return $fallback;
	}

	public static function mask( $target ) {
		$target = (string) $target;

		if ( strlen( $target ) < 5 ) {
			return '***';
		}

		if ( false !== strpos( $target, '@' ) ) {
			$parts = explode( '@', $target );

			return substr( $parts[0], 0, 2 ) . '***@' . $parts[1];
		}

		return substr( $target, 0, 4 ) . '***' . substr( $target, -2 );
	}
}
