<?php

/**
 * Fluentcrm Importer
 *
 * This class is responsible for handling the Fluentcrm importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;

/**
 * Fluentcrm Importer class
 */
class Fluentcrm extends Importer {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'FluentCRM';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcrm';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = true;

	/**
	 * Is Active
	 *
	 * @var bool
	 */
	public function is_active() {
		return doublescale_is_plugin_active( 'fluent-crm/fluent-crm.php' );
	}

	/**
	 * Run importer
	 */
	public function run() {
		global $wpdb;

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are from Fluentcrm plugin tables.

		$table_name  = $wpdb->prefix . 'fc_subscribers';
		$pivot_table = $wpdb->prefix . 'fc_subscriber_pivot';
		$list_table  = $wpdb->prefix . 'fc_lists';
		$tag_table   = $wpdb->prefix . 'fc_tags';
		$meta_table  = $wpdb->prefix . 'fc_subscriber_meta';

		// Define the mapping as before
		$mapping = array(
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'email'      => 'email',
			'phone'      => 'phone',
			'address_1'  => 'address_line_1',
			'address_2'  => 'address_line_2',
			'city'       => 'city',
			'state'      => 'state',
			'zip'        => 'postal_code',
			'country'    => 'country',
			'status'     => array(
				'unsubscribed' => 'unsubscribed',
				'subscribed'   => 'subscribed',
				'pending'      => 'unverified',
			),
		);

		// Get total subscribers count
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

		$result = $this->import_with_offset(
			$total,
			$this->offset,
			function ( $offset ) use ( $wpdb, $table_name, $pivot_table, $list_table, $tag_table, $meta_table ) {
				$like_list = '%' . $wpdb->esc_like( 'List' ) . '%';
				$like_tag  = '%' . $wpdb->esc_like( 'Tag' ) . '%';

				$subscribers = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT s.*, 
								GROUP_CONCAT(DISTINCT CASE WHEN p.object_type LIKE %s THEN l.title END) AS lists,
								GROUP_CONCAT(DISTINCT CASE WHEN p.object_type LIKE %s THEN t.title END) AS tags
						 FROM $table_name AS s
						 
						 LEFT JOIN $pivot_table AS p ON s.id = p.subscriber_id
						 LEFT JOIN $list_table AS l ON p.object_id = l.id AND p.object_type LIKE %s
						 LEFT JOIN $tag_table AS t ON p.object_id = t.id AND p.object_type LIKE %s
						 
						 GROUP BY s.id
						 LIMIT %d, 20",
						$like_list,
						$like_tag,
						$like_list,
						$like_tag,
						$offset
					),
					ARRAY_A
				);

				// Fetch custom fields (meta) for each subscriber
				foreach ( $subscribers as &$subscriber ) {
					$meta = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT `key`, value FROM $meta_table WHERE subscriber_id = %d AND object_type = 'custom_field'",
							$subscriber['id']
						),
						ARRAY_A
					);

