<?php
/**
 * Standalone tests for the analysis engine: run with `php tests/test-analysis.php`.
 * WordPress is not loaded; the few functions the engine uses are stubbed below.
 *
 * @package Hoptrail
 */

// phpcs:ignoreFile -- Test runner with WordPress stubs.

// Command line only: never run from a web request (e.g. on a git-clone install).
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOPTRAIL_PATH', dirname( __DIR__ ) . '/' );
define( 'HOPTRAIL_VERSION', 'test' );

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
		$prefix = 'Hoptrail\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = strtolower( str_replace( [ $prefix, '\\', '_' ], [ '', '/', '-' ], $fqcn ) );
		foreach ( [ 'class', 'trait', 'interface' ] as $kind ) {
			$path = HOPTRAIL_PATH . dirname( $relative ) . '/' . $kind . '-' . basename( $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

use Hoptrail\Includes\Analysis\Analyzer;
use Hoptrail\Includes\Analysis\Expander;
use Hoptrail\Includes\Log_Repository;
use Hoptrail\Includes\Analysis\Matcher;
use Hoptrail\Includes\Rule;
use Hoptrail\Includes\Scheduler;
use Hoptrail\Includes\Url_Normalizer;

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

// --- Regex rules ---------------------------------------------------------------------------------
$m   = new Matcher( $normalizer, microtime( true ) + 10 );
$re  = static fn( string $from, string $to, bool $slash = true, bool $case = false, string $src = 'redirection' ) => new Rule( $src, md5( $from . $to ), $from, $to, 301, Rule::MATCH_REGEX, $slash, $case );

check( 'regex: $1 back-reference', $m->apply( $re( '^/old/(.*)$', '/new/$1' ), '/old/page' ), '/new/page' );
check( 'regex: \1 back-reference', $m->apply( $re( '^/old/(.*)$', '/new/\\1' ), '/old/page' ), '/new/page' );
check( 'regex: ${1} back-reference', $m->apply( $re( '^/old/(.*)$', '/new/${1}x' ), '/old/page' ), '/new/pagex' );
check( 'regex: two groups swapped', $m->apply( $re( '^/(\\d+)/(\\w+)$', '/$2/$1' ), '/2024/news' ), '/news/2024' );
check( 'regex: no match leaves nothing', $m->apply( $re( '^/old/(.*)$', '/new/$1' ), '/other/page' ), null );
check( 'regex: case-insensitive by default', $m->apply( $re( '^/old/(.*)$', '/new/$1' ), '/OLD/Page' ), '/new/Page' );
check( 'regex: case-sensitive rule', $m->apply( $re( '^/old/(.*)$', '/new/$1', true, true ), '/OLD/Page' ), null );
check( 'regex: slash-less pattern (Yoast, Rank Math, Slim SEO)', $m->apply( $re( '^blog/(.*)', 'news/$1', false ), '/blog/post' ), 'news/post' );
check( 'regex: @ in the pattern is escaped', $m->apply( $re( '^/a@b/(.*)$', '/c/$1' ), '/a@b/x' ), '/c/x' );
check( 'regex: # and / in the pattern', $m->apply( $re( '^/x/#?(.*)$', '/y/$1' ), '/x/z' ), '/y/z' );

$t0 = microtime( true );
$evil = $m->apply( $re( '^/(a+)+$', '/x' ), '/' . str_repeat( 'a', 32 ) . '!' );
check( 'regex: catastrophic backtracking returns quickly', [ $evil, microtime( true ) - $t0 < 2 ], [ null, true ] );

[ $i, $c ] = analyse( [ $re( '^/(unclosed', '/x' ) ] );
check( 'regex: invalid pattern reported as broken', [ $c['broken'] ?? 0, str_contains( $i[0]['suggestion'] ?? '', 'invalid' ) ], [ 1, true ] );

check( 'regex sample: digits', $m->sample_key( $re( '^/archive/(\\d+)/(.*)$', '/a/$1' ) ), '/archive/1/sample' );
check( 'regex sample: keeps case for case-sensitive rules', $m->sample_key( $re( '^/Blog/(.*)$', '/b/$1', true, true ) ), '/Blog/sample' );
check( 'regex sample: unanchored trailing slash', null !== $m->sample_key( $re( '/old-cat/', '/new-cat/' ) ), true );

[ $i, $c ] = analyse( [ $re( '^/promo/(.*)$', '/offer/$1' ), new Rule( 'safe-redirect-manager', 'p', '/offer/', '/shop', 301, Rule::MATCH_PREFIX ) ] );
check( 'regex into a prefix rule is a chain', [ $c['chain'] ?? 0, count( $i[0]['hops'] ?? [] ) ], [ 1, 2 ] );

[ , $c ] = analyse( [ $re( '^/a/(.*)$', '/b/$1' ), $re( '^b/(.*)$', 'c/$1', false, false, 'yoast' ), $re( '^/c/(.*)$', '/a/$1', true, false, 'slim-seo' ) ] );
check( 'regex loop across three plugins', $c['loop'] ?? 0, 1 );

[ , $c ] = analyse( [ $re( '^/same/(.*)$', '/same/$1' ) ] );
check( 'regex that maps onto itself is a loop', $c['loop'] ?? 0, 1 );

// Migration converts patterns between slash styles and match types.
check( 'to regex: prefix', ( new Rule( 'x', '1', '/legacy/', '/n', 301, Rule::MATCH_PREFIX ) )->regex( true ), '^/legacy/(.*)' );
check( 'to regex: suffix', ( new Rule( 'x', '1', '.html', '/n', 301, Rule::MATCH_SUFFIX ) )->regex( true ), '\\.html$' );
check( 'to regex: slash-less → slash', ( new Rule( 'yoast', '1', '^blog/(.*)', 'n', 301, Rule::MATCH_REGEX, false ) )->regex( true ), '^/blog/(.*)' );
check( 'to regex: slash → slash-less', ( new Rule( 'redirection', '1', '^/blog/(.*)', '/n', 301, Rule::MATCH_REGEX, true ) )->regex( false ), '^blog/(.*)' );
check( 'to regex: exact path is quoted', ( new Rule( 'x', '1', '/a.b?c', '/n' ) )->regex( true ), '^/a\\.b\\?c$' );

// --- Security: what Apply fix and migration may write ---------------------------------------------
check( 'safe target: path', Url_Normalizer::is_safe_target( '/new-page/?a=1#top' ), true );
check( 'safe target: https URL', Url_Normalizer::is_safe_target( 'https://example.test/blog/new' ), true );
check( 'unsafe target: markup', Url_Normalizer::is_safe_target( '/x"><img src=x onerror=alert(1)>' ), false );
check( 'unsafe target: javascript:', Url_Normalizer::is_safe_target( 'javascript:alert(1)' ), false );
check( 'unsafe target: protocol-relative', Url_Normalizer::is_safe_target( '//evil.example/x' ), false );
check( 'unsafe target: newline', Url_Normalizer::is_safe_target( "/a\nLocation: /b" ), false );
check( 'unsafe target: relative without slash', Url_Normalizer::is_safe_target( 'contact' ), false );

[ $i ] = analyse( [ new Rule( 'redirection', '1', '/old', '/mid' ), new Rule( 'redirection', '2', '/mid', '/x%22%3E%3Cimg%20src=x%3E' ) ] );
check( 'chain fix writes the raw target, not the decoded key', $i[0]['fix']['to'] ?? null, '/x%22%3E%3Cimg%20src=x%3E' );
[ $i ] = analyse( [ new Rule( 'redirection', '1', '/old', '/mid' ), new Rule( 'redirection', '2', '/mid', 'https://example.test/sub/new/' ) ] );
check( 'chain fix keeps the absolute target as stored', $i[0]['fix']['to'] ?? null, 'https://example.test/sub/new/' );
[ $i ] = analyse( [ new Rule( 'redirection', '1', '/old', '/mid' ), new Rule( 'redirection', '2', '/mid', '/a"b' ) ] );
check( 'chain to an unsafe target gets no automatic fix', $i[0]['fix'] ?? [], [] );

// Schedule: Monday 10:15 Amsterdam, scans on Mondays and Wednesdays at 03:00 and 14:00 → Monday 14:00.
$after = ( new DateTimeImmutable( '2026-11-16 10:15', wp_timezone() ) )->getTimestamp();
$next  = Scheduler::next_run( [ 'enabled' => true, 'days' => [ 1, 3 ], 'hours' => [ 3, 14 ] ], $after );
check( 'next scheduled slot', ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() )->format( 'D H:i' ), 'Mon 14:00' );
$fmt = static fn( ?int $ts ): string => null === $ts ? 'none' : ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() )->format( 'D j M H:i' );
check( 'daily: next hour today', $fmt( Scheduler::next_run( [ 'enabled' => true, 'frequency' => 'daily', 'hours' => [ 11, 21 ] ], $after ) ), 'Mon 16 Nov 11:00' );
check( 'daily: rolls to tomorrow', $fmt( Scheduler::next_run( [ 'enabled' => true, 'frequency' => 'daily', 'hours' => [ 3 ] ], $after ) ), 'Tue 17 Nov 03:00' );
check( 'monthly: day 1 of next month', $fmt( Scheduler::next_run( [ 'enabled' => true, 'frequency' => 'monthly', 'month_day' => 1, 'hours' => [ 3 ] ], $after ) ), 'Tue 1 Dec 03:00' );
check( 'monthly: last day of the month', $fmt( Scheduler::next_run( [ 'enabled' => true, 'frequency' => 'monthly', 'month_day' => 0, 'hours' => [ 23 ] ], $after ) ), 'Mon 30 Nov 23:00' );
check( 'monthly: needs no weekdays', null !== Scheduler::next_run( [ 'enabled' => true, 'frequency' => 'monthly', 'days' => [], 'month_day' => 20, 'hours' => [ 3 ] ], $after ), true );
check( 'disabled schedule has no slot', Scheduler::next_run( [ 'enabled' => false, 'days' => [ 1 ], 'hours' => [ 3 ] ], $after ), null );

