<?php
/**
 * Single write path for help-center articles.
 *
 * REST, abilities and Pro all create, update and delete articles through this
 * service so the derived columns never drift from the body:
 *   - `content` is run through wp_kses_post()
 *   - `search_text` holds TextNormalizer stems of the title and body
 *   - `content_hash` lets Pro's indexer skip unchanged articles
 *   - only a hand-written `excerpt` is stored; {@see display_excerpt()} falls
 *     back to the first words of the body
 *   - `published_at` is stamped the first time an article is published
 *
 * Hooks fired (Pro's assistant indexer listens to both):
 *   - `doublescale_kb_article_saved` ( KbArticleModel $article, ?array $before )
 *     `$before` is null on create, else the attributes before the change, so a
 *     listener can tell a visibility or status change from a body edit.
 *   - `doublescale_kb_article_deleted` ( int $article_id, array $snapshot )
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbFeedbackModel;
use WP_Error;

/**
 * ArticleService class.
 */
final class ArticleService {

	/** Fields that only place an article (order, category), not change it. */
	private const PLACEMENT = array( 'position', 'category_id' );

	/**
	 * Longest slug stored; leaves room for a "-NN" suffix under the 191 index limit.
	 */
	private const SLUG_MAX_LENGTH = 180;

	/**
	 * Words kept in an automatic excerpt.
	 */
	private const EXCERPT_WORDS = 30;

	/**
	 * Fields a caller may set.
	 *
	 * @var string[]
	 */
	private const WRITABLE = array(
		'category_id',
		'title',
		'slug',
		'content',
		'excerpt',
		'status',
		'visibility',
		'include_in_assistant',
		'position',
	);

	/**
	 * Create an article.
	 *
	 * @param array<string, mixed> $data Writable fields; `title` is required.
	 * @return KbArticleModel|WP_Error
	 */
	public function create( array $data ) {
		$fields = $this->validate( $data, null );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$article            = new KbArticleModel();
		$article->author_id = get_current_user_id() ?: null;
		$this->apply( $article, $fields );

		$saved = $this->persist( $article );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		do_action( 'doublescale_kb_article_saved', $article, null );

		return $article;
	}

