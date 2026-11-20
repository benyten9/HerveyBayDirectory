<?php

/**
 * Webhook Received Trigger
 *
 * This trigger will be fired when a webhook is received.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * Webhook Received
 */
class WebhookReceived extends Trigger {


	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Webhook Received';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'webhook_received';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a webhook is received.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'webhooks';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'webhooks';

	/**
	 * Load hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_webhook_receive', array( $this, 'webhook_received' ), 10, 2 );
	}

	/**
	 * Webhook Received
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array            $payload Payload.
	 *
	 * @return void
	 */
	public function webhook_received( $automation, $payload ) {
		$automation->set_setting( 'payload', $payload );
		$automation->set_setting( 'received_at', current_time( 'mysql' ) );
		$automation->save();

		// Process the automation
		$this->process_webhook_automation( $automation, $payload );
	}

	/**
	 * Create or update the contact from the mapped fields, then start this
	 * workflow with the whole payload kept on the run (merge tags
	 * {{webhook:field:<path>}}, "Webhook data" conditions).
	 *
	 * Mappings are `payload path => contact field`. A path reaches into nested
	 * payloads (`data.lead.email`); a contact field may be a custom field.
	 * Empty values never blank what the contact already has.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $payload    Payload.
	 *
	 * @return void
	 */
	public function process_webhook_automation( $automation, $payload ) {
		$payload       = is_array( $payload ) ? $payload : array();
		$mapped_fields = (array) $automation->get_setting( 'mapped_fields', array() );

		list( $contact_data, $custom_fields, $tag_names ) = $this->contact_values( $mapped_fields, $payload );

		// A contact is found or created by email or phone; without either there is nobody to run the workflow for.
		if ( empty( $contact_data['email'] ) && empty( $contact_data['phone'] ) ) {
			doublescale_get_logger()->warning(
				__( 'Webhook received without an email or phone mapped to the contact, so the workflow did not start.', 'doublescale' ),
				array( 'automation_id' => (int) $automation->id )
			);
			return;
		}

		try {
			// A new contact gets this workflow's owner (see ContactEnrollment).
			$contact = \DoubleScale\Pro\Core\UserRoles\PermissionsCompat::create_workflow_contact(
				$automation,
				static function () use ( $contact_data ) {
					return ContactModel::createOrUpdate( $contact_data );
				}
			);
			if ( ! $contact ) {
				return;
			}

			if ( ! empty( $custom_fields ) && $contact->custom_fields() ) {
				$values = array();
				foreach ( $custom_fields as $field_id => $value ) {
					$values[ $field_id ] = array(
						'value'       => $value,
						'entity_type' => 'contact',
					);
				}
				// Add or change only the mapped fields; the contact's other custom values stay.
				$contact->custom_fields()->syncWithoutDetaching( $values );
			}

			if ( ! empty( $tag_names ) ) {
				$this->add_tags_by_name( $contact, $tag_names );
			}

			$this->process(
				array(
					'contact' => $contact,
					'data'    => array(
						'webhook'               => $payload,
						'webhook_automation_id'  => (int) $automation->id,
					),
				)
			);
		} catch ( \Exception $e ) {
			error_log( 'Error creating contact: ' . $e->getMessage() );
		}
	}

