<?php

namespace SEOPressPro\Actions\Front\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksFrontend;
use SEOPressPro\Helpers\Schemas\PersonId;
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

		$json = seopress_pro_json_ld_encode( $schema );

		?><script type="application/ld+json"><?php echo apply_filters( 'seopress_schemas_profile_page_html', $json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD document encoded with wp_json_encode() above, filterable by third parties. ?></script>
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

		// Every social profile the author has, the WordPress website field
		// included: getSameAs() puts user_url first, then the SEOPress fields,
		// then the imported Yoast / Rank Math / AIOSEO ones, as a sequential list.
		$same_as = SocialProfiles::getSameAs( $author->ID );

		// Build profile data for the filter.
		$profile_data = array(
			'name'          => esc_html( $author->display_name ),
			'alternateName' => esc_html( $author->user_login ),
			'identifier'    => (string) $author->ID,
			'description'   => $description,
			'image'         => $avatar_url,
			'url'           => $author_url,
			'sameAs'        => $same_as,
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
			// Rebuilt from the filtered URL, so an integrator emptying it
			// through seopress_pro_schema_profilepage_data cannot turn the
			// identifier into the relative "/#person".
			'@id'           => PersonId::getFromUrl( $profile_data['url'], $author->ID ),
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
			// Reindexed again: the filter is public, so an integrator can hand
			// back a list with gaps and the single-profile branch below would
			// read a key that is not there.
			$filtered_same_as = array_values( $profile_data['sameAs'] );

			// If only one sameAs, use string; otherwise use array.
			if ( 1 === count( $filtered_same_as ) ) {
				$main_entity['sameAs'] = esc_url( $filtered_same_as[0] );
			} else {
				$main_entity['sameAs'] = array_map( 'esc_url', $filtered_same_as );
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
