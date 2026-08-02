<?php
/**
 * Pipedrive Importer
 *
 * This class is responsible for handling the Pipedrive importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Pro\Modules\Integrations\Pipedrive\Api;

/**
 * Pipedrive Importer class
 */
class Pipedrive extends Importer {


	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Pipedrive';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'pipedrive';

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
	 * Cached person fields
	 *
	 * @var array|null
	 */
	private $cached_person_fields = null;

	/**
	 * Cached pipelines
	 *
	 * @var array|null
	 */
	private $cached_pipelines = null;

	/**
	 * Cached stages
	 *
	 * @var array|null
	 */
	private $cached_stages = null;

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
	 * Run importer using cursor-based pagination for v2 Api
	 */
	public function run() {
		$api = $this->get_api();

		// Cache metadata once at start to avoid repeated Api calls
		$this->cache_metadata( $api );

		if ( empty( $this->mapping ) ) {
			$this->mapping = $this->get_field_mapping();
		}
		$mapping = array_flip( $this->mapping );

		// For cursor-based pagination, we need to track total count differently
		// Get first page to estimate total count
		$first_page_response = $api->get_persons_first_page();

		if ( empty( $first_page_response['success'] ) || $first_page_response['success'] !== true ) {
			$error_message = __( 'Pipedrive: Error fetching persons', 'doublescale' );
			$response_code = $first_page_response['code'] ?? 0;

			// Provide more specific error messages based on response code
			if ( $response_code === 401 ) {
				$error_message = __( 'Pipedrive Api Token is invalid or expired. Please verify your credentials.', 'doublescale' );
			} elseif ( $response_code === 403 ) {
				$error_message = __( 'Pipedrive Api access denied. Please check your token permissions.', 'doublescale' );
			} elseif ( $response_code === 404 ) {
				$error_message = __( 'Pipedrive Api endpoint not found. Please verify your domain format.', 'doublescale' );
			} elseif ( in_array( $response_code, array( 0, 500, 502, 503, 504 ) ) ) {
				$error_message = __( 'Pipedrive Api connection failed. Please check your domain and try again.', 'doublescale' );
			}

			$error_details = array(
				'code'          => 'pipedrive_get_persons_first_page',
				'response'      => $first_page_response,
				'response_code' => $response_code,
			);

			doublescale_get_logger()->error( $error_message, $error_details );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( $error_message );
		}

		// Count total items by iterating through all pages
		$total  = 0;
		$cursor = null;

		do {
			$response = $api->get_persons_by_cursor( $cursor, 500 ); // Use max limit for efficiency
			if ( ! $response['success'] ) {
				break;
			}

			$items  = $response['data']['data'] ?? array();
			$total += count( $items );

			$cursor = $response['data']['additional_data']['next_cursor'] ?? null;

		} while ( $cursor && $total < 10000 ); // Safety limit

		if ( $total === 0 ) {
			return array(
				'total'  => 0,
				'status' => 'completed',
				'offset' => 0,
			);
		}

		// Use cursor-based pagination
		$result = $this->import_with_cursor(
			$total,
			$this->offset ?? 0,  // Pass offset as expected by the method signature
			function ( $cursor ) use ( $api ) {
				return $this->fetch_persons_batch_cursor( $api, $cursor );
			},
			$mapping
		);

		return $result;
	}

	/**
	 * Cache metadata once per import session
	 *
	 * @param Api $api Pipedrive Api instance.
	 *
	 * @return void
	 */
	private function cache_metadata( $api ) {
		if ( $this->cached_person_fields === null ) {
			$fields_response = $api->get_person_fields();
			if ( $fields_response['success'] ) {
				$this->cached_person_fields = $fields_response['data']['data'] ?? array();
			} else {
				$this->cached_person_fields = array();
				doublescale_get_logger()->info(
					__( 'Pipedrive: Could not fetch person fields', 'doublescale' ),
					array(
						'code'     => 'pipedrive_get_person_fields',
						'response' => $fields_response,
					)
				);
			}
		}

		if ( $this->cached_pipelines === null ) {
			$pipelines_response = $api->get_pipelines();
			if ( $pipelines_response['success'] ) {
				$this->cached_pipelines = $pipelines_response['data']['data'] ?? array();
			} else {
				$this->cached_pipelines = array();
			}
		}

		if ( $this->cached_stages === null ) {
			$stages_response = $api->get_stages();
			if ( $stages_response['success'] ) {
				$this->cached_stages = $stages_response['data']['data'] ?? array();
			} else {
				$this->cached_stages = array();
			}
		}
	}