	/**
	 * Mapped payload values, cleaned the way each contact field expects.
	 *
	 * @param array $mapped_fields Payload path => contact field key.
	 * @param array $payload       Payload.
	 * @return array{0: array, 1: array, 2: string[]} Contact columns, custom field values (by field id), tag names.
	 */
	private function contact_values( array $mapped_fields, array $payload ): array {
		$definitions = class_exists( '\DoubleScale\Core\Fields\ContactFields' )
			? \DoubleScale\Core\Fields\ContactFields::instance()->get_fields()
			: array();

		$contact_data  = array();
		$custom_fields = array();
		$tag_names     = array();
		$full_name     = '';

		foreach ( $mapped_fields as $path => $field ) {
			$field = (string) $field;
			if ( '' === $field ) {
				continue;
			}
			$raw = self::payload_value( $payload, (string) $path );
			if ( null === $raw ) {
				continue;
			}

			// Special targets: one "name" field, and tags given by name (a list or "a, b").
			if ( '__tags' === $field ) {
				$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
				foreach ( $list as $name ) {
					$name = is_scalar( $name ) ? trim( sanitize_text_field( (string) $name ) ) : '';
					if ( '' !== $name ) {
						$tag_names[] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
					}
				}
				continue;
			}
			if ( '__full_name' === $field ) {
				$full_name = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
				continue;
			}
			$raw = is_array( $raw ) ? implode( ', ', array_filter( array_map( static function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; }, $raw ) ) ) : $raw;
			if ( '' === trim( (string) $raw ) ) {
				continue;
			}

			$definition = $definitions[ $field ] ?? null;
			$value      = $raw;
			if ( $definition && ! empty( $definition['type'] ) && class_exists( $definition['type'] ) ) {
				/** @var \DoubleScale\Core\Abstracts\FieldType $type */
				$type  = new $definition['type']( $definition );
				$value = $type->sanitize_field( $raw );
				if ( 'country' === $field && function_exists( 'doublescale_get_country_code' ) ) {
					$value = doublescale_get_country_code( $value );
				}
				$type->validate_value( $value );
				if ( ! $type->is_valid ) {
					continue;
				}
			} elseif ( is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			}

			if ( $definition && ! empty( $definition['is_custom'] ) ) {
				$custom_fields[ $field ] = $value;
			} else {
				$contact_data[ $field ] = $value;
			}
		}

		// A full name fills first and last name, unless those are mapped on their own.
		if ( '' !== $full_name ) {
			$parts = preg_split( '/\s+/', $full_name, 2 );
			if ( empty( $contact_data['first_name'] ) ) {
				$contact_data['first_name'] = $parts[0];
			}
			if ( empty( $contact_data['last_name'] ) && ! empty( $parts[1] ) ) {
				$contact_data['last_name'] = $parts[1];
			}
		}

		return array( $contact_data, $custom_fields, array_slice( array_values( array_unique( $tag_names ) ), 0, 20 ) );
	}

	/**
	 * Add tags to the contact by name, creating the ones that do not exist yet.
	 *
	 * @param ContactModel $contact Contact.
	 * @param string[]     $names   Tag names.
	 */
	private function add_tags_by_name( $contact, array $names ): void {
		if ( ! class_exists( '\\DoubleScale\\Modules\\Contacts\\Models\\TagModel' ) ) {
			return;
		}
		$ids = array();
		foreach ( $names as $name ) {
			$tag = \DoubleScale\Modules\Contacts\Models\TagModel::where( 'name', $name )->first();
			if ( ! $tag ) {
				$tag = \DoubleScale\Modules\Contacts\Models\TagModel::create( array( 'name' => $name ) );
			}
			if ( $tag ) {
				$ids[] = (int) $tag->id;
			}
		}
		if ( ! empty( $ids ) ) {
			$contact->add_tags( $ids );
		}
	}

	/**
	 * The value at a dot path (`data.lead.email`); an exact key with a dot wins.
	 * Kept here as well as in free's WebhookPayload so Pro works next to an
	 * older free.
	 *
	 * @param array  $payload Payload.
	 * @param string $path    Path.
	 * @return mixed
	 */
	private static function payload_value( array $payload, string $path ) {
		$path = trim( $path );
		if ( '' === $path ) {
			return null;
		}
		if ( array_key_exists( $path, $payload ) ) {
			return $payload[ $path ];
		}
		$current = $payload;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	/**
	 * Only the workflow the webhook was sent to runs.
	 *
	 * Every workflow with this trigger shares the trigger slug; the webhook URL
	 * names one workflow, so the others must not start.
	 *
	 * @param AutomationModel $automation Candidate workflow.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$target = (int) ( $args['data']['webhook_automation_id'] ?? 0 );
		return $target > 0 && $target === (int) $automation->id;
	}

	/**
	 * Set automation attributes
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 *
	 * @return void
	 */
	public function set_settings( $automation ) {
		$random_string = bin2hex( random_bytes( 16 ) );
		$automation->set_setting( 'webhook_key', $random_string );
		$automation->save();
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'mapped_fields' => array(
				'label'  => __( 'Mapped Fields', 'doublescale'),
				'type'   => 'mapped_fields',
				'fields' => array(
					'first_name' => array(
						'label' => __( 'First Name', 'doublescale'),
					),
					'last_name'  => array(
						'label' => __( 'Last Name', 'doublescale'),
					),
					'email'      => array(
						'label' => __( 'Email', 'doublescale'),
					),
				),
			),
		);
	}
}
