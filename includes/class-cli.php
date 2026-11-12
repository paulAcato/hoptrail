<?php
/**
 * WP-CLI commands.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

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
	 * ## EXAMPLES
	 *
	 *     wp redirect-inspector scan
	 *     wp redirect-inspector scan --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function scan( $args, $assoc_args ): void {
		unset( $args );

		try {
			$scan = Scanner::run( 'cli' );
		} catch ( \RuntimeException $error ) {
			WP_CLI::error( $error->getMessage() );
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

		WP_CLI::success( sprintf( '%d rules, %d issues, health score %d (%s, %d ms).', $scan['rule_count'], $scan['issue_count'], $scan['score'], $scan['status'], $scan['duration_ms'] ) );
	}

	/**
	 * Strip control characters from untrusted rule strings before they reach the terminal.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function plain( string $text ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $text );
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
