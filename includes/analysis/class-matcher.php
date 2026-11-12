<?php
/**
 * Resolves a URL to the rule that would redirect it.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Analysis;

use Acato\RedirectInspector\Includes\Rule;
use Acato\RedirectInspector\Includes\Url_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Exact rules are an O(1) lookup; pattern rules are evaluated in order, within a time and
 * evaluation budget. Third-party regexes are untrusted: invalid ones are caught and reported, and
 * PCRE errors (such as hitting the backtrack limit) count as no match (S3).
 */
final class Matcher {

	/**
	 * Exact rules by folded (lower-case) source key, in precedence order.
	 *
	 * @var array<string, Rule[]>
	 */
	private array $exact = [];

	/**
	 * Pattern rules, in precedence order.
	 *
	 * @var Rule[]
	 */
	private array $patterns = [];

	/**
	 * Compiled regex per pattern (with flags); false when the pattern is invalid.
	 *
	 * @var array<string, string|false>
	 */
	private array $compiled = [];

	/**
	 * Resolve cache per key.
	 *
	 * @var array<string, array{rule:Rule, target:string}|null>
	 */
	private array $cache = [];

	/**
	 * Pattern evaluations so far.
	 *
	 * @var int
	 */
	private int $evaluations = 0;

	/**
	 * Whether a budget was hit, so results may be incomplete.
	 *
	 * @var bool
	 */
	public bool $partial = false;

	/**
	 * Rules whose pattern could not be compiled.
	 *
	 * @var array<string, Rule>
	 */
	public array $invalid = [];

	/**
	 * Constructor.
	 *
	 * @param Url_Normalizer $normalizer      Normaliser.
	 * @param float          $deadline        microtime(true) after which pattern evaluation stops.
	 * @param int            $max_evaluations Maximum pattern evaluations.
	 */
	public function __construct(
		private Url_Normalizer $normalizer,
		private float $deadline,
		private int $max_evaluations = 2000000
	) {}

	/**
	 * Add rules (call in precedence order).
	 *
	 * @param Rule[] $rules Rules.
	 *
	 * @return void
	 */
	public function add( array $rules ): void {
		foreach ( $rules as $rule ) {
			if ( $rule->is_exact() ) {
				$this->exact[ Url_Normalizer::fold( $this->normalizer->key( $rule->from ) ) ][] = $rule;
				continue;
			}

			if ( Rule::MATCH_REGEX === $rule->match && false === $this->compile( $rule ) ) {
				$this->invalid[ $rule->key() ] = $rule;
				continue;
			}

			$this->patterns[] = $rule;
		}

		$this->cache = [];
	}

	/**
	 * Exact rules per folded source key (index 0 is the rule that fires).
	 *
	 * @return array<string, Rule[]>
	 */
	public function exact_index(): array {
		return $this->exact;
	}

	/**
	 * The rule that fires for a key, and where it sends the visitor.
	 *
	 * @param string $key Normalised key.
	 *
	 * @return array{rule:Rule, target:string}|null
	 */
	public function resolve( string $key ): ?array {
		if ( Url_Normalizer::is_external_key( $key ) ) {
			return null;
		}

		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		$result = null;

		foreach ( $this->exact[ Url_Normalizer::fold( $key ) ] ?? [] as $rule ) {
			if ( $rule->case_sensitive && $this->normalizer->key( $rule->from ) !== $key ) {
				continue;
			}
			$result = [
				'rule'   => $rule,
				'target' => $this->normalizer->key( $rule->to ),
			];
			break;
		}

		if ( null === $result ) {
			foreach ( $this->patterns as $rule ) {
				$target = $this->apply( $rule, $key );
				if ( null !== $target ) {
					$result = [
						'rule'   => $rule,
						'target' => $this->normalizer->key( $target ),
					];
					break;
				}
			}
		}

		$this->cache[ $key ] = $result;

		return $result;
	}

	/**
	 * Whether this exact rule is the one that fires for its source (not a losing duplicate).
	 *
	 * @param Rule $rule Exact rule.
	 *
	 * @return bool
	 */
	public function is_winner( Rule $rule ): bool {
		$resolved = $this->resolve( $this->normalizer->key( $rule->from ) );

		return null !== $resolved && $resolved['rule'] === $rule;
	}

	/**
	 * Apply a pattern rule to a key: the raw target when it matches, else null.
	 *
	 * @param Rule   $rule Rule.
	 * @param string $key  Normalised key.
	 *
	 * @return string|null
	 */
	public function apply( Rule $rule, string $key ): ?string {
		if ( ! $this->within_budget() ) {
			return null;
		}

		++$this->evaluations;

		$subject  = Url_Normalizer::subject( $key, $rule->leading_slash );
		$from     = $rule->case_sensitive ? $rule->from : strtolower( $rule->from );
		$haystack = $rule->case_sensitive ? $subject : strtolower( $subject );

		switch ( $rule->match ) {
			case Rule::MATCH_EXACT:
				$own = $this->normalizer->key( $rule->from );
				return ( $rule->case_sensitive ? $own === $key : Url_Normalizer::fold( $own ) === Url_Normalizer::fold( $key ) ) ? $rule->to : null;

			case Rule::MATCH_PREFIX:
				$prefix = Url_Normalizer::subject( '/' . ltrim( $from, '/' ), $rule->leading_slash );
				if ( '' === $prefix || ! str_starts_with( $haystack, $prefix ) ) {
					return null;
				}
				// SRM: a target ending in `*` receives the remainder.
				return str_ends_with( $rule->to, '*' ) ? substr( $rule->to, 0, -1 ) . substr( $subject, strlen( $prefix ) ) : $rule->to;

			case Rule::MATCH_SUFFIX:
				return '' !== $from && str_ends_with( $haystack, $from ) ? $rule->to : null;

			case Rule::MATCH_CONTAINS:
				return '' !== $from && str_contains( $haystack, $from ) ? $rule->to : null;

			case Rule::MATCH_REGEX:
				$regex = $this->compile( $rule );
				if ( false === $regex ) {
					return null;
				}

				if ( 1 !== $this->guarded( static fn() => preg_match( $regex, $subject ) ) ) {
					return null;
				}

				// Like the plugins: preg_replace keeps the unmatched parts of the URL.
				$target = $this->guarded( static fn() => preg_replace( $regex, $rule->to, $subject ) );

				return is_string( $target ) ? $target : null;
		}

		return null;
	}

