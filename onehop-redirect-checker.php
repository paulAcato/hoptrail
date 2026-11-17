<?php
/**
 * Plugin Name:       Onehop Redirect Checker
 * Plugin URI:        https://github.com/paulAcato/onehop-redirect-checker
 * Description:       Finds redirect loops, chains and redirects you don't need across ten SEO and redirect plugins at once, and fixes the clear-cut ones.
 * Version:           1.2.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Paul van Impelen
 * Author URI:        https://profiles.wordpress.org/paulacato/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       onehop-redirect-checker
 * Domain Path:       /languages
 *
 * @package Onehop\RedirectChecker
 */

defined( 'ABSPATH' ) || exit;

define( 'ONEHOP_REDIRECT_CHECKER_VERSION', '1.2.0' );
// Display name and WP-CLI command (`wp onehop-redirect-checker …`). The plugin header, readme.txt and the WP-CLI
// docblocks in includes/class-cli.php can't use these: update them too when renaming.
define( 'ONEHOP_REDIRECT_CHECKER_NAME', 'Onehop Redirect Checker' );
define( 'ONEHOP_REDIRECT_CHECKER_CLI_COMMAND', 'onehop-redirect-checker' );
define( 'ONEHOP_REDIRECT_CHECKER_FILE', __FILE__ );
define( 'ONEHOP_REDIRECT_CHECKER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ONEHOP_REDIRECT_CHECKER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the Onehop Redirect Checker namespace.
 *
 * Onehop\RedirectChecker\Includes\Sources\Rank_Math → includes/sources/class-rank-math.php.
 * Resolves classes, then traits, then interfaces.
 *
 * @param string $fqcn Fully qualified class name.
 *
 * @return void
 */
function onehop_redirect_checker_autoload( $fqcn ) {
	$prefix = 'Onehop\\RedirectChecker\\';
	if ( 0 !== strpos( $fqcn, $prefix ) ) {
		return;
	}

	$relative = strtolower( str_replace( [ $prefix, '\\', '_' ], [ '', '/', '-' ], $fqcn ) );
	$dir      = dirname( $relative );
	$name     = basename( $relative );

	foreach ( [ "class-{$name}.php", "trait-{$name}.php", "interface-{$name}.php" ] as $file ) {
		$path = ONEHOP_REDIRECT_CHECKER_PATH . $dir . '/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'onehop_redirect_checker_autoload' );

Onehop\RedirectChecker\Includes\Plugin::instance();
