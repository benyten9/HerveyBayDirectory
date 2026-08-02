<?php
/**
 * GoHighLevel Importer
 *
 * This class is responsible for handling the GoHighLevel importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Pro\Modules\Integrations\Gohighlevel\Api;

/**
 * GoHighLevel Importer class
 */
class Gohighlevel extends Importer {


	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'GoHighLevel';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'gohighlevel';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = true;

	/**
	 * Mapping
	 *
	 * @var array
	 */
	protected $mapping;


	/**
	 * Constructor
	 *
	 * @param array $args args
	 */
	public function __construct( $args = array() ) {
		parent::__construct( $args );
		$this->mapping = $args['mapping'] ?? array();
	}

	/**
	 * Run importer
	 */
	public function run() {
		$api = $this->get_api();

		// GoHighLevel uses direct field mapping (no transformation needed)
		$mapping = $this->mapping ?: array(
			'email'      => 'email',
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'phone'      => 'phone',
			'status'     => 'status',
			// Note: 'company', 'tags', 'lists' are handled separately by the abstract importer
		);

		$total_response = $api->get_contacts_count();
		if ( ! $total_response['success'] ) {
			$error_message = __( 'GoHighLevel: Error fetching contacts count', 'doublescale' );
			$error_details = array(
				'code'     => 'gohighlevel_get_contacts_count',
				'response' => $total_response,
			);

			// Add more specific error information if available
			if ( isset( $total_response['data']['message'] ) ) {
				$error_message .= ': ' . $total_response['data']['message'];
			} elseif ( isset( $total_response['code'] ) ) {
				$error_message .= ' (HTTP ' . $total_response['code'] . ')';
			}

			doublescale_get_logger()->error( $error_message, $error_details );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( $error_message );
		}

		// GoHighLevel returns total count in meta information
		$meta  = $total_response['data']['meta'] ?? array();
		$total = intval( $meta['total'] ?? 0 );

		if ( $total === 0 ) {
			return array(
				'total'  => 0,
				'status' => 'completed',
				'offset' => 0,
			);
		}

		if ( $this->offset > $total ) {
			return array(
				'total'  => $total,
				'status' => 'completed',
				'offset' => $total,
			);
		}

		$result = $this->import_with_cursor(
			$total,
			$this->offset,
			function ( $cursor ) use ( $api ) {
				return $this->fetch_contacts_batch_with_cursor( $api, $cursor );
			},
			$mapping
		);

		return $result;
	}



