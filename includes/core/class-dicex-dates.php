<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dates as people type them, in the Solar Hijri (Shamsi) calendar or the
 * Gregorian one.
 *
 * Whatever is typed, a date is kept and sent as a Gregorian Y-m-d. A Shamsi date
 * is converted here in plain PHP. The intl extension could convert it too, but
 * WordPress only recommends intl, so a host may not have it.
 *
 * A Shamsi year is leap by the 33-year rule. From 1206 to 1498 that rule gives
 * exactly the leap years on the official list of the University of Tehran's
 * Calendar Center, and ICU — the library behind intl — computes the same years.
 * Outside that span the rule drifts from the astronomical calendar Iran actually
 * uses, so those years are refused rather than guessed.
 *
 * Every conversion counts days from one fixed pair, 1 Farvardin 1404 = 21 March
 * 2025, and lets PHP's own date arithmetic do the Gregorian side.
 *
 * Core: returns data or WP_Error and never prints anything.
 */
class Dicex_Connect_Dates {

	/** The Shamsi years the leap rule is known to get right. */
	const JALALI_MIN_YEAR = 1206;

	const JALALI_MAX_YEAR = 1498;

	const ANCHOR_JALALI_YEAR = 1404;

	const ANCHOR_GREGORIAN = '2025-03-21';

	/** Gregorian years a typed date may use; anything older is not a birthday. */
	const GREGORIAN_MIN_YEAR = 1827;

	/** The oldest a birthday can make somebody, in years. */
	const MAX_AGE = 120;

	const CALENDARS = array( 'auto', 'jalali', 'gregorian' );

	/**
	 * @param int $year Shamsi year.
	 * @return bool Whether Esfand of this year has 30 days.
	 */
	public static function is_jalali_leap( $year ) {
		return 366 === self::jalali_year_start( $year + 1 ) - self::jalali_year_start( $year );
	}

	/**
	 * @param int $year
	 * @param int $month 1–12.
	 * @return int
	 */
	public static function jalali_month_length( $year, $month ) {
		if ( $month <= 6 ) {
			return 31;
		}

		if ( $month <= 11 ) {
			return 30;
		}

		return self::is_jalali_leap( $year ) ? 30 : 29;
	}

	/**
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @return bool
	 */
	public static function is_valid_jalali( $year, $month, $day ) {
		return $year >= self::JALALI_MIN_YEAR && $year <= self::JALALI_MAX_YEAR
			&& $month >= 1 && $month <= 12
			&& $day >= 1 && $day <= self::jalali_month_length( $year, $month );
	}

	/**
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @return int[]|null Gregorian year, month and day, or null for a date that does not exist.
	 */
	public static function jalali_to_gregorian( $year, $month, $day ) {
		$year  = (int) $year;
		$month = (int) $month;
		$day   = (int) $day;

		if ( ! self::is_valid_jalali( $year, $month, $day ) ) {
			return null;
		}

		// The first six months have 31 days, the next five 30.
		$day_of_year = ( $month <= 7 ? 31 * ( $month - 1 ) : 186 + 30 * ( $month - 7 ) ) + $day - 1;
		$date        = self::anchor()->modify( sprintf( '%+d days', self::jalali_year_start( $year ) + $day_of_year ) );

		return array( (int) $date->format( 'Y' ), (int) $date->format( 'n' ), (int) $date->format( 'j' ) );
	}

