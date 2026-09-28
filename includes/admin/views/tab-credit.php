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

$region_currency = Dicex_Connect_Region::currency();

$amount_placeholder = ( '' === $region_currency )
	? __( 'Amount', 'dicex-connect' )
	: sprintf(
		/* translators: %s: currency such as IRR */
		__( 'Amount in %s', 'dicex-connect' ),
		$region_currency
	);

$region_gateways = Dicex_Connect_Credit::payment_providers();
?>
<div class="dicex-connect-card">
	<h3><?php esc_html_e( 'Top up online', 'dicex-connect' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'This sends you to the DiceX payment gateway. Try a small amount once before relying on it.', 'dicex-connect' ); ?>
	</p>

	<p>
		<input type="text" id="dicex-connect-charge-amount" class="regular-text ltr" dir="ltr"
			placeholder="<?php echo esc_attr( $amount_placeholder ); ?>">
	</p>
	<p class="dicex-connect-credit-options">
		<?php foreach ( Dicex_Connect_Region::quick_amounts() as $quick_amount ) : ?>
			<button type="button" class="button dicex-connect-credit-quick" data-amount="<?php echo esc_attr( $quick_amount ); ?>"><?php echo esc_html( number_format_i18n( $quick_amount ) ); ?></button>
		<?php endforeach; ?>
		<button type="button" class="button" id="dicex-connect-credit-custom"><?php esc_html_e( 'Another amount', 'dicex-connect' ); ?></button>
	</p>
	<p class="description">
		<?php echo esc_html( Dicex_Connect_Credit::minimum_message() ); ?>
	</p>

	<?php
	/*
	 * What the amount is worth somewhere the reader already thinks in. Rendered
	 * for the region's minimum so the line says something before anybody types,
	 * and moved by the admin script from there.
	 */
	if ( Dicex_Connect_Currency::can_convert( $region_currency ) ) :
		$worth_amount = Dicex_Connect_Region::min_charge();
		$worth        = Dicex_Connect_Currency::equivalents( $worth_amount, $region_currency );
		?>
		<p class="dicex-connect-credit-worth" id="dicex-connect-credit-worth">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<span id="dicex-connect-credit-worth-text">
				<?php
				/*
				 * The converted figures carry the emphasis, because they are the
				 * part somebody is scanning for. Each is a whole phrase rather
				 * than a number glued to a word, so a translator can put the
				 * currency wherever their language wants it.
				 */
				$worth_usd = '<strong>' . esc_html(
					sprintf(
						/* translators: %s: an amount of money, for example 13.00 */
						__( '%s US dollars', 'dicex-connect' ),
						isset( $worth['USD'] ) ? $worth['USD'] : ''
					)
				) . '</strong>';

				$worth_eur = '<strong>' . esc_html(
					sprintf(
						/* translators: %s: an amount of money, for example 11.22 */
						__( '%s euros', 'dicex-connect' ),
						isset( $worth['EUR'] ) ? $worth['EUR'] : ''
					)
				) . '</strong>';

				printf(
					/* translators: 1: amount with its currency, for example "5 OMR". 2: the same amount in US dollars, already emphasised. 3: the same amount in euros, already emphasised. */
					esc_html__( '%1$s is roughly %2$s, or %3$s.', 'dicex-connect' ),
					esc_html( number_format_i18n( $worth_amount ) . ' ' . $region_currency ),
					$worth_usd, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above, then wrapped in a <strong> this file owns.
					$worth_eur  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above, then wrapped in a <strong> this file owns.
				);
				?>
			</span>
		</p>
		<p class="description dicex-connect-credit-worth-note">
			<?php
			printf(
				/* translators: %s: the date the euro rate was read, in the site's own date format. */
				esc_html__( 'The rial is pegged to the dollar, so that figure is exact. The euro figure is approximate, from the European Central Bank reference rate of %s.', 'dicex-connect' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( Dicex_Connect_Currency::EUR_RATE_READ ) ) )
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( empty( $region_gateways ) ) : ?>
		<p class="dicex-connect-region-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: %s: region name such as Iran, GCC */
				esc_html__( 'No payment gateway is available for the %s region yet. You can still top up from the DiceX panel.', 'dicex-connect' ),
				esc_html( Dicex_Connect_Region::label() )
			);
			?>
		</p>
	<?php endif; ?>

	<fieldset class="dicex-connect-gateways"<?php echo esc_attr( empty( $region_gateways ) ? 'hidden' : '' ); ?>>
		<legend><?php esc_html_e( 'Pay through', 'dicex-connect' ); ?></legend>
		<div class="dicex-connect-gateway-list">
			<?php
			$first_chargeable = Dicex_Connect_Credit::clean_provider( '' );

			foreach ( $region_gateways as $provider_value => $provider_label ) :
				$provider_logo = Dicex_Connect_Credit::provider_logo( $provider_value );
				$provider_id   = 'dicex-connect-gateway-' . strtolower( $provider_value );
				$chargeable    = Dicex_Connect_Credit::is_chargeable( $provider_value );
				?>
				<label class="dicex-connect-gateway<?php echo $chargeable ? '' : ' is-unavailable'; ?>" for="<?php echo esc_attr( $provider_id ); ?>">
					<input type="radio" name="dicex_connect_gateway" id="<?php echo esc_attr( $provider_id ); ?>"
						class="dicex-connect-gateway-input" value="<?php echo esc_attr( $provider_value ); ?>"
						<?php checked( $first_chargeable, $provider_value ); ?>
						<?php disabled( ! $chargeable ); ?>>
					<span class="dicex-connect-gateway-tile">
						<span class="dicex-connect-gateway-mark">
							<?php if ( '' !== $provider_logo ) : ?>
								<img class="dicex-connect-gateway-logo" src="<?php echo esc_url( $provider_logo ); ?>" alt="">
							<?php else : ?>
								<span class="dashicons dashicons-bank" aria-hidden="true"></span>
							<?php endif; ?>
						</span>
						<span class="dicex-connect-gateway-name"><?php echo esc_html( $provider_label ); ?></span>
						<?php if ( ! $chargeable ) : ?>
							<span class="dicex-connect-gateway-soon"><?php esc_html_e( 'Not available yet', 'dicex-connect' ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
	</fieldset>

	<?php if ( Dicex_Connect_Payment_Terms::applies() ) : ?>
		<?php
		/*
		 * Before the button, not after it: a money-back guarantee and a refund
		 * window are what somebody wants to know while they are deciding.
		 *
		 * Marked lang="en" dir="ltr" because the text stays in English whatever
		 * the admin is in, and an Arabic or Hebrew dashboard would otherwise
		 * reorder an English paragraph. See class-dicex-payment-terms.php for why
		 * it is not translated.
		 */
		?>
		<div class="dicex-connect-paywords" lang="en" dir="ltr">
			<p class="dicex-connect-paywords-headline">
				<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
				<span><?php echo esc_html( Dicex_Connect_Payment_Terms::headline() ); ?></span>
			</p>
			<p class="dicex-connect-paywords-seller"><?php echo esc_html( Dicex_Connect_Payment_Terms::seller() ); ?></p>

			<details class="dicex-connect-paywords-more">
				<summary>Refund and privacy terms</summary>
				<div class="dicex-connect-paywords-body">
					<h4>Refunds</h4>
					<?php foreach ( Dicex_Connect_Payment_Terms::refund() as $paywords_line ) : ?>
						<p><?php echo esc_html( $paywords_line ); ?></p>
					<?php endforeach; ?>

					<h4>Your details</h4>
					<?php foreach ( Dicex_Connect_Payment_Terms::privacy() as $paywords_line ) : ?>
						<p><?php echo esc_html( $paywords_line ); ?></p>
					<?php endforeach; ?>

					<p class="dicex-connect-paywords-links">
						<a href="<?php echo esc_url( Dicex_Connect_Payment_Terms::REFUND_URL ); ?>" target="_blank" rel="noopener noreferrer">Full refund and return policy</a>
						<a href="<?php echo esc_url( Dicex_Connect_Payment_Terms::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer">Full privacy policy</a>
					</p>
				</div>
			</details>
		</div>
	<?php endif; ?>

	<p>
		<button type="button" id="dicex-connect-charge-submit" class="button button-primary"><?php esc_html_e( 'Pay online', 'dicex-connect' ); ?></button>
	</p>
	<div id="dicex-connect-charge-result"></div>

	<p class="dicex-connect-region-note dicex-connect-coupon-note">
		<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
		<span>
			<?php esc_html_e( 'Holding a discount coupon? A coupon can only be applied in the DiceX panel, not from here, so top up there instead and the discount is taken off the amount.', 'dicex-connect' ); ?>
			<a href="<?php echo esc_url( 'https://home.dicex.me/financial-operations/user-charge' ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Top up in the DiceX panel', 'dicex-connect' ); ?>
			</a>
		</span>
	</p>
</div>

<form id="dicex-connect-charge-redirect-form" method="POST" style="display:none;"></form>
