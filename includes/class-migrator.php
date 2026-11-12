<?php
/**
 * Copies leftover rules from an inactive redirect plugin into an active one.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Includes\Sources\Registry;
use Acato\RedirectInspector\Includes\Sources\Source;
use Acato\RedirectInspector\Includes\Sources\Writable;

defined( 'ABSPATH' ) || exit;

/**
 * Copy, never move: the inactive plugin's data is left as it is. Rules the target already has
 * (same source URL or pattern) and rules it can't express are skipped. Each copy is logged.
 */
final class Migrator {

	/**
	 * Rules read per page.
	 */
	const PAGE_SIZE = 500;

	/**
	 * Inactive plugins that still hold rules.
	 *
	 * @return array<int, array{slug:string, label:string, rules:int}>
	 */
	public static function leftovers(): array {
		$leftovers = [];
		foreach ( Registry::all() as $slug => $source ) {
			if ( ! $source->is_available() && $source->has_storage() ) {
				$leftovers[] = [
					'slug'  => $slug,
					'label' => $source->label(),
					'rules' => $source->count(),
				];
			}
		}

		return $leftovers;
	}

	/**
	 * Active plugins rules can be migrated into.
	 *
	 * @return array<int, array{slug:string, label:string}>
	 */
	public static function targets(): array {
		$targets = [];
		foreach ( Registry::all() as $slug => $source ) {
			if ( $source instanceof Writable && $source->is_available() ) {
				$targets[] = [
					'slug'  => $slug,
					'label' => $source->label(),
				];
			}
		}

		return $targets;
	}

	/**
	 * Copy (or with $dry_run, count) the leftover rules of one plugin into another.
	 *
	 * @param string $from    Source slug (inactive, with storage).
	 * @param string $to      Target slug (active, writable).
	 * @param bool   $dry_run Only count.
	 *
	 * @return array{created:int, existing:int, unsupported:int, failed:int, dry_run:bool}
	 *
	 * @throws \RuntimeException When the source or target isn't eligible.
	 */
	public static function run( string $from, string $to, bool $dry_run ): array {
		$sources = Registry::all();
		$source  = $sources[ $from ] ?? null;
		$target  = $sources[ $to ] ?? null;

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text (JSON/React/CLI).
		if ( ! $source instanceof Source || $source->is_available() || ! $source->has_storage() ) {
			throw new \RuntimeException( __( 'There are no leftover rules to migrate from that plugin.', 'redirect-inspector' ), 400 );
		}
		if ( ! $target instanceof Writable || ! $target instanceof Source || ! $target->is_available() || $from === $to ) {
			throw new \RuntimeException( __( 'Rules can only be migrated into an active redirect plugin.', 'redirect-inspector' ), 400 );
		}
		// phpcs:enable

		$normalizer = new Url_Normalizer();
		$existing   = self::existing_sources( $target, $normalizer );
		$result     = [
			'created'     => 0,
			'existing'    => 0,
			'unsupported' => 0,
			'failed'      => 0,
			'dry_run'     => $dry_run,
		];

		$rows = $source->count() + $source->skipped();
		for ( $offset = 0; $offset < $rows; $offset += self::PAGE_SIZE ) {
			foreach ( $source->rules( $offset, self::PAGE_SIZE ) as $rule ) {
				$key = $rule->is_exact() ? Url_Normalizer::fold( $normalizer->key( $rule->from ) ) : 're:' . $rule->regex( true );

				if ( isset( $existing[ $key ] ) ) {
					++$result['existing'];
					continue;
				}
				if ( ! $target->supports( $rule ) ) {
					++$result['unsupported'];
					continue;
				}
				if ( $dry_run ) {
					++$result['created'];
					$existing[ $key ] = true;
					continue;
				}

				if ( $target->create( $rule ) ) {
					++$result['created'];
					$existing[ $key ] = true;
					Log_Repository::log_fix( 0, 'migrated', $to, '', array_merge( $rule->to_array(), [ 'from_plugin' => $from ] ), [] );
				} else {
					++$result['failed'];
				}
			}
		}

		return $result;
	}

	/**
	 * Source keys the target already has.
	 *
	 * @param Source         $target     Target.
	 * @param Url_Normalizer $normalizer Normaliser.
	 *
	 * @return array<string, true>
	 */
	private static function existing_sources( Source $target, Url_Normalizer $normalizer ): array {
		$keys = [];
		$rows = $target->count() + $target->skipped();
		for ( $offset = 0; $offset < $rows; $offset += self::PAGE_SIZE ) {
			foreach ( $target->rules( $offset, self::PAGE_SIZE ) as $rule ) {
				$keys[ $rule->is_exact() ? Url_Normalizer::fold( $normalizer->key( $rule->from ) ) : 're:' . $rule->regex( true ) ] = true;
			}
		}

		return $keys;
	}
}
