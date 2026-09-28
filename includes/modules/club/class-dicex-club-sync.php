<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the DiceX club in step with the site.
 *
 * Work happens in small steps, each safe to repeat and safe to interrupt:
 *
 *   import     reads existing orders and users, a page at a time
 *   recompute  works out each changed member's level and what DiceX should hold
 *   send       sends what changed, fifty customers to a request
 *
 * A run takes the club's lock and does steps until it runs out of work or time.
 * The background scheduler starts runs; so does the Customer Club tab while it is
 * open, which is what lets an import finish on a site whose WP-Cron never fires.
 *
 * Nothing is ever deleted from DiceX: there is no way to, by design. Taking a
 * number out of the club means moving it to the level kept for that.
 */
class Dicex_Connect_Club_Sync {

	const STATE = 'dicex_connect_club_state';

	const LOCK = 'dicex_connect_club_lock';

	/** Longer than a run's time budget plus one batch's request timeout. */
	const LOCK_TTL = 180;

	const ORDERS_PER_STEP = 50;

	const USERS_PER_STEP = 100;

	const RECOMPUTE_PER_STEP = 50;

	const SEND_PER_STEP = 50;

	/**
	 * Group memberships one step sends. Each is a request of its own — the
	 * gateway has no array form — so a step stays small and pauses between them.
	 */
	const GROUPS_PER_STEP = 20;

	/** Pause between group requests: the club's limit is ten a second per address. */
	const GROUP_PAUSE = 150000;

	/**
	 * Pause between the extra requests a refused batch is split into. The club
	 * endpoints allow ten requests a second from one address and queue none.
	 */
	const SPLIT_PAUSE = 150000;

	/** @var array Users already looked at in this request. */
	private static $seen_users = array();

	/** @var string Why the last order or user was not added: '' or 'not_joined'. */
	private static $skip_reason = '';

	/** How many members one preview request looks at. */
	const PREVIEW_PER_REQUEST = 200;

	/**
	 * User meta: when an account was welcomed. One welcome per account, whatever
	 * numbers it types afterwards — a number on an account is nobody's word that
	 * it is theirs.
	 */
	const WELCOMED_META = 'dicex_connect_club_welcomed';

	/** User meta: level id => when that level's message last went to this account. */
	const LEVEL_NOTES_META = 'dicex_connect_club_level_notes';

	/**
	 * An account hears one level's message again only after this many days. The
	 * facts an account controls itself — its city, its date of birth — could
	 * otherwise move it up and down, and each move up would send a message.
	 */
	const LEVEL_NOTE_GAP_DAYS = 30;

	/** Characters of a customer's own name that go into a message. */
	const NAME_IN_MESSAGE = 50;

	/**
	 * @return array
	 */
	private static function state_defaults() {
		return array(
			'import'        => array(
				'running'      => false,
				'mode'         => 'full',
				'stage'        => '',
				'page'         => 1,
				'since'        => 0,
				'orders_done'  => 0,
				'orders_total' => 0,
				'users_done'   => 0,
				'users_total'  => 0,
				'skipped'      => 0,
				// Customers left out only because, with consent, they did not tick the box.
				'not_joined'   => 0,
				'started'      => 0,
				'finished'     => 0,
			),
			'imported_once' => false,
			// The first import waits here, read and worked out, until the owner has
			// seen the numbers: whatever reaches DiceX stays there.
			'hold'          => false,
			// The table schema the last full import was read under. 1.1.0 knew
			// nothing of products, countries or cities, hence 1 for any site that
			// imported before this key existed.
			'import_schema' => 1,
			'levels'        => array(),
			'levels_key'    => '',
			// DiceX's own ids for the owner's groups, and the key they belong to.
			'groups'        => array(),
			'groups_key'    => '',
			'last_daily'    => 0,
			'last_sent'     => 0,
			'last_error'    => '',
			'failures'      => 0,
			'backoff_until' => 0,
		);
	}

	/**
	 * @return array
	 */
	public static function state() {
		$defaults = self::state_defaults();
		$stored   = get_option( self::STATE, array() );
		$state    = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );

		$state['import'] = wp_parse_args( is_array( $state['import'] ) ? $state['import'] : array(), $defaults['import'] );
		$state['levels'] = is_array( $state['levels'] ) ? $state['levels'] : array();
		$state['groups'] = is_array( $state['groups'] ) ? $state['groups'] : array();

