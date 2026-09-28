<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the club's customers come from: WordPress users, and WooCommerce orders.
 *
 * Read only. Orders are read through the CRUD API — the WC_Order a hook hands
 * over, wc_get_order(), wc_get_orders() — so this works the same on stores using
 * High-Performance Order Storage as on the older post tables. Nothing here writes
 * to a user, a customer or an order.
 */
class Dicex_Connect_Club_Sources {

	/** @var array Taxonomy => product id => term ids, for this request. */
	private static $terms = array();

	/**
	 * @return bool
	 */
	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * The currency the store prices in. Level conditions are written in it, and
	 * only orders placed in it are counted towards them.
	 *
	 * @return string
	 */
	public static function store_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * @param string $country Two-letter country code.
	 * @return string Such as +968, or '' when unknown.
	 */
	public static function calling_code( $country ) {
		$country = strtoupper( (string) $country );

		if ( '' === $country || ! function_exists( 'WC' ) || ! isset( WC()->countries ) || ! method_exists( WC()->countries, 'get_country_calling_code' ) ) {
			return '';
		}

		// WC_Countries::get_country_calling_code(), WooCommerce 3.6 and later.
		$code = WC()->countries->get_country_calling_code( $country );

		return is_array( $code ) ? (string) reset( $code ) : (string) $code;
	}

