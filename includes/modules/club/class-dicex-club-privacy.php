<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The customer club and WordPress's privacy tools.
 *
 * Tools → Export Personal Data lists what the club holds about a person. Tools →
 * Erase Personal Data takes them out of the club — which, in DiceX, means moving
 * their number to the level kept for removed numbers: DiceX deletes nothing, by
 * design, and the admin is told so in plain words rather than shown a success.
 *
 * A person is found by their email address, which is how those tools ask: their
 * WordPress account, and any WooCommerce order placed under that address.
 */
class Dicex_Connect_Club_Privacy {

	const PER_PAGE = 50;

	/**
	 * @param array $exporters
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['dicex-connect-club'] = array(
			'exporter_friendly_name' => __( 'DiceX customer club', 'dicex-connect' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array $erasers
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['dicex-connect-club'] = array(
			'eraser_friendly_name' => __( 'DiceX customer club', 'dicex-connect' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Suggested wording for the site's privacy policy.
	 */
	public static function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'DiceX Connect sends customer details to DiceX when the customer club is switched on. Adjust this text to what you actually send, and to whether customers join the club by ticking a box.', 'dicex-connect' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you buy from us or create an account, your mobile number, your name and the date of your first purchase are added to our customer club at DiceX (dicex.me), which we use to stay in touch with you. If you give us your date of birth, it is added too. Depending on our settings your email address, company and billing address may be added as well. The club records which membership level you are in, based on your purchases. You can leave the club at any time from your account page, or ask us to take you out of it.', 'dicex-connect' ) . '</p>';

		wp_add_privacy_policy_content( __( 'DiceX Connect', 'dicex-connect' ), wp_kses_post( $text ) );
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$members  = self::members_for( $email );
		$settings = Dicex_Connect_Club_Settings::get();
		$items    = array();

		// What the person told the club about themselves, once, on the first page.
		if ( 1 === max( 1, (int) $page ) ) {
			$details = self::personal_details( $email );

			if ( ! empty( $details ) ) {
				$items[] = array(
					'group_id'    => 'dicex-connect-club',
					'group_label' => __( 'Customer club', 'dicex-connect' ),
					'item_id'     => 'dicex-connect-club-details',
					'data'        => $details,
				);
			}
		}

