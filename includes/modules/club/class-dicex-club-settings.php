<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the site owner decided about the customer club, and the rules that
 * follow from it.
 *
 * One option, dicex_connect_club, never autoloaded — the same arrangement the
 * integrations use for their own settings, and for the same reason: it is a
 * list that grows, and reading the API key on every request should not drag it
 * along.
 *
 * Levels are the owner's own. Any number of them, called anything, in the order
 * that decides which one a customer lands in: a customer joins the first level
 * whose conditions they meet, and the default level when they meet none. That
 * order is the whole model, because DiceX keeps a contact in one level at a time.
 *
 * Nothing in here runs a translation at load time. Whatever reads a setting
 * before init — the module deciding whether to listen for orders — must not
 * trigger one.
 */
class Dicex_Connect_Club_Settings {

	const OPTION = 'dicex_connect_club';

	/** How far back purchases count, in months. 0 is all time. */
	const WINDOWS = array( 0, 3, 6, 12, 24 );

	const SYNC_MODES = array( 'both', 'realtime', 'daily' );

	/**
	 * What may go to DiceX beyond the number, the name and the level, each only
	 * when the owner ticks it. Less leaves the site by default.
	 */
	const OPTIONAL_FIELDS = array( 'email', 'company', 'address' );

	/**
	 * A level's conditions. An empty string is "no limit".
	 *
	 * idle_min and idle_max are whole days since the customer's last paid order.
	 * Somebody who never bought anything meets neither: they are not a customer
	 * who stopped buying, and "orders up to 0" is how a level asks for them.
	 *
	 * age_min and age_max are whole years, counted from the date of birth in the
	 * calendar the customer keeps birthdays in. Somebody whose date of birth the
	 * club does not know meets neither. Unlike the others they need no
	 * WooCommerce: a WordPress user has a date of birth too.
	 */
	const BOUNDS = array( 'spent_min', 'spent_max', 'orders_min', 'orders_max', 'idle_min', 'idle_max', 'age_min', 'age_max' );

	/**
	 * Who joins the club. The owner chooses when setting it up — there is no
	 * default, and the club cannot be switched on before the choice is made.
	 *
	 *   everyone  every user in the chosen roles, and guests who paid for an order
	 *   consent   only the customers who ticked the club box at checkout, when
	 *             registering, or in their account
	 *
	 * In both, a customer can leave from their own account: the level for removed
	 * numbers takes them, and ticking the box again brings them back.
	 */
	const JOIN_MODES = array( 'everyone', 'consent' );

	/**
	 * A level's conditions on what a customer bought and where they are. Each is a
	 * list, and an empty list is "any": a customer meets a list when any one entry
	 * in it applies to them.
	 *
	 *   categories  product_cat term ids — a product in a child category counts
	 *   products    product ids — a variation counts as its parent product
	 *   brands      product_brand term ids, where WooCommerce has brands
	 *   countries   two-letter billing country codes
	 *   cities      billing city names, as typed; matched however they are spelled
	 */
	const LISTS = array( 'categories', 'products', 'brands', 'countries', 'cities' );

	/** Conditions that only mean something with WooCommerce running. */
	const WOO_KEYS = array( 'spent_min', 'spent_max', 'orders_min', 'orders_max', 'idle_min', 'idle_max', 'categories', 'products', 'brands', 'countries', 'cities' );

	/** The most entries one list keeps. */
	const MAX_LIST = 200;

	const MAX_LEVELS = 50;

	/**
	 * Groups are the club's other half: a customer is in one level and in as many
	 * groups as their purchases match. They are ordinary DiceX contact groups, and
	 * DiceX has no way to take a contact out of one — so a group means "matched at
	 * least once", and nobody is ever added twice.
	 */
	const MAX_GROUPS = 20;

