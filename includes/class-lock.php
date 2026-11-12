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
	 * Values of the locks this request holds (timestamp and a random token), so a release only
	 * removes our own lock, never one another request took over after ours went stale.
	 *
	 * @var array<string, string>
	 */
	private static array $held = [];

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

		// "<timestamp>:<token>": the timestamp decides staleness, the token ownership.
		$value = time() . ':' . wp_generate_password( 16, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, $name, $value ) );
		if ( 1 === (int) $inserted ) {
			self::flush( $name );
			self::$held[ $name ] = $value;
			return true;
		}

		$current = self::value( $name );
		if ( null !== $current && (int) $current > time() - $ttl ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, $name, (string) $current ) );
		self::flush( $name );

		if ( 1 !== (int) $updated ) {
			return false;
		}
		self::$held[ $name ] = $value;

		return true;
	}

	/**
	 * Release a lock this request took. A lock taken over by another request after ours went
	 * stale is left alone. Without a lock of its own (deactivation, uninstall) it is removed.
	 *
	 * @param string $name Option name.
	 *
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;

		if ( isset( self::$held[ $name ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, self::$held[ $name ] ) );
			unset( self::$held[ $name ] );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		}
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
		$value = self::value( $name );

		return null !== $value && (int) $value > time() - $ttl;
	}

	/**
	 * The lock's stored value ("<timestamp>:<token>", or a bare timestamp from before tokens),
	 * read from the database. (int) of it is the moment it was taken.
	 *
	 * @param string $name Option name.
	 *
	 * @return string|null
	 */
	private static function value( string $name ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );

		return null === $value ? null : (string) $value;
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