	/**
	 * Fetch persons batch from Pipedrive using cursor-based pagination (v2 Api)
	 *
	 * @param Api         $api    Pipedrive Api instance.
	 * @param string|null $cursor Current cursor.
	 *
	 * @return array
	 */
	private function fetch_persons_batch_cursor( $api, $cursor ) {
		$response = $api->get_persons_by_cursor( $cursor, 100 );

		if ( ! $response['success'] || empty( $response['data']['data'] ) ) {
			return array(
				'contacts'    => array(),
				'next_cursor' => null,
			);
		}

		$persons = array();
		foreach ( $response['data']['data'] as $person ) {
			$processed_person = $this->process_pipedrive_person( $person );
			$persons[]        = $processed_person;
		}

		// Return in the format expected by import_with_cursor method
		return array(
			'contacts'    => $persons,
			'next_cursor' => $response['data']['additional_data']['next_cursor'] ?? null,
		);
	}

	/**
	 * Fetch persons batch from Pipedrive (legacy v1 method for backward compatibility)
	 *
	 * @param Api $api    Pipedrive Api instance.
	 * @param int $offset Current offset.
	 *
	 * @return array
	 */
	private function fetch_persons_batch( $api, $offset ) {
		// This method is no longer used in v2 Api but kept for compatibility
		return array();
	}

	/**
	 * Process individual Pipedrive person
	 *
	 * @param array $person Raw Pipedrive person data.
	 *
	 * @return array
	 */
	private function process_pipedrive_person( $person ) {
		$processed = array();

		// Map standard properties
		$processed['email']      = $this->get_primary_email( $person );
		$processed['first_name'] = $person['first_name'] ?? '';
		$processed['last_name']  = $person['last_name'] ?? '';
		$processed['phone']      = $this->get_primary_phone( $person );

		// Handle Pipedrive v2 Api status mapping (strict boolean values)
		$is_active = $person['active_flag'] ?? true;
		// v2 Api uses strict boolean values, not 1/0
		if ( is_bool( $is_active ) ) {
			$processed['email_status'] = $is_active ? 'subscribed' : 'unsubscribed';
		} else {
			// Fallback for any legacy data
			$processed['email_status'] = ( $is_active == true || $is_active === 1 || $is_active === '1' ) ? 'subscribed' : 'unsubscribed';
		}

		// Process organization/company info
		$processed['company'] = '';
		// In v2 Api, org_id is just an integer, not an object with name
		// We could potentially fetch org details, but for now leave empty
		if ( ! empty( $person['org_id'] ) && is_array( $person['org_id'] ) && ! empty( $person['org_id']['name'] ) ) {
			$processed['company'] = $person['org_id']['name'];
		}

		// Process custom fields
		foreach ( $this->cached_person_fields as $field ) {
			$field_key = $field['key'];
			if ( isset( $person[ $field_key ] ) && ! in_array( $field_key, array( 'name', 'first_name', 'last_name', 'email', 'emails', 'phone', 'phones', 'active_flag' ) ) ) {
				$processed[ $field_key ] = $person[ $field_key ];
			}
		}

		// Set lists and tags - keep lists empty, add simple tags for organization
		$processed['lists'] = '';  // No automatic list assignment
		$processed['tags']  = $this->get_person_tags( $person );

		return $processed;
	}

	/**
	 * Get primary email from Pipedrive person
	 *
	 * @param array $person Pipedrive person data.
	 *
	 * @return string
	 */
	private function get_primary_email( $person ) {
		// Pipedrive Api v2 stores emails in 'emails' array
		if ( ! empty( $person['emails'] ) && is_array( $person['emails'] ) ) {
			// Return the first/primary email
			return $person['emails'][0]['value'] ?? '';
		}
		// Fallback for legacy 'email' field
		if ( ! empty( $person['email'] ) ) {
			if ( is_array( $person['email'] ) ) {
				return $person['email'][0]['value'] ?? '';
			}
			return $person['email'];
		}
		return '';
	}

