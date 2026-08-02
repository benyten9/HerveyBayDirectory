<?php
/**
 * Shared REST shaping for contracts.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Services\ContractUrl;
use DoubleScale\Pro\Modules\Contracts\Services\ContractContentMergeTags;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Core\Settings\Settings;

/**
 * ContractShaper class.
 */
final class ContractShaper {

	/**
	 * @param ContractModel $contract Contract.
	 * @param bool          $with_relations Include relations.
	 * @return array
	 */
	public static function shape_admin( ContractModel $contract, bool $with_relations = false ): array {
		$data = array(
			'id'                 => (int) $contract->id,
			'contract_number'    => (string) $contract->contract_number,
			'hash'               => (string) $contract->hash,
			'subject'            => (string) $contract->subject,
			'status'             => (string) $contract->status,
			'contact_id'         => (int) $contract->contact_id,
			'assigned_user_id'   => $contract->assigned_user_id ? (int) $contract->assigned_user_id : null,
			'contract_type_id'   => $contract->contract_type_id ? (int) $contract->contract_type_id : null,
			'contract_value'     => (float) $contract->contract_value,
			'currency'           => Settings::document_currency( $contract->currency, $contract->sent_at ),
			'start_date'         => $contract->start_date,
			'end_date'           => $contract->end_date,
			'description'        => $contract->description ? (string) $contract->description : '',
			'hide_from_customer' => (bool) $contract->hide_from_customer,
			'is_trash'           => (bool) $contract->is_trash,
			'sent_at'            => $contract->sent_at ? (string) $contract->sent_at : null,
			'viewed_at'          => $contract->viewed_at ? (string) $contract->viewed_at : null,
			'signed_name'        => $contract->signed_name ? (string) $contract->signed_name : null,
			'signed_at'          => $contract->signed_at ? (string) $contract->signed_at : null,
			'has_signature'      => ! empty( $contract->signature ),
			'is_expired'         => self::is_expired( $contract ),
			'is_about_to_expire' => self::is_about_to_expire( $contract ),
			'issuer_snapshot_raw' => $contract->issuer_snapshot ? (string) $contract->issuer_snapshot : null,
			'public_url'         => ContractUrl::get_public_url( $contract ),
			'created_at'         => $contract->created_at,
			'updated_at'         => $contract->updated_at,
		);

		if ( $with_relations ) {
			$contact = $contract->relationLoaded( 'contact' ) ? $contract->contact : null;
			if ( $contact ) {
				$data['contact'] = array(
					'id'                          => (int) $contact->id,
					'email'                       => (string) $contact->email,
					'first_name'                  => $contact->first_name,
					'last_name'                   => $contact->last_name,
					'company_name'                => $contact->company_name,
					'company_registration_number' => $contact->company_registration_number,
					'tax_vat_number'              => $contact->tax_vat_number,
				);
			}
			$agent = $contract->relationLoaded( 'assigned_user' ) ? $contract->assigned_user : null;
			if ( $agent ) {
				$data['assigned_user'] = array(
					'id'           => (int) $agent->ID,
					'display_name' => (string) $agent->display_name,
					'email'        => (string) $agent->user_email,
				);
			}
			$type = $contract->relationLoaded( 'type' ) ? $contract->type : null;
			if ( $type ) {
				$data['contract_type'] = array(
					'id'   => (int) $type->id,
					'name' => (string) $type->name,
				);
			}
		}

		return apply_filters( 'doublescale_sales_contract_admin_shape', $data, $contract );
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return array
	 */
	public static function shape_public( ContractModel $contract ): array {
		$is_expired = self::is_expired( $contract );
		$can_sign   = self::can_sign( $contract, $is_expired );

		return array(
			'contract_number'     => (string) $contract->contract_number,
			'subject'             => (string) $contract->subject,
			'status'              => (string) $contract->status,
			'contract_value'      => (float) $contract->contract_value,
			'currency'            => Settings::document_currency( $contract->currency, $contract->sent_at ),
			'start_date'          => $contract->start_date,
			'end_date'            => $contract->end_date,
			'description'         => self::resolved_description( $contract ),
			'contract_type'       => self::shape_type( $contract ),
			'is_expired'          => $is_expired,
			'can_sign'            => $can_sign,
			'require_signature'   => (bool) SalesSettings::get( 'require_signature_on_accept', true ),
			'signed_name'         => $contract->signed_name ? (string) $contract->signed_name : null,
			'signed_at'           => $contract->signed_at ? (string) $contract->signed_at : null,
			'has_signature'       => ! empty( $contract->signature ),
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @param bool|null     $is_expired Whether the contract is expired.
	 * @return bool
	 */
	public static function can_sign( ContractModel $contract, ?bool $is_expired = null ): bool {
		$is_expired = null === $is_expired ? self::is_expired( $contract ) : $is_expired;
		if ( $is_expired ) {
			return false;
		}

		return in_array(
			(string) $contract->status,
			array( ContractStatus::SENT, ContractStatus::ACTIVE ),
			true
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return bool
	 */
	public static function is_expired( ContractModel $contract ): bool {
		if ( ContractStatus::EXPIRED === (string) $contract->status ) {
			return true;
		}
		if ( empty( $contract->end_date ) ) {
			return false;
		}
		if ( ! in_array( (string) $contract->status, array( ContractStatus::DRAFT, ContractStatus::SENT, ContractStatus::SIGNED, ContractStatus::ACTIVE ), true ) ) {
			return false;
		}
		return (string) $contract->end_date < current_time( 'Y-m-d' );
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return bool
	 */
	public static function is_about_to_expire( ContractModel $contract ): bool {
		if ( empty( $contract->end_date ) ) {
			return false;
		}
		if ( ! in_array( (string) $contract->status, array( ContractStatus::SENT, ContractStatus::SIGNED, ContractStatus::ACTIVE ), true ) ) {
			return false;
		}
		$days = (int) SalesSettings::get( 'contract_expiry_reminder_days', 30 );
		if ( $days <= 0 ) {
			$days = 30;
		}
		$threshold = gmdate( 'Y-m-d', strtotime( '+' . $days . ' days', current_time( 'timestamp' ) ) );
		$today     = current_time( 'Y-m-d' );
		$end       = (string) $contract->end_date;
		return $end >= $today && $end <= $threshold;
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return array{id: int, name: string}|null
	 */
	private static function shape_type( ContractModel $contract ): ?array {
		$type = $contract->relationLoaded( 'type' ) ? $contract->type : null;
		if ( ! $type ) {
			return null;
		}
		return array(
			'id'   => (int) $type->id,
			'name' => (string) $type->name,
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return string
	 */
	public static function resolved_description( ContractModel $contract ): string {
		$description = $contract->description ? (string) $contract->description : '';
		return ContractContentMergeTags::resolve( $contract, $description );
	}
}
