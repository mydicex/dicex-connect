<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings that shape what goes out: how long a login code is, which language a
 * voice call speaks, and whether messages carry their extra detail lines.
 *
 * Three settings rather than three classes because they answer one question —
 * what does a message look like when it leaves — and because they are saved from
 * the same screen. They live in the single `dicex_connect_options` array like
 * every other setting in this plugin.
 *
 * Core layer: this reads and writes options and validates them. It never renders
 * anything and never decides when to send.
 */
class Dicex_Connect_Send_Options {

	/**
	 * How many digits a login code may have.
	 *
	 * The gateway does not say. `SMOR_SendRequest.length` is a bare int32 in the
	 * Swagger with no minimum and no maximum, so this range is the plugin's own
	 * judgement: four is the shortest anyone should accept, and eight is long
	 * enough that nobody will read it back over the phone correctly anyway.
	 */
	const MIN_LENGTH     = 4;
	const MAX_LENGTH     = 8;
	const DEFAULT_LENGTH = 6;

	/**
	 * Languages a voice call can speak.
	 *
	 * `lang` is an enum of exactly `fa` and `en` — read from the live Swagger on
	 * 2026-09-08, unchanged since the first read on 2026-09-01. Arabic and German
	 * are offered but disabled: the plugin already speaks to GCC and European
	 * accounts, so they are the two worth asking for, and showing them greyed out
	 * says "not yet" where silence would say "never".
	 *
	 * When the gateway adds one, move it from UPCOMING to SUPPORTED. Nothing else
	 * changes — the screen builds itself from these two lists.
	 */
	const SUPPORTED_LANGS = array( 'fa', 'en' );
	const UPCOMING_LANGS  = array( 'ar', 'de' );
	const DEFAULT_LANG    = 'fa';

	/**
	 * @return int A length inside the allowed range, always.
	 */
	public static function code_length() {
		$options = get_option( 'dicex_connect_options', array() );
		$length  = isset( $options['otp_length'] ) ? (int) $options['otp_length'] : self::DEFAULT_LENGTH;

		return self::clean_length( $length );
	}

	/**
	 * @param mixed $length
	 * @return int
	 */
	public static function clean_length( $length ) {
		$length = (int) $length;

		if ( $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
			return self::DEFAULT_LENGTH;
		}

		return $length;
	}

	/**
	 * @param mixed $length
	 * @return int What was actually stored.
	 */
	public static function save_code_length( $length ) {
		$length = self::clean_length( $length );

		$options               = get_option( 'dicex_connect_options', array() );
		$options['otp_length'] = $length;
		update_option( 'dicex_connect_options', $options, false );

		return $length;
	}

	/**
	 * @return string A locale the gateway actually accepts.
	 */
	public static function voice_lang() {
		$options = get_option( 'dicex_connect_options', array() );
		$lang    = isset( $options['voice_lang'] ) ? (string) $options['voice_lang'] : self::DEFAULT_LANG;

		return self::clean_lang( $lang );
	}

	/**
	 * A language the gateway cannot speak yet falls back rather than being sent —
	 * the enum would reject it and take the whole message down with it.
	 *
	 * @param mixed $lang
	 * @return string
	 */
	public static function clean_lang( $lang ) {
		$lang = strtolower( trim( (string) $lang ) );

		return in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : self::DEFAULT_LANG;
	}

	/**
	 * @param mixed $lang
	 * @return string What was actually stored.
	 */
	public static function save_voice_lang( $lang ) {
		$lang = self::clean_lang( $lang );

		$options               = get_option( 'dicex_connect_options', array() );
		$options['voice_lang'] = $lang;
		update_option( 'dicex_connect_options', $options, false );

		return $lang;
	}

