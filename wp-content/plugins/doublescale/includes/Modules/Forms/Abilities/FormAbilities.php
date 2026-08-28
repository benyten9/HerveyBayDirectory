<?php
/**
 * Read-only form abilities.
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;

/**
 * Forms here are connections to external form plugins (Contact Form 7, Fluent
 * Forms, Quill Forms, JotForm) plus the submissions they feed into the CRM.
 *
 * There is no write half by design, and for a different reason than elsewhere:
 * forms are filled in by the public, not by staff. An agent creating a
 * submission would be fabricating a record of something a person never did,
 * and editing a form definition is a settings change rather than CRM work.
 */
final class FormAbilities {

	/**
	 * Form connections are site-wide configuration, so access matches the form
	 * REST controllers: CRM Manager.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_crm_manager_access' );

		return array(
			'doublescale/list-forms'            => array(
				'module_slug'      => 'forms',
				'label'            => __( 'List forms', 'doublescale' ),
				'description'      => __( 'List connected forms with their source plugin, status, and submission count. Read-only: this never changes a form or its connection.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'    => array(
							'type'        => 'string',
							'description' => 'Filter by connection status.',
							'enum'        => array( 'active', 'inactive' ),
						),
						'form_type' => array(
							'type'        => 'string',
							'description' => 'Filter by source plugin, e.g. fluentforms, contactform7, quillforms, jotform.',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Match on form name.',
						),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'    => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_forms' ),
			),

			'doublescale/list-form-submissions' => array(
				'module_slug'      => 'forms',
				'label'            => __( 'List form submissions', 'doublescale' ),
				'description'      => __( 'List form submissions with the form and the contact created or matched. Use this to answer how many people submitted a form in a period.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'    => array(
							'type'        => 'integer',
							'description' => 'Only submissions for this connected form (the DoubleScale form id from list-forms).',
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only submissions from this contact.',
						),
						'from'       => array(
							'type'        => 'string',
							'description' => 'Only submissions on or after this date (YYYY-MM-DD).',
						),
						'to'         => array(
							'type'        => 'string',
							'description' => 'Only submissions on or before this date (YYYY-MM-DD).',
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
				'execute_callback' => array( self::class, 'list_form_submissions' ),
			),
		);
	}

	/**
	 * List connected forms.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_forms( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = FormModel::query();

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', sanitize_text_field( (string) $input['status'] ) );
		}

		if ( ! empty( $input['form_type'] ) ) {
			$query->where( 'form_type', sanitize_text_field( (string) $input['form_type'] ) );
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
			$items[] = array(
				'id'               => (int) $row->id,
				'name'             => (string) $row->name,
				'form_type'        => (string) $row->form_type,
				// The id inside the source plugin, which differs from ours.
				'external_form_id' => (string) $row->form_id,
				'status'           => (string) $row->status,
				'submissions'      => (int) FormSubmissionModel::query()
					->where( 'form_id', (int) $row->id )
					->count(),
				'created_at'       => (string) $row->created_at,
			);
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * List form submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_form_submissions( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = FormSubmissionModel::query()->with( array( 'form', 'contact' ) );

		if ( ! empty( $input['form_id'] ) ) {
			$query->where( 'form_id', (int) $input['form_id'] );
		}

		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		if ( ! empty( $input['from'] ) ) {
			$query->where( 'created_at', '>=', sanitize_text_field( (string) $input['from'] ) . ' 00:00:00' );
		}

		if ( ! empty( $input['to'] ) ) {
			$query->where( 'created_at', '<=', sanitize_text_field( (string) $input['to'] ) . ' 23:59:59' );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$form    = $row->form;
			$contact = $row->contact;

			$items[] = array(
				'id'           => (int) $row->id,
				'form'         => $form ? array(
					'id'   => (int) $form->id,
					'name' => (string) $form->name,
					'type' => (string) $form->form_type,
				) : null,
				'contact'      => $contact ? array(
					'id'    => (int) $contact->id,
					'email' => (string) $contact->email,
				) : null,
				'submitted_at' => (string) $row->created_at,
			);
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}
}
