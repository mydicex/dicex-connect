<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the club asks customers itself: their date of birth, and whether they
 * want to be in the club.
 *
 * The two fields appear wherever customers give their details — the classic
 * checkout and the checkout block, the registration forms of WordPress and
 * WooCommerce, the account page — and on the WordPress profile screen, which
 * Dicex_Connect_User_Profile draws with the helpers below.
 *
 * What is kept, all of it the plugin's own meta:
 *
 *   user  dicex_connect_birthday        Gregorian Y-m-d
 *   user  dicex_connect_club_consent    given or left, with when and where each happened
 *   order _dicex_connect_birthday       Gregorian Y-m-d, typed on that order
 *   order _dicex_connect_club_consent   when the box was ticked on that order
 *
 * The checkout block, and the account page from WooCommerce 9.8, are drawn by
 * WooCommerce: the fields are registered with its Additional Checkout Fields API,
 * which stores what was typed under its own _wc_other/ keys. Every value it
 * saves passes woocommerce_set_additional_field_value first, where the plugin
 * keeps its own converted copy — so the keys above stay the one place the club
 * reads from, whichever form was used.
 *
 * WooCommerce's own data is never changed: orders and customers only gain the
 * keys above, through the CRUD API, the way WooCommerce documents.
 */
class Dicex_Connect_Club_Fields {

	const BIRTHDAY_META = 'dicex_connect_birthday';

	const CONSENT_META = 'dicex_connect_club_consent';

	const ORDER_BIRTHDAY_META = '_dicex_connect_birthday';

	const ORDER_CONSENT_META = '_dicex_connect_club_consent';

	/** Ids in WooCommerce's Additional Checkout Fields API. */
	const BLOCK_BIRTHDAY = 'dicex-connect/birthday';

	const BLOCK_CLUB = 'dicex-connect/club';

	/** Where WooCommerce keeps a contact field's value, on orders and customers alike. */
	const BLOCK_META_PREFIX = '_wc_other/';

	/** Names on the forms this plugin draws itself. */
	const FIELD_BIRTHDAY = 'dicex_connect_birthday';

	const FIELD_CLUB = 'dicex_connect_club';

	/**
	 * Says which of this plugin's forms was posted, so a save never reads fields
	 * from a form that did not show them.
	 */
	const FIELD_FORM = 'dicex_connect_club_form';

	/**
	 * From this version the account page saves block-registered fields; before it,
	 * the value was lost (WooCommerce PR 55047). Older stores get the plugin's own
	 * account fields and no block checkout fields.
	 */
	const BLOCK_FIELDS_SINCE = '9.8.0';

	/** @var array|null The club's settings for this request. */
	private static $settings = null;

	/**
	 * Adds the fields to the forms. Called by the module on init, only while the
	 * club is switched on.
	 *
	 * @param array $settings
	 */
	public static function boot( $settings ) {
		self::$settings = $settings;

		$birthday = Dicex_Connect_Club_Settings::asks_birthday( $settings );
		$consent  = 'consent' === $settings['join'];

		if ( Dicex_Connect_Club_Sources::has_woocommerce() ) {
			if ( self::use_block_fields() ) {
				self::register_block_fields( $birthday, $consent );

				add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'block_value_saved' ), 10, 4 );
				add_filter( 'woocommerce_get_default_value_for_' . self::BLOCK_BIRTHDAY, array( __CLASS__, 'block_birthday_default' ), 10, 3 );
				add_filter( 'woocommerce_get_default_value_for_' . self::BLOCK_CLUB, array( __CLASS__, 'block_club_default' ), 10, 3 );
			}

