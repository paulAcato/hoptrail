<?php
/**
 * Who else has the dashboard open, and what changed: through the WordPress Heartbeat API.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Every Heartbeat tick of an open dashboard marks its user as present (for 60 seconds) and
 * returns the other administrators present plus the latest change version. Any change — scan,
 * fix, migration, deletion, settings, flush — bumps that version, so other open dashboards reload
 * their data. Heartbeat verifies the nonce; the capability is checked here.
 */
final class Presence extends Singleton {

	/**
	 * Transient with user ID ⇒ last seen (per site).
	 */
	const PRESENCE = 'hoptrail_presence';

	/**
	 * Option with the change version and the latest change.
	 */
	const STATE = 'hoptrail_state';

	/**
	 * Seconds a user counts as present after their last tick.
	 */
	const TTL = 60;

	/**
	 * Heartbeat key.
	 */
	const KEY = 'hoptrail';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_filter( 'heartbeat_received', [ self::class, 'received' ], 10, 2 );
	}

	/**
	 * Record a change (called by every write path).
	 *
	 * @param string $action scan|scheduled-scan|fix|migrate|delete|purge|settings|flush.
	 *
	 * @return void
	 */
	public static function changed( string $action ): void {
		$state = self::state();

		update_option(
			self::STATE,
			[
				'version' => $state['version'] + 1,
				'action'  => sanitize_key( $action ),
				'by'      => get_current_user_id(),
				'at'      => time(),
			],
			false
		);
	}

	/**
	 * The stored state.
	 *
	 * @return array{version:int, action:string, by:int, at:int}
	 */
	private static function state(): array {
		$state = get_option( self::STATE, [] );
		$state = is_array( $state ) ? $state : [];

		return [
			'version' => (int) ( $state['version'] ?? 0 ),
			'action'  => (string) ( $state['action'] ?? '' ),
			'by'      => (int) ( $state['by'] ?? 0 ),
			'at'      => (int) ( $state['at'] ?? 0 ),
		];
	}

	/**
	 * Heartbeat: mark presence, answer with the others and the change state.
	 *
	 * @param array<string, mixed> $response Heartbeat response.
	 * @param array<string, mixed> $data     Data sent by the client.
	 *
	 * @return array<string, mixed>
	 */
	public static function received( $response, $data ): array {
		$response = (array) $response;

		if ( empty( $data[ self::KEY ] ) || ! Caps::can_manage() ) {
			return $response;
		}

		$me      = get_current_user_id();
		$now     = time();
		$present = get_transient( self::PRESENCE );
		$present = is_array( $present ) ? array_filter( $present, static fn( $seen ) => (int) $seen > $now - self::TTL ) : [];

		$present[ $me ] = $now;
		set_transient( self::PRESENCE, $present, self::TTL * 2 );

		$others = [];
		foreach ( array_keys( $present ) as $user_id ) {
			$user = (int) $user_id !== $me ? get_userdata( (int) $user_id ) : false;
			if ( $user && user_can( $user, Caps::MANAGE ) ) {
				$others[] = [
					'id'     => (int) $user->ID,
					'name'   => $user->display_name,
					'avatar' => (string) get_avatar_url( $user->ID, [ 'size' => 48 ] ),
				];
			}
		}

		$state = self::state();
		$by    = $state['by'] ? get_userdata( $state['by'] ) : false;
		// Named only while that person may still use the dashboard, like the list of others above.
		$by = $by && user_can( $by, Caps::MANAGE ) ? $by : false;

		$response[ self::KEY ] = [
			'others'  => $others,
			'version' => $state['version'],
			'change'  => [
				'action' => $state['action'],
				'by'     => $by ? $by->display_name : '',
				'self'   => $state['by'] === $me,
				'at'     => $state['at'] ? gmdate( 'c', $state['at'] ) : null,
			],
		];

		return $response;
	}

	/**
	 * Current change version (for the first page load).
	 *
	 * @return int
	 */
	public static function version(): int {
		return self::state()['version'];
	}
}
