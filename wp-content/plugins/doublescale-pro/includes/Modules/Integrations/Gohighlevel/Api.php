<?php
/**
 * Class GoHighLevel Api
 *
 * This class is responsible for handling the GoHighLevel Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Gohighlevel;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;

/**
 * GoHighLevel Api class
 */
class Api extends IntegrationApi
{

	/**
	 * Api Token (Location Api Key)
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_token;

	/**
	 * Location ID extracted from JWT token
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $location_id;

	/**
	 * Constructor
	 *
	 * @param string $api_token   GoHighLevel Api Token
	 *
	 * @since 1.0.0
	 */
	public function __construct($api_token)
	{
		$this->endpoint = 'https://services.leadconnectorhq.com';
		$this->api_token = $api_token;
		$this->location_id = $this->extract_location_id_from_token($api_token);
	}

	/**
	 * Extract location ID from JWT token
	 *
	 * @param string $token JWT token
	 * @return string|null
	 */
	private function extract_location_id_from_token($token)
	{
		try {
			// JWT tokens have 3 parts separated by dots
			$parts = explode('.', $token);
			if (count($parts) !== 3) {
				return null;
			}

			// Decode the payload (second part)
			$payload = json_decode(base64_decode($parts[1]), true);
			
			// Extract authClassId which is the location ID
			return $payload['authClassId'] ?? null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get all contacts from GoHighLevel with pagination
	 *
	 * @param int $limit       Number of contacts per page (max 100).
	 * @param string $startAfter   Timestamp for pagination.
	 * @param string $startAfterId Contact ID for pagination.
	 *
	 * @return array
	 */
	public function get_contacts_by_cursor($limit = 100, $startAfter = null, $startAfterId = null)
	{
		$params = array(
			'locationId' => $this->location_id,
			'limit' => min($limit, 100), // GoHighLevel max is 100
		);
		
		if ($startAfter && $startAfterId) {
			$params['startAfter'] = $startAfter;
			$params['startAfterId'] = $startAfterId;
		}
		
		return $this->get('contacts', $params);
	}

	/**
	 * Get contacts count
	 *
	 * @return array
	 */
	public function get_contacts_count()
	{
		// Get first page to determine total count
		return $this->get('contacts', array(
			'locationId' => $this->location_id,
			'limit' => 1,
		));
	}


	/**
	 * Get contact by email
	 *
	 * @param string $email Email address.
	 *
	 * @return array
	 */
	public function get_contact_by_email($email)
	{
		return $this->get('contacts/search', array(
			'locationId' => $this->location_id,
			'email' => $email,
		));
	}

	/**
	 * Create or update contact
	 *
	 * @param array $data Contact data.
	 *
	 * @return array
	 */
	public function create_or_update_contact($data)
	{
		$data['locationId'] = $this->location_id;
		return $this->post('contacts', $data);
	}

	/**
	 * Get accessible locations for debugging
	 *
	 * @return array
	 */
	public function get_locations()
	{
		return $this->get('locations');
	}

	/**
	 * Get user info to determine accessible locations
	 *
	 * @return array
	 */
	public function get_user_info()
	{
		return $this->get('users/me');
	}


	/**
	 * Send request to the GoHighLevel Api
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   Api path.
	 * @param string|null $body   Request body.
	 *
	 * @return array|WP_Error
	 */
	public function request_remote($method, $path, $body = null)
	{
		// GoHighLevel doesn't use version in URL, but in headers
		$url = "{$this->endpoint}/$path";

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json; charset=' . get_option('blog_charset'),
					'Authorization' => 'Bearer ' . $this->api_token,
					'Version'       => '2021-07-28',
					'Cache-Control' => 'no-cache',
				),
				'timeout' => 30,
			)
		);

		return $response;
	}
}