// --- Migration: a pattern copied as exact rules ---------------------------------------------------
$expander = new Expander( new Url_Normalizer( 'https://example.test/', 'https://example.test/' ) );
( new ReflectionProperty( Expander::class, 'known' ) )->setValue( $expander, [ '/docs/v1/install', '/docs/v1/upgrade', '/docs/v2/install', '/kb/a', '/news', 'ext:https://other.test/docs/v1/x' ] );
$fmt_rules = static fn( array $rules ) => implode( ', ', array_map( static fn( Rule $r ) => $r->from . ' > ' . $r->to, $rules ) );

check( 'expand: regex with capture over known URLs', $fmt_rules( $expander->expand( new Rule( 'rank-math', '1', '^docs/v1/(.*)', '/docs/v2/$1', 301, Rule::MATCH_REGEX, false ) ) ), '/docs/v1/install > /docs/v2/install, /docs/v1/upgrade > /docs/v2/upgrade' );
check( 'expand: relative target gets a leading slash', $fmt_rules( $expander->expand( new Rule( 'yoast', '2', '^news$', 'blog', 301, Rule::MATCH_REGEX, false ) ) ), '/news > /blog' );
check( 'expand: prefix rule (SRM wildcard)', $fmt_rules( $expander->expand( new Rule( 'safe-redirect-manager', '3', '/kb/', '/help/*', 301, Rule::MATCH_PREFIX ) ) ), '/kb/a > /help/a' );
check( 'expand: a rule into itself is left out', $expander->expand( new Rule( 'smartcrawl', '4', '^/kb/(.+)$', '/kb/$1', 301, Rule::MATCH_REGEX ) ), [] );
check( 'expand: unsafe target is left out', $expander->expand( new Rule( 'x', '5', '^/news$', '/x"onmouseover=1', 301, Rule::MATCH_REGEX ) ), [] );
check( 'expand: invalid regex gives nothing', $expander->expand( new Rule( 'x', '6', '^/(unclosed', '/y', 301, Rule::MATCH_REGEX ) ), [] );
check( 'expand: exact rules are not expanded', $expander->expand( new Rule( 'x', '7', '/news', '/y' ) ), [] );

