<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The club's own three tables, and every query against them.
 *
 * members  One row per mobile number — the key DiceX itself uses. Which level
 *          the number is in, what was last sent, whether sending worked.
 * orders   One row per WooCommerce order, reduced to what the levels are
 *          decided by: who, whether it was paid, how much, how much came back,
 *          and the billing country and city.
 * items    Which products each order bought, by parent product id. Categories
 *          and brands are looked up from these when a level asks, so moving a
 *          product to another category needs no rewrite here.
 *
 * Why tables rather than meta: a guest has no user row to hang anything on, the
 * rate limit means only what changed may be sent, and deciding a level across
 * every customer has to be a query rather than a loop over orders. Nothing here
 * writes to WooCommerce's own orders or to any other plugin's data.
 *
 * The SQL is kept to what MySQL, MariaDB and WordPress's SQLite driver all run —
 * no UPDATE with ORDER BY or JOIN — so what is tested on Playground is what runs
 * on a real host.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- These are this plugin's own tables. Their names come from $wpdb->prefix, every value goes through $wpdb->prepare(), and the data changes on every sync, so an object cache would only serve stale rows. NotPrepared is here for the same reason as its sibling: a sniff cannot tell a table name built from a prefix from a value typed by somebody, and this file is where every query in the plugin lives.
class Dicex_Connect_Club_Store {

	/**
	 * Raise when the table definitions below change; install() runs again.
	 *
	 * 2: orders and members gained country and city, and the items table arrived.
	 * 3: members gained a language, and group memberships got a table.
	 * 4: members keep their date of birth and its day in their own calendar.
	 */
	const DB_VERSION = 4;

	/**
	 * Raise only when orders have to be read from WooCommerce again, which is a
	 * far heavier thing than a new column. A site that imported under an older
	 * number re-imports once — see Dicex_Connect_Club_Sync::tick().
	 *
	 * 2: orders needed country, city and their products (1.2.0).
	 */
	const IMPORT_SCHEMA = 2;

	const DB_OPTION = 'dicex_connect_club_db';

	/** @var bool Checked once per request. */
	private static $checked = false;

	/**
	 * @return string
	 */
	public static function members_table() {
		global $wpdb;

		return $wpdb->prefix . 'dicex_connect_club_members';
	}

	/**
	 * @return string
	 */
	public static function orders_table() {
		global $wpdb;

		return $wpdb->prefix . 'dicex_connect_club_orders';
	}

	/**
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;

		return $wpdb->prefix . 'dicex_connect_club_order_items';
	}

	/**
	 * @return string
	 */
	public static function group_table() {
		global $wpdb;

		return $wpdb->prefix . 'dicex_connect_club_group_members';
	}

	/**
	 * Whether the tables have ever been created on this site.
	 *
	 * @return bool
	 */
	public static function exists() {
		return (int) get_option( self::DB_OPTION, 0 ) > 0;
	}

	/**
	 * Creates or updates the tables when the stored version is behind.
	 *
	 * Activation does not run on a plugin update, so this is checked where the
	 * tables are about to be used, the way the plugin handbook describes.
	 */
	public static function maybe_install() {
		if ( self::$checked ) {
			return;
		}

		$installed = (int) get_option( self::DB_OPTION, 0 );

		if ( $installed !== self::DB_VERSION ) {
			self::install();

			// Members from before 1.5.0 have no date of birth on their row. One look at
			// everybody, in the background runs, fills it in; nothing reaches DiceX from
			// it, since what DiceX is sent has not changed.
			if ( $installed > 0 && $installed < 4 ) {
				self::mark_all_dirty();
			}
		}

		self::$checked = true;
	}

