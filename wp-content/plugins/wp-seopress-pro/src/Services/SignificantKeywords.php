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
	 * @since 10.4.0 Reads the text held in custom fields, ACF included.
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

			if ( $this->shouldExpandShortcodes() ) {
				$content = do_shortcode( $content );
			}

			// Attribute text is the safety net: it is readable whether or not
			// the builder rendered anything on this pass.
			$attributes = $this->collectBlockAttributeText( $raw );

			if ( '' !== $attributes ) {
				$content .= ' ' . $attributes;
			}
		}

		// Custom fields are read whatever `post_content` holds, outside the
		// render gate above: a post whose visible text lives entirely in ACF
		// stores nothing in `post_content`, which is precisely the case the
		// gate refuses to render.
		$custom_fields = $this->collectCustomFieldText( $post );

		if ( '' !== $custom_fields ) {
			$content .= ' ' . $custom_fields;
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
	 * Whether shortcodes are expanded before the text is read.
	 *
	 * The historical filter reads as "disable", and its default was the string
	 * '__return_true' compared against false, so the shortcode branch never
	 * ran. Honour it with its documented meaning while keeping the old escape
	 * hatch working: only an explicit false (the value the previous code
	 * demanded) still disables expansion.
	 *
	 * @since 10.4.0
	 *
	 * @return bool
	 */
	protected function shouldExpandShortcodes() {
		return false === apply_filters( 'seopress_pro_significant_kw_disable_shortcode', false );
	}

	/**
	 * The meta keys whose text joins the post content.
	 *
	 * ACF keys are discovered from storage rather than from the plugin: for
	 * every field `foo` it saves, ACF writes a sibling `_foo` holding the field
	 * key (`field_xxxxxxxx`). Reading the pair back needs neither ACF loaded
	 * nor its field groups walked, and it picks up repeater and group
	 * subfields, which are stored under flattened keys of the same shape.
	 *
	 * @since 10.4.0
	 *
	 * @param \WP_Post $post The post being analysed.
	 *
	 * @return string[]
	 */
	protected function getCustomFieldKeys( $post ) {
		/**
		 * Filter the custom fields the keyword extraction reads.
		 *
		 * Defaults to the ACF fields stored on the post. Append the meta keys
		 * of any other builder that keeps its text in post meta, or return an
		 * empty array to read nothing but `post_content`.
		 *
		 * @since 10.4.0
		 *
		 * @param string[] $keys The meta keys to read.
		 * @param \WP_Post $post The post being analysed.
		 */
		$keys = apply_filters( 'seopress_pro_significant_kw_custom_fields', $this->discoverAcfFieldKeys( $post ), $post );

		if ( ! is_array( $keys ) ) {
			return array();
		}

		$clean = array();

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === trim( $key ) ) {
				continue;
			}

			$clean[] = trim( $key );
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The ACF fields stored on a post.
	 *
	 * @since 10.4.0
	 *
	 * @param \WP_Post $post The post being analysed.
	 *
	 * @return string[]
	 */
	protected function discoverAcfFieldKeys( $post ) {
		$meta = get_post_meta( $post->ID );

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return array();
		}

		$keys = array();

		foreach ( $meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || '_' !== substr( $meta_key, 0, 1 ) ) {
				continue;
			}

			$reference = is_array( $values ) ? reset( $values ) : $values;

			if ( ! $this->isAcfFieldReference( $reference ) ) {
				continue;
			}

			$field_key = substr( $meta_key, 1 );

			// The reference is only meaningful next to the field it describes.
			// This also rules out a bare underscore: it reads as an empty meta
			// key, which the meta API answers with every row the post has.
			if ( ! isset( $meta[ $field_key ] ) ) {
				continue;
			}

			// The field type is checked later, in collectCustomFieldText(),
			// once the filter has had its say and only for values that hold
			// text: with field groups stored in the database, each lookup can
			// cost a query.
			$keys[] = $field_key;
		}

		return $keys;
	}

	/**
	 * Whether a sibling meta value is an ACF field key.
	 *
	 * Matched on the prefix, which is all ACF itself checks in
	 * `acf_is_field_key()`. A hexadecimal shape is what the admin generates,
	 * not what the format allows: a Clone field in seamless mode, which is the
	 * default, stores `field_aaa_field_bbb`, and a field declared in code with
	 * acf-builder or ACF Composer has a readable key such as
	 * `field_hero_title`. Both were being skipped.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $reference The sibling value.
	 *
	 * @return bool
	 */
	protected function isAcfFieldReference( $reference ) {
		if ( ! is_string( $reference ) || 0 !== strpos( $reference, 'field_' ) ) {
			return false;
		}

		// Something has to follow the prefix: a bare `field_` names nothing.
		return '' !== substr( $reference, 6 );
	}

	/**
	 * Whether an ACF field holds text a reader is meant to see.
	 *
	 * Only asked when ACF is loaded, which is the only way to know a field's
	 * type. Without it every field is read, which is what this branch did for
	 * all of them: a `password` field is stored in clear, and an internal note
	 * or an admin-only field is not page text either.
	 *
	 * @since 10.4.0
	 *
	 * @param string $reference The ACF field key.
	 *
	 * @return bool
	 */
	protected function isReadableAcfFieldType( $reference ) {
		$field = $this->getAcfField( $reference );

		// ACF is not loaded, or the reference is an orphan left behind by a
		// deleted field: the type is unknown.
		if ( ! is_array( $field ) || empty( $field['type'] ) ) {
			return true;
		}

		/**
		 * Filter the ACF field types read for keyword extraction.
		 *
		 * @since 10.4.0
		 *
		 * @param string[] $types The field types whose value is page text.
		 */
		$types = apply_filters(
			'seopress_pro_significant_kw_acf_field_types',
			array( 'text', 'textarea', 'wysiwyg' )
		);

		return in_array( $field['type'], (array) $types, true );
	}

	/**
	 * The ACF definition of a field, or null when ACF is not loaded.
	 *
	 * @since 10.4.0
	 *
	 * @param string $reference The ACF field key.
	 *
	 * @return array|false|null
	 */
	protected function getAcfField( $reference ) {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return null;
		}

		return acf_get_field( $reference );
	}

	/**
	 * The ACF field key stored next to a meta key, or null.
	 *
	 * @since 10.4.0
	 *
	 * @param int    $post_id  The post.
	 * @param string $meta_key The meta key.
	 *
	 * @return string|null
	 */
	protected function getAcfReference( $post_id, $meta_key ) {
		$reference = get_post_meta( $post_id, '_' . $meta_key, true );

		return $this->isAcfFieldReference( $reference ) ? $reference : null;
	}

	/**
	 * Collect the prose held in custom fields.
	 *
	 * Same reading as block attributes, and for the same reason: a field holds
	 * whatever the site builder put in it, so an unfiltered sweep indexes
	 * colour codes, URLs and identifiers alongside the text and starves the
	 * matcher, which ranks by frequency.
	 *
	 * @since 10.4.0
	 *
	 * @param \WP_Post $post The post being analysed.
	 *
	 * @return string
	 */
	protected function collectCustomFieldText( $post ) {
		// A password protected post is not readable text: its body is behind a
		// form. The keyword index feeds internal link suggestions, which anyone
		// with edit_posts can query, so what the author put in its fields has no
		// business being there, whether the key was discovered or added through
		// the filter. `post_content` has always been indexed for these posts and
		// still is; this only declines to widen it.
		if ( isset( $post->post_password ) && '' !== (string) $post->post_password ) {
			return '';
		}

		$keys = $this->getCustomFieldKeys( $post );

		if ( empty( $keys ) ) {
			return '';
		}

		$collected = array();

		foreach ( $keys as $key ) {
			$values = get_post_meta( $post->ID, $key, false );

			if ( ! is_array( $values ) || ! $this->holdsText( $values ) ) {
				continue;
			}

			// Only now ask ACF for the type: a field holding no text costs
			// nothing, and neither does a sweep the filter switched off.
			$reference = $this->getAcfReference( $post->ID, $key );

			if ( null !== $reference && ! $this->isReadableAcfFieldType( $reference ) ) {
				continue;
			}

			array_walk_recursive(
				$values,
				function ( $value ) use ( &$collected ) {
					$text = $this->readableMetaValue( $value );

					if ( '' !== $text ) {
						$collected[] = $text;
					}
				}
			);
		}

		return implode( ' ', $collected );
	}

	/**
	 * Whether stored values contain anything that could be text.
	 *
	 * Cheap on purpose: it runs before the field type lookup, to spare it on
	 * empty fields, numbers, IDs and serialized payloads.
	 *
	 * @since 10.4.0
	 *
	 * @param array $values The stored values of one meta key.
	 *
	 * @return bool
	 */
	protected function holdsText( $values ) {
		$found = false;

		array_walk_recursive(
			$values,
			function ( $value ) use ( &$found ) {
				if ( $found || ! is_string( $value ) ) {
					return;
				}

				$value = trim( $value );

				if ( '' !== $value && ! is_numeric( $value ) && ! is_serialized( $value ) ) {
					$found = true;
				}
			}
		);

		return $found;
	}

	/**
	 * The readable text of a single meta value, or an empty string.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string
	 */
	protected function readableMetaValue( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		// A serialized payload is storage, not prose: its class and property
		// names would be indexed as vocabulary.
		if ( is_serialized( $value ) ) {
			return '';
		}

		// Expand before reading: a WYSIWYG field is stored with its shortcodes
		// unrendered, and the tags alone read as markup, not as sentences.
		if ( $this->shouldExpandShortcodes() && false !== strpos( $value, '[' ) ) {
			$value = do_shortcode( $value );
		}

		return $this->collectFieldProse( $value );
	}

	/**
	 * The prose inside one field value.
	 *
	 * `looksLikeProse()` was written for block attributes, which are short
	 * configuration strings, and asking it about a whole field body made one
	 * hit reject a two thousand word WYSIWYG. `Taux de TVA : 20%` matches the
	 * unit rule, `terms &amp; conditions:` and `&nbsp;Lundi :` match the CSS
	 * declaration rule once TinyMCE's entities are in play, and a single brace
	 * anywhere in the text is enough on its own.
	 *
	 * So the value is decoded, split on its own line breaks, and judged line by
	 * line. A long line is held to the two word minimum only: the structural
	 * rules exist to recognise configuration, and configuration is short.
	 *
	 * @since 10.4.0
	 *
	 * @param string $value The stored field value.
	 *
	 * @return string
	 */
	protected function collectFieldProse( $value ) {
		// Drop invalid UTF-8 first: one stray byte makes every /u regex below
		// fail, and the whole field was lost with it.
		$value = wp_check_invalid_utf8( $value, true );
		$value = wp_strip_all_tags( $value );

		// TinyMCE stores its text with entities, and the semicolon ending one
		// is what tripped the CSS declaration rule: `&amp; tarifs :` reads as
		// `; tarifs :`. The `/u` modifier makes \s match the non breaking
		// space a decoded `&nbsp;` leaves behind, so the collapsing below
		// handles that one.
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

		$lines = preg_split( '/\R+/u', $value );

		if ( ! is_array( $lines ) ) {
			$lines = array( $value );
		}

		/**
		 * Filter the length above which a field line is read as prose on the
		 * strength of its words alone.
		 *
		 * @since 10.4.0
		 *
		 * @param int $length Bytes, as counted by strlen(). Default 200.
		 */
		$long = (int) apply_filters( 'seopress_pro_significant_kw_prose_line_length', 200 );

		$kept = array();

		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/\s+/u', ' ', $line ) );

			if ( '' === $line ) {
				continue;
			}

			if ( strlen( $line ) >= $long ) {
				// Two words minimum still applies: that is the rule that
				// recognises text rather than the rules that recognise markup.
				if ( preg_match( '/\p{L}\p{L}+\s+\p{L}\p{L}+/u', $line ) ) {
					$kept[] = $line;
				}

				continue;
			}

			if ( $this->looksLikeProse( $line ) ) {
				$kept[] = $line;
			}
		}

		return implode( ' ', $kept );
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

		// How many suggestions come back, not how many words are examined: $y only
		// advances once a suggestion has been produced, so a word with no
		// correspondent costs nothing and the walk continues through the whole list.
		// Read as a cap on the walk, this looks like five unshared words starving the
		// matcher, which is what #1690 reported. See
		// test-internal-linking-low-ranked-word.php.
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