	/**
	 * A usable number out of a phone field somebody typed into, or ''.
	 *
	 * With a country — from a billing address — a local number is completed with
	 * that country's code. Without one, only a number that says its own country
	 * is accepted, or a national one in a region that is a single country: the
	 * same rule as a number typed into this plugin's own fields. A contact written
	 * into DiceX under a wrong number can never be taken back out, so a number
	 * that cannot be placed is left out rather than guessed.
	 *
	 * @param string $phone
	 * @param string $country
	 * @return string
	 */
	public static function mobile_from( $phone, $country = '' ) {
		$phone = trim( (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		$code   = self::calling_code( $country );
		$number = ( '' !== $code )
			? Dicex_Connect_Mobile::normalize_for_country( $phone, $code )
			: Dicex_Connect_Mobile::from_input( $phone );

		return ( '' !== $number && Dicex_Connect_Mobile::is_valid( $number ) ) ? $number : '';
	}

	/**
	 * The number a registered user is known by in the club.
	 *
	 * The mobile field this plugin adds to the profile comes first — somebody
	 * chose it on purpose. The WooCommerce billing phone saved on the account is
	 * next. Last, the phone on the customer's newest order: an order the shop
	 * enters for a customer by hand does not copy its billing phone to the
	 * account, and that customer is still a buyer.
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function user_mobile( $user_id ) {
		$number = Dicex_Connect_Recipients::get_user_number( $user_id );

		if ( '' !== $number || ! self::has_woocommerce() || ! class_exists( 'WC_Customer' ) ) {
			return $number;
		}

		$customer = new WC_Customer( (int) $user_id );
		$number   = self::mobile_from( $customer->get_billing_phone(), $customer->get_billing_country() );

		return '' !== $number ? $number : Dicex_Connect_Club_Store::latest_user_order_mobile( (int) $user_id );
	}

	/**
	 * @param WP_User $user
	 * @param array   $roles
	 * @return bool Whether this user's role puts them in the club.
	 */
	public static function user_in_scope( $user, $roles ) {
		return $user instanceof WP_User && array() !== array_intersect( (array) $user->roles, (array) $roles );
	}

	/**
	 * What the club knows about a registered user.
	 *
	 * @param int $user_id
	 * @return array name, family, email, company, address
	 */
	public static function user_profile( $user_id ) {
		$user    = get_userdata( (int) $user_id );
		$profile = array(
			'name'    => '',
			'family'  => '',
			'email'   => '',
			'company' => '',
			'address' => '',
		);

		if ( ! $user instanceof WP_User ) {
			return $profile;
		}

		$profile['name']   = (string) $user->first_name;
		$profile['family'] = (string) $user->last_name;
		$profile['email']  = (string) $user->user_email;

		if ( self::has_woocommerce() && class_exists( 'WC_Customer' ) ) {
			$customer = new WC_Customer( (int) $user_id );

			if ( '' === $profile['name'] ) {
				$profile['name'] = (string) $customer->get_billing_first_name();
			}

			if ( '' === $profile['family'] ) {
				$profile['family'] = (string) $customer->get_billing_last_name();
			}

			$profile['company'] = (string) $customer->get_billing_company();
			$profile['address'] = self::format_address(
				array(
					'address_1' => $customer->get_billing_address_1(),
					'address_2' => $customer->get_billing_address_2(),
					'city'      => $customer->get_billing_city(),
					'state'     => $customer->get_billing_state(),
					'postcode'  => $customer->get_billing_postcode(),
					'country'   => $customer->get_billing_country(),
				)
			);
		}

		if ( '' === $profile['name'] && '' === $profile['family'] ) {
			$profile['name'] = (string) $user->display_name;
		}

		return $profile;
	}

	/**
	 * A guest's date of birth: the one on their newest paid order that has one. A
	 * guest who typed it once and skipped it the next time still has a birthday.
	 *
	 * @param string $mobile
	 * @return string Gregorian Y-m-d, or ''.
	 */
	public static function guest_birthday( $mobile ) {
		if ( ! self::has_woocommerce() ) {
			return '';
		}

		foreach ( Dicex_Connect_Club_Store::guest_order_ids( $mobile, 20 ) as $order_id ) {
			$order = wc_get_order( $order_id );
			$date  = $order instanceof WC_Order ? Dicex_Connect_Club_Fields::order_birthday( $order ) : '';

			if ( '' !== $date ) {
				return $date;
			}
		}

		return '';
	}

	/**
	 * Whether somebody paid under a number without an account, giving this email.
	 *
	 * Typing a number at checkout proves nothing; paying under it with an email
	 * is what ties that email to the guest behind the number. An unpaid order
	 * never counts, so nobody gets a customer's club record by placing one.
	 *
	 * @param string $mobile
	 * @param string $email
	 * @return bool
	 */
	public static function guest_paid_with_email( $mobile, $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email || ! self::has_woocommerce() ) {
			return false;
		}

		foreach ( Dicex_Connect_Club_Store::guest_order_ids( (string) $mobile, 50 ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order && strtolower( trim( (string) $order->get_billing_email() ) ) === $email ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What the club knows about a guest, from their newest paid order.
	 *
	 * @param int $order_id
	 * @return array name, family, email, company, address
	 */
	public static function order_profile( $order_id ) {
		$profile = array(
			'name'    => '',
			'family'  => '',
			'email'   => '',
			'company' => '',
			'address' => '',
		);

		$order = ( $order_id > 0 && self::has_woocommerce() ) ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return $profile;
		}

		$profile['name']    = (string) $order->get_billing_first_name();
		$profile['family']  = (string) $order->get_billing_last_name();
		$profile['email']   = (string) $order->get_billing_email();
		$profile['company'] = (string) $order->get_billing_company();
		$profile['address'] = self::format_address(
			array(
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city'      => $order->get_billing_city(),
				'state'     => $order->get_billing_state(),
				'postcode'  => $order->get_billing_postcode(),
				'country'   => $order->get_billing_country(),
			)
		);

		return $profile;
	}

	/**
	 * An order reduced to what levels are decided by.
	 *
	 * @param WC_Order $order
	 * @return array Columns of the club's orders table, in its order.
	 */
	public static function order_row( $order ) {
		$created = $order->get_date_created();

		return array(
			'order_id'    => (int) $order->get_id(),
			'mobile'      => self::mobile_from( $order->get_billing_phone(), $order->get_billing_country() ),
			'user_id'     => (int) $order->get_customer_id(),
			'status'      => (string) $order->get_status(),
			// The statuses WooCommerce itself treats as paid: processing and completed,
			// unless a store has changed that through its own filter.
			'paid'        => in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) ? 1 : 0,
			// wc_format_decimal() rather than a float cast: a rial total in the
			// billions would otherwise be written in exponent notation.
			'total'       => wc_format_decimal( $order->get_total() ),
			'refunded'    => wc_format_decimal( $order->get_total_refunded() ),
			'currency'    => (string) $order->get_currency(),
			'created_gmt' => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : null,
			'country'     => self::country_code( $order->get_billing_country() ),
			'city'        => self::city_name( $order->get_billing_city() ),
		);
	}

	/**
	 * The products an order bought, by parent product.
	 *
	 * A variation line item carries its parent in product_id and itself in
	 * variation_id (WC_Order_Item_Product), and categories and brands belong to
	 * the parent — so the parent is what a level's lists are about.
	 *
	 * @param WC_Order $order
	 * @return int[]
	 */
	public static function order_product_ids( $order ) {
		$ids = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product && (int) $item->get_product_id() > 0 ) {
				$ids[ (int) $item->get_product_id() ] = true;
			}
		}

		return array_keys( $ids );
	}

