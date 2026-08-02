<?php
/**
 * MailerLite Importer
 *
 * This class is responsible for handling the MailerLite importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Pro\Modules\Integrations\Mailerlite\Api;

/**
 * MailerLite Importer class
 */
class Mailerlite extends Importer {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'MailerLite';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'mailerlite';

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
		// Remove empty fields
		$this->mapping = array_filter( $this->mapping );
		$mapping       = array_flip( $this->mapping );
		if ( ! isset( $mapping['email'] ) || empty( $mapping['email'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Email field is required.', 'doublescale' ) );
		}

		$api             = $this->get_api();
		$groups_response = $api->get_groups();
		if ( ! $groups_response['success'] ) {
			doublescale_get_logger()->error(
				__( 'MailerLite: Error fetching groups', 'doublescale' ),
				array(
					'code'     => 'mailerlite_get_groups',
					'response' => $groups_response,
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Error fetching groups', 'doublescale' ) );
		}

		if ( empty( $groups_response['data'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'There are no groups to import subscribers from', 'doublescale' ) );
		}

		$groups = $groups_response['data'];
		$total  = 0;
		foreach ( $groups as $group ) {
			$total += $group['total'];
		}

		if ( 0 === $total ) {
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
			function ( $offset ) use ( $api, $groups ) {
				$current_group = $this->get_current_group( $groups, $offset );
				if ( empty( $current_group ) ) {
					return array();
				}
				$contacts       = $api->get_subscribers_by_offset( $offset, $current_group['id'] );
				$contacts_array = array();

				foreach ( $contacts['data'] as $contact ) {
					$contact['tags'] = $current_group['name'];
					foreach ( $contact['fields'] ?? array() as $field ) {
						$contact[ $field['key'] ] = $field['value'];
					}

					$contacts_array[] = $contact;
				}

				return $contacts_array;
			},
			$mapping
		);

		return $result;
	}

	/**
	 * Get current group
	 *
	 * @param array $groups
	 * @param int   $offset
	 *
	 * @return array
	 */
	private function get_current_group( $groups, $offset ) {
		$current_group = null;
		$groups        = $this->prepare_groups( $groups );

		$groups_offsets = array();
		$total          = 0;
		foreach ( $groups as $group ) {
			$total                         += $group['total'];
			$groups_offsets[ $group['id'] ] = $total;
		}

		foreach ( $groups_offsets as $group_id => $group_offset ) {
			if ( $offset < $group_offset ) {
				$current_group = $groups[ $group_id ];
				break;
			}
		}

		return $current_group;
	}

	/**
	 * Prepare groups
	 *
	 * @param array $groups
	 *
	 * @return array
	 */
	private function prepare_groups( $groups ) {
		$prepared_groups = array();
		foreach ( $groups as $group ) {
			$prepared_groups[ $group['id'] ] = $group;
		}
		return $prepared_groups;
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
		);
	}

	/**
	 * Get extra fields
	 *
	 * @return array
	 */
	public function get_fields_options() {
		$api      = $this->get_api();
		$response = $api->get_fields();
		if ( ! $response['success'] ) {
			doublescale_get_logger()->error(
				__( 'MailerLite: Error fetching fields', 'doublescale' ),
				array(
					'code'     => 'mailerlite_get_fields',
					'response' => $response,
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Error fetching fields', 'doublescale' ) );
		}

		$fields = array();
		foreach ( $response['data'] as $field ) {
			$fields[ $field['key'] ] = array(
				'label' => $field['title'],
			);
		}

		return $fields;
	}

	/**
	 * Get groups
	 *
	 * @return array
	 */
	public function get_groups() {
		$api      = $this->get_api();
		$response = $api->get_groups();
		if ( ! $response['success'] ) {
			doublescale_get_logger()->error(
				__( 'MailerLite: Error fetching groups', 'doublescale' ),
				array(
					'code'     => 'mailerlite_get_groups',
					'response' => $response,
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Error fetching groups', 'doublescale' ) );
		}

		$groups = array();
		foreach ( $response['data'] as $group ) {
			$groups[] = array(
				'label' => $group['name'],
				'key'   => $group['name'],
			);
		}

		return $groups;
	}

	/**
	 * Get api
	 *
	 * @throws Exception
	 * @return Api
	 */
	public function get_api() {
		if ( empty( $this->credentials['api_key'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Api Key is required.', 'doublescale' ) );
		}
		return new Api( $this->credentials['api_key'] );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'mapping'      => array(
				'label'   => __( 'Mapped Fields', 'doublescale' ),
				'type'    => 'contact_mapped_fields',
				'options' => $this->get_fields_options(),
			),
			'tags_mapping' => array(
				'type'    => 'tags_mapping',
				'label'   => __( 'Groups Mapping', 'doublescale' ),
				'options' => $this->get_groups(),
			),
		);
	}
}
