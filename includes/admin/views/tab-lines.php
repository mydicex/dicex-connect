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

$channel_labels  = Dicex_Connect_Lines::labels();
$region_channels = Dicex_Connect_Region::channels();

$sms_lines = Dicex_Connect_Credit::get_sms_lines();
$providers = Dicex_Connect_Credit::get_social_providers();

$sms_error      = is_wp_error( $sms_lines ) ? $sms_lines->get_error_message() : '';
$provider_error = is_wp_error( $providers ) ? $providers->get_error_message() : '';

// Ordered by this plugin's own channel order, and limited to providers it can map.
$social_providers = array();
if ( is_array( $providers ) ) {
	foreach ( Dicex_Connect_Lines::PROVIDER_CHANNELS as $provider => $provider_channel ) {
		if ( in_array( $provider, $providers, true ) ) {
			$social_providers[ $provider ] = $provider_channel;
		}
	}
}

$sms_selected = Dicex_Connect_Lines::get_selected( 'sms' );

// One table for every channel, SMS included, so all the configured lines are in
// one place instead of SMS sitting apart from the rest.
$summary_channels = array( 'sms' );
foreach ( $social_providers as $provider_channel ) {
	if ( ! in_array( $provider_channel, $summary_channels, true ) ) {
		$summary_channels[] = $provider_channel;
	}
}

// A channel that already has a line belongs in the table even when the provider
// list did not come back — otherwise an API hiccup makes a saved line look lost.
foreach ( Dicex_Connect_Lines::SUPPORTED_CHANNELS as $supported_channel ) {
	if ( ! in_array( $supported_channel, $summary_channels, true ) && '' !== Dicex_Connect_Lines::get_selected( $supported_channel ) ) {
		$summary_channels[] = $supported_channel;
	}
}

// The test box offers SMS plus whatever social channels this account actually has,
// so a channel the gateway never returned as a provider is never offered for a test.
$test_channels = array( 'sms' );
foreach ( $social_providers as $provider_channel ) {
	if ( ! in_array( $provider_channel, $test_channels, true ) ) {
		$test_channels[] = $provider_channel;
	}
}

// If the provider list could not be loaded, a channel that already has a saved
// line is still worth offering — otherwise an API hiccup hides it from testing.
foreach ( Dicex_Connect_Lines::SUPPORTED_CHANNELS as $supported_channel ) {
	if ( ! in_array( $supported_channel, $test_channels, true ) && '' !== Dicex_Connect_Lines::get_selected( $supported_channel ) ) {
		$test_channels[] = $supported_channel;
	}
}

// Everything above works out what the account has; the region decides what it
// may use. Filtering once, here, keeps the three lists from drifting apart.
$summary_channels = array_values( array_intersect( $summary_channels, $region_channels ) );
$test_channels    = array_values( array_intersect( $test_channels, $region_channels ) );

$social_providers = array_filter(
	$social_providers,
	static function ( $provider_channel ) use ( $region_channels ) {
		return in_array( $provider_channel, $region_channels, true );
	}
);
?>
<div class="dicex-connect-card">
<?php if ( empty( $region_channels ) ) : ?>
	<p class="dicex-connect-region-note">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<?php
		$region_note = Dicex_Connect_Region::note();
		echo esc_html( '' !== $region_note ? $region_note : __( 'No messaging channel is available in the region this account is set to.', 'dicex-connect' ) );
		?>
	</p>
