<?php // phpcs:ignore

namespace SEOPressPro\Actions\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Helpers\Audit\HomepageAudit;

/**
 * Register SEOPress PRO abilities on the WordPress Abilities API (WP 6.9+).
 *
 * The free plugin registers the per-post abilities (titles, robots, social,
 * content analysis). Pro adds the site-wide ones. Here we expose the
 * homepage / technical audit as a read-only ability so AI agents and MCP
 * clients can query the site's technical health in one call.
 *
 * @since 10.1.0
 */
class AbilitiesApi implements ExecuteHooks {

	/**
	 * Shared category slug, created by the free plugin. We reuse it and only
	 * register it defensively in case Pro somehow boots without the free
	 * category being present.
	 */
	const CATEGORY = 'seopress';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		// Feature-detect: silently no-op on WordPress < 6.9 or when the free
		// helper is unavailable.
		if ( ! function_exists( 'seopress_abilities_api_available' ) || ! seopress_abilities_api_available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	/**
	 * Register the shared "seopress" ability category if the free plugin has
	 * not already done so.
	 *
	 * @return void
	 */
	public function registerCategory() {
		if ( function_exists( 'wp_get_ability_category' ) && wp_get_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SEO', 'wp-seopress-pro' ),
				'description' => __( 'Read and manage SEO data (titles, meta descriptions, robots directives, social metadata, content and technical analysis).', 'wp-seopress-pro' ),
			)
		);
	}

	/**
	 * Register the Pro abilities.
	 *
	 * @return void
	 */
	public function registerAbilities() {
		wp_register_ability(
			'seopress/get-technical-audit',
			array(
				'label'               => __( 'Get site technical audit', 'wp-seopress-pro' ),
				'description'         => __( 'Run the site-wide technical checks (homepage head tags, HTTP security headers, indexability, social tags, well-known endpoints) and return the results as a structured checklist.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'refresh' => array(
							'type'        => 'boolean',
							'description' => __( 'When true, force a fresh analysis instead of returning the cached result (up to 15 minutes old).', 'wp-seopress-pro' ),
							'default'     => false,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'checkedUrl'  => array(
							'type'        => 'string',
							'description' => __( 'The URL that was analyzed (the site homepage).', 'wp-seopress-pro' ),
						),
						'generatedAt' => array(
							'type'        => 'string',
							'description' => __( 'When the analysis was generated (site timezone).', 'wp-seopress-pro' ),
						),
						'reachable'   => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the homepage could be fetched.', 'wp-seopress-pro' ),
						),
						'summary'     => array(
							'type'        => 'object',
							'description' => __( 'Counts of passing, recommended and failing checks.', 'wp-seopress-pro' ),
							'properties'  => array(
								'good'    => array( 'type' => 'integer' ),
								'warning' => array( 'type' => 'integer' ),
								'bad'     => array( 'type' => 'integer' ),
							),
						),
						'checks'      => array(
							'type'        => 'array',
							'description' => __( 'The individual checks. Each has id, group, label, status (good|warning|bad|na) and a description.', 'wp-seopress-pro' ),
						),
					),
				),
				'permission_callback' => array( $this, 'canManageAudit' ),
				'execute_callback'    => function ( $input ) {
					$refresh = ! empty( $input['refresh'] );

					return ( new HomepageAudit() )->getResults( $refresh );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => true,
					)
				),
			)
		);
	}

	/**
	 * Build the "meta" array of an ability.
	 *
	 * Delegates to the free plugin helper, which owns the exposure rules
	 * (meta.mcp.public for MCP clients, meta.show_in_rest for the REST API
	 * on WordPress 6.9/7.0). The fallback covers Pro running alongside a free
	 * version that predates the helper, and stays disabled by default.
	 *
	 * @since 10.2.0
	 *
	 * @param array $annotations The MCP annotations.
	 *
	 * @return array
	 */
	protected function abilityMeta( $annotations ) {
		if ( function_exists( 'seopress_abilities_api_meta' ) ) {
			return seopress_abilities_api_meta( $annotations );
		}

		$exposed = function_exists( 'seopress_abilities_api_rest_enabled' ) && seopress_abilities_api_rest_enabled();

		return array(
			'public'       => $exposed,
			'show_in_rest' => $exposed,
			'mcp'          => array(
				'public' => $exposed,
				'type'   => 'tool',
			),
			'annotations'  => $annotations,
		);
	}

	/**
	 * Permission gate for the technical audit ability. Mirrors the Site Audit
	 * REST controller so custom-role capabilities are honoured.
	 *
	 * @return bool
	 */
	public function canManageAudit() {
		$cap = function_exists( 'seopress_capability' )
			? seopress_capability( 'manage_options', 'bot' )
			: 'manage_options';

		return current_user_can( $cap );
	}
}