			if ( $birthday || $consent ) {
				add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ) );
				add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_value' ), 10, 2 );
				add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validate' ), 10, 2 );
				add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'checkout_save_order' ), 10, 2 );
				add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'checkout_save_customer' ), 10, 2 );

				add_action( 'woocommerce_register_form', array( __CLASS__, 'render_woocommerce_registration' ) );
				add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'validate_woocommerce_registration' ), 10, 4 );
				add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_woocommerce_registration' ) );
			}

			if ( ( $birthday && ! self::use_block_fields() ) || self::account_box_is_ours() ) {
				// The hook meant for extra account fields arrived in WooCommerce 8.7.
				$hook = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.7.0', '>=' ) ? 'woocommerce_edit_account_form_fields' : 'woocommerce_edit_account_form';

				add_action( $hook, array( __CLASS__, 'render_account' ) );
				add_action( 'woocommerce_save_account_details_errors', array( __CLASS__, 'validate_account' ), 10, 2 );
				add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_account' ) );
			}
		}

		if ( $birthday || $consent ) {
			add_action( 'register_form', array( __CLASS__, 'render_wordpress_registration' ) );
			add_filter( 'registration_errors', array( __CLASS__, 'validate_wordpress_registration' ), 10, 3 );
			add_action( 'register_new_user', array( __CLASS__, 'save_wordpress_registration' ) );
		}
	}

	/**
	 * @return array
	 */
	private static function settings() {
		if ( null === self::$settings ) {
			self::$settings = Dicex_Connect_Club_Settings::get();
		}

		return self::$settings;
	}

	/**
	 * Whether WooCommerce draws the fields on the checkout block and account page.
	 *
	 * @return bool
	 */
	public static function use_block_fields() {
		return function_exists( 'woocommerce_register_additional_checkout_field' )
			&& defined( 'WC_VERSION' )
			&& version_compare( WC_VERSION, self::BLOCK_FIELDS_SINCE, '>=' );
	}

	/**
	 * Whether the club box on the account page is this plugin's rather than
	 * WooCommerce's.
	 *
	 * With everybody in the club, the box is only a way out, and belongs on the
	 * account page alone — registered with WooCommerce it would show at checkout
	 * too. With consent, WooCommerce draws it where it can.
	 *
	 * @return bool
	 */
	private static function account_box_is_ours() {
		$settings = self::settings();

		if ( ! Dicex_Connect_Club_Settings::can_leave( $settings ) ) {
			return false;
		}

		return 'everyone' === $settings['join'] || ! self::use_block_fields();
	}

	/**
	 * The calendar the person looking at a form writes dates in.
	 *
	 * @return string
	 */
	public static function calendar() {
		return Dicex_Connect_Club_Settings::birthday_calendar( self::settings(), determine_locale() );
	}

	/* ---- Reading and writing ------------------------------------------- */

	/**
	 * @param int        $user_id
	 * @param array|null $settings
	 * @return string Gregorian Y-m-d, or ''.
	 */
	public static function user_birthday( $user_id, $settings = null ) {
		$settings = null === $settings ? self::settings() : $settings;
		$date     = (string) get_user_meta( (int) $user_id, self::BIRTHDAY_META, true );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		// Typed on the checkout block or the account page while the copy above was not being kept.
		$date = Dicex_Connect_Dates::read_stored_date( get_user_meta( (int) $user_id, self::BLOCK_META_PREFIX . self::BLOCK_BIRTHDAY, true ) );

		if ( '' !== $date ) {
			return $date;
		}

		$key = (string) $settings['birthday']['meta_key'];

		return '' === $key ? '' : Dicex_Connect_Dates::read_stored_date( get_user_meta( (int) $user_id, $key, true ) );
	}

	/**
	 * @param WC_Order $order
	 * @return string Gregorian Y-m-d, or ''.
	 */
	public static function order_birthday( $order ) {
		$date = (string) $order->get_meta( self::ORDER_BIRTHDAY_META, true );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		return Dicex_Connect_Dates::read_stored_date( $order->get_meta( self::BLOCK_META_PREFIX . self::BLOCK_BIRTHDAY, true ) );
	}

	/**
	 * Stores a birthday entered on one of this plugin's own forms.
	 *
	 * @param int    $user_id
	 * @param string $date Gregorian Y-m-d, or '' to remove it.
	 */
	public static function set_user_birthday( $user_id, $date ) {
		self::keep_user_birthday( $user_id, $date );

		// WooCommerce's copy of what was typed on its forms would now say something
		// else. Without it, its forms ask this plugin, and show this date.
		delete_user_meta( (int) $user_id, self::BLOCK_META_PREFIX . self::BLOCK_BIRTHDAY );
	}

	/**
	 * @param int    $user_id
	 * @param string $date
	 */
	private static function keep_user_birthday( $user_id, $date ) {
		$user_id = (int) $user_id;
		$before  = (string) get_user_meta( $user_id, self::BIRTHDAY_META, true );

		if ( '' === $date ) {
			delete_user_meta( $user_id, self::BIRTHDAY_META );
		} else {
			update_user_meta( $user_id, self::BIRTHDAY_META, $date );
		}

		if ( $before !== $date ) {
			// A birthday alone saves no user and no order, so nothing else would say so.
			Dicex_Connect_Club_Module::user_changed( $user_id );
		}
	}

	/**
	 * @param int $user_id
	 * @return array status ('', 'given' or 'left'), given_gmt, given_where, left_gmt, left_where.
	 */
	public static function consent( $user_id ) {
		$record = get_user_meta( (int) $user_id, self::CONSENT_META, true );

		return wp_parse_args(
			is_array( $record ) ? $record : array(),
			array(
				'status'      => '',
				'given_gmt'   => '',
				'given_where' => '',
				'left_gmt'    => '',
				'left_where'  => '',
			)
		);
	}

	/**
	 * @param int $user_id
	 * @return bool Whether this person asked to be in the club.
	 */
	public static function has_joined( $user_id ) {
		return 'given' === self::consent( $user_id )['status'];
	}

	/**
	 * @param int $user_id
	 * @return bool Whether this person took themselves out of the club.
	 */
	public static function has_left( $user_id ) {
		return 'left' === self::consent( $user_id )['status'];
	}

	/**
	 * Whether the club box should show ticked for a user.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function box_ticked( $user_id ) {
		return 'consent' === self::settings()['join'] ? self::has_joined( $user_id ) : ! self::has_left( $user_id );
	}

	/**
	 * What a user's club box said when a form was saved.
	 *
	 * With consent, ticking is joining and unticking after that is leaving. With
	 * everybody in, the box starts ticked, so unticking is leaving and ticking only
	 * matters for somebody who left — a box that was simply left ticked is not
	 * recorded as a choice anybody made.
	 *
	 * @param int    $user_id
	 * @param bool   $ticked
	 * @param string $where   checkout, registration, account or profile.
	 * @return bool Whether anything changed.
	 */
	public static function apply_box( $user_id, $ticked, $where ) {
		$user_id = (int) $user_id;
		$record  = self::consent( $user_id );
		$consent = 'consent' === self::settings()['join'];

		if ( $ticked ) {
			if ( 'given' === $record['status'] || ( ! $consent && 'left' !== $record['status'] ) ) {
				return false;
			}

			$record['status']      = 'given';
			$record['given_gmt']   = current_time( 'mysql', true );
			$record['given_where'] = $where;
		} else {
			if ( 'left' === $record['status'] || ( $consent && 'given' !== $record['status'] ) ) {
				return false;
			}

			$record['status']     = 'left';
			$record['left_gmt']   = current_time( 'mysql', true );
			$record['left_where'] = $where;
		}

		update_user_meta( $user_id, self::CONSENT_META, $record );

		Dicex_Connect_Logger::log( 'CLUB: user ' . $user_id . ( $ticked ? ' joined' : ' left' ) . ' from ' . $where );

		$settings = self::settings();

		// Back in: whoever had put them among the removed numbers, their own choice now says otherwise.
		if ( $ticked && '' !== (string) $settings['removed_level'] && Dicex_Connect_Club_Store::exists() ) {
			Dicex_Connect_Club_Store::unlock_removed( $user_id, (string) $settings['removed_level'] );
		}

		Dicex_Connect_Club_Module::membership_changed( $user_id );

		return true;
	}

	/**
	 * Records a person leaving on their behalf — the privacy eraser does this, so
	 * they do not quietly come back.
	 *
	 * @param int    $user_id
	 * @param string $where
	 */
	public static function mark_left( $user_id, $where ) {
		$record = self::consent( $user_id );

		if ( 'left' === $record['status'] ) {
			return;
		}

		$record['status']     = 'left';
		$record['left_gmt']   = current_time( 'mysql', true );
		$record['left_where'] = $where;

		update_user_meta( (int) $user_id, self::CONSENT_META, $record );
	}

	/**
	 * When and where somebody made a club choice, in words.
	 *
	 * @param string $gmt   MySQL datetime, UTC.
	 * @param string $where checkout, registration, account, profile or privacy.
	 * @return string
	 */
	public static function describe_choice( $gmt, $where ) {
		$places = array(
			'checkout'     => __( 'at checkout', 'dicex-connect' ),
			'registration' => __( 'when registering', 'dicex-connect' ),
			'account'      => __( 'on their account page', 'dicex-connect' ),
			'profile'      => __( 'on their profile', 'dicex-connect' ),
			'privacy'      => __( 'through a request to erase their data', 'dicex-connect' ),
		);

		$when = '' === (string) $gmt ? '' : get_date_from_gmt( (string) $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		if ( ! isset( $places[ $where ] ) ) {
			return $when;
		}

		return sprintf(
			/* translators: 1: a date and time, 2: where it happened, such as "at checkout" */
			__( '%1$s, %2$s', 'dicex-connect' ),
			$when,
			$places[ $where ]
		);
	}

	/**
	 * @param WC_Order $order
	 * @return bool Whether the club box was ticked on this order.
	 */
	public static function order_joined( $order ) {
		return '' !== (string) $order->get_meta( self::ORDER_CONSENT_META, true );
	}

	/**
	 * A date as the person reading it writes dates.
	 *
	 * @param string $date
	 * @return string
	 */
	public static function display_birthday( $date ) {
		return Dicex_Connect_Dates::format_birthday( $date, self::calendar() );
	}

	/**
	 * @param mixed $value
	 * @return string Gregorian Y-m-d, or '' for anything that is not a birthday.
	 */
	private static function parse_quietly( $value ) {
		$parsed = Dicex_Connect_Dates::parse_birthday( is_scalar( $value ) ? (string) $value : '' );

		return is_string( $parsed ) ? $parsed : '';
	}

	/**
	 * @param mixed $value A checkbox as WooCommerce passes it: true, '1', or '' and '0' for unticked.
	 * @return bool
	 */
	private static function ticked( $value ) {
		return true === $value || '1' === $value || 1 === $value;
	}

	/* ---- Checkout block and account page (WooCommerce draws these) ------ */

	/**
	 * @param bool $birthday
	 * @param bool $consent
	 */
	private static function register_block_fields( $birthday, $consent ) {
		$settings = self::settings();

		if ( $birthday ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCK_BIRTHDAY,
					// The block uses the label as the placeholder, so it says how to write the date.
					'label'                      => __( 'Date of birth (year/month/day)', 'dicex-connect' ),
					'optionalLabel'              => __( 'Date of birth (year/month/day, optional)', 'dicex-connect' ),
					'location'                   => 'contact',
					'type'                       => 'text',
					'required'                   => ! empty( $settings['birthday']['required'] ),
					'attributes'                 => array(
						'maxLength'    => 20,
						// Lets the browser offer the date it keeps, and assistive tools label it.
						'autocomplete' => 'bday',
						'title'        => sprintf(
							/* translators: %s: an example date, such as 1370/05/21 or 1991-08-12 */
							__( 'For example %s', 'dicex-connect' ),
							Dicex_Connect_Dates::example( self::calendar() )
						),
					),
					// A birthday has no place on receipts and order emails.
					'show_in_order_confirmation' => false,
					'sanitize_callback'          => array( __CLASS__, 'block_sanitize_birthday' ),
					'validate_callback'          => array( __CLASS__, 'block_validate_birthday' ),
				)
			);
		}

		if ( $consent ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCK_CLUB,
					'label'                      => Dicex_Connect_Club_Settings::consent_label( $settings ),
					'location'                   => 'contact',
					'type'                       => 'checkbox',
					'show_in_order_confirmation' => false,
				)
			);
		}
	}

	/**
	 * Keeps what was typed, tidied, so the form shows it back the way it was
	 * written.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function block_sanitize_birthday( $value ) {
		return trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * @param mixed $value
	 * @return WP_Error|true
	 */
	public static function block_validate_birthday( $value ) {
		$parsed = Dicex_Connect_Dates::parse_birthday( is_scalar( $value ) ? (string) $value : '', self::calendar() );

		return is_wp_error( $parsed ) ? $parsed : true;
	}

	/**
	 * Keeps the plugin's own copy of what WooCommerce is about to save.
	 *
	 * @param string               $key
	 * @param mixed                $value
	 * @param string               $group
	 * @param WC_Customer|WC_Order $wc_object
	 */
	public static function block_value_saved( $key, $value, $group, $wc_object ) {
		if ( self::BLOCK_BIRTHDAY !== $key && self::BLOCK_CLUB !== $key ) {
			return;
		}

		if ( $wc_object instanceof WC_Order ) {
			if ( self::BLOCK_BIRTHDAY === $key ) {
				$date = self::parse_quietly( $value );

				if ( '' === $date ) {
					$wc_object->delete_meta_data( self::ORDER_BIRTHDAY_META );
				} else {
					$wc_object->update_meta_data( self::ORDER_BIRTHDAY_META, $date );
				}

				return;
			}

			// Somebody editing an order in the admin is not the customer agreeing to anything.
			if ( is_admin() && ! wp_doing_ajax() ) {
				return;
			}

			if ( self::ticked( $value ) ) {
				$wc_object->update_meta_data( self::ORDER_CONSENT_META, current_time( 'mysql', true ) );
			} else {
				$wc_object->delete_meta_data( self::ORDER_CONSENT_META );
			}

			return;
		}

		// The shopping session's copy of a customer changes while the checkout is
		// being filled in; only the saved customer is what they submitted.
		if ( ! $wc_object instanceof WC_Customer || $wc_object->get_id() <= 0 || self::is_session_customer( $wc_object ) ) {
			return;
		}

		if ( self::BLOCK_BIRTHDAY === $key ) {
			self::keep_user_birthday( $wc_object->get_id(), self::parse_quietly( $value ) );
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		self::apply_box( $wc_object->get_id(), self::ticked( $value ), doing_action( 'woocommerce_save_account_details' ) ? 'account' : 'checkout' );
	}

	/**
	 * What WooCommerce's forms show when they have no typed value of their own.
	 *
	 * @param mixed   $value     Always null.
	 * @param string  $group
	 * @param WC_Data $wc_object
	 * @return mixed
	 */
	public static function block_birthday_default( $value, $group, $wc_object ) {
		if ( ! $wc_object instanceof WC_Customer || $wc_object->get_id() <= 0 ) {
			return $value;
		}

		$date = self::user_birthday( $wc_object->get_id() );

		return '' === $date ? $value : self::display_birthday( $date );
	}

	/**
	 * Ticks the box for somebody already in, and otherwise says nothing.
	 *
	 * An explicit '0' would be sent to the checkout block with every update of the
	 * customer while the form is being filled in, and could untick a box the
	 * shopper had just ticked. Without a value the box simply starts unticked.
	 *
	 * @param mixed   $value
	 * @param string  $group
	 * @param WC_Data $wc_object
	 * @return mixed '1' for a customer in the club, as WooCommerce stores a ticked checkbox.
	 */
	public static function block_club_default( $value, $group, $wc_object ) {
		if ( ! $wc_object instanceof WC_Customer || $wc_object->get_id() <= 0 ) {
			return $value;
		}

		return self::box_ticked( $wc_object->get_id() ) ? '1' : $value;
	}

	/**
	 * @param WC_Customer $customer
	 * @return bool
	 */
	private static function is_session_customer( $customer ) {
		$store = $customer->get_data_store();

		return is_object( $store ) && method_exists( $store, 'get_current_class_name' ) && 'WC_Customer_Data_Store_Session' === $store->get_current_class_name();
	}

	/* ---- Classic checkout ----------------------------------------------- */

	/**
	 * Adds the fields to the billing details. Their names do not start with
	 * billing_, so WooCommerce does not store them on its own; the plugin does,
	 * below.
	 *
	 * @param array $fields
	 * @return array
	 */
	public static function checkout_fields( $fields ) {
		$settings = self::settings();

		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) ) {
			$fields['billing'][ self::FIELD_BIRTHDAY ] = array(
				'type'              => 'text',
				'label'             => __( 'Date of birth', 'dicex-connect' ),
				'placeholder'       => Dicex_Connect_Dates::example( self::calendar() ),
				'description'       => __( 'Year, month and day.', 'dicex-connect' ),
				'required'          => ! empty( $settings['birthday']['required'] ),
				'class'             => array( 'form-row-wide' ),
				'maxlength'         => 20,
				'priority'          => 115,
				'autocomplete'      => 'bday',
				'custom_attributes' => array( 'dir' => 'ltr' ),
			);
		}

		if ( 'consent' === $settings['join'] ) {
			$fields['billing'][ self::FIELD_CLUB ] = array(
				'type'     => 'checkbox',
				'label'    => Dicex_Connect_Club_Settings::consent_label( $settings ),
				'required' => false,
				'class'    => array( 'form-row-wide' ),
				'priority' => 116,
			);
		}

		return $fields;
	}

	/**
	 * @param mixed  $value Null unless something else answered first.
	 * @param string $input
	 * @return mixed
	 */
	public static function checkout_value( $value, $input ) {
		if ( null !== $value || ! is_user_logged_in() ) {
			return $value;
		}

		if ( self::FIELD_BIRTHDAY === $input ) {
			$date = self::user_birthday( get_current_user_id() );

			return '' === $date ? $value : self::display_birthday( $date );
		}

		if ( self::FIELD_CLUB === $input && self::has_joined( get_current_user_id() ) ) {
			return 1;
		}

		return $value;
	}

	/**
	 * @param array    $data
	 * @param WP_Error $errors
	 */
	public static function checkout_validate( $data, $errors ) {
		if ( ! isset( $data[ self::FIELD_BIRTHDAY ] ) || '' === (string) $data[ self::FIELD_BIRTHDAY ] ) {
			// An empty required field is refused by WooCommerce itself.
			return;
		}

		$parsed = Dicex_Connect_Dates::parse_birthday( $data[ self::FIELD_BIRTHDAY ], self::calendar() );

		if ( is_wp_error( $parsed ) ) {
			$errors->add( self::FIELD_BIRTHDAY . '_invalid', $parsed->get_error_message(), array( 'id' => self::FIELD_BIRTHDAY ) );
		}
	}

	/**
	 * @param WC_Order $order Not saved yet; WooCommerce saves it next.
	 * @param array    $data
	 */
	public static function checkout_save_order( $order, $data ) {
		if ( isset( $data[ self::FIELD_BIRTHDAY ] ) ) {
			$date = self::parse_quietly( $data[ self::FIELD_BIRTHDAY ] );

			if ( '' !== $date ) {
				$order->update_meta_data( self::ORDER_BIRTHDAY_META, $date );
			}
		}

		if ( ! empty( $data[ self::FIELD_CLUB ] ) ) {
			$order->update_meta_data( self::ORDER_CONSENT_META, current_time( 'mysql', true ) );
		}
	}

	/**
	 * The account behind the checkout: an existing customer, or one just created.
	 *
	 * @param WC_Customer $customer
	 * @param array       $data
	 */
	public static function checkout_save_customer( $customer, $data ) {
		$user_id = (int) $customer->get_id();

		if ( $user_id <= 0 ) {
			return;
		}

		if ( isset( $data[ self::FIELD_BIRTHDAY ] ) ) {
			$date = self::parse_quietly( $data[ self::FIELD_BIRTHDAY ] );

			// An empty field at checkout is somebody skipping it, not deleting what their account holds.
			if ( '' !== $date ) {
				self::set_user_birthday( $user_id, $date );
			}
		}

		if ( array_key_exists( self::FIELD_CLUB, $data ) ) {
			self::apply_box( $user_id, ! empty( $data[ self::FIELD_CLUB ] ), 'checkout' );
		}
	}

	/* ---- Registration ---------------------------------------------------- */

	public static function render_woocommerce_registration() {
		$settings = self::settings();

		// Shown again after a refused registration, the way WooCommerce refills its own fields.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only refills the form WooCommerce is drawing; nothing is saved here.
		$birthday = isset( $_POST[ self::FIELD_BIRTHDAY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_BIRTHDAY ] ) ) : '';
		$ticked   = ! empty( $_POST[ self::FIELD_CLUB ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) ) {
			woocommerce_form_field(
				self::FIELD_BIRTHDAY,
				array(
					'type'              => 'text',
					'id'                => 'reg_' . self::FIELD_BIRTHDAY,
					'label'             => __( 'Date of birth', 'dicex-connect' ),
					'placeholder'       => Dicex_Connect_Dates::example( self::calendar() ),
					'description'       => __( 'Year, month and day.', 'dicex-connect' ),
					'required'          => ! empty( $settings['birthday']['required'] ),
					'class'             => array( 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row-wide' ),
					'input_class'       => array( 'woocommerce-Input', 'woocommerce-Input--text' ),
					'maxlength'         => 20,
					'autocomplete'      => 'bday',
					'custom_attributes' => array( 'dir' => 'ltr' ),
				),
				$birthday
			);
		}

		if ( 'consent' === $settings['join'] ) {
			woocommerce_form_field(
				self::FIELD_CLUB,
				array(
					'type'  => 'checkbox',
					'id'    => 'reg_' . self::FIELD_CLUB,
					'label' => Dicex_Connect_Club_Settings::consent_label( $settings ),
					'class' => array( 'woocommerce-form-row', 'form-row-wide' ),
				),
				$ticked ? 1 : 0
			);
		}

		echo '<input type="hidden" name="' . esc_attr( self::FIELD_FORM ) . '" value="registration">';
	}

	/**
	 * Only the account page's own registration form runs this filter.
	 *
	 * @param WP_Error $errors
	 * @param string   $username
	 * @param string   $password
	 * @param string   $email
	 * @return WP_Error
	 */
	public static function validate_woocommerce_registration( $errors, $username, $password, $email ) {
		return self::validate_registration( $errors, false );
	}

	/**
	 * @param int $customer_id
	 */
	public static function save_woocommerce_registration( $customer_id ) {
		self::save_registration( $customer_id );
	}

	public static function render_wordpress_registration() {
		$settings = self::settings();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WordPress's registration form carries no nonce; this only refills it after a refusal.
		$birthday = isset( $_POST[ self::FIELD_BIRTHDAY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_BIRTHDAY ] ) ) : '';
		$ticked   = ! empty( $_POST[ self::FIELD_CLUB ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) ) {
			$label = empty( $settings['birthday']['required'] ) ? __( 'Date of birth (optional)', 'dicex-connect' ) : __( 'Date of birth', 'dicex-connect' );
			// The placeholder goes as soon as somebody types, so the format is said in words too.
			$hint = __( 'Year, month and day.', 'dicex-connect' ) . ' ' . sprintf(
				/* translators: %s: an example date, such as 1370/05/21 or 1991-08-12 */
				__( 'For example %s', 'dicex-connect' ),
				Dicex_Connect_Dates::example( self::calendar() )
			);
			?>
			<p>
				<label for="<?php echo esc_attr( self::FIELD_BIRTHDAY ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="text" name="<?php echo esc_attr( self::FIELD_BIRTHDAY ); ?>" id="<?php echo esc_attr( self::FIELD_BIRTHDAY ); ?>" class="input" dir="ltr" maxlength="20" autocomplete="bday"
					aria-describedby="<?php echo esc_attr( self::FIELD_BIRTHDAY ); ?>-description"
					value="<?php echo esc_attr( $birthday ); ?>" placeholder="<?php echo esc_attr( Dicex_Connect_Dates::example( self::calendar() ) ); ?>">
				<span class="description" id="<?php echo esc_attr( self::FIELD_BIRTHDAY ); ?>-description"><?php echo esc_html( $hint ); ?></span>
			</p>
			<?php
		}

		if ( 'consent' === $settings['join'] ) {
			?>
			<p>
				<label for="<?php echo esc_attr( self::FIELD_CLUB ); ?>">
					<input type="checkbox" name="<?php echo esc_attr( self::FIELD_CLUB ); ?>" id="<?php echo esc_attr( self::FIELD_CLUB ); ?>" value="1" <?php checked( $ticked ); ?>>
					<?php echo esc_html( Dicex_Connect_Club_Settings::consent_label( $settings ) ); ?>
				</label>
			</p>
			<?php
		}

		echo '<input type="hidden" name="' . esc_attr( self::FIELD_FORM ) . '" value="registration">';
	}

	/**
	 * @param WP_Error $errors
	 * @param string   $login
	 * @param string   $email
	 * @return WP_Error
	 */
	public static function validate_wordpress_registration( $errors, $login, $email ) {
		return self::validate_registration( $errors, true );
	}

	/**
	 * @param int $user_id
	 */
	public static function save_wordpress_registration( $user_id ) {
		self::save_registration( $user_id );
	}

	/**
	 * @param WP_Error $errors
	 * @param bool     $prefixed WordPress's own registration errors start with "Error:".
	 * @return WP_Error
	 */
	private static function validate_registration( $errors, $prefixed ) {
		$settings = self::settings();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checks its registration nonce before this filter runs; WordPress's own registration form has none.
		if ( ! Dicex_Connect_Club_Settings::asks_birthday( $settings ) || ! isset( $_POST[ self::FIELD_FORM ] ) ) {
			return $errors;
		}

		$raw = isset( $_POST[ self::FIELD_BIRTHDAY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_BIRTHDAY ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$parsed  = Dicex_Connect_Dates::parse_birthday( $raw, self::calendar() );
		$message = '';

		if ( is_wp_error( $parsed ) ) {
			$message = $parsed->get_error_message();
		} elseif ( '' === $parsed && ! empty( $settings['birthday']['required'] ) ) {
			$message = __( 'Please enter your date of birth.', 'dicex-connect' );
		}

		if ( '' !== $message ) {
			$errors->add(
				self::FIELD_BIRTHDAY,
				$prefixed ? '<strong>' . esc_html__( 'Error:', 'dicex-connect' ) . '</strong> ' . esc_html( $message ) : $message
			);
		}

		return $errors;
	}

	/**
	 * @param int $user_id
	 */
	private static function save_registration( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Runs inside a registration WordPress or WooCommerce has already accepted; the marker below says it was that form.
		if ( ! isset( $_POST[ self::FIELD_FORM ] ) || 'registration' !== sanitize_key( wp_unslash( $_POST[ self::FIELD_FORM ] ) ) ) {
			return;
		}

		$settings = self::settings();

		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) && isset( $_POST[ self::FIELD_BIRTHDAY ] ) ) {
			$date = self::parse_quietly( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_BIRTHDAY ] ) ) );

			if ( '' !== $date ) {
				self::set_user_birthday( $user_id, $date );
			}
		}

		if ( 'consent' === $settings['join'] && ! empty( $_POST[ self::FIELD_CLUB ] ) ) {
			self::apply_box( $user_id, true, 'registration' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* ---- Account page, where WooCommerce does not draw the fields ------- */

	public static function render_account() {
		$settings = self::settings();
		$user_id  = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) && ! self::use_block_fields() ) {
			$date = self::user_birthday( $user_id );

			woocommerce_form_field(
				self::FIELD_BIRTHDAY,
				array(
					'type'              => 'text',
					'id'                => 'account_' . self::FIELD_BIRTHDAY,
					'label'             => __( 'Date of birth', 'dicex-connect' ),
					'placeholder'       => Dicex_Connect_Dates::example( self::calendar() ),
					'description'       => __( 'Year, month and day.', 'dicex-connect' ),
					'required'          => ! empty( $settings['birthday']['required'] ),
					'class'             => array( 'woocommerce-form-row', 'woocommerce-form-row--wide', 'form-row-wide' ),
					'input_class'       => array( 'woocommerce-Input', 'woocommerce-Input--text' ),
					'maxlength'         => 20,
					'autocomplete'      => 'bday',
					'custom_attributes' => array( 'dir' => 'ltr' ),
				),
				'' === $date ? '' : self::display_birthday( $date )
			);
		}

		if ( self::account_box_is_ours() && self::shows_box_to( $user_id ) ) {
			woocommerce_form_field(
				self::FIELD_CLUB,
				array(
					'type'  => 'checkbox',
					'id'    => 'account_' . self::FIELD_CLUB,
					'label' => Dicex_Connect_Club_Settings::consent_label( $settings ),
					'class' => array( 'woocommerce-form-row', 'form-row-wide' ),
				),
				self::box_ticked( $user_id ) ? 1 : 0
			);

			echo '<input type="hidden" name="' . esc_attr( self::FIELD_FORM ) . '" value="account">';
		}
	}

	/**
	 * Whether a user is somebody the club box means anything to.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function shows_box_to( $user_id ) {
		$settings = self::settings();

		if ( 'consent' === $settings['join'] ) {
			return true;
		}

		// With everybody in, only somebody the club takes in has anything to leave.
		return Dicex_Connect_Club_Sources::user_in_scope( get_userdata( (int) $user_id ), $settings['roles'] )
			|| self::has_left( $user_id );
	}

	/**
	 * @param WP_Error $errors Passed by reference by WooCommerce.
	 * @param stdClass $user
	 */
	public static function validate_account( $errors, $user ) {
		$settings = self::settings();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form's nonce before running this hook.
		if ( ! Dicex_Connect_Club_Settings::asks_birthday( $settings ) || self::use_block_fields() || ! isset( $_POST[ self::FIELD_BIRTHDAY ] ) ) {
			return;
		}

		$parsed = Dicex_Connect_Dates::parse_birthday( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_BIRTHDAY ] ) ), self::calendar() );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $parsed ) ) {
			$errors->add( self::FIELD_BIRTHDAY, $parsed->get_error_message() );
		} elseif ( '' === $parsed && ! empty( $settings['birthday']['required'] ) ) {
			$errors->add( self::FIELD_BIRTHDAY, __( 'Please enter your date of birth.', 'dicex-connect' ) );
		}
	}

	/**
	 * @param int $user_id
	 */
	public static function save_account( $user_id ) {
		$settings = self::settings();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the account form's nonce before running this hook.
		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) && ! self::use_block_fields() && isset( $_POST[ self::FIELD_BIRTHDAY ] ) ) {
			$parsed = Dicex_Connect_Dates::parse_birthday( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_BIRTHDAY ] ) ) );

			// Emptied on purpose here is removed; anything unreadable was refused above.
			if ( is_string( $parsed ) ) {
				self::set_user_birthday( $user_id, $parsed );
			}
		}

		if ( self::account_box_is_ours() && isset( $_POST[ self::FIELD_FORM ] ) && 'account' === sanitize_key( wp_unslash( $_POST[ self::FIELD_FORM ] ) ) ) {
			self::apply_box( $user_id, ! empty( $_POST[ self::FIELD_CLUB ] ), 'account' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
