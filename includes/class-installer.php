<?php
/**
 * Database tables, per site.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

use Hoptrail\Admin\Tour;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and drops the per-site tables. The version option is only written after the tables
 * provably exist, so a failed dbDelta is retried instead of marked done.
 */
final class Installer extends Singleton {

	/**
	 * Schema version.
	 */
	const DB_VERSION = '1.2.0';

	/**
	 * Table name suffixes before 1.2.0 (the prefix was too short for WordPress.org); renamed on upgrade.
	 */
	const LEGACY_TABLES = [
		'rdi_scans'  => 'hoptrail_scans',
		'rdi_issues' => 'hoptrail_issues',
		'rdi_fixes'  => 'hoptrail_fixes',
	];

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'hoptrail_db_version';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_install' ] );
		add_action( 'wp_initialize_site', [ self::class, 'on_initialize_site' ], 200 );
		add_filter( 'wpmu_drop_tables', [ self::class, 'drop_site_tables' ], 10, 2 );
	}

	/**
	 * Table name of the scans log.
	 *
	 * @return string
	 */
	public static function scans_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'hoptrail_scans';
	}

	/**
	 * Table name of the issues found per scan.
	 *
	 * @return string
	 */
	public static function issues_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'hoptrail_issues';
	}

	/**
	 * Table name of the fix log (who changed which rule, before and after).
	 *
	 * @return string
	 */
	public static function fixes_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'hoptrail_fixes';
	}

	/**
	 * Activation: install on this site, or on every site when network-activated.
	 *
	 * @param bool $network_wide Network activation.
	 *
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Deactivation: stop the cleanup. Data stays until uninstall.
	 *
	 * @param bool $network_wide Network deactivation.
	 *
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Stop the cleanup and release locks on the current site.
	 *
	 * @return void
	 */
	private static function deactivate_site(): void {
		wp_clear_scheduled_hook( Retention::HOOK );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		Lock::release( Scanner::LOCK_OPTION );
		Lock::release( Retention::LOCK_OPTION );
	}

	/**
	 * Install when the stored schema version is missing or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Whether this site's tables are installed at the current version.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return self::DB_VERSION === get_option( self::VERSION_OPTION );
	}

	/**
	 * Rename tables from before 1.2.0 (rdi_*) to their current names, keeping the history.
	 *
	 * @return void
	 */
	private static function rename_legacy_tables(): void {
		global $wpdb;

		foreach ( self::LEGACY_TABLES as $old => $new ) {
			if ( self::table_exists( $wpdb->prefix . $old ) && ! self::table_exists( $wpdb->prefix . $new ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table, one-off rename on upgrade.
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $wpdb->prefix . $old, $wpdb->prefix . $new ) );
			}
		}
	}

	/**
	 * Create or upgrade the tables on the current site.
	 *
	 * @return bool Whether both tables exist afterwards.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::rename_legacy_tables();

		$charset = $wpdb->get_charset_collate();
		$scans   = self::scans_table();
		$issues  = self::issues_table();

		dbDelta(
			"CREATE TABLE {$scans} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				status VARCHAR(20) NOT NULL DEFAULT 'running',
				trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				user_login VARCHAR(60) NOT NULL DEFAULT '',
				started_at DATETIME NOT NULL,
				finished_at DATETIME NULL,
				duration_ms INT(10) UNSIGNED NOT NULL DEFAULT 0,
				rule_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
				issue_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
				score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
				totals LONGTEXT NULL,
				message VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY started_at (started_at),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$issues} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_id BIGINT(20) UNSIGNED NOT NULL,
				type VARCHAR(20) NOT NULL,
				severity VARCHAR(10) NOT NULL,
				fingerprint CHAR(40) NOT NULL,
				source_url VARCHAR(2048) NOT NULL DEFAULT '',
				target_url VARCHAR(2048) NOT NULL DEFAULT '',
				plugins VARCHAR(255) NOT NULL DEFAULT '',
				hops LONGTEXT NULL,
				suggestion TEXT NULL,
				fix LONGTEXT NULL,
				fixed LONGTEXT NULL,
				live_result LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY scan_type (scan_id, type),
				KEY fingerprint (fingerprint)
			) {$charset};"
		);

		$fixes = self::fixes_table();
		dbDelta(
			"CREATE TABLE {$fixes} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				user_login VARCHAR(60) NOT NULL DEFAULT '',
				issue_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(20) NOT NULL,
				source VARCHAR(40) NOT NULL,
				rule_id VARCHAR(40) NOT NULL,
				before_value LONGTEXT NULL,
				after_value LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);

		$ok = self::table_exists( $scans ) && self::table_exists( $issues ) && self::table_exists( $fixes );

		if ( $ok ) {
			update_option( self::VERSION_OPTION, self::DB_VERSION, true );
		}

		return $ok;
	}

	/**
	 * Remove what the plugin stores per user, once for the whole install (user meta is shared by
	 * every site of a network): the walkthrough progress, and the plugin's entries in each user's
	 * list of dismissed admin pointers. Other plugins' entries stay.
	 *
	 * @return void
	 */
	public static function uninstall_users(): void {
		delete_metadata( 'user', 0, Tour::META, '', true );

		$users = get_users(
			[
				'blog_id'  => 0,
				'fields'   => 'ID',
				'meta_key' => 'dismissed_wp_pointers', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off, at uninstall.
			]
		);
		foreach ( $users as $user_id ) {
			$dismissed = array_filter( explode( ',', (string) get_user_meta( (int) $user_id, 'dismissed_wp_pointers', true ) ) );
			$kept      = array_filter( $dismissed, static fn( $pointer ) => ! str_starts_with( $pointer, 'hoptrail_tour_' ) );
			if ( count( $kept ) !== count( $dismissed ) ) {
				update_user_meta( (int) $user_id, 'dismissed_wp_pointers', implode( ',', $kept ) );
			}
		}
	}

	/**
	 * Drop the tables, options, transients, locks and scheduled events of the current site.
	 *
	 * @return void
	 */
	public static function uninstall_site(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own tables, names from $wpdb->prefix.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i, %i', self::issues_table(), self::scans_table(), self::fixes_table() ) );
		// Tables from before 1.2.0, should an upgrade never have run.
		foreach ( array_keys( self::LEGACY_TABLES ) as $old ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $old ) );
		}

		delete_option( self::VERSION_OPTION );
		delete_option( Settings::OPTION );
		delete_option( Scanner::LOCK_OPTION );
		delete_option( Retention::LOCK_OPTION );
		delete_option( Fixer::LOCK );
		delete_option( Presence::STATE );
		delete_option( Flush_Requests::OPTION );
		// Manifest of the development seed script (bin/seed-dummy-data.php), if it ever ran here.
		delete_option( 'hoptrail_seed' );
		delete_transient( Presence::PRESENCE );
		// Per-user live-check locks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( Live_Checker::LOCK_PREFIX ) . '%' ) );
		wp_clear_scheduled_hook( Retention::HOOK );
		wp_clear_scheduled_hook( Scheduler::HOOK );
	}

	/**
	 * New site on a network where the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 *
	 * @return void
	 */
	public static function on_initialize_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( HOPTRAIL_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Site deleted: core drops its options with its tables; add ours to the list.
	 *
	 * @param string[] $tables  Tables core will drop.
	 * @param int      $site_id Site being deleted.
	 *
	 * @return string[]
	 */
	public static function drop_site_tables( $tables, $site_id ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( (int) $site_id );
		foreach ( self::LEGACY_TABLES as $old => $new ) {
			$tables[] = $prefix . $new;
			$tables[] = $prefix . $old; // From before 1.2.0, if never upgraded.
		}

		return (array) $tables;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
