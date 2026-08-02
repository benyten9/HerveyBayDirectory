<?php
/**
 * AI Assistant Memory API.
 *
 * Persistent instructions/context for the AI assistant, stored per-user.
 * Similar to CLAUDE.md / Memory.md — works with any LLM provider.
 *
 * @package SEOPress PRO
 * @subpackage API
 * @since 9.8.0
 */

namespace SEOPressPro\Actions\Api\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Class Memory
 */
class Memory implements ExecuteHooks {

	/**
	 * User meta key for personal memory.
	 *
	 * @var string
	 */
	const USER_META_KEY = '_seopress_ai_memory';

	/**
	 * Option key for shared/site memory.
	 *
	 * @var string
	 */
	const SITE_OPTION_KEY = 'seopress_ai_memory_site';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/ai/memory',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_memory' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_memory' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'personal'      => array(
							'default'           => null,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'site'          => array(
							'default'           => null,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'active_skills' => array(
							'default'           => null,
							'validate_callback' => function ( $param ) {
								return null === $param || is_array( $param );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Check permission.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get memory content.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_memory() {
		$user_id       = get_current_user_id();
		$personal      = get_user_meta( $user_id, self::USER_META_KEY, true );
		$site          = get_option( self::SITE_OPTION_KEY, '' );
		$active_skills = get_user_meta( $user_id, '_seopress_ai_active_skills', true );

		return new \WP_REST_Response(
			array(
				'personal'      => is_string( $personal ) ? $personal : '',
				'site'          => is_string( $site ) ? $site : '',
				'active_skills' => is_array( $active_skills ) ? $active_skills : array(),
				'skills'        => self::get_skills_library(),
			),
			200
		);
	}

	/**
	 * Save memory content.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function save_memory( \WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$personal = $request->get_param( 'personal' );

		if ( null !== $personal ) {
			update_user_meta( $user_id, self::USER_META_KEY, $personal );
		}

		// Site memory: only admins can edit.
		$site = $request->get_param( 'site' );
		if ( null !== $site && current_user_can( 'manage_options' ) ) {
			update_option( self::SITE_OPTION_KEY, $site, false );
		}

		// Active skills.
		$active_skills = $request->get_param( 'active_skills' );
		if ( null !== $active_skills && is_array( $active_skills ) ) {
			$sanitized = array_map( 'sanitize_text_field', $active_skills );
			update_user_meta( $user_id, '_seopress_ai_active_skills', $sanitized );
		}

		return new \WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * Get the full memory text to inject into the AI system prompt.
	 *
	 * @param int $user_id User ID.
	 * @return string Combined memory text.
	 */
	public static function get_prompt_memory( $user_id = 0 ) {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		$parts = array();

		// Site-wide memory (shared instructions).
		$site = get_option( self::SITE_OPTION_KEY, '' );
		if ( ! empty( $site ) ) {
			$parts[] = "## Site Instructions\n" . $site;
		}

		// Personal memory (per-user preferences).
		$personal = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! empty( $personal ) ) {
			$parts[] = "## Personal Instructions\n" . $personal;
		}

		// Active skills.
		$skills = self::get_active_skills( $user_id );
		if ( ! empty( $skills ) ) {
			$parts[] = "## Active Skills\n" . implode( "\n\n", $skills );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return "\n\n## Memory & Skills (persistent instructions — always apply these)\n" . implode( "\n\n", $parts );
	}

	/**
	 * Get the built-in skills library.
	 *
	 * @return array Skills with name, description, content.
	 */
	public static function get_skills_library() {
		return array(
			'seo-writing' => array(
				'name'        => __( 'SEO Writing', 'wp-seopress-pro' ),
				'description' => __( 'Write content optimized for search engines', 'wp-seopress-pro' ),
				'content'     => 'When writing or editing content:
- Use the target keyword naturally in the first 100 words
- Include the keyword in at least one H2 heading
- Write short paragraphs (2-3 sentences max)
- Use transition words for readability
- Aim for a Flesch reading score above 60
- Include relevant internal and external links
- Use active voice over passive voice',
			),
			'e-commerce' => array(
				'name'        => __( 'E-commerce SEO', 'wp-seopress-pro' ),
				'description' => __( 'Product page optimization best practices', 'wp-seopress-pro' ),
				'content'     => 'For product pages:
- Write unique product descriptions (no manufacturer copy)
- Include product specs, dimensions, materials
- Use Product schema markup with price, availability, reviews
- Optimize image alt text with product name + key feature
- Add FAQ schema for common product questions
- Include customer review snippets for rich results',
			),
			'local-seo' => array(
				'name'        => __( 'Local SEO', 'wp-seopress-pro' ),
				'description' => __( 'Optimize for local search results', 'wp-seopress-pro' ),
				'content'     => 'For local businesses:
- Include city/region name in title and description
- Use LocalBusiness schema with NAP (Name, Address, Phone)
- Add opening hours to schema
- Optimize Google Business Profile mentions
- Use geo-specific keywords (near me, in [city])
- Include local landmarks and neighborhood names',
			),
			'blog-posts' => array(
				'name'        => __( 'Blog Strategy', 'wp-seopress-pro' ),
				'description' => __( 'Blog post structure and optimization', 'wp-seopress-pro' ),
				'content'     => 'For blog posts:
- Use a compelling, keyword-rich H1
- Structure with H2/H3 hierarchy (table of contents friendly)
- Include a TL;DR or key takeaways section
- Add FAQ section at the bottom for featured snippet opportunities
- Internal link to 3-5 related articles
- Include at least one image with descriptive alt text per 300 words
- End with a clear CTA',
			),
			'technical-seo' => array(
				'name'        => __( 'Technical SEO', 'wp-seopress-pro' ),
				'description' => __( 'Technical optimization guidelines', 'wp-seopress-pro' ),
				'content'     => 'Technical SEO rules:
- Ensure every page has a unique canonical URL
- Check for noindex/nofollow only on intended pages
- Validate structured data with Google Rich Results Test
- Keep title under 60 chars, description under 160 chars
- Avoid duplicate content across pages
- Ensure proper hreflang tags for multilingual sites
- Check Core Web Vitals impact of recommendations',
			),
			'tone-formal' => array(
				'name'        => __( 'Formal Tone', 'wp-seopress-pro' ),
				'description' => __( 'Professional, formal writing style', 'wp-seopress-pro' ),
				'content'     => 'Writing style:
- Use formal, professional language
- Avoid contractions (use "do not" instead of "don\'t")
- No slang, colloquialisms, or emojis
- Use third person perspective
- Maintain an authoritative, expert tone',
			),
			'tone-casual' => array(
				'name'        => __( 'Casual Tone', 'wp-seopress-pro' ),
				'description' => __( 'Friendly, conversational writing style', 'wp-seopress-pro' ),
				'content'     => 'Writing style:
- Use a friendly, conversational tone
- Write as if talking to a friend
- Use contractions naturally
- Include questions to engage the reader
- Keep sentences short and punchy
- Use "you" and "your" to address the reader directly',
			),
		);
	}

	/**
	 * Get active skills for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array Active skill contents.
	 */
	public static function get_active_skills( $user_id ) {
		$active_keys = get_user_meta( $user_id, '_seopress_ai_active_skills', true );
		if ( ! is_array( $active_keys ) || empty( $active_keys ) ) {
			return array();
		}

		$library = self::get_skills_library();

		// Also get custom skills.
		$custom_skills = get_user_meta( $user_id, '_seopress_ai_custom_skills', true );
		if ( is_array( $custom_skills ) ) {
			foreach ( $custom_skills as $key => $skill ) {
				$library[ 'custom_' . $key ] = $skill;
			}
		}

		$active = array();
		foreach ( $active_keys as $key ) {
			if ( isset( $library[ $key ] ) ) {
				$name = isset( $library[ $key ]['name'] ) ? $library[ $key ]['name'] : $key;
				$active[] = "### " . $name . "\n" . $library[ $key ]['content'];
			}
		}

		return $active;
	}
}
