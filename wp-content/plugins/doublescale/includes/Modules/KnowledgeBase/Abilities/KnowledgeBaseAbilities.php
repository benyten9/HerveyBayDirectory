<?php
/**
 * Help-center abilities for the WordPress Abilities API (and so MCP clients).
 *
 * Read-only, for helpdesk staff: search the published articles and read one,
 * team-only ones included — each result says who may read it, so an agent can
 * tell a customer-facing answer from an internal note. Drafts are never
 * returned.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleSearch;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleService;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;

final class KnowledgeBaseAbilities {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( KbAccess::class, 'can_read' );

		return array(
			'doublescale/search-help-articles' => array(
				'module_slug'      => 'knowledge_base',
				'label'            => __( 'Search help articles', 'doublescale' ),
				'description'      => __( 'Search the published help-center articles by words in their title or text. Each result says who may read it: public (anyone), portal (signed-in clients) or internal (the team only).', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Words to look for. Arabic and English both work.',
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 10,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'search_articles' ),
			),

			'doublescale/get-help-article'     => array(
				'module_slug'      => 'knowledge_base',
				'label'            => __( 'Get help article', 'doublescale' ),
				'description'      => __( 'One published help-center article with its full text as plain text, its category and who may read it.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Article id from search-help-articles.',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_article' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function search_articles( array $input ): array {
		$limit  = AbilityResult::limit( $input, 10, 50 );
		$offset = AbilityResult::offset( $input );

		$query = self::published()->with( 'category' );
		ArticleSearch::apply( $query, isset( $input['search'] ) ? (string) $input['search'] : '' );

		$total = (int) ( clone $query )->count();
		$rows  = $query->orderBy( 'position', 'asc' )->orderBy( 'title', 'asc' )->offset( $offset )->limit( $limit )->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape( $row, false );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_article( array $input ) {
		$article = self::published()->with( 'category' )->where( 'id', (int) ( $input['id'] ?? 0 ) )->first();
		if ( ! $article instanceof KbArticleModel ) {
			return AbilityResult::not_found( __( 'Article not found.', 'doublescale' ) );
		}

		return self::shape( $article, true );
	}

	/**
	 * Published articles of every visibility (staff-only ability).
	 *
	 * @return object Eloquent builder.
	 */
	private static function published() {
		return KbArticleModel::query()->where( 'status', KbArticleModel::STATUS_PUBLISHED );
	}

	/**
	 * @param KbArticleModel $article   Article.
	 * @param bool           $with_text Include the body as plain text.
	 * @return array<string, mixed>
	 */
	private static function shape( KbArticleModel $article, bool $with_text ): array {
		$category   = $article->category;
		$visibility = (string) $article->visibility;

		$out = array(
			'id'         => (int) $article->id,
			'title'      => (string) $article->title,
			'excerpt'    => ArticleService::display_excerpt( $article ),
			'category'   => $category ? (string) $category->name : null,
			'visibility' => $visibility,
			// Only articles customers can open have a link worth sharing.
			'url'        => KbArticleModel::VISIBILITY_INTERNAL === $visibility ? null : ( KbPublicArticles::article_url( (string) $article->slug ) ?: null ),
			'updated_at' => (string) $article->updated_at,
		);

		if ( $with_text ) {
			$text             = AbilityResult::truncate( (string) $article->content, 8000 );
			$out['text']      = $text['text'];
			$out['truncated'] = $text['truncated'];
		}

		return $out;
	}
}
