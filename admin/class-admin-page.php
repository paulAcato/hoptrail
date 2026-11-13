<?php
/**
 * Tools → Hoptrail and the network overview page.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Admin;

use Hoptrail\Includes\Caps;
use Hoptrail\Includes\Presence;
use Hoptrail\Includes\Retention;
use Hoptrail\Includes\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the screens; the React app mounts into an empty root.
 */
final class Admin_Page extends Singleton {

	/**
	 * Menu slug.
	 */
	const SLUG = 'hoptrail';

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var string[]
	 */
	private array $hooks = [];

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_menu', [ $this, 'register_site_page' ] );
		add_action( 'network_admin_menu', [ $this, 'register_network_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( HOPTRAIL_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * Tools → Hoptrail.
	 *
	 * @return void
	 */
	public function register_site_page(): void {
		$hook = add_management_page(
			HOPTRAIL_NAME,
			HOPTRAIL_NAME,
			Caps::MANAGE,
			self::SLUG,
			[ $this, 'render' ]
		);

		if ( $hook ) {
			$this->hooks[ $hook ] = 'site';
		}
	}

	/**
	 * Network Admin → Settings → Hoptrail (read-only overview).
	 *
	 * @return void
	 */
	public function register_network_page(): void {
		$hook = add_submenu_page(
			'settings.php',
			HOPTRAIL_NAME,
			HOPTRAIL_NAME,
			Caps::NETWORK,
			self::SLUG,
			[ $this, 'render' ]
		);

		if ( $hook ) {
			$this->hooks[ $hook ] = 'network';
		}
	}

	/**
	 * Mount point. Without JavaScript the notice explains why nothing shows.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap rdi-wrap">
			<hr class="wp-header-end">
			<div id="hoptrail-root">
				<noscript><h1><?php echo esc_html( HOPTRAIL_NAME ); ?></h1><div class="notice notice-error"><p>
				<?php
				printf(
					/* translators: 1: plugin name, 2: WP-CLI command, e.g. wp hoptrail scan. */
					esc_html__( '%1$s needs JavaScript. Alternatively, run %2$s.', 'hoptrail' ),
					esc_html( HOPTRAIL_NAME ),
					'<code>wp ' . esc_html( HOPTRAIL_CLI_COMMAND ) . ' scan</code>'
				);
				?>
				</p></div></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Load the app on our screens only.
	 *
	 * @param string $hook_suffix Current screen.
	 *
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! isset( $this->hooks[ $hook_suffix ] ) ) {
			return;
		}

		$mode = $this->hooks[ $hook_suffix ];

		if ( ! Assets::enqueue_app() ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						sprintf(
							/* translators: 1: plugin name, 2: build command, npm run build. */
							esc_html__( '%1$s: the dashboard assets are missing. Run %2$s in the plugin folder.', 'hoptrail' ),
							esc_html( HOPTRAIL_NAME ),
							'<code>npm run build</code>'
						)
					);
				}
			);
			return;
		}

		wp_add_inline_script(
			Assets::HANDLE,
			'window.hoptrail = ' . wp_json_encode(
				[
					'mode'          => $mode,
					'name'          => HOPTRAIL_NAME,
					'version'       => HOPTRAIL_VERSION,
					'retention'     => Retention::describe(),
					'siteUrl'       => home_url( '/' ),
					'startOfWeek'   => (int) get_option( 'start_of_week', 1 ),
					'timezone'      => wp_timezone_string(),
					'wapuu'         => HOPTRAIL_URL . 'assets/images/wapuu.svg',
					'stateVersion'  => Presence::version(),
					'userId'        => get_current_user_id(),
					'tour'          => Tour::boot(),
					'canDropTables' => Caps::can_drop_tables(),
				],
				// Never let a value end the inline script, whatever is added to this later.
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}


	/**
	 * Mark our screens, so the colour scheme (light/dark/contrast) can style the whole page.
	 *
	 * @param string $classes Space-separated classes.
	 *
	 * @return string
	 */
	public function body_class( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && in_array( $screen->id, [ 'tools_page_' . self::SLUG, 'settings_page_' . self::SLUG . '-network' ], true ) ) {
			$classes .= ' rdi-page';
		}

		return (string) $classes;
	}

	/**
	 * "Settings" link on the plugins screen, to the retention settings on the dashboard.
	 *
	 * @param string[] $links Links.
	 *
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( Caps::can_manage() ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( admin_url( 'tools.php?page=' . self::SLUG . '#settings' ) ),
					esc_html__( 'Settings', 'hoptrail' )
				)
			);
		}

		return (array) $links;
	}
}
