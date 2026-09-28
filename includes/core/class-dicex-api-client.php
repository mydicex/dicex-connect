<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin HTTP client for the DiceX API.
 *
 * Endpoint paths, the ApiKey auth header and every request/response schema
 * this plugin touches were read from the live Swagger document at
 * https://gateway.dicex.me/swagger/v1/swagger.json on 2026-09-01. That
 * document is titled after the gateway vendor — the same backend family the
 * predecessor ran on, which is why the request bodies match the old code.
 *
 * Verified there: SMOR_SendRequest (see class-dicex-sender.php),
 * SMOR_SendResponse { code, cost, id, message }, AccountInformationResponse
 * { credit, expireDate, plan }, and the SM_ApiResult<T> envelope
 * { isSuccess, message, value } returned by the account and list endpoints.
 *
 * Confirmed NOT to exist: any Telegram/Bale endpoint, and any endpoint that
 * lists the lines permitted for Otp/Send — Sms/GetLineNumbers takes no
 * parameters and is the SMS-service list, so a line it returns can still be
 * refused by Otp/Send with "You dont have permission for use this line
 * number". See class-dicex-lines.php.
 */
class Dicex_Connect_Api_Client {

	const PATH_ACCOUNT_INFO = 'api/v1/Account/GetInformation';
	const PATH_CHARGE       = 'api/v1/Account/ChargeAccount';
	const PATH_SEND         = 'api/v1/Otp/Send';
	const PATH_SMS_LINES    = 'api/v1/Sms/GetLineNumbers';
	const PATH_PROVIDERS    = 'api/v1/Social/GetProviders';
	const PATH_SOCIAL_LINES = 'api/v1/Social/GetLineNumbers';
	const PATH_SOCIAL_SEND  = 'api/v1/Social/SendMessage';

	/*
	 * The customer club, read off the live Swagger on 2026-09-16 — the day the
	 * singular RegisterCustomer and ChangeCustomerLevel were replaced by these
	 * array forms. Dicex_Connect_Club is the only caller.
	 */
	const PATH_CLUB_LEVELS             = 'api/v1/Club/GetLevels';
	const PATH_CLUB_CUSTOMERS          = 'api/v1/Club/GetCustomers';
	const PATH_CLUB_REGISTER_LEVEL     = 'api/v1/Club/RegisterLevel';
	const PATH_CLUB_REGISTER_CUSTOMERS = 'api/v1/Club/RegisterCustomers';
	const PATH_CLUB_CHANGE_LEVELS      = 'api/v1/Club/ChangeCustomerLevels';

	/**
	 * Ordinary contact groups, which the club's levels are not: a contact is in at
	 * most one level and in any number of these. There is no way to take a contact
	 * out of one — see the dicex-api-contract skill.
	 */
	const PATH_GROUPS       = 'api/v1/Account/GetContactGroupList';

	const PATH_GROUP_CREATE = 'api/v1/Account/CreateContactGroup';

	const PATH_GROUP_ADD    = 'api/v1/Account/AddContactToGroup';

	/** Seconds a request may take, unless the caller needs longer. */
	const DEFAULT_TIMEOUT = 15;

	public static function get_api_key() {
		$options = get_option( 'dicex_connect_options', array() );
		return isset( $options['api_key'] ) ? $options['api_key'] : '';
	}

	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	public static function get( $path, $query = array() ) {
		return self::request( 'GET', $path, null, $query );
	}

	/**
	 * @param string $path
	 * @param array  $body    Encoded as JSON; a list becomes a JSON array.
	 * @param int    $timeout Seconds. A batch of customers needs longer than one message.
	 * @return array|WP_Error
	 */
	public static function post( $path, $body = array(), $timeout = self::DEFAULT_TIMEOUT ) {
		return self::request( 'POST', $path, $body, array(), $timeout );
	}

