<?php
/**
 * Permission-filtered Directorist directory facts for builder readers.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Small internal adapter.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\DirectoristInspector;

/** Exposes only field identities and revisions needed for catalog eligibility. */
final class DirectoristDirectoryFactsReader implements DirectoryFactsReader {
	private DirectoristInspector $inspector;

	public function __construct( DirectoristInspector $inspector ) {
		$this->inspector = $inspector;
	}

	/** Return sanitized facts after the native Directorist authority check. */
	public function read( Context $context, int $directory_id ): object {
		$context->assert_current();
		$purpose     = $context->actor()->can( 'manage_options' ) ? 'management' : 'submission';
		$description = $this->inspector->describe( $context, $directory_id, $purpose );
		$field_ids   = array_map(
			static fn( object $field ): string => $field->field_id,
			$description->fields
		);
		$field_ids   = array_values( array_unique( $field_ids ) );
		sort( $field_ids, SORT_STRING );
		return (object) array(
			'directory_id'   => $directory_id,
			'directory_slug' => $description->directory->slug,
			'revision'       => $description->schema_hash,
			'field_ids'      => $field_ids,
			'fields'         => array_map(
				static fn( object $field ): object => (object) array(
					'field_id'          => $field->field_id,
					'widget'            => $field->widget,
					'type'              => $field->type,
					'required'          => $field->required,
					'editable'          => $field->editable,
					'admin_only'        => (bool) ( $field->admin_only ?? false ),
					'conditional_logic' => $field->conditional_logic,
				),
				$description->fields
			),
			'complete'       => self::structural_complete( $description ),
			'limitations'    => array_values( $description->limitations ),
		);
	}

	/** Option-list sampling does not make the separately read field structure incomplete. */
	private static function structural_complete( object $description ): bool {
		if ( $description->complete ) {
			return true;
		}
		if ( ! $description->fields || ! $description->limitations ) {
			return false;
		}
		// Builder facts deliberately expose no taxonomy option IDs. The exact
		// catalog/relationship validators still own every requested value. Keep
		// the sampling limitation visible while allowing unrelated structure.
		$option_limits = array( 'taxonomy_terms_truncated:categories', 'taxonomy_terms_truncated:locations', 'taxonomy_terms_truncated:tags' );
		return ! array_diff( $description->limitations, $option_limits );
	}
}
