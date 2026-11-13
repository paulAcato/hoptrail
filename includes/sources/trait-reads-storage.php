<?php
/**
 * Shared helpers for adapters that read another plugin's storage.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

use Hoptrail\Includes\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Safe reads only: no plugin code is loaded. Raw serialised values this trait unserialises itself
 * never become objects. Options and post meta arrive already unserialised by WordPress core
 * (get_option / get_post_meta), as for every plugin; only values written through those APIs end up
 * there, which re-serialise what they store.
 */
trait Reads_Storage {

	/**
	 * Cached table-exists results.
	 *
	 * @var array<string, bool>
	 */
	private array $tables = [];

	/**
	 * Whether a table exists (cached per request).
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	protected function has_table( string $table ): bool {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = Installer::table_exists( $table );
		}

		return $this->tables[ $table ];
	}

	/**
	 * Unserialise a raw database value without ever instantiating objects (S4: no object injection).
	 *
	 * @param mixed $value Raw DB value.
	 *
	 * @return mixed
	 */
	protected static function safe_unserialize( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}

		// allowed_classes=false prevents object injection; @ because third-party data may be corrupt.
		$data = @unserialize( trim( $value ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $data ? null : $data;
	}

	/**
	 * Status codes that redirect somewhere (410 Gone / 451 have no target).
	 *
	 * @param int $code Status code.
	 *
	 * @return bool
	 */
	protected static function is_redirect_code( int $code ): bool {
		return $code >= 300 && $code < 400;
	}
}
