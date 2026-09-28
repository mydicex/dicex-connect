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

$support_user  = wp_get_current_user();
$support_facts = Dicex_Connect_Support::site_facts();
$support_wait  = Dicex_Connect_Support::wait();
$support_yes   = __( 'Yes', 'dicex-connect' );
$support_no    = __( 'No', 'dicex-connect' );

// What goes with every message, shown in the words of this screen. The email
// itself carries the same facts, labelled in English for the support inbox.
$support_rows = array(
	array( __( 'Site', 'dicex-connect' ), $support_facts['site'], false ),
	array( __( 'Address', 'dicex-connect' ), $support_facts['address'], true ),
	array( __( 'DiceX Connect', 'dicex-connect' ), $support_facts['plugin'], true ),
	array( __( 'WordPress', 'dicex-connect' ), $support_facts['wordpress'], true ),
	array( __( 'PHP', 'dicex-connect' ), $support_facts['php'], true ),
	array( __( 'WooCommerce', 'dicex-connect' ), '' === $support_facts['woocommerce'] ? __( 'Not active', 'dicex-connect' ) : $support_facts['woocommerce'], '' !== $support_facts['woocommerce'] ),
	array( __( 'Site language', 'dicex-connect' ), $support_facts['language'], true ),
	array( __( 'Region', 'dicex-connect' ), Dicex_Connect_Region::label( $support_facts['region'] ), false ),
	array( __( 'Multisite', 'dicex-connect' ), 'yes' === $support_facts['multisite'] ? $support_yes : $support_no, false ),
	array( __( 'Connected to DiceX', 'dicex-connect' ), 'yes' === $support_facts['connected'] ? $support_yes : $support_no, false ),
);
?>
<div class="dicex-connect-card dicex-connect-support">
	<h3><?php esc_html_e( 'Write to DiceX support', 'dicex-connect' ); ?></h3>
	<p>
		<?php
		$support_link = '<a href="' . esc_url( 'mailto:' . Dicex_Connect_Support::ADDRESS ) . '" dir="ltr">' . esc_html( Dicex_Connect_Support::ADDRESS ) . '</a>';

		printf(
			/* translators: %s: the support email address, as a link */
			esc_html__( 'A question, a problem, or an idea for the plugin? This form emails DiceX support at %s through your site\'s own mail, and the answer comes to the address you give.', 'dicex-connect' ),
			$support_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above, then wrapped in a link this file owns.
		);
		?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="dicex-connect-support-email"><?php esc_html_e( 'Your email', 'dicex-connect' ); ?></label></th>
			<td>
				<input type="email" id="dicex-connect-support-email" class="regular-text ltr" dir="ltr"
					value="<?php echo esc_attr( $support_user->user_email ); ?>"
					autocomplete="email" maxlength="254" required
					aria-describedby="dicex-connect-support-email-hint">
				<p class="description" id="dicex-connect-support-email-hint"><?php esc_html_e( 'DiceX support answers here.', 'dicex-connect' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-support-subject"><?php esc_html_e( 'Subject', 'dicex-connect' ); ?></label></th>
			<td>
				<input type="text" id="dicex-connect-support-subject" class="large-text"
					maxlength="<?php echo esc_attr( Dicex_Connect_Support::SUBJECT_MAX ); ?>" required>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-support-message"><?php esc_html_e( 'Message', 'dicex-connect' ); ?></label></th>
			<td>
				<textarea id="dicex-connect-support-message" class="large-text" rows="8"
					maxlength="<?php echo esc_attr( Dicex_Connect_Support::MESSAGE_MAX ); ?>" required
					aria-describedby="dicex-connect-support-message-hint"></textarea>
				<p class="description" id="dicex-connect-support-message-hint"><?php esc_html_e( 'What you did, what you expected, and what happened instead.', 'dicex-connect' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Log', 'dicex-connect' ); ?></th>
			<td>
				<label for="dicex-connect-support-log">
					<input type="checkbox" id="dicex-connect-support-log" checked
						aria-describedby="dicex-connect-support-log-hint">
					<?php esc_html_e( 'Attach the plugin\'s log', 'dicex-connect' ); ?>
				</label>
				<p class="description" id="dicex-connect-support-log-hint">
					<?php
					printf(
						/* translators: %s: how many events the log keeps */
						esc_html__( 'Its last %s events: messages sent, with numbers partly hidden, errors, and sign-in attempts. Email and IP addresses in it are shortened before it goes.', 'dicex-connect' ),
						esc_html( number_format_i18n( Dicex_Connect_Logger::MAX_ENTRIES ) )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dicex-connect-logs' ) ); ?>"><?php esc_html_e( 'Read the log', 'dicex-connect' ); ?></a>
				</p>
			</td>
		</tr>
	</table>

	<h4 class="dicex-connect-summary-heading">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<?php esc_html_e( 'Sent with every message', 'dicex-connect' ); ?>
	</h4>
	<p class="description"><?php esc_html_e( 'So DiceX support knows which site is writing without having to ask. Your API key is never sent.', 'dicex-connect' ); ?></p>

	<table class="widefat striped dicex-connect-support-facts">
		<tbody>
			<?php foreach ( $support_rows as $support_row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $support_row[0] ); ?></th>
					<td>
						<?php if ( $support_row[2] ) : ?>
							<bdi dir="ltr"><?php echo esc_html( $support_row[1] ); ?></bdi>
						<?php else : ?>
							<?php echo esc_html( $support_row[1] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="submit">
		<button type="button" id="dicex-connect-support-send" class="button button-primary">
			<?php esc_html_e( 'Send to DiceX support', 'dicex-connect' ); ?>
		</button>
	</p>

	<div id="dicex-connect-support-result" class="<?php echo esc_attr( $support_wait > 0 ? 'notice notice-warning' : '' ); ?>">
		<?php if ( $support_wait > 0 ) : ?>
			<p><?php echo esc_html( Dicex_Connect_Support::wait_message( $support_wait ) ); ?></p>
		<?php endif; ?>
	</div>

	<p class="description">
		<?php
		printf(
			/* translators: %s: how many messages a site may send in a day */
			esc_html__( 'To keep this form from being misused, a site can send one message a minute and %s a day.', 'dicex-connect' ),
			esc_html( number_format_i18n( Dicex_Connect_Support::PER_DAY ) )
		);
		?>
	</p>
</div>
