<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * A template, never loaded on its own — see views/settings-page.php for why the
 * variables below are function-scoped rather than globals.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Function-scoped template variables; see the note above.

$club          = Dicex_Connect_Club_Settings::get();
$club_on       = ! empty( $club['enabled'] );
$club_woo      = Dicex_Connect_Club_Sources::has_woocommerce();
$club_has_key  = Dicex_Connect_Api_Client::has_api_key();
$club_status   = Dicex_Connect_Club_Page::status_data();
$club_currency = $club_woo ? get_woocommerce_currency() : '';
$club_labels   = Dicex_Connect_Lines::labels();
$club_region   = Dicex_Connect_Region::channels();

$club_bound_row = function ( $level, $bound_min, $bound_max, $label, $unit, $inputmode ) {
	// Named for its condition, so "From" and "To" are heard as that condition's.
	$club_group_name = '' === $unit ? $label : sprintf(
		/* translators: 1: a level condition, such as Purchases or Age. 2: its unit, such as IRR, days or years. */
		__( '%1$s (%2$s)', 'dicex-connect' ),
		$label,
		$unit
	);
	?>
	<div class="dicex-connect-club-bound" role="group" aria-label="<?php echo esc_attr( $club_group_name ); ?>">
		<span class="dicex-connect-club-bound-label"><?php echo esc_html( $label ); ?></span>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'From', 'dicex-connect' ); ?></span>
			<input type="text" class="dicex-connect-club-input" data-key="<?php echo esc_attr( $bound_min ); ?>" inputmode="<?php echo esc_attr( $inputmode ); ?>" dir="ltr"
				placeholder="<?php esc_attr_e( 'from', 'dicex-connect' ); ?>" value="<?php echo esc_attr( isset( $level[ $bound_min ] ) ? $level[ $bound_min ] : '' ); ?>">
		</label>
		<span aria-hidden="true">–</span>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'To', 'dicex-connect' ); ?></span>
			<input type="text" class="dicex-connect-club-input" data-key="<?php echo esc_attr( $bound_max ); ?>" inputmode="<?php echo esc_attr( $inputmode ); ?>" dir="ltr"
				placeholder="<?php esc_attr_e( 'to', 'dicex-connect' ); ?>" value="<?php echo esc_attr( isset( $level[ $bound_max ] ) ? $level[ $bound_max ] : '' ); ?>">
		</label>
		<?php if ( '' !== $unit ) : ?>
			<span class="dicex-connect-club-unit" dir="ltr"><?php echo esc_html( $unit ); ?></span>
		<?php endif; ?>
	</div>
	<?php
};

/*
 * A level's lists. Each is a hidden field holding JSON, a button that says what is
 * chosen, and one panel per level that club.js fills with the right picker.
 */
$club_lists = array(
	'categories' => __( 'Bought from categories', 'dicex-connect' ),
	'products'   => __( 'Bought products', 'dicex-connect' ),
);

if ( taxonomy_exists( 'product_brand' ) ) {
	$club_lists['brands'] = __( 'Bought from brands', 'dicex-connect' );
}

$club_lists['countries'] = __( "Buyer's country", 'dicex-connect' );
$club_lists['cities']    = __( "Buyer's city", 'dicex-connect' );

$club_locales = Dicex_Connect_Club_Language::available();

/*
 * A level and a group are edited the same way: the same conditions, a name of its
 * own and a description. Only a level is in a list whose order matters, and only
 * a level carries the message for somebody who has just moved up into it.
 */
