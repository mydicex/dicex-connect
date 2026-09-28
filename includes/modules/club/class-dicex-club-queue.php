<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the club's background work runs.
 *
 * Action Scheduler when it is there — it ships inside WooCommerce, keeps a record
 * of every run and retries on its own. WP-Cron otherwise. Both are started by
 * visits to the site: Action Scheduler's own FAQ says it is initiated by WP-Cron
 * and by admin requests. On a quiet site a daily run happens at the first visit
 * after its time, unless the host calls wp-cron.php on a real schedule.
 *
 * Action Scheduler's functions may not be called before init at priority 1,
 * which is when it sets itself up; did_action( 'action_scheduler_init' ) is the
 * check. Everything here is called later than that.
 */
class Dicex_Connect_Club_Queue {

	const GROUP = 'dicex-connect';

	const HOOK_TICK = 'dicex_connect_club_tick';

	const HOOK_DAILY = 'dicex_connect_club_daily';

	/**
	 * @return bool
	 */
	public static function uses_action_scheduler() {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_get_scheduled_actions' )
			&& did_action( 'action_scheduler_init' );
	}

	/**
	 * Asks for a run soon, unless one is already waiting.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function tick_soon( $delay = 0 ) {
		$when = time() + max( 0, (int) $delay );

		if ( self::uses_action_scheduler() ) {
			// Pending only: a run that is under way right now may already have
			// decided there is nothing left, so it does not count as waiting.
			$waiting = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK_TICK,
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);

			if ( empty( $waiting ) ) {
				as_schedule_single_action( $when, self::HOOK_TICK, array(), self::GROUP );
			}

			return;
		}

		if ( false === wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_single_event( $when, self::HOOK_TICK );
		}
	}

	/**
	 * Schedules the next daily run, replacing any that is set.
	 *
	 * One run at a time, each scheduling the next: a single event rather than a
	 * repeating one, so a change of time, of time zone, or a daylight-saving jump
	 * is picked up the next day rather than drifting.
	 *
	 * @param string $time HH:MM in the site's time zone.
	 */
	public static function schedule_daily( $time ) {
		self::unschedule_daily();

		$when = self::next_daily_timestamp( $time );

		if ( self::uses_action_scheduler() ) {
			as_schedule_single_action( $when, self::HOOK_DAILY, array(), self::GROUP );
			return;
		}

		wp_schedule_single_event( $when, self::HOOK_DAILY );
	}

	/**
	 * Makes sure a daily run is booked with whichever scheduler is running now.
	 *
	 * WooCommerce can be switched off after a run was booked with Action
	 * Scheduler, which then never runs it.
	 *
	 * @param string $time
	 */
	public static function ensure_daily( $time ) {
		$booked = self::uses_action_scheduler()
			? ! empty(
				as_get_scheduled_actions(
					array(
						'hook'     => self::HOOK_DAILY,
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 1,
					),
					'ids'
				)
			)
			: false !== wp_next_scheduled( self::HOOK_DAILY );

		if ( ! $booked ) {
			self::schedule_daily( $time );
		}
	}

	public static function unschedule_daily() {
		wp_clear_scheduled_hook( self::HOOK_DAILY );

		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( self::HOOK_DAILY, array(), self::GROUP );
		}
	}

	/**
	 * Nothing of the club's left scheduled anywhere.
	 */
	public static function unschedule_all() {
		self::unschedule_daily();

		wp_clear_scheduled_hook( self::HOOK_TICK );

		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( self::HOOK_TICK, array(), self::GROUP );
		}
	}

	/**
	 * @param string $time HH:MM in the site's time zone.
	 * @return int The next time that clock time comes round, as a timestamp.
	 */
	public static function next_daily_timestamp( $time ) {
		$parts = explode( ':', Dicex_Connect_Club_Settings::clean_time( $time ) );
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$run   = $now->setTime( (int) $parts[0], (int) $parts[1] );

		if ( $run <= $now ) {
			$run = $run->modify( '+1 day' );
		}

		return $run->getTimestamp();
	}

	/**
	 * @return int|false When the next daily run is due, if one is booked.
	 */
	public static function next_daily() {
		if ( self::uses_action_scheduler() && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK_DAILY, array(), self::GROUP );

			return is_int( $next ) ? $next : false;
		}

		return wp_next_scheduled( self::HOOK_DAILY );
	}
}
