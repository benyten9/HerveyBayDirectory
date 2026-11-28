<?php
/** Permission-filtered existing native authors; no account changes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

use DirectoristAIPro\Capabilities\{Actor, Context};
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed administrative author picker contract.

/** Expose account display names only within current native author authority. */
final class AuthorCatalog {
	public static function read( Context $context, int $directory, string $search = '', int $offset = 0, int $selected = 0 ): array {
		$context->assert_current();
		$context->actor()->refresh();
		if ( ! $context->actor()->can( 'manage_options' ) || ! directorist_is_directory( $directory ) || $offset < 0 || $selected < 0 || mb_strlen( $search ) > 100 || 1 !== preg_match( '//u', $search ) || preg_match( '/[\x00-\x1f\x7f]/u', $search ) ) {
			throw new ContractException( 'unauthorized', 'Choose an authorized directory and bounded author search.' );
		}
		$type    = get_post_type_object( ATBDP_POST_TYPE );
		$others  = $context->actor()->can( $type->cap->edit_others_posts ) && $context->actor()->can( 'list_users' );
		$query   = $others ? new \WP_User_Query(
			array(
				'search'         => '' === $search ? '' : '*' . $search . '*',
				'search_columns' => array( 'display_name' ),
				'number'         => 21,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'count_total'    => false,
				'capability'     => $type->cap->create_posts,
			)
		) : null;
		$users   = $query ? $query->get_results() : ( 0 === $offset ? array( get_userdata( $context->actor()->user_id() ) ) : array() );
		$more    = count( $users ) > 20;
		$authors = array();
		foreach ( array_slice( $users, 0, 20 ) as $user ) {
			if ( ! $user || ( '' !== $search && false === mb_stripos( $user->display_name, $search ) ) ) {
				continue;
			}
			$entry = self::entry( $context, $directory, (int) $user->ID );
			if ( $entry['eligible'] ) {
				$authors[] = $entry;
			}
		}
		$selected_author = null;
		if ( $selected ) {
			$selected_author = ( $others || $selected === $context->actor()->user_id() ) ? self::entry( $context, $directory, $selected ) : array(
				'id'          => $selected,
				'eligible'    => false,
				'can_publish' => false,
				'reason'      => 'author_unavailable',
			);
		}
		return array(
			'authors'         => $authors,
			'has_more'        => $more,
			'next_offset'     => $more ? $offset + 20 : null,
			'selected_author' => $selected_author,
		);
	}

	public static function selected( Context $context, int $directory, int $id ): array {
		$context->assert_current();
		$type = get_post_type_object( ATBDP_POST_TYPE );
		if ( ! $context->actor()->can( 'manage_options' ) || ( $id !== $context->actor()->user_id() && ( ! $context->actor()->can( 'list_users' ) || ! $context->actor()->can( $type->cap->edit_others_posts ) ) ) ) {
			return array(
				'id'          => $id,
				'eligible'    => false,
				'can_publish' => false,
				'reason'      => 'author_unavailable',
			);
		}
		return self::entry( $context, $directory, $id );
	}

	private static function entry( Context $context, int $directory, int $id ): array {
		$result = array(
			'id'          => $id,
			'eligible'    => false,
			'can_publish' => false,
			'reason'      => 'author_unavailable',
		);
		$user   = get_userdata( $id );
		if ( ! $user ) {
			return $result;
		}
		$actor = Actor::user( $id );
		try {
			( new DirectoristInspector() )->creation_eligibility( new Context( $actor, 'owner', 'worker' ), $directory );
		} catch ( ContractException $error ) {
			$result['reason'] = 'author_not_eligible';
			return $result;
		}
		$type    = get_post_type_object( ATBDP_POST_TYPE );
		$publish = 'publish' === directorist_get_listing_create_status( $directory ) && $context->actor()->can( $type->cap->publish_posts ) && $actor->can( $type->cap->publish_posts );
		return array(
			'id'          => $id,
			'name'        => mb_substr( sanitize_text_field( $user->display_name ), 0, 160 ),
			'eligible'    => true,
			'can_publish' => $publish,
			'reason'      => $publish ? null : 'moderation_required',
		);
	}
}
