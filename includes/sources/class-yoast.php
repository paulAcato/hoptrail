<?php
/**
 * Yoast SEO Premium redirect manager.
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

use Hoptrail\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the option Premium keeps its redirects in: a list of { origin, url, type, format }.
 * Origins are stored without leading slash; regex origins are matched against that slash-less path.
 */
final class Yoast implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * Option name used by Yoast SEO Premium (WPSEO_Redirect_Option::OPTION).
	 */
	const OPTION = 'wpseo-premium-redirects-base';

	/**
	 * Decoded option, cached.
	 *
	 * @var array|null
	 */
	private ?array $items = null;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Yoast SEO Premium';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->plugin_active() && [] !== $this->items();
	}

	/**
	 * Valid redirect items.
	 *
	 * @return array<int, array{origin:string, url:string, type:int, format:string}>
	 */
	private function items(): array {
		if ( null !== $this->items ) {
			return $this->items;
		}

		$raw   = self::safe_unserialize( get_option( self::OPTION, [] ) );
		$items = [];

		foreach ( is_array( $raw ) ? $raw : [] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['origin'], $item['url'] ) || ! is_scalar( $item['origin'] ) || ! is_scalar( $item['url'] ) ) {
				continue;
			}
			$items[] = [
				'origin' => (string) $item['origin'],
				'url'    => (string) $item['url'],
				'type'   => (int) ( $item['type'] ?? 301 ),
				'format' => 'regex' === ( $item['format'] ?? 'plain' ) ? 'regex' : 'plain',
			];
		}

		$this->items = $items;

		return $items;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		return count( array_filter( $this->items(), static fn( $item ) => self::is_redirect_code( $item['type'] ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		return count( $this->items() ) - $this->count();
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
		$rules = [];

		foreach ( array_slice( $this->items(), $offset, $limit ) as $item ) {
			if ( ! self::is_redirect_code( $item['type'] ) ) {
				continue;
			}

			$regex   = 'regex' === $item['format'];
			$rules[] = new Rule(
				source: $this->slug(),
				id: substr( md5( $item['format'] . '|' . $item['origin'] ), 0, 12 ),
				from: $regex ? $item['origin'] : '/' . ltrim( $item['origin'], '/' ),
				to: $item['url'],
				code: $item['type'],
				match: $regex ? Rule::MATCH_REGEX : Rule::MATCH_EXACT,
				leading_slash: false,
				case_sensitive: false,
				edit_url: admin_url( 'admin.php?page=wpseo_redirects' )
			);
		}

		return $rules;
	}

	/**
	 * Index of a rule ID in the stored list.
	 *
	 * @param string $id Rule ID.
	 *
	 * @return int|null
	 */
	private function index_of( string $id ): ?int {
		$raw = self::safe_unserialize( get_option( self::OPTION, [] ) );
		foreach ( is_array( $raw ) ? $raw : [] as $index => $item ) {
			if ( is_array( $item ) && isset( $item['origin'] ) && substr( md5( ( 'regex' === ( $item['format'] ?? 'plain' ) ? 'regex' : 'plain' ) . '|' . $item['origin'] ), 0, 12 ) === $id ) {
				return (int) $index;
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
		$this->items = null;
		foreach ( $this->rules( 0, PHP_INT_MAX ) as $rule ) {
			if ( $rule->id === $id ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Write the stored list back. With Yoast SEO Premium active its own manager does it, so the
	 * files and export options it redirects from are regenerated too.
	 *
	 * @param callable $change Receives and returns the list.
	 *
	 * @return bool
	 */
	private function change( callable $change ): bool {
		$raw  = self::safe_unserialize( get_option( self::OPTION, [] ) );
		$list = $change( is_array( $raw ) ? array_values( $raw ) : [] );

		$this->items = null;

		$premium = 'WPSEO_Redirect_Option';
		if ( class_exists( $premium ) ) {
			$option = new $premium();
			if ( method_exists( $option, 'set' ) && method_exists( $option, 'save' ) ) {
				$option->set( $list );
				$option->save();
				return true;
			}
		}

		return update_option( self::OPTION, $list, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		$index = $this->index_of( $id );
		if ( null === $index ) {
			return false;
		}

		// Yoast stores targets without the leading slash.
		return $this->change(
			static function ( array $entries ) use ( $index, $to ) {
				$entries[ $index ]['url'] = preg_match( '#^https?://#i', $to ) ? $to : ltrim( $to, '/' );
				return $entries;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Yoast has no "disabled" state, so the entry is removed (its old value is in the fix log).
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		$index = $this->index_of( $id );
		if ( null === $index ) {
			return '';
		}

		$ok = $this->change(
			static function ( array $entries ) use ( $index ) {
				unset( $entries[ $index ] );
				return array_values( $entries );
			}
		);

		return $ok ? 'removed' : '';
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
	 * @param Rule $rule Rule.
	 */
	public function supports( Rule $rule ): bool {
		unset( $rule );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		$regex = ! $rule->is_exact();
		$entry = [
			'origin' => $regex ? $rule->regex( false ) : ltrim( $rule->path(), '/' ),
			'url'    => preg_match( '#^https?://#i', $rule->to ) ? $rule->to : ltrim( $rule->to, '/' ),
			'type'   => $rule->code,
			'format' => $regex ? 'regex' : 'plain',
		];

		return $this->change(
			static function ( array $entries ) use ( $entry ) {
				$entries[] = $entry;
				return $entries;
			}
		);
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
	 * @param bool $drop_tables Unused: Yoast keeps its redirects in options.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		unset( $drop_tables );

		$this->items = null;
		foreach ( [ self::OPTION, 'wpseo-premium-redirects-export-plain', 'wpseo-premium-redirects-export-regex' ] as $option ) {
			delete_option( $option );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'WPSEO_PREMIUM_VERSION' );
	}
}
