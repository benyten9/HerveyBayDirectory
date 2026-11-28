<?php
/** Native visitor actions, with fresh public listing and directory checks.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\SchemaRegistry;
use DirectoristAIPro\Validation\ContractException;

/** Explicit UI actions use native workflows; a model cannot invoke writes. */
final class ListingActions {
	/** Publish a read-only native handoff to the grounded visitor planner.
	 *
	 * @param Registry       $registry Shared operation registry.
	 * @param SchemaRegistry $schemas Closed runtime schemas.
	 * @param Database       $db Owned runtime database.
	 */
	public static function register_operations( Registry $registry, SchemaRegistry $schemas, Database $db ): void {
		$schemas->register( 'listing.actions.input', '{"type":"object","properties":{"directory_id":{"type":"integer","minimum":1},"listing_id":{"type":"integer","minimum":1},"action_id":{"enum":["view","favorite","contact","directions","booking","review","claim","live_chat"]},"workflow_id":{"enum":["booking.request","booking.cancel","review.submit"]},"values":{"type":"object","maxProperties":20}},"required":["directory_id","listing_id","action_id"],"additionalProperties":false}' );
		$schemas->register( 'listing.actions.output', '{"type":"object","properties":{"listing_id":{"type":"integer","minimum":1},"directory_id":{"type":"integer","minimum":1},"title":{"type":"string"},"url":{"type":"string"},"workflow":{"type":"object","properties":{"task_id":{"type":"string","pattern":"^[a-f0-9]{32}$"},"type":{"enum":["booking","review"]}},"required":["task_id","type"],"additionalProperties":false},"actions":{"type":"array","maxItems":12,"items":{"type":"object","properties":{"id":{"enum":["view","favorite","contact","directions","booking","review","claim","live_chat"]},"label":{"type":"string"},"kind":{"enum":["link","favorite"]},"url":{"type":"string"},"saved":{"type":"boolean"},"requires_auth":{"type":"boolean"}},"required":["id","label","kind","requires_auth"],"additionalProperties":false}},"authenticated":{"type":"boolean"}},"required":["listing_id","directory_id","title","url","actions","authenticated"],"additionalProperties":false}' );
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.actions',
					'revision'      => '2',
					'description'   => 'Read one current listing action. Booking and review can prepare an owned chat workflow using optional values from its native input schema. booking.cancel prepares cancellation of your own native reservation. Missing inputs become editable fields; submission always requires explicit visitor confirmation. Other actions return native handoffs. This ability never submits, pays or sends.',
					'input_schema'  => 'listing.actions.input',
					'output_schema' => 'listing.actions.output',
					'effect'        => 'read',
					'profiles'      => array( 'visitor' ),
					'allow_guest'   => true,
				),
				static function ( object $input, Context $context ) use ( $db ): bool {
					self::assert_scope( $context, $input->directory_id, $db );
					return true;
				},
				static fn(): bool => true,
				static fn(): bool => ( new DirectoristInspector() )->available(),
				static function ( object $input, Context $context ) use ( $db ): object {
					$result            = self::describe( $context, $input->directory_id, $input->listing_id );
					$result['actions'] = array_values( array_filter( $result['actions'], static fn( array $action ): bool => $action['id'] === $input->action_id ) );
					if ( isset( $input->values ) || isset( $input->workflow_id ) ) {
						$expected = 'booking' === $input->action_id ? array( 'booking.request', 'booking.cancel' ) : ( 'review' === $input->action_id ? array( 'review.submit' ) : array() );
						if ( ! $expected || ! in_array( $input->workflow_id ?? $expected[0], $expected, true ) ) {
							throw new ContractException( 'invalid_input', 'This action does not accept workflow inputs.' );
						}
					}
					if ( $result['actions'] && in_array( $input->action_id, array( 'booking', 'review' ), true ) && null !== $context->conversation_id() ) {
						$adapter = $input->workflow_id ?? ( 'booking' === $input->action_id ? 'booking.request' : 'review.submit' );
						$values  = $input->values ?? (object) array();
						$request = 'workflow-' . hash( 'sha256', wp_json_encode( array( $context->run_id(), $adapter, $input->listing_id, $values ) ) );
						$block   = ( new VisitorWorkflowService( $db ) )->create( $context, $context->conversation_id(), $request, $adapter, $input->directory_id, $input->listing_id, $values );
						// Only stable identity participates in factual replay; fresh forms/receipts
						// come from the owned workflow route, never an old message's quote.
						$result['workflow'] = array(
							'task_id' => $block['task_id'],
							'type'    => $block['type'],
						);
					}
					return json_decode( wp_json_encode( $result ), false, 16, JSON_THROW_ON_ERROR );
				}
			)
		);
	}

	/** Recheck a worker's immutable conversation directory before native retrieval.
	 *
	 * @param Context  $context Current visitor request or worker.
	 * @param int      $directory Requested directory ID.
	 * @param Database $db Owned runtime database.
	 * @throws ContractException When an action changes its chat directory.
	 */
	public static function assert_scope( Context $context, int $directory, Database $db ): void {
		self::directory( (string) $directory );
		if ( 'visitor' !== $context->profile() || null === $context->conversation_id() ) {
			return;
		}
		$rows  = $db->rows( 'SELECT context_json FROM ' . $db->table( 'conversations' ) . ' WHERE public_id=%s AND owner_ref=%s AND erasure_requested=0', array( $context->conversation_id(), $context->actor()->owner()->reference() ) );
		$scope = $rows ? json_decode( $rows[0]['context_json'] ) : null;
		if ( null !== $context->run_id() ) {
			$runs  = $db->rows( 'SELECT r.checkpoint_json FROM ' . $db->table( 'runs' ) . ' r JOIN ' . $db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s AND c.erasure_requested=0', array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id() ) );
			$scope = $runs ? ( json_decode( $runs[0]['checkpoint_json'] )->target_context ?? $scope ) : null;
		}
		if ( $scope instanceof \stdClass && VisitorScope::global( $scope ) ) {
			return; // directory() above rechecks the current administrator allow-list.
		}
		if ( ( $scope->directory_id ?? null ) !== (string) $directory ) {
			throw new ContractException( 'scope_conflict', 'The requested directory differs from this turn.' );
		}
	}

	/** Register listing discovery and an authenticated, idempotent favorite intent. */
	public static function register_routes(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/listings/(?P<id>[1-9][0-9]*)/actions',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						return self::describe( self::context(), self::directory( $request['directory_id'] ), (int) $request['id'], true );
					} catch ( \Throwable $error ) {
						return self::error( $error );
					}
				},
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/listings/(?P<id>[1-9][0-9]*)/favorite',
			array(
				'methods'             => 'PUT',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						$body = json_decode( $request->get_body(), true, 8, JSON_THROW_ON_ERROR );
						if ( ! is_array( $body ) || count( $body ) !== 2 || ! isset( $body['directory_id'], $body['saved'] ) || ! is_bool( $body['saved'] ) ) {
							throw new ContractException( 'invalid_input', 'Choose whether to save this listing.' );
						}
						$context = self::context();
						$directory = self::directory( $body['directory_id'] );
						$id = (int) $request['id'];
						$current = self::describe( $context, $directory, $id );
						$favorite = array_values( array_filter( $current['actions'], static fn( array $action ): bool => 'favorite' === $action['id'] ) );
						if ( ! $favorite ) {
							throw new ContractException( 'unsupported', 'Saving this listing is unavailable.' );
						}
						if ( $favorite[0]['saved'] !== $body['saved'] ) {
							$route = '/directorist/v1/users/' . $context->actor()->user_id() . '/favorites';
							$native = new \WP_REST_Request( $body['saved'] ? 'POST' : 'DELETE', $body['saved'] ? $route : $route . '/' . $id );
							$native->set_param( 'id', $id );
							$response = rest_do_request( $native );
							if ( $response->is_error() ) {
								return $response->as_error();
							}
						}
						return self::describe( $context, $directory, $id );
					} catch ( \Throwable $error ) {
						return self::error( $error );
					}
				},
			)
		);
	}

	/** Resolve the current identity, never a client supplied user ID. */
	private static function context(): Context {
		$cookie = isset( $_COOKIE['directorist_ai_guest'] ) && is_string( $_COOKIE['directorist_ai_guest'] ) ? $_COOKIE['directorist_ai_guest'] : '';
		return Context::current( 'visitor', 'ui', $cookie );
	}

	/** Normalize only a current, explicitly enabled directory.
	 *
	 * @param mixed $directory Requested native directory ID.
	 * @throws ContractException When the directory is unavailable.
	 */
	public static function directory( $directory ): int {
		if ( ! is_string( $directory ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $directory ) || ! in_array( $directory, array_column( Operations::public_directories(), 'id' ), true ) ) {
			throw new ContractException( 'unauthorized', 'This chat directory is unavailable.' );
		}
		return (int) $directory;
	}

	/** Validate selected listing identities and refresh their permitted public facts.
	 *
	 * @param Context $context Server-resolved visitor identity.
	 * @param int     $directory Enabled directory ID.
	 * @param array   $ids Selected listing IDs.
	 * @throws ContractException When selection is invalid or unavailable.
	 */
	public static function selection( Context $context, int $directory, array $ids ): object {
		if ( 'visitor' !== $context->profile() || count( $ids ) > 4 || array_values( $ids ) !== $ids || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) {
			throw new ContractException( 'invalid_input', 'Choose up to four listings in this directory.' );
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new ContractException( 'invalid_input', 'Choose a current listing.' );
			}
		}
		self::directory( (string) $directory );
		$context->assert_execution_identity();
		return ( new DirectoristInspector() )->query_facts( $context, $directory, 'public', $ids );
	}

	/** Return only actions that exist in the native listing configuration.
	 *
	 * @param Context $context Server-resolved visitor identity.
	 * @param int     $directory Enabled directory ID.
	 * @param int     $id Native listing ID.
	 * @throws ContractException When the current listing is unavailable.
	 */
	public static function describe( Context $context, int $directory, int $id, bool $presentation = false ): array {
		$inspection = self::selection( $context, $directory, array( $id ) );
		$listing    = $inspection->listings[0];
		$url        = get_permalink( $id );
		if ( ! is_string( $url ) || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			throw new ContractException( 'unsupported', 'The native listing link is unavailable.' );
		}
		$facts = array();
		foreach ( $listing->fields as $fact ) {
			if ( $fact->known ) {
				$facts[ $fact->field_id ] = $fact->value;
			}
		}
		$actions = array(
			array(
				'id'            => 'view',
				'label'         => __( 'View listing', 'directorist-ai' ),
				'kind'          => 'link',
				'url'           => $url,
				'requires_auth' => false,
			),
		);
		$layout  = function_exists( 'directorist_single_listings_contents' ) ? directorist_single_listings_contents(
			$directory,
			array(
				'listing_id'        => $id,
				'directory_type_id' => $directory,
			)
		) : array();
		$groups  = is_array( $layout ) && is_array( $layout['groups'] ?? null ) ? $layout['groups'] : array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['section_id'] ) ) {
				continue;
			}
			$widget = $group['widget_name'] ?? '';
			if ( 'contact_listings_owner' === $widget && 'yes' !== get_user_meta( (int) get_post_field( 'post_author', $id ), 'directorist_hide_contact_form', true ) && ! get_post_meta( $id, '_hide_contact_owner', true ) ) {
				$section_id = is_string( $group['custom_block_id'] ?? null ) ? $group['custom_block_id'] : '';
				// The native section renders an ID only when one was configured.
				// A bare permalink would duplicate View listing without opening contact.
				if ( '' === $section_id || preg_match( '/[\x00-\x20\x7f]/', $section_id ) ) {
					continue;
				}
				$anchor    = '#' . rawurlencode( $section_id );
				$actions[] = array(
					'id'            => 'contact',
					'label'         => __( 'Contact', 'directorist-ai' ),
					'kind'          => 'link',
					'url'           => $url . $anchor,
					'requires_auth' => false,
				);
			}
		}
		$header = function_exists( 'directorist_single_listing_header' ) ? directorist_single_listing_header(
			$directory,
			array(
				'listing_id'        => $id,
				'directory_type_id' => $directory,
			)
		) : array();
		if ( self::has_widget( $header, 'bookmark' ) && function_exists( 'directorist_get_user_favorites' ) ) {
			$authenticated = ! $context->actor()->is_guest();
			$actions[]     = array(
				'id'            => 'favorite',
				'label'         => __( 'Save listing', 'directorist-ai' ),
				'kind'          => 'favorite',
				'saved'         => $authenticated && in_array( $id, directorist_get_user_favorites( $context->actor()->user_id() ), true ),
				'requires_auth' => ! $authenticated,
			);
		}
		if ( is_string( $facts['address'] ?? null ) && '' !== trim( $facts['address'] ) && self::has_widget( $layout, 'map' ) ) {
			$actions[] = array(
				'id'            => 'directions',
				'label'         => __( 'Directions', 'directorist-ai' ),
				'kind'          => 'link',
				'url'           => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $facts['address'] ),
				'requires_auth' => false,
			);
		}
		$actions = array_merge( $actions, NativeCapabilities::actions( $context, $directory, $id, $url, is_array( $layout ) ? $layout : array() ) );
		$result = array(
			'listing_id'    => $id,
			'directory_id'  => $directory,
			'title'         => wp_strip_all_tags( get_the_title( $id ) ),
			'url'           => $url,
			'actions'       => $actions,
			'authenticated' => ! $context->actor()->is_guest(),
		);
		if ( $presentation ) {
			// UI-only current metadata shares the exact public native read used
			// above. It never decorates a signed model result or its old facts.
			$hours = $facts['hours'] ?? null;
			$open = $facts['open_now'] ?? ( is_object( $hours ) ? ( $hours->open_now ?? null ) : null );
			$complete = ! empty( $inspection->complete ) && ! empty( $listing->complete );
			$result['current_hours'] = array(
				'known'       => $complete && is_bool( $open ),
				'open_now'    => $complete && is_bool( $open ) ? $open : null,
				'always_open' => $complete && is_object( $hours ) && true === ( $hours->always_open ?? false ),
				'timezone'    => is_object( $hours ) && is_string( $hours->timezone ?? null ) ? $hours->timezone : null,
				'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			);
		}
		return $result;
	}

	/** Walk only a native, bounded configuration tree, never model-authored actions.
	 *
	 * @param mixed  $node Native configuration node.
	 * @param string $widget Native widget name.
	 * @param int    $depth Current traversal depth.
	 */
	private static function has_widget( $node, string $widget, int $depth = 0 ): bool {
		if ( ! is_array( $node ) || $depth > 12 ) {
			return false;
		}
		if ( ( $node['widget_name'] ?? null ) === $widget ) {
			return true;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) && self::has_widget( $child, $widget, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/** Surface a useful public failure without exposing source or credentials.
	 *
	 * @param \Throwable $error Native action failure.
	 */
	private static function error( \Throwable $error ): \WP_Error {
		$code    = $error instanceof ContractException ? $error->error_code() : 'invalid_input';
		$message = $error instanceof ContractException ? $error->getMessage() : 'This listing action is unavailable.';
		return new \WP_Error( $code, $message, array( 'status' => 'unauthorized' === $code ? 403 : 400 ) );
	}
}
