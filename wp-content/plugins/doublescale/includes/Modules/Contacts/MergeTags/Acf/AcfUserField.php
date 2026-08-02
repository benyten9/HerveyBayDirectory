<?php

/**
 * ACF User Field Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\MergeTags\Acf;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ACF User Field Merge Tag
 *
 * Resolves ACF field values from the WordPress user linked to a contact.
 */
class AcfUserField extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'acf_user';

	/**
	 * Is automation merge tag
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Constructor
	 *
	 * @param string $field_name  ACF field name.
	 * @param string $field_label ACF field label.
	 */
	public function __construct( $field_name, $field_label ) {
		$this->name        = $field_label;
		$this->slug        = $field_name;
		$this->description = $field_label;
	}

	/**
	 * Get Merge Tag Value
	 *
	 * @param ContactModel $contact   Contact Model.
	 * @param string       $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( is_null( $contact ) ) {
			return '';
		}

		$user = $contact->user;
		if ( ! $user ) {
			return '';
		}

		$value = get_field( $this->slug, 'user_' . $user->ID );

		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}

		if ( $value === null || $value === false ) {
			return '';
		}

		return (string) $value;
	}
}

// Register ACF user fields as merge tags after ACF has fully loaded.
add_action(
	'init',
	function () {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return;
		}

		$allowed_types = array(
			'text',
			'textarea',
			'number',
			'range',
			'email',
			'url',
			'password',
			'select',
			'checkbox',
			'radio',
			'button_group',
			'true_false',
			'date_picker',
			'date_time_picker',
			'time_picker',
			'color_picker',
		);

		$user_params = array( 'user_form', 'user_role' );
		$all_groups  = acf_get_field_groups();

		foreach ( $all_groups as $group ) {
			if ( empty( $group['active'] ) ) {
				continue;
			}

			// Check if this group targets users.
			$is_user_group = false;
			foreach ( $group['location'] as $or_group ) {
				foreach ( $or_group as $rule ) {
					if ( in_array( $rule['param'], $user_params, true ) ) {
						$is_user_group = true;
						break 2;
					}
				}
			}

			if ( ! $is_user_group ) {
				continue;
			}

			// Register each simple field from this group.
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( ! in_array( $field['type'], $allowed_types, true ) ) {
					continue;
				}

				MergeTagsManager::instance()->register(
					new AcfUserField( $field['name'], $field['label'] )
				);
			}
		}
	},
	20
);
