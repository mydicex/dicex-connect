<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes what the plugin keeps on the current site: its options, transients,
 * tables and anything still scheduled.
 *
 * On a network every site has its own copy of all of these, so this runs once
 * per site. User meta is shared by the whole network and is removed once, below.
 */
function dicex_connect_uninstall_site() {
	global $wpdb;

	delete_option( 'dicex_connect_options' );
	delete_option( 'dicex_connect_api_verified' );
	delete_option( 'dicex_connect_logs' );
	delete_option( 'dicex_connect_integrations' );
	delete_option( 'dicex_connect_integration_settings' );
	delete_option( 'dicex_connect_support_sent' );

	delete_transient( 'dicex_connect_account_info' );
	foreach ( array( 'sms', 'whatsapp', 'voice', 'telegram', 'bale', 'safir' ) as $dicex_connect_channel ) {
		delete_transient( 'dicex_connect_lines_' . $dicex_connect_channel );
	}

	// Left behind by the pre-0.4.0 identity, on sites that used the plugin before it
	// was renamed. Dicex_Connect_Plugin::migrate_legacy_identity() normally clears these on
	// activation; this is the safety net for a site that never got that far.
	delete_option( 'dicex_connect_wordpress_plugin_options' );
	delete_option( 'dicex_connect_wordpress_plugin_api_verified' );
	delete_option( 'dicex_connect_wordpress_plugin_logs' );
	delete_option( 'dicex_connect_wordpress_plugin_integrations' );
	delete_option( 'dicex_connect_wordpress_plugin_integration_settings' );

	delete_transient( 'dicex_connect_wordpress_plugin_account_info' );
	foreach ( array( 'sms', 'whatsapp', 'voice', 'telegram', 'bale', 'safir' ) as $dicex_connect_channel ) {
		delete_transient( 'dicex_connect_wordpress_plugin_lines_' . $dicex_connect_channel );
	}

	/*
	 * The customer club: its settings, its state, its tables and anything still
	 * scheduled. Only this site's copy goes — the DiceX club keeps its customers and
	 * its contact groups, because nothing can be deleted there.
	 */
	delete_option( 'dicex_connect_club' );
	delete_option( 'dicex_connect_club_state' );
	delete_option( 'dicex_connect_club_db' );
	delete_option( 'dicex_connect_club_lock' );
	delete_transient( 'dicex_connect_club_reschedule' );

	wp_clear_scheduled_hook( 'dicex_connect_club_tick' );
	wp_clear_scheduled_hook( 'dicex_connect_club_daily' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'dicex_connect_club_tick', array(), 'dicex-connect' );
		as_unschedule_all_actions( 'dicex_connect_club_daily', array(), 'dicex-connect' );
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dicex_connect_club_members" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- this plugin's own table, removed on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dicex_connect_club_orders" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- this plugin's own table, removed on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dicex_connect_club_order_items" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- this plugin's own table, removed on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dicex_connect_club_group_members" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- this plugin's own table, removed on uninstall.

	/*
	 * Failed-login counters and pending login codes are transients keyed by a hash
	 * or a random token, so there is no list of them to walk — only a pattern. They
	 * expire on their own within minutes, but an uninstall should leave no rows.
	 */
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's own transients on uninstall.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_dicex_connect_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_dicex_connect_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	// Every site of the network, not just the one the uninstall was started from.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $dicex_connect_site_id ) {
		switch_to_blog( $dicex_connect_site_id );
		dicex_connect_uninstall_site();
		restore_current_blog();
	}
} else {
	dicex_connect_uninstall_site();
}

// The mobile number each administrator put on their own profile.
delete_metadata( 'user', 0, 'dicex_connect_mobile', '', true );

/*
 * Dates of birth and club choices kept on user accounts, including WooCommerce's
 * copies of what was typed into the club's fields on its own forms, and what the
 * club remembers of the messages each account was sent. Orders keep theirs: an
 * order is the shop's record of what happened, and stays as it was.
 */
foreach ( array( 'dicex_connect_birthday', 'dicex_connect_club_consent', 'dicex_connect_club_welcomed', 'dicex_connect_club_level_notes', '_wc_other/dicex-connect/birthday', '_wc_other/dicex-connect/club' ) as $dicex_connect_meta_key ) {
	delete_metadata( 'user', 0, $dicex_connect_meta_key, '', true );
}
