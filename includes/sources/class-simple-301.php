<?php
/**
 * Simple 301 Redirects (BetterLinks).
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `301_redirects` option (request ⇒ destination). With the wildcard setting on, a `*`
 * in the request becomes `(.*)` and a `*` in the destination becomes `$1`, exactly as the plugin
 * does it (unescaped, case-sensitive).
 */
final class Simple_301 implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'simple-301';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Simple 301 Redirects';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'SIMPLE301REDIRECTS_VERSION' ) || class_exists( 'Simple301redirects' );
	}

	/**
	 * Stored redirects.
	 *
	 * @return array<string, string>
	 */
	private function items(): array {
		$raw   = self::safe_unserialize( get_option( '301_redirects', [] ) );
		$items = [];

		foreach ( is_array( $raw ) ? $raw : [] as $from => $to ) {
			if ( is_scalar( $to ) && '' !== trim( (string) $from ) && '' !== trim( (string) $to ) ) {
				$items[ (string) $from ] = (string) $to;
			}
		}

		return $items;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		return count( $this->items() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		return 0;
	}

	/**
	 * Enabled rules, paged.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 *
	 * @return Rule[]
	 */
	public function rules( int $offset, int $limit ): array {
		$wildcard = 'true' === get_option( '301_redirects_wildcard' );
		$rules    = [];

		foreach ( array_slice( $this->items(), $offset, $limit, true ) as $from => $to ) {
			$is_wild = $wildcard && str_contains( $from, '*' );

			$rules[] = new Rule(
				source: $this->slug(),
				id: substr( md5( (string) $from ), 0, 12 ),
				from: $is_wild ? '^' . rtrim( str_replace( '*', '(.*)', $from ), '/' ) : $from,
				to: $is_wild ? str_replace( '*', '$1', $to ) : $to,
				code: 301,
				match: $is_wild ? Rule::MATCH_REGEX : Rule::MATCH_EXACT,
				leading_slash: true,
				case_sensitive: true,
				edit_url: admin_url( 'admin.php?page=301options' )
			);
		}

		return $rules;
	}

	/**
	 * Stored key of a rule ID.
	 *
	 * @param string $id Rule ID.
	 *
	 * @return string|null
	 */
	private function key_of( string $id ): ?string {
		foreach ( array_keys( $this->items() ) as $from ) {
			if ( substr( md5( (string) $from ), 0, 12 ) === $id ) {
				return (string) $from;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function find( string $id ): ?Rule {
		foreach ( $this->rules( 0, PHP_INT_MAX ) as $rule ) {
			if ( $rule->id === $id ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		$key = $this->key_of( $id );
		if ( null === $key ) {
			return false;
		}

		$items         = (array) get_option( '301_redirects', [] );
		$items[ $key ] = $to;

		return update_option( '301_redirects', $items );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Simple 301 Redirects has no "disabled" state, so the entry is removed.
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		$key = $this->key_of( $id );
		if ( null === $key ) {
			return '';
		}

		$items = (array) get_option( '301_redirects', [] );
		unset( $items[ $key ] );

		return update_option( '301_redirects', $items ) ? 'removed' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return [] !== $this->items();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Simple 301 Redirects holds exact rules only (its wildcard is a site-wide setting).
	 *
	 * @param Rule $rule Rule.
	 */
	public function supports( Rule $rule ): bool {
		return $rule->is_exact();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		if ( ! $rule->is_exact() ) {
			return false;
		}

		$items                  = (array) get_option( '301_redirects', [] );
		$items[ $rule->path() ] = $rule->to;

		return update_option( '301_redirects', $items );
	}

	/**
	 * {@inheritDoc}
	 */
	public function own_tables(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function delete_stored( string $id ): bool {
		return '' !== $this->disable( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Unused: Simple 301 Redirects keeps its redirects in options.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		unset( $drop_tables );

		delete_option( '301_redirects' );
		delete_option( '301_redirects_wildcard' );

		return true;
	}
}
