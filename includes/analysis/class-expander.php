<?php
/**
 * Turns a pattern rule into exact rules, for plugins that only hold exact rules.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Analysis;

use Acato\RedirectInspector\Includes\Installer;
use Acato\RedirectInspector\Includes\Rule;
use Acato\RedirectInspector\Includes\Sources\Registry;
use Acato\RedirectInspector\Includes\Url_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * A regex can match infinitely many URLs, so it is expanded over the URLs this site is known to
 * have: published content, every source and target of every stored redirect rule, and URLs from
 * Redirection's 404 log. URLs outside that set are not covered; the preview says so.
 *
 * 404-log URLs are written by anonymous visitors, so: a URL carrying HTML entities is skipped
 * (decoding could rebuild markup the logger neutralised), every source must pass
 * Url_Normalizer::is_safe_source(), and content and rule URLs take the per-rule slots first, so a
 * flood of 404 requests cannot push them out.
 */
final class Expander {

	/**
	 * Most exact rules one pattern rule expands into.
	 */
	const MAX_PER_RULE = 200;

	/**
	 * Most published posts looked at.
	 */
	const MAX_CONTENT = 2000;

	/**
	 * Rules read per page from a source.
	 */
	const PAGE_SIZE = 500;

	/**
	 * Known URL keys (lazy).
	 *
	 * @var string[]|null
	 */
	private ?array $known = null;

	/**
	 * Whether the time budget ran out, so some rules were not (fully) expanded.
	 *
	 * @var bool
	 */
	public bool $partial = false;

	/**
	 * Constructor.
	 *
	 * @param Url_Normalizer $normalizer Normaliser.
	 * @param float          $deadline   microtime(true) after which nothing more is expanded (0 = none),
	 *                                   shared by every rule of one migration.
	 */
	public function __construct( private Url_Normalizer $normalizer, private float $deadline = 0.0 ) {}

	/**
	 * Exact rules standing in for a pattern rule: one per known URL it redirects.
	 *
	 * @param Rule $rule Pattern rule.
	 *
	 * @return Rule[] Empty when the rule is exact, invalid or matches no known URL.
	 */
	public function expand( Rule $rule ): array {
		if ( $rule->is_exact() ) {
			return [];
		}

		$now = microtime( true );
		if ( $this->deadline && $now >= $this->deadline ) {
			$this->partial = true;
			return [];
		}

		$matcher = new Matcher( $this->normalizer, $this->deadline ? min( $now + 5, $this->deadline ) : $now + 5, 100000 );
		$matcher->add( [ $rule ] );
		if ( $matcher->invalid ) {
			return [];
		}

		$rules = [];
		foreach ( $this->known() as $key ) {
			// Only plain paths become rule sources in another plugin.
			if ( ! Url_Normalizer::is_safe_source( $key ) ) {
				continue;
			}
			$target = $matcher->apply( $rule, $key );
			if ( null === $target ) {
				continue;
			}

			// Plugins store relative targets without their leading slash.
			$target = preg_match( '#^https?://#i', $target ) ? $target : '/' . ltrim( $target, '/' );
			if ( ! Url_Normalizer::is_safe_target( $target ) || Url_Normalizer::fold( $this->normalizer->key( $target ) ) === Url_Normalizer::fold( $key ) ) {
				continue;
			}

			$rules[] = new Rule(
				source: $rule->source,
				id: $rule->id,
				from: $key,
				to: $target,
				code: $rule->code,
				case_sensitive: $rule->case_sensitive
			);

			if ( count( $rules ) >= self::MAX_PER_RULE ) {
				break;
			}
		}

		if ( $matcher->partial ) {
			$this->partial = true;
		}

		return $rules;
	}

	/**
	 * Internal URL keys this site is known to have.
	 *
	 * @return string[]
	 */
	public function known(): array {
		if ( null !== $this->known ) {
			return $this->known;
		}

		$keys = [];

		foreach ( $this->content_urls() as $url ) {
			$keys[ $this->normalizer->key( $url ) ] = true;
		}

		foreach ( Registry::all() as $source ) {
			if ( ! $source->has_storage() ) {
				continue;
			}
			$rows = $source->count() + $source->skipped();
			for ( $offset = 0; $offset < $rows; $offset += self::PAGE_SIZE ) {
				foreach ( $source->rules( $offset, self::PAGE_SIZE ) as $stored ) {
					if ( $stored->is_exact() ) {
						$keys[ $this->normalizer->key( $stored->from ) ] = true;
					}
					if ( ! str_contains( $stored->to, '$' ) && ! str_contains( $stored->to, '*' ) ) {
						$keys[ $this->normalizer->key( $stored->to ) ] = true;
					}
				}
			}
		}

		$internal = static fn( $key ) => ! Url_Normalizer::is_external_key( (string) $key ) && '/' !== $key;
		$trusted  = array_values( array_filter( array_keys( $keys ), $internal ) );
		sort( $trusted );

		// Visitor-requested URLs come after the site's own, so they never take their slots.
		$requested = [];
		foreach ( $this->requested_urls() as $url ) {
			$key = $this->normalizer->key( $url );
			if ( ! isset( $keys[ $key ] ) ) {
				$requested[ $key ] = true;
			}
		}
		$requested = array_values( array_filter( array_keys( $requested ), $internal ) );
		sort( $requested );

		$this->known = array_merge( $trusted, $requested );

		return $this->known;
	}

	/**
	 * URLs visitors requested that gave a 404, from Redirection's 404 log (also when Redirection is
	 * inactive): the old URLs a pattern rule is usually there for.
	 *
	 * @return string[]
	 */
	private function requested_urls(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'redirection_404';
		if ( ! Installer::table_exists( $table ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$urls = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM %i GROUP BY url ORDER BY MAX(id) DESC LIMIT %d', $table, self::MAX_CONTENT ) ) );

		// Redirection stores markup as entities; decoding them would rebuild it. Such URLs are skipped.
		return array_values( array_filter( $urls, static fn( $url ) => ! preg_match( '/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $url ) ) );
	}

	/**
	 * Permalinks of published, public content.
	 *
	 * @return string[]
	 */
	private function content_urls(): array {
		$ids = get_posts(
			[
				'post_type'              => array_values( get_post_types( [ 'public' => true ] ) ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_CONTENT,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return array_filter( array_map( static fn( $id ) => (string) get_permalink( (int) $id ), $ids ) );
	}
}
