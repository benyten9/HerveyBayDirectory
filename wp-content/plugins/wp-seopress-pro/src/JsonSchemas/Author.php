<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetJsonData;
use SEOPressPro\Helpers\SocialProfiles;
use SEOPressPro\Models\JsonSchemaValue;

class Author extends JsonSchemaValue implements GetJsonData {
	const NAME = 'author';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.6.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		// Resolve author-specific fields from the current post context.
		$post_id = isset( $context['post']->ID ) ? $context['post']->ID : get_the_ID();

		if ( $post_id ) {
			$post    = get_post( $post_id );
			$user_id = $post ? (int) $post->post_author : 0;

			if ( $user_id ) {
				$author_url = get_author_posts_url( $user_id );

				// Person @id — consistent with ProfilePage.
				if ( isset( $data['@id'] ) && '[[personId]]' === $data['@id'] ) {
					$data['@id'] = trailingslashit( esc_url( $author_url ) ) . '#person';
				}

				// Description.
				if ( isset( $data['description'] ) && '[[description]]' === $data['description'] ) {
					$desc = get_the_author_meta( 'description', $user_id );
					if ( ! empty( $desc ) ) {
						$data['description'] = esc_html( $desc );
					} else {
						unset( $data['description'] );
					}
				}

				// Image (avatar).
				if ( isset( $data['image'] ) && '[[image]]' === $data['image'] ) {
					$avatar = get_avatar_url( $user_id, array( 'size' => 512 ) );
					if ( ! empty( $avatar ) && false !== $avatar ) {
						$data['image'] = esc_url( $avatar );
					} else {
						unset( $data['image'] );
					}
				}

				// sameAs (social profiles).
				if ( isset( $data['sameAs'] ) && '[[sameAs]]' === $data['sameAs'] ) {
					$same_as = SocialProfiles::getSameAs( $user_id );
					if ( ! empty( $same_as ) ) {
						$data['sameAs'] = $same_as;
					} else {
						unset( $data['sameAs'] );
					}
				}
			}
		}

		return apply_filters( 'seopress_pro_get_json_data_author', $data, $context );
	}
}
