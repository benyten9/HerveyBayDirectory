<?php
/**
 * Update Contact Fields Action
 *
 * Maps source values (literals or merge tags) into the contact's own fields.
 * Standard columns are writable for free; Pro custom-field targets appear when
 * the Pro add-on is active.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Validators\PhoneValidator;

/**
 * Update Contact Fields Action
 */
class UpdateContactFields extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update Contact';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_contact_fields';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the contact fields, mapping a value or merge tag into each field.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel        $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$map = $step->get_setting( 'contact_fields', array() );
		if ( ! is_array( $map ) || empty( $map ) ) {
			return true;
		}

		$contact = $automation_contact->contact;
		if ( ! $contact ) {
			return false;
		}

		$writable_columns = $this->writable_columns();
		$custom_fields    = array();
		$dirty            = false;

		foreach ( $map as $field => $value ) {
			if ( ! is_string( $field ) || '' === $field ) {
				continue;
			}

			if ( is_string( $value ) && '' === trim( $value ) ) {
				continue;
			}

			// Resolve merge tags like {{group:slug}} against the enrolled contact.
			if ( is_string( $value ) && preg_match( '/{{.*?:.*?}}/', $value ) ) {
				$value = $this->merge_tags_manager->process_merge_tags( $value, $automation_contact );
			}

			if ( is_string( $value ) && '' === trim( $value ) ) {
				continue; // Merge tag resolved to an empty value.
			}

			// Pro custom-field target (keyed cf_<id> in get_fields()).
			if ( 0 === strpos( $field, 'cf_' ) ) {
				$custom_id = (int) substr( $field, 3 );
				if ( $custom_id > 0 ) {
					$custom_fields[ $custom_id ] = array( 'value' => is_scalar( $value ) ? (string) $value : '' );
				}
				continue;
			}

			if ( ! in_array( $field, $writable_columns, true ) ) {
				continue; // Ignore unknown or protected columns.
			}

			$normalized = $this->normalize_for_column( $field, $value );
			if ( null === $normalized ) {
				// Logged at debug (not warning): the shared logger's allow-list never
				// includes the warning level, so a warning here would be silently
				// dropped. debug surfaces under the standard log_level=debug
				// investigation bump, where info/notice are not captured either.
				$this->log(
					'debug',
					'Skipped contact field update: value failed validation.',
					array(
						'contact_id' => $contact->id,
						'field'      => $field,
						'value'      => is_scalar( $value ) ? (string) $value : '',
					)
				);
				continue;
			}

			$contact->{$field} = $normalized;
			$dirty             = true;
		}

		if ( $dirty ) {
			try {
				$contact->save();
			} catch ( \Exception $e ) {
				$this->log(
					'error',
					'Failed to update contact fields.',
					array(
						'contact_id' => $contact->id,
						'exception'  => $e->getMessage(),
					)
				);
				return false;
			}
		}

		if ( ! empty( $custom_fields ) && null !== $contact->custom_fields() ) {
			try {
				$contact->custom_fields()->syncWithoutDetaching( $custom_fields );
			} catch ( \Exception $e ) {
				$this->log(
					'error',
					'Failed to sync contact custom fields.',
					array(
						'contact_id' => $contact->id,
						'exception'  => $e->getMessage(),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		$fields = array();

		foreach ( $this->standard_fields() as $key => $label ) {
			$fields[ $key ] = array( 'label' => $label );
		}

		foreach ( $this->custom_field_targets() as $key => $label ) {
			$fields[ $key ] = array( 'label' => $label );
		}

		return array(
			'contact_fields' => array(
				'label'  => __( 'Contact fields to update', 'doublescale' ),
				'type'   => 'mapped_fields',
				'fields' => $fields,
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'contact_fields' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		);
	}

	/**
	 * Standard writable contact columns mapped to their display labels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function standard_fields() {
		return array(
			'first_name'     => __( 'First Name', 'doublescale' ),
			'last_name'      => __( 'Last Name', 'doublescale' ),
			'email'          => __( 'Email', 'doublescale' ),
			'phone'          => __( 'Phone', 'doublescale' ),
			'whatsapp_phone' => __( 'WhatsApp Phone', 'doublescale' ),
			'address_1'      => __( 'Address Line 1', 'doublescale' ),
			'address_2'      => __( 'Address Line 2', 'doublescale' ),
			'city'           => __( 'City', 'doublescale' ),
			'state'          => __( 'State / Region', 'doublescale' ),
			'country'        => __( 'Country', 'doublescale' ),
			'zip'            => __( 'Zip / Postal Code', 'doublescale' ),
		);
	}

	/**
	 * Keys of the standard writable contact columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	private function writable_columns() {
		return array_keys( $this->standard_fields() );
	}

	/**
	 * Pro contact custom-field targets, keyed cf_<id>. Empty without Pro.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function custom_field_targets() {
		$targets = array();

		if ( ! class_exists( '\DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
			return $targets;
		}

		try {
			$custom_fields = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::where( 'scope', 'contact' )->get();
			foreach ( $custom_fields as $custom_field ) {
				$targets[ 'cf_' . $custom_field->id ] = $custom_field->name;
			}
		} catch ( \Exception $e ) {
			return array();
		}

		return $targets;
	}

	/**
	 * Normalize a resolved value for a rule-bound contact column.
	 *
	 * Returns the normalized value, or null when it cannot satisfy the column's
	 * validation rule (caller skips + logs). This keeps the value consistent with
	 * the rules enforced in ContactModel::$rules so save() never throws.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column Contact column name.
	 * @param mixed  $value  Resolved value.
	 *
	 * @return string|null
	 */
	private function normalize_for_column( $column, $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		switch ( $column ) {
			case 'whatsapp_phone':
				$normalized = PhoneValidator::to_e164( $value );
				return ( null !== $normalized && preg_match( '/^\+[1-9][0-9]{0,14}$/', $normalized ) ) ? $normalized : null;

			case 'phone':
				$normalized = PhoneValidator::normalize_loose( $value );
				return ( '' !== $normalized && preg_match( '/^\+?[0-9]+$/', $normalized ) ) ? $normalized : null;

			case 'email':
				return is_email( $value ) ? $value : null;

			case 'zip':
				$zip = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				return '' !== $zip && strlen( $zip ) <= 150 ? $zip : null;

			default:
				return $value;
		}
	}

	/**
	 * Write a structured log entry, guarded so the action never fatals if the
	 * logger helper is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Logger level method (error, warning, ...).
	 * @param string $message Human-readable summary.
	 * @param array  $context Extra context; 'source' is added automatically.
	 *
	 * @return void
	 */
	private function log( $level, $message, array $context = array() ) {
		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}

		$context['source'] = 'automation-update-contact';
		doublescale_get_logger()->{$level}( $message, $context );
	}
}

UpdateContactFields::instance();