	/**
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @return int[]|null Shamsi year, month and day, or null outside the supported years.
	 */
	public static function gregorian_to_jalali( $year, $month, $day ) {
		$year  = (int) $year;
		$month = (int) $month;
		$day   = (int) $day;

		if ( $year < self::GREGORIAN_MIN_YEAR || ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', $year, $month, $day ), new DateTimeZone( 'UTC' ) );
		$diff = self::anchor()->diff( $date );
		$days = $diff->invert ? -$diff->days : $diff->days;

		// A Gregorian year starts in the Shamsi year 622 before it and ends in the one 621 before.
		$jalali = $year - 622;

		while ( self::jalali_year_start( $jalali + 1 ) <= $days ) {
			++$jalali;
		}

		if ( $jalali < self::JALALI_MIN_YEAR || $jalali > self::JALALI_MAX_YEAR ) {
			return null;
		}

		$day_of_year = $days - self::jalali_year_start( $jalali );

		if ( $day_of_year < 186 ) {
			return array( $jalali, 1 + intdiv( $day_of_year, 31 ), 1 + $day_of_year % 31 );
		}

		$day_of_year -= 186;

		return array( $jalali, 7 + intdiv( $day_of_year, 30 ), 1 + $day_of_year % 30 );
	}

	/**
	 * Days from 1 Farvardin of the anchor year to 1 Farvardin of $year.
	 *
	 * floor( ( 8y + 21 ) / 33 ) counts the leap days before year y under the
	 * 33-year rule — the same count ICU uses — so no loop over years is needed.
	 *
	 * @param int $year
	 * @return int Negative for years before the anchor.
	 */
	private static function jalali_year_start( $year ) {
		$leaps = function ( $y ) {
			return (int) floor( ( 8 * $y + 21 ) / 33 );
		};

		return 365 * ( $year - self::ANCHOR_JALALI_YEAR ) + $leaps( $year ) - $leaps( self::ANCHOR_JALALI_YEAR );
	}

	/**
	 * @return DateTimeImmutable
	 */
	private static function anchor() {
		return new DateTimeImmutable( self::ANCHOR_GREGORIAN, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * How old somebody is on a day, counted in the calendar they keep birthdays in.
	 *
	 * A birthday the year does not have — 29 February outside a leap year, or 30
	 * Esfand outside a Shamsi leap year — is kept on the last day of that month,
	 * so the year is added on 28 February or on 29 Esfand. PHP's own date
	 * arithmetic would add it on 1 March; this keeps the day inside the month the
	 * person was born in.
	 *
	 * @param string $birthday Gregorian Y-m-d.
	 * @param string $calendar 'jalali' or 'gregorian'.
	 * @param string $today    Gregorian Y-m-d: the day as it is in the site's time zone.
	 * @return int|null Whole years, or null when either date cannot be read.
	 */
	public static function age( $birthday, $calendar, $today ) {
		$born = self::in_calendar( $birthday, $calendar );
		$now  = self::in_calendar( $today, $calendar );

		if ( null === $born || null === $now ) {
			return null;
		}

		list( $month, $day ) = self::birthday_in_year( $born[1], $born[2], $now[0], $calendar );

		$age = $now[0] - $born[0];

		if ( $now[1] < $month || ( $now[1] === $month && $now[2] < $day ) ) {
			--$age;
		}

		// Born after the day asked about: not a date of birth this person can have.
		return $age < 0 ? null : $age;
	}

	/**
	 * A birthday's month and day in its own calendar, with that calendar's letter
	 * in front: j05-21 for 21 Mordad, g08-12 for 12 August. The club keeps it so
	 * the people whose birthday is today can be found without reading every date.
	 *
	 * @param string $birthday Gregorian Y-m-d.
	 * @param string $calendar 'jalali' or 'gregorian'.
	 * @return string '' when there is no date to read.
	 */
	public static function birthday_key( $birthday, $calendar ) {
		$born = self::in_calendar( $birthday, $calendar );

		return null === $born ? '' : sprintf( '%s%02d-%02d', 'jalali' === $calendar ? 'j' : 'g', $born[1], $born[2] );
	}

	/**
	 * Every birthday_key() whose birthday falls on a day, in either calendar.
	 *
	 * On 28 February of a common year it also answers for 29 February, and on 29
	 * Esfand of a common Shamsi year for 30 Esfand — the days age() moves those
	 * birthdays to.
	 *
	 * @param string $date Gregorian Y-m-d.
	 * @return string[]
	 */
	public static function birthday_keys_for( $date ) {
		$keys = array();

		foreach ( array( 'gregorian', 'jalali' ) as $calendar ) {
			$day = self::in_calendar( $date, $calendar );

			if ( null === $day ) {
				continue;
			}

			$prefix = 'jalali' === $calendar ? 'j' : 'g';
			$keys[] = sprintf( '%s%02d-%02d', $prefix, $day[1], $day[2] );

			if ( 'gregorian' === $calendar && 2 === $day[1] && 28 === $day[2] && ! checkdate( 2, 29, $day[0] ) ) {
				$keys[] = $prefix . '02-29';
			}

			if ( 'jalali' === $calendar && 12 === $day[1] && 29 === $day[2] && ! self::is_jalali_leap( $day[0] ) ) {
				$keys[] = $prefix . '12-30';
			}
		}

		return $keys;
	}

	/**
	 * @param string $date     Gregorian Y-m-d.
	 * @param string $calendar
	 * @return int[]|null Year, month and day in that calendar.
	 */
	private static function in_calendar( $date, $calendar ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return null;
		}

		if ( 'jalali' === $calendar ) {
			return self::gregorian_to_jalali( (int) $parts[1], (int) $parts[2], (int) $parts[3] );
		}

		return array( (int) $parts[1], (int) $parts[2], (int) $parts[3] );
	}

	/**
	 * The month and day a birthday is kept on in one year: its own, unless that
	 * year has no such day.
	 *
	 * @param int    $month
	 * @param int    $day
	 * @param int    $year
	 * @param string $calendar
	 * @return int[]
	 */
	private static function birthday_in_year( $month, $day, $year, $calendar ) {
		if ( 'jalali' === $calendar && 12 === $month && 30 === $day && ! self::is_jalali_leap( $year ) ) {
			return array( 12, 29 );
		}

		if ( 'jalali' !== $calendar && 2 === $month && 29 === $day && ! checkdate( 2, 29, $year ) ) {
			return array( 2, 28 );
		}

		return array( $month, $day );
	}

	/**
	 * Which calendar a person writes dates in.
	 *
	 * @param string $setting From CALENDARS.
	 * @param string $locale  The language the form is shown in.
	 * @return string 'jalali' or 'gregorian'.
	 */
	public static function calendar_for( $setting, $locale ) {
		if ( 'jalali' === $setting || 'gregorian' === $setting ) {
			return $setting;
		}

		// Persian, as written in Iran and in Afghanistan, dates things in Solar Hijri years.
		return 0 === strpos( (string) $locale, 'fa' ) ? 'jalali' : 'gregorian';
	}

	/**
	 * A birthday as somebody typed it: year first, in either calendar, in Latin,
	 * Persian or Arabic-Indic digits, with a slash, hyphen or dot between parts.
	 *
	 * Day-first dates are refused: 03/04/1990 is the 3rd of April in one country
	 * and the 4th of March in another, and a wrong birthday in DiceX cannot be
	 * told from a right one.
	 *
	 * @param string $raw
	 * @param string $calendar 'jalali' or 'gregorian' — only decides the example in an error.
	 * @return string|WP_Error Gregorian Y-m-d, '' when nothing was typed.
	 */
	public static function parse_birthday( $raw, $calendar = 'gregorian' ) {
		$text = self::normalize( $raw );

		if ( '' === $text ) {
			return '';
		}

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $text, $parts ) || preg_match( '#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $text, $parts ) ) {
			$year  = (int) $parts[1];
			$month = (int) $parts[2];
			$day   = (int) $parts[3];
		} else {
			return new WP_Error(
				'dicex_connect_birthday_format',
				sprintf(
					/* translators: %s: an example date, such as 1370/05/21 or 1991-08-12 */
					__( 'Write the date of birth as year, month and day, for example %s.', 'dicex-connect' ),
					self::example( $calendar )
				)
			);
		}

		if ( $year >= self::JALALI_MIN_YEAR && $year <= self::JALALI_MAX_YEAR ) {
			$gregorian = self::jalali_to_gregorian( $year, $month, $day );
		} elseif ( $year >= self::GREGORIAN_MIN_YEAR && checkdate( $month, $day, $year ) ) {
			$gregorian = array( $year, $month, $day );
		} else {
			$gregorian = null;
		}

		if ( null === $gregorian ) {
			return new WP_Error(
				'dicex_connect_birthday_invalid',
				sprintf(
					/* translators: %s: an example date, such as 1370/05/21 or 1991-08-12 */
					__( 'That date of birth does not exist. Check the day, month and year, for example %s.', 'dicex-connect' ),
					self::example( $calendar )
				)
			);
		}

		$date  = sprintf( '%04d-%02d-%02d', $gregorian[0], $gregorian[1], $gregorian[2] );
		$today = wp_date( 'Y-m-d' );

		if ( $date > $today ) {
			return new WP_Error( 'dicex_connect_birthday_future', __( 'A date of birth cannot be in the future.', 'dicex-connect' ) );
		}

		$oldest = ( (int) substr( $today, 0, 4 ) - self::MAX_AGE ) . substr( $today, 4 );

		if ( $date < $oldest ) {
			return new WP_Error( 'dicex_connect_birthday_old', __( 'Check the year of birth; that date is too long ago.', 'dicex-connect' ) );
		}

		return $date;
	}

	/**
	 * Reads a date some other plugin stored, without guessing at its layout.
	 *
	 * Year-first dates in either calendar and Unix timestamps are understood;
	 * anything else is left alone.
	 *
	 * @param mixed $value
	 * @return string Gregorian Y-m-d, or ''.
	 */
	public static function read_stored_date( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d{9,11}$/', trim( $value ) ) ) ) {
			// Stored as a moment; the date is the one it was in UTC, where no day moves.
			return gmdate( 'Y-m-d', (int) $value );
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		// A date and a time: only the date matters.
		$text   = preg_replace( '/[T\s]\d{1,2}:\d{2}(:\d{2})?.*$/', '', trim( $value ) );
		$parsed = self::parse_birthday( $text );

		return is_string( $parsed ) ? $parsed : '';
	}

	/**
	 * A stored birthday written the way this person reads dates.
	 *
	 * @param string $date     Gregorian Y-m-d.
	 * @param string $calendar 'jalali' or 'gregorian'.
	 * @return string '' for anything that is not a date.
	 */
	public static function format_birthday( $date, $calendar ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts ) ) {
			return '';
		}

