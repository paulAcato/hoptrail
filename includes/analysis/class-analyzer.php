<?php
/**
 * Finds loops, chains and unnecessary redirects in the merged rule graph.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Analysis;

use Acato\RedirectInspector\Includes\Rule;
use Acato\RedirectInspector\Includes\Sources\Registry;
use Acato\RedirectInspector\Includes\Url_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Every rule is followed hop by hop through whichever rule (of any plugin) fires next. A revisited
 * URL is a loop, two or more hops is a chain. Then the static checks for unnecessary rules.
 */
final class Analyzer {

	/**
	 * Hops followed before a path is considered endless.
	 */
	const MAX_HOPS = 20;

	/**
	 * Upper bound of WordPress lookups (url_to_postid) for the shadow and broken checks.
	 */
	const MAX_LOOKUPS = 2000;

	const TYPE_LOOP      = 'loop';
	const TYPE_CHAIN     = 'chain';
	const TYPE_CONFLICT  = 'conflict';
	const TYPE_DUPLICATE = 'duplicate';
	const TYPE_NOOP      = 'noop';
	const TYPE_REDUNDANT = 'redundant';
	const TYPE_SHADOW    = 'shadow';
	const TYPE_BROKEN    = 'broken';

	/**
	 * All types, in display order.
	 */
	const TYPES = [ self::TYPE_LOOP, self::TYPE_CHAIN, self::TYPE_CONFLICT, self::TYPE_NOOP, self::TYPE_DUPLICATE, self::TYPE_REDUNDANT, self::TYPE_SHADOW, self::TYPE_BROKEN ];

	/**
	 * Issues by fingerprint.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $issues = [];

	/**
	 * WordPress lookups so far.
	 *
	 * @var int
	 */
	private int $lookups = 0;

	/**
	 * Whether the lookup budget was hit.
	 *
	 * @var bool
	 */
	public bool $partial = false;

	/**
	 * Constructor.
	 *
	 * @param Matcher        $matcher    Matcher holding all rules.
	 * @param Url_Normalizer $normalizer Normaliser.
	 * @param float          $deadline   microtime(true) after which optional checks stop.
	 */
	public function __construct(
		private Matcher $matcher,
		private Url_Normalizer $normalizer,
		private float $deadline
	) {}

	/**
	 * Analyse all rules.
	 *
	 * @param Rule[] $rules All rules, in precedence order.
	 *
	 * @return array<int, array<string, mixed>> Issues.
	 */
	public function run( array $rules ): array {
		$this->issues = [];

		$this->find_conflicts();

		$terminals = [];
		foreach ( $rules as $rule ) {
			// A losing duplicate never fires; it is reported as a duplicate/conflict, not walked.
			if ( $rule->is_exact() && ! $this->matcher->is_winner( $rule ) ) {
				continue;
			}

			$final = $this->walk( $rule );
			// Only exact rules end on a real URL; a pattern rule's end is derived from a sample.
			if ( null !== $final && $rule->is_exact() && ! Url_Normalizer::is_external_key( $final ) ) {
				$terminals[ $final ][] = $rule;
			}
		}

		$this->find_redundant_old_slugs();
		$this->find_shadowed_content();
		$this->find_broken_targets( $terminals );

		foreach ( $this->matcher->invalid as $rule ) {
			$this->add(
				self::TYPE_BROKEN,
				'medium',
				'invalid|' . $rule->key(),
				$rule->from,
				$rule->to,
				[ self::hop( $rule, $rule->from, $rule->to ) ],
				__( 'This regular expression is invalid, so the rule never fires. Fix or remove it.', 'redirect-inspector' )
			);
		}

		$this->partial = $this->partial || $this->matcher->partial;

		return array_values( $this->issues );
	}

