<?php
/**
 * Listing form tools share the normal gateway, worker, approvals and ledger.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Approvals\EffectHandlerRegistry;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Native listing integration using the shared runtime. */
final class ListingFormModule {
	/**
	 * Site-scoped database.
	 *
	 * @var Database|null
	 */
	private ?Database $db = null;
	/**
	 * Native form service.
	 *
	 * @var ListingFormService|null
	 */
	private ?ListingFormService $service = null;

	/**
	 * Bind the site runtime and native listing services.
	 *
	 * @param ?Database $db Site-scoped runtime database.
	 */
	public function __construct( ?Database $db = null ) {
		$this->db = $db;
	}

	/**
	 * Register native listing integration hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		add_action( 'directorist_ai_pro_register_effect_handlers', array( $this, 'effects' ) );
		add_action( 'rest_api_init', array( $this, 'api' ) );
	}

	/**
	 * Reuse the bound site database and form service.
	 *
	 * @return ListingFormService
	 */
	private function service(): ListingFormService {
		if ( ! $this->service ) {
			global $wpdb;
			$this->db      = $this->db ?? new Database( $wpdb );
			$this->service = new ListingFormService( $this->db );
		}
		return $this->service;
	}

	/**
	 * Register bounded native listing tools with the common gateway.
	 *
	 * @param Registry       $registry Shared operation registry.
	 * @param SchemaRegistry $schemas Closed schema registry.
	 * @return void
	 */
	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		$service = $this->service();
		$empty   = array(
			'type'                 => 'object',
			'properties'           => (object) array(),
			'additionalProperties' => false,
		);
		$changes = array(
			'type'                 => 'object',
			'properties'           => array(
				'changes' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'field_id' => array(
								'type'      => 'string',
								'maxLength' => 64,
							),
							'value'    => array(
								'type'      => 'string',
								'maxLength' => 8192,
							),
						),
						'required'             => array( 'field_id', 'value' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'changes' ),
			'additionalProperties' => false,
		);
		$image   = array(
			'type'                 => 'object',
			'properties'           => array(
				'brief' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 400,
				),
			),
			'required'             => array( 'brief' ),
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
			'listing.form_inspect' => $empty,
			'listing.form_propose' => $changes,
			'listing.form_image'   => $image,
			'listing.media_import' => $apply,
			'listing.form_fill'    => $apply,
			'listing.term_fill'    => $apply,
			'listing.draft_create' => $apply,
		) as $id => $input ) {
			$schemas->register( $id . '.input', wp_json_encode( $input ) );
			$schemas->register( $id . '.output', wp_json_encode( $this->output_schema( $id ) ) );
			$read           = 'listing.form_inspect' === $id;
			$proposal       = 'listing.form_propose' === $id;
			$image_proposal = 'listing.form_image' === $id;
			$registry->register(
				new Operation(
					array(
						'id'            => $id,
						'revision'      => '1',
						'description'   => $read ? 'Inspect the server-bound listing form, its current unsaved facts and allowed typed native fields.' : ( $image_proposal ? 'Generate one new illustrative image for the server-bound native preview or gallery slot, using only a brief of at most 400 characters. Provider generation occurs in the durable worker; human acceptance is required before import.' : ( $proposal ? 'Prepare an exact human review of supported field changes within the current listing form scope. New listings become drafts, never submissions.' : 'Complete an exact human-reviewed listing draft or native form suggestion.' ) ),
						'input_schema'  => $id . '.input',
						'output_schema' => $id . '.output',
						'effect'        => $read ? 'read' : ( $proposal || $image_proposal ? 'private' : 'write' ),
						'profiles'      => array( 'admin', 'owner' ),
						'allow_guest'   => false,
						'retry_policy'  => $read || $proposal || $image_proposal ? 'never' : 'reconcile',
					),
					static function ( object $input, Context $context ) use ( $service, $read, $proposal, $image_proposal ): bool {
						if ( $context->actor()->is_guest() ) {
							return false;
						}
						if ( ! $read && ! $proposal && ! $image_proposal ) {
							return true;
						}
						try {
							$form = $service->bound( $context );
							if ( $image_proposal ) {
								return isset( $form->media );
							}
							return true;
						} catch ( \Throwable $error ) {
							return false;
						}
					},
					static fn( Context $context ): bool => ! $context->actor()->is_guest(),
					static fn(): bool => true,
					static fn( object $input, Context $context ): object => $image_proposal ? (object) array( 'status' => ( new \DirectoristAIPro\Orchestration\WordPressImageClient() )->available() ? 'ready' : 'image_unavailable' ) : ( $read ? $service->inspect( $context ) : ( $proposal ? $service->propose( $context, $input ) : (object) array() ) )
				)
			);
		}
	}

	/**
	 * Describe closed native listing response envelopes.
	 *
	 * @param string $id Native listing or operation identity.
	 * @return array
	 */
	private function output_schema( string $id ): array {
		$string = array( 'type' => 'string' );
		$object = array( 'type' => 'object' );
		$closed = static fn( array $props ): array => array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => array_keys( $props ),
			'additionalProperties' => false,
		);
		if ( 'listing.form_inspect' === $id ) {
			return $closed(
				array(
					'directory'          => $string,
					'mode'               => array( 'enum' => array( 'create_draft', 'edit_form', 'media_form', 'edit_terms' ) ),
					'controls'           => array(
						'type'  => 'array',
						'items' => $closed(
							array(
								'field_id'     => $string,
								'label'        => $string,
								'required'     => array( 'type' => 'boolean' ),
								'max'          => array( 'type' => 'integer' ),
								'before'       => $string,
								'widget'       => array(
									'type'      => 'string',
									'maxLength' => 64,
								),
								'value_format' => array( 'enum' => array( 'string', '0_or_1', 'YYYY-MM-DD', 'HH:MM', 'json_array_of_option_values', 'json_array_of_existing_term_ids', 'json_term_candidates', 'json_object_native_pricing' ) ),
								'options'      => array(
									'type'     => 'array',
									'maxItems' => 500,
									'items'    => $closed(
										array(
											'value' => array(
												'type' => array( 'string', 'integer' ),
												'maxLength' => 512,
											),
											'label' => array(
												'type' => 'string',
												'maxLength' => 512,
											),
										)
									),
								),
								'max_items'    => array(
									'type'    => array( 'integer', 'null' ),
									'minimum' => 1,
									'maximum' => 50,
								),
								'minimum'      => array(
									'type'      => array( 'number', 'string', 'null' ),
									'maxLength' => 64,
								),
								'maximum'      => array(
									'type'      => array( 'number', 'string', 'null' ),
									'maxLength' => 64,
								),
								'taxonomy'     => array(
									'type'      => array( 'string', 'null' ),
									'maxLength' => 128,
								),
								'pricing'      => array(
									'anyOf' => array(
										array( 'type' => 'null' ),
										$closed(
											array(
												'mode'     => array( 'enum' => array( 'both', 'price_unit', 'price_range' ) ),
												'types'    => array(
													'type' => 'array',
													'minItems' => 1,
													'maxItems' => 2,
													'items' => array( 'enum' => array( 'price', 'range' ) ),
												),
												'allow_decimal' => array( 'type' => 'boolean' ),
												'currency' => array(
													'type' => 'string',
													'maxLength' => 16,
												),
												'ranges'   => array(
													'type' => 'array',
													'minItems' => 4,
													'maxItems' => 4,
													'items' => $closed(
														array(
															'value' => array( 'enum' => array( 'skimming', 'moderate', 'economy', 'bellow_economy' ) ),
															'label' => array(
																'type' => 'string',
																'maxLength' => 128,
															),
														)
													),
												),
											)
										),
									),
								),
							)
						),
					),
					'current_form_facts' => $object,
					'instructions'       => $string,
				)
			);
		}
		if ( 'listing.form_image' === $id ) {
			return array(
				'type'  => 'object',
				'anyOf' => array(
					$closed( array( 'status' => array( 'enum' => array( 'ready', 'image_unavailable', 'image_rejected' ) ) ) ),
					$closed(
						array(
							'status'       => array( 'const' => 'staged' ),
							'capability'   => array( 'const' => 'listing.media_import' ),
							'operation_id' => $string,
							'artifact'     => $closed(
								array(
									'id'      => $string,
									'version' => array( 'type' => 'integer' ),
								)
							),
							'review'       => $closed(
								array(
									'id'          => $string,
									'review_hash' => array(
										'type'    => 'string',
										'pattern' => '^[a-f0-9]{64}$',
									),
								)
							),
						)
					),
				),
			);
		}
		if ( 'listing.form_propose' === $id ) {
			return $closed(
				array(
					'capability'   => array( 'enum' => array( 'listing.form_fill', 'listing.term_fill', 'listing.draft_create' ) ),
					'operation_id' => $string,
					'artifact'     => $closed(
						array(
							'id'      => $string,
							'version' => array( 'type' => 'integer' ),
						)
					),
					'review'       => $closed(
						array(
							'id'          => $string,
							'review_hash' => array(
								'type'    => 'string',
								'pattern' => '^[a-f0-9]{64}$',
							),
						)
					),
				)
			);
		}
		return $closed(
			array(
				'operation_id'     => $string,
				'plan_hash'        => $string,
				'proposal_hash'    => $string,
				'target_set_hash'  => $string,
				'state'            => array( 'enum' => array( 'succeeded', 'failed', 'partial', 'unknown' ) ),
				'plan'             => $object,
				'targets'          => $object,
				'external_effects' => array( 'type' => 'array' ),
				'observed_at'      => $string,
			)
		);
	}

	/**
	 * Register approved native form effect handlers.
	 *
	 * @param EffectHandlerRegistry $handlers Shared native effect registry.
	 * @return void
	 */
	public function effects( EffectHandlerRegistry $handlers ): void {
		$service = $this->service();
		$handler = new ListingFormEffect( $this->db, $service );
		$handlers->register( 'listing.form_fill', $handler );
		$handlers->register( 'listing.term_fill', $handler );
		$handlers->register( 'listing.draft_create', $handler );
		$handlers->register( 'listing.media_import', new ListingMediaEffect( $this->db, $service, new ListingMediaService( $this->db, $service ) ) );
	}

	/**
	 * Register the authenticated native form endpoints.
	 *
	 * @return void
	 */
	public function api(): void {
		$this->service();
		( new ListingFormApi( $this->db, $this->service ) )->register();
	}
}
