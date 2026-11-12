<?php
/**
 * SEOPress: the redirect set on a post (free version).
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta `_seopress_redirections_enabled` = "yes", `_seopress_redirections_value` (target) and
 * `_seopress_redirections_type` (status). Redirects that only apply to logged-in users are skipped.
 */
final class Seopress implements Source, Writable, Purgeable {

	use Reads_Storage;
	use Per_Post_Redirects;

	/**
	 * Meta keys SEOPress uses for a post's redirect.
	 */
	const KEYS = [ '_seopress_redirections_enabled', '_seopress_redirections_value', '_seopress_redirections_type', '_seopress_redirections_logged_status', '_seopress_redirections_enabled_regex', '_seopress_redirections_param' ];

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'SEOPress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return $this->count() > 0;
	}

	/**
	 * Rows of redirecting posts.
	 *
	 * @param int      $offset  Offset.
	 * @param int      $limit   Page size.
	 * @param int|null $post_id Only this post.
	 *
	 * @return object[]
	 */
	protected function redirect_rows( int $offset, int $limit, ?int $post_id = null ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, v.meta_value AS `to`, t.meta_value AS code FROM %i p
				INNER JOIN %i e ON e.post_id = p.ID AND e.meta_key = '_seopress_redirections_enabled' AND e.meta_value = 'yes'
				INNER JOIN %i v ON v.post_id = p.ID AND v.meta_key = '_seopress_redirections_value' AND v.meta_value <> ''
				LEFT JOIN %i t ON t.post_id = p.ID AND t.meta_key = '_seopress_redirections_type'
				LEFT JOIN %i l ON l.post_id = p.ID AND l.meta_key = '_seopress_redirections_logged_status'
				WHERE p.post_status = 'publish' AND ( l.meta_value IS NULL OR l.meta_value <> 'only_logged_in' ) AND ( %d = 0 OR p.ID = %d )
				ORDER BY p.ID LIMIT %d OFFSET %d",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$wpdb->postmeta,
				(int) $post_id,
				(int) $post_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function count(): int {
		return count( $this->redirect_rows( 0, 100000 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function skipped(): int {
		return 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Post ID.
	 * @param string $to New target.
	 */
	public function retarget( string $id, string $to ): bool {
		// Only a post that already has a SEOPress redirect.
		if ( '' === (string) get_post_meta( absint( $id ), '_seopress_redirections_value', true ) ) {
			return false;
		}

		return false !== update_post_meta( absint( $id ), '_seopress_redirections_value', $to );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Post ID.
	 */
	public function disable( string $id ): string {
		if ( '' === (string) get_post_meta( absint( $id ), '_seopress_redirections_value', true ) ) {
			return '';
		}

		return false !== update_post_meta( absint( $id ), '_seopress_redirections_enabled', '' ) ? 'disabled' : '';
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
	 * @param string $id Post ID.
	 */
	public function delete_stored( string $id ): bool {
		foreach ( self::KEYS as $key ) {
			delete_post_meta( absint( $id ), $key );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $drop_tables Unused: SEOPress keeps these redirects in post meta.
	 */
	public function purge_storage( bool $drop_tables ): bool {
		unset( $drop_tables );

		// Only the redirects that were listed (published, not "logged-in only").
		foreach ( $this->redirect_rows( 0, 100000 ) as $row ) {
			$this->delete_stored( (string) $row->ID );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}
}
