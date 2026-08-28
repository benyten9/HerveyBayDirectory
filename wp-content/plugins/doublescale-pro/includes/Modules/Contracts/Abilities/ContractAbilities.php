<?php
/**
 * Read-only contract abilities.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;

/**
 * Gate 3 keys on `assigned_user_id`.
 *
 * Contracts are the one entity in the product with a soft delete — the
 * `is_trash` flag — so every query here must exclude trashed rows or an agent
 * will report deleted contracts as live ones.
 */
final class ContractAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( self::class, 'can_view_sales' );

		return array(
			'doublescale/list-contracts' => array(
				'module_slug'      => 'contracts',
				'label'            => __( 'List contracts', 'doublescale' ),
				'description'      => __( 'List contracts with subject, status, contact, value, and date range. Trashed contracts are excluded. If your sales scope is "own" you see only contracts assigned to you.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'     => array(
							'type'        => 'string',
							'description' => 'Filter by contract status.',
							'enum'        => ContractStatus::all(),
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only contracts for this contact.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_contracts' ),
			),

			'doublescale/get-contract'   => array(
				'module_slug'      => 'contracts',
				'label'            => __( 'Get contract', 'doublescale' ),
				'description'      => __( 'One contract with its subject, status, contact, value, dates, and signature state.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Contract id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_contract' ),
			),
		);
	}

	/**
	 * Gate 2 — the shared Sales view capability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_view_sales(): bool {
		return Capabilities::current_user_can( 'doublescale_view_sales' );
	}

	/**
	 * Whether the caller sees every contract or only their own.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Capabilities::can_manage_all_sales() || Capabilities::can_assign_sales_rep();
	}

	/**
	 * List contracts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_contracts( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		// Trashed contracts are soft-deleted, not gone — never surface them.
		$query = ContractModel::query()
			->with( array( 'contact' ) )
			->where( 'is_trash', 0 );

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$sees_all = self::sees_all();
		AbilityScope::apply( $query, 'assigned_user_id', $sees_all );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_contract( $row );
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( $sees_all ) )
		);
	}

	/**
	 * Get one contract.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_contract( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid contract id.', 'doublescale' ) );
		}

		$contract = ContractModel::query()
			->with( array( 'contact' ) )
			->where( 'id', $id )
			->where( 'is_trash', 0 )
			->first();

		if ( ! $contract ) {
			return AbilityResult::not_found( __( 'No contract found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$contract,
			'assigned_user_id',
			self::sees_all(),
			__( 'You do not have permission to access this contract.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		return self::shape_contract( $contract );
	}

	/**
	 * Shape a contract row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $contract Contract.
	 * @return array<string, mixed>
	 */
	private static function shape_contract( $contract ): array {
		$contact = $contract->contact ?? null;

		$contact_name = '';
		if ( is_object( $contact ) ) {
			$contact_name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
			if ( '' === $contact_name ) {
				$contact_name = (string) $contact->email;
			}
		}

		return array(
			'id'              => (int) $contract->id,
			'contract_number' => $contract->contract_number,
			'subject'         => $contract->subject,
			'status'          => $contract->status,
			'value'           => null !== $contract->contract_value ? (float) $contract->contract_value : null,
			'currency'        => CurrencyResolver::resolve( $contract ),
			'contact'         => is_object( $contact )
				? array(
					'id'    => (int) $contact->id,
					'name'  => $contact_name,
					'email' => $contact->email,
				)
				: null,
			'start_date'      => $contract->start_date,
			'end_date'        => $contract->end_date,
			'signed_at'       => $contract->signed_at ?? null,
		);
	}
}
