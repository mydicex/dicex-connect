<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The DiceX customer club: its levels, and the customers placed in them.
 *
 * Core layer. Every call goes through Dicex_Connect_Api_Client and comes back as
 * data or a WP_Error. Nothing here knows about WordPress users, WooCommerce or a
 * screen — the club module decides who is a customer, this only talks to DiceX.
 *
 * Read off the live Swagger on 2026-09-16, the day RegisterCustomer and
 * ChangeCustomerLevel were replaced by RegisterCustomers and ChangeCustomerLevels,
 * which take arrays. What the model means — one level per contact, a level known
 * only by its name, no delete anywhere by design — is written down in the
 * dicex-api-contract skill.
 */
class Dicex_Connect_Club {

	/**
	 * Seconds one batch may take. The client's usual fifteen is sized for a single
	 * message; a batch writes up to fifty customers in one request.
	 */
	const BATCH_TIMEOUT = 45;

	/**
	 * Every field RegisterCustomers takes, in the Swagger's order.
	 *
	 * All of them go out on every call. The account owner: an update that leaves a
	 * field out is not applied. The published notes say an empty field keeps the
	 * value DiceX already has. Either way the safe request is the whole object.
	 */
	const CUSTOMER_FIELDS = array( 'levelName', 'mobile', 'name', 'family', 'company', 'birthDay', 'anniversaryDay', 'email', 'address', 'description' );

	/**
	 * The levels that already exist in this DiceX account.
	 *
	 * @return array|WP_Error List of array( id, name, description ).
	 */
	public static function get_levels() {
		$response = Dicex_Connect_Api_Client::get( Dicex_Connect_Api_Client::PATH_CLUB_LEVELS );

		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		$found  = ( isset( $response['value'] ) && is_array( $response['value'] ) ) ? $response['value'] : array();
		$levels = array();

		foreach ( $found as $level ) {
			if ( ! is_array( $level ) || ! isset( $level['name'] ) ) {
				continue;
			}

			$levels[] = array(
				'id'          => isset( $level['id'] ) ? (int) $level['id'] : 0,
				'name'        => (string) $level['name'],
				'description' => isset( $level['description'] ) ? (string) $level['description'] : '',
			);
		}

		return $levels;
	}

