<?php
/**
 * The quick walkthrough of the dashboard, taken when somebody asks for it.
 *
 * @package Onehop\RedirectChecker
 */

namespace Onehop\RedirectChecker\Admin;

use Onehop\RedirectChecker\Includes\Caps;
use Onehop\RedirectChecker\Includes\Rest_Controller;
use Onehop\RedirectChecker\Includes\Settings;
use Onehop\RedirectChecker\Includes\Singleton;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * A short walkthrough shows the main parts of the dashboard. Every step carries the version it was
 * added in (assets/scripts/lib/tour.ts); each administrator's last completed version is kept in user
 * meta. Bump VERSION when a release adds steps: everyone who saw an older walkthrough gets one with
 * only the new steps; who never saw one gets all of them.
 *
 * It never starts by itself. It is started from the "Start walkthrough" link beside Settings on the
 * Plugins screen, tagged "New" while this user has steps they have not seen, or from the link at the
 * foot of the dashboard. A site can switch it off in the settings, and code can with the
 * `onehop_redirect_checker_walkthrough` filter or the ONEHOP_REDIRECT_CHECKER_DISABLE_WALKTHROUGH
 * constant; then neither link shows and the dashboard gets nothing of it.
 */
final class Tour extends Singleton {

	/**
	 * Walkthrough version: the highest `since` of the steps.
	 */
	const VERSION = '1.0.0';

	/**
	 * User meta: the walkthrough version this user last completed or skipped.
	 */
	const META = 'onehop_redirect_checker_tour';

	/**
	 * Query argument on the dashboard's URL that starts the walkthrough.
	 */
	const START_ARG = 'onehop-walkthrough';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// After Admin_Page's own Settings link, at 10, so this one comes next to it.
		add_filter( 'plugin_action_links_' . plugin_basename( ONEHOP_REDIRECT_CHECKER_FILE ), [ $this, 'add_action_link' ], 11 );
		add_action( 'admin_enqueue_scripts', [ $this, 'tag_style' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Whether the walkthrough is offered on this site at all.
	 *
	 * Code has the last word: the ONEHOP_REDIRECT_CHECKER_DISABLE_WALKTHROUGH constant switches it
	 * off everywhere, and the `onehop_redirect_checker_walkthrough` filter decides per site.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'ONEHOP_REDIRECT_CHECKER_DISABLE_WALKTHROUGH' ) && ONEHOP_REDIRECT_CHECKER_DISABLE_WALKTHROUGH ) {
			return false;
		}

		/**
		 * Whether the walkthrough of the dashboard is offered.
		 *
		 * @param bool $enabled Whether the site's settings switch it on.
		 */
		return (bool) apply_filters( 'onehop_redirect_checker_walkthrough', Settings::all()['walkthrough'] );
	}

	/**
	 * The version this user last saw ('' = never), or null when they are up to date, may not
	 * manage the plugin, or the walkthrough is switched off.
	 *
	 * @return string|null
	 */
	public static function pending(): ?string {
		if ( ! Caps::can_manage() || ! self::is_enabled() ) {
			return null;
		}

		$seen = (string) get_user_meta( get_current_user_id(), self::META, true );

		return version_compare( $seen ? $seen : '0', self::VERSION, '<' ) ? $seen : null;
	}

	/**
	 * Boot data for the dashboard, or null when the walkthrough is switched off.
	 *
	 * @return array{version:string, seen:string, pending:bool, start:string}|null
	 */
	public static function boot(): ?array {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$pending = self::pending();

		return [
			'version' => self::VERSION,
			'seen'    => (string) $pending,
			'pending' => null !== $pending,
			'start'   => self::START_ARG,
		];
	}

	/**
	 * The "Start walkthrough" link beside Settings on the Plugins screen, tagged "New" while this
	 * user has steps they have not seen.
	 *
	 * @param mixed $links Action links of the plugin's row.
	 *
	 * @return mixed
	 */
	public function add_action_link( $links ) {
		if ( ! is_array( $links ) || ! Caps::can_manage() || ! self::is_enabled() ) {
			return $links;
		}

		$tag = null !== self::pending()
			? ' <span class="rdi-walkthrough-tag">' . esc_html_x( 'New', 'walkthrough tag on the Plugins screen', 'onehop-redirect-checker' ) . '</span>'
			: '';

		$link = sprintf(
			'<a href="%s">%s</a>%s',
			esc_url( add_query_arg( self::START_ARG, '1', admin_url( 'tools.php?page=' . Admin_Page::SLUG ) ) ),
			esc_html_x( 'Start walkthrough', 'plugin action link', 'onehop-redirect-checker' ),
			$tag
		);

		// Right after Settings, which Admin_Page puts first.
		array_splice( $links, 1, 0, [ $link ] );

		return $links;
	}

	/**
	 * The look of the "New" tag, on the Plugins screen only, while it shows.
	 *
	 * @param string $hook_suffix Current screen.
	 *
	 * @return void
	 */
	public function tag_style( $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix || is_network_admin() || null === self::pending() ) {
			return;
		}

		// Shaped like core's update count bubbles, in core's red, white on it.
		wp_register_style( 'onehop-redirect-checker-walkthrough-tag', false, [], ONEHOP_REDIRECT_CHECKER_VERSION );
		wp_enqueue_style( 'onehop-redirect-checker-walkthrough-tag' );
		wp_add_inline_style(
			'onehop-redirect-checker-walkthrough-tag',
			'.rdi-walkthrough-tag{display:inline-block;margin-inline-start:4px;padding:0 6px;border-radius:9px;background:#d63638;color:#fff;font-size:9px;font-weight:600;line-height:17px;text-transform:uppercase;vertical-align:text-top}@media (forced-colors:active){.rdi-walkthrough-tag{border:1px solid CanvasText}}'
		);
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
