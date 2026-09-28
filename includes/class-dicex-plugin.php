<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dicex_Connect_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		/*
		 * Nothing loads translations here, and nothing needs to.
		 *
		 * WordPress has loaded a plugin's own text domain by itself since 4.6,
		 * just in time on the first translated string, from the language pack
		 * translate.wordpress.org builds. This plugin declares Text Domain and
		 * Domain Path in its header and requires 5.8, so registering the domain by
		 * hand would only repeat what core already does.
		 *
		 * That also settles which language these screens speak: whichever one
		 * WordPress is showing the person signed in, from their profile language
		 * or the site language. There is nothing to configure and nothing that can
		 * disagree with the rest of wp-admin.
		 */

		/*
		 * Integrations hook the front end as well as the admin — an order status
		 * changes on the storefront, a form is submitted by a visitor — so this
		 * runs on every request, not just in wp-admin. An integration that is
		 * switched off registers nothing at all.
		 *
		 * On after_setup_theme, after plugins_loaded: every active plugin's own
		 * file has run by then, so class_exists() checks against WooCommerce,
		 * Gravity Forms and the rest give a truthful answer no matter what order
		 * the plugins load in. Not earlier, because the registry's rows carry
		 * translated labels, and since WordPress 6.7 a translation loaded before
		 * after_setup_theme is reported as loaded too early — which printed a
		 * notice on every wp dicex command of a site with debugging on. Every hook
		 * an integration listens to fires after init: orders, forms, logins, and
		 * Digits' own filters, which it applies on its settings screen and when
		 * it sends.
		 */
		add_action( 'after_setup_theme', array( 'Dicex_Connect_Integration_Registry', 'boot' ), 1 );

		/*
		 * The customer club starts on init rather than plugins_loaded: Action
		 * Scheduler, which it hands its background work to, sets itself up on
		 * init at priority 1 and may not be used before that. Every order and
		 * user event it listens for happens after init anyway.
		 */
		add_action( 'init', array( 'Dicex_Connect_Club_Module', 'boot' ), 20 );

		if ( is_admin() ) {
			$settings_page = new Dicex_Connect_Settings_Page();
			$logs_page     = new Dicex_Connect_Logs_Page();
			new Dicex_Connect_Admin_Menu( $settings_page, $logs_page );
			new Dicex_Connect_Integrations_Page();
			new Dicex_Connect_Club_Page();
			new Dicex_Connect_Support_Page();
			new Dicex_Connect_User_Profile();
		}
	}

	public static function activate() {
		self::migrate_legacy_identity();

		if ( false === get_option( 'dicex_connect_options' ) ) {
			add_option( 'dicex_connect_options', array(), '', false );
		}

		// A club that was on before deactivation gets its daily run back the next
		// time an administrator loads a screen — the scheduler is not ready yet here.
		if ( Dicex_Connect_Club_Settings::is_enabled() ) {
			set_transient( 'dicex_connect_club_reschedule', 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Carries settings over from the identity used before the plugin was renamed.
	 *
	 * Up to 0.3.4 this shipped as "DiceX Connect WordPress Plugin" in a dicexwp/
	 * folder with a dicex_connect_wordpress_plugin_* option prefix. That name and
	 * slug could never be published: WordPress.org bans "WordPress" and "WP" in
	 * both (guideline 17 / Trademarks_Check). Renaming also changes the plugin
	 * folder, which WordPress treats as a different plugin entirely — so the site
	 * activates this as a fresh plugin, and activation is exactly the moment the
	 * old API key, lines and logs need to come across.
	 */
	private static function migrate_legacy_identity() {
		$legacy_options = array(
			'dicex_connect_wordpress_plugin_options'              => 'dicex_connect_options',
			'dicex_connect_wordpress_plugin_api_verified'         => 'dicex_connect_api_verified',
			'dicex_connect_wordpress_plugin_logs'                 => 'dicex_connect_logs',
			'dicex_connect_wordpress_plugin_integrations'         => 'dicex_connect_integrations',
			'dicex_connect_wordpress_plugin_integration_settings' => 'dicex_connect_integration_settings',
		);

		foreach ( $legacy_options as $old_key => $new_key ) {
			$old_value = get_option( $old_key, null );

			if ( null === $old_value ) {
				continue;
			}

			// Never overwrite something the renamed plugin already wrote.
			if ( false === get_option( $new_key, false ) ) {
				update_option( $new_key, $old_value, false );
			}

			delete_option( $old_key );
		}

		delete_transient( 'dicex_connect_wordpress_plugin_account_info' );

		foreach ( array( 'sms', 'whatsapp', 'voice', 'telegram', 'bale', 'safir' ) as $channel ) {
			delete_transient( 'dicex_connect_wordpress_plugin_lines_' . $channel );
		}
	}

	public static function deactivate() {
		// Data is only ever removed on uninstall, not on deactivate. Scheduled runs
		// are not data: left behind, they would fire for a plugin that is not there.
		Dicex_Connect_Club_Queue::unschedule_all();
	}
}
