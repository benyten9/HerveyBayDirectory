<?php

/**
 * Class Export
 *
 * This class is responsible for handling the export class
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Utils\Utils;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Filters\Process as Contact_Filters_Process;
use DoubleScale\Modules\Contacts\ImportExport\Security;
use WP_Error;
use Exception;

/**
 * Export class
 */
class Export {
	/**
	 * File id
	 *
	 * @var string
	 */
	protected $file_id;

	/**
	 * Offset
	 *
	 * @var int
	 */
	protected $offset;

	/**
	 * Fields
	 *
	 * @var array
	 */
	protected $fields;

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
	 * Filters
	 *
	 * @var array
	 */
	protected $filters;

	/**
	 * Constructor
	 *
	 * @param array $args
	 *
	 * @since 1.0.0
	 */
	public function __construct( $args ) {
		$this->max_execution_time = Utils::get_max_execution_time();
		$this->file_id            = $args['file_id'];
		$this->offset             = $args['offset'];
		$this->fields             = $args['fields'] ?? array(
			'first_name',
			'last_name',
			'email',
		);
		$this->filters            = $args['filters'] ?? array();
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
	 * Export
	 */
	public function export() {
		$this->start_time = microtime( true );
		if ( ! Security::prepare_upload_dir() ) {
			return new WP_Error( 'filesystem_error', 'Failed to create upload directory', array( 'status' => 500 ) );
		}

		$file_path            = Security::get_upload_file_path( 'doublescale-export-' . $this->file_id . '.csv' );
		$total_contacts_query = ContactModel::query();

		if ( ! empty( $this->filters ) ) {
			$filters_process = new Contact_Filters_Process( $total_contacts_query, $this->filters );
			$total_contacts  = $filters_process->filter()->count();
		} else {
			$total_contacts = $total_contacts_query->count();
		}

		$contact_fields = $this->get_contact_fields();
		$headers        = array_map(
			function ( $key ) use ( $contact_fields ) {
				return $contact_fields[ $key ]['name'];
			},
			$this->fields
		);

		// Check if file exists and open file handle for writing
		try {
			$is_new_file = ! file_exists( $file_path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV append; WP_Filesystem has no streaming append API.
			$handle = fopen( $file_path, $is_new_file ? 'w' : 'a' );

			if ( false === $handle ) {
				return new WP_Error( 'csv_create_error', __( 'Failed to create export file', 'doublescale' ), array( 'status' => 500 ) );
			}

			// Write headers for new file
			if ( $is_new_file ) {
				$this->write_csv_line( $handle, $headers );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'csv_create_error', __( 'Failed to create export file: ', 'doublescale' ) . $e->getMessage(), array( 'status' => 500 ) );
		}

		while ( $this->get_current_execution_time() < $this->max_execution_time && ! Utils::is_memory_limit_reached() ) {
			// Usleep is used to prevent the server from crashing
			usleep( 1000000 );

			$contacts_query = ContactModel::with( 'lists', 'tags' )
				->offset( $this->offset )
				->limit( 10 )
				->orderBy( 'created_at', 'desc' );

			if ( ! empty( $this->filters ) ) {
				$filters_process = new Contact_Filters_Process( $contacts_query, $this->filters );
				$contacts_query  = $filters_process->filter();
			}

			$contacts = $contacts_query->get();

			if ( $contacts->isEmpty() ) {
				break;
			}

			foreach ( $contacts as $contact ) {
				$contact_array = array();
				foreach ( $this->fields as $field ) {
					$contact_array[] = $this->get_field_value( $contact, $field );
				}

				try {
					$this->write_csv_line( $handle, $contact_array );
					++$this->offset;
				} catch ( Exception $e ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streaming CSV handle opened above.
					// Clean up partial file on error
					if ( file_exists( $file_path ) ) {
						wp_delete_file( $file_path );
					}
					return new WP_Error(
						'csv_write_error',
						__( 'Failed to write to export file: ', 'doublescale' ) . $e->getMessage(),
						array( 'status' => 500 )
					);
				}
			}

			if ( $this->offset >= $total_contacts ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streaming CSV handle opened above.

		$is_completed = $this->offset >= $total_contacts;

		// Fire export completed action when done.
		if ( $is_completed ) {
			/**
			 * Fires when a contact export completes.
			 *
			 * @since 1.0.0
			 *
			 * @param int $user_id         User who initiated the export.
			 * @param int $exported_count  Number of contacts exported.
			 * @param int $total           Total contacts processed.
			 */
			do_action( 'doublescale_export_completed', get_current_user_id(), $this->offset, $total_contacts );
		}

		return array(
			'total'  => $total_contacts,
			'offset' => $this->offset,
			'status' => $is_completed ? 'completed' : 'in_progress',
		);
	}

	/**
	 * Retrieve and format the field value.
	 *
	 * @param object $contact        The contact object.
	 * @param string $field          The field name.
	 * @return string The formatted field value.
	 */
	private function get_field_value( $contact, $field ) {
		$contact_fields = $this->get_contact_fields();
		if ( ! isset( $contact_fields[ $field ] ) ) {
			return '';
		}

		switch ( $field ) {
			case 'lists':
				return $contact->lists->pluck( 'name' )->implode( ', ' );
			case 'tags':
				return $contact->tags->pluck( 'name' )->implode( ', ' );
			case 'last_opened':
				return Utils::format_date( $contact->campaign_emails()->orderBy( 'opened_at', 'desc' )->first()->opened_at ?? '' );
			case 'last_clicked':
				return Utils::format_date( $contact->campaign_emails()->orderBy( 'clicked_at', 'desc' )->first()->clicked_at ?? '' );
			case 'last_sent':
				return Utils::format_date( $contact->campaign_emails()->orderBy( 'sent_at', 'desc' )->first()->sent_at ?? '' );
			case 'created_at':
			case 'updated_at':
				return Utils::format_date( $contact->$field );
			default:
				if ( $contact_fields[ $field ]['is_custom'] ?? false ) {
					return $contact->get_custom_field( $field );
				}
				return $contact->$field;
		}
	}

	/**
	 * Build flat field map (slug => [ 'name' => string, 'is_custom' => bool ]) from core Utils groups.
	 *
	 * @return array<string, array{name: string, is_custom?: bool}>
	 */
	private function get_contact_fields_flat_from_utils(): array {
		$flat = array();
		foreach ( Utils::get_contact_fields() as $group_id => $group ) {
			if ( empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
				continue;
			}
			$is_custom_group = ( $group_id !== 0 );
			foreach ( $group['fields'] as $slug => $def ) {
				if ( ! is_string( $slug ) && ! is_int( $slug ) ) {
					continue;
				}
				$key          = is_int( $slug ) ? (string) $slug : $slug;
				$flat[ $key ] = array(
					'name'      => $def['label'] ?? $def['name'] ?? $key,
					'is_custom' => $is_custom_group,
				);
			}
		}
		return $flat;
	}

	/**
	 * Get contact fields
	 *
	 * @return array
	 */
	public function get_contact_fields() {
		if ( class_exists( \DoubleScale\Core\Fields\ContactFields::class ) ) {
			$contact_fields = \DoubleScale\Core\Fields\ContactFields::instance()->get_fields();
		} else {
			$contact_fields = $this->get_contact_fields_flat_from_utils();
		}
		$contact_fields['lists']        = array(
			'name' => __( 'Lists', 'doublescale' ),
		);
		$contact_fields['tags']         = array(
			'name' => __( 'Tags', 'doublescale' ),
		);
		$contact_fields['created_at']   = array(
			'name' => __( 'Created At', 'doublescale' ),
		);
		$contact_fields['updated_at']   = array(
			'name' => __( 'Updated At', 'doublescale' ),
		);
		$contact_fields['last_opened']  = array(
			'name' => __( 'Last Open', 'doublescale' ),
		);
		$contact_fields['last_clicked'] = array(
			'name' => __( 'Last Click', 'doublescale' ),
		);
		$contact_fields['last_sent']    = array(
			'name' => __( 'Last Sent', 'doublescale' ),
		);

		return $contact_fields;
	}

	/**
	 * Write a Csv line with all fields enclosed in double quotes.
	 *
	 * This ensures all fields are quoted for better compatibility with
	 * various Csv readers (Excel, Google Sheets, etc.)
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle The file handle to write to.
	 * @param array    $fields The fields to write.
	 *
	 * @return int|false The number of bytes written, or false on failure.
	 */
	protected function write_csv_line( $handle, array $fields ) {
		$quoted_fields = array_map(
			function ( $value ) {
				// Ensure value is a string
				$value = (string) $value;
				// Escape any double quotes by doubling them (RFC 4180)
				$escaped = str_replace( '"', '""', $value );
				// Wrap in double quotes
				return '"' . $escaped . '"';
			},
			$fields
		);

		$line = implode( ',', $quoted_fields ) . "\n";

		return fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the streaming CSV handle opened above.
	}
}
