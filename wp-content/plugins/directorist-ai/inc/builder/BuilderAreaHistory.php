<?php
/**
 * Private area discussion memory, separate from executable builder bindings.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\UI\EditorAssistant;
use DirectoristAIPro\UI\EditorWorkingCopy;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

// phpcs:disable Squiz.Commenting.FunctionComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Bounded read-only discussion contract.
/** Private retained discussions supply preferences, never executable authority. */
final class BuilderAreaHistory {
	public const CAPABILITIES = array( 'inspect', 'columns', 'title-color', 'background', 'spacing', 'text', 'filter-labels', 'insert-text', 'insert-image' );
	/**
	 * Site-scoped runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Normalize observations; native permission/family/stamp are independently read.
	 *
	 * @throws ContractException Invalid or unavailable native context.
	 */
	public function normalize( object $input ): object {
		$keys = array_keys( get_object_vars( $input ) );
		if ( array_diff( $keys, array( 'document', 'selection', 'source_stamp', 'scope_key', 'capabilities', 'role', 'allow_auto', 'previous_id', 'directory_id' ) ) || ! ( $input->document ?? null ) instanceof \stdClass || ! ( $input->selection ?? null ) instanceof \stdClass || ! is_string( $input->scope_key ?? null ) || strlen( $input->scope_key ) > 4096 || ! is_array( $input->capabilities ?? null ) || count( $input->capabilities ) > 10 || ! is_string( $input->role ?? null ) || ! preg_match( '/^[a-z-]{1,48}$/D', $input->role ) || ( isset( $input->allow_auto ) && ! is_bool( $input->allow_auto ) ) ) {
			throw new ContractException( 'invalid_input', 'Invalid area discussion context.' );
		}
		if ( ! property_exists( $input, 'directory_id' ) || ( null !== $input->directory_id && ( ! is_string( $input->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $input->directory_id ) ) ) || ( isset( $input->previous_id ) && ( ! is_string( $input->previous_id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $input->previous_id ) ) ) ) {
			throw new ContractException( 'invalid_input', 'Invalid discussion directory or prior conversation.' );
		}
		$doc = $input->document;
		if ( array_diff( array_keys( get_object_vars( $doc ) ), array( 'builder', 'native_kind', 'native_id' ) ) || ! in_array( $doc->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $doc->native_kind ?? null ) || ! is_string( $doc->native_id ?? null ) ) {
			throw new ContractException( 'invalid_input', 'Invalid area document.' );
		}
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'builder', $doc->builder );
		$request->set_param( 'post_id', preg_match( '/^post:([1-9][0-9]*)$/D', $doc->native_id, $match ) ? (int) $match[1] : 0 );
		$request->set_param( 'template_ref', str_starts_with( $doc->native_id, 'template:' ) ? $doc->native_id : '' );
		$response = ( new EditorAssistant() )->context( $request );
		if ( is_wp_error( $response ) || ( $response->get_data()['currentDocument']['nativeKind'] ?? null ) !== $doc->native_kind ) {
			throw new ContractException( 'unauthorized', 'Area document is unavailable.' );
		}
		$selection = $input->selection;
		if ( array_diff( array_keys( get_object_vars( $selection ) ), array( 'native_node_id', 'slot_path', 'label' ) ) || ! is_string( $selection->label ?? null ) || strlen( $selection->label ) > 160 ) {
			throw new ContractException( 'invalid_input', 'Invalid area selection.' );
		}
		$observed               = clone $selection;
		$observed->source_stamp = $input->source_stamp ?? null;
		BuilderEditorSelection::assert_source( $observed, $doc );
		foreach ( $input->capabilities as $capability ) {
			if ( ! is_string( $capability ) || ! in_array( $capability, self::CAPABILITIES, true ) ) {
				throw new ContractException( 'invalid_input', 'Invalid suggestion capability.' );
			}
		}
		return (object) array(
			'document'     => $doc,
			'selection'    => $selection,
			'source_stamp' => $observed->source_stamp,
			'scope_hash'   => hash( 'sha256', $input->scope_key . "\0" . ( $input->directory_id ?? '' ) ),
			'capabilities' => array_values( array_unique( array_merge( array( 'inspect' ), $input->capabilities ) ) ),
			'role'         => $input->role,
			'directory_id' => $input->directory_id,
		);
	}

	/**
	 * Bind history metadata only to the signed source of this new working copy.
	 *
	 * @throws ContractException History and fresh capture differ.
	 */
	public function bind( object $input, object $document, object $selection, object $scope ): object {
		$memory = $this->normalize( $input );
		if ( $memory->directory_id !== $scope->directory_id ) {
			throw new ContractException( 'scope_conflict', 'Discussion directory differs from the execution scope.' );
		}
		$id = preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) ? (int) $match[1] : 0;
		if ( ! $id && 'gutenberg' === $document->builder && preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $document->native_id, $match ) && get_stylesheet() === $match[2] ) {
			$template = get_block_template( $match[2] . '//' . $match[3], $match[1] );
			$id       = $template instanceof \WP_Block_Template ? (int) ( $template->wp_id ?? 0 ) : 0;
		}
		$origin    = $id ? EditorWorkingCopy::area_origin( $id ) : null;
		$canonical = new JsonCanonicalizer();
		if ( null === $origin || $canonical->encode( $origin['baseline_document'] ) !== $canonical->encode( $memory->document ) || ! hash_equals( $origin['baseline_stamp'], $memory->source_stamp ) || $canonical->encode( $origin['baseline_selection'] ) !== $canonical->encode( $memory->selection ) ) {
			throw new ContractException( 'scope_conflict', 'Area history does not match the captured editor source.' );
		}
		$bound               = clone $memory->selection;
		$bound->source_stamp = $selection->source_stamp;
		if ( 'gutenberg' === $document->builder ) {
			$bound = BuilderEditorSelection::saved_gutenberg_selection( $bound, $document );
		}
		if ( ( $bound->native_node_id ?? null ) !== ( $selection->native_node_id ?? null ) || $canonical->encode( (object) array( 'path' => $bound->slot_path ?? null ) ) !== $canonical->encode( (object) array( 'path' => $selection->slot_path ?? null ) ) ) {
			throw new ContractException( 'scope_conflict', 'Discussion and execution areas differ.' );
		}
		if ( isset( $input->previous_id ) ) {
			$row   = ( new ConversationStore( $this->db ) )->get( Owner::user( get_current_user_id() ), $input->previous_id );
			$prior = json_decode( $row['context_json'] )->builder_area_memory ?? null;
			if ( 'builder' !== $row['profile'] || false === ( $input->allow_auto ?? true ) || ! $this->same_area( $memory, $prior ) ) {
				throw new ContractException( 'scope_conflict', 'Earlier discussion does not belong to this exact current area.' );
			}
			$memory->previous_id = $row['public_id'];
		}
		return $memory;
	}

	/** Existing owner/request index supplies lookup; no extra table is required. */
	public function request_id( object $memory, string $request_id ): string {
		return $this->area_prefix( $memory ) . substr( hash( 'sha256', $request_id ), 0, 24 );
	}

	public function document_prefix( object $memory ): string {
		return 'area-' . substr( hash( 'sha256', ( new JsonCanonicalizer() )->encode( $memory->document ) ), 0, 24 ) . '-';
	}

	public function area_prefix( object $memory ): string {
		$native_id = $memory->selection->native_node_id ?? null;
		// Persisted Elementor instance IDs survive reordering. Positional areas
		// require the exact saved source revision; uncertain areas start with presets.
		$identity = (object) array( 'scope' => $memory->scope_hash, 'native_id' => $native_id, 'path' => null === $native_id ? $memory->selection->slot_path : null, 'revision' => null === $native_id ? $memory->source_stamp : null );
		return $this->document_prefix( $memory ) . substr( hash( 'sha256', ( new JsonCanonicalizer() )->encode( $identity ) ), 0, 24 ) . '-';
	}

	/** Match the exact instance/revision, document, directory scope and area role. */
	public function same_area( object $memory, $prior ): bool {
		return $prior instanceof \stdClass && ( $prior->document ?? null ) instanceof \stdClass && ( $prior->selection ?? null ) instanceof \stdClass && is_string( $prior->scope_hash ?? null ) && is_string( $prior->source_stamp ?? null ) && ( $prior->role ?? null ) === $memory->role && $this->area_prefix( $prior ) === $this->area_prefix( $memory );
	}

	public function lookup( object $input ): array {
		$memory = $this->normalize( $input );
		if ( false === ( $input->allow_auto ?? true ) ) {
			return array( 'conversation_ids' => array() );
		}
		$owner = Owner::user( get_current_user_id() );
		$table = $this->db->table( 'conversations' );
		// Search the existing owner/request index for this one area only.
		// Legacy manual links never expand a lookup to a different area.
		$rows = $this->db->rows( "SELECT public_id,context_json FROM $table WHERE owner_ref=%s AND profile='builder' AND request_id LIKE %s AND erasure_requested=0 AND expires_at>UTC_TIMESTAMP() ORDER BY updated_at DESC,id DESC LIMIT 30", array( $owner->reference(), $this->area_prefix( $memory ) . '%' ) );
		$ids  = array();
		foreach ( $rows as $row ) {
			$prior = json_decode( $row['context_json'] )->builder_area_memory ?? null;
			if ( $this->same_area( $memory, $prior ) ) {
				$ids[] = $row['public_id'];
			}
		}
		return array( 'conversation_ids' => array_slice( $ids, 0, 5 ) );
	}

	/** Only earlier user wording and durable run state enter the new model packet. */
	public function prior_requests( object $memory, Owner $owner ): array {
		$result = array();
		$id     = $memory->previous_id ?? null;
		$seen   = array();
		$bytes  = 0;
		while ( is_string( $id ) && count( $seen ) < 3 && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			try {
				$row = ( new ConversationStore( $this->db ) )->get( $owner, $id );
			} catch ( \Throwable $error ) {
				break;
			}
			$prior = json_decode( $row['context_json'] )->builder_area_memory ?? null;
			if ( 'builder' !== $row['profile'] || ! $this->same_area( $memory, $prior ) ) {
				break;
			}
			$messages = $this->db->table( 'messages' );
			$runs     = $this->db->table( 'runs' );
			$turns    = $this->db->table( 'turns' );
			$requests = $this->db->rows( "SELECT m.content_json,r.state FROM $messages m JOIN $turns t ON t.id=m.turn_id AND t.conversation_id=m.conversation_id JOIN $runs r ON r.id=t.run_id AND r.conversation_id=m.conversation_id AND r.owner_ref=%s WHERE m.conversation_id=%d AND m.role='user' AND r.state<>'cancelled' AND r.cancel_requested=0 ORDER BY m.sequence DESC LIMIT 2", array( $owner->reference(), (int) $row['id'] ) );
			foreach ( $requests as $request ) {
				$text = json_decode( $request['content_json'] )->text ?? '';
				if ( ! is_string( $text ) ) {
					continue;
				}
				$boundary = strpos( $text, ' Request: ' );
				if ( false !== $boundary ) {
					$text = substr( $text, $boundary + 10 );
				}
				$text = preg_split( '/\n(?:Selected-area|Edit only this|Human-observed)/', $text )[0];
				$text = mb_strcut( $text, 0, min( 700, 2400 - $bytes ), 'UTF-8' );
				if ( '' === $text ) {
					break 2;
				}
				$bytes   += strlen( $text );
				$result[] = array( 'request' => $text, 'run_state' => $request['state'], 'meaning' => 'Earlier discussion only. Succeeded means a reviewed draft was created, not that this source was changed.' );
			}
			$id = $prior->previous_id ?? null;
		}
		return $result;
	}

	/** Same-call advisory JSON never becomes an ability argument or approval. */
	public static function suggestions( ?string $text, array $capabilities ): array {
		if ( null === $text || strlen( $text ) > 4096 ) {
			return array();
		}
		try {
			$data = ( new JsonDecoder( 4096, 8 ) )->decode( $text );
			if ( ! $data instanceof \stdClass || array( 'suggestions' ) !== array_keys( get_object_vars( $data ) ) || ! is_array( $data->suggestions ) || count( $data->suggestions ) > 3 ) {
				return array();
			}
			$valid = array();
			foreach ( $data->suggestions as $item ) {
				if ( ! $item instanceof \stdClass || array_diff( array_keys( get_object_vars( $item ) ), array( 'label', 'text', 'capability' ) ) || ! is_string( $item->label ?? null ) || ! is_string( $item->text ?? null ) || ! in_array( $item->capability ?? null, $capabilities, true ) || ! in_array( $item->capability, self::CAPABILITIES, true ) || '' === trim( $item->label ) || '' === trim( $item->text ) || strlen( $item->label ) > 80 || strlen( $item->text ) > 700 || preg_match( '/[<>\x00-\x08]/', $item->label . $item->text ) ) {
					continue;
				}
				$valid[] = (object) array( 'label' => trim( $item->label ), 'text' => trim( $item->text ), 'capability' => $item->capability );
			}
			return $valid;
		} catch ( \Throwable $error ) {
			return array();
		}
	}
}
