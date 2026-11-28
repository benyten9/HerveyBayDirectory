<?php
/**
 * Explicit per-profile model tool allowlists and guarded call execution.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Capabilities\AbilityBridge;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Builder\GraphVersion;
use DirectoristAIPro\Validation\ContractException;

/** Discovery narrows this policy; it can never add an operation. */
final class ToolPolicy {
	private const ALLOWED = array(
		'admin'   => array( 'chat.starters_propose', 'import.mapping_propose', 'directory.inspect', 'directory.propose', 'listing.form_inspect', 'listing.form_propose', 'listing.form_image', 'runtime.status', 'conversation.clarify', 'conversation.respond', 'directory.describe', 'listing.catalog', 'listing.inspect', 'listing.propose', 'media.propose', 'listing.bulk_propose', 'taxonomy.propose', 'listing.search', 'listing.compare', 'knowledge.search', 'builder.describe', 'builder.inspect', 'builder.draft_propose', 'builder.publish_propose', 'builder.restore_propose', 'builder.template_assign_propose', 'builder.template_restore_propose', 'builder.live_propose' ),
		'owner'   => array( 'listing.form_inspect', 'listing.form_propose', 'listing.form_image', 'conversation.clarify', 'conversation.respond', 'directory.describe', 'listing.catalog', 'listing.inspect', 'listing.propose', 'media.propose', 'listing.search', 'listing.compare', 'knowledge.search' ),
		'visitor' => array( 'conversation.clarify', 'conversation.respond', 'directory.describe', 'listing.catalog', 'listing.search', 'listing.compare', 'listing.actions', 'knowledge.search' ),
		'builder' => array( 'conversation.clarify', 'directory.describe', 'listing.inspect', 'builder.describe', 'builder.inspect', 'builder.draft_propose', 'builder.publish_propose', 'builder.restore_propose', 'builder.template_assign_propose', 'builder.template_restore_propose', 'builder.live_propose' ),
	);

