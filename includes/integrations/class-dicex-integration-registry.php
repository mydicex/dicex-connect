<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one list of plugins DiceX can ride on.
 *
 * Adding an integration is one row in definitions() plus one class file — the
 * Integrations tab, the AJAX handlers and the storage all read this registry
 * and need no edits.
 *
 * Each row's `detect` key says how to tell whether the host plugin is present.
 * Those checks are the ones each project documents for third parties, so they
 * keep working across versions:
 *
 *   WooCommerce      class WooCommerce
 *   WPForms          function wpforms()      (used in WPForms' own examples)
 *   Contact Form 7   constant WPCF7_VERSION  (defined in its main plugin file)
 *   Gravity Forms    class GFAPI             (the check Gravity Forms recommends)
 *
 * Never reach past these into a host plugin's private classes, tables or files.
 */
class Dicex_Connect_Integration_Registry {

	const OPTION_ENABLED  = 'dicex_connect_integrations';
	const OPTION_SETTINGS = 'dicex_connect_integration_settings';

	/**
	 * @return array slug => definition
	 */
	public static function definitions() {
		return array(
			'woocommerce'   => array(
				'label'       => __( 'WooCommerce', 'dicex-connect' ),
				'plugin'      => 'WooCommerce',
				'description' => __( 'Notify admins and the customer when an order changes status.', 'dicex-connect' ),
				'icon'        => 'dashicons-cart',
				'logo'    => 'assets/images/integrations/woocommerce.svg',
				'detect'      => array( 'class' => 'WooCommerce' ),
				'class'       => 'Dicex_Connect_Integration_Woocommerce',
				'events'      => array(
					__( 'Order status changed', 'dicex-connect' ),
				),
				'tags'        => array( '{order_id}', '{order_status}', '{order_total}', '{customer_name}', '{site_name}' ),
				'fields'      => array(
					'statuses' => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Which order statuses should send a message?', 'dicex-connect' ),
						'options' => self::order_status_options(),
						'default' => array( 'processing', 'completed' ),
					),
				),
				'defaults'    => array(
					'channels'   => array( 'sms' ),
					'recipients' => array( Dicex_Connect_Recipients::TYPE_ADMIN_LIST ),
					'template'   => __( 'Order {order_id} on {site_name} changed to {order_status}.', 'dicex-connect' ),
				),
			),
			'wpforms'       => array(
				'label'       => __( 'WPForms', 'dicex-connect' ),
				'plugin'      => 'WPForms',
				'description' => __( 'Send a message on every successful submission.', 'dicex-connect' ),
				'icon'        => 'dashicons-feedback',
				'logo'    => 'assets/images/integrations/wpforms.png',
				'detect'      => array( 'function' => 'wpforms' ),
				'class'       => 'Dicex_Connect_Integration_Wpforms',
				'note'        => __( 'The sender number is found automatically in the submitted fields; there is nothing to map.', 'dicex-connect' ),
				'events'      => array(
					__( 'Successful submission', 'dicex-connect' ),
				),
				'tags'        => array( '{form_title}', '{entry_id}', '{site_name}' ),
				'defaults'    => array(
					'channels'   => array( 'sms' ),
					'recipients' => array( Dicex_Connect_Recipients::TYPE_ADMIN_LIST ),
					'template'   => __( 'Form {form_title} on {site_name} was completed.', 'dicex-connect' ),
				),
			),
			'cf7'           => array(
				'label'       => __( 'Contact Form 7', 'dicex-connect' ),
				'plugin'      => 'Contact Form 7',
				'description' => __( 'Send a message on every successful submission.', 'dicex-connect' ),
				'icon'        => 'dashicons-email-alt',
				'logo'    => 'assets/images/integrations/cf7.svg',
				'detect'      => array( 'constant' => 'WPCF7_VERSION' ),
				'class'       => 'Dicex_Connect_Integration_Cf7',
				'note'        => __( 'The sender number is found automatically in the submitted fields; there is nothing to map.', 'dicex-connect' ),
				'events'      => array(
					__( 'Successful submission', 'dicex-connect' ),
				),
				'tags'        => array( '{form_title}', '{site_name}' ),
				'defaults'    => array(
					'channels'   => array( 'sms' ),
					'recipients' => array( Dicex_Connect_Recipients::TYPE_ADMIN_LIST ),
					'template'   => __( 'Form {form_title} on {site_name} was completed.', 'dicex-connect' ),
				),
			),
			'gravityforms'  => array(
				'label'       => __( 'Gravity Forms', 'dicex-connect' ),
				'plugin'      => 'Gravity Forms',
				'description' => __( 'Send a message on every entry.', 'dicex-connect' ),
				'icon'        => 'dashicons-forms',
				'logo'    => 'assets/images/integrations/gravityforms.svg',
				'detect'      => array( 'class' => 'GFAPI' ),
				'class'       => 'Dicex_Connect_Integration_Gravityforms',
				'note'        => __( 'The sender number is found automatically in the submitted fields; there is nothing to map.', 'dicex-connect' ),
				'events'      => array(
					__( 'Entry created', 'dicex-connect' ),
				),
				'tags'        => array( '{form_title}', '{entry_id}', '{site_name}' ),
				'defaults'    => array(
					'channels'   => array( 'sms' ),
					'recipients' => array( Dicex_Connect_Recipients::TYPE_ADMIN_LIST ),
					'template'   => __( 'Form {form_title} on {site_name} was completed.', 'dicex-connect' ),
				),
			),
			'core-security' => array(
				'label'       => __( 'WordPress security events', 'dicex-connect' ),
				'plugin'      => '',
				'description' => __( 'Failed logins, administrator sign-ins, new users and password resets — independent of any security plugin.', 'dicex-connect' ),
				'icon'        => 'dashicons-shield',
				'logo'    => 'assets/images/integrations/wordpress.svg',
				'detect'      => array( 'core' => true ),
				'class'       => 'Dicex_Connect_Integration_Core_Security',
				'events'      => array(
					__( 'Failed login', 'dicex-connect' ),
					__( 'Administrator signed in', 'dicex-connect' ),
					__( 'New user registered', 'dicex-connect' ),
					__( 'Password reset', 'dicex-connect' ),
				),
				'tags'        => array( '{event}', '{user_login}', '{ip}', '{site_name}' ),
				'fields'      => array(
					'security_events' => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Which events should send a message?', 'dicex-connect' ),
						'options' => array(
							'failed_login'   => __( 'Failed logins from one address', 'dicex-connect' ),
							'admin_login'    => __( 'An administrator signing in', 'dicex-connect' ),
							'new_user'       => __( 'New user registered', 'dicex-connect' ),
							'password_reset' => __( 'Password reset', 'dicex-connect' ),
						),
						'default' => array( 'failed_login', 'admin_login' ),
					),
					'failed_threshold' => array(
						'type'    => 'select',
						'label'   => __( 'Warn after this many failed logins from one address, within fifteen minutes', 'dicex-connect' ),
						'options' => array(
							'1'  => __( 'Every failed attempt', 'dicex-connect' ),
							'3'  => __( '3 attempts', 'dicex-connect' ),
							'5'  => __( '5 attempts', 'dicex-connect' ),
							'10' => __( '10 attempts', 'dicex-connect' ),
						),
						'default' => '5',
					),
				),
				'defaults'    => array(
					'channels'   => array( 'sms' ),
					'recipients' => array( Dicex_Connect_Recipients::TYPE_ADMIN_LIST ),
					'template'   => __( '{site_name}: {event} — user {user_login} from {ip}', 'dicex-connect' ),
				),
			),
			'digits'        => array(
				'label'           => __( 'Digits', 'dicex-connect' ),
				'plugin'          => 'Digits',
				'description'     => __( 'Deliver the login codes Digits generates through DiceX.', 'dicex-connect' ),
				'icon'            => 'dashicons-smartphone',
				'logo'        => 'assets/images/integrations/digits.png',
				'detect'          => array( 'function' => 'digits_login_user' ),
				'class'           => 'Dicex_Connect_Integration_Digits',
				'hide_recipients' => true,
				'hide_template'   => true,
				'note'            => __( 'One more step after switching this on: open Digits, go to its SMS gateway settings and choose DiceX. The wording and the recipient stay under Digits control — DiceX only delivers.', 'dicex-connect' ),
				'events'          => array(
					__( 'Login and registration codes', 'dicex-connect' ),
				),
				'tags'            => array(),
				'defaults'        => array(
					'channels'   => array( 'sms' ),
					'recipients' => array(),
					'template'   => '',
				),
			),
			'otp-login'     => array(
				'label'           => __( 'DiceX two-step login', 'dicex-connect' ),
				'plugin'          => '',
				'description'     => __( 'An SMS code on the WordPress login page, with no other plugin needed.', 'dicex-connect' ),
				'icon'            => 'dashicons-lock',
				'logo'            => 'assets/images/menu-icon.png',
				'detect'          => array( 'core' => true ),
				'class'           => 'Dicex_Connect_Login',
				'hide_recipients' => true,
				'note'            => __( 'The second step applies only to users with a mobile number on their profile. To switch it off in an emergency, set DICEX_CONNECT_DISABLE_LOGIN_OTP to true in wp-config.php.', 'dicex-connect' ),
				'events'          => array(
					__( 'Login code by SMS', 'dicex-connect' ),
				),
				'tags'            => array( '{code}', '{site_name}' ),
				'fields'          => array(
					'login_mode' => array(
						'type'    => 'select',
						'label'   => __( 'Login method', 'dicex-connect' ),
						'options' => array(
							'second_factor' => __( 'An SMS code as a second step, after the password', 'dicex-connect' ),
							'passwordless'  => __( 'Sign in with a number and a code, no password', 'dicex-connect' ),
							'both'          => __( 'Both — the person chooses on the login page', 'dicex-connect' ),
						),
						'default' => 'second_factor',
					),
				),
				'defaults'        => array(
					'channels'   => array( 'sms' ),
					'recipients' => array(),
					'template'   => __( 'Your login code for {site_name}: {code}', 'dicex-connect' ),
				),
			),
		);
	}

	/**
	 * Order statuses for the WooCommerce card, keyed by the unprefixed status.
	 *
	 * wc_get_order_statuses() has historically keyed its array with the "wc-"
	 * storage prefix, while WC_Order::get_status() and the
	 * woocommerce_order_status_changed hook both report a status without it. The
	 * prefix is stripped here so the stored value always matches what the hook
	 * hands us, whichever way the running WooCommerce spells it.
	 *
	 * @return array status => translated label; empty when WooCommerce is absent.
	 */
	public static function order_status_options() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$options = array();

		foreach ( wc_get_order_statuses() as $status => $label ) {
			$status             = ( 0 === strpos( $status, 'wc-' ) ) ? substr( $status, 3 ) : $status;
			$options[ $status ] = $label;
		}

		return $options;
	}

	/**
	 * Extra per-integration settings fields, beyond channel/recipients/template.
	 *
	 * @return array key => array( type, label, options, default )
	 */
	public static function fields( $slug ) {
		$definition = self::get( $slug );

		return ( null !== $definition && isset( $definition['fields'] ) ) ? $definition['fields'] : array();
	}

	public static function exists( $slug ) {
		$definitions = self::definitions();

		return isset( $definitions[ $slug ] );
	}

	/**
	 * @return array|null Definition, or null when the slug is unknown.
	 */
	public static function get( $slug ) {
		$definitions = self::definitions();

		return isset( $definitions[ $slug ] ) ? $definitions[ $slug ] : null;
	}

	/**
	 * Is the host plugin present on this site?
	 */
	public static function is_available( $slug ) {
		$definition = self::get( $slug );

		if ( null === $definition ) {
			return false;
		}

		$detect = $definition['detect'];

		if ( ! empty( $detect['core'] ) ) {
			return true;
		}
		if ( isset( $detect['class'] ) ) {
			return class_exists( $detect['class'] );
		}
		if ( isset( $detect['function'] ) ) {
			return function_exists( $detect['function'] );
		}
		if ( isset( $detect['constant'] ) ) {
			return defined( $detect['constant'] );
		}

		return false;
	}

	/**
	 * Has the admin switched this integration on? Availability is part of the
	 * answer: a host plugin that was deactivated silently stops the sends
	 * without the stored toggle being touched.
	 */
	public static function is_enabled( $slug ) {
		if ( ! self::is_available( $slug ) ) {
			return false;
		}

		$enabled = get_option( self::OPTION_ENABLED, array() );

		return is_array( $enabled ) && ! empty( $enabled[ $slug ] );
	}

	public static function set_enabled( $slug, $enabled ) {
		$stored = get_option( self::OPTION_ENABLED, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored[ $slug ] = (bool) $enabled;
		update_option( self::OPTION_ENABLED, $stored, false );
	}

	/**
	 * Stored settings for one integration, with the definition's defaults
	 * filled in for anything never saved.
	 *
	 * @return array channel, recipients, template
	 */
	public static function get_settings( $slug ) {
		$definition = self::get( $slug );

		if ( null === $definition ) {
			return array();
		}

		$stored = get_option( self::OPTION_SETTINGS, array() );
		$saved = ( is_array( $stored ) && isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) )
			? $stored[ $slug ]
			: array();

		/*
		 * Before 0.14.0 a card sent over one channel, stored as 'channel'. It now
		 * keeps an ordered list to fall back through, so an old setting is read as a
		 * list of one. Nothing has to be re-saved for this to work.
		 */
		if ( ! isset( $saved['channels'] ) && ! empty( $saved['channel'] ) ) {
			$saved['channels'] = array( $saved['channel'] );
		}

		$defaults = $definition['defaults'];

		// Extra fields keep their default in the field definition, not duplicated here.
		if ( isset( $definition['fields'] ) ) {
			foreach ( $definition['fields'] as $field_key => $field ) {
				if ( ! array_key_exists( $field_key, $defaults ) ) {
					$defaults[ $field_key ] = $field['default'];
				}
			}
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * @param string $slug
	 * @param array  $settings Already sanitized by the admin layer.
	 */
	public static function save_settings( $slug, $settings ) {
		$stored = get_option( self::OPTION_SETTINGS, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored[ $slug ] = $settings;
		update_option( self::OPTION_SETTINGS, $stored, false );
	}


	/**
	 * Things that stop a switched-on card from doing anything, said out loud on
	 * the card itself. A card that is on but inert is the worst failure mode
	 * there is: nothing happens and nothing explains why.
	 *
	 * @param string $slug
	 * @return array Warning lines, empty when the card is fine.
	 */
	/**
	 * Where the current user's own mobile number is edited.
	 *
	 * Anchored at the field itself, so the link lands on it rather than at the
	 * top of a long profile screen.
	 *
	 * @return string
	 */
	public static function profile_url() {
		return get_edit_profile_url() . '#dicex-connect-mobile';
	}

	/**
	 * Why no card can be switched on yet, or an empty array when one can.
	 *
	 * The prerequisite is a usable mobile number on the current user's own
	 * profile. Everything this plugin does ends in a message to somebody, and
	 * somebody setting it up without a number of their own has no way to see
	 * that it works — which is how a switched-on card ends up doing nothing
	 * quietly.
	 *
	 * @return array A note, or empty when nothing is in the way.
	 */
	public static function enable_blocker() {
		$user_id = get_current_user_id();

		if ( '' !== Dicex_Connect_Recipients::get_user_number( $user_id ) ) {
			return array();
		}

		$stored = get_user_meta( $user_id, Dicex_Connect_Recipients::USER_META_KEY, true );

		// Never set and set wrongly are different problems with different fixes,
		// and telling somebody to "add a number" when they can see one in the
		// field is how a person decides the plugin is broken.
		$text = ( '' === trim( (string) $stored ) )
			? __( 'Add a mobile number to your profile before switching a card on. Until the plugin knows where to reach you, a card switched on now would do nothing.', 'dicex-connect' )
			: sprintf(
				/* translators: %s: how a number should be written, such as "Write every number with a plus and its country code, like +96891234567." */
				__( 'The mobile number on your profile is not in a form this plugin can use, so no card can be switched on yet. %s', 'dicex-connect' ),
				Dicex_Connect_Mobile::format_hint()
			);

		return array(
			'text'  => $text,
			'url'   => self::profile_url(),
			'label' => __( 'Open your profile', 'dicex-connect' ),
		);
	}

	/**
	 * Things worth saying on a card that is already switched on.
	 *
	 * Each note is an array of text, and optionally a url and the words to put
	 * on it — so the screens can render a real link and do their own escaping,
	 * rather than a translated string carrying markup.
	 *
	 * @param string $slug
	 * @return array
	 */
	public static function status_notes( $slug ) {
		$notes = array();

		if ( 'otp-login' !== $slug ) {
			return $notes;
		}

		if ( defined( 'DICEX_CONNECT_DISABLE_LOGIN_OTP' ) && DICEX_CONNECT_DISABLE_LOGIN_OTP ) {
			$notes[] = array( 'text' => __( 'Switched off in wp-config.php by DICEX_CONNECT_DISABLE_LOGIN_OTP. Remove that line to use the second step.', 'dicex-connect' ) );
		}

		if ( '' === Dicex_Connect_Recipients::get_user_number( get_current_user_id() ) ) {
			$notes[] = array(
				// Neutral on purpose: this fires both when no number was ever set
				// and when the one there cannot be used, and "you have no number"
				// is a confusing thing to read next to a field with a number in it.
				'text'  => __( 'Your logins will not ask for a code until your own profile has a mobile number this plugin can use.', 'dicex-connect' ),
				'url'   => self::profile_url(),
				'label' => __( 'Open your profile', 'dicex-connect' ),
			);
		}

		/*
		 * The card is on but nothing it sends over can send — wrong region for
		 * the channel, or a channel with no line behind it. The module stands
		 * itself down in that state rather than refusing logins, and somebody
		 * looking at a switched-on card deserves to be told why nothing happens.
		 */
		$settings = self::get_settings( 'otp-login' );
		$channels = ( isset( $settings['channels'] ) && is_array( $settings['channels'] ) && ! empty( $settings['channels'] ) )
			? $settings['channels']
			: array( 'sms' );

		if ( empty( Dicex_Connect_Sender::sendable( $channels ) ) ) {
			$notes[] = array( 'text' => __( 'None of the channels this card is set to use can send in your current region, so the second step is standing down and logins are working as normal. Choose a channel your region offers, or change the region on the Connection tab.', 'dicex-connect' ) );
		}

		return $notes;
	}
	/**
	 * Instantiates every integration that is switched on and whose class has
	 * shipped. A slug still waiting for its class file is simply skipped, so
	 * the tab can list an integration before it can send.
	 */
	public static function boot() {
		foreach ( array_keys( self::definitions() ) as $slug ) {
			if ( ! self::is_enabled( $slug ) ) {
				continue;
			}

			$class = self::get( $slug )['class'];

			if ( class_exists( $class ) ) {
				new $class();
			}
		}
	}
}
