<?php
/**
 * The list of sources.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in adapters plus whatever `redirect_inspector_sources` adds.
 */
final class Registry {

	/**
	 * All registered sources, keyed by slug, in precedence order.
	 *
	 * @return array<string, Source>
	 */
	public static function all(): array {
		$sources = [
			new Redirection(),
			new Yoast(),
			new Rank_Math(),
			new Safe_Redirect_Manager(),
			new Simple_301(),
		];

		/**
		 * Filter the redirect sources Redirect Inspector reads.
		 *
		 * @param Source[] $sources Source adapters, in the order rules take precedence.
		 */
		$sources = apply_filters( 'redirect_inspector_sources', $sources );

		$keyed = [];
		foreach ( (array) $sources as $source ) {
			if ( $source instanceof Source ) {
				$keyed[ $source->slug() ] = $source;
			}
		}

		return $keyed;
	}
}
