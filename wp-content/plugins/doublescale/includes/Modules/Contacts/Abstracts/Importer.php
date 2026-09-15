<?php

/**
 * Class Importer
 *
 * This class is responsible for handling the import class
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Abstracts;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\PhoneAsWhatsappSetting;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\Validators\PhoneValidator;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Modules\Contacts\Models\TagModel;

/**
 * Importer class
 */
abstract class Importer {


	/**
	 * Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	protected $is_integration = false;

	/**
	 * Start time
	 *
	 * @var int
	 */
	protected $start_time;

	/**
	 * Max execution time
	 *
	 * @var int
	 */
	protected $max_execution_time;

	/**
	 * Current execution time
	 *
	 * @var int
	 */
	protected $current_execution_time;

	/**
	 * Lists mapping
	 *
	 * @var array
	 */
	protected $lists_mapping;

	/**
	 * Tags mapping
	 *
	 * @var array
	 */
	protected $tags_mapping;

	/**
	 * Custom fields mapping
	 *
	 * @var array
	 */
	protected $custom_fields_mapping;

	/**
	 * Lists
	 *
	 * @var array
	 */
	protected $lists;

	/**
	 * Tags
	 *
	 * @var array
	 */
	protected $tags;

	/**
	 * Offset
	 *
	 * @var int
	 */
	protected $offset;

	/**
	 * Cursor (for cursor-based pagination)
	 *
	 * @var string|null
	 */
	protected $cursor;

	/**
	 * Update existing
	 *
	 * @var bool
	 */
	protected $update_existing;

	/**
	 * Status
	 *
	 * @var string
	 */
	protected $status;

	/**
	 * Send double opt-in email
	 *
	 * @var bool
	 */
	protected $send_double_optin;

	/**
	 * Group
	 *
	 * @var string
	 */
	protected $group;

	/**
	 * Credentials
	 *
	 * @var array
	 */
	public $credentials;

	/**
	 * Whether the imported phone should also be saved as WhatsApp number.
	 *
	 * @var bool
	 */
	protected $phone_is_whatsapp = true;

	/**
	 * Reason the last import_contact() call returned false.
	 *
	 * @var string
	 */
	protected $last_failure_reason = '';

	/**
	 * Constructor
	 *
	 * @param array $args args
	 */
	public function __construct( $args = array() ) {
		// Get the max execution time
		$this->max_execution_time = Utils::get_max_execution_time();

		// Set the args
		$this->update_existing       = $args['update_existing'] ?? false;
		$this->status                = $args['status'] ?? 'unverified';
		$this->send_double_optin     = $args['send_double_optin'] ?? false;
		$this->lists_mapping         = $args['lists_mapping'] ?? array();
		$this->tags_mapping          = $args['tags_mapping'] ?? array();
		$this->custom_fields_mapping = $args['custom_fields_mapping'] ?? array();
		$this->offset                = $args['offset'] ?? 0;
		$this->cursor                = $args['cursor'] ?? null;
		$this->lists                 = $args['lists'] ?? array();
		$this->tags                  = $args['tags'] ?? array();
		$this->credentials           = $args['credentials'] ?? array();
		$this->phone_is_whatsapp     = PhoneAsWhatsappSetting::is_enabled(
			$args[ PhoneAsWhatsappSetting::SETTING_KEY ] ?? null
		);
	}

	/**
	 * Get credentials
	 *
	 * @return array
	 */
	public function get_credentials() {
		return array();
	}

	/**
	 * Set credentials
	 *
	 * @param array $credentials Credentials
	 *
	 * @return void
	 */
	public function set_credentials( $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array();
	}

	/**
	 * Is active
	 *
	 * @return bool
	 */
	public function is_active() {
		return true;
	}

	/**
	 * Is integration
	 *
	 * @return bool
	 */
	public function is_integration() {
		return $this->is_integration;
	}

