<?php

namespace SEOPressPro\Actions\Api;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Services\RobotsTxt\RobotsTxtRevisions;

/**
 * REST API for the robots.txt revisions history: list, diff and restore.
 *
 * Routes (namespace `seopress/v1`):
 *  - GET  /robots-revisions          List revisions for a history.
 *  - GET  /robots-revisions/diff     Unified HTML diff between two revisions.
 *  - POST /robots-revisions/restore  Restore a revision as the live robots.txt.
 *
 * @since 10.0.0
 */
class RobotsRevisions implements ExecuteHooks {

	/**
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Permission check. Uses the live current-user state on purpose (see
	 * ProSettings::permissionCheck for why it is not cached).
	 *
	 * @return boolean
	 */
	public function permissionCheck() {
		return current_user_can( seopress_capability( 'manage_options', 'pro' ) );
	}

	/**
	 * Shared `type` argument definition (site | network).
	 *
	 * @return array
	 */
	protected function typeArg() {
		return array(
			'type' => array(
				'default'           => RobotsTxtRevisions::TYPE_SITE,
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( RobotsTxtRevisions::TYPE_SITE, RobotsTxtRevisions::TYPE_NETWORK ), true );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/robots-revisions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processList' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
					'args'                => $this->typeArg(),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/robots-revisions/diff',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processDiff' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
					'args'                => array_merge(
						$this->typeArg(),
						array(
							'from' => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
							'to'   => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/robots-revisions/restore',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processRestore' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
					'args'                => array_merge(
						$this->typeArg(),
						array(
							'id' => array(
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * GET /robots-revisions — list a history (newest first).
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processList( \WP_REST_Request $request ) {
		$type    = $request->get_param( 'type' );
		$service = $this->service();

		$current   = $this->currentContent( $type );
		$revisions = array();

		foreach ( $service->all( $type ) as $revision ) {
			$revisions[] = $this->present( $revision, $current );
		}

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'type'      => $type,
				'revisions' => $revisions,
			),
			200
		);
	}

	/**
	 * GET /robots-revisions/diff — HTML diff between two revisions.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDiff( \WP_REST_Request $request ) {
		$type    = $request->get_param( 'type' );
		$from_id = (int) $request->get_param( 'from' );
		$to_id   = (int) $request->get_param( 'to' );
		$service = $this->service();

		$from = $service->get( $from_id, $type );
		$to   = $service->get( $to_id, $type );

		if ( null === $from || null === $to ) {
			return new \WP_Error(
				'not_found',
				__( 'One of the revisions to compare no longer exists.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! function_exists( 'wp_text_diff' ) ) {
			require_once ABSPATH . 'wp-admin/includes/revision.php';
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}

		$diff = wp_text_diff(
			$from['content'],
			$to['content'],
			array(
				'title_left'  => __( 'Older', 'wp-seopress-pro' ),
				'title_right' => __( 'Newer', 'wp-seopress-pro' ),
			)
		);

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'identical' => '' === $diff,
				'diff'      => $diff,
				'from'      => $this->present( $from, null ),
				'to'        => $this->present( $to, null ),
			),
			200
		);
	}

	/**
	 * POST /robots-revisions/restore — set a revision as the live robots.txt.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processRestore( \WP_REST_Request $request ) {
		$type    = $request->get_param( 'type' );
		$id      = (int) $request->get_param( 'id' );
		$service = $this->service();

		if ( null === $service->get( $id, $type ) ) {
			return new \WP_Error(
				'not_found',
				__( 'This revision no longer exists.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		$restored = $service->restore( $id, $type );

		if ( ! $restored ) {
			return new \WP_Error(
				'restore_failed',
				__( 'The revision could not be restored.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		$current   = $this->currentContent( $type );
		$revisions = array();
		foreach ( $service->all( $type ) as $revision ) {
			$revisions[] = $this->present( $revision, $current );
		}

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'message'   => __( 'Revision restored.', 'wp-seopress-pro' ),
				'content'   => $current,
				'revisions' => $revisions,
			),
			200
		);
	}

	/**
	 * Shape a stored revision for the API, enriched for display.
	 *
	 * @param array       $revision Raw revision.
	 * @param string|null $current  Current live content, to flag the active one.
	 *
	 * @return array
	 */
	protected function present( array $revision, $current ) {
		$author      = (int) $revision['author'];
		$author_name = $author > 0 ? get_the_author_meta( 'display_name', $author ) : __( 'System', 'wp-seopress-pro' );

		return array(
			'id'         => (int) $revision['id'],
			'content'    => (string) $revision['content'],
			'size'       => strlen( (string) $revision['content'] ),
			'author'     => $author,
			'authorName' => $author_name ? $author_name : __( 'Unknown', 'wp-seopress-pro' ),
			'time'       => (int) $revision['time'],
			/* translators: %s: human time difference, e.g. "3 hours". */
			'timeHuman'  => sprintf( __( '%s ago', 'wp-seopress-pro' ), human_time_diff( (int) $revision['time'] ) ),
			'dateFull'   => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $revision['time'] ),
			'isCurrent'  => null !== $current && (string) $revision['content'] === (string) $current,
		);
	}

	/**
	 * Current live robots.txt content for a history type.
	 *
	 * @param string $type Self::TYPE_* value.
	 *
	 * @return string
	 */
	protected function currentContent( $type ) {
		if ( RobotsTxtRevisions::TYPE_NETWORK === $type ) {
			$network = function_exists( 'get_network' ) ? get_network() : null;
			if ( null === $network ) {
				return '';
			}

			$option = get_blog_option( $network->site_id, 'seopress_pro_mu_option_name' );

			return is_array( $option ) && isset( $option['seopress_mu_robots_file'] ) ? (string) $option['seopress_mu_robots_file'] : '';
		}

		$option = get_option( 'seopress_pro_option_name' );

		return is_array( $option ) && isset( $option['seopress_robots_file'] ) ? (string) $option['seopress_robots_file'] : '';
	}

	/**
	 * Revisions service.
	 *
	 * @return RobotsTxtRevisions
	 */
	protected function service() {
		return seopress_pro_get_service( 'RobotsTxtRevisions' );
	}
}
