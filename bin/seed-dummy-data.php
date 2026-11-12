<?php
/**
 * Dummy redirects for every supported plugin. Development only; not part of the release.
 *
 * Usage: wp eval-file bin/seed-dummy-data.php            (seed / re-seed)
 *        wp eval-file bin/seed-dummy-data.php remove     (remove seed data)
 *
 * Idempotent: everything it creates is recorded in the `redirect_inspector_seed` option and
 * removed again on the next run (S12: it never touches rules it didn't create).
 *
 * @package Acato\RedirectInspector
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	WP_CLI::error( 'The seed script only runs on local or development environments.' );
}

const RDI_SEED_OPTION = 'redirect_inspector_seed';

$manifest = get_option( RDI_SEED_OPTION, [] );
$manifest = is_array( $manifest ) ? $manifest : [];

global $wpdb;

/**
 * Whether one of this site's tables exists (plugins keep their tables while inactive).
 *
 * @param string $table Table name without prefix.
 *
 * @return bool
 */
$rdi_has_table = static function ( string $table ) use ( $wpdb ): bool {
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) === $wpdb->prefix . $table; // phpcs:ignore
};

/*
 * 1. Remove the previous seed.
 */

if ( ! empty( $manifest['redirection_group'] ) && $rdi_has_table( 'redirection_groups' ) ) {
	// Directly, so it also works while Redirection is inactive.
	$wpdb->delete( $wpdb->prefix . 'redirection_items', [ 'group_id' => (int) $manifest['redirection_group'] ] ); // phpcs:ignore
	$wpdb->delete( $wpdb->prefix . 'redirection_groups', [ 'id' => (int) $manifest['redirection_group'] ] ); // phpcs:ignore
}

if ( ! empty( $manifest['rank_math_ids'] ) && $rdi_has_table( 'rank_math_redirections' ) ) {
	$ids = implode( ',', array_map( 'absint', $manifest['rank_math_ids'] ) );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}rank_math_redirections WHERE id IN ({$ids})" ); // phpcs:ignore
}

foreach ( $manifest['srm_ids'] ?? [] as $seed_post_id ) {
	wp_delete_post( (int) $seed_post_id, true );
}

$yoast = get_option( 'wpseo-premium-redirects-base', [] );
$yoast = is_array( $yoast ) ? $yoast : [];
$yoast = array_values( array_filter( $yoast, static fn( $item ) => ! in_array( $item['origin'] ?? '', $manifest['yoast_origins'] ?? [], true ) ) );

$simple = get_option( '301_redirects', [] );
$simple = is_array( $simple ) ? $simple : [];
foreach ( $manifest['simple_keys'] ?? [] as $key ) {
	unset( $simple[ $key ] );
}

foreach ( $manifest['post_ids'] ?? [] as $seed_post_id ) {
	wp_delete_post( (int) $seed_post_id, true );
}

if ( ! empty( $manifest['eps_ids'] ) && $rdi_has_table( 'redirects' ) ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_map( 'absint', $manifest['eps_ids'] ) ) . ')', $wpdb->prefix . 'redirects' ) ); // phpcs:ignore
}
if ( ! empty( $manifest['smartcrawl_ids'] ) && $rdi_has_table( 'smartcrawl_redirects' ) ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_map( 'absint', $manifest['smartcrawl_ids'] ) ) . ')', $wpdb->prefix . 'smartcrawl_redirects' ) ); // phpcs:ignore
}
$slim = get_option( 'ss_redirects', [] );
$slim = is_array( $slim ) ? array_diff_key( $slim, array_flip( $manifest['slim_ids'] ?? [] ) ) : [];
update_option( 'ss_redirects', $slim );

if ( ! empty( $manifest['redirection_404_ids'] ) && $rdi_has_table( 'redirection_404' ) ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_map( 'absint', $manifest['redirection_404_ids'] ) ) . ')', $wpdb->prefix . 'redirection_404' ) ); // phpcs:ignore
}

