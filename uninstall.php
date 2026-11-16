<?php
/**
 * Uninstall: remove everything Onehop Redirect Checker stored, on every site of a network: its tables,
 * options, transients, locks and scheduled events, and its user meta. Other plugins' redirect rules
 * (including rules it migrated or fixed there) are theirs and stay.
 *
 * @package Onehop\RedirectChecker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/onehop-redirect-checker.php';

use Onehop\RedirectChecker\Admin\Rating_Notice;
use Onehop\RedirectChecker\Includes\Installer;

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $onehop_redirect_checker_site_id ) {
		switch_to_blog( (int) $onehop_redirect_checker_site_id );
		Installer::uninstall_site();
		restore_current_blog();
	}
} else {
	Installer::uninstall_site();
}

// User meta and network options are shared by every site, so once.
Installer::uninstall_users();
Rating_Notice::uninstall();