	/**
	 * Same source in more than one exact rule: duplicate (same target) or conflict (different).
	 *
	 * @return void
	 */
	private function find_conflicts(): void {
		foreach ( $this->matcher->exact_index() as $key => $rules ) {
			if ( count( $rules ) < 2 ) {
				continue;
			}

			$targets = array_unique( array_map( fn( Rule $rule ) => Url_Normalizer::fold( $this->normalizer->key( $rule->to ) ), $rules ) );
			$hops    = array_map( static fn( Rule $rule ) => self::hop( $rule, $rule->from, $rule->to ), $rules );
			$winner  = $rules[0];

			if ( 1 === count( $targets ) ) {
				$this->add(
					self::TYPE_DUPLICATE,
					'low',
					'duplicate|' . $key,
					$winner->from,
					$winner->to,
					$hops,
					sprintf(
						/* translators: 1: number of rules, 2: plugin that wins. */
						__( '%1$d rules redirect this URL to the same target. Keep the one in %2$s and remove the others.', 'redirect-inspector' ),
						count( $rules ),
						self::label( $winner->source )
					),
					self::fix( 'disable', array_slice( $rules, 1 ) )
				);
				continue;
			}

			$this->add(
				self::TYPE_CONFLICT,
				'high',
				'conflict|' . $key,
				$winner->from,
				$winner->to,
				$hops,
				sprintf(
					/* translators: 1: number of rules, 2: number of targets. */
					__( '%1$d rules send this URL to %2$d different targets; which one wins depends on plugin load order. Keep one rule.', 'redirect-inspector' ),
					count( $rules ),
					count( $targets )
				)
			);
		}
	}

	/**
	 * Follow one rule through the graph and record a loop, chain or no-op.
	 *
	 * @param Rule $rule Starting rule.
	 *
	 * @return string|null Final key, or null when the path loops or can't be followed.
	 */
	private function walk( Rule $rule ): ?string {
		$start = $rule->is_exact() ? $this->normalizer->key( $rule->from ) : $this->matcher->sample_key( $rule );
		if ( null === $start ) {
			return null;
		}

		$raw_target = $rule->is_exact() ? $rule->to : $this->matcher->apply( $rule, $start );
		if ( null === $raw_target ) {
			return null; // Budget exhausted.
		}
		$first_target = $this->normalizer->key( $raw_target );

		// Source and target are the same URL (as far as this rule's matching can tell).
		$same = $rule->case_sensitive ? $first_target === $start : Url_Normalizer::fold( $first_target ) === Url_Normalizer::fold( $start );
		if ( $same ) {
			$from      = trim( $rule->is_exact() ? $rule->from : $start );
			$to        = trim( $raw_target );
			$identical = $rule->case_sensitive ? $from === $to : strtolower( $from ) === strtolower( $to );
			$this->add(
				$identical ? self::TYPE_LOOP : self::TYPE_NOOP,
				$identical ? 'critical' : 'high',
				$identical ? 'loop|' . Url_Normalizer::fold( $start ) : 'noop|' . $rule->key(),
				$rule->from,
				$rule->to,
				[ self::hop( $rule, $start, $first_target ) ],
				$identical
					? __( 'This rule redirects a URL to itself: an endless loop. Remove it.', 'redirect-inspector' )
					: __( 'Source and target differ only in case, trailing slash or encoding. Depending on the plugin this loops or does nothing. Remove it.', 'redirect-inspector' ),
				self::fix( 'disable', [ $rule ] )
			);
			return null;
		}

		$path    = [ $start ];
		$hops    = [ self::hop( $rule, $start, $first_target ) ];
		$current = $first_target;

		for ( $steps = 1; $steps < self::MAX_HOPS; $steps = count( $hops ) ) {
			$index = array_search( $current, $path, true );
			if ( false !== $index ) {
				$this->record_loop( $rule, array_slice( $path, (int) $index ), array_slice( $hops, (int) $index ), 0 !== $index, $hops );
				return null;
			}

			$path[] = $current;
			$next   = $this->matcher->resolve( $current );
			if ( null === $next ) {
				break;
			}

			$hops[]  = self::hop( $next['rule'], $current, $next['target'] );
			$current = $next['target'];
		}

		if ( count( $hops ) > 1 ) {
			$this->add(
				self::TYPE_CHAIN,
				count( $hops ) >= self::MAX_HOPS ? 'critical' : ( count( $hops ) > 2 ? 'high' : 'medium' ),
				'chain|' . $rule->key(),
				$rule->from,
				$current,
				$hops,
				sprintf(
					/* translators: 1: source URL, 2: final URL, 3: number of hops. */
					__( 'Visitors and crawlers take %3$d hops. Point %1$s straight to %2$s.', 'redirect-inspector' ),
					$rule->from,
					$this->display( $current ),
					count( $hops )
				),
				$rule->is_exact() && count( $hops ) < self::MAX_HOPS ? self::fix( 'retarget', [ $rule ], $this->display( $current ) ) : []
			);
		}

		return $current;
	}

