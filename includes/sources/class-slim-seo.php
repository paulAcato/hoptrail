<?php
/**
 * Slim SEO (redirection module).
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `ss_redirects` option: id ⇒ { type, condition, from, to, enable }. Sources are stored
 * without leading slash; conditions exact-match, contain, start-with, end-with and regex.
 */
final class Slim_Seo implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * Option name (SLIM_SEO_REDIRECTS).
	 */
	const OPTION = 'ss_redirects';

	/**
	 * Slim SEO condition ⇒ match type.
	 */
	const MATCHES = [
		'exact-match' => Rule::MATCH_EXACT,
		'contain'     => Rule::MATCH_CONTAINS,
		'start-with'  => Rule::MATCH_PREFIX,
		'end-with'    => Rule::MATCH_SUFFIX,
		'regex'       => Rule::MATCH_REGEX,
	];

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'slim-seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Slim SEO';
	}

	/**
	 * Stored redirects, keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function items(): array {
		$items = self::safe_unserialize( get_option( self::OPTION, [] ) );

		return is_array( $items ) ? array_filter( $items, 'is_array' ) : [];
	}

	/**
	 * Slim SEO's own settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		$settings = self::safe_unserialize( get_option( 'slim_seo', [] ) );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		$features = self::settings()['features'] ?? null;

		// Without saved settings every feature is on, redirection included.
		return defined( 'SLIM_SEO_VER' ) && ( ! is_array( $features ) || in_array( 'redirection', $features, true ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return [] !== $this->items();
	}

	/**
	 * Whether an item redirects.
	 *
	 * @param array<string, mixed> $item Item.
	 *
	 * @return bool
	 */
	private static function active( array $item ): bool {
		return ! empty( $item['enable'] ) && self::is_redirect_code( (int) ( $item['type'] ?? 301 ) ) && '' !== (string) ( $item['from'] ?? '' ) && '' !== (string) ( $item['to'] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		return count( array_filter( $this->items(), [ self::class, 'active' ] ) );
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
		foreach ( array_slice( $this->items(), $offset, $limit, true ) as $id => $item ) {
			if ( self::active( $item ) ) {
				$rules[] = $this->to_rule( (string) $id, $item );
			}
		}

		return $rules;
	}

	/**
	 * An item as a rule.
	 *
	 * @param string               $id   ID.
	 * @param array<string, mixed> $item Item.
	 *
	 * @return Rule
	 */
	private function to_rule( string $id, array $item ): Rule {
		$match = self::MATCHES[ $item['condition'] ?? 'exact-match' ] ?? Rule::MATCH_EXACT;
		$from  = (string) $item['from'];

		return new Rule(
			source: $this->slug(),
			id: $id,
			from: Rule::MATCH_EXACT === $match ? '/' . ltrim( $from, '/' ) : $from,
			to: (string) $item['to'],
			code: (int) ( $item['type'] ?? 301 ),
			match: $match,
			leading_slash: false,
			case_sensitive: ! empty( self::settings()['case_sensitive'] ),
			edit_url: admin_url( 'options-general.php?page=slim-seo#redirection' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function find( string $id ): ?Rule {
		$item = $this->items()[ $id ] ?? null;

		return is_array( $item ) && self::active( $item ) ? $this->to_rule( $id, $item ) : null;
	}

	/**
	 * Change the stored items.
	 *
	 * @param callable $change Receives and returns the items.
	 *
	 * @return bool
	 */
	private function change( callable $change ): bool {
		return update_option( self::OPTION, $change( $this->items() ) );
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
		$condition = array_search( $rule->match, self::MATCHES, true );
		$item      = [
			'type'             => $rule->code,
			'condition'        => false === $condition ? 'regex' : $condition,
			'from'             => Rule::MATCH_REGEX === $rule->match ? $rule->regex( false ) : ltrim( $rule->from, '/' ),
			'to'               => $rule->to,
			'note'             => 'Migrated by Redirect Inspector',
			'enable'           => 1,
			'ignoreParameters' => 0,
		];

		return $this->change(
			static function ( array $items ) use ( $item ) {
				return [ uniqid() => $item ] + $items;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		return isset( $this->items()[ $id ] ) && $this->change(
			static function ( array $items ) use ( $id, $to ) {
				$items[ $id ]['to'] = $to;
				return $items;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		$ok = isset( $this->items()[ $id ] ) && $this->change(
			static function ( array $items ) use ( $id ) {
				$items[ $id ]['enable'] = 0;
				return $items;
			}
		);

		return $ok ? 'disabled' : '';
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
		return isset( $this->items()[ $id ] ) && $this->change(
			static function ( array $items ) use ( $id ) {
				unset( $items[ $id ] );
				return $items;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Unused: Slim SEO keeps its redirects in an option.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		unset( $drop_tables );

		return delete_option( self::OPTION );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'SLIM_SEO_VER' );
	}
}
