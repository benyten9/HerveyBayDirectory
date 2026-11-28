<?php
/**
 * Native WordPress abilities delegate to the same guarded gateway.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Validation\ContractException;

/** Registration does not imply public REST or MCP exposure. */
final class AbilityBridge {
	/**
	 * Trusted registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;
	/**
	 * Shared execution gateway.
	 *
	 * @var Gateway
	 */
	private Gateway $gateway;

	/**
	 * Bind the shared implementation path.
	 *
	 * @param Registry $registry Trusted definitions.
	 * @param Gateway  $gateway Shared authority.
	 */
	public function __construct( Registry $registry, Gateway $gateway ) {
		$this->registry = $registry;
		$this->gateway  = $gateway;
	}

	/**
	 * Register the native category and abilities on their required hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function () {
				wp_register_ability_category(
					'directorist-ai',
					array(
						'label'       => __( 'Directorist AI', 'directorist-ai' ),
						'description' => __( 'Authorized Directorist operations.', 'directorist-ai' ),
					)
				);
			}
		);
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Publish object-shaped lossless argument envelopes, never a raw callback.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->registry->all() as $operation ) {
			$metadata = $operation->metadata();
			$id       = $metadata['id'];
			$schema   = $this->registry->schema( $metadata['input_schema'] );
			if ( 'conversation.respond' === $id ) {
				$schema = \DirectoristAIPro\Retrieval\GroundedResponseContracts::model_description( $schema );
			}
			if ( 'builder.draft_propose' === $id ) {
				// Local references share identical locator/scope/configuration schemas.
				// The gateway still validates against the unchanged registered schema.
				$schema = ( new \DirectoristAIPro\Orchestration\BuilderPromptSchema() )->compact( $schema );
			}
			wp_register_ability(
				self::name( $id ),
				array(
					'label'               => $id,
					'description'         => $metadata['description'],
					'category'            => 'directorist-ai',
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'input_json' => array(
								'type'        => 'string',
								'maxLength'   => 1048576,
								'description' => 'JSON encoded operation input. Authoritative schema: ' . wp_json_encode( $schema ),
							),
						),
						'required'             => array( 'input_json' ),
						'additionalProperties' => false,
					),
					'permission_callback' => static function () {
						return get_current_user_id() > 0; },
					'execute_callback'    => function ( $input ) use ( $id ) {
						try {
							$values = is_array( $input ) ? $input : ( $input instanceof \stdClass ? get_object_vars( $input ) : array() );
							if ( array_keys( $values ) !== array( 'input_json' ) || ! is_string( $values['input_json'] ) ) {
								throw new ContractException( 'invalid_input', 'Expected the exact lossless argument envelope.' );
							}
							return $this->gateway->execute( $id, $values['input_json'], Context::current( 'external', 'ability' ) );
						} catch ( ContractException $error ) {
							return new \WP_Error( $error->error_code(), $error->getMessage() );
						}
					},
					'meta'                => array(
						'public'       => false,
						'show_in_rest' => false,
						'annotations'  => array( 'readonly' => 'read' === $metadata['effect'] ),
					),
				)
			);
		}
	}

	/**
	 * Map an operation ID to a native ability without ambiguous aliases.
	 *
	 * Registry IDs have no underscores in the domain and exactly one dot.
	 * Thus the first hyphen separates domain/action; action underscores map
	 * to remaining hyphens. The bounded ID also fits model function-name limits.
	 *
	 * @param string $id Trusted registered operation ID.
	 * @return string WordPress ability name.
	 */
	public static function name( string $id ): string {
		return 'directorist-ai/' . str_replace( array( '.', '_' ), '-', $id );
	}
}