		foreach ( array_slice( $members, ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $member ) {
			$data = array(
				array(
					'name'  => __( 'Mobile number', 'dicex-connect' ),
					'value' => (string) $member->mobile,
				),
				array(
					'name'  => __( 'Club level', 'dicex-connect' ),
					'value' => Dicex_Connect_Club_Settings::level_name( $settings, (string) $member->level_id ),
				),
				array(
					'name'  => __( 'Orders counted', 'dicex-connect' ),
					'value' => (string) (int) $member->order_count,
				),
			);

			if ( Dicex_Connect_Club_Sources::has_woocommerce() && function_exists( 'wc_get_price_decimals' ) ) {
				$data[] = array(
					'name'  => __( 'Amount counted', 'dicex-connect' ),
					'value' => wc_format_decimal( (string) $member->net_spent, wc_get_price_decimals() ) . ' ' . Dicex_Connect_Club_Sources::store_currency(),
				);
			}

			if ( ! empty( $member->last_order_gmt ) ) {
				$data[] = array(
					'name'  => __( 'Last purchase', 'dicex-connect' ),
					'value' => get_date_from_gmt( (string) $member->last_order_gmt, 'Y-m-d' ),
				);
			}

			$data[] = array(
				'name'  => __( 'Location', 'dicex-connect' ),
				'value' => trim( (string) $member->city . ' ' . (string) $member->country ),
			);

			// Exactly what DiceX was last given, field by field.
			$data = array_merge( $data, self::sent_details( (string) $member->payload ) );

			$data[] = array(
				'name'  => __( 'Last sent to DiceX', 'dicex-connect' ),
				'value' => empty( $member->synced_gmt ) ? '' : get_date_from_gmt( (string) $member->synced_gmt ),
			);
			$data[] = array(
				'name'  => __( 'Contact groups', 'dicex-connect' ),
				'value' => self::group_names( $settings, (int) $member->id ),
			);

			$items[] = array(
				'group_id'    => 'dicex-connect-club',
				'group_label' => __( 'Customer club', 'dicex-connect' ),
				'item_id'     => 'dicex-connect-club-' . (int) $member->id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $members ) <= max( 1, (int) $page ) * self::PER_PAGE,
		);
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$members  = self::members_for( $email );
		$settings = Dicex_Connect_Club_Settings::get();
		$removed  = Dicex_Connect_Club_Settings::find_level( $settings, $settings['removed_level'] );
		$messages = array();

		// A date of birth is deleted outright, member or not.
		$changed = self::erase_personal_details( $email );

		// So are the phone and the place on the club's copy of their orders.
		if ( Dicex_Connect_Club_Store::exists() ) {
			$order_ids = array();

			foreach ( self::orders_for( $email ) as $order ) {
				$order_ids[] = (int) $order->get_id();
			}

			$changed = Dicex_Connect_Club_Store::forget_order_contacts( $order_ids ) > 0 || $changed;
		}

		if ( empty( $members ) ) {
			return array(
				'items_removed'  => $changed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		/*
		 * The club's own copies go whatever else happens: the date of birth, the
		 * details last sent to DiceX, where they bought, the name on the members
		 * list. What they were copied from was deleted above, or is the shop's own
		 * record. From the next run DiceX is sent the number and nothing else.
		 */
		foreach ( $members as $member ) {
			$clear = array(
				'display_name' => '',
				'country'      => '',
				'city'         => '',
				'payload'      => null,
				'dirty'        => 1,
			);

			// A table not yet upgraded to 1.5.0 has no copy of the date of birth.
			if ( property_exists( $member, 'birth_md' ) ) {
				$clear['birthday'] = null;
				$clear['birth_md'] = '';
			}

			Dicex_Connect_Club_Store::update_member( $member->id, $clear );

			$changed = true;
		}

		if ( null === $removed ) {
			$messages[] = __( 'This person is in the DiceX customer club, but no level is set for removed numbers. Choose one on the Customer Club tab, then erase again.', 'dicex-connect' );
		} else {
			foreach ( $members as $member ) {
				Dicex_Connect_Club_Store::update_member(
					$member->id,
					array(
						'level_id' => $removed['id'],
						'locked'   => 1,
					)
				);
			}

			Dicex_Connect_Club_Queue::tick_soon();

			$messages[] = sprintf(
				/* translators: %s: the name of the club level for removed numbers */
				__( 'Moved to the "%s" level of the DiceX customer club: the number is no longer used, and nothing more about this person is sent. DiceX keeps the number and whatever details it was sent before; WordPress cannot delete them there.', 'dicex-connect' ),
				$removed['name']
			);

			if ( ! empty( $settings['groups'] ) ) {
				$messages[] = __( 'They are in no contact group here any more. A DiceX contact group they were added to still holds the number: DiceX has no way to take a contact out of a group.', 'dicex-connect' );
			}
		}

		return array(
			'items_removed'  => $changed,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * The personal details a member's last payload carried, as export lines.
	 * Level and number are shown already; empty fields are left out.
	 *
	 * @param string $payload JSON, as stored.
	 * @return array
	 */
	private static function sent_details( $payload ) {
		$sent  = json_decode( $payload, true );
		$lines = array();

		if ( ! is_array( $sent ) ) {
			return $lines;
		}

		$labels = array(
			'name'           => __( 'First name sent to DiceX', 'dicex-connect' ),
			'family'         => __( 'Last name sent to DiceX', 'dicex-connect' ),
			'email'          => __( 'Email sent to DiceX', 'dicex-connect' ),
			'company'        => __( 'Company sent to DiceX', 'dicex-connect' ),
			'address'        => __( 'Address sent to DiceX', 'dicex-connect' ),
			'birthDay'       => __( 'Date of birth sent to DiceX', 'dicex-connect' ),
			'anniversaryDay' => __( 'First purchase date sent to DiceX', 'dicex-connect' ),
		);

		foreach ( $labels as $key => $label ) {
			$value = isset( $sent[ $key ] ) && is_scalar( $sent[ $key ] ) ? trim( (string) $sent[ $key ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			// The two dates travel as UTC midnight; the day is what they mean.
			if ( 'birthDay' === $key || 'anniversaryDay' === $key ) {
				$value = substr( $value, 0, 10 );
			}

			$lines[] = array(
				'name'  => $label,
				'value' => $value,
			);
		}

		return $lines;
	}

	/**
	 * The groups one member is in, as a line somebody can read.
	 *
	 * A group they no longer match is still named, because DiceX has no way to take
	 * a contact out of a group and saying otherwise would be untrue.
	 *
	 * @param array $settings
	 * @param int   $member_id
	 * @return string
	 */
	private static function group_names( $settings, $member_id ) {
		if ( empty( $settings['groups'] ) || ! Dicex_Connect_Club_Store::exists() ) {
			return '';
		}

		$names = array();

		foreach ( Dicex_Connect_Club_Store::member_groups( $member_id, false ) as $key => $status ) {
			$group = Dicex_Connect_Club_Settings::find_group( $settings, $key );

			if ( null === $group || 'pending' === $status || 'sending' === $status ) {
				continue;
			}

			$names[] = 'left' === $status
				? sprintf(
					/* translators: %s: the name of a contact group */
					__( '%s (no longer matching; DiceX keeps the contact)', 'dicex-connect' ),
					$group['name']
				)
				: $group['name'];
		}

		return implode( ', ', $names );
	}

	/**
	 * The date of birth and the club choices a person gave, as export lines.
	 *
	 * @param string $email
	 * @return array
	 */
	private static function personal_details( $email ) {
		$data     = array();
		$user     = get_user_by( 'email', sanitize_email( (string) $email ) );
		$woo_meta = Dicex_Connect_Club_Fields::BLOCK_META_PREFIX . Dicex_Connect_Club_Fields::BLOCK_BIRTHDAY;

		if ( $user instanceof WP_User ) {
			// The plugin's own copy, or WooCommerce's copy of what was typed at a
			// checkout block when the plugin's is gone.
			$birthday = (string) get_user_meta( $user->ID, Dicex_Connect_Club_Fields::BIRTHDAY_META, true );
			$birthday = '' !== $birthday ? $birthday : (string) get_user_meta( $user->ID, $woo_meta, true );
			$consent  = Dicex_Connect_Club_Fields::consent( $user->ID );

			if ( '' !== $birthday ) {
				$data[] = array(
					'name'  => __( 'Date of birth', 'dicex-connect' ),
					'value' => $birthday,
				);
			}

			if ( '' !== $consent['given_gmt'] ) {
				$data[] = array(
					'name'  => __( 'Joined the customer club', 'dicex-connect' ),
					'value' => Dicex_Connect_Club_Fields::describe_choice( $consent['given_gmt'], $consent['given_where'] ),
				);
			}

			if ( '' !== $consent['left_gmt'] ) {
				$data[] = array(
					'name'  => __( 'Left the customer club', 'dicex-connect' ),
					'value' => Dicex_Connect_Club_Fields::describe_choice( $consent['left_gmt'], $consent['left_where'] ),
				);
			}
		}

		foreach ( self::orders_for( $email ) as $order ) {
			$birthday = (string) $order->get_meta( Dicex_Connect_Club_Fields::ORDER_BIRTHDAY_META, true );
			$birthday = '' !== $birthday ? $birthday : (string) $order->get_meta( $woo_meta, true );
			$joined   = (string) $order->get_meta( Dicex_Connect_Club_Fields::ORDER_CONSENT_META, true );

			if ( '' !== $birthday ) {
				$data[] = array(
					/* translators: %s: an order number */
					'name'  => sprintf( __( 'Date of birth given with order %s', 'dicex-connect' ), $order->get_order_number() ),
					'value' => $birthday,
				);
			}

			if ( '' !== $joined ) {
				$data[] = array(
					/* translators: %s: an order number */
					'name'  => sprintf( __( 'Joined the customer club with order %s', 'dicex-connect' ), $order->get_order_number() ),
					'value' => Dicex_Connect_Club_Fields::describe_choice( $joined, 'checkout' ),
				);
			}
		}

		return $data;
	}

	/**
	 * Deletes the dates of birth a person gave, and records that they left.
	 *
	 * The record of having joined stays: it says when this person agreed, which
	 * is what the shop may need to show.
	 *
	 * @param string $email
	 * @return bool Whether anything was deleted.
	 */
	private static function erase_personal_details( $email ) {
		$changed  = false;
		$user     = get_user_by( 'email', sanitize_email( (string) $email ) );
		$woo_meta = Dicex_Connect_Club_Fields::BLOCK_META_PREFIX . Dicex_Connect_Club_Fields::BLOCK_BIRTHDAY;

		if ( $user instanceof WP_User ) {
			foreach ( array( Dicex_Connect_Club_Fields::BIRTHDAY_META, $woo_meta ) as $key ) {
				$changed = delete_user_meta( $user->ID, $key ) || $changed;
			}

			Dicex_Connect_Club_Fields::mark_left( $user->ID, 'privacy' );
		}

		foreach ( self::orders_for( $email ) as $order ) {
			$had = '' !== (string) $order->get_meta( Dicex_Connect_Club_Fields::ORDER_BIRTHDAY_META, true ) || '' !== (string) $order->get_meta( $woo_meta, true );

			if ( $had ) {
				$order->delete_meta_data( Dicex_Connect_Club_Fields::ORDER_BIRTHDAY_META );
				$order->delete_meta_data( $woo_meta );
				$order->save();
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * @param string $email
	 * @return WC_Order[] Orders placed under this email address.
	 */
	private static function orders_for( $email ) {
		$email = sanitize_email( (string) $email );

		if ( '' === $email || ! Dicex_Connect_Club_Sources::has_woocommerce() ) {
			return array();
		}

		return array_filter(
			(array) wc_get_orders(
				array(
					'type'          => 'shop_order',
					'billing_email' => $email,
					'limit'         => -1,
				)
			),
			function ( $order ) {
				return $order instanceof WC_Order;
			}
		);
	}

	/**
	 * The club members a privacy request is about: the numbers on this person's
	 * account, and the guest numbers they paid under with this email.
	 *
	 * Typing a number at checkout proves nothing, so an order under this email
	 * only points at numbers to look at. A number another account holds is that
	 * account's, and a guest's belongs to a request only when an order paid under
	 * it carries the request's email — an unpaid one never does.
	 *
	 * @param string $email
	 * @return array
	 */
	private static function members_for( $email ) {
		$email = sanitize_email( (string) $email );

		if ( '' === $email || ! Dicex_Connect_Club_Store::exists() ) {
			return array();
		}

		$members = array();
		$user    = get_user_by( 'email', $email );
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;

		if ( $user_id > 0 ) {
			foreach ( Dicex_Connect_Club_Store::members_of_user( $user_id ) as $member ) {
				$members[ (int) $member->id ] = $member;
			}
		}

		if ( Dicex_Connect_Club_Sources::has_woocommerce() ) {
			$order_ids = wc_get_orders(
				array(
					'type'          => 'shop_order',
					'billing_email' => $email,
					'limit'         => -1,
					'return'        => 'ids',
				)
			);

			$mobiles = array();

			foreach ( (array) $order_ids as $order_id ) {
				$row = Dicex_Connect_Club_Store::get_order( $order_id );

				if ( null !== $row && '' !== (string) $row->mobile ) {
					$mobiles[] = (string) $row->mobile;
				}
			}

			foreach ( Dicex_Connect_Club_Store::members_by_mobiles( $mobiles ) as $member ) {
				$owner = (int) $member->user_id;

				if ( $owner > 0 ? $owner === $user_id : Dicex_Connect_Club_Sources::guest_paid_with_email( (string) $member->mobile, $email ) ) {
					$members[ (int) $member->id ] = $member;
				}
			}
		}

		return array_values( $members );
	}
}
