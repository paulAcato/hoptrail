<?php
/**
 * SmartCrawl (WPMU DEV) redirects.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {prefix}smartcrawl_redirects: `source` (a path, or a regex when `options` holds "regex"),
 * `destination` as JSON (a URL, or { id } of a post), `type` the status code. Matching is
 * case-insensitive and ignores the trailing slash. SmartCrawl has no "off" state per rule; with its URL
 * Redirection module switched off, its rules are treated like those of an inactive plugin.
 */
final class Smartcrawl implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'smartcrawl';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'SmartCrawl';
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'smartcrawl_redirects';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		if ( ! defined( 'SMARTCRAWL_PLUGIN_DIR' ) || ! $this->has_table( $this->table() ) ) {
			return false;
		}

		// Redirects only run when SmartCrawl's URL Redirection module is switched on; ask SmartCrawl.
		$controller = [ '\\SmartCrawl\\Modules\\Advanced\\Redirects\\Controller', 'get' ];
		if ( is_callable( $controller ) ) {
			$instance = call_user_func( $controller );
			return is_object( $instance ) && is_callable( [ $instance, 'should_run' ] ) && (bool) call_user_func( [ $instance, 'should_run' ] );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return $this->has_table( $this->table() ) && $this->count() > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type BETWEEN 300 AND 399', $this->table() ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) ) - $this->count() );
	}

	/**
	 * Enabled rules, paged.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 *
	 * @return Rule[]
	 */
	public function rules( int $offset, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, destination, type, options FROM %i WHERE type BETWEEN 300 AND 399 ORDER BY id LIMIT %d OFFSET %d', $this->table(), $limit, $offset ) );

		return array_values( array_filter( array_map( [ $this, 'to_rule' ], $rows ) ) );
	}

	/**
	 * A row as a rule (null when the destination can't be resolved).
	 *
	 * @param object $row DB row.
	 *
	 * @return Rule|null
	 */
	private function to_rule( object $row ): ?Rule {
		$destination = json_decode( (string) $row->destination, true );
		$to          = is_array( $destination ) ? ( empty( $destination['id'] ) ? '' : (string) get_permalink( (int) $destination['id'] ) ) : (string) $destination;
		if ( '' === $to ) {
			return null;
		}

		$regex = in_array( 'regex', explode( '|', (string) $row->options ), true );

		return new Rule(
			source: $this->slug(),
			id: (string) $row->id,
			from: $regex ? (string) $row->source : '/' . ltrim( (string) $row->source, '/' ),
			to: $to,
			code: (int) $row->type,
			match: $regex ? Rule::MATCH_REGEX : Rule::MATCH_EXACT,
			leading_slash: true,
			case_sensitive: false,
			edit_url: admin_url( 'admin.php?page=wds_settings&tab=tab_url_redirection' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function find( string $id ): ?Rule {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, source, destination, type, options FROM %i WHERE id = %d AND type BETWEEN 300 AND 399', $this->table(), absint( $id ) ) );

		return $row ? $this->to_rule( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function supports( Rule $rule ): bool {
		return $rule->is_exact() || Rule::MATCH_REGEX === $rule->match || Rule::MATCH_PREFIX === $rule->match;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		global $wpdb;

		$exact = $rule->is_exact();
		$path  = strtolower( untrailingslashit( $rule->path() ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->insert(
			$this->table(),
			[
				'title'       => 'Migrated by Redirect Inspector',
				'source'      => $exact ? $rule->path() : $rule->regex( true ),
				'path'        => $exact ? ( '' === $path ? '/' : $path ) : 'regex',
				'destination' => wp_json_encode( $rule->to ),
				'type'        => $rule->code,
				'options'     => $exact ? '' : 'regex',
				'rules'       => '[]',
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $this->table(), [ 'destination' => wp_json_encode( $to ) ], [ 'id' => absint( $id ) ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * SmartCrawl has no "off" state, so the rule is removed (its old value is in the fix log).
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		return $this->delete_stored( $id ) ? 'removed' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function own_tables(): array {
		return [ $this->table() ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function delete_stored( string $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $this->table(), [ 'id' => absint( $id ) ], [ '%d' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Drop the table too.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		global $wpdb;

		if ( $drop_tables ) {
			delete_option( $wpdb->prefix . 'smartcrawl_redirects_version' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit, confirmed clean-up of an inactive plugin.
			return false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table() ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'SMARTCRAWL_PLUGIN_DIR' );
	}
}
