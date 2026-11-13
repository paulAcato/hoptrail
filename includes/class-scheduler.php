<?php
/**
 * Scheduled scans: on chosen weekdays at chosen hours, in the site's timezone.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

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
	const HOOK = 'hoptrail_scheduled_scan';

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
	 * Next timestamp, strictly after $after, that matches the schedule in site time:
	 * daily (any day), weekly (selected weekdays) or monthly (day of month; 0 = last day).
	 *
	 * @param array<string, mixed> $schedule Schedule (Settings::sanitize_schedule()).
	 * @param int|null             $after    Unix time (default: now).
	 *
	 * @return int|null
	 */
	public static function next_run( array $schedule, ?int $after = null ): ?int {
		$frequency = (string) ( $schedule['frequency'] ?? 'weekly' );
		$hours     = (array) ( $schedule['hours'] ?? [] );
		$days      = (array) ( $schedule['days'] ?? [] );

		if ( empty( $schedule['enabled'] ) || ! $hours || ( 'weekly' === $frequency && ! $days ) ) {
			return null;
		}

		$slot = ( new \DateTimeImmutable( '@' . ( $after ?? time() ) ) )->setTimezone( wp_timezone() );
		$slot = $slot->setTime( (int) $slot->format( 'G' ), 0 )->modify( '+1 hour' );

		// Up to two months of hours to try (a monthly schedule's next slot is at most ~31 days away).
		for ( $i = 0; $i < 62 * 24; $i++ ) {
			if ( in_array( (int) $slot->format( 'G' ), $hours, true ) && self::day_matches( $slot, $frequency, $days, (int) ( $schedule['month_day'] ?? 1 ) ) ) {
				return $slot->getTimestamp();
			}
			$slot = $slot->modify( '+1 hour' );
		}

		return null;
	}

	/**
	 * Whether a site-time moment falls on a scheduled day.
	 *
	 * @param \DateTimeImmutable $moment    Moment in site time.
	 * @param string             $frequency daily|weekly|monthly.
	 * @param int[]              $days      Weekdays (weekly).
	 * @param int                $month_day Day of the month, 0 = last (monthly).
	 *
	 * @return bool
	 */
	private static function day_matches( \DateTimeImmutable $moment, string $frequency, array $days, int $month_day ): bool {
		return match ( $frequency ) {
			'daily'   => true,
			'monthly' => (int) $moment->format( 'j' ) === ( 0 === $month_day ? (int) $moment->format( 't' ) : $month_day ),
			default   => in_array( (int) $moment->format( 'w' ), $days, true ),
		};
	}
}
