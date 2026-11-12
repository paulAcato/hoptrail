<?php
/**
 * Safe Redirect Manager (10up).
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

use Acato\RedirectInspector\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads published `redirect_rule` posts and their meta. A source ending in `*` is a wildcard
 * (prefix) rule; a target ending in `*` receives the matched remainder.
 */
final class Safe_Redirect_Manager implements Source, Writable, Purgeable {

	use Reads_Storage;

	/**
	 * Post type.
	 */
	const POST_TYPE = 'redirect_rule';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'safe-redirect-manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Safe Redirect Manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'SRM_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		global $wpdb;

		// Not wp_count_posts(): it returns nothing while SRM is inactive (post type unregistered).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = 'publish'", $wpdb->posts, self::POST_TYPE ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$all = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash')", $wpdb->posts, self::POST_TYPE ) );

		return max( 0, $all - $this->count() );
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
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'menu_order ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		if ( ! $ids ) {
			return [];
		}

		update_meta_cache( 'post', $ids );

		$case_insensitive = (bool) apply_filters( 'srm_case_insensitive_redirects', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- SRM's own filter.

		$rules = [];
		foreach ( $ids as $id ) {
			$from  = (string) get_post_meta( $id, '_redirect_rule_from', true );
			$to    = (string) get_post_meta( $id, '_redirect_rule_to', true );
			$code  = (int) get_post_meta( $id, '_redirect_rule_status_code', true );
			$regex = (bool) get_post_meta( $id, '_redirect_rule_from_regex', true );
			$code  = $code ? $code : 301;

			if ( '' === $from || '' === $to || ! self::is_redirect_code( $code ) ) {
				continue;
			}

			$match = Rule::MATCH_EXACT;
			if ( $regex ) {
				$match = Rule::MATCH_REGEX;
			} elseif ( str_ends_with( $from, '*' ) ) {
				$match = Rule::MATCH_PREFIX;
				$from  = rtrim( $from, '*' );
			}

			$rules[] = new Rule(
				source: $this->slug(),
				id: (string) $id,
				from: $from,
				to: $to,
				code: $code,
				match: $match,
				leading_slash: true,
				case_sensitive: ! $case_insensitive,
				edit_url: admin_url( 'post.php?post=' . (int) $id . '&action=edit' )
			);
		}

		return $rules;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function find( string $id ): ?Rule {
		$post = get_post( absint( $id ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$from  = (string) get_post_meta( $post->ID, '_redirect_rule_from', true );
		$regex = (bool) get_post_meta( $post->ID, '_redirect_rule_from_regex', true );
		$match = $regex ? Rule::MATCH_REGEX : ( str_ends_with( $from, '*' ) ? Rule::MATCH_PREFIX : Rule::MATCH_EXACT );

		return new Rule( $this->slug(), (string) $post->ID, Rule::MATCH_PREFIX === $match ? rtrim( $from, '*' ) : $from, (string) get_post_meta( $post->ID, '_redirect_rule_to', true ), (int) get_post_meta( $post->ID, '_redirect_rule_status_code', true ), $match );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		$ok = false !== update_post_meta( absint( $id ), '_redirect_rule_to', $to );
		self::flush();

		return $ok;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Rule ID.
	 */
	public function disable( string $id ): string {
		$result = wp_update_post(
			[
				'ID'          => absint( $id ),
				'post_status' => 'draft',
			],
			true
		);
		self::flush();

		return is_wp_error( $result ) ? '' : 'disabled';
	}

	/**
	 * Drop SRM's cached rule list.
	 *
	 * @return void
	 */
	private static function flush(): void {
		if ( function_exists( 'srm_flush_cache' ) ) {
			srm_flush_cache();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return $this->count() > 0;
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
	 * Through SRM's own function; prefix rules become SRM wildcards, other patterns regex.
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		if ( ! function_exists( 'srm_create_redirect' ) ) {
			return false;
		}

		if ( $rule->is_exact() ) {
			$result = srm_create_redirect( $rule->path(), $rule->to, $rule->code );
		} elseif ( Rule::MATCH_PREFIX === $rule->match ) {
			$result = srm_create_redirect( $rule->path() . '*', $rule->to, $rule->code );
		} else {
			$result = srm_create_redirect( $rule->regex( true ), $rule->to, $rule->code, true );
		}
		self::flush();

		return ! is_wp_error( $result ) && $result;
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
		$post = get_post( absint( $id ) );

		return $post && self::POST_TYPE === $post->post_type && (bool) wp_delete_post( $post->ID, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Unused: SRM keeps its rules as posts.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		global $wpdb;

		unset( $drop_tables );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s', $wpdb->posts, self::POST_TYPE ) );
		foreach ( $ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
		delete_transient( '_srm_redirects_graph' );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'SRM_VERSION' );
	}
}