	/**
	 * What this site says it is, on every request.
	 *
	 * WordPress already sends "WordPress/<version>; <site address>" when a plugin
	 * sets no user agent of its own, so DiceX could always tell which site was
	 * calling. The account owner asked on 2026-09-16 for more than that to go with
	 * it, rather than a separate endpoint for registering sites.
	 *
	 * Environment only: versions, the site's language, the region chosen on the
	 * Connection tab, and the address. No counts and nothing about any customer —
	 * anything like that would need the site owner's explicit consent under
	 * guideline 7 of the plugin directory. The readme lists every part of it.
	 *
	 * @return string
	 */
	public static function user_agent() {
		$environment = array(
			'WordPress/' . get_bloginfo( 'version' ),
			'PHP/' . PHP_VERSION,
		);

		if ( defined( 'WC_VERSION' ) ) {
			$environment[] = 'WooCommerce/' . WC_VERSION;
		}

		$environment[] = 'locale ' . get_locale();
		$environment[] = 'region ' . Dicex_Connect_Region::get();
		$environment[] = is_multisite() ? 'multisite' : 'single site';

		return 'DiceX-Connect/' . DICEX_CONNECT_VERSION . ' (' . implode( '; ', $environment ) . ') ' . get_bloginfo( 'url' );
	}

	private static function request( $method, $path, $body = null, $query = array(), $timeout = self::DEFAULT_TIMEOUT ) {
		$api_key = self::get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'dicex_connect_no_key', __( 'No DiceX API key is set.', 'dicex-connect' ) );
		}

		$url = trailingslashit( DICEX_API_BASE_URL ) . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'timeout'    => max( 1, (int) $timeout ),
			'user-agent' => self::user_agent(),
			'headers'    => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'text/plain, application/json',
				'ApiKey'       => $api_key,
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = ( 'GET' === $method )
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			Dicex_Connect_Logger::log( 'HTTP ERROR (' . $url . '): ' . $error_message );
			return new WP_Error(
				'dicex_connect_http_error',
				sprintf(
					/* translators: 1: API URL, 2: HTTP error message */
					__( 'Could not reach the API. URL: %1$s | Error: %2$s', 'dicex-connect' ),
					$url,
					$error_message
				),
				$response
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ( is_array( $data ) && isset( $data['message'] ) )
				? $data['message']
				: sprintf(
					/* translators: %d: HTTP status code returned by the DiceX API */
					__( 'DiceX server error (code %d)', 'dicex-connect' ),
					$code
				);

			Dicex_Connect_Logger::log( 'API ERROR (' . $path . ') [HTTP ' . $code . ']: ' . $message );
			return new WP_Error( 'dicex_connect_api_error', $message, self::error_data( $data, $code ) );
		}

		/*
		 * The gateway wraps most responses in { isSuccess, message, value } and can
		 * report a business-level rejection while still answering HTTP 200. Treating
		 * every 2xx as success would report a refused message as sent. Envelope shape
		 * confirmed against the live Swagger (SM_ApiResult<T>) on 2026-09-01.
		 */
		if ( is_array( $data ) && array_key_exists( 'isSuccess', $data ) && ! $data['isSuccess'] ) {
			$message = ( isset( $data['message'] ) && '' !== $data['message'] )
				? $data['message']
				: __( 'DiceX refused the request.', 'dicex-connect' );

			Dicex_Connect_Logger::log( 'API REJECTED (' . $path . '): ' . $message );
			return new WP_Error( 'dicex_connect_api_error', $message, self::error_data( $data, $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The decoded body, with the HTTP status beside it.
	 *
	 * A caller sending a batch has to tell a request that will never work — a
	 * 400 naming a bad field — from one worth trying again later, like a 429 from
	 * the rate limit or a 503. The message alone cannot say which. Namespaced so
	 * it cannot collide with a field the gateway returns.
	 *
	 * @param mixed $data Decoded response body.
	 * @param int   $code HTTP status.
	 * @return array
	 */
	private static function error_data( $data, $code ) {
		$data = is_array( $data ) ? $data : array();

		$data['dicex_http_status'] = (int) $code;

		return $data;
	}
}
