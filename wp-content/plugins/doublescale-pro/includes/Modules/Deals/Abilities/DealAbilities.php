<?php
/**
 * Read-only deal and pipeline abilities.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Deals\Models\PipelineModel;
use DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel;
use DoubleScale\Pro\Modules\Deals\Services\DealManager;

/**
 * Pipeline questions are the archetypal CRM request, so this is the module an
 * agent reaches for most.
 *
 * Gate 3 keys on `owner_id`. Note the REST controller does NOT scope its list
 * endpoint (RestDealController::get_items passes filters straight to
 * DealManager) — these abilities scope regardless, and must not be "corrected"
 * to match the controller.
 */
final class DealAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_sales_rep_access' );

		return array(
			'doublescale/list-deals'       => array(
				'module_slug'      => 'deals',
				'label'            => __( 'List deals', 'doublescale' ),
				'description'      => __( 'List deals with title, value, stage, pipeline, contact, and expected close date. If your sales scope is "own" you see only deals you own — check get-context first.', 'doublescale' ),
				'category'         => AbilityCategories::DEALS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'      => array(
							'type'        => 'string',
							'description' => 'Deal status.',
							'enum'        => array( 'open', 'won', 'lost' ),
						),
						'pipeline_id' => array(
							'type'        => 'integer',
							'description' => 'Only deals in this pipeline. Use list-pipelines to find ids.',
						),
						'stage_id'    => array(
							'type'        => 'integer',
							'description' => 'Only deals in this stage.',
						),
						'contact_id'  => array(
							'type'        => 'integer',
							'description' => 'Only deals for this contact.',
						),
						'search'      => array(
							'type'        => 'string',
							'description' => 'Match on deal title.',
						),
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'      => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_deals' ),
			),

			'doublescale/get-deal'         => array(
				'module_slug'      => 'deals',
				'label'            => __( 'Get deal', 'doublescale' ),
				'description'      => __( 'One deal with its value, stage, pipeline, owner, contact, probability, and close dates.', 'doublescale' ),
				'category'         => AbilityCategories::DEALS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Deal id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_deal' ),
			),

			'doublescale/list-pipelines'   => array(
				'module_slug'      => 'deals',
				'label'            => __( 'List pipelines and stages', 'doublescale' ),
				'description'      => __( 'All sales pipelines with their stages in order. Call this to resolve a pipeline or stage name to an id before filtering deals.', 'doublescale' ),
				'category'         => AbilityCategories::DEALS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'list_pipelines' ),
			),

			'doublescale/get-deal-summary' => array(
				'module_slug'      => 'deals',
				'label'            => __( 'Get pipeline summary', 'doublescale' ),
				'description'      => __( 'Deal counts and total value grouped by stage, broken down per currency. Respects your sales scope: if it is "own", these are your deals only.', 'doublescale' ),
				'category'         => AbilityCategories::DEALS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'pipeline_id' => array(
							'type'        => 'integer',
							'description' => 'Restrict to one pipeline.',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_deal_summary' ),
			),

			'doublescale/create-deal'      => array(
				'module_slug'      => 'deals',
				'label'            => __( 'Create a deal', 'doublescale' ),
				'description'      => __( 'Create a deal in a pipeline stage for a contact. Call list-pipelines first to get valid pipeline and stage ids. Creating a deal can start an automation.', 'doublescale' ),
				'category'         => AbilityCategories::DEALS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'title'               => array(
							'type'        => 'string',
							'description' => 'What the deal is for.',
						),
						'contact_id'          => array(
							'type'        => 'integer',
							'description' => 'Contact the deal belongs to.',
						),
						'pipeline_id'         => array(
							'type'        => 'integer',
							'description' => 'Pipeline id from list-pipelines.',
						),
						'stage_id'            => array(
							'type'        => 'integer',
							'description' => 'Stage id from list-pipelines. Must belong to the pipeline.',
						),
						'value'               => array(
							'type'        => 'number',
							'description' => 'Deal value. Currency is set by the site, not here.',
						),
						'priority'            => array(
							'type'        => 'string',
							'description' => 'Deal priority.',
							'enum'        => array( 'low', 'medium', 'high' ),
						),
						'expected_close_date' => array(
							'type'        => 'string',
							'description' => 'Expected close date as YYYY-MM-DD.',
						),
					),
					'required'   => array( 'title', 'contact_id', 'pipeline_id', 'stage_id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'create_deal' ),
			),

			'doublescale/update-deal'      => array(
				'module_slug'      => 'deals',
				'label'            => __( 'Update a deal', 'doublescale' ),
				'description'      => __( 'Change a deal\'s title, value, stage, priority, or expected close date. Moving a deal between stages also updates its status and probability. Every update fires automation triggers for owner, value, and status changes, which can send email.', 'doublescale' ),
				'category'         => AbilityCategories::DEALS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'                  => array(
							'type'        => 'integer',
							'description' => 'Deal id.',
						),
						'title'               => array(
							'type'        => 'string',
							'description' => 'New title.',
						),
						'value'               => array(
							'type'        => 'number',
							'description' => 'New value.',
						),
						'stage_id'            => array(
							'type'        => 'integer',
							'description' => 'Move the deal to this stage. Status and probability follow the stage automatically.',
						),
						'priority'            => array(
							'type'        => 'string',
							'description' => 'New priority.',
							'enum'        => array( 'low', 'medium', 'high' ),
						),
						'expected_close_date' => array(
							'type'        => 'string',
							'description' => 'New expected close date as YYYY-MM-DD.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						// DealManager fires three automation hooks on EVERY
						// update — owner, value, and status changed.
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'update_deal' ),
			),
		);
	}

	/**
	 * Create a deal.
	 *
	 * Delegates to DealManager so pipeline/stage validation, currency freezing,
	 * and automation triggers behave exactly as they do in the admin. The
	 * manager swallows every failure and returns null, so the reason has to be
	 * reconstructed here.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_deal( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'title', 'contact_id', 'pipeline_id', 'stage_id' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
				AbilityInput::id( $input['pipeline_id'] ?? null, 'pipeline_id' ),
				AbilityInput::id( $input['stage_id'] ?? null, 'stage_id' ),
				AbilityInput::date( $input['expected_close_date'] ?? null, 'expected_close_date' ),
				AbilityInput::enum( $input['priority'] ?? null, array( 'low', 'medium', 'high' ), 'priority' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$check = self::assert_targets_exist(
			(int) $input['contact_id'],
			(int) $input['pipeline_id'],
			(int) $input['stage_id']
		);
		if ( $check ) {
			return $check;
		}

		$data = array(
			'title'       => (string) $input['title'],
			'contact_id'  => (int) $input['contact_id'],
			'pipeline_id' => (int) $input['pipeline_id'],
			'stage_id'    => (int) $input['stage_id'],
			'owner_id'    => get_current_user_id(),
		);

		if ( isset( $input['value'] ) ) {
			$data['value'] = (float) $input['value'];
		}
		if ( ! empty( $input['priority'] ) ) {
			$data['priority'] = (string) $input['priority'];
		}
		if ( ! empty( $input['expected_close_date'] ) ) {
			$data['expected_close_date'] = (string) $input['expected_close_date'];
		}

		$deal = DealManager::instance()->create_deal( $data );

		if ( ! $deal ) {
			return new \WP_Error(
				'doublescale_deal_not_created',
				__( 'The deal could not be created. Check that the stage belongs to the pipeline you named.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'created'  => true,
			'deal_id'  => (int) $deal->id,
			'title'    => $deal->title,
			'stage_id' => (int) $deal->stage_id,
			'status'   => $deal->status,
		);
	}

	/**
	 * Update a deal.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_deal( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
				AbilityInput::id( $input['stage_id'] ?? null, 'stage_id' ),
				AbilityInput::date( $input['expected_close_date'] ?? null, 'expected_close_date' ),
				AbilityInput::enum( $input['priority'] ?? null, array( 'low', 'medium', 'high' ), 'priority' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$deal = DealModel::query()->where( 'id', (int) $input['id'] )->first();
		if ( ! $deal ) {
			return AbilityResult::not_found( __( 'No deal found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$deal,
			'owner_id',
			self::sees_all(),
			__( 'This deal is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		$data = array();
		foreach ( array( 'title', 'priority', 'expected_close_date' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$data[ $field ] = (string) $input[ $field ];
			}
		}
		if ( isset( $input['value'] ) ) {
			$data['value'] = (float) $input['value'];
		}
		if ( isset( $input['stage_id'] ) ) {
			$data['stage_id'] = (int) $input['stage_id'];
		}

		if ( array() === $data ) {
			return array(
				'updated' => false,
				'deal_id' => (int) $deal->id,
				'message' => __( 'Nothing to change — supply at least one field to update.', 'doublescale' ),
			);
		}

		try {
			$updated = DealManager::instance()->update_deal( (int) $deal->id, $data );
		} catch ( \Throwable $e ) {
			// update_deal() throws on an invalid pipeline/stage pairing while
			// create_deal() returns null for the same class of problem. Normalise
			// both into an error the agent can act on.
			return new \WP_Error(
				'doublescale_deal_not_updated',
				__( 'The deal could not be updated. If you moved it to a new stage, check the stage belongs to the deal\'s pipeline.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $updated ) {
			return new \WP_Error(
				'doublescale_deal_not_updated',
				__( 'The deal could not be updated.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'updated'  => true,
			'deal_id'  => (int) $deal->id,
			'changed'  => array_keys( $data ),
			'status'   => $updated->status,
			'stage_id' => (int) $updated->stage_id,
		);
	}

	/**
	 * Confirm the contact, pipeline, and stage all exist and belong together.
	 *
	 * DealManager only checks these fields are PRESENT; a stage from a different
	 * pipeline throws deep inside it, and an unknown contact produces a deal
	 * pointing at nobody.
	 *
	 * @since 1.0.0
	 *
	 * @param int $contact_id  Contact id.
	 * @param int $pipeline_id Pipeline id.
	 * @param int $stage_id    Stage id.
	 * @return \WP_Error|null Null when everything resolves.
	 */
	private static function assert_targets_exist( int $contact_id, int $pipeline_id, int $stage_id ): ?\WP_Error {
		if ( ContactModel::query()->where( 'id', $contact_id )->count() < 1 ) {
			return AbilityResult::not_found(
				sprintf(
					/* translators: %d: contact id */
					__( 'No contact exists with id %d.', 'doublescale' ),
					$contact_id
				)
			);
		}

		if ( PipelineModel::query()->where( 'id', $pipeline_id )->count() < 1 ) {
			return AbilityResult::not_found(
				sprintf(
					/* translators: %d: pipeline id */
					__( 'No pipeline exists with id %d. Call list-pipelines for valid ids.', 'doublescale' ),
					$pipeline_id
				)
			);
		}

		$stage_in_pipeline = PipelineStageModel::query()
			->where( 'id', $stage_id )
			->where( 'pipeline_id', $pipeline_id )
			->count();

		if ( $stage_in_pipeline < 1 ) {
			return AbilityResult::not_found(
				sprintf(
					/* translators: 1: stage id, 2: pipeline id */
					__( 'Stage %1$d does not belong to pipeline %2$d. Call list-pipelines to see which stages each pipeline has.', 'doublescale' ),
					$stage_id,
					$pipeline_id
				)
			);
		}

		return null;
	}

	/**
	 * Whether the caller sees every deal or only their own.
	 *
	 * Mirrors the rule enforced inside DealManager::update_deal(): a plain
	 * Sales Rep is confined to deals they own.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Permissions::has_sales_manager_access();
	}

	/**
	 * List deals.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_deals( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = DealModel::query()->with( array( 'contact', 'stage', 'pipeline' ) );

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['pipeline_id'] ) ) {
			$query->where( 'pipeline_id', (int) $input['pipeline_id'] );
		}
		if ( ! empty( $input['stage_id'] ) ) {
			$query->where( 'stage_id', (int) $input['stage_id'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$query->where( 'title', 'LIKE', '%' . $search . '%' );
		}

		// Applied last so no caller filter can widen it.
		$sees_all = self::sees_all();
		AbilityScope::apply( $query, 'owner_id', $sees_all );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_deal( $row );
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
	 * Get one deal.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_deal( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid deal id.', 'doublescale' ) );
		}

		$deal = DealModel::query()
			->with( array( 'contact', 'stage', 'pipeline', 'owner' ) )
			->where( 'id', $id )
			->first();

		if ( ! $deal ) {
			return AbilityResult::not_found( __( 'No deal found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$deal,
			'owner_id',
			self::sees_all(),
			__( 'This deal is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		$data = self::shape_deal( $deal );

		$owner               = $deal->owner ?? null;
		$data['owner']       = is_object( $owner )
			? array(
				'id'   => (int) $deal->owner_id,
				'name' => $owner->display_name,
			)
			: null;
		$data['probability'] = null !== $deal->probability ? (int) $deal->probability : null;
		$data['source']      = $deal->source;
		$data['lost_reason'] = $deal->lost_reason;
		$data['won_time']    = $deal->won_time;
		$data['lost_time']   = $deal->lost_time;

		return $data;
	}

	/**
	 * Pipelines with their ordered stages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_pipelines( array $input ): array {
		unset( $input );

		$pipelines = PipelineModel::query()->with( array( 'stages' ) )->get();

		$out = array();
		foreach ( $pipelines as $pipeline ) {
			// NOT (array) — casting an Eloquent Collection exposes its internal
			// `items` property as a single element instead of iterating rows.
			$stages = array();
			foreach ( ( $pipeline->stages ?? array() ) as $stage ) {
				if ( ! is_object( $stage ) ) {
					continue;
				}
				$stages[] = array(
					'id'              => (int) $stage->id,
					'name'            => $stage->name,
					'win_probability' => isset( $stage->win_probability ) ? (int) $stage->win_probability : null,
				);
			}

			$out[] = array(
				'id'     => (int) $pipeline->id,
				'name'   => $pipeline->name,
				'stages' => $stages,
			);
		}

		return array( 'pipelines' => $out );
	}

	/**
	 * Deal counts and value by stage, per currency.
	 *
	 * Grouped by currency because summing mixed-currency deals produces a
	 * figure that is wrong in every currency.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_deal_summary( array $input ): array {
		$query = DealModel::query()->with( array( 'stage' ) );

		if ( ! empty( $input['pipeline_id'] ) ) {
			$query->where( 'pipeline_id', (int) $input['pipeline_id'] );
		}

		$sees_all = self::sees_all();
		AbilityScope::apply( $query, 'owner_id', $sees_all );

		$by_currency = array();
		$total_count = 0;

		foreach ( $query->get() as $deal ) {
			$currency = (string) ( $deal->currency ?? '' );
			$stage    = is_object( $deal->stage ?? null )
				? (string) ( $deal->stage->name )
				: '';
			$status   = (string) $deal->status;

			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = array(
					'currency'  => $currency,
					'count'     => 0,
					'value'     => 0.0,
					'by_stage'  => array(),
					'by_status' => array(),
				);
			}

			++$by_currency[ $currency ]['count'];
			$by_currency[ $currency ]['value'] += (float) $deal->value;
			++$total_count;

			if ( '' !== $stage ) {
				if ( ! isset( $by_currency[ $currency ]['by_stage'][ $stage ] ) ) {
					$by_currency[ $currency ]['by_stage'][ $stage ] = array(
						'count' => 0,
						'value' => 0.0,
					);
				}
				++$by_currency[ $currency ]['by_stage'][ $stage ]['count'];
				$by_currency[ $currency ]['by_stage'][ $stage ]['value'] += (float) $deal->value;
			}

			if ( ! isset( $by_currency[ $currency ]['by_status'][ $status ] ) ) {
				$by_currency[ $currency ]['by_status'][ $status ] = array(
					'count' => 0,
					'value' => 0.0,
				);
			}
			++$by_currency[ $currency ]['by_status'][ $status ]['count'];
			$by_currency[ $currency ]['by_status'][ $status ]['value'] += (float) $deal->value;
		}

		return array(
			'currencies'    => array_values( $by_currency ),
			'total_deals'   => $total_count,
			'scope'         => AbilityScope::label( $sees_all ),
			'currency_note' => __( 'Figures are grouped by currency and must not be added together across currencies.', 'doublescale' ),
		);
	}

	/**
	 * Shape a deal row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $deal Deal.
	 * @return array<string, mixed>
	 */
	private static function shape_deal( $deal ): array {
		$contact = $deal->contact ?? null;
		$stage   = $deal->stage ?? null;

		$contact_name = '';
		if ( is_object( $contact ) ) {
			$contact_name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
			if ( '' === $contact_name ) {
				$contact_name = (string) $contact->email;
			}
		}

		return array(
			'id'                  => (int) $deal->id,
			'title'               => $deal->title,
			'status'              => $deal->status,
			'value'               => (float) $deal->value,
			'currency'            => $deal->currency,
			'priority'            => $deal->priority,
			'stage'               => is_object( $stage )
				? array(
					'id'   => (int) $deal->stage_id,
					'name' => $stage->name,
				)
				: null,
			'pipeline'            => is_object( $deal->pipeline ?? null )
				? array(
					'id'   => (int) $deal->pipeline_id,
					'name' => $deal->pipeline->name,
				)
				: null,
			'contact'             => is_object( $contact )
				? array(
					'id'    => (int) $contact->id,
					'name'  => $contact_name,
					'email' => $contact->email,
				)
				: null,
			'expected_close_date' => $deal->expected_close_date,
			'created_at'          => $deal->created_at,
		);
	}
}
