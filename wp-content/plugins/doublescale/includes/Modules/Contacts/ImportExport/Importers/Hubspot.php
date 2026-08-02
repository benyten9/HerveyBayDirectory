<?php
/**
 * Hubspot Importer
 *
 * This class is responsible for handling the Hubspot importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Pro\Modules\Integrations\Hubspot\Api;

/**
 * Hubspot Importer class
 */
class Hubspot extends Importer {


	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Hubspot';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'hubspot';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = true;

	/**
	 * Cached lists
	 *
	 * @var array|null
	 */
	private $cached_lists = null;

	/**
	 * Cached properties
	 *
	 * @var array|null
	 */
	private $cached_properties = null;

	/**
	 * Run importer
	 */
	public function run() {
		$api = $this->get_api();

		// Cache metadata once at start to avoid repeated Api calls
		$this->cache_metadata( $api );

		$mapping = $this->get_field_mapping();

		$total_response = $api->get_contacts_count();
		if ( ! $total_response['success'] ) {
			$error_message = __( 'Hubspot: Error fetching contacts count', 'doublescale' );
			$error_details = array(
				'code'     => 'hubspot_get_contacts_count',
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

		$total = $total_response['data']['total'] ?? 0;
		$total = intval( $total );

		if ( $total === 0 ) {
			return array(
				'total'  => 0,
				'status' => 'completed',
				'offset' => 0,
			);
		}

		// Use cursor-based pagination for Hubspot
		$result = $this->import_with_cursor(
			$total,
			$this->offset,
			function ( $cursor ) use ( $api ) {
				return $this->fetch_contacts_batch( $api, $cursor );
			},
			$mapping
		);

		return $result;
	}

	/**
	 * Cache metadata once per import session
	 *
	 * @param Api $api Hubspot Api instance.
	 *
	 * @return void
	 */
	private function cache_metadata( $api ) {
		if ( $this->cached_lists === null ) {
			$lists_response = $api->get_all_lists();
			if ( $lists_response['success'] ) {
				$this->cached_lists = $lists_response['data']['lists'] ?? array();
			} else {
				$this->cached_lists = array();
			}
		}

		if ( $this->cached_properties === null ) {
			$props_response = $api->get_contact_properties();
			if ( $props_response['success'] ) {
				$this->cached_properties = $props_response['data']['results'] ?? array();
			} else {
				$this->cached_properties = array();
			}
		}
	}

	/**
	 * Fetch contacts batch from Hubspot with cursor-based pagination
	 *
	 * @param Api         $api Hubspot Api instance.
	 * @param string|null $cursor Current cursor.
	 *
	 * @return array
	 */
	private function fetch_contacts_batch( $api, $cursor ) {
		$response = $api->get_contacts_batch( $cursor, 20 );

		if ( ! $response['success'] || empty( $response['data']['results'] ) ) {
			return array(
				'contacts'    => array(),
				'next_cursor' => null,
			);
		}

		$contacts = array();
		foreach ( $response['data']['results'] as $contact ) {
			$processed_contact = $this->process_hubspot_contact( $contact );
			$contacts[]        = $processed_contact;
		}

		// Extract next cursor from Hubspot's paging information
		$next_cursor = $response['data']['paging']['next']['after'] ?? null;

		return array(
			'contacts'    => $contacts,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Process individual Hubspot contact
	 *
	 * @param array $contact Raw Hubspot contact data.
	 *
	 * @return array
	 */
	private function process_hubspot_contact( $contact ) {
		$processed = array();

		// Map standard properties
		$properties              = $contact['properties'] ?? array();
		$processed['email']      = $properties['email'] ?? '';
		$processed['first_name'] = $properties['firstname'] ?? '';
		$processed['last_name']  = $properties['lastname'] ?? '';
		$processed['phone']      = $properties['phone'] ?? '';
		// Company field removed as it doesn't exist in the database table

		// Handle Hubspot-specific status mapping
		$lead_status               = $properties['hs_lead_status'] ?? 'new';
		$processed['email_status'] = $this->map_hubspot_status( $lead_status );

		// Process list memberships efficiently
		$processed['lists'] = $this->get_contact_lists( $contact );
		$processed['tags']  = ''; // Hubspot doesn't have tags, but lists can be mapped

		// Include custom properties dynamically
		$excluded_properties = array( 'email', 'firstname', 'lastname', 'phone', 'hs_lead_status' );
		foreach ( $this->cached_properties as $property ) {
			$prop_name = $property['name'];
			if ( isset( $properties[ $prop_name ] ) && ! in_array( $prop_name, $excluded_properties ) ) {
				$processed[ $prop_name ] = $properties[ $prop_name ];
			}
		}

		return $processed;
	}

	/**
	 * Map Hubspot lead status to Plugin status
	 *
	 * @param string $hs_status Hubspot lead status.
	 *
	 * @return string
	 */
	private function map_hubspot_status( $hs_status ) {
		$status_mapping = array(
			'new'                  => 'unverified',
			'open'                 => 'subscribed',
			'in_progress'          => 'subscribed',
			'open_deal'            => 'subscribed',
			'unqualified'          => 'unsubscribed',
			'attempted_to_contact' => 'subscribed',
			'connected'            => 'subscribed',
			'bad_timing'           => 'unsubscribed',
		);

		return $status_mapping[ $hs_status ] ?? 'unverified';
	}

	/**
	 * Get contact list memberships
	 *
	 * @param array $contact Hubspot contact data.
	 *
	 * @return string
	 */
	private function get_contact_lists( $contact ) {
		$list_names = array();

		// Hubspot v3 Api includes list memberships in associations
		if ( isset( $contact['associations']['lists']['results'] ) ) {
			foreach ( $contact['associations']['lists']['results'] as $list_assoc ) {
				$list_id   = $list_assoc['id'];
				$list_name = $this->get_list_name_by_id( $list_id );
				if ( $list_name ) {
					$list_names[] = $list_name;
				}
			}
		}

		return implode( ',', $list_names );
	}

	/**
	 * Get list name by ID from cached lists
	 *
	 * @param int $list_id Hubspot list ID.
	 *
	 * @return string|null
	 */
	private function get_list_name_by_id( $list_id ) {
		foreach ( $this->cached_lists as $list ) {
			if ( $list['listId'] == $list_id ) {
				return $list['name'];
			}
		}
		return null;
	}

	/**
	 * Get field mapping for import
	 *
	 * @return array
	 */
	private function get_field_mapping() {
		return array(
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'email'      => 'email',
			'phone'      => 'phone',
			// Remove company field as it doesn't exist in the database table
			'status'     => array(
				'new'                  => 'unverified',
				'open'                 => 'subscribed',
				'in_progress'          => 'subscribed',
				'open_deal'            => 'subscribed',
				'unqualified'          => 'unsubscribed',
				'attempted_to_contact' => 'subscribed',
				'connected'            => 'subscribed',
				'bad_timing'           => 'unsubscribed',
			),
		);
	}

	/**
	 * Get credentials configuration
	 *
	 * @return array
	 */
	public function get_credentials() {
		return array(
			'access_token' => array(
				'label'       => __( 'Access Token', 'doublescale' ),
				'type'        => 'text',
				'description' => __( 'Hubspot Private App Access Token', 'doublescale' ),
			),
		);
	}

	/**
	 * Get Hubspot Api instance
	 *
	 * @throws Exception If access token is missing or invalid.
	 *
	 * @return Api
	 */
	public function get_api() {
		if ( empty( $this->credentials['access_token'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Hubspot Access Token is required.', 'doublescale' ) );
		}

		$access_token = trim( $this->credentials['access_token'] );

		// Basic validation for Hubspot access token format
		if ( strlen( $access_token ) < 20 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Hubspot Access Token appears to be invalid. Please verify your token.', 'doublescale' ) );
		}

		return new Api( $access_token );
	}

	/**
	 * Get Hubspot lists for mapping
	 *
	 * @return array
	 */
	public function get_lists() {
		try {
			$api      = $this->get_api();
			$response = $api->get_all_lists();

			if ( ! $response['success'] ) {
				doublescale_get_logger()->error(
					__( 'Hubspot: Error fetching lists', 'doublescale' ),
					array(
						'code'     => 'hubspot_get_lists',
						'response' => $response,
					)
				);
				return array();
			}

			$options = array();
			foreach ( $response['data']['lists'] ?? array() as $list ) {
				$options[] = array(
					'key'   => $list['listId'],
					'label' => $list['name'],
				);
			}

			return $options;
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Hubspot: Exception fetching lists', 'doublescale' ),
				array(
					'code'  => 'hubspot_get_lists_exception',
					'error' => $e->getMessage(),
				)
			);
			return array();
		}
	}

	/**
	 * Get fields configuration for the importer
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'lists_mapping' => array(
				'type'    => 'lists_mapping',
				'label'   => __( 'Lists', 'doublescale' ),
				'options' => $this->get_lists(),
			),
		);
	}
}
