<?php
/**
 * Rank Math SEO redirections module.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {prefix}rank_math_redirections. One row can hold several sources (serialised); each becomes
 * its own rule. Patterns are stored and matched without leading slash.
 */
final class Rank_Math implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * Comparison → match type.
	 */
	const MATCHES = [
		'exact'    => Rule::MATCH_EXACT,
		'regex'    => Rule::MATCH_REGEX,
		'start'    => Rule::MATCH_PREFIX,
		'end'      => Rule::MATCH_SUFFIX,
		'contains' => Rule::MATCH_CONTAINS,
	];

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'rank-math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		global $wpdb;

		$modules = self::safe_unserialize( get_option( 'rank_math_modules', [] ) );

		return defined( 'RANK_MATH_VERSION' ) && is_array( $modules ) && in_array( 'redirections', $modules, true ) && $this->has_table( $wpdb->prefix . 'rank_math_redirections' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active' AND header_code BETWEEN 300 AND 399", $wpdb->prefix . 'rank_math_redirections' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'rank_math_redirections' ) );

		return max( 0, $total - $this->count() );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code FROM %i WHERE status = 'active' AND header_code BETWEEN 300 AND 399 ORDER BY id LIMIT %d OFFSET %d", $wpdb->prefix . 'rank_math_redirections', $limit, $offset ) );

		$rules = [];
		foreach ( $rows as $row ) {
			$sources = self::safe_unserialize( $row->sources );
			if ( ! is_array( $sources ) ) {
				continue;
			}

			foreach ( array_values( $sources ) as $index => $source ) {
				if ( ! is_array( $source ) || empty( $source['pattern'] ) || ! is_scalar( $source['pattern'] ) ) {
					continue;
				}

				$match   = self::MATCHES[ $source['comparison'] ?? 'exact' ] ?? Rule::MATCH_EXACT;
				$pattern = Rule::MATCH_REGEX === $match ? stripslashes( (string) $source['pattern'] ) : (string) $source['pattern'];

				$rules[] = new Rule(
					source: $this->slug(),
					id: $row->id . ( $index ? '.' . $index : '' ),
					from: Rule::MATCH_EXACT === $match ? '/' . ltrim( $pattern, '/' ) : $pattern,
					to: (string) $row->url_to,
					code: (int) $row->header_code,
					match: $match,
					leading_slash: false,
					// Rank Math applies "ignore case" to exact sources only.
					case_sensitive: Rule::MATCH_EXACT === $match ? empty( $source['ignore'] ) : true,
					edit_url: admin_url( 'admin.php?page=rank-math-redirections&url=edit&id=' . absint( $row->id ) )
				);
			}
		}

		return $rules;
	}

	/**
	 * Row ID and source index of a rule ID ("12" or "12.1").
	 *
	 * @param string $id Rule ID.
	 *
	 * @return array{0:int, 1:int}
	 */
	private static function split_id( string $id ): array {
		$parts = explode( '.', $id, 2 );

		return [ absint( $parts[0] ), absint( $parts[1] ?? 0 ) ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function find( string $id ): ?Rule {
		global $wpdb;

		[ $row_id, $index ] = self::split_id( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT id, sources, url_to, header_code, status FROM %i WHERE id = %d', $wpdb->prefix . 'rank_math_redirections', $row_id ) );
		$sources = $row ? self::safe_unserialize( $row->sources ) : null;
		if ( ! $row || 'active' !== $row->status || ! is_array( $sources ) ) {
			return null;
		}

		$source = array_values( $sources )[ $index ] ?? null;
		if ( ! is_array( $source ) || ! isset( $source['pattern'] ) ) {
			return null;
		}

		$match = self::MATCHES[ $source['comparison'] ?? 'exact' ] ?? Rule::MATCH_EXACT;

		return new Rule( $this->slug(), $id, Rule::MATCH_EXACT === $match ? '/' . ltrim( (string) $source['pattern'], '/' ) : (string) $source['pattern'], (string) $row->url_to, (int) $row->header_code, $match );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		global $wpdb;

		[ $row_id ] = self::split_id( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = false !== $wpdb->update(
			"{$wpdb->prefix}rank_math_redirections",
			[
				'url_to'  => $to,
				'updated' => current_time( 'mysql' ),
			],
			[ 'id' => $row_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		self::purge( $row_id );

		return $ok;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A row with several sources loses only this source; a row with one is set inactive.
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		global $wpdb;

		[ $row_id, $index ] = self::split_id( $id );
		$table              = "{$wpdb->prefix}rank_math_redirections";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$sources = self::safe_unserialize( $wpdb->get_var( $wpdb->prepare( 'SELECT sources FROM %i WHERE id = %d', $table, $row_id ) ) );
		$sources = is_array( $sources ) ? array_values( $sources ) : [];

		if ( count( $sources ) > 1 ) {
			unset( $sources[ $index ] );
			// Rank Math's own storage format (plain arrays only).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$ok     = false !== $wpdb->update( $table, [ 'sources' => serialize( array_values( $sources ) ) ], [ 'id' => $row_id ], [ '%s' ], [ '%d' ] );
			$result = $ok ? 'removed' : '';
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok     = false !== $wpdb->update( $table, [ 'status' => 'inactive' ], [ 'id' => $row_id ], [ '%s' ], [ '%d' ] );
			$result = $ok ? 'disabled' : '';
		}

		self::purge( $row_id );

		return $result;
	}

	/**
	 * Drop Rank Math's lookup cache for a redirection.
	 *
	 * @param int $row_id Redirection ID.
	 *
	 * @return void
	 */
	private static function purge( int $row_id ): void {
		if ( class_exists( '\\RankMath\\Redirections\\Cache' ) ) {
			\RankMath\Redirections\Cache::purge( [ $row_id ] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		global $wpdb;

		return $this->has_table( $wpdb->prefix . 'rank_math_redirections' ) && $this->count() > 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function supports( Rule $rule ): bool {
		unset( $rule );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		global $wpdb;

		$comparison = array_search( $rule->match, self::MATCHES, true );
		$comparison = false === $comparison ? 'regex' : $comparison;
		$pattern    = 'regex' === $comparison ? $rule->regex( false ) : ltrim( $rule->from, '/' );

		$source = [
			'pattern'    => $pattern,
			'comparison' => $comparison,
			'ignore'     => $rule->case_sensitive ? '' : 'case',
		];
		// Rank Math's own storage format (plain arrays only).
		$sources = serialize( [ $source ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			"{$wpdb->prefix}rank_math_redirections",
			[
				'sources'     => $sources,
				'url_to'      => $rule->to,
				'header_code' => $rule->code,
				'status'      => 'active',
				'created'     => current_time( 'mysql' ),
				'updated'     => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return false !== $ok;
	}

	/**
	 * {@inheritDoc}
	 */
	public function own_tables(): array {
		global $wpdb;

		return [ $wpdb->prefix . 'rank_math_redirections', $wpdb->prefix . 'rank_math_redirections_cache' ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Removes this source from its row, or the row when it was the only source.
	 *
	 * @param string $id Rule ID.
	 */
	public function delete_stored( string $id ): bool {
		global $wpdb;

		[ $row_id, $index ] = self::split_id( $id );
		$table              = $wpdb->prefix . 'rank_math_redirections';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sources = self::safe_unserialize( $wpdb->get_var( $wpdb->prepare( 'SELECT sources FROM %i WHERE id = %d', $table, $row_id ) ) );
		$sources = is_array( $sources ) ? array_values( $sources ) : [];

		if ( count( $sources ) > 1 ) {
			unset( $sources[ $index ] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Rank Math's own storage format.
			return false !== $wpdb->update( $table, [ 'sources' => serialize( array_values( $sources ) ) ], [ 'id' => $row_id ], [ '%s' ], [ '%d' ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'rank_math_redirections_cache', [ 'redirection_id' => $row_id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $table, [ 'id' => $row_id ], [ '%d' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Drop Rank Math's redirect tables too.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		global $wpdb;

		foreach ( $this->own_tables() as $table ) {
			if ( $drop_tables ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit, confirmed clean-up of an inactive plugin.
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
			}
		}

		return true;
	}
}
