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
/** @var array $logs */
?>
<div class="wrap dicex-connect-wrap <?php echo esc_attr( is_rtl() ? 'is-rtl' : 'is-ltr' ); ?>">
	<h1><?php esc_html_e( 'DiceX logs', 'dicex-connect' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dicex_connect_clear_logs' ); ?>
		<input type="hidden" name="action" value="dicex_connect_clear_logs">
		<button type="submit" class="button"><?php esc_html_e( 'Clear logs', 'dicex-connect' ); ?></button>
	</form>

	<div class="dicex-connect-log-box">
		<?php if ( empty( $logs ) ) : ?>
			<p><?php esc_html_e( 'Nothing has been logged yet.', 'dicex-connect' ); ?></p>
		<?php else : ?>
			<?php foreach ( $logs as $entry ) : ?>
				<div><?php echo esc_html( $entry ); ?></div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
