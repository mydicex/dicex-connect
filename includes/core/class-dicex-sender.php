<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single facade every module/integration uses to send a message.
 * Nothing else in the plugin is allowed to call DiceX_Api_Client directly
 * for sending — that keeps the API contract centralized in one place.
 *
 * Per the user's explicit instruction, all notification-style sends (OTP
 * login codes as well as plain WooCommerce/WPForms/etc. notification text)
 * go through POST /api/v1/Otp/Send with code=0, which asks the DiceX gateway
 * to treat it as a plain informational message rather than generate a real
 * OTP. This mirrors exactly how the old plugin used the equivalent endpoint
 * on the same backend family (gateway.dicex.me).
 *
 * The Otp/Send body matches SMOR_SendRequest in the live Swagger, verified field
 * by field on 2026-09-01: templateCode (int32), message, code (int64),
 * lineNumber (string), mobile, sendType (enum Sms|WhatsApp|Voip|Safir|BaleBot),
 * length (int32) and lang (enum fa|en). A rejected send is therefore an
 * account- or line-permission matter at the gateway, not a malformed request.
 *
 * Telegram, Bale and Safir are the exception: Otp/Send's sendType enum has no
 * room for them, so those channels are sent through Social/SendMessage with a
 * SMSR_SendMessageRequest body (receiver, lineNumber, providerName, message).
 * Which transport a channel uses is decided in send() and nowhere else — the
 * facade stays the single entry point either way.
 */
class Dicex_Connect_Sender {

	/*
	 * Kept for anything that still refers to it. The body below now sends the
	 * length the plugin actually generates, which is not the same number: this
	 * constant said 5 while Dicex_Connect_Otp was producing 6-digit codes.
	 *
	 * The field is inert either way — every send goes out with code: 0, which
	 * tells the gateway not to generate a code at all, so the field describes a
	 * code the gateway never makes. It is sent truthfully rather than removed
	 * because the day a send needs the gateway's own code, a field that already
	 * agrees with the plugin is one less thing to get wrong.
	 */
	const DEFAULT_OTP_LENGTH = 5;


	/**
	 * Whether a channel could send right now, without trying it.
	 *
	 * The same four refusals send() makes, asked in advance: the plugin supports
	 * the channel, the region allows it, it has a line or a shared line behind
	 * it, and a provider-backed channel resolves to a provider.
	 *
	 * This exists because one caller has to know before it commits. The two-step
	 * login refuses a sign-in when a code cannot be delivered, which is right for
	 * a gateway outage and wrong for a configuration that could never have
	 * worked — that combination locks the administrator out of their own site.
	 * It asks here instead, and stands down.
	 *
	 * Whether the gateway will actually accept the request is a different
	 * question, and only a real send answers it.
	 *
	 * @param string $channel Channel key.
	 * @return bool
	 */
	public static function can_send( $channel ) {
		$channel = strtolower( (string) $channel );

		if ( ! Dicex_Connect_Lines::is_supported( $channel ) ) {
			return false;
		}

		if ( ! Dicex_Connect_Region::allows_channel( $channel ) ) {
			return false;
		}

		if ( '' === Dicex_Connect_Lines::get_selected( $channel ) && ! Dicex_Connect_Lines::has_shared_line( $channel ) ) {
			return false;
		}

		$send_types = array( 'sms', 'whatsapp', 'voice' );

		return in_array( $channel, $send_types, true ) || '' !== Dicex_Connect_Lines::provider_for_channel( $channel );
	}

	/**
	 * Whichever of these channels could send right now, in the order given.
	 *
	 * @param array $channels Channel keys.
	 * @return array
	 */
	public static function sendable( $channels ) {
		return array_values( array_filter( (array) $channels, array( __CLASS__, 'can_send' ) ) );
	}

