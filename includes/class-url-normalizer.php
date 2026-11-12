<?php
/**
 * Turns the many ways plugins store URLs into one comparable key.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Key format: an internal path like `/About-us` (case kept, no trailing slash, decoded, query
 * sorted), or `ext:` + absolute URL for other hosts. Case is kept so case-sensitive rules and
 * regexes see the real URL; fold() gives the case-insensitive form for lookups and identities.
 */
final class Url_Normalizer {

	/**
	 * Hosts that count as "this site".
	 *
	 * @var string[]
	 */
	private array $hosts;

	/**
	 * Path of home_url() (WordPress in a sub-directory), without trailing slash.
	 *
	 * @var string
	 */
	private string $home_path;

	/**
	 * Constructor.
	 *
	 * @param string|null $home Home URL (defaults to home_url()).
	 * @param string|null $site Site URL (defaults to site_url()).
	 */
	public function __construct( ?string $home = null, ?string $site = null ) {
		$home = $home ?? home_url( '/' );
		$site = $site ?? site_url( '/' );

		$hosts = [];
		foreach ( [ $home, $site ] as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$hosts[] = $host;
				$hosts[] = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
			}
		}

		$this->hosts     = array_values( array_unique( $hosts ) );
		$this->home_path = rtrim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
	}

	/**
	 * Hosts treated as internal.
	 *
	 * @return string[]
	 */
	public function hosts(): array {
		return $this->hosts;
	}

	/**
	 * Whether a host belongs to this site.
	 *
	 * @param string $host Host.
	 *
	 * @return bool
	 */
	public function is_internal_host( string $host ): bool {
		return in_array( strtolower( $host ), $this->hosts, true );
	}

	/**
	 * Normalise a stored URL/path into a key.
	 *
	 * @param string $url Raw URL or path.
	 *
	 * @return string
	 */
	public function key( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );

		if ( '' === $url ) {
			return '/';
		}

		// Protocol-relative.
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return '/' . ltrim( $url, '/' );
		}

		if ( ! empty( $parts['host'] ) && ! $this->is_internal_host( $parts['host'] ) ) {
			$scheme = strtolower( $parts['scheme'] ?? 'https' );
			$path   = rtrim( $parts['path'] ?? '', '/' );

			return 'ext:' . $scheme . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		$path = '/' . ltrim( rawurldecode( $parts['path'] ?? '' ), '/' );

		// Absolute URLs on this host include the sub-directory WordPress lives in; strip it.
		if ( ! empty( $parts['host'] ) && '' !== $this->home_path && str_starts_with( $path, $this->home_path . '/' ) ) {
			$path = substr( $path, strlen( $this->home_path ) );
		}

		$path = preg_replace( '#/{2,}#', '/', $path ) ?? $path;
		$path = '/' === $path ? '/' : rtrim( $path, '/' );

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( $query ) {
				ksort( $query );
				$path .= '?' . http_build_query( $query );
			}
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Whether a redirect target may be written into another plugin's storage: an http(s) URL or a
	 * path, without whitespace, control characters or markup characters.
	 *
	 * @param string $target Target.
	 *
	 * @return bool
	 */
	public static function is_safe_target( string $target ): bool {
		if ( '' === $target || strlen( $target ) > 2048 || preg_match( '/[\s<>"\'`\\\\]|\p{Cc}/u', $target ) ) {
			return false;
		}

		return (bool) preg_match( '~^(https?://[^/?#]+(?:[/?#].*)?|/(?!/).*)$~i', $target );
	}

	/**
	 * Case-insensitive form of a key.
	 *
	 * @param string $key Key.
	 *
	 * @return string
	 */
	public static function fold( string $key ): string {
		return strtolower( $key );
	}

	/**
	 * Whether a key is outside this site.
	 *
	 * @param string $key Normalised key.
	 *
	 * @return bool
	 */
	public static function is_external_key( string $key ): bool {
		return str_starts_with( $key, 'ext:' );
	}

	/**
	 * Absolute URL for an internal key (for live checks and links).
	 *
	 * @param string $key Normalised key.
	 *
	 * @return string
	 */
	public function to_url( string $key ): string {
		return self::is_external_key( $key ) ? substr( $key, 4 ) : home_url( $key );
	}

	/**
	 * Path a plugin's pattern is matched against.
	 *
	 * @param string $key           Normalised key.
	 * @param bool   $leading_slash Whether the plugin includes the leading slash.
	 *
	 * @return string
	 */
	public static function subject( string $key, bool $leading_slash ): string {
		return $leading_slash ? $key : ltrim( $key, '/' );
	}
}
