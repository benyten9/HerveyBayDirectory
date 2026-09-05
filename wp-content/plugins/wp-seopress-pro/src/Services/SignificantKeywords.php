<?php

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableInterface;
use SEOPressPro\Core\Table\TableFactory;
use SEOPressPro\Models\Table\TableStructure;
use SEOPressPro\Models\Table\TableColumn;
use SEOPressPro\Models\Table\Table;

class SignificantKeywords {

	/**
	 * The readable text of a post, whatever stores it.
	 *
	 * Every consumer of this service goes through here: the save-time indexer,
	 * the suggestions REST route and the classic metabox. It used to return
	 * `post_content` untouched, which is empty or unreadable on any builder
	 * that keeps its text elsewhere:
	 *
	 * - block builders write the words into block attributes inside an HTML
	 *   comment, and `cleanContent()` strips comments together with everything
	 *   in them, so nothing survives to be indexed (Divi 5);
	 * - shortcode builders leave the tag names behind as vocabulary and the
	 *   generated text out of reach.
	 *
	 * Rendering alone is not enough. A builder can legitimately render nothing
	 * on a given pass: on a real Divi 5 site the editor saves each post twice
	 * and the second pass rendered 0 characters where the first rendered
	 * 34,695. Collecting the block attribute strings as well makes the result
	 * stable across both passes, which is what made the difference in
	 * production.
	 *
	 * @since 10.2.0 Renders blocks and shortcodes, and falls back on block
	 *               attribute text.
	 *
	 * @param \WP_Post $post The post to read.
	 *
	 * @return string
	 */
	public function getFullContentByPost( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		setup_postdata( $post );

		// Builders echo instead of returning during render; swallow that rather
		// than letting it reach the REST response or the metabox markup.
		ob_start();

		$raw     = (string) $post->post_content;
		$content = $raw;

		if ( $this->shouldRenderContent( $post ) ) {
			if ( function_exists( 'has_blocks' ) && has_blocks( $raw ) ) {
				$content = do_blocks( $content );
			}

			// The historical filter reads as "disable", and its default was the
			// string '__return_true' compared against false, so the shortcode
			// branch never ran. Honour it with its documented meaning while
			// keeping the old escape hatch working: only an explicit false (the
			// value the previous code demanded) still disables expansion.
			$disable_shortcodes = apply_filters( 'seopress_pro_significant_kw_disable_shortcode', false );

			if ( false === $disable_shortcodes ) {
				$content = do_shortcode( $content );
			}

			// Attribute text is the safety net: it is readable whether or not
			// the builder rendered anything on this pass.
			$attributes = $this->collectBlockAttributeText( $raw );

			if ( '' !== $attributes ) {
				$content .= ' ' . $attributes;
			}
		}

		ob_end_clean();

		wp_reset_postdata();

		/**
		 * Filter the text the keyword extraction reads for a post.
		 *
		 * The last resort for a builder that renders outside WordPress: return
		 * its text here and internal linking works without waiting for us to
		 * support it explicitly.
		 *
		 * @since 10.2.0
		 *
		 * @param string   $content The text about to be analysed.
		 * @param \WP_Post $post    The post it came from.
		 * @param string   $raw     The untouched post_content.
		 */
		return (string) apply_filters( 'seopress_pro_significant_kw_content', $content, $post, $raw );
	}

	/**
	 * Whether rendering should be attempted for this post.
	 *
	 * Rendering executes dynamic block callbacks and shortcodes, so it is not
	 * free: it runs on every save and on every render of the suggestions panel.
	 * Skip it outright when there is nothing to render.
	 *
	 * @since 10.2.0
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return bool
	 */
	protected function shouldRenderContent( $post ) {
		$raw = (string) $post->post_content;

		if ( '' === trim( $raw ) ) {
			return false;
		}

		$has_markup = ( function_exists( 'has_blocks' ) && has_blocks( $raw ) ) || false !== strpos( $raw, '[' );

		/**
		 * Filter whether the post content is rendered before keyword extraction.
		 *
		 * @since 10.2.0
		 *
		 * @param bool     $should_render Whether to render.
		 * @param \WP_Post $post          The post.
		 */
		return (bool) apply_filters( 'seopress_pro_significant_kw_render_content', $has_markup, $post );
	}