	/**
	 * How many customers DiceX holds in one level.
	 *
	 * GetCustomers is paged and says how many there are in total, so asking for a
	 * page of one is enough to count without reading anybody's details.
	 *
	 * @param string $level_name
	 * @return int|WP_Error
	 */
	public static function count_customers( $level_name ) {
		$response = Dicex_Connect_Api_Client::get(
			Dicex_Connect_Api_Client::PATH_CLUB_CUSTOMERS,
			array(
				// add_query_arg() adds values exactly as given; a Persian level name
				// has to be encoded before it goes into the address.
				'levelName'  => rawurlencode( (string) $level_name ),
				'pageNumber' => 1,
				'pagingSize' => 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		return isset( $response['totalCount'] ) ? (int) $response['totalCount'] : 0;
	}

	/**
	 * Creates a level, or updates the description of the one with this name.
	 *
	 * A level cannot be renamed: the name is how DiceX finds it.
	 *
	 * @param string $name
	 * @param string $description DiceX refuses an empty one.
	 * @return int|WP_Error The level's id in DiceX.
	 */
	public static function register_level( $name, $description ) {
		$response = Dicex_Connect_Api_Client::post(
			Dicex_Connect_Api_Client::PATH_CLUB_REGISTER_LEVEL,
			array(
				'name'        => (string) $name,
				'description' => (string) $description,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		return isset( $response['value'] ) ? (int) $response['value'] : 0;
	}

	/**
	 * Creates or updates customers, each in the level it names.
	 *
	 * @param array $customers List of field => value, keyed as CUSTOMER_FIELDS.
	 * @return array|WP_Error Contact ids, in the order the customers were given.
	 */
	public static function register_customers( $customers ) {
		$body = array();

		foreach ( (array) $customers as $customer ) {
			$body[] = self::customer_body( $customer );
		}

		if ( empty( $body ) ) {
			return array();
		}

		$response = Dicex_Connect_Api_Client::post( Dicex_Connect_Api_Client::PATH_CLUB_REGISTER_CUSTOMERS, $body, self::BATCH_TIMEOUT );

		return self::id_list( $response );
	}

	/**
	 * Moves customers between levels.
	 *
	 * Every level named has to exist in DiceX already. RegisterCustomers creates a
	 * level it has not seen; this does not.
	 *
	 * @param array $moves List of array( levelName, mobile ).
	 * @return array|WP_Error Contact ids, in the order the moves were given.
	 */
	public static function change_levels( $moves ) {
		$body = array();

		foreach ( (array) $moves as $move ) {
			$body[] = array(
				'levelName' => isset( $move['levelName'] ) ? (string) $move['levelName'] : '',
				'mobile'    => isset( $move['mobile'] ) ? (string) $move['mobile'] : '',
			);
		}

		if ( empty( $body ) ) {
			return array();
		}

		$response = Dicex_Connect_Api_Client::post( Dicex_Connect_Api_Client::PATH_CLUB_CHANGE_LEVELS, $body, self::BATCH_TIMEOUT );

		return self::id_list( $response );
	}

	/* ---- Ordinary contact groups ---------------------------------------- */

	/**
	 * The ordinary contact groups this DiceX account has.
	 *
	 * Club levels are not among them: they are groups flagged as levels, and this
	 * list leaves those out.
	 *
	 * @return array|WP_Error List of array( id, name ).
	 */
	public static function get_groups() {
		$response = Dicex_Connect_Api_Client::get( Dicex_Connect_Api_Client::PATH_GROUPS );

		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		$found  = ( isset( $response['value'] ) && is_array( $response['value'] ) ) ? $response['value'] : array();
		$groups = array();

		foreach ( $found as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['name'] ) ) {
				continue;
			}

			$groups[] = array(
				'id'   => isset( $group['id'] ) ? (int) $group['id'] : 0,
				'name' => (string) $group['name'],
			);
		}

		return $groups;
	}

	/**
	 * Creates an ordinary contact group.
	 *
	 * Neither a line number nor a welcome message is sent: the plugin's own club
	 * messages go out through Dicex_Connect_Sender, so a group never greets
	 * anybody by itself.
	 *
	 * @param string $name
	 * @param string $description
	 * @return int|WP_Error The group's id in DiceX.
	 */
	public static function create_group( $name, $description ) {
		$response = Dicex_Connect_Api_Client::post(
			Dicex_Connect_Api_Client::PATH_GROUP_CREATE,
			array(
				'name'           => (string) $name,
				'description'    => (string) $description,
				'lineNumber'     => null,
				'welcomeMessage' => null,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		return isset( $response['value'] ) ? (int) $response['value'] : 0;
	}

	/**
	 * Puts one customer in one group, with the details DiceX keeps about them.
	 *
	 * One contact per request: the gateway has no array form of this call. Nothing
	 * takes a contact back out again, so a caller only ever adds somebody who
	 * matches now.
	 *
	 * @param int   $group_id
	 * @param array $customer Keyed as CUSTOMER_FIELDS; levelName is not part of this call.
	 * @return int|WP_Error The contact's id in DiceX.
	 */
	public static function add_to_group( $group_id, $customer ) {
		$body = self::customer_body( $customer );

		unset( $body['levelName'] );

		$response = Dicex_Connect_Api_Client::post(
			Dicex_Connect_Api_Client::PATH_GROUP_ADD,
			array_merge(
				array( 'contactGroupId' => (int) $group_id ),
				$body,
				// The plugin sends its own messages; the gateway greets nobody here.
				array( 'sendWelcomMessage' => false )
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		return isset( $response['value'] ) ? (int) $response['value'] : 0;
	}

	/**
	 * One customer as RegisterCustomers takes it: every field present, in order.
	 *
	 * A value this site does not have goes out as null rather than being left out.
	 *
	 * TODO: DiceX API — the published notes say a null keeps what DiceX already
	 * holds. Confirm with a real update that it does not clear the field.
	 *
	 * @param array $customer
	 * @return array
	 */
	public static function customer_body( $customer ) {
		$body = array();

		foreach ( self::CUSTOMER_FIELDS as $field ) {
			$value = isset( $customer[ $field ] ) ? $customer[ $field ] : null;

			$body[ $field ] = ( null === $value || '' === $value ) ? null : (string) $value;
		}

		return $body;
	}

	/**
	 * A calendar date as DiceX should keep it: midnight, universal time.
	 *
	 * The account owner asked for dates to be universal rather than Tehran or
	 * Muscat time. A local midnight converted to UTC lands on the day before, which
	 * is how a birthday greeting arrives a day early.
	 *
	 * @param int $timestamp Any moment on the day; its UTC date is the one used.
	 * @return string Such as 2026-09-16T00:00:00Z.
	 */
	public static function utc_date( $timestamp ) {
		return gmdate( 'Y-m-d\T00:00:00\Z', (int) $timestamp );
	}

	/**
	 * Whether a refusal is about the request, or about the moment it was made.
	 *
	 * A 400 names something wrong with a customer, and sending the same thing
	 * again gets the same answer. No answer at all, the rate limit, a server error
	 * or a key the account no longer accepts are not any one customer's fault:
	 * those stop the whole batch, and it is tried again later, untouched.
	 *
	 * @param WP_Error $error
	 * @return bool
	 */
	public static function is_retryable( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['dicex_http_status'] ) ) ? (int) $data['dicex_http_status'] : 0;

		return 0 === $status || in_array( $status, array( 401, 403, 408, 425, 429 ), true ) || $status >= 500;
	}

	/**
	 * The club's refusals, in the language of the screen.
	 *
	 * DiceX answers these in English. Anything not on this list is passed on as it
	 * came, so an unexpected refusal is still shown rather than swallowed.
	 *
	 * @param WP_Error $error
	 * @return WP_Error
	 */
	public static function translate_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return $error;
		}

		$known = array(
			'request is required!'                         => __( 'DiceX received an empty request.', 'dicex-connect' ),
			'name is required!'                            => __( 'DiceX needs a name for every club level.', 'dicex-connect' ),
			'description is required!'                     => __( 'DiceX needs a description for every club level.', 'dicex-connect' ),
			'mobile is required!'                          => __( 'DiceX needs a mobile number for every customer.', 'dicex-connect' ),
			'levelname is required!'                       => __( 'DiceX needs a club level for every customer.', 'dicex-connect' ),
			'mobile is not a valid iranian mobile number!' => __( 'DiceX did not accept this mobile number.', 'dicex-connect' ),
			'level with this levelname does not exist!'    => __( 'That club level does not exist in DiceX yet.', 'dicex-connect' ),
		);

		$key = strtolower( trim( $error->get_error_message() ) );

		if ( ! isset( $known[ $key ] ) ) {
			return $error;
		}

		return new WP_Error( $error->get_error_code(), $known[ $key ], $error->get_error_data() );
	}

	/**
	 * @param array|WP_Error $response
	 * @return array|WP_Error
	 */
	private static function id_list( $response ) {
		if ( is_wp_error( $response ) ) {
			return self::translate_error( $response );
		}

		$ids = ( isset( $response['value'] ) && is_array( $response['value'] ) ) ? $response['value'] : array();

		return array_map( 'intval', array_values( $ids ) );
	}
}
