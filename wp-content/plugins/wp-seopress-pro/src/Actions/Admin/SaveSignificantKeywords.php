<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

class SaveSignificantKeywords implements ExecuteHooks {

	/**
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'delete_post', array( $this, 'delete' ) );
	}

	/**
	 * @return void
	 */
	public function save( $id, $post ) {

		// Disable content analysis
		if ( seopress_get_service( 'AdvancedOption' )->getAppearanceCaMetaboxe() ) {
			return;
		}

		// Check if $post is null
		if ( is_null( $post ) ) {
			return;
		}

		$postTypes = seopress_get_service( 'WordPressData' )->getPostTypes();

		$canSave = true;
		if ( wp_is_post_revision( $id ) ) {
			$canSave = false;
		}

		if ( $post->post_status !== 'publish' ) {
			$canSave = false;
		}

		if ( ! in_array( $post->post_type, array_keys( $postTypes ) ) ) {
			$canSave = false;
		}

		if ( ! \property_exists( $post, 'post_content' ) ) {
			$canSave = false;
		}

		if ( ! $canSave ) {
			return;
		}

		$content  = seopress_pro_get_service( 'SignificantKeywords' )->getFullContentByPost( $post );
		$keywords = seopress_pro_get_service( 'SignificantKeywords' )->retrieveSignificantKeywords( $content );
		$data     = seopress_pro_get_service( 'SignificantKeywords' )->prepareWordsToInsert( $keywords, $id, $content );

		$repository = seopress_pro_get_service( 'SignificantKeywordsRepository' );

		if ( ! empty( $data ) ) {
			$repository->removeSignificantKeywordsByPostId( $id );
			$repository->insertSignificantKeywords( $data );

			return;
		}

		// Nothing to index. The delete above used to run unconditionally, so a
		// save that produced no keywords was not a no-op: it emptied the index
		// and nothing put it back. Internal link suggestions are built from
		// that table, so the post silently stopped being suggested anywhere,
		// while the save reported success.
		if ( $this->shouldClearIndex( $post, $content ) ) {
			$repository->removeSignificantKeywordsByPostId( $id );
		}
	}

	/**
	 * Whether an empty extraction is an answer about the post, or a failure to
	 * read it.
	 *
	 * The two are indistinguishable from the result alone, which is why the
	 * unconditional delete was destructive: a builder that renders nothing
	 * here looks exactly like a post with nothing in it.
	 *
	 * The emptiness is accounted for, so the index should be cleared, when the
	 * post genuinely carries no content, or when what it carries sits under the
	 * minimum length `retrieveSignificantKeywords()` deliberately refuses to
	 * work on. Measured with the same `cleanContent()` and the same filtered
	 * floor, so the two cannot drift apart.
	 *
	 * Anything else means readable text produced no keywords, which is what
	 * #1666 looks like on Divi 5: the stored content is substantial and the
	 * render comes back empty. Deleting on that loses an index this save
	 * cannot rebuild, and nothing surfaces it.
	 *
	 * @since 10.2.0
	 *
	 * @param \WP_Post $post    The post being saved.
	 * @param string   $content What the renderer handed back for it.
	 *
	 * @return bool
	 */
	protected function shouldClearIndex( $post, $content ) {
		$readable = seopress_pro_get_service( 'SignificantKeywords' )->cleanContent( (string) $content );

		if ( '' === trim( $readable ) ) {
			// Nothing readable. Only an equally empty post accounts for that;
			// stored content that did not survive the render does not.
			return '' === trim( (string) $post->post_content );
		}

		return strlen( $readable ) < apply_filters( 'seopress_pro_minimum_length_content_linking', 200 );
	}

	public function delete( $id ) {
		seopress_pro_get_service( 'SignificantKeywordsRepository' )->removeSignificantKeywordsByPostId( $id );
	}
}
