<?php
/**
 * File uploads in DoubleScale forms (Pro): the visitor's browser uploads each file first and
 * gets a token; the submission then claims its tokens. Files sit in DoubleScale's protected
 * attachments folder and are only reachable through signed links.
 *
 * Unclaimed uploads (abandoned forms) are deleted after a day.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Form uploads.
 */
final class Uploads {

	public const TYPE = 'form_submission';

	/**
	 * Store one uploaded file for a form's file field.
	 *
	 * @param FormModel $form  Form.
	 * @param string    $key   Field key.
	 * @param array     $file  $_FILES entry.
	 * @return array|\WP_Error { token, name, size }
	 */
	public static function store( FormModel $form, string $key, array $file ) {
		if ( ! Schema::pro_active() ) {
			return new \WP_Error( 'pro_required', __( 'File uploads are not available.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$field = null;
		foreach ( Schema::input_fields( Schema::effective( $form )['schema'] ) as $candidate ) {
			if ( $candidate['key'] === $key && FieldTypes::get( $candidate )->is_upload() ) {
				$field = $candidate;
				break;
			}
		}
		if ( ! $field ) {
			return new \WP_Error( 'no_field', __( 'This form does not take files.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( RateLimiter::ready() && RateLimiter::hit( 'forms_upload_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > 30 ) {
			return new \WP_Error( 'rate_limited', __( 'Too many uploads. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}
		// And no more than about 200 MB an hour from one visitor, however the files are split.
		$size = (int) ( $file['size'] ?? 0 );
		$budget_key = 'dsf_upload_mb_' . md5( ClientIp::bucket() );
		$budget     = get_transient( $budget_key );
		$used       = is_array( $budget ) ? (int) $budget['mb'] : 0;
		$mb         = (int) ceil( max( 0, $size ) / MB_IN_BYTES );
		if ( $used + $mb > 200 ) {
			return new \WP_Error( 'rate_limited', __( 'Too many uploads. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}
		$until = is_array( $budget ) ? (int) $budget['until'] : time() + HOUR_IN_SECONDS;
		set_transient( $budget_key, array( 'mb' => $used + $mb, 'until' => $until ), max( 60, $until - time() ) );

		// Site-wide: files not yet attached to a sent form are capped (whatever the number of
		// visitors), and nothing is stored when the disk is nearly full.
		$pending = (int) AttachmentModel::where( 'attachable_type', self::TYPE )
			->where( 'status', 'temp' )
			->sum( 'file_size' );
		$cap     = (int) apply_filters( 'doublescale_forms_pending_upload_bytes', 1024 * MB_IN_BYTES );
		$uploads = wp_get_upload_dir();
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( (string) $uploads['basedir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on some hosts.
		if ( $pending + $size > $cap || ( false !== $free && $free - $size < 500 * MB_IN_BYTES ) ) {
			return new \WP_Error( 'busy', __( 'Files can’t be uploaded right now. Please try again later.', 'doublescale' ), array( 'status' => 503 ) );
		}

		$name    = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$allowed = self::allowed_extensions( $field );
		$ext     = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( $allowed && ! in_array( $ext, $allowed, true ) ) {
			/* translators: %s: allowed file types, e.g. "pdf, jpg" */
			return new \WP_Error( 'file_type', sprintf( __( 'Please upload one of these file types: %s.', 'doublescale' ), implode( ', ', $allowed ) ), array( 'status' => 400 ) );
		}

		$max_mb = (float) ( $field['props']['max_mb'] ?? 10 );
		$max    = (int) min( max( 1, $max_mb ) * MB_IN_BYTES, wp_max_upload_size() );

		$stored = ( new AttachmentService() )->store_upload(
			$file,
			self::TYPE,
			null,
			array(),
			array(
				'status'         => 'temp',
				'randomize_name' => true,
				'storage_subdir' => AttachmentService::DEFAULT_STORAGE_BASE . '/forms/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ),
				'max_size_bytes' => $max,
				'meta'           => array(
					'form_id' => (int) $form->id,
					'field'   => $key,
				),
			)
		);
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'token' => (string) $stored->file_hash,
			'name'  => (string) $stored->file_name,
			'size'  => (int) $stored->file_size,
		);
	}

	/**
	 * Claim a submission's uploads: tie them to the submission and contact, and return the
	 * file names (for the answers) and attachment ids (for the submission meta).
	 *
	 * @param FormModel $form       Form.
	 * @param int       $submission Submission id.
	 * @param int|null  $contact_id Contact id.
	 * @param string    $key        Field key.
	 * @param array     $tokens     Upload tokens.
	 * @return array{names: string[], ids: int[]}
	 */
	public static function claim( FormModel $form, int $submission, $contact_id, string $key, array $tokens ): array {
		$names = array();
		$ids   = array();
		if ( empty( $tokens ) ) {
			return compact( 'names', 'ids' );
		}
		$rows = AttachmentModel::whereIn( 'file_hash', $tokens )
			->where( 'attachable_type', self::TYPE )
			->whereNull( 'attachable_id' )
			->where( 'status', 'temp' )
			->get();
		foreach ( $rows as $row ) {
			$meta = is_array( $row->meta ) ? $row->meta : (array) json_decode( (string) $row->meta, true );
			if ( (int) ( $meta['form_id'] ?? 0 ) !== (int) $form->id || ( $meta['field'] ?? '' ) !== $key ) {
				continue;
			}
			$row->attachable_id = $submission;
			$row->contact_id    = $contact_id ? (int) $contact_id : null;
			$row->status        = 'active';
			$row->save();
			$names[] = (string) $row->file_name;
			$ids[]   = (int) $row->id;
		}
		return compact( 'names', 'ids' );
	}

	/**
	 * Delete the files of these submissions (or of this contact's form submissions), from
	 * the database and the disk. Uploaded files can hold personal data.
	 *
	 * @param int[] $submission_ids Submission ids.
	 * @param int   $contact_id     Or every form file of this contact.
	 * @return void
	 */
	public static function delete_files( array $submission_ids, int $contact_id = 0 ): void {
		if ( ! doublescale_table_exists( 'attachments' ) || ( empty( $submission_ids ) && $contact_id <= 0 ) ) {
			return;
		}
		$query = AttachmentModel::where( 'attachable_type', self::TYPE );
		if ( $contact_id > 0 ) {
			$query->where( 'contact_id', $contact_id );
		} else {
			$query->whereIn( 'attachable_id', array_map( 'intval', $submission_ids ) );
		}
		foreach ( $query->get() as $row ) {
			try {
				method_exists( $row, 'forceDelete' ) ? $row->forceDelete() : $row->delete();
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->warning( 'Form file could not be deleted', array( 'attachment_id' => (int) $row->id, 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * Files of a submission, with signed download links.
	 *
	 * @param int $submission Submission id.
	 * @return array
	 */
	public static function for_submission( int $submission ): array {
		if ( ! doublescale_table_exists( 'attachments' ) ) {
			return array();
		}
		$service = new AttachmentService();
		$out     = array();
		foreach ( AttachmentModel::where( 'attachable_type', self::TYPE )->where( 'attachable_id', $submission )->where( 'status', 'active' )->get() as $row ) {
			$meta  = is_array( $row->meta ) ? $row->meta : (array) json_decode( (string) $row->meta, true );
			$shape = $service->shape_for_api( $row );
			$shape['field'] = (string) ( $meta['field'] ?? '' );
			$out[]          = $shape;
		}
		return $out;
	}

	/**
	 * Allowed extensions from the field's "accept" setting (".pdf, .jpg" or "pdf jpg").
	 *
	 * @param array $field Field.
	 * @return string[] Empty = the attachment service's own allow-list.
	 */
	private static function allowed_extensions( array $field ): array {
		$accept = strtolower( (string) ( $field['props']['accept'] ?? '' ) );
		$out    = array();
		foreach ( preg_split( '/[\s,]+/', $accept ) as $item ) {
			$item = ltrim( trim( $item ), '.' );
			if ( '' !== $item && preg_match( '/^[a-z0-9]{1,8}$/', $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