	/**
	 * The billing country and city saved on a registered customer.
	 *
	 * Where a customer with no paid order yet is, for a level that asks.
	 *
	 * @param int $user_id
	 * @return array country, city
	 */
	public static function user_location( $user_id ) {
		if ( ! self::has_woocommerce() || ! class_exists( 'WC_Customer' ) ) {
			return array(
				'country' => '',
				'city'    => '',
			);
		}

		$customer = new WC_Customer( (int) $user_id );

		return array(
			'country' => self::country_code( $customer->get_billing_country() ),
			'city'    => self::city_name( $customer->get_billing_city() ),
		);
	}

	/**
	 * The terms of a taxonomy that these products are in, with every ancestor.
	 *
	 * A product in "Shoes › Sneakers" counts for a level that lists "Shoes", the
	 * way WooCommerce's own category pages include their subcategories. Terms are
	 * read through wp_get_object_terms() in one query per batch of products not
	 * seen yet in this request.
	 *
	 * @param int[]  $product_ids
	 * @param string $taxonomy    product_cat or product_brand.
	 * @return int[]
	 */
	public static function product_terms( $product_ids, $taxonomy ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );

		if ( empty( $product_ids ) || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		if ( ! isset( self::$terms[ $taxonomy ] ) ) {
			self::$terms[ $taxonomy ] = array();
		}

		$missing = array_values( array_diff( $product_ids, array_keys( self::$terms[ $taxonomy ] ) ) );

		if ( ! empty( $missing ) ) {
			foreach ( $missing as $product_id ) {
				self::$terms[ $taxonomy ][ $product_id ] = array();
			}

			$found = wp_get_object_terms( $missing, $taxonomy, array( 'fields' => 'all_with_object_id' ) );

			if ( ! is_wp_error( $found ) ) {
				foreach ( $found as $term ) {
					$ids = array_merge(
						array( (int) $term->term_id ),
						array_map( 'intval', get_ancestors( (int) $term->term_id, $taxonomy, 'taxonomy' ) )
					);

					self::$terms[ $taxonomy ][ (int) $term->object_id ] = array_merge( self::$terms[ $taxonomy ][ (int) $term->object_id ], $ids );
				}
			}
		}

		$terms = array();

		foreach ( $product_ids as $product_id ) {
			$terms = array_merge( $terms, self::$terms[ $taxonomy ][ $product_id ] );
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Forgets the terms read in this request. A product just moved to another
	 * category must be read again.
	 */
	public static function forget_terms() {
		self::$terms = array();
	}

	/**
	 * @param string $country
	 * @return string Two uppercase letters, or ''.
	 */
	private static function country_code( $country ) {
		$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $country ) );

		return 2 === strlen( $country ) ? $country : '';
	}

	/**
	 * @param string $city
	 * @return string As typed, trimmed, at most 100 characters.
	 */
	private static function city_name( $city ) {
		$city = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $city ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $city, 0, 100, 'UTF-8' ) : substr( $city, 0, 100 );
	}

	/**
	 * One line of address, in the country's own order.
	 *
	 * @param array $parts
	 * @return string
	 */
	private static function format_address( $parts ) {
		if ( '' === implode( '', array_map( 'strval', $parts ) ) || ! function_exists( 'WC' ) || ! isset( WC()->countries ) ) {
			return '';
		}

		// The separator argument exists since WooCommerce 3.5.
		$address = WC()->countries->get_formatted_address( $parts, ', ' );

		return trim( wp_strip_all_tags( html_entity_decode( (string) $address, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
