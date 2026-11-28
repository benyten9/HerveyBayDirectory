<?php
/**
 * Additional exact cold fence for an admitted initial-factory value handoff.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer, JsonDecoder};

/** Interpreting a frozen value contract never replaces current native authority. */
final class BuilderFactoryAuthority {
	public const REVISION = 'builder-factory-authority-v1';

	/**
	 * Map only a frozen server descriptor after strict initializer expansion.
	 *
	 * Root enrichment must come from the current signed context/capture before
	 * freezing. This pure mapping never establishes current native permission.
	 *
	 * @param object $descriptor Exact server-stored factory descriptor.
	 * @param object $input Strictly expanded full native proposal.
	 * @return object Closed additional cold fence for only this normalized call.
	 * @throws ContractException The descriptor or expanded structure is inconsistent.
	 */
	public static function from_binding( object $descriptor, object $input ): object {
		$authority = $descriptor->authority ?? null;
		$template  = $descriptor->template ?? null;
		$loop      = $template->first_loop ?? null;
		if ( ! $authority instanceof \stdClass || ! $template instanceof \stdClass || ! $loop instanceof \stdClass || ! ( $authority->graph_version ?? null ) instanceof \stdClass || ! ( $template->document ?? null ) instanceof \stdClass || ! ( $template->scope ?? null ) instanceof \stdClass || ! is_string( $input->request_id ?? null ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $input->request_id ) ) {
			throw new ContractException( 'stale_version', 'The admitted native factory descriptor is unavailable.' );
		}
		$canonical = new JsonCanonicalizer();
		try {
			$version = new GraphVersion( get_object_vars( $authority->graph_version ) );
		} catch ( ContractException $error ) {
			throw new ContractException( 'stale_version', 'The admitted native factory graph version is unavailable.' );
		}
		foreach ( array( 'document', 'directory_id', 'scope', 'intent' ) as $field ) {
			if ( ! property_exists( $template, $field ) || ! property_exists( $input, $field ) || $canonical->encode( $template->{$field} ) !== $canonical->encode( $input->{$field} ) ) {
				throw new ContractException( 'stale_version', 'Expanded native proposal changed its admitted factory target.' );
			}
		}
		$changes   = array(
			(object) array(
				'op'       => 'insert',
				'parent'   => $loop->parent ?? null,
				'scope'    => $loop->scope ?? null,
				'position' => $loop->position ?? null,
				'subtree'  => $loop->subtree ?? null,
			),
		);
		$structure = self::structure( $changes );
		if ( $canonical->encode( $structure ) !== $canonical->encode( self::structure( $input->changes ?? array() ) ) || $canonical->encode( $authority->root ?? null ) !== $canonical->encode( $loop->parent ?? null ) || $canonical->encode( $authority->working_document ?? null ) !== $canonical->encode( $template->document ) || ( $authority->source_hash ?? null ) !== $version->source_hash() || 'page' !== ( $template->document->native_kind ?? null ) || 'private_page_draft' !== $template->intent || 'editor_candidate' !== ( $authority->output_mode ?? null ) ) {
			throw new ContractException( 'stale_version', 'Expanded native proposal changed its admitted factory structure.' );
		}
		$value = (object) array(
			'revision'                   => self::REVISION,
			'owner_ref'                  => $authority->owner_ref ?? null,
			'site_id'                    => $authority->site_id ?? null,
			'conversation_id'            => $authority->conversation_id ?? null,
			'run_id'                     => $authority->run_id ?? null,
			'request_id'                 => $input->request_id,
			'document_key'               => $authority->document_key ?? null,
			'document'                   => $template->document,
			'directory_id'               => $template->directory_id,
			'scope'                      => $template->scope,
			'intent'                     => $template->intent,
			'source_hash'                => $version->source_hash(),
			'graph_hash'                 => $authority->graph_hash ?? null,
			'fact_revision'              => $authority->fact_revision ?? null,
			'catalog_revision'           => $authority->catalog_revision ?? null,
			'semantic_rule_set_revision' => $authority->semantic_rule_set_revision ?? null,
			'first_loop_sha256'          => $authority->first_loop_sha256 ?? null,
			'environment_hash'           => $version->environment_hash(),
			'directory_schema_hash'      => $version->directory_schema_hash(),
			'adapter_revision'           => $version->adapter_revision(),
			'normalization_revision'     => $version->normalization_revision(),
			'editor_epoch'               => $version->editor_epoch(),
			'editor_revision'            => $version->editor_revision(),
			'capture'                    => (object) array(
				'capture_id'             => $authority->capture_id ?? null,
				'captured_snapshot_hash' => $authority->captured_snapshot_hash ?? null,
				'original_document'      => $authority->original_document ?? null,
				'working_document'       => $authority->working_document ?? null,
				'output_mode'            => $authority->output_mode ?? null,
				'signature_hash'         => $authority->capture_signature_hash ?? null,
			),
			'changes_structure'          => $structure,
		);
		self::check( $value );
		return json_decode( $canonical->encode( $value ), false, 25, JSON_THROW_ON_ERROR );
	}

