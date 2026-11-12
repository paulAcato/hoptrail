<?php
/**
 * Applies an issue's automatic fix to the plugin that owns the rule.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Includes\Sources\Registry;
use Acato\RedirectInspector\Includes\Sources\Writable;

defined( 'ABSPATH' ) || exit;

/**
 * Every rule is re-read and compared with what the scan saw before anything is written: a rule
 * that changed (or disappeared) since the scan refuses the whole fix. Each change is logged.
 */
final class Fixer {

	/**
	 * One write at a time: fixes, migrations and deletions share this lock (also used by Migrator).
	 */
	const LOCK = 'redirect_inspector_write_lock';

	/**
	 * Apply the fix of an issue.
	 *
	 * @param int $issue_id Issue ID.
	 *
	 * @return array{action:string, changes:array[]} What was done.
	 *
	 * @throws \RuntimeException When there is nothing to fix, the rules changed, or a write failed.
	 */
	public static function apply( int $issue_id ): array {
		if ( Scanner::is_running() || ! Lock::acquire( self::LOCK, 2 * MINUTE_IN_SECONDS ) ) {
			throw new \RuntimeException( __( 'Another change or a scan is running. Try again in a moment.', 'redirect-inspector' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			return self::apply_locked( $issue_id );
		} finally {
			Lock::release( self::LOCK );
		}
	}

	/**
	 * Apply the fix while holding the write lock (the "already applied" check included).
	 *
	 * @param int $issue_id Issue ID.
	 *
	 * @return array{action:string, changes:array[]}
	 *
	 * @throws \RuntimeException See apply().
	 */
	private static function apply_locked( int $issue_id ): array {
		$issue = Log_Repository::issue( $issue_id );
		$fix   = $issue['fix'] ?? null;

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are shown as text (JSON/React).
		if ( ! $issue || ! is_array( $fix ) || empty( $fix['rules'] ) || ! in_array( $fix['action'] ?? '', [ 'retarget', 'disable' ], true ) ) {
			throw new \RuntimeException( __( 'This issue has no automatic fix.', 'redirect-inspector' ), 400 );
		}

		if ( ! empty( $issue['fixed'] ) ) {
			throw new \RuntimeException( __( 'This fix was already applied.', 'redirect-inspector' ), 409 );
		}

		$sources = Registry::all();
		$targets = [];

		// Check everything first, so a stale scan changes nothing.
		foreach ( $fix['rules'] as $ref ) {
			$source = $sources[ $ref['source'] ?? '' ] ?? null;
			if ( ! $source instanceof Writable || ! $source->is_available() ) {
				throw new \RuntimeException( __( 'The plugin that owns this rule is not active.', 'redirect-inspector' ), 409 );
			}

			$current = $source->find( (string) $ref['id'] );
			if ( ! $current || $current->from !== $ref['from'] || $current->to !== $ref['to'] ) {
				throw new \RuntimeException( __( 'The rule changed since the last scan. Run a new scan first.', 'redirect-inspector' ), 409 );
			}

			$targets[] = [ $source, $current ];
		}

		// Rules the fix relies on without changing them (the later hops of a chain, the duplicate
		// that stays) must still be as scanned, where their plugin lets us read one rule back.
		foreach ( (array) ( $fix['requires'] ?? [] ) as $ref ) {
			$source = $sources[ $ref['source'] ?? '' ] ?? null;
			if ( ! $source instanceof Writable || ! $source->is_available() ) {
				continue;
			}
			$current = $source->find( (string) $ref['id'] );
			if ( ! $current || $current->from !== $ref['from'] || $current->to !== $ref['to'] ) {
				throw new \RuntimeException( __( 'A rule this fix depends on changed since the last scan. Run a new scan first.', 'redirect-inspector' ), 409 );
			}
		}

		$changes = [];
		foreach ( $targets as [ $source, $rule ] ) {
			$before = $rule->to_array();

			if ( 'retarget' === $fix['action'] ) {
				$to = (string) $fix['to'];
				if ( ! Url_Normalizer::is_safe_target( $to ) || ! $source->retarget( $rule->id, $to ) ) {
					throw new \RuntimeException( __( 'The rule could not be updated.', 'redirect-inspector' ), 500 );
				}
				$result = 'retargeted';
				$after  = array_merge( $before, [ 'to' => $to ] );
			} else {
				$result = $source->disable( $rule->id );
				if ( '' === $result ) {
					throw new \RuntimeException( __( 'The rule could not be switched off.', 'redirect-inspector' ), 500 );
				}
				$after = [];
			}
			// phpcs:enable

			Log_Repository::log_fix( $issue_id, $result, $rule->source, $rule->id, $before, $after );
			$changes[] = [
				'source' => $rule->source,
				'id'     => $rule->id,
				'from'   => $rule->from,
				'result' => $result,
			];
		}

		$user  = wp_get_current_user();
		$fixed = [
			'action'  => (string) $fix['action'],
			'changes' => $changes,
			'by'      => $user->display_name,
			'at'      => gmdate( 'c' ),
		];
		Log_Repository::mark_fixed( $issue_id, $fixed );
		Presence::changed( 'fix' );

		return $fixed;
	}
}
