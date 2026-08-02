<?php
/**
 * Class Pipedrive Api
 *
 * This class is responsible for handling the Pipedrive Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Pipedrive;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;

/**
 * Pipedrive Api class
 */
class Api extends IntegrationApi
{

	/**
	 * Api Domain
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_domain;

	/**
	 * Api Token
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_token;

	/**
	 * Constructor
	 *
	 * @param string $api_domain Pipedrive company domain (e.g., yourcompany.pipedrive.com)
	 * @param string $api_token  Pipedrive Api token
	 *
	 * @since 1.0.0
	 */
	public function __construct($api_domain, $api_token)
	{
		// Clean up domain in case it contains protocol
		$api_domain = preg_replace('/^https?:\/\//', '', $api_domain);
		$api_domain = rtrim($api_domain, '/');
		
		$this->endpoint = 'https://' . $api_domain . '/api/v2';
		$this->api_domain = $api_domain;
		$this->api_token = $api_token;
	}

	/**
	 * Get all persons (contacts) from Pipedrive using cursor-based pagination
	 *
	 * @param string|null $cursor Cursor for pagination.
	 * @param int $limit Number of items per page.
	 *
	 * @return array
	 */
	public function get_persons_by_cursor($cursor = null, $limit = 100)
	{
		$params = array('limit' => min($limit, 500)); // Pipedrive max is 500
		if ($cursor) {
			$params['cursor'] = $cursor;
		}
		return $this->get('persons', $params);
	}

	/**
	 * Get first page of persons to start cursor-based pagination
	 *
	 * @return array
	 */
	public function get_persons_first_page()
	{
		// Get first page with reasonable limit for counting
		return $this->get('persons', array('limit' => 100));
	}

	/**
	 * Get person by email
	 *
	 * @param string $email Email address.
	 *
	 * @return array
	 */
	public function get_person_by_email($email)
	{
		return $this->get('persons/search', array(
			'term' => $email,
			'fields' => 'email',
		));
	}

	/**
	 * Create or update person
	 *
	 * @param array $data Person data.
	 *
	 * @return array
	 */
	public function create_or_update_person($data)
	{
		return $this->post('persons', $data);
	}

	/**
	 * Get all custom fields for persons
	 *
	 * @return array
	 */
	public function get_person_fields()
	{
		return $this->get('personFields');
	}

	/**
	 * Get all pipelines
	 *
	 * @return array
	 */
	public function get_pipelines()
	{
		return $this->get('pipelines');
	}

	/**
	 * Get all stages
	 *
	 * @return array
	 */
	public function get_stages()
	{
		return $this->get('stages');
	}

	/**
	 * Send request to the Pipedrive Api v2
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   Api path.
	 * @param string|null $body   Request body.
	 *
	 * @return array|WP_Error
	 */
	public function request_remote($method, $path, $body = null)
	{
		$url = "{$this->endpoint}/$path";
		
		// Add query parameters to URL if they exist
		if (strpos($path, '?') === false && !empty($_GET)) {
			// This handles query params passed via the path
		}

		return wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json; charset=' . get_option('blog_charset'),
					'Cache-Control' => 'no-cache',
					'x-api-token'   => $this->api_token, // v2 uses header authentication
				),
				'timeout' => 30,
			)
		);
	}
}