	/**
	 * Compare the admitted fence with the ordinary proposal's one current read.
	 *
	 * @param Context            $context Current server-owned execution context.
	 * @param object             $input Unchanged full native proposal after strict expansion.
	 * @param BuilderGraphResult $result Existing fresh native graph and facts.
	 * @param string             $catalog_revision Current effective native catalog revision.
	 * @param string             $rule_revision Current relationship rule revision.
	 * @param array|null         $capture Existing signed current native capture record.
	 * @param object             $first_loop Current exact native factory insertion contract.
	 * @return void
	 * @throws ContractException Any frozen authority or structural input changed.
	 */
	public static function assert_current( Context $context, object $input, BuilderGraphResult $result, string $catalog_revision, string $rule_revision, ?array $capture, object $first_loop ): void {
		$expected = $context->builder_factory_authority();
		if ( null === $expected ) {
			return;
		}
		$current = self::observe( $context, $input, $result, $catalog_revision, $rule_revision, $capture, $first_loop );
		self::assert_equal( $expected, $current );
	}

	/**
	 * Describe only already-read native authority; no graph extraction or effects.
	 *
	 * The caller supplies the current effective catalog and signed capture through
	 * native readers. This value object itself establishes no permission.
	 *
	 * @param Context            $context Current server-owned context.
	 * @param object             $input Exact full native proposal.
	 * @param BuilderGraphResult $result Existing fresh native result.
	 * @param string             $catalog_revision Current effective catalog revision.
	 * @param string             $rule_revision Current semantic rule revision.
	 * @param array|null         $capture Current signed capture supplied by its native reader.
	 * @param object             $first_loop Current exact native factory insertion contract.
	 * @return object Detached exact authority shape for the normalized call.
	 * @throws ContractException The current call is outside initial-factory authority.
	 */
	public static function observe( Context $context, object $input, BuilderGraphResult $result, string $catalog_revision, string $rule_revision, ?array $capture, object $first_loop ): object {
		$context->assert_execution_identity();
		if ( 'builder' !== $context->profile() || 'worker' !== $context->transport() || $context->actor()->is_guest() || null === $context->run_id() || null === $context->conversation_id() || ! is_array( $capture ) || 'editor_candidate' !== ( $capture['output_mode'] ?? null ) || 'page' !== ( $input->document->native_kind ?? null ) || 'private_page_draft' !== ( $input->intent ?? null ) || ! is_string( $input->request_id ?? null ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $input->request_id ) ) {
			throw new ContractException( 'stale_version', 'The admitted initial factory context is unavailable.' );
		}
		$canonical = new JsonCanonicalizer();
		$owner     = $context->actor()->owner()->reference();
		if ( $result->facts()->owner_ref() !== $owner || ( $capture['user_id'] ?? null ) !== $context->actor()->user_id() || ( $capture['site_id'] ?? null ) !== get_current_blog_id() || $canonical->encode( $capture['working_document'] ?? null ) !== $canonical->encode( $input->document ) || ! is_string( $capture['capture_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $capture['capture_id'] ) || ! is_string( $capture['captured_snapshot_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $capture['captured_snapshot_hash'] ) || ! is_string( $capture['signature'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $capture['signature'] ) || ! ( $capture['document'] ?? null ) instanceof \stdClass ) {
			throw new ContractException( 'stale_version', 'The admitted initial factory capture changed.' );
		}
		$version = $result->version();
		$value   = (object) array(
			'revision'                   => self::REVISION,
			'owner_ref'                  => $owner,
			'site_id'                    => get_current_blog_id(),
			'conversation_id'            => $context->conversation_id(),
			'run_id'                     => $context->run_id(),
			'request_id'                 => $input->request_id,
			'document_key'               => $result->document_key(),
			'document'                   => $input->document,
			'directory_id'               => $input->directory_id ?? null,
			'scope'                      => $input->scope,
			'intent'                     => $input->intent,
			'source_hash'                => $version->source_hash(),
			'graph_hash'                 => $result->graph_hash(),
			'fact_revision'              => $result->facts()->revision(),
			'catalog_revision'           => $catalog_revision,
			'semantic_rule_set_revision' => $rule_revision,
			'first_loop_sha256'          => $canonical->digest( $first_loop ),
			'environment_hash'           => $version->environment_hash(),
			'directory_schema_hash'      => $version->directory_schema_hash(),
			'adapter_revision'           => $version->adapter_revision(),
			'normalization_revision'     => $version->normalization_revision(),
			'editor_epoch'               => $version->editor_epoch(),
			'editor_revision'            => $version->editor_revision(),
			'capture'                    => (object) array(
				'capture_id'             => $capture['capture_id'],
				'captured_snapshot_hash' => $capture['captured_snapshot_hash'],
				'original_document'      => $capture['document'],
				'working_document'       => $capture['working_document'],
				'output_mode'            => $capture['output_mode'],
				'signature_hash'         => hash( 'sha256', $capture['signature'] ),
			),
			'changes_structure'          => self::structure( $input->changes ?? array() ),
		);
		self::check( $value );
		return json_decode( $canonical->encode( $value ), false, 25, JSON_THROW_ON_ERROR );
	}

	/**
	 * Validate the exact bounded fence scalar and capture identities.
	 *
	 * @param object $value Additional server-owned cold fence.
	 * @return void
	 * @throws ContractException Native authority identities are unavailable.
	 */
	private static function check( object $value ): void {
		foreach ( array( 'source_hash', 'graph_hash', 'fact_revision', 'catalog_revision', 'semantic_rule_set_revision', 'first_loop_sha256', 'environment_hash', 'directory_schema_hash' ) as $hash ) {
			if ( ! is_string( $value->{$hash} ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value->{$hash} ) ) {
				throw new ContractException( 'stale_version', 'Current native factory revision is unavailable.' );
			}
		}
		if ( ! is_string( $value->owner_ref ?? null ) || ! preg_match( '/^user:[1-9][0-9]*$/D', $value->owner_ref ) || ! is_int( $value->site_id ?? null ) || $value->site_id < 1 || ! is_string( $value->conversation_id ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $value->conversation_id ) || ! is_string( $value->run_id ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $value->run_id ) || ! is_string( $value->capture->capture_id ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value->capture->capture_id ) || ! is_string( $value->capture->captured_snapshot_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value->capture->captured_snapshot_hash ) || ! is_string( $value->capture->signature_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value->capture->signature_hash ) || ! ( $value->capture->original_document ?? null ) instanceof \stdClass || ! ( $value->capture->working_document ?? null ) instanceof \stdClass ) {
			throw new ContractException( 'stale_version', 'The admitted native factory owner or capture is unavailable.' );
		}
		( new JsonDecoder( 65536, 24 ) )->check( $value );
	}

	/**
	 * Compare every admitted authority member, including unknown/missing fields.
	 *
	 * @param object $expected Server-stored admitted authority.
	 * @param object $current Fresh observation in the exact same closed shape.
	 * @return void
	 * @throws ContractException The admitted native authority is stale.
	 */
	public static function assert_equal( object $expected, object $current ): void {
		( new JsonDecoder( 65536, 24 ) )->check( $expected );
		( new JsonDecoder( 65536, 24 ) )->check( $current );
		$canonical = new JsonCanonicalizer();
		if ( $canonical->encode( $expected ) !== $canonical->encode( $current ) ) {
			$categories = array(
				'catalog_revision'           => 'factory_catalog_changed',
				'semantic_rule_set_revision' => 'factory_rules_changed',
				'first_loop_sha256'          => 'factory_recipe_changed',
			);
			foreach ( array( 'owner_ref', 'site_id', 'conversation_id', 'run_id', 'request_id', 'capture' ) as $field ) {
				$categories[ $field ] = 'factory_identity_changed';
			}
			foreach ( array( 'revision', 'document_key', 'document', 'directory_id', 'scope', 'intent', 'source_hash', 'graph_hash', 'fact_revision', 'environment_hash', 'directory_schema_hash', 'adapter_revision', 'normalization_revision', 'editor_epoch', 'editor_revision', 'changes_structure' ) as $field ) {
				$categories[ $field ] = 'factory_source_changed';
			}
			$fields = array_unique( array_merge( array_keys( get_object_vars( $expected ) ), array_keys( get_object_vars( $current ) ) ) );
			$codes  = array();
			foreach ( $fields as $field ) {
				if ( ! property_exists( $expected, $field ) || ! property_exists( $current, $field ) ) {
					$codes[] = 'factory_authority_changed';
				} elseif ( $canonical->encode( $expected->{$field} ) !== $canonical->encode( $current->{$field} ) ) {
					$codes[] = $categories[ $field ] ?? 'factory_authority_changed';
				}
			}
			$codes = array_values( array_unique( $codes ) );
			sort( $codes, SORT_STRING );
			throw new ContractException( 'stale_version', 'The admitted native layout factory authority changed before proposal.', $codes );
		}
	}

	/**
	 * Fixed native structure excludes only model-supplied NEW node attributes.
	 *
	 * @param array $changes Exact native changes after schema validation.
	 * @return array Detached structure; no target, edge or default is rewritten.
	 * @throws ContractException An initial handoff attempted a non-insert change.
	 */
	private static function structure( array $changes ): array {
		if ( ! $changes || count( $changes ) > 10 ) {
			throw new ContractException( 'stale_version', 'The admitted initial factory structure is unavailable.' );
		}
		$copy = array();
		foreach ( $changes as $change ) {
			if ( ! $change instanceof \stdClass || 'insert' !== ( $change->op ?? null ) || ! is_array( $change->subtree->nodes ?? null ) || ! $change->subtree->nodes ) {
				throw new ContractException( 'stale_version', 'The admitted initial factory requires its exact insertion structure.' );
			}
			$next          = clone $change;
			$next->subtree = clone $change->subtree;
			$nodes         = array();
			foreach ( $change->subtree->nodes as $node ) {
				if ( ! $node instanceof \stdClass || ! ( $node->attributes ?? null ) instanceof \stdClass ) {
					throw new ContractException( 'stale_version', 'The admitted initial factory node is unavailable.' );
				}
				$structural = clone $node;
				unset( $structural->attributes );
				$nodes[] = $structural;
			}
			$next->subtree->nodes = $nodes;
			$copy[]               = $next;
		}
		return json_decode( ( new JsonCanonicalizer() )->encode( $copy ), false, 25, JSON_THROW_ON_ERROR );
	}
}