		return $state;
	}

	/**
	 * @param array $state
	 */
	private static function save_state( $state ) {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Changes some keys of the state, reading it fresh first, so a run does not
	 * write back a copy that someone else changed in the meantime.
	 *
	 * @param array $changes
	 */
	private static function update_state( $changes ) {
		self::save_state( array_merge( self::state(), $changes ) );
	}

	/* ---- Runs ----------------------------------------------------------- */

	/**
	 * Does as much work as fits in the time given.
	 *
	 * @param int $budget Seconds.
	 * @return array What is left; see progress().
	 */
	public static function tick( $budget = 20 ) {
		if ( ! Dicex_Connect_Club_Settings::is_enabled() ) {
			return array( 'more' => false );
		}

		Dicex_Connect_Club_Store::maybe_install();

		if ( ! self::lock() ) {
			return array_merge( self::progress(), array( 'busy' => true ) );
		}

		$started = microtime( true );

		// Action Scheduler runs several actions in one PHP process; what a user
		// looked like during the last one is not what they look like now.
		self::forget_seen_users();
		Dicex_Connect_Club_Sources::forget_terms();

		// Orders recorded before the tables held products, countries and cities
		// cannot meet a level that asks about them. Read everything once more.
		$state = self::state();

		if ( ! empty( $state['imported_once'] ) && (int) $state['import_schema'] < Dicex_Connect_Club_Store::IMPORT_SCHEMA && empty( $state['import']['running'] ) ) {
			Dicex_Connect_Logger::log( 'CLUB: tables upgraded, reading every order again' );
			self::start_import( 'full' );
		}

		try {
			Dicex_Connect_Club_Store::release_abandoned();
			Dicex_Connect_Club_Store::release_abandoned_groups();

			do {
				$worked = self::import_step() || self::recompute_step() || self::send_step() || self::groups_step();
			} while ( $worked && ( microtime( true ) - $started ) < $budget );
		} catch ( Throwable $e ) {
			Dicex_Connect_Logger::log( 'CLUB EXCEPTION: ' . $e->getMessage() );
		} finally {
			self::unlock();
		}

		return self::progress();
	}

	/**
	 * The scheduler's entry point.
	 */
	public static function run_scheduled_tick() {
		self::schedule_follow_up( self::tick( 20 ) );
	}

	/**
	 * Books the next run when there is more to do.
	 *
	 * @param array $progress
	 */
	public static function schedule_follow_up( $progress ) {
		if ( empty( $progress['more'] ) ) {
			return;
		}

		if ( ! empty( $progress['busy'] ) ) {
			Dicex_Connect_Club_Queue::tick_soon( 30 );
			return;
		}

		$wait = (int) self::state()['backoff_until'] - time();

		Dicex_Connect_Club_Queue::tick_soon( $wait > 0 ? $wait : 1 );
	}

	/**
	 * Once a day: look again at customers whom time alone moved — purchases aged
	 * out of the window, a last purchase grown old — and, unless the club syncs
	 * as things happen, catch whatever the site did not hear about.
	 */
	public static function run_daily() {
		if ( ! Dicex_Connect_Club_Settings::is_enabled() ) {
			return;
		}

		$settings = Dicex_Connect_Club_Settings::get();

		// Tomorrow's run first, so nothing below can break the chain.
		Dicex_Connect_Club_Queue::schedule_daily( $settings['daily_time'] );

		Dicex_Connect_Club_Store::maybe_install();

		$state   = self::state();
		$now     = time();
		$last    = (int) $state['last_daily'];
		$elapsed = $last > 0 ? $now - $last : DAY_IN_SECONDS;

		// Time moves people between levels with nothing happening to them, in every
		// sync mode. Purchases leave the window…
		if ( (int) $settings['window_months'] > 0 ) {
			$to   = Dicex_Connect_Club_Settings::window_start_gmt( $settings );
			$from = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' UTC' ) - $elapsed );

			Dicex_Connect_Club_Store::mark_dirty_for_orders_between( $from, $to );
		}

		// …and a last purchase grows old enough for "days since the last purchase".
		foreach ( Dicex_Connect_Club_Settings::idle_thresholds( $settings ) as $days ) {
			$line = $now - $days * DAY_IN_SECONDS;

			Dicex_Connect_Club_Store::mark_dirty_for_last_order_between( gmdate( 'Y-m-d H:i:s', $line - $elapsed ), gmdate( 'Y-m-d H:i:s', $line ) );
		}

		// …and a birthday makes somebody a year older, which an age condition notices.
		// Every day since the last run, so a day the site slept through is not lost.
		if ( Dicex_Connect_Club_Settings::uses_age( $settings ) ) {
			$today = current_datetime();
			$days  = min( 366, max( 1, (int) ceil( $elapsed / DAY_IN_SECONDS ) ) );
			$keys  = array();

			for ( $back = 0; $back < $days; $back++ ) {
				$keys = array_merge( $keys, Dicex_Connect_Dates::birthday_keys_for( $today->modify( '-' . $back . ' days' )->format( 'Y-m-d' ) ) );
			}

			Dicex_Connect_Club_Store::mark_dirty_for_birthdays( $keys );
		}

		// Reading what changed is what "as things happen" already does all day.
		if ( 'realtime' !== $settings['sync_mode'] && empty( $state['import']['running'] ) ) {
			self::start_import( 'recent', $last > 0 ? $last - HOUR_IN_SECONDS : $now - DAY_IN_SECONDS );
		}

		self::update_state( array( 'last_daily' => $now ) );

		Dicex_Connect_Club_Queue::tick_soon();
	}

	/**
	 * Starts reading orders and users from the beginning.
	 *
	 * @param string $mode  'full' reads everything; 'recent' only orders changed since $since.
	 * @param int    $since Timestamp.
	 */
	public static function start_import( $mode = 'full', $since = 0 ) {
		$import  = self::state_defaults()['import'];
		$state   = self::state();
		$changes = array();

		$import['running'] = true;
		$import['mode']    = 'recent' === $mode ? 'recent' : 'full';
		$import['stage']   = 'orders';
		$import['since']   = (int) $since;
		$import['started'] = time();

		$changes['import'] = $import;

		// The first time the whole store is read, nothing goes out until the owner
		// has seen who lands where.
		if ( 'full' === $import['mode'] && empty( $state['imported_once'] ) ) {
			$changes['hold'] = true;
		}

		self::update_state( $changes );

		Dicex_Connect_Club_Queue::tick_soon();
	}

	/**
	 * The owner has seen the numbers of the first import: send.
	 */
	public static function release_hold() {
		self::update_state( array( 'hold' => false ) );

		Dicex_Connect_Logger::log( 'CLUB IMPORT: first send confirmed' );

		Dicex_Connect_Club_Queue::tick_soon();
	}

	/**
	 * Where things stand, for the screen and for deciding whether to run again.
	 *
	 * @return array
	 */
	public static function progress() {
		$state    = self::state();
		$tables   = Dicex_Connect_Club_Store::exists();
		$statuses = $tables ? Dicex_Connect_Club_Store::counts_by_status() : array();
		$dirty    = $tables ? Dicex_Connect_Club_Store::dirty_count() : 0;
		$waiting  = ( isset( $statuses['pending'] ) ? $statuses['pending'] : 0 ) + ( isset( $statuses['sending'] ) ? $statuses['sending'] : 0 );
		$groups   = $tables ? Dicex_Connect_Club_Store::group_waiting_count() : 0;
		$can_send = Dicex_Connect_Api_Client::has_api_key();
		$hold     = ! empty( $state['hold'] );

		return array(
			'more'          => ! empty( $state['import']['running'] ) || $dirty > 0 || ( ( $waiting + $groups ) > 0 && $can_send && ! $hold ),
			'import'        => $state['import'],
			'hold'          => $hold,
			'imported_once' => ! empty( $state['imported_once'] ),
			'statuses'      => $statuses,
			'dirty'         => $dirty,
			'waiting'       => $waiting,
			'groups'        => $groups,
			'can_send'      => $can_send,
			'last_sent'     => (int) $state['last_sent'],
			'last_error'    => (string) $state['last_error'],
			'backoff_until' => (int) $state['backoff_until'],
		);
	}

	/* ---- Recording what happened --------------------------------------- */

	/**
	 * Takes note of an order and of the customer it belongs to.
	 *
	 * @param WC_Order $order
	 * @param string   $welcome 'due' for a customer who may be greeted if new.
	 * @return bool Whether the order could be tied to a member.
	 */
	public static function record_order( $order, $welcome ) {
		$row      = Dicex_Connect_Club_Sources::order_row( $order );
		$previous = Dicex_Connect_Club_Store::get_order( $row['order_id'] );
		$settings = Dicex_Connect_Club_Settings::get();

		Dicex_Connect_Club_Store::save_order( $row );
		Dicex_Connect_Club_Store::save_order_items( $row['order_id'], Dicex_Connect_Club_Sources::order_product_ids( $order ) );

		// Only a paid order makes a guest a customer — and, where customers choose,
		// only one they ticked the club box on.
		$joins = 1 === $row['paid'];

		if ( $joins && 0 === $row['user_id'] && 'consent' === $settings['join'] && ! Dicex_Connect_Club_Fields::order_joined( $order ) ) {
			$joins             = false;
			self::$skip_reason = 'not_joined';
		}

		$placed = self::touch( $row['mobile'], $row['user_id'], $welcome, $joins );

		// The order used to belong to someone else — its phone or customer was edited.
		if ( null !== $previous && ( (string) $previous->mobile !== $row['mobile'] || (int) $previous->user_id !== $row['user_id'] ) ) {
			self::touch( (string) $previous->mobile, (int) $previous->user_id, 'skip', false );
		}

		return $placed;
	}

	/**
	 * Forgets an order that is gone, and looks at its customer again.
	 *
	 * @param int $order_id
	 */
	public static function forget_order( $order_id ) {
		$row = Dicex_Connect_Club_Store::delete_order( $order_id );

		if ( null !== $row ) {
			self::touch( (string) $row->mobile, (int) $row->user_id, 'skip', false );
		}
	}

	/**
	 * Brings a WordPress user into the club, or updates what it knows about them.
	 *
	 * @param int    $user_id
	 * @param string $welcome
	 * @return bool Whether the user is a member.
	 */
	public static function record_user( $user_id, $welcome ) {
		$user_id = (int) $user_id;

		if ( isset( self::$seen_users[ $user_id ] ) ) {
			self::$skip_reason = self::$seen_users[ $user_id ]['reason'];

			return self::$seen_users[ $user_id ]['placed'];
		}

		$placed = self::place_user( $user_id, $welcome );

		self::$seen_users[ $user_id ] = array(
			'placed' => $placed,
			'reason' => $placed ? '' : self::$skip_reason,
		);

		return $placed;
	}

	/**
	 * Clears what this request remembered about users.
	 */
	public static function forget_seen_users() {
		self::$seen_users = array();
	}

	/**
	 * @param int    $user_id
	 * @param string $welcome
	 * @return bool
	 */
	private static function place_user( $user_id, $welcome ) {
		$user     = get_userdata( $user_id );
		$settings = Dicex_Connect_Club_Settings::get();

		self::$skip_reason = '';

		if ( ! $user instanceof WP_User || ! Dicex_Connect_Club_Sources::user_in_scope( $user, $settings['roles'] ) ) {
			// Deleted, or no longer in a role the club takes in. Their numbers stay in
			// DiceX, which cannot remove them, and count only guest purchases from now.
			Dicex_Connect_Club_Store::detach_user( $user_id, '' );
			return false;
		}

		$mobile = Dicex_Connect_Club_Sources::user_mobile( $user_id );

		// Welcomed before 1.7.0, when only the number's own row kept track of it:
		// noted on the account before the row stops being the account's.
		if ( 'due' === $welcome && '' === (string) get_user_meta( $user_id, self::WELCOMED_META, true ) && Dicex_Connect_Club_Store::user_welcomed( $user_id ) ) {
			update_user_meta( $user_id, self::WELCOMED_META, current_time( 'mysql', true ) );
		}

		// Any number this account used to be known by is its own member now.
		Dicex_Connect_Club_Store::detach_user( $user_id, $mobile );

		if ( '' === $mobile ) {
			return false;
		}

		$member = Dicex_Connect_Club_Store::member_by_mobile( $mobile );

		if ( null === $member ) {
			// Somebody who left, or who never said yes where customers choose, is not
			// written into DiceX at all: it could never be taken back out.
			if ( Dicex_Connect_Club_Fields::has_left( $user_id )
				|| ( 'consent' === $settings['join'] && ! Dicex_Connect_Club_Fields::has_joined( $user_id ) ) ) {
				self::$skip_reason = 'not_joined';
				return false;
			}

			// An account that was greeted once is not greeted again for a new number.
			if ( '' !== (string) get_user_meta( $user_id, self::WELCOMED_META, true ) ) {
				$welcome = 'skip';
			}

			return null !== Dicex_Connect_Club_Store::add_member( $mobile, $user_id, $welcome );
		}

		$owner = (int) $member->user_id;

		if ( $owner === $user_id ) {
			Dicex_Connect_Club_Store::mark_dirty( $member->id );
			return true;
		}

		// Another account has this number already. The first keeps it: two people
		// sharing one number is something only the site owner can sort out.
		if ( $owner > 0 && get_userdata( $owner ) instanceof WP_User ) {
			return false;
		}

		/*
		 * A guest who bought under this number is somebody. Typing the number into
		 * an account does not make it that account's: it takes the guest over only
		 * when it has paid under the number itself, or its email is one a guest
		 * paid under it with. Anything less would let a stranger put their own name
		 * on a customer's number in DiceX, count that customer's purchases as
		 * theirs, and take the number out of the club by leaving it.
		 */
		if ( Dicex_Connect_Club_Store::has_paid_guest_order( $mobile ) && ! self::shows_guest_is_them( $user, $mobile ) ) {
			return false;
		}

		Dicex_Connect_Club_Store::update_member(
			$member->id,
			array(
				'user_id' => $user_id,
				'dirty'   => 1,
			)
		);

		return true;
	}

	/**
	 * @param WP_User $user
	 * @param string  $mobile A number somebody has paid under as a guest.
	 * @return bool Whether this account has shown it is that guest.
	 */
	private static function shows_guest_is_them( $user, $mobile ) {
		return Dicex_Connect_Club_Store::user_paid_with_mobile( (int) $user->ID, $mobile )
			|| Dicex_Connect_Club_Sources::guest_paid_with_email( $mobile, (string) $user->user_email );
	}

	/**
	 * Makes sure the member behind an order or an account is looked at again.
	 *
	 * @param string $mobile
	 * @param int    $user_id
	 * @param string $welcome
	 * @param bool   $may_create Whether a guest who is not a member yet should become one.
	 * @return bool
	 */
	private static function touch( $mobile, $user_id, $welcome, $may_create ) {
		if ( $user_id > 0 ) {
			return self::record_user( $user_id, $welcome );
		}

		if ( '' === $mobile ) {
			return false;
		}

		$member = Dicex_Connect_Club_Store::member_by_mobile( $mobile );

		if ( null !== $member ) {
			Dicex_Connect_Club_Store::mark_dirty( $member->id );
			return true;
		}

		$settings = Dicex_Connect_Club_Settings::get();

		if ( ! $may_create || empty( $settings['guests'] ) ) {
			return false;
		}

		return null !== Dicex_Connect_Club_Store::add_member( $mobile, 0, $welcome );
	}

	/* ---- Steps ---------------------------------------------------------- */

	/**
	 * One page of an import.
	 *
	 * @return bool Whether there was an import to work on.
	 */
	private static function import_step() {
		$import = self::state()['import'];

		if ( empty( $import['running'] ) ) {
			return false;
		}

		$settings = Dicex_Connect_Club_Settings::get();

		if ( 'orders' === $import['stage'] ) {
			if ( ! Dicex_Connect_Club_Sources::has_woocommerce() ) {
				$import['stage'] = 'users';
				$import['page']  = 1;
			} else {
				$query = array(
					'type'     => 'shop_order',
					'limit'    => self::ORDERS_PER_STEP,
					'page'     => max( 1, (int) $import['page'] ),
					'paginate' => true,
					'orderby'  => 'ID',
					'order'    => 'ASC',
				);

				if ( 'recent' === $import['mode'] && (int) $import['since'] > 0 ) {
					$query['date_modified'] = '>' . (int) $import['since'];
				}

				$result = wc_get_orders( $query );
				$orders = ( is_object( $result ) && isset( $result->orders ) ) ? (array) $result->orders : array();

				foreach ( $orders as $order ) {
					self::$skip_reason = '';

					if ( $order instanceof WC_Order && ! self::record_order( $order, 'skip' ) ) {
						++$import[ 'not_joined' === self::$skip_reason ? 'not_joined' : 'skipped' ];
					}
				}

				$import['orders_total'] = is_object( $result ) ? (int) $result->total : 0;
				$import['orders_done'] += count( $orders );

				if ( empty( $orders ) || (int) $import['page'] >= ( is_object( $result ) ? (int) $result->max_num_pages : 0 ) ) {
					$import['stage'] = 'users';
					$import['page']  = 1;
				} else {
					++$import['page'];
				}
			}
		} elseif ( 'users' === $import['stage'] ) {
			// A catch-up only reads users when nothing else follows them: with
			// real-time sync on, every change to a user is already heard about.
			$read_users = ! empty( $settings['roles'] ) && ( 'full' === $import['mode'] || 'daily' === $settings['sync_mode'] );

			if ( ! $read_users ) {
				$import = self::finish_import( $import );
			} else {
				$query = new WP_User_Query(
					array(
						'role__in'    => $settings['roles'],
						'number'      => self::USERS_PER_STEP,
						'paged'       => max( 1, (int) $import['page'] ),
						'fields'      => 'ID',
						'orderby'     => 'ID',
						'order'       => 'ASC',
						'count_total' => true,
					)
				);

				$ids = (array) $query->get_results();

				foreach ( $ids as $id ) {
					self::$skip_reason = '';

					if ( ! self::record_user( (int) $id, 'skip' ) ) {
						++$import[ 'not_joined' === self::$skip_reason ? 'not_joined' : 'skipped' ];
					}
				}

				$import['users_total'] = (int) $query->get_total();
				$import['users_done'] += count( $ids );

				if ( count( $ids ) < self::USERS_PER_STEP ) {
					$import = self::finish_import( $import );
				} else {
					++$import['page'];
				}
			}
		} else {
			$import = self::finish_import( $import );
		}

		$changes = array( 'import' => $import );

		if ( empty( $import['running'] ) && 'full' === $import['mode'] ) {
			$changes['imported_once'] = true;
			$changes['import_schema'] = Dicex_Connect_Club_Store::IMPORT_SCHEMA;
		}

		self::update_state( $changes );

		return true;
	}

	/**
	 * @param array $import
	 * @return array
	 */
	private static function finish_import( $import ) {
		$import['running']  = false;
		$import['stage']    = 'done';
		$import['finished'] = time();

		Dicex_Connect_Logger::log(
			sprintf(
				'CLUB IMPORT (%s): %d orders, %d users, %d skipped, %d not joined',
				$import['mode'],
				(int) $import['orders_done'],
				(int) $import['users_done'],
				(int) $import['skipped'],
				(int) $import['not_joined']
			)
		);

		return $import;
	}

	/**
	 * Works out again the members that changed.
	 *
	 * @return bool Whether there were any.
	 */
	private static function recompute_step() {
		$rows = Dicex_Connect_Club_Store::dirty_members( self::RECOMPUTE_PER_STEP );

		if ( empty( $rows ) ) {
			return false;
		}

		$settings = Dicex_Connect_Club_Settings::get();
		$context  = self::context( $settings );

		foreach ( $rows as $row ) {
			// One customer whose data throws must not stop everyone behind them.
			try {
				self::recompute( $row, $settings, $context );
			} catch ( Throwable $e ) {
				Dicex_Connect_Club_Store::update_member(
					$row->id,
					array(
						'dirty'      => 0,
						'status'     => 'error',
						'last_error' => $e->getMessage(),
					)
				);
			}
		}

		return true;
	}

	/**
	 * What working out a level needs to know once per run rather than per member.
	 *
	 * @param array $settings
	 * @return array
	 */
	private static function context( $settings ) {
		// One moment for the whole run, so two members bought on the same day agree.
		$now = time();

		return array(
			'currency'        => Dicex_Connect_Club_Sources::store_currency(),
			'since'           => Dicex_Connect_Club_Settings::window_start_gmt( $settings ),
			'uses_products'   => Dicex_Connect_Club_Settings::uses( $settings, array( 'products', 'categories', 'brands' ) ),
			'uses_categories' => Dicex_Connect_Club_Settings::uses( $settings, array( 'categories' ) ),
			'uses_brands'     => Dicex_Connect_Club_Settings::uses( $settings, array( 'brands' ) ),
			'needs_birthday'  => Dicex_Connect_Club_Settings::needs_birthday( $settings ),
			'calendar'        => (string) $settings['birthday']['calendar'],
			'now'             => $now,
			// The site's own day at that moment: birthdays happen on it.
			'today'           => wp_date( 'Y-m-d', $now ),
		);
	}

	/**
	 * Everything the levels ask about one member: purchases, what they bought,
	 * where they buy from, how long ago they last bought.
	 *
	 * @param object $row
	 * @param array  $context
	 * @return array
	 */
	private static function member_facts( $row, $context ) {
		$user_id = (int) $row->user_id;
		$mobile  = (string) $row->mobile;
		$stats   = Dicex_Connect_Club_Store::stats( $mobile, $user_id, $context['currency'], $context['since'] );

		$stats['now'] = $context['now'];

		// What they bought — only read when some level asks.
		$stats['product_ids']  = array();
		$stats['category_ids'] = array();
		$stats['brand_ids']    = array();

		if ( $context['uses_products'] ) {
			$stats['product_ids'] = Dicex_Connect_Club_Store::product_ids( $mobile, $user_id, $context['since'] );

			if ( $context['uses_categories'] ) {
				$stats['category_ids'] = Dicex_Connect_Club_Sources::product_terms( $stats['product_ids'], 'product_cat' );
			}

			if ( $context['uses_brands'] ) {
				$stats['brand_ids'] = Dicex_Connect_Club_Sources::product_terms( $stats['product_ids'], 'product_brand' );
			}
		}

		// Where they are: the billing address of their newest paid order, or the
		// one on their account while they have not bought anything yet.
		$location = Dicex_Connect_Club_Store::latest_location( $mobile, $user_id );

		if ( '' === $location['country'] && '' === $location['city'] && $user_id > 0 ) {
			$location = Dicex_Connect_Club_Sources::user_location( $user_id );
		}

		$stats['country'] = $location['country'];
		$stats['city']    = $location['city'];

		// How old they are: the date of birth on their row, counted in the calendar
		// their language keeps birthdays in, on the site's own day.
		$stats['birthday'] = isset( $row->birthday ) && null !== $row->birthday ? (string) $row->birthday : '';
		$stats['calendar'] = Dicex_Connect_Dates::calendar_for( $context['calendar'], isset( $row->locale ) ? (string) $row->locale : '' );
		$stats['today']    = $context['today'];

		return $stats;
	}

	/**
	 * A member's date of birth from wherever the club keeps it: their account, or
	 * the newest of their guest orders that has one.
	 *
	 * @param object $row
	 * @param array  $settings
	 * @return string Gregorian Y-m-d, or ''.
	 */
	private static function birthday_of( $row, $settings ) {
		$user_id = (int) $row->user_id;

		return $user_id > 0
			? Dicex_Connect_Club_Fields::user_birthday( $user_id, $settings )
			: Dicex_Connect_Club_Sources::guest_birthday( (string) $row->mobile );
	}

	/**
	 * The level a member belongs in.
	 *
	 * Somebody who left the club goes to the level for removed numbers, whatever
	 * else is true of them. A level a person chose by hand stands next, as long as
	 * it still exists. Everybody else is placed by the conditions.
	 *
	 * @param object $row
	 * @param array  $settings
	 * @param array  $stats
	 * @return string Level id.
	 */
	private static function decide_level( $row, $settings, $stats ) {
		$removed = Dicex_Connect_Club_Settings::find_level( $settings, $settings['removed_level'] );

		if ( null !== $removed && (int) $row->user_id > 0 && Dicex_Connect_Club_Fields::has_left( (int) $row->user_id ) ) {
			return $removed['id'];
		}

		if ( 1 === (int) $row->locked && null !== Dicex_Connect_Club_Settings::find_level( $settings, (string) $row->level_id ) ) {
			return (string) $row->level_id;
		}

		return Dicex_Connect_Club_Settings::evaluate( $settings, $stats );
	}

	/**
	 * Where members would land under settings that are not saved yet — the
	 * levels screen's count, a page of members at a time. Nothing is written.
	 *
	 * @param array $settings Settings as they would be saved.
	 * @param int   $after_id The last member id already counted.
	 * @return array counts (level id => members), groups (group id => members), moves, checked, next, done
	 */
	public static function preview_page( $settings, $after_id ) {
		$rows    = Dicex_Connect_Club_Store::members_after( $after_id, self::PREVIEW_PER_REQUEST );
		$context = self::context( $settings );
		$counts  = array();
		$groups  = array();
		$moves   = 0;
		$next    = (int) $after_id;

		Dicex_Connect_Club_Sources::forget_terms();

		foreach ( $rows as $row ) {
			$next     = (int) $row->id;
			$stats    = self::member_facts( $row, $context );
			$level_id = self::decide_level( $row, $settings, $stats );

			if ( '' === $level_id ) {
				continue;
			}

			$counts[ $level_id ] = ( isset( $counts[ $level_id ] ) ? $counts[ $level_id ] : 0 ) + 1;

			if ( $level_id !== (string) $row->level_id ) {
				++$moves;
			}

			// Nobody in the level for removed numbers is in a group.
			if ( $level_id === (string) $settings['removed_level'] ) {
				continue;
			}

			foreach ( Dicex_Connect_Club_Settings::matching_groups( $settings, $stats ) as $key ) {
				$groups[ $key ] = ( isset( $groups[ $key ] ) ? $groups[ $key ] : 0 ) + 1;
			}
		}

		return array(
			'counts'  => $counts,
			'groups'  => $groups,
			'moves'   => $moves,
			'checked' => count( $rows ),
			'next'    => $next,
			'done'    => count( $rows ) < self::PREVIEW_PER_REQUEST,
		);
	}

	/**
	 * @param object $row
	 * @param array  $settings
	 * @param array  $context
	 */
	private static function recompute( $row, $settings, $context ) {
		$user_id = (int) $row->user_id;

		// Read before the level is worked out: a level may ask how old somebody is,
		// and which calendar their age is counted in follows their language. The
		// row is this run's own copy; the values are written back below.
		$row->locale   = Dicex_Connect_Club_Language::for_member( (string) $row->mobile, $user_id );
		$row->birthday = $context['needs_birthday'] ? self::birthday_of( $row, $settings ) : '';

		$stats    = self::member_facts( $row, $context );
		$locked   = 1 === (int) $row->locked && null !== Dicex_Connect_Club_Settings::find_level( $settings, (string) $row->level_id );
		$level_id = self::decide_level( $row, $settings, $stats );
		$level    = Dicex_Connect_Club_Settings::level_name( $settings, $level_id );

		if ( '' === $level ) {
			Dicex_Connect_Club_Store::update_member( $row->id, array( 'dirty' => 0 ) );
			return;
		}

		/*
		 * Somebody in the level for removed numbers, or who left, is out: from now on
		 * DiceX gets their number and that level, and every other field empty.
		 * Under DiceX's documented merge an empty field keeps what it holds, so this
		 * stops sending rather than erasing anything there. Nothing else about them
		 * is kept here either.
		 */
		$out = ( $user_id > 0 && Dicex_Connect_Club_Fields::has_left( $user_id ) )
			|| ( '' !== (string) $settings['removed_level'] && $level_id === (string) $settings['removed_level'] );

		if ( $out ) {
			$profile       = array_fill_keys( array( 'name', 'family', 'email', 'company', 'address' ), '' );
			$row->birthday = '';

			$stats['first_order_gmt'] = null;
			$stats['country']         = '';
			$stats['city']            = '';
		} else {
			// A guest is whoever last paid under the number.
			$profile = $user_id > 0
				? Dicex_Connect_Club_Sources::user_profile( $user_id )
				: Dicex_Connect_Club_Sources::order_profile( Dicex_Connect_Club_Store::latest_guest_order_id( (string) $row->mobile ) );
		}

		$fields = (array) $settings['fields'];

		// Sent while the club asks for it, or reads it from another plugin — the
		// owner's choice either way. An age condition alone reads it, but keeps it here.
		$birthday = Dicex_Connect_Club_Settings::sends_birthday( $settings ) ? (string) $row->birthday : '';

		$payload = Dicex_Connect_Club::customer_body(
			array(
				'levelName'      => $level,
				'mobile'         => (string) $row->mobile,
				'name'           => $profile['name'],
				'family'         => $profile['family'],
				'company'        => in_array( 'company', $fields, true ) ? $profile['company'] : null,
				// With its year, at midnight UTC so the day never moves.
				'birthDay'       => Dicex_Connect_Dates::to_api( $birthday ),
				// The day this person first bought something: their anniversary as a customer.
				'anniversaryDay' => null !== $stats['first_order_gmt'] ? Dicex_Connect_Club::utc_date( strtotime( $stats['first_order_gmt'] . ' UTC' ) ) : null,
				'email'          => in_array( 'email', $fields, true ) ? $profile['email'] : null,
				'address'        => in_array( 'address', $fields, true ) ? $profile['address'] : null,
				'description'    => null,
			)
		);

		// Groups are not exclusive: a customer is in every one they match. Somebody
		// who is out is in none.
		if ( ! empty( $settings['groups'] ) ) {
			Dicex_Connect_Club_Store::set_group_matches( $row->id, $out ? array() : Dicex_Connect_Club_Settings::matching_groups( $settings, $stats ) );
		}

		$attempt = md5( wp_json_encode( $payload ) );
		$changed = self::profile_hash( $payload ) !== (string) $row->profile_hash || $level !== (string) $row->sent_level;

		if ( ! $changed ) {
			$status = 'synced';
		} elseif ( 'error' === $row->status && $attempt === (string) $row->attempt_hash ) {
			// The same thing DiceX already refused. Not again until something changes
			// or somebody presses retry.
			$status = 'error';
		} else {
			$status = 'pending';
		}

		$name = trim( (string) $profile['name'] . ' ' . (string) $profile['family'] );

		Dicex_Connect_Club_Store::update_member(
			$row->id,
			array(
				'level_id'        => $level_id,
				'locked'          => $locked ? 1 : 0,
				'order_count'     => (int) $stats['order_count'],
				'net_spent'       => number_format( (float) $stats['net_spent'], 8, '.', '' ),
				'first_order_gmt' => $stats['first_order_gmt'],
				'last_order_gmt'  => $stats['last_order_gmt'],
				'display_name'    => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 191, 'UTF-8' ) : substr( $name, 0, 191 ),
				'locale'          => (string) $row->locale,
				// Kept only while something needs it, and gone with the reason for it.
				'birthday'        => '' === (string) $row->birthday ? null : (string) $row->birthday,
				'birth_md'        => Dicex_Connect_Dates::birthday_key( (string) $row->birthday, $stats['calendar'] ),
				'country'         => $stats['country'],
				'city'            => $stats['city'],
				'payload'         => wp_json_encode( $payload ),
				'dirty'           => 0,
				'status'          => $status,
			)
		);
	}

	/**
	 * Sends one batch of changed members.
	 *
	 * @return bool Whether there was anything to send.
	 */
	private static function send_step() {
		$state = self::state();

		// A paused send, or a first import the owner has not confirmed yet.
		if ( (int) $state['backoff_until'] > time() || ! empty( $state['hold'] ) || ! Dicex_Connect_Api_Client::has_api_key() ) {
			return false;
		}

		$token = strtolower( wp_generate_password( 20, false, false ) );
		$rows  = Dicex_Connect_Club_Store::claim_pending( self::SEND_PER_STEP, $token );

		if ( empty( $rows ) ) {
			return false;
		}

		$settings = Dicex_Connect_Club_Settings::get();
		$by_id    = array();
		$payloads = array();

		foreach ( $rows as $row ) {
			$id      = (int) $row->id;
			$payload = json_decode( (string) $row->payload, true );

			// Built for a level that has since been renamed or removed: work it out again.
			if ( ! is_array( $payload ) || empty( $payload['levelName'] ) || null === self::level_by_name( $settings, $payload['levelName'] ) ) {
				Dicex_Connect_Club_Store::update_member(
					$id,
					array(
						'status' => 'pending',
						'claim'  => '',
						'dirty'  => 1,
					)
				);
				continue;
			}

			$by_id[ $id ]    = $row;
			$payloads[ $id ] = $payload;
		}

		if ( empty( $payloads ) ) {
			return true;
		}

		$levels = self::ensure_levels( $settings, $payloads );

		if ( is_wp_error( $levels ) ) {
			Dicex_Connect_Club_Store::release( array_keys( $payloads ) );
			self::back_off( $levels );
			return true;
		}

		$register = array();
		$move     = array();

		foreach ( $payloads as $id => $payload ) {
			$sent_before = '' !== (string) $by_id[ $id ]->profile_hash;

			if ( ! $sent_before || self::profile_hash( $payload ) !== (string) $by_id[ $id ]->profile_hash ) {
				$register[ $id ] = $payload;
			}

			/*
			 * A level change also goes through ChangeCustomerLevels, even when the
			 * customer is being registered again anyway. The account owner says an
			 * update without every field is not applied; this is the endpoint made
			 * for moving people, and moving them is the part that must not be lost.
			 */
			if ( $sent_before && $payload['levelName'] !== (string) $by_id[ $id ]->sent_level ) {
				$move[ $id ] = array(
					'levelName' => $payload['levelName'],
					'mobile'    => $payload['mobile'],
				);
			}
		}

		$done        = array(
			'register' => array(),
			'move'     => array(),
		);
		$failed      = array();
		$contact_ids = array();
		$stopped     = self::send_batch( 'register', $register, $done, $failed, $contact_ids );

		if ( null === $stopped ) {
			$stopped = self::send_batch( 'move', array_diff_key( $move, $failed ), $done, $failed, $contact_ids );
		}

		$now      = current_time( 'mysql', true );
		$release  = array();
		$greet    = array();
		$moved_up = array();
		$synced   = 0;
		$refused  = 0;

		foreach ( $payloads as $id => $payload ) {
			if ( isset( $failed[ $id ] ) ) {
				++$refused;

				Dicex_Connect_Club_Store::update_member(
					$id,
					array(
						'status'       => 'error',
						'claim'        => '',
						'attempt_hash' => md5( wp_json_encode( $payload ) ),
						'last_error'   => $failed[ $id ]->get_error_message(),
					)
				);
				continue;
			}

			$registered = ! isset( $register[ $id ] ) || isset( $done['register'][ $id ] );
			$moved      = ! isset( $move[ $id ] ) || isset( $done['move'][ $id ] );

			if ( ! $registered || ! $moved ) {
				$release[] = $id;
				continue;
			}

			$update = array(
				'status'       => 'synced',
				'claim'        => '',
				'profile_hash' => self::profile_hash( $payload ),
				'sent_level'   => $payload['levelName'],
				'attempt_hash' => '',
				'last_error'   => '',
				'synced_gmt'   => $now,
			);

			if ( ! empty( $contact_ids[ $id ] ) ) {
				$update['contact_id'] = $contact_ids[ $id ];
			}

			Dicex_Connect_Club_Store::update_member( $id, $update );
			++$synced;

			if ( '' === (string) $by_id[ $id ]->profile_hash && 'due' === (string) $by_id[ $id ]->welcome ) {
				$greet[ $id ] = $payload;
			} elseif ( isset( $done['move'][ $id ] ) ) {
				$moved_up[ $id ] = $payload;
			}
		}

		Dicex_Connect_Club_Store::release( $release );

		if ( $stopped instanceof WP_Error ) {
			self::back_off( $stopped );
		} else {
			self::update_state(
				array(
					'last_sent'     => time(),
					'last_error'    => '',
					'failures'      => 0,
					'backoff_until' => 0,
				)
			);
		}

		Dicex_Connect_Logger::log( sprintf( 'CLUB SYNC: %d sent, %d moved, %d refused, %d waiting', $synced, count( $done['move'] ), $refused, count( $release ) ) );

		foreach ( $greet as $id => $payload ) {
			self::welcome( $settings, $by_id[ $id ], $payload );
		}

		foreach ( $moved_up as $id => $payload ) {
			self::level_message( $settings, $by_id[ $id ], $payload );
		}

		return true;
	}

	/**
	 * Adds members to the DiceX groups they match, one request each.
	 *
	 * Nothing here ever takes anybody out: the gateway cannot. A member who stops
	 * matching keeps their row, marked as no longer matching, and the screen says
	 * how many those are.
	 *
	 * @return bool Whether there was anything to do.
	 */
	private static function groups_step() {
		$state    = self::state();
		$settings = Dicex_Connect_Club_Settings::get();

		if ( empty( $settings['groups'] ) || (int) $state['backoff_until'] > time() || ! empty( $state['hold'] ) || ! Dicex_Connect_Api_Client::has_api_key() ) {
			return false;
		}

		$token = strtolower( wp_generate_password( 20, false, false ) );
		$rows  = Dicex_Connect_Club_Store::claim_group_adds( self::GROUPS_PER_STEP, $token );

		if ( empty( $rows ) ) {
			return false;
		}

		$ids   = self::ensure_groups( $settings, $rows );
		$added = 0;

		if ( is_wp_error( $ids ) ) {
			Dicex_Connect_Club_Store::release_groups( wp_list_pluck( $rows, 'id' ) );
			self::back_off( $ids );
			return true;
		}

		foreach ( $rows as $index => $row ) {
			$payload = json_decode( (string) $row->payload, true );

			// The group is gone from the settings, or the member has nothing to send yet.
			if ( ! isset( $ids[ (string) $row->group_key ] ) || ! is_array( $payload ) || empty( $payload['mobile'] ) ) {
				Dicex_Connect_Club_Store::release_groups( array( (int) $row->id ) );
				continue;
			}

			if ( $index > 0 ) {
				usleep( self::GROUP_PAUSE );
			}

			$result = Dicex_Connect_Club::add_to_group( $ids[ (string) $row->group_key ], $payload );

			if ( is_wp_error( $result ) ) {
				if ( Dicex_Connect_Club::is_retryable( $result ) ) {
					Dicex_Connect_Club_Store::release_groups( array( (int) $row->id ) );
					self::back_off( $result );
					break;
				}

				Dicex_Connect_Club_Store::group_failed( (int) $row->id, $result->get_error_message() );
				continue;
			}

			Dicex_Connect_Club_Store::group_added( (int) $row->id, (int) $result );
			++$added;
		}

		if ( $added > 0 ) {
			Dicex_Connect_Logger::log( sprintf( 'CLUB GROUPS: %d membership(s) sent', $added ) );
			self::update_state( array( 'last_sent' => time() ) );
		}

		return true;
	}

	/**
	 * The DiceX group id behind each of the owner's groups, creating what is not
	 * there yet and reusing a group of the same name.
	 *
	 * Kept in the state, keyed by the API key, the way levels are: another account
	 * has other groups.
	 *
	 * @param array $settings
	 * @param array $rows     Memberships about to be sent.
	 * @return array|WP_Error group id => DiceX id.
	 */
	private static function ensure_groups( $settings, $rows ) {
		$state = self::state();
		$key   = md5( (string) Dicex_Connect_Api_Client::get_api_key() );
		$known = ( isset( $state['groups'] ) && is_array( $state['groups'] ) && isset( $state['groups_key'] ) && $state['groups_key'] === $key )
			? $state['groups']
			: array();

		$wanted = array();

		foreach ( $rows as $row ) {
			$group = Dicex_Connect_Club_Settings::find_group( $settings, (string) $row->group_key );

			if ( null !== $group && ! isset( $known[ $group['id'] ] ) ) {
				$wanted[ $group['id'] ] = $group;
			}
		}

		if ( empty( $wanted ) ) {
			return $known;
		}

		// A group of the same name may already be there, from an earlier install or
		// from the DiceX panel. Nothing creates a second one.
		$existing = Dicex_Connect_Club::get_groups();

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$by_name = array();

		foreach ( $existing as $group ) {
			$by_name[ Dicex_Connect_Club_Settings::normalize_name( $group['name'] ) ] = (int) $group['id'];
		}

		foreach ( $wanted as $id => $group ) {
			if ( isset( $by_name[ $group['name'] ] ) ) {
				$known[ $id ] = $by_name[ $group['name'] ];
				continue;
			}

			$created = Dicex_Connect_Club::create_group( $group['name'], Dicex_Connect_Club_Settings::group_description( $group ) );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$known[ $id ] = (int) $created;
		}

		self::update_state(
			array(
				'groups'     => $known,
				'groups_key' => $key,
			)
		);

		return $known;
	}

	/**
	 * Tells somebody they have moved up, when the owner wrote a message for the
	 * level they reached.
	 *
	 * Only upwards: the levels are a list, and a customer who lands in one nearer
	 * the top than the one they were in has moved up. Nothing is sent when they
	 * first join — the welcome covers that — nor for the level kept for removed
	 * numbers, nor to anybody who left. An account hears one level's message at
	 * most once in LEVEL_NOTE_GAP_DAYS; a guest only moves on what they paid for.
	 *
	 * @param array  $settings
	 * @param object $row      The member as they were before this run.
	 * @param array  $payload  What was just sent to DiceX.
	 */
	private static function level_message( $settings, $row, $payload ) {
		$before  = (string) $row->sent_level;
		$after   = (string) $payload['levelName'];
		$user_id = (int) $row->user_id;

		if ( '' === $before || $before === $after ) {
			return;
		}

		if ( $user_id > 0 && Dicex_Connect_Club_Fields::has_left( $user_id ) ) {
			return;
		}

		$order   = array();
		$removed = (string) $settings['removed_level'];

		foreach ( $settings['levels'] as $index => $level ) {
			$order[ $level['name'] ] = $index;
		}

		if ( ! isset( $order[ $before ], $order[ $after ] ) || $order[ $after ] >= $order[ $before ] ) {
			return;
		}

		$level = self::level_by_name( $settings, $after );

		if ( null === $level || $level['id'] === $removed ) {
			return;
		}

		$locale  = (string) $row->locale;
		$message = Dicex_Connect_Club_Settings::level_message( $level, $locale );

		if ( '' === trim( $message ) ) {
			return;
		}

		$notes = $user_id > 0 ? get_user_meta( $user_id, self::LEVEL_NOTES_META, true ) : array();
		$notes = is_array( $notes ) ? $notes : array();

		if ( isset( $notes[ $level['id'] ] ) && time() - (int) $notes[ $level['id'] ] < self::LEVEL_NOTE_GAP_DAYS * DAY_IN_SECONDS ) {
			return;
		}

		$channels = array_values( array_intersect( (array) $settings['welcome']['channels'], Dicex_Connect_Lines::SUPPORTED_CHANNELS ) );
		$vars     = self::message_vars( $payload, $after );

		try {
			$result = Dicex_Connect_Sender::send_chain( empty( $channels ) ? array( 'sms' ) : $channels, (string) $row->mobile, $message, $vars );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'dicex_connect_club_level_message', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			Dicex_Connect_Logger::log( 'CLUB LEVEL MESSAGE FAILED ' . Dicex_Connect_Mobile::mask( (string) $row->mobile ) . ': ' . $result->get_error_message() );
			return;
		}

		if ( $user_id > 0 ) {
			// Only the levels that still exist are worth remembering.
			$notes = array_intersect_key( $notes, array_flip( wp_list_pluck( $settings['levels'], 'id' ) ) );

			$notes[ $level['id'] ] = time();

			update_user_meta( $user_id, self::LEVEL_NOTES_META, $notes );
		}
	}

	/**
	 * The tags a club message can use. The name is the customer's own, typed by
	 * them, so only its first NAME_IN_MESSAGE characters go into a message sent
	 * from the shop's line.
	 *
	 * @param array  $payload What was just sent to DiceX.
	 * @param string $level   The level's name.
	 * @return array
	 */
	private static function message_vars( $payload, $level ) {
		$cut = function ( $text ) {
			$text = trim( (string) $text );

			return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::NAME_IN_MESSAGE, 'UTF-8' ) : substr( $text, 0, self::NAME_IN_MESSAGE );
		};

		return array(
			'first_name' => $cut( $payload['name'] ),
			'last_name'  => $cut( $payload['family'] ),
			'level'      => $level,
			'site_name'  => Dicex_Connect_Branding::sender_name(),
		);
	}

	/**
	 * Sends a batch, and halves it when DiceX refuses it.
	 *
	 * DiceX refuses a batch as a whole without saying which customer it was about.
	 * Halving it until the refusal has a name costs a handful of requests, spaced
	 * out under the gateway's limit.
	 *
	 * @param string $kind        'register' or 'move'.
	 * @param array  $items       Member id => body.
	 * @param array  $done        Filled with the member ids that went through.
	 * @param array  $failed      Filled with member id => WP_Error for refusals.
	 * @param array  $contact_ids Filled with member id => DiceX contact id.
	 * @return WP_Error|null An error that stops the whole run, or null.
	 */
	private static function send_batch( $kind, $items, &$done, &$failed, &$contact_ids ) {
		if ( empty( $items ) ) {
			return null;
		}

		$ids    = array_keys( $items );
		$result = 'register' === $kind
			? Dicex_Connect_Club::register_customers( array_values( $items ) )
			: Dicex_Connect_Club::change_levels( array_values( $items ) );

		if ( ! is_wp_error( $result ) ) {
			foreach ( $ids as $index => $id ) {
				$done[ $kind ][ $id ] = true;

				if ( 'register' === $kind && ! empty( $result[ $index ] ) ) {
					$contact_ids[ $id ] = (int) $result[ $index ];
				}
			}

			return null;
		}

		if ( Dicex_Connect_Club::is_retryable( $result ) ) {
			return $result;
		}

		if ( 1 === count( $items ) ) {
			$failed[ $ids[0] ] = $result;
			return null;
		}

		$half = (int) ceil( count( $items ) / 2 );

		usleep( self::SPLIT_PAUSE );

		$stopped = self::send_batch( $kind, array_slice( $items, 0, $half, true ), $done, $failed, $contact_ids );

		if ( null !== $stopped ) {
			return $stopped;
		}

		usleep( self::SPLIT_PAUSE );

		return self::send_batch( $kind, array_slice( $items, $half, null, true ), $done, $failed, $contact_ids );
	}

	/**
	 * Registers every level these customers are going into, unless DiceX already
	 * has it with the same description.
	 *
	 * ChangeCustomerLevels refuses a level DiceX has not heard of. What was
	 * registered is remembered per API key: a different account starts empty.
	 *
	 * @param array $settings
	 * @param array $payloads
	 * @return true|WP_Error
	 */
	private static function ensure_levels( $settings, $payloads ) {
		$state = self::state();
		$key   = md5( Dicex_Connect_Api_Client::get_api_key() );
		$known = ( $state['levels_key'] === $key ) ? $state['levels'] : array();
		$names = array();

		foreach ( $payloads as $payload ) {
			$names[ $payload['levelName'] ] = true;
		}

		foreach ( array_keys( $names ) as $name ) {
			$level       = self::level_by_name( $settings, (string) $name );
			$description = Dicex_Connect_Club_Settings::level_description( $settings, $level );
			$signature   = md5( $name . '|' . $description );

			if ( isset( $known[ md5( $name ) ] ) && $known[ md5( $name ) ] === $signature ) {
				continue;
			}

			$result = Dicex_Connect_Club::register_level( (string) $name, $description );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$known[ md5( $name ) ] = $signature;

			usleep( self::SPLIT_PAUSE );
		}

		self::update_state(
			array(
				'levels'     => $known,
				'levels_key' => $key,
			)
		);

		return true;
	}

	/**
	 * @param array  $settings
	 * @param string $name
	 * @return array|null
	 */
	private static function level_by_name( $settings, $name ) {
		foreach ( $settings['levels'] as $level ) {
			if ( $level['name'] === $name ) {
				return $level;
			}
		}

		return null;
	}

	/**
	 * Greets a customer who has just joined, when the owner switched that on.
	 *
	 * Sent by this plugin because no club endpoint can carry a welcome yet.
	 * TODO: DiceX API — the owner wants the gateway to send it once a level can
	 * hold a welcome message, the way an ordinary contact group already does.
	 *
	 * One welcome per number, and one per account: an account that types a new
	 * number is not greeted again, since nothing shows the number is its own.
	 *
	 * @param array  $settings
	 * @param object $row      The member as they were before this run.
	 * @param array  $payload  What was just sent to DiceX.
	 */
	private static function welcome( $settings, $row, $payload ) {
		$id      = (int) $row->id;
		$mobile  = (string) $row->mobile;
		$locale  = (string) $row->locale;
		$user_id = (int) $row->user_id;
		$removed = Dicex_Connect_Club_Settings::level_name( $settings, $settings['removed_level'] );

		$skip = empty( $settings['welcome']['enabled'] )
			|| ( '' !== $removed && $payload['levelName'] === $removed )
			|| ( $user_id > 0 && ( Dicex_Connect_Club_Fields::has_left( $user_id ) || '' !== (string) get_user_meta( $user_id, self::WELCOMED_META, true ) ) );

		if ( $skip ) {
			Dicex_Connect_Club_Store::update_member( $id, array( 'welcome' => 'skip' ) );
			return;
		}

		// Exactly one run gets to send it, however many are going at once.
		if ( ! Dicex_Connect_Club_Store::claim_welcome( $id ) ) {
			return;
		}

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::WELCOMED_META, current_time( 'mysql', true ) );
		}

		$channels = array_values( array_intersect( (array) $settings['welcome']['channels'], Dicex_Connect_Lines::SUPPORTED_CHANNELS ) );
		$vars     = self::message_vars( $payload, (string) $payload['levelName'] );

		$template = Dicex_Connect_Club_Language::with_locale(
			$locale,
			function () use ( $settings, $locale ) {
				return Dicex_Connect_Club_Settings::welcome_template( $settings, $locale );
			}
		);

		try {
			$result = Dicex_Connect_Sender::send_chain( empty( $channels ) ? array( 'sms' ) : $channels, $mobile, $template, $vars );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'dicex_connect_club_welcome', $e->getMessage() );
		}

		Dicex_Connect_Club_Store::update_member( $id, array( 'welcome' => is_wp_error( $result ) ? 'failed' : 'sent' ) );

		if ( is_wp_error( $result ) ) {
			Dicex_Connect_Logger::log( 'CLUB WELCOME FAILED ' . Dicex_Connect_Mobile::mask( $mobile ) . ': ' . $result->get_error_message() );
		}
	}

	/**
	 * Waits longer after each failure in a row: half a minute, then doubling, up
	 * to an hour.
	 *
	 * @param WP_Error $error
	 */
	private static function back_off( $error ) {
		$failures = min( 10, (int) self::state()['failures'] + 1 );
		$delay    = (int) min( HOUR_IN_SECONDS, 30 * pow( 2, $failures - 1 ) );

		self::update_state(
			array(
				'failures'      => $failures,
				'backoff_until' => time() + $delay,
				'last_error'    => $error->get_error_message(),
			)
		);

		Dicex_Connect_Logger::log( 'CLUB PAUSED ' . $delay . 's: ' . $error->get_error_message() );
	}

	/**
	 * What decides whether a customer's details need sending again: everything
	 * but the level, which travels separately.
	 *
	 * @param array $payload
	 * @return string
	 */
	private static function profile_hash( $payload ) {
		unset( $payload['levelName'] );

		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * One run at a time.
	 *
	 * Not atomic — add_option() reads before it writes — and it does not need to
	 * be: each step can be repeated harmlessly, members are claimed row by row
	 * before sending, and a welcome is claimed by a single-row update. The lock
	 * only saves a second run from doing the same work, and bursts of requests
	 * against the rate limit.
	 *
	 * @return bool
	 */
	private static function lock() {
		$now = time();

		if ( add_option( self::LOCK, $now + self::LOCK_TTL, '', false ) ) {
			return true;
		}

		if ( (int) get_option( self::LOCK, 0 ) > $now ) {
			return false;
		}

		update_option( self::LOCK, $now + self::LOCK_TTL, false );

		return true;
	}

	private static function unlock() {
		delete_option( self::LOCK );
	}
}