					// Add meta fields to subscriber array
					foreach ( $meta as $meta_item ) {
						$subscriber[ $meta_item['key'] ] = $meta_item['value'];
					}
				}

				return $subscribers;
			},
			$mapping
		);

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result;
	}

	/**
	 * Get tags
	 *
	 * @return array
	 */
	public function get_tags() {
		global $wpdb;

		$tags_table = $wpdb->prefix . 'fc_tags';
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $tags_table is a trusted prefixed table name from the Fluent CRM plugin.
		$tags = $wpdb->get_results( "SELECT * FROM $tags_table" );
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$tags_array = array();
		foreach ( $tags ?? array() as $tag ) {
			$tags_array[] = array(
				'key'   => $tag->title,
				'label' => $tag->title,
			);
		}

		return $tags_array;
	}

	/**
	 * Get lists
	 *
	 * @return array
	 */
	public function get_lists() {
		global $wpdb;

		$lists_table = $wpdb->prefix . 'fc_lists';
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $lists_table is a trusted prefixed table name from the Fluent CRM plugin.
		$lists = $wpdb->get_results( "SELECT * FROM $lists_table" );
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$lists_array = array();
		foreach ( $lists ?? array() as $list ) {
			$lists_array[] = array(
				'key'   => $list->title,
				'label' => $list->title,
			);
		}

		return $lists_array;
	}

	/**
	 * Get imported custom fields
	 *
	 * @return array
	 */
	public function get_imported_custom_fields() {
		global $wpdb;

		$fc_meta_table = $wpdb->prefix . 'fc_meta';

		// Get custom field definitions from Fluentcrm meta table.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $fc_meta_table is the Fluentcrm-prefixed table name; values bound via prepare().
		$custom_fields_data = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM $fc_meta_table WHERE object_type = %s AND `key` = %s",
				'option',
				'contact_custom_fields'
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $custom_fields_data ) {
			return maybe_unserialize( $custom_fields_data );
		}

		return array();
	}

	/**
	 * Get custom fields
	 *
	 * @return array
	 */
	public function get_custom_fields() {
		$custom_fields = $this->get_imported_custom_fields();
		// Map Fluentcrm field types to Plugin field types
		$type_mapping = array(
			'text'         => 'text',
			'textarea'     => 'textarea',
			'number'       => 'number',
			'select-one'   => 'select',
			'select-multi' => 'multiselect',
			'date'         => 'date',
			'email'        => 'email',
			'phone'        => 'phone',
			'url'          => 'url',
		);

		$fields_array = array();

		if ( $custom_fields ) {
			if ( is_array( $custom_fields ) ) {
				foreach ( $custom_fields as $field ) {
					$field_type  = $field['type'] ?? '';
					$field_key   = $field['slug'] ?? '';
					$field_label = $field['label'] ?? $field['slug'] ?? $field_key;
					$field_group = $field['group'] ?? '';

					// Get options for select, radio, checkbox fields
					$field_options = array();
					if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
						$field_options = $field['options'];
					}

					// Only include fields with types that exist in Plugin
					if ( ! empty( $field_key ) && isset( $type_mapping[ $field_type ] ) ) {
						$field_data = array(
							'key'      => $field_key,
							'label'    => $field_label,
							'type'     => $type_mapping[ $field_type ],
							'raw_type' => $field_type,
							'group'    => $field_group,
						);

						// Add options for fields that support them
						if ( ! empty( $field_options ) ) {
							$field_data['options'] = $field_options;
						}

						$fields_array[] = $field_data;
					}
				}
			}
		}

		return $fields_array;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		$fields = array(
			'lists_mapping'         => array(
				'type'    => 'lists_mapping',
				'label'   => __( 'Lists Mapping', 'doublescale' ),
				'options' => $this->get_lists(),
				'tooltip' => __( 'Map Fluentcrm lists to Plugin lists. For each Fluentcrm list, you can either: 1) "Assign to (Plugin)" - Choose one or more existing Plugin lists to add contacts to (useful for renaming or consolidating lists), or 2) "Auto Create" - Automatically create a new Plugin list with the same name as the Fluentcrm list (useful for preserving your original list structure). Contacts will only be added to lists they belonged to in Fluentcrm.', 'doublescale' ),
			),
			'tags_mapping'          => array(
				'type'    => 'tags_mapping',
				'label'   => __( 'Tags Mapping', 'doublescale' ),
				'options' => $this->get_tags(),
				'tooltip' => __( 'Map Fluentcrm tags to Plugin tags. For each Fluentcrm tag, you can either: 1) "Assign to (Plugin)" - Choose one or more existing Plugin tags to apply to contacts (useful for renaming or consolidating tags), or 2) "Auto Create" - Automatically create a new Plugin tag with the same name as the Fluentcrm tag (useful for preserving your original tag structure). Contacts will only receive tags they had in Fluentcrm.', 'doublescale' ),
			),
			'custom_fields_mapping' => array(
				'type'    => 'custom_fields_mapping',
				'label'   => __( 'Custom Fields Mapping', 'doublescale' ),
				'options' => $this->get_custom_fields(),
				'tooltip' => __( 'Map Fluentcrm custom fields to Plugin custom fields. For each Fluentcrm custom field, you can either: 1) "Assign to (Plugin)" - Choose one or more existing Plugin custom fields to map the data to (useful for renaming or consolidating fields), or 2) "Auto Create" - Automatically create a new Plugin custom field with the same name as the Fluentcrm field (useful for preserving your original field structure). Only contacts with values in these fields will have them imported.', 'doublescale' ),
			),
		);
		return $fields;
	}
}
