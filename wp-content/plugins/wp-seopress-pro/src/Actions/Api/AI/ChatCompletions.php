<?php
/**
 * AI Assistant Chat Completions API.
 *
 * @package SEOPress PRO
 * @subpackage API
 */

namespace SEOPressPro\Actions\Api\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Class ChatCompletions
 *
 * REST API endpoint for AI Assistant chat functionality.
 *
 * @since 9.6.0
 */
class ChatCompletions implements ExecuteHooks {

	/**
	 * System prompt with SEOPress documentation.
	 *
	 * @var string
	 */
	private $system_prompt = '';

	/**
	 * Whether the last AI response was cut off by the output token limit.
	 *
	 * @var bool
	 */
	private $last_truncated = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_action( 'wp_ajax_seopress_ai_stream_chat', array( $this, 'stream_chat_ajax' ) );
	}

	/**
	 * Register REST route.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/ai/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_chat' ),
				'permission_callback' => function () {
					if ( ! current_user_can( 'edit_posts' ) ) {
						return false;
					}
					if ( function_exists( 'seopress_ai_generation_check' ) ) {
						return seopress_ai_generation_check( null, 'edit_posts', 'chat' );
					}
					return true;
				},
				'args'                => array(
					'messages'     => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_array( $param );
						},
						'sanitize_callback' => function ( $param ) {
							return $this->sanitize_messages( $param );
						},
					),
					'post_context' => array(
						'default'           => null,
						'sanitize_callback' => function ( $param ) {
							return $this->sanitize_post_context( $param );
						},
					),
					'command'      => array(
						'default'           => null,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							if ( null === $param || '' === $param ) {
								return true;
							}
							$valid = array( 'title', 'description', 'social', 'translate', 'outline', 'schema', 'links', 'fix', 'keywords', 'write', 'faq', 'proofread', 'tone', 'summarize', 'product' );
							return in_array( $param, $valid, true );
						},
					),
					'command_args' => array(
						'default'           => null,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_id'      => array(
						'default'           => null,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Sanitize messages array.
	 *
	 * @param array $messages Messages array.
	 * @return array Sanitized messages.
	 */
	private function sanitize_messages( $messages ) {
		$sanitized = array();

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}

			$sanitized[] = array(
				'role'    => sanitize_text_field( $message['role'] ),
				'content' => sanitize_textarea_field( $message['content'] ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize post context.
	 *
	 * @param array|null $context Post context array.
	 * @return array|null Sanitized context.
	 */
	private function sanitize_post_context( $context ) {
		if ( ! is_array( $context ) ) {
			return null;
		}

		$sanitized = array();

		if ( isset( $context['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( $context['title'] );
		}

		if ( isset( $context['excerpt'] ) ) {
			$sanitized['excerpt'] = sanitize_textarea_field( $context['excerpt'] );
		}

		if ( isset( $context['content'] ) ) {
			// Limit content to 6000 characters (~1500 tokens).
			$sanitized['content'] = sanitize_textarea_field( substr( $context['content'], 0, 6000 ) );
		}

		if ( isset( $context['url'] ) ) {
			$sanitized['url'] = esc_url_raw( $context['url'] );
		}

		if ( isset( $context['language'] ) ) {
			$sanitized['language'] = sanitize_text_field( $context['language'] );
		}

		// Article brief collected from the user before running the /write command.
		if ( isset( $context['write_brief'] ) && is_array( $context['write_brief'] ) ) {
			$brief                    = $context['write_brief'];
			$sanitized['write_brief'] = array(
				'audience'   => isset( $brief['audience'] ) ? sanitize_text_field( $brief['audience'] ) : '',
				'tone'       => isset( $brief['tone'] ) ? sanitize_text_field( $brief['tone'] ) : '',
				'length'     => isset( $brief['length'] ) ? sanitize_text_field( $brief['length'] ) : '',
				'key_points' => isset( $brief['key_points'] ) ? sanitize_textarea_field( $brief['key_points'] ) : '',
			);
		}

		if ( isset( $context['target_keywords'] ) ) {
			if ( is_array( $context['target_keywords'] ) ) {
				$sanitized['target_keywords'] = array_map( 'sanitize_text_field', $context['target_keywords'] );
			} else {
				$sanitized['target_keywords'] = sanitize_text_field( $context['target_keywords'] );
			}
		}

		if ( isset( $context['analysis_results'] ) && is_array( $context['analysis_results'] ) ) {
			$sanitized['analysis_results'] = array();
			foreach ( $context['analysis_results'] as $result ) {
				if ( isset( $result['title'] ) && isset( $result['status'] ) ) {
					$sanitized['analysis_results'][] = array(
						'title'       => sanitize_text_field( $result['title'] ),
						'status'      => sanitize_text_field( $result['status'] ),
						'description' => isset( $result['description'] ) ? sanitize_text_field( $result['description'] ) : '',
					);
				}
			}
		}

		if ( isset( $context['image_urls'] ) && is_array( $context['image_urls'] ) ) {
			$sanitized['image_urls'] = array();
			foreach ( $context['image_urls'] as $url ) {
				$clean = esc_url_raw( $url );
				if ( ! empty( $clean ) ) {
					$sanitized['image_urls'][] = $clean;
				}
			}
		}

		if ( isset( $context['indexed_blocks'] ) ) {
			$sanitized['indexed_blocks'] = sanitize_textarea_field( substr( $context['indexed_blocks'], 0, 8000 ) );
		}

		if ( isset( $context['reference_posts'] ) && is_array( $context['reference_posts'] ) ) {
			$sanitized['reference_posts'] = array();
			foreach ( $context['reference_posts'] as $ref ) {
				$sanitized['reference_posts'][] = array(
					'title'   => isset( $ref['title'] ) ? sanitize_text_field( $ref['title'] ) : '',
					'url'     => isset( $ref['url'] ) ? esc_url_raw( $ref['url'] ) : '',
					'type'    => isset( $ref['type'] ) ? sanitize_text_field( $ref['type'] ) : '',
					'content' => isset( $ref['content'] ) ? sanitize_textarea_field( substr( $ref['content'], 0, 4000 ) ) : '',
					'excerpt' => isset( $ref['excerpt'] ) ? sanitize_text_field( $ref['excerpt'] ) : '',
				);
			}
		}

		if ( isset( $context['block_context'] ) && is_array( $context['block_context'] ) ) {
			$block_ctx = $context['block_context'];
			$sanitized_block = array(
				'block_names' => isset( $block_ctx['block_names'] ) && is_array( $block_ctx['block_names'] )
					? array_map( 'sanitize_text_field', $block_ctx['block_names'] )
					: array(),
				'count'       => isset( $block_ctx['count'] ) ? absint( $block_ctx['count'] ) : 0,
				'content'     => isset( $block_ctx['content'] ) ? sanitize_textarea_field( substr( $block_ctx['content'], 0, 3000 ) ) : '',
			);

			// Media block attributes.
			$media_fields = array( 'image_url', 'image_alt', 'image_caption', 'video_url', 'video_caption', 'audio_url', 'audio_caption', 'file_url', 'file_name', 'embed_url', 'embed_caption' );
			foreach ( $media_fields as $field ) {
				if ( ! empty( $block_ctx[ $field ] ) ) {
					if ( false !== strpos( $field, '_url' ) ) {
						$sanitized_block[ $field ] = esc_url_raw( $block_ctx[ $field ] );
					} else {
						$sanitized_block[ $field ] = sanitize_text_field( $block_ctx[ $field ] );
					}
				}
			}
			if ( ! empty( $block_ctx['image_id'] ) ) {
				$sanitized_block['image_id'] = absint( $block_ctx['image_id'] );
			}

			$sanitized['block_context'] = $sanitized_block;
		}

		return $sanitized;
	}

	/**
	 * Get the system prompt with SEOPress documentation.
	 *
	 * @param array|null $post_context   Optional post context.
	 * @param int|null   $post_id        Optional post ID.
	 * @param string     $context_level  Context level: 'block', 'page', or 'full'.
	 * @return string System prompt.
	 */
	private function get_system_prompt( $post_context = null, $post_id = null, $context_level = 'page' ) {
		$prompt = __(
			'You are a helpful SEO assistant for SEOPress, a WordPress SEO plugin.

## Your Role
- Answer questions about SEO best practices
- Explain SEOPress features and settings
- Provide actionable optimization advice
- Help users understand SEO concepts

## SEOPress Features Documentation

### Titles & Metas
- Meta title: Maximum 60 characters recommended, set in the SEO metabox
- Meta description: Maximum 160 characters recommended
- Dynamic variables available: %%title%%, %%sitename%%, %%sep%%, %%post_content%%, %%excerpt%%
- Settings location: SEOPress > Titles & Metas

### Content Analysis
- Target keywords: Set in the SEO metabox to analyze your content
- Readability score: Checks sentence length, paragraph structure
- Internal/external links check: Verifies linking strategy
- Image alt text verification: Ensures accessibility compliance

### XML Sitemaps
- Automatic generation for posts, pages, and custom post types
- Image sitemap support for better image indexing
- News sitemap for Google News publishers
- Video sitemap for video content
- Settings location: SEOPress > XML Sitemaps

### Structured Data (Schema)
- Automatic schemas: Article, Product, LocalBusiness, FAQ, HowTo
- Manual schema editor for custom structured data
- Schema types: Article, NewsArticle, BlogPosting, Product, LocalBusiness, FAQ, HowTo, Recipe, Event, Course, Job, Review, Service, SoftwareApplication, Video
- Settings location: SEOPress > PRO > Structured Data Types

### Redirections (PRO)
- Redirect types: 301 (permanent), 302 (temporary), 307, 410 (gone), 451
- Regex pattern support for advanced redirects
- 404 monitoring to catch broken links
- Import/export functionality
- Settings location: SEOPress > PRO > 404

### Google Analytics (PRO)
- Google Analytics 4 integration
- Event tracking for downloads, outbound links, affiliate links
- Cross-domain tracking support
- Settings location: SEOPress > PRO > Analytics

### Other Features
- Social media meta tags (Open Graph, Twitter Cards)
- Breadcrumbs with Schema markup
- Robots.txt editor
- .htaccess editor
- Broken link checker (Bot feature)
- Google Search Console integration (Inspect URL)
- PageSpeed Insights integration

## Guidelines
- Be concise and actionable in your responses
- Reference specific SEOPress settings locations when relevant
- You can modify the post content when the user asks you to (via block editing and meta updates)
- If asked about something outside SEO or SEOPress, politely redirect to SEO topics

## CRITICAL: Action-First Behavior
When the user asks you to DO something (translate, rewrite, move, fix, add, update, generate, etc.):
- DO IT IMMEDIATELY. Do not explain what you would do. Do not ask for confirmation. Do not list steps. JUST DO IT.
- Use the appropriate tags: [PROPOSED_CHANGES], [BLOCK_CONTENT], [INSERT_CONTENT], or [ACTION] tags
- Keep your explanation to ONE short sentence maximum, then provide the actual changes
- If the user says "translate the page" → output [PROPOSED_CHANGES] with ALL blocks translated. Do NOT say "I will translate" or ask which language.
- If the user says "move block 1 down" → output [ACTION]seopress/move-block|...[/ACTION] or [PROPOSED_CHANGES] with the reordered content
- If the user says "add a caption" → output [BLOCK_CONTENT]the caption text[/BLOCK_CONTENT]
- NEVER respond with "I will do X. Here is what I recommend:" — just do X.

## Conversation Behavior
- This is a continuous conversation. Maintain full context from all previous messages.
- When the user asks a follow-up question (e.g. "do it", "yes", "the second one", "add it"), refer to your previous response and act on it.
- Do not ask the user to repeat information that was already provided earlier in the conversation.
- If the user provides a keyword, text, or instruction, remember it for subsequent messages until they explicitly change the topic.
- Always respond in the same language as the user.

## Content Quality — CRITICAL
When generating content that will be inserted into a post (paragraphs, headings, descriptions, alt text, etc.), you MUST follow these rules strictly:
- NO AI slop: never use filler phrases like "In today\'s digital landscape", "It\'s worth noting that", "In conclusion", "Let\'s dive in", "Whether you\'re a beginner or expert", "In this comprehensive guide", "Unlock the power of", "Take your X to the next level"
- NO emojis in post content (they are acceptable only in chat responses)
- NO generic padding or fluff — every sentence must carry meaning
- Write like a skilled human writer, not like a language model
- Match the tone and style of the existing content on the page
- Be specific and concrete, avoid vague generalities
- Prefer short, direct sentences over complex constructions
- Do not start paragraphs with "This" or "It is"
- Never use the word "leverage" as a verb

## Bulk Block Changes (translate, rewrite, etc.)
When the user asks to modify multiple blocks (e.g. "translate everything", "rewrite all paragraphs", "fix all headings"), return the changes in [PROPOSED_CHANGES] tags with a JSON array.
Each item must have: block_index (integer matching the block numbers in the content), block_type (the block name like "core/heading" or "core/paragraph"), original (first 50 chars of original text), and proposed (the full new text).
Format: [PROPOSED_CHANGES][{"block_index": 0, "block_type": "core/heading", "original": "first words...", "proposed": "full new text"}, ...][/PROPOSED_CHANGES]
The user will review each change individually and can accept or reject them one by one.

## Language Rule — CRITICAL
- When generating or modifying POST CONTENT (text for blocks, titles, descriptions, alt text, captions), ALWAYS use the SAME LANGUAGE as the existing content on the page.
- Detect the language from the post title, content preview, and blocks provided in the context.
- If the page content is in French, write in French. If in Spanish, write in Spanish. Etc.
- This applies to [BLOCK_CONTENT], [INSERT_CONTENT], [PROPOSED_CHANGES], and all meta suggestions.
- Chat responses (explanations) should match the user language, but inserted content must match the PAGE language.

## Content Formatting for Block Editor
When the user asks you to write, draft, or generate content (paragraphs, sections, articles, tables, lists, etc.) that should be inserted into the post, wrap the content in [INSERT_CONTENT] and [/INSERT_CONTENT] tags.
Inside these tags, use clean HTML (not markdown) so it converts perfectly to WordPress blocks:
- Paragraphs: <p>text</p>
- Headings: <h2>title</h2>, <h3>subtitle</h3>
- Lists: <ul><li>item</li></ul> or <ol><li>item</li></ol>
- Tables: <table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table>
- Blockquotes: <blockquote><p>text</p></blockquote>
- Code: <pre><code>code here</code></pre>
- Bold/italic: <strong>bold</strong>, <em>italic</em>
Put your explanation and comments OUTSIDE these tags.
Example response: "Here is a paragraph about SEO:\n[INSERT_CONTENT]<p>Search engine optimization helps your site rank higher in search results.</p>[/INSERT_CONTENT]"',
			'wp-seopress-pro'
		);

		// Add persistent memory — always included (user preferences matter for all requests).
		$memory = \SEOPressPro\Actions\Api\AI\Memory::get_prompt_memory();
		if ( ! empty( $memory ) ) {
			$prompt .= $memory;
		}

		// Add available abilities — only for page-level context (not needed for block edits).
		if ( 'block' !== $context_level && function_exists( 'wp_get_abilities' ) ) {
			$abilities_text = $this->get_abilities_prompt();
			if ( ! empty( $abilities_text ) ) {
				$prompt .= $abilities_text;
			}
		}

		// Add post context if provided.
		if ( ! empty( $post_context ) ) {
			$prompt .= "\n\n" . __( '## Current Post Context', 'wp-seopress-pro' );

			if ( ! empty( $post_id ) ) {
				$prompt .= "\n" . sprintf( 'Post ID: %d (use this for all abilities that require post_id)', $post_id );
			}

			if ( ! empty( $post_context['title'] ) ) {
				/* translators: %s: post title */
				$prompt .= "\n" . sprintf( __( 'Title: %s', 'wp-seopress-pro' ), $post_context['title'] );
			}

			if ( ! empty( $post_context['url'] ) ) {
				/* translators: %s: post URL */
				$prompt .= "\n" . sprintf( __( 'URL: %s', 'wp-seopress-pro' ), $post_context['url'] );
			}

			if ( ! empty( $post_context['language'] ) ) {
				/* translators: %s: post language */
				$prompt .= "\n" . sprintf( __( 'Language: %s', 'wp-seopress-pro' ), $post_context['language'] );
			}

			if ( ! empty( $post_context['target_keywords'] ) ) {
				$keywords = is_array( $post_context['target_keywords'] )
					? implode( ', ', $post_context['target_keywords'] )
					: $post_context['target_keywords'];
				/* translators: %s: target keywords */
				$prompt .= "\n" . sprintf( __( 'Target Keywords: %s', 'wp-seopress-pro' ), $keywords );
			}

			if ( ! empty( $post_context['excerpt'] ) ) {
				/* translators: %s: post excerpt */
				$prompt .= "\n" . sprintf( __( 'Excerpt: %s', 'wp-seopress-pro' ), $post_context['excerpt'] );
			}

			// Content — skip for block-level edits (the block content is already provided).
			if ( 'block' !== $context_level && ! empty( $post_context['content'] ) ) {
				$content_text = substr( $post_context['content'], 0, 'page' === $context_level ? 4000 : 2000 );
				$prompt .= "\n" . sprintf( __( 'Content: %s', 'wp-seopress-pro' ), $content_text );
			}

			// SEO metadata — include for page context, skip for block edits.
			if ( 'block' !== $context_level && ! empty( $post_context['seo_metas'] ) && is_array( $post_context['seo_metas'] ) ) {
				$prompt .= "\n\n" . __( '### Current SEOPress SEO Settings', 'wp-seopress-pro' );
				$seo_labels = array(
					'seo_title'       => __( 'SEO Title', 'wp-seopress-pro' ),
					'seo_description' => __( 'Meta Description', 'wp-seopress-pro' ),
					'fb_title'        => __( 'Facebook Title', 'wp-seopress-pro' ),
					'fb_description'  => __( 'Facebook Description', 'wp-seopress-pro' ),
					'tw_title'        => __( 'X/Twitter Title', 'wp-seopress-pro' ),
					'tw_description'  => __( 'X/Twitter Description', 'wp-seopress-pro' ),
					'canonical'       => __( 'Canonical URL', 'wp-seopress-pro' ),
					'noindex'         => __( 'Noindex', 'wp-seopress-pro' ),
				);
				foreach ( $post_context['seo_metas'] as $key => $value ) {
					$label = isset( $seo_labels[ $key ] ) ? $seo_labels[ $key ] : $key;
					$prompt .= "\n" . sprintf( '%1$s: %2$s', $label, $value );
				}
			}

			// Indexed blocks — only for page context (bulk operations).
			if ( 'block' !== $context_level && ! empty( $post_context['indexed_blocks'] ) ) {
				$prompt .= "\n\n" . __( '### Page Blocks (indexed for bulk operations)', 'wp-seopress-pro' );
				$prompt .= "\n" . substr( $post_context['indexed_blocks'], 0, 4000 );
			}

			// Add reference posts attached by the user.
			if ( ! empty( $post_context['reference_posts'] ) && is_array( $post_context['reference_posts'] ) ) {
				$prompt .= "\n\n" . __( '### Reference Content (attached by user)', 'wp-seopress-pro' );
				foreach ( $post_context['reference_posts'] as $ref ) {
					$ref_title   = isset( $ref['title'] ) ? sanitize_text_field( $ref['title'] ) : '';
					$ref_type    = isset( $ref['type'] ) ? sanitize_text_field( $ref['type'] ) : 'post';
					$ref_url     = isset( $ref['url'] ) ? esc_url( $ref['url'] ) : '';
					$ref_content = isset( $ref['content'] ) ? sanitize_textarea_field( substr( $ref['content'], 0, 4000 ) ) : '';
					$ref_excerpt = isset( $ref['excerpt'] ) ? sanitize_text_field( $ref['excerpt'] ) : '';

					$prompt .= "\n\n" . sprintf( '#### %s (%s)', $ref_title, $ref_type );
					if ( ! empty( $ref_url ) ) {
						$prompt .= "\n" . sprintf( 'URL: %s', $ref_url );
					}
					if ( ! empty( $ref_excerpt ) ) {
						$prompt .= "\n" . sprintf( 'Excerpt: %s', $ref_excerpt );
					}
					if ( ! empty( $ref_content ) ) {
						$prompt .= "\n" . sprintf( 'Content: %s', $ref_content );
					}
				}
			}

			// Add content analysis results if available.
			if ( ! empty( $post_context['analysis_results'] ) && is_array( $post_context['analysis_results'] ) ) {
				$prompt .= "\n\n" . __( '### Content Analysis Results', 'wp-seopress-pro' );

				$issues  = array();
				$good    = array();

				foreach ( $post_context['analysis_results'] as $result ) {
					$status = isset( $result['status'] ) ? $result['status'] : '';
					$title  = isset( $result['title'] ) ? $result['title'] : '';

					if ( 'good' === $status ) {
						$good[] = $title;
					} else {
						$issues[] = $title . ( ! empty( $result['description'] ) ? ' - ' . $result['description'] : '' );
					}
				}

				if ( ! empty( $issues ) ) {
					$prompt .= "\n" . __( 'Issues to fix:', 'wp-seopress-pro' );
					foreach ( $issues as $issue ) {
						$prompt .= "\n- " . $issue;
					}
				}

				if ( ! empty( $good ) ) {
					$prompt .= "\n" . __( 'Good:', 'wp-seopress-pro' );
					foreach ( $good as $item ) {
						$prompt .= "\n- " . $item;
					}
				}
			}

			// Add selected block context if provided.
			if ( ! empty( $post_context['block_context'] ) ) {
				$block_ctx = $post_context['block_context'];
				$prompt   .= "\n\n" . __( '## Selected Block Context', 'wp-seopress-pro' );
				$prompt   .= "\n" . __( 'The user has attached one or more blocks from their editor. Focus your answer on this specific content.', 'wp-seopress-pro' );

				if ( ! empty( $block_ctx['block_names'] ) ) {
					/* translators: %s: comma-separated block names */
					$prompt .= "\n" . sprintf( __( 'Block type(s): %s', 'wp-seopress-pro' ), implode( ', ', $block_ctx['block_names'] ) );
				}

				if ( ! empty( $block_ctx['content'] ) ) {
					/* translators: %s: block HTML content */
					$prompt .= "\n" . sprintf( __( 'Block content:\n```\n%s\n```', 'wp-seopress-pro' ), $block_ctx['content'] );
				}

				// Add rich attributes for media blocks.
				if ( ! empty( $block_ctx['image_url'] ) ) {
					$prompt .= "\n" . sprintf( 'Image URL: %s', $block_ctx['image_url'] );
					$prompt .= "\n" . sprintf( 'Current alt text: %s', ! empty( $block_ctx['image_alt'] ) ? $block_ctx['image_alt'] : '(empty)' );
					$prompt .= "\n" . sprintf( 'Current caption: %s', ! empty( $block_ctx['image_caption'] ) ? $block_ctx['image_caption'] : '(empty)' );
					$prompt .= "\n" . 'This image ALREADY EXISTS in the Media Library — do NOT use seopress/upload-image or try to download it.';
					$prompt .= "\n" . 'To update alt text and caption, use [BLOCK_CONTENT] with the format: Alt text: ... Caption: ...';
				}
				if ( ! empty( $block_ctx['video_url'] ) ) {
					$prompt .= "\n" . sprintf( 'Video URL: %s', $block_ctx['video_url'] );
				}
				if ( ! empty( $block_ctx['embed_url'] ) ) {
					$prompt .= "\n" . sprintf( 'Embed URL: %s', $block_ctx['embed_url'] );
				}

				// Tell the AI exactly what format the attached block expects.
				$block_name    = ! empty( $block_ctx['block_names'][0] ) ? $block_ctx['block_names'][0] : '';
				$format_rules  = $this->get_block_format_rules( $block_name );
				$block_count = ! empty( $block_ctx['count'] ) ? (int) $block_ctx['count'] : 1;
				if ( $block_count > 1 ) {
					$prompt .= "\n\n" . 'CRITICAL RULES — The user has selected ' . $block_count . ' blocks:
- Use [PROPOSED_CHANGES] to modify each block individually so the user can review each change.
- Only modify the selected blocks listed above, not other blocks on the page.
- NEVER output HTML wrappers in your response text.
' . $format_rules;
				} else {
					$prompt .= "\n\n" . 'CRITICAL RULES — The user has selected ONE specific block:
- Only modify THIS block. Do NOT touch other blocks.
- Do NOT use [PROPOSED_CHANGES] — use [BLOCK_CONTENT] instead.
- ALWAYS wrap the content to apply in [BLOCK_CONTENT]...[/BLOCK_CONTENT] tags.
- Your explanation goes OUTSIDE the tags, the value to apply goes INSIDE.
- NEVER output HTML wrappers in your response text.
' . $format_rules;
				}
			}
		}

		return $prompt;
	}

	/**
	 * Process chat request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function process_chat( \WP_REST_Request $request ) {
		// Get parameters.
		$messages     = $request->get_param( 'messages' );
		$post_context = $request->get_param( 'post_context' );
		$command      = $request->get_param( 'command' );
		$command_args = $request->get_param( 'command_args' );
		$post_id      = $request->get_param( 'post_id' );

		// Get current provider and API key.
		$option_service = seopress_pro_get_service( 'OptionPro' );
		$provider       = $option_service->getAIProvider();
		$provider       = ! empty( $provider ) ? $provider : 'openai';

		$usage_service = seopress_pro_get_service( 'Usage' );
		$api_key       = $usage_service->getLicenseKey( $provider );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'no_api_key',
				__( 'No API key configured. Please add your API key in SEOPress > PRO > AI settings.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		// Enrich post context with SEO metadata from the database.
		if ( $post_id ) {
			$post_context = $this->enrich_seo_context( $post_id, $post_context );
		}

		// Handle slash commands — commands build their own lightweight prompts.
		if ( ! empty( $command ) ) {
			return $this->process_command( $command, $command_args, $post_id, $post_context, $provider, $api_key );
		}

		// Determine context level based on what's attached.
		// - 'block': specific block(s) in context → lightweight prompt, no indexed_blocks, no abilities list
		// - 'page': full page context → medium prompt with indexed_blocks, no abilities details
		// - 'full': no specific context → full prompt with everything
		$has_block   = ! empty( $post_context['block_context'] );
		$context_level = $has_block ? 'block' : 'page';

		// Build system prompt adapted to context level.
		$system_prompt   = $this->get_system_prompt( $post_context, $post_id, $context_level );
		$chat_messages   = array();
		$chat_messages[] = array(
			'role'    => 'system',
			'content' => $system_prompt,
		);

		// Adapt message history to context level.
		$max_messages = 'block' === $context_level ? 10 : 20;
		$total           = count( $messages );
		$image_urls      = ! empty( $post_context['image_urls'] ) ? $post_context['image_urls'] : array();

		// If conversation is longer than the limit, summarize early messages.
		if ( $total > $max_messages ) {
			$early_messages = array_slice( $messages, 0, $total - $max_messages );
			$summary_parts  = array();
			foreach ( $early_messages as $msg ) {
				if ( 'user' === $msg['role'] ) {
					$summary_parts[] = '- User: ' . mb_substr( $msg['content'], 0, 100 );
				}
			}
			if ( ! empty( $summary_parts ) ) {
				$chat_messages[] = array(
					'role'    => 'user',
					'content' => "Summary of earlier conversation:\n" . implode( "\n", $summary_parts ),
				);
				$chat_messages[] = array(
					'role'    => 'assistant',
					'content' => 'Understood, I have the context from our earlier conversation.',
				);
			}
		}

		$recent_messages = array_slice( $messages, -$max_messages );

		foreach ( $recent_messages as $index => $message ) {
			$is_last_user = ( 'user' === $message['role'] && count( $recent_messages ) - 1 === $index );

			if ( $is_last_user && ! empty( $image_urls ) ) {
				$chat_messages[] = array(
					'role'       => 'user',
					'content'    => $message['content'],
					'image_urls' => $image_urls,
				);
			} else {
				$chat_messages[] = array(
					'role'    => $message['role'],
					'content' => $message['content'],
				);
			}
		}

		// Make API request based on provider. 4096 gives free-form replies (including
		// "Continue" follow-ups and block edits) enough output budget — the previous
		// 1024 default left almost nothing once reasoning-capable models consumed it,
		// so continuations came back as tiny fragments that were immediately truncated.
		$response = $this->make_api_request( $chat_messages, $provider, $api_key, 4096 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse and execute [ACTION] tags from AI response.
		$parsed = $this->parse_and_execute_actions( $response, $post_id );

		$data = array(
			'content' => $parsed['content'],
		);

		if ( ! empty( $parsed['actions_executed'] ) ) {
			$data['actions_executed'] = $parsed['actions_executed'];
		}
		if ( $this->last_truncated ) {
			$data['truncated'] = true;
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * AJAX handler for streaming chat via Server-Sent Events.
	 *
	 * @return void
	 */
	public function stream_chat_ajax() {
		check_ajax_referer( 'seopress_ai_stream', '_ajax_nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'edit_posts', 'chat' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw     = file_get_contents( 'php://input' );
		$params  = json_decode( $raw, true );
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$messages     = isset( $params['messages'] ) ? $this->sanitize_messages( $params['messages'] ) : array();
		$post_context = isset( $params['post_context'] ) ? $this->sanitize_post_context( $params['post_context'] ) : null;
		$post_id      = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : null;

		// Enrich context.
		if ( $post_id ) {
			$post_context = $this->enrich_seo_context( $post_id, $post_context );
		}

		// Get provider.
		$option_service = seopress_pro_get_service( 'OptionPro' );
		$provider       = $option_service->getAIProvider();
		$provider       = ! empty( $provider ) ? $provider : 'openai';
		$usage_service  = seopress_pro_get_service( 'Usage' );
		$api_key        = $usage_service->getLicenseKey( $provider );

		if ( empty( $api_key ) ) {
			header( 'Content-Type: text/event-stream' );
			echo "data: " . wp_json_encode( array( 'error' => 'No API key configured.' ) ) . "\n\n";
			echo "data: " . wp_json_encode( array( 'done' => true ) ) . "\n\n";
			wp_die();
		}

		// Build messages.
		$system_prompt = $this->get_system_prompt( $post_context, $post_id );
		$chat_messages = array();
		$chat_messages[] = array( 'role' => 'system', 'content' => $system_prompt );

		$max_messages    = 20;
		$total           = count( $messages );
		if ( $total > $max_messages ) {
			$early = array_slice( $messages, 0, $total - $max_messages );
			$parts = array();
			foreach ( $early as $msg ) {
				if ( 'user' === $msg['role'] ) {
					$parts[] = '- User: ' . mb_substr( $msg['content'], 0, 100 );
				}
			}
			if ( ! empty( $parts ) ) {
				$chat_messages[] = array( 'role' => 'user', 'content' => "Summary of earlier conversation:\n" . implode( "\n", $parts ) );
				$chat_messages[] = array( 'role' => 'assistant', 'content' => 'Understood.' );
			}
		}

		$recent = array_slice( $messages, -$max_messages );
		foreach ( $recent as $msg ) {
			$chat_messages[] = array( 'role' => $msg['role'], 'content' => $msg['content'] );
		}

		// Build provider request.
		$completions = seopress_pro_get_service( 'Completions' );
		$model       = $completions->getAIModel( $provider );

		$this->do_stream( $chat_messages, $provider, $api_key, $model );
		wp_die();
	}

	/**
	 * Execute streaming request to AI provider and emit SSE events.
	 *
	 * @param array  $messages Chat messages.
	 * @param string $provider Provider name.
	 * @param string $api_key  API key.
	 * @param string $model    Model name.
	 * @return void
	 */
	private function do_stream( $messages, $provider, $api_key, $model ) {
		// SSE headers.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' ); // Nginx.
		header( 'Connection: keep-alive' );

		if ( ob_get_level() ) {
			ob_end_flush();
		}

		// Build URL and body per provider.
		$url     = '';
		$headers = array();
		$body    = array( 'model' => $model, 'messages' => $messages, 'stream' => true );

		switch ( strtolower( $provider ) ) {
			case 'seopress':
				// SEOPress Credits go through our own gateway, never to a provider
				// directly: the token is ours, not an OpenAI key. Without this case
				// the default branch below posted it to api.openai.com, which
				// answered "Incorrect API key provided". The gateway speaks the
				// OpenAI chat-completions format, so the body needs no change.
				$url     = $this->credits_chat_completions_url();
				$headers = array( 'Authorization: Bearer ' . $api_key, 'Content-Type: application/json' );
				// Same parameter split as the OpenAI branch: the gateway forwards
				// to the underlying model, so a gpt-5.x or o-series model rejects
				// max_tokens and only accepts the default temperature.
				if ( $this->model_requires_completion_tokens( $model ) ) {
					$body['max_completion_tokens'] = 2048;
				} else {
					$body['max_tokens']  = 2048;
					$body['temperature'] = 0.7;
				}
				break;

			case 'openai':
				$url     = 'https://api.openai.com/v1/chat/completions';
				$headers = array( 'Authorization: Bearer ' . $api_key, 'Content-Type: application/json' );
				if ( $this->model_requires_completion_tokens( $model ) ) {
					$body['max_completion_tokens'] = 2048;
				} else {
					$body['max_tokens']  = 2048;
					$body['temperature'] = 0.7;
				}
				break;

			case 'claude':
				$url     = 'https://api.anthropic.com/v1/messages';
				$headers = array( 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01', 'Content-Type: application/json' );
				// Claude uses different message format.
				$system_content = '';
				$claude_msgs    = array();
				foreach ( $messages as $msg ) {
					if ( 'system' === $msg['role'] ) {
						$system_content = $msg['content'];
					} else {
						$claude_msgs[] = $msg;
					}
				}
				$body = array(
					'model'      => $model,
					'max_tokens' => 2048,
					'stream'     => true,
					'messages'   => $claude_msgs,
				);
				if ( ! empty( $system_content ) ) {
					$body['system'] = $system_content;
				}
				break;

			case 'gemini':
				$url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':streamGenerateContent?alt=sse&key=' . $api_key;
				$headers = array( 'Content-Type: application/json' );
				$body    = $this->build_gemini_body( $messages, 2048 );
				unset( $body['stream'] ); // Gemini uses URL param, not body.
				break;

			case 'mistral':
				$url     = 'https://api.mistral.ai/v1/chat/completions';
				$headers = array( 'Authorization: Bearer ' . $api_key, 'Content-Type: application/json' );
				$body['max_tokens']  = 2048;
				$body['temperature'] = 0.7;
				break;

			case 'deepseek':
				$url     = 'https://api.deepseek.com/v1/chat/completions';
				$headers = array( 'Authorization: Bearer ' . $api_key, 'Content-Type: application/json' );
				$body['max_tokens']  = 2048;
				$body['temperature'] = 0.7;
				break;

			default:
				$url     = 'https://api.openai.com/v1/chat/completions';
				$headers = array( 'Authorization: Bearer ' . $api_key, 'Content-Type: application/json' );
				$body['max_tokens']  = 2048;
				$body['temperature'] = 0.7;
		}

		// cURL streaming.
		$ch = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt( $ch, CURLOPT_POST, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt

		$full_content  = '';
		$provider_lower = strtolower( $provider );

		// Write function — called for each chunk from the provider.
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $ch, $data ) use ( &$full_content, $provider_lower ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			$lines = explode( "\n", $data );

			foreach ( $lines as $line ) {
				$line = trim( $line );

				if ( empty( $line ) ) {
					continue;
				}

				// OpenAI / Mistral / DeepSeek format.
				if ( 'claude' !== $provider_lower && 'gemini' !== $provider_lower ) {
					if ( 'data: [DONE]' === $line ) {
						continue;
					}
					if ( 0 === strpos( $line, 'data: ' ) ) {
						$json = json_decode( substr( $line, 6 ), true );
						if ( isset( $json['choices'][0]['delta']['content'] ) ) {
							$chunk = $json['choices'][0]['delta']['content'];
							$full_content .= $chunk;
							echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
							flush();
						}
					}
				}

				// Claude format.
				if ( 'claude' === $provider_lower ) {
					if ( 0 === strpos( $line, 'data: ' ) ) {
						$json = json_decode( substr( $line, 6 ), true );
						if ( isset( $json['type'] ) ) {
							if ( 'content_block_delta' === $json['type'] && isset( $json['delta']['text'] ) ) {
								$chunk = $json['delta']['text'];
								$full_content .= $chunk;
								echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
								flush();
							}
						}
					}
				}

				// Gemini format.
				if ( 'gemini' === $provider_lower ) {
					if ( 0 === strpos( $line, 'data: ' ) ) {
						$json = json_decode( substr( $line, 6 ), true );
						if ( isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ) {
							$chunk = $json['candidates'][0]['content']['parts'][0]['text'];
							$full_content .= $chunk;
							echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
							flush();
						}
					}
				}
			}

			return strlen( $data );
		} );

		curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		if ( \PHP_VERSION_ID < 80000 ) {
			// curl_close() is a no-op since PHP 8.0 and deprecated since PHP 8.5.
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		}

		// Parse actions from the full content.
		$parsed = $this->parse_and_execute_actions( $full_content );

		$done_data = array( 'done' => true, 'full_content' => $parsed['content'] );
		if ( ! empty( $parsed['actions_executed'] ) ) {
			$done_data['actions_executed'] = $parsed['actions_executed'];
		}

		echo 'data: ' . wp_json_encode( $done_data ) . "\n\n";
		flush();
	}

	/**
	 * Check if an OpenAI model requires max_completion_tokens instead of max_tokens.
	 * Chat completions endpoint of the SEOPress Credits gateway.
	 *
	 * Mirrors what Completions::getProviderEndpoints() resolves for the
	 * `seopress` provider, including the SEOPRESS_AI_PROXY_URL override used
	 * to point a local install at a development gateway.
	 *
	 * @return string
	 */
	private function credits_chat_completions_url() {
		$proxy_url = defined( 'SEOPRESS_AI_PROXY_URL' ) ? SEOPRESS_AI_PROXY_URL : 'https://api.seopress.org';

		return $proxy_url . '/v1/chat/completions';
	}

	/**
	 *
	 * @param string $model Model name.
	 * @return bool True if model requires max_completion_tokens.
	 */
	private function model_requires_completion_tokens( $model ) {
		// Models that require max_completion_tokens: o1, o3, gpt-5.x, etc.
		$model_lower = strtolower( $model );

		// Check for o-series models (o1, o3, etc.). Credits models carry a vendor
		// prefix ("openai/o3"), so allow the name to start after a slash too,
		// otherwise those would wrongly be sent max_tokens.
		if ( preg_match( '#(^|/)o[0-9]#', $model_lower ) ) {
			return true;
		}

		// Check for GPT-5.x models.
		if ( strpos( $model_lower, 'gpt-5' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Process a slash command.
	 *
	 * @param string      $command      Command name.
	 * @param string|null $command_args Optional arguments.
	 * @param int|null    $post_id      Post ID.
	 * @param array|null  $post_context Post context.
	 * @param string      $provider     AI provider.
	 * @param string      $api_key      API key.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function process_command( $command, $command_args, $post_id, $post_context, $provider, $api_key ) {
		$prompt       = $this->get_command_prompt( $command, $command_args, $post_id, $post_context );
		$expects_json = in_array( $command, array( 'title', 'description', 'social', 'translate', 'fix' ), true );

		$chat_messages = array(
			array(
				'role'    => 'system',
				'content' => $prompt['system'],
			),
			array(
				'role'    => 'user',
				'content' => $prompt['user'],
			),
		);

		// Long-form commands generate full articles/sections, so they get a larger
		// output budget than the short commands. 4096 keeps generation well within
		// the request timeout; if the model still hits the ceiling the reply is
		// flagged truncated and the user can resume it with the "Continue" action.
		$long_form  = array( 'write', 'faq', 'summarize', 'product', 'outline' );
		$max_tokens = in_array( $command, $long_form, true ) ? 4096 : 2048;

		$response = $this->make_api_request( $chat_messages, $provider, $api_key, $max_tokens );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$suggestions = null;

		if ( $expects_json ) {
			$suggestions = $this->parse_suggestions_json( $response );

			// Models occasionally answer with prose instead of strict JSON,
			// which leaves suggestion commands (title, description, social…)
			// with nothing to apply. Retry once with a hard nudge.
			if ( empty( $suggestions ) ) {
				$retry_messages   = $chat_messages;
				$retry_messages[] = array(
					'role'    => 'user',
					'content' => 'Your previous answer was not valid JSON. Reply again with ONLY the JSON object described above — no prose, no markdown, no code fences.',
				);
				$retry_response   = $this->make_api_request( $retry_messages, $provider, $api_key, $max_tokens );
				if ( ! is_wp_error( $retry_response ) ) {
					$retry_suggestions = $this->parse_suggestions_json( $retry_response );
					if ( ! empty( $retry_suggestions ) ) {
						$suggestions = $retry_suggestions;
						$response    = $retry_response;
					}
				}
			}
		}

		// Unwrap a JSON envelope if the model returned one, so the chat never
		// displays raw JSON to the user. This runs for every command (not only
		// those expecting suggestions) as a safety net against prompts that
		// accidentally request a JSON-formatted response.
		$content   = $response;
		$json_data = $this->extract_json( $response );
		if ( is_array( $json_data ) && isset( $json_data['content'] ) && is_string( $json_data['content'] ) ) {
			$content = $json_data['content'];
		} elseif ( null !== $suggestions ) {
			// Suggestions were found but no usable content field — strip the JSON markup.
			$content = preg_replace( '/```json\s*\{.*?\}\s*```/s', '', $response );
			$content = preg_replace( '/\{[^{}]*"suggestions"[^{}]*\[.*?\]\s*\}/s', '', $content );
			$content = trim( $content );
			if ( empty( $content ) ) {
				$content = __( 'Here are my suggestions:', 'wp-seopress-pro' );
			}
		}

		$data = array( 'content' => $content );
		if ( null !== $suggestions && ! empty( $suggestions ) ) {
			$data['suggestions'] = $suggestions;
		}
		if ( $this->last_truncated ) {
			$data['truncated'] = true;
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Build command-specific prompts.
	 *
	 * @param string      $command      Command name.
	 * @param string|null $command_args Arguments.
	 * @param int|null    $post_id      Post ID.
	 * @param array|null  $post_context Post context.
	 * @return array { system, user } prompts.
	 */
	/**
	 * Build a prompt section listing available abilities for the AI.
	 *
	 * @return string Abilities prompt text.
	 */
	private function get_abilities_prompt() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return '';
		}

		$abilities = wp_get_abilities();
		if ( empty( $abilities ) ) {
			return '';
		}

		// Filter to SEOPress abilities only.
		$seopress_abilities = array();
		foreach ( $abilities as $ability ) {
			if ( 'seopress' === $ability->get_category() ) {
				$input_schema = $ability->get_input_schema();
				$params       = '';
				if ( ! empty( $input_schema['properties'] ) ) {
					$param_names = array_keys( $input_schema['properties'] );
					$params      = implode( ', ', $param_names );
				}
				$seopress_abilities[] = sprintf(
					'- %s: %s (params: %s)',
					$ability->get_name(),
					$ability->get_description(),
					$params
				);
			}
		}

		if ( empty( $seopress_abilities ) ) {
			return '';
		}

		$text  = "\n\n## Available Actions (WordPress Abilities)";
		$text .= "\nYou can execute these actions. When you want to perform an action, use this format:";
		$text .= "\n[ACTION]ability-name|{\"param\": \"value\"}[/ACTION]";
		$text .= "\nExample: [ACTION]seopress/update-post-title-description|{\"post_id\": 1, \"title\": \"New Title\"}[/ACTION]";
		$text .= "\n\nAvailable abilities:";
		$text .= "\n" . implode( "\n", $seopress_abilities );

		return $text;
	}

	/**
	 * Parse and execute [ACTION] tags from AI response.
	 *
	 * @param string   $content   AI response content.
	 * @param int|null $post_id   Current post ID for context.
	 * @return array { content: string, actions_executed: array }
	 */
	private function parse_and_execute_actions( $content, $post_id = null ) {
		$actions_executed = array();

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'content'          => $content,
				'actions_executed' => $actions_executed,
			);
		}

		// Find all [ACTION]...[/ACTION] tags.
		preg_match_all( '/\[ACTION\](.*?)\[\/ACTION\]/s', $content, $matches );

		if ( empty( $matches[1] ) ) {
			return array(
				'content'          => $content,
				'actions_executed' => $actions_executed,
			);
		}

		foreach ( $matches[1] as $action_raw ) {
			$parts = explode( '|', $action_raw, 2 );
			$name  = trim( $parts[0] );
			$input = array();

			if ( isset( $parts[1] ) ) {
				$decoded = json_decode( trim( $parts[1] ), true );
				if ( is_array( $decoded ) ) {
					$input = $decoded;
				}
			}

			// Auto-inject post_id if not provided and we have one.
			if ( $post_id && empty( $input['post_id'] ) ) {
				$input['post_id'] = $post_id;
			}

			$ability = wp_get_ability( $name );
			if ( ! $ability ) {
				$actions_executed[] = array(
					'ability' => $name,
					'status'  => 'error',
					'message' => 'Ability not found.',
				);
				continue;
			}

			$result = $ability->execute( $input );

			if ( is_wp_error( $result ) ) {
				$actions_executed[] = array(
					'ability' => $name,
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);
			} else {
				$actions_executed[] = array(
					'ability' => $name,
					'status'  => 'success',
					'result'  => $result,
				);
			}
		}

		// Clean the [ACTION] tags from the displayed content.
		$clean_content = preg_replace( '/\[ACTION\].*?\[\/ACTION\]/s', '', $content );
		$clean_content = trim( $clean_content );

		return array(
			'content'          => $clean_content,
			'actions_executed' => $actions_executed,
		);
	}

	/**
	 * Get format rules for a specific block type to include in the AI prompt.
	 *
	 * @param string $block_name Block name (e.g. core/image).
	 * @return string Format instruction for the AI.
	 */
	private function get_block_format_rules( $block_name ) {
		$rules = array(
			'core/image'        => 'This is an IMAGE block. You can set BOTH the alt text and the caption. Use this exact format inside [BLOCK_CONTENT]:
Alt text: descriptive text for accessibility
Caption: visible caption text below the image
Example: [BLOCK_CONTENT]Alt text: Study of drapery by Leonardo da Vinci, black chalk on tinted paper
Caption: Study of drapery of the Virgin, attributed to Leonardo da Vinci[/BLOCK_CONTENT]
If only one is requested, include only that field. PLAIN TEXT only, no HTML.',
			'core/button'       => 'This is a BUTTON block. The [BLOCK_CONTENT] must be PLAIN TEXT only for the button label. No HTML.',
			'core/code'         => 'This is a CODE block. The [BLOCK_CONTENT] must be PLAIN TEXT code. No HTML tags.',
			'core/preformatted' => 'This is a PREFORMATTED block. The [BLOCK_CONTENT] must be PLAIN TEXT. No HTML.',
			'core/paragraph'    => 'This is a PARAGRAPH block. The [BLOCK_CONTENT] can include simple inline formatting: <strong>, <em>, <a href="...">, <code>. No block-level HTML (<p>, <div>, <figure>).',
			'core/heading'      => 'This is a HEADING block. The [BLOCK_CONTENT] must be plain text or simple inline formatting (<strong>, <em>). No <h1>/<h2>/<h3> tags.',
			'core/list'         => 'This is a LIST block. The [BLOCK_CONTENT] should be an HTML list: <li>item 1</li><li>item 2</li>. No <ul> or <ol> wrapper needed.',
			'core/quote'        => 'This is a QUOTE block. The [BLOCK_CONTENT] should be the quote text with optional inline formatting.',
			'core/table'        => 'This is a TABLE block. The [BLOCK_CONTENT] should be a full HTML table: <table><thead>...</thead><tbody>...</tbody></table>.',
		);

		if ( isset( $rules[ $block_name ] ) ) {
			return $rules[ $block_name ];
		}

		return 'The [BLOCK_CONTENT] should be plain text or simple inline HTML (<strong>, <em>, <a>). No block-level HTML wrappers.';
	}

	/**
	 * Enrich post context with SEO metadata from the database.
	 *
	 * @param int        $post_id      Post ID.
	 * @param array|null $post_context Existing post context.
	 * @return array Enriched context.
	 */
	private function enrich_seo_context( $post_id, $post_context ) {
		if ( ! is_array( $post_context ) ) {
			$post_context = array();
		}

		// Target keywords.
		if ( empty( $post_context['target_keywords'] ) ) {
			$kw = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );
			if ( ! empty( $kw ) ) {
				$post_context['target_keywords'] = $kw;
			}
		}

		// SEO metas.
		$seo_metas = array(
			'seo_title'       => get_post_meta( $post_id, '_seopress_titles_title', true ),
			'seo_description' => get_post_meta( $post_id, '_seopress_titles_desc', true ),
			'fb_title'        => get_post_meta( $post_id, '_seopress_social_fb_title', true ),
			'fb_description'  => get_post_meta( $post_id, '_seopress_social_fb_desc', true ),
			'tw_title'        => get_post_meta( $post_id, '_seopress_social_twitter_title', true ),
			'tw_description'  => get_post_meta( $post_id, '_seopress_social_twitter_desc', true ),
			'canonical'       => get_post_meta( $post_id, '_seopress_robots_canonical', true ),
			'noindex'         => get_post_meta( $post_id, '_seopress_robots_index', true ),
		);

		// Remove empty values.
		$seo_metas = array_filter( $seo_metas );

		if ( ! empty( $seo_metas ) ) {
			$post_context['seo_metas'] = $seo_metas;
		}

		return $post_context;
	}

	private function get_command_prompt( $command, $command_args, $post_id, $post_context ) {
		$language = ! empty( $post_context['language'] ) ? $post_context['language'] : 'en';
		$title    = ! empty( $post_context['title'] ) ? $post_context['title'] : '';
		$content  = ! empty( $post_context['content'] ) ? $post_context['content'] : '';
		$keywords = '';
		if ( ! empty( $post_context['target_keywords'] ) ) {
			$keywords = is_array( $post_context['target_keywords'] )
				? implode( ', ', $post_context['target_keywords'] )
				: $post_context['target_keywords'];
		}

		$json_instruction     = 'Return ONLY a valid JSON object (no markdown, no code blocks) with this exact structure: {"content": "brief explanation", "suggestions": [{"type": "...", "value": "...", "meta_key": "..."}]}';
		$json_fix_instruction = 'Return ONLY a valid JSON object (no markdown, no code blocks) with this structure: {"content": "brief summary of fixes", "suggestions": [{"type": "issue_name", "value": "the fixed value", "meta_key": "_seopress_...", "issue": "issue_key"}]}. Only include suggestions for issues you can actually fix with a new value.';

		$system = 'You are an SEO expert assistant for SEOPress, a WordPress SEO plugin. Always respond in the same language as the post content.';

		switch ( $command ) {
			case 'title':
				$user = sprintf(
					'Generate exactly 5 SEO-optimized title suggestions for a blog post. Each title must be under 60 characters, engaging, and include relevant keywords. %s Post title: "%s". Keywords: "%s". Content: %s',
					$json_instruction . ' Each suggestion must have "type": "title" and "meta_key": "_seopress_titles_title".',
					$title,
					$keywords,
					substr( $content, 0, 800 )
				);
				break;

			case 'description':
				$user = sprintf(
					'Generate exactly 3 SEO meta description suggestions. Each must be under 160 characters, compelling, and include a call-to-action. %s Post title: "%s". Keywords: "%s". Content: %s',
					$json_instruction . ' Each suggestion must have "type": "description" and "meta_key": "_seopress_titles_desc".',
					$title,
					$keywords,
					substr( $content, 0, 800 )
				);
				break;

			case 'social':
				$user = sprintf(
					'Generate social media meta tags for this post. Create exactly 4 suggestions: 1) Facebook title (engaging, max 60 chars), 2) Facebook description (compelling, max 200 chars), 3) X/Twitter title (punchy, max 60 chars), 4) X/Twitter description (concise, max 200 chars). %s Use these meta_keys respectively: "_seopress_social_fb_title", "_seopress_social_fb_desc", "_seopress_social_twitter_title", "_seopress_social_twitter_desc". Types: "fb_title", "fb_desc", "twitter_title", "twitter_desc". Post title: "%s". Content: %s',
					$json_instruction,
					$title,
					substr( $content, 0, 800 )
				);
				break;

			case 'translate':
				$current_title = '';
				$current_desc  = '';
				if ( $post_id ) {
					$current_title = get_post_meta( $post_id, '_seopress_titles_title', true );
					$current_desc  = get_post_meta( $post_id, '_seopress_titles_desc', true );
				}
				if ( empty( $current_title ) ) {
					$current_title = $title;
				}

				$target_lang = ! empty( $command_args ) ? $command_args : 'en';

				$user = sprintf(
					'Translate the following SEO meta title and description to %s. Keep them SEO-optimized and within character limits (title: 60 chars, description: 160 chars). %s Create 2 suggestions: one with type "title" and meta_key "_seopress_titles_title", one with type "description" and meta_key "_seopress_titles_desc". Current title: "%s". Current description: "%s".',
					$target_lang,
					$json_instruction,
					$current_title,
					$current_desc
				);
				break;

			case 'outline':
				$user = sprintf(
					'Write a detailed SEO-optimized article outline for this post. Include H2 and H3 heading suggestions with keyword placement. Post title: "%s". Keywords: "%s". Current content: %s',
					$title,
					$keywords,
					substr( $content, 0, 1000 )
				);
				break;

			case 'schema':
				$user = sprintf(
					'Analyze this post and recommend the most appropriate Schema.org structured data type (e.g., Article, HowTo, FAQ, Recipe, Product, etc.). Explain why this type fits and list the key properties to fill. Post title: "%s". Content: %s',
					$title,
					substr( $content, 0, 1000 )
				);
				break;

			case 'links':
				$related_posts = $this->get_related_posts( $post_id, $keywords, $title );
				$user          = sprintf(
					'Based on this post content, suggest internal linking opportunities. For each suggestion, provide: the exact anchor text to use, where in the content to place it, and the target URL.

Here are existing pages on this site that could be good link targets:
%s

Post title: "%s". Keywords: "%s". Content: %s

Only suggest links to the pages listed above. Suggest up to 5 relevant links.',
					$related_posts,
					$title,
					$keywords,
					substr( $content, 0, 800 )
				);
				break;

			case 'fix':
				// Build the issues list from analysis results or fetch them.
				$issues_text = $this->build_fix_issues_text( $post_id, $post_context );
				$user        = sprintf(
					'You are fixing SEO issues for a WordPress post. Here are the issues detected by our content analysis tool:

%s

Post title: "%s"
Target keywords: "%s"
Content preview: %s

For each fixable issue, generate the corrected value. %s

Rules for fixes:
- SEO title: max 60 characters, include target keywords naturally
- Meta description: max 160 characters, compelling, include keywords
- Social titles (Facebook/Twitter): engaging, max 60 characters
- Social descriptions: compelling, max 200 characters
- For missing social metas, generate them based on the SEO title and description
- Only fix issues that can be resolved with a text value and a meta_key
- Do NOT fix: old_post, outbound_links_missing, internal_links_missing, schemas_not_found, keywords_permalink',
					$issues_text,
					$title,
					$keywords,
					substr( $content, 0, 800 ),
					$json_fix_instruction
				);
				break;

			case 'keywords':
				$seed_keyword = ! empty( $command_args ) ? $command_args : $keywords;
				if ( empty( $seed_keyword ) ) {
					$seed_keyword = $title;
				}
				$user = sprintf(
					'Perform a keyword research analysis for: "%s"

Provide the following in a well-structured response:

1. **Primary Keyword** — the main keyword to target
2. **Search Intent** — informational, transactional, navigational, or commercial
3. **LSI Keywords** — 10 related semantic keywords (Latent Semantic Indexing)
4. **Long-tail Variations** — 10 long-tail keyword phrases (3-5 words)
5. **Questions People Ask** — 5 question-based keywords (great for FAQ schema)
6. **Content Strategy** — brief recommendation on content type and structure to rank for this keyword

Context: This is for a WordPress post titled "%s" with existing target keywords: "%s".',
					$seed_keyword,
					$title,
					$keywords
				);
				break;

			case 'write':
				$topic = ! empty( $command_args ) ? $command_args : $title;
				$brief = ! empty( $post_context['write_brief'] ) && is_array( $post_context['write_brief'] )
					? $post_context['write_brief']
					: array();

				$length_word_map = array(
					'short'  => 'about 500 words (strictly between 400 and 550 words)',
					'medium' => 'about 900 words (strictly between 800 and 1000 words)',
					'long'   => 'about 1400 words (strictly between 1200 and 1500 words)',
				);

				$length_key   = ! empty( $brief['length'] ) ? $brief['length'] : 'medium';
				$length_label = isset( $length_word_map[ $length_key ] ) ? $length_word_map[ $length_key ] : $length_word_map['medium'];

				$brief_lines = array();
				if ( ! empty( $brief['audience'] ) ) {
					$brief_lines[] = 'Target audience: ' . $brief['audience'] . '. Adapt the vocabulary, depth and examples to this audience.';
				}
				if ( ! empty( $brief['tone'] ) ) {
					$brief_lines[] = 'Write in a ' . $brief['tone'] . ' tone.';
				}
				if ( ! empty( $brief['key_points'] ) ) {
					$brief_lines[] = 'Make sure to cover these key points / angle: ' . $brief['key_points'] . '.';
				}
				$brief_text = ! empty( $brief_lines ) ? ' ' . implode( ' ', $brief_lines ) : '';

				$user = sprintf(
					'Write a complete, SEO-optimized article about: "%s". Target keywords: "%s". Structure it with H2 and H3 headings, short paragraphs, bullet lists where appropriate. Do not include an <h1> heading — the post title is already the H1, so start headings at <h2>. Length: %s. Respect this limit and do not exceed the upper bound.%s IMPORTANT: write the COMPLETE article, finish your final sentence, and make sure every HTML tag is properly closed — never stop mid-sentence. Wrap the full HTML article in [INSERT_CONTENT] tags, optionally preceded by a one-sentence plain-text summary. Do not respond with JSON.',
					$topic,
					$keywords,
					$length_label,
					$brief_text
				);
				break;

			case 'faq':
				$user = sprintf(
					'Based on this post content, generate 5 FAQ questions and answers that would be valuable for readers and SEO. Each Q&A should be relevant to the topic and include target keywords naturally. Format as HTML: <h3>Question?</h3><p>Answer.</p> for each pair. %s Use [INSERT_CONTENT] tags to wrap the full FAQ HTML. Content: %s',
					'',
					substr( $content, 0, 1000 )
				);
				break;

			case 'proofread':
				$user = sprintf(
					'Proofread and fix all grammar, spelling, and punctuation errors in the following blocks. Keep the meaning and tone identical — only fix errors. Return changes as PROPOSED_CHANGES with the corrected text for each block that has errors. If a block has no errors, skip it. Language: %s. %s',
					$language,
					$json_fix_instruction
				);
				break;

			case 'tone':
				$target_tone = ! empty( $command_args ) ? $command_args : 'professional';
				$user        = sprintf(
					'Rewrite ALL text blocks in a %s tone. Keep the same meaning, facts, and structure — only change the writing style and tone. Return as PROPOSED_CHANGES for each block. Language: %s.',
					$target_tone,
					$language
				);
				break;

			case 'summarize':
				$user = sprintf(
					'Write a concise TL;DR summary (3-5 sentences) of this post content. The summary should capture the key points and be useful as an introduction. Format as HTML inside [INSERT_CONTENT] tags: <p><strong>TL;DR:</strong> summary text here.</p>. Content: %s',
					substr( $content, 0, 2000 )
				);
				break;

			case 'product':
				$user = sprintf(
					'Generate an optimized product page content based on this post. Include: 1) A compelling product description (2-3 paragraphs), 2) Key features as a bullet list, 3) A specifications section if relevant, 4) 3-5 FAQ questions and answers. Format as HTML inside [INSERT_CONTENT] tags with proper headings (H2, H3). Also recommend enabling Product schema in SEOPress. Post title: "%s". Keywords: "%s". Existing content: %s',
					$title,
					$keywords,
					substr( $content, 0, 1000 )
				);
				break;

			default:
				$user = '';
		}

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Extract JSON from AI response text.
	 *
	 * @param string $text Response text.
	 * @return array|null Parsed JSON or null.
	 */
	private function extract_json( $text ) {
		// Try direct JSON parse.
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		// Try extracting from markdown code blocks.
		if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches ) ) {
			$data = json_decode( $matches[1], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		// Try extracting raw JSON object.
		if ( preg_match( '/(\{[^{}]*"suggestions"\s*:\s*\[.*?\]\s*\})/s', $text, $matches ) ) {
			$data = json_decode( $matches[1], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * Parse suggestions from AI response.
	 *
	 * @param string $response AI response text.
	 * @return array|null Suggestions array or null.
	 */
	private function parse_suggestions_json( $response ) {
		$data = $this->extract_json( $response );

		if ( ! $data || ! isset( $data['suggestions'] ) || ! is_array( $data['suggestions'] ) ) {
			return null;
		}

		$suggestions = array();
		foreach ( $data['suggestions'] as $item ) {
			if ( ! isset( $item['value'] ) || ! isset( $item['meta_key'] ) ) {
				continue;
			}
			$suggestions[] = array(
				'type'     => isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : '',
				'value'    => sanitize_text_field( $item['value'] ),
				'meta_key' => sanitize_text_field( $item['meta_key'] ),
			);
		}

		return ! empty( $suggestions ) ? $suggestions : null;
	}

	/**
	 * Convert messages containing image_urls to provider-specific multimodal format.
	 *
	 * @param array  $messages Chat messages.
	 * @param string $provider AI provider.
	 * @return array Prepared messages.
	 */
	/**
	 * Get related posts from the site for internal linking suggestions.
	 *
	 * @param int|null $post_id  Current post ID (to exclude).
	 * @param string   $keywords Target keywords.
	 * @param string   $title    Post title.
	 * @return string Formatted list of related posts.
	 */
	private function get_related_posts( $post_id, $keywords, $title ) {
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'post__not_in'   => $post_id ? array( $post_id ) : array(),
			'orderby'        => 'relevance',
			's'              => ! empty( $keywords ) ? $keywords : $title,
		);

		$query = new \WP_Query( $args );
		$posts = $query->posts;

		// If search gave few results, also get recent posts.
		if ( count( $posts ) < 10 ) {
			$recent_args = array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'post__not_in'   => $post_id ? array( $post_id ) : array(),
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
			$recent      = get_posts( $recent_args );
			$existing_ids = wp_list_pluck( $posts, 'ID' );
			foreach ( $recent as $p ) {
				if ( ! in_array( $p->ID, $existing_ids, true ) ) {
					$posts[] = $p;
				}
			}
		}

		if ( empty( $posts ) ) {
			return 'No other published pages found on this site.';
		}

		$lines = array();
		foreach ( array_slice( $posts, 0, 20 ) as $p ) {
			$url     = get_permalink( $p->ID );
			$excerpt = wp_trim_words( $p->post_excerpt ? $p->post_excerpt : $p->post_content, 15, '...' );
			$lines[] = sprintf( '- "%s" (%s) — %s', $p->post_title, $url, $excerpt );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a text description of SEO issues for the /fix command.
	 *
	 * @param int|null   $post_id      Post ID.
	 * @param array|null $post_context Post context with optional analysis_results.
	 * @return string Issues text.
	 */
	private function build_fix_issues_text( $post_id, $post_context ) {
		$issues = array();

		// Use analysis_results from post_context if available.
		if ( ! empty( $post_context['analysis_results'] ) && is_array( $post_context['analysis_results'] ) ) {
			foreach ( $post_context['analysis_results'] as $result ) {
				$status = isset( $result['status'] ) ? $result['status'] : '';
				if ( 'good' === $status ) {
					continue;
				}
				$title = isset( $result['title'] ) ? $result['title'] : '';
				$desc  = isset( $result['description'] ) ? $result['description'] : '';
				$issues[] = '- [' . strtoupper( $status ) . '] ' . $title . ( $desc ? ': ' . $desc : '' );
			}
		}

		// If no issues from context and we have a post_id, try to read current meta for context.
		if ( empty( $issues ) && $post_id ) {
			$current_title = get_post_meta( $post_id, '_seopress_titles_title', true );
			$current_desc  = get_post_meta( $post_id, '_seopress_titles_desc', true );
			$fb_title      = get_post_meta( $post_id, '_seopress_social_fb_title', true );
			$fb_desc       = get_post_meta( $post_id, '_seopress_social_fb_desc', true );
			$tw_title      = get_post_meta( $post_id, '_seopress_social_twitter_title', true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$tw_desc       = get_post_meta( $post_id, '_seopress_social_twitter_desc', true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

			if ( empty( $current_title ) ) {
				$issues[] = '- [ERROR] No custom SEO title set (meta_key: _seopress_titles_title)';
			} elseif ( mb_strlen( $current_title ) > 60 ) {
				/* translators: %d: character count */
				$issues[] = sprintf( '- [WARNING] SEO title too long (%d chars, max 60) (meta_key: _seopress_titles_title). Current: "%s"', mb_strlen( $current_title ), $current_title );
			}

			if ( empty( $current_desc ) ) {
				$issues[] = '- [ERROR] No custom meta description set (meta_key: _seopress_titles_desc)';
			} elseif ( mb_strlen( $current_desc ) > 160 ) {
				/* translators: %d: character count */
				$issues[] = sprintf( '- [WARNING] Meta description too long (%d chars, max 160) (meta_key: _seopress_titles_desc). Current: "%s"', mb_strlen( $current_desc ), $current_desc );
			}

			if ( empty( $fb_title ) ) {
				$issues[] = '- [ERROR] Missing Facebook/Open Graph title (meta_key: _seopress_social_fb_title)';
			}
			if ( empty( $fb_desc ) ) {
				$issues[] = '- [ERROR] Missing Facebook/Open Graph description (meta_key: _seopress_social_fb_desc)';
			}
			if ( empty( $tw_title ) ) {
				$issues[] = '- [ERROR] Missing X/Twitter title (meta_key: _seopress_social_twitter_title)';
			}
			if ( empty( $tw_desc ) ) {
				$issues[] = '- [ERROR] Missing X/Twitter description (meta_key: _seopress_social_twitter_desc)';
			}
		}

		if ( empty( $issues ) ) {
			return 'No issues detected — all checks passed.';
		}

		return implode( "\n", $issues );
	}

	private function prepare_multimodal_messages( $messages, $provider ) {
		$prepared = array();

		foreach ( $messages as $message ) {
			if ( empty( $message['image_urls'] ) ) {
				unset( $message['image_urls'] );
				$prepared[] = $message;
				continue;
			}

			$image_urls = $message['image_urls'];
			$text       = $message['content'];

			switch ( strtolower( $provider ) ) {
				case 'openai':
				case 'mistral':
					// OpenAI / Mistral multimodal format.
					$content   = array();
					$content[] = array(
						'type' => 'text',
						'text' => $text,
					);
					foreach ( $image_urls as $url ) {
						$content[] = array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => $url ),
						);
					}
					$prepared[] = array(
						'role'    => $message['role'],
						'content' => $content,
					);
					break;

				case 'claude':
					// Claude multimodal format with base64.
					$content   = array();
					$content[] = array(
						'type' => 'text',
						'text' => $text,
					);
					foreach ( $image_urls as $url ) {
						$completions = seopress_pro_get_service( 'Completions' );
						$base64_data = $completions->fetchImageAsBase64( $url );
						if ( $base64_data ) {
							$content[] = array(
								'type'   => 'image',
								'source' => array(
									'type'       => 'base64',
									'media_type' => $base64_data['mime_type'],
									'data'       => $base64_data['data'],
								),
							);
						}
					}
					$prepared[] = array(
						'role'    => $message['role'],
						'content' => $content,
					);
					break;

				case 'gemini':
					// Gemini handles images in build_gemini_body — pass through.
					$prepared[] = $message;
					break;

				default:
					// DeepSeek and others: no multimodal, just text.
					unset( $message['image_urls'] );
					$prepared[] = $message;
					break;
			}
		}

		return $prepared;
	}

	/**
	 * Make API request to the AI provider.
	 *
	 * @param array  $messages   Chat messages.
	 * @param string $provider   AI provider.
	 * @param string $api_key    API key.
	 * @param int    $max_tokens Max tokens for response.
	 * @return string|\WP_Error Response content or error.
	 */
	private function make_api_request( $messages, $provider, $api_key, $max_tokens = 1024 ) {
		$completions_service = seopress_pro_get_service( 'Completions' );
		$model               = $completions_service->getAIModel( $provider );

		// Convert messages with image_urls to multimodal format.
		$messages = $this->prepare_multimodal_messages( $messages, $provider );

		// Build request body based on provider.
		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);

		// Provider-specific URL and headers.
		switch ( strtolower( $provider ) ) {
			case 'seopress':
				// See do_stream(): credits are routed through our gateway, which
				// speaks the OpenAI chat-completions format.
				$url     = $this->credits_chat_completions_url();
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				);
				// See do_stream(): a gpt-5.x or o-series model behind the gateway
				// rejects max_tokens and only supports the default temperature.
				if ( $this->model_requires_completion_tokens( $model ) ) {
					$body['max_completion_tokens'] = $max_tokens;
				} else {
					$body['max_tokens']  = $max_tokens;
					$body['temperature'] = 0.7;
				}
				break;

			case 'openai':
				$url     = 'https://api.openai.com/v1/chat/completions';
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				);
				// Newer models (o1, o3, gpt-5.x) require different parameters.
				if ( $this->model_requires_completion_tokens( $model ) ) {
					$body['max_completion_tokens'] = $max_tokens;
					// These models only support temperature = 1 (default), so don't set it.
				} else {
					$body['max_tokens']  = $max_tokens;
					$body['temperature'] = 0.7;
				}
				break;

			case 'gemini':
				$url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
				$headers = array(
					'Content-Type' => 'application/json',
				);
				$body    = $this->build_gemini_body( $messages, $max_tokens );
				break;

			case 'claude':
				$url     = 'https://api.anthropic.com/v1/messages';
				$headers = array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				);
				$body    = $this->build_claude_body( $messages, $model, $max_tokens );
				break;

			case 'mistral':
				$url     = 'https://api.mistral.ai/v1/chat/completions';
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				);
				$body['max_tokens']  = $max_tokens;
				$body['temperature'] = 0.7;
				break;

			case 'deepseek':
				$url     = 'https://api.deepseek.com/v1/chat/completions';
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				);
				$body['max_tokens']  = $max_tokens;
				$body['temperature'] = 0.7;
				break;

			default:
				$url     = 'https://api.openai.com/v1/chat/completions';
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				);
				$body['max_tokens']  = $max_tokens;
				$body['temperature'] = 0.7;
		}

		$args = array(
			'body'        => wp_json_encode( $body ),
			'timeout'     => 120,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);

		/**
		 * Filter the API request arguments.
		 *
		 * @since 9.6.0
		 * @param array  $args     Request arguments.
		 * @param string $provider AI provider.
		 */
		$args = apply_filters( 'seopress_ai_assistant_request_args', $args, $provider );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			// The early return meant this never reached the logging call a few
			// lines below, so a request that never left the server was the one
			// failure the panel could not show.
			$completions_service->logTransportError( $response, $provider );

			return new \WP_Error(
				'api_error',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$response_body = wp_remote_retrieve_body( $response );
			$error_data    = json_decode( $response_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : __( 'API request failed', 'wp-seopress-pro' );

			// Log error, flat like every other write.
			//
			// This used to nest everything under an `error` key while
			// Completions wrote the same fields flat, and AITab gates on
			// `logs.provider` (`AITab.jsx:514`). So an assistant failure took
			// the transient and then rendered as "Currently no errors logged":
			// the panel reported no error while holding one.
			$error_log = array(
				'provider'      => $provider,
				'response_code' => $response_code,
				'response_body' => $error_message,
				'request_body'  => '',
				'timestamp'     => current_time( 'mysql' ),
			);
			$completions_service->logApiError( $error_log );

			return new \WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $response_code )
			);
		}

		// The call went through, so whatever the panel is still reporting is no
		// longer true.
		$completions_service->clearApiErrorLog();

		// Parse response based on provider.
		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->last_truncated = $this->detect_truncation( $response_data, $provider );

		return $this->extract_content( $response_data, $provider );
	}

	/**
	 * Detect whether the provider stopped because of the output token limit.
	 *
	 * @param array|null $response_data Decoded provider response.
	 * @param string     $provider      AI provider.
	 * @return bool True if the response was truncated by the token limit.
	 */
	private function detect_truncation( $response_data, $provider ) {
		if ( ! is_array( $response_data ) ) {
			return false;
		}

		switch ( strtolower( $provider ) ) {
			case 'claude':
				return isset( $response_data['stop_reason'] ) && 'max_tokens' === $response_data['stop_reason'];

			case 'gemini':
				$reason = isset( $response_data['candidates'][0]['finishReason'] ) ? $response_data['candidates'][0]['finishReason'] : '';
				return 'MAX_TOKENS' === $reason;

			default: // openai, mistral, deepseek, and OpenAI-compatible APIs.
				$reason = isset( $response_data['choices'][0]['finish_reason'] ) ? $response_data['choices'][0]['finish_reason'] : '';
				return 'length' === $reason;
		}
	}

	/**
	 * Build Gemini-specific request body.
	 *
	 * @param array $messages   Chat messages.
	 * @param int   $max_tokens Max output tokens.
	 * @return array Gemini request body.
	 */
	private function build_gemini_body( $messages, $max_tokens = 1024 ) {
		$contents = array();

		foreach ( $messages as $message ) {
			$role = 'user';
			if ( 'assistant' === $message['role'] ) {
				$role = 'model';
			} elseif ( 'system' === $message['role'] ) {
				// Gemini handles system prompts differently, prepend to first user message.
				continue;
			}

			$parts   = array();
			$parts[] = array( 'text' => $message['content'] );

			// Add images for Gemini multimodal.
			if ( ! empty( $message['image_urls'] ) ) {
				$completions = seopress_pro_get_service( 'Completions' );
				foreach ( $message['image_urls'] as $img_url ) {
					$base64_data = $completions->fetchImageAsBase64( $img_url );
					if ( $base64_data ) {
						$parts[] = array(
							'inline_data' => array(
								'mime_type' => $base64_data['mime_type'],
								'data'      => $base64_data['data'],
							),
						);
					}
				}
			}

			$contents[] = array(
				'role'  => $role,
				'parts' => $parts,
			);
		}

		// Prepend system message to first user message if exists.
		$system_content = '';
		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system_content = $message['content'] . "\n\n";
				break;
			}
		}

		if ( ! empty( $system_content ) && ! empty( $contents ) ) {
			$contents[0]['parts'][0]['text'] = $system_content . $contents[0]['parts'][0]['text'];
		}

		return array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => 0.7,
				'maxOutputTokens' => $max_tokens,
			),
		);
	}

	/**
	 * Build Claude-specific request body.
	 *
	 * @param array  $messages   Chat messages.
	 * @param string $model      Model name.
	 * @param int    $max_tokens Max output tokens.
	 * @return array Claude request body.
	 */
	private function build_claude_body( $messages, $model, $max_tokens = 1024 ) {
		$system_prompt  = '';
		$claude_messages = array();

		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system_prompt = $message['content'];
				continue;
			}

			$claude_messages[] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => $claude_messages,
		);

		if ( ! empty( $system_prompt ) ) {
			$body['system'] = $system_prompt;
		}

		return $body;
	}

	/**
	 * Extract content from API response based on provider.
	 *
	 * @param array  $response_data Response data.
	 * @param string $provider      AI provider.
	 * @return string Content.
	 */
	private function extract_content( $response_data, $provider ) {
		switch ( strtolower( $provider ) ) {
			case 'gemini':
				if ( isset( $response_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
					return $response_data['candidates'][0]['content']['parts'][0]['text'];
				}
				break;

			case 'claude':
				if ( isset( $response_data['content'][0]['text'] ) ) {
					return $response_data['content'][0]['text'];
				}
				break;

			default:
				// OpenAI, Mistral, DeepSeek format.
				if ( isset( $response_data['choices'][0]['message']['content'] ) ) {
					return $response_data['choices'][0]['message']['content'];
				}
		}

		return __( 'Unable to get response from AI.', 'wp-seopress-pro' );
	}
}
