<?php

namespace SEOPressPro\Actions\Front\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksFrontend;
use SEOPressPro\Helpers\SocialProfiles;

class PrintProfilePage implements ExecuteHooksFrontend {
	/**
	 * Hooks
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/**
	 * Render ProfilePage schema on author archive pages
	 *
	 * @return void
	 */
	public function render() {
		// Only render on author archive pages.
		if ( ! is_author() ) {
			return;
		}

		// Check if feature is globally enabled.
		$enabled = seopress_pro_get_service( 'OptionPro' )->getProfilePageSchemaEnable();
		if ( '1' !== $enabled ) {
			return;
		}

		// Get the author object.
		$author = get_queried_object();
		if ( ! $author instanceof \WP_User ) {
			return;
		}

		// Check if user has opted out.
		$user_disabled = get_user_meta( $author->ID, '_seopress_pro_rich_snippets_profilepage_disable', true );
		if ( '1' === $user_disabled ) {
			return;
		}

		// Build the schema data.
		$schema = $this->buildSchema( $author );

		if ( empty( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		?><script type="application/ld+json"><?php echo apply_filters( 'seopress_schemas_profile_page_html', $json ); ?></script>
		<?php
	}

	/**
	 * Build ProfilePage schema data
	 *
	 * @param \WP_User $author The author object.
	 *
	 * @return array
	 */
	protected function buildSchema( \WP_User $author ) {
		$author_url  = esc_url( get_author_posts_url( $author->ID ) );
		$post_count  = count_user_posts( $author->ID, 'post', true );
		$avatar_url  = esc_url( get_avatar_url( $author->ID, array( 'size' => 512 ) ) );
		$user_url    = esc_url( $author->user_url );
		$description = esc_html( get_the_author_meta( 'description', $author->ID ) );

		// Get date created (user registration date).
		$date_created = $author->user_registered;
		if ( ! empty( $date_created ) ) {
			$date_created = esc_html( gmdate( 'c', strtotime( $date_created ) ) );
		}

		// Get date modified (last post date or registration date).
		$date_modified = $this->getLastPostDate( $author->ID );
		if ( empty( $date_modified ) ) {
			$date_modified = $date_created;
		}

		// Build main entity (Person).
		$main_entity = array(
			'@type'         => 'Person',
			'@id'           => trailingslashit( $author_url ) . '#person',
			'name'          => esc_html( $author->display_name ),
			'alternateName' => esc_html( $author->user_login ),
			'identifier'    => (string) $author->ID,
			'url'           => $author_url,
		);

		// Add description if available.
		if ( ! empty( $description ) ) {
			$main_entity['description'] = $description;
		}

		// Add avatar if available.
		if ( ! empty( $avatar_url ) && false !== $avatar_url ) {
			$main_entity['image'] = $avatar_url;
		}

		// Build sameAs array from social profiles.
		$same_as = SocialProfiles::getSameAs( $author->ID );

		if ( ! empty( $same_as ) ) {
			$main_entity['sameAs'] = count( $same_as ) === 1 ? $same_as[0] : array_values( $same_as );
		}

		// Add interaction statistic (post count).
		$main_entity['interactionStatistic'] = array(
			'@type'                => 'InteractionCounter',
			'interactionType'      => seopress_check_ssl() . 'schema.org/WriteAction',
			'userInteractionCount' => (int) $post_count,
		);

		// Build the full ProfilePage schema.
		$schema = array(
			'@context'   => seopress_check_ssl() . 'schema.org',
			'@type'      => 'ProfilePage',
			'mainEntity' => $main_entity,
		);

		// Add dates if available.
		if ( ! empty( $date_created ) ) {
			$schema['dateCreated'] = $date_created;
		}
		if ( ! empty( $date_modified ) ) {
			$schema['dateModified'] = $date_modified;
		}

		// Build profile data for the filter.
		$profile_data = array(
			'name'          => esc_html( $author->display_name ),
			'alternateName' => esc_html( $author->user_login ),
			'identifier'    => (string) $author->ID,
			'description'   => $description,
			'image'         => $avatar_url,
			'url'           => $author_url,
			'sameAs'        => ! empty( $user_url ) ? array( $user_url ) : array(),
			'postCount'     => (int) $post_count,
			'dateCreated'   => $date_created,
			'dateModified'  => $date_modified,
		);

		/**
		 * Filter profile data before schema generation.
		 *
		 * @since 9.6.0
		 *
		 * @param array    $profile_data Profile data array.
		 * @param \WP_User $author       The author object.
		 */
		$profile_data = apply_filters( 'seopress_pro_schema_profilepage_data', $profile_data, $author );

		// Rebuild schema from filtered data.
		$main_entity = array(
			'@type'         => 'Person',
			'@id'           => esc_url( trailingslashit( $profile_data['url'] ) ) . '#person',
			'name'          => esc_html( $profile_data['name'] ),
			'alternateName' => esc_html( $profile_data['alternateName'] ),
			'identifier'    => esc_html( $profile_data['identifier'] ),
			'url'           => esc_url( $profile_data['url'] ),
		);

		if ( ! empty( $profile_data['description'] ) ) {
			$main_entity['description'] = esc_html( $profile_data['description'] );
		}

		if ( ! empty( $profile_data['image'] ) ) {
			$main_entity['image'] = esc_url( $profile_data['image'] );
		}

		if ( ! empty( $profile_data['sameAs'] ) && is_array( $profile_data['sameAs'] ) ) {
			// If only one sameAs, use string; otherwise use array.
			if ( count( $profile_data['sameAs'] ) === 1 ) {
				$main_entity['sameAs'] = esc_url( $profile_data['sameAs'][0] );
			} else {
				$main_entity['sameAs'] = array_map( 'esc_url', $profile_data['sameAs'] );
			}
		}

		$main_entity['interactionStatistic'] = array(
			'@type'                => 'InteractionCounter',
			'interactionType'      => seopress_check_ssl() . 'schema.org/WriteAction',
			'userInteractionCount' => (int) $profile_data['postCount'],
		);

		$schema = array(
			'@context'   => seopress_check_ssl() . 'schema.org',
			'@type'      => 'ProfilePage',
			'mainEntity' => $main_entity,
		);

		if ( ! empty( $profile_data['dateCreated'] ) ) {
			$schema['dateCreated'] = esc_html( $profile_data['dateCreated'] );
		}
		if ( ! empty( $profile_data['dateModified'] ) ) {
			$schema['dateModified'] = esc_html( $profile_data['dateModified'] );
		}

		return $schema;
	}

	/**
	 * Get the last post date for an author
	 *
	 * @param int $author_id The author ID.
	 *
	 * @return string|null ISO 8601 formatted date or null.
	 */
	protected function getLastPostDate( $author_id ) {
		$args = array(
			'author'         => $author_id,
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => 'publish',
			'post_type'      => 'post',
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return null;
		}

		return esc_html( gmdate( 'c', strtotime( $posts[0]->post_date_gmt ) ) );
	}
}
