<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Messages a visitor should not have to wait for.
 *
 * A notification about an order, a form or a login used to be sent while the
 * visitor's own request was still open, so their checkout or form waited on
 * DiceX — up to the gateway's timeout, once for every recipient. The
 * integration cards hand their messages here instead, and they go out at the
 * very end of the same request, after the page has reached the visitor.
 *
 * That is how WordPress runs wp-cron.php: it finishes the response with
 * fastcgi_finish_request() or litespeed_finish_request() where the server has
 * one, and ignores the visitor leaving. Where the server has neither, the
 * messages still go out at the end of the request — never later than before.
 *
 * Nothing is written anywhere to be sent later. A queue would keep phone
 * numbers and message text in the database, and would depend on scheduled tasks
 * some sites never run. A message that fails is logged, as it always was, and is
 * not tried again: the gateway may have delivered it before the error, and
 * nobody should get the same message twice.
 *
 * Sends somebody is waiting on — a login code, a message Digits asked for, the
 * test button — never come through here.
 *
 * Core: returns nothing to print and prints nothing.
 */
class Dicex_Connect_Outbox {

	/** @var array[] Messages held until the end of this request. */
	private static $held = array();

	/**
	 * Sends a message after the response, or at once where nobody is waiting on
	 * this request anyway.
	 *
	 * @param string   $source   Who asked, for the log: an integration's slug.
	 * @param string[] $channels In the order to try them.
	 * @param string   $number
	 * @param string   $template With its {tags}.
	 * @param array    $vars
	 */
	public static function send_later( $source, $channels, $number, $template, $vars ) {
		$message = array(
			'source'   => (string) $source,
			'channels' => (array) $channels,
			'number'   => (string) $number,
			'template' => (string) $template,
			'vars'     => (array) $vars,
		);

		if ( ! self::after_response() ) {
			self::deliver( $message );
			return;
		}

		if ( empty( self::$held ) ) {
			// Last of all: after WordPress has flushed the page, at priority 1, and
			// after everything else has had its turn.
			add_action( 'shutdown', array( __CLASS__, 'flush' ), PHP_INT_MAX );
		}

		self::$held[] = $message;
	}

	/**
	 * Whether this request has a visitor to spare the wait: not a scheduled task,
	 * not the command line.
	 *
	 * @return bool
	 */
	public static function after_response() {
		$visitor = ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );

		/**
		 * Filters whether a card's messages wait for the end of the request.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $later True to send after the response; false to send at once.
		 */
		return (bool) apply_filters( 'dicex_connect_send_after_response', $visitor );
	}

	/**
	 * How many messages this request is holding, for the test harness and the log.
	 *
	 * @return int
	 */
	public static function held() {
		return count( self::$held );
	}

	/**
	 * Sends what this request held back. Runs once, at shutdown.
	 */
	public static function flush() {
		if ( empty( self::$held ) ) {
			return;
		}

		$held       = self::$held;
		self::$held = array();

		// The visitor has their page. What follows has to finish even if they leave.
		ignore_user_abort( true );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		foreach ( $held as $message ) {
			self::deliver( $message );
		}
	}

	/**
	 * @param array $message
	 */
	private static function deliver( $message ) {
		/*
		 * A send must never take the host plugin down with it. Dicex_Connect_Sender
		 * already returns WP_Error instead of throwing, so this catch is only ever
		 * reached by something unexpected further down.
		 */
		try {
			$result = Dicex_Connect_Sender::send_chain( $message['channels'], $message['number'], $message['template'], $message['vars'] );

			if ( is_wp_error( $result ) ) {
				Dicex_Connect_Logger::log( 'INTEGRATION ' . $message['source'] . ' FAILED: ' . $result->get_error_message() );
			}
		} catch ( Throwable $e ) {
			Dicex_Connect_Logger::log( 'INTEGRATION ' . $message['source'] . ' EXCEPTION: ' . $e->getMessage() );
		}
	}
}