	/** The longest a message this plugin sends may be. */
	const MAX_MESSAGE = 500;

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'       => false,
			'join'          => '',
			// Empty means the translated default, resolved when it is shown.
			'consent_label' => '',
			'birthday'      => array(
				'enabled'  => true,
				'required' => false,
				'calendar' => 'auto',
				// A user meta key another plugin keeps birthdays under, read when the club has none.
				'meta_key' => '',
			),
			'levels'        => array(),
			'groups'        => array(),
			'default_level' => '',
			'removed_level' => '',
			'roles'         => array( 'customer', 'subscriber' ),
			'guests'        => true,
			'window_months' => 0,
			'fields'        => array(),
			'sync_mode'     => 'both',
			'daily_time'    => '03:00',
			'welcome'       => array(
				'enabled'   => false,
				'channels'  => array( 'sms' ),
				// Empty means the translated default, resolved when it is used.
				'template'  => '',
				// locale => text, for sites that sell in more than one language.
				'templates' => array(),
			),
		);
	}

	/**
	 * @return array Stored settings with every key present.
	 */
	public static function get() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$settings = wp_parse_args( $stored, $defaults );

		// A club switched on before owners were asked took everybody in, and still does.
		if ( ! array_key_exists( 'join', $stored ) && ! empty( $stored['enabled'] ) ) {
			$settings['join'] = 'everyone';
		}

		$settings['join']     = in_array( $settings['join'], self::JOIN_MODES, true ) ? $settings['join'] : '';
		$settings['birthday'] = wp_parse_args( is_array( $settings['birthday'] ) ? $settings['birthday'] : array(), $defaults['birthday'] );
		$settings['welcome']  = wp_parse_args( is_array( $settings['welcome'] ) ? $settings['welcome'] : array(), $defaults['welcome'] );
		$settings['levels']   = is_array( $settings['levels'] ) ? array_values( $settings['levels'] ) : array();
		$settings['groups']   = is_array( $settings['groups'] ) ? array_values( $settings['groups'] ) : array();

		$settings['welcome']['templates'] = is_array( $settings['welcome']['templates'] ) ? $settings['welcome']['templates'] : array();

		// Levels saved by 1.1.0 have no lists yet; every reader gets every key.
		foreach ( $settings['levels'] as $index => $level ) {
			$settings['levels'][ $index ] = wp_parse_args( is_array( $level ) ? $level : array(), self::blank_level() );
		}

		foreach ( $settings['groups'] as $index => $group ) {
			$settings['groups'][ $index ] = wp_parse_args( is_array( $group ) ? $group : array(), self::blank_group() );
		}
		$settings['roles']   = is_array( $settings['roles'] ) ? $settings['roles'] : array();
		$settings['fields']  = is_array( $settings['fields'] ) ? $settings['fields'] : array();

		return $settings;
	}

	/**
	 * @param array $settings Already cleaned.
	 */
	public static function save( $settings ) {
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Switched on, and set up enough to place a customer somewhere.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get();

		return ! empty( $settings['enabled'] )
			&& '' !== $settings['join']
			&& null !== self::find_level( $settings, $settings['default_level'] );
	}

	/**
	 * Whether a customer who leaves has a level to go to. Without one, nobody is
	 * offered the way out, since DiceX cannot take a number back.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function can_leave( $settings ) {
		return null !== self::find_level( $settings, $settings['removed_level'] );
	}

	/**
	 * @param array $settings
	 * @return bool Whether the club asks customers for their date of birth.
	 */
	public static function asks_birthday( $settings ) {
		return ! empty( $settings['birthday']['enabled'] );
	}

	/**
	 * The words beside the club box. Empty in the settings means the translated
	 * default, so a site that never touched it follows its language.
	 *
	 * @param array $settings
	 * @return string
	 */
	public static function consent_label( $settings ) {
		$label = isset( $settings['consent_label'] ) ? trim( (string) $settings['consent_label'] ) : '';

		return '' !== $label ? $label : __( 'Join our customer club and receive news and offers', 'dicex-connect' );
	}

	/**
	 * @param array  $settings
	 * @param string $locale
	 * @return string 'jalali' or 'gregorian'.
	 */
	public static function birthday_calendar( $settings, $locale ) {
		return Dicex_Connect_Dates::calendar_for( (string) $settings['birthday']['calendar'], $locale );
	}

	/**
	 * Birthday settings as they may be stored.
	 *
	 * @param array $raw
	 * @return array
	 */
	public static function clean_birthday( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$calendar = isset( $raw['calendar'] ) ? sanitize_key( $raw['calendar'] ) : 'auto';
		$meta_key = isset( $raw['meta_key'] ) ? trim( (string) $raw['meta_key'] ) : '';

		return array(
			'enabled'  => ! empty( $raw['enabled'] ),
			'required' => ! empty( $raw['required'] ),
			'calendar' => in_array( $calendar, Dicex_Connect_Dates::CALENDARS, true ) ? $calendar : 'auto',
			// Meta keys other plugins use: letters, digits and a few separators, nothing more.
			'meta_key' => substr( (string) preg_replace( '/[^A-Za-z0-9_\-\/:.]/', '', $meta_key ), 0, 191 ),
		);
	}

	/**
	 * Whole days since a member last paid for an order.
	 *
	 * @param array $stats last_order_gmt, and now as a timestamp when a caller fixes the moment.
	 * @return int|null Null for somebody who never bought anything.
	 */
	public static function idle_days( $stats ) {
		if ( empty( $stats['last_order_gmt'] ) ) {
			return null;
		}

		$last = strtotime( $stats['last_order_gmt'] . ' UTC' );

		if ( false === $last ) {
			return null;
		}

		$now = isset( $stats['now'] ) ? (int) $stats['now'] : time();

		return max( 0, (int) floor( ( $now - $last ) / DAY_IN_SECONDS ) );
	}

	/**
	 * How old a member is today, or null when the club does not know.
	 *
	 * @param array $stats birthday (Gregorian Y-m-d), calendar, and today (the site's day, Y-m-d) when a caller fixes it.
	 * @return int|null
	 */
	public static function member_age( $stats ) {
		if ( empty( $stats['birthday'] ) ) {
			return null;
		}

		return Dicex_Connect_Dates::age(
			(string) $stats['birthday'],
			isset( $stats['calendar'] ) ? (string) $stats['calendar'] : 'gregorian',
			isset( $stats['today'] ) ? (string) $stats['today'] : current_datetime()->format( 'Y-m-d' )
		);
	}

	/**
	 * Whether a level people can be placed in by rules, or any group, asks how old
	 * somebody is.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function uses_age( $settings ) {
		foreach ( self::entries( $settings ) as $level ) {
			if ( isset( $level['id'] ) && $level['id'] === $settings['removed_level'] ) {
				continue;
			}

			if ( ( isset( $level['age_min'] ) && '' !== (string) $level['age_min'] ) || ( isset( $level['age_max'] ) && '' !== (string) $level['age_max'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a recompute reads each member's date of birth: to send it to DiceX,
	 * or to tell how old they are.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function needs_birthday( $settings ) {
		return self::sends_birthday( $settings ) || self::uses_age( $settings );
	}

	/**
	 * Whether DiceX is sent the date of birth: while the club asks for it, or reads
	 * it from another plugin's meta key — the owner's choice either way.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function sends_birthday( $settings ) {
		return self::asks_birthday( $settings ) || '' !== (string) $settings['birthday']['meta_key'];
	}

	/**
	 * The groups, from settings as they come: a caller may hand over an array it
	 * built itself, from before groups existed.
	 *
	 * @param array $settings
	 * @return array
	 */
	private static function groups( $settings ) {
		return isset( $settings['groups'] ) && is_array( $settings['groups'] ) ? $settings['groups'] : array();
	}

	/**
	 * Every level and every group.
	 *
	 * @param array $settings
	 * @return array
	 */
	private static function entries( $settings ) {
		$levels = isset( $settings['levels'] ) && is_array( $settings['levels'] ) ? $settings['levels'] : array();

		return array_merge( $levels, self::groups( $settings ) );
	}

	/**
	 * The day counts at which somebody enters or leaves a level just because time
	 * passed: each "at least" value, and one day past each "at most" value.
	 *
	 * @param array $settings
	 * @return int[]
	 */
	public static function idle_thresholds( $settings ) {
		$days = array();

		foreach ( self::entries( $settings ) as $level ) {
			if ( $level['id'] === $settings['removed_level'] ) {
				continue;
			}

			if ( '' !== $level['idle_min'] && (int) $level['idle_min'] > 0 ) {
				$days[ (int) $level['idle_min'] ] = true;
			}

			if ( '' !== $level['idle_max'] ) {
				$days[ (int) $level['idle_max'] + 1 ] = true;
			}
		}

		return array_keys( $days );
	}

	/**
	 * @param array  $settings
	 * @param string $id
	 * @return array|null
	 */
	public static function find_level( $settings, $id ) {
		if ( '' === (string) $id ) {
			return null;
		}

		foreach ( $settings['levels'] as $level ) {
			if ( $level['id'] === $id ) {
				return $level;
			}
		}

		return null;
	}

	/**
	 * @param array  $settings
	 * @param string $id
	 * @return string
	 */
	public static function level_name( $settings, $id ) {
		$level = self::find_level( $settings, $id );

		return null === $level ? '' : $level['name'];
	}

	/**
	 * Which level a customer belongs in.
	 *
	 * The first level, in the owner's order, whose conditions all hold. The level
	 * kept for numbers taken out of the club is never matched by rules — only a
	 * person puts somebody there.
	 *
	 * @param array $settings
	 * @param array $stats    order_count, net_spent, last_order_gmt, product_ids, category_ids, brand_ids, country, city.
	 * @return string Level id.
	 */
	public static function evaluate( $settings, $stats ) {
		foreach ( $settings['levels'] as $level ) {
			if ( $level['id'] === $settings['removed_level'] ) {
				continue;
			}

			if ( self::matches( $level, $stats ) ) {
				return $level['id'];
			}
		}

		return $settings['default_level'];
	}

	/**
	 * @param array $level
	 * @param array $stats
	 * @return bool
	 */
	public static function matches( $level, $stats ) {
		$spent  = isset( $stats['net_spent'] ) ? (float) $stats['net_spent'] : 0.0;
		$orders = isset( $stats['order_count'] ) ? (int) $stats['order_count'] : 0;

		if ( '' !== $level['spent_min'] && $spent < (float) $level['spent_min'] ) {
			return false;
		}

		if ( '' !== $level['spent_max'] && $spent > (float) $level['spent_max'] ) {
			return false;
		}

		if ( '' !== $level['orders_min'] && $orders < (int) $level['orders_min'] ) {
			return false;
		}

		if ( '' !== $level['orders_max'] && $orders > (int) $level['orders_max'] ) {
			return false;
		}

		if ( '' !== $level['idle_min'] || '' !== $level['idle_max'] ) {
			$idle = self::idle_days( $stats );

			// Never bought anything: not a customer who stopped buying.
			if ( null === $idle ) {
				return false;
			}

			if ( '' !== $level['idle_min'] && $idle < (int) $level['idle_min'] ) {
				return false;
			}

			if ( '' !== $level['idle_max'] && $idle > (int) $level['idle_max'] ) {
				return false;
			}
		}

		if ( '' !== $level['age_min'] || '' !== $level['age_max'] ) {
			$age = self::member_age( $stats );

			// A date of birth the club does not have makes nobody any age.
			if ( null === $age ) {
				return false;
			}

			if ( '' !== $level['age_min'] && $age < (int) $level['age_min'] ) {
				return false;
			}

			if ( '' !== $level['age_max'] && $age > (int) $level['age_max'] ) {
				return false;
			}
		}

		// Bought something from the list: an overlap is enough.
		$bought = array(
			'categories' => 'category_ids',
			'products'   => 'product_ids',
			'brands'     => 'brand_ids',
		);

		foreach ( $bought as $key => $stat ) {
			if ( empty( $level[ $key ] ) ) {
				continue;
			}

			$have = isset( $stats[ $stat ] ) ? array_map( 'intval', (array) $stats[ $stat ] ) : array();

			if ( array() === array_intersect( array_map( 'intval', (array) $level[ $key ] ), $have ) ) {
				return false;
			}
		}

		if ( ! empty( $level['countries'] ) ) {
			$country = isset( $stats['country'] ) ? strtoupper( (string) $stats['country'] ) : '';

			if ( '' === $country || ! in_array( $country, (array) $level['countries'], true ) ) {
				return false;
			}
		}

		if ( ! empty( $level['cities'] ) ) {
			$city = self::normalize_city( isset( $stats['city'] ) ? $stats['city'] : '' );

			if ( '' === $city || ! in_array( $city, array_map( array( __CLASS__, 'normalize_city' ), (array) $level['cities'] ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every group whose conditions a customer meets. Unlike a level, a customer is
	 * in all of them at once.
	 *
	 * @param array $settings
	 * @param array $stats
	 * @return string[] Group ids.
	 */
	public static function matching_groups( $settings, $stats ) {
		$keys = array();

		foreach ( self::groups( $settings ) as $group ) {
			// A group with no conditions would hold every customer; it holds nobody.
			if ( self::has_conditions( $group ) && self::matches( $group, $stats ) ) {
				$keys[] = $group['id'];
			}
		}

		return $keys;
	}

	/**
	 * @param array  $settings
	 * @param string $id
	 * @return array|null
	 */
	public static function find_group( $settings, $id ) {
		foreach ( self::groups( $settings ) as $group ) {
			if ( $group['id'] === (string) $id ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Whether any level a rule can place somebody in, or any group, uses one of
	 * these lists.
	 *
	 * Reading what a customer bought costs queries; nobody pays for it when
	 * nothing asks.
	 *
	 * @param array $settings
	 * @param array $keys     From LISTS.
	 * @return bool
	 */
	public static function uses( $settings, $keys ) {
		foreach ( self::entries( $settings ) as $level ) {
			if ( isset( $level['id'] ) && $level['id'] === $settings['removed_level'] ) {
				continue;
			}

			foreach ( (array) $keys as $key ) {
				if ( ! empty( $level[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * A city name reduced to what tells cities apart.
	 *
	 * Billing cities are typed by shoppers: "Tehran", "tehran", "تهران". Case,
	 * spaces, hyphens, the zero-width non-joiner and the Arabic forms of yeh and
	 * kaf are ignored, so "بندر عباس" and "بندرعباس", or "شيراز" typed on an
	 * Arabic keyboard, match what the owner wrote. A different language is a
	 * different spelling: the owner lists both.
	 *
	 * cityKey() in assets/js/club.js applies the same rule while the owner types, so
	 * a change here is made there too.
	 *
	 * @param string $city
	 * @return string
	 */
	public static function normalize_city( $city ) {
		$city = sanitize_text_field( (string) $city );
		$city = str_replace( array( "\u{064A}", "\u{0649}", "\u{0643}" ), array( "\u{06CC}", "\u{06CC}", "\u{06A9}" ), $city );
		$city = preg_replace( '/[\s\x{200C}\x{200D}\x{2010}\x{2011}\-_.\'\x{2019}]+/u', '', $city );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $city, 'UTF-8' ) : strtolower( (string) $city );
	}

	/**
	 * A level with every key present and nothing set.
	 *
	 * @return array
	 */
	public static function blank_level() {
		return array_merge(
			self::blank_group(),
			array(
				// What to send somebody who moves up into this level, and the same in
				// other languages. Empty means nothing is sent.
				'message'   => '',
				'messages'  => array(),
			)
		);
	}

	/**
	 * A group with every key present and nothing set. A group is a level without
	 * the ordering, the message, or the one-at-a-time rule.
	 *
	 * @return array
	 */
	public static function blank_group() {
		return array(
			'id'          => '',
			'name'        => '',
			'description' => '',
			'spent_min'   => '',
			'spent_max'   => '',
			'orders_min'  => '',
			'orders_max'  => '',
			'idle_min'    => '',
			'idle_max'    => '',
			'age_min'     => '',
			'age_max'     => '',
			'categories'  => array(),
			'products'    => array(),
			'brands'      => array(),
			'countries'   => array(),
			'cities'      => array(),
		);
	}

	/**
	 * @param array $level
	 * @return bool
	 */
	public static function has_conditions( $level ) {
		foreach ( self::BOUNDS as $bound ) {
			if ( isset( $level[ $bound ] ) && '' !== $level[ $bound ] ) {
				return true;
			}
		}

		foreach ( self::LISTS as $list ) {
			if ( ! empty( $level[ $list ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A level name as DiceX will keep it — permanently, since a level cannot be
	 * renamed there.
	 *
	 * Letters that look identical but are not the same character are made the
	 * same: Arabic yeh and kaf become the Persian ones. Typed on an Arabic
	 * keyboard, "طلايي" and "طلایی" would otherwise be two levels that nobody can
	 * tell apart on screen and nobody can delete. Digits are left as typed; they
	 * look different, so a person can see the difference.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function normalize_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = str_replace( array( "\u{064A}", "\u{0649}", "\u{0643}" ), array( "\u{06CC}", "\u{06CC}", "\u{06A9}" ), $name );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );

		return self::cut( $name, 100 );
	}

	/**
	 * Shortens text without splitting a Persian letter in half.
	 *
	 * @param string $text
	 * @param int    $length Characters.
	 * @return string
	 */
	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length, 'UTF-8' ) : substr( (string) $text, 0, $length );
	}

	/**
	 * An amount as the owner typed it — in Persian or Latin digits, with or
	 * without thousands separators.
	 *
	 * @param mixed $raw
	 * @return string|false Plain digits with an optional decimal part, '' for none, false when it is not a number.
	 */
	public static function parse_amount( $raw ) {
		$raw = trim( strtr( (string) $raw, Dicex_Connect_Mobile::DIGIT_MAP ) );
		$raw = str_replace( array( ',', "\u{066C}", "\u{060C}", ' ', "\u{00A0}", "\u{202F}" ), '', $raw );
		$raw = str_replace( "\u{066B}", '.', $raw );

		if ( '' === $raw ) {
			return '';
		}

		return preg_match( '/^\d{1,18}(\.\d{1,8})?$/', $raw ) ? $raw : false;
	}

	/**
	 * @param mixed $raw
	 * @return string|false Whole number as a string, '' for none, false when it is not one.
	 */
	public static function parse_count( $raw ) {
		$raw = trim( strtr( (string) $raw, Dicex_Connect_Mobile::DIGIT_MAP ) );
		$raw = str_replace( array( ',', "\u{066C}", ' ' ), '', $raw );

		if ( '' === $raw ) {
			return '';
		}

		return preg_match( '/^\d{1,9}$/', $raw ) ? (string) (int) $raw : false;
	}

	/**
	 * Checks a submitted list of levels and returns the one to store.
	 *
	 * @param array  $submitted  Levels as the screen sent them, in order.
	 * @param string $default_id
	 * @param string $removed_id
	 * @return array|WP_Error array( levels, default_level, removed_level )
	 */
	public static function clean_levels( $submitted, $default_id, $removed_id ) {
		$levels = array();
		$names  = array();
		$ids    = array();
		$woo    = Dicex_Connect_Club_Sources::has_woocommerce();
		$stored = array();

		// Without WooCommerce the screen shows no purchase conditions, so nothing
		// comes back for them. Saving then must not wipe what was set while it ran.
		foreach ( self::get()['levels'] as $stored_level ) {
			$stored[ $stored_level['id'] ] = $stored_level;
		}

		foreach ( (array) $submitted as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$level = self::clean_entry( $raw, $names, $ids, $woo, $stored, 'level' );

			if ( is_wp_error( $level ) ) {
				return $level;
			}

			$levels[] = $level;
		}

		if ( empty( $levels ) ) {
			return new WP_Error( 'dicex_connect_club_level', __( 'Add at least one level.', 'dicex-connect' ) );
		}

		if ( count( $levels ) > self::MAX_LEVELS ) {
			return new WP_Error(
				'dicex_connect_club_level',
				sprintf(
					/* translators: %s: the largest number of levels allowed */
					__( 'A club can have at most %s levels.', 'dicex-connect' ),
					number_format_i18n( self::MAX_LEVELS )
				)
			);
		}

		$default_id = sanitize_key( (string) $default_id );
		$removed_id = sanitize_key( (string) $removed_id );

		if ( ! isset( $ids[ $default_id ] ) ) {
			return new WP_Error( 'dicex_connect_club_level', __( 'Choose the level for customers who match none of the others.', 'dicex-connect' ) );
		}

		if ( '' !== $removed_id && ! isset( $ids[ $removed_id ] ) ) {
			$removed_id = '';
		}

		if ( $removed_id === $default_id ) {
			return new WP_Error( 'dicex_connect_club_level', __( 'The level for removed numbers cannot also be the level everybody else joins.', 'dicex-connect' ) );
		}

		return array(
			'levels'        => $levels,
			'default_level' => $default_id,
			'removed_level' => $removed_id,
		);
	}

	/**
	 * One level or group as it may be stored: a name of its own, conditions that
	 * read as numbers, and lists of things the store still has.
	 *
	 * @param array  $raw
	 * @param array  $names  Folded names already taken, by reference.
	 * @param array  $ids    Ids already taken, by reference.
	 * @param bool   $woo    Whether WooCommerce is running.
	 * @param array  $stored What is stored today, keyed by id.
	 * @param string $kind   'level' or 'group'.
	 * @return array|WP_Error
	 */
	private static function clean_entry( $raw, &$names, &$ids, $woo, $stored, $kind ) {
		$name = self::normalize_name( isset( $raw['name'] ) ? $raw['name'] : '' );

		if ( '' === $name ) {
			return self::entry_error( $kind, 'no_name', '' );
		}

		$folded = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );

		if ( isset( $names[ $folded ] ) ) {
			return self::entry_error( $kind, 'same_name', $name );
		}

		$names[ $folded ] = true;

		$id = isset( $raw['id'] ) ? substr( sanitize_key( $raw['id'] ), 0, 32 ) : '';

		if ( '' === $id || isset( $ids[ $id ] ) ) {
			$id = self::new_id();
		}

		$ids[ $id ] = true;

		$entry = array(
			'id'          => $id,
			'name'        => $name,
			'description' => self::cut( sanitize_text_field( isset( $raw['description'] ) ? $raw['description'] : '' ), 250 ),
		);

		foreach ( array( 'spent_min', 'spent_max' ) as $bound ) {
			$value = self::parse_amount( isset( $raw[ $bound ] ) ? $raw[ $bound ] : '' );

			if ( false === $value ) {
				return self::entry_error( $kind, 'spent', $name );
			}

			$entry[ $bound ] = $value;
		}

		foreach ( array( 'orders_min', 'orders_max' ) as $bound ) {
			$value = self::parse_count( isset( $raw[ $bound ] ) ? $raw[ $bound ] : '' );

			if ( false === $value ) {
				return self::entry_error( $kind, 'orders', $name );
			}

			$entry[ $bound ] = $value;
		}

		foreach ( array( 'idle_min', 'idle_max' ) as $bound ) {
			$value = self::parse_count( isset( $raw[ $bound ] ) ? $raw[ $bound ] : '' );

			if ( false === $value ) {
				return self::entry_error( $kind, 'idle', $name );
			}

			$entry[ $bound ] = $value;
		}

		// No date of birth the club accepts makes anybody older than this.
		foreach ( array( 'age_min', 'age_max' ) as $bound ) {
			$value = self::parse_count( isset( $raw[ $bound ] ) ? $raw[ $bound ] : '' );

			if ( false === $value || ( '' !== $value && (int) $value > Dicex_Connect_Dates::MAX_AGE ) ) {
				return self::entry_error( $kind, 'age', $name );
			}

			$entry[ $bound ] = $value;
		}

		if ( ( '' !== $entry['spent_min'] && '' !== $entry['spent_max'] && (float) $entry['spent_min'] > (float) $entry['spent_max'] )
			|| ( '' !== $entry['orders_min'] && '' !== $entry['orders_max'] && (int) $entry['orders_min'] > (int) $entry['orders_max'] )
			|| ( '' !== $entry['idle_min'] && '' !== $entry['idle_max'] && (int) $entry['idle_min'] > (int) $entry['idle_max'] )
			|| ( '' !== $entry['age_min'] && '' !== $entry['age_max'] && (int) $entry['age_min'] > (int) $entry['age_max'] ) ) {
			return self::entry_error( $kind, 'range', $name );
		}

		foreach ( self::LISTS as $list ) {
			$entry[ $list ] = self::clean_list( $list, isset( $raw[ $list ] ) ? $raw[ $list ] : array() );
		}

		if ( 'level' === $kind ) {
			$entry['message'] = self::clean_message( isset( $raw['message'] ) ? $raw['message'] : '' );

			// The screen only shows the other languages on a site that has more than
			// one. Where it does not, saving leaves what is written in them alone.
			if ( isset( $raw['messages'] ) ) {
				$entry['messages'] = self::clean_messages( $raw['messages'] );
			} else {
				$entry['messages'] = isset( $stored[ $id ]['messages'] ) ? $stored[ $id ]['messages'] : array();
			}
		}

		// Without WooCommerce the screen shows no purchase conditions, so nothing
		// comes back for them. Saving then must not wipe what was set while it ran.
		if ( ! $woo && isset( $stored[ $id ] ) ) {
			foreach ( self::WOO_KEYS as $key ) {
				$entry[ $key ] = $stored[ $id ][ $key ];
			}
		}

		return $entry;
	}

	/**
	 * @param string $kind
	 * @param string $reason
	 * @param string $name
	 * @return WP_Error
	 */
	private static function entry_error( $kind, $reason, $name ) {
		$level = array(
			'no_name'   => __( 'Every level needs a name.', 'dicex-connect' ),
			/* translators: %s: a level name */
			'same_name' => __( 'Two levels are called "%s". Every level needs a name of its own.', 'dicex-connect' ),
			/* translators: %s: a level name */
			'spent'     => __( 'The purchase amounts for "%s" have to be numbers.', 'dicex-connect' ),
			/* translators: %s: a level name */
			'orders'    => __( 'The number of orders for "%s" has to be a whole number.', 'dicex-connect' ),
			/* translators: %s: a level name */
			'idle'      => __( 'The days since the last purchase for "%s" have to be a whole number.', 'dicex-connect' ),
			/* translators: 1: a level name, 2: the greatest age, such as 120 */
			'age'       => __( 'The ages for "%1$s" have to be whole numbers of years, up to %2$s.', 'dicex-connect' ),
			/* translators: %s: a level name */
			'range'     => __( 'In "%s", a "from" value is larger than its "to" value.', 'dicex-connect' ),
		);

		$group = array(
			'no_name'   => __( 'Every group needs a name.', 'dicex-connect' ),
			/* translators: %s: a group name */
			'same_name' => __( 'Two groups are called "%s". Every group needs a name of its own.', 'dicex-connect' ),
			/* translators: %s: a group name */
			'spent'     => __( 'The purchase amounts for the "%s" group have to be numbers.', 'dicex-connect' ),
			/* translators: %s: a group name */
			'orders'    => __( 'The number of orders for the "%s" group has to be a whole number.', 'dicex-connect' ),
			/* translators: %s: a group name */
			'idle'      => __( 'The days since the last purchase for the "%s" group have to be a whole number.', 'dicex-connect' ),
			/* translators: 1: a group name, 2: the greatest age, such as 120 */
			'age'       => __( 'The ages for the "%1$s" group have to be whole numbers of years, up to %2$s.', 'dicex-connect' ),
			/* translators: %s: a group name */
			'range'     => __( 'In the "%s" group, a "from" value is larger than its "to" value.', 'dicex-connect' ),
		);

		$messages = 'group' === $kind ? $group : $level;
		$code     = 'group' === $kind ? 'dicex_connect_club_group' : 'dicex_connect_club_level';

		if ( '' === $name ) {
			return new WP_Error( $code, $messages[ $reason ] );
		}

		// The age messages also say how old is too old.
		return new WP_Error(
			$code,
			'age' === $reason
				? sprintf( $messages[ $reason ], $name, number_format_i18n( Dicex_Connect_Dates::MAX_AGE ) )
				: sprintf( $messages[ $reason ], $name )
		);
	}

	/**
	 * Checks a submitted list of groups and returns the one to store.
	 *
	 * A group with no conditions would hold every customer in the club, which is
	 * what the levels are for, so it is refused instead.
	 *
	 * @param array $submitted
	 * @return array|WP_Error
	 */
	public static function clean_groups( $submitted ) {
		$groups = array();
		$names  = array();
		$ids    = array();
		$woo    = Dicex_Connect_Club_Sources::has_woocommerce();
		$stored = array();

		foreach ( self::get()['groups'] as $stored_group ) {
			$stored[ $stored_group['id'] ] = $stored_group;
		}

		foreach ( (array) $submitted as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$group = self::clean_entry( $raw, $names, $ids, $woo, $stored, 'group' );

			if ( is_wp_error( $group ) ) {
				return $group;
			}

			if ( ! self::has_conditions( $group ) ) {
				return new WP_Error(
					'dicex_connect_club_group',
					sprintf(
						/* translators: %s: a group name */
						__( 'The "%s" group needs at least one condition. A group without conditions would hold every customer.', 'dicex-connect' ),
						$group['name']
					)
				);
			}

			$groups[] = $group;
		}

		if ( count( $groups ) > self::MAX_GROUPS ) {
			return new WP_Error(
				'dicex_connect_club_group',
				sprintf(
					/* translators: %s: the largest number of groups allowed */
					__( 'A club can have at most %s groups.', 'dicex-connect' ),
					number_format_i18n( self::MAX_GROUPS )
				)
			);
		}

		return $groups;
	}

	/**
	 * @param mixed $raw
	 * @return string
	 */
	public static function clean_message( $raw ) {
		return self::cut( sanitize_textarea_field( is_scalar( $raw ) ? (string) $raw : '' ), self::MAX_MESSAGE );
	}

	/**
	 * Message texts by language, for a site that sells in more than one.
	 *
	 * @param mixed $raw
	 * @return array locale => text, empty texts left out.
	 */
	public static function clean_messages( $raw ) {
		$texts = array();

		foreach ( (array) $raw as $locale => $text ) {
			$locale = self::clean_locale( $locale );
			$text   = self::clean_message( $text );

			if ( '' !== $locale && '' !== $text ) {
				$texts[ $locale ] = $text;
			}
		}

		return $texts;
	}

	/**
	 * @param mixed $locale
	 * @return string A WordPress locale such as fa_IR, or ''.
	 */
	public static function clean_locale( $locale ) {
		$locale = is_scalar( $locale ) ? trim( (string) $locale ) : '';

		return preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9_-]{2,12})?$/', $locale ) ? $locale : '';
	}

	/**
	 * The text to send somebody, in the language they read.
	 *
	 * A text for their exact language wins; then one for the same language in
	 * another country, so a Persian customer in Afghanistan still gets Persian;
	 * then the text the owner wrote for everybody else.
	 *
	 * @param string $fallback
	 * @param array  $texts    locale => text.
	 * @param string $locale Optional.
	 * @return string
	 */
	public static function text_for_locale( $fallback, $texts, $locale ) {
		$texts  = is_array( $texts ) ? $texts : array();
		$locale = (string) $locale;

		if ( '' !== $locale && isset( $texts[ $locale ] ) ) {
			return $texts[ $locale ];
		}

		$language = strtok( $locale, '_' );

		foreach ( $texts as $key => $text ) {
			if ( '' !== $language && strtok( (string) $key, '_' ) === $language ) {
				return $text;
			}
		}

		return $fallback;
	}

	/**
	 * What to send somebody who has just moved up into a level, or '' for nothing.
	 *
	 * @param array  $level
	 * @param string $locale Optional.
	 * @return string
	 */
	public static function level_message( $level, $locale = '' ) {
		$default = isset( $level['message'] ) ? (string) $level['message'] : '';
		$texts   = isset( $level['messages'] ) ? $level['messages'] : array();

		return self::text_for_locale( $default, $texts, $locale );
	}

	/**
	 * One condition list as it may be stored: known values only, no duplicates.
	 *
	 * Terms and products are checked against the store, countries against
	 * WooCommerce's own list. A city is kept as the owner wrote it; spellings that
	 * reduce to the same city are stored once.
	 *
	 * @param string $list  From LISTS.
	 * @param mixed  $value What the screen sent.
	 * @return array
	 */
	private static function clean_list( $list, $value ) {
		$value = is_array( $value ) ? $value : array();

		if ( 'cities' === $list ) {
			$cities = array();

			foreach ( $value as $city ) {
				$city = self::cut( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( is_scalar( $city ) ? (string) $city : '' ) ) ), 100 );
				$key  = self::normalize_city( $city );

				if ( '' !== $key && ! isset( $cities[ $key ] ) ) {
					$cities[ $key ] = $city;
				}
			}

			return array_slice( array_values( $cities ), 0, self::MAX_LIST );
		}

		if ( 'countries' === $list ) {
			$known     = ( function_exists( 'WC' ) && isset( WC()->countries ) ) ? WC()->countries->get_countries() : array();
			$countries = array();

			foreach ( $value as $code ) {
				$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', is_scalar( $code ) ? (string) $code : '' ) );

				if ( 2 === strlen( $code ) && isset( $known[ $code ] ) ) {
					$countries[ $code ] = true;
				}
			}

			return array_slice( array_keys( $countries ), 0, self::MAX_LIST );
		}

		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', array_filter( $value, 'is_scalar' ) ) ) ) ), 0, self::MAX_LIST );

		if ( empty( $ids ) ) {
			return array();
		}

		if ( 'products' === $list ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return array();
			}

			return array_values(
				array_filter(
					$ids,
					function ( $id ) {
						return (bool) wc_get_product( $id );
					}
				)
			);
		}

		$taxonomy = 'brands' === $list ? 'product_brand' : 'product_cat';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		// Keep the owner's order, dropping anything the store no longer has.
		return is_wp_error( $found ) ? array() : array_values( array_intersect( $ids, array_map( 'intval', $found ) ) );
	}

	/**
	 * A ready-made set to start from. The names are translated once, when the
	 * owner picks the set; after saving they are data like any other name.
	 *
	 * @return array Levels, plus which one is the default and which the removed one.
	 */
	public static function starter_set() {
		$blank = self::blank_level();

		$levels = array(
			array_merge( $blank, array( 'id' => 'gold', 'name' => __( 'Gold', 'dicex-connect' ) ) ),
			array_merge( $blank, array( 'id' => 'silver', 'name' => __( 'Silver', 'dicex-connect' ) ) ),
			array_merge( $blank, array( 'id' => 'bronze', 'name' => __( 'Bronze', 'dicex-connect' ) ) ),
			array_merge( $blank, array( 'id' => 'member', 'name' => __( 'Regular member', 'dicex-connect' ) ) ),
			array_merge( $blank, array( 'id' => 'removed', 'name' => __( 'Removed', 'dicex-connect' ) ) ),
		);

		// Nobody who has not bought in half a year is anybody's gold customer: the
		// level a win-back campaign is sent to, checked first. It needs purchases to
		// mean anything, so only a store gets it.
		if ( Dicex_Connect_Club_Sources::has_woocommerce() ) {
			array_unshift(
				$levels,
				array_merge(
					$blank,
					array(
						'id'       => 'inactive',
						'name'     => __( 'Inactive', 'dicex-connect' ),
						'idle_min' => '180',
					)
				)
			);
		}

		return array(
			'levels'        => $levels,
			'default_level' => 'member',
			'removed_level' => 'removed',
		);
	}

	/**
	 * @return string
	 */
	public static function new_id() {
		return 'lvl_' . strtolower( wp_generate_password( 10, false, false ) );
	}

	/**
	 * The description DiceX keeps for a level. It refuses an empty one.
	 *
	 * @param array $settings
	 * @param array $level
	 * @return string
	 */
	public static function level_description( $settings, $level ) {
		if ( '' !== $level['description'] ) {
			return $level['description'];
		}

		if ( $level['id'] === $settings['removed_level'] ) {
			return __( 'Numbers taken out of the club. Nobody in this level is contacted.', 'dicex-connect' );
		}

		return sprintf(
			/* translators: %s: the site's name */
			__( 'Customer club level on %s.', 'dicex-connect' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * The description DiceX keeps for a group, so somebody reading the panel knows
	 * where the contacts came from.
	 *
	 * @param array $group
	 * @return string
	 */
	public static function group_description( $group ) {
		if ( '' !== (string) $group['description'] ) {
			return (string) $group['description'];
		}

		return sprintf(
			/* translators: %s: the site's name */
			__( 'Customer group on %s.', 'dicex-connect' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * @param array  $settings
	 * @param string $locale Optional.
	 * @return string
	 */
	public static function welcome_template( $settings, $locale = '' ) {
		$template = isset( $settings['welcome']['template'] ) ? trim( (string) $settings['welcome']['template'] ) : '';
		$template = self::text_for_locale( $template, isset( $settings['welcome']['templates'] ) ? $settings['welcome']['templates'] : array(), $locale );

		return '' !== $template ? $template : __( 'Hi {first_name}, welcome to the {site_name} customer club. Your level: {level}.', 'dicex-connect' );
	}

	/**
	 * The oldest moment a purchase still counts from, in UTC.
	 *
	 * @param array $settings
	 * @return string MySQL datetime.
	 */
	public static function window_start_gmt( $settings ) {
		$months = (int) $settings['window_months'];

		if ( $months <= 0 ) {
			return '1970-01-01 00:00:00';
		}

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		return $now->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param string $time
	 * @return string HH:MM, or the default when it is not one.
	 */
	public static function clean_time( $time ) {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time ) ? (string) $time : '03:00';
	}

	/**
	 * @return array Role slug => translated name.
	 */
	public static function role_options() {
		$options = array();

		foreach ( wp_roles()->get_names() as $role => $name ) {
			$options[ $role ] = translate_user_role( $name );
		}

		return $options;
	}
}
