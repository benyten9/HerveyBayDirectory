<?php
/**
 * The public help center page, for the strip on the Articles screen:
 *
 *   GET  kb/page   Where the `[doublescale_help_center]` page is (View / Edit
 *                  links) and the shortcode to copy.
 *   POST kb/page   Adopt-or-create the page when it's missing or was trashed.
 *   POST kb/page/address   The page's slug and the word in category
 *                  addresses; old addresses keep working ({@see KbAddresses}).
 *
 * The page is created on its own when the module boots
 * ({@see \DoubleScale\Core\Services\ShortcodePageProvisioner}); this is the
 * visible layer and the manual way back, like the Client Portal settings card.
 *
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use DoubleScale\Modules\KnowledgeBase\Services\KbAddresses;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestKbPageController class.
 */
class RestKbPageController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/kb/page',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'create_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/kb/page/address',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_address' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'slug'          => array( 'type' => 'string' ),
						'category_word' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Help center managers see where the page is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function manage_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return KbAccess::can_manage();
	}

	/**
	 * Creating a WordPress page also needs the right to publish pages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function create_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return KbAccess::can_manage() && current_user_can( 'publish_pages' );
	}

	/**
	 * GET kb/page
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		return new WP_REST_Response( self::status(), 200 );
	}

	/**
	 * POST kb/page
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		if ( ShortcodePageProvisioner::provision( KbPublicArticles::SHORTCODE ) <= 0 ) {
			return new WP_Error(
				'kb_page_create_failed',
				__( 'The help center page could not be created. Add the shortcode to a page instead.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( self::status(), 200 );
	}

	/**
	 * POST kb/page/address
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_address( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$page_id = ShortcodePageProvisioner::resolve_page_id( KbPublicArticles::SHORTCODE );
			if ( $page_id <= 0 ) {
				return new WP_Error( 'kb_page_missing', __( 'Create the help center page first.', 'doublescale' ), array( 'status' => 404 ) );
			}
			// Renaming the page is editing it.
			if ( ! current_user_can( 'edit_post', $page_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You can’t edit the help center page.', 'doublescale' ), array( 'status' => rest_authorization_required_code() ) );
			}
			$renamed = KbAddresses::rename_page( $page_id, (string) $slug );
			if ( is_wp_error( $renamed ) ) {
				return $renamed;
			}
		}

		$word = $request->get_param( 'category_word' );
		if ( null !== $word ) {
			$saved = KbAddresses::set_category_segment( (string) $word );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return new WP_REST_Response( self::status(), 200 );
	}

	/**
	 * The page's status, with the Edit link only for someone who may edit it.
	 *
	 * @return array<string, mixed>
	 */
	private static function status(): array {
		$status  = ShortcodePageProvisioner::get_status( KbPublicArticles::SHORTCODE );
		$page_id = (int) $status['page_id'];

		if ( $page_id <= 0 || ! current_user_can( 'edit_post', $page_id ) ) {
			$status['edit_url'] = '';
		}

		// The address up to the page's own slug: home, then any parent page.
		$slug   = $page_id > 0 ? rawurldecode( (string) get_post_field( 'post_name', $page_id ) ) : '';
		$uri    = $page_id > 0 ? trim( (string) get_page_uri( $page_id ), '/' ) : '';
		$parent = '' !== $uri && false !== strrpos( $uri, '/' ) ? substr( $uri, 0, (int) strrpos( $uri, '/' ) + 1 ) : '';

		return array(
			'exists'        => (bool) $status['exists'],
			'page_id'       => $page_id,
			'title'         => $page_id > 0 ? get_the_title( $page_id ) : '',
			'private'       => $page_id > 0 && 'private' === get_post_status( $page_id ),
			'view_url'      => (string) $status['view_url'],
			'edit_url'      => (string) $status['edit_url'],
			'shortcode'     => (string) $status['shortcode'],
			'can_create'    => current_user_can( 'publish_pages' ),
			'slug'          => $slug,
			'address_start' => trailingslashit( home_url( '/' . self::front() . rawurldecode( $parent ) ) ),
			'category_word' => KbAddresses::category_segment(),
			// Plain links (?page_id=) have no addresses to change.
			'pretty'        => '' !== (string) get_option( 'permalink_structure' ),
			'can_rename'    => $page_id > 0 && current_user_can( 'edit_post', $page_id ),
		);
	}

	/**
	 * `index.php/` on "PATHINFO" permalinks, else ''.
	 *
	 * @return string
	 */
	private static function front(): string {
		global $wp_rewrite;

		return $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_index_permalinks() ? $wp_rewrite->index . '/' : '';
	}
}
