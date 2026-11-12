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

/*
 * 1. Remove the previous seed.
 */
global $wpdb;

if ( ! empty( $manifest['redirection_group'] ) && class_exists( 'Red_Group' ) ) {
	$group = Red_Group::get( (int) $manifest['redirection_group'] );
	if ( $group ) {
		$group->delete();
	}
}

if ( ! empty( $manifest['rank_math_ids'] ) ) {
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
if ( class_exists( 'Red_Group' ) && class_exists( 'Red_Item' ) ) {
	$group = Red_Group::create( 'Redirect Inspector seed', 1 );
	$gid   = $group->get_id();

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

	foreach ( $redirection as $row ) {
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
	WP_CLI::warning( 'Redirection is not active; skipped.' );
}

/*
 * 4. Yoast SEO Premium (its storage format): chain continuation, trailing-slash no-op,
 *    regex rule, cross-plugin loop leg.
 */
$yoast_seed = [
	[ 'promo-2024', 'pricing', 'plain' ],
	[ 'help/', 'help', 'plain' ],
	[ '^shop/(.*)', 'store/$1', 'regex' ],
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
if ( class_exists( '\RankMath\Installer' ) ) {
	$modules = get_option( 'rank_math_modules', [] );
	if ( ! in_array( 'redirections', (array) $modules, true ) ) {
		$modules[] = 'redirections';
		update_option( 'rank_math_modules', $modules );
	}
	\RankMath\Installer::create_tables( [ 'redirections' ] );

	$rank_math = [
		[ 'cross-b', 'exact', home_url( '/cross-c' ) ],
		[ 'archive-2019', 'start', home_url( '/news' ) ],
		[ 'services', 'exact', home_url( '/pricing' ) ],
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
	WP_CLI::warning( 'Rank Math is not active; skipped.' );
}

/*
 * 6. Safe Redirect Manager: conflicting duplicate, chain, wildcard that runs into the regex loop.
 */
if ( function_exists( 'srm_create_redirect' ) ) {
	$srm = [
		[ '/conflict-source', '/team', false ],
		[ '/careers-old', '/jobs', false ],
		[ '/jobs', '/careers', false ],
		[ '/legacy/*', '/news/*', false ],
	];
	foreach ( $srm as [ $seed_from, $seed_to, $regex ] ) {
		$seed_id = srm_create_redirect( $seed_from, $seed_to, 301, $regex );
		if ( is_wp_error( $seed_id ) ) {
			WP_CLI::warning( 'SRM: ' . $seed_id->get_error_message() );
			continue;
		}
		$manifest['srm_ids'][] = (int) $seed_id;
	}
} else {
	WP_CLI::warning( 'Safe Redirect Manager is not active; skipped.' );
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

update_option( RDI_SEED_OPTION, $manifest, false );

WP_CLI::success( 'Seeded redirects for Redirection, Yoast (storage format), Rank Math, Safe Redirect Manager and Simple 301 Redirects.' );
WP_CLI::log( 'Expected counts: see bin/seed-expected.json. Verify with: wp redirect-inspector scan' );
