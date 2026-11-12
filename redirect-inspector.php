<?php
/**
 * Plugin Name:       Redirect Inspector
 * Plugin URI:        https://github.com/paulAcato/redirect-inspector
 * Description:       Finds redirect loops, chains and redirects you don't need across Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager and Simple 301 Redirects, and fixes the clear-cut ones.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Paul van Impelen
 * Author URI:        https://profiles.wordpress.org/paulacato/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redirect-inspector
 * Domain Path:       /languages
 *
 * @package Acato\RedirectInspector
 */

defined( 'ABSPATH' ) || exit;

define( 'REDIRECT_INSPECTOR_VERSION', '1.0.0' );
define( 'REDIRECT_INSPECTOR_FILE', __FILE__ );
define( 'REDIRECT_INSPECTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'REDIRECT_INSPECTOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the Acato\RedirectInspector namespace.
 *
 * Acato\RedirectInspector\Includes\Sources\Rank_Math → includes/sources/class-rank-math.php.
 * Resolves classes, then traits, then interfaces.
 *
 * @param string $fqcn Fully qualified class name.
 *
 * @return void
 */
function redirect_inspector_autoload( $fqcn ) {
	$prefix = 'Acato\\RedirectInspector\\';
	if ( 0 !== strpos( $fqcn, $prefix ) ) {
		return;
	}

	$relative = strtolower( str_replace( [ $prefix, '\\', '_' ], [ '', '/', '-' ], $fqcn ) );
	$dir      = dirname( $relative );
	$name     = basename( $relative );

	foreach ( [ "class-{$name}.php", "trait-{$name}.php", "interface-{$name}.php" ] as $file ) {
		$path = REDIRECT_INSPECTOR_PATH . $dir . '/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'redirect_inspector_autoload' );

Acato\RedirectInspector\Includes\Plugin::instance();
