<?php
/**
 * Files the team keeps on a company (attachable_type `company`).
 *
 * They stay internal until the team turns on "Visible to client": then every
 * member whose portal shows Files sees them (free's PortalFileService lists
 * them). The flag lives in the attachment's meta, as on project and contact
 * files.
 *
 * @package DoubleScale\Pro\Modules\Companies\Services
 */

namespace DoubleScale\Pro\Modules\Companies\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Portal\Services\PortalFileService;
use DoubleScale\Modules\Portal\Services\PortalScope;
use WP_Error;

/**
 * CompanyFiles class
 */
class CompanyFiles {

	/** Attachment owner type. */
	public const TYPE = 'company';

	/** Largest file, like contact files. */
	public const MAX_BYTES = 10485760;

	/** Files one company may hold. */
	public const MAX_COUNT = 50;

	/**
	 * Store a team upload on the company.
	 *
	 * @param int                       $company_id Company.
	 * @param array<string, mixed>|null $file       PHP upload array.
	 * @param bool                      $visible    Show it in the members' portal now.
	 * @return AttachmentModel|WP_Error
	 */
	public static function upload( int $company_id, ?array $file, bool $visible ) {
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( AttachmentModel::forType( self::TYPE )->where( 'attachable_id', $company_id )->active()->count() >= self::MAX_COUNT ) {
			return new WP_Error(
				'too_many_files',
				/* translators: %d: maximum number of files on a company */
				sprintf( __( 'You can attach at most %d files to this company.', 'doublescale' ), self::MAX_COUNT ),
				array( 'status' => 400 )
			);
		}

		$attachment = ( new AttachmentService() )->store_upload(
			$file,
			self::TYPE,
			$company_id,
			array( 'user_id' => get_current_user_id() ),
			array(
				'status'         => 'active',
				'max_size_bytes' => self::MAX_BYTES,
				'meta'           => array( 'company_id' => $company_id ),
			)
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		if ( $visible ) {
			self::set_visible( $attachment, true );
		}
		return $attachment;
	}

	/**
	 * One of the company's files.
	 *
	 * @param int $company_id    Company.
	 * @param int $attachment_id Attachment.
	 * @return AttachmentModel|null
	 */
	public static function find( int $company_id, int $attachment_id ): ?AttachmentModel {
		$attachment = AttachmentModel::forType( self::TYPE )->where( 'attachable_id', $company_id )->where( 'id', $attachment_id )->first();
		return $attachment instanceof AttachmentModel ? $attachment : null;
	}

	/**
	 * Show or hide a company file in the members' portal; members are told
	 * the first time it shows.
	 *
	 * @param AttachmentModel $attachment Company file.
	 * @param bool            $visible    Show it.
	 * @return void
	 */
	public static function set_visible( AttachmentModel $attachment, bool $visible ): void {
		if ( PortalFileService::set_client_visible( $attachment, $visible ) ) {
			PortalFileService::notify_shared( $attachment, self::viewers( (int) $attachment->attachable_id ) );
		}
	}

	/**
	 * Members whose portal shows the company's files.
	 *
	 * @param int $company_id Company.
	 * @return int[]
	 */
	public static function viewers( int $company_id ): array {
		$ids = array();
		foreach ( CompanyService::members( $company_id ) as $member ) {
			$contact_id = (int) $member['contact_id'];
			if ( in_array( $company_id, PortalScope::company_ids( $contact_id, 'files' ), true ) ) {
				$ids[] = $contact_id;
			}
		}
		return $ids;
	}

	/**
	 * Remove every file of a deleted company (the files on disk too).
	 *
	 * @param int $company_id Company.
	 * @return void
	 */
	public static function delete_all( int $company_id ): void {
		foreach ( AttachmentModel::forType( self::TYPE )->where( 'attachable_id', $company_id )->get() as $attachment ) {
			$attachment->delete();
		}
	}
}
