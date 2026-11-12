<?php
/**
 * The SEO Framework: the 301 redirect set on a post.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta `redirect` (target URL); The SEO Framework redirects with a 301. Only read while the
 * plugin is active: `redirect` is a generic meta key that other plugins may use, so it is never
 * treated as leftover data, nor deleted.
 */
final class Seo_Framework implements Source, Writable {

	use Reads_Storage;
	use Per_Post_Redirects;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'the-seo-framework';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'The SEO Framework';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_storage(): bool {
		return $this->is_available() && $this->count() > 0;
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
				"SELECT p.ID, m.meta_value AS `to`, 301 AS code FROM %i p
				INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = 'redirect' AND m.meta_value <> ''
				WHERE p.post_status = 'publish' AND ( %d = 0 OR p.ID = %d )
				ORDER BY p.ID LIMIT %d OFFSET %d",
				$wpdb->posts,
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
		return false !== update_post_meta( absint( $id ), 'redirect', $to );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The SEO Framework has no "off" state: the redirect field is cleared.
	 *
	 * @param string $id Post ID.
	 */
	public function disable( string $id ): string {
		return delete_post_meta( absint( $id ), 'redirect' ) ? 'removed' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugin_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}
}
