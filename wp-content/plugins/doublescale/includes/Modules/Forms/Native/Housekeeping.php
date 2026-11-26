<?php
/**
 * Keeping form data tidy: spam older than 30 days goes, a deleted contact's submissions
 * go with them, and WordPress's privacy tools include submissions and consent records.
 *
 * Merging contacts moves submissions and consent rows (ContactMergeService).
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;

/**
 * Form data housekeeping.
 */
final class Housekeeping {

	public const GROUP = 'doublescale_forms';
	public const HOOK  = 'doublescale_forms_cleanup';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'doublescale_contact_deleting', array( self::class, 'forget_contact' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Daily clean-up schedule.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		$tasks = new Tasks( self::GROUP );
		$tasks->register_callback(
			self::HOOK,
			static function () {
				self::daily();
			}
		);
		if ( get_transient( 'doublescale_register_tasks_lock_forms_cleanup' ) ) {
			return;
		}
		set_transient( 'doublescale_register_tasks_lock_forms_cleanup', 1, MINUTE_IN_SECONDS );
		if ( function_exists( 'as_next_scheduled_action' ) && false === $tasks->get_next_timestamp( self::HOOK ) ) {
			$tasks->schedule_recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Delete spam and unfinished entries older than 30 days.
	 *
	 * @return void
	 */
	public static function daily(): void {
		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return;
		}
		// In batches, so a big backlog clears without one huge query.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$deleted = FormSubmissionModel::where( 'status', FormSubmissionModel::STATUS_SPAM )
				->where( 'created_at', '<', $cutoff )
				->limit( 5000 )
				->delete();
			if ( (int) $deleted < 5000 ) {
				break;
			}
		}

		// Unfinished entries: their resume links stop working after 30 days, so they go too.
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$deleted = FormSubmissionModel::where( 'status', FormSubmissionModel::STATUS_PARTIAL )
				->where( 'updated_at', '<', $cutoff )
				->limit( 5000 )
				->delete();
			if ( (int) $deleted < 5000 ) {
				break;
			}
		}

		// Files uploaded to forms that were never sent.
		if ( doublescale_table_exists( 'attachments' ) ) {
			( new \DoubleScale\Core\Services\AttachmentService() )->cleanup_stale_temp( Uploads::TYPE );
		}
	}

	/**
	 * A contact is being deleted: their submissions and consent records go too.
	 *
	 * @param mixed $contact Contact model.
	 * @return void
	 */
	public static function forget_contact( $contact ): void {
		$id = is_object( $contact ) ? (int) $contact->id : (int) $contact;
		if ( $id <= 0 ) {
			return;
		}
		global $wpdb;
		Uploads::delete_files( array(), $id );
		if ( doublescale_table_exists( 'form_submissions' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean-up of our own table.
			$wpdb->delete( $wpdb->prefix . 'doublescale_form_submissions', array( 'contact_id' => $id ) );
		}
		if ( doublescale_table_exists( 'consent_log' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean-up of our own table.
			$wpdb->delete( $wpdb->prefix . 'doublescale_consent_log', array( 'contact_id' => $id ) );
		}
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['doublescale-forms'] = array(
			'exporter_friendly_name' => __( 'DoubleScale form submissions', 'doublescale' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['doublescale-forms'] = array(
			'eraser_friendly_name' => __( 'DoubleScale form submissions', 'doublescale' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export a person's submissions.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$items   = array();
		$contact = doublescale_module_data_ready( 'forms' ) ? ContactModel::get_by_email( (string) $email ) : null;

		$per_page = 50;
		$rows     = $contact ? FormSubmissionModel::where( 'contact_id', $contact->id )->orderBy( 'id' )->forPage( (int) $page, $per_page )->get() : new \DoubleScale\Vendor\Illuminate\Support\Collection( array() );
		if ( 1 === (int) $page ) {
			// Entries with their email but no contact, on the first page.
			$rows = $rows->concat( self::unlinked_rows( (string) $email ) );
		}
		if ( ! $rows->count() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$forms    = FormModel::whereIn( 'id', $rows->pluck( 'form_id' )->filter()->unique()->all() )->pluck( 'name', 'id' );

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Form', 'doublescale' ),
					'value' => (string) ( $forms[ $row->form_id ] ?? '' ),
				),
				array(
					'name'  => __( 'Submitted', 'doublescale' ),
					'value' => (string) $row->created_at,
				),
			);
			$labels = (array) ( $row->meta['fields'] ?? array() );
			foreach ( (array) $row->answers as $key => $value ) {
				$field  = array(
					'type'    => (string) ( $labels[ $key ]['type'] ?? 'text' ),
					'label'   => (string) ( $labels[ $key ]['label'] ?? $key ),
					'options' => (array) ( $labels[ $key ]['options'] ?? array() ),
				);
				$data[] = array(
					'name'  => '' !== $field['label'] ? $field['label'] : (string) $key,
					'value' => FieldTypes::readable( $field, $value ),
				);
			}
			$items[] = array(
				'group_id'    => 'doublescale-form-submissions',
				'group_label' => __( 'Form submissions', 'doublescale' ),
				'item_id'     => 'doublescale-form-submission-' . $row->id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => ! $contact || FormSubmissionModel::where( 'contact_id', $contact->id )->count() <= (int) $page * $per_page,
		);
	}

	/**
	 * Submissions not linked to a contact whose answers hold this email address.
	 *
	 * @param string $email Email.
	 * @return \DoubleScale\Vendor\Illuminate\Support\Collection
	 */
	private static function unlinked_rows( string $email ) {
		$email = strtolower( trim( $email ) );
		if ( ! doublescale_module_data_ready( 'forms' ) || ! is_email( $email ) ) {
			return new \DoubleScale\Vendor\Illuminate\Support\Collection( array() );
		}
		global $wpdb;
		$needle = '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%';
		return FormSubmissionModel::whereNull( 'contact_id' )
			->where( 'answers', 'LIKE', $needle )
			->get()
			->filter(
				static function ( $row ) use ( $email ) {
					foreach ( (array) $row->answers as $value ) {
						if ( is_string( $value ) && strtolower( $value ) === $email ) {
							return true;
						}
					}
					return false;
				}
			)
			->values();
	}

	/**
	 * Erase a person's submissions and consent records.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase( $email ) {
		$contact = doublescale_module_data_ready( 'forms' ) ? ContactModel::get_by_email( (string) $email ) : null;
		$removed = false;
		if ( $contact ) {
			$count   = FormSubmissionModel::where( 'contact_id', $contact->id )->count();
			self::forget_contact( $contact );
			$removed = $count > 0;
		}
		// Entries with their email but no contact (spam, unfinished, forms that don't make
		// contacts) are theirs too.
		$unlinked = self::unlinked_rows( (string) $email );
		if ( $unlinked->count() ) {
			$ids = $unlinked->pluck( 'id' )->map( 'intval' )->all();
			Uploads::delete_files( $ids );
			FormSubmissionModel::whereIn( 'id', $ids )->delete();
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
