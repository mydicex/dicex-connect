<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the plugin's own changelog so a screen can show it.
 *
 * Core layer: returns arrays, renders nothing.
 *
 * There is one changelog, `changelog.txt`, and WordPress.org reads the same file
 * format for the directory listing. Parsing it rather than keeping a second copy
 * in PHP is what stops the two from disagreeing — a release note is written once.
 */
class Dicex_Connect_Changelog {

	const FILE = 'changelog.txt';

	/**
	 * The released versions, newest first.
	 *
	 * A line that is only bold text, such as **Security**, heads the bullets under
	 * it. It starts a new group rather than becoming a line of its own; the text
	 * before the first one is a group with no title.
	 *
	 * @param int $limit How many to return; 0 for all.
	 * @return array List of array( version => string, lines => string[],
	 *               groups => list of array( title => string, lines => string[] ) ).
	 */
	public static function entries( $limit = 0 ) {
		$path = DICEX_CONNECT_DIR . self::FILE;

		if ( ! is_readable( $path ) ) {
			return array();
		}

		// Small file, read once per request that asks for it. Not cached in an
		// option: an option would be one more thing to invalidate on update, and
		// this is only ever read by a screen somebody opened on purpose.
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file inside the plugin, not a remote resource.

		if ( false === $raw ) {
			return array();
		}

		$entries = array();
		$current = null;

		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$line = rtrim( $line );

			if ( preg_match( '/^=\s*(\d+\.\d+\.\d+)\s*=$/', trim( $line ), $found ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}

				$current = array(
					'version' => $found[1],
					'lines'   => array(),
					'groups'  => array(),
				);

				continue;
			}

			if ( null === $current ) {
				continue; // Header text above the first version.
			}

			// Entries are markdown bullets; anything else in the block is prose.
			$text = ltrim( $line );

			if ( '' === $text ) {
				continue;
			}

			if ( preg_match( '/^\*\*([^*]+)\*\*$/', $text, $heading ) ) {
				$current['groups'][] = array(
					'title' => trim( $heading[1] ),
					'lines' => array(),
				);

				continue;
			}

			$text = ltrim( $text, '*- ' );

			if ( empty( $current['groups'] ) ) {
				$current['groups'][] = array(
					'title' => '',
					'lines' => array(),
				);
			}

			$last = count( $current['groups'] ) - 1;

			$current['lines'][]                    = $text;
			$current['groups'][ $last ]['lines'][] = $text;
		}

		if ( null !== $current ) {
			$entries[] = $current;
		}

		if ( $limit > 0 ) {
			$entries = array_slice( $entries, 0, (int) $limit );
		}

		return $entries;
	}

	/**
	 * @return string The version this copy of the plugin is.
	 */
	public static function current_version() {
		return defined( 'DICEX_CONNECT_VERSION' ) ? DICEX_CONNECT_VERSION : '';
	}

	/**
	 * Whether the newest entry in the changelog is the version now running.
	 *
	 * They disagree when a release forgot its changelog line — worth knowing on a
	 * screen that is showing one of them as the truth.
	 *
	 * @return bool
	 */
	public static function is_current() {
		$entries = self::entries( 1 );

		return ! empty( $entries ) && $entries[0]['version'] === self::current_version();
	}
}