update_option( 'wpseo-premium-redirects-base', $yoast, false );
update_option( '301_redirects', $simple );
delete_option( RDI_SEED_OPTION );

if ( 'remove' === ( $args[0] ?? '' ) ) {
	WP_CLI::success( 'Seed data removed.' );
	return;
}

$manifest = [];

/*
 * 2. Content: pages the redirects point to, and a post whose slug changed (→ _wp_old_slug).
 */
$seed_pages = [
	'about-us' => 'About us',
	'services' => 'Services',
	'contact'  => 'Contact',
	'team'     => 'Team',
	'pricing'  => 'Pricing',
	'news'     => 'News',
	'careers'  => 'Careers',
	'faq'      => 'FAQ',
];
foreach ( $seed_pages as $slug => $seed_title ) {
	if ( get_page_by_path( $slug ) ) {
		continue;
	}
	$manifest['post_ids'][] = wp_insert_post(
		[
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $seed_title,
			'post_content' => '<!-- wp:paragraph --><p>Redirect Inspector demo page.</p><!-- /wp:paragraph -->',
		]
	);
}

$launch = get_page_by_path( 'launch-announcement', OBJECT, 'post' );
if ( ! $launch ) {
	$launch_id = wp_insert_post(
		[
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_name'   => 'old-launch',
			'post_title'  => 'Launch announcement',
		]
	);
	wp_update_post(
		[
			'ID'        => $launch_id,
			'post_name' => 'launch-announcement',
		]
	); // Core stores `old-launch` in _wp_old_slug.
	$manifest['post_ids'][] = $launch_id;
}

/*
 * 3. Redirection: chain, 2-node loop, self-loop, regex loop, disabled rule, broken target,
 *    duplicate, conflict, cross-plugin chain and loop legs.
 */
if ( $rdi_has_table( 'redirection_items' ) && $rdi_has_table( 'redirection_groups' ) ) {
	// Redirection's API when it is active; its own table format when it isn't.
	$api = class_exists( 'Red_Group' ) && class_exists( 'Red_Item' );
	if ( $api ) {
		$gid = Red_Group::create( 'Redirect Inspector seed', 1 )->get_id();
	} else {
		$wpdb->insert( $wpdb->prefix . 'redirection_groups', [ 'name' => 'Redirect Inspector seed', 'tracking' => 1, 'module_id' => 1, 'status' => 'enabled', 'position' => 0 ] ); // phpcs:ignore
		$gid = (int) $wpdb->insert_id;
	}

	$redirection = [
		[ '/old-about', '/about-old' ],
		[ '/about-old', '/about-2020' ],
		[ '/about-2020', '/about-us' ],
		[ '/loop-a', '/loop-b' ],
		[ '/loop-b', '/loop-a' ],
		[ '/self', '/self' ],
		[ '^/blog/(.*)$', '/news/$1', true ],
		[ '^/news/(.+)$', '/blog/$1', true ],
		[ '/old-pricing', '/pricing-2019' ],
		[ '/dup-source', '/contact' ],
		[ '/conflict-source', '/contact' ],
		[ '/promo', '/promo-2024' ],
		[ '/cross-a', '/cross-b' ],
		[ '/disabled-rule', '/contact', false, 'disabled' ],
	];

	foreach ( $redirection as $position => $row ) {
		if ( ! $api ) {
			$wpdb->insert( // phpcs:ignore
				$wpdb->prefix . 'redirection_items',
				[
					'url'         => $row[0],
					'match_url'   => ! empty( $row[2] ) ? 'regex' : strtolower( $row[0] ),
					'match_data'  => wp_json_encode( [ 'source' => [ 'flag_regex' => ! empty( $row[2] ) ] ] ),
					'regex'       => ! empty( $row[2] ) ? 1 : 0,
					'position'    => $position,
					'group_id'    => $gid,
					'status'      => 'disabled' === ( $row[3] ?? '' ) ? 'disabled' : 'enabled',
					'action_type' => 'url',
					'action_code' => 301,
					'action_data' => $row[1],
					'match_type'  => 'url',
					'title'       => '',
				]
			);
			continue;
		}
		$item = Red_Item::create(
			[
				'url'         => $row[0],
				'action_data' => [ 'url' => $row[1] ],
				'regex'       => ! empty( $row[2] ),
				'group_id'    => $gid,
				'match_type'  => 'url',
				'action_type' => 'url',
				'action_code' => 301,
			]
		);
		if ( is_wp_error( $item ) ) {
			WP_CLI::warning( 'Redirection: ' . $item->get_error_message() );
			continue;
		}
		if ( 'disabled' === ( $row[3] ?? '' ) ) {
			$item->disable();
		}
	}

	$manifest['redirection_group'] = $gid;
} else {
	WP_CLI::warning( 'Redirection has no tables (never activated); skipped.' );
}