	/**
	 * dbDelta() is strict about layout: one field per line, two spaces after
	 * PRIMARY KEY, KEY rather than INDEX, lowercase types.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$members         = self::members_table();
		$orders          = self::orders_table();
		$items           = self::items_table();
		$groups          = self::group_table();

		$sql = "CREATE TABLE $members (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mobile varchar(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  display_name varchar(191) NOT NULL DEFAULT '',
  locale varchar(20) NOT NULL DEFAULT '',
  birthday date DEFAULT NULL,
  birth_md varchar(6) NOT NULL DEFAULT '',
  country varchar(2) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  level_id varchar(32) NOT NULL DEFAULT '',
  locked tinyint(1) unsigned NOT NULL DEFAULT 0,
  order_count int(10) unsigned NOT NULL DEFAULT 0,
  net_spent decimal(26,8) NOT NULL DEFAULT 0,
  first_order_gmt datetime DEFAULT NULL,
  last_order_gmt datetime DEFAULT NULL,
  payload longtext,
  dirty tinyint(1) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'new',
  claim varchar(32) NOT NULL DEFAULT '',
  attempt_hash varchar(32) NOT NULL DEFAULT '',
  profile_hash varchar(32) NOT NULL DEFAULT '',
  sent_level varchar(191) NOT NULL DEFAULT '',
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_error text,
  welcome varchar(10) NOT NULL DEFAULT 'skip',
  created_gmt datetime DEFAULT NULL,
  synced_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY mobile (mobile),
  KEY user_id (user_id),
  KEY status (status),
  KEY dirty (dirty),
  KEY level_id (level_id),
  KEY birth_md (birth_md)
) $charset_collate;
CREATE TABLE $orders (
  order_id bigint(20) unsigned NOT NULL,
  mobile varchar(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(32) NOT NULL DEFAULT '',
  paid tinyint(1) unsigned NOT NULL DEFAULT 0,
  total decimal(26,8) NOT NULL DEFAULT 0,
  refunded decimal(26,8) NOT NULL DEFAULT 0,
  currency varchar(10) NOT NULL DEFAULT '',
  created_gmt datetime DEFAULT NULL,
  country varchar(2) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (order_id),
  KEY mobile (mobile),
  KEY user_id (user_id)
) $charset_collate;
CREATE TABLE $items (
  order_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (order_id,product_id),
  KEY product_id (product_id)
) $charset_collate;
CREATE TABLE $groups (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  group_key varchar(32) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  matched tinyint(1) unsigned NOT NULL DEFAULT 1,
  claim varchar(32) NOT NULL DEFAULT '',
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_error text,
  added_gmt datetime DEFAULT NULL,
  changed_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY member_group (member_id,group_key),
  KEY status (status),
  KEY group_key (group_key)
) $charset_collate;";

		dbDelta( $sql );

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ---- Orders --------------------------------------------------------- */