	/**
	 * Import
	 *
	 * @return array
	 */
	public function import() {
		if ( ! $this->is_active() ) {
			return new \WP_Error( 'importer_not_active', __( 'The importer is not active', 'doublescale' ) );
		}
		$this->start_time = microtime( true );
		return $this->run();
	}

	/**
	 * Run import
	 *
	 * @return array
	 */
	abstract protected function run();

	/**
	 * Import contact
	 *
	 * @since 1.0.0
	 *
	 * @param object $subscriber Subscriber
	 * @param array  $mapping Mapping
	 *
	 * @return bool|string True on success, 'skipped' if skipped, false on failure
	 */
	public function import_contact( $subscriber, $mapping ) {
		$this->last_failure_reason = '';

		try {
			$email          = $this->subscriber_value( $subscriber, $mapping['email'] ?? '' );
			$phone          = $this->subscriber_value( $subscriber, $mapping['phone'] ?? '' );
			$whatsapp_phone = $this->subscriber_value( $subscriber, $mapping['whatsapp_phone'] ?? '' );
			$country        = $this->subscriber_value( $subscriber, $mapping['country'] ?? '' );

			if ( is_string( $email ) ) {
				$email = trim( $email );
			} else {
				$email = '';
			}

			// Invalid email skips this row only; never abort the file.
			if ( '' !== $email && ! is_email( $email ) ) {
				$this->last_failure_reason = 'invalid_email';
				return false;
			}

			if ( ! ContactModel::has_identifier( $email, $phone, $whatsapp_phone ) ) {
				$this->last_failure_reason = 'missing_identifier';
				return false;
			}

			$lists = is_object( $subscriber ) ? $subscriber->lists ?? array() : $subscriber['lists'] ?? array();
			$lists = $lists ? explode( ',', $lists ) : array();
			$tags  = is_object( $subscriber ) ? $subscriber->tags ?? array() : $subscriber['tags'] ?? array();
			$tags  = $tags ? explode( ',', $tags ) : array();

			$contact  = ContactModel::find_by_identifiers(
				array(
					'email'          => $email,
					'phone'          => $phone,
					'whatsapp_phone' => $whatsapp_phone,
					'country'        => $country,
				)
			);
			$existing = $contact ? true : false;
			if ( ! $contact ) {
				$contact = new ContactModel();
			}

			// Skip if contact exists and update_existing is false
			if ( $existing && ! $this->update_existing ) {
				return 'skipped';
			}

			if ( ( $this->update_existing && $existing ) || ! $existing ) {
				$custom_field_values = array();

				foreach ( $mapping as $key => $value ) {
					if ( 'status' === $key ) {
						$status                = $this->subscriber_value( $subscriber, 'status' );
						$contact->email_status = isset( $value[ $status ] ) ? $value[ $status ] : 'unverified';
						continue;
					}

					$raw_value = $this->subscriber_value( $subscriber, $value );

					if ( is_numeric( $key ) ) {
						$custom_field_values[ (int) $key ] = $raw_value;
					} else {
						$contact->$key = $raw_value;
					}
				}

				if ( ! empty( $this->status ) && ! isset( $mapping['status'] ) ) {
					$contact->email_status = $this->status;
				}

				$this->maybe_copy_phone_to_whatsapp( $contact );

				$contact->save();

				if ( ! empty( $custom_field_values ) && class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
					foreach ( $custom_field_values as $custom_field_id => $cf_value ) {
						if ( empty( $cf_value ) && $cf_value !== '0' ) {
							continue;
						}
						$this->attach_custom_field_to_contact( $contact, $custom_field_id, $cf_value );
					}
				}

				if ( ! $existing && $this->send_double_optin && 'unverified' === $contact->email_status && ! empty( $contact->email ) ) {
					$this->send_double_optin_email( $contact );
				}

				// Add the contact to the lists
				foreach ( $this->lists_mapping as $list ) {
					$name        = $list['list'];
					$assign_to   = $list['assignedList'] ?? array();
					$auto_create = $list['auto'] ?? false;

					if ( ! in_array( $name, $lists ) ) {
						continue;
					}

					if ( $auto_create ) {
						$list = ListModel::getOrCreate( $name );
						$this->attach_contact_terms( $contact, 'lists', array( $list->id ), 'list' );
					} elseif ( ! empty( $assign_to ) ) {
						$this->attach_contact_terms( $contact, 'lists', $assign_to, 'list' );
					}
				}

				// Add the contact to the tags
				foreach ( $this->tags_mapping as $tag ) {
					$name        = $tag['tag'];
					$assign_to   = $tag['assignedTag'] ?? array();
					$auto_create = $tag['auto'] ?? false;

					if ( ! in_array( $name, $tags ) ) {
						continue;
					}

					if ( $auto_create ) {
						$tag = TagModel::getOrCreate( $name );
						$this->attach_contact_terms( $contact, 'tags', array( $tag->id ), 'tag' );
					} elseif ( ! empty( $assign_to ) ) {
						$this->attach_contact_terms( $contact, 'tags', $assign_to, 'tag' );
					}
				}

				if ( ! empty( $this->tags ) ) {
					$this->attach_contact_terms( $contact, 'tags', $this->tags, 'tag' );
				}

				if ( ! empty( $this->lists ) ) {
					$this->attach_contact_terms( $contact, 'lists', $this->lists, 'list' );
				}

				// Handle custom fields mapping (Pro feature)
				if ( class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) && ! empty( $this->custom_fields_mapping ) ) {
					$this->import_custom_fields( $contact, $subscriber );
				}

				return true;
			}

			return 'skipped';
		} catch ( \Throwable $e ) {
			$this->last_failure_reason = $e->getMessage();
			$error_message             = __( 'Error importing contact', 'doublescale' ) . ': ' . $e->getMessage();
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					$error_message,
					array(
						'code'       => 'import_contact_error',
						'subscriber' => $subscriber,
						'mapping'    => $mapping,
						'error'      => array(
							'message' => $e->getMessage(),
							'code'    => $e->getCode(),
							'file'    => $e->getFile(),
							'line'    => $e->getLine(),
						),
					)
				);
			}
			// Don't return WP_Error here as it stops the import process
			// Just log the error and continue with the next contact
			return false;
		}
	}

	/**
	 * Read a mapped column from a CSV/API row without emitting notices.
	 *
	 * @param object|array $subscriber Row.
	 * @param string       $column     Column / property name.
	 * @return mixed
	 */
	protected function subscriber_value( $subscriber, $column ) {
		if ( '' === $column || null === $column ) {
			return '';
		}

		if ( is_object( $subscriber ) ) {
			return $subscriber->{$column} ?? '';
		}

		if ( is_array( $subscriber ) ) {
			return $subscriber[ $column ] ?? '';
		}

		return '';
	}

	/**
	 * Copy phone onto WhatsApp when the import toggle is on and WhatsApp is empty.
	 *
	 * Invalid E.164 conversions are skipped so a national phone still imports.
	 *
	 * @param ContactModel $contact Contact being saved.
	 */
	protected function maybe_copy_phone_to_whatsapp( $contact ) {
		if ( ! PhoneAsWhatsappSetting::is_enabled( $this->phone_is_whatsapp ) ) {
			return;
		}

		$phone    = (string) ( $contact->phone ?? '' );
		$whatsapp = (string) ( $contact->whatsapp_phone ?? '' );
		if ( '' === $phone || '' !== $whatsapp ) {
			return;
		}

		$converted = PhoneValidator::to_e164( $phone, (string) ( $contact->country ?? '' ) );
		if ( null !== $converted ) {
			$contact->whatsapp_phone = $converted;
		}
	}

	/**
	 * Attach lists/tags to a contact without detaching existing terms.
	 *
	 * Uses attach() — the same pivot primitive the ContactModel list/tag
	 * helpers use — because the bundled Eloquent port does not implement
	 * syncWithPivotValues(); calling it threw and silently aborted the
	 * assignment (and everything after it) for every imported contact.
	 *
	 * Assignment is additive and silent: it dedupes against the contact's
	 * current terms to avoid duplicate pivot rows, and intentionally does NOT
	 * fire the doublescale_contact_{list,tag}_apply hooks, so a bulk import
	 * does not mass-trigger list/tag automations.
	 *
	 * @param ContactModel $contact  Contact (already saved, has an id).
	 * @param string       $relation Relation name: 'lists' or 'tags'.
	 * @param array        $ids      Term IDs to attach.
	 * @param string       $type     Pivot taxonomy_type: 'list' or 'tag'.
	 *
	 * @return void
	 */
	protected function attach_contact_terms( $contact, $relation, $ids, $type ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$existing = array_map( 'intval', $contact->{$relation}->pluck( 'id' )->all() );
		$to_add   = array_values( array_diff( $ids, $existing ) );

		if ( ! empty( $to_add ) ) {
			$contact->{$relation}()->attach( $to_add, array( 'taxonomy_type' => $type ) );
			// Drop the cached relation so a later attach in the same run
			// dedupes against the rows we just inserted.
			$contact->unsetRelation( $relation );
		}
	}

	/**
	 * Import custom fields for a contact
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact model.
	 * @param object|array $subscriber Subscriber data.
	 *
	 * @return void
	 */
	protected function import_custom_fields( $contact, $subscriber ) {
		if ( ! class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
			return;
		}

		foreach ( $this->custom_fields_mapping as $field_mapping ) {
			$source_field  = $field_mapping['field'] ?? '';
			$target_fields = $field_mapping['assignedField'] ?? array();
			$auto_create   = $field_mapping['auto'] ?? false;
			$field_type    = $field_mapping['type'] ?? 'text'; // Get type from mapping, default to text
			$field_group   = $field_mapping['group'] ?? ''; // Get group from mapping
			$field_options = $field_mapping['options'] ?? array(); // Get options for select/radio/checkbox fields
			$field_label   = $field_mapping['label'] ?? ''; // Get label from mapping

			if ( empty( $target_fields ) && ! $auto_create ) {
				continue;
			}

			// Get the value from the subscriber
			$value = is_object( $subscriber ) ? ( $subscriber->$source_field ?? '' ) : ( $subscriber[ $source_field ] ?? '' );

			// Skip if no value
			if ( empty( $value ) && $value !== '0' ) {
				continue;
			}

			// Prepare attributes for fields with options
			$attributes = null;
			if ( ! empty( $field_options ) && in_array( $field_type, array( 'select', 'multiselect', 'radio', 'checkbox' ) ) ) {
				$attributes = $field_options;
			}

			$value_serialized = maybe_unserialize( $value );

			// Convert arrays to JSON for storage in Plugin
			if ( is_array( $value_serialized ) ) {
				// separate by comma
				$value = implode( ',', $value_serialized );
				$value = trim( $value );
			}

			// Auto create custom field
			if ( $auto_create ) {
				// Get group ID (either from the source group or default)
				$group_id = $this->get_or_create_custom_fields_group( $field_group, $source_field );

				// Check if custom field already exists
				$custom_field = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::where( 'slug', sanitize_title( $source_field ) )
					->where( 'scope', 'contact' )
					->first();

				if ( $custom_field ) {
					// Update existing field
					$custom_field->name       = ! empty( $field_label ) ? $field_label : $source_field;
					$custom_field->type       = $field_type;
					$custom_field->group_id   = $group_id;
					$custom_field->attributes = $attributes;
					$custom_field->save();
				} else {
					// Create new field
					$custom_field             = new \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel();
					$custom_field->name       = ! empty( $field_label ) ? $field_label : $source_field;
					$custom_field->slug       = sanitize_title( $source_field );
					$custom_field->type       = $field_type;
					$custom_field->scope      = 'contact';
					$custom_field->group_id   = $group_id;
					$custom_field->attributes = $attributes;
					$custom_field->save();
				}

				$this->attach_custom_field_to_contact( $contact, $custom_field->id, $value );
			} else {
				// Map to existing custom fields
				if ( ! empty( $target_fields ) ) {
					foreach ( $target_fields as $custom_field_id ) {
						$custom_field = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::find( $custom_field_id );
						if ( ! $custom_field ) {
							continue;
						}
						// for updating attributes if these values are not in the attributes array
						if ( in_array( $custom_field->type, array( 'select', 'multiselect', 'radio', 'checkbox' ), true ) ) {
							$custom_field->attributes = array_merge( $custom_field->attributes, $attributes );
							$custom_field->attributes = array_unique( $custom_field->attributes );
							$custom_field->save();
						}
						$this->attach_custom_field_to_contact( $contact, $custom_field_id, $value );
					}
				}
			}
		}
	}

	/**
	 * Attach custom field value to contact
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact model.
	 * @param int          $custom_field_id Custom field ID.
	 * @param mixed        $value Field value.
	 *
	 * @return void
	 */
	protected function attach_custom_field_to_contact( $contact, $custom_field_id, $value ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'doublescale_custom_field_relationship';

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix.

		// check table exists
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) ) {
			// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		// Check if relationship already exists.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is the prefixed contact-relationships table; values bound via prepare().
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE entity_id = %d AND entity_type = 'contact' AND custom_field_id = %d",
				$contact->id,
				$custom_field_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			// Update existing
			$wpdb->update(
				$table_name,
				array(
					'value'      => $value,
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id' => $existing->id,
				),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert new
			$wpdb->insert(
				$table_name,
				array(
					'entity_id'       => $contact->id,
					'entity_type'     => 'contact',
					'custom_field_id' => $custom_field_id,
					'value'           => $value,
					'created_at'      => current_time( 'mysql' ),
					'updated_at'      => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Get or create custom fields group for imports
	 *
	 * @since 1.0.0
	 *
	 * @param string $group_name Group name from source system
	 * @param string $field_slug Field slug for fallback
	 * @return int Group ID
	 */
	protected function get_or_create_custom_fields_group( $group_name, $field_slug ) {
		if ( ! class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel' ) ) {
			return 0;
		}

		// If no group name provided, use default
		if ( empty( $group_name ) ) {
			$group_name = __( 'Imported Fields', 'doublescale' );
			$group_slug = 'imported-fields';
		} else {
			$group_slug = sanitize_title( $group_name );
		}

		$group = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::firstOrCreate(
			array(
				'slug'  => $group_slug,
				'scope' => 'contact',
			),
			array(
				'name' => $group_name,
			)
		);

		return $group->id;
	}

	/**
	 * Send double opt-in email to a contact
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact Model.
	 *
	 * @return void
	 */
	protected function send_double_optin_email( $contact ) {
		if ( class_exists( '\DoubleScale\Modules\Emails\Emails' ) ) {
			\DoubleScale\Modules\Emails\Emails::send_double_optin_email( $contact );
		}
	}

	/**
	 * Get current execution time
	 *
	 * @return int
	 */
	public function get_current_execution_time() {
		return microtime( true ) - $this->start_time;
	}

	/**
	 * Contacts processed in one HTTP request.
	 *
	 * Kept small so the REST handler always returns JSON before the web
	 * server or PHP kills the request. The browser chains requests until
	 * status is completed.
	 *
	 * @return int
	 */
	public function get_contacts_per_request() {
		$limit = (int) apply_filters( 'doublescale_import_contacts_per_request', 50 );
		return max( 1, $limit );
	}

	/**
	 * Optional pause between HTTP batches. CSV defaults to 0 — a 1s sleep
	 * per page of 20 rows is what timed out 1,500-row imports.
	 *
	 * @return int
	 */
	protected function get_batch_pause_microseconds() {
		return max( 0, (int) apply_filters( 'doublescale_import_batch_pause_microseconds', 0 ) );
	}

	/**
	 * @param object|array $subscriber Row.
	 * @param array        $mapping    Field mapping.
	 * @return array{email: string, reason: string}
	 */
	protected function describe_import_failure( $subscriber, $mapping ) {
		$email = $this->subscriber_value( $subscriber, $mapping['email'] ?? 'email' );
		return array(
			'email'  => is_scalar( $email ) ? (string) $email : '',
			'reason' => '' !== $this->last_failure_reason ? $this->last_failure_reason : 'invalid_or_duplicate',
		);
	}

	/**
	 * Import with offset
	 *
	 * One page of rows per HTTP request so the client always receives JSON.
	 *
	 * @param int      $total
	 * @param int      $offset
	 * @param callable $get_subscribers_callback
	 * @param array    $mapping
	 * @return array
	 */
	public function import_with_offset( $total, $offset, $get_subscribers_callback, $mapping ) {
		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		$failures = array();

		$pause = $this->get_batch_pause_microseconds();
		if ( $pause > 0 ) {
			usleep( $pause );
		}

		$subscribers = $get_subscribers_callback( $offset );
		if ( empty( $subscribers ) ) {
			return array(
				'offset'   => $offset,
				'status'   => 'completed',
				'total'    => $total,
				'imported' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'failures' => array(),
			);
		}

		foreach ( $subscribers as $subscriber ) {
			if ( Utils::is_memory_limit_reached() ) {
				break;
			}

			$result = $this->import_contact( $subscriber, $mapping );
			if ( false === $result ) {
				++$failed;
				if ( count( $failures ) < 10 ) {
					$failures[] = $this->describe_import_failure( $subscriber, $mapping );
				}
			} elseif ( 'skipped' === $result ) {
				++$skipped;
			} else {
				++$imported;
			}
			++$offset;

			if ( $offset >= $total ) {
				break;
			}
		}

		return array(
			'offset'   => $offset,
			'status'   => $offset >= $total ? 'completed' : 'in_progress',
			'total'    => $total,
			'imported' => $imported,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'failures' => $failures,
		);
	}

	/**
	 * Import with cursor-based pagination
	 *
	 * @param int      $total
	 * @param int      $offset Starting offset (for compatibility)
	 * @param callable $get_subscribers_callback
	 * @param array    $mapping
	 * @return array
	 */
	public function import_with_cursor( $total, $offset, $get_subscribers_callback, $mapping ) {
		$current_offset = $offset;
		$cursor         = $this->cursor;
		$imported       = 0;
		$skipped        = 0;
		$failed         = 0;
		$failures       = array();

		$pause = $this->get_batch_pause_microseconds();
		if ( $pause > 0 ) {
			usleep( $pause );
		}

		$batch_result = $get_subscribers_callback( $cursor );
		if ( empty( $batch_result['contacts'] ) ) {
			return array(
				'offset'   => $current_offset,
				'cursor'   => null,
				'status'   => 'completed',
				'total'    => $total,
				'imported' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'failures' => array(),
			);
		}

		foreach ( $batch_result['contacts'] as $subscriber ) {
			if ( Utils::is_memory_limit_reached() ) {
				break;
			}

			$result = $this->import_contact( $subscriber, $mapping );
			if ( false === $result ) {
				++$failed;
				if ( count( $failures ) < 10 ) {
					$failures[] = $this->describe_import_failure( $subscriber, $mapping );
				}
			} elseif ( 'skipped' === $result ) {
				++$skipped;
			} else {
				++$imported;
			}
			++$current_offset;
		}

		$cursor       = $batch_result['next_cursor'] ?? null;
		$this->cursor = $cursor;

		return array(
			'offset'   => $current_offset,
			'cursor'   => $cursor,
			'status'   => empty( $cursor ) || $current_offset >= $total ? 'completed' : 'in_progress',
			'total'    => $total,
			'imported' => $imported,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'failures' => $failures,
		);
	}
}
