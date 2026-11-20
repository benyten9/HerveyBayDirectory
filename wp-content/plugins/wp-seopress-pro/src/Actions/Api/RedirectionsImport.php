<?php
/**
 * SEOPress PRO redirections import REST endpoints.
 *
 * @package SEOPress PRO
 * @subpackage Api
 */

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * The three calls a bulk redirections import is made of.
 *
 * The prepare call uploads the file and turns it into a job, run imports one
 * slice of that job, cancel throws it away. The client loops on run until it
 * comes back done, which is what keeps an import of any size inside the
 * request limits of any host.
 *
 * @since 10.3.0
 */
class RedirectionsImport implements ExecuteHooks {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );

		// Sweep job files left behind by an import nobody came back to.
		add_action( 'seopress_404_cron_cleaning', array( $this, 'garbage_collect' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register() {
		$permission = array( $this, 'permission' );

		register_rest_route(
			'seopress/v1',
			'/redirections/import/prepare',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processPrepare' ),
				'permission_callback' => $permission,
				'args'                => array(
					'source'    => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validateSource' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'separator' => array(
						'default'           => 'auto',
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'auto', 'comma', 'semicolon', 'tab', 'pipe' ), true );
						},
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/import/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processRun' ),
				'permission_callback' => $permission,
				'args'                => array(
					'jobId' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && (bool) preg_match( '/^[A-Za-z0-9]{8,64}$/', $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/import/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processStatus' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/import/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processCancel' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Who may import redirections.
	 *
	 * @return bool
	 */
	public function permission() {
		return current_user_can( seopress_capability( 'manage_options', 'import_settings' ) );
	}

	/**
	 * Whether the requested source is one the importer knows.
	 *
	 * @param string $param Source key.
	 *
	 * @return bool
	 */
	public function validateSource( $param ) {
		return is_string( $param ) && array_key_exists( $param, $this->importer()->get_sources() );
	}

	/**
	 * POST /seopress/v1/redirections/import/prepare
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processPrepare( \WP_REST_Request $request ) {
		$source = (string) $request->get_param( 'source' );
		$files  = $request->get_file_params();

		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error(
				'seopress_import_no_file',
				__( 'Please choose a file to import.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$file  = $files['file'];
		$error = $this->validateUpload( $file, $source );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$job = $this->importer()->prepare(
			$file['tmp_name'],
			$source,
			array( 'separator' => (string) $request->get_param( 'separator' ) )
		);

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return new \WP_REST_Response(
			array(
				'code' => 'prepared',
				'data' => $this->importer()->progress( $job, false ),
			)
		);
	}

	/**
	 * POST /seopress/v1/redirections/import/run
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processRun( \WP_REST_Request $request ) {
		$progress = $this->importer()->run( (string) $request->get_param( 'jobId' ) );

		if ( is_wp_error( $progress ) ) {
			return $progress;
		}

		return new \WP_REST_Response(
			array(
				'code' => $progress['done'] ? 'done' : 'running',
				'data' => $progress,
			)
		);
	}

	/**
	 * GET /seopress/v1/redirections/import/status
	 *
	 * What the Tools screen asks on load, so an import interrupted by a closed
	 * tab, a lost connection or a failed batch can be offered back to the user
	 * instead of being silently abandoned half-written.
	 *
	 * @return \WP_REST_Response
	 */
	public function processStatus() {
		$importer = $this->importer();
		$job      = $importer->get_job();

		return new \WP_REST_Response(
			array(
				'code' => 'status',
				'data' => $job ? $importer->progress( $job, false ) : null,
			)
		);
	}

	/**
	 * POST /seopress/v1/redirections/import/cancel
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processCancel() {
		$result = $this->importer()->cancel();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'code' => 'cancelled' ) );
	}

	/**
	 * Delete stale job files. Hooked on the daily redirections cron.
	 *
	 * @return void
	 */
	public function garbage_collect() {
		$this->importer()->garbage_collect();
	}

	/**
	 * Reject an upload that cannot be what it claims to be.
	 *
	 * PHP has already enforced upload_max_filesize and post_max_size by the
	 * time this runs; what is left to check is that the file arrived through an
	 * upload at all, and that its extension matches the chosen source - reading
	 * a JSON export as a CSV produces one nonsense redirection per line rather
	 * than an error the user can act on.
	 *
	 * @param array  $file   One entry of $_FILES.
	 * @param string $source Source key.
	 *
	 * @return true|\WP_Error
	 */
	protected function validateUpload( $file, $source ) {
		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error(
				'seopress_import_upload_error',
				$this->uploadErrorMessage( (int) $file['error'] ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error(
				'seopress_import_no_file',
				__( 'Please choose a file to import.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$sources    = $this->importer()->get_sources();
		$extensions = $sources[ $source ]['extensions'];
		$name       = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$extension  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, $extensions, true ) ) {
			return new \WP_Error(
				'seopress_import_wrong_extension',
				sprintf(
					/* translators: %s: comma-separated list of accepted file extensions, e.g. ".csv, .txt". */
					__( 'This source expects one of these file types: %s', 'wp-seopress-pro' ),
					seopress_pro_list_extensions( $extensions )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Turn a PHP upload error code into something actionable.
	 *
	 * @param int $code One of the UPLOAD_ERR_* constants.
	 *
	 * @return string
	 */
	protected function uploadErrorMessage( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: maximum upload size, e.g. "64 MB". */
					__( 'This file is larger than the %s your server accepts. Split it, or ask your host to raise upload_max_filesize.', 'wp-seopress-pro' ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The file was only partially uploaded. Please try again.', 'wp-seopress-pro' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Please choose a file to import.', 'wp-seopress-pro' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded file to disk. Check the permissions of your temporary folder.', 'wp-seopress-pro' );
			default:
				return __( 'The file could not be uploaded. Please try again.', 'wp-seopress-pro' );
		}
	}

	/**
	 * The import service.
	 *
	 * @return \SEOPressPro\Services\RedirectionsImport
	 */
	protected function importer() {
		return seopress_pro_get_service( 'RedirectionsImport' );
	}
}
