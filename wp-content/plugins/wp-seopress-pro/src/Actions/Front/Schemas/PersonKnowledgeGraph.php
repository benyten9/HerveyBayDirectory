<?php

namespace SEOPressPro\Actions\Front\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksFrontend;
use SEOPressPro\Helpers\SocialProfiles;

/**
 * Override Organization schema sameAs with Person social profiles
 * when the Knowledge Graph type is "Person" and a user ID is set.
 *
 * @since 9.8
 */
class PersonKnowledgeGraph implements ExecuteHooksFrontend {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'seopress_get_json_data_organization', array( $this, 'overrideSameAs' ) );
	}

	/**
	 * Replace global social accounts with the linked user's social profiles.
	 *
	 * @param array $data The Organization/Person schema data.
	 *
	 * @return array
	 */
	public function overrideSameAs( $data ) {
		$type = seopress_get_service( 'SocialOption' )->getSocialKnowledgeType();

		if ( 'Person' !== $type ) {
			return $data;
		}

		$social_option = seopress_get_service( 'SocialOption' );
		if ( ! method_exists( $social_option, 'getSocialKnowledgeUserId' ) ) {
			return $data;
		}

		$user_id = $social_option->getSocialKnowledgeUserId();

		if ( empty( $user_id ) ) {
			return $data;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $data;
		}

		// Build sameAs exclusively from user social profiles (not global accounts).
		$same_as = SocialProfiles::getSameAs( $user_id );

		// Replace sameAs in the schema data.
		if ( ! empty( $same_as ) ) {
			$data['sameAs'] = array_values( $same_as );
		} else {
			unset( $data['sameAs'] );
		}

		// Override name with user display name if not set.
		if ( empty( $data['name'] ) ) {
			$data['name'] = esc_html( $user->display_name );
		}

		// Add description from user bio.
		$description = get_the_author_meta( 'description', $user_id );
		if ( ! empty( $description ) && empty( $data['description'] ) ) {
			$data['description'] = esc_html( $description );
		}

		// Add image from avatar if not set.
		if ( empty( $data['image'] ) ) {
			$avatar = get_avatar_url( $user_id, array( 'size' => 512 ) );
			if ( ! empty( $avatar ) ) {
				$data['image'] = esc_url( $avatar );
			}
		}

		// Add @id for Person.
		$author_url  = get_author_posts_url( $user_id );
		$data['@id'] = trailingslashit( esc_url( $author_url ) ) . '#person';

		return $data;
	}
}