	/**
	 * @param int $order_id
	 * @return object|null
	 */
	public static function get_order( $order_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::orders_table() . ' WHERE order_id = %d', $order_id ) );
	}

	/**
	 * @param array $row order_id, mobile, user_id, status, paid, total, refunded, currency, created_gmt, country, city — in that order.
	 */
	public static function save_order( $row ) {
		global $wpdb;

		$wpdb->replace(
			self::orders_table(),
			$row,
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Replaces the products recorded for an order.
	 *
	 * @param int   $order_id
	 * @param array $product_ids Parent product ids.
	 */
	public static function save_order_items( $order_id, $product_ids ) {
		global $wpdb;

		$table       = self::items_table();
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );

		$wpdb->delete( $table, array( 'order_id' => (int) $order_id ), array( '%d' ) );

		foreach ( array_chunk( $product_ids, 100 ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $product_id ) {
				$values[] = (int) $order_id;
				$values[] = $product_id;
			}

			$wpdb->query( $wpdb->prepare( "INSERT INTO $table ( order_id, product_id ) VALUES " . implode( ', ', array_fill( 0, count( $chunk ), '( %d, %d )' ) ), $values ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- two %d per product, built on the same line.
		}
	}

	/**
	 * The products a member bought, over the paid orders inside the window.
	 *
	 * @param string $mobile
	 * @param int    $user_id
	 * @param string $since_gmt
	 * @return int[]
	 */
	public static function product_ids( $mobile, $user_id, $since_gmt ) {
		global $wpdb;

		$items  = self::items_table();
		$orders = self::orders_table();

		if ( $user_id > 0 ) {
			$who  = '( o.user_id = %d OR ( o.user_id = 0 AND o.mobile = %s ) )';
			$args = array( $since_gmt, $user_id, $mobile );
		} else {
			$who  = '( o.user_id = 0 AND o.mobile = %s )';
			$args = array( $since_gmt, $mobile );
		}

		$sql = "SELECT DISTINCT i.product_id FROM $items i INNER JOIN $orders o ON o.order_id = i.order_id WHERE o.paid = 1 AND o.created_gmt >= %s AND $who";

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $who carries its own placeholders, filled from $args.
	}

	/**
	 * Where a member bought from most recently: the billing country and city of
	 * their newest paid order that has either.
	 *
	 * @param string $mobile
	 * @param int    $user_id
	 * @return array country, city — empty strings when unknown.
	 */
	public static function latest_location( $mobile, $user_id ) {
		global $wpdb;

		$orders = self::orders_table();

		if ( $user_id > 0 ) {
			$who  = '( user_id = %d OR ( user_id = 0 AND mobile = %s ) )';
			$args = array( $user_id, $mobile );
		} else {
			$who  = '( user_id = 0 AND mobile = %s )';
			$args = array( $mobile );
		}

		$sql = "SELECT country, city FROM $orders WHERE paid = 1 AND ( country <> '' OR city <> '' ) AND $who ORDER BY created_gmt DESC, order_id DESC LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $who carries its own placeholders, filled from $args.

		return array(
			'country' => isset( $row['country'] ) ? (string) $row['country'] : '',
			'city'    => isset( $row['city'] ) ? (string) $row['city'] : '',
		);
	}

	/**
	 * Marks for another look everybody who ever bought this product.
	 *
	 * Used when a product moves to another category or brand: buying it may now
	 * mean something else for their level.
	 *
	 * @param int $product_id
	 */
	public static function mark_dirty_for_product( $product_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT o.user_id, o.mobile FROM ' . self::items_table() . ' i INNER JOIN ' . self::orders_table() . ' o ON o.order_id = i.order_id WHERE i.product_id = %d',
				$product_id
			)
		);

		self::mark_dirty_rows( $rows );
	}

	/**
	 * Forgets an order that was deleted or trashed.
	 *
	 * @param int $order_id
	 * @return object|null What the row was, so its customer can be looked at again.
	 */
	public static function delete_order( $order_id ) {
		global $wpdb;

		$row = self::get_order( $order_id );

		if ( null !== $row ) {
			$wpdb->delete( self::orders_table(), array( 'order_id' => (int) $order_id ), array( '%d' ) );
			$wpdb->delete( self::items_table(), array( 'order_id' => (int) $order_id ), array( '%d' ) );
		}

		return $row;
	}

	/**
	 * Totals for one member, over the purchases that count.
	 *
	 * A registered customer's purchases are theirs by account, whatever number
	 * they typed at checkout, plus any made as a guest with their number. A guest's
	 * are the ones made without an account under that number. Only paid orders
	 * count, only in the store's currency, and only inside the chosen window —
	 * except the first purchase date, which is the customer's anniversary whenever
	 * it was.
	 *
	 * @param string $mobile
	 * @param int    $user_id
	 * @param string $currency
	 * @param string $since_gmt
	 * @return array order_count, net_spent, first_order_gmt, last_order_gmt
	 */
	public static function stats( $mobile, $user_id, $currency, $since_gmt ) {
		global $wpdb;

		$table = self::orders_table();

		if ( $user_id > 0 ) {
			$who  = '( user_id = %d OR ( user_id = 0 AND mobile = %s ) )';
			$args = array( $user_id, $mobile );
		} else {
			$who  = '( user_id = 0 AND mobile = %s )';
			$args = array( $mobile );
		}

		$sql = "SELECT
				SUM( CASE WHEN currency = %s AND created_gmt >= %s THEN 1 ELSE 0 END ) AS order_count,
				SUM( CASE WHEN currency = %s AND created_gmt >= %s THEN total - refunded ELSE 0 END ) AS net_spent,
				MIN( created_gmt ) AS first_order_gmt,
				MAX( created_gmt ) AS last_order_gmt
			FROM $table
			WHERE paid = 1 AND $who";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $currency, $since_gmt, $currency, $since_gmt ), $args ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $who carries its own placeholders, filled from $args.

		return array(
			'order_count'     => isset( $row['order_count'] ) ? (int) $row['order_count'] : 0,
			'net_spent'       => isset( $row['net_spent'] ) ? max( 0, (float) $row['net_spent'] ) : 0.0,
			'first_order_gmt' => empty( $row['first_order_gmt'] ) ? null : $row['first_order_gmt'],
			'last_order_gmt'  => empty( $row['last_order_gmt'] ) ? null : $row['last_order_gmt'],
		);
	}

	/**
	 * @param int $user_id
	 * @return string The usable phone on the newest order this account placed, or ''.
	 */
	public static function latest_user_order_mobile( $user_id ) {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT mobile FROM ' . self::orders_table() . " WHERE user_id = %d AND mobile <> '' ORDER BY created_gmt DESC, order_id DESC LIMIT 1",
				$user_id
			)
		);
	}

	/**
	 * The guest behind a number is whoever last paid under it.
	 *
	 * Only a paid order counts. Anybody can place an unpaid one with somebody
	 * else's number, and that must not rewrite the name, the email or the date of
	 * birth DiceX holds for them.
	 *
	 * @param string $mobile
	 * @return int The newest paid order placed without an account under this number, or 0.
	 */
	public static function latest_guest_order_id( $mobile ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT order_id FROM ' . self::orders_table() . ' WHERE user_id = 0 AND paid = 1 AND mobile = %s ORDER BY order_id DESC LIMIT 1',
				$mobile
			)
		);
	}

	/**
	 * @param int    $user_id
	 * @param string $mobile
	 * @return bool Whether this account has paid for an order under this number.
	 */
	public static function user_paid_with_mobile( $user_id, $mobile ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT order_id FROM ' . self::orders_table() . ' WHERE user_id = %d AND paid = 1 AND mobile = %s LIMIT 1',
				$user_id,
				$mobile
			)
		);
	}

	/**
	 * Takes the phone and the place off the club's copy of these orders — what
	 * Erase Personal Data asks of it. Totals stay: without a number they are
	 * nobody's.
	 *
	 * @param int[] $order_ids
	 * @return int How many of the club's order rows still had a phone or a place.
	 */
	public static function forget_order_contacts( $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $order_ids ) ) ) );
		$changed   = 0;

		foreach ( array_chunk( $order_ids, 200 ) as $chunk ) {
			$changed += (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::orders_table() . " SET mobile = '', country = '', city = '' WHERE ( mobile <> '' OR country <> '' OR city <> '' ) AND order_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
		}

		return $changed;
	}

	/**
	 * @param int $user_id
	 * @param int $limit
	 * @return int[] Orders this account placed, newest first.
	 */
	public static function user_order_ids( $user_id, $limit ) {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT order_id FROM ' . self::orders_table() . ' WHERE user_id = %d ORDER BY created_gmt DESC, order_id DESC LIMIT %d',
					$user_id,
					$limit
				)
			)
		);
	}

	/**
	 * @param string $mobile
	 * @param int    $limit
	 * @return int[] Paid orders placed without an account under this number, newest first.
	 */
	public static function guest_order_ids( $mobile, $limit ) {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT order_id FROM ' . self::orders_table() . ' WHERE user_id = 0 AND paid = 1 AND mobile = %s ORDER BY order_id DESC LIMIT %d',
					$mobile,
					$limit
				)
			)
		);
	}

	/**
	 * @param string $mobile
	 * @return bool Whether a guest has paid for anything under this number.
	 */
	public static function has_paid_guest_order( $mobile ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT order_id FROM ' . self::orders_table() . ' WHERE user_id = 0 AND paid = 1 AND mobile = %s LIMIT 1',
				$mobile
			)
		);
	}

	/**
	 * Marks for another look every member with a paid purchase inside a period.
	 *
	 * The daily run uses it on the day that just left the purchase window: those
	 * customers' totals went down without anything happening to them.
	 *
	 * @param string $from_gmt Exclusive.
	 * @param string $to_gmt   Inclusive.
	 */
	public static function mark_dirty_for_orders_between( $from_gmt, $to_gmt ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT user_id, mobile FROM ' . self::orders_table() . ' WHERE paid = 1 AND created_gmt > %s AND created_gmt <= %s',
				$from_gmt,
				$to_gmt
			)
		);

		self::mark_dirty_rows( $rows );
	}

	/**
	 * Marks for another look every member whose last paid purchase falls in a
	 * period.
	 *
	 * The daily run uses it for "days since the last purchase": whoever's last
	 * purchase is exactly that many days old since the previous run has crossed
	 * the line without anything happening to them.
	 *
	 * @param string $from_gmt Exclusive.
	 * @param string $to_gmt   Inclusive.
	 */
	public static function mark_dirty_for_last_order_between( $from_gmt, $to_gmt ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::members_table() . ' SET dirty = 1 WHERE last_order_gmt > %s AND last_order_gmt <= %s',
				$from_gmt,
				$to_gmt
			)
		);
	}

	/**
	 * Marks dirty the members whose birthday is one of these days — somebody a
	 * year older may now meet an age condition, or no longer meet one.
	 *
	 * @param string[] $keys From Dicex_Connect_Dates::birthday_keys_for().
	 */
	public static function mark_dirty_for_birthdays( $keys ) {
		global $wpdb;

		$keys = array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );

		if ( empty( $keys ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::members_table() . ' SET dirty = 1 WHERE birth_md IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per key, built on the same line.
				$keys
			)
		);
	}

	/**
	 * Marks dirty the members behind rows of user_id and mobile: by account for a
	 * registered customer, by number for a guest.
	 *
	 * @param array $rows Objects with user_id and mobile.
	 */
	private static function mark_dirty_rows( $rows ) {
		global $wpdb;

		$user_ids = array();
		$mobiles  = array();

		foreach ( (array) $rows as $row ) {
			if ( (int) $row->user_id > 0 ) {
				$user_ids[] = (int) $row->user_id;
			} elseif ( '' !== $row->mobile ) {
				$mobiles[] = $row->mobile;
			}
		}

		foreach ( array_chunk( array_unique( $user_ids ), 200 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::members_table() . ' SET dirty = 1 WHERE user_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
		}

		foreach ( array_chunk( array_unique( $mobiles ), 200 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::members_table() . ' SET dirty = 1 WHERE mobile IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per number, built on the same line.
		}
	}

	/* ---- Members -------------------------------------------------------- */

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function member( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * @param string $mobile
	 * @return object|null
	 */
	public static function member_by_mobile( $mobile ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE mobile = %s', $mobile ) );
	}

	/**
	 * @param array $mobiles
	 * @return array
	 */
	public static function members_by_mobiles( $mobiles ) {
		global $wpdb;

		$mobiles = array_values( array_unique( array_filter( (array) $mobiles ) ) );

		if ( empty( $mobiles ) ) {
			return array();
		}

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE mobile IN (' . implode( ',', array_fill( 0, count( $mobiles ), '%s' ) ) . ')', $mobiles ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per number, built on the same line.
	}

	/**
	 * @param int $user_id
	 * @return array
	 */
	public static function members_of_user( $user_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE user_id = %d', $user_id ) );
	}

	/**
	 * Adds a number to the club, or finds it if it is there already.
	 *
	 * @param string $mobile
	 * @param int    $user_id
	 * @param string $welcome 'due' when this is a new customer who may be greeted, 'skip' otherwise.
	 * @return object|null
	 */
	public static function add_member( $mobile, $user_id, $welcome ) {
		global $wpdb;

		$existing = self::member_by_mobile( $mobile );

		if ( null !== $existing ) {
			return $existing;
		}

		// Two requests can reach here for the same number at once. The unique key
		// turns the second insert into a failure, which is fine: the row exists.
		$suppress = $wpdb->suppress_errors( true );

		$wpdb->insert(
			self::members_table(),
			array(
				'mobile'      => $mobile,
				'user_id'     => (int) $user_id,
				'dirty'       => 1,
				'status'      => 'new',
				'welcome'     => 'due' === $welcome ? 'due' : 'skip',
				'created_gmt' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( $suppress );

		return self::member_by_mobile( $mobile );
	}

	/**
	 * @param int   $id
	 * @param array $data Column => value. A null is written as NULL.
	 */
	public static function update_member( $id, $data ) {
		global $wpdb;

		$wpdb->update( self::members_table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $id
	 */
	public static function mark_dirty( $id ) {
		self::update_member( $id, array( 'dirty' => 1 ) );
	}

	/**
	 * Everyone gets looked at again — after the levels or what is sent change.
	 */
	public static function mark_all_dirty() {
		global $wpdb;

		$wpdb->query( 'UPDATE ' . self::members_table() . ' SET dirty = 1' );
	}

	/**
	 * Somebody who left the club ticked the box again: a hand placement in the
	 * level for removed numbers no longer holds them there. A placement anywhere
	 * else still stands.
	 *
	 * @param int    $user_id
	 * @param string $removed_level_id
	 */
	public static function unlock_removed( $user_id, $removed_level_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::members_table() . ' SET locked = 0, dirty = 1 WHERE user_id = %d AND level_id = %s AND locked = 1',
				$user_id,
				$removed_level_id
			)
		);
	}

	/**
	 * A user is no longer known by these numbers: their account changed its
	 * number, or was deleted. The numbers stay in the club — DiceX keeps them —
	 * and from now on only count their guest purchases.
	 *
	 * A welcome still waiting for one of them is dropped: the account has moved
	 * on, and greeting every number it ever typed is how a stranger's phone gets
	 * messages from this shop.
	 *
	 * @param int    $user_id
	 * @param string $keep_mobile The number the user is known by now, or '' for none.
	 */
	public static function detach_user( $user_id, $keep_mobile ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::members_table() . " SET user_id = 0, dirty = 1, welcome = CASE WHEN welcome = 'due' THEN 'skip' ELSE welcome END WHERE user_id = %d AND mobile <> %s",
				$user_id,
				(string) $keep_mobile
			)
		);
	}

	/**
	 * @param int $limit
	 * @return array Members waiting to be worked out again.
	 */
	public static function dirty_members( $limit ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE dirty = 1 ORDER BY id ASC LIMIT %d', $limit ) );
	}

	/**
	 * Takes up to $limit members waiting to be sent, for this run only.
	 *
	 * Selecting first and then updating by id keeps to SQL every database here
	 * runs. The club's lock means one run at a time; the status test in the
	 * update is what stops a member being taken twice if that ever fails.
	 *
	 * @param int    $limit
	 * @param string $token
	 * @return array
	 */
	public static function claim_pending( $limit, $token ) {
		global $wpdb;

		$table = self::members_table();
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status = 'pending' AND dirty = 0 ORDER BY id ASC LIMIT %d", $limit ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'sending', claim = %s WHERE status = 'pending' AND id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
				array_merge( array( $token ), $ids )
			)
		);

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = 'sending' AND claim = %s ORDER BY id ASC", $token ) );
	}

	/**
	 * Puts members taken by a run back in the queue, untouched.
	 *
	 * @param array $ids
	 */
	public static function release( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::members_table() . " SET status = 'pending', claim = '' WHERE status = 'sending' AND id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
	}

	/**
	 * A run that died half way leaves members marked as being sent. Only one run
	 * holds the lock, so anything still marked when a run starts is left over.
	 */
	public static function release_abandoned() {
		global $wpdb;

		$wpdb->query( 'UPDATE ' . self::members_table() . " SET status = 'pending', claim = '' WHERE status = 'sending'" );
	}

	/**
	 * Every member whose send was refused goes back in the queue.
	 *
	 * @return int How many.
	 */
	public static function retry_errors() {
		global $wpdb;

		return (int) $wpdb->query( 'UPDATE ' . self::members_table() . " SET status = 'pending', attempt_hash = '' WHERE status = 'error'" );
	}

	/**
	 * Takes the right to greet a member. Exactly one caller ever gets it.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function claim_welcome( $id ) {
		global $wpdb;

		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::members_table() . " SET welcome = 'sending' WHERE id = %d AND welcome = 'due'", $id ) );
	}

	/**
	 * @param int $user_id
	 * @return bool Whether a number this account is known by was welcomed.
	 */
	public static function user_welcomed( $user_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::members_table() . " WHERE user_id = %d AND welcome IN ( 'sending', 'sent' ) LIMIT 1", $user_id ) );
	}

	/**
	 * @return array Level id => how many members.
	 */
	public static function counts_by_level() {
		global $wpdb;

		$counts = array();

		foreach ( (array) $wpdb->get_results( 'SELECT level_id, COUNT(*) AS members FROM ' . self::members_table() . ' GROUP BY level_id' ) as $row ) {
			$counts[ (string) $row->level_id ] = (int) $row->members;
		}

		return $counts;
	}

	/**
	 * @return array Status => how many members.
	 */
	public static function counts_by_status() {
		global $wpdb;

		$counts = array();

		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS members FROM ' . self::members_table() . ' GROUP BY status' ) as $row ) {
			$counts[ (string) $row->status ] = (int) $row->members;
		}

		return $counts;
	}

	/**
	 * The next members after an id, for counting through the whole club a page
	 * at a time.
	 *
	 * @param int $after_id
	 * @param int $limit
	 * @return array
	 */
	public static function members_after( $after_id, $limit ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', $after_id, $limit ) );
	}

	/**
	 * @return int
	 */
	public static function member_count() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::members_table() );
	}

	/**
	 * @return int
	 */
	public static function dirty_count() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::members_table() . ' WHERE dirty = 1' );
	}

	/* ---- Group memberships ---------------------------------------------- */

	/**
	 * Writes down which groups a member matches now.
	 *
	 * Somebody who matches a group they are already in stays as they are; DiceX is
	 * only ever asked once. Somebody who stops matching keeps their row, marked as
	 * no longer matching: the contact is still in the group at DiceX, which has no
	 * way to take them out, and the row is what says so.
	 *
	 * @param int   $member_id
	 * @param array $keys Group ids the member matches.
	 */
	public static function set_group_matches( $member_id, $keys ) {
		global $wpdb;

		$table     = self::group_table();
		$member_id = (int) $member_id;
		$keys      = array_values( array_unique( array_map( 'strval', (array) $keys ) ) );
		$now       = current_time( 'mysql', true );
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, group_key, matched FROM $table WHERE member_id = %d", $member_id ) );
		$known     = array();

		foreach ( $rows as $row ) {
			$known[ (string) $row->group_key ] = $row;
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $known[ $key ] ) ) {
				$wpdb->insert(
					$table,
					array(
						'member_id'   => $member_id,
						'group_key'   => $key,
						'status'      => 'pending',
						'matched'     => 1,
						'changed_gmt' => $now,
					),
					array( '%d', '%s', '%s', '%d', '%s' )
				);
				continue;
			}

			if ( 0 === (int) $known[ $key ]->matched ) {
				$wpdb->update( $table, array( 'matched' => 1, 'changed_gmt' => $now ), array( 'id' => (int) $known[ $key ]->id ), array( '%d', '%s' ), array( '%d' ) );
			}
		}

		foreach ( $known as $key => $row ) {
			if ( ! in_array( $key, $keys, true ) && 1 === (int) $row->matched ) {
				$wpdb->update( $table, array( 'matched' => 0, 'changed_gmt' => $now ), array( 'id' => (int) $row->id ), array( '%d', '%s' ), array( '%d' ) );
			}
		}
	}

	/**
	 * Takes up to $limit memberships waiting to reach DiceX, for this run only.
	 *
	 * @param int    $limit
	 * @param string $token
	 * @return array Rows with the member's number and payload alongside.
	 */
	public static function claim_group_adds( $limit, $token ) {
		global $wpdb;

		$table   = self::group_table();
		$members = self::members_table();
		$ids     = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM $table WHERE status = 'pending' AND matched = 1 ORDER BY id ASC LIMIT %d", $limit )
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'sending', claim = %s WHERE status = 'pending' AND id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
				array_merge( array( $token ), $ids )
			)
		);

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id, g.member_id, g.group_key, m.mobile, m.payload, m.status AS member_status FROM $table g INNER JOIN $members m ON m.id = g.member_id WHERE g.status = 'sending' AND g.claim = %s ORDER BY g.id ASC",
				$token
			)
		);
	}

	/**
	 * @param int $id
	 * @param int $contact_id
	 */
	public static function group_added( $id, $contact_id ) {
		global $wpdb;

		$wpdb->update(
			self::group_table(),
			array(
				'status'     => 'synced',
				'claim'      => '',
				'contact_id' => (int) $contact_id,
				'last_error' => '',
				'added_gmt'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $id
	 * @param string $error
	 */
	public static function group_failed( $id, $error ) {
		global $wpdb;

		$wpdb->update(
			self::group_table(),
			array(
				'status'     => 'error',
				'claim'      => '',
				'last_error' => (string) $error,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param array $ids
	 */
	public static function release_groups( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::group_table() . " SET status = 'pending', claim = '' WHERE status = 'sending' AND id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
	}

	/**
	 * A run that died half way leaves memberships marked as being sent.
	 */
	public static function release_abandoned_groups() {
		global $wpdb;

		$wpdb->query( 'UPDATE ' . self::group_table() . " SET status = 'pending', claim = '' WHERE status = 'sending'" );
	}

	/**
	 * @return int How many memberships were put back in the queue.
	 */
	public static function retry_group_errors() {
		global $wpdb;

		return (int) $wpdb->query( 'UPDATE ' . self::group_table() . " SET status = 'pending', last_error = '' WHERE status = 'error' AND matched = 1" );
	}

	/**
	 * @return int Memberships still to reach DiceX.
	 */
	public static function group_waiting_count() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::group_table() . " WHERE matched = 1 AND status IN ( 'pending', 'sending' )" );
	}

	/**
	 * @return array group id => array( in, waiting, stale )
	 */
	public static function group_counts() {
		global $wpdb;

		$counts = array();

		$rows = (array) $wpdb->get_results(
			'SELECT group_key, matched, status, COUNT(*) AS members FROM ' . self::group_table() . ' GROUP BY group_key, matched, status'
		);

		foreach ( $rows as $row ) {
			$key = (string) $row->group_key;

			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = array(
					'in'      => 0,
					'waiting' => 0,
					'stale'   => 0,
				);
			}

			if ( 1 === (int) $row->matched ) {
				$counts[ $key ]['in'] += (int) $row->members;

				if ( 'synced' !== $row->status ) {
					$counts[ $key ]['waiting'] += (int) $row->members;
				}
			} elseif ( 'synced' === $row->status ) {
				// Sent to DiceX once and no longer matching: DiceX cannot remove them.
				$counts[ $key ]['stale'] += (int) $row->members;
			}
		}

		return $counts;
	}

	/**
	 * @param int  $member_id
	 * @param bool $matched_only
	 * @return array group id => status
	 */
	public static function member_groups( $member_id, $matched_only = true ) {
		global $wpdb;

		$sql  = 'SELECT group_key, status, matched FROM ' . self::group_table() . ' WHERE member_id = %d';
		$sql .= $matched_only ? ' AND matched = 1' : '';
		$out  = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, (int) $member_id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolation is a fixed clause above.
			$out[ (string) $row->group_key ] = 1 === (int) $row->matched ? (string) $row->status : 'left';
		}

		return $out;
	}

	/**
	 * The groups of a page of members, in one query rather than one each.
	 *
	 * @param array $ids Member ids.
	 * @return array member id => array( group id => status, or 'left' )
	 */
	public static function groups_for_members( $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		$out = array();

		if ( empty( $ids ) ) {
			return $out;
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT member_id, group_key, status, matched FROM ' . self::group_table() . ' WHERE member_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id, built on the same line.
				$ids
			)
		);

		foreach ( $rows as $row ) {
			$out[ (int) $row->member_id ][ (string) $row->group_key ] = 1 === (int) $row->matched ? (string) $row->status : 'left';
		}

		return $out;
	}

	/**
	 * Forgets a group the owner deleted from the settings. DiceX keeps its own
	 * group and everybody in it; this only stops the plugin counting them.
	 *
	 * @param array $keys Group ids that still exist.
	 */
	public static function forget_missing_groups( $keys ) {
		global $wpdb;

		$keys = array_values( array_unique( array_map( 'strval', (array) $keys ) ) );

		if ( empty( $keys ) ) {
			$wpdb->query( 'DELETE FROM ' . self::group_table() ); // phpcs:ignore
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::group_table() . ' WHERE group_key NOT IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per group, built on the same line.
				$keys
			)
		);
	}

	/**
	 * One page of the members list.
	 *
	 * @param array $args search, level, status, page, per_page.
	 * @return array rows, total
	 */
	public static function list_members( $args ) {
		global $wpdb;

		$table  = self::members_table();
		$where  = array( '1 = 1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( mobile LIKE %s OR display_name LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $args['level'] ) {
			$where[]  = 'level_id = %s';
			$params[] = $args['level'];
		}

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$total     = (int) ( empty( $params ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are added together with their values above.

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are added together with their values above.
				array_merge( $params, array( $per_page, $offset ) )
			)
		);

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}
}
// phpcs:enable
