<?php
/**
 * Serialize editor slot changes with admission of new work for the same owner.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** No native/runtime transaction remains open while the editor copies its canvas. */
final class EditorCaptureSlots {

	/**
	 * Dedicated runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Current owner advisory lock.
	 *
	 * @var string|null
	 */
	private ?string $held = null;

	/**
	 * Bind the current site's runtime connection.
	 *
	 * @param Database $db Current runtime storage.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Serialize only this owner's capture capacity and new run admission.
	 *
	 * @throws ContractException When another preparation holds the owner lease.
	 */
	public function acquire(): void {
		global $wpdb;
		if ( null !== $this->held || get_current_user_id() < 1 ) {
			throw new ContractException( 'conflict', 'This editor request is already being prepared.' );
		}
		$key = 'dai-editor-slots-' . substr( hash( 'sha256', $wpdb->dbname . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . get_current_user_id() ), 0, 40 );
		if ( '1' !== (string) $this->db->scalar( 'SELECT GET_LOCK(%s, 0)', array( $key ) ) ) {
			throw new ContractException( 'conflict', 'Another editor request is being prepared. Try again shortly.' );
		}
		$this->held = $key;
	}

	/** Release on the same dedicated connection, including native failure paths. */
	public function release(): void {
		if ( null !== $this->held ) {
			$key        = $this->held;
			$this->held = null;
			$this->db->scalar( 'SELECT RELEASE_LOCK(%s)', array( $key ) );
		}
	}

	/**
	 * Admit fresh work only while its signed capture still owns a mutable slot.
	 *
	 * Existing accepted request IDs remain replayable after slot retirement.
	 *
	 * @param Database $db Runtime connection.
	 * @param array    $conversation Owned conversation already verified by RunStore.
	 * @param string   $request_id Existing turn idempotency identity.
	 * @param callable $allocate Unchanged atomic turn/run allocation.
	 * @return array Original allocation result.
	 */
	public static function admit( Database $db, array $conversation, string $request_id, callable $allocate ): array {
		$scope    = json_decode( $conversation['context_json'] );
		$document = $scope->builder_document ?? null;
		if ( 'builder' !== $conversation['profile'] || ! $document instanceof \stdClass || ! preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) || EditorWorkingCopy::INTERNAL_STATUS !== get_post_status( (int) $match[1] ) ) {
			return $allocate();
		}
		$turns = $db->table( 'turns' );
		if ( $db->scalar( "SELECT id FROM $turns WHERE conversation_id=%d AND request_id=%s LIMIT 1", array( (int) $conversation['id'], $request_id ) ) ) {
			return $allocate();
		}
		$slots = new self( $db );
		$slots->acquire();
		try {
			$turns = $db->table( 'turns' );
			if ( ! $db->scalar( "SELECT id FROM $turns WHERE conversation_id=%d AND request_id=%s LIMIT 1", array( (int) $conversation['id'], $request_id ) ) ) {
				EditorWorkingCopy::assert_active_slot( $document, $scope->builder_editor_origin ?? null );
			}
			return $allocate();
		} finally {
			$slots->release();
		}
	}
}
