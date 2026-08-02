<?php

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableInterface;
use SEOPressPro\Core\Table\TableFactory;
use SEOPressPro\Models\Table\TableStructure;
use SEOPressPro\Models\Table\TableColumn;
use SEOPressPro\Models\Table\Table;

class SignificantKeywords {

	public function getFullContentByPost( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		setup_postdata( $post );

		ob_start();

		$disabled = apply_filters( 'seopress_pro_significant_kw_disable_shortcode', '__return_true' );

		if ( $disabled === false ) {
			$content = \do_shortcode( $post->post_content );
		} else {
			$content = $post->post_content;
		}

		ob_end_clean();

		wp_reset_postdata();

		return $content;
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
