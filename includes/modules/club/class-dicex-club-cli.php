<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Customer Club from the command line: wp dicex club <command>.
 *
 * For large stores, and for sites that run their scheduled tasks from the
 * server's own cron: an import of a hundred thousand orders is better watched
 * in a terminal than in a browser tab. Every command does what the Customer
 * Club tab does, through the same classes, so nothing here reaches DiceX by any
 * other road — the first import still waits for release, a refused customer
 * still waits for retry.
 *
 * Loaded only while WP-CLI runs: see dicex-connect.php. The help text below is
 * WP-CLI's own and stays in English, as WordPress's own commands do; what a
 * command prints is translated.
 */
class Dicex_Connect_Club_CLI {

	/** Seconds of work in one round. The command line has no time limit; the club's lock is taken per round. */
	const ROUND = 20;

	/** How long to wait for a scheduled run that holds the lock, in five-second steps. */
	const BUSY_TRIES = 24;

	/**
	 * Shows where the club stands: members, what is waiting, levels and groups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp dicex club status
	 *     wp dicex club status --format=json
	 *
	 * @param array $args       Positional arguments; none.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$settings = Dicex_Connect_Club_Settings::get();
		$data     = $this->status_data( $settings );

		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$rows = array(
			array(
				'item'  => __( 'Switched on', 'dicex-connect' ),
				'value' => $data['enabled'] ? __( 'Yes', 'dicex-connect' ) : __( 'No', 'dicex-connect' ),
			),
			array(
				'item'  => __( 'Members', 'dicex-connect' ),
				'value' => number_format_i18n( $data['members'] ),
			),
			array(
				'item'  => __( 'In DiceX', 'dicex-connect' ),
				'value' => number_format_i18n( $data['in_dicex'] ),
			),
			array(
				'item'  => __( 'Waiting', 'dicex-connect' ),
				'value' => number_format_i18n( $data['waiting'] ),
			),
			array(
				'item'  => __( 'Refused by DiceX', 'dicex-connect' ),
				'value' => number_format_i18n( $data['refused'] ),
			),
			array(
				'item'  => __( 'Group memberships on the way', 'dicex-connect' ),
				'value' => number_format_i18n( $data['group_waiting'] ),
			),
			array(
				'item'  => __( 'Import', 'dicex-connect' ),
				'value' => $data['importing']
					? sprintf(
						/* translators: 1: how many orders and users have been read, 2: how many there are */
						__( 'Running: %1$s of %2$s read', 'dicex-connect' ),
						number_format_i18n( $data['import_done'] ),
						number_format_i18n( $data['import_total'] )
					)
					: ( $data['imported_once'] ? __( 'Done', 'dicex-connect' ) : __( 'Never run', 'dicex-connect' ) ),
			),
			array(
				'item'  => __( 'Waiting for your release', 'dicex-connect' ),
				'value' => $data['hold'] ? __( 'Yes — run wp dicex club release', 'dicex-connect' ) : __( 'No', 'dicex-connect' ),
			),
			array(
				'item'  => __( 'Paused', 'dicex-connect' ),
				'value' => '' === $data['paused'] ? __( 'No', 'dicex-connect' ) : $data['paused'],
			),
			array(
				'item'  => __( 'Last sent', 'dicex-connect' ),
				'value' => $data['last_sent'] > 0 ? wp_date( 'Y-m-d H:i', $data['last_sent'] ) : __( 'Not yet', 'dicex-connect' ),
			),
			array(
				'item'  => __( 'Next daily run', 'dicex-connect' ),
				'value' => $data['next_daily'] > 0 ? wp_date( 'Y-m-d H:i', $data['next_daily'] ) : '—',
			),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );

		if ( ! empty( $data['levels'] ) ) {
			WP_CLI::line( '' );
			WP_CLI\Utils\format_items( 'table', $data['levels'], array( 'level', 'members' ) );
		}

		if ( ! empty( $data['groups'] ) ) {
			WP_CLI::line( '' );
			WP_CLI\Utils\format_items( 'table', $data['groups'], array( 'group', 'members', 'waiting', 'no_longer_matching' ) );
		}
	}

	/**
	 * Reads your customers into the club, works out their levels and sends them to DiceX.
	 *
	 * The first import reads everybody and then waits: nothing reaches DiceX
	 * until you have checked the counts and run `wp dicex club release`. Later
	 * imports send as they go.
	 *
	 * ## OPTIONS
	 *
	 * [--recent]
	 * : Read only the orders changed in the last day, instead of every order and user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dicex club import
	 *     wp dicex club import --recent
	 *
	 * @param array $args       Positional arguments; none.
	 * @param array $assoc_args Named arguments.
	 */
	public function import( $args, $assoc_args ) {
		$this->require_enabled();

		$progress = Dicex_Connect_Club_Sync::progress();

		if ( ! empty( $progress['import']['running'] ) ) {
			WP_CLI::log( __( 'An import is already under way. Carrying it on.', 'dicex-connect' ) );
		} elseif ( ! empty( $assoc_args['recent'] ) ) {
			Dicex_Connect_Club_Sync::start_import( 'recent', time() - DAY_IN_SECONDS );
		} else {
			Dicex_Connect_Club_Sync::start_import( 'full' );
		}

		$progress = $this->work();

		$this->finish( $progress );
	}

	/**
	 * Works through everything waiting: members to work out again, to send, and to add to groups.
	 *
	 * The same work the club's scheduled runs do, all at once. Stops when there
	 * is nothing left, or when DiceX asks the club to wait.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dicex club sync
	 *
	 * @param array $args       Positional arguments; none.
	 * @param array $assoc_args Named arguments; none.
	 */
	public function sync( $args, $assoc_args ) {
		$this->require_enabled();

		$this->finish( $this->work() );
	}

	/**
	 * Puts the customers and group memberships DiceX refused back in the queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dicex club retry && wp dicex club sync
	 *
	 * @param array $args       Positional arguments; none.
	 * @param array $assoc_args Named arguments; none.
	 */
	public function retry( $args, $assoc_args ) {
		$this->require_enabled();

		$customers = Dicex_Connect_Club_Store::retry_errors();
		$groups    = Dicex_Connect_Club_Store::retry_group_errors();

		WP_CLI::success(
			sprintf(
				/* translators: 1: how many customers will be sent again, 2: how many group memberships */
				__( 'Back in the queue: %1$s customers and %2$s group memberships. Run wp dicex club sync to send them now.', 'dicex-connect' ),
				number_format_i18n( $customers ),
				number_format_i18n( $groups )
			)
		);
	}

	/**
	 * Sends the first import to DiceX, once you have checked it.
	 *
	 * A number that reaches DiceX cannot be taken back out, which is why the
	 * first import waits for this.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Send without asking first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dicex club release
	 *
	 * @param array $args       Positional arguments; none.
	 * @param array $assoc_args Named arguments.
	 */
	public function release( $args, $assoc_args ) {
		$this->require_enabled();

		if ( empty( Dicex_Connect_Club_Sync::progress()['hold'] ) ) {
			WP_CLI::warning( __( 'Nothing is waiting for your release.', 'dicex-connect' ) );
			return;
		}

		WP_CLI::confirm( __( 'Send every customer in the club to DiceX now? A number that reaches DiceX cannot be taken back out.', 'dicex-connect' ), $assoc_args );

		Dicex_Connect_Club_Sync::release_hold();

		$this->finish( $this->work() );
	}

	/**
	 * Stops the command, with the reason, when the club cannot run.
	 */
	private function require_enabled() {
		if ( ! Dicex_Connect_Club_Settings::is_enabled() ) {
			WP_CLI::error( __( 'The Customer Club is switched off, or not set up yet. Switch it on in the DiceX > Customer Club tab.', 'dicex-connect' ) );
		}

		Dicex_Connect_Club_Store::maybe_install();
	}

	/**
	 * Runs the club's own rounds until nothing is left, or until DiceX asks it to
	 * wait.
	 *
	 * @return array The club's progress after the last round.
	 */
	private function work() {
		$busy = 0;

		do {
			$progress = Dicex_Connect_Club_Sync::tick( self::ROUND );

			if ( ! empty( $progress['busy'] ) ) {
				// A scheduled run holds the lock: let it finish its round.
				if ( ++$busy > self::BUSY_TRIES ) {
					WP_CLI::error( __( 'Another run of the club has been working for two minutes. Try again when it is done.', 'dicex-connect' ) );
				}

				sleep( 5 );
				continue;
			}

			$busy = 0;

			WP_CLI::log( $this->progress_line( $progress ) );

			if ( (int) $progress['backoff_until'] > time() ) {
				break;
			}
		} while ( ! empty( $progress['more'] ) );

		return $progress;
	}

	/**
	 * Says how a run ended.
	 *
	 * @param array $progress
	 */
	private function finish( $progress ) {
		if ( (int) $progress['backoff_until'] > time() ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: the reason DiceX gave, 2: a length of time, such as "2 minutes" */
					__( 'Sending is paused: %1$s. The club tries again by itself in %2$s.', 'dicex-connect' ),
					$progress['last_error'],
					human_time_diff( time(), (int) $progress['backoff_until'] )
				)
			);
			return;
		}

		if ( ! empty( $progress['hold'] ) ) {
			WP_CLI::success( __( 'Read and worked out. Nothing has been sent yet: check the counts with wp dicex club status, then run wp dicex club release.', 'dicex-connect' ) );
			return;
		}

		if ( empty( $progress['can_send'] ) ) {
			WP_CLI::warning( __( 'Worked out, but nothing can be sent without an API key. Add one in the DiceX > Connection tab.', 'dicex-connect' ) );
			return;
		}

		WP_CLI::success( __( 'The club is up to date.', 'dicex-connect' ) );
	}

	/**
	 * One line about a round.
	 *
	 * @param array $progress
	 * @return string
	 */
	private function progress_line( $progress ) {
		$import = $progress['import'];

		if ( ! empty( $import['running'] ) ) {
			return sprintf(
				/* translators: 1: orders and users read so far, 2: how many there are */
				__( 'Reading: %1$s of %2$s', 'dicex-connect' ),
				number_format_i18n( (int) $import['orders_done'] + (int) $import['users_done'] ),
				number_format_i18n( max( (int) $import['orders_total'] + (int) $import['users_total'], (int) $import['orders_done'] + (int) $import['users_done'] ) )
			);
		}

		$statuses = (array) $progress['statuses'];

		return sprintf(
			/* translators: 1: members still to work out, 2: members waiting to be sent, 3: members DiceX has, 4: group memberships on the way */
			__( 'To work out: %1$s · to send: %2$s · in DiceX: %3$s · group memberships on the way: %4$s', 'dicex-connect' ),
			number_format_i18n( (int) $progress['dirty'] ),
			number_format_i18n( (int) $progress['waiting'] ),
			number_format_i18n( isset( $statuses['synced'] ) ? (int) $statuses['synced'] : 0 ),
			number_format_i18n( (int) $progress['groups'] )
		);
	}

	/**
	 * Everything status prints, as plain values.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function status_data( $settings ) {
		$exists   = Dicex_Connect_Club_Store::exists();
		$progress = Dicex_Connect_Club_Sync::progress();
		$statuses = (array) $progress['statuses'];
		$import   = $progress['import'];
		$by_level = $exists ? Dicex_Connect_Club_Store::counts_by_level() : array();
		$in_group = $exists ? Dicex_Connect_Club_Store::group_counts() : array();
		$levels   = array();
		$groups   = array();

		foreach ( $settings['levels'] as $level ) {
			$levels[] = array(
				'level'   => $level['name'],
				'members' => isset( $by_level[ $level['id'] ] ) ? (int) $by_level[ $level['id'] ] : 0,
			);
		}

		foreach ( $settings['groups'] as $group ) {
			$count    = isset( $in_group[ $group['id'] ] ) ? $in_group[ $group['id'] ] : array();
			$groups[] = array(
				'group'              => $group['name'],
				'members'            => isset( $count['in'] ) ? (int) $count['in'] : 0,
				'waiting'            => isset( $count['waiting'] ) ? (int) $count['waiting'] : 0,
				'no_longer_matching' => isset( $count['stale'] ) ? (int) $count['stale'] : 0,
			);
		}

		$next = Dicex_Connect_Club_Settings::is_enabled() ? Dicex_Connect_Club_Queue::next_daily() : false;

		return array(
			'enabled'       => ! empty( $settings['enabled'] ),
			'members'       => (int) array_sum( $statuses ),
			'in_dicex'      => isset( $statuses['synced'] ) ? (int) $statuses['synced'] : 0,
			'waiting'       => (int) $progress['waiting'] + (int) $progress['dirty'],
			'refused'       => isset( $statuses['error'] ) ? (int) $statuses['error'] : 0,
			'group_waiting' => (int) $progress['groups'],
			'importing'     => ! empty( $import['running'] ),
			'import_done'   => (int) $import['orders_done'] + (int) $import['users_done'],
			'import_total'  => max( (int) $import['orders_total'] + (int) $import['users_total'], (int) $import['orders_done'] + (int) $import['users_done'] ),
			'imported_once' => ! empty( $progress['imported_once'] ),
			'hold'          => ! empty( $progress['hold'] ),
			'paused'        => (int) $progress['backoff_until'] > time() ? (string) $progress['last_error'] : '',
			'last_sent'     => (int) $progress['last_sent'],
			'next_daily'    => is_int( $next ) ? $next : 0,
			'levels'        => $levels,
			'groups'        => $groups,
		);
	}
}
