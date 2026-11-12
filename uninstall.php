<?php
/**
 * Uninstall: remove everything Redirect Inspector stored, on every site of a network: its tables,
 * options, transients, locks and scheduled events, and its user meta. Other plugins' redirect rules
 * (including rules it migrated or fixed there) are theirs and stay.
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

// User meta is shared by every site, so once.
Installer::uninstall_users();
