<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the confirmed GET /api/v1/Account/GetInformation endpoint.
 * Exact response field names (credit, expireDate, ...) are inherited from
 * the old code's assumptions for the same backend family and are not yet
 * independently verified — see class-dicex-api-client.php.
 */
class Dicex_Connect_Account {

	const CACHE_KEY = 'dicex_connect_account_info';
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	public static function get_info( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = Dicex_Connect_Api_Client::get( Dicex_Connect_Api_Client::PATH_ACCOUNT_INFO );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['value'] ) && is_array( $response['value'] ) ) {
			$info = $response['value'];
		} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			$info = $response['data'];
		} else {
			$info = $response;
		}
		set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );

		return $info;
	}

	/**
	 * Save a new API key and immediately try to verify it against the account-info endpoint.
	 *
	 * @param string $api_key
	 * @return array|WP_Error Account info on success, WP_Error on failure.
	 */
	public static function verify_key( $api_key ) {
		$options            = get_option( 'dicex_connect_options', array() );
		$options['api_key'] = sanitize_text_field( $api_key );
		update_option( 'dicex_connect_options', $options, false );

		delete_transient( self::CACHE_KEY );
		$info = self::get_info( true );

		update_option( 'dicex_connect_api_verified', is_wp_error( $info ) ? 'no' : 'yes', false );

		return $info;
	}

	public static function is_verified() {
		return 'yes' === get_option( 'dicex_connect_api_verified' );
	}

	/**
	 * Formats a credit balance for display.
	 *
	 * This used to hard-code Persian digits and the word for rials, which meant
	 * the amount ignored the site's language entirely. number_format_i18n() uses
	 * whatever separators the active locale defines, and the currency word is a
	 * translatable string like every other.
	 *
	 * @param mixed $credit
	 * @return string
	 */
	public static function format_credit( $credit ) {
		if ( null === $credit || '' === $credit || ! is_numeric( $credit ) ) {
			return '—';
		}

		$amount   = number_format_i18n( (float) $credit );
		$currency = Dicex_Connect_Region::currency();

		if ( '' === $currency ) {
			return $amount;
		}

		return sprintf(
			/* translators: 1: an amount of money, already formatted for the locale. 2: currency such as IRR */
			__( '%1$s %2$s', 'dicex-connect' ),
			$amount,
			$currency
		);
	}

	public static function get_credit( $info ) {
		if ( isset( $info['credit'] ) ) {
			$credit = self::normalize_credit( $info['credit'] );
			if ( null !== $credit ) {
				return $credit;
			}
		}

		$direct_credit = self::normalize_credit( $info );
		if ( null !== $direct_credit ) {
			return $direct_credit;
		}

		if ( ! is_array( $info ) ) {
			return null;
		}

		$zero_credit = null;
		foreach ( $info as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( preg_match( '/(credit|balance|charge|wallet|remaining)/', $key ) ) {
				$credit = self::normalize_credit( $value );
				if ( null !== $credit ) {
					if ( 0 != $credit ) {
						return $credit;
					}
					$zero_credit = $credit;
				}
			}
			if ( is_array( $value ) ) {
				$credit = self::get_credit( $value );
				if ( null !== $credit && 0 != $credit ) {
					return $credit;
				}
				if ( null !== $credit ) {
					$zero_credit = $credit;
				}
			}
		}

		return $zero_credit;
	}

	private static function normalize_credit( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		// One digit map for the whole plugin, so the two never drift apart.
		$value = strtr( trim( $value ), Dicex_Connect_Mobile::DIGIT_MAP );
		$value = str_replace( array( ',', '٬', ' ' ), '', $value );

		return is_numeric( $value ) ? $value : null;
	}
}
