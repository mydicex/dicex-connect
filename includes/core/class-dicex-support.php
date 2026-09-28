<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A message to DiceX support, from the Support tab.
 *
 * It is an email to one fixed address, sent through the site's own mail with
 * wp_mail(), and the answer goes to the address the person gives. The site's
 * name, address and versions go with it, and — when the box is left ticked —
 * the plugin's log, as an attachment.
 *
 * Nothing here can be turned into a way to send mail anywhere else, or much of it:
 *
 * - The address is a constant. No request can name another recipient, add a Cc
 *   or a Bcc, or change who it is from.
 * - One message a minute and five a day from a site. An attempt counts whether
 *   or not the site's mail took it, so a failure cannot be retried in a loop.
 * - Plain text only. The subject and the reply address can hold no line break,
 *   so nothing can be slipped into the headers, and every field has a cap.
 * - The only attachment is the log, built here from the plugin's own option and
 *   never written to disk. Nothing is uploaded.
 *
 * Only the admin layer calls this, after its guard() — nonce and manage_options.
 */
class Dicex_Connect_Support {

	/** Where every message goes. Never taken from a request. */
	const ADDRESS = 'wp-support@dicex.me';

	/** Messages one site may send in any 24 hours. */
	const PER_DAY = 5;

	/** Seconds between two messages from one site. */
	const GAP = 60;

	const SUBJECT_MAX = 150;

	const MESSAGE_MAX = 5000;

	/** Option: when this site's messages of the last 24 hours went, oldest first. */
	const OPTION = 'dicex_connect_support_sent';

	/** The attachment's name. */
	const LOG_FILE = 'dicex-connect-log.txt';

	/**
	 * What goes with every message, and what the tab shows is going with it.
	 *
	 * @return array Key => value, in the order they are shown.
	 */
	public static function site_facts() {
		return array(
			'site'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'address'     => home_url( '/' ),
			'plugin'      => DICEX_CONNECT_VERSION,
			'wordpress'   => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'woocommerce' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'language'    => get_locale(),
			'region'      => Dicex_Connect_Region::get(),
			'multisite'   => is_multisite() ? 'yes' : 'no',
			'connected'   => Dicex_Connect_Account::is_verified() ? 'yes' : 'no',
		);
	}

	/**
	 * @return int Seconds until this site may write again; 0 when it may now.
	 */
	public static function wait() {
		$now  = time();
		$sent = self::recent( $now );

		if ( ! empty( $sent ) && $now - end( $sent ) < self::GAP ) {
			return self::GAP - ( $now - end( $sent ) );
		}

		if ( count( $sent ) >= self::PER_DAY ) {
			return max( 1, reset( $sent ) + DAY_IN_SECONDS - $now );
		}

		return 0;
	}

	/**
	 * @param int $wait Seconds.
	 * @return string What to tell somebody who has to wait.
	 */
	public static function wait_message( $wait ) {
		return sprintf(
			/* translators: 1: how many messages a day, 2: how long until the next one may go, such as "3 mins", 3: the support email address */
			__( 'A site can send DiceX support one message a minute and %1$s a day. You can write again in %2$s, or write to %3$s from your own mailbox.', 'dicex-connect' ),
			number_format_i18n( self::PER_DAY ),
			human_time_diff( time(), time() + max( 1, (int) $wait ) ),
			self::ADDRESS
		);
	}