	/**
	 * Partially update an article: only the keys present in $data change.
	 *
	 * @param KbArticleModel       $article Article to change.
	 * @param array<string, mixed> $data    Writable fields.
	 * @return KbArticleModel|WP_Error
	 */
	public function update( KbArticleModel $article, array $data ) {
		$fields = $this->validate( $data, $article );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$before = $article->getAttributes();
		$this->apply( $article, $fields );

		// Reordered or moved to another category: where it is listed, not
		// what it says. Its "Updated" date stays (the column updates itself
		// on any change unless the row is written with its own value).
		$dirty = $article->getDirty();
		if ( ! empty( $article->id ) && array() !== $dirty && array() === array_diff( array_keys( $dirty ), self::PLACEMENT ) ) {
			$saved = $this->place( $article, $dirty );
		} else {
			$saved = $this->persist( $article );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		do_action( 'doublescale_kb_article_saved', $article, $before );

		return $article;
	}

	/**
	 * Delete an article.
	 *
	 * @param KbArticleModel $article Article to delete.
	 * @return true|WP_Error
	 */
	public function delete( KbArticleModel $article ) {
		$id       = (int) $article->id;
		$snapshot = $article->getAttributes();

		try {
			$article->delete();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kb_article_delete_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		// Its readers' reasons go with it.
		try {
			KbFeedbackModel::query()->where( 'article_id', $id )->delete();
		} catch ( \Throwable $e ) {
			// Table not there yet: nothing to remove.
			unset( $e );
		}

		do_action( 'doublescale_kb_article_deleted', $id, $snapshot );

		return true;
	}

	/**
	 * Validate and normalise the writable subset of $data.
	 *
	 * @param array<string, mixed> $data    Raw input.
	 * @param KbArticleModel|null  $article Existing article on update.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate( array $data, ?KbArticleModel $article ) {
		$fields = array_intersect_key( $data, array_flip( self::WRITABLE ) );

		if ( null === $article || array_key_exists( 'title', $fields ) ) {
			$title = trim( sanitize_text_field( (string) ( $fields['title'] ?? '' ) ) );
			if ( '' === $title ) {
				return $this->invalid( 'title', __( 'The article needs a title.', 'doublescale' ) );
			}
			$fields['title'] = mb_substr( $title, 0, 255, 'UTF-8' );
		}

		if ( array_key_exists( 'status', $fields ) && ! in_array( $fields['status'], KbArticleModel::statuses(), true ) ) {
			return $this->invalid( 'status', __( 'Unknown article status.', 'doublescale' ) );
		}

		if ( array_key_exists( 'visibility', $fields ) && ! in_array( $fields['visibility'], KbArticleModel::visibilities(), true ) ) {
			return $this->invalid( 'visibility', __( 'Unknown article visibility.', 'doublescale' ) );
		}

		if ( array_key_exists( 'category_id', $fields ) ) {
			$category_id = (int) $fields['category_id'];
			if ( $category_id > 0 && ! KbCategoryModel::find( $category_id ) ) {
				return $this->invalid( 'category_id', __( 'That category does not exist.', 'doublescale' ) );
			}
			$fields['category_id'] = $category_id > 0 ? $category_id : null;
		}

		if ( array_key_exists( 'content', $fields ) ) {
			$fields['content'] = wp_kses_post( (string) $fields['content'] );
		}

		if ( array_key_exists( 'excerpt', $fields ) ) {
			$fields['excerpt'] = trim( sanitize_textarea_field( (string) $fields['excerpt'] ) );
		}

		if ( array_key_exists( 'include_in_assistant', $fields ) ) {
			$fields['include_in_assistant'] = (bool) $fields['include_in_assistant'];
		}

		if ( array_key_exists( 'position', $fields ) ) {
			$fields['position'] = (int) $fields['position'];
		}

		return $fields;
	}

	/**
	 * Copy validated fields onto the model and recompute the derived columns.
	 *
	 * @param KbArticleModel       $article Target.
	 * @param array<string, mixed> $fields  Validated fields.
	 * @return void
	 */
	private function apply( KbArticleModel $article, array $fields ): void {
		$requested_slug = array_key_exists( 'slug', $fields ) ? (string) $fields['slug'] : null;
		unset( $fields['slug'] );

		foreach ( $fields as $key => $value ) {
			$article->{$key} = $value;
		}

		if ( empty( $article->status ) ) {
			$article->status = KbArticleModel::STATUS_DRAFT;
		}
		if ( empty( $article->visibility ) ) {
			$article->visibility = KbArticleModel::VISIBILITY_PUBLIC;
		}
		if ( null === $article->include_in_assistant ) {
			$article->include_in_assistant = true;
		}

		$title   = (string) $article->title;
		$content = (string) $article->content;

		// A slug is set once (from the title) and only changes when asked for,
		// so a title edit never breaks a link someone already shared.
		if ( null !== $requested_slug && '' !== trim( $requested_slug ) ) {
			$article->slug = $this->unique_slug( $requested_slug, (int) $article->id );
		} elseif ( empty( $article->slug ) ) {
			$article->slug = $this->unique_slug( $title, (int) $article->id );
		}

		$article->search_text  = TextNormalizer::search_text( $title . ' ' . $content );
		$article->content_hash = hash( 'sha256', $title . "\n" . $content );

		if ( KbArticleModel::STATUS_PUBLISHED === $article->status && empty( $article->published_at ) ) {
			$article->published_at = current_time( 'mysql', true );
		}
	}

	/**
	 * Save a new position or category only, keeping the "Updated" date.
	 *
	 * @param KbArticleModel       $article Article (already holding the change).
	 * @param array<string, mixed> $dirty   The changed fields.
	 * @return true|WP_Error
	 */
	private function place( KbArticleModel $article, array $dirty ) {
		try {
			$kept = $article->getAttributes()['updated_at'] ?? null;
			KbArticleModel::query()
				->where( 'id', (int) $article->id )
				->update( null !== $kept ? $dirty + array( 'updated_at' => $kept ) : $dirty );
			$article->syncOriginal();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kb_article_save_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Save, treating a save that left no id as a failure: the WPEloquent
	 * wrapper does not always throw when wpdb rejects a statement.
	 *
	 * @param KbArticleModel $article Article.
	 * @return true|WP_Error
	 */
	private function persist( KbArticleModel $article ) {
		try {
			$article->save();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kb_article_save_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( empty( $article->id ) ) {
			return new WP_Error( 'kb_article_save_failed', __( 'The article could not be saved.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * The excerpt readers see: the author's own, else the first words of the body.
	 * Only a hand-written excerpt is stored, so a body edit never leaves a stale
	 * automatic one behind.
	 *
	 * @param KbArticleModel $article Article.
	 * @return string
	 */
	public static function display_excerpt( KbArticleModel $article ): string {
		$own = trim( (string) $article->excerpt );

		return '' !== $own ? $own : self::excerpt_from( (string) $article->content );
	}

	/**
	 * First words of the body, plain text.
	 *
	 * @param string $content HTML body.
	 * @return string
	 */
	public static function excerpt_from( string $content ): string {
		// Tags become spaces first: a heading followed by a paragraph would
		// otherwise run together ("Stepsopen the invoice").
		$text = wp_strip_all_tags( (string) preg_replace( '/<[^>]*>/u', ' ', $content ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return wp_trim_words( $text, self::EXCERPT_WORDS, '…' );
	}

	/**
	 * A slug no other article uses, derived from $source.
	 *
	 * @param string $source     Title or requested slug.
	 * @param int    $exclude_id Article being saved (0 on create).
	 * @return string
	 */
	public function unique_slug( string $source, int $exclude_id = 0 ): string {
		// sanitize_title() percent-encodes non-Latin text (six characters per
		// Arabic letter), so shorten the SOURCE a letter at a time: cutting the
		// encoded slug could split a letter and make the link unreachable.
		$base = sanitize_title( $source );
		while ( strlen( $base ) > self::SLUG_MAX_LENGTH && '' !== $source ) {
			$source = mb_substr( $source, 0, -1, 'UTF-8' );
			$base   = sanitize_title( $source );
		}
		$base = rtrim( $base, '-' );
		if ( '' === $base ) {
			$base = 'article';
		}

		$candidate = $base;
		$suffix    = 2;
		while ( $this->slug_taken( $candidate, $exclude_id ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * @param string $slug       Candidate slug.
	 * @param int    $exclude_id Article being saved.
	 * @return bool
	 */
	private function slug_taken( string $slug, int $exclude_id ): bool {
		$query = KbArticleModel::query()->where( 'slug', $slug );
		if ( $exclude_id > 0 ) {
			$query->where( 'id', '!=', $exclude_id );
		}

		return $query->exists();
	}

	/**
	 * @param string $field   Field name.
	 * @param string $message Human message.
	 * @return WP_Error
	 */
	private function invalid( string $field, string $message ): WP_Error {
		return new WP_Error( 'kb_invalid_' . $field, $message, array( 'status' => 400 ) );
	}
}
