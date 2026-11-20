<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

class SaveSignificantKeywords implements ExecuteHooks {

	/**
	 * Posts already indexed in this request, and the content they were indexed
	 * from.
	 *
	 * The indexing now runs on more than one hook, because no single one of
	 * them fires after every way a custom field can be written. Running twice
	 * is correct, the later pass simply overwrites the earlier one, but the
	 * extraction and the two table writes are the expensive half, so a pass
	 * that would store exactly what is already stored stops there.
	 *
	 * @since 10.4.0
	 *
	 * @var array<int,string>
	 */
	protected $indexed = array();

	/**
	 *
	 * @return void
	 */
	public function hooks() {
		/*
		 * `wp_after_insert_post` rather than `save_post`, which is where this
		 * used to sit.
		 *
		 * The index is built from the post content, and since it also reads
		 * custom fields it now depends on meta that is written after the post
		 * row. On `save_post` it ran before: the REST controller writes
		 * registered meta and the headless `acf` field in
		 * `update_additional_fields_for_object()`, after `wp_update_post()`
		 * returns, and a front-end `acf_form()` calls `acf_save_post()` after
		 * `wp_insert_post()`. A post whose text lives entirely in ACF fields
		 * was therefore not indexed on its first publish, and `shouldClearIndex`
		 * even emptied it, after which the index stayed one save behind for
		 * good.
		 *
		 * `wp_after_insert_post` runs after everything hooked to `save_post`,
		 * so meta written by another plugin there is in place too.
		 */
		add_action( 'wp_after_insert_post', array( $this, 'afterInsert' ), 20, 2 );

		// And once more after ACF has written its fields, for the flows that
		// write them later still: ACF's own REST integration, acf_form(), and
		// importers that call acf/save_post themselves.
		add_action( 'acf/save_post', array( $this, 'afterAcfSave' ), 20 );

		add_action( 'delete_post', array( $this, 'delete' ) );
	}

	/**
	 * Index a post once WordPress has finished writing it.
	 *
	 * The post is read again rather than taken from the hook. Core passes
	 * `wp_after_insert_post` the object it read before `save_post`, so when a
	 * `save_post` or `acf/save_post` callback rewrites the same post with
	 * wp_update_post(), this outer pass runs last and would index the old
	 * content over the new one.
	 *
	 * @since 10.4.0
	 *
	 * @param int      $id   The post ID.
	 * @param \WP_Post $post The post, as read before `save_post`. Unused.
	 *
	 * @return void
	 */
	public function afterInsert( $id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->save( (int) $id, get_post( $id ) );
	}

	/**
	 * Index a post once ACF has finished writing its fields.
	 *
	 * ACF passes the post ID it saved, which is not always a post: it also
	 * saves options and terms through the same hook, as `options` or
	 * `term_123`.
	 *
	 * Only the flows that write fields after the post row need this pass:
	 * ACF's REST integration, acf_form() and importers calling
	 * acf/save_post themselves.
	 *
	 * @since 10.4.0
	 *
	 * @param int|string $post_id What ACF saved.
	 *
	 * @return void
	 */
	public function afterAcfSave( $post_id ) {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}

		// An admin save fires `acf/save_post` from inside `save_post`, and
		// `wp_after_insert_post` follows with the fields in place. Extracting
		// here too would render the post twice for one save.
		if ( doing_action( 'save_post' ) ) {
			return;
		}

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return;
		}

		$this->save( (int) $post_id, $post );
	}

	/**
	 * Index one post.
	 *
	 * @param int      $id   The post ID.
	 * @param \WP_Post $post The post.
	 *
	 * @since 10.4.0 Returns whether the index was written.
	 *
	 * @return bool Whether the index was written. False when the post is not
	 *              indexable, or when this request already stored the same
	 *              content for it.
	 */
	public function save( $id, $post ) {

		// Disable content analysis
		if ( seopress_get_service( 'AdvancedOption' )->getAppearanceCaMetaboxe() ) {
			return false;
		}

		// Check if $post is null
		if ( is_null( $post ) ) {
			return false;
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
			return false;
		}

		$content = seopress_pro_get_service( 'SignificantKeywords' )->getFullContentByPost( $post );

		// Already indexed from this exact content earlier in the request, by
		// the other hook. See $indexed.
		$signature = md5( (string) $content );

		if ( isset( $this->indexed[ $id ] ) && $this->indexed[ $id ] === $signature ) {
			return false;
		}

		$this->indexed[ $id ] = $signature;

		$keywords = seopress_pro_get_service( 'SignificantKeywords' )->retrieveSignificantKeywords( $content );
		$data     = seopress_pro_get_service( 'SignificantKeywords' )->prepareWordsToInsert( $keywords, $id, $content );

		$repository = seopress_pro_get_service( 'SignificantKeywordsRepository' );

		if ( ! empty( $data ) ) {
			$repository->removeSignificantKeywordsByPostId( $id );
			$repository->insertSignificantKeywords( $data );

			return true;
		}

		// Nothing to index. The delete above used to run unconditionally, so a
		// save that produced no keywords was not a no-op: it emptied the index
		// and nothing put it back. Internal link suggestions are built from
		// that table, so the post silently stopped being suggested anywhere,
		// while the save reported success.
		if ( $this->shouldClearIndex( $post, $content ) ) {
			$repository->removeSignificantKeywordsByPostId( $id );

			return true;
		}

		return false;
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
