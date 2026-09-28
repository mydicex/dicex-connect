<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language a customer reads, so a club message reaches them in it.
 *
 * WooCommerce keeps no such thing: its own emails switch to the shop's language,
 * not the shopper's. What exists is what a multilingual plugin wrote down, so
 * this reads theirs first and falls back to WordPress's own idea of a user's
 * language:
 *
 *   order  the plugin's own note, taken at the classic checkout
 *          WPML: order meta wpml_language, a language code
 *          TranslatePress: order meta trp_language, a locale
 *          Polylang: pll_get_post_language(), where it answers
 *   user   TranslatePress and WPML's own user meta, then the profile language
 *
 * The block checkout is deliberately not sampled. Its requests are sent with
 * `_locale=user`, so WordPress answers with the customer's profile language
 * rather than the language they were shopping in — a snapshot there would be
 * wrong more often than empty.
 *
 * Reads only. Nothing here changes a multilingual plugin's data.
 */
class Dicex_Connect_Club_Language {

	/** Where this plugin notes the language an order was placed in. */
	const ORDER_META = '_dicex_connect_locale';

	const WPML_ORDER_META = 'wpml_language';

	const WPML_USER_META = 'icl_admin_language';

	/** TranslatePress keeps a locale under the same key on orders and users. */
	const TRP_META = 'trp_language';

	/** Worked out once a request: whether this site is read in more than one language. */
	private static $multilingual = null;

