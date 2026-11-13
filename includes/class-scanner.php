<?php
/**
 * Runs a scan: read every source, analyse, store.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

use Hoptrail\Includes\Analysis\Analyzer;
use Hoptrail\Includes\Analysis\Matcher;
use Hoptrail\Includes\Sources\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The shared worker. It checks nothing about the caller: REST checks capability + nonce, WP-CLI
 * runs as the operator.
 */
final class Scanner {

	/**
	 * Mutex option; one scan per site at a time (S8).
	 */
	const LOCK_OPTION = 'hoptrail_scan_lock';

	/**
	 * A lock or running scan older than this is stale.
	 */
	const STALE_MINUTES = 5;

	/**
	 * Seconds a scan may spend analysing.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Rules read per query.
	 */
	const PAGE_SIZE = 500;


	/**
	 * Weight per issue type for the health score.
	 */
	const WEIGHTS = [
		Analyzer::TYPE_LOOP      => 10,
		Analyzer::TYPE_CONFLICT  => 5,
		Analyzer::TYPE_CHAIN     => 3,
		Analyzer::TYPE_BROKEN    => 3,
		Analyzer::TYPE_NOOP      => 2,
		Analyzer::TYPE_SHADOW    => 2,
		Analyzer::TYPE_DUPLICATE => 1,
		Analyzer::TYPE_REDUNDANT => 1,
	];

	/**
	 * Run a scan.
	 *
	 * @param string $trigger manual|cli|cron|ability.
	 *
	 * @return array Scan (formatted) on success.
	 *
	 * @throws \RuntimeException When not installed, already running, or the scan failed.
	 */
	public static function run( string $trigger = 'manual' ): array {
		if ( ! Installer::is_installed() && ! Installer::install() ) {
			throw new \RuntimeException( sprintf( /* translators: %s: plugin name. */ __( 'The %s tables are missing and could not be created.', 'hoptrail' ), HOPTRAIL_NAME ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text via JSON/React or WP-CLI.
		}

		if ( ! Lock::acquire( self::LOCK_OPTION, self::STALE_MINUTES * MINUTE_IN_SECONDS ) ) {
			throw new \RuntimeException( __( 'A scan is already running. Try again in a moment.', 'hoptrail' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
		}

		$started = microtime( true );
		$scan_id = 0;

		try {
			Log_Repository::fail_stale_scans( self::STALE_MINUTES );

			$scan_id = Log_Repository::start_scan( $trigger );
			if ( ! $scan_id ) {
				throw new \RuntimeException( __( 'Could not start a scan.', 'hoptrail' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
			}

			$deadline   = $started + self::TIME_BUDGET;
			$normalizer = new Url_Normalizer();
			$matcher    = new Matcher( $normalizer, $deadline );
			$rules      = [];
			$per_source = [];

			foreach ( Registry::all() as $slug => $source ) {
				$available = $source->is_available();
				$count     = 0;

				if ( $available ) {
					// Page over the stored rows; an adapter may drop rows (non-redirect codes) or
					// expand one row into several rules, so the row total is the bound.
					$rows = $source->count() + $source->skipped();
					for ( $offset = 0; $offset < $rows; $offset += self::PAGE_SIZE ) {
						$page = $source->rules( $offset, self::PAGE_SIZE );
						array_push( $rules, ...$page );
						$count += count( $page );
					}
				}

				$per_source[ $slug ] = [
					'label'     => $source->label(),
					'available' => $available,
					'rules'     => $count,
					'skipped'   => $available ? $source->skipped() : 0,
				];
			}

			$matcher->add( $rules );
			$analyzer = new Analyzer( $matcher, $normalizer, $deadline );
			$issues   = $analyzer->run( $rules );
			$partial  = $analyzer->partial;

			if ( ! Log_Repository::insert_issues( $scan_id, $issues ) ) {
				throw new \RuntimeException( __( 'Could not store the scan results.', 'hoptrail' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
			}

			$totals = self::totals( $issues, $per_source );
			$score  = self::score( count( $rules ), $totals['types'] );

			Log_Repository::finish_scan(
				$scan_id,
				$partial ? 'partial' : 'done',
				[
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'rule_count'  => count( $rules ),
					'issue_count' => count( $issues ),
					'score'       => $score,
					'totals'      => $totals,
					'message'     => $partial ? __( 'Time or evaluation budget reached; results may be incomplete.', 'hoptrail' ) : '',
				]
			);

			Presence::changed( 'cron' === $trigger ? 'scheduled-scan' : 'scan' );

			// "Keep the newest N scans": trim right away, so the history never grows past N.
			$settings = Settings::all();
			if ( 'count' === $settings['retention_mode'] ) {
				Retention::delete_beyond_newest( $settings['retention_count'] );
			}

			return (array) Log_Repository::scan( $scan_id );
		} catch ( \Throwable $error ) {
			if ( $scan_id ) {
				Log_Repository::delete_issues( $scan_id );
				Log_Repository::finish_scan(
					$scan_id,
					'failed',
					[
						'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'message'     => $error->getMessage(),
					]
				);
			}

			throw new \RuntimeException( $error->getMessage(), (int) $error->getCode(), $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
		} finally {
			Lock::release( self::LOCK_OPTION );
		}
	}

	/**
	 * Whether a scan is running right now.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		return Lock::is_held( self::LOCK_OPTION, self::STALE_MINUTES * MINUTE_IN_SECONDS );
	}

	/**
	 * Counts per type, per severity and per plugin.
	 *
	 * @param array[] $issues     Issues.
	 * @param array   $per_source Per-source stats.
	 *
	 * @return array
	 */
	private static function totals( array $issues, array $per_source ): array {
		$types      = array_fill_keys( Analyzer::TYPES, 0 );
		$severities = array_fill_keys( [ 'critical', 'high', 'medium', 'low' ], 0 );
		$plugins    = array_fill_keys( array_keys( $per_source ), 0 );

		foreach ( $issues as $issue ) {
			++$types[ $issue['type'] ];
			++$severities[ $issue['severity'] ];
			foreach ( explode( ',', $issue['plugins'] ) as $plugin ) {
				if ( isset( $plugins[ $plugin ] ) ) {
					++$plugins[ $plugin ];
				}
			}
		}

		foreach ( $per_source as $slug => $stats ) {
			$per_source[ $slug ]['issues'] = $plugins[ $slug ];
		}

		return [
			'types'      => $types,
			'severities' => $severities,
			'sources'    => $per_source,
		];
	}

	/**
	 * Health score 0–100: rules / (rules + weighted issues).
	 *
	 * @param int   $rules Rule count.
	 * @param array $types Count per type.
	 *
	 * @return int
	 */
	public static function score( int $rules, array $types ): int {
		$weighted = 0;
		foreach ( $types as $type => $count ) {
			$weighted += ( self::WEIGHTS[ $type ] ?? 1 ) * $count;
		}

		if ( 0 === $weighted ) {
			return 100;
		}

		$rules = max( 1, $rules );

		return (int) round( 100 * $rules / ( $rules + $weighted ) );
	}
}