	/**
	 * Sends one message.
	 *
	 * @param string $reply_to   Where the answer should go.
	 * @param string $subject
	 * @param string $message
	 * @param bool   $attach_log Whether the plugin's log goes with it.
	 * @return array|WP_Error `attached`: whether the log really went — a site's
	 *                        mail plugin can send without the attachment.
	 */
	public static function send( $reply_to, $subject, $message, $attach_log ) {
		// sanitize_email() and sanitize_text_field() leave no line break behind:
		// nothing typed here can reach the headers as a header of its own.
		$reply_to = sanitize_email( (string) $reply_to );
		$subject  = trim( sanitize_text_field( (string) $subject ) );
		$message  = trim( sanitize_textarea_field( (string) $message ) );

		if ( ! is_email( $reply_to ) ) {
			return new WP_Error( 'dicex_connect_support_email', __( 'Enter an email address DiceX support can answer.', 'dicex-connect' ) );
		}

		if ( '' === $subject || '' === $message ) {
			return new WP_Error( 'dicex_connect_support_empty', __( 'Write a subject and a message.', 'dicex-connect' ) );
		}

		if ( self::length( $subject ) > self::SUBJECT_MAX || self::length( $message ) > self::MESSAGE_MAX ) {
			return new WP_Error(
				'dicex_connect_support_long',
				sprintf(
					/* translators: 1: most characters in the subject, 2: most characters in the message */
					__( 'Keep the subject to %1$s characters and the message to %2$s.', 'dicex-connect' ),
					number_format_i18n( self::SUBJECT_MAX ),
					number_format_i18n( self::MESSAGE_MAX )
				)
			);
		}

		$wait = self::wait();

		if ( $wait > 0 ) {
			return new WP_Error( 'dicex_connect_support_wait', self::wait_message( $wait ) );
		}

		self::remember( time() );

		$facts    = self::site_facts();
		$host     = (string) wp_parse_url( $facts['address'], PHP_URL_HOST );
		$log      = $attach_log ? self::log_text() : '';
		$attached = false;

		// Until the log is really attached, the body says it is not — some mail
		// plugins send without ever running the hook below.
		$log_line = $attach_log ? "Log: asked for, but the site's mail sent this without it\n" : "Log: not sent\n";
		$body     = $message . "\n\n-- \n" . self::facts_text( $facts ) . 'Reply to: ' . $reply_to . "\n" . $log_line;

		/*
		 * The log goes as an attachment built in memory. wp_mail() only takes
		 * files by path, and fires phpmailer_init just before it sends, so the
		 * text is handed to PHPMailer there. The hook is added right before this
		 * one call and taken off right after it, and it attaches nothing unless
		 * the email is still going to DiceX support and nobody else: a plugin
		 * that redirects the site's mail must not carry the log somewhere else.
		 */
		$attach = function ( $phpmailer ) use ( $log, $log_line, &$attached ) {
			$to = array();

			foreach ( (array) $phpmailer->getToAddresses() as $address ) {
				$to[] = strtolower( (string) $address[0] );
			}

			if ( array( self::ADDRESS ) !== $to ) {
				return;
			}

			try {
				$phpmailer->addStringAttachment( $log, self::LOG_FILE, 'base64', 'text/plain' );
			} catch ( Exception $e ) {
				return;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
			$phpmailer->Body = str_replace( $log_line, 'Log: attached, ' . self::LOG_FILE . "\n", $phpmailer->Body );
			$attached        = true;
		};

		if ( $attach_log ) {
			add_action( 'phpmailer_init', $attach );
		}

		try {
			$sent = wp_mail(
				self::ADDRESS,
				sprintf( '[DiceX Connect %s] %s', $host, $subject ),
				$body,
				array(
					'Content-Type: text/plain; charset=UTF-8',
					'Reply-To: ' . $reply_to,
				)
			);
		} finally {
			remove_action( 'phpmailer_init', $attach );
		}

		if ( ! $sent ) {
			Dicex_Connect_Logger::log( 'SUPPORT: the site could not send the email' );

			return new WP_Error(
				'dicex_connect_support_mail',
				sprintf(
					/* translators: %s: the support email address */
					__( 'Your site could not send the email; its mail may not be set up. Write to %s from your own mailbox instead.', 'dicex-connect' ),
					self::ADDRESS
				)
			);
		}

		Dicex_Connect_Logger::log( 'SUPPORT: message sent to DiceX support' . ( $attached ? ', with the log' : '' ) );

		return array( 'attached' => $attached );
	}

	/**
	 * The plugin's log as the attachment carries it, newest line first.
	 *
	 * Customers' numbers are already partly hidden by the log itself. What else in
	 * it points at a person is shortened here: email addresses keep the first two
	 * characters of the name, IPv4 addresses lose their last part, IPv6 addresses
	 * everything after the second group.
	 *
	 * @return string
	 */
	public static function log_text() {
		$lines = Dicex_Connect_Logger::get_logs();
		$text  = 'DiceX Connect ' . DICEX_CONNECT_VERSION . ' log, newest first, taken ' . gmdate( 'Y-m-d H:i' ) . " UTC\n\n";
		$text .= empty( $lines ) ? "(empty)\n" : implode( "\n", array_map( 'strval', $lines ) ) . "\n";

		$text = preg_replace( '/\b([A-Za-z0-9._%+-]{1,2})[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', '$1***@$2', $text );
		$text = preg_replace( '/\b(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}\b/', '$1.x', $text );
		$text = preg_replace( '/\b([0-9a-f]{1,4}:[0-9a-f]{1,4}):(?:[0-9a-f]{0,4}:){1,6}[0-9a-f]{0,4}/i', '$1:x', $text );

		return (string) $text;
	}

	/**
	 * The site's details, one per line, labelled in English for the people who
	 * read the support inbox, whatever language the site is in.
	 *
	 * @param array $facts site_facts().
	 * @return string
	 */
	private static function facts_text( $facts ) {
		$labels = array(
			'site'        => 'Site',
			'address'     => 'Address',
			'plugin'      => 'DiceX Connect',
			'wordpress'   => 'WordPress',
			'php'         => 'PHP',
			'woocommerce' => 'WooCommerce',
			'language'    => 'Language',
			'region'      => 'Region',
			'multisite'   => 'Multisite',
			'connected'   => 'Connected to DiceX',
		);
		$text   = '';

		foreach ( $labels as $key => $label ) {
			$value = isset( $facts[ $key ] ) ? trim( (string) $facts[ $key ] ) : '';
			$text .= $label . ': ' . ( '' === $value ? '-' : $value ) . "\n";
		}

		return $text;
	}

	/**
	 * @param int $now
	 * @return int[] When this site's messages of the last 24 hours went, oldest first.
	 */
	private static function recent( $now ) {
		$sent = get_option( self::OPTION, array() );
		$sent = is_array( $sent ) ? array_map( 'intval', $sent ) : array();

		$sent = array_filter(
			$sent,
			function ( $time ) use ( $now ) {
				return $time > $now - DAY_IN_SECONDS && $time <= $now;
			}
		);

		sort( $sent );

		return array_values( $sent );
	}

	/**
	 * @param int $time When a message went.
	 */
	private static function remember( $time ) {
		$sent   = self::recent( $time );
		$sent[] = (int) $time;

		update_option( self::OPTION, $sent, false );
	}

	/**
	 * @param string $text
	 * @return int Characters, not bytes: a Persian message is not cut short.
	 */
	private static function length( $text ) {
		return mb_strlen( $text, 'UTF-8' ); // WordPress provides mb_strlen() for UTF-8 where mbstring is missing.
	}
}
