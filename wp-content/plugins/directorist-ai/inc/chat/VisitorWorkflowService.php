<?php
/** Approved native customer workflows; models prepare, visitors confirm.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment -- Methods follow the visitor workflow adapter contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\SchemaRegistry;

/** No plugin/model supplied HTML, code or arbitrary native callback dispatch. */
final class VisitorWorkflowService {
	private Database $db;
	private VisitorWorkflowStore $store;
	private array $adapters;

	public function __construct( Database $db, ?array $adapters = null ) {
		$this->db       = $db;
		$this->store    = new VisitorWorkflowStore( $db );
		$this->adapters = $adapters ?? \DirectoristAIPro\Extensions\ExtensionRegistry::workflow_adapters();
	}

	/**
	 * Schema guidance declares input shapes without granting listing availability.
	 *
	 * @throws ContractException When the declared native registry exceeds its bounds.
	 */
	public function input_schemas(): array {
		if ( count( $this->adapters ) > 8 ) {
			throw new ContractException( 'unsupported', 'The native workflow input registry is unavailable.' );
		}
		$result = array();
		$bytes  = 0;
		foreach ( array_keys( $this->adapters ) as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_.-]{0,95}$/D', $id ) ) {
				throw new ContractException( 'unsupported', 'The native workflow input identity is unavailable.' );
			}
			$adapter  = $this->adapter( $id );
			$revision = $adapter->revision();
			$schema   = $adapter->input_schema();
			$encoded  = wp_json_encode( $schema );
			if ( ! is_string( $revision ) || '' === $revision || strlen( $revision ) > 191 || ! is_array( $schema ) || ! is_string( $encoded ) || strlen( $encoded ) > 8192 ) {
				throw new ContractException( 'unsupported', 'The native workflow input contract is unavailable.' );
			}
			$bytes += strlen( $encoded );
			if ( $bytes > 16384 ) {
				throw new ContractException( 'unsupported', 'The native workflow input registry exceeds its bound.' );
			}
			$result[] = array(
				'adapter_id'   => $id,
				'revision'     => $revision,
				'input_schema' => $schema,
			);
		}
		return $result;
	}

	/** Validate partial model inputs before creating or storing a native request. */
	public function validate_inputs( string $adapter_id, object $inputs ): void {
		$this->validate( $this->adapter( $adapter_id ), $inputs );
	}

	public function create( Context $context, string $conversation, string $request, string $adapter_id, int $directory, int $listing, object $inputs ): array {
		$this->guard( $context, $directory, $listing );
		$adapter = $this->adapter( $adapter_id );
		$this->validate( $adapter, $inputs );
		$row = $this->store->create( $context, $conversation, $request, $adapter_id, $adapter->revision(), $directory, $listing, $inputs );
		return $this->store->locked(
			$context,
			$row['public_id'],
			function ( array $current ) use ( $context, $adapter, $inputs ): array {
				$this->admit_native( $context, $current );
				if ( 'draft' === $current['state'] ) {
					$current = $this->store->prepared( $current, $this->prepare( $context, $adapter, $current, $inputs ) );
				}
				return $this->present( $context, $current );
			}
		);
	}

	public function list_current( Context $context, string $conversation ): array {
		$blocks = array();
		foreach ( $this->store->list_current( $context, $conversation ) as $row ) {
			try {
				if ( ! in_array( $row['state'], array( 'draft', 'needs_input', 'ready', 'unavailable' ), true ) ) {
					$adapter = $this->adapter( $row['adapter_id'] );
					if ( $adapter->revision() !== $row['adapter_revision'] ) {
						$block                    = $this->present( $context, $row );
						$block['status']          = 'unavailable';
						$block['receipt']         = null;
						$block['receipt_details'] = null;
						$block['summary']         = 'The native workflow changed. Open the listing to check its status.';
						$blocks[]                 = $block;
						continue;
					}
					$row = $this->store->locked( $context, $row['public_id'], fn( array $current ): array => $this->refresh( $context, $adapter, $current ) );
				}
				$blocks[] = $this->present( $context, $row );
			} catch ( ContractException $error ) {
				// A private or withdrawn listing cannot reappear through task history.
				continue;
			} catch ( \Error $error ) {
				$block                    = $this->present( $context, $row );
				$block['status']          = 'unavailable';
				$block['receipt']         = null;
				$block['receipt_details'] = null;
				$block['summary']         = 'This option is currently unavailable. Open the listing to check its status.';
				$blocks[]                 = $block;
			}
		}
		return array(
			'contract' => 'directorist-ai/visitor-presentation-v1',
			'blocks'   => $blocks,
		);
	}

	/** Current native contracts guide extraction; their existence grants no write. */
	public function describe( Context $context, int $directory, int $listing ): array {
		$this->guard( $context, $directory, $listing );
		$result = array();
		foreach ( $this->adapters as $id => $adapter ) {
			$availability = $adapter->available( $context, $directory, $listing );
			$result[]     = array(
				'adapter_id'   => $id,
				'revision'     => $adapter->revision(),
				'listing_id'   => $listing,
				'directory_id' => $directory,
				'availability' => $availability,
				'input_schema' => $adapter->input_schema(),
			);
		}
		return $result;
	}

	public function action( Context $context, string $id, int $version, string $action, ?object $inputs = null, ?string $quote_id = null, bool $confirmed = false ): array {
		return $this->store->locked(
			$context,
			$id,
			function ( array $row ) use ( $context, $version, $action, $inputs, $quote_id, $confirmed ): array {
				$this->guard( $context, (int) $row['directory_id'], (int) $row['listing_id'] );
				$adapter = $this->adapter( $row['adapter_id'] );
				if ( $adapter->revision() !== $row['adapter_revision'] ) {
					throw new ContractException( 'stale_version', 'The native workflow changed. Start a new request.' );
				}
				if ( 'refresh' === $action ) {
					return $this->present( $context, $this->refresh( $context, $adapter, $row ) );
				}
				$this->store->conversation( $context, $row['conversation_public_id'] );
				if ( in_array( $row['state'], array( 'executing', 'unknown' ), true ) ) {
					throw new ContractException( 'unknown_outcome', 'Refresh the native result before retrying this request.' );
				}
				if ( ! in_array( $row['state'], array( 'draft', 'needs_input', 'ready', 'unavailable' ), true ) ) {
					// Repeated confirmation returns the existing native receipt without execution.
					return $this->present( $context, $row );
				}
				if ( $version !== (int) $row['state_version'] ) {
					throw new ContractException( 'stale_version', 'This request changed. Review its current details.' );
				}
				if ( 'prepare' === $action ) {
					$inputs = $inputs ?? (object) array();
					$this->validate( $adapter, $inputs );
					return $this->present( $context, $this->store->prepared( $row, $this->prepare( $context, $adapter, $row, $inputs ) ) );
				}
				if ( 'confirm' !== $action || ! $confirmed || 'ready' !== $row['state'] || ! is_string( $quote_id ) || ! is_string( $row['quote_id'] ) || ! hash_equals( $row['quote_id'], $quote_id ) || ! is_string( $row['quote_expires_at'] ) || strtotime( $row['quote_expires_at'] . ' UTC' ) <= time() ) {
					throw new ContractException( 'approval_required', 'Review the current details and explicitly confirm this request.' );
				}
				$prepared = json_decode( $row['prepared_json'], true );
				$fresh    = $this->prepare( $context, $adapter, $row, $this->object( $prepared['inputs'] ?? (object) array() ) );
				if ( 'ready' !== $fresh['status'] || ! hash_equals( (string) $row['proposal_hash'], hash( 'sha256', wp_json_encode( $fresh['proposal'], JSON_PRESERVE_ZERO_FRACTION ) ) ) ) {
					return $this->present( $context, $this->store->prepared( $row, $fresh ) );
				}
				$row = $this->store->state( $row, 'executing' );
				try {
					$receipt = $adapter->execute( $context, (int) $row['directory_id'], (int) $row['listing_id'], $this->object( $fresh['proposal'] ), $row['operation_key'] );
					$state   = $this->receipt_state( $receipt );
					$row     = $this->store->state( $row, $state, $receipt );
					$this->finish_native( $context, $row );
				} catch ( \Throwable $error ) {
					// The native hook may have committed before throwing. Never replay a write.
					$row = $this->store->state( $row, 'unknown' );
					( new \DirectoristAIPro\AI\TaskAccounting( $this->db ) )->uncertain( $context->actor()->owner(), $row['public_id'] );
				}
				return $this->present( $context, $row );
			}
		);
	}

	private function prepare( Context $context, object $adapter, array $row, object $inputs ): array {
		$availability = $adapter->available( $context, (int) $row['directory_id'], (int) $row['listing_id'] );
		if ( empty( $availability['available'] ) ) {
			return array(
				'status'   => 'unavailable',
				'fields'   => array(),
				'inputs'   => $inputs,
				'proposal' => null,
				'summary'  => $availability['reason'] ?? 'Use the listing to continue in its native workflow.',
			);
		}
		$result = $adapter->prepare( $context, (int) $row['directory_id'], (int) $row['listing_id'], $inputs );
		if ( ! in_array( $result['status'] ?? '', array( 'needs_input', 'ready', 'unavailable' ), true ) || ! is_array( $result['fields'] ?? null ) || ! isset( $result['inputs'], $result['summary'] ) || ( 'ready' === $result['status'] && ! is_object( $result['proposal'] ?? null ) && ! is_array( $result['proposal'] ?? null ) ) ) {
			throw new ContractException( 'invalid_output', 'The native workflow details could not be verified.' );
		}
		return $result;
	}

	private function refresh( Context $context, object $adapter, array $row ): array {
		if ( in_array( $row['state'], array( 'draft', 'ready', 'needs_input', 'unavailable' ), true ) ) {
			return $row;
		}
		$prepared                 = json_decode( $row['prepared_json'], true );
		$receipt                  = json_decode( $row['receipt_json'] ?? 'null', true ) ?? array();
		$receipt['operation_key'] = $row['operation_key'];
		$receipt['proposal']      = $this->object( $prepared['proposal'] ?? (object) array() );
		$current                  = $adapter->reconcile( $context, (int) $row['directory_id'], (int) $row['listing_id'], $receipt );
		if ( json_decode( $row['receipt_json'] ?? 'null', true ) !== $current || $this->receipt_state( $current ) !== $row['state'] ) {
			$row = $this->store->state( $row, $this->receipt_state( $current ), $current );
			$this->finish_native( $context, $row );
			return $row;
		}
		return $row;
	}

	private function present( Context $context, array $row ): array {
		$this->guard( $context, (int) $row['directory_id'], (int) $row['listing_id'] );
		$prepared = json_decode( $row['prepared_json'], true );
		$proposal = $prepared['proposal'] ?? array();
		$quote    = (array) ( $proposal['quote'] ?? array() );
		$receipt  = json_decode( $row['receipt_json'] ?? 'null', true );
		$status   = 'executing' === $row['state'] ? 'unknown' : $row['state'];
		if ( 'ready' === $status && strtotime( (string) $row['quote_expires_at'] . ' UTC' ) <= time() ) {
			$status = 'needs_input';
		}
		$fields = array_slice( $prepared['fields'] ?? array(), 0, 20 );
		foreach ( $fields as &$field ) {
			if ( isset( $field['maxLength'] ) ) {
				$field['max_length'] = $field['maxLength'];
				unset( $field['maxLength'] );
			}
		}
		unset( $field );
		return array(
			'type'            => str_starts_with( $row['adapter_id'], 'booking.' ) ? 'booking' : 'review',
			'intent'          => 'booking.cancel' === $row['adapter_id'] ? 'cancel' : 'request',
			'requires_auth'   => $context->actor()->is_guest() && ( str_starts_with( $row['adapter_id'], 'booking.' ) || ( 'review.submit' === $row['adapter_id'] && function_exists( 'directorist_can_guest_review' ) && ! directorist_can_guest_review() ) ),
			'authenticated'   => ! $context->actor()->is_guest(),
			'task_id'         => $row['public_id'],
			'version'         => (int) $row['state_version'],
			'status'          => $status,
			'listing'         => array(
				'listing_id'   => (int) $row['listing_id'],
				'directory_id' => (int) $row['directory_id'],
				'title'        => wp_strip_all_tags( get_the_title( (int) $row['listing_id'] ) ),
				'url'          => get_permalink( (int) $row['listing_id'] ),
			),
			'fields'          => $fields,
			'inputs'          => $this->object( $prepared['inputs'] ?? (object) array() ),
			'summary'         => in_array( $status, array( 'unknown', 'executing' ), true ) ? 'The native result must be checked before retrying. No second submission will be made.' : ( $receipt ? '' : ( $prepared['summary'] ?? '' ) ),
			'quote'           => 'ready' === $status ? array(
				'id'               => $row['quote_id'],
				'expires_at'       => gmdate( 'c', strtotime( $row['quote_expires_at'] . ' UTC' ) ),
				'formatted'        => $quote['formatted'] ?? null,
				'amount'           => $quote['amount'] ?? null,
				'currency'         => $quote['currency'] ?? null,
				'payment_required' => $quote['payment_required'] ?? false,
			) : null,
			// Display-only recorded values stay outside the signed helper's existing receipt.
			'receipt_details' => 'ui' !== $context->transport() || in_array( $status, array( 'unknown', 'unavailable', 'failed' ), true ) ? null : $this->recorded_receipt( $row['adapter_id'], $receipt ),
			'receipt'         => null === $receipt ? null : array(
				'reference'      => isset( $receipt['native_id'] ) ? (string) $receipt['native_id'] : null,
				'status'         => $status,
				'native_state'   => $receipt['native_state'] ?? 'unknown',
				'payment_status' => $receipt['payment_status'] ?? null,
				'summary'        => $receipt['summary'] ?? '',
				'url'            => $receipt['url'] ?? get_permalink( (int) $row['listing_id'] ),
			),
		);
	}

	/** Literal native booking values; never infer a timezone or currency from current settings. */
	private function recorded_receipt( string $adapter, ?array $receipt ): ?array {
		if ( ! in_array( $adapter, array( 'booking.request', 'booking.cancel' ), true ) || null === $receipt || ! is_int( $receipt['native_id'] ?? null ) || $receipt['native_id'] < 1 ) {
			return null;
		}
		$clock  = static function ( $value ): ?string {
			if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return null;
			}
			return $value;
		};
		$amount = $receipt['amount'] ?? null;
		return array(
			'date_start' => $clock( $receipt['date_start'] ?? null ),
			'date_end'   => $clock( $receipt['date_end'] ?? null ),
			'amount'     => ( is_int( $amount ) || is_float( $amount ) ) && is_finite( (float) $amount ) && $amount >= 0 ? $amount : null,
		);
	}

	private function guard( Context $context, int $directory, int $listing ): void {
		$context->assert_execution_identity();
		if ( 'visitor' !== $context->profile() || Operations::settings()['emergency_stop'] ) {
			throw new ContractException( 'unauthorized', 'Customer actions are currently unavailable.' );
		}
		ListingActions::directory( (string) $directory );
		ListingActions::selection( $context, $directory, array( $listing ) );
		if ( null !== $context->run_id() ) {
			ListingActions::assert_scope( $context, $directory, $this->db );
		}
	}

	private function adapter( string $id ): object {
		if ( ! isset( $this->adapters[ $id ] ) || $this->adapters[ $id ]->id() !== $id ) {
			throw new ContractException( 'unsupported', 'This native workflow is unavailable.' );
		}
		return $this->adapters[ $id ];
	}

	private function validate( object $adapter, object $inputs ): void {
		$schema = $adapter->input_schema();
		unset( $schema['required'] );
		$schemas = new SchemaRegistry();
		$schemas->register( 'visitor.workflow', wp_json_encode( $schema ) );
		( new ContractValidator( $schemas ) )->validate( $inputs, 'visitor.workflow' );
	}

	private function receipt_state( array $receipt ): string {
		if ( 'succeeded' !== ( $receipt['status'] ?? null ) ) {
			return 'unknown';
		}
		$native = $receipt['native_state'] ?? '';
		if ( 'cancelled' === $native ) {
			return 'cancelled';
		}
		if ( ! empty( $receipt['payment_required'] ) ) {
			return 'payment_required';
		}
		if ( in_array( $native, array( 'pending', 'waiting', 'unapproved' ), true ) ) {
			return 'pending';
		}
		if ( in_array( $native, array( 'published', 'approved' ), true ) ) {
			return 'published';
		}
		return 'confirmed' === $native ? 'confirmed' : 'completed';
	}

	private function object( $value ): object {
		$value = (object) $value;
		if ( isset( $value->ratings ) && is_array( $value->ratings ) ) {
			$value->ratings = (object) $value->ratings;
		}
		return json_decode( wp_json_encode( $value ), false, 32, JSON_THROW_ON_ERROR );
	}

	private function admit_native( Context $context, array $row ): void {
		$tasks = new \DirectoristAIPro\AI\TaskAccounting( $this->db );
		if ( $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'task_receipts' ) . ' WHERE task_id=%s AND owner_ref=%s', array( $row['public_id'], $row['owner_ref'] ) ) ) {
			return;
		}
		// No provider is called for a form, confirmation or native action. The
		// neutral WordPress snapshot records zero credits without source fallback.
		$empty = array(
			'provider_id' => '',
			'model_id'    => '',
		);
		$route = array(
			'version'   => 1,
			'feature'   => 'visitor',
			'source'    => 'wordpress',
			'wordpress' => array(
				'text'   => $empty,
				'image'  => $empty,
				'vision' => $empty,
			),
		);
		$tasks->admit( $context, $row['public_id'], $row['run_public_id'] ?? $row['public_id'], null, $route, false );
	}

	private function finish_native( Context $context, array $row ): void {
		$tasks = new \DirectoristAIPro\AI\TaskAccounting( $this->db );
		$prior = $tasks->get( $context->actor()->owner(), $row['public_id'] );
		if ( in_array( $prior['state'], array( 'completed', 'released' ), true ) ) {
			return;
		}
		if ( 'unknown' === $row['state'] || 'executing' === $row['state'] ) {
			$tasks->uncertain( $context->actor()->owner(), $row['public_id'] );
			return;
		}
		if ( 'uncertain' === $prior['state'] ) {
			$tasks->reconcile( $context->actor()->owner(), $row['public_id'], 'native_operation_completed' );
		} else {
			$tasks->finish( $context->actor()->owner(), $row['public_id'], 'native_operation_completed' );
		}
	}
}
