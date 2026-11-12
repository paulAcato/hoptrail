<?php
/**
 * Shared helpers for adapters that read another plugin's storage.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Safe reads only: no plugin code is loaded, no objects are ever unserialised.
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
	 * Unserialise without ever instantiating objects (S4: no object injection).
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