	/**
	 * Record a loop once per cycle (whichever rule found it), plus a chain for a rule that leads
	 * into a loop it isn't part of.
	 *
	 * @param Rule     $rule        Starting rule.
	 * @param string[] $cycle       Keys in the cycle.
	 * @param array[]  $cycle_hops  Hops in the cycle.
	 * @param bool     $leads_in    Whether the starting rule leads into the cycle from outside.
	 * @param array[]  $all_hops    All hops walked.
	 *
	 * @return void
	 */
	private function record_loop( Rule $rule, array $cycle, array $cycle_hops, bool $leads_in, array $all_hops ): void {
		$folded    = array_map( [ Url_Normalizer::class, 'fold' ], $cycle );
		$min       = array_keys( $folded, min( $folded ), true )[0];
		$canonical = array_merge( array_slice( $folded, $min ), array_slice( $folded, 0, $min ) );
		$hops      = array_merge( array_slice( $cycle_hops, $min ), array_slice( $cycle_hops, 0, $min ) );
		$plugins   = array_unique( array_column( array_column( $hops, 'rule' ), 'label' ) );

		$this->add(
			self::TYPE_LOOP,
			'critical',
			'loop|' . implode( '>', $canonical ),
			$this->display( $cycle[ $min ] ),
			$this->display( $cycle[ $min ] ),
			$hops,
			count( $plugins ) > 1
				? sprintf(
					/* translators: %s: list of plugins. */
					__( 'Endless loop across %s. Visitors get "too many redirects". Break the loop by changing or removing one of these rules.', 'redirect-inspector' ),
					implode( ', ', $plugins )
				)
				: __( 'Endless loop. Visitors get "too many redirects". Break the loop by changing or removing one of these rules.', 'redirect-inspector' )
		);

		if ( $leads_in ) {
			$this->add(
				self::TYPE_CHAIN,
				'high',
				'chain|' . $rule->key(),
				$rule->from,
				$this->display( $cycle[ $min ] ),
				$all_hops,
				sprintf(
					/* translators: %s: source URL. */
					__( '%s ends up in a redirect loop. Point it to a page that loads.', 'redirect-inspector' ),
					$rule->from
				)
			);
		}
	}

