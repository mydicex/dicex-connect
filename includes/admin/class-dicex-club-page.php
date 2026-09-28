<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX behind the Customer Club tab.
 *
 * The tab's markup lives in views/tab-club.php. Nothing here echoes: every
 * handler checks the nonce and the capability first, reads and cleans its input,
 * asks the club classes, and answers in JSON.
 */
class Dicex_Connect_Club_Page {

	const MEMBERS_PER_PAGE = 20;

	public function __construct() {
		add_action( 'wp_ajax_dicex_connect_club_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_dicex_connect_club_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_dicex_connect_club_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_dicex_connect_club_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_dicex_connect_club_members', array( $this, 'ajax_members' ) );
		add_action( 'wp_ajax_dicex_connect_club_move', array( $this, 'ajax_move' ) );
		add_action( 'wp_ajax_dicex_connect_club_retry', array( $this, 'ajax_retry' ) );
		add_action( 'wp_ajax_dicex_connect_club_remote', array( $this, 'ajax_remote' ) );
		add_action( 'wp_ajax_dicex_connect_club_lists', array( $this, 'ajax_lists' ) );
		add_action( 'wp_ajax_dicex_connect_club_products', array( $this, 'ajax_products' ) );
		add_action( 'wp_ajax_dicex_connect_club_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_dicex_connect_club_release', array( $this, 'ajax_release' ) );
	}

	/**
	 * Nonce plus capability, first line of every handler — the same guard every
	 * other tab uses.
	 */
	private function guard() {
		check_ajax_referer( 'dicex_connect_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'dicex-connect' ), 403 );
		}
	}

	public function ajax_toggle() {
		$this->guard();

		$enabled  = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$settings = Dicex_Connect_Club_Settings::get();

		if ( $enabled ) {
			if ( ! Dicex_Connect_Api_Client::has_api_key() ) {
				wp_send_json_error( __( 'Connect your DiceX account on the Connection tab before switching the club on.', 'dicex-connect' ) );
			}

			if ( null === Dicex_Connect_Club_Settings::find_level( $settings, $settings['default_level'] ) ) {
				wp_send_json_error( __( 'Save at least one level, and choose the level for everybody else, before switching the club on.', 'dicex-connect' ) );
			}

			if ( '' === $settings['join'] ) {
				wp_send_json_error( __( 'Choose who joins the club, and save, before switching it on.', 'dicex-connect' ) );
			}

			if ( ! Dicex_Connect_Club_Settings::can_leave( $settings ) ) {
				wp_send_json_error( __( 'Choose the level for removed numbers, and save, before switching the club on: customers can leave the club from their account, and that is where they go.', 'dicex-connect' ) );
			}
		}

		$settings['enabled'] = $enabled;
		Dicex_Connect_Club_Settings::save( $settings );

		if ( $enabled ) {
			Dicex_Connect_Club_Store::maybe_install();
			// Anything could have changed while the club was off.
			Dicex_Connect_Club_Store::mark_all_dirty();
			Dicex_Connect_Club_Queue::schedule_daily( $settings['daily_time'] );
			Dicex_Connect_Club_Queue::tick_soon();
		} else {
			Dicex_Connect_Club_Queue::unschedule_all();
		}

		Dicex_Connect_Logger::log( 'CLUB: ' . ( $enabled ? 'enabled' : 'disabled' ) );

		$progress = Dicex_Connect_Club_Sync::progress();

		wp_send_json_success(
			array(
				'enabled'      => $enabled,
				'needs_import' => $enabled && empty( $progress['imported_once'] ),
			)
		);
	}

	public function ajax_save() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$before = Dicex_Connect_Club_Settings::get();

