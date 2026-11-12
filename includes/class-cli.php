<?php
/**
 * WP-CLI commands.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Includes\Analysis\Analyzer;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Scan redirects for loops, chains and unnecessary rules.
 */
final class Cli {

	/**
	 * Run a scan and print the issues found.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - summary
	 * ---
	 *
	 * [--fail-on=<types>]
	 * : Exit with code 1 when the scan finds issues of these types (comma-separated), or of any
	 * type with `any`. Types: loop, chain, conflict, noop, duplicate, redundant, shadow, broken.
	 *
	 * [--porcelain]
	 * : Print only the health score (0–100).
	 *
	 * ## EXAMPLES
	 *
	 *     wp redirect-inspector scan
	 *     wp redirect-inspector scan --format=json
	 *     wp redirect-inspector scan --porcelain --fail-on=loop,conflict
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function scan( $args, $assoc_args ): void {
		unset( $args );

		$fail_on = self::fail_on_types( (string) ( $assoc_args['fail-on'] ?? '' ) );

		try {
			$scan = Scanner::run( 'cli' );
		} catch ( \RuntimeException $error ) {
			WP_CLI::error( $error->getMessage() );
			return;
		}

		$failing = array_filter(
			array_intersect_key( (array) ( $scan['totals']['types'] ?? [] ), array_flip( $fail_on ) ),
			static fn( $count ) => (int) $count > 0
		);

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $scan['score'] );
			if ( $failing ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		$issues = Log_Repository::issues( $scan['id'], [], '', '', 1, 100 );

		if ( 'json' === $format ) {
			WP_CLI::log(
				(string) wp_json_encode(
					[
						'scan'   => $scan,
						'issues' => $issues['items'],
					],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			if ( $failing ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		if ( 'table' === $format && $issues['items'] ) {
			WP_CLI\Utils\format_items(
				'table',
				array_map(
					static fn( $issue ) => [
						'type'     => $issue['type'],
						'severity' => $issue['severity'],
						'source'   => self::plain( $issue['source_url'] ),
						'target'   => self::plain( $issue['target_url'] ),
						'hops'     => count( $issue['hops'] ),
						'plugins'  => implode( ',', $issue['plugins'] ),
					],
					$issues['items']
				),
				[ 'type', 'severity', 'source', 'target', 'hops', 'plugins' ]
			);
		}

		foreach ( $scan['totals']['types'] ?? [] as $type => $count ) {
			WP_CLI::log( sprintf( '%-10s %d', $type, $count ) );
		}

		if ( $failing ) {
			WP_CLI::error( sprintf( 'Found %s (score %d).', implode( ', ', array_map( static fn( $type, $count ) => $count . ' ' . $type, array_keys( $failing ), $failing ) ), $scan['score'] ) );
		}

		WP_CLI::success( sprintf( '%d rules, %d issues, health score %d (%s, %d ms).', $scan['rule_count'], $scan['issue_count'], $scan['score'], $scan['status'], $scan['duration_ms'] ) );
	}

	/**
	 * Parse --fail-on.
	 *
	 * @param string $value Comma-separated types, or "any".
	 *
	 * @return string[]
	 */
	private static function fail_on_types( string $value ): array {
		if ( '' === trim( $value ) ) {
			return [];
		}

		if ( 'any' === trim( $value ) ) {
			return Analyzer::TYPES;
		}

		$types   = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		$unknown = array_diff( $types, Analyzer::TYPES );
		if ( $unknown ) {
			WP_CLI::error( sprintf( 'Unknown issue type(s): %s. Use: %s, or any.', implode( ', ', $unknown ), implode( ', ', Analyzer::TYPES ) ) );
		}

		return $types;
	}

	/**
	 * Strip control characters from untrusted rule strings before they reach the terminal.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function plain( string $text ): string {
		return (string) preg_replace( '/\p{Cc}/u', '', $text );
	}

	/**
	 * Delete scan history older than the retention period (or everything with --all).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Delete all scan history.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function purge_logs( $args, $assoc_args ): void {
		unset( $args );

		$deleted = Retention::cleanup( isset( $assoc_args['all'] ) ? 0 : null );
		WP_CLI::success( sprintf( 'Deleted %d scans.', $deleted ) );
	}
}
