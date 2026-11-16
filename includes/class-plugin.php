<?php
/**
 * Main plugin class: wires everything. Nothing runs on front-end requests except the cleanup cron.
 *
 * @package Onehop\RedirectChecker
 */

namespace Onehop\RedirectChecker\Includes;

use Onehop\RedirectChecker\Admin\Admin_Page;
use Onehop\RedirectChecker\Admin\Dashboard_Widget;
use Onehop\RedirectChecker\Admin\Tour;

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
		register_activation_hook( ONEHOP_REDIRECT_CHECKER_FILE, [ Installer::class, 'activate' ] );
		register_deactivation_hook( ONEHOP_REDIRECT_CHECKER_FILE, [ Installer::class, 'deactivate' ] );

		Installer::instance();
		Retention::instance();
		Scheduler::instance();
		Presence::instance();
		Tour::instance();

		// WordPress 7.1+ Abilities API (the class checks the version itself).
		if ( class_exists( Abilities::class ) ) {
			Abilities::instance();
		}

		if ( is_admin() ) {
			Admin_Page::instance();
			Dashboard_Widget::instance();
		}

		add_action( 'rest_api_init', [ Rest_Controller::instance(), 'register_routes' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( ONEHOP_REDIRECT_CHECKER_CLI_COMMAND, Cli::class );
		}
	}
}
