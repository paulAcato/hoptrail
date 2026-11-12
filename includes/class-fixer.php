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
	 * Apply the fix of an issue.
	 *
	 * @param int $issue_id Issue ID.
	 *
	 * @return array{action:string, changes:array[]} What was done.
	 *
	 * @throws \RuntimeException When there is nothing to fix, the rules changed, or a write failed.
	 */
	public static function apply( int $issue_id ): array {
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

		$changes = [];
		foreach ( $targets as [ $source, $rule ] ) {
			$before = $rule->to_array();

			if ( 'retarget' === $fix['action'] ) {
				$to = (string) $fix['to'];
				if ( '' === $to || ! $source->retarget( $rule->id, $to ) ) {
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

		return $fixed;
	}
}
