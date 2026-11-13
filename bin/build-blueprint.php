<?php
/**
 * Builds .wordpress-org/blueprints/blueprint.json (the "Live preview" on WordPress.org) from
 * bin/seed-dummy-data.php, so the Playground demo and the local seed never drift apart.
 *
 * Usage: php bin/build-blueprint.php
 *
 * The Playground installs all ten plugins, runs the seed with a minimal WP_CLI stand-in, and runs a
 * first scan. Yoast SEO Premium isn't on WordPress.org: its rules are seeded in its storage format and
 * show up as leftovers.
 *
 * @package Hoptrail
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- A build script, outside WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$seed = (string) file_get_contents( $root . '/bin/seed-dummy-data.php' );
$seed = preg_replace( '/^<\?php\s*/', '', $seed );

// The seed runs inside WP-CLI locally; in the Playground a stand-in collects its messages.
$prelude = <<<'PHP'
<?php
require_once '/wordpress/wp-load.php';
wp_set_current_user( 1 );

if ( ! class_exists( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
	class WP_CLI {
		public static function log( $message ) {}
		public static function warning( $message ) { error_log( 'Seed: ' . $message ); }
		public static function success( $message ) {}
		public static function error( $message ) { throw new RuntimeException( $message ); }
	}
}

// Redirection creates its tables on first use of its admin screen; create them now.
foreach ( [ 'Redirection\\Database\\Database', 'Red_Database' ] as $rdi_db ) {
	if ( class_exists( $rdi_db ) && method_exists( $rdi_db, 'get_latest_database' ) ) {
		$rdi_db::get_latest_database()->install();
		break;
	}
}
if ( function_exists( 'red_get_options' ) && function_exists( 'red_set_options' ) ) {
	red_set_options( [ 'database' => REDIRECTION_DB_VERSION ] );
}

$args = [];
PHP;

$scan = <<<'PHP'

// A first scan, so the dashboard has something to show.
\Hoptrail\Includes\Scanner::run( 'manual' );
PHP;

$plugins = [
	'redirection',
	'seo-by-rank-math',
	'safe-redirect-manager',
	'simple-301-redirects',
	'eps-301-redirects',
	'slim-seo',
	'smartcrawl-seo',
	'wp-seopress',
	'autodescription',
	'hoptrail',
];

$steps = [
	[
		'step'   => 'login',
		'username' => 'admin',
		'password' => 'password',
	],
	[
		// The seed only runs on local or development sites.
		'step'   => 'defineWpConfigConsts',
		'consts' => [ 'WP_ENVIRONMENT_TYPE' => 'development' ],
	],
	[
		'step' => 'setSiteOptions',
		'options' => [ 'permalink_structure' => '/%postname%/' ],
	],
];

foreach ( $plugins as $slug ) {
	$steps[] = [
		'step'       => 'installPlugin',
		'pluginData' => [
			'resource' => 'wordpress.org/plugins',
			'slug'     => $slug,
		],
		'options'    => [ 'activate' => true ],
	];
}

$steps[] = [
	'step' => 'runPHP',
	'code' => $prelude . "\n" . $seed . $scan . "\n",
];

$blueprint = [
	'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
	'landingPage'       => '/wp-admin/tools.php?page=hoptrail',
	'preferredVersions' => [
		'php' => '8.2',
		'wp'  => 'latest',
	],
	'features'          => [ 'networking' => true ],
	'steps'             => $steps,
];

$file = $root . '/.wordpress-org/blueprints/blueprint.json';
file_put_contents( $file, json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

echo 'Wrote ' . $file . ' (' . count( $plugins ) . " plugins, seed included)\n";
