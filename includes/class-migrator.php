<?php
/**
 * Copies leftover rules from an inactive redirect plugin into an active one.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

use Hoptrail\Includes\Analysis\Expander;
use Hoptrail\Includes\Sources\Purgeable;
use Hoptrail\Includes\Sources\Registry;
use Hoptrail\Includes\Sources\Source;
use Hoptrail\Includes\Sources\Writable;

defined( 'ABSPATH' ) || exit;

/**
 * Copy, never move: the inactive plugin's data is left as it is. Rules the target already has
 * (same source URL or pattern) and rules it can't express are skipped. Each copy is logged.
 */
final class Migrator {

	/**
	 * Seconds one migration (or its preview) may spend expanding patterns, over all rules together.
	 */
	const EXPANSION_BUDGET = 20;

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
			if ( ! $source->plugin_active() && $source->has_storage() ) {
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
	 * @param string $plan    The plan the administrator reviewed (from preview()); when given, a real
	 *                        run refuses if what it would write differs ('' = no check).
	 *
	 * @return array{created:int, existing:int, unsupported:int, failed:int, expanded:int, partial:bool, plan:string, dry_run:bool}
	 *
	 * @throws \RuntimeException When the source or target isn't eligible, or the plan changed.
	 */
	public static function run( string $from, string $to, bool $dry_run, string $plan = '' ): array {
		$sources = Registry::all();
		$source  = $sources[ $from ] ?? null;
		$target  = $sources[ $to ] ?? null;

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text (JSON/React/CLI).
		if ( ! $source instanceof Source || $source->plugin_active() || ! $source->has_storage() ) {
			throw new \RuntimeException( __( 'There are no leftover rules to migrate from that plugin.', 'hoptrail' ), 400 );
		}
		if ( ! $target instanceof Writable || ! $target instanceof Source || ! $target->is_available() || $from === $to ) {
			throw new \RuntimeException( __( 'Rules can only be migrated into an active redirect plugin.', 'hoptrail' ), 400 );
		}
		// phpcs:enable

		if ( ! $dry_run ) {
			$result = self::locked(
				static function () use ( $source, $target, $from, $to, $plan ) {
					// What was reviewed is what gets written: the 404 log and other plugins' rules
					// may have changed since the preview.
					if ( '' !== $plan && ! hash_equals( $plan, self::copy( $source, $target, $from, $to, true )['plan'] ) ) {
						throw new \RuntimeException( __( 'The rules to migrate changed since you reviewed them. Review the list again.', 'hoptrail' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					}
					return self::copy( $source, $target, $from, $to, false );
				}
			);
			Presence::changed( 'migrate' );
			return $result;
		}

		return self::copy( $source, $target, $from, $to, true );
	}

	/**
	 * Run a write while holding the shared write lock, never during a scan.
	 *
	 * @param callable $write Write.
	 *
	 * @return mixed
	 *
	 * @throws \RuntimeException When another write or a scan is running.
	 */
	private static function locked( callable $write ) {
		if ( Scanner::is_running() || ! Lock::acquire( Fixer::LOCK, 2 * MINUTE_IN_SECONDS ) ) {
			throw new \RuntimeException( __( 'Another change or a scan is running. Try again in a moment.', 'hoptrail' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			return $write();
		} finally {
			Lock::release( Fixer::LOCK );
		}
	}

	/**
	 * Copy (or count) leftover rules.
	 *
	 * @param Source           $source  Source.
	 * @param Source &Writable $target  Target.
	 * @param string           $from    Source slug.
	 * @param string           $to      Target slug.
	 * @param bool             $dry_run Only count.
	 *
	 * @return array{created:int, existing:int, unsupported:int, failed:int, expanded:int, partial:bool, plan:string, dry_run:bool}
	 */
	private static function copy( Source $source, $target, string $from, string $to, bool $dry_run ): array {
		$normalizer = new Url_Normalizer();
		$existing   = self::existing_sources( $target, $normalizer );
		$expander   = new Expander( $normalizer, microtime( true ) + self::EXPANSION_BUDGET );
		$planned    = [];
		$result     = [
			'created'     => 0,
			'existing'    => 0,
			'unsupported' => 0,
			'failed'      => 0,
			'expanded'    => 0,
			'partial'     => false,
			'plan'        => '',
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
				// An exact source is written as-is: it must be a plain path, like the target.
				if ( $rule->is_exact() && ! Url_Normalizer::is_safe_source( $rule->path() ) ) {
					++$result['unsupported'];
					continue;
				}
				if ( ! $target->supports( $rule ) || ! Url_Normalizer::is_safe_target( $rule->to ) ) {
					// A pattern the target can't hold: copy it as exact rules for the URLs it redirects.
					$exact = self::expandable( $target, $rule ) ? self::fresh( $expander->expand( $rule ), $existing, $normalizer ) : [];
					if ( ! $exact ) {
						++$result['unsupported'];
						continue;
					}
					++$result['expanded'];
					foreach ( $exact as $key_exact => $one ) {
						$planned[] = [ $one->from, $one->to, $one->code ];
						if ( $dry_run || $target->create( $one ) ) {
							++$result['created'];
							$existing[ $key_exact ] = true;
							if ( ! $dry_run ) {
								Log_Repository::log_fix(
									0,
									'migrated',
									$to,
									'',
									array_merge(
										$one->to_array(),
										[
											'from_plugin' => $from,
											'expanded_from' => $rule->from,
										]
									),
									[]
								);
							}
						} else {
							++$result['failed'];
						}
					}
					continue;
				}
				$planned[] = [ $rule->from, $rule->to, $rule->code ];
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

		$result['partial'] = $expander->partial;
		$result['plan']    = hash( 'sha256', $to . "\n" . wp_json_encode( $planned ) );

		return $result;
	}

	/**
	 * Every leftover rule with what migrating it into $to would do: copy, exists or unsupported.
	 *
	 * @param string $from Source slug (inactive, with storage).
	 * @param string $to   Target slug ('' = no target, only list).
	 *
	 * @return array{rules: array<int, array<string, mixed>>, total: int, plan: string, partial: bool}
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
		$expander   = new Expander( $normalizer, microtime( true ) + self::EXPANSION_BUDGET );
		$rules      = [];

		$rows = $source->count() + $source->skipped();
		// At most 1000 rules in the preview; the total is reported separately.
		$rows = min( $rows, 1000 );
		for ( $offset = 0; $offset < $rows; $offset += self::PAGE_SIZE ) {
			foreach ( $source->rules( $offset, self::PAGE_SIZE ) as $rule ) {
				$key      = $rule->is_exact() ? Url_Normalizer::fold( $normalizer->key( $rule->from ) ) : 're:' . $rule->regex( true );
				$status   = 'none';
				$expanded = [];
				if ( $target ) {
					$status = isset( $existing[ $key ] ) ? 'exists' : ( $target->supports( $rule ) && Url_Normalizer::is_safe_target( $rule->to ) && ( ! $rule->is_exact() || Url_Normalizer::is_safe_source( $rule->path() ) ) ? 'copy' : 'unsupported' );
					if ( 'unsupported' === $status && self::expandable( $target, $rule ) ) {
						$expanded = array_values( self::fresh( $expander->expand( $rule ), $existing, $normalizer ) );
						$status   = $expanded ? 'expand' : 'unsupported';
					}
				}
				$rules[] = [
					'id'       => $rule->id,
					'from'     => $rule->from,
					'to'       => $rule->to,
					'code'     => $rule->code,
					'match'    => $rule->match,
					'status'   => $status,
					'expanded' => array_map(
						static fn( Rule $one ) => [
							'from' => $one->from,
							'to'   => $one->to,
						],
						array_slice( $expanded, 0, 50 )
					),
					'expands'  => count( $expanded ),
				];
			}
		}

		// The plan the administrator reviews: the real run refuses when it would write anything else.
		$plan = $target ? self::copy( $source, $target, $from, $to, true ) : null;

		return [
			'rules'   => $rules,
			'total'   => $source->count(),
			'plan'    => $plan ? $plan['plan'] : '',
			'partial' => $expander->partial || ( $plan && $plan['partial'] ),
		];
	}

	/**
	 * Delete one leftover rule (logged with its old value).
	 *
	 * @param string $from     Source slug.
	 * @param string $id       Rule ID.
	 * @param string $expected The rule's source as the user saw it; must still match (IDs can shift).
	 *
	 * @return bool
	 *
	 * @throws \RuntimeException When the source isn't a leftover or the rule is gone or changed.
	 */
	public static function delete_rule( string $from, string $id, string $expected ): bool {
		$deleted = (bool) self::locked( static fn() => self::delete_rule_locked( $from, $id, $expected ) );
		Presence::changed( 'delete' );

		return $deleted;
	}

	/**
	 * Delete one leftover rule while holding the write lock.
	 *
	 * @param string $from     Source slug.
	 * @param string $id       Rule ID.
	 * @param string $expected Expected source.
	 *
	 * @return bool
	 *
	 * @throws \RuntimeException See delete_rule().
	 */
	private static function delete_rule_locked( string $from, string $id, string $expected ): bool {
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
		if ( ! $rule || $rule->from !== $expected || ! $source instanceof Purgeable || ! $source->delete_stored( $id ) ) {
			throw new \RuntimeException( __( 'The rule could not be deleted. It may already be gone.', 'hoptrail' ), 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
		$deleted = (int) self::locked( static fn() => self::purge_locked( $from, $drop_tables ) );
		Presence::changed( 'purge' );

		return $deleted;
	}

	/**
	 * Purge while holding the write lock.
	 *
	 * @param string $from        Source slug.
	 * @param bool   $drop_tables Drop the plugin's tables too.
	 *
	 * @return int
	 *
	 * @throws \RuntimeException See purge().
	 */
	private static function purge_locked( string $from, bool $drop_tables ): int {
		$source = self::leftover( $from );
		$count  = $source->count();

		if ( ! $source instanceof Purgeable || ! $source->purge_storage( $drop_tables && [] !== $source->own_tables() ) ) {
			throw new \RuntimeException( __( 'The data could not be deleted.', 'hoptrail' ), 500 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
		// The plugin itself must be inactive: a plugin with only its redirect feature switched off
		// still owns its data, and must never have it deleted from here.
		if ( ! $source instanceof Source || $source->plugin_active() || ! $source->has_storage() ) {
			throw new \RuntimeException( __( 'There are no leftover rules for that plugin.', 'hoptrail' ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $source;
	}

	/**
	 * Whether a pattern rule may be copied as exact rules into the target.
	 *
	 * @param Writable $target Target.
	 * @param Rule     $rule   Rule.
	 *
	 * @return bool
	 */
	private static function expandable( Writable $target, Rule $rule ): bool {
		return ! $rule->is_exact() && $target->supports( new Rule( $rule->source, $rule->id, '/x', '/y' ) );
	}

	/**
	 * Expanded rules whose source the target doesn't have yet, by folded key.
	 *
	 * @param Rule[]              $rules      Exact rules.
	 * @param array<string, true> $existing   Keys the target has.
	 * @param Url_Normalizer      $normalizer Normaliser.
	 *
	 * @return array<string, Rule>
	 */
	private static function fresh( array $rules, array $existing, Url_Normalizer $normalizer ): array {
		$fresh = [];
		foreach ( $rules as $rule ) {
			$key = Url_Normalizer::fold( $normalizer->key( $rule->from ) );
			if ( ! isset( $existing[ $key ] ) ) {
				$fresh[ $key ] = $rule;
			}
		}

		return $fresh;
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
