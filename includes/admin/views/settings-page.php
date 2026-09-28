<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * A template, never loaded on its own. Every view here is require'd from inside
 * a method — Dicex_Connect_Settings_Page::render() and ::ajax_load_tab() for the
 * tabs, Dicex_Connect_Logs_Page::render() for the log screen — so PHP scopes the
 * variables below to that method. None of them is a global.
 *
 * PHP_CodeSniffer reads each file on its own and cannot see where it is included
 * from, so it takes every assignment at file level for a global and asks for a
 * prefix. Prefixing a hundred local template variables would make these files
 * harder to read for no gain, so the sniff is told about the scope instead.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Function-scoped template variables; see the note above.
/** @var array $tabs */
/** @var string $active_tab */

$tab_icons = array(
	'welcome'      => 'lightbulb',
	'connection'   => 'admin-links',
	'lines'        => 'list-view',
	'integrations' => 'admin-plugins',
	'club'         => 'groups',
	'credit'       => 'money-alt',
	'support'      => 'sos',
);
?>
<div class="wrap dicex-connect-wrap <?php echo esc_attr( is_rtl() ? 'is-rtl' : 'is-ltr' ); ?>">
	<h1><?php esc_html_e( 'DiceX — communications connection', 'dicex-connect' ); ?></h1>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'dicex-connect', 'tab' => $tab_key ), admin_url( 'admin.php' ) ) ); ?>"
				data-tab="<?php echo esc_attr( $tab_key ); ?>"
				class="nav-tab <?php echo esc_attr( $active_tab === $tab_key ? 'nav-tab-active' : '' ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( isset( $tab_icons[ $tab_key ] ) ? $tab_icons[ $tab_key ] : 'admin-generic' ); ?>"></span>
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<div class="dicex-connect-tab-content" id="dicex-connect-tab-content">
		<?php
		$view_file = DICEX_CONNECT_DIR . 'includes/admin/views/tab-' . $active_tab . '.php';
		if ( file_exists( $view_file ) ) {
			require $view_file;
		}
		?>
	</div>
</div>
