<?php
/**
 * Reads and writes the scans and issues tables.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

use Hoptrail\Includes\Analysis\Analyzer;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for the plugin's own tables lives here. Every value is prepared; ORDER BY and filters
 * use allowlists (S5).
 */
final class Log_Repository {

	/**
	 * Most issues a regex search looks at.
	 */
	const MAX_REGEX_ROWS = 5000;

	/**
	 * Most seconds a regex search may take.
	 */
	const REGEX_SECONDS = 2;

	/**
	 * Create a running scan.
	 *
	 * @param string $trigger manual|cli.
	 *
	 * @return int Scan ID (0 on failure).
	 */
	public static function start_scan( string $trigger ): int {
		global $wpdb;

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		$ok = $wpdb->insert(
			Installer::scans_table(),
			[
				'status'       => 'running',
				'trigger_type' => in_array( $trigger, [ 'manual', 'cli', 'cron', 'ability' ], true ) ? $trigger : 'manual',
				'user_id'      => (int) $user->ID,
				'user_login'   => (string) $user->user_login,
				'started_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%s', '%s' ]
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Finish a scan.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status  done|partial|failed.
	 * @param array  $data    duration_ms, rule_count, issue_count, score, totals, message.
	 *
	 * @return void
	 */
	public static function finish_scan( int $scan_id, string $status, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update(
			Installer::scans_table(),
			[
				'status'      => $status,
				'finished_at' => current_time( 'mysql', true ),
				'duration_ms' => (int) ( $data['duration_ms'] ?? 0 ),
				'rule_count'  => (int) ( $data['rule_count'] ?? 0 ),
				'issue_count' => (int) ( $data['issue_count'] ?? 0 ),
				'score'       => (int) ( $data['score'] ?? 0 ),
				'totals'      => wp_json_encode( $data['totals'] ?? [] ),
				'message'     => mb_substr( (string) ( $data['message'] ?? '' ), 0, 255 ),
			],
			[ 'id' => $scan_id ],
			[ '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark scans still "running" after $minutes as failed (a crashed request).
	 *
	 * @param int $minutes Age.
	 *
	 * @return void
	 */
	public static function fail_stale_scans( int $minutes ): void {
		global $wpdb;

		$table = Installer::scans_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'failed', message = %s WHERE status = 'running' AND started_at < %s", $table, 'Interrupted', gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Insert a scan's issues in one transaction; roll back on any failure (S10).
	 *
	 * @param int     $scan_id Scan ID.
	 * @param array[] $issues  Issues from the analyser.
	 *
	 * @return bool
	 */
	public static function insert_issues( int $scan_id, array $issues ): bool {
		global $wpdb;

		$table = Installer::issues_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Own table; placeholders built per row.
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $issues as $issue ) {
			$ok = $wpdb->insert(
				$table,
				[
					'scan_id'     => $scan_id,
					'type'        => $issue['type'],
					'severity'    => $issue['severity'],
					'fingerprint' => $issue['fingerprint'],
					'source_url'  => mb_substr( $issue['source_url'], 0, 2048 ),
					'target_url'  => mb_substr( $issue['target_url'], 0, 2048 ),
					'plugins'     => mb_substr( $issue['plugins'], 0, 255 ),
					'hops'        => wp_json_encode( $issue['hops'] ),
					'suggestion'  => $issue['suggestion'],
					'fix'         => empty( $issue['fix'] ) ? '' : wp_json_encode( $issue['fix'] ),
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scan_id = %d', $table, $scan_id ) );
				return false;
			}
		}

		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		return true;
	}

	/**
	 * Delete a scan's issues (failed scan cleanup).
	 *
	 * @param int $scan_id Scan ID.
	 *
	 * @return void
	 */
	public static function delete_issues( int $scan_id ): void {
		global $wpdb;

		$table = Installer::issues_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scan_id = %d', $table, $scan_id ) );
	}

	/**
	 * Latest finished (done or partial) scan.
	 *
	 * @return array|null
	 */
	public static function latest_scan(): ?array {
		global $wpdb;

		$table = Installer::scans_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status IN ('done', 'partial') ORDER BY id DESC LIMIT 1", $table ), ARRAY_A );

		return $row ? self::format_scan( $row ) : null;
	}

	/**
	 * One scan.
	 *
	 * @param int $scan_id Scan ID.
	 *
	 * @return array|null
	 */
	public static function scan( int $scan_id ): ?array {
		global $wpdb;

		$table = Installer::scans_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $scan_id ), ARRAY_A );

		return $row ? self::format_scan( $row ) : null;
	}

	/**
	 * Columns the history may be sorted by (allowlist; never from input directly).
	 */
	const SCAN_ORDER = [
		'date'     => 'started_at',
		'rules'    => 'rule_count',
		'issues'   => 'issue_count',
		'score'    => 'score',
		'duration' => 'duration_ms',
	];

	/**
	 * Scan history.
	 *
	 * @param int    $limit   Max rows.
	 * @param int    $offset  Offset.
	 * @param string $date    Site-local day (Y-m-d) to show, or ''.
	 * @param string $orderby Key of SCAN_ORDER.
	 * @param string $order   asc|desc.
	 *
	 * @return array{items: array[], total: int}
	 */
	public static function scans( int $limit = 20, int $offset = 0, string $date = '', string $orderby = 'date', string $order = 'desc' ): array {
		global $wpdb;

		$table = Installer::scans_table();
		$range = self::day_range( $date ) ?? [ '1000-01-01 00:00:00', '9999-12-31 00:00:00' ];
		$asc   = 'asc' === strtolower( $order );

		// One static query per allowlisted sort; nothing from the request reaches the SQL text.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = match ( ( self::SCAN_ORDER[ $orderby ] ?? 'started_at' ) . ( $asc ? ' ASC' : ' DESC' ) ) {
			'rule_count ASC'   => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY rule_count ASC, id ASC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'rule_count DESC'  => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY rule_count DESC, id DESC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'issue_count ASC'  => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY issue_count ASC, id ASC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'issue_count DESC' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY issue_count DESC, id DESC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'score ASC'        => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY score ASC, id ASC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'score DESC'       => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY score DESC, id DESC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'duration_ms ASC'  => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY duration_ms ASC, id ASC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'duration_ms DESC' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY duration_ms DESC, id DESC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			'started_at ASC'   => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY started_at ASC, id ASC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
			default            => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE started_at >= %s AND started_at < %s ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d', $table, $range[0], $range[1], $limit, $offset ), ARRAY_A ),
		};
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE started_at >= %s AND started_at < %s', $table, $range[0], $range[1] ) );
		// phpcs:enable

		return [
			'items' => array_map( [ self::class, 'format_scan' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * Site-local days (Y-m-d) that have at least one scan, for marking the date picker.
	 *
	 * @return string[]
	 */
	public static function scan_days(): array {
		global $wpdb;

		$table = Installer::scans_table();
		// Retention keeps this small; capped anyway.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$times = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT started_at FROM %i ORDER BY id DESC LIMIT 5000', $table ) );

		$days = [];
		foreach ( $times as $time ) {
			$days[ ( new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ) ] = true;
		}

		return array_keys( $days );
	}

	/**
	 * UTC start and end of a site-local day.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return array{0:string, 1:string}|null
	 */
	private static function day_range( string $date ): ?array {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $day || $day->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		$utc = new \DateTimeZone( 'UTC' );

		return [
			$day->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$day->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Issues of a scan, filtered and paged.
	 *
	 * @param int    $scan_id  Scan ID.
	 * @param array  $types    Types to include (allowlisted); empty = all.
	 * @param string $plugin   Source slug filter ('' = all).
	 * @param string $search   Search in source/target.
	 * @param int    $page     Page (1-based).
	 * @param int    $per_page Page size (max 100).
	 * @param bool   $regex    Treat $search as a regular expression.
	 *
	 * @return array{items: array[], total: int}
	 *
	 * @throws \InvalidArgumentException When $regex is set and the pattern is invalid.
	 */
	public static function issues( int $scan_id, array $types, string $plugin, string $search, int $page, int $per_page, bool $regex = false ): array {
		global $wpdb;

		if ( $regex && '' !== $search ) {
			return self::issues_matching( $scan_id, $types, $plugin, self::search_pattern( $search ), $page, $per_page );
		}

		$table    = Installer::issues_table();
		$types    = implode( ',', array_values( array_intersect( $types, Analyzer::TYPES ) ) );
		$like     = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		// Static SQL: optional filters are "(%s = '' OR …)", the type list is an allowlisted CSV.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE scan_id = %d AND ( %s = '' OR FIND_IN_SET( type, %s ) ) AND ( %s = '' OR FIND_IN_SET( %s, plugins ) ) AND ( %s = '' OR source_url LIKE %s OR target_url LIKE %s OR hops LIKE %s )", $table, $scan_id, $types, $types, $plugin, $plugin, $like, $like, $like, $like ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE scan_id = %d AND ( %s = '' OR FIND_IN_SET( type, %s ) ) AND ( %s = '' OR FIND_IN_SET( %s, plugins ) ) AND ( %s = '' OR source_url LIKE %s OR target_url LIKE %s OR hops LIKE %s ) ORDER BY FIELD( severity, 'critical', 'high', 'medium', 'low' ), FIELD( type, 'loop', 'chain', 'conflict', 'noop', 'duplicate', 'redundant', 'shadow', 'broken' ), id LIMIT %d OFFSET %d", $table, $scan_id, $types, $types, $plugin, $plugin, $like, $like, $like, $like, $per_page, $offset ), ARRAY_A );
		// phpcs:enable

		$previous = self::previous_fingerprints( $scan_id );

		return [
			'items' => array_map( static fn( $row ) => self::format_issue( $row, $previous ), $rows ),
			'total' => $total,
		];
	}

	/**
	 * A user's search regex as a PCRE pattern (case-insensitive, like the text search).
	 *
	 * @param string $search Pattern without delimiters.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When it doesn't compile.
	 */
	public static function search_pattern( string $search ): string {
		$pattern = '~' . str_replace( '~', '\\~', $search ) . '~iu';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The compile error is reported below.
		if ( false === @preg_match( $pattern, '' ) ) {
			throw new \InvalidArgumentException( __( 'This is not a valid regular expression.', 'hoptrail' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $pattern;
	}

	/**
	 * Issues whose source, target or any hop matches a regex; filtered in PHP (PCRE, the engine
	 * the redirect plugins use), at most MAX_REGEX_ROWS issues and REGEX_SECONDS looked at.
	 *
	 * @param int    $scan_id  Scan ID.
	 * @param array  $types    Types.
	 * @param string $plugin   Plugin slug.
	 * @param string $pattern  Compiled-checked PCRE pattern.
	 * @param int    $page     Page.
	 * @param int    $per_page Page size.
	 *
	 * @return array{items: array[], total: int}
	 */
	private static function issues_matching( int $scan_id, array $types, string $plugin, string $pattern, int $page, int $per_page ): array {
		global $wpdb;

		$table    = Installer::issues_table();
		$types    = implode( ',', array_values( array_intersect( $types, Analyzer::TYPES ) ) );
		$per_page = max( 1, min( 100, $per_page ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, source_url, target_url, hops FROM %i WHERE scan_id = %d AND ( %s = '' OR FIND_IN_SET( type, %s ) ) AND ( %s = '' OR FIND_IN_SET( %s, plugins ) ) ORDER BY FIELD( severity, 'critical', 'high', 'medium', 'low' ), FIELD( type, 'loop', 'chain', 'conflict', 'noop', 'duplicate', 'redundant', 'shadow', 'broken' ), id LIMIT %d", $table, $scan_id, $types, $types, $plugin, $plugin, self::MAX_REGEX_ROWS ), ARRAY_A );

		// Untrusted pattern: a PCRE error (such as the backtrack limit) counts as no match, and the
		// search stops after a time budget.
		$deadline = microtime( true ) + self::REGEX_SECONDS;

		$ids = [];
		foreach ( $rows as $row ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$subjects = [ (string) $row['source_url'], (string) $row['target_url'] ];
			foreach ( (array) json_decode( (string) $row['hops'], true ) as $hop ) {
				$subjects[] = (string) ( $hop['from'] ?? '' );
				$subjects[] = (string) ( $hop['to'] ?? '' );
				$subjects[] = (string) ( $hop['rule']['from'] ?? '' );
			}
			foreach ( $subjects as $subject ) {
				// Also per subject: one conflict can carry many hops.
				if ( microtime( true ) > $deadline ) {
					break 2;
				}
				if ( '' !== $subject && 1 === preg_match( $pattern, $subject ) ) {
					$ids[] = (int) $row['id'];
					break;
				}
			}
		}

		$page_ids = array_slice( $ids, ( max( 1, $page ) - 1 ) * $per_page, $per_page );
		$items    = [];
		if ( $page_ids ) {
			$marks = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Placeholders built from count.
			$found    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$marks})", $table, ...$page_ids ), ARRAY_A );
			$by_id    = array_column( $found, null, 'id' );
			$previous = self::previous_fingerprints( $scan_id );
			foreach ( $page_ids as $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$items[] = self::format_issue( $by_id[ $id ], $previous );
				}
			}
		}

		return [
			'items' => $items,
			'total' => count( $ids ),
		];
	}

	/**
	 * One issue.
	 *
	 * @param int $issue_id Issue ID.
	 *
	 * @return array|null
	 */
	public static function issue( int $issue_id ): ?array {
		global $wpdb;

		$table = Installer::issues_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $issue_id ), ARRAY_A );

		return $row ? self::format_issue( $row, null ) : null;
	}

	/**
	 * Store a live-check result on an issue.
	 *
	 * @param int   $issue_id Issue ID.
	 * @param array $result   Live result.
	 *
	 * @return void
	 */
	public static function save_live_result( int $issue_id, array $result ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update( Installer::issues_table(), [ 'live_result' => wp_json_encode( $result ) ], [ 'id' => $issue_id ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * Mark an issue as fixed.
	 *
	 * @param int   $issue_id Issue ID.
	 * @param array $fixed    What was done.
	 *
	 * @return void
	 */
	public static function mark_fixed( int $issue_id, array $fixed ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update( Installer::issues_table(), [ 'fixed' => wp_json_encode( $fixed ) ], [ 'id' => $issue_id ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * Record a rule change in the fix log.
	 *
	 * @param int    $issue_id Issue ID.
	 * @param string $action   retargeted|disabled|removed.
	 * @param string $source   Source slug.
	 * @param string $rule_id  Rule ID.
	 * @param array  $before   Rule before.
	 * @param array  $after    Rule after (empty when disabled/removed).
	 *
	 * @return void
	 */
	public static function log_fix( int $issue_id, string $action, string $source, string $rule_id, array $before, array $after ): void {
		global $wpdb;

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		$wpdb->insert(
			Installer::fixes_table(),
			[
				'created_at'   => current_time( 'mysql', true ),
				'user_id'      => (int) $user->ID,
				'user_login'   => (string) $user->user_login,
				'issue_id'     => $issue_id,
				'action'       => $action,
				'source'       => $source,
				'rule_id'      => $rule_id,
				'before_value' => wp_json_encode( $before ),
				'after_value'  => $after ? wp_json_encode( $after ) : null,
			],
			[ '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Fingerprints of the scan before this one, to mark issues as new.
	 *
	 * @param int $scan_id Scan ID.
	 *
	 * @return array<string, true>|null Null when there is no earlier scan.
	 */
	private static function previous_fingerprints( int $scan_id ): ?array {
		global $wpdb;

		$scans  = Installer::scans_table();
		$issues = Installer::issues_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables.
		$previous = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id < %d AND status IN ('done', 'partial') ORDER BY id DESC LIMIT 1", $scans, $scan_id ) );
		if ( ! $previous ) {
			return null;
		}

		$prints = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT fingerprint FROM %i WHERE scan_id = %d', $issues, $previous ) );
		// phpcs:enable

		return array_fill_keys( $prints, true );
	}

	/**
	 * Typed scan row for output. Only the user's display name leaves the server (S7).
	 *
	 * @param array $row DB row.
	 *
	 * @return array
	 */
	public static function format_scan( array $row ): array {
		$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;

		return [
			'id'          => (int) $row['id'],
			'status'      => (string) $row['status'],
			'trigger'     => (string) $row['trigger_type'],
			'user'        => $user ? $user->display_name : self::trigger_label( (string) $row['trigger_type'], (string) $row['user_login'] ),
			'started_at'  => self::utc_iso( (string) $row['started_at'] ),
			'finished_at' => $row['finished_at'] ? self::utc_iso( (string) $row['finished_at'] ) : null,
			'duration_ms' => (int) $row['duration_ms'],
			'rule_count'  => (int) $row['rule_count'],
			'issue_count' => (int) $row['issue_count'],
			'score'       => (int) $row['score'],
			'totals'      => json_decode( (string) $row['totals'], true ) ?: (object) [],
			'message'     => (string) $row['message'],
		];
	}

	/**
	 * Who ran a scan that has no (remaining) user.
	 *
	 * @param string $trigger    manual|cli|cron.
	 * @param string $user_login Stored login, for deleted users.
	 *
	 * @return string
	 */
	private static function trigger_label( string $trigger, string $user_login ): string {
		return match ( $trigger ) {
			'cli'   => 'WP-CLI',
			'cron'    => _x( 'Scheduled', 'who ran the scan: a scheduled scan', 'hoptrail' ),
			'ability' => _x( 'Ability', 'who ran the scan: an AI client through an ability', 'hoptrail' ),
			default => $user_login,
		};
	}

	/**
	 * ISO 8601 with offset for a stored UTC datetime.
	 *
	 * @param string $mysql Datetime in UTC.
	 *
	 * @return string
	 */
	private static function utc_iso( string $mysql ): string {
		return gmdate( 'c', (int) strtotime( $mysql . ' UTC' ) );
	}

	/**
	 * Typed issue row for output.
	 *
	 * @param array      $row      DB row.
	 * @param array|null $previous Fingerprints of the previous scan.
	 *
	 * @return array
	 */
	private static function format_issue( array $row, ?array $previous ): array {
		return [
			'id'         => (int) $row['id'],
			'scan_id'    => (int) $row['scan_id'],
			'type'       => (string) $row['type'],
			'severity'   => (string) $row['severity'],
			'source_url' => (string) $row['source_url'],
			'target_url' => (string) $row['target_url'],
			'plugins'    => array_values( array_filter( explode( ',', (string) $row['plugins'] ) ) ),
			'hops'       => json_decode( (string) $row['hops'], true ) ?: [],
			'suggestion' => (string) $row['suggestion'],
			'live'       => $row['live_result'] ? json_decode( (string) $row['live_result'], true ) : null,
			'fix'        => ! empty( $row['fix'] ) ? json_decode( (string) $row['fix'], true ) : null,
			'fixed'      => ! empty( $row['fixed'] ) ? json_decode( (string) $row['fixed'], true ) : null,
			'is_new'     => null !== $previous && ! isset( $previous[ $row['fingerprint'] ] ),
		];
	}
}
