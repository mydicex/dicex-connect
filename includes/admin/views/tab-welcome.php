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

/**
 * The screen a site owner lands on with the plugin freshly installed: what DiceX
 * is, and the two roads out of here — make an account, or fetch a key for the
 * one you already have.
 *
 * Deliberately not an activation redirect or a dashboard notice. Guideline 11
 * asks plugins to feel like part of WordPress rather than take the admin over,
 * so this is simply the tab the DiceX menu opens on until the key is verified.
 */

$verified = Dicex_Connect_Account::is_verified();
?>
<div class="dicex-connect-card dicex-connect-welcome">

	<div class="dicex-connect-welcome-head">
		<img class="dicex-connect-welcome-mark" src="<?php echo esc_url( DICEX_CONNECT_URL . 'assets/images/menu-icon.png' ); ?>" alt="" width="56" height="56">
		<h2><?php esc_html_e( 'Welcome to DiceX', 'dicex-connect' ); ?></h2>
		<p class="dicex-connect-welcome-version" dir="ltr">
			<?php
			printf(
				/* translators: %s: a version number, such as 1.0.12 */
				esc_html__( 'Version %s', 'dicex-connect' ),
				esc_html( Dicex_Connect_Changelog::current_version() )
			);
			?>
		</p>
		<p class="dicex-connect-welcome-sub">
			<?php esc_html_e( 'Connecting WordPress to the DiceX digital communications ecosystem', 'dicex-connect' ); ?>
		</p>
	</div>

	<?php if ( $verified ) : ?>
		<p class="dicex-connect-welcome-connected">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'This site is connected to DiceX. Choose your sender lines, switch on the cards you want, and set up your Customer Club.', 'dicex-connect' ); ?>
		</p>
	<?php endif; ?>

	<div class="dicex-connect-welcome-body">
		<p><?php esc_html_e( 'The DiceX plugin connects your WordPress site to the DiceX digital communications platform.', 'dicex-connect' ); ?></p>

		<p><?php esc_html_e( 'DiceX is a single infrastructure for managing digital communications, offering services such as SMS, social messengers, Telegram Bot, Bale, WhatsApp, voice calls, online meetings and digital marketing tools in one ecosystem.', 'dicex-connect' ); ?></p>

		<p><?php esc_html_e( 'With this plugin your WordPress users and WooCommerce customers join your DiceX customer club, each in a level you define and in every contact group they match, and move between levels as they buy.', 'dicex-connect' ); ?></p>

		<p><?php esc_html_e( 'It also sends a message over DiceX channels when something important happens in WordPress or in a compatible plugin: a new order, an order status change, a user registration, a form submission, an administrative alert, or a login that asks for a one-time code.', 'dicex-connect' ); ?></p>

		<ul class="dicex-connect-welcome-channels">
			<?php
			foreach ( Dicex_Connect_Lines::labels() as $channel_key => $channel_label ) :
				$channel_mark = Dicex_Connect_Marks::channel( $channel_key );
				?>
				<li class="dicex-connect-welcome-channel dicex-connect-channel-<?php echo esc_attr( $channel_key ); ?>">
					<?php if ( '' !== $channel_mark ) : ?>
						<img src="<?php echo esc_url( $channel_mark ); ?>" alt="" width="18" height="18" loading="lazy">
					<?php endif; ?>
					<?php echo esc_html( $channel_label ); ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<p><?php esc_html_e( 'To begin: if you do not have a DiceX account yet, create one first. If you are already a DiceX member, get your API key from your panel and enter it in the plugin settings.', 'dicex-connect' ); ?></p>

		<p><?php esc_html_e( 'Your customer club and your site\'s messages then live in one place: your DiceX account.', 'dicex-connect' ); ?></p>
	</div>

	<div class="dicex-connect-welcome-actions">
		<a class="button button-primary button-hero dicex-connect-welcome-cta" href="https://kyc.dicex.me/" target="_blank" rel="noopener noreferrer">
			<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
			<?php esc_html_e( 'Create an account', 'dicex-connect' ); ?>
		</a>
		<a class="button button-secondary button-hero dicex-connect-welcome-cta" href="https://dev.dicex.me/developers/api-keys" target="_blank" rel="noopener noreferrer">
			<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
			<?php esc_html_e( 'Get your API key', 'dicex-connect' ); ?>
		</a>
	</div>

	<p class="description dicex-connect-welcome-hint">
		<?php esc_html_e( 'If you do not have a DiceX account yet, start with Create an account. If you are already a DiceX member, get your API key first and then enter it in the plugin settings.', 'dicex-connect' ); ?>
		<button type="button" class="button-link dicex-connect-goto-tab" data-goto="connection">
			<?php esc_html_e( 'Enter the key now', 'dicex-connect' ); ?>
		</button>
	</p>

	<?php
	$dicex_entries = Dicex_Connect_Changelog::entries( 5 );

	if ( ! empty( $dicex_entries ) ) :
		?>
		<details class="dicex-connect-whatsnew">
			<summary>
				<span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
				<?php esc_html_e( "What's new", 'dicex-connect' ); ?>
				<span class="dicex-connect-whatsnew-latest" dir="ltr"><?php echo esc_html( $dicex_entries[0]['version'] ); ?></span>
			</summary>

			<div class="dicex-connect-whatsnew-body">
				<?php foreach ( $dicex_entries as $dicex_entry ) : ?>
					<div class="dicex-connect-release">
						<h4>
							<span dir="ltr"><?php echo esc_html( $dicex_entry['version'] ); ?></span>
							<?php if ( $dicex_entry['version'] === Dicex_Connect_Changelog::current_version() ) : ?>
								<span class="dicex-connect-release-current"><?php esc_html_e( 'installed', 'dicex-connect' ); ?></span>
							<?php endif; ?>
						</h4>
						<?php foreach ( $dicex_entry['groups'] as $dicex_group ) : ?>
							<?php if ( '' !== $dicex_group['title'] ) : ?>
								<h5 class="dicex-connect-release-group"><?php echo esc_html( $dicex_group['title'] ); ?></h5>
							<?php endif; ?>
							<?php if ( ! empty( $dicex_group['lines'] ) ) : ?>
								<ul>
									<?php foreach ( $dicex_group['lines'] as $dicex_line ) : ?>
										<li><?php echo esc_html( $dicex_line ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<p class="description">
					<?php esc_html_e( 'WordPress tells you when a newer version is available and offers it on the Plugins screen — there is nothing to check here.', 'dicex-connect' ); ?>
				</p>
			</div>
		</details>
	<?php endif; ?>

	<p class="dicex-connect-welcome-links">
		<a href="https://wp.dicex.me/" target="_blank" rel="noopener noreferrer">
			<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
			<?php esc_html_e( 'What this plugin does', 'dicex-connect' ); ?>
		</a>
		<a href="https://dicex.me/" target="_blank" rel="noopener noreferrer">
			<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
			<?php esc_html_e( 'Visit the DiceX website', 'dicex-connect' ); ?>
		</a>
		<a href="https://app.dicex.me/" target="_blank" rel="noopener noreferrer">
			<span class="dashicons dashicons-external" aria-hidden="true"></span>
			<?php esc_html_e( 'Open the DiceX panel', 'dicex-connect' ); ?>
		</a>
	</p>
</div>