		$levels_json = isset( $_POST['levels'] ) ? wp_unslash( $_POST['levels'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every value in it is cleaned by Dicex_Connect_Club_Settings::clean_levels() below.
		$submitted   = json_decode( is_string( $levels_json ) ? $levels_json : '[]', true );

		$levels = Dicex_Connect_Club_Settings::clean_levels(
			is_array( $submitted ) ? $submitted : array(),
			isset( $_POST['default_level'] ) ? sanitize_key( wp_unslash( $_POST['default_level'] ) ) : '',
			isset( $_POST['removed_level'] ) ? sanitize_key( wp_unslash( $_POST['removed_level'] ) ) : ''
		);

		if ( is_wp_error( $levels ) ) {
			wp_send_json_error( $levels->get_error_message() );
		}

		$groups = Dicex_Connect_Club_Settings::clean_groups( self::submitted_json( 'groups' ) );

		if ( is_wp_error( $groups ) ) {
			wp_send_json_error( $groups->get_error_message() );
		}

		$roles = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key on every value, then allowlisted against the site's roles.
		$roles = array_values( array_intersect( $roles, array_keys( Dicex_Connect_Club_Settings::role_options() ) ) );

		$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key on every value, then allowlisted.
		$fields = array_values( array_intersect( $fields, Dicex_Connect_Club_Settings::OPTIONAL_FIELDS ) );

		$window = isset( $_POST['window_months'] ) ? absint( wp_unslash( $_POST['window_months'] ) ) : 0;
		$window = in_array( $window, Dicex_Connect_Club_Settings::WINDOWS, true ) ? $window : 0;

		$mode = isset( $_POST['sync_mode'] ) ? sanitize_key( wp_unslash( $_POST['sync_mode'] ) ) : 'both';
		$mode = in_array( $mode, Dicex_Connect_Club_Settings::SYNC_MODES, true ) ? $mode : 'both';

		// The order the list came back in is the order the channels are tried.
		$channels = isset( $_POST['welcome_channels'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['welcome_channels'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key on every value, then allowlisted.
		$channels = array_values( array_unique( array_intersect( $channels, Dicex_Connect_Lines::SUPPORTED_CHANNELS ) ) );

		$welcome_enabled = isset( $_POST['welcome_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['welcome_enabled'] ) );

		if ( $welcome_enabled && empty( $channels ) ) {
			wp_send_json_error( __( 'Choose at least one channel for the welcome message.', 'dicex-connect' ) );
		}

		$join = isset( $_POST['join'] ) ? sanitize_key( wp_unslash( $_POST['join'] ) ) : '';
		$join = in_array( $join, Dicex_Connect_Club_Settings::JOIN_MODES, true ) ? $join : '';

		// A club that is on keeps working the way it was set up until somebody picks otherwise.
		if ( '' === $join && ! empty( $before['enabled'] ) ) {
			wp_send_json_error( __( 'Choose who joins the club.', 'dicex-connect' ) );
		}

		if ( ! empty( $before['enabled'] ) && '' === $levels['removed_level'] ) {
			wp_send_json_error( __( 'Choose the level for removed numbers: customers can leave the club from their account, and that is where they go.', 'dicex-connect' ) );
		}

		$birthday = Dicex_Connect_Club_Settings::clean_birthday(
			array(
				'enabled'  => isset( $_POST['birthday_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['birthday_enabled'] ) ),
				'required' => isset( $_POST['birthday_required'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['birthday_required'] ) ),
				'calendar' => isset( $_POST['birthday_calendar'] ) ? sanitize_key( wp_unslash( $_POST['birthday_calendar'] ) ) : 'auto',
				'meta_key' => isset( $_POST['birthday_meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['birthday_meta_key'] ) ) : '',
			)
		);

		$consent_label = isset( $_POST['consent_label'] ) ? sanitize_text_field( wp_unslash( $_POST['consent_label'] ) ) : '';

		$settings = array_merge(
			$before,
			$levels,
			array(
				'join'          => $join,
				'consent_label' => function_exists( 'mb_substr' ) ? mb_substr( $consent_label, 0, 250, 'UTF-8' ) : substr( $consent_label, 0, 250 ),
				'birthday'      => $birthday,
				'roles'         => $roles,
				'guests'        => isset( $_POST['guests'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['guests'] ) ),
				'window_months' => $window,
				'fields'        => $fields,
				'sync_mode'     => $mode,
				'daily_time'    => Dicex_Connect_Club_Settings::clean_time( isset( $_POST['daily_time'] ) ? sanitize_text_field( wp_unslash( $_POST['daily_time'] ) ) : '' ),
				'groups'        => $groups,
				'welcome'       => array(
					'enabled'   => $welcome_enabled,
					'channels'  => empty( $channels ) ? array( 'sms' ) : $channels,
					'template'  => isset( $_POST['welcome_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['welcome_template'] ) ) : '',
					// As with a level's messages: a site with one language never shows
					// the other texts, and saving there leaves them as they are.
					'templates' => isset( $_POST['welcome_templates'] )
						? Dicex_Connect_Club_Settings::clean_messages( self::submitted_json( 'welcome_templates' ) )
						: $before['welcome']['templates'],
				),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Dicex_Connect_Club_Settings::save( $settings );

		// Who joins changes nobody already in: it decides who gets in from now on.
		$decides = array( 'levels', 'default_level', 'removed_level', 'roles', 'guests', 'window_months', 'fields', 'birthday', 'groups' );
		$changed = false;

		foreach ( $decides as $key ) {
			if ( $before[ $key ] !== $settings[ $key ] ) {
				$changed = true;
				break;
			}
		}

		if ( Dicex_Connect_Club_Store::exists() ) {
			if ( $changed ) {
				Dicex_Connect_Club_Store::mark_all_dirty();
			}

			// A deleted group stops being counted here. DiceX keeps its own group
			// and everybody already in it — it has no way to take them out.
			if ( $before['groups'] !== $settings['groups'] ) {
				Dicex_Connect_Club_Store::forget_missing_groups( wp_list_pluck( $settings['groups'], 'id' ) );
			}
		}

		if ( ! empty( $settings['enabled'] ) ) {
			if ( $before['daily_time'] !== $settings['daily_time'] || $before['sync_mode'] !== $settings['sync_mode'] ) {
				Dicex_Connect_Club_Queue::schedule_daily( $settings['daily_time'] );
			}

			if ( $changed ) {
				Dicex_Connect_Club_Queue::tick_soon();
			}
		}

		Dicex_Connect_Logger::log( 'CLUB SETTINGS: ' . count( $settings['levels'] ) . ' levels, mode ' . $settings['sync_mode'] );

		wp_send_json_success(
			array(
				'levels'        => $settings['levels'],
				'groups'        => $settings['groups'],
				'default_level' => $settings['default_level'],
				'removed_level' => $settings['removed_level'],
				// New roles or guests only reach people already in the club; anybody
				// else arrives with an import.
				'suggest_import' => ! empty( $settings['enabled'] ) && ( $before['roles'] !== $settings['roles'] || $before['guests'] !== $settings['guests'] ),
				'message'        => __( 'Club settings saved.', 'dicex-connect' ),
			)
		);
	}

	/**
	 * A field the screen posts as JSON, decoded but not yet cleaned.
	 *
	 * Every value in it goes through the club's own cleaners before it is stored,
	 * which is why nothing is sanitized here.
	 *
	 * @param string $key
	 * @return array
	 */
	private static function submitted_json( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller is an AJAX handler whose first statement is $this->guard().
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; the club's own cleaners sanitize every value out of it.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$value = json_decode( is_string( $raw ) ? $raw : '', true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Where the club stands. With run=1, also does a few seconds of work first:
	 * while this tab is open it keeps an import moving even on a site whose
	 * scheduled tasks never fire.
	 */
	public function ajax_status() {
		$this->guard();

		$run = isset( $_POST['run'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['run'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( $run && Dicex_Connect_Club_Settings::is_enabled() ) {
			$settings = Dicex_Connect_Club_Settings::get();

			// Cheap to check while somebody is looking, and it puts back a daily run
			// that was lost — WooCommerce switched off after it was booked, say.
			Dicex_Connect_Club_Queue::ensure_daily( $settings['daily_time'] );
			Dicex_Connect_Club_Sync::schedule_follow_up( Dicex_Connect_Club_Sync::tick( 4 ) );
		}

		wp_send_json_success( self::status_data() );
	}

	public function ajax_import() {
		$this->guard();

		if ( ! Dicex_Connect_Club_Settings::is_enabled() ) {
			wp_send_json_error( __( 'Switch the club on first.', 'dicex-connect' ) );
		}

		Dicex_Connect_Club_Store::maybe_install();
		Dicex_Connect_Club_Sync::start_import( 'full' );

		Dicex_Connect_Logger::log( 'CLUB IMPORT: started' );

		wp_send_json_success( self::status_data() );
	}

	/**
	 * Counts where the club's members would land under the levels on the screen,
	 * before anything is saved. A page of members per request: the screen asks
	 * again from `next` until the answer says done. Nothing is written or sent.
	 */
	public function ajax_preview() {
		$this->guard();

		if ( ! Dicex_Connect_Club_Store::exists() || 0 === Dicex_Connect_Club_Store::member_count() ) {
			wp_send_json_error( __( 'There is nobody to count yet. Import your existing customers first; nothing is sent to DiceX until you confirm.', 'dicex-connect' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$levels_json = isset( $_POST['levels'] ) ? wp_unslash( $_POST['levels'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every value in it is cleaned by Dicex_Connect_Club_Settings::clean_levels() below.
		$submitted   = json_decode( is_string( $levels_json ) ? $levels_json : '[]', true );

		$levels = Dicex_Connect_Club_Settings::clean_levels(
			is_array( $submitted ) ? $submitted : array(),
			isset( $_POST['default_level'] ) ? sanitize_key( wp_unslash( $_POST['default_level'] ) ) : '',
			isset( $_POST['removed_level'] ) ? sanitize_key( wp_unslash( $_POST['removed_level'] ) ) : ''
		);

		$window = isset( $_POST['window_months'] ) ? absint( wp_unslash( $_POST['window_months'] ) ) : 0;
		$after  = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $levels ) ) {
			wp_send_json_error( $levels->get_error_message() );
		}

		$groups = Dicex_Connect_Club_Settings::clean_groups( self::submitted_json( 'groups' ) );

		if ( is_wp_error( $groups ) ) {
			wp_send_json_error( $groups->get_error_message() );
		}

		$settings = array_merge(
			Dicex_Connect_Club_Settings::get(),
			$levels,
			array(
				'groups'        => $groups,
				'window_months' => in_array( $window, Dicex_Connect_Club_Settings::WINDOWS, true ) ? $window : 0,
			)
		);

		$page = Dicex_Connect_Club_Sync::preview_page( $settings, $after );

		if ( 0 === $after ) {
			$page['total'] = Dicex_Connect_Club_Store::member_count();
		}

		wp_send_json_success( $page );
	}

	/**
	 * The owner has seen who the first import put where: send it.
	 */
	public function ajax_release() {
		$this->guard();

		Dicex_Connect_Club_Sync::release_hold();

		wp_send_json_success( self::status_data() );
	}

	public function ajax_retry() {
		$this->guard();

		$exists = Dicex_Connect_Club_Store::exists();
		$count  = $exists ? Dicex_Connect_Club_Store::retry_errors() : 0;
		$groups = $exists ? Dicex_Connect_Club_Store::retry_group_errors() : 0;

		Dicex_Connect_Club_Queue::tick_soon();

		$message = sprintf(
			/* translators: %s: how many customers will be sent again */
			_n( '%s customer will be sent again.', '%s customers will be sent again.', $count, 'dicex-connect' ),
			number_format_i18n( $count )
		);

		if ( $groups > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: how many group memberships will be sent again */
				_n( '%s group membership will be sent again.', '%s group memberships will be sent again.', $groups, 'dicex-connect' ),
				number_format_i18n( $groups )
			);
		}

		wp_send_json_success( array_merge( self::status_data(), array( 'message' => $message ) ) );
	}

	public function ajax_members() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$page   = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$level  = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! array_key_exists( $status, self::status_labels() ) ) {
			$status = '';
		}

		if ( ! Dicex_Connect_Club_Store::exists() ) {
			wp_send_json_success(
				array(
					'rows'  => array(),
					'total' => 0,
					'pages' => 0,
					'page'  => 1,
				)
			);
		}

		// A number typed with Persian digits should still find the member.
		$search = strtr( $search, Dicex_Connect_Mobile::DIGIT_MAP );

		$result   = Dicex_Connect_Club_Store::list_members(
			array(
				'search'   => $search,
				'level'    => $level,
				'status'   => $status,
				'page'     => $page,
				'per_page' => self::MEMBERS_PER_PAGE,
			)
		);
		$settings = Dicex_Connect_Club_Settings::get();
		$rows     = array();
		$groups   = empty( $settings['groups'] )
			? array()
			: Dicex_Connect_Club_Store::groups_for_members( wp_list_pluck( $result['rows'], 'id' ) );

		foreach ( $result['rows'] as $member ) {
			$rows[] = self::member_row( $member, $settings, isset( $groups[ (int) $member->id ] ) ? $groups[ (int) $member->id ] : array() );
		}

		wp_send_json_success(
			array(
				'rows'  => $rows,
				'total' => $result['total'],
				'pages' => (int) ceil( $result['total'] / self::MEMBERS_PER_PAGE ),
				'page'  => $page,
			)
		);
	}

	/**
	 * Puts a member in a level by hand, or hands them back to the rules.
	 *
	 * This is how a number is taken out of the club: it goes to the level kept for
	 * removed numbers, and stays there until somebody moves it again.
	 */
	public function ajax_move() {
		$this->guard();

		$member_id = isset( $_POST['member'] ) ? absint( wp_unslash( $_POST['member'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$level_id  = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.
		$member    = Dicex_Connect_Club_Store::exists() ? Dicex_Connect_Club_Store::member( $member_id ) : null;
		$settings  = Dicex_Connect_Club_Settings::get();

		if ( null === $member ) {
			wp_send_json_error( __( 'That member was not found.', 'dicex-connect' ) );
		}

		if ( 'auto' === $level_id ) {
			$changes = array(
				'locked' => 0,
				'dirty'  => 1,
			);
		} elseif ( null !== Dicex_Connect_Club_Settings::find_level( $settings, $level_id ) ) {
			$changes = array(
				'level_id' => $level_id,
				'locked'   => 1,
				'dirty'    => 1,
			);
		} else {
			wp_send_json_error( __( 'That level does not exist.', 'dicex-connect' ) );
		}

		Dicex_Connect_Club_Store::update_member( $member_id, $changes );

		Dicex_Connect_Logger::log( 'CLUB MOVE: ' . Dicex_Connect_Mobile::mask( (string) $member->mobile ) . ' to ' . $level_id );

		// Somebody is looking at this row: work it out and send it now.
		Dicex_Connect_Club_Sync::schedule_follow_up( Dicex_Connect_Club_Sync::tick( 6 ) );

		// The row is drawn again whole, groups included, or it would lose them.
		$groups = empty( $settings['groups'] ) ? array() : Dicex_Connect_Club_Store::groups_for_members( array( $member_id ) );

		wp_send_json_success(
			array(
				'row'    => self::member_row( Dicex_Connect_Club_Store::member( $member_id ), $settings, isset( $groups[ $member_id ] ) ? $groups[ $member_id ] : array() ),
				'status' => self::status_data(),
			)
		);
	}

	/**
	 * What a level's lists can be chosen from: the store's categories and brands,
	 * WooCommerce's countries, and the names of the products levels already use.
	 *
	 * Asked for by the tab when it opens, rather than printed into every screen
	 * of the plugin: a store can have a great many categories.
	 */
	public function ajax_lists() {
		$this->guard();

		if ( ! Dicex_Connect_Club_Sources::has_woocommerce() ) {
			wp_send_json_success(
				array(
					'categories' => array(),
					'brands'     => array(),
					'countries'  => array(),
					'products'   => array(),
				)
			);
		}

		$countries = array();

		foreach ( WC()->countries->get_countries() as $code => $name ) {
			$countries[] = array(
				'id'   => (string) $code,
				'name' => html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		$products = array();

		foreach ( Dicex_Connect_Club_Settings::get()['levels'] as $level ) {
			foreach ( (array) $level['products'] as $product_id ) {
				$product = wc_get_product( (int) $product_id );

				if ( $product ) {
					$products[ (int) $product_id ] = self::product_label( $product );
				}
			}
		}

		wp_send_json_success(
			array(
				'categories' => self::term_choices( 'product_cat' ),
				'brands'     => self::term_choices( 'product_brand' ),
				'countries'  => $countries,
				'products'   => (object) $products,
			)
		);
	}

	/**
	 * Products matching what was typed, for a level's product list.
	 *
	 * WooCommerce's own product search (the data store behind its admin search
	 * fields), across every status: a product no longer on sale still has buyers.
	 */
	public function ajax_products() {
		$this->guard();

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified by $this->guard(), the first statement of this handler.

		if ( '' === trim( $term ) || ! Dicex_Connect_Club_Sources::has_woocommerce() || ! class_exists( 'WC_Data_Store' ) ) {
			wp_send_json_success( array() );
		}

		try {
			$ids = WC_Data_Store::load( 'product' )->search_products( $term, '', false, true, 20 );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}

		$found = array();

		foreach ( array_filter( array_map( 'absint', (array) $ids ) ) as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product && 'variation' !== $product->get_type() ) {
				$found[] = array(
					'id'   => $product_id,
					'name' => self::product_label( $product ),
				);
			}
		}

		wp_send_json_success( $found );
	}

	/**
	 * @param WC_Product $product
	 * @return string The product's name, with its SKU when it has one.
	 */
	private static function product_label( $product ) {
		$name = html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' );
		$sku  = (string) $product->get_sku();

		return '' !== $sku ? $name . ' (' . $sku . ')' : $name;
	}

	/**
	 * A taxonomy's terms in tree order, each with its depth.
	 *
	 * @param string $taxonomy
	 * @return array List of array( id, name, depth ).
	 */
	private static function term_choices( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$children = array();

		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}

		$ordered = array();
		$walk    = function ( $parent, $depth ) use ( &$walk, &$ordered, $children ) {
			if ( empty( $children[ $parent ] ) || $depth > 20 ) {
				return;
			}

			foreach ( $children[ $parent ] as $term ) {
				$ordered[] = array(
					'id'    => (int) $term->term_id,
					'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'depth' => $depth,
				);

				$walk( (int) $term->term_id, $depth + 1 );
			}
		};

		$walk( 0, 0 );

		return $ordered;
	}

	/**
	 * The levels already in the DiceX account, and how many customers each holds
	 * there — a way to check the club against what this site sent.
	 */
	public function ajax_remote() {
		$this->guard();

		$levels = Dicex_Connect_Club::get_levels();

		if ( is_wp_error( $levels ) ) {
			wp_send_json_error( $levels->get_error_message() );
		}

		$rows = array();

		// Counting is one request per level; the club allows ten a second.
		foreach ( array_slice( $levels, 0, 20 ) as $level ) {
			$count  = Dicex_Connect_Club::count_customers( $level['name'] );
			$rows[] = array(
				'name'  => $level['name'],
				'count' => is_wp_error( $count ) ? '—' : number_format_i18n( $count ),
			);

			usleep( 120000 );
		}

		wp_send_json_success(
			array(
				'levels' => $rows,
				'more'   => count( $levels ) > 20,
			)
		);
	}

	/* ---- Shared ---------------------------------------------------------- */

	/**
	 * @return array Status => label.
	 */
	public static function status_labels() {
		return array(
			''        => __( 'Any status', 'dicex-connect' ),
			'new'     => __( 'Being worked out', 'dicex-connect' ),
			'pending' => __( 'Waiting to be sent', 'dicex-connect' ),
			'sending' => __( 'Sending', 'dicex-connect' ),
			'synced'  => __( 'In DiceX', 'dicex-connect' ),
			'error'   => __( 'Refused by DiceX', 'dicex-connect' ),
		);
	}

	/**
	 * Everything the status area of the tab shows.
	 *
	 * @return array
	 */
	public static function status_data() {
		$progress = Dicex_Connect_Club_Sync::progress();
		$settings = Dicex_Connect_Club_Settings::get();
		$counts   = Dicex_Connect_Club_Store::exists() ? Dicex_Connect_Club_Store::counts_by_level() : array();
		$statuses = $progress['statuses'];
		$total    = array_sum( $statuses );
		$import   = $progress['import'];
		$levels   = array();
		$groups   = array();
		$in_group = Dicex_Connect_Club_Store::exists() ? Dicex_Connect_Club_Store::group_counts() : array();

		foreach ( $settings['levels'] as $level ) {
			$levels[] = array(
				'id'      => $level['id'],
				'name'    => $level['name'],
				'members' => number_format_i18n( isset( $counts[ $level['id'] ] ) ? $counts[ $level['id'] ] : 0 ),
			);
		}

		foreach ( $settings['groups'] as $group ) {
			$count = isset( $in_group[ $group['id'] ] )
				? $in_group[ $group['id'] ]
				: array(
					'in'      => 0,
					'waiting' => 0,
					'stale'   => 0,
				);

			$groups[] = array(
				'id'      => $group['id'],
				'name'    => $group['name'],
				'members' => number_format_i18n( (int) $count['in'] ),
				'waiting' => (int) $count['waiting'],
				// Sent to DiceX once and no longer matching. DiceX cannot take them out.
				'stale'   => (int) $count['stale'],
			);
		}

		$import_total = (int) $import['orders_total'] + (int) $import['users_total'];
		$import_done  = (int) $import['orders_done'] + (int) $import['users_done'];

		$next_daily = Dicex_Connect_Club_Settings::is_enabled() && 'realtime' !== $settings['sync_mode'] ? Dicex_Connect_Club_Queue::next_daily() : false;

		return array(
			'enabled'       => ! empty( $settings['enabled'] ),
			'more'          => ! empty( $progress['more'] ),
			'total'         => number_format_i18n( $total ),
			'member_count'  => (int) $total,
			'synced'        => number_format_i18n( isset( $statuses['synced'] ) ? $statuses['synced'] : 0 ),
			'waiting'       => number_format_i18n( $progress['waiting'] + $progress['dirty'] ),
			'refused'       => number_format_i18n( isset( $statuses['error'] ) ? $statuses['error'] : 0 ),
			'refused_count' => isset( $statuses['error'] ) ? (int) $statuses['error'] : 0,
			'levels'        => $levels,
			'groups'        => $groups,
			'group_waiting' => number_format_i18n( isset( $progress['groups'] ) ? (int) $progress['groups'] : 0 ),
			'importing'     => ! empty( $import['running'] ),
			'hold'          => ! empty( $progress['hold'] ),
			'import_mode'   => $import['mode'],
			'import_done'   => $import_done,
			'import_total'  => max( $import_total, $import_done ),
			'import_text'   => self::import_text( $import ),
			'imported_once' => $progress['imported_once'],
			'can_send'      => $progress['can_send'],
			'last_sent'     => $progress['last_sent'] > 0
				? sprintf(
					/* translators: %s: a length of time, such as "5 minutes" */
					__( '%s ago', 'dicex-connect' ),
					human_time_diff( $progress['last_sent'], time() )
				)
				: __( 'Not yet', 'dicex-connect' ),
			'paused'        => $progress['backoff_until'] > time()
				? sprintf(
					/* translators: 1: the reason DiceX gave, 2: a length of time, such as "2 minutes" */
					__( 'Sending is paused: %1$s. It will try again in %2$s.', 'dicex-connect' ),
					$progress['last_error'],
					human_time_diff( time(), $progress['backoff_until'] )
				)
				: '',
			'next_daily'    => $next_daily
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_daily )
				: '',
		);
	}

	/**
	 * @param array $import
	 * @return string
	 */
	private static function import_text( $import ) {
		if ( ! empty( $import['running'] ) ) {
			$read = (int) $import['orders_done'] + (int) $import['users_done'];

			return 'orders' === $import['stage']
				? sprintf(
					/* translators: 1: orders read so far, 2: all orders */
					__( 'Reading orders: %1$s of %2$s.', 'dicex-connect' ),
					number_format_i18n( (int) $import['orders_done'] ),
					number_format_i18n( max( (int) $import['orders_total'], (int) $import['orders_done'] ) )
				)
				: sprintf(
					/* translators: %s: how many orders and users have been read */
					__( 'Reading users: %s read so far.', 'dicex-connect' ),
					number_format_i18n( $read )
				);
		}

		if ( ! empty( $import['finished'] ) && 'full' === $import['mode'] && (int) $import['not_joined'] > 0 ) {
			return sprintf(
				/* translators: 1: a date and time, 2: orders read, 3: users read, 4: how many had no usable number, 5: how many had not ticked the club box */
				__( 'Last import %1$s: %2$s orders and %3$s users read, %4$s without a usable mobile number, %5$s who have not joined the club.', 'dicex-connect' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $import['finished'] ),
				number_format_i18n( (int) $import['orders_done'] ),
				number_format_i18n( (int) $import['users_done'] ),
				number_format_i18n( (int) $import['skipped'] ),
				number_format_i18n( (int) $import['not_joined'] )
			);
		}

		if ( ! empty( $import['finished'] ) && 'full' === $import['mode'] ) {
			return sprintf(
				/* translators: 1: a date and time, 2: orders read, 3: users read, 4: how many could not be added */
				__( 'Last import %1$s: %2$s orders and %3$s users read, %4$s without a usable mobile number.', 'dicex-connect' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $import['finished'] ),
				number_format_i18n( (int) $import['orders_done'] ),
				number_format_i18n( (int) $import['users_done'] ),
				number_format_i18n( (int) $import['skipped'] )
			);
		}

		return '';
	}

	/**
	 * One member as the list shows it.
	 *
	 * @param object $member
	 * @param array  $settings
	 * @param array  $groups   This member's groups: group id => status.
	 * @return array
	 */
	private static function member_row( $member, $settings, $groups = array() ) {
		$labels = self::status_labels();
		$status = (string) $member->status;
		$spent  = (float) $member->net_spent;

		if ( function_exists( 'wc_price' ) ) {
			$spent_text = trim( html_entity_decode( wp_strip_all_tags( wc_price( $spent ) ), ENT_QUOTES, 'UTF-8' ) );
		} else {
			$spent_text = number_format_i18n( $spent );
		}

		$country = (string) $member->country;

		if ( '' !== $country && function_exists( 'WC' ) && isset( WC()->countries ) ) {
			$names   = WC()->countries->get_countries();
			$country = isset( $names[ $country ] ) ? html_entity_decode( wp_strip_all_tags( (string) $names[ $country ] ), ENT_QUOTES, 'UTF-8' ) : $country;
		}

		$chips = array();

		foreach ( $groups as $key => $state ) {
			$group = Dicex_Connect_Club_Settings::find_group( $settings, $key );

			if ( null === $group ) {
				continue;
			}

			$chips[] = array(
				'name'  => $group['name'],
				// in: DiceX has them. waiting: on its way. left: no longer matching,
				// and DiceX has no way to take them out of the group.
				'state' => 'synced' === $state ? 'in' : ( 'left' === $state ? 'left' : 'waiting' ),
			);
		}

		return array(
			'id'        => (int) $member->id,
			'mobile'    => (string) $member->mobile,
			'groups'    => $chips,
			'name'      => (string) $member->display_name,
			'user_link' => (int) $member->user_id > 0 ? (string) get_edit_user_link( (int) $member->user_id ) : '',
			'level_id'  => (string) $member->level_id,
			'level'     => Dicex_Connect_Club_Settings::level_name( $settings, (string) $member->level_id ),
			'locked'    => 1 === (int) $member->locked,
			'orders'    => number_format_i18n( (int) $member->order_count ),
			'spent'     => $spent_text,
			'city'      => (string) $member->city,
			'country'   => $country,
			'status'    => $status,
			'status_label' => isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
			'error'     => 'error' === $status ? (string) $member->last_error : '',
			'synced'    => empty( $member->synced_gmt ) ? '' : get_date_from_gmt( (string) $member->synced_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
	}
}
