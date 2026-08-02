<?php
/**
 * Class Hubspot Api
 *
 * This class is responsible for handling the Hubspot Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Hubspot;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Hubspot Api class
 */
class Api extends IntegrationApi
{

	/**
	 * Access token
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $access_token;

	/**
	 * Constructor
	 *
	 * @param string $access_token
	 *
	 * @since 1.0.0
	 */
	public function __construct($access_token)
	{
		$this->endpoint = 'https://api.hubapi.com';
		$this->access_token = $access_token;
	}

	/**
	 * Get companies
	 *
	 * @return array|WP_Error
	 */
	public function get_companies()
	{
		return $this->get('crm/v3/objects/companies');
	}

	/**
	 * Create contact
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	public function create_contact($data)
	{
		return $this->post('crm/v3/objects/contacts', $data);
	}

	/**
	 * Get contact by email
	 *
	 * @param string $email
	 *
	 * @return array|WP_Error
	 */
	public function get_contact_by_email($email)
	{
		$body = array(
			'filterGroups' => array(
				array(
					'filters' => array(
						array(
							'propertyName' => 'email',
							'operator' => 'EQ',
							'value' => $email,
						),
					),
				),
			),
			'sorts' => array(
				'vid',
			),
			'query' => $email,
			'properties' => array(
				'vid',
			),
			'limit' => 1,
			'after' => 0,
		);

		$contacts = $this->post('crm/v3/objects/contacts/search', $body);

		if ($contacts['success'] && !empty($contacts['data'])) {
			return $this->prepare_response(true, 200, $contacts['data']['results'][0]);
		}

		return $this->prepare_response(false, 404, array());
	}

	/**
	 * Get or create contact
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	public function get_or_create_contact($data)
	{
		$email = $data['properties']['email'];
		$contact = $this->get_contact_by_email($email);

		if ($contact['success']) {
			return $contact;
		}

		return $this->create_contact($data);
	}

	/**
	 * Create or update contact
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	public function create_or_update_contact($data)
	{
		$email = $data['properties']['email'];
		$contact = $this->get_contact_by_email($email);

		if ($contact['success']) {
			$contact_id = $contact['data']['id'];
			return $this->update_contact($contact_id, $data);
		}

		return $this->create_contact($data);
	}

	/**
	 * Update contact
	 *
	 * @param int   $contact_id
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	public function update_contact($contact_id, $data)
	{
		return $this->patch("crm/v3/objects/contacts/$contact_id", $data);
	}


	/**
	 * Get fields
	 *
	 * @return array|WP_Error
	 */
	public function get_fields()
	{
		return $this->get('crm/v3/properties/contacts');
	}

	/**
	 * Get lists
	 *
	 * @return array|WP_Error
	 */
	public function get_lists()
	{
		return $this->get('crm/v3/lists');
	}

	/**
	 * Add contact to list
	 *
	 * @param int $contact_id
	 * @param int $list_id
	 *
	 * @return array|WP_Error
	 */
	public function add_contact_to_list($contact_id, $list_id)
	{
		$body = array(
			'objectIds' => array($contact_id),
		);

		return $this->post("crm/v3/lists/$list_id/memberships/add", $body);
	}

	/**
	 * Remove contact from list
	 *
	 * @param int $contact_id
	 * @param int $list_id
	 *
	 * @return array|WP_Error
	 */
	public function remove_contact_from_list($contact_id, $list_id)
	{
		$body = array(
			'objectIds' => array($contact_id),
		);

		return $this->post("crm/v3/lists/$list_id/memberships/remove", $body);
	}

	/**
	 * Get contacts count
	 *
	 * @return array
	 */
	public function get_contacts_count()
	{
		// Use Hubspot's search Api to get an accurate count
		$search_body = array(
			'filterGroups' => array(),
			'sorts' => array(),
			'query' => '',
			'properties' => array('firstname'), // Just get one property to minimize data
			'limit' => 1,
		);

		$response = $this->post('crm/v3/objects/contacts/search', $search_body);

		// If the search was successful, extract total from the response
		if ($response['success'] && isset($response['data'])) {
			$data = $response['data'];

			// Hubspot search Api returns total in the response
			if (isset($data['total'])) {
				return $response;
			}

			// If no total field, check if we have results
			if (isset($data['results']) && is_array($data['results'])) {
				// If we have results, we'll do a fallback to simple contacts Api
				$fallback_response = $this->get('crm/v3/objects/contacts', array('limit' => 100));

				if ($fallback_response['success'] && isset($fallback_response['data']['results'])) {
					// Estimate based on results - if we get 100 results, there are likely more
					$results_count = count($fallback_response['data']['results']);
					$estimated_total = $results_count < 100 ? $results_count : $results_count * 10; // Conservative estimate

					$response['data']['total'] = $estimated_total;
					return $response;
				}
			}

			// If no results at all, return 0
			$response['data']['total'] = 0;
			return $response;
		}

		return $response;
	}

	/**
	 * Get contacts batch for import with cursor-based pagination
	 *
	 * @param string|null $after Cursor for pagination (Hubspot's 'after' parameter).
	 * @param int         $limit Limit (max 100 for Hubspot).
	 *
	 * @return array
	 */
	public function get_contacts_batch($after = null, $limit = 20)
	{
		$params = array(
			'limit' => min($limit, 100),
			'properties' => array('firstname', 'lastname', 'email', 'phone', 'company', 'hs_lead_status', 'createdate', 'lastmodifieddate'),
			'associations' => array('lists'),
		);

		// Use Hubspot's cursor-based pagination
		if (!empty($after)) {
			$params['after'] = $after;
		}

		return $this->get('crm/v3/objects/contacts', $params);
	}

	/**
	 * Get all lists with pagination support
	 *
	 * @param int $count Number of lists to retrieve (max 250).
	 *
	 * @return array
	 */
	public function get_all_lists($count = 250)
	{
		return $this->get('crm/v3/lists', array('count' => min($count, 250)));
	}

	/**
	 * Get contact properties (custom fields)
	 *
	 * @return array
	 */
	public function get_contact_properties()
	{
		return $this->get('crm/v3/properties/contacts');
	}

	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path URL.
	 * @param string|null $body Body.
	 * @return array|WP_Error
	 */
	public function request_remote($method, $path, $body = null)
	{
		return wp_remote_request(
			"{$this->endpoint}/$path",
			array(
				'method' => $method,
				'body' => $body,
				'headers' => array(
					'Accept' => 'application/json',
					'Content-Type' => 'application/json; charset=' . get_option('blog_charset'),
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'timeout' => 30,
			)
		);
	}
}
