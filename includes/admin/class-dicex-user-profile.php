<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds DiceX's fields to the WordPress profile screen: the user's mobile number,
 * and, while the customer club is on, their date of birth and club membership.
 *
 * The mobile number is what makes the "WordPress administrators" recipient
 * option work — an administrator who fills it in starts receiving the
 * notifications that are set to go to admins. The number is stored normalized so
 * it never has to be cleaned up again at send time.
 *
 * On a site without WooCommerce this screen is the only account page a customer
 * has, so their own profile carries the club box too. Somebody else's profile
 * only shows what that person chose: nobody joins or leaves on another's behalf.
 */
class Dicex_Connect_User_Profile {

	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_field' ) );
		add_action( 'user_profile_update_errors', array( $this, 'validate_field' ), 10, 3 );

		add_action( 'personal_options_update', array( $this, 'save_club_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_club_fields' ) );
		add_action( 'user_profile_update_errors', array( $this, 'validate_club_fields' ), 10, 3 );
	}

	/**
	 * @param WP_User $user
	 */
	public function render_field( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$value = get_user_meta( $user->ID, Dicex_Connect_Recipients::USER_META_KEY, true );
		?>
		<h2><?php esc_html_e( 'DiceX', 'dicex-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="dicex-connect-mobile"><?php esc_html_e( 'Mobile number', 'dicex-connect' ); ?></label>
				</th>
				<td>
					<input type="text" name="dicex_connect_mobile" id="dicex-connect-mobile" class="regular-text ltr" dir="ltr" aria-describedby="dicex-connect-mobile-description"
						value="<?php echo esc_attr( Dicex_Connect_Mobile::display( $value ) ); ?>"
						placeholder="<?php echo esc_attr( Dicex_Connect_Mobile::example() ); ?>">
					<p class="description" id="dicex-connect-mobile-description">
						<?php esc_html_e( 'If this user is an administrator and a card sends to WordPress administrators, messages go to this number.', 'dicex-connect' ); ?>
						<?php echo esc_html( Dicex_Connect_Mobile::format_hint() ); ?>
					</p>
				</td>
			</tr>
			<?php $this->render_club_rows( $user ); ?>
		</table>
		<?php
	}

	/**
	 * @param WP_User $user
	 */
	private function render_club_rows( $user ) {
		if ( ! Dicex_Connect_Club_Settings::is_enabled() ) {
			return;
		}

		$settings = Dicex_Connect_Club_Settings::get();
		$own      = defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE;

		if ( Dicex_Connect_Club_Settings::asks_birthday( $settings ) ) {
			$date = Dicex_Connect_Club_Fields::user_birthday( $user->ID, $settings );
			// The browser's own date of birth belongs to whoever is signed in, not to the profile being edited.
			?>
			<tr>
				<th scope="row">
					<label for="dicex-connect-birthday"><?php esc_html_e( 'Date of birth', 'dicex-connect' ); ?></label>
				</th>
				<td>
					<input type="text" name="<?php echo esc_attr( Dicex_Connect_Club_Fields::FIELD_BIRTHDAY ); ?>" id="dicex-connect-birthday" class="regular-text ltr" dir="ltr" maxlength="20"
						autocomplete="<?php echo esc_attr( $own ? 'bday' : 'off' ); ?>" aria-describedby="dicex-connect-birthday-description"
						value="<?php echo esc_attr( '' === $date ? '' : Dicex_Connect_Club_Fields::display_birthday( $date ) ); ?>"
						placeholder="<?php echo esc_attr( Dicex_Connect_Dates::example( Dicex_Connect_Club_Fields::calendar() ) ); ?>">
					<p class="description" id="dicex-connect-birthday-description"><?php esc_html_e( 'Year, month and day. It goes to the DiceX customer club with the rest of this customer\'s details.', 'dicex-connect' ); ?></p>
				</td>
			</tr>
			<?php
		}

		if ( 'consent' !== $settings['join'] && ! Dicex_Connect_Club_Settings::can_leave( $settings ) ) {
			return;
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Customer club', 'dicex-connect' ); ?></th>
			<td>
				<?php if ( $own && Dicex_Connect_Club_Settings::can_leave( $settings ) && Dicex_Connect_Club_Fields::shows_box_to( $user->ID ) ) : ?>
					<label for="dicex-connect-club">
						<input type="checkbox" name="<?php echo esc_attr( Dicex_Connect_Club_Fields::FIELD_CLUB ); ?>" id="dicex-connect-club" value="1" <?php checked( Dicex_Connect_Club_Fields::box_ticked( $user->ID ) ); ?>>
						<?php echo esc_html( Dicex_Connect_Club_Settings::consent_label( $settings ) ); ?>
					</label>
					<input type="hidden" name="<?php echo esc_attr( Dicex_Connect_Club_Fields::FIELD_FORM ); ?>" value="profile">
				<?php else : ?>
					<?php echo esc_html( $this->membership_text( $user->ID, $settings ) ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * What a user chose about the club, for somebody else looking at their profile.
	 *
	 * @param int   $user_id
	 * @param array $settings
	 * @return string
	 */
	private function membership_text( $user_id, $settings ) {
		$consent = Dicex_Connect_Club_Fields::consent( $user_id );

		if ( 'left' === $consent['status'] ) {
			return sprintf(
				/* translators: %s: when and where, such as "May 2, 2026 10:15 am, on their account page" */
				__( 'Left the club: %s.', 'dicex-connect' ),
				Dicex_Connect_Club_Fields::describe_choice( $consent['left_gmt'], $consent['left_where'] )
			);
		}

		if ( 'given' === $consent['status'] ) {
			return sprintf(
				/* translators: %s: when and where, such as "May 2, 2026 10:15 am, at checkout" */
				__( 'Asked to join the club: %s.', 'dicex-connect' ),
				Dicex_Connect_Club_Fields::describe_choice( $consent['given_gmt'], $consent['given_where'] )
			);
		}

		return 'consent' === $settings['join']
			? __( 'Has not asked to join the club.', 'dicex-connect' )
			: __( 'In the club like every customer, unless they leave from their account.', 'dicex-connect' );
	}

	/**
	 * WordPress runs the save hooks before it checks the form, so only a date
	 * that reads — or an emptied field — is kept here; validate_club_fields()
	 * shows the error for anything else.
	 *
	 * @param int $user_id
	 */
	public function save_club_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! Dicex_Connect_Club_Settings::is_enabled() ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		if ( isset( $_POST[ Dicex_Connect_Club_Fields::FIELD_BIRTHDAY ] ) ) {
			$parsed = Dicex_Connect_Dates::parse_birthday( sanitize_text_field( wp_unslash( $_POST[ Dicex_Connect_Club_Fields::FIELD_BIRTHDAY ] ) ) );

			if ( is_string( $parsed ) ) {
				Dicex_Connect_Club_Fields::set_user_birthday( $user_id, $parsed );
			}
		}

		// Only a person's own box counts.
		if ( get_current_user_id() === (int) $user_id
			&& isset( $_POST[ Dicex_Connect_Club_Fields::FIELD_FORM ] )
			&& 'profile' === sanitize_key( wp_unslash( $_POST[ Dicex_Connect_Club_Fields::FIELD_FORM ] ) ) ) {
			Dicex_Connect_Club_Fields::apply_box( $user_id, ! empty( $_POST[ Dicex_Connect_Club_Fields::FIELD_CLUB ] ), 'profile' );
		}
	}

	/**
	 * @param WP_Error $errors Passed by reference by edit_user().
	 * @param bool     $update
	 * @param stdClass $user
	 */
	public function validate_club_fields( $errors, $update, $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- edit_user() has already checked the nonce.
		if ( ! isset( $_POST[ Dicex_Connect_Club_Fields::FIELD_BIRTHDAY ] ) || ! Dicex_Connect_Club_Settings::is_enabled() ) {
			return;
		}

		$parsed = Dicex_Connect_Dates::parse_birthday( sanitize_text_field( wp_unslash( $_POST[ Dicex_Connect_Club_Fields::FIELD_BIRTHDAY ] ) ), Dicex_Connect_Club_Fields::calendar() );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $parsed ) ) {
			$errors->add( 'dicex_connect_birthday', '<strong>' . esc_html__( 'Error:', 'dicex-connect' ) . '</strong> ' . esc_html( $parsed->get_error_message() ) );
		}
	}

	/**
	 * @param int $user_id
	 */
	public function save_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		if ( ! isset( $_POST['dicex_connect_mobile'] ) ) {
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST['dicex_connect_mobile'] ) );

		if ( '' === trim( $raw ) ) {
			delete_user_meta( $user_id, Dicex_Connect_Recipients::USER_META_KEY );
			return;
		}

		$number = Dicex_Connect_Mobile::from_input( $raw );

		// An unusable number is not stored — better an empty field than a
		// recipient list that silently drops one number at send time.
		if ( ! Dicex_Connect_Mobile::is_valid( $number ) ) {
			delete_user_meta( $user_id, Dicex_Connect_Recipients::USER_META_KEY );
			return;
		}

		// WordPress saves before it checks: validate_field() shows why this one is
		// not kept, and the number the account had stays.
		if ( $this->taken( (int) $user_id, $number ) ) {
			return;
		}

		update_user_meta( $user_id, Dicex_Connect_Recipients::USER_META_KEY, $number );
	}

	/**
	 * Refuses a mobile number WordPress cannot use, in the place WordPress shows
	 * errors — the top of the profile form.
	 *
	 * Without this the number was accepted by the form and then quietly dropped,
	 * so somebody could set a number, see the field empty on the next page load,
	 * and never learn why. WordPress aborts the whole save when this error object
	 * comes back with anything in it.
	 *
	 * @param WP_Error $errors Passed by reference by edit_user().
	 * @param bool     $update
	 * @param stdClass $user
	 */
	public function validate_field( $errors, $update, $user ) {
		if ( ! isset( $_POST['dicex_connect_mobile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- edit_user() has already checked the nonce.
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST['dicex_connect_mobile'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// An empty field is how you remove a number.
		if ( '' === trim( $raw ) ) {
			return;
		}

		$number = Dicex_Connect_Mobile::normalize( $raw );

		if ( ! Dicex_Connect_Mobile::is_valid( $number ) ) {
			$errors->add(
				'dicex_connect_mobile',
				sprintf(
					/* translators: %s: how the number should be written in this region */
					__( '<strong>Error</strong>: That is not a mobile number DiceX can deliver to. %s', 'dicex-connect' ),
					Dicex_Connect_Mobile::format_hint()
				)
			);
			return;
		}

		if ( $this->taken( isset( $user->ID ) ? (int) $user->ID : 0, $number ) ) {
			$errors->add( 'dicex_connect_mobile_taken', __( '<strong>Error</strong>: That mobile number is already on another account. Each account needs a number of its own.', 'dicex-connect' ) );
		}
	}

	/**
	 * One account per number. A login code goes to the one account a number
	 * belongs to, and two accounts sharing it means nobody can sign in with a
	 * code — so anybody could otherwise lock another person out of that just by
	 * typing their number. A number already shared before this check existed can
	 * still be saved unchanged; only a new one is refused.
	 *
	 * @param int    $user_id
	 * @param string $number Normalized.
	 * @return bool Whether this would be a new number for the account, already on another.
	 */
	private function taken( $user_id, $number ) {
		if ( $user_id > 0 && $number === (string) get_user_meta( $user_id, Dicex_Connect_Recipients::USER_META_KEY, true ) ) {
			return false;
		}

		$others = get_users(
			array(
				'meta_key'   => Dicex_Connect_Recipients::USER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $number,                                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'exclude'    => array( $user_id ),
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		return ! empty( $others );
	}
}