		if ( 'jalali' === $calendar ) {
			$jalali = self::gregorian_to_jalali( (int) $parts[1], (int) $parts[2], (int) $parts[3] );

			return null === $jalali ? '' : sprintf( '%04d/%02d/%02d', $jalali[0], $jalali[1], $jalali[2] );
		}

		return $parts[0];
	}

	/**
	 * @param string $calendar
	 * @return string A made-up birthday in the calendar's own layout.
	 */
	public static function example( $calendar ) {
		return 'jalali' === $calendar ? '1370/05/21' : '1991-08-12';
	}

	/**
	 * @param string $date Gregorian Y-m-d.
	 * @return string|null The value DiceX takes: midnight UTC, so the day never shifts.
	 */
	public static function to_api( $date ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? $date . 'T00:00:00Z' : null;
	}

	/**
	 * Digits in ASCII, invisible marks gone, every separator a slash.
	 *
	 * @param mixed $raw
	 * @return string
	 */
	private static function normalize( $raw ) {
		$text = strtr( is_scalar( $raw ) ? (string) $raw : '', Dicex_Connect_Mobile::DIGIT_MAP );

		// Direction marks and joiners come along when a date is pasted from RTL text.
		$text = trim( (string) preg_replace( '/[\x{200B}-\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text ) );

		// Slash, hyphen, dot, the Arabic decimal separator, the Arabic comma, or plain spaces.
		return (string) preg_replace( '#\s*[/\-.\x{066B}\x{060C}]\s*|\s+#u', '/', $text );
	}
}
