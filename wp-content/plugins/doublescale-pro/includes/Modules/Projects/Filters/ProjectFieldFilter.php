<?php
/**
 * Contact filter for one project custom field ("CMS is WordPress").
 *
 * @package DoubleScale\Pro\Modules\Projects\Filters
 */

namespace DoubleScale\Pro\Modules\Projects\Filters;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Filters\ContactFields\Fields;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * Same operators, options and value matching as a contact custom field, run
 * against the project's value; the contacts kept are the projects' clients.
 */
class ProjectFieldFilter extends Fields {

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = ProjectAudience::GROUP;

	/**
	 * Constructor
	 *
	 * @param \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel $custom_field Project custom field.
	 */
	public function __construct( $custom_field ) {
		parent::__construct( $custom_field );
		$this->slug  = self::slug_for( (int) $custom_field->id );
		$this->group = ProjectAudience::GROUP;
	}

	/**
	 * Filter slug for a project custom field.
	 *
	 * @param int $field_id Custom field id.
	 * @return string
	 */
	public static function slug_for( int $field_id ): string {
		return 'project_field_' . $field_id;
	}

	/**
	 * Apply the rule to a contact query.
	 *
	 * @param Builder              $query  Contact query.
	 * @param array<string, mixed> $filter Filter rule.
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		$operator = isset( $filter['operator'] ) ? (string) $filter['operator'] : 'is';
		$value    = $filter['value'] ?? '';

		// An unfinished row is ignored, as for contact fields.
		if ( $this->operator_needs_value( $operator ) && $this->is_empty_filter_value( $value ) ) {
			return $query;
		}

		return ProjectAudience::apply(
			$query,
			$operator,
			function ( Builder $projects, string $project_operator ) use ( $value ) {
				parent::apply(
					$projects,
					array(
						'operator' => $project_operator,
						'value'    => $value,
					)
				);
			}
		);
	}
}
