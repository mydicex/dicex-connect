<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The customer club's hooks into WordPress and WooCommerce.
 *
 * Listening is cheap on purpose. A hook only writes down which order or user
 * changed; the work happens once, at the end of the request, and the sending
 * happens later still, in the background. No request to DiceX is ever made in
 * the middle of somebody's checkout.
 *
 * Only hooks WooCommerce and WordPress document are used. Listening writes nothing
 * to an order, a customer or a user; what customers type into the club's own
 * fields is kept by Dicex_Connect_Club_Fields.
 */
class Dicex_Connect_Club_Module {

	/** @var int[] Orders that changed in this request. */
	private static $orders = array();

	/** @var int[] Orders that were deleted or trashed in this request. */
	private static $gone = array();

	/** @var int[] Users that changed in this request. */
	private static $users = array();

	/** @var int[] Products whose categories or brands changed in this request. */
	private static $products = array();

	/** @var bool A category or brand itself was edited or deleted in this request. */
	private static $taxonomy_changed = false;

	/** @var bool Somebody joined or left in this request, which does not wait for the daily run. */
	private static $urgent = false;

	/** @var bool */
	private static $flush_hooked = false;

	/**
	 * Runs on init, after Action Scheduler has set itself up at priority 1.
	 */
	public static function boot() {
		// Always answered, so a run booked before the club was switched off ends
		// quietly instead of being left with nothing listening.
		add_action( Dicex_Connect_Club_Queue::HOOK_TICK, array( 'Dicex_Connect_Club_Sync', 'run_scheduled_tick' ) );
		add_action( Dicex_Connect_Club_Queue::HOOK_DAILY, array( 'Dicex_Connect_Club_Sync', 'run_daily' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( 'Dicex_Connect_Club_Privacy', 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( 'Dicex_Connect_Club_Privacy', 'register_eraser' ) );

		if ( ! Dicex_Connect_Club_Settings::is_enabled() ) {
			return;
		}

		add_action( 'admin_init', array( 'Dicex_Connect_Club_Privacy', 'add_policy_text' ) );
		add_action( 'admin_init', array( __CLASS__, 'restore_schedule' ) );

		$settings = Dicex_Connect_Club_Settings::get();

		// The date of birth and the club box, on every form a customer fills in.
		Dicex_Connect_Club_Fields::boot( $settings );
		Dicex_Connect_Club_Language::boot();

		/*
		 * A product moved to another category or brand, or a category moved under
		 * another parent, changes what past purchases mean for a level. No order
		 * changes when that happens, so the daily run's reading of changed orders
		 * would never notice: these are heard in every sync mode.
		 */
		add_action( 'set_object_terms', array( __CLASS__, 'product_terms_changed' ), 10, 4 );

		foreach ( array( 'product_cat', 'product_brand' ) as $taxonomy ) {
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ) );
			add_action( 'delete_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ) );
		}

		// Daily only: nothing else to listen for. The daily run reads what changed.
		if ( 'daily' === $settings['sync_mode'] ) {
			return;
		}

		// Fired for every save, however it was made — checkout, the order screen,
		// a payment gateway, the REST API — by both order storage engines.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'order_changed' ) );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'order_changed' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_changed' ) );
		// A partial refund leaves the status alone and changes the total that counts.
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'order_changed' ) );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'order_changed' ) );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'order_gone' ) );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'order_gone' ) );

		add_action( 'user_register', array( __CLASS__, 'user_changed' ) );
		add_action( 'profile_update', array( __CLASS__, 'user_changed' ) );
		add_action( 'set_user_role', array( __CLASS__, 'user_changed' ) );
		add_action( 'deleted_user', array( __CLASS__, 'user_changed' ) );
		// A billing phone saved from My Account or the order screen.
		add_action( 'woocommerce_update_customer', array( __CLASS__, 'user_changed' ) );
	}

	/**
	 * Books the daily run again after the plugin was deactivated and reactivated.
	 */
	public static function restore_schedule() {
		if ( ! get_transient( 'dicex_connect_club_reschedule' ) ) {
			return;
		}

		delete_transient( 'dicex_connect_club_reschedule' );

		$settings = Dicex_Connect_Club_Settings::get();

		Dicex_Connect_Club_Queue::ensure_daily( $settings['daily_time'] );
		Dicex_Connect_Club_Queue::tick_soon();
	}

	/**
	 * @param int $order_id
	 */
	public static function order_changed( $order_id ) {
		self::$orders[ (int) $order_id ] = true;
		self::hook_flush();
	}

	/**
	 * @param int $order_id
	 */
	public static function order_gone( $order_id ) {
		self::$gone[ (int) $order_id ] = true;
		self::hook_flush();
	}

	/**
	 * @param int $user_id
	 */
	public static function user_changed( $user_id ) {
		self::$users[ (int) $user_id ] = true;
		self::hook_flush();
	}

	/**
	 * Somebody joined the club or left it.
	 *
	 * Leaving is somebody asking not to be contacted, so it is sent as soon as
	 * possible whatever the sync mode — and joining with it, since both come from
	 * the same box.
	 *
	 * @param int $user_id
	 */
	public static function membership_changed( $user_id ) {
		self::$urgent = true;
		self::user_changed( $user_id );
	}

	/**
	 * set_object_terms fires for every object and taxonomy; only a product's
	 * categories and brands matter here.
	 *
	 * @param int    $object_id
	 * @param array  $terms
	 * @param array  $tt_ids
	 * @param string $taxonomy
	 */
	public static function product_terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy && 'product_brand' !== $taxonomy ) {
			return;
		}

		self::$products[ (int) $object_id ] = true;
		self::hook_flush();
	}

	/**
	 * A category or brand was edited or deleted — possibly moved under another
	 * parent, which changes every purchase in it.
	 */
	public static function taxonomy_changed() {
		self::$taxonomy_changed = true;
		self::hook_flush();
	}

	private static function hook_flush() {
		if ( ! self::$flush_hooked ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
			self::$flush_hooked = true;
		}
	}

	/**
	 * Records what this request changed, once, after WooCommerce has finished
	 * saving it.
	 *
	 * Whatever goes wrong here is logged and swallowed: the page this runs at the
	 * end of belongs to somebody else — a shopper's checkout, an order screen.
	 */
	public static function flush() {
		if ( empty( self::$orders ) && empty( self::$gone ) && empty( self::$users ) && empty( self::$products ) && ! self::$taxonomy_changed ) {
			return;
		}

		try {
			Dicex_Connect_Club_Store::maybe_install();
			Dicex_Connect_Club_Sync::forget_seen_users();

			$settings = Dicex_Connect_Club_Settings::get();
			$welcome  = empty( $settings['welcome']['enabled'] ) ? 'skip' : 'due';

			foreach ( array_keys( self::$gone ) as $order_id ) {
				unset( self::$orders[ $order_id ] );
				Dicex_Connect_Club_Sync::forget_order( $order_id );
			}

			if ( Dicex_Connect_Club_Sources::has_woocommerce() ) {
				foreach ( array_keys( self::$orders ) as $order_id ) {
					$order = wc_get_order( $order_id );

					// Refunds are orders of their own type; the order they belong to is
					// what is recorded, through woocommerce_order_refunded.
					if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
						Dicex_Connect_Club_Sync::record_order( $order, $welcome );
					}
				}
			}

			foreach ( array_keys( self::$users ) as $user_id ) {
				Dicex_Connect_Club_Sync::record_user( $user_id, $welcome );
			}

			// Only worth looking at anybody again when some level asks about it.
			$uses_terms = Dicex_Connect_Club_Settings::uses( $settings, array( 'categories', 'brands' ) );

			if ( $uses_terms && self::$taxonomy_changed ) {
				Dicex_Connect_Club_Store::mark_all_dirty();
			} elseif ( $uses_terms ) {
				foreach ( array_keys( self::$products ) as $product_id ) {
					Dicex_Connect_Club_Store::mark_dirty_for_product( $product_id );
				}
			}

			// Daily only means daily: what was marked waits for the daily run — except
			// somebody joining or leaving.
			if ( 'daily' !== $settings['sync_mode'] || self::$urgent ) {
				Dicex_Connect_Club_Queue::tick_soon( 5 );
			}
		} catch ( Throwable $e ) {
			Dicex_Connect_Logger::log( 'CLUB RECORD FAILED: ' . $e->getMessage() );
		}

		self::$orders           = array();
		self::$gone             = array();
		self::$users            = array();
		self::$products         = array();
		self::$taxonomy_changed = false;
		self::$urgent           = false;
	}
}