	/**
	 * Get primary phone from Pipedrive person
	 *
	 * @param array $person Pipedrive person data.
	 *
	 * @return string
	 */
	private function get_primary_phone( $person ) {
		$phone = '';

		// Pipedrive Api v2 stores phones in 'phones' array
		if ( ! empty( $person['phones'] ) && is_array( $person['phones'] ) ) {
			// Return the first/primary phone
			$phone = $person['phones'][0]['value'] ?? '';
		}
		// Fallback for legacy 'phone' field
		elseif ( ! empty( $person['phone'] ) ) {
			if ( is_array( $person['phone'] ) ) {
				$phone = $person['phone'][0]['value'] ?? '';
			} else {
				$phone = $person['phone'];
			}
		}

		// Clean phone number - remove dashes, spaces, parentheses, and other non-numeric characters
		// Keep only digits and + sign for international numbers
		if ( ! empty( $phone ) ) {
			$phone = preg_replace( '/[^\d+]/', '', $phone );
		}

		return $phone;
	}


	/**
	 * Get person tags from Pipedrive data
	 *
	 * @param array $person Pipedrive person data.
	 *
	 * @return string
	 */
	private function get_person_tags( $person ) {
		$tags = array();

		// Add owner information as tag for assignment tracking
		if ( ! empty( $person['owner_id'] ) ) {
			$tags[] = 'pipedrive-owner-' . $person['owner_id'];
		}

		// Add organization information if available
		if ( ! empty( $person['org_id'] ) ) {
			$tags[] = 'pipedrive-org-' . $person['org_id'];
		}

		// Add labels if available (v2 Api uses label_ids array)
		if ( ! empty( $person['label_ids'] ) && is_array( $person['label_ids'] ) ) {
			foreach ( $person['label_ids'] as $label_id ) {
				$tags[] = 'pipedrive-label-' . $label_id;
			}
		}

		// Add Pipedrive source identifier
		$tags[] = 'imported-from-pipedrive';

		return implode( ',', $tags );
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
			'status'     => 'status',
		);
	}

	/**
	 * Get credentials configuration
	 *
	 * @return array
	 */
	public function get_credentials() {
		return array(
			'api_domain' => array(
				'label'       => __( 'Api Domain', 'doublescale' ),
				'type'        => 'text',
				'description' => __( 'Your Pipedrive company domain (e.g., yourcompany.pipedrive.com)', 'doublescale' ),
				'placeholder' => 'yourcompany.pipedrive.com',
			),
			'api_token'  => array(
				'label'       => __( 'Api Token', 'doublescale' ),
				'type'        => 'text',
				'description' => __( 'Your Pipedrive Api token from Settings > Personal preferences > Api', 'doublescale' ),
			),
		);
	}

	/**
	 * Get Pipedrive Api instance
	 *
	 * @throws Exception If Api credentials are missing or invalid.
	 *
	 * @return Api
	 */
	public function get_api() {
		if ( empty( $this->credentials['api_domain'] ) || empty( $this->credentials['api_token'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Pipedrive Api Domain and Api Token are required.', 'doublescale' ) );
		}

		$api_domain = trim( $this->credentials['api_domain'] );
		$api_token  = trim( $this->credentials['api_token'] );

		// Clean up the domain - remove protocol and trailing slash
		$api_domain = preg_replace( '/^https?:\/\//', '', $api_domain );
		$api_domain = rtrim( $api_domain, '/' );

		// Basic validation for domain format
		if ( ! preg_match( '/^[a-zA-Z0-9.-]+\.(pipedrive\.com|pipedrivecdn\.com)$/', $api_domain ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Pipedrive Api Domain format appears to be invalid. Please use format: yourcompany.pipedrive.com', 'doublescale' ) );
		}

		// Basic validation for Api token
		if ( strlen( $api_token ) < 30 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Pipedrive Api Token appears to be invalid. Please verify your token.', 'doublescale' ) );
		}

		return new Api( $api_domain, $api_token );
	}


	/**
	 * Get fields configuration for the importer
	 *
	 * @return array
	 */
	public function get_fields() {
		try {
			$api           = $this->get_api();
			$test_response = $api->get_persons_first_page();

			if ( empty( $test_response['success'] ) || $test_response['success'] !== true ) {
				throw new \Exception( __( 'Invalid Pipedrive credentials. Please verify your Api Domain and Token.', 'doublescale' ) );
			}
		} catch ( \Exception $e ) {
			// Re-throw the exception so the REST Api catches it and returns error
			throw $e;
		}

		return array();
	}
}
