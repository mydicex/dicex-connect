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

$recipient_labels = array(
	Dicex_Connect_Recipients::TYPE_ADMIN_LIST => __( 'Numbers in the admin list, further down this page', 'dicex-connect' ),
	Dicex_Connect_Recipients::TYPE_WP_ADMINS  => __( 'WordPress administrators who added a mobile number to their profile', 'dicex-connect' ),
	Dicex_Connect_Recipients::TYPE_SUBJECT    => __( 'The user or customer the event is about', 'dicex-connect' ),
);

$admin_numbers = Dicex_Connect_Recipients::get_admin_list();
$integrations  = Dicex_Connect_Integration_Registry::definitions();
$enable_block  = Dicex_Connect_Integration_Registry::enable_blocker();
?>
<div class="dicex-connect-card">
	<p><?php esc_html_e( 'Every plugin DiceX can notify on has a card here. Switch a card on to use its settings.', 'dicex-connect' ); ?></p>

	<?php if ( ! empty( $enable_block ) ) : ?>
		<p class="dicex-connect-region-note dicex-connect-enable-block" id="dicex-connect-enable-block">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<span>
				<span id="dicex-connect-enable-block-text"><?php echo esc_html( $enable_block['text'] ); ?></span>
				<a href="<?php echo esc_url( $enable_block['url'] ); ?>"><?php echo esc_html( $enable_block['label'] ); ?></a>
			</span>
		</p>
	<?php endif; ?>

	<div class="dicex-connect-recipients-box">
		<h3><span class="dashicons dashicons-groups"></span><?php esc_html_e( 'Admin numbers', 'dicex-connect' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'One number per line. This list is shared by every card that sends to the admin list.', 'dicex-connect' ); ?>
			<?php echo esc_html( Dicex_Connect_Mobile::format_hint() ); ?>
		</p>
		<textarea id="dicex-connect-admin-recipients" class="ltr" dir="ltr" rows="3" placeholder="<?php echo esc_attr( Dicex_Connect_Mobile::example() ); ?>"><?php echo esc_textarea( implode( "\n", array_map( array( 'Dicex_Connect_Mobile', 'display' ), $admin_numbers ) ) ); ?></textarea>
		<p>
			<button type="button" class="button button-secondary" id="dicex-connect-save-recipients"><?php esc_html_e( 'Save numbers', 'dicex-connect' ); ?></button>
			<span class="dicex-connect-recipients-status" id="dicex-connect-recipients-status">
				<?php
				printf(
					/* translators: %s: how many admin numbers are stored */
					esc_html( _n( '%s number stored.', '%s numbers stored.', count( $admin_numbers ), 'dicex-connect' ) ),
					esc_html( number_format_i18n( count( $admin_numbers ) ) )
				);
				?>
			</span>
		</p>
	</div>

	<div class="dicex-connect-integration-grid">
		<?php
		foreach ( $integrations as $slug => $integration ) :
			$available = Dicex_Connect_Integration_Registry::is_available( $slug );
			$enabled   = Dicex_Connect_Integration_Registry::is_enabled( $slug );
			$settings  = Dicex_Connect_Integration_Registry::get_settings( $slug );
			$field_id  = 'dicex-connect-toggle-' . $slug;

			/*
			 * A card that could be switched on, but not until the profile has a
			 * number, keeps a switch that can be focused and pressed: admin.js
			 * then says why on the card itself. Disabled, it did nothing at all,
			 * and the reason sat only at the top of the tab. A card whose plugin
			 * is missing stays disabled — its "Not installed" badge is the reason.
			 */
			$blocked = $available && ! $enabled && ! empty( $enable_block );
			?>
			<div class="dicex-connect-integration-card<?php echo $available ? '' : ' is-unavailable'; ?><?php echo $enabled ? ' is-enabled' : ''; ?>" data-slug="<?php echo esc_attr( $slug ); ?>">
				<div class="dicex-connect-integration-head">
					<?php if ( ! empty( $integration['logo'] ) ) : ?>
						<img class="dicex-connect-integration-logo" src="<?php echo esc_url( DICEX_CONNECT_URL . $integration['logo'] ); ?>" alt="">
					<?php else : ?>
						<span class="dashicons <?php echo esc_attr( $integration['icon'] ); ?>"></span>
					<?php endif; ?>
					<div class="dicex-connect-integration-title">
						<strong><?php echo esc_html( $integration['label'] ); ?></strong>
						<?php if ( ! $available ) : ?>
							<span class="dicex-connect-badge-off"><?php esc_html_e( 'Not installed', 'dicex-connect' ); ?></span>
						<?php endif; ?>
					</div>
					<label class="dicex-connect-switch" for="<?php echo esc_attr( $field_id ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" class="dicex-connect-integration-toggle"
							data-slug="<?php echo esc_attr( $slug ); ?>"
							<?php checked( $enabled ); ?>
							<?php disabled( ! $available ); ?>
							<?php if ( $blocked ) : ?>
								aria-disabled="true" aria-describedby="dicex-connect-enable-block-text"
							<?php endif; ?>>
						<span class="dicex-connect-switch-track" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( $integration['label'] ); ?></span>
					</label>
				</div>

				<p class="dicex-connect-integration-desc"><?php echo esc_html( $integration['description'] ); ?></p>

				<ul class="dicex-connect-integration-events">
					<?php foreach ( $integration['events'] as $event ) : ?>
						<li><?php echo esc_html( $event ); ?></li>
					<?php endforeach; ?>
				</ul>

				<div class="dicex-connect-integration-settings" <?php echo esc_attr( $enabled ? '' : 'hidden' ); ?>>
					<?php
					/*
					 * Sending order. Chosen channels first, in the order the admin put
					 * them in, then the rest — so the list itself is the priority.
					 */
					$chosen = array();
					foreach ( (array) $settings['channels'] as $ordered_channel ) {
						if ( in_array( $ordered_channel, $region_channels, true ) ) {
							$chosen[] = $ordered_channel;
						}
					}
					$rest  = array_diff( $region_channels, $chosen );
					$order = array_merge( $chosen, $rest );
					?>
					<?php if ( empty( $region_channels ) ) : ?>
						<p class="dicex-connect-region-note">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<?php
							$card_region_note = Dicex_Connect_Region::note();
							echo esc_html( '' !== $card_region_note ? $card_region_note : __( 'No messaging channel is available in the region this account is set to.', 'dicex-connect' ) );
							?>
						</p>
					<?php endif; ?>

					<fieldset class="dicex-connect-recipient-set" <?php echo esc_attr( empty( $region_channels ) ? 'hidden' : '' ); ?>>
						<legend><?php esc_html_e( 'Send over, in this order', 'dicex-connect' ); ?></legend>
						<p class="description"><?php esc_html_e( 'Drag to reorder, or use the arrows. If the first one does not go through, the next is tried.', 'dicex-connect' ); ?></p>
						<ol class="dicex-connect-channel-list">
							<?php
							foreach ( $order as $channel_key ) :
								$channel_line = Dicex_Connect_Lines::get_selected( $channel_key );
								?>
								<li data-channel="<?php echo esc_attr( $channel_key ); ?>">
									<span class="dashicons dashicons-menu dicex-connect-channel-handle" aria-hidden="true"></span>
									<label>
										<input type="checkbox" class="dicex-connect-integration-channel" value="<?php echo esc_attr( $channel_key ); ?>"
											<?php checked( in_array( $channel_key, $chosen, true ) ); ?>>
										<?php
										/*
										 * Only worth flagging when the channel genuinely cannot
										 * send. SMS, voice and Safir fall back to the DiceX
										 * shared line, so nagging about them would be noise.
										 */
										if ( '' === $channel_line && ! Dicex_Connect_Lines::has_shared_line( $channel_key ) ) {
											printf(
												/* translators: %s: channel name such as WhatsApp */
												esc_html__( '%s — no line yet', 'dicex-connect' ),
												esc_html( $channel_labels[ $channel_key ] )
											);
										} else {
											echo esc_html( $channel_labels[ $channel_key ] );
										}
										?>
									</label>
									<?php Dicex_Connect_Settings_Page::channel_order_buttons( $channel_labels[ $channel_key ] ); ?>
								</li>
							<?php endforeach; ?>
						</ol>
					</fieldset>

					<?php
					/*
					 * Extra fields an integration declares for itself — WooCommerce picks
					 * order statuses, the login card picks its mode. Rendered from the
					 * registry, so a new card needs no edit here.
					 */
					foreach ( Dicex_Connect_Integration_Registry::fields( $slug ) as $field_key => $field ) :
						if ( empty( $field['options'] ) ) {
							continue;
						}

						if ( 'select' === $field['type'] ) :
							$field_value = isset( $settings[ $field_key ] ) ? (string) $settings[ $field_key ] : '';
							$field_id    = 'dicex-connect-field-' . $slug . '-' . $field_key;
							?>
							<p>
								<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
								<select id="<?php echo esc_attr( $field_id ); ?>" class="dicex-connect-integration-field-select" data-field="<?php echo esc_attr( $field_key ); ?>">
									<?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
										<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $field_value, (string) $option_value ); ?>>
											<?php echo esc_html( $option_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
							<?php
							continue;
						endif;

						if ( 'checkboxes' !== $field['type'] ) {
							continue;
						}

						$field_selected = isset( $settings[ $field_key ] ) ? array_map( 'strval', (array) $settings[ $field_key ] ) : array();
						?>
						<fieldset class="dicex-connect-recipient-set dicex-connect-field-set" data-field="<?php echo esc_attr( $field_key ); ?>">
							<legend><?php echo esc_html( $field['label'] ); ?></legend>
							<?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
								<label>
									<input type="checkbox" class="dicex-connect-integration-field" value="<?php echo esc_attr( $option_value ); ?>"
										<?php checked( in_array( (string) $option_value, $field_selected, true ) ); ?>>
									<?php echo esc_html( $option_label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endforeach; ?>

					<?php if ( empty( $integration['hide_recipients'] ) ) : ?>
						<fieldset class="dicex-connect-recipient-set">
						<legend><?php esc_html_e( 'Recipients', 'dicex-connect' ); ?></legend>
						<?php foreach ( $recipient_labels as $type => $recipient_label ) : ?>
							<label>
								<input type="checkbox" class="dicex-connect-integration-recipient" value="<?php echo esc_attr( $type ); ?>"
									<?php checked( in_array( $type, (array) $settings['recipients'], true ) ); ?>>
								<?php echo esc_html( $recipient_label ); ?>
							</label>
						<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>

					<?php if ( empty( $integration['hide_template'] ) ) : ?>
					<p>
						<label for="dicex-connect-template-<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Message text', 'dicex-connect' ); ?></label>
						<textarea id="dicex-connect-template-<?php echo esc_attr( $slug ); ?>" class="dicex-connect-integration-template" rows="3"><?php echo esc_textarea( $settings['template'] ); ?></textarea>
						<span class="dicex-connect-tag-list">
							<?php esc_html_e( 'Tags you can use:', 'dicex-connect' ); ?>
							<?php foreach ( $integration['tags'] as $tag ) : ?>
								<code dir="ltr"><?php echo esc_html( $tag ); ?></code>
							<?php endforeach; ?>
						</span>
					</p>
					<?php endif; ?>

					<?php if ( ! empty( $integration['note'] ) ) : ?>
						<p class="description dicex-connect-integration-hint"><?php echo esc_html( $integration['note'] ); ?></p>
					<?php endif; ?>

					<div class="dicex-connect-integration-status">
						<?php foreach ( Dicex_Connect_Integration_Registry::status_notes( $slug ) as $status_note ) : ?>
							<p class="dicex-connect-integration-warning">
								<span class="dashicons dashicons-warning"></span>
								<?php echo esc_html( $status_note['text'] ); ?>
								<?php if ( ! empty( $status_note['url'] ) ) : ?>
									<a href="<?php echo esc_url( $status_note['url'] ); ?>"><?php echo esc_html( $status_note['label'] ); ?></a>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</div>

					<p class="dicex-connect-integration-actions">
						<button type="button" class="button button-secondary dicex-connect-save-integration"><?php esc_html_e( 'Save this card', 'dicex-connect' ); ?></button>
						<span class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
					</p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="description dicex-connect-integration-note">
		<?php esc_html_e( 'Cards only listen for events. They change nothing in the plugins themselves, and a failed DiceX send never breaks your site.', 'dicex-connect' ); ?>
	</p>
</div>