	/**
	 * Rules that redirect an old slug WordPress already redirects through `_wp_old_slug`.
	 *
	 * @return void
	 */
	private function find_redundant_old_slugs(): void {
		global $wpdb;

		$by_slug = [];
		foreach ( $this->matcher->exact_index() as $key => $rules ) {
			if ( Url_Normalizer::is_external_key( $key ) || str_contains( $key, '?' ) ) {
				continue;
			}
			$slug = strtolower( basename( $key ) );
			if ( '' !== $slug ) {
				$by_slug[ $slug ][] = [ $key, $rules[0] ];
			}
		}

		foreach ( array_chunk( array_keys( $by_slug ), 500, false ) as $chunk ) {
			if ( ! $this->has_time() ) {
				return;
			}

			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Placeholders built from count.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id AND p.post_status = 'publish' WHERE pm.meta_key = '_wp_old_slug' AND pm.meta_value IN ({$placeholders})", $wpdb->postmeta, $wpdb->posts, ...array_map( 'strval', $chunk ) ) );

			foreach ( $rows as $row ) {
				$permalink = $this->normalizer->key( (string) get_permalink( (int) $row->post_id ) );
				foreach ( $by_slug[ strtolower( $row->meta_value ) ] ?? [] as [ $key, $rule ] ) {
					if ( $this->normalizer->key( $rule->to ) !== $permalink || $key === $permalink ) {
						continue;
					}
					$this->add(
						self::TYPE_REDUNDANT,
						'low',
						'redundant|' . $rule->key(),
						$rule->from,
						$rule->to,
						[ self::hop( $rule, $key, $permalink ) ],
						__( 'WordPress already redirects this old slug to the post\'s current URL. This rule can be removed.', 'redirect-inspector' ),
						self::fix( 'disable', [ $rule ] )
					);
				}
			}
		}
	}

