<?php
/**
 * Dummy redirects for every supported plugin. Development only; not part of the release.
 *
 * Usage: wp eval-file bin/seed-dummy-data.php            (seed / re-seed)
 *        wp eval-file bin/seed-dummy-data.php remove     (remove seed data)
 *
 * Idempotent: everything it creates is recorded in the `hoptrail_seed` option and
 * removed again on the next run (S12: it never touches rules it didn't create). One exception:
 * Yoast rules on one of the seed's own demo paths are removed too. Those are copies of seed rules
 * that Hoptrail's migration wrote into Yoast, and they would otherwise stay behind for good.
 *
 * @package Hoptrail
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	WP_CLI::error( 'The seed script only runs on local or development environments.' );
}

const RDI_SEED_OPTION = 'hoptrail_seed';

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
 * 0. The seed data, per plugin. The sections below write it in each plugin's storage format.
 */

// Redirection: [ from, to, regex?, status? ].
$seed_redirection = [
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
	// Three-hop chain to the FAQ page, a three-node loop, and a target that doesn't exist.
	[ '/old-faq', '/faq-2021' ],
	[ '/faq-2021', '/faq-2022' ],
	[ '/faq-2022', '/faq' ],
	[ '/tri-a', '/tri-b' ],
	[ '/tri-b', '/tri-c' ],
	[ '/tri-c', '/tri-a' ],
	[ '/old-brochure', '/brochure-2018' ],
];

// Yoast SEO Premium: [ origin, url, format ]. Origins have no leading slash.
$seed_yoast = [
	[ 'promo-2024', 'pricing', 'plain' ],
	[ 'help/', 'help', 'plain' ],
	[ '^shop/(.*)', 'store/$1', 'regex' ],
	[ '^(?i)Team-(\\d+)$', 'team', 'regex' ],
	[ 'cross-c', 'cross-a', 'plain' ],
	// Chain inside Yoast, a second trailing-slash no-op, a duplicate of Rank Math, a broken target.
	[ 'old-vacatures', 'vacatures-2022', 'plain' ],
	[ 'vacatures-2022', 'careers', 'plain' ],
	[ 'downloads/', 'downloads', 'plain' ],
	[ 'yoast-dup', 'contact', 'plain' ],
	[ 'contact-us', 'contact-form-old', 'plain' ],
];

// Rank Math: [ pattern, comparison, absolute target ] (filled in once home_url() is final).
$seed_rank_math = [
	[ 'cross-b', 'exact', '/cross-c' ],
	[ 'archive-2019', 'start', '/news' ],
	[ 'services', 'exact', '/pricing' ],
	[ '^docs/v1/(.*)', 'regex', '/docs/v2/$1' ],
	// Duplicate of Yoast, a rule hiding the Partners page, one side of a conflict with SRM.
	[ 'yoast-dup', 'exact', '/contact' ],
	[ 'partners', 'exact', '/about-us' ],
	[ 'rm-conflict', 'exact', '/contact' ],
];

// Safe Redirect Manager: [ from, to, regex? ].
$seed_srm = [
	[ '/conflict-source', '/team', false ],
	[ '/careers-old', '/jobs', false ],
	[ '/jobs', '/careers', false ],
	[ '/legacy/*', '/news/*', false ],
	[ '/products/([a-z]+)/?$', '/shop/$1', true ],
	// The other side of the Rank Math conflict, and the start of a chain over three plugins.
	[ '/rm-conflict', '/faq', false ],
	[ '/srm-old', '/srm-mid', false ],
];

// Simple 301 Redirects: from => to.
$seed_simple = [
	'/dup-source'  => '/contact',
	'/old-launch'  => '/launch-announcement',
	'/old-shop/*'  => '/store/*',
	'/Pricing-Old' => '/pricing',
	'/s301-loop'   => '/eps-loop', // Loop with 301 Redirects.
];

// 301 Redirects: [ from, to ]. Sources have no leading slash.
$seed_eps = [
	[ 'eps-old', '/eps-mid' ],
	[ 'dup-source', '/contact' ],
	[ 'eps-loop', '/s301-loop' ],
];

// Slim SEO: [ from, to, condition ].
$seed_slim = [
	[ 'eps-mid', 'contact', 'exact-match' ],
	[ '^docs/v2/(.*)', 'docs/$1', 'regex' ],
	[ 'srm-mid', 'srm-end', 'exact-match' ], // Second leg of the SRM chain.
];

