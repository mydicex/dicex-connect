<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composing the text of a message, and knowing what it will cost.
 *
 * Core layer: returns strings and numbers, renders nothing.
 */
class Dicex_Connect_Message {

	/**
	 * Every send schema in the DiceX API takes a plain `message` string and
	 * nothing else — there is no parse mode, no format flag, no HTML anywhere in
	 * SMOR_SendRequest or SMSR_SendMessageRequest (live Swagger, 2026-09-08). So
	 * a line break is a line break; <br> would be delivered as four characters.
	 */
	const BREAK = "\n";

	/**
	 * Adds the plugin's own lines under a message, one per line.
	 *
	 * Only when the admin has left the extra detail on. Anything the admin typed
	 * into the template themselves — a {code_expires} tag, say — is resolved
	 * whatever this setting says, because they asked for it explicitly; this is
	 * about what the plugin volunteers.
	 *
	 * @param string $message The template, tags already resolved.
	 * @param array  $extras  Lines to append. Empty entries are dropped.
	 * @return string
	 */
	public static function decorate( $message, $extras = array() ) {
		$message = rtrim( (string) $message );

		if ( ! Dicex_Connect_Send_Options::rich_messages() ) {
			return $message;
		}

		$extras = array_filter( array_map( 'trim', (array) $extras ), 'strlen' );

		if ( empty( $extras ) ) {
			return $message;
		}

		return $message . self::BREAK . implode( self::BREAK, $extras );
	}

	/**
	 * "Valid until 14:32 (Tehran)" — the time a login code stops working, on a
	 * clock the reader can name.
	 *
	 * The hour on its own is worth less than it looks. Somebody reading it does
	 * not know whether it is their clock or the server's, and a message that
	 * crosses a border is exactly where that matters. Saying which city settles
	 * it in three words.
	 *
	 * @param int $timestamp Unix time the code expires.
	 * @return string
	 */
	public static function valid_until( $timestamp ) {
		$timestamp = (int) $timestamp;

		if ( $timestamp <= 0 ) {
			return '';
		}

		$switched = self::switch_language();

		$line = sprintf(
			/* translators: 1: a clock time, such as 14:32. 2: the place that clock belongs to, such as Tehran or UTC+04:00. */
			__( 'Valid until %1$s (%2$s).', 'dicex-connect' ),
			// wp_date() gives the site's timezone; date() would give the server's.
			wp_date( get_option( 'time_format', 'H:i' ), $timestamp ),
			self::zone_label()
		);

		self::restore_language( $switched );

		return $line;
	}

	/**
	 * "13 Sep 2026, 14:32" — when something happened, for an alert about it.
	 *
	 * @param int $timestamp Unix time, or 0 for now.
	 * @return string
	 */
	public static function happened_at( $timestamp = 0 ) {
		$timestamp = (int) $timestamp > 0 ? (int) $timestamp : time();

		$switched = self::switch_language();

		$line = sprintf(
			/* translators: 1: a date and time, such as 13 Sep 2026 at 14:32. 2: the place that clock belongs to, such as Tehran or UTC+04:00. */
			__( 'Time: %1$s (%2$s)', 'dicex-connect' ),
			wp_date(
				get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ),
				$timestamp
			),
			self::zone_label()
		);

		self::restore_language( $switched );

