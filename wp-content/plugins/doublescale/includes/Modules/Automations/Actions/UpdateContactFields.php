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
	 * @return bool|array True when every mapped field was written; otherwise
	 *                    `{ success, message }` naming the fields that were
	 *                    skipped, so the automation report shows why.
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
		$skipped          = array();
		$written          = 0;
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
					$custom_fields[ $custom_id ] = is_scalar( $value ) ? trim( (string) $value ) : '';
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
				$skipped[] = sprintf(
					/* translators: %s: contact field label, e.g. "Email". */
					__( '%s (the value is not valid for this field)', 'doublescale' ),
					$this->standard_fields()[ $field ] ?? $field
				);
				continue;
			}

			$contact->{$field} = $normalized;
			$dirty             = true;
			++$written;
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
				return array(
					'success' => false,
					'message' => __( 'The contact could not be saved.', 'doublescale' ) . ' ' . $e->getMessage(),
				);
			}
		}

		if ( ! empty( $custom_fields ) && null !== $contact->custom_fields() ) {
			$pivot = $this->custom_field_values( $custom_fields, $skipped );

			if ( ! empty( $pivot ) ) {
				try {
					$contact->custom_fields()->syncWithoutDetaching( $pivot );
					$written += count( $pivot );
				} catch ( \Exception $e ) {
					$this->log(
						'error',
						'Failed to sync contact custom fields.',
						array(
							'contact_id' => $contact->id,
							'exception'  => $e->getMessage(),
						)
					);
					return array(
						'success' => false,
						'message' => __( 'The custom fields could not be saved.', 'doublescale' ) . ' ' . $e->getMessage(),
					);
				}
			}
		}

		if ( empty( $skipped ) ) {
			return true;
		}

		/* translators: %s: comma-separated list of skipped fields with the reason. */
		$message = sprintf( __( 'Skipped: %s', 'doublescale' ), implode( '; ', $skipped ) );

		return array(
			// Nothing written at all is a failed step; a partial update completes
			// and still names what was left out.
			'success' => $written > 0,
			'message' => $message,
		);
	}

	/**
	 * Build the pivot rows for the mapped custom fields.
	 *
	 * Rows carry `entity_type => contact`: the contact's custom_fields()
	 * relation only reads rows of that type, so a row written without it was
	 * saved but never shown. Choice fields (select, radio, multiselect,
	 * checkbox) are matched to their options without regard to case or
	 * spacing and stored with the option's own spelling, comma-joined for
	 * several values, the same format the contact screen saves. A value that
	 * matches no option is left out and named in $skipped, because the
	 * contact screen hides values that are not options.
	 *
	 * @param array<int, string> $values  Field id => resolved value.
	 * @param array<int, string> $skipped Collects "Field (reason)" entries.
	 * @return array<int, array{value: string, entity_type: string}>
	 */
	private function custom_field_values( array $values, array &$skipped ) {
		$model = '\DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel';
		if ( ! class_exists( $model ) ) {
			return array();
		}

		try {
			$fields = $model::whereIn( 'id', array_keys( $values ) )->get()->keyBy( 'id' );
		} catch ( \Exception $e ) {
			$fields = null;
		}

		$pivot = array();
		foreach ( $values as $id => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$custom_field = $fields ? $fields->get( $id ) : null;
			if ( ! $custom_field ) {
				$skipped[] = sprintf(
					/* translators: %d: custom field id. */
					__( 'custom field #%d (it no longer exists)', 'doublescale' ),
					$id
				);
				continue;
			}

			$type    = (string) $custom_field->type;
			$options = method_exists( $custom_field, 'get_option_values' ) ? (array) $custom_field->get_option_values() : array();

			if ( in_array( $type, array( 'select', 'radio', 'multiselect', 'checkbox' ), true ) && ! empty( $options ) ) {
				$several = in_array( $type, array( 'multiselect', 'checkbox' ), true );
				$parts   = $several ? explode( ',', $value ) : array( $value );
				$matched = array();
				$missing = array();

				foreach ( $parts as $part ) {
					$part = trim( (string) $part );
					if ( '' === $part ) {
						continue;
					}
					$option = self::match_option( $part, $options );
					if ( null === $option ) {
						$missing[] = $part;
					} elseif ( ! in_array( $option, $matched, true ) ) {
						$matched[] = $option;
					}
				}

				if ( ! empty( $missing ) ) {
					$skipped[] = sprintf(
						/* translators: 1: custom field name, 2: the value(s) that matched no option. */
						__( '%1$s ("%2$s" is not one of its options)', 'doublescale' ),
						$custom_field->name,
						implode( '", "', $missing )
					);
				}

				if ( empty( $matched ) ) {
					continue;
				}

				$value = $several ? implode( ',', $matched ) : $matched[0];
			}

			$pivot[ (int) $id ] = array(
				'value'       => $value,
				'entity_type' => 'contact',
			);
		}

		return $pivot;
	}

	/**
	 * The option a value stands for, ignoring case and repeated spaces.
	 *
	 * @param string             $value   Value to look up.
	 * @param array<int, string> $options The field's option values.
	 * @return string|null The option as configured, or null when none matches.
	 */
	private static function match_option( $value, array $options ) {
		$key = static function ( $text ) {
			$text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text ) : strtolower( (string) $text );
		};

		$needle = $key( $value );
		foreach ( $options as $option ) {
			if ( $key( $option ) === $needle ) {
				return (string) $option;
			}
		}

		return null;
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

		// Custom fields also carry their type and, for choice fields, their
		// options, so the editor can offer a picker instead of free text.
		foreach ( $this->custom_field_targets() as $key => $target ) {
			$fields[ $key ] = $target;
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
	 * @return array<string, array{label: string, type: string, options?: array<int, string>}>
	 */
	private function custom_field_targets() {
		$targets = array();

		if ( ! class_exists( '\DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
			return $targets;
		}

		try {
			$model = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class;
			// In the order the admin set (Pro sorts its cached list; older Pro: as stored).
			$custom_fields = method_exists( $model, 'cached_for_scope' )
				? $model::cached_for_scope( 'contact' )
				: $model::where( 'scope', 'contact' )->get();
			foreach ( $custom_fields as $custom_field ) {
				$target = array(
					'label' => (string) $custom_field->name,
					'type'  => (string) $custom_field->type,
				);
				if ( in_array( $target['type'], array( 'select', 'radio', 'multiselect', 'checkbox' ), true ) && method_exists( $custom_field, 'get_option_values' ) ) {
					$target['options'] = array_values( (array) $custom_field->get_option_values() );
				}
				$targets[ 'cf_' . $custom_field->id ] = $target;
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