// SmartCrawl: [ from, target path, regex? ]. The regex redirects into itself: a loop.
$seed_smartcrawl = [
	[ '/old-seopress', '/seopress-page', false ],
	[ '/sc-target', '/tsf-page', false ],
	[ '^/kb/(.+)$', '/kb/$1', true ],
	[ '/srm-end', '/about-us', false ], // Last leg of the SRM chain.
];

/**
 * A rule source reduced to the part every plugin keeps: no anchors, outer slashes, escapes or
 * wildcard syntax. A Yoast copy of a seed rule has the same stem as the rule it was copied from.
 *
 * @param string $source Source URL or pattern.
 *
 * @return string
 */
$rdi_stem = static fn( string $source ): string => stripslashes( (string) preg_replace( '/(?<!\.)\*/', '(.*)', trim( $source, '^$/' ) ) );

$seed_stems = [];
foreach ( array_merge( array_column( $seed_redirection, 0 ), array_column( $seed_yoast, 0 ), array_column( $seed_rank_math, 0 ), array_column( $seed_srm, 0 ), array_keys( $seed_simple ), array_column( $seed_eps, 0 ), array_column( $seed_slim, 0 ), array_column( $seed_smartcrawl, 0 ) ) as $seed_source ) {
	$stem                         = $rdi_stem( $seed_source );
	$seed_stems[ $stem ]          = true;
	$seed_stems[ $stem . '(.*)' ] = true; // "Starts with" rules are copied as a prefix pattern.
}

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
$yoast = array_values(
	array_filter(
		$yoast,
		static fn( $item ) => is_array( $item )
			&& ! in_array( $item['origin'] ?? '', $manifest['yoast_origins'] ?? [], true )
			&& ! isset( $seed_stems[ $rdi_stem( (string) ( $item['origin'] ?? '' ) ) ] )
	)
);

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
	'partners' => 'Partners',
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
			'post_content' => '<!-- wp:paragraph --><p>Hoptrail demo page.</p><!-- /wp:paragraph -->',
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
 * 3. Redirection: chains, 2- and 3-node loops, self-loop, regex loop, disabled rule, broken
 *    targets, duplicate, conflict, cross-plugin chain and loop legs.
 */