/*
 * 3b. Old URLs visitors still request (Redirection's 404 log): the URLs pattern rules are for.
 *     Migrating a pattern rule into a plugin that only holds exact rules expands it over these.
 */
if ( $rdi_has_table( 'redirection_404' ) ) {
	foreach ( [ '/docs/v1/install', '/docs/v1/upgrade', '/products/shoes', '/products/socks', '/blog/hello-world', '/legacy/brochure', '/old-shop/gift-card' ] as $seed_url ) {
		$wpdb->insert( $wpdb->prefix . 'redirection_404', [ 'created' => current_time( 'mysql', true ), 'url' => $seed_url, 'http_code' => 404, 'request_method' => 'GET', 'agent' => 'Redirect Inspector seed' ] ); // phpcs:ignore
		$manifest['redirection_404_ids'][] = (int) $wpdb->insert_id;
	}
}

/*
 * 4. Yoast SEO Premium (its storage format): chain continuation, trailing-slash no-op,
 *    regex rule, cross-plugin loop leg.
 */
$yoast_seed = [
	[ 'promo-2024', 'pricing', 'plain' ],
	[ 'help/', 'help', 'plain' ],
	[ '^shop/(.*)', 'store/$1', 'regex' ],
	[ '^(?i)Team-(\\d+)$', 'team', 'regex' ],
	[ 'cross-c', 'cross-a', 'plain' ],
];
foreach ( $yoast_seed as [ $origin, $url, $format ] ) {
	$yoast[]                     = [
		'origin' => $origin,
		'url'    => $url,
		'type'   => 301,
		'format' => $format,
	];
	$manifest['yoast_origins'][] = $origin;
}
update_option( 'wpseo-premium-redirects-base', $yoast, false );

/*
 * 5. Rank Math: cross-plugin loop leg, "starts with" rule, rule hiding a published page.
 */
if ( class_exists( '\RankMath\Installer' ) || $rdi_has_table( 'rank_math_redirections' ) ) {
	$modules = get_option( 'rank_math_modules', [] );
	if ( ! in_array( 'redirections', (array) $modules, true ) ) {
		$modules[] = 'redirections';
		update_option( 'rank_math_modules', $modules );
	}
	if ( class_exists( '\RankMath\Installer' ) ) {
		\RankMath\Installer::create_tables( [ 'redirections' ] );
	}

	$rank_math = [
		[ 'cross-b', 'exact', home_url( '/cross-c' ) ],
		[ 'archive-2019', 'start', home_url( '/news' ) ],
		[ 'services', 'exact', home_url( '/pricing' ) ],
		[ '^docs/v1/(.*)', 'regex', home_url( '/docs/v2/$1' ) ],
	];
	foreach ( $rank_math as [ $pattern, $comparison, $seed_to ] ) {
		$wpdb->insert(
			"{$wpdb->prefix}rank_math_redirections",
			[
				'sources'     => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Rank Math's own storage format.

					[
					[
					'pattern'    => $pattern,
					'comparison' => $comparison,
					'ignore'     => 'case',
					],
					]
				), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Rank Math's own storage format.
				'url_to'      => $seed_to,
				'header_code' => 301,
				'status'      => 'active',
				'created'     => current_time( 'mysql' ),
				'updated'     => current_time( 'mysql' ),
			]
		);
		$manifest['rank_math_ids'][] = (int) $wpdb->insert_id;
	}
} else {
	WP_CLI::warning( 'Rank Math has no tables (never activated); skipped.' );
}

