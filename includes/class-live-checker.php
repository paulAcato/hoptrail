<?php
/**
 * Follows real redirects hop by hop, on this site only.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * S2 (SSRF): every hop's host is checked against this site's hosts *before* it is requested;
 * other hosts are reported, never fetched. No cookies or auth headers are sent. Requests use
 * wp_safe_remote_request(); only for the duration of our own request, and only for this site's
 * hosts, `http_request_host_is_external` allows the (often local) site address.
 */
final class Live_Checker {

	/**
	 * Hops before we stop.
	 */
	const MAX_HOPS = 10;

	/**
	 * Seconds per request.
	 */
	const TIMEOUT = 5;

	/**
	 * URLs per call.
	 */
	const MAX_URLS = 20;

	/**
	 * Per-user mutex option prefix.
	 */
	const LOCK_PREFIX = 'redirect_inspector_live_lock_';

	/**
	 * Normaliser.
	 *
	 * @var Url_Normalizer
	 */
	private Url_Normalizer $normalizer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->normalizer = new Url_Normalizer();
	}

	/**
	 * Check several URLs (paths or absolute URLs on this site).
	 *
	 * @param string[] $urls URLs.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \RuntimeException When another live check by this user is running.
	 */
	public function check_many( array $urls ): array {
		$lock = self::LOCK_PREFIX . get_current_user_id();
		if ( ! Lock::acquire( $lock, 2 * MINUTE_IN_SECONDS ) ) {
			throw new \RuntimeException( __( 'A live check is already running.', 'redirect-inspector' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
		}

		add_filter( 'http_request_host_is_external', [ $this, 'allow_own_host' ], 10, 2 );

		try {
			$results = [];
			foreach ( array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_URLS ) as $url ) {
				$results[] = $this->check( (string) $url );
			}
			return $results;
		} finally {
			remove_filter( 'http_request_host_is_external', [ $this, 'allow_own_host' ], 10 );
			Lock::release( $lock );
		}
	}

	/**
	 * Let wp_safe_remote_* reach this site's own host (it may resolve to 127.0.0.1 locally).
	 *
	 * @param bool   $external Whether the host is considered external.
	 * @param string $host     Host.
	 *
	 * @return bool
	 */
	public function allow_own_host( $external, $host ) {
		return $external || $this->normalizer->is_internal_host( (string) $host );
	}

	/**
	 * Follow one URL.
	 *
	 * @param string $url Path or absolute URL.
	 *
	 * @return array{url:string, verdict:string, hops:array[], checked_at:string}
	 */
	public function check( string $url ): array {
		$current = $this->absolute( $url );
		$hops    = [];
		$seen    = [];
		$verdict = 'ok';
		$limited = true;

		for ( $steps = 0; $steps < self::MAX_HOPS; $steps = count( $hops ) ) {
			$host = (string) wp_parse_url( $current, PHP_URL_HOST );
			if ( ! $this->normalizer->is_internal_host( $host ) || ! in_array( wp_parse_url( $current, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
				$hops[]  = [
					'url'    => $current,
					'status' => null,
					'note'   => 'external',
				];
				$verdict = 'external';
				$limited = false;
				break;
			}

			if ( isset( $seen[ $current ] ) ) {
				$verdict = 'loop';
				$limited = false;
				break;
			}
			$seen[ $current ] = true;

			$response = $this->request( $current );
			if ( is_wp_error( $response ) ) {
				$hops[]  = [
					'url'    => $current,
					'status' => null,
					'note'   => $response->get_error_message(),
				];
				$verdict = 'error';
				$limited = false;
				break;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
			$hops[]   = [
				'url'    => $current,
				'status' => $status,
				'note'   => '',
			];

			if ( $status < 300 || $status >= 400 || '' === $location ) {
				$verdict = $status >= 400 ? 'broken' : 'ok';
				$limited = false;
				break;
			}

			$current = \WP_Http::make_absolute_url( $location, $current );
		}

		if ( $limited ) {
			$verdict = 'loop';
		}

		$redirects = count( array_filter( $hops, static fn( $hop ) => $hop['status'] >= 300 && $hop['status'] < 400 ) );
		if ( 'ok' === $verdict && $redirects > 1 ) {
			$verdict = 'chain';
		}

		return [
			'url'        => $this->absolute( $url ),
			'verdict'    => $verdict,
			'hops'       => $hops,
			'checked_at' => gmdate( 'c' ),
		];
	}

	/**
	 * HEAD, falling back to a tiny GET when HEAD isn't allowed.
	 *
	 * @param string $url URL.
	 *
	 * @return array|\WP_Error
	 */
	private function request( string $url ) {
		$args = [
			'method'              => 'HEAD',
			'redirection'         => 0,
			'timeout'             => self::TIMEOUT,
			'reject_unsafe_urls'  => true,
			'user-agent'          => 'RedirectInspector/' . REDIRECT_INSPECTOR_VERSION . '; ' . home_url( '/' ),
			'cookies'             => [],
			'headers'             => [],
			'limit_response_size' => 1024,
			'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
		];

		$response = wp_safe_remote_request( $url, $args );
		if ( ! is_wp_error( $response ) && 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$args['method'] = 'GET';
			$response       = wp_safe_remote_request( $url, $args );
		}

		return $response;
	}

	/**
	 * Absolute URL for a path or URL.
	 *
	 * @param string $url Path or URL.
	 *
	 * @return string
	 */
	private function absolute( string $url ): string {
		$url = trim( $url );

		return preg_match( '#^https?://#i', $url ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
	}
}
