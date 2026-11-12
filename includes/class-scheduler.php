<?php
/**
 * Scheduled scans: on chosen weekdays at chosen hours, in the site's timezone.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * One single cron event at a time: after each run, the next matching slot is scheduled. Settings
 * changes reschedule it (update_option hook, not the sanitize callback). The callback is reachable
 * only through its cron hook and does not depend on the current user.
 */
final class Scheduler extends Singleton {

	/**
	 * Cron hook.
	 */
	const HOOK = 'redirect_inspector_scheduled_scan';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'ensure' ] );
		add_action( 'add_option_' . Settings::OPTION, [ self::class, 'reschedule' ] );
		add_action( 'update_option_' . Settings::OPTION, [ self::class, 'reschedule' ] );
	}

	/**
	 * Scheduled event exists exactly when scheduling is on (cheap: the cron array is autoloaded).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( ! Installer::is_installed() ) {
			return;
		}

		$enabled   = Settings::schedule()['enabled'];
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( $enabled && ! $scheduled ) {
			self::reschedule();
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Replace the pending event with the next matching slot (or none).
	 *
	 * @return void
	 */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );

		$next = self::next_run( Settings::schedule() );
		if ( null !== $next ) {
			wp_schedule_single_event( $next, self::HOOK );
		}
	}

	/**
	 * Cron callback: scan, then schedule the next slot whatever happened.
	 *
	 * @return void
	 */
	public static function run(): void {
		try {
			Scanner::run( 'cron' );
		} catch ( \RuntimeException $error ) {
			// A running manual scan (409) or a failure is recorded on the scan row itself; skip this slot.
			unset( $error );
		} finally {
			self::reschedule();
		}
	}

	/**
	 * Next timestamp, strictly after $after, whose site-time weekday and hour are selected.
	 *
	 * @param array{enabled:bool, days:int[], hours:int[]} $schedule Schedule.
	 * @param int|null                                     $after    Unix time (default: now).
	 *
	 * @return int|null
	 */
	public static function next_run( array $schedule, ?int $after = null ): ?int {
		if ( empty( $schedule['enabled'] ) || ! $schedule['days'] || ! $schedule['hours'] ) {
			return null;
		}

		$slot = ( new \DateTimeImmutable( '@' . ( $after ?? time() ) ) )->setTimezone( wp_timezone() );
		$slot = $slot->setTime( (int) $slot->format( 'G' ), 0 )->modify( '+1 hour' );

		// At most 8 days × 24 hours to try.
		for ( $i = 0; $i < 192; $i++ ) {
			if ( in_array( (int) $slot->format( 'w' ), $schedule['days'], true ) && in_array( (int) $slot->format( 'G' ), $schedule['hours'], true ) ) {
				return $slot->getTimestamp();
			}
			$slot = $slot->modify( '+1 hour' );
		}

		return null;
	}
}