/*
 * 6. Safe Redirect Manager: conflicting duplicate, chain, wildcard that runs into the regex loop.
 */
$srm = [
	[ '/conflict-source', '/team', false ],
	[ '/careers-old', '/jobs', false ],
	[ '/jobs', '/careers', false ],
	[ '/legacy/*', '/news/*', false ],
	[ '/products/([a-z]+)/?$', '/shop/$1', true ],
];
if ( function_exists( 'srm_create_redirect' ) ) {
	foreach ( $srm as [ $seed_from, $seed_to, $regex ] ) {
		$seed_id = srm_create_redirect( $seed_from, $seed_to, 301, $regex );
		if ( is_wp_error( $seed_id ) ) {
			WP_CLI::warning( 'SRM: ' . $seed_id->get_error_message() );
			continue;
		}
		$manifest['srm_ids'][] = (int) $seed_id;
	}
} else {
	// SRM's own storage: a redirect_rule post with its meta.
	foreach ( $srm as $position => [ $seed_from, $seed_to, $regex ] ) {
		$manifest['srm_ids'][] = (int) wp_insert_post(
			[
				'post_type'   => 'redirect_rule',
				'post_status' => 'publish',
				'post_title'  => $seed_from,
				'menu_order'  => $position,
				'meta_input'  => [
					'_redirect_rule_from'        => $seed_from,
					'_redirect_rule_to'          => $seed_to,
					'_redirect_rule_status_code' => 301,
					'_redirect_rule_from_regex'  => $regex ? 1 : 0,
				],
			]
		);
	}
}

/*
 * 7. Simple 301 Redirects: duplicate (same target), rule made redundant by _wp_old_slug,
 *    wildcard rule, case-sensitive rule.
 */
$simple_seed = [
	'/dup-source'  => '/contact',
	'/old-launch'  => '/launch-announcement',
	'/old-shop/*'  => '/store/*',
	'/Pricing-Old' => '/pricing',
];
foreach ( $simple_seed as $seed_from => $seed_to ) {
	$simple[ $seed_from ]      = $seed_to;
	$manifest['simple_keys'][] = $seed_from;
}
update_option( '301_redirects', $simple );
update_option( '301_redirects_wildcard', 'true' );

/*
 * 8. 301 Redirects: a chain that continues in Slim SEO, and a third copy of /dup-source.
 */
global $wpdb;
if ( defined( 'EPS_REDIRECT_VERSION' ) || $rdi_has_table( 'redirects' ) ) {
	foreach ( [ [ 'eps-old', '/eps-mid' ], [ 'dup-source', '/contact' ] ] as [ $seed_from, $seed_to ] ) {
		$wpdb->insert( $wpdb->prefix . 'redirects', [ 'url_from' => $seed_from, 'url_to' => $seed_to, 'status' => '301', 'type' => 'url' ] ); // phpcs:ignore
		$manifest['eps_ids'][] = (int) $wpdb->insert_id;
	}
} else {
	WP_CLI::warning( '301 Redirects has no table (never activated); skipped.' );
}

/*
 * 9. Slim SEO: the second leg of the 301 Redirects chain.
 */