	/**
	 * Called by the module while the club is on.
	 */
	public static function boot() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'record_order_locale' ), 10, 2 );
	}

	/**
	 * Notes the language of a classic checkout, which is an ordinary front-end
	 * request: whatever a multilingual plugin set is what the shopper was reading.
	 *
	 * @param WC_Order $order Not saved yet; WooCommerce saves it next.
	 * @param array    $data
	 */
	public static function record_order_locale( $order, $data ) {
		if ( ! self::multilingual() ) {
			return;
		}

		$locale = Dicex_Connect_Club_Settings::clean_locale( determine_locale() );

		if ( '' !== $locale ) {
			$order->update_meta_data( self::ORDER_META, $locale );
		}
	}

	/**
	 * @param WC_Order $order
	 * @return string A locale such as fa_IR, or ''.
	 */
	public static function for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		foreach ( array( self::ORDER_META, self::TRP_META ) as $key ) {
			$locale = Dicex_Connect_Club_Settings::clean_locale( $order->get_meta( $key, true ) );

			if ( '' !== $locale ) {
				return $locale;
			}
		}

		$wpml = self::locale_from_code( $order->get_meta( self::WPML_ORDER_META, true ) );

		if ( '' !== $wpml ) {
			return $wpml;
		}

		// Polylang keeps the language of an order in its own taxonomy. Its function
		// answers where it can, and this takes no for an answer where it cannot.
		if ( function_exists( 'pll_get_post_language' ) ) {
			return Dicex_Connect_Club_Settings::clean_locale( pll_get_post_language( $order->get_id(), 'locale' ) );
		}

		return '';
	}

	/**
	 * The language a user chose for themselves, or '' when they never did.
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function for_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return '';
		}

		foreach ( array( self::TRP_META, 'locale' ) as $key ) {
			$locale = Dicex_Connect_Club_Settings::clean_locale( get_user_meta( $user_id, $key, true ) );

			if ( '' !== $locale ) {
				return $locale;
			}
		}

		return self::locale_from_code( get_user_meta( $user_id, self::WPML_USER_META, true ) );
	}

	/**
	 * The language to write to one member in: their own if they have one, else the
	 * language of their newest order, else the site's.
	 *
	 * @param string $mobile
	 * @param int    $user_id
	 * @return string
	 */
	public static function for_member( $mobile, $user_id ) {
		$locale = self::for_user( $user_id );

		if ( '' !== $locale ) {
			return $locale;
		}

		// Orders only carry a language where something wrote one. On a site read in
		// one language that is always the site's, and reading them would buy nothing.
		if ( self::multilingual() && Dicex_Connect_Club_Sources::has_woocommerce() ) {
			$orders = (int) $user_id > 0
				? Dicex_Connect_Club_Store::user_order_ids( (int) $user_id, 5 )
				: Dicex_Connect_Club_Store::guest_order_ids( (string) $mobile, 5 );

			foreach ( $orders as $order_id ) {
				$locale = self::for_order( wc_get_order( $order_id ) );

				if ( '' !== $locale ) {
					return $locale;
				}
			}
		}

		return Dicex_Connect_Club_Settings::clean_locale( get_locale() );
	}

	/**
	 * Whether anything on this site offers a second language: a multilingual plugin,
	 * or more than one translation installed for people to choose in their profile.
	 *
	 * @return bool
	 */
	public static function multilingual() {
		if ( null === self::$multilingual ) {
			$wpml = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, read by the name WPML documents; this plugin is asking it a question, not inventing a hook.

			self::$multilingual = ( is_array( $wpml ) && count( $wpml ) > 1 )
				|| function_exists( 'pll_languages_list' )
				|| defined( 'TRP_PLUGIN_VERSION' )
				|| count( (array) get_available_languages() ) > 1;
		}

		return self::$multilingual;
	}

	/**
	 * WPML names languages by code, such as fa; the rest of WordPress wants a
	 * locale. Its own list maps one to the other.
	 *
	 * @param mixed $code
	 * @return string
	 */
	private static function locale_from_code( $code ) {
		$code = is_scalar( $code ) ? trim( (string) $code ) : '';

		if ( '' === $code ) {
			return '';
		}

		// Already a locale, as TranslatePress and WordPress write them.
		if ( false !== strpos( $code, '_' ) ) {
			return Dicex_Connect_Club_Settings::clean_locale( $code );
		}

		$languages = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, read by the name WPML documents; this plugin is asking it a question, not inventing a hook.

		if ( is_array( $languages ) && isset( $languages[ $code ]['default_locale'] ) ) {
			return Dicex_Connect_Club_Settings::clean_locale( $languages[ $code ]['default_locale'] );
		}

		return '';
	}

	/**
	 * The languages this site can be read in, for the message editors.
	 *
	 * @return array locale => name to show.
	 */
	public static function available() {
		$locales = array( Dicex_Connect_Club_Settings::clean_locale( get_locale() ) );

		foreach ( (array) get_available_languages() as $locale ) {
			$locales[] = Dicex_Connect_Club_Settings::clean_locale( $locale );
		}

		$wpml = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, read by the name WPML documents; this plugin is asking it a question, not inventing a hook.

		if ( is_array( $wpml ) ) {
			foreach ( $wpml as $language ) {
				if ( isset( $language['default_locale'] ) ) {
					$locales[] = Dicex_Connect_Club_Settings::clean_locale( $language['default_locale'] );
				}
			}
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list( array( 'fields' => 'locale' ) ) as $locale ) {
				$locales[] = Dicex_Connect_Club_Settings::clean_locale( $locale );
			}
		}

		// The names WordPress already downloaded with its language list, if it has.
		$known = get_site_transient( 'available_translations' );
		$names = array();

		foreach ( array_unique( array_filter( $locales ) ) as $locale ) {
			$names[ $locale ] = ( is_array( $known ) && isset( $known[ $locale ]['native_name'] ) )
				? (string) $known[ $locale ]['native_name']
				: $locale;
		}

		if ( ! isset( $names['en_US'] ) ) {
			$names['en_US'] = 'English (United States)';
		}

		return $names;
	}

	/**
	 * Runs something with WordPress switched to a language, and switches back
	 * whatever happens.
	 *
	 * @param string   $locale
	 * @param callable $callback
	 * @return mixed What the callback returned.
	 */
	public static function with_locale( $locale, $callback ) {
		$switched = '' !== (string) $locale && function_exists( 'switch_to_locale' ) && determine_locale() !== $locale && switch_to_locale( $locale );

		try {
			return call_user_func( $callback );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
