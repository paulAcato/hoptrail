<?php
/**
 * Manifest-aware enqueueing of the Vite build.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads dist/.vite/manifest.json and enqueues the hashed files. React and @wordpress/* come from
 * WordPress itself (externals), so the dependencies below must match vite.config.ts.
 */
final class Assets {

	/**
	 * Script/style handle.
	 */
	const HANDLE = 'redirect-inspector';

	/**
	 * Vite entry.
	 */
	const ENTRY = 'assets/scripts/main.tsx';

	/**
	 * WordPress script dependencies (externals in vite.config.ts).
	 */
	const DEPENDENCIES = [ 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-a11y', 'wp-primitives', 'wp-date' ];

	/**
	 * Enqueue the app.
	 *
	 * @return bool Whether the build was found.
	 */
	public static function enqueue_app(): bool {
		$manifest = self::manifest();
		$entry    = $manifest[ self::ENTRY ] ?? null;

		if ( ! $entry || empty( $entry['file'] ) ) {
			return false;
		}

		wp_enqueue_script( self::HANDLE, self::url( $entry['file'] ), self::DEPENDENCIES, null, [ 'in_footer' => true ] ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Hashed filename is the cache-buster.
		wp_set_script_translations( self::HANDLE, 'redirect-inspector', REDIRECT_INSPECTOR_PATH . 'languages' );

		wp_enqueue_style( 'wp-components' );
		// IIFE builds list the extracted stylesheet under `style.css` instead of on the entry.
		$styles = (array) ( $entry['css'] ?? [] );
		if ( ! $styles && ! empty( $manifest['style.css']['file'] ) ) {
			$styles[] = $manifest['style.css']['file'];
		}

		foreach ( $styles as $index => $css ) {
			wp_enqueue_style( self::HANDLE . ( $index ? '-' . $index : '' ), self::url( $css ), [ 'wp-components' ], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Hashed filename is the cache-buster.
		}

		return true;
	}

	/**
	 * Decoded manifest.
	 *
	 * @return array
	 */
	private static function manifest(): array {
		$path = REDIRECT_INSPECTOR_PATH . 'dist/.vite/manifest.json';
		if ( ! is_readable( $path ) ) {
			return [];
		}

		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Public URL of a built file.
	 *
	 * @param string $file Path relative to dist/.
	 *
	 * @return string
	 */
	private static function url( string $file ): string {
		return REDIRECT_INSPECTOR_URL . 'dist/' . ltrim( $file, '/' );
	}
}
