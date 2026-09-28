<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce: send a message when an order changes status.
 *
 * Hook: woocommerce_order_status_changed( $order_id, $from, $to, $order ),
 * which fires for every transition however it was made — checkout, the admin
 * order screen, a payment gateway callback or WP-CLI.
 *
 * Everything here reads the order through the CRUD API (the WC_Order object the
 * hook hands over, or wc_get_order() as a fallback). No post meta, no queries
 * against wp_posts — that is what keeps this working on stores using
 * High-Performance Order Storage, which is the default since WooCommerce 8.2.
 * The matching compatibility declaration lives in the plugin bootstrap.
 */
class Dicex_Connect_Integration_Woocommerce extends Dicex_Connect_Integration_Base {

	protected $slug = 'woocommerce';

	protected function register_hooks() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from     Previous status, without the wc- prefix.
	 * @param string   $to       New status, without the wc- prefix.
	 * @param WC_Order $order    The order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ) {
		$settings = $this->settings();
		$statuses = ( isset( $settings['statuses'] ) && is_array( $settings['statuses'] ) ) ? $settings['statuses'] : array();

		// Only the transitions the admin ticked on the card.
		if ( ! in_array( $to, $statuses, true ) ) {
			return;
		}

		// WooCommerce has passed the order object since 3.0; older callers and
		// anything re-firing this hook by hand may not.
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->notify(
			array(
				'order_id'      => $order->get_order_number(),
				'order_status'  => wc_get_order_status_name( $to ),
				'order_total'   => $this->plain_total( $order ),
				'customer_name' => trim( $order->get_formatted_billing_full_name() ),
			),
			$order->get_billing_phone()
		);
	}

	/**
	 * wc_price() returns markup with HTML entities in it — fine for a web page,
	 * wrong in an SMS. This flattens it to the plain localized amount.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private function plain_total( $order ) {
		$formatted = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );

		return trim( html_entity_decode( wp_strip_all_tags( $formatted ), ENT_QUOTES, 'UTF-8' ) );
	}
}
