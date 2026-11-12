<?php
/**
 * Scheduled cleanup of old scans and issues.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Weekly cleanup, Sunday around 03:00 site time (as agreed). Per site: each site has its own
 * tables and its own cron. The callback is reachable only through its cron hook (and WP-CLI).
 */
final class Retention extends Singleton {

	/**
	 * Cron hook.
	 */
	const HOOK = 'redirect_inspector_cleanup';

	/**
	 * Cleanup frequency (fixed, as agreed).
	 */
	const FREQUENCY = 'weekly';

	/**
	 * Rows per delete query.
	 */
	const BATCH = 1000;

	/**
	 * Mutex option so overlapping runs (cron + "purge" + CLI) never interleave.
	 */
	const LOCK_OPTION = 'redirect_inspector_cleanup_lock';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'init', [ self::class, 'schedule' ] );
		add_action( self::HOOK, [ self::class, 'run_scheduled' ] );
	}

	/**
	 * Schedule the cleanup on Sunday 03:00 site time; move an event that drifted elsewhere.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! Installer::is_installed() ) {
			return;
		}

		$scheduled = wp_next_scheduled( self::HOOK );

		if ( $scheduled ) {
			$moment = ( new \DateTimeImmutable( '@' . $scheduled ) )->setTimezone( wp_timezone() );
			if ( self::FREQUENCY === wp_get_schedule( self::HOOK ) && '03' === $moment->format( 'H' ) && '0' === $moment->format( 'w' ) ) {
				return;
			}

			wp_clear_scheduled_hook( self::HOOK );
		}

		wp_schedule_event( self::next_run(), self::FREQUENCY, self::HOOK );
	}

	/**
	 * Next Sunday 03:00 in the site's timezone, as a UTC timestamp.
	 *
	 * @return int
	 */
	public static function next_run(): int {
		$next = new \DateTimeImmutable( 'sunday 03:00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 week' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		self::cleanup();
	}

	/**
	 * Delete scans (and their issues) older than the retention period, in batches.
	 *
	 * @param int|null $older_than_days Override the retention (null = setting). 0 = everything.
	 *
	 * @return int Number of scans deleted.
	 */
	public static function cleanup( $older_than_days = null ): int {
		if ( ! Installer::is_installed() ) {
			return 0;
		}

		// Atomic mutex; a lock older than 10 minutes is stale.
		if ( ! Lock::acquire( self::LOCK_OPTION, 10 * MINUTE_IN_SECONDS ) ) {
			return 0;
		}

		try {
			return self::delete_older_than( null === $older_than_days || '' === $older_than_days ? Settings::retention_days() : absint( $older_than_days ) );
		} finally {
			Lock::release( self::LOCK_OPTION );
		}
	}

	/**
	 * Do the batched delete.
	 *
	 * @param int $days Age in days; 0 deletes everything.
	 *
	 * @return int
	 */
	private static function delete_older_than( int $days ): int {
		global $wpdb;

		$scans   = Installer::scans_table();
		$issues  = Installer::issues_table();
		$cutoff  = 0 === $days ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE started_at < %s AND status <> 'running' ORDER BY id LIMIT %d", $scans, $cutoff, self::BATCH ) ) );
			if ( ! $ids ) {
				break;
			}

			$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Placeholders built from count.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE scan_id IN ({$marks})", $issues, ...$ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Placeholders built from count.
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$marks})", $scans, ...$ids ) );
			$full     = self::BATCH === count( $ids );
		} while ( $full );

		$fixes = Installer::fixes_table();
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			$batch = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT %d', $fixes, $cutoff, self::BATCH ) );
		} while ( self::BATCH === $batch );

		return $deleted;
	}

	/**
	 * Human description of the retention, for the dashboard.
	 *
	 * @return string
	 */
	public static function describe(): string {
		return sprintf(
			/* translators: %d: number of days. */
			_n( 'Scan history is kept for %d day; older scans are removed every Sunday around 03:00.', 'Scan history is kept for %d days; older scans are removed every Sunday around 03:00.', Settings::retention_days(), 'redirect-inspector' ),
			Settings::retention_days()
		);
	}
}
