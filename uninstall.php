<?php
/**
 * Uninstall: remove everything Hoptrail stored, on every site of a network: its tables,
 * options, transients, locks and scheduled events, and its user meta. Other plugins' redirect rules
 * (including rules it migrated or fixed there) are theirs and stay.
 *
 * @package Hoptrail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/hoptrail.php';

use Hoptrail\Includes\Installer;

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $hoptrail_site_id ) {
		switch_to_blog( (int) $hoptrail_site_id );
		Installer::uninstall_site();
		restore_current_blog();
	}
} else {
	Installer::uninstall_site();
}

// User meta is shared by every site, so once.
Installer::uninstall_users();