	/**
	 * A concrete URL that a pattern rule matches, so the rule can be followed like an exact one.
	 *
	 * @param Rule $rule Pattern rule.
	 *
	 * @return string|null Normalised key, or null when no sample could be derived.
	 */
	public function sample_key( Rule $rule ): ?string {
		$candidate = match ( $rule->match ) {
			Rule::MATCH_PREFIX   => '/' . ltrim( $rule->from, '/' ) . 'sample',
			Rule::MATCH_SUFFIX   => '/sample' . ( str_starts_with( $rule->from, '/' ) ? '' : '-' ) . $rule->from,
			Rule::MATCH_CONTAINS => '/sample-' . ltrim( $rule->from, '/' ),
			Rule::MATCH_REGEX    => self::regex_sample( $rule->from ),
			default              => $rule->from,
		};

		if ( null === $candidate ) {
			return null;
		}

		// The key loses a trailing slash; try the sample as-is and with a slash-free ending.
		foreach ( [ $candidate, rtrim( $candidate, '/' ) . '/sample', rtrim( $candidate, '/' ) ] as $try ) {
			$key = $this->normalizer->key( $try );
			if ( null !== $this->apply( $rule, $key ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Turn a simple regex into an example path (`^/blog/(.*)$` → `/blog/sample`).
	 *
	 * @param string $pattern Regex without delimiters.
	 *
	 * @return string|null
	 */
	private static function regex_sample( string $pattern ): ?string {
		$sample = trim( $pattern );
		$sample = preg_replace( '/^\^|\$$/', '', $sample ) ?? '';
		$sample = preg_replace( '/\{\d+(?:,\d*)?\}/', '', $sample ) ?? '';
		$sample = preg_replace_callback(
			'/\((?:\?:)?([^()]*)\)[?*+]?/',
			static fn( $group ) => preg_match( '/^(?:\\\\d|\[0-9\])[+*]?$/', $group[1] ) ? '1' : 'sample',
			$sample
		) ?? '';
		$sample = preg_replace( '/\\\\d[*+]?|\[0-9\][*+?]?/', '1', $sample ) ?? '';
		$sample = preg_replace( '/\[[^\]]*\][*+?]?|\.[*+]\??|\\\\[ws][*+]?/', 'sample', $sample ) ?? '';
		$sample = str_replace( [ '\\/', '\\.', '\\-', '\\?' ], [ '/', '.', '-', '' ], $sample );
		$sample = preg_replace( '/[?*+]/', '', $sample ) ?? '';

		if ( '' === $sample || preg_match( '/[()\[\]{}|\\\\]/', $sample ) ) {
			return null;
		}

		return '/' . ltrim( $sample, '/' );
	}

	/**
	 * Compile (and cache) a rule's regex; false when invalid.
	 *
	 * @param Rule $rule Regex rule.
	 *
	 * @return string|false
	 */
	private function compile( Rule $rule ) {
		$regex = '@' . str_replace( '@', '\\@', $rule->from ) . '@' . ( $rule->case_sensitive ? '' : 'i' );

		// Keyed on the compiled pattern itself (flags included): rule IDs are only unique per plugin.
		$key = $regex;
		if ( isset( $this->compiled[ $key ] ) ) {
			return $this->compiled[ $key ];
		}

		$result = $this->guarded( static fn() => preg_match( $regex, '' ) );

		$this->compiled[ $key ] = false === $result ? false : $regex;

		return $this->compiled[ $key ];
	}

	/**
	 * Run a preg_* call; warnings (invalid pattern) and PCRE errors (backtrack limit) become false.
	 *
	 * @param callable $call Call returning int|false.
	 *
	 * @return int|false
	 */
	private function guarded( callable $call ) {
		set_error_handler( static fn() => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped: swallow warnings from untrusted patterns.
		try {
			$result = $call();
		} finally {
			restore_error_handler();
		}

		if ( PREG_NO_ERROR !== preg_last_error() ) {
			return false;
		}

		return $result;
	}

	/**
	 * Whether there is time and evaluation budget left.
	 *
	 * @return bool
	 */
	private function within_budget(): bool {
		if ( $this->evaluations >= $this->max_evaluations || microtime( true ) > $this->deadline ) {
			$this->partial = true;
			return false;
		}

		return true;
	}
}