		return $line;
	}

	/**
	 * The site's timezone, said the way a person would say it.
	 *
	 * wp_timezone_string() gives either an IANA identifier or a bare offset, and
	 * neither reads well in a text message. 'Asia/Tehran' becomes 'Tehran',
	 * 'America/North_Dakota/New_Salem' becomes 'New Salem', and a site that was
	 * set to an offset instead of a place gets 'UTC+03:30', which is at least
	 * unambiguous.
	 *
	 * This is the clock the site itself is set to, which is the only exact one
	 * the plugin has. It is not guessed from the reader's address: working that
	 * out means sending somebody's IP to a geolocation service, and this plugin
	 * contacts no host but the DiceX gateway.
	 *
	 * @return string
	 */
	public static function zone_label() {
		$zone = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';

		if ( '' === $zone ) {
			return 'UTC';
		}

		// A bare offset, which is what a site gets when it picked one instead of
		// a city. '+03:30' on its own would read as a typo.
		if ( '+' === $zone[0] || '-' === $zone[0] ) {
			return 'UTC' . $zone;
		}

		$parts = explode( '/', $zone );
		$place = (string) end( $parts );

		return str_replace( '_', ' ', $place );
	}

	/**
	 * Puts WordPress into the language the plugin's message text should be in.
	 *
	 * This is the pattern core itself uses for notification content — switch,
	 * build the string, switch back — because the person receiving a message is
	 * not necessarily the person whose language the request is running in.
	 *
	 * @return bool Whether a switch happened, and so whether to undo one.
	 */
	private static function switch_language() {
		$locale = Dicex_Connect_Send_Options::message_lang();

		if ( '' === $locale || ! function_exists( 'switch_to_locale' ) ) {
			return false;
		}

		if ( $locale === determine_locale() ) {
			return false;
		}

		return (bool) switch_to_locale( $locale );
	}

	/**
	 * @param bool $switched What switch_language() returned.
	 * @return void
	 */
	private static function restore_language( $switched ) {
		if ( $switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
	}

	/**
	 * How many SMS segments a body will be billed as.
	 *
	 * This is the GSM 03.38 rule, and it is worth stating because it decides what
	 * a message costs. A body made entirely of characters in the GSM-7 alphabet
	 * fits 160 in one segment, or 153 each once it splits — the missing seven are
	 * the concatenation header. One character outside that alphabet, and a single
	 * Persian letter is enough, re-encodes the whole body as UCS-2: 70 in one
	 * segment, 67 each after that.
	 *
	 * Which is why a second line of Persian is not free. It is also why this is
	 * shown next to the templates rather than left for the invoice to explain.
	 *
	 * The extended GSM characters ({ } [ ] ~ ^ \ | and the euro sign) each take
	 * two of the 160 rather than one; they are counted as two here.
	 *
	 * @param string $text
	 * @return array { encoding: 'gsm'|'ucs2', characters: int, segments: int, per_segment: int }
	 */
	public static function segments( $text ) {
		$text = (string) $text;

		/*
		 * Single-quoted, and that is not a style choice: in a double-quoted string
		 * PHP reads the `$` of the GSM alphabet as the start of a variable and
		 * swallows the characters after it, which silently corrupts the table this
		 * whole count depends on.
		 */
		$basic = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡'
			. 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

		$extended = '^{}\\[~]|€';

		// preg_split with /u splits on characters without needing mbstring, which
		// WordPress recommends but does not guarantee.
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$chars = is_array( $chars ) ? $chars : array();
		$count = count( $chars );

		$in_basic    = array_flip( preg_split( '//u', $basic, -1, PREG_SPLIT_NO_EMPTY ) );
		$in_extended = array_flip( preg_split( '//u', $extended, -1, PREG_SPLIT_NO_EMPTY ) );

		$length = 0;
		$gsm    = true;

		foreach ( $chars as $char ) {
			if ( isset( $in_basic[ $char ] ) ) {
				$length++;
			} elseif ( isset( $in_extended[ $char ] ) ) {
				$length += 2;
			} else {
				$gsm = false;
				break;
			}
		}

		if ( ! $gsm ) {
			$length = $count;
		}

		$single = $gsm ? 160 : 70;
		$multi  = $gsm ? 153 : 67;

		if ( 0 === $length ) {
			$segments = 0;
		} elseif ( $length <= $single ) {
			$segments = 1;
		} else {
			$segments = (int) ceil( $length / $multi );
		}

		return array(
			'encoding'    => $gsm ? 'gsm' : 'ucs2',
			'characters'  => $length,
			'segments'    => $segments,
			'per_segment' => $segments > 1 ? $multi : $single,
		);
	}
}
