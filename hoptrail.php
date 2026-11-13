<?php
/**
 * Plugin Name:       Hoptrail – Redirect Loop & Chain Checker
 * Plugin URI:        https://github.com/paulAcato/hoptrail
 * Description:       Finds redirect loops, chains and redirects you don't need across ten SEO and redirect plugins at once, and fixes the clear-cut ones.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Paul van Impelen
 * Author URI:        https://profiles.wordpress.org/paulacato/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hoptrail
 * Domain Path:       /languages
 *
 * @package Hoptrail
 */

defined( 'ABSPATH' ) || exit;

define( 'HOPTRAIL_VERSION', '1.0.0' );
// Display name and WP-CLI command (`wp hoptrail …`). The plugin header, readme.txt and the WP-CLI
// docblocks in includes/class-cli.php can't use these: update them too when renaming.
define( 'HOPTRAIL_NAME', 'Hoptrail' );
define( 'HOPTRAIL_CLI_COMMAND', 'hoptrail' );
define( 'HOPTRAIL_FILE', __FILE__ );
define( 'HOPTRAIL_PATH', plugin_dir_path( __FILE__ ) );
define( 'HOPTRAIL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the Hoptrail namespace.
 *
 * Hoptrail\Includes\Sources\Rank_Math → includes/sources/class-rank-math.php.
 * Resolves classes, then traits, then interfaces.
 *
 * @param string $fqcn Fully qualified class name.
 *
 * @return void
 */
function hoptrail_autoload( $fqcn ) {
	$prefix = 'Hoptrail\\';
	if ( 0 !== strpos( $fqcn, $prefix ) ) {
		return;
	}

	$relative = strtolower( str_replace( [ $prefix, '\\', '_' ], [ '', '/', '-' ], $fqcn ) );
	$dir      = dirname( $relative );
	$name     = basename( $relative );

	foreach ( [ "class-{$name}.php", "trait-{$name}.php", "interface-{$name}.php" ] as $file ) {
		$path = HOPTRAIL_PATH . $dir . '/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'hoptrail_autoload' );

Hoptrail\Includes\Plugin::instance();
