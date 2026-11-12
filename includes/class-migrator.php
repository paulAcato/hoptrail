<?php
/**
 * Copies leftover rules from an inactive redirect plugin into an active one.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Includes\Sources\Purgeable;
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
					'slug'   => $slug,
					'label'  => $source->label(),
					'rules'  => $source->count(),
					'tables' => $source instanceof Purgeable ? count( $source->own_tables() ) : 0,
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
	 * Every leftover rule with what migrating it into $to would do: copy, exists or unsupported.
	 *
	 * @param string $from Source slug (inactive, with storage).
	 * @param string $to   Target slug ('' = no target, only list).
	 *
	 * @return array{rules: array<int, array<string, mixed>>, total: int}
	 *
	 * @throws \RuntimeException When the source isn't a leftover.
	 */
	public static function preview( string $from, string $to ): array {
		$source = self::leftover( $from );
		$all    = Registry::all();
		$target = $all[ $to ] ?? null;
		$target = $target instanceof Writable && $target instanceof Source && $target->is_available() ? $target : null;

		$normalizer = new Url_Normalizer();
		$existing   = $target ? self::existing_sources( $target, $normalizer ) : [];
		$rules      = [];

		$rows = $source->count() + $source->skipped();
		// At most 1000 rules in the preview; the total is reported separately.
		$rows = min( $rows, 1000 );
		for ( $offset = 0; $offset < $rows; $offset += self::PAGE_SIZE ) {
			foreach ( $source->rules( $offset, self::PAGE_SIZE ) as $rule ) {
				$key    = $rule->is_exact() ? Url_Normalizer::fold( $normalizer->key( $rule->from ) ) : 're:' . $rule->regex( true );
				$status = 'none';
				if ( $target ) {
					$status = isset( $existing[ $key ] ) ? 'exists' : ( $target->supports( $rule ) ? 'copy' : 'unsupported' );
				}
				$rules[] = [
					'id'     => $rule->id,
					'from'   => $rule->from,
					'to'     => $rule->to,
					'code'   => $rule->code,
					'match'  => $rule->match,
					'status' => $status,
				];
			}
		}

		return [
			'rules' => $rules,
			'total' => $source->count(),
		];
	}

	/**
	 * Delete one leftover rule (logged with its old value).
	 *
	 * @param string $from Source slug.
	 * @param string $id   Rule ID.
	 *
	 * @return bool
	 *
	 * @throws \RuntimeException When the source isn't a leftover or the rule is gone.
	 */
	public static function delete_rule( string $from, string $id ): bool {
		$source = self::leftover( $from );
		$rule   = null;

		$rows = $source->count() + $source->skipped();
		for ( $offset = 0; $offset < $rows && ! $rule; $offset += self::PAGE_SIZE ) {
			foreach ( $source->rules( $offset, self::PAGE_SIZE ) as $candidate ) {
				if ( $candidate->id === $id ) {
					$rule = $candidate;
					break;
				}
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
		if ( ! $rule || ! $source instanceof Purgeable || ! $source->delete_stored( $id ) ) {
			throw new \RuntimeException( __( 'The rule could not be deleted. It may already be gone.', 'redirect-inspector' ), 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Log_Repository::log_fix( 0, 'deleted', $from, $id, $rule->to_array(), [] );

		return true;
	}

	/**
	 * Delete all leftover rules of a plugin; optionally drop its own tables (logged).
	 *
	 * @param string $from        Source slug.
	 * @param bool   $drop_tables Drop the plugin's redirect tables too.
	 *
	 * @return int Rules deleted.
	 *
	 * @throws \RuntimeException When the source isn't a leftover.
	 */
	public static function purge( string $from, bool $drop_tables ): int {
		$source = self::leftover( $from );
		$count  = $source->count();

		if ( ! $source instanceof Purgeable || ! $source->purge_storage( $drop_tables && [] !== $source->own_tables() ) ) {
			throw new \RuntimeException( __( 'The data could not be deleted.', 'redirect-inspector' ), 500 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Log_Repository::log_fix( 0, $drop_tables ? 'dropped' : 'purged', $from, '', [ 'rules' => $count ], [] );

		return $count;
	}

	/**
	 * A leftover source (inactive plugin with stored rules), or an exception.
	 *
	 * @param string $from Source slug.
	 *
	 * @return Source
	 *
	 * @throws \RuntimeException When it isn't one.
	 */
	private static function leftover( string $from ): Source {
		$source = Registry::all()[ $from ] ?? null;
		if ( ! $source instanceof Source || $source->is_available() || ! $source->has_storage() ) {
			throw new \RuntimeException( __( 'There are no leftover rules for that plugin.', 'redirect-inspector' ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $source;
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
