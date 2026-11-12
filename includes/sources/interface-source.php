<?php
/**
 * A redirect plugin we read rules from.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only adapter around a plugin's storage.
 */
interface Source {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Human name.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the plugin is active and has storage to read.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Whether the plugin's storage holds rules, whether or not the plugin is active.
	 * Inactive + stored = leftovers that redirect nothing.
	 *
	 * @return bool
	 */
	public function has_storage(): bool;

	/**
	 * Enabled rules, paged.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 *
	 * @return Rule[]
	 */
	public function rules( int $offset, int $limit ): array;

	/**
	 * Number of enabled rules.
	 *
	 * @return int
	 */
	public function count(): int;

	/**
	 * Number of rules skipped (disabled or conditional), for the dashboard.
	 *
	 * @return int
	 */
	public function skipped(): int;
}