if ( $rdi_has_table( 'redirection_items' ) && $rdi_has_table( 'redirection_groups' ) ) {
	// Redirection's API when it is active; its own table format when it isn't.
	$api = class_exists( 'Red_Group' ) && class_exists( 'Red_Item' );
	if ( $api ) {
		$gid = Red_Group::create( 'Hoptrail seed', 1 )->get_id();
	} else {
		$wpdb->insert( $wpdb->prefix . 'redirection_groups', [ 'name' => 'Hoptrail seed', 'tracking' => 1, 'module_id' => 1, 'status' => 'enabled', 'position' => 0 ] ); // phpcs:ignore
		$gid = (int) $wpdb->insert_id;
	}

	foreach ( $seed_redirection as $position => $row ) {
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
	WP_CLI::warning( 'Redirection has no tables (never activated, or its data was purged): activate it once and seed again; skipped.' );
}

/*
 * 3b. Old URLs visitors still request (Redirection's 404 log): the URLs pattern rules are for.
 *     Migrating a pattern rule into a plugin that only holds exact rules expands it over these.
 */
if ( $rdi_has_table( 'redirection_404' ) ) {
	foreach ( [ '/docs/v1/install', '/docs/v1/upgrade', '/products/shoes', '/products/socks', '/blog/hello-world', '/legacy/brochure', '/old-shop/gift-card' ] as $seed_url ) {
		$wpdb->insert( $wpdb->prefix . 'redirection_404', [ 'created' => current_time( 'mysql', true ), 'url' => $seed_url, 'http_code' => 404, 'request_method' => 'GET', 'agent' => 'Hoptrail seed' ] ); // phpcs:ignore
		$manifest['redirection_404_ids'][] = (int) $wpdb->insert_id;
	}
}

/*
 * 4. Yoast SEO Premium (its storage format): chains, trailing-slash no-ops, regex rules,
 *    duplicate, broken target, cross-plugin loop leg. Free Yoast SEO doesn't read this option,
 *    so without Premium these rules are leftovers.
 */
foreach ( $seed_yoast as [ $origin, $url, $format ] ) {
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
 * 5. Rank Math: cross-plugin loop leg, "starts with" rule, rules hiding published pages,
 *    duplicate, conflict.
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

	foreach ( $seed_rank_math as [ $pattern, $comparison, $seed_to ] ) {
		$seed_to = home_url( $seed_to );
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
 * 6. Safe Redirect Manager: conflicts, chains, wildcard that runs into the regex loop.
 */
if ( function_exists( 'srm_create_redirect' ) ) {
	foreach ( $seed_srm as [ $seed_from, $seed_to, $regex ] ) {
		$seed_id = srm_create_redirect( $seed_from, $seed_to, 301, $regex );
		if ( is_wp_error( $seed_id ) ) {
			WP_CLI::warning( 'SRM: ' . $seed_id->get_error_message() );
			continue;
		}
		$manifest['srm_ids'][] = (int) $seed_id;
	}
} else {
	// SRM's own storage: a redirect_rule post with its meta.
	foreach ( $seed_srm as $position => [ $seed_from, $seed_to, $regex ] ) {
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
 *    wildcard rule, case-sensitive rule, cross-plugin loop leg.
 */
foreach ( $seed_simple as $seed_from => $seed_to ) {
	$simple[ $seed_from ]      = $seed_to;
	$manifest['simple_keys'][] = $seed_from;
}
update_option( '301_redirects', $simple );
update_option( '301_redirects_wildcard', 'true' );

/*
 * 8. 301 Redirects: a chain that continues in Slim SEO, a third copy of /dup-source, and the
 *    other leg of the Simple 301 loop.
 */
global $wpdb;
if ( defined( 'EPS_REDIRECT_VERSION' ) || $rdi_has_table( 'redirects' ) ) {
	foreach ( $seed_eps as [ $seed_from, $seed_to ] ) {
		$wpdb->insert( $wpdb->prefix . 'redirects', [ 'url_from' => $seed_from, 'url_to' => $seed_to, 'status' => '301', 'type' => 'url' ] ); // phpcs:ignore
		$manifest['eps_ids'][] = (int) $wpdb->insert_id;
	}
} else {
	WP_CLI::warning( '301 Redirects has no table (never activated); skipped.' );
}

/*
 * 9. Slim SEO: the second leg of the 301 Redirects chain, of the Rank Math docs chain (regex)
 *    and of the SRM chain.
 */
foreach ( $seed_slim as [ $seed_from, $seed_to, $condition ] ) {
	$seed_slim_id           = 'rdiseed' . substr( md5( $seed_from ), 0, 6 );
	$slim[ $seed_slim_id ] = [
		'type'             => 301,
		'condition'        => $condition,
		'from'             => $seed_from,
		'to'               => $seed_to,
		'note'             => 'Hoptrail seed',
		'enable'           => 1,
		'ignoreParameters' => 0,
	];
	$manifest['slim_ids'][] = $seed_slim_id;
}
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

// A SEOPress page that redirects to a URL with no content: a broken target.
$seopress_gone = $seed_page( 'seopress-gone', 'SEOPress page that moved' );
update_post_meta( $seopress_gone, '_seopress_redirections_enabled', 'yes' );
update_post_meta( $seopress_gone, '_seopress_redirections_value', home_url( '/gone-for-good' ) );
update_post_meta( $seopress_gone, '_seopress_redirections_type', '301' );
update_post_meta( $seopress_gone, '_seopress_redirections_logged_status', 'both' );

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

	foreach ( $seed_smartcrawl as [ $seed_from, $seed_to, $regex ] ) {
		$seed_to = $regex ? $seed_to : home_url( $seed_to );
		$wpdb->insert( $wpdb->prefix . 'smartcrawl_redirects', [ 'title' => 'Hoptrail seed', 'source' => $seed_from, 'path' => $regex ? 'regex' : $seed_from, 'destination' => wp_json_encode( $seed_to ), 'type' => 301, 'options' => $regex ? 'regex' : '', 'rules' => '[]' ] ); // phpcs:ignore
		$manifest['smartcrawl_ids'][] = (int) $wpdb->insert_id;
	}
} else {
	WP_CLI::warning( 'SmartCrawl has no table (never activated); skipped.' );
}

update_option( RDI_SEED_OPTION, $manifest, false );

WP_CLI::success( 'Seeded redirects for all ten plugins (inactive ones in their own storage format, so they show up as leftovers; Yoast needs Yoast SEO Premium).' );
WP_CLI::log( 'Expected counts: see bin/seed-expected.json. Verify with: wp hoptrail scan' );
