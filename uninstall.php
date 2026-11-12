<?php
/**
 * Uninstall: drop tables, options and cron on every site.
 *
 * @package Acato\RedirectInspector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/redirect-inspector.php';

use Acato\RedirectInspector\Includes\Installer;

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $redirect_inspector_site_id ) {
		switch_to_blog( (int) $redirect_inspector_site_id );
		Installer::uninstall_site();
		restore_current_blog();
	}
} else {
	Installer::uninstall_site();
}
