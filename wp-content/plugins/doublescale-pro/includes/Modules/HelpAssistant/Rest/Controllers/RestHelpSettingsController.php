<?php
/**
 * Help assistant settings (Helpdesk → Help Center → Assistant).
 *
 *   GET  /doublescale/v1/help/settings  settings + what the assistant needs
 *   POST /doublescale/v1/help/settings  partial settings → stored settings
 *
 * Site administrators only: these turn on spending on the site's AI key.
 * Values are bounded by {@see HelpSettings::normalize()}.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatGuard;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveChatPlan;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HelpSettings;
use DoubleScale\Pro\Modules\HelpAssistant\Services\SitePages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpSettingsController class.
 */
class RestHelpSettingsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'help/settings';

	/**
	 * @return void
	 */
	public function register_routes() {
		// The site pages the assistant may learn, and whether it uses each.
		register_rest_route(
			$this->namespace,
			'/help/site-pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'site_pages' ),
					'permission_callback' => array( $this, 'site_pages_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function site_pages( WP_REST_Request $request ) {
		$list = SitePages::overview( (int) ( $request->get_param( 'page' ) ?: 1 ), (int) ( $request->get_param( 'per_page' ) ?: 20 ) );

		return new WP_REST_Response(
			array(
				'settings'    => SitePages::settings(),
				'pages'       => $list['pages'],
				'total'       => $list['total'],
				'total_pages' => $list['total_pages'],
				'used'        => count( array_unique( SitePages::learned_post_ids() ) ),
				'max'         => (int) apply_filters( 'doublescale_help_site_pages_max', SitePages::MAX ),
			),
			200
		);
	}

	/**
	 * Who may change the assistant's settings may see its pages.
	 *
	 * @return bool
	 */
	public function site_pages_permissions_check(): bool {
		return $this->permissions_check();
	}

	/**
	 * @return bool
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return new WP_REST_Response( self::shape( HelpSettings::get() ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$body    = (array) $request->get_json_params();
		$allowed = array_intersect_key( $body, array_flip( array( 'enabled', 'name', 'fallback', 'welcome', 'quick_replies', 'faq', 'instructions', 'avatar', 'subtitle', 'theme', 'channels', 'widget', 'limits', 'live_chat', 'site_pages', 'handoff', 'handoff_mailbox', 'retention_days' ) ) );

		// The assistant is turned on only once AI works: connected in
		// Settings → AI and not refused by the provider. Turning it off, and
		// every other setting, stays possible.
		if ( ! empty( $allowed['enabled'] ) && ! HelpSettings::enabled() && ! RestHelpPublicChatController::ai_ready() ) {
			$problem = \DoubleScale\Pro\Modules\Ai\AiHealth::problem();
			return new WP_Error(
				'help_ai_not_ready',
				'' !== $problem
					/* translators: %s: the AI provider's own error message */
					? sprintf( __( 'AI isn\'t working: “%s”. Check Settings → AI, then turn the assistant on.', 'doublescale' ), $problem )
					: __( 'Connect an AI provider in Settings → AI first, then turn the assistant on.', 'doublescale' ),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response( self::shape( HelpSettings::update( $allowed ) ), 200 );
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function shape( array $settings ): array {
		// The chosen picture, for the settings screen to show.
		$settings['avatar_url'] = ! empty( $settings['avatar'] ) ? (string) wp_get_attachment_image_url( (int) $settings['avatar'], 'thumbnail' ) : '';

		return array(
			'settings' => $settings,
			'status'   => array(
				'ai_configured' => RestHelpPublicChatController::ai_ready(),
				'limits_ready'  => ChatGuard::available(),
				// For the setup hints on Helpdesk → Settings → Chat.
				'ai_connected'  => class_exists( '\\DoubleScale\\Admin\\AdminConfig' ) && \DoubleScale\Admin\AdminConfig::is_ai_configured(),
				'ai_problem'    => \DoubleScale\Pro\Modules\HelpAssistant\Module::can_change_ai() ? \DoubleScale\Pro\Modules\Ai\AiHealth::problem() : '',
				'articles'      => \DoubleScale\Pro\Modules\HelpAssistant\Module::answerable_articles(),
				'live_plan'     => LiveChatPlan::allowed(),
			),
		);
	}
}