	/**
	 * Collect the prose held in block attributes.
	 *
	 * Only strings that read like sentences are kept. Attribute values are
	 * mostly configuration — colour codes, unit names, identifiers, booleans —
	 * and indexing those puts markup vocabulary at the top of the frequency
	 * list. A production table built by an unfiltered version of this had
	 * `font`, `divi` and `uua` outranking the article's own subject.
	 *
	 * @since 10.2.0
	 *
	 * @param string $raw The untouched post_content.
	 *
	 * @return string
	 */
	protected function collectBlockAttributeText( $raw ) {
		if ( ! function_exists( 'parse_blocks' ) || false === strpos( $raw, '<!-- wp:' ) ) {
			return '';
		}

		$collected = array();

		$this->walkBlocksForAttributeText( parse_blocks( $raw ), $collected );

		return implode( ' ', $collected );
	}

	/**
	 * Walk a parsed block tree, gathering prose-looking attribute strings.
	 *
	 * @since 10.2.0
	 *
	 * @param array $blocks    Parsed blocks.
	 * @param array $collected Accumulator, by reference.
	 *
	 * @return void
	 */
	protected function walkBlocksForAttributeText( $blocks, &$collected ) {
		if ( ! is_array( $blocks ) ) {
			return;
		}

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				array_walk_recursive(
					$block['attrs'],
					function ( $value ) use ( &$collected ) {
						if ( $this->looksLikeProse( $value ) ) {
							$collected[] = $value;
						}
					}
				);
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walkBlocksForAttributeText( $block['innerBlocks'], $collected );
			}
		}
	}

	/**
	 * Whether an attribute value is text a reader would see.
	 *
	 * Deliberately strict: a missed sentence costs a few keywords, an accepted
	 * identifier pollutes the frequency ranking the matcher relies on.
	 *
	 * @since 10.2.0
	 *
	 * @param mixed $value The attribute value.
	 *
	 * @return bool
	 */
	protected function looksLikeProse( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = trim( wp_strip_all_tags( $value ) );

		// Two words minimum: single tokens are overwhelmingly identifiers,
		// class names, units and colour keywords.
		if ( ! preg_match( '/\p{L}\p{L}+\s+\p{L}\p{L}+/u', $value ) ) {
			return false;
		}

		// Structural values that happen to contain a space.
		if ( preg_match( '/^(#[0-9a-f]{3,8}|https?:\/\/|data:|\{|\[)/i', $value ) ) {
			return false;
		}

		// CSS declarations and JSON fragments.
		if ( preg_match( '/[{}]|:\s*\d+(px|rem|em|%)|;\s*\w+\s*:/i', $value ) ) {
			return false;
		}

		return true;
	}

	public function cleanContent( $content ) {
		$content = strtolower( $content );
		$content = sanitize_text_field( $content );

		return $content;
	}

	public function getWordsByGroup( $content ) {
		$count = preg_match_all( '/\pL+/u', $content, $matches );

		return array_count_values( $matches[0] );
	}

	public function getTotalWords( $content ) {
		return array_sum( $this->getWordsByGroup( $content ) );
	}

	/**
	 *
	 * @param string $content
	 * @return array
	 */
	public function retrieveSignificantKeywords( $content ) {
		$content = $this->cleanContent( $content );

		if ( strlen( $content ) < apply_filters( 'seopress_pro_minimum_length_content_linking', 200 ) ) {
			return array();
		}

		$languages = array(
			'ar',
			'hy',
			'eu',
			'bg',
			'ca',
			'ceb',
			'zh',
			'cs',
			'da',
			'nl',
			'en',
			'es',
			'et',
			'fi',
			'fr',
			'de',
			'el',
			'gu',
			'he',
			'hi',
			'hu',
			'id',
			'it',
			'ja',
			'lv',
			'ml',
			'no',
			'fa',
			'pt',
			'ro',
			'ru',
			'sk',
			'sv',
			'tl',
			'th',
			'tr',
			'ukr',
			've',
		);

		$languagesSupported = apply_filters( 'seopress_pro_stop_words_languages_supported_keywords', $languages );
		$stopWords          = seopress_pro_get_service( 'StopWords' )->setLanguages( $languagesSupported );
		$content            = $stopWords->clean( $content );

		$words = $this->getWordsByGroup( $content );

		$words = array_filter(
			$words,
			function ( $item ) {
				return strlen( $item ) >= apply_filters( 'seopress_pro_significant_keyword_min_length', 3 );
			},
			ARRAY_FILTER_USE_KEY
		);
		arsort( $words );

		$words = array_slice( $words, 0, 20 );

		return $words;
	}

	public function prepareWordsToInsert( $words, $postId, $content ) {
		$data  = array();
		$total = $this->getTotalWords( $content );
		foreach ( $words as $word => $count ) {
			$tf = $count / max( 1, $total ); // prevent div by 0
			$tf = str_replace( ',', '.', $tf );

			$data[] = array(
				'post_id' => $postId,
				'word'    => $word,
				'count'   => $count,
				'tf'      => $tf,
			);
		}

		return $data;
	}

	/**
	 *
	 * @param array  $words
	 * @param string $content
	 * @param int    $postId
	 * @return array
	 */
	public function computeKeywords( $words, $content, $postId ) {
		$data    = array();
		$content = $this->cleanContent( $content );

		$total          = $this->getTotalWords( $content );
		$totalDocuments = seopress_pro_get_service( 'SignificantKeywordsRepository' )->countAllDocuments();

		$limit = apply_filters( 'seopress_pro_significant_keywords_limit', 5 );
		$y     = 0;
		foreach ( $words as $word => $count ) {
			if ( $y >= $limit ) {
				continue;
			}
			$allWordsCorrespondent = seopress_pro_get_service( 'SignificantKeywordsRepository' )->getAllWordsCorrespondent( $word, $postId );
			if ( empty( $allWordsCorrespondent ) ) {
				continue;
			}
			$totalWordsCorrespondent = count( $allWordsCorrespondent );
			$idf                     = log10( $totalDocuments / max( 1, $totalWordsCorrespondent ) );
			$idf                     = is_infinite( $idf ) ? 0 : $idf;

			$bestCorrespondent = null;
			$bestScore         = 0;
			$i                 = 0;
			do {
				$wordCorrespondent = (array) $allWordsCorrespondent[ $i ];
				$score             = $wordCorrespondent['tf'] * $idf;

				if ( $score > $bestScore ) {
					$bestCorrespondent = $wordCorrespondent;
					$bestScore         = $score;
				}
				++$i;
			} while ( isset( $allWordsCorrespondent[ $i ] ) );

			$data[] = array(
				'word'       => $word,
				'count'      => (int) $count,
				'documents'  => $totalWordsCorrespondent,
				'idf'        => $idf,
				'suggestion' => $bestCorrespondent,
				'score'      => isset( $bestCorrespondent['tf'] ) ? $bestCorrespondent['tf'] * $idf : 0,
				'title'      => isset( $bestCorrespondent['post_id'] ) ? get_the_title( $bestCorrespondent['post_id'] ) : '',
				'post_id'    => isset( $bestCorrespondent['post_id'] ) ? $bestCorrespondent['post_id'] : null,
			);
			++$y;
		}

		// Sort the table by score
		usort(
			$data,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? -1 : 1;
			}
		);

		// We suggest only one keyword per post (the most relevant).
		$temp = array_unique( array_column( $data, 'post_id' ) );
		// This retrieves the first ID that has been sorted.
		$data = array_intersect_key( $data, $temp );

		foreach ( $data as $key => $item ) {
			$data[ $key ]['permalink'] = get_permalink( $item['post_id'] );

			$editLink = '';
			try {
				$post             = get_post( $item['post_id'] );
				$post_type_object = get_post_type_object( $post->post_type );
				$action           = '&action=edit';
				$editLink         = admin_url( sprintf( $post_type_object->_edit_link . $action, $post->ID ) );
			} catch ( \Exception $e ) {
			}

			$data[ $key ]['edit_link'] = $editLink;
		}

		return $data;
	}
}
