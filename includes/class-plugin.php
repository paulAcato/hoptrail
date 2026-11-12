<?php
/**
 * Main plugin class: wires everything. Nothing runs on front-end requests except the cleanup cron.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Admin\Admin_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap.
 */
final class Plugin extends Singleton {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		register_activation_hook( REDIRECT_INSPECTOR_FILE, [ Installer::class, 'activate' ] );
		register_deactivation_hook( REDIRECT_INSPECTOR_FILE, [ Installer::class, 'deactivate' ] );

		Installer::instance();
		Retention::instance();
		Scheduler::instance();
		Presence::instance();

		// WordPress 7.1+ Abilities API (the class checks the version itself).
		if ( class_exists( Abilities::class ) ) {
			Abilities::instance();
		}

		if ( is_admin() ) {
			Admin_Page::instance();
		}

		add_action( 'rest_api_init', [ Rest_Controller::instance(), 'register_routes' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'redirect-inspector', Cli::class );
		}
	}
}
