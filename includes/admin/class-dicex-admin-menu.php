<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dicex_Connect_Admin_Menu {

	private $settings_page;
	private $logs_page;

	public function __construct( Dicex_Connect_Settings_Page $settings_page, Dicex_Connect_Logs_Page $logs_page ) {
		$this->settings_page = $settings_page;
		$this->logs_page     = $logs_page;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'size_menu_icon' ) );

		// A way in from the Plugins screen, which is where somebody stands the
		// moment they activate this. Cheaper for them than hunting the menu, and
		// it takes nothing over the way an activation redirect would.
		add_filter( 'plugin_action_links_' . plugin_basename( DICEX_CONNECT_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * @param array $links Row links WordPress already built.
	 * @return array
	 */
	public function action_links( $links ) {
		$start = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( array( 'page' => 'dicex-connect', 'tab' => 'welcome' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Getting started', 'dicex-connect' )
		);

		array_unshift( $links, $start );

		return $links;
	}

	public function register_menu() {
		add_menu_page(
			__( 'DiceX', 'dicex-connect' ),
			__( 'DiceX', 'dicex-connect' ),
			'manage_options',
			'dicex-connect',
			array( $this->settings_page, 'render' ),
			self::menu_icon(),
			58
		);

		add_submenu_page(
			'dicex-connect',
			__( 'Settings', 'dicex-connect' ),
			__( 'Settings', 'dicex-connect' ),
			'manage_options',
			'dicex-connect',
			array( $this->settings_page, 'render' )
		);

		add_submenu_page(
			'dicex-connect',
			__( 'Logs', 'dicex-connect' ),
			__( 'Logs', 'dicex-connect' ),
			'manage_options',
			'dicex-connect-logs',
			array( $this->logs_page, 'render' )
		);
	}

	/**
	 * The DiceX mark beside the menu entry.
	 *
	 * A plain URL, which is what add_menu_page() takes by default:
	 * wp-admin/menu-header.php builds `<img src="' . esc_url( $item[6] ) . '">`
	 * unless the value is 'none', 'div', a Dashicon name, or a base64 SVG data
	 * URI. That esc_url() is why the icon may not be inlined as a data URI here
	 * — wp_allowed_protocols() has no `data`, so a base64 PNG would be stripped
	 * and the menu would show a broken image. Only the SVG branch escapes it,
	 * and it does so because it never becomes an src at all.
	 *
	 * The file is the brand mark itself rather than a drawing of it. It carries
	 * its own colours: WordPress recolours Dashicons and SVG data URIs to match
	 * the admin scheme, but never an <img>. The gradient is what makes that
	 * safe — its light and dark ends between them stay legible on the dark
	 * default menu and on the light schemes. A single-colour mark could not do
	 * both, which is why the white variant is not the one used here.
	 *
	 * Falls back to a Dashicon when the file is missing, so the menu never
	 * renders blank.
	 *
	 * @return string Icon URL, or a Dashicon name.
	 */
	private static function menu_icon() {
		if ( ! is_readable( DICEX_CONNECT_DIR . 'assets/images/menu-icon.png' ) ) {
			return 'dashicons-share';
		}

		return DICEX_CONNECT_URL . 'assets/images/menu-icon.png';
	}

	/**
	 * Holds the menu icon to 20px.
	 *
	 * WordPress only sizes a menu icon it recognises. A Dashicon is a font, and
	 * an SVG data URI gets `class="wp-menu-image svg"` and a background sized to
	 * 20px — but any other URL is emitted as a plain `<img>` with no width, no
	 * height and `max-width: none`, so it draws at its natural size. The brand
	 * mark is 128px square, which is what it drew: a 128px image in a 34px row,
	 * spilling over the four menu items underneath it.
	 *
	 * The rule has to load on every admin page, not just this plugin's, because
	 * that is where the menu is. It rides on core's own admin-menu stylesheet
	 * rather than adding a request of its own.
	 *
	 * The source file stays 128px on purpose: the browser downsamples it, which
	 * is what keeps the mark sharp on a high-density screen. Shrinking the file
	 * to 20px would fix the size and lose that.
	 *
	 * @return void
	 */
	public function size_menu_icon() {
		wp_add_inline_style(
			'admin-menu',
			// Padding on both sides, not just the top: 20px of icon plus 7px above
			// and below fills the 34px row exactly, which is what puts the mark on
			// the same line as its label and as every Dashicon above it.
			'#adminmenu .toplevel_page_dicex-connect .wp-menu-image img{width:20px;height:20px;padding:7px 0;box-sizing:content-box}'
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'dicex-connect' ) ) {
			return;
		}

		wp_enqueue_style( 'dicex-connect-admin', DICEX_CONNECT_URL . 'assets/css/admin.css', array(), DICEX_CONNECT_VERSION );
		// wp-a11y: wp.a11y.speak(), WordPress's own way to announce a change to screen readers.
		wp_enqueue_script( 'dicex-connect-admin', DICEX_CONNECT_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-a11y' ), DICEX_CONNECT_VERSION, true );

		wp_localize_script(
			'dicex-connect-admin',
			'DiceXAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'dicex_connect_admin_nonce' ),
				'minCharge' => Dicex_Connect_Region::min_charge(),
				/*
				 * Units of another currency per one of this region's, so the line
				 * under the amount field can follow what somebody types without
				 * asking the server. Empty for a currency with no defensible rate.
				 */
				'rates'     => Dicex_Connect_Currency::rates( Dicex_Connect_Region::currency() ),
				'currency'  => Dicex_Connect_Region::currency(),
				/*
				 * Which channels can send with no line of their own. The script
				 * redraws a summary row after a save and has to know whether an
				 * emptied line leaves the DiceX shared line behind it, or nothing.
				 */
				'sharedLineChannels' => Dicex_Connect_Lines::SHARED_LINE_CHANNELS,
				'i18n'      => array(
					'checking'       => __( 'Checking...', 'dicex-connect' ),
					'checkConn'      => __( 'Check connection', 'dicex-connect' ),
					'sending'        => __( 'Sending...', 'dicex-connect' ),
					'sendTest'       => __( 'Send test', 'dicex-connect' ),
					'payOnline'      => __( 'Pay online', 'dicex-connect' ),
					'error'          => __( 'Error', 'dicex-connect' ),
					'loading'        => __( 'Loading...', 'dicex-connect' ),
					'chooseProvider' => __( 'Choose a network first', 'dicex-connect' ),
					'chooseLine'     => __( 'Choose a line', 'dicex-connect' ),
					'sharedLine'     => __( 'Shared line', 'dicex-connect' ),
					'noLineYet'      => __( 'No line yet — activate one in your DiceX panel', 'dicex-connect' ),
					'saving'         => __( 'Saving...', 'dicex-connect' ),
					'saveCard'       => __( 'Save this card', 'dicex-connect' ),
					'saveNumbers'    => __( 'Save numbers', 'dicex-connect' ),
					'minAmount'      => Dicex_Connect_Credit::minimum_message(),
					/* translators: 1: amount with its currency, for example "5 OMR". 2: the same amount in US dollars, already emphasised. 3: the same amount in euros, already emphasised. */
					'worth'          => __( '%1$s is roughly %2$s, or %3$s.', 'dicex-connect' ),
					/* translators: %s: an amount of money, for example 13.00 */
					'worthUsd'       => __( '%s US dollars', 'dicex-connect' ),
					/* translators: %s: an amount of money, for example 11.22 */
					'worthEur'       => __( '%s euros', 'dicex-connect' ),
					/* translators: 1: a name, such as WhatsApp or a club level. 2: its place in the list. 3: how many there are. */
					'channelPosition' => __( '%1$s: %2$s of %3$s', 'dicex-connect' ),
					'supportSend'    => __( 'Send to DiceX support', 'dicex-connect' ),
					'supportFill'    => __( 'Fill in your email, a subject and a message.', 'dicex-connect' ),
				),
			)
		);

		// The settings screen only: the log screen has no club on it. Loaded
		// whichever tab opens first, because tabs arrive over AJAX.
		if ( 'toplevel_page_dicex-connect' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dicex-connect-club', DICEX_CONNECT_URL . 'assets/css/club.css', array( 'dicex-connect-admin' ), DICEX_CONNECT_VERSION );
		wp_enqueue_script( 'dicex-connect-club', DICEX_CONNECT_URL . 'assets/js/club.js', array( 'jquery', 'dicex-connect-admin', 'wp-a11y' ), DICEX_CONNECT_VERSION, true );

		wp_localize_script(
			'dicex-connect-club',
			'DiceXClub',
			array(
				'woo'     => Dicex_Connect_Club_Sources::has_woocommerce(),
				'starter' => Dicex_Connect_Club_Settings::starter_set(),
				'i18n'    => array(
					'noRemovedLevel'   => __( 'None', 'dicex-connect' ),
					'addLevelFirst'    => __( 'Add a level first', 'dicex-connect' ),
					'unnamedLevel'     => __( '(no name yet)', 'dicex-connect' ),
					'tagDefault'       => __( 'Everybody else', 'dicex-connect' ),
					'tagRemoved'       => __( 'Removed numbers', 'dicex-connect' ),
					'hintRemoved'      => __( 'Conditions do not apply here. A number comes here when you move it, or when the customer leaves the club from their account.', 'dicex-connect' ),
					'hintNoConditions' => __( 'No conditions: everybody who reaches this level joins it, so the conditions of the levels below it are never checked.', 'dicex-connect' ),
					'noMembers'        => __( 'No members yet.', 'dicex-connect' ),
					/* translators: 1: this page number, 2: how many pages there are */
					'pageOf'           => __( 'Page %1$s of %2$s', 'dicex-connect' ),
					'automatic'        => __( 'Automatic', 'dicex-connect' ),
					/* translators: %s: the level the conditions put this customer in */
					'automaticNow'     => __( 'Automatic (now %s)', 'dicex-connect' ),
					'lockedTitle'      => __( 'Placed by hand', 'dicex-connect' ),
					'noName'           => __( '(no name)', 'dicex-connect' ),
					'guest'            => __( 'Guest', 'dicex-connect' ),
					'remoteTitle'      => __( 'Levels in your DiceX club, and how many customers each holds there:', 'dicex-connect' ),
					'remoteEmpty'      => __( 'Your DiceX club has no levels yet.', 'dicex-connect' ),
					'filter'           => __( 'Filter', 'dicex-connect' ),
					'searchProducts'   => __( 'Search products by name or SKU', 'dicex-connect' ),
					'noMatch'          => __( 'Nothing matches.', 'dicex-connect' ),
					'typeCity'         => __( 'City name', 'dicex-connect' ),
					'add'              => __( 'Add', 'dicex-connect' ),
					'cityHint'         => __( 'Add every spelling your customers use, in each language — for example Tehran and تهران. Capital letters, spaces and the Arabic or Persian forms of a letter do not matter.', 'dicex-connect' ),
					'done'             => __( 'Done', 'dicex-connect' ),
					/* translators: %s: one entry in a list, such as a city or a product */
					'removeItem'       => __( 'Remove %s', 'dicex-connect' ),
					/* translators: %s: how many more entries are chosen than are shown */
					'more'             => __( '+%s more', 'dicex-connect' ),
					// WordPress's own list separator, translated by core: "، " in Persian.
					'listSeparator'    => function_exists( 'wp_get_list_item_separator' ) ? wp_get_list_item_separator() : ', ',
					'noChoices'        => __( 'There is nothing to choose from here yet.', 'dicex-connect' ),
					'noTerms'          => __( 'The store has none of these yet.', 'dicex-connect' ),
					'counting'         => __( 'Counting…', 'dicex-connect' ),
					/* translators: 1: members counted so far, 2: all members */
					'countingProgress' => __( 'Counting: %1$s of %2$s', 'dicex-connect' ),
					/* translators: %s: how many customers the level would hold */
					'levelCount'       => __( 'Customers: %s', 'dicex-connect' ),
					/* translators: 1: members counted, 2: how many of them these levels would put in another level */
					'previewDone'      => __( 'Members counted: %1$s. Would move to another level if you save: %2$s. Nothing has been saved or sent.', 'dicex-connect' ),
					'releaseConfirm'   => __( 'Send every customer in the club to DiceX now? A number that reaches DiceX cannot be taken back out.', 'dicex-connect' ),
					'hintGroupEmpty'   => __( 'A group with no conditions would hold every customer in the club, so it cannot be saved. Give it at least one condition.', 'dicex-connect' ),
					'hintAgeUnknown'   => __( 'Nobody meets an age condition while the club knows no dates of birth. Ask for the date of birth below, or name the meta key another plugin keeps them under.', 'dicex-connect' ),
					/* translators: %s: how many memberships are still to reach DiceX */
					'groupWaiting'     => __( '%s on the way', 'dicex-connect' ),
					/* translators: %s: how many contacts are in the DiceX group but no longer match it */
					'groupStale'       => __( '%s no longer matching', 'dicex-connect' ),
					'groupStaleHint'   => __( 'They no longer match this group. DiceX has no way to take a contact out of a group, so they are still in it there.', 'dicex-connect' ),
					'groupChipWaiting' => __( 'On its way to DiceX.', 'dicex-connect' ),
					/* translators: %s: how many languages have a message written for them */
					'languagesWritten' => __( '%s written', 'dicex-connect' ),
					/* translators: %s: a level's name */
					'moveUpNamed'      => __( 'Move %s up', 'dicex-connect' ),
					/* translators: %s: a level's name */
					'moveDownNamed'    => __( 'Move %s down', 'dicex-connect' ),
					/* translators: 1: a name, such as WhatsApp or a club level. 2: its place in the list. 3: how many there are. */
					'position'         => __( '%1$s: %2$s of %3$s', 'dicex-connect' ),
					'apply'            => __( 'Apply', 'dicex-connect' ),
					/* translators: %s: a member's mobile number */
					'moveLabelFor'     => __( 'Level for %s', 'dicex-connect' ),
					/* translators: 1: a member's mobile number, 2: a level's name */
					'movedTo'          => __( '%1$s is now kept in %2$s.', 'dicex-connect' ),
					/* translators: 1: a member's mobile number, 2: the level the conditions put them in */
					'movedAuto'        => __( '%1$s is back on automatic, in %2$s.', 'dicex-connect' ),
					/* translators: %s: the list being filtered, such as "Bought from categories" */
					'filterList'       => __( 'Filter: %s', 'dicex-connect' ),
					/* translators: %s: a parent category's name */
					'inParent'         => __( 'in %s', 'dicex-connect' ),
					/* translators: %s: a city, the way it is already listed */
					'alreadyListed'    => __( 'Already listed as %s.', 'dicex-connect' ),
					/* translators: %s: how many members match */
					'membersFound'     => __( 'Members found: %s', 'dicex-connect' ),
					'noMembersMatch'   => __( 'No members match.', 'dicex-connect' ),
					'groupChipIn'      => __( 'In DiceX', 'dicex-connect' ),
					'groupChipLeft'    => __( 'No longer matching', 'dicex-connect' ),
				),
			)
		);
	}
}
