<?php
/**
 * Standalone tests for the analysis engine: run with `php tests/test-analysis.php`.
 * WordPress is not loaded; the few functions the engine uses are stubbed below.
 *
 * @package Acato\RedirectInspector
 */

// phpcs:ignoreFile -- Test runner with WordPress stubs.

define( 'ABSPATH', __DIR__ . '/' );
define( 'REDIRECT_INSPECTOR_PATH', dirname( __DIR__ ) . '/' );
define( 'REDIRECT_INSPECTOR_VERSION', 'test' );

// --- WordPress stubs ---------------------------------------------------------------------------
function __( $text ) { return $text; }
function _n( $single, $plural, $number ) { return 1 === $number ? $single : $plural; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function site_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_timezone() { return new DateTimeZone( 'Europe/Amsterdam' ); }
function apply_filters( $hook, $value ) { return $value; }
function url_to_postid( $url ) { return 0; }
function get_terms( $args ) { return []; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function absint( $value ) { return abs( (int) $value ); }
function is_serialized( $data ) { return is_string( $data ) && preg_match( '/^[aOs]:\d/', $data ); }
function get_option( $name, $default = false ) { return $default; }
// No posts with an old slug in these tests.
$GLOBALS['wpdb'] = new class() {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $prefix   = 'wp_';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_results( $query ) { return []; }
	public function get_var( $query ) { return null; }
};

spl_autoload_register(
	static function ( $fqcn ) {
		$prefix = 'Acato\\RedirectInspector\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = strtolower( str_replace( [ $prefix, '\\', '_' ], [ '', '/', '-' ], $fqcn ) );
		foreach ( [ 'class', 'trait', 'interface' ] as $kind ) {
			$path = REDIRECT_INSPECTOR_PATH . dirname( $relative ) . '/' . $kind . '-' . basename( $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

use Acato\RedirectInspector\Includes\Analysis\Analyzer;
use Acato\RedirectInspector\Includes\Analysis\Matcher;
use Acato\RedirectInspector\Includes\Rule;
use Acato\RedirectInspector\Includes\Scheduler;
use Acato\RedirectInspector\Includes\Url_Normalizer;

// --- Tiny assertion helper ---------------------------------------------------------------------
$failures = 0;
function check( string $name, $actual, $expected ): void {
	global $failures;
	$ok = $actual === $expected;
	$failures += $ok ? 0 : 1;
	printf( "%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

function analyse( array $rules ): array {
	$normalizer = new Url_Normalizer( 'https://example.test/', 'https://example.test/' );
	$matcher    = new Matcher( $normalizer, microtime( true ) + 10 );
	$matcher->add( $rules );
	$issues = ( new Analyzer( $matcher, $normalizer, microtime( true ) + 10 ) )->run( $rules );
	$counts = array_count_values( array_column( $issues, 'type' ) );
	return [ $issues, $counts ];
}

// --- Cases ---------------------------------------------------------------------------------------
[ , $c ] = analyse( [ new Rule( 'redirection', '1', '/a', '/b' ), new Rule( 'redirection', '2', '/b', '/a' ) ] );
check( 'two-rule loop is one loop', $c['loop'] ?? 0, 1 );

[ $i, $c ] = analyse( [ new Rule( 'redirection', '1', '/x', '/y' ), new Rule( 'rank-math', '2', 'y', '/z', 301, Rule::MATCH_EXACT, false ), new Rule( 'yoast', '3', '/z', 'x', 301, Rule::MATCH_EXACT, false ) ] );
check( 'loop across three plugins', $c['loop'] ?? 0, 1 );
check( 'loop names all three plugins', count( explode( ',', $i[0]['plugins'] ) ), 3 );

[ $i, $c ] = analyse( [ new Rule( 'redirection', '1', '/old', '/mid' ), new Rule( 'redirection', '2', '/mid', '/new' ) ] );
check( 'chain detected', $c['chain'] ?? 0, 1 );
check( 'chain fix retargets to final URL', $i[0]['fix']['to'] ?? null, '/new' );

[ , $c ] = analyse( [ new Rule( 'redirection', '1', '/self', '/self' ) ] );
check( 'self-redirect is a loop', $c['loop'] ?? 0, 1 );

[ $i, $c ] = analyse( [ new Rule( 'yoast', '1', '/help/', 'help', 301, Rule::MATCH_EXACT, false ) ] );
check( 'trailing-slash only is a no-op', $c['noop'] ?? 0, 1 );
check( 'no-op fix switches the rule off', $i[0]['fix']['action'] ?? null, 'disable' );

[ $i, $c ] = analyse( [ new Rule( 'redirection', '1', '/dup', '/t' ), new Rule( 'simple-301', '2', '/dup', '/t' ) ] );
check( 'duplicate detected', $c['duplicate'] ?? 0, 1 );
check( 'duplicate fix switches off the loser only', $i[0]['fix']['rules'][0]['source'] ?? null, 'simple-301' );

[ $i, $c ] = analyse( [ new Rule( 'redirection', '1', '/c', '/one' ), new Rule( 'safe-redirect-manager', '2', '/c', '/two' ) ] );
check( 'conflict detected', $c['conflict'] ?? 0, 1 );
check( 'conflict has no automatic fix', $i[0]['fix'] ?? [], [] );

[ , $c ] = analyse( [ new Rule( 'redirection', '1', '^/blog/(.*)$', '/news/$1', 301, Rule::MATCH_REGEX ), new Rule( 'redirection', '2', '^/news/(.+)$', '/blog/$1', 301, Rule::MATCH_REGEX ) ] );
check( 'regex rules looping into each other', $c['loop'] ?? 0, 1 );

$normalizer = new Url_Normalizer( 'https://example.test/', 'https://example.test/' );
$matcher    = new Matcher( $normalizer, microtime( true ) + 10 );
check( 'regex keeps the unmatched rest (like preg_replace)', $matcher->apply( new Rule( 'redirection', '1', '/old-cat/', '/new-cat/', 301, Rule::MATCH_REGEX ), '/old-cat/post' ), '/new-cat/post' );
check( 'invalid regex never matches', $matcher->apply( new Rule( 'redirection', '1', '(unclosed', '/x', 301, Rule::MATCH_REGEX ), '/unclosed' ), null );

check( 'external target key', Url_Normalizer::is_external_key( $normalizer->key( 'https://other.example/x' ) ), true );
check( 'own absolute URL is internal', $normalizer->key( 'https://example.test/About/' ), '/About' );

// Schedule: Monday 10:15 Amsterdam, scans on Mondays and Wednesdays at 03:00 and 14:00 → Monday 14:00.
$after = ( new DateTimeImmutable( '2026-11-16 10:15', wp_timezone() ) )->getTimestamp();
$next  = Scheduler::next_run( [ 'enabled' => true, 'days' => [ 1, 3 ], 'hours' => [ 3, 14 ] ], $after );
check( 'next scheduled slot', ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() )->format( 'D H:i' ), 'Mon 14:00' );
check( 'disabled schedule has no slot', Scheduler::next_run( [ 'enabled' => false, 'days' => [ 1 ], 'hours' => [ 3 ] ], $after ), null );

echo $failures ? "\n{$failures} failed\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
