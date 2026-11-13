<?php
/**
 * Base singleton.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per concrete class, created through instance().
 */
abstract class Singleton {

	/**
	 * Instances per class.
	 *
	 * @var array<class-string, static>
	 */
	private static array $instances = [];

	/**
	 * Get (and on first call create and initialise) the instance.
	 *
	 * @return static
	 */
	final public static function instance(): static {
		$class = static::class;
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
			self::$instances[ $class ]->init();
		}

		return self::$instances[ $class ];
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {}

	/**
	 * Use instance().
	 */
	final protected function __construct() {}

	/**
	 * No cloning.
	 */
	private function __clone() {}
}
