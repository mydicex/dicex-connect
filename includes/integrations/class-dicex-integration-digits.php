<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Digits: deliver the codes it generates through DiceX.
 *
 * Digits owns the whole login flow — it writes the message, decides who gets it
 * and when. This integration only registers DiceX as one more SMS gateway in
 * Digits' own settings and answers when Digits asks for a message to be sent.
 * Nothing here touches Digits' forms, users or sessions.
 *
 * It rides on three things Digits exposes to third parties, all read from its
 * source rather than guessed at:
 *
 *   digits_sms_gateways   filters the gateway list shown in Digits' settings,
 *                         so DiceX can be picked there like any other provider.
 *   unitedover_send_sms   fires from the `default:` branch of Digits'
 *                         digit_send_message() switch, which is where a gateway
 *                         id it has no case for ends up. Returning true tells
 *                         Digits the message was sent.
 *   digits_login_user     defined in includes/login.php, which Digits always
 *                         loads — so it is a reliable "is Digits here" check.
 *                         digit_send_message() is not: Digits only requires
 *                         gateways.php at the moment it sends.
 *
 * The one-time code is already substituted into the message before Digits hands
 * it over, so the text is passed straight through and never inspected.
 *
 * This calls Dicex_Connect_Sender::send_chain() directly rather than the base class's notify():
 * the recipient and the wording come from Digits, so there is no template to
 * render and no recipient list to resolve. The send still goes through the one
 * facade, which is the rule that matters.
 */
class Dicex_Connect_Integration_Digits extends Dicex_Connect_Integration_Base {

	protected $slug = 'digits';

	/**
	 * The gateway id DiceX registers itself under inside Digits.
	 *
	 * Digits routes by a numeric id through one large switch; anything it has no
	 * case for falls through to `default:`, which is where unitedover_send_sms
	 * fires. So this number only has to be one Digits does not already use — its
	 * own run from 2 to 123, plus 900 for the built-in custom gateway.
	 */
	const GATEWAY_ID = 9310;

	protected function register_hooks() {
		add_filter( 'digits_sms_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'unitedover_send_sms', array( $this, 'send' ), 10, 7 );
	}

	/**
	 * Adds DiceX to the gateway list on Digits' own settings screen.
	 *
	 * No input fields: the API key and the sender line are configured in this
	 * plugin, so there is nothing to type twice.
	 *
	 * @param array $gateways
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}

		$gateways['dicex_connect'] = array(
			'value'  => self::GATEWAY_ID,
			'group'  => __( 'DiceX', 'dicex-connect' ),
			'label'  => __( 'DiceX', 'dicex-connect' ),
			'inputs' => array(),
		);

		return $gateways;
	}

	/**
	 * @param mixed  $handled     Whatever an earlier filter decided; false by default.
	 * @param string $option_slug Digits' own option prefix. Unused.
	 * @param mixed  $gateway_id  The gateway Digits was told to use.
	 * @param string $countrycode Dialling code, with or without a plus.
	 * @param string $mobile      National number.
	 * @param string $message     Final message text, code already substituted.
	 * @param mixed  $test_call   Truthy when Digits is testing the gateway from its settings.
	 * @return mixed True when sent; a string explains the failure on a test call.
	 */
	public function send( $handled, $option_slug, $gateway_id, $countrycode, $mobile, $message, $test_call = false ) {
		// Another gateway's send. Leave whatever was decided untouched.
		if ( self::GATEWAY_ID !== (int) $gateway_id ) {
			return $handled;
		}

		$number = Dicex_Connect_Mobile::normalize( $countrycode . $mobile );

		if ( ! Dicex_Connect_Mobile::is_valid( $number ) ) {
			Dicex_Connect_Logger::log( 'DIGITS: refused, not a number this region can deliver to' );

			$reason = Dicex_Connect_Mobile::format_hint();

			return $test_call ? $reason : false;
		}

		$result = Dicex_Connect_Sender::send_chain(
			$this->channels(),
			$number,
			(string) $message
		);

		if ( is_wp_error( $result ) ) {
			Dicex_Connect_Logger::log( 'DIGITS FAILED: ' . $result->get_error_message() );

			return $test_call ? $result->get_error_message() : false;
		}

		return true;
	}
}