$seed_slim_id                 = 'rdiseed' . substr( md5( 'eps-mid' ), 0, 6 );
$slim[ $seed_slim_id ]        = [
	'type'             => 301,
	'condition'        => 'exact-match',
	'from'             => 'eps-mid',
	'to'               => 'contact',
	'note'             => 'Redirect Inspector seed',
	'enable'           => 1,
	'ignoreParameters' => 0,
];
$manifest['slim_ids'][]       = $seed_slim_id;
// A regex rule: the second leg of the Rank Math docs chain.
$seed_slim_id           = 'rdiseed' . substr( md5( 'docs-v2' ), 0, 6 );
$slim[ $seed_slim_id ]  = [
	'type'             => 301,
	'condition'        => 'regex',
	'from'             => '^docs/v2/(.*)',
	'to'               => 'docs/$1',
	'note'             => 'Redirect Inspector seed',
	'enable'           => 1,
	'ignoreParameters' => 0,
];
$manifest['slim_ids'][] = $seed_slim_id;
update_option( 'ss_redirects', $slim );

/*
 * 10. SEOPress and The SEO Framework redirect from a page itself; SmartCrawl links into both:
 *     /old-seopress → (SEOPress page) → /about-us is a chain,
 *     (SEO Framework page) → /sc-target → back to that page is a loop.
 */
$seed_page = static function ( string $slug, string $title ) use ( &$manifest ): int {
	$id                     = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title ] );
	$manifest['post_ids'][] = $id;
	return (int) $id;
};
$seopress_page = $seed_page( 'seopress-page', 'SEOPress demo page' );
update_post_meta( $seopress_page, '_seopress_redirections_enabled', 'yes' );
update_post_meta( $seopress_page, '_seopress_redirections_value', home_url( '/about-us' ) );
update_post_meta( $seopress_page, '_seopress_redirections_type', '301' );
update_post_meta( $seopress_page, '_seopress_redirections_logged_status', 'both' );

$tsf_page = $seed_page( 'tsf-page', 'SEO Framework demo page' );
update_post_meta( $tsf_page, 'redirect', home_url( '/sc-target' ) );

if ( defined( 'SMARTCRAWL_PLUGIN_DIR' ) || $rdi_has_table( 'smartcrawl_redirects' ) ) {
	// SmartCrawl only redirects with its URL Redirection module on.
	if ( class_exists( '\\SmartCrawl\\Modules\\Advanced\\Redirects\\Controller' ) ) {
		$sc = \SmartCrawl\Modules\Advanced\Redirects\Controller::get();
		if ( ! $sc->parent->should_run() ) {
			$sc->parent->update_option( 'active', true );
		}
		$sc->parent->update_option( $sc->module_name, array_merge( (array) $sc->options, [ 'active' => true ] ) );
	}

	// The last one is a regex that redirects into itself: a loop.
	foreach ( [ [ '/old-seopress', home_url( '/seopress-page' ), false ], [ '/sc-target', home_url( '/tsf-page' ), false ], [ '^/kb/(.+)$', '/kb/$1', true ] ] as [ $seed_from, $seed_to, $regex ] ) {
		$wpdb->insert( $wpdb->prefix . 'smartcrawl_redirects', [ 'title' => 'Redirect Inspector seed', 'source' => $seed_from, 'path' => $regex ? 'regex' : $seed_from, 'destination' => wp_json_encode( $seed_to ), 'type' => 301, 'options' => $regex ? 'regex' : '', 'rules' => '[]' ] ); // phpcs:ignore
		$manifest['smartcrawl_ids'][] = (int) $wpdb->insert_id;
	}
} else {
	WP_CLI::warning( 'SmartCrawl has no table (never activated); skipped.' );
}

update_option( RDI_SEED_OPTION, $manifest, false );

WP_CLI::success( 'Seeded redirects for all ten plugins (inactive ones in their own storage format, so they show up as leftovers).' );
WP_CLI::log( 'Expected counts: see bin/seed-expected.json. Verify with: wp redirect-inspector scan' );
