<?php
/**
 * Read-only automation abilities.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * Automations are executable workflows, not records — which is exactly why
 * only the read half lives here.
 *
 * An agent editing a workflow does not change one row; it changes something
 * that will keep running by itself, against every contact that enters it, long
 * after the conversation ends. A single wrong edit amplifies. Reading one runs
 * nothing and enrolls nobody, so it carries none of that.
 */
final class AutomationAbilities {

	/**
	 * Automations are a site-wide configuration surface with no per-user owner
	 * column, so access matches the automation REST controllers: CRM Manager.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_crm_manager_access' );

		return array(
			'doublescale/list-automations' => array(
				'module_slug'      => 'automations',
				'label'            => __( 'List automations', 'doublescale' ),
				'description'      => __( 'List automation workflows with their trigger, status, and enrolled contact count. Read-only: this never creates, edits, or runs a workflow.', 'doublescale' ),
				'category'         => AbilityCategories::MARKETING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'  => array(
							'type'        => 'string',
							'description' => 'Filter by workflow status.',
							'enum'        => array( 'active', 'draft', 'inactive' ),
						),
						'trigger' => array(
							'type'        => 'string',
							'description' => 'Filter by trigger key, e.g. contact_subscribed.',
						),
						'search'  => array(
							'type'        => 'string',
							'description' => 'Match on workflow name.',
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_automations' ),
			),

			'doublescale/get-automation'   => array(
				'module_slug'      => 'automations',
				'label'            => __( 'Get automation', 'doublescale' ),
				'description'      => __( 'One automation workflow with its trigger and ordered steps. Step configuration — including email bodies — is omitted unless include_step_settings is true, so the default answers "what does this workflow do" without dumping its contents.', 'doublescale' ),
				'category'         => AbilityCategories::MARKETING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'                    => array(
							'type'        => 'integer',
							'description' => 'Automation id.',
						),
						// Step settings hold whole email bodies and branch
						// conditions. The shape of a workflow is the useful
						// answer; its contents are large and rarely needed.
						'include_step_settings' => array(
							'type'        => 'boolean',
							'description' => 'Include each step’s full configuration. Off by default to save tokens.',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_automation' ),
			),
		);
	}

	/**
	 * List automations.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_automations( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = AutomationModel::query();

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', sanitize_text_field( (string) $input['status'] ) );
		}

		if ( ! empty( $input['trigger'] ) ) {
			$query->where( 'trigger', sanitize_text_field( (string) $input['trigger'] ) );
		}

		if ( ! empty( $input['search'] ) ) {
			$query->where( 'name', 'LIKE', '%' . sanitize_text_field( (string) $input['search'] ) . '%' );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape( $row );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * One automation with its ordered steps.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_automation( $input ) {
		$input      = (array) $input;
		$automation = AutomationModel::find( (int) ( $input['id'] ?? 0 ) );

		if ( ! $automation ) {
			return AbilityResult::not_found( __( 'Automation not found.', 'doublescale' ) );
		}

		$out           = self::shape( $automation );
		$with_settings = ! empty( $input['include_step_settings'] );

		$steps = array();

		// Steps are soft-deleted by flipping status, and the rows stay behind.
		// Returning them would describe a workflow that no longer runs.
		$rows = $automation->steps()
			->where( 'status', '!=', 'deleted' )
			->orderBy( 'order', 'asc' )
			->get();

		foreach ( $rows as $step ) {
			$settings = self::decode_settings( $step->settings );

			$entry = array(
				'id'     => (int) $step->id,
				'order'  => (int) $step->order,
				'kind'   => (string) $step->type,
				'action' => (string) $step->action,
				// Authors give steps a human label in settings; it is the only
				// readable name a step has.
				'label'  => (string) ( $settings['_action_label'] ?? $step->action ),
			);

			if ( $with_settings ) {
				$entry['settings'] = $settings;
			}

			$steps[] = $entry;
		}

		$out['steps']      = $steps;
		$out['step_count'] = count( $steps );

		return $out;
	}

	/**
	 * Common automation fields.
	 *
	 * @since 1.0.0
	 *
	 * @param object $automation Automation row.
	 * @return array<string, mixed>
	 */
	private static function shape( $automation ): array {
		return array(
			'id'               => (int) $automation->id,
			'name'             => (string) $automation->name,
			'status'           => (string) $automation->status,
			'trigger'          => (string) $automation->trigger,
			'enrolled_contacts' => (int) $automation->contacts()->count(),
			'created_at'       => (string) $automation->created_at,
		);
	}

	/**
	 * Step settings are stored as JSON; hand back an array either way.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Raw settings value.
	 * @return array<string, mixed>
	 */
	private static function decode_settings( $settings ): array {
		if ( is_array( $settings ) ) {
			return $settings;
		}

		$decoded = json_decode( (string) $settings, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
