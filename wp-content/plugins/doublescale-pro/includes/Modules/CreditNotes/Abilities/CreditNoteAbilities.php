<?php
/**
 * Read-only credit note abilities.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;

/**
 * Gate 3 keys on `sale_agent_user_id`, the same column invoices use.
 *
 * Credit notes are financial records: `amount_applied` tracks how much has been
 * credited against invoices, so the remaining balance is total minus applied
 * and is the figure a user actually asks about.
 */
final class CreditNoteAbilities {

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
			'doublescale/list-credit-notes' => array(
				'module_slug'      => 'credit_notes',
				'label'            => __( 'List credit notes', 'doublescale' ),
				'description'      => __( 'List credit notes with number, status, contact, total, and how much has been applied. Amounts carry their currency code. If your sales scope is "own" you see only your own.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'     => array(
							'type'        => 'string',
							'description' => 'Filter by credit note status.',
							'enum'        => CreditNoteStatus::all(),
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only credit notes for this contact.',
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
				'execute_callback' => array( self::class, 'list_credit_notes' ),
			),

			'doublescale/get-credit-note'   => array(
				'module_slug'      => 'credit_notes',
				'label'            => __( 'Get credit note', 'doublescale' ),
				'description'      => __( 'One credit note with its line items, totals, amount applied, remaining balance, and the invoice it relates to.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Credit note id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_credit_note' ),
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
	 * Whether the caller sees every credit note or only their own.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Capabilities::can_manage_all_sales() || Capabilities::can_assign_sales_rep();
	}

	/**
	 * List credit notes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_credit_notes( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = CreditNoteModel::query()->with( array( 'contact' ) );

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$sees_all = self::sees_all();
		AbilityScope::apply( $query, 'sale_agent_user_id', $sees_all );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'credit_note_date', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_credit_note( $row );
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
	 * Get one credit note.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_credit_note( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid credit note id.', 'doublescale' ) );
		}

		$note = CreditNoteModel::query()
			->with( array( 'contact' ) )
			->where( 'id', $id )
			->first();

		if ( ! $note ) {
			return AbilityResult::not_found( __( 'No credit note found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$note,
			'sale_agent_user_id',
			self::sees_all(),
			__( 'You do not have permission to access this credit note.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		$data = self::shape_credit_note( $note );

		$data['subtotal']   = (float) $note->subtotal;
		$data['total_tax']  = (float) $note->total_tax;
		$data['reason']     = $note->reason;
		$data['invoice_id'] = $note->invoice_id ? (int) $note->invoice_id : null;
		$data['line_items'] = self::shape_line_items( $note->line_items );

		return $data;
	}

	/**
	 * Shape a credit note row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $note Credit note.
	 * @return array<string, mixed>
	 */
	private static function shape_credit_note( $note ): array {
		$contact = $note->contact ?? null;

		$contact_name = '';
		if ( is_object( $contact ) ) {
			$contact_name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
			if ( '' === $contact_name ) {
				$contact_name = (string) $contact->email;
			}
		}

		$total   = (float) $note->total;
		$applied = (float) $note->amount_applied;

		return array(
			'id'                 => (int) $note->id,
			'credit_note_number' => $note->credit_note_number,
			'status'             => $note->status,
			'contact'            => is_object( $contact )
				? array(
					'id'    => (int) $contact->id,
					'name'  => $contact_name,
					'email' => $contact->email,
				)
				: null,
			'total'              => $total,
			'amount_applied'     => $applied,
			'remaining'          => $total - $applied,
			'currency'           => CurrencyResolver::resolve( $note ),
			'credit_note_date'   => $note->credit_note_date,
		);
	}

	/**
	 * Normalise stored line items.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $line_items Raw column value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function shape_line_items( $line_items ): array {
		if ( is_string( $line_items ) ) {
			$line_items = json_decode( $line_items, true );
		}
		if ( ! is_array( $line_items ) ) {
			return array();
		}

		$out = array();
		foreach ( $line_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'description' => $item['description'] ?? ( $item['name'] ?? '' ),
				'quantity'    => isset( $item['quantity'] ) ? (float) $item['quantity'] : null,
				'rate'        => isset( $item['rate'] ) ? (float) $item['rate'] : null,
				'amount'      => isset( $item['amount'] ) ? (float) $item['amount'] : null,
			);
		}

		return $out;
	}
}
