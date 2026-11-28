<?php
/**
 * Native directory tools registered in the common gateway and effect ledger.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Directory;

use DirectoristAIPro\Approvals\EffectHandlerRegistry;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\SchemaRegistry;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Shared-runtime integration.
/** Native Directory Builder AI integration. */
final class DirectoryModule {
	private ?Database $db              = null;
	private ?DirectoryService $service = null;

	public function __construct( ?Database $db = null ) {
		$this->db = $db; }

	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		add_action( 'directorist_ai_pro_register_effect_handlers', array( $this, 'effects' ) );
		add_action( 'rest_api_init', array( $this, 'api' ) );
	}

	private function service(): DirectoryService {
		if ( ! $this->service ) {
			global $wpdb;
			$this->db      = $this->db ?? new Database( $wpdb );
			$this->service = new DirectoryService( $this->db ); }
		return $this->service;
	}

	public static function proposal_schema(): array {
		$text    = array(
			'type'      => array( 'string', 'null' ),
			'maxLength' => 200,
		);
		$boolean = array( 'type' => array( 'boolean', 'null' ) );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 100,
				),
				'fields'   => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'         => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 128,
							),
							'widget'      => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 64,
							),
							'label'       => $text,
							'placeholder' => $text,
							'group'       => $text,
							'required'    => $boolean,
							'options'     => array(
								'type'     => array( 'array', 'null' ),
								'maxItems' => 30,
								'items'    => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 100,
								),
							),
							'search'      => $boolean,
							'single'      => $boolean,
							'card'        => $boolean,
						),
						'required'             => array( 'key', 'widget' ),
						'additionalProperties' => false,
					),
				),
				'settings' => array(
					'type'     => 'array',
					'maxItems' => count( DirectorySchema::SETTINGS ),
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'   => array( 'enum' => DirectorySchema::SETTINGS ),
							'value' => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
						),
						'required'             => array( 'key', 'value' ),
						'additionalProperties' => false,
					),
				),
				'order'    => array(
					'type'        => array( 'array', 'null' ),
					'maxItems'    => 100,
					'uniqueItems' => true,
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 128,
					),
				),
			),
			'required'             => array( 'fields', 'settings' ),
			'additionalProperties' => false,
		);
	}

	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		$service = $this->service();
		$empty   = array(
			'type'                 => 'object',
			'properties'           => (object) array(),
			'additionalProperties' => false,
		);
		$apply   = array(
			'type'                 => 'object',
			'properties'           => array(
				'approval_id'  => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'operation_id' => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'review_hash'  => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'approval_id', 'operation_id', 'review_hash' ),
			'additionalProperties' => false,
		);
		foreach ( array(
			'directory.inspect'     => $empty,
			'directory.propose'     => self::proposal_schema(),
			'directory.create'      => $apply,
			'directory.editor_fill' => $apply,
		) as $id => $input ) {
			$read     = 'directory.inspect' === $id;
			$proposal = 'directory.propose' === $id;
			$schemas->register( $id . '.input', wp_json_encode( $input ) );
			$schemas->register( $id . '.output', wp_json_encode( array( 'type' => 'object' ) ) );
			$registry->register(
				new Operation(
					array(
						'id'            => $id,
						'revision'      => '1',
						'description'   => $read ? 'Inspect the bound directory builder configuration, native field catalog and supported settings.' : ( $proposal ? 'Propose a native directory configuration for human review. Fields are additive updates: preserve existing keys and types. Null properties preserve defaults. Settings values are JSON strings. No directory is saved by this proposal.' : 'Apply an exact human-reviewed native directory creation or unsaved editor suggestion.' ),
						'input_schema'  => $id . '.input',
						'output_schema' => $id . '.output',
						'effect'        => $read ? 'read' : ( $proposal ? 'private' : 'write' ),
						'profiles'      => array( 'admin' ),
						'allow_guest'   => false,
						'retry_policy'  => $read || $proposal ? 'never' : 'reconcile',
					),
					static function ( object $input, Context $context ) use ( $service, $read, $proposal ): bool {
						if ( $context->actor()->is_guest() || ! $context->actor()->can( 'manage_options' ) ) {
							return false; }
						if ( ! $read && ! $proposal ) {
							return true; }
						try {
							$service->bound( $context );
							return true;
						} catch ( \Throwable $error ) {
										return false; }
					},
					static fn( Context $context ): bool => $context->actor()->can( 'manage_options' ),
					static fn(): bool => true,
					static fn( object $input, Context $context ): object => $read ? $service->inspect( $context ) : ( $proposal ? $service->propose( $context, $input ) : (object) array() )
				)
			);
		}
	}

	public function effects( EffectHandlerRegistry $handlers ): void {
		$service = $this->service();
		$handler = new DirectoryEffect( $this->db, $service );
		$handlers->register( 'directory.create', $handler );
		$handlers->register( 'directory.editor_fill', $handler );
	}

	public function api(): void {
		$service = $this->service();
		( new DirectoryApi( $this->db, $service ) )->register(); }
}