// --- Rule sources written into other plugins --------------------------------------------------
check( 'safe source: plain path with query', Url_Normalizer::is_safe_source( '/docs/v1/install?ref=a' ), true );
check( 'safe source: newline refused', Url_Normalizer::is_safe_source( "/old-blog/x\n<img src=x onerror=alert(1)>" ), false );
check( 'safe source: markup refused', Url_Normalizer::is_safe_source( '/a<b' ), false );
check( 'safe source: quote refused', Url_Normalizer::is_safe_source( '/a"b' ), false );
check( 'safe source: wildcard refused', Url_Normalizer::is_safe_source( '/legacy/*' ), false );
check( 'safe source: backslash refused', Url_Normalizer::is_safe_source( '/a\\b' ), false );
check( 'safe source: protocol-relative refused', Url_Normalizer::is_safe_source( '//evil.example/x' ), false );
check( 'safe source: relative refused', Url_Normalizer::is_safe_source( 'docs' ), false );

$expander2 = new Expander( new Url_Normalizer( 'https://example.test/', 'https://example.test/' ) );
( new ReflectionProperty( Expander::class, 'known' ) )->setValue( $expander2, [ '/old-blog/fine', "/old-blog/x\n<img src=x>", '/old-blog/a"b' ] );
check( 'expand: unsafe known URLs never become sources', $fmt_rules( $expander2->expand( new Rule( 'rank-math', '9', 'old-blog/', '/blog/', 301, Rule::MATCH_PREFIX, false ) ) ), '/old-blog/fine > /blog/' );

$expander3 = new Expander( new Url_Normalizer( 'https://example.test/', 'https://example.test/' ), microtime( true ) - 1 );
( new ReflectionProperty( Expander::class, 'known' ) )->setValue( $expander3, [ '/old-blog/fine' ] );
check( 'expand: shared budget spent gives nothing and flags partial', [ $expander3->expand( new Rule( 'x', '10', 'old-blog/', '/blog/', 301, Rule::MATCH_PREFIX, false ) ), $expander3->partial ], [ [], true ] );

// --- Regex search --------------------------------------------------------------------------------
check( 'search regex: case-insensitive', 1 === preg_match( Log_Repository::search_pattern( '^/DOCS/' ), '/docs/v1' ), true );
check( 'search regex: delimiter in the pattern is escaped', 1 === preg_match( Log_Repository::search_pattern( 'a~b' ), 'xa~by' ), true );
$invalid = false;
try {
	Log_Repository::search_pattern( '(unclosed' );
} catch ( InvalidArgumentException $e ) {
	$invalid = true;
}
check( 'search regex: invalid pattern is refused', $invalid, true );

echo $failures ? "\n{$failures} failed\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