	/**
	 * Exact rules whose source is the live URL of published content: the redirect hides it.
	 *
	 * @return void
	 */
	private function find_shadowed_content(): void {
		foreach ( $this->matcher->exact_index() as $key => $rules ) {
			if ( '/' === $key || Url_Normalizer::is_external_key( $key ) || ! $this->lookup_allowed() ) {
				continue;
			}

			$post_id = url_to_postid( $this->normalizer->to_url( $key ) );
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || $this->normalizer->key( (string) get_permalink( $post_id ) ) !== $key ) {
				continue;
			}

			$rule = $rules[0];
			$this->add(
				self::TYPE_SHADOW,
				'medium',
				'shadow|' . $key,
				$rule->from,
				$rule->to,
				[ self::hop( $rule, $key, $this->normalizer->key( $rule->to ) ) ],
				sprintf(
					/* translators: %s: post title. */
					__( 'This URL belongs to the published "%s", which visitors can no longer reach. Remove the redirect, or unpublish the content.', 'redirect-inspector' ),
					wp_strip_all_tags( get_the_title( $post_id ) )
				),
				self::fix( 'disable', [ $rule ] )
			);
		}
	}

	/**
	 * Internal final targets that don't resolve to content.
	 *
	 * @param array<string, Rule[]> $terminals Final key ⇒ rules ending there.
	 *
	 * @return void
	 */
	private function find_broken_targets( array $terminals ): void {
		foreach ( $terminals as $key => $rules ) {
			if ( '/' === $key || str_starts_with( $key, '/wp-' ) || preg_match( '/\.[a-z0-9]{2,5}$/', $key ) || ! $this->lookup_allowed() ) {
				continue;
			}

			if ( url_to_postid( $this->normalizer->to_url( $key ) ) || $this->is_term_url( $key ) ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				$this->add(
					self::TYPE_BROKEN,
					'medium',
					'broken|' . $rule->key(),
					$rule->from,
					$this->display( $key ),
					[ self::hop( $rule, $this->normalizer->key( $rule->is_exact() ? $rule->from : $key ), $key ) ],
					__( 'The final target doesn\'t match a post, page or term, so it probably shows a 404. Run a live check to confirm.', 'redirect-inspector' )
				);
			}
		}
	}

	/**
	 * Whether the last path segment is a term slug.
	 *
	 * @param string $key Normalised key.
	 *
	 * @return bool
	 */
	private function is_term_url( string $key ): bool {
		$terms = get_terms(
			[
				'slug'                   => basename( strtok( $key, '?' ) ),
				'hide_empty'             => false,
				'number'                 => 1,
				'fields'                 => 'ids',
				'update_term_meta_cache' => false,
			]
		);

		return is_array( $terms ) && [] !== $terms;
	}

	/**
	 * Count a WordPress lookup against the budget.
	 *
	 * @return bool Whether it may run.
	 */
	private function lookup_allowed(): bool {
		if ( $this->lookups >= self::MAX_LOOKUPS || ! $this->has_time() ) {
			$this->partial = true;
			return false;
		}

		++$this->lookups;

		return true;
	}

	/**
	 * Whether the deadline has not passed.
	 *
	 * @return bool
	 */
	private function has_time(): bool {
		if ( microtime( true ) > $this->deadline ) {
			$this->partial = true;
			return false;
		}

		return true;
	}

	/**
	 * Store an issue once per fingerprint.
	 *
	 * @param string  $type       Type.
	 * @param string  $severity   critical|high|medium|low.
	 * @param string  $identity   Unique identity, hashed into the fingerprint.
	 * @param string  $source     Source shown.
	 * @param string  $target     Final target shown.
	 * @param array[] $hops       Hops.
	 * @param string  $suggestion What to do.
	 * @param array   $fix        Unambiguous automatic fix: { action: retarget|disable, rules: [ {source,id,from,to} ], to?: string }, or [].
	 *
	 * @return void
	 */
	private function add( string $type, string $severity, string $identity, string $source, string $target, array $hops, string $suggestion, array $fix = [] ): void {
		$fingerprint = sha1( $identity );
		if ( isset( $this->issues[ $fingerprint ] ) ) {
			return;
		}

		$this->issues[ $fingerprint ] = [
			'type'        => $type,
			'severity'    => $severity,
			'fingerprint' => $fingerprint,
			'source_url'  => $source,
			'target_url'  => $target,
			'plugins'     => implode( ',', array_unique( array_column( array_column( $hops, 'rule' ), 'source' ) ) ),
			'hops'        => $hops,
			'suggestion'  => $suggestion,
			'fix'         => $fix,
		];
	}

	/**
	 * Fix payload referencing rules by source, ID and their current from/to (checked before applying).
	 *
	 * @param string $action retarget|disable.
	 * @param Rule[] $rules  Rules to change.
	 * @param string $to     New target (retarget).
	 *
	 * @return array
	 */
	private static function fix( string $action, array $rules, string $to = '' ): array {
		return array_filter(
			[
				'action' => $action,
				'to'     => $to,
				'rules'  => array_map(
					static fn( Rule $rule ) => [
						'source' => $rule->source,
						'label'  => self::label( $rule->source ),
						'id'     => $rule->id,
						'from'   => $rule->from,
						'to'     => $rule->to,
					],
					$rules
				),
			]
		);
	}

	/**
	 * One hop for storage and display.
	 *
	 * @param Rule   $rule Rule that fires.
	 * @param string $from From.
	 * @param string $to   To.
	 *
	 * @return array<string, mixed>
	 */
	private static function hop( Rule $rule, string $from, string $to ): array {
		return [
			'from' => $from,
			'to'   => Url_Normalizer::is_external_key( $to ) ? substr( $to, 4 ) : $to,
			'code' => $rule->code,
			'rule' => [
				'source' => $rule->source,
				'label'  => self::label( $rule->source ),
				'id'     => $rule->id,
				'from'   => $rule->from,
				'to'     => $rule->to,
				'match'  => $rule->match,
				'edit'   => $rule->edit_url,
			],
		];
	}

	/**
	 * Display form of a key.
	 *
	 * @param string $key Key.
	 *
	 * @return string
	 */
	private function display( string $key ): string {
		return Url_Normalizer::is_external_key( $key ) ? substr( $key, 4 ) : $key;
	}

	/**
	 * Label of a source slug.
	 *
	 * @param string $slug Source slug.
	 *
	 * @return string
	 */
	private static function label( string $slug ): string {
		static $labels = null;
		if ( null === $labels ) {
			$labels = array_map( static fn( $source ) => $source->label(), Registry::all() );
		}

		return $labels[ $slug ] ?? $slug;
	}
}