	/**
	 * Fetch contacts batch from GoHighLevel with cursor-based pagination
	 *
	 * @param Api         $api    GoHighLevel Api instance.
	 * @param string|null $cursor Current cursor for pagination.
	 *
	 * @return array Array with 'contacts' and 'next_cursor' keys
	 */
	private function fetch_contacts_batch_with_cursor( $api, $cursor ) {
		// Parse cursor for startAfter and startAfterId
		$startAfter   = null;
		$startAfterId = null;

		if ( $cursor ) {
			$cursor_parts = explode( '|', $cursor );
			if ( count( $cursor_parts ) === 2 ) {
				$startAfter   = $cursor_parts[0];
				$startAfterId = $cursor_parts[1];
			}
		}

		$response = $api->get_contacts_by_cursor( 100, $startAfter, $startAfterId );

		if ( ! $response['success'] || empty( $response['data']['contacts'] ) ) {
			return array(
				'contacts'    => array(),
				'next_cursor' => null,
			);
		}

		$contacts = array();
		foreach ( $response['data']['contacts'] as $contact ) {
			$processed_contact = $this->process_gohighlevel_contact( $contact );
			$contacts[]        = $processed_contact;
		}

		// Extract next cursor from response meta
		$next_cursor = null;
		$meta        = $response['data']['meta'] ?? array();
		if ( ! empty( $meta['nextCursor'] ) ) {
			// GoHighLevel returns nextCursor object with startAfter and startAfterId
			$next_cursor_obj = $meta['nextCursor'];
			if ( isset( $next_cursor_obj['startAfter'] ) && isset( $next_cursor_obj['startAfterId'] ) ) {
				$next_cursor = $next_cursor_obj['startAfter'] . '|' . $next_cursor_obj['startAfterId'];
			}
		}

		return array(
			'contacts'    => $contacts,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Process individual GoHighLevel contact
	 *
	 * @param array $contact Raw GoHighLevel contact data.
	 *
	 * @return array
	 */
	private function process_gohighlevel_contact( $contact ) {
		$processed = array();

		// Map standard properties
		$processed['email']      = $contact['email'] ?? '';
		$processed['first_name'] = $contact['firstName'] ?? '';
		$processed['last_name']  = $contact['lastName'] ?? '';
		$processed['phone']      = $this->get_primary_phone( $contact );

		// Handle GoHighLevel status mapping
		$processed['email_status'] = $this->map_gohighlevel_status( $contact );

		// Note: 'company' field not included as it doesn't exist in Contact model schema
		// GoHighLevel company info could be stored in custom fields if needed

		// Process tags
		$processed['tags'] = $this->get_contact_tags( $contact );

		// Process lists (from source or campaigns)
		$processed['lists'] = $this->get_contact_lists( $contact );

		return $processed;
	}

	/**
	 * Get primary phone from GoHighLevel contact
	 *
	 * @param array $contact GoHighLevel contact data.
	 *
	 * @return string
	 */
	private function get_primary_phone( $contact ) {
		// GoHighLevel stores phone in the 'phone' field
		$phone = $contact['phone'] ?? '';

		// Clean phone number - remove formatting characters for validation
		// Keep only digits and + sign for international numbers
		if ( ! empty( $phone ) ) {
			$phone = preg_replace( '/[^\d+]/', '', $phone );
		}

		return $phone;
	}

	/**
	 * Map GoHighLevel status to Plugin status
	 *
	 * @param array $contact GoHighLevel contact data.
	 *
	 * @return string
	 */
	private function map_gohighlevel_status( $contact ) {
		// GoHighLevel has various status indicators
		$status = $contact['status'] ?? 'active';

		$status_mapping = array(
			'active'      => 'subscribed',
			'inactive'    => 'unsubscribed',
			'lead'        => 'unverified',
			'prospect'    => 'unverified',
			'customer'    => 'subscribed',
			'do_not_call' => 'unsubscribed',
		);

		return $status_mapping[ $status ] ?? 'unverified';
	}

	/**
	 * Get contact tags
	 *
	 * @param array $contact GoHighLevel contact data.
	 *
	 * @return string
	 */
	private function get_contact_tags( $contact ) {
		$tags = array();

		// GoHighLevel stores tags in the 'tags' array
		if ( ! empty( $contact['tags'] ) && is_array( $contact['tags'] ) ) {
			$tags = $contact['tags'];
		}

		// Add source as a tag if available
		if ( ! empty( $contact['source'] ) ) {
			$tags[] = 'Source: ' . $contact['source'];
		}

		return implode( ',', $tags );
	}

	/**
	 * Get contact lists (from campaigns or other groupings)
	 *
	 * @param array $contact GoHighLevel contact data.
	 *
	 * @return string
	 */
	private function get_contact_lists( $contact ) {
		$lists = array();

		// Add assignment information as lists
		if ( ! empty( $contact['assignedUser'] ) ) {
			$lists[] = 'Assigned to: ' . $contact['assignedUser'];
		}

		return implode( ',', $lists );
	}



	/**
	 * Get credentials configuration
	 *
	 * @return array
	 */
	public function get_credentials() {
		// Check if user has active OAuth session
		$stored_tokens = \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::get_stored_tokens();
		if ( $stored_tokens ) {
			return array(
				'oauth_status' => array(
					'type'         => 'oauth_connected',
					'label'        => __( 'Connected to GoHighLevel', 'doublescale' ),
					'connected_at' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stored_tokens['created_at'] ),
					'expires_in'   => max( 0, $stored_tokens['expires_at'] - time() ),
				),
			);
		}

		// Show setup form when no tokens exist (for OAuth credentials setup)
		return array(
			'oauth_setup' => array(
				'type'               => 'oauth_setup_required',
				'label'              => __( 'Setup OAuth Connection', 'doublescale' ),
				'description'        => __( 'Configure your GoHighLevel OAuth app credentials to connect and import contacts.', 'doublescale' ),
				'fields'             => array(
					'client_id'     => array(
						'label'       => __( 'Client ID', 'doublescale' ),
						'type'        => 'text',
						'required'    => true,
						'description' => __( 'Your GoHighLevel app Client ID from the marketplace', 'doublescale' ),
					),
					'client_secret' => array(
						'label'       => __( 'Client Secret', 'doublescale' ),
						'type'        => 'password',
						'required'    => true,
						'description' => __( 'Your GoHighLevel app Client Secret (keep this confidential)', 'doublescale' ),
					),
				),
				'redirect_url'       => \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::get_redirect_uri(),
				'setup_instructions' => array(
					'title'    => __( 'Setup Instructions', 'doublescale' ),
					'steps'    => array(
						array(
							'title'   => __( 'Create GoHighLevel App', 'doublescale' ),
							'details' => array(
								__( 'Go to GoHighLevel Marketplace → My Apps → Create App', 'doublescale' ),
								__( 'Set the app name and description', 'doublescale' ),
							),
						),
						array(
							'title'   => __( 'Configure Permissions', 'doublescale' ),
							'details' => array(
								__( 'Set scopes: contacts.readonly', 'doublescale' ),
								__( 'Add redirect URL: ', 'doublescale' ) . \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::get_redirect_uri(),
								__( 'Note: The app will access contacts from all authorized locations', 'doublescale' ),
							),
						),
						array(
							'title'   => __( 'Get Credentials', 'doublescale' ),
							'details' => array(
								__( 'Go to Client Keys section', 'doublescale' ),
								__( 'Copy Client ID and Client Secret', 'doublescale' ),
								__( 'Paste them in the form above', 'doublescale' ),
							),
						),
					),
					'docs_url' => 'https://highlevel.stoplight.io/docs/integrations/0443d7d1a4bd0-overview',
				),
			),
		);
	}

	/**
	 * Get GoHighLevel Api instance using OAuth tokens
	 *
	 * @throws Exception If OAuth tokens are missing or expired.
	 *
	 * @return Api
	 */
	public function get_api() {
		// Get OAuth tokens from session storage
		$stored_tokens = \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::get_stored_tokens();
		if ( ! $stored_tokens ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Please connect to your GoHighLevel account using OAuth first', 'doublescale' ) );
		}

		// Check if tokens are expired
		if ( $stored_tokens['expires_at'] <= time() ) {
			// Clear expired tokens
			\DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::clear_stored_tokens();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Your GoHighLevel OAuth connection has expired. Please reconnect.', 'doublescale' ) );
		}

		return new Api( $stored_tokens['access_token'] );
	}

	/**
	 * Get fields configuration for the importer
	 *
	 * @return array
	 */
	public function get_fields() {
		// Check if OAuth tokens exist before trying to validate
		$stored_tokens = \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::get_stored_tokens();
		if ( ! $stored_tokens ) {
			// No OAuth connection yet, return empty fields array
			// The frontend will show the OAuth setup form instead
			return array();
		}

		// Validate OAuth credentials by making a test Api call
		// This ensures expired/invalid tokens are caught before proceeding to import
		try {
			$api           = $this->get_api();
			$test_response = $api->get_contacts_count();

			if ( empty( $test_response['success'] ) || $test_response['success'] !== true ) {
				$error_message = __( 'GoHighLevel OAuth connection has expired or is invalid. Please reconnect your account.', 'doublescale' );
				if ( isset( $test_response['code'] ) && $test_response['code'] === 401 ) {
					\DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::clear_stored_tokens();
				}
				throw new \Exception( $error_message );
			}
		} catch ( \Exception $e ) {
			throw $e;
		}

		// GoHighLevel doesn't need complex field mapping
		// Contacts are imported with their basic data and automatic tags/lists
		// Tags and lists are extracted directly from contact data during processing
		return array();
	}
}
