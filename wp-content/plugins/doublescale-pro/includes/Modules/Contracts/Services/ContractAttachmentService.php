<?php
/**
 * Contract file upload adapter — delegates to the core attachment service.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AttachmentService as CoreAttachmentService;
use DoubleScale\Pro\Modules\Contracts\Models\ContractAttachmentModel;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use WP_Error;

/**
 * ContractAttachmentService class.
 */
final class ContractAttachmentService {

	/**
	 * @var CoreAttachmentService
	 */
	private $core;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->core = new CoreAttachmentService();
	}

	/**
	 * @return string[]
	 */
	public static function accepted_mimes(): array {
		$mimes = apply_filters( 'doublescale_sales_contract_attachment_mimes', CoreAttachmentService::accepted_mimes() );
		return is_array( $mimes ) ? array_values( array_filter( array_map( 'strval', $mimes ) ) ) : CoreAttachmentService::accepted_mimes();
	}

	/**
	 * @return int
	 */
	public static function max_file_size_bytes(): int {
		$max = (int) apply_filters( 'doublescale_sales_contract_attachment_max_bytes', 10 * 1024 * 1024 );
		return max( 0, $max );
	}

	/**
	 * @return int
	 */
	public static function max_files_per_contract(): int {
		$max = (int) apply_filters( 'doublescale_sales_contract_attachment_max_count', 20 );
		return max( 1, $max );
	}

	/**
	 * @return array{max_file_size_mb:int, max_file_size_bytes:int, max_file_count:int}
	 */
	public static function limits_payload(): array {
		$max_bytes  = self::max_file_size_bytes();
		$server_max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		if ( $server_max > 0 && $max_bytes > $server_max ) {
			$max_bytes = $server_max;
		}

		return array(
			'max_file_size_mb'    => max( 1, (int) round( $max_bytes / ( 1024 * 1024 ) ) ),
			'max_file_size_bytes' => $max_bytes,
			'max_file_count'      => self::max_files_per_contract(),
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return WP_Error|null
	 */
	public function guard_contract_file_count( ContractModel $contract ): ?WP_Error {
		$count = (int) ContractAttachmentModel::query()
			->forType( ContractAttachmentModel::ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $contract->id )
			->count();
		if ( $count >= self::max_files_per_contract() ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files */
					_n(
						'You can attach at most %d file to this contract.',
						'You can attach at most %d files to this contract.',
						self::max_files_per_contract(),
						'doublescale'
					),
					self::max_files_per_contract()
				),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $file     PHP upload array.
	 * @param ContractModel        $contract Contract.
	 * @param array<string, mixed> $uploader user_id and/or contact_id.
	 * @return ContractAttachmentModel|WP_Error
	 */
	public function store_upload( array $file, ContractModel $contract, array $uploader ) {
		$too_many = $this->guard_contract_file_count( $contract );
		if ( $too_many ) {
			return $too_many;
		}

		$subdir = 'doublescale-sales/contracts/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$result = $this->core->store_upload(
			$file,
			ContractAttachmentModel::ATTACHABLE_TYPE,
			(int) $contract->id,
			$uploader,
			array(
				'status'         => 'active',
				'storage_subdir' => $subdir,
				'max_size_bytes' => self::max_file_size_bytes(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return ContractAttachmentModel::find( (int) $result->id );
	}

	/**
	 * @param ContractAttachmentModel $attachment Attachment.
	 * @return array<string, mixed>
	 */
	public function shape_for_api( ContractAttachmentModel $attachment ): array {
		$shaped                = $this->core->shape_for_api( $attachment );
		$shaped['uploaded_by'] = $this->resolve_uploader_name( $attachment );
		return $shaped;
	}

	/**
	 * @param ContractAttachmentModel $attachment Attachment.
	 * @return string
	 */
	public function signed_url( ContractAttachmentModel $attachment ): string {
		return $this->core->signed_url( $attachment );
	}

	/**
	 * @param ContractAttachmentModel $attachment Attachment.
	 * @return string|null
	 */
	public function resolve_uploader_name( ContractAttachmentModel $attachment ): ?string {
		if ( ! empty( $attachment->user_id ) ) {
			if ( $attachment->relationLoaded( 'user' ) && $attachment->user ) {
				$name = trim( (string) $attachment->user->display_name );
				if ( '' !== $name ) {
					return $name;
				}
			}

			$user = get_userdata( (int) $attachment->user_id );
			if ( $user && $user->exists() ) {
				$name = trim( (string) $user->display_name );
				return '' !== $name ? $name : __( 'Staff', 'doublescale' );
			}

			return __( 'Staff', 'doublescale' );
		}

		if ( ! empty( $attachment->contact_id ) ) {
			if ( $attachment->relationLoaded( 'contact' ) && $attachment->contact ) {
				$contact = $attachment->contact;
				$name    = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
				if ( '' !== $name ) {
					return $name;
				}
				$email = trim( (string) ( $contact->email ?? '' ) );
				if ( '' !== $email ) {
					return $email;
				}
			}

			return __( 'Customer', 'doublescale' );
		}

		return null;
	}
}
