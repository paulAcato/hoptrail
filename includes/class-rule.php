<?php
/**
 * One redirect rule, normalised across plugins.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object.
 */
final class Rule {

	const MATCH_EXACT    = 'exact';
	const MATCH_REGEX    = 'regex';
	const MATCH_PREFIX   = 'prefix';
	const MATCH_SUFFIX   = 'suffix';
	const MATCH_CONTAINS = 'contains';

	/**
	 * Constructor.
	 *
	 * @param string $source          Source adapter slug (redirection, yoast, …).
	 * @param string $id              Rule identifier inside its plugin.
	 * @param string $from            Raw source pattern/URL, as stored.
	 * @param string $to              Raw target, as stored.
	 * @param int    $code            HTTP status code.
	 * @param string $match           One of the MATCH_* constants.
	 * @param bool   $leading_slash   Whether the plugin matches against the path *with* a leading slash.
	 * @param bool   $case_sensitive  Whether matching is case-sensitive.
	 * @param string $edit_url        Admin URL where the rule can be edited ('' if none).
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $id,
		public readonly string $from,
		public readonly string $to,
		public readonly int $code = 301,
		public readonly string $match = self::MATCH_EXACT, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound -- Used as a named argument by the adapters.
		public readonly bool $leading_slash = true,
		public readonly bool $case_sensitive = false,
		public readonly string $edit_url = ''
	) {}

	/**
	 * Whether this rule only matches one URL.
	 *
	 * @return bool
	 */
	public function is_exact(): bool {
		return self::MATCH_EXACT === $this->match;
	}

	/**
	 * Unique key over all sources.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->source . ':' . $this->id;
	}

	/**
	 * The source as a path with a leading slash (exact rules only).
	 *
	 * @return string
	 */
	public function path(): string {
		return '/' . ltrim( $this->from, '/' );
	}

	/**
	 * The source as a regex matched against the path, with or without its leading slash.
	 *
	 * @param bool $leading_slash Whether the target plugin matches with the leading slash.
	 *
	 * @return string
	 */
	public function regex( bool $leading_slash ): string {
		$slash = $leading_slash ? '/' : '';

		if ( self::MATCH_REGEX === $this->match ) {
			// Re-anchor a pattern written for the other slash style.
			if ( $this->leading_slash && ! $leading_slash ) {
				return preg_replace( '#^\^/#', '^', $this->from ) ?? $this->from;
			}
			if ( ! $this->leading_slash && $leading_slash ) {
				return preg_replace( '#^\^(?!/)#', '^/', $this->from ) ?? $this->from;
			}
			return $this->from;
		}

		$plain = preg_quote( ltrim( $this->from, '/' ), '@' );

		return match ( $this->match ) {
			self::MATCH_PREFIX   => '^' . $slash . $plain . '(.*)',
			self::MATCH_SUFFIX   => $plain . '$',
			self::MATCH_CONTAINS => $plain,
			default              => '^' . $slash . $plain . '$',
		};
	}

	/**
	 * Data for JSON storage/output.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'source' => $this->source,
			'id'     => $this->id,
			'from'   => $this->from,
			'to'     => $this->to,
			'code'   => $this->code,
			'match'  => $this->match,
			'edit'   => $this->edit_url,
		];
	}
}
