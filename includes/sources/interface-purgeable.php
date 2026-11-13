<?php
/**
 * A source whose stored rules can be deleted (leftovers of an inactive plugin).
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Only used for plugins that are not active: their rules redirect nothing any more.
 */
interface Purgeable {

	/**
	 * Delete one stored rule.
	 *
	 * @param string $id Rule ID (as in Rule::$id).
	 *
	 * @return bool
	 */
	public function delete_stored( string $id ): bool;

	/**
	 * Delete every stored rule; optionally drop the plugin's own redirect tables.
	 *
	 * @param bool $drop_tables Also drop the tables (only plugins with their own tables).
	 *
	 * @return bool
	 */
	public function purge_storage( bool $drop_tables ): bool;

	/**
	 * Tables the plugin keeps its redirects in ([] when it uses options or posts).
	 *
	 * @return string[]
	 */
	public function own_tables(): array;
}
