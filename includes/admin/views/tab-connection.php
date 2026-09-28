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

$api_key  = Dicex_Connect_Api_Client::get_api_key();
$verified = Dicex_Connect_Account::is_verified();
$balance  = $verified ? Dicex_Connect_Credit::get_balance() : '';

/*
 * The language WordPress is actually showing this person, named in that language.
 * locale_get_display_name() comes from the intl extension, which is not
 * guaranteed on a host, so the locale code is the fallback.
 */
$current_locale   = determine_locale();
$current_language = function_exists( 'locale_get_display_name' )
	? locale_get_display_name( $current_locale, $current_locale )
	: $current_locale;

$region_choice = Dicex_Connect_Region::get();
$otp_length    = Dicex_Connect_Send_Options::code_length();
$voice_lang    = Dicex_Connect_Send_Options::voice_lang();
$message_lang  = Dicex_Connect_Send_Options::message_lang();
$rich_messages = Dicex_Connect_Send_Options::rich_messages();
$message_name  = Dicex_Connect_Branding::get_override();
?>
<div class="dicex-connect-card">
	<p>
		<?php esc_html_e( 'To use DiceX, create an account in the DiceX panel and then paste your API key in the field below.', 'dicex-connect' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="dicex-connect-api-key"><?php esc_html_e( 'API key', 'dicex-connect' ); ?></label></th>
			<td>
				<div class="dicex-connect-api-key-row">
					<input type="text" id="dicex-connect-api-key" class="regular-text ltr" dir="ltr"
						value="<?php echo esc_attr( $api_key ); ?>"
						placeholder="<?php esc_attr_e( 'Paste your API key here', 'dicex-connect' ); ?>">
					<button type="button" id="dicex-connect-verify-key" class="button button-primary">
						<?php esc_html_e( 'Check connection', 'dicex-connect' ); ?>
					</button>
					<span id="dicex-connect-verify-status" class="dicex-connect-status-icon dashicons <?php echo esc_attr( $verified ? 'dashicons-yes-alt dicex-connect-status-ok' : '' ); ?>"></span>
				</div>
				<p class="description"><?php esc_html_e( 'You can find your API key in the DiceX panel.', 'dicex-connect' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-region"><?php esc_html_e( 'Region', 'dicex-connect' ); ?></label></th>
			<td>
				<select id="dicex-connect-region">
					<?php foreach ( Dicex_Connect_Region::regions() as $region_key => $region_def ) : ?>
						<option value="<?php echo esc_attr( $region_key ); ?>" <?php selected( $region_choice, $region_key ); ?>>
							<?php echo esc_html( $region_def['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span id="dicex-connect-region-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
				<p class="description">
					<?php esc_html_e( 'Where your DiceX account operates. DiceX does not sell the same services everywhere, so this decides which channels and which payment gateways the plugin offers you. Nothing about the region is sent to DiceX.', 'dicex-connect' ); ?>
				</p>
				<?php if ( '' !== Dicex_Connect_Region::note() ) : ?>
					<p class="dicex-connect-region-note">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<?php echo esc_html( Dicex_Connect_Region::note() ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-otp-length"><?php esc_html_e( 'Login code length', 'dicex-connect' ); ?></label></th>
			<td>
				<select id="dicex-connect-otp-length">
					<?php for ( $n = Dicex_Connect_Send_Options::MIN_LENGTH; $n <= Dicex_Connect_Send_Options::MAX_LENGTH; $n++ ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $otp_length, $n ); ?>>
							<?php
							printf(
								/* translators: %s: a number of digits, already in the reader's own numerals */
								esc_html( _n( '%s digit', '%s digits', $n, 'dicex-connect' ) ),
								esc_html( number_format_i18n( $n ) )
							);
							?>
						</option>
					<?php endfor; ?>
				</select>
				<span id="dicex-connect-otp-length-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
				<p class="description">
					<?php esc_html_e( 'How many digits the two-step login code has. Longer is harder to guess; shorter is easier to read back over a phone call.', 'dicex-connect' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-message-lang"><?php esc_html_e( 'Message language', 'dicex-connect' ); ?></label></th>
			<td>
				<select id="dicex-connect-message-lang">
					<?php foreach ( Dicex_Connect_Send_Options::message_lang_choices() as $lang_value => $lang_name ) : ?>
						<option value="<?php echo esc_attr( $lang_value ); ?>" <?php selected( $message_lang, $lang_value ); ?>>
							<?php echo esc_html( $lang_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span id="dicex-connect-message-lang-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
				<p class="description">
					<?php esc_html_e( 'The language of the words this plugin puts into a message itself — when a code expires, when an event happened, and the month names in a date. Your own message templates are untouched; they go out exactly as you wrote them.', 'dicex-connect' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Worth setting when the people receiving your messages do not read the language your dashboard is in. Only languages installed on this site are listed — WordPress cannot write in one it does not have.', 'dicex-connect' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-voice-lang"><?php esc_html_e( 'Voice call language', 'dicex-connect' ); ?></label></th>
			<td>
				<select id="dicex-connect-voice-lang">
					<?php foreach ( Dicex_Connect_Send_Options::lang_choices() as $lang_code => $lang_def ) : ?>
						<option value="<?php echo esc_attr( $lang_code ); ?>"
							<?php selected( $voice_lang, $lang_code ); ?>
							<?php disabled( ! $lang_def['supported'] ); ?>>
							<?php
							echo esc_html(
								$lang_def['supported']
									? $lang_def['label']
									/* translators: %s: a language name, such as العربية */
									: sprintf( __( '%s — not available yet', 'dicex-connect' ), $lang_def['label'] )
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<span id="dicex-connect-voice-lang-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
				<p class="description">
					<?php esc_html_e( 'The language a voice call speaks in. Only this channel uses it — a written message goes out in whatever language you wrote the template in. Arabic and German are listed because DiceX is asked for them; they turn on here by themselves once the gateway accepts them.', 'dicex-connect' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Message detail', 'dicex-connect' ); ?></th>
			<td>
				<label for="dicex-connect-rich-messages">
					<input type="checkbox" id="dicex-connect-rich-messages" <?php checked( $rich_messages ); ?>>
					<?php esc_html_e( 'Add a line for when a code expires, and when an event happened', 'dicex-connect' ); ?>
				</label>
				<span id="dicex-connect-rich-messages-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
				<p class="description">
					<?php esc_html_e( 'Worth knowing what it costs: Persian text is sent as UCS-2, which fits 70 characters in one SMS and 67 in each one after that, so an extra line can turn one paid message into two. On WhatsApp, Telegram and Bale it is free. Each message template shows its own count.', 'dicex-connect' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dicex-connect-message-name"><?php esc_html_e( 'Name in messages', 'dicex-connect' ); ?></label></th>
			<td>
				<input type="text" id="dicex-connect-message-name" class="regular-text"
					value="<?php echo esc_attr( $message_name ); ?>"
					maxlength="<?php echo esc_attr( Dicex_Connect_Branding::MAX_LENGTH ); ?>"
					placeholder="<?php echo esc_attr( Dicex_Connect_Branding::DEFAULT_NAME ); ?>">
				<span id="dicex-connect-message-name-saved" class="dicex-connect-line-saved dashicons dashicons-yes-alt" style="display:none;"></span>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the name messages are signed with when the field is left empty */
						esc_html__( 'What the {site_name} tag becomes in every message this plugin sends. Leave it empty to use %s.', 'dicex-connect' ),
						esc_html( Dicex_Connect_Branding::DEFAULT_NAME )
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Plugin language', 'dicex-connect' ); ?></th>
			<td>
				<p>
					<?php
					printf(
						/* translators: %s: the language WordPress is currently showing this person, e.g. "English (United States)" */
						esc_html__( 'These screens follow your WordPress language, which is currently %s. There is nothing to set here.', 'dicex-connect' ),
						'<strong>' . esc_html( $current_language ) . '</strong>'
					);
					?>
				</p>
				<p class="description">
					<?php
					/*
					 * The arrows below are literal characters, not &rarr;. esc_html()
					 * would turn the ampersand of an entity into &amp; and print
					 * "&rarr;" on the screen.
					 *
					 * The translators comment has to be the last thing before the
					 * string, with nothing between them, or the extractor does not
					 * associate the two and the note never reaches the translator.
					 */
					printf(
						/* translators: 1: opening link tag to the WordPress profile screen, 2: closing link tag */
						esc_html__( 'To read them in another language, change your own language under %1$sProfile → Language%2$s, or change the site language under Settings → General.', 'dicex-connect' ),
						'<a href="' . esc_url( get_edit_profile_url() ) . '#locale">',
						'</a>'
					);
					?>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: opening link tag to the plugin's translation project, 2: closing link tag */
						esc_html__( 'The plugin is translated by the WordPress community. If your language is missing or incomplete, you can add it at %1$stranslate.wordpress.org%2$s and every site running this plugin receives it automatically.', 'dicex-connect' ),
						'<a href="https://translate.wordpress.org/projects/wp-plugins/dicex-connect/" target="_blank" rel="noopener noreferrer">',
						'</a>'
					);
					?>
				</p>
			</td>
		</tr>
	</table>

	<div id="dicex-connect-account-info" class="dicex-connect-info-card <?php echo esc_attr( $verified ? 'is-visible' : '' ); ?>">
		<div class="dicex-connect-info-item">
			<span class="dicex-connect-info-label"><?php esc_html_e( 'Account credit', 'dicex-connect' ); ?></span>
			<span class="dicex-connect-info-value" id="dicex-connect-info-credit">
				<?php
				if ( is_wp_error( $balance ) || null === $balance || '' === $balance ) {
					echo '—';
				} else {
						echo esc_html( Dicex_Connect_Account::format_credit( $balance ) );
				}
				?>
			</span>
			<button type="button" class="button button-secondary dicex-connect-goto-tab" data-goto="credit">
				<span class="dashicons dashicons-cart" aria-hidden="true"></span><?php esc_html_e( 'Top up', 'dicex-connect' ); ?>
			</button>
		</div>
	</div>
</div>
