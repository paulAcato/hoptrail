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
			if ( null !== $older_than_days && '' !== $older_than_days ) {
				return self::delete_older_than( absint( $older_than_days ) );
			}

			$settings = Settings::all();
			if ( 'count' === $settings['retention_mode'] ) {
				// The change log keeps following the day-based retention.
				self::delete_fixes_older_than( $settings['retention_days'] );
				return self::delete_beyond_newest( $settings['retention_count'] );
			}

			return self::delete_older_than( $settings['retention_days'] );
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

		self::delete_fixes_older_than( $days );

		return $deleted;
	}

	/**
	 * Flush stored logs now: the scan history, the change log, or both.
	 *
	 * @param string $scope   history|changes|all.
	 * @param bool   $dry_run Only count what would be deleted.
	 *
	 * @return array{scans:int, changes:int}
	 *
	 * @throws \RuntimeException While a scan or another cleanup runs.
	 */
	public static function flush( string $scope, bool $dry_run ): array {
		global $wpdb;

		$history = in_array( $scope, [ 'history', 'all' ], true );
		$changes = in_array( $scope, [ 'changes', 'all' ], true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$counts = [
			'scans'   => $history ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'running'", Installer::scans_table() ) ) : 0,
			'changes' => $changes ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::fixes_table() ) ) : 0,
		];
		// phpcs:enable

		if ( $dry_run ) {
			return $counts;
		}

		if ( Scanner::is_running() || ! Lock::acquire( self::LOCK_OPTION, 10 * MINUTE_IN_SECONDS ) ) {
			throw new \RuntimeException( __( 'A scan or cleanup is running. Try again in a moment.', 'redirect-inspector' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			$deleted = [
				'scans'   => $history ? self::delete_beyond_newest( 0 ) : 0,
				'changes' => 0,
			];
			if ( $changes ) {
				self::delete_fixes_older_than( 0 );
				$deleted['changes'] = $counts['changes'];
			}
			Presence::changed( 'flush' );
			return $deleted;
		} finally {
			Lock::release( self::LOCK_OPTION );
		}
	}

	/**
	 * Delete change-log entries older than $days (0 = all), in batches.
	 *
	 * @param int $days Age in days.
	 *
	 * @return void
	 */
	private static function delete_fixes_older_than( int $days ): void {
		global $wpdb;

		$cutoff = 0 === $days ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$batch = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT %d', Installer::fixes_table(), $cutoff, self::BATCH ) );
		} while ( self::BATCH === $batch );
	}

	/**
	 * Keep only the newest $keep finished scans (and their issues).
	 *
	 * @param int $keep Scans to keep.
	 *
	 * @return int Scans deleted.
	 */
	public static function delete_beyond_newest( int $keep ): int {
		global $wpdb;

		$scans  = Installer::scans_table();
		$issues = Installer::issues_table();

		// Everything older than the $keep-th newest scan (keep 0: everything).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$threshold = 0 === $keep ? PHP_INT_MAX : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status <> 'running' ORDER BY id DESC LIMIT 1 OFFSET %d", $scans, $keep - 1 ) );
		if ( ! $threshold ) {
			return 0;
		}

		$deleted = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE id < %d AND status <> 'running' ORDER BY id LIMIT %d", $scans, $threshold, self::BATCH ) ) );
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

		return $deleted;
	}

	/**
	 * Human description of the retention, for the dashboard.
	 *
	 * @return string
	 */
	public static function describe(): string {
		$settings = Settings::all();
		if ( 'count' === $settings['retention_mode'] ) {
			return sprintf(
				/* translators: 1: number of scans, 2: number of days. */
				__( 'The newest %1$d scans are kept; older ones are removed after every scan. Changes made through Apply fix or Migrate are logged for %2$d days.', 'redirect-inspector' ),
				$settings['retention_count'],
				$settings['retention_days']
			);
		}

		return sprintf(
			/* translators: %d: number of days. */
			_n( 'Scan history is kept for %d day; older scans are removed every Sunday around 03:00.', 'Scan history is kept for %d days; older scans are removed every Sunday around 03:00.', Settings::retention_days(), 'redirect-inspector' ),
			Settings::retention_days()
		);
	}
}
