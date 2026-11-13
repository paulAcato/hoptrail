<?php
/**
 * Shared reading of redirects stored on posts (post meta).
 *
 * @package Hoptrail
 */

namespace Hoptrail\Includes\Sources;

use Hoptrail\Includes\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * A redirect set on a published post: its source is the post's own URL, its target a meta value.
 * Such rules are exact, per object, and can't receive other plugins' rules (no migration target).
 */
trait Per_Post_Redirects {

	/**
	 * Rows of published posts that redirect: [ ID, to, code ].
	 *
	 * @param int      $offset  Offset.
	 * @param int      $limit   Page size.
	 * @param int|null $post_id Only this post.
	 *
	 * @return object[]
	 */
	abstract protected function redirect_rows( int $offset, int $limit, ?int $post_id = null ): array;

	/**
	 * Enabled rules, paged.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 *
	 * @return Rule[]
	 */
	public function rules( int $offset, int $limit ): array {
		return array_values( array_filter( array_map( [ $this, 'post_rule' ], $this->redirect_rows( $offset, $limit ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Post ID.
	 */
	public function find( string $id ): ?Rule {
		$rows = $this->redirect_rows( 0, 1, absint( $id ) );

		return $rows ? $this->post_rule( $rows[0] ) : null;
	}

	/**
	 * A post row as a rule.
	 *
	 * @param object $row Row with ID, to and code.
	 *
	 * @return Rule|null
	 */
	private function post_rule( object $row ): ?Rule {
		$permalink = (string) get_permalink( (int) $row->ID );
		if ( '' === $permalink || '' === trim( (string) $row->to ) ) {
			return null;
		}

		$code = (int) $row->code;

		return new Rule(
			source: $this->slug(),
			id: (string) $row->ID,
			from: wp_make_link_relative( $permalink ),
			to: trim( (string) $row->to ),
			code: self::is_redirect_code( $code ) ? $code : 301,
			match: Rule::MATCH_EXACT,
			leading_slash: true,
			case_sensitive: false,
			edit_url: admin_url( 'post.php?post=' . (int) $row->ID . '&action=edit' ),
			per_object: true
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function supports( Rule $rule ): bool {
		unset( $rule );

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rule $rule Rule.
	 */
	public function create( Rule $rule ): bool {
		unset( $rule );

		return false;
	}
}
