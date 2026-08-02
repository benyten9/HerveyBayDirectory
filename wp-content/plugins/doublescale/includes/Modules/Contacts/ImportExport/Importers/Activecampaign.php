<?php
/**
 * ActiveCampaign Importer
 *
 * This class is responsible for handling the ActiveCampaign importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Pro\Modules\Integrations\Activecampaign\Api;

/**
 * ActiveCampaign Importer class
 */
class Activecampaign extends Importer {


	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'ActiveCampaign';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'activecampaign';

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
	 * Cached tags
	 *
	 * @var array|null
	 */
	private $cached_tags = null;

	/**
	 * Run importer
	 */
	public function run() {
		$api = $this->get_api();

		// Cache metadata once at start to avoid repeated Api calls
		$this->cache_metadata( $api );

		// Get cached lists and tags arrays
		$lists_array = $this->cached_lists;
		$tags_array  = $this->cached_tags;

		$mapping = array(
			'first_name' => 'firstName',
			'last_name'  => 'lastName',
			'email'      => 'email',
			'phone'      => 'phone',
		);

		$total = $api->get_contacts_count();
		if ( ! $total['success'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Error fetching contacts count', 'doublescale' ) );
		}
		$total = $total['data']['meta']['total'] ?? 0;
		$total = intval( $total );

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

		$result = $this->import_with_offset(
			$total,
			$this->offset,
			function ( $offset ) use ( $api, $lists_array, $tags_array ) {
				$contacts       = $api->get_contacts_by_offset( $offset );
				$contacts_lists = array();
				$contacts_tags  = array();

				foreach ( $contacts['data']['contactLists'] ?? array() as $contact_list ) {
					$contacts_lists[ $contact_list['contact'] ][] = $contact_list['list'];
				}

				foreach ( $contacts['data']['contactTags'] ?? array() as $contact_tag ) {
					$contacts_tags[ $contact_tag['contact'] ][] = $contact_tag['tag'];
				}

				$contacts_array = array();

				foreach ( $contacts['data']['contacts'] as $contact ) {
					$contact['lists'] = array();
					$contact['tags']  = array();

					if ( isset( $contacts_lists[ $contact['id'] ] ) ) {
						foreach ( $contacts_lists[ $contact['id'] ] as $list_id ) {
							$contact['lists'][] = $lists_array[ $list_id ];
						}
					}

					if ( isset( $contacts_tags[ $contact['id'] ] ) ) {
						foreach ( $contacts_tags[ $contact['id'] ] as $tag_id ) {
							$contact['tags'][] = $tags_array[ $tag_id ];
						}
					}

					$contact['tags']  = implode( ',', $contact['tags'] );
					$contact['lists'] = implode( ',', $contact['lists'] );

					$contacts_array[] = $contact;
				}

				return $contacts_array;
			},
			$mapping
		);

		return $result;
	}

	/**
	 * Cache metadata once per import session
	 *
	 * @param Api $api ActiveCampaign Api instance.
	 *
	 * @return void
	 */
	private function cache_metadata( $api ) {
		if ( $this->cached_lists === null ) {
			$lists_response = $api->get_lists();
			if ( $lists_response['success'] ) {
				$this->cached_lists = array();
				foreach ( $lists_response['data']['lists'] as $list ) {
					$this->cached_lists[ $list['id'] ] = $list['name'];
				}
			} else {
				doublescale_get_logger()->error(
					__( 'ActiveCampaign: Error fetching lists', 'doublescale' ),
					array(
						'code'     => 'activecampaign_get_lists',
						'response' => $lists_response,
					)
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
				throw new \Exception( __( 'Error fetching lists', 'doublescale' ) );
			}
		}

		if ( $this->cached_tags === null ) {
			$tags_response = $api->get_tags();
			if ( $tags_response['success'] ) {
				$this->cached_tags = array();
				foreach ( $tags_response['data']['tags'] as $tag ) {
					$this->cached_tags[ $tag['id'] ] = $tag['tag'];
				}
			} else {
				doublescale_get_logger()->error(
					__( 'ActiveCampaign: Error fetching tags', 'doublescale' ),
					array(
						'code'     => 'activecampaign_get_tags',
						'response' => $tags_response,
					)
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
				throw new \Exception( __( 'Error fetching tags', 'doublescale' ) );
			}
		}
	}

	/**
	 * Credentials
	 *
	 * @return array
	 */
	public function get_credentials() {
		return array(
			'api_key' => array(
				'label' => __( 'Api Key', 'doublescale' ),
				'type'  => 'text',
			),
			'api_url' => array(
				'label' => __( 'Api URL', 'doublescale' ),
				'type'  => 'text',
			),
		);
	}

	/**
	 * Get lists
	 *
	 * @return array
	 */
	public function get_lists() {
		try {
			$api      = $this->get_api();
			$response = $api->get_lists();
			if ( ! $response['success'] ) {
				doublescale_get_logger()->error(
					__( 'ActiveCampaign: Error fetching lists', 'doublescale' ),
					array(
						'code'     => 'activecampaign_get_lists',
						'response' => $response,
					)
				);
				return array();
			}

			$options = array();
			foreach ( $response['data']['lists'] as $list ) {
				$options[] = array(
					'key'   => $list['id'],
					'label' => $list['name'],
				);
			}

			return $options;
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'ActiveCampaign: Exception fetching lists', 'doublescale' ),
				array(
					'code'  => 'activecampaign_get_lists_exception',
					'error' => $e->getMessage(),
				)
			);
			return array();
		}
	}

	/**
	 * Get tags
	 *
	 * @return array
	 */
	public function get_tags() {
		try {
			$api      = $this->get_api();
			$response = $api->get_tags();
			if ( ! $response['success'] ) {
				doublescale_get_logger()->error(
					__( 'ActiveCampaign: Error fetching tags', 'doublescale' ),
					array(
						'code'     => 'activecampaign_get_tags',
						'response' => $response,
					)
				);
				return array();
			}

			$options = array();
			foreach ( $response['data']['tags'] as $tag ) {
				$options[] = array(
					'key'   => $tag['id'],
					'label' => $tag['tag'],
				);
			}

			return $options;
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'ActiveCampaign: Exception fetching tags', 'doublescale' ),
				array(
					'code'  => 'activecampaign_get_tags_exception',
					'error' => $e->getMessage(),
				)
			);
			return array();
		}
	}

	/**
	 * Get api
	 *
	 * @throws Exception
	 * @return Api
	 */
	public function get_api() {
		if ( empty( $this->credentials['api_key'] ) || empty( $this->credentials['api_url'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Api Key and Api URL are required.', 'doublescale' ) );
		}
		return new Api( $this->credentials['api_url'], $this->credentials['api_key'] );
	}

	/**
	 * Get fields
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
			'tags_mapping'  => array(
				'type'    => 'tags_mapping',
				'label'   => __( 'Tags', 'doublescale' ),
				'options' => $this->get_tags(),
			),
		);
	}
}
