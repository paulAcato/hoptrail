<?php
/**
 * A source whose rules Hoptrail may change (Apply fix).
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

use Hoptrail\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow write API: re-read one rule, change its target, or switch it off.
 */
interface Writable {

	/**
	 * The rule as currently stored, or null when it no longer exists.
	 *
	 * @param string $id Rule ID (as in Rule::$id).
	 *
	 * @return Rule|null
	 */
	public function find( string $id ): ?Rule;

	/**
	 * Point a rule at a new target.
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 *
	 * @return bool
	 */
	public function retarget( string $id, string $to ): bool;

	/**
	 * Whether this plugin can hold a rule of this kind (migration target).
	 *
	 * @param Rule $rule Rule from another plugin.
	 *
	 * @return bool
	 */
	public function supports( Rule $rule ): bool;

	/**
	 * Add a rule (migration). Patterns arrive converted by Rule::regex().
	 *
	 * @param Rule $rule Rule from another plugin.
	 *
	 * @return bool
	 */
	public function create( Rule $rule ): bool;

	/**
	 * Switch a rule off: disabled where the plugin supports it, removed where it doesn't.
	 *
	 * @param string $id Rule ID.
	 *
	 * @return string 'disabled', 'removed', or '' on failure.
	 */
	public function disable( string $id ): string;
}