	/**
	 * The language the plugin's own words in a message are written in.
	 *
	 * Separate from the voice language, which the gateway constrains to two, and
	 * separate from the template — the admin writes that in whatever language
	 * they like and the plugin never touches it.
	 *
	 * What this covers is the text the plugin adds for itself: when a code
	 * expires, when an event happened, and the month names in a date. Those go
	 * through the plugin's translations, so without a setting they come out in
	 * whichever language the request happened to be running in — the admin's on
	 * an admin action, the site's on a storefront one. Neither is necessarily the
	 * language of the person receiving the message.
	 *
	 * '' means follow WordPress, which is the old behaviour and the default.
	 *
	 * @return string A locale, or '' to follow WordPress.
	 */
	public static function message_lang() {
		$options = get_option( 'dicex_connect_options', array() );
		$lang    = isset( $options['message_lang'] ) ? (string) $options['message_lang'] : '';

		return self::clean_message_lang( $lang );
	}

	/**
	 * A locale the site does not have installed cannot be switched to, so it
	 * falls back to following WordPress rather than silently doing nothing.
	 *
	 * @param mixed $lang
	 * @return string
	 */
	public static function clean_message_lang( $lang ) {
		$lang = trim( (string) $lang );

		return array_key_exists( $lang, self::message_lang_choices() ) ? $lang : '';
	}

	/**
	 * @param mixed $lang
	 * @return string What was actually stored.
	 */
	public static function save_message_lang( $lang ) {
		$lang = self::clean_message_lang( $lang );

		$options                 = get_option( 'dicex_connect_options', array() );
		$options['message_lang'] = $lang;
		update_option( 'dicex_connect_options', $options, false );

		return $lang;
	}

	/**
	 * Follow WordPress, plus every language this site actually has installed.
	 *
	 * Offering a language WordPress cannot load would be offering nothing:
	 * switch_to_locale() needs the translations to be on disk.
	 *
	 * @return array locale => label
	 */
	public static function message_lang_choices() {
		$choices = array( '' => __( 'Follow WordPress', 'dicex-connect' ) );

		// en_US is the source language and is never in the installed list.
		$choices['en_US'] = 'English (United States)';

		$installed = function_exists( 'get_available_languages' ) ? get_available_languages() : array();

		foreach ( $installed as $locale ) {
			$choices[ $locale ] = function_exists( 'locale_get_display_name' )
				? locale_get_display_name( $locale, $locale )
				: $locale;
		}

		return $choices;
	}

	/**
	 * Whether messages carry their extra lines — when a code stops working, when
	 * an event happened.
	 *
	 * On by default, and a setting rather than a rule, because it is not free.
	 * Persian text goes out as UCS-2, which fits 70 characters in one SMS segment
	 * and 67 per segment after that, so two extra lines can turn one paid message
	 * into two. On Telegram, WhatsApp and Bale it costs nothing.
	 *
	 * @return bool
	 */
	public static function rich_messages() {
		$options = get_option( 'dicex_connect_options', array() );

		return isset( $options['rich_messages'] ) ? (bool) $options['rich_messages'] : true;
	}

	/**
	 * @param mixed $on
	 * @return bool What was actually stored.
	 */
	public static function save_rich_messages( $on ) {
		$on = (bool) $on;

		$options                  = get_option( 'dicex_connect_options', array() );
		$options['rich_messages'] = $on;
		update_option( 'dicex_connect_options', $options, false );

		return $on;
	}

	/**
	 * Labels for the language setting, each written in its own language.
	 *
	 * @return array locale => array( label, supported )
	 */
	public static function lang_choices() {
		$names = array(
			'fa' => 'فارسی',
			'en' => 'English',
			'ar' => 'العربية',
			'de' => 'Deutsch',
		);

		$choices = array();

		foreach ( array_merge( self::SUPPORTED_LANGS, self::UPCOMING_LANGS ) as $code ) {
			$choices[ $code ] = array(
				'label'     => isset( $names[ $code ] ) ? $names[ $code ] : $code,
				'supported' => in_array( $code, self::SUPPORTED_LANGS, true ),
			);
		}

		return $choices;
	}
}
