<?php
/**
 * The quick walkthrough: WordPress admin pointers, versioned per administrator.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Admin;

use Hoptrail\Includes\Caps;
use Hoptrail\Includes\Rest_Controller;
use Hoptrail\Includes\Singleton;
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
	const META = 'hoptrail_tour';

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
		return 'hoptrail_tour_' . str_replace( '.', '_', self::VERSION );
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
			? __( 'Find redirect loops, chains and redirects you don’t need, across all your SEO and redirect plugins. A quick walkthrough shows you around.', 'hoptrail' )
			: __( 'Hoptrail has new features. A quick walkthrough shows what changed.', 'hoptrail' );

		$pointer = [
			'name'     => self::pointer_name(),
			'title'    => 'Hoptrail',
			'text'     => $text,
			'link'     => admin_url( 'tools.php?page=' . Admin_Page::SLUG ),
			'linkText' => __( 'Take the walkthrough', 'hoptrail' ),
			'dismiss'  => __( 'Not now', 'hoptrail' ),
			'wapuu'    => HOPTRAIL_URL . 'assets/images/wapuu.svg',
		];

		// Our own look (the dashboard banner's), placed by a few lines of plain JavaScript.
		wp_enqueue_style( 'hoptrail-pointer', HOPTRAIL_URL . 'assets/menu-pointer.css', [], HOPTRAIL_VERSION );
		wp_enqueue_script( 'hoptrail-pointer', HOPTRAIL_URL . 'assets/menu-pointer.js', [], HOPTRAIL_VERSION, true );
		wp_add_inline_script( 'hoptrail-pointer', 'window.hoptrailPointer = ' . wp_json_encode( $pointer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
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
