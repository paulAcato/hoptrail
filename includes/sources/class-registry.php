<?php
/**
 * The list of sources.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in adapters plus whatever `hoptrail_sources` adds.
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
			new Eps_301(),
			new Seopress(),
			new Slim_Seo(),
			new Smartcrawl(),
			new Seo_Framework(),
		];

		/**
		 * Filter the redirect sources Hoptrail reads.
		 *
		 * @param Source[] $sources Source adapters, in the order rules take precedence.
		 */
		$sources = apply_filters( 'hoptrail_sources', $sources );

		$keyed = [];
		foreach ( (array) $sources as $source ) {
			if ( $source instanceof Source ) {
				$keyed[ $source->slug() ] = $source;
			}
		}

		return $keyed;
	}
}