$club_entry_row = function ( $level, $kind ) use ( $club_woo, $club_currency, $club_bound_row, $club_lists, $club_locales ) {
	$is_level = 'level' === $kind;
	?>
	<li class="dicex-connect-club-entry dicex-connect-club-<?php echo esc_attr( $kind ); ?>" data-id="<?php echo esc_attr( $level['id'] ); ?>">
		<div class="dicex-connect-club-level-head">
			<?php if ( $is_level ) : ?>
			<span class="dicex-connect-club-level-order">
				<?php // Named after the level by club.js, which knows it as it is typed. ?>
				<button type="button" class="button-link dicex-connect-club-up">
					<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Move up', 'dicex-connect' ); ?></span>
				</button>
				<button type="button" class="button-link dicex-connect-club-down">
					<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Move down', 'dicex-connect' ); ?></span>
				</button>
			</span>
			<?php endif; ?>
			<label class="dicex-connect-club-level-name">
				<span class="screen-reader-text"><?php echo $is_level ? esc_html__( 'Level name', 'dicex-connect' ) : esc_html__( 'Group name', 'dicex-connect' ); ?></span>
				<input type="text" class="dicex-connect-club-input" data-key="name" maxlength="100"
					placeholder="<?php echo $is_level ? esc_attr__( 'Level name', 'dicex-connect' ) : esc_attr__( 'Group name', 'dicex-connect' ); ?>" value="<?php echo esc_attr( $level['name'] ); ?>">
			</label>
			<span class="dicex-connect-club-level-tags"></span>
			<span class="dicex-connect-club-level-count" hidden></span>
			<button type="button" class="button-link button-link-delete dicex-connect-club-remove">
				<?php esc_html_e( 'Remove', 'dicex-connect' ); ?>
			</button>
		</div>

		<div class="dicex-connect-club-level-body">
			<?php if ( $club_woo ) : ?>
				<?php $club_bound_row( $level, 'spent_min', 'spent_max', __( 'Purchases', 'dicex-connect' ), $club_currency, 'decimal' ); ?>
				<?php $club_bound_row( $level, 'orders_min', 'orders_max', __( 'Orders', 'dicex-connect' ), '', 'numeric' ); ?>
				<?php $club_bound_row( $level, 'idle_min', 'idle_max', __( 'Days since the last purchase', 'dicex-connect' ), __( 'days', 'dicex-connect' ), 'numeric' ); ?>
			<?php endif; ?>
			<?php $club_bound_row( $level, 'age_min', 'age_max', __( 'Age', 'dicex-connect' ), __( 'years', 'dicex-connect' ), 'numeric' ); ?>
			<?php if ( $club_woo ) : ?>
				<div class="dicex-connect-club-lists">
					<?php foreach ( $club_lists as $club_list_key => $club_list_label ) : ?>
						<input type="hidden" class="dicex-connect-club-input" data-key="<?php echo esc_attr( $club_list_key ); ?>" data-list="1"
							value="<?php echo esc_attr( wp_json_encode( array_values( isset( $level[ $club_list_key ] ) ? (array) $level[ $club_list_key ] : array() ) ) ); ?>">
						<button type="button" class="dicex-connect-club-list-button" data-list="<?php echo esc_attr( $club_list_key ); ?>" aria-expanded="false">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<span class="dicex-connect-club-list-name"><?php echo esc_html( $club_list_label ); ?></span>
							<span class="dicex-connect-club-list-summary"></span>
						</button>
					<?php endforeach; ?>
					<div class="dicex-connect-club-list-panel" hidden></div>
				</div>
			<?php endif; ?>
			<label class="dicex-connect-club-description">
				<span class="dicex-connect-club-bound-label"><?php esc_html_e( 'Description', 'dicex-connect' ); ?></span>
				<input type="text" class="dicex-connect-club-input" data-key="description" maxlength="250"
					placeholder="<?php esc_attr_e( 'Optional — shown in your DiceX panel', 'dicex-connect' ); ?>" value="<?php echo esc_attr( $level['description'] ); ?>">
			</label>
			<?php if ( $is_level ) : ?>
				<label class="dicex-connect-club-description dicex-connect-club-message">
					<span class="dicex-connect-club-bound-label"><?php esc_html_e( 'Message on moving up', 'dicex-connect' ); ?></span>
					<textarea class="dicex-connect-club-input" data-key="message" rows="2" maxlength="500"
						placeholder="<?php esc_attr_e( 'Optional — sent when a customer reaches this level from a lower one', 'dicex-connect' ); ?>"><?php echo esc_textarea( isset( $level['message'] ) ? $level['message'] : '' ); ?></textarea>
				</label>
				<?php if ( count( $club_locales ) > 1 ) : ?>
					<div class="dicex-connect-club-languages-wrap">
						<input type="hidden" class="dicex-connect-club-input" data-key="messages" data-map="1"
							value="<?php echo esc_attr( wp_json_encode( (object) ( isset( $level['messages'] ) ? $level['messages'] : array() ) ) ); ?>">
						<button type="button" class="dicex-connect-club-list-button dicex-connect-club-languages" aria-expanded="false">
							<span class="dashicons dashicons-translation" aria-hidden="true"></span>
							<span class="dicex-connect-club-list-name"><?php esc_html_e( 'In another language', 'dicex-connect' ); ?></span>
							<span class="dicex-connect-club-list-summary"></span>
						</button>
						<div class="dicex-connect-club-language-panel" hidden>
							<?php foreach ( $club_locales as $club_locale => $club_locale_name ) : ?>
								<?php $club_lang = str_replace( '_', '-', $club_locale ); ?>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Message on moving up', 'dicex-connect' ); ?></span>
									<span class="dicex-connect-club-bound-label" lang="<?php echo esc_attr( $club_lang ); ?>"><?php echo esc_html( $club_locale_name ); ?></span>
									<textarea rows="2" maxlength="500" lang="<?php echo esc_attr( $club_lang ); ?>" dir="auto" data-locale="<?php echo esc_attr( $club_locale ); ?>"><?php echo esc_textarea( isset( $level['messages'][ $club_locale ] ) ? $level['messages'][ $club_locale ] : '' ); ?></textarea>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<p class="dicex-connect-club-level-hint" hidden></p>
		</div>
	</li>
	<?php
};

$club_level_row = function ( $level ) use ( $club_entry_row ) {
	$club_entry_row( $level, 'level' );
};

$club_group_row = function ( $group ) use ( $club_entry_row ) {
	$club_entry_row( $group, 'group' );
};

