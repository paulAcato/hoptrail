<?php
/**
 * 301 Redirects – Redirect Manager (WebFactory, slug eps-301-redirects).
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

use Hoptrail\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {prefix}redirects: exact paths (case-sensitive, trailing slash ignored), to a URL or to a
 * post ID (type "post"). Status "inactive" means switched off.
 */
final class Eps_301 implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return '301-redirects';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return '301 Redirects';
	}

	/**
	 * The plugin's table (a generic name, so its columns are checked too).
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'redirects';
	}

	/**
	 * Whether the table exists and is this plugin's.
	 *
	 * @return bool
	 */
	private function has_own_table(): bool {
		global $wpdb;

		if ( ! $this->has_table( $this->table() ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->table(), 'url_from' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'EPS_REDIRECT_VERSION' ) && $this->has_own_table();
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return $this->has_own_table() && $this->count() > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'inactive'", $this->table() ) );
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
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, url_from, url_to, status, type FROM %i WHERE status <> 'inactive' ORDER BY id LIMIT %d OFFSET %d", $this->table(), $limit, $offset ) );

		return array_values( array_filter( array_map( [ $this, 'to_rule' ], $rows ) ) );
	}

	/**
	 * A row as a rule (null when the target can't be resolved).
	 *
	 * @param object $row DB row.
	 *
	 * @return Rule|null
	 */
	private function to_rule( object $row ): ?Rule {
		$to = ( 'post' === $row->type || is_numeric( $row->url_to ) ) ? (string) get_permalink( (int) $row->url_to ) : html_entity_decode( (string) $row->url_to );
		if ( '' === $to ) {
			return null;
		}

		$code = (int) $row->status;

		return new Rule(
			source: $this->slug(),
			id: (string) $row->id,
			from: '/' . ltrim( rawurldecode( html_entity_decode( (string) $row->url_from ) ), '/' ),
			to: $to,
			code: self::is_redirect_code( $code ) ? $code : 301,
			match: Rule::MATCH_EXACT,
			leading_slash: true,
			case_sensitive: true,
			edit_url: admin_url( 'options-general.php?page=eps_redirects' )
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
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, url_from, url_to, status, type FROM %i WHERE id = %d AND status <> 'inactive'", $this->table(), absint( $id ) ) );

		return $row ? $this->to_rule( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function supports( Rule $rule ): bool {
		return $rule->is_exact();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $rule->is_exact() && false !== $wpdb->insert(
			$this->table(),
			[
				'url_from' => ltrim( $rule->path(), '/' ),
				'url_to'   => $rule->to,
				'status'   => (string) $rule->code,
				'type'     => 'url',
			],
			[ '%s', '%s', '%s', '%s' ]
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
		return false !== $wpdb->update(
			$this->table(),
			[
				'url_to' => $to,
				'type'   => 'url',
			],
			[ 'id' => absint( $id ) ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $this->table(), [ 'status' => 'inactive' ], [ 'id' => absint( $id ) ], [ '%s' ], [ '%d' ] ) ? 'disabled' : '';
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
		return defined( 'EPS_REDIRECT_VERSION' ) || defined( 'WF301_PLUGIN_FILE' );
	}
}
