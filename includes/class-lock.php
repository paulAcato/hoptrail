<?php
/**
 * Atomic named locks in the options table.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * INSERT IGNORE succeeds for exactly one request; a stale lock is taken over with a conditional
 * UPDATE on the old value, which also succeeds for exactly one request (S8). The options cache is
 * bypassed so a persistent object cache can't serve a stale value.
 */
final class Lock {

	/**
	 * Take a lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Seconds after which a held lock counts as stale.
	 *
	 * @return bool
	 */
	public static function acquire( string $name, int $ttl ): bool {
		global $wpdb;

		$now = (string) time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, $name, $now ) );
		if ( 1 === (int) $inserted ) {
			self::flush( $name );
			return true;
		}

		$held = self::held_since( $name );
		if ( null !== $held && $held > time() - $ttl ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $now, $name, (string) $held ) );
		self::flush( $name );

		return 1 === (int) $updated;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Option name.
	 *
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		self::flush( $name );
	}

	/**
	 * Whether a lock is held and not stale.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Staleness in seconds.
	 *
	 * @return bool
	 */
	public static function is_held( string $name, int $ttl ): bool {
		$held = self::held_since( $name );

		return null !== $held && $held > time() - $ttl;
	}

	/**
	 * Timestamp the lock was taken, read from the database.
	 *
	 * @param string $name Option name.
	 *
	 * @return int|null
	 */
	private static function held_since( string $name ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );

		return null === $value ? null : (int) $value;
	}

	/**
	 * Drop cached copies of the option.
	 *
	 * @param string $name Option name.
	 *
	 * @return void
	 */
	private static function flush( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
