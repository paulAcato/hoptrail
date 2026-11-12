<?php
/**
 * Redirection (John Godley).
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {prefix}redirection_items: enabled URL-only rules in enabled WordPress-module groups.
 * Conditional rules (login, referrer, cookie, …) and non-redirect actions are counted as skipped.
 */
final class Redirection implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'redirection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Redirection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		global $wpdb;

		return defined( 'REDIRECTION_VERSION' ) && $this->has_table( $wpdb->prefix . 'redirection_items' ) && $this->has_table( $wpdb->prefix . 'redirection_groups' );
	}


	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix, static WHERE.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i i INNER JOIN %i g ON g.id = i.group_id WHERE i.status = 'enabled' AND g.status = 'enabled' AND g.module_id = 1 AND i.match_type = 'url' AND i.action_type = 'url'", $wpdb->prefix . 'redirection_items', $wpdb->prefix . 'redirection_groups' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'redirection_items' ) );

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

		static $ign_case = null;
		if ( null === $ign_case ) {
			$options  = self::safe_unserialize( get_option( 'redirection_options' ) );
			$ign_case = is_array( $options ) && ! empty( $options['flag_case'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix, static WHERE.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.url, i.regex, i.action_code, i.action_data, i.match_data FROM %i i INNER JOIN %i g ON g.id = i.group_id WHERE i.status = 'enabled' AND g.status = 'enabled' AND g.module_id = 1 AND i.match_type = 'url' AND i.action_type = 'url' ORDER BY g.position, i.position, i.id LIMIT %d OFFSET %d", $wpdb->prefix . 'redirection_items', $wpdb->prefix . 'redirection_groups', $limit, $offset ) );

		$rules = [];
		foreach ( $rows as $row ) {
			$code = (int) $row->action_code;
			if ( ! self::is_redirect_code( $code ) || '' === trim( (string) $row->action_data ) ) {
				continue;
			}

			$flags = json_decode( (string) $row->match_data, true );
			$case  = is_array( $flags ) && isset( $flags['source']['flag_case'] ) ? ! $flags['source']['flag_case'] : ! $ign_case;

			$rules[] = new Rule(
				source: $this->slug(),
				id: (string) $row->id,
				from: (string) $row->url,
				to: (string) $row->action_data,
				code: $code,
				match: $row->regex ? Rule::MATCH_REGEX : Rule::MATCH_EXACT,
				leading_slash: true,
				case_sensitive: $case,
				edit_url: admin_url( 'tools.php?page=redirection.php&sub=redirects' )
			);
		}

		return $rules;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function find( string $id ): ?Rule {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, url, regex, action_code, action_data, status FROM %i WHERE id = %d AND match_type = 'url' AND action_type = 'url'", $wpdb->prefix . 'redirection_items', absint( $id ) ) );
		if ( ! $row || 'enabled' !== $row->status ) {
			return null;
		}

		return new Rule( $this->slug(), (string) $row->id, (string) $row->url, (string) $row->action_data, (int) $row->action_code, $row->regex ? Rule::MATCH_REGEX : Rule::MATCH_EXACT );
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
		return false !== $wpdb->update( "{$wpdb->prefix}redirection_items", [ 'action_data' => $to ], [ 'id' => absint( $id ) ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = false !== $wpdb->update( "{$wpdb->prefix}redirection_items", [ 'status' => 'disabled' ], [ 'id' => absint( $id ) ], [ '%s' ], [ '%d' ] );

		return $ok ? 'disabled' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		global $wpdb;

		return $this->has_table( $wpdb->prefix . 'redirection_items' ) && $this->has_table( $wpdb->prefix . 'redirection_groups' ) && $this->count() > 0;
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
	 * Through Redirection's own API, into a "Migrated by Redirect Inspector" group.
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		if ( ! class_exists( 'Red_Item' ) || ! class_exists( 'Red_Group' ) ) {
			return false;
		}

		$item = \Red_Item::create(
			[
				'url'         => $rule->is_exact() ? $rule->path() : $rule->regex( true ),
				'action_data' => [ 'url' => $rule->to ],
				'regex'       => ! $rule->is_exact(),
				'group_id'    => $this->migration_group(),
				'match_type'  => 'url',
				'action_type' => 'url',
				'action_code' => $rule->code,
			]
		);

		return ! is_wp_error( $item );
	}

	/**
	 * ID of the group migrated rules go into (created on first use).
	 *
	 * @return int
	 */
	private function migration_group(): int {
		global $wpdb;

		$name = 'Migrated by Redirect Inspector';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE name = %s AND module_id = 1 LIMIT 1', $wpdb->prefix . 'redirection_groups', $name ) );
		if ( $id ) {
			return $id;
		}

		$group = \Red_Group::create( $name, 1 );

		return $group ? (int) $group->get_id() : 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function own_tables(): array {
		global $wpdb;

		return [ $wpdb->prefix . 'redirection_items', $wpdb->prefix . 'redirection_groups', $wpdb->prefix . 'redirection_logs', $wpdb->prefix . 'redirection_404' ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function delete_stored( string $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $wpdb->prefix . 'redirection_items', [ 'id' => absint( $id ) ], [ '%d' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Drop Redirection's tables too.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		global $wpdb;

		if ( $drop_tables ) {
			$ok = true;
			foreach ( $this->own_tables() as $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit, confirmed clean-up of an inactive plugin.
				$ok = false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) && $ok;
			}
			// Its settings only go once every table is gone, so a half-failed purge can be retried.
			if ( $ok ) {
				delete_option( 'redirection_options' );
			}
			return $ok;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'redirection_items' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'REDIRECTION_VERSION' );
	}
}
