<?php
/**
 * Capabilities: one constant per surface, used by menus and every handler.
 *
 * @package Onehop\RedirectChecker
 */

namespace Onehop\RedirectChecker\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Who may do what (as agreed: administrators per site, super admins on the network overview).
 */
final class Caps {

	/**
	 * View the per-site dashboard and act on it (scan, live check, settings, purge).
	 */
	const MANAGE = 'manage_options';

	/**
	 * View the network overview.
	 */
	const NETWORK = 'manage_network_options';

	/**
	 * Whether the current user may use the per-site dashboard.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::MANAGE );
	}

	/**
	 * Whether the current user may see the network overview.
	 *
	 * @return bool
	 */
	public static function can_view_network(): bool {
		return is_multisite() && current_user_can( self::NETWORK );
	}

	/**
	 * Whether the current user may see the dashboard widget (read-only): administrators, plus every
	 * role an active SEO or redirect plugin lets manage its settings or redirects.
	 *
	 * @return bool
	 */
	public static function can_view_widget(): bool {
		if ( self::can_manage() ) {
			return true;
		}

		foreach ( self::seo_capabilities() as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Capabilities with which the active supported plugins grant managing their SEO settings or
	 * redirects. Plugins that only use manage_options are covered by MANAGE.
	 *
	 * @return string[]
	 */
	public static function seo_capabilities(): array {
		$map = [
			'redirection'           => static fn() => [ (string) apply_filters( 'redirection_role', 'manage_options' ) ], // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Redirection's own filter.
			'yoast'                 => static fn() => [ 'wpseo_manage_options' ],
			'rank-math'             => static fn() => [ 'rank_math_general', 'rank_math_titles', 'rank_math_redirections' ],
			'safe-redirect-manager' => static fn() => [ (string) apply_filters( 'srm_restrict_to_capability', 'srm_manage_redirects' ) ], // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Safe Redirect Manager's own filter.
			'seopress'              => static fn() => [ 'seopress_manage_titles_metas', 'seopress_manage_advanced' ],
			'the-seo-framework'     => static fn() => defined( 'THE_SEO_FRAMEWORK_SETTINGS_CAP' ) ? [ (string) constant( 'THE_SEO_FRAMEWORK_SETTINGS_CAP' ) ] : [],
		];

		$capabilities = [];
		foreach ( Sources\Registry::all() as $slug => $source ) {
			if ( isset( $map[ $slug ] ) && $source->plugin_active() ) {
				$capabilities = array_merge( $capabilities, $map[ $slug ]() );
			}
		}

		/**
		 * Filter the capabilities that may see the Onehop Redirect Checker dashboard widget,
		 * besides manage_options.
		 *
		 * @param string[] $capabilities Capabilities.
		 */
		$capabilities = (array) apply_filters( 'onehop_redirect_checker_widget_capabilities', $capabilities );

		return array_values( array_unique( array_filter( array_map( 'strval', $capabilities ) ) ) );
	}

	/**
	 * Whether the current user may drop another plugin's database tables (leftover clean-up).
	 * On a multisite network that is uninstall-like work for a super admin, not a site admin.
	 *
	 * @return bool
	 */
	public static function can_drop_tables(): bool {
		return self::can_manage() && ( ! is_multisite() || is_super_admin() );
	}
}
