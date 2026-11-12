<?php
/**
 * The quick walkthrough: WordPress admin pointers, versioned per administrator.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Admin;

use Acato\RedirectInspector\Includes\Caps;
use Acato\RedirectInspector\Includes\Rest_Controller;
use Acato\RedirectInspector\Includes\Singleton;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Like many plugins, a pointer on the Tools menu (in our banner's look) leads to the dashboard, where
 * a short walkthrough shows the main parts. Every step carries the version it was added in
 * (assets/scripts/lib/tour.ts); each administrator's last completed version is kept in user meta.
 * Bump VERSION when a release adds steps: everyone who saw an older walkthrough gets one with only
 * the new steps; who never saw one gets all of them.
 */
final class Tour extends Singleton {

	/**
	 * Walkthrough version: the highest `since` of the steps.
	 */
	const VERSION = '1.0.0';

	/**
	 * User meta: the walkthrough version this user last completed or skipped.
	 */
	const META = 'redirect_inspector_tour';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'menu_pointer' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * The version this user last saw ('' = never), or null when they are up to date.
	 *
	 * @return string|null
	 */
	public static function pending(): ?string {
		if ( ! Caps::can_manage() ) {
			return null;
		}

		$seen = (string) get_user_meta( get_current_user_id(), self::META, true );

		return version_compare( $seen ? $seen : '0', self::VERSION, '<' ) ? $seen : null;
	}

	/**
	 * Boot data for the dashboard.
	 *
	 * @return array{version:string, seen:string, pending:bool}
	 */
	public static function boot(): array {
		$pending = self::pending();

		return [
			'version' => self::VERSION,
			'seen'    => (string) $pending,
			'pending' => null !== $pending,
		];
	}

	/**
	 * Core's pointer name for this version (dismissed per user through core's dismiss-wp-pointer).
	 *
	 * @return string
	 */
	private static function pointer_name(): string {
		return 'redirect_inspector_tour_' . str_replace( '.', '_', self::VERSION );
	}

	/**
	 * Pointer on the Tools menu, on every admin screen but our own, until opened or dismissed.
	 *
	 * @param string $hook_suffix Current screen.
	 *
	 * @return void
	 */
	public function menu_pointer( $hook_suffix ): void {
		$pending = self::pending();
		if ( null === $pending || 'tools_page_' . Admin_Page::SLUG === $hook_suffix || is_network_admin() ) {
			return;
		}

		$dismissed = array_filter( explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) ) );
		if ( in_array( self::pointer_name(), $dismissed, true ) ) {
			return;
		}

		$text = '' === $pending
			? __( 'Find redirect loops, chains and redirects you don’t need, across all your SEO and redirect plugins. A quick walkthrough shows you around.', 'redirect-inspector' )
			: __( 'Redirect Inspector has new features. A quick walkthrough shows what changed.', 'redirect-inspector' );

		$pointer = [
			'name'     => self::pointer_name(),
			'title'    => __( 'Redirect Inspector', 'redirect-inspector' ),
			'text'     => $text,
			'link'     => admin_url( 'tools.php?page=' . Admin_Page::SLUG ),
			'linkText' => __( 'Take the walkthrough', 'redirect-inspector' ),
			'dismiss'  => __( 'Not now', 'redirect-inspector' ),
			'wapuu'    => REDIRECT_INSPECTOR_URL . 'assets/images/wapuu.svg',
		];

		// Our own look (the dashboard banner's), placed by a few lines of plain JavaScript.
		wp_enqueue_style( 'redirect-inspector-pointer', REDIRECT_INSPECTOR_URL . 'assets/menu-pointer.css', [], REDIRECT_INSPECTOR_VERSION );
		wp_enqueue_script( 'redirect-inspector-pointer', REDIRECT_INSPECTOR_URL . 'assets/menu-pointer.js', [], REDIRECT_INSPECTOR_VERSION, true );
		wp_add_inline_script( 'redirect-inspector-pointer', 'window.redirectInspectorPointer = ' . wp_json_encode( $pointer ) . ';', 'before' );
	}

	/**
	 * POST /tour: the walkthrough was completed or skipped (stores the current version).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/tour',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => [ Caps::class, 'can_manage' ],
				'callback'            => static function (): WP_REST_Response {
					update_user_meta( get_current_user_id(), self::META, self::VERSION );
					return new WP_REST_Response( [ 'version' => self::VERSION ] );
				},
			]
		);
	}
}