$club_blank       = Dicex_Connect_Club_Settings::blank_level();
$club_blank_group = Dicex_Connect_Club_Settings::blank_group();
?>
<div class="dicex-connect-club" id="dicex-connect-club" data-enabled="<?php echo esc_attr( $club_on ? '1' : '0' ); ?>">

	<div class="dicex-connect-card dicex-connect-club-intro">
		<div class="dicex-connect-club-intro-head">
			<div>
				<h2><span class="dashicons dashicons-groups" aria-hidden="true"></span><?php esc_html_e( 'Customer Club', 'dicex-connect' ); ?></h2>
				<p>
					<?php esc_html_e( 'Registers your WordPress users and WooCommerce customers in your DiceX club, each in a level you define. DiceX uses those numbers across its services: messages, online meetings, classes and live commerce.', 'dicex-connect' ); ?>
				</p>
			</div>
			<label class="dicex-connect-switch" for="dicex-connect-club-toggle">
				<input type="checkbox" id="dicex-connect-club-toggle" <?php checked( $club_on ); ?>>
				<span class="dicex-connect-switch-track" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Customer Club', 'dicex-connect' ); ?></span>
			</label>
		</div>

		<?php if ( ! $club_has_key ) : ?>
			<p class="dicex-connect-region-note">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<span>
					<?php esc_html_e( 'Connect your DiceX account first. Nothing can reach the club without an API key.', 'dicex-connect' ); ?>
					<button type="button" class="button-link dicex-connect-goto-tab" data-goto="connection"><?php esc_html_e( 'Open the Connection tab', 'dicex-connect' ); ?></button>
				</span>
			</p>
		<?php endif; ?>

		<?php if ( ! $club_woo ) : ?>
			<p class="dicex-connect-region-note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<span><?php esc_html_e( 'WooCommerce is not active, so the club takes in WordPress users only and levels cannot depend on purchases.', 'dicex-connect' ); ?></span>
			</p>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Nothing is ever deleted from the DiceX club. To take a number out, move it to the level you keep for removed numbers.', 'dicex-connect' ); ?>
		</p>
	</div>

	<div class="dicex-connect-card dicex-connect-club-status" id="dicex-connect-club-status" <?php echo esc_attr( $club_on ? '' : 'hidden' ); ?>>
		<h3><?php esc_html_e( 'Where the club stands', 'dicex-connect' ); ?></h3>

		<div class="dicex-connect-club-tiles">
			<div class="dicex-connect-club-tile">
				<span class="dicex-connect-club-tile-value" data-status="total"><?php echo esc_html( $club_status['total'] ); ?></span>
				<span class="dicex-connect-club-tile-label"><?php esc_html_e( 'Members', 'dicex-connect' ); ?></span>
			</div>
			<div class="dicex-connect-club-tile is-good">
				<span class="dicex-connect-club-tile-value" data-status="synced"><?php echo esc_html( $club_status['synced'] ); ?></span>
				<span class="dicex-connect-club-tile-label"><?php esc_html_e( 'In DiceX', 'dicex-connect' ); ?></span>
			</div>
			<div class="dicex-connect-club-tile">
				<span class="dicex-connect-club-tile-value" data-status="waiting"><?php echo esc_html( $club_status['waiting'] ); ?></span>
				<span class="dicex-connect-club-tile-label"><?php esc_html_e( 'Waiting', 'dicex-connect' ); ?></span>
			</div>
			<div class="dicex-connect-club-tile is-bad">
				<span class="dicex-connect-club-tile-value" data-status="refused"><?php echo esc_html( $club_status['refused'] ); ?></span>
				<span class="dicex-connect-club-tile-label"><?php esc_html_e( 'Refused', 'dicex-connect' ); ?></span>
			</div>
		</div>

		<ul class="dicex-connect-club-level-counts" id="dicex-connect-club-level-counts">
			<?php foreach ( $club_status['levels'] as $club_count ) : ?>
				<li><span><?php echo esc_html( $club_count['name'] ); ?></span> <strong><?php echo esc_html( $club_count['members'] ); ?></strong></li>
			<?php endforeach; ?>
		</ul>

		<div class="dicex-connect-club-import">
			<progress id="dicex-connect-club-progress" aria-labelledby="dicex-connect-club-import-text" max="<?php echo esc_attr( max( 1, $club_status['import_total'] ) ); ?>" value="<?php echo esc_attr( $club_status['import_done'] ); ?>" <?php echo esc_attr( $club_status['importing'] ? '' : 'hidden' ); ?>></progress>
			<?php // Focus lands here when the button that was used goes away — after sending, or retrying the last refusal. ?>
			<p class="description" id="dicex-connect-club-import-text" tabindex="-1"><?php echo esc_html( $club_status['import_text'] ); ?></p>
			<p class="dicex-connect-region-note" id="dicex-connect-club-import-prompt" <?php echo esc_attr( ( $club_on && ! $club_status['imported_once'] && ! $club_status['importing'] ) ? '' : 'hidden' ); ?>>
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Your existing customers are not in the club yet. Import them once; everyone after that joins on their own. Nothing is sent until you have seen who lands in which level.', 'dicex-connect' ); ?></span>
			</p>
		</div>

		<div class="dicex-connect-club-hold" id="dicex-connect-club-hold" <?php echo esc_attr( $club_status['hold'] && ! $club_status['importing'] ? '' : 'hidden' ); ?>>
			<p>
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Your customers are read and placed in levels, as counted above. Nothing has been sent to DiceX yet: a number that reaches the club stays there. Change the levels and save if the counts are not what you want, then send.', 'dicex-connect' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="dicex-connect-club-release"><?php esc_html_e( 'Send to DiceX', 'dicex-connect' ); ?></button>
			</p>
		</div>

		<p class="dicex-connect-region-note dicex-connect-club-paused" id="dicex-connect-club-paused" <?php echo esc_attr( '' === $club_status['paused'] ? 'hidden' : '' ); ?>>
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<span><?php echo esc_html( $club_status['paused'] ); ?></span>
		</p>

		<p class="dicex-connect-club-actions">
			<?php // aria-disabled rather than disabled: a disabled button drops the keyboard focus it holds. ?>
			<button type="button" class="button button-primary" id="dicex-connect-club-import" <?php echo $club_status['importing'] ? 'aria-disabled="true"' : ''; ?>><?php esc_html_e( 'Import existing customers', 'dicex-connect' ); ?></button>
			<button type="button" class="button button-secondary" id="dicex-connect-club-sync"><?php esc_html_e( 'Sync now', 'dicex-connect' ); ?></button>
			<button type="button" class="button button-secondary" id="dicex-connect-club-retry" <?php echo esc_attr( $club_status['refused_count'] > 0 ? '' : 'hidden' ); ?>><?php esc_html_e( 'Send refused customers again', 'dicex-connect' ); ?></button>
			<button type="button" class="button-link" id="dicex-connect-club-remote"><?php esc_html_e( 'Compare with DiceX', 'dicex-connect' ); ?></button>
		</p>

		<p class="description dicex-connect-club-times">
			<span><?php esc_html_e( 'Last sent:', 'dicex-connect' ); ?> <strong id="dicex-connect-club-last-sent"><?php echo esc_html( $club_status['last_sent'] ); ?></strong></span>
			<span id="dicex-connect-club-next-daily-wrap" <?php echo esc_attr( '' === $club_status['next_daily'] ? 'hidden' : '' ); ?>>
				<?php esc_html_e( 'Next daily sync:', 'dicex-connect' ); ?> <strong id="dicex-connect-club-next-daily"><?php echo esc_html( $club_status['next_daily'] ); ?></strong>
			</span>
		</p>

		<div class="dicex-connect-club-group-status" id="dicex-connect-club-groups-wrap" <?php echo esc_attr( empty( $club_status['groups'] ) ? 'hidden' : '' ); ?>>
			<h4><?php esc_html_e( 'Contact groups', 'dicex-connect' ); ?></h4>
			<ul class="dicex-connect-club-level-counts" id="dicex-connect-club-group-counts">
				<?php foreach ( $club_status['groups'] as $club_group_count ) : ?>
					<li>
						<span><?php echo esc_html( $club_group_count['name'] ); ?></span>
						<strong><?php echo esc_html( $club_group_count['members'] ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="dicex-connect-club-remote" id="dicex-connect-club-remote-list" hidden></div>
	</div>

	<div class="dicex-connect-card dicex-connect-club-settings">
		<h3><?php esc_html_e( 'Levels', 'dicex-connect' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Name your levels and set what it takes to be in each. A customer joins the first level, from the top, whose conditions they all meet. Leave a box empty for no limit.', 'dicex-connect' ); ?>
			<?php if ( $club_woo ) : ?>
				<?php esc_html_e( 'A level can also ask what a customer bought — from which categories or brands, which products — and where they buy from. Inside one list, any single entry is enough.', 'dicex-connect' ); ?>
				<?php esc_html_e( 'Days since the last purchase are whole days, and move customers on their own as time passes; somebody who never bought anything does not meet them.', 'dicex-connect' ); ?>
			<?php endif; ?>
			<?php esc_html_e( 'Age is counted in whole years from the date of birth, in the calendar the customer reads — Solar Hijri in Persian — and moves customers on their birthday. Somebody whose date of birth the club does not know meets no age condition.', 'dicex-connect' ); ?>
			<?php if ( $club_woo ) : ?>
				<?php
				printf(
					/* translators: %s: the store's currency code, such as IRR or OMR */
					esc_html__( 'Purchases are counted in your store currency, %s, after refunds, from paid orders only.', 'dicex-connect' ),
					'<span dir="ltr">' . esc_html( $club_currency ) . '</span>'
				);
				?>
			<?php endif; ?>
		</p>
		<p class="dicex-connect-region-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<span><?php esc_html_e( 'DiceX keeps a level name for good. Renaming a level here creates a new level in DiceX and moves its customers there; the old name stays behind, empty.', 'dicex-connect' ); ?></span>
		</p>

		<ol class="dicex-connect-club-levels" id="dicex-connect-club-levels">
			<?php
			foreach ( $club['levels'] as $club_level ) {
				$club_level_row( $club_level );
			}
			?>
		</ol>

		<template id="dicex-connect-club-level-template">
			<?php $club_level_row( $club_blank ); ?>
		</template>

		<p class="dicex-connect-club-level-actions">
			<button type="button" class="button button-secondary" id="dicex-connect-club-add-level">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add a level', 'dicex-connect' ); ?>
			</button>
			<button type="button" class="button-link" id="dicex-connect-club-starter" <?php echo esc_attr( empty( $club['levels'] ) ? '' : 'hidden' ); ?>>
				<?php if ( $club_woo ) : ?>
					<?php esc_html_e( 'Start with Inactive, Gold, Silver, Bronze, Regular member and Removed', 'dicex-connect' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Start with Gold, Silver, Bronze, Regular member and Removed', 'dicex-connect' ); ?>
				<?php endif; ?>
			</button>
			<button type="button" class="button button-secondary" id="dicex-connect-club-preview" <?php echo esc_attr( Dicex_Connect_Club_Store::exists() ? '' : 'hidden' ); ?>>
				<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<?php esc_html_e( 'Count customers', 'dicex-connect' ); ?>
			</button>
		</p>
		<?php // Not a live region: its progress would queue a message a page. club.js announces the start and the end. ?>
		<p class="description dicex-connect-club-preview-status" id="dicex-connect-club-preview-status" hidden></p>

		<div class="dicex-connect-club-grid">
			<p>
				<label for="dicex-connect-club-default"><?php esc_html_e( 'Customers who meet no conditions join', 'dicex-connect' ); ?></label>
				<select id="dicex-connect-club-default" data-selected="<?php echo esc_attr( $club['default_level'] ); ?>"></select>
			</p>
			<p>
				<label for="dicex-connect-club-removed"><?php esc_html_e( 'Numbers taken out of the club go to', 'dicex-connect' ); ?></label>
				<select id="dicex-connect-club-removed" data-selected="<?php echo esc_attr( $club['removed_level'] ); ?>"></select>
				<span class="description"><?php esc_html_e( 'Never by the conditions: a number comes here when you move it, or when the customer leaves the club from their account. Needed before the club can be switched on.', 'dicex-connect' ); ?></span>
			</p>
		</div>

		<h3><?php esc_html_e( 'Contact groups', 'dicex-connect' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Groups sit alongside the levels. A customer is in one level, but in every group whose conditions they meet — so you can gather, say, everybody who buys coffee and everybody who buys from Tehran, and reach each in DiceX.', 'dicex-connect' ); ?>
			<?php esc_html_e( 'A group needs at least one condition. Nobody in the level for removed numbers is put in a group.', 'dicex-connect' ); ?>
		</p>
		<p class="dicex-connect-region-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<span><?php esc_html_e( 'DiceX has no way to take a contact out of a group. A customer who stops matching stays in the group at DiceX; this screen says how many those are.', 'dicex-connect' ); ?></span>
		</p>

		<ol class="dicex-connect-club-levels dicex-connect-club-groups" id="dicex-connect-club-groups">
			<?php
			foreach ( $club['groups'] as $club_group ) {
				$club_group_row( $club_group );
			}
			?>
		</ol>

		<template id="dicex-connect-club-group-template">
			<?php $club_group_row( $club_blank_group ); ?>
		</template>

		<p class="dicex-connect-club-level-actions">
			<button type="button" class="button button-secondary" id="dicex-connect-club-add-group">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add a group', 'dicex-connect' ); ?>
			</button>
		</p>

		<h3><?php esc_html_e( 'Who joins, and what is sent', 'dicex-connect' ); ?></h3>

		<fieldset class="dicex-connect-recipient-set dicex-connect-club-join">
			<legend><?php esc_html_e( 'Who joins the club', 'dicex-connect' ); ?></legend>
			<label>
				<input type="radio" name="dicex-connect-club-join" class="dicex-connect-club-join" value="everyone" <?php checked( $club['join'], 'everyone' ); ?>>
				<?php esc_html_e( 'Every customer: the users and guests chosen below', 'dicex-connect' ); ?>
			</label>
			<label>
				<input type="radio" name="dicex-connect-club-join" class="dicex-connect-club-join" value="consent" <?php checked( $club['join'], 'consent' ); ?>>
				<?php esc_html_e( 'Only customers who agree: they tick a box at checkout, when registering, or in their account', 'dicex-connect' ); ?>
			</label>
			<span class="description">
				<?php esc_html_e( 'Whether you need your customers\' agreement depends on the law where you sell, so nothing is chosen for you. Either way, customers can leave the club from their account whenever they like.', 'dicex-connect' ); ?>
			</span>
		</fieldset>

		<p class="dicex-connect-club-consent-label">
			<label for="dicex-connect-club-consent-label"><?php esc_html_e( 'Words beside the club box', 'dicex-connect' ); ?></label>
			<input type="text" id="dicex-connect-club-consent-label" class="large-text" maxlength="250"
				value="<?php echo esc_attr( $club['consent_label'] ); ?>" placeholder="<?php echo esc_attr( Dicex_Connect_Club_Settings::consent_label( array( 'consent_label' => '' ) ) ); ?>">
			<span class="description"><?php esc_html_e( 'Leave it empty for the wording shown, in the language of each visitor. Say what customers are agreeing to, the way your privacy policy does.', 'dicex-connect' ); ?></span>
		</p>

		<fieldset class="dicex-connect-recipient-set dicex-connect-club-roles">
			<legend><?php esc_html_e( 'WordPress users with these roles', 'dicex-connect' ); ?></legend>
			<?php foreach ( Dicex_Connect_Club_Settings::role_options() as $club_role => $club_role_name ) : ?>
				<label>
					<input type="checkbox" class="dicex-connect-club-role" value="<?php echo esc_attr( $club_role ); ?>" <?php checked( in_array( $club_role, $club['roles'], true ) ); ?>>
					<?php echo esc_html( $club_role_name ); ?>
				</label>
			<?php endforeach; ?>
			<span class="description"><?php esc_html_e( 'A user joins with the mobile number on their profile, or their billing phone.', 'dicex-connect' ); ?></span>
		</fieldset>

		<?php if ( $club_woo ) : ?>
			<fieldset class="dicex-connect-recipient-set">
				<legend><?php esc_html_e( 'Customers without an account', 'dicex-connect' ); ?></legend>
				<label>
					<input type="checkbox" id="dicex-connect-club-guests" <?php checked( ! empty( $club['guests'] ) ); ?>>
					<?php esc_html_e( 'Add guests who paid for an order, by their billing phone', 'dicex-connect' ); ?>
				</label>
			</fieldset>

			<p>
				<label for="dicex-connect-club-window"><?php esc_html_e( 'Purchases that count towards a level', 'dicex-connect' ); ?></label>
				<select id="dicex-connect-club-window">
					<?php
					foreach ( Dicex_Connect_Club_Settings::WINDOWS as $club_window ) :
						if ( 0 === $club_window ) {
							$club_window_label = __( 'All purchases, ever', 'dicex-connect' );
						} else {
							/* translators: %s: a number of months */
							$club_window_label = sprintf( _n( 'The last %s month', 'The last %s months', $club_window, 'dicex-connect' ), number_format_i18n( $club_window ) );
						}
						?>
						<option value="<?php echo esc_attr( $club_window ); ?>" <?php selected( (int) $club['window_months'], $club_window ); ?>><?php echo esc_html( $club_window_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<fieldset class="dicex-connect-recipient-set">
			<legend><?php esc_html_e( 'Sent to DiceX', 'dicex-connect' ); ?></legend>
			<p class="description"><?php esc_html_e( 'Always: mobile number, first and last name, level, the date of the first purchase, and the date of birth while the club asks for it. Add more only if you need it there.', 'dicex-connect' ); ?></p>
			<?php
			$club_field_labels = array(
				'email'   => __( 'Email address', 'dicex-connect' ),
				'company' => __( 'Company', 'dicex-connect' ),
				'address' => __( 'Billing address', 'dicex-connect' ),
			);
			foreach ( $club_field_labels as $club_field => $club_field_label ) :
				?>
				<label>
					<input type="checkbox" class="dicex-connect-club-field" value="<?php echo esc_attr( $club_field ); ?>" <?php checked( in_array( $club_field, $club['fields'], true ) ); ?>>
					<?php echo esc_html( $club_field_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<h3><?php esc_html_e( 'Date of birth', 'dicex-connect' ); ?></h3>

		<label class="dicex-connect-club-inline">
			<input type="checkbox" id="dicex-connect-club-birthday" <?php checked( ! empty( $club['birthday']['enabled'] ) ); ?>>
			<?php esc_html_e( 'Ask customers for their date of birth, and send it to DiceX', 'dicex-connect' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Asked at checkout, when registering, in the customer\'s account and on the WordPress profile screen. It goes to DiceX with the year, so you can see how old your customers are.', 'dicex-connect' ); ?></p>

		<?php if ( $club_woo && ! Dicex_Connect_Club_Fields::use_block_fields() ) : ?>
			<p class="dicex-connect-region-note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<span><?php esc_html_e( 'The checkout block shows the date of birth and the club box from WooCommerce 9.8. On this store only the classic checkout, the registration forms and the account page show them.', 'dicex-connect' ); ?></span>
			</p>
		<?php endif; ?>

		<div class="dicex-connect-club-birthday-settings">
			<label class="dicex-connect-club-inline">
				<input type="checkbox" id="dicex-connect-club-birthday-required" <?php checked( ! empty( $club['birthday']['required'] ) ); ?>>
				<?php esc_html_e( 'Required: nobody checks out or registers without it', 'dicex-connect' ); ?>
			</label>

			<p>
				<label for="dicex-connect-club-calendar"><?php esc_html_e( 'Calendar customers write it in', 'dicex-connect' ); ?></label>
				<select id="dicex-connect-club-calendar">
					<?php
					$club_calendars = array(
						'auto'      => __( 'By language: Solar Hijri in Persian, Gregorian otherwise', 'dicex-connect' ),
						'jalali'    => __( 'Solar Hijri (Shamsi)', 'dicex-connect' ),
						'gregorian' => __( 'Gregorian', 'dicex-connect' ),
					);
					foreach ( $club_calendars as $club_calendar => $club_calendar_label ) :
						?>
						<option value="<?php echo esc_attr( $club_calendar ); ?>" <?php selected( $club['birthday']['calendar'], $club_calendar ); ?>><?php echo esc_html( $club_calendar_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'This decides the example customers see and how a saved date is shown back to them. A date typed in either calendar is understood, and it is kept and sent as Gregorian.', 'dicex-connect' ); ?></span>
			</p>

			<p>
				<label for="dicex-connect-club-birthday-key"><?php esc_html_e( 'Also read birthdays from this user meta key', 'dicex-connect' ); ?></label>
				<input type="text" id="dicex-connect-club-birthday-key" class="regular-text code" dir="ltr" maxlength="191" value="<?php echo esc_attr( $club['birthday']['meta_key'] ); ?>">
				<span class="description"><?php esc_html_e( 'If another plugin already keeps your customers\' birthdays, enter the key it stores them under. Dates written year first, in either calendar, and timestamps are read; anything else is left out.', 'dicex-connect' ); ?></span>
			</p>
		</div>

		<h3><?php esc_html_e( 'When to sync', 'dicex-connect' ); ?></h3>

		<fieldset class="dicex-connect-recipient-set">
			<legend class="screen-reader-text"><?php esc_html_e( 'When to sync', 'dicex-connect' ); ?></legend>
			<?php
			$club_modes = array(
				'both'     => __( 'As things happen, and once a day to catch anything missed (recommended)', 'dicex-connect' ),
				'realtime' => __( 'Only as things happen', 'dicex-connect' ),
				'daily'    => __( 'Only once a day', 'dicex-connect' ),
			);
			foreach ( $club_modes as $club_mode => $club_mode_label ) :
				?>
				<label>
					<input type="radio" name="dicex-connect-club-mode" class="dicex-connect-club-mode" value="<?php echo esc_attr( $club_mode ); ?>" <?php checked( $club['sync_mode'], $club_mode ); ?>>
					<?php echo esc_html( $club_mode_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<p>
			<label for="dicex-connect-club-time"><?php esc_html_e( 'Daily sync at', 'dicex-connect' ); ?></label>
			<input type="time" id="dicex-connect-club-time" dir="ltr" value="<?php echo esc_attr( $club['daily_time'] ); ?>">
			<span class="description">
				<?php
				printf(
					/* translators: %s: the site's time zone, such as Asia/Tehran */
					esc_html__( 'Site time, %s. A quiet site runs it at its first visit after this time.', 'dicex-connect' ),
					'<span dir="ltr">' . esc_html( wp_timezone_string() ) . '</span>'
				);
				?>
			</span>
		</p>

		<h3><?php esc_html_e( 'Welcome message', 'dicex-connect' ); ?></h3>

		<label class="dicex-connect-club-inline">
			<input type="checkbox" id="dicex-connect-club-welcome" <?php checked( ! empty( $club['welcome']['enabled'] ) ); ?>>
			<?php esc_html_e( 'Greet customers when they join the club', 'dicex-connect' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Sent once, when somebody joins for the first time. Never to customers brought in by an import, and never to the level for removed numbers.', 'dicex-connect' ); ?></p>

		<div class="dicex-connect-club-welcome-settings dicex-connect-club-welcome">
			<?php
			$club_chosen = array_values( array_intersect( (array) $club['welcome']['channels'], $club_region ) );
			$club_order  = array_merge( $club_chosen, array_diff( $club_region, $club_chosen ) );
			?>
			<?php if ( empty( $club_region ) ) : ?>
				<p class="dicex-connect-region-note">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php esc_html_e( 'No messaging channel is available in the region this account is set to.', 'dicex-connect' ); ?>
				</p>
			<?php else : ?>
				<fieldset class="dicex-connect-recipient-set">
					<legend><?php esc_html_e( 'Send over, in this order', 'dicex-connect' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Drag to reorder, or use the arrows. If the first one does not go through, the next is tried.', 'dicex-connect' ); ?></p>
					<ol class="dicex-connect-channel-list">
						<?php foreach ( $club_order as $club_channel ) : ?>
							<li data-channel="<?php echo esc_attr( $club_channel ); ?>">
								<span class="dashicons dashicons-menu dicex-connect-channel-handle" aria-hidden="true"></span>
								<label>
									<input type="checkbox" class="dicex-connect-club-channel" value="<?php echo esc_attr( $club_channel ); ?>" <?php checked( in_array( $club_channel, $club_chosen, true ) ); ?>>
									<?php echo esc_html( $club_labels[ $club_channel ] ); ?>
								</label>
								<?php Dicex_Connect_Settings_Page::channel_order_buttons( $club_labels[ $club_channel ] ); ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</fieldset>
			<?php endif; ?>

			<p>
				<label for="dicex-connect-club-template"><?php esc_html_e( 'Message text', 'dicex-connect' ); ?></label>
				<textarea id="dicex-connect-club-template" rows="3"><?php echo esc_textarea( Dicex_Connect_Club_Settings::welcome_template( $club ) ); ?></textarea>
				<span class="dicex-connect-tag-list">
					<?php esc_html_e( 'Tags you can use:', 'dicex-connect' ); ?>
					<?php foreach ( array( '{first_name}', '{last_name}', '{level}', '{site_name}' ) as $club_tag ) : ?>
						<code dir="ltr"><?php echo esc_html( $club_tag ); ?></code>
					<?php endforeach; ?>
				</span>
			</p>

			<?php if ( count( $club_locales ) > 1 ) : ?>
				<div class="dicex-connect-club-languages-wrap">
					<input type="hidden" id="dicex-connect-club-templates" class="dicex-connect-club-input" data-key="templates" data-map="1"
						value="<?php echo esc_attr( wp_json_encode( (object) $club['welcome']['templates'] ) ); ?>">
					<button type="button" class="dicex-connect-club-list-button dicex-connect-club-languages" aria-expanded="false">
						<span class="dashicons dashicons-translation" aria-hidden="true"></span>
						<span class="dicex-connect-club-list-name"><?php esc_html_e( 'In another language', 'dicex-connect' ); ?></span>
						<span class="dicex-connect-club-list-summary"></span>
					</button>
					<div class="dicex-connect-club-language-panel" hidden>
						<p class="description"><?php esc_html_e( 'Each customer is written to in their own language where the site knows it: the language they chose for their account, or the one they were reading when they ordered. Anything left empty falls back to the text above.', 'dicex-connect' ); ?></p>
						<?php foreach ( $club_locales as $club_locale => $club_locale_name ) : ?>
							<?php $club_lang = str_replace( '_', '-', $club_locale ); ?>
							<label>
								<span class="screen-reader-text"><?php esc_html_e( 'Welcome message', 'dicex-connect' ); ?></span>
								<span class="dicex-connect-club-bound-label" lang="<?php echo esc_attr( $club_lang ); ?>"><?php echo esc_html( $club_locale_name ); ?></span>
								<textarea rows="3" maxlength="500" lang="<?php echo esc_attr( $club_lang ); ?>" dir="auto" data-locale="<?php echo esc_attr( $club_locale ); ?>"><?php echo esc_textarea( isset( $club['welcome']['templates'][ $club_locale ] ) ? $club['welcome']['templates'][ $club_locale ] : '' ); ?></textarea>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<p class="dicex-connect-club-save-row">
			<button type="button" class="button button-primary" id="dicex-connect-club-save"><?php esc_html_e( 'Save club settings', 'dicex-connect' ); ?></button>
			<span class="dicex-connect-club-save-status" id="dicex-connect-club-save-status" role="status"></span>
		</p>
	</div>

	<div class="dicex-connect-card dicex-connect-club-members" id="dicex-connect-club-members" <?php echo esc_attr( Dicex_Connect_Club_Store::exists() ? '' : 'hidden' ); ?>>
		<h3><?php esc_html_e( 'Members', 'dicex-connect' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Choose a level for someone to keep them there, whatever their purchases. "Automatic" hands them back to the conditions.', 'dicex-connect' ); ?></p>

		<div class="dicex-connect-club-filters">
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Search by number or name', 'dicex-connect' ); ?></span>
				<input type="search" id="dicex-connect-club-search" placeholder="<?php esc_attr_e( 'Search by number or name', 'dicex-connect' ); ?>">
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Level', 'dicex-connect' ); ?></span>
				<select id="dicex-connect-club-filter-level">
					<option value=""><?php esc_html_e( 'Any level', 'dicex-connect' ); ?></option>
					<?php foreach ( $club['levels'] as $club_level ) : ?>
						<option value="<?php echo esc_attr( $club_level['id'] ); ?>"><?php echo esc_html( $club_level['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Status', 'dicex-connect' ); ?></span>
				<select id="dicex-connect-club-filter-status">
					<?php foreach ( Dicex_Connect_Club_Page::status_labels() as $club_state => $club_state_label ) : ?>
						<option value="<?php echo esc_attr( $club_state ); ?>"><?php echo esc_html( $club_state_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<div class="dicex-connect-club-table-wrap">
			<table class="widefat striped dicex-connect-club-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Mobile number', 'dicex-connect' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'dicex-connect' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Orders', 'dicex-connect' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Purchases', 'dicex-connect' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Location', 'dicex-connect' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Level', 'dicex-connect' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'dicex-connect' ); ?></th>
					</tr>
				</thead>
				<tbody id="dicex-connect-club-rows">
					<tr><td colspan="7"><?php esc_html_e( 'Loading...', 'dicex-connect' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<p class="dicex-connect-club-pager">
			<button type="button" class="button button-secondary" id="dicex-connect-club-prev" aria-disabled="true"><?php esc_html_e( 'Previous', 'dicex-connect' ); ?></button>
			<span id="dicex-connect-club-page-text"></span>
			<button type="button" class="button button-secondary" id="dicex-connect-club-next" aria-disabled="true"><?php esc_html_e( 'Next', 'dicex-connect' ); ?></button>
		</p>
	</div>
</div>
