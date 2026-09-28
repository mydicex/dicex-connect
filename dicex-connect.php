<?php
/**
 * Plugin Name: DiceX Connect – Customer Club and Event Notifications
 * Plugin URI: https://wp.dicex.me/
 * Description: A customer club with levels you define, plus SMS, WhatsApp and Telegram alerts for orders, forms and logins, through your own DiceX account.
 * Version: 1.8.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: DiceX
 * Author URI: https://dicex.me/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dicex-connect
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DICEX_CONNECT_VERSION', '1.8.1' );
define( 'DICEX_CONNECT_FILE', __FILE__ );
define( 'DICEX_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'DICEX_CONNECT_URL', plugin_dir_url( __FILE__ ) );

/*
 * This is the active gateway the predecessor plugin used. Endpoint
 * paths built on top of this constant live in includes/core/class-dicex-api-client.php.
 */
if ( ! defined( 'DICEX_API_BASE_URL' ) ) {
	define( 'DICEX_API_BASE_URL', 'https://gateway.dicex.me/' );
}

require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-mobile.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-logger.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-branding.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-api-client.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-account.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-send-options.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-message.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-changelog.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-marks.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-lines.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-currency.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-region.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-sender.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-outbox.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-credit.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-payment-terms.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-recipients.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-otp.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-dates.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-club.php';
require_once DICEX_CONNECT_DIR . 'includes/core/class-dicex-support.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-registry.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-base.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-woocommerce.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-wpforms.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-cf7.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-gravityforms.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-core-security.php';
require_once DICEX_CONNECT_DIR . 'includes/integrations/class-dicex-integration-digits.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/class-dicex-login.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-settings.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-store.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-sources.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-queue.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-sync.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-fields.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-language.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-privacy.php';
require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-module.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-integrations-page.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-club-page.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-user-profile.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-support-page.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-settings-page.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-logs-page.php';
require_once DICEX_CONNECT_DIR . 'includes/admin/class-dicex-admin-menu.php';
require_once DICEX_CONNECT_DIR . 'includes/class-dicex-plugin.php';

/*
 * WooCommerce High-Performance Order Storage. This plugin only ever handles orders
 * through the CRUD API — the WC_Order object WooCommerce hands its own hooks,
 * and wc_get_order(), which is also how the club keeps a customer's date of birth
 * and club choice on an order — so it is compatible with the custom order tables that
 * have been the default for new stores since WooCommerce 8.2. Declared here
 * unconditionally: switching the WooCommerce card off does not make the plugin
 * incompatible, and an undeclared plugin shows up on WooCommerce's
 * incompatible-plugins list either way.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/*
 * The Customer Club's commands — wp dicex club status, import, sync, retry and
 * release — only while WP-CLI is the one running WordPress.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DICEX_CONNECT_DIR . 'includes/modules/club/class-dicex-club-cli.php';
	WP_CLI::add_command( 'dicex club', 'Dicex_Connect_Club_CLI' );
}

register_activation_hook( __FILE__, array( 'Dicex_Connect_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Dicex_Connect_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Dicex_Connect_Plugin', 'instance' ) );