<?php else : ?>
	<p><?php esc_html_e( 'Choose the sender line for each channel on your DiceX account. One line per channel.', 'dicex-connect' ); ?></p>

	<?php if ( '' !== $sms_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $sms_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( in_array( 'sms', $region_channels, true ) ) : ?>
	<table class="form-table dicex-connect-lines-table" role="presentation">
		<tr data-channel="sms">
			<th scope="row"><?php echo esc_html( $channel_labels['sms'] ); ?></th>
			<td>
				<?php if ( is_array( $sms_lines ) && ! empty( $sms_lines ) ) : ?>
					<select class="regular-text ltr dicex-connect-line-input" dir="ltr" data-channel="sms">
						<option value=""><?php esc_html_e( 'Shared line', 'dicex-connect' ); ?></option>
						<?php foreach ( $sms_lines as $sms_line ) : ?>
							<option value="<?php echo esc_attr( $sms_line ); ?>" <?php selected( $sms_selected, $sms_line ); ?>><?php echo esc_html( $sms_line ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="text" class="regular-text ltr dicex-connect-line-input" dir="ltr"
						data-channel="sms"
						value="<?php echo esc_attr( $sms_selected ); ?>"
						placeholder="<?php esc_attr_e( 'Line number or sender ID', 'dicex-connect' ); ?>">
				<?php endif; ?>
				<span class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
			</td>
		</tr>
	</table>
	<?php endif; ?>

	<div class="dicex-connect-social-lines">
		<h3><span class="dashicons dashicons-share-alt2"></span><?php esc_html_e( 'Social network lines', 'dicex-connect' ); ?></h3>

		<?php if ( '' !== $provider_error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $provider_error ); ?></p></div>
		<?php elseif ( empty( $social_providers ) ) : ?>
			<p class="description"><?php esc_html_e( 'No networks are enabled on this DiceX account.', 'dicex-connect' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Choose a network, then its line. The choice is saved straight away.', 'dicex-connect' ); ?></p>
			<p>
				<select id="dicex-connect-social-provider">
					<option value=""><?php esc_html_e( 'Choose a network', 'dicex-connect' ); ?></option>
					<?php foreach ( $social_providers as $provider => $provider_channel ) : ?>
						<option value="<?php echo esc_attr( $provider ); ?>" data-channel="<?php echo esc_attr( $provider_channel ); ?>">
							<?php echo esc_html( isset( $channel_labels[ $provider_channel ] ) ? $channel_labels[ $provider_channel ] : $provider ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select id="dicex-connect-social-lines" disabled>
					<option><?php esc_html_e( 'Choose a network first', 'dicex-connect' ); ?></option>
				</select>
				<span id="dicex-connect-social-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
			</p>

		<?php endif; ?>

		<p class="description dicex-connect-default-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<?php esc_html_e( 'SMS, voice calls and Safir can go out on the DiceX shared line, so choose a line for them only when you want a particular one. WhatsApp, Telegram Bot and Bale Bot have no shared line: activate one for them in your DiceX panel first, then pick it here.', 'dicex-connect' ); ?>
		</p>
	</div>

	<h3 class="dicex-connect-summary-heading"><span class="dashicons dashicons-list-view"></span><?php esc_html_e( 'Lines in use', 'dicex-connect' ); ?></h3>

	<table class="widefat striped dicex-connect-social-summary">
		<thead>
			<tr>
				<th scope="col" class="dicex-connect-summary-state"><span class="screen-reader-text"><?php esc_html_e( 'Ready to send', 'dicex-connect' ); ?></span></th>
				<th scope="col"><?php esc_html_e( 'Channel', 'dicex-connect' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Line', 'dicex-connect' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $summary_channels as $summary_channel ) :
				$selected_line = Dicex_Connect_Lines::get_selected( $summary_channel );
				$selected_name = Dicex_Connect_Lines::get_label( $summary_channel );
				/*
				 * The tick means "this channel will send". A line of its own does
				 * that, and so does the DiceX shared line — but only SMS, voice and
				 * Safir have one. An empty WhatsApp, Telegram or Bale row is a real
				 * gap, and saying so here is the whole point of the column.
				 */
				$has_own_line = ( '' !== $selected_line );
				$shared_line  = Dicex_Connect_Lines::has_shared_line( $summary_channel );
				$ready        = ( $has_own_line || $shared_line );
				?>
				<tr data-channel="<?php echo esc_attr( $summary_channel ); ?>" class="<?php echo esc_attr( $ready ? 'is-configured' : 'is-unset' ); ?>">
					<td class="dicex-connect-summary-state">
						<span class="dashicons <?php echo esc_attr( $ready ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>
					</td>
					<td><?php echo esc_html( isset( $channel_labels[ $summary_channel ] ) ? $channel_labels[ $summary_channel ] : $summary_channel ); ?></td>
					<td class="dicex-connect-summary-line">
						<?php if ( $has_own_line ) : ?>
							<span class="dicex-connect-summary-number" dir="ltr"><?php echo esc_html( $selected_line ); ?></span>
							<?php if ( '' !== $selected_name ) : ?>
								<em class="dicex-connect-summary-name"><?php echo esc_html( $selected_name ); ?></em>
							<?php endif; ?>
						<?php elseif ( $shared_line ) : ?>
							<span class="dicex-connect-summary-default"><?php esc_html_e( 'Shared line', 'dicex-connect' ); ?></span>
						<?php else : ?>
							<span class="dicex-connect-summary-missing"><?php esc_html_e( 'No line yet — activate one in your DiceX panel', 'dicex-connect' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="dicex-connect-test-box">
		<h3><?php esc_html_e( 'Test send', 'dicex-connect' ); ?></h3>
		<p>
			<select id="dicex-connect-test-channel">
				<?php
				foreach ( $test_channels as $channel_key ) :
					$test_line = Dicex_Connect_Lines::get_selected( $channel_key );

					/*
					 * Both wordings travel with the option so the script can swap
					 * them the moment a line is saved, without the page being
					 * reloaded and without a translated string having to live in
					 * JavaScript. Only two are ever needed: whether a channel falls
					 * back to the shared line is fixed, so the "unset" wording for
					 * this channel never changes.
					 */
					$test_label_set = $channel_labels[ $channel_key ];

					if ( Dicex_Connect_Lines::has_shared_line( $channel_key ) ) {
						$test_label_unset = sprintf(
							/* translators: %s: channel name, for example SMS */
							__( '%s — shared line', 'dicex-connect' ),
							$channel_labels[ $channel_key ]
						);
					} else {
						$test_label_unset = sprintf(
							/* translators: %s: channel name, for example WhatsApp */
							__( '%s — no line yet', 'dicex-connect' ),
							$channel_labels[ $channel_key ]
						);
					}
					?>
					<option value="<?php echo esc_attr( $channel_key ); ?>"
						data-label-set="<?php echo esc_attr( $test_label_set ); ?>"
						data-label-unset="<?php echo esc_attr( $test_label_unset ); ?>">
						<?php echo esc_html( '' !== $test_line ? $test_label_set : $test_label_unset ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<input type="text" id="dicex-connect-test-target" class="regular-text ltr" dir="ltr" placeholder="<?php esc_attr_e( 'Destination mobile number', 'dicex-connect' ); ?>">
			<button type="button" id="dicex-connect-test-send" class="button button-secondary"><?php esc_html_e( 'Send test', 'dicex-connect' ); ?></button>
		</p>
		<div id="dicex-connect-test-result"></div>
	</div>
<?php endif; ?>
</div>