	/**
	 * Tries each channel in turn and stops at the first one that gets through.
	 *
	 * A channel fails for all sorts of reasons — no line configured for it, the
	 * gateway refusing the line, the network being down — and none of them are
	 * worth losing the message over when another channel is available. Whatever
	 * the last channel said is what comes back if every one of them fails, so the
	 * caller still gets a real reason to show.
	 *
	 * @param array  $channels Channel keys, in the order they should be tried.
	 * @param string $target   Mobile number.
	 * @param string $message  Message text; may contain {tag} placeholders.
	 * @param array  $vars     Tag => value replacements.
	 * @return array|WP_Error
	 */
	public static function send_chain( $channels, $target, $message, $vars = array() ) {
		$channels = array_values( array_unique( array_filter( (array) $channels ) ) );

		if ( empty( $channels ) ) {
			return new WP_Error(
				'dicex_connect_no_channel',
				__( 'No sending channel has been chosen.', 'dicex-connect' )
			);
		}

		$last = null;
		$total = count( $channels );

		foreach ( $channels as $index => $channel ) {
			$result = self::send( $channel, $target, $message, $vars );

			if ( ! is_wp_error( $result ) ) {
				/*
				 * Which channel carried it. The caller cannot work this out for
				 * itself — the chain may have fallen through two or three before
				 * this one — and the login screen has to tell the person where to
				 * go and look for their code.
				 *
				 * Namespaced so it cannot be mistaken for, or collide with, a
				 * field the gateway itself returns.
				 */
				if ( is_array( $result ) ) {
					$result['dicex_channel'] = $channel;
				}

				return $result;
			}

			$last = $result;

			if ( $index + 1 < $total ) {
				Dicex_Connect_Logger::log( 'FALLBACK: ' . $channel . ' did not go through, trying ' . $channels[ $index + 1 ] );
			}
		}

		return $last;
	}
	/**
	 * @param string $channel One of Dicex_Connect_Lines::SUPPORTED_CHANNELS.
	 * @param string $target  Mobile number.
	 * @param string $message Message text; may contain {tag} placeholders resolved via $vars.
	 * @param array  $vars    Tag => value replacements applied to $message before sending.
	 * @return array|WP_Error
	 */
	public static function send( $channel, $target, $message, $vars = array() ) {
		$channel = strtolower( $channel );

		if ( ! Dicex_Connect_Lines::is_supported( $channel ) ) {
			return new WP_Error(
				'dicex_connect_channel_unsupported',
				sprintf(
					/* translators: %s: channel name such as telegram, bale */
					__( 'The %s channel is not available through the DiceX API yet.', 'dicex-connect' ),
					$channel
				)
			);
		}

		/*
		 * The region gate belongs here rather than in the admin screens: a card
		 * saved while the region allowed a channel keeps that channel in its list
		 * after the region changes, so hiding a checkbox would not stop the send.
		 * Refusing here is what actually holds.
		 */
		if ( ! Dicex_Connect_Region::allows_channel( $channel ) ) {
			return new WP_Error(
				'dicex_connect_channel_region',
				sprintf(
					/* translators: 1: channel name such as sms, whatsapp. 2: region name such as Iran, GCC */
					__( 'The %1$s channel is not available in the %2$s region.', 'dicex-connect' ),
					$channel,
					Dicex_Connect_Region::label()
				)
			);
		}

		/*
		 * An empty line is a valid request on the three channels DiceX runs a
		 * shared line for, and an impossible one everywhere else.
		 *
		 * SMS, voice and Safir go out on that shared line, so refusing them here
		 * would stop an account that is working perfectly well — which is what
		 * 1.0.16 fixed. WhatsApp, Telegram Bot and Bale Bot have no shared line,
		 * so a send with no line has nothing to go out on: spending a request on
		 * it only turns a clear instruction into an opaque gateway refusal.
		 *
		 * A line that is set and then refused by the gateway is a different thing,
		 * and still comes back as the gateway's own error.
		 */
		$line = Dicex_Connect_Lines::get_selected( $channel );

		if ( '' === $line && ! Dicex_Connect_Lines::has_shared_line( $channel ) ) {
			return new WP_Error(
				'dicex_connect_no_line',
				sprintf(
					/* translators: %s: channel name such as WhatsApp, Telegram Bot */
					__( 'No line is set for %s, and DiceX has no shared line for it. Activate a line for this channel in your DiceX panel, then choose it on the Sender lines tab.', 'dicex-connect' ),
					Dicex_Connect_Lines::label_for( $channel )
				)
			);
		}

		foreach ( $vars as $key => $value ) {
			$message = str_replace( '{' . $key . '}', $value, $message );
		}

		/*
		 * Judged in the canonical form and sent in the channel's own. Those are
		 * not the same string: SMS goes out as 09…, which is deliberately not a
		 * shape is_valid() accepts, so testing what goes on the wire would refuse
		 * every SMS the plugin sends.
		 */
		$canonical = Dicex_Connect_Mobile::normalize( $target );
		$mobile    = Dicex_Connect_Mobile::for_channel( $target, $channel );

		// Normalizing has never been the same as accepting. Without this a number
		// the plugin could not make sense of still went to the gateway, mangled.
		if ( ! Dicex_Connect_Mobile::is_valid( $canonical ) ) {
			return new WP_Error(
				'dicex_connect_bad_number',
				sprintf(
					/* translators: %s: how a number should be written here, such as "Include the country code, in a form like 96891234567." */
					__( 'That destination is not a number this plugin can deliver to. %s', 'dicex-connect' ),
					Dicex_Connect_Mobile::format_hint()
				)
			);
		}

		$send_type_map = array(
			'sms'      => 'Sms',
			'whatsapp' => 'WhatsApp',
			'voice'    => 'Voip',
		);

		if ( isset( $send_type_map[ $channel ] ) ) {
			$path      = Dicex_Connect_Api_Client::PATH_SEND;
			$transport = $send_type_map[ $channel ];
			$body      = array(
				'templateCode' => 0,
				'message'      => $message,
				'code'         => 0,
				'lineNumber'   => $line,
				'mobile'       => $mobile,
				'sendType'     => $transport,
				'length'       => Dicex_Connect_Send_Options::code_length(),
				'lang'         => Dicex_Connect_Send_Options::voice_lang(),
			);
		} else {
			/*
			 * Telegram, Bale and Safir are outside Otp/Send's sendType enum, so they
			 * go through Social/SendMessage instead. Its body is a different shape
			 * (SMSR_SendMessageRequest, read from the live Swagger on 2026-09-01):
			 * receiver, lineNumber, providerName, message, plus optional messageId
			 * and scheduleDate this plugin does not use.
			 */
			$provider = Dicex_Connect_Lines::provider_for_channel( $channel );

			if ( '' === $provider ) {
				return new WP_Error(
					'dicex_connect_channel_unsupported',
					sprintf(
						/* translators: %s: channel name such as telegram, bale */
						__( 'The %s channel is not available through the DiceX API yet.', 'dicex-connect' ),
						$channel
					)
				);
			}

			$path      = Dicex_Connect_Api_Client::PATH_SOCIAL_SEND;
			$transport = $provider;
			$body      = array(
				// TODO: DiceX API — the Swagger types receiver as a plain string and does
				// not say which format a social provider wants. Sending the same
				// normalized 09... number the rest of the plugin uses until a real send
				// through one of these channels proves otherwise.
				'receiver'     => $mobile,
				'lineNumber'   => $line,
				'providerName' => $provider,
				'message'      => $message,
			);
		}

		Dicex_Connect_Logger::log(
			sprintf(
				'SEND START: channel=%s via=%s line=%s to=%s',
				$channel,
				$transport,
				'' !== $line ? $line : '(empty)',
				Dicex_Connect_Mobile::mask( $mobile )
			)
		);

		$response = Dicex_Connect_Api_Client::post( $path, $body );

		if ( is_wp_error( $response ) ) {
			Dicex_Connect_Logger::log( 'SEND FAILED (line=' . $line . '): ' . $response->get_error_message() );
		} else {
			Dicex_Connect_Logger::log( 'SEND OK: ' . wp_json_encode( $response ) );
		}

		return $response;
	}
}
