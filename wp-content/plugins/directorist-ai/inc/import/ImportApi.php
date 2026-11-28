<?php
/**
 * Native administrator REST surface; raw child plans are never exposed.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;

/** Bootstrap supplies the existing accounted model route as an optional callback. */
final class ImportApi {
	private ImportService $service;
	private $mapping_callback;
	public function __construct( private Database $db, ?callable $mapping_callback = null ) {
		$this->service          = new ImportService( $db );
		$this->mapping_callback = $mapping_callback; }
	public function register(): void {
		$this->route( '/imports', 'GET', fn( $request ) => $this->service->history_page( $this->context(), self::integer( $request, 'offset' ) ) );
		$this->route( '/imports/authors', 'GET', fn( $request ) => AuthorCatalog::read( $this->context(), self::integer( $request, 'directory_id' ), self::text( $request, 'search' ), self::integer( $request, 'offset' ), self::integer( $request, 'selected' ) ) );
		$this->route(
			'/imports',
			'POST',
			function ( $request ) {
				$directory = (int) $request->get_param( 'directory_id' );
				$context   = $this->context();
				$this->service->schema( $context, $directory ); // Reject authority/selection before any source fetch.
				$source = 'url' === $request->get_param( 'source_kind' ) ? ( new SourceIntake() )->url( (string) $request->get_param( 'url' ) ) : ( new SourceIntake() )->upload( $request->get_file_params()['source'] ?? array(), (string) ( $request->get_param( 'records_path' ) ?? '' ) );
				return $this->service->stage( $context, $directory, $source );
			}
		);
		$this->route( '/imports/schema/(?P<directory>[1-9][0-9]*)', 'GET', fn( $request ) => $this->service->schema( $this->context(), (int) $request['directory'] ) );
		$job = '/imports/(?P<id>[a-f0-9]{32})';
		$this->route( $job, 'GET', fn( $request ) => $this->service->view( $this->context(), $request['id'], self::integer( $request, 'offset' ), self::text( $request, 'filter', 'all' ) ) );
		$this->route( $job . '/paths', 'GET', fn( $request ) => $this->service->source_paths( $this->context(), $request['id'], self::text( $request, 'search' ), self::integer( $request, 'offset' ) ) );
		$this->route( $job . '/path-check', 'POST', fn( $request ) => $this->service->source_path( $this->context(), $request['id'], self::text( $request, 'source_path' ) ) );
		$this->route( $job . '/mapping', 'PUT', fn( $request ) => $this->service->mapping( $this->context(), $request['id'], (int) $request->get_param( 'version' ), $request->get_param( 'mapping' ) ?? array(), $request->get_param( 'policy' ) ?? array() ) );
		$this->route(
			$job . '/suggest',
			'POST',
			function ( $request ) {
				$context = $this->context();
				try {
					$request_id = self::text( $request, 'request_id' );
					\DirectoristAIPro\Storage\ConversationStore::identifier( $request_id );
				} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
					return new \WP_Error(
						$error->error_code(),
						$error->getMessage(),
						array(
							'status'     => 400,
							'acceptance' => 'rejected_before_acceptance',
						)
					);
				}
					$envelope = array(
						'job_id'     => $request['id'],
						'request_id' => $request_id,
					);
				if ( ! $this->mapping_callback ) {
					return new \WP_Error(
						'mapping_unavailable',
						'No mapping provider is configured. Parsing and manual mapping are available.',
						array(
							'status'     => 503,
							'acceptance' => 'uncertain',
						)
					); }
				return call_user_func( $this->mapping_callback, $context, $envelope );
			}
		);
		$this->route( $job . '/validate', 'POST', fn( $request ) => $this->service->validate( $this->context(), $request['id'] ) );
		$this->route( $job . '/review', 'POST', fn( $request ) => $this->service->review( $this->context(), $request['id'], (int) $request->get_param( 'version' ), true === $request->get_param( 'valid_only' ), $request->get_param( 'selected_records' ) ?? array() ) );
		$this->route( $job . '/launch', 'POST', fn( $request ) => $this->service->launch( $this->context(), $request['id'], (string) $request->get_param( 'review_hash' ) ) );
		$this->route( $job . '/resume', 'POST', fn( $request ) => $this->service->resume( $this->context(), $request['id'] ) );
		$this->route( $job . '/cancel', 'POST', fn( $request ) => $this->service->cancel( $this->context(), $request['id'] ) );
		$this->route(
			$job . '/report',
			'GET',
			fn( $request ) => array(
				'filename' => 'listing-import-' . $request['id'] . '.csv',
				'csv'      => $this->service->report( $this->context(), $request['id'] ),
			)
		);
	}

	private function route( string $path, string $method, callable $callback ): void {
		$db = $this->db;
		register_rest_route(
			'directorist-ai/v1',
			$path,
			array(
				'methods'             => $method,
				'permission_callback' => static fn( $request ) => is_user_logged_in() && current_user_can( 'manage_options' ) && wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ),
				'callback'            => static function ( $request ) use ( $callback, $db, $path ) {
					try {
						$db->readiness()->assert_ready();
						return $callback( $request );
					} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
						$status = 'storage_unavailable' === $error->error_code() ? 503 : ( 'unauthorized' === $error->error_code() ? 403 : 409 );
						$data = array( 'status' => $status );
						if ( str_ends_with( $path, '/suggest' ) ) {
							$data['acceptance'] = 'uncertain'; }
						if ( 'import_unavailable' === $error->error_code() ) {
							$data['job_availability'] = 'unavailable'; }
						return new \WP_Error( $error->error_code(), $error->getMessage(), $data );
					} catch ( \Throwable $error ) {
						return new \WP_Error(
							'import_unavailable',
							'Import storage or a native dependency is unavailable. Reload the private job before retrying.',
							array(
								'status'     => 503,
								'acceptance' => str_ends_with( $path, '/suggest' ) ? 'uncertain' : null,
							)
						);
					} },
			)
		);
	}
	private function context(): Context {
		return Context::current( 'admin', 'ui' ); }

	private static function integer( $request, string $name ): int {
		$value = $request->get_param( $name ) ?? 0;
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || strlen( (string) $value ) > 10 ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Choose a valid nonnegative identifier or record offset.' );
		}
		return (int) $value;
	}

	private static function text( $request, string $name, string $fallback = '' ): string {
		$value = $request->get_param( $name ) ?? $fallback;
		if ( ! is_string( $value ) || strlen( $value ) > 4096 || 1 !== preg_match( '//u', $value ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Use a bounded text value.' );
		}
		return $value;
	}
}
