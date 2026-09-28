<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The refund and privacy terms that cover a top-up made through the
 * international payment gateways.
 *
 * Money taken outside Iran is taken by a different company from the one behind
 * the Iranian banks, under its own published terms, and a buyer is entitled to
 * read those before paying rather than after. That is what this holds.
 *
 * Two decisions worth knowing about.
 *
 * **The text is not translatable, on purpose.** Every other user-facing string
 * in this plugin goes through __() and is translated by the community at
 * translate.wordpress.org. A refund guarantee is not a label: a well-meant
 * translation that says six days, or drops the exception for a suspended
 * account, misstates what somebody is owed. The wording here stays as its owner
 * published it, and the links go to the pages that are authoritative. See
 * wp-i18n-persian-rtl for the rule this deliberately steps outside of.
 *
 * **It is condensed from the published pages, not copied whole.** The refund
 * page reads as it does here. The privacy page does not: it describes the
 * ntft.tech website — cookies it does not set, analytics it does not run — and
 * none of that is true of a WordPress admin screen. Repeating it here would
 * have told the reader something false about the page they were looking at, so
 * only the part that describes what happens to a customer's own details is
 * kept, and the full policy is a click away.
 */
class Dicex_Connect_Payment_Terms {

	const ENTITY       = 'New Taste For Technology and Investment LLC';
	const REGISTRATION = '1454780';
	const PLACE        = 'Muscat, Oman';
	const CONTACT      = 'hello@ntft.tech';

	/*
	 * The plugin's own site rather than the seller's. Same terms, but a reader
	 * who followed a link out of a WordPress dashboard lands somewhere that
	 * knows what DiceX Connect is, and the pages stay under the DiceX brand.
	 */
	const REFUND_URL  = 'https://wp.dicex.me/refund/';
	const PRIVACY_URL = 'https://wp.dicex.me/privacy/';

	/**
	 * Whether these terms are shown at all.
	 *
	 * Persian is the Iranian business, whose top-ups go to an Iranian bank under
	 * DiceX's own terms; every other language is the international one. The
	 * plugin has no other signal for which of the two a reader belongs to — the
	 * region setting says which gateways are offered, not who the buyer is — so
	 * the language the screens are already in is what decides.
	 *
	 * The block names the gateways it covers, so it stays truthful even on the
	 * mixed case: an English-speaking admin whose region is Iran reads terms
	 * that are plainly labelled as belonging to the international gateways.
	 *
	 * @return bool
	 */
	public static function applies() {
		return 'fa' !== strtolower( substr( (string) determine_locale(), 0, 2 ) );
	}

	/**
	 * One line, always visible, because it is the part that changes a decision.
	 *
	 * @return string
	 */
	public static function headline() {
		return 'A seven-day, no-questions-asked money-back guarantee covers every digital service bought through the international gateways.';
	}

	/**
	 * Who takes the money.
	 *
	 * @return string
	 */
	public static function seller() {
		return sprintf(
			'Payments through the international gateways are taken by %1$s (commercial registration %2$s), %3$s.',
			self::ENTITY,
			self::REGISTRATION,
			self::PLACE
		);
	}

	/**
	 * @return array Paragraphs, in reading order.
	 */
	public static function refund() {
		return array(
			'A refund request must arrive within seven days of purchase. After seven days a sale is final.',
			'To ask for one, write to ' . self::CONTACT . ' with your order details. A reason is welcome but not required.',
			'An approved refund goes back to the payment method you used, in the currency you paid, usually within five to ten business days. Your payment provider decides how quickly it appears.',
			'A refund is not available on an account or service used in breach of the terms of service, or suspended for misuse or fraud.',
			'You may cancel a service at any time. A cancellation after the seven-day window carries no refund, and access ends immediately.',
		);
	}

	/**
	 * @return array Paragraphs, in reading order.
	 */
	public static function privacy() {
		return array(
			'If you contact the seller, it holds what you send: your name, your contact details and the content of your message. It keeps that for as long as it needs to answer you and to keep a record of business correspondence, then deletes it.',
			'It does not sell that, and does not pass it to anyone outside the company except a service provider acting for it, such as its email provider.',
			'You can ask what is held about you, ask for it to be corrected, or ask for it to be deleted, by writing to ' . self::CONTACT . '. Some records have to be kept for a period set by law or for accounting, and you will be told when that applies.',
		);
	}
}
