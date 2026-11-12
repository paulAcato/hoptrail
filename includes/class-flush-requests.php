<?php
/**
 * Log deletions requested by an AI client, waiting for an administrator's approval in wp-admin.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * The flush-logs ability can't delete on its own: a model sets `confirm` itself, and text it reads
 * (third-party redirect rules, post titles) may ask it to. So the ability only files a request; an
 * administrator approves or declines it on the dashboard, where the model has no hand.
 *
 * One request at a time, stored in an option, valid for an hour. A newer request replaces it.
 */
final class Flush_Requests {

	/**
	 * Option holding the pending request.
	 */
	const OPTION = 'redirect_inspector_flush_request';

	/**
	 * Seconds a request stays valid.
	 */
	const TTL = HOUR_IN_SECONDS;

	/**
	 * File a request (replaces a pending one).
	 *
	 * @param string $scope  history|changes|all.
	 * @param int    $scans   Scans it would delete (from the dry run).
	 * @param int    $changes Change-log entries it would delete.
	 *
	 * @return array{id:string, scope:string, scans:int, changes:int, user:int, created:int, expires:int}
	 */
	public static function create( string $scope, int $scans, int $changes ): array {
		$request = [
			'id'      => wp_generate_password( 20, false ),
			'scope'   => $scope,
			'scans'   => $scans,
			'changes' => $changes,
			'user'    => get_current_user_id(),
			'created' => time(),
			'expires' => time() + self::TTL,
		];
		update_option( self::OPTION, $request, false );
		Presence::changed( 'flush-request' );

		return $request;
	}

	/**
	 * The pending request, or null (none, or expired).
	 *
	 * @return array{id:string, scope:string, scans:int, changes:int, user:int, created:int, expires:int}|null
	 */
	public static function pending(): ?array {
		$request = get_option( self::OPTION );
		if ( ! is_array( $request ) || empty( $request['id'] ) ) {
			return null;
		}
		if ( (int) $request['expires'] < time() ) {
			delete_option( self::OPTION );
			return null;
		}

		return $request;
	}

	/**
	 * The pending request for the dashboard, with who asked (display name only).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function for_dashboard(): ?array {
		$request = self::pending();
		if ( ! $request ) {
			return null;
		}
		$user = get_userdata( (int) $request['user'] );

		return [
			'id'      => (string) $request['id'],
			'scope'   => (string) $request['scope'],
			'scans'   => (int) $request['scans'],
			'changes' => (int) $request['changes'],
			'by'      => $user ? $user->display_name : '',
			'created' => gmdate( 'c', (int) $request['created'] ),
			'expires' => gmdate( 'c', (int) $request['expires'] ),
		];
	}

	/**
	 * Approve: delete what the request names. Only the exact pending request (by ID) can be approved.
	 *
	 * @param string $id Request ID as shown on the dashboard.
	 *
	 * @return array{scans:int, changes:int}
	 *
	 * @throws \RuntimeException When there is no such request, or a scan or cleanup is running.
	 */
	public static function approve( string $id ): array {
		$request = self::pending();
		if ( ! $request || ! hash_equals( (string) $request['id'], $id ) ) {
			throw new \RuntimeException( __( 'This request is no longer waiting for approval.', 'redirect-inspector' ), 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$deleted = Retention::flush( (string) $request['scope'], false );
		delete_option( self::OPTION );

		return $deleted;
	}

	/**
	 * Decline: forget the request.
	 *
	 * @param string $id Request ID.
	 *
	 * @return bool Whether a matching request was removed.
	 */
	public static function decline( string $id ): bool {
		$request = self::pending();
		if ( ! $request || ! hash_equals( (string) $request['id'], $id ) ) {
			return false;
		}
		delete_option( self::OPTION );
		Presence::changed( 'flush-declined' );

		return true;
	}
}
