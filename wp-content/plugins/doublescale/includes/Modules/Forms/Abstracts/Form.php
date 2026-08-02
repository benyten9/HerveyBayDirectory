<?php

/**
 * Class Form
 * This class is responsible for handling the integration of forms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */


namespace DoubleScale\Modules\Forms\Abstracts;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;
use DoubleScale\Core\Fields\ContactFields;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Form class
 */
abstract class Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Form Data
	 *
	 * @var FormModel
	 */
	protected $form_data = array();

	/**
	 * Entry
	 *
	 * @var array
	 */
	protected $submission = array();


	/**
	 * Is PRO only
	 *
	 * @var bool
	 */
	public $is_pro = false;

	/**
	 * Platform category: wordpress plugin or external saas form builder.
	 *
	 * @var string
	 */
	public $platform = 'wordpress';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function load_hooks();

	/**
	 * Get forms
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_forms() {
		return $this->forms;
	}

	/**
	 * Get form
	 *
	 * @since 1.0.0
	 *
	 * @param string $id
	 * @return array
	 */
	public function get_form( $id ) {
		return isset( $this->forms[ $id ] ) ? $this->forms[ $id ] : null;
	}

	/**
	 * Get form fields
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return array
	 */
	abstract public function get_fields( $form_id);

	/**
	 * Register merge tags
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return void
	 */
	public function register_merge_tags_for_form( $form_id, array $new_fields = array() ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$fields = ! empty( $new_fields ) ? $new_fields : $this->get_fields( $form_id );

		if ( empty( $fields ) ) {
			return;
		}

		if ( ! class_exists( 'DoubleScale\Pro\Modules\Forms\MergeTags\Forms\DynamicFieldsRegistration' ) ) {
			return;
		}

		new \DoubleScale\Pro\Modules\Forms\MergeTags\Forms\DynamicFieldsRegistration( $fields, $this->slug );
	}

	/**
	 * Register field rules for form
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return void
	 */
	public function register_field_rules_for_form( $form_id, array $new_fields = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$fields = ! empty( $new_fields ) ? $new_fields : $this->get_fields( $form_id );

		if ( empty( $fields ) ) {
			return;
		}

		if ( ! class_exists( 'DoubleScale\Modules\Automations\Services\RulesManager' ) ) {
			return;
		}

		if ( ! class_exists( 'DoubleScale\Pro\Modules\Automations\Rules\Forms\FormFieldRule' ) ) {
			return;
		}

		$rules_manager = RulesManager::instance();

		foreach ( $fields as $field_id => $field_name ) {
			$rule = new \DoubleScale\Pro\Modules\Automations\Rules\Forms\FormFieldRule( $this, $form_id, $field_id, $field_name['label'] ?? '' );
			$rules_manager->register( $rule );
		}
	}


	/**
	 * Process form (async)
	 *
	 * Schedules form processing as a background task using Action Scheduler.
	 * This prevents form submission from crashing if CRM processing fails.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Form submission data
	 *
	 * @return void
	 */
	public function process_form( $data ) {
		$data['_form_settings'] = $this->form_data ? $this->form_data->toArray() : array();
		$data['_form_slug']     = $this->slug;

		PluginKernel::instance()->forms_tasks->enqueue_async( 'process_form', $data );
	}

	/**
	 * Process form synchronously
	 *
	 * Contains the actual form processing logic. Called by the async handler.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Form submission data
	 *
	 * @return void
	 */
	public function process_form_sync( $data ) {
		try {
			$this->submission = $data;
			$contact_fields   = $this->get_contact_data();
			$contact_data     = $contact_fields['fields'];
			$custom_fields    = $contact_fields['custom_fields'];
			$lists            = $this->form_data->data['lists'] ?? array();
			$tags             = $this->form_data->data['tags'] ?? array();
			$update_existing  = $this->form_data->data['update_existing_contact'] ?? false;

			$contact_data['source'] = $this->slug;

			$make_as_subscriber = $this->form_data->data['mark_as_subscribed'] ?? false;
			if ( ! $make_as_subscriber ) {
				$contact_data['email_status'] = 'unverified';
			} else {
				$contact_data['email_status'] = 'subscribed';
			}

			if ( ! $update_existing ) {
				$contact = ContactModel::get_by_email( $contact_data['email'] ?? '' );
				if ( $contact ) {
					$this->save_form_submission( $data, $contact->id );
					return;
				}
			}

			$contact = ContactModel::createOrUpdate( $contact_data );

			if ( ! empty( $lists ) ) {
				$contact->add_lists( $lists );
			}

			if ( ! empty( $tags ) ) {
				$contact->add_tags( $tags );
			}

			if ( ! empty( $custom_fields ) ) {
				$custom_fields_values = array();
				foreach ( $custom_fields as $key => $value ) {
					$custom_fields_values[ $key ] = array(
						'value'       => $value,
						'entity_type' => 'contact',
					);
				}

				$contact->custom_fields()->sync( $custom_fields_values );
			}

			$this->save_form_submission( $data, $contact->id );

			$enable_email_notification = $this->form_data->data['enable_email_notification'] ?? false;
			if ( ! $make_as_subscriber && $enable_email_notification && 'unverified' === $contact->email_status ) {
				$this->send_double_optin_email( $contact );
			}

			doublescale_get_logger()->info(
				__( 'Contact created successfully', 'doublescale'),
				array(
					'id'     => $contact->id,
					'email'  => $contact->email,
					'source' => $this->slug,
				)
			);
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error creating contact', 'doublescale'),
				array(
					'code'  => 'error_creating_contact',
					'data'  => $data,
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}



	/**
	 * Save form submission
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Form submission data
	 * @param int   $contact_id Contact ID
	 */
	protected function save_form_submission( $data, $contact_id = null ) {
		try {
			$submission_data = array(
				'form_id'           => $this->form_data->id ?? null,
				'contact_id'        => $contact_id,
				'external_entry_id' => $data['entry_id'] ?? null,
			);

			FormSubmissionModel::create( $submission_data );

			do_action( 'doublescale_form_submitted', $contact_id );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error saving form submission', 'doublescale'),
				array(
					'code'  => 'error_saving_form_submission',
					'data'  => $data,
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
		}
	}

	/**
	 * Get contact fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_contact_data() {
		$mapped_fields = $this->form_data->data['mapped_fields'] ?? array();

		if ( empty( $mapped_fields ) ) {
			throw new Exception( 'No mapped fields found' );
		}

		$contact_data = $this->get_contact_fields( $mapped_fields );

		return $contact_data;
	}

	/**
	 * Get contact fields for form
	 *
	 * @since 1.0.0
	 *
	 * @param array $mapped_fields Mapped fields
	 *
	 * @return array
	 */
	public function get_contact_fields( $mapped_fields ) {
		$entry          = $this->submission['entry'] ?? array();
		$fields         = $this->submission['fields'] ?? array();
		$contact_fields = ContactFields::instance()->get_fields();

		$contact_data  = array();
		$custom_fields = array();

		foreach ( $mapped_fields as $form_field => $contact_field ) {

			if ( ! isset( $contact_fields[ $form_field ] ) ) {
				continue;
			}

			$raw_value = null;

			if ( $this->has_form_merge_tags( $contact_field ) ) {
				$raw_value = $this->resolve_form_merge_tags(
					$contact_field,
					$entry['fields'] ?? array()
				);
			} else {
				if ( ! isset( $entry['fields'][ $contact_field ] ) || ! isset( $fields[ $contact_field ] ) ) {
					continue;
				}
				$raw_value = $entry['fields'][ $contact_field ];
			}

			if ( ! class_exists( $contact_fields[ $form_field ]['type'] ) ) {
				throw new Exception( 'Invalid field type' );
			}

			/** @var \DoubleScale\Core\Abstracts\FieldType $field_type */
			$field_type = new $contact_fields[ $form_field ]['type'](
				$contact_fields[ $form_field ]
			);

			$value = $field_type->sanitize_field( $raw_value );

			if ( $form_field === 'country' ) {
				$value = doublescale_get_country_code( $value );
			}

			$field_type->validate_value( $value );

			if ( ! $field_type->is_valid ) {
				continue;
			}

			if ( $contact_fields[ $form_field ]['is_custom'] ?? false ) {
				$custom_fields[ $form_field ] = $value;
			} else {
				$contact_data[ $form_field ] = $value;
			}
		}

		return array(
			'fields'        => $contact_data,
			'custom_fields' => $custom_fields,
		);
	}


	/**
	 * Check if value contains form merge tags
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to check
	 *
	 * @return bool
	 */
	private function has_form_merge_tags( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return preg_match( '/\{\{form:[^}]+\}\}/', $value ) === 1;
	}

	/**
	 * Resolve form merge tags in a value
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value with merge tags
	 * @param array  $entry_fields Entry fields data
	 *
	 * @return string Resolved value
	 */
	private function resolve_form_merge_tags( $value, $entry_fields ) {

		$resolved_value = preg_replace_callback(
			'/\{\{form:([^}]+)\}\}/',
			function ( $matches ) use ( $entry_fields ) {
				$field_key = $matches[1];

				if ( isset( $entry_fields[ $field_key ] ) ) {
					$field_value = $entry_fields[ $field_key ];

					if ( is_array( $field_value ) ) {
						return implode( ', ', $field_value );
					}

					return (string) $field_value;
				}
				return '';
			},
			$value
		);

		return $resolved_value;
	}

	/**
	 * Get default form data
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_data() {
		return array();
	}

	/**
	 * Check if form is active
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return bool
	 */
	public function is_form_active( $form_id ) {
		try {
			if ( ! class_exists( 'DoubleScale\\Modules\\Forms\\Models\\FormModel' ) ) {
				return false;
			}
			$form            = FormModel::get_form_by_form_id( $this->get_form_id( $form_id ), $this->slug, 'active' );
			$this->form_data = $form;
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get form id
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return string
	 */
	public function get_form_id( $form_id ) {
		if ( strpos( $form_id, ':' ) !== false ) {
			$form_id = explode( ':', $form_id );
			$form_id = $form_id[1];
		}

		return $form_id;
	}

	/**
	 * Process automations
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments
	 *
	 * @return void
	 */
	public function process_automations( $args ) {
		
		try {
			$this->submission = $args;

			$automations = AutomationModel::get_automations_by_trigger( $this->slug );

			foreach ( $automations as $automation ) {
				if ( ! $this->is_processable( $automation, $args ) ) {
					continue;
				}

				$contact = $this->maybe_create_contact( $automation );
				if ( ! $contact ) {
					continue;
				}

				$data = array(
					'contact' => $contact,
					'data'    => $args,
				);

				PluginKernel::instance()->automations_tasks->enqueue_sync( 'process_automations', $automation, $data );
			}
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error processing automations', 'doublescale'),
				array(
					'code'  => 'error_processing_automations',
					'data'  => $args,
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}

	/**
	 * Maybe create contact
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 *
	 * @return ContactModel
	 */
	public function maybe_create_contact( AutomationModel $automation ) {
		try {
			$mapped_fields          = $automation->get_setting( 'mapped_fields', array() );
			$contact_fields         = $this->get_contact_fields( $mapped_fields );
			$custom_fields          = $contact_fields['custom_fields'];
			$contact_data           = $contact_fields['fields'];
			$contact_data['source'] = $this->slug;
			$make_as_subscriber     = $automation->get_setting( 'mark_as_subscribed', false );
			$update_blank_fields    = $automation->get_setting( 'update_blank_fields', false );
			$update_existing        = $automation->get_setting( 'update_existing_contact', false );
			$lists                  = $automation->get_setting( 'lists', array() );
			$tags                   = $automation->get_setting( 'tags', array() );

			if ( ! $make_as_subscriber ) {
				$contact_data['email_status'] = 'unverified';
			} else {
				$contact_data['email_status'] = 'subscribed';
			}

			if ( ! $update_blank_fields ) {
				$contact_data = array_filter( $contact_data );
			}

			if ( ! $update_existing ) {
				$contact = ContactModel::get_by_email( $contact_data['email'] ?? '' );
				if ( $contact ) {
					$this->save_form_submission( $this->submission, $contact->id );
					return $contact;
				}
			}

			$contact = ContactModel::createOrUpdate( $contact_data );

			if ( ! empty( $lists ) ) {
				$contact->add_lists( $lists );
			}

			if ( ! empty( $tags ) ) {
				$contact->add_tags( $tags );
			}

			if ( ! empty( $custom_fields ) && class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
				$custom_fields_values = array();
				foreach ( $custom_fields as $key => $value ) {
					$custom_fields_values[ $key ] = array(
						'value'       => $value,
						'entity_type' => 'contact',
					);
				}

				$contact->custom_fields()->syncWithoutDetaching( $custom_fields_values );
			}

			$this->save_form_submission( $this->submission, $contact->id );

			return $contact;
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error creating contact', 'doublescale'),
				array(
					'code'  => 'error_creating_contact',
					'data'  => $contact_data ?? array(),
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return null;
		}
	}

	/**
	 * Check if trigger should be processed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 * @param array            $args Arguments
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$form_id            = $this->get_form_id( $args['form_id'] );
		$automation_form_id = $automation->get_setting( 'form_id' );

		return $form_id == $automation_form_id;
	}

	/**
	 * Get form options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_form_options() {
		$options = array(
			'form_id' => array(
				'label'       => __( 'Form ID', 'doublescale'),
				'type'        => 'ajax_select',
				'ajax_action' => "doublescale_{$this->slug}_get_form_select_options",
			),
		);

		return $options;
	}

	/**
	 * Get form fields settings
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_form_fields_settings() {
		$settings = array(
			'action' => "doublescale_{$this->slug}_get_fields",
			'fields' => array(
				'form_id',
			),
		);

		return $settings;
	}

	/**
	 * Is Enabled
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return true;
	}

	/**
	 * Send double opt-in email to contact
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact Model.
	 *
	 * @return void
	 */
	protected function send_double_optin_email( $contact ) {
		\DoubleScale\Modules\Emails\Emails::send_double_optin_email( $contact );
	}

	/**
	 * Restore form data from serialized settings
	 *
	 * Used by async handler to restore form_data from the stored settings
	 * that were serialized when the async task was scheduled.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Form settings array
	 *
	 * @return void
	 */
	public function restore_form_data( $settings ) {
		if ( empty( $settings ) ) {
			return;
		}

		$this->form_data = new FormModel( $settings );
	}
}
