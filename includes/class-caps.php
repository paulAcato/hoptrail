<?php
/**
 * Capabilities: one constant per surface, used by menus and every handler.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

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
}