	/**
	 * Intersect the fixed policy with live gateway discovery and native abilities.
	 *
	 * @param Gateway $gateway Shared guarded gateway.
	 * @param Context $context Current run context.
	 * @return array<string,array> Function-name keyed execution map.
	 */
	public function catalog( Gateway $gateway, Context $context ): array {
		$allowed = array_fill_keys( self::ALLOWED[ $context->profile() ] ?? array(), true );
		$result  = array();
		foreach ( $gateway->describe( $context ) as $definition ) {
			if ( ! isset( $allowed[ $definition['id'] ] ) || 'write' === $definition['effect'] ) {
				continue;
			}
			$ability_name = AbilityBridge::name( $definition['id'] );
			if ( ! wp_get_ability( $ability_name ) ) {
				continue;
			}
			$function_name            = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $ability_name );
			$result[ $function_name ] = array(
				'operation_id' => $definition['id'],
				'ability_name' => $ability_name,
			);
		}
		return $result;
	}

	/**
	 * Narrow a visitor's next round after freshly validated retrieval.
	 *
	 * The final typed plan performs its own current-source retrieval, including
	 * refinement. Discovery stays available when no refreshed retrieval succeeds.
	 *
	 * @param array   $catalog Current profile/discovery intersection.
	 * @param array   $results Results re-executed through the current gateway.
	 * @param Context $context Current run identity.
	 * @return array Function map shared by declaration and execution.
	 */
	public function for_results( array $catalog, array $results, Context $context ): array {
		if ( in_array( $context->profile(), array( 'admin', 'owner' ), true ) ) {
			foreach ( $results as $entry ) {
				if ( true === ( $entry->response->ok ?? false ) && in_array( $entry->response->operation ?? null, array( 'listing.form_inspect', 'directory.inspect' ), true ) ) {
					return array_filter( $catalog, static fn( array $tool ): bool => ! in_array( $tool['operation_id'], array( 'listing.form_inspect', 'directory.inspect' ), true ) );
				}
			}
		}
		if ( 'builder' === $context->profile() ) {
			foreach ( $results as $entry ) {
				if ( true !== ( $entry->response->ok ?? false ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) ) {
					continue;
				}
				$authoring = $entry->response->data->authoring ?? null;
				if ( true === ( $authoring->can_propose_private_draft ?? false ) || ( ( ! empty( $entry->response->data->directory_query->targets ) || ! empty( $entry->response->data->saved_variants->targets ) || array_filter( $entry->response->data->directory_binding->targets ?? array(), static fn( object $target ): bool => true === ( $target->can_configure ?? false ) ) ) && true === ( $entry->response->data->semantic_report->accepted ?? false ) ) ) {
					return $catalog;
				}
				try {
					$graph_version_hash = ( new GraphVersion( (array) ( $entry->response->data->graph->version ?? array() ) ) )->hash();
				} catch ( ContractException $error ) {
					continue;
				}
				foreach ( $authoring->ready_subsets ?? array() as $subset ) {
					if ( 'complete_for_selection' === ( $subset->state ?? null ) && 'exact_first_directory_loop' === ( $subset->readiness_scope ?? null ) && true === ( $subset->can_propose_private_draft ?? false ) && is_string( $subset->graph_hash ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $subset->graph_hash ) && hash_equals( (string) ( $entry->response->data->graph_hash ?? '' ), $subset->graph_hash ) && is_string( $subset->graph_version_hash ?? null ) && hash_equals( $graph_version_hash, $subset->graph_version_hash ) && is_string( $subset->catalog_revision ?? null ) && hash_equals( $subset->catalog_revision, (string) ( $authoring->catalog_revision ?? '' ) ) && is_string( $subset->semantic_rule_set_revision ?? null ) && hash_equals( $subset->semantic_rule_set_revision, (string) ( $authoring->semantic_rule_set_revision ?? '' ) ) ) {
						return $catalog;
					}
				}
			}
			return array_filter( $catalog, static fn( array $tool ): bool => 'builder.draft_propose' !== $tool['operation_id'] );
		}
		if ( 'visitor' !== $context->profile() ) {
			return $catalog;
		}
		foreach ( $results as $entry ) {
			if ( true !== ( $entry->response->ok ?? false ) || ! in_array( $entry->response->operation ?? '', array( 'listing.search', 'listing.compare', 'knowledge.search' ), true ) ) {
				continue;
			}
			return array_filter(
				$catalog,
				static fn( array $tool ): bool => in_array( $tool['operation_id'], array( 'conversation.respond', 'conversation.clarify' ), true )
			);
		}
		return $catalog;
	}

	/**
	 * Omit redundant search declarations for a selected, freshly described turn.
	 *
	 * The conversation.respond tool retains its complete typed search plan and guarded
	 * retrieval. Only this request's server-prefetched matching public contracts
	 * qualify; stored tool results never replace missing or stale discovery.
	 *
	 * @param array   $catalog Current function map.
	 * @param array   $prefetched Request-local results from guarded native reads.
	 * @param Context $context Current verified profile and actor.
	 * @param array   $run Current run with its validated turn intent.
	 * @return array Narrowed declarations and execution map.
	 */
	public function for_selected_visitor( array $catalog, array $prefetched, Context $context, array $run ): array {
		if ( 'visitor' !== $context->profile() ) {
			return $catalog;
		}
		$intent    = $run['checkpoint']->reply ?? $run['checkpoint']->intent ?? null;
		$ids       = $intent->selected_listing_ids ?? null;
		$scope     = json_decode( $run['context_json'] ?? '' );
		$directory = $scope->directory_id ?? null;
		if ( ! is_array( $ids ) || ! $ids || count( $ids ) > 4 || array_values( $ids ) !== $ids || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) || ( ! is_int( $directory ) && ( ! is_string( $directory ) || ! ctype_digit( $directory ) ) ) || (int) $directory < 1 ) {
			return $catalog;
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return $catalog;
			}
		}
		$verified = array();
		foreach ( $prefetched as $entry ) {
			$operation = $entry->response->operation ?? '';
			$call_id   = 'server-prefetch-' . $operation;
			if ( ! in_array( $operation, array( 'directory.describe', 'listing.catalog' ), true ) || ( $entry->call_id ?? '' ) !== $call_id || true !== ( $entry->response->ok ?? false ) || ( $catalog[ $entry->function ?? '' ]['operation_id'] ?? null ) !== $operation ) {
				continue;
			}
			$input = json_decode( $entry->input_json ?? '' );
			$data  = $entry->response->data ?? null;
			if ( ( $input->directory_id ?? null ) !== (int) $directory || ! $data instanceof \stdClass || ! is_string( $data->schema_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data->schema_hash ) ) {
				continue;
			}
			if ( 'directory.describe' === $operation && 'public_search' === ( $input->purpose ?? null ) && 'public_search' === ( $data->purpose ?? null ) && ( $data->directory->id ?? null ) === (int) $directory ) {
				$verified[ $operation ] = $data->schema_hash;
			}
			if ( 'listing.catalog' === $operation && 'public' === ( $input->dataset ?? null ) && 'public' === ( $data->dataset ?? null ) && ( $data->directory_id ?? null ) === (int) $directory && is_string( $data->revision ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $data->revision ) ) {
				$verified[ $operation ] = $data->schema_hash;
			}
		}
		if ( count( $verified ) !== 2 || $verified['directory.describe'] !== $verified['listing.catalog'] || ! in_array( 'conversation.respond', array_column( $catalog, 'operation_id' ), true ) ) {
			return $catalog;
		}
		return array_filter( $catalog, static fn( array $tool ): bool => 'listing.search' !== $tool['operation_id'] );
	}

	/**
	 * Execute only an exact current catalog call through the shared gateway.
	 *
	 * @param ModelToolCall $call Untrusted model call.
	 * @param array         $catalog Current function map.
	 * @param Gateway       $gateway Shared guarded gateway.
	 * @param Context       $context Current run context.
	 * @return array Safe tool response.
	 */
	public function execute( ModelToolCall $call, array $catalog, Gateway $gateway, Context $context ): array {
		if ( ! isset( $catalog[ $call->name ] ) ) {
			return array(
				'ok'   => false,
				'code' => 'unsupported_operation',
			);
		}
		$arguments = is_object( $call->arguments ) ? get_object_vars( $call->arguments ) : $call->arguments;
		if ( ! is_array( $arguments ) || array_keys( $arguments ) !== array( 'input_json' ) || ! is_string( $arguments['input_json'] ) || strlen( $arguments['input_json'] ) > 1048576 ) {
			return array(
				'ok'           => false,
				'operation'    => $catalog[ $call->name ]['operation_id'],
				'code'         => 'invalid_input',
				'repair_codes' => array( 'exact_input_json_envelope_required' ),
			);
		}
		if ( '' === trim( $arguments['input_json'] ) ) {
			return array(
				'ok'           => false,
				'operation'    => $catalog[ $call->name ]['operation_id'],
				'code'         => 'invalid_input',
				'repair_codes' => array( 'nonempty_input_json_required' ),
			);
		}
		try {
			$input_json = $arguments['input_json'];
			if ( 'visitor' === $context->profile() && in_array( $catalog[ $call->name ]['operation_id'], array( 'directory.describe', 'listing.catalog', 'listing.search', 'listing.compare', 'listing.actions', 'knowledge.search' ), true ) ) {
				$visitor_input = $gateway->validate_input( $catalog[ $call->name ]['operation_id'], $input_json );
				if ( isset( $visitor_input->directory_id ) ) {
					\DirectoristAIPro\Chat\ListingActions::directory( (string) $visitor_input->directory_id ); }
			}
			if ( 'builder.describe' === $catalog[ $call->name ]['operation_id'] ) {
				// Validate original input, then narrow the response inside the guarded handler.
				// Full native Elementor controls can exceed the generic 1MB response bound.
				$input             = $gateway->validate_input( 'builder.describe', $input_json );
				$input->projection = 'discovery';
				$input_json        = wp_json_encode( $input );
			}
			$data = $gateway->execute( $catalog[ $call->name ]['operation_id'], $input_json, $context );
			return array(
				'ok'        => true,
				'operation' => $catalog[ $call->name ]['operation_id'],
				'data'      => $data,
			);
		} catch ( ContractException $error ) {
			$result = array(
				'ok'        => false,
				'operation' => $catalog[ $call->name ]['operation_id'],
				'code'      => $error->error_code(),
			);
			if ( 'directory.propose' === $result['operation'] && in_array( $result['code'], array( 'invalid_directory_proposal', 'unsupported_directory_field', 'directory_data_protected', 'invalid_directory_schema' ), true ) ) {
				$result['message'] = substr( $error->getMessage(), 0, 320 );
			}
			if ( 'builder.draft_propose' === $result['operation'] && 'invalid_input' === $result['code'] && ! $error->repair_codes() ) {
				$result['repair_codes'] = array( 'copy_complete_declared_input_schema' );
			}
			if ( 'builder.inspect' === $result['operation'] && 'invalid_input' === $result['code'] && ! $error->repair_codes() ) {
				$result['repair_codes'] = array( 'copy_complete_declared_input_schema', 'copy_returned_locator_including_nullable_editor_epoch' );
			}
			if ( in_array( $result['operation'], array( 'builder.inspect', 'builder.draft_propose' ), true ) && 'invalid_input' === $result['code'] && ! $error->repair_codes() ) {
				$result = array_merge( $result, self::builder_locator_feedback( $result['operation'], $input_json ) );
			}
			if ( 'builder.inspect' === $result['operation'] && 'stale_version' === $result['code'] && ! $error->repair_codes() ) {
				$result['repair_codes'] = array( 'read_current_graph_without_selection', 'copy_returned_locator_including_nullable_editor_epoch' );
				$result['message']      = 'Read the current bound document with selection:[] and include_authoring:false. Use an exact returned locator for the next read. Repeating the rejected locator cannot refresh it.';
			}
			if ( str_starts_with( $result['operation'], 'builder.' ) && $error->repair_codes() ) {
				$result['repair_codes'] = $error->repair_codes();
			}
			if ( 'listing.form_propose' === $result['operation'] && 'invalid_input' === $result['code'] && in_array( 'listing_native_value_invalid', $error->repair_codes(), true ) ) {
				$result['repair_codes'] = array_values( array_filter( $error->repair_codes(), static fn( string $code ): bool => 1 === preg_match( '/^listing_(native_value_invalid|field_index_[0-9]{1,2}|field_id_[a-z0-9_]+|widget_[a-z0-9_]+)$/D', $code ) ) );
			}
			return $result;
		}
	}

	/**
	 * Diagnose fixed public locator members only; never echo model values or native exceptions.
	 *
	 * @param string $operation Guarded builder operation.
	 * @param string $json      Original model tool input.
	 * @return array Repair guidance for the invalid locator.
	 */
	private static function builder_locator_feedback( string $operation, string $json ): array {
		try {
			$input = ( new \DirectoristAIPro\Validation\JsonDecoder( 1048576, 48 ) )->decode( $json );
		} catch ( \Throwable $error ) {
			return array(
				'repair_codes' => array( 'operation_input_must_be_valid_json' ),
				'message'      => 'input_json must contain one complete valid JSON operation object. No operation was executed.',
			);
		}
		$locators = array();
		if ( 'builder.inspect' === $operation && is_array( $input->selection ?? null ) && array_is_list( $input->selection ) ) {
			foreach ( array_slice( $input->selection, 0, 4 ) as $index => $locator ) {
				$locators[ 'selection[' . $index . ']' ] = $locator; }
		}
		if ( 'builder.draft_propose' === $operation && is_array( $input->changes ?? null ) && array_is_list( $input->changes ) ) {
			foreach ( array_slice( $input->changes, 0, 4 ) as $index => $change ) {
				foreach ( array( 'target', 'parent' ) as $key ) {
					if ( property_exists( (object) $change, $key ) ) {
						$locators[ 'changes[' . $index . '].' . $key ] = $change->$key; }
				}
			}
		}
		$missing = array();
		foreach ( $locators as $path => $locator ) {
			if ( ! $locator instanceof \stdClass ) {
				continue; }
			foreach ( array( 'document', 'snapshot_hash', 'slot_path', 'native_id', 'editor_epoch' ) as $key ) {
				if ( ! property_exists( $locator, $key ) && count( $missing ) < 8 ) {
					$missing[] = $path . '.' . $key; }
			}
		}
		if ( ! $missing ) {
			return array(); }
		$locator_code = 'builder.inspect' === $operation ? 'copy_returned_locator_including_nullable_editor_epoch' : 'copy_complete_returned_locator';
		return array(
			'repair_codes' => array( 'copy_complete_declared_input_schema', $locator_code ),
			'message'      => 'Missing required locator members: ' . implode( ', ', $missing ) . '. Copy the complete current locator returned by builder.inspect, including snapshot_hash and editor_epoch (null when returned as null). Never invent a hash or reuse a stale locator. No operation was executed.',
		);
	}
}
