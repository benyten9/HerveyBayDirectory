<?php
/**
 * Contact filter: the client has a project in a given status.
 *
 * @package DoubleScale\Pro\Modules\Projects\Filters
 */

namespace DoubleScale\Pro\Modules\Projects\Filters;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * "Project status is In progress" keeps the clients of in-progress projects.
 */
class ProjectStatusFilter extends Filter {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'project_status';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = ProjectAudience::GROUP;

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'select';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->name = __( 'Project status', 'doublescale' );
	}

	/**
	 * Operators
	 *
	 * @return array<string, string>
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	/**
	 * Status options, keyed by status id.
	 *
	 * @return array<string, string>
	 */
	public function get_options() {
		if ( ! ProjectAudience::ready() ) {
			return array();
		}

		try {
			$statuses = ProjectStatusModel::query()->orderBy( 'position' )->get( array( 'id', 'name' ) );
		} catch ( \Throwable $e ) {
			return array();
		}

		$options = array();
		foreach ( $statuses as $status ) {
			$options[ (string) (int) $status->id ] = (string) $status->name;
		}

		return $options;
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
		$ids      = self::status_ids( $filter['value'] ?? '' );

		if ( array() === $ids ) {
			return $query;
		}

		return ProjectAudience::apply(
			$query,
			'is_not' === $operator ? 'is_not' : 'is',
			static function ( Builder $projects ) use ( $ids ) {
				$projects->whereIn( 'status_id', $ids );
			}
		);
	}

	/**
	 * Status ids picked in the rule: one id, a list, or {value: id} rows.
	 *
	 * @param mixed $value Rule value.
	 * @return int[]
	 */
	private static function status_ids( $value ): array {
		if ( is_array( $value ) && isset( $value['value'] ) ) {
			$value = $value['value'];
		}

		$ids = array();
		foreach ( (array) $value as $item ) {
			if ( is_array( $item ) && isset( $item['value'] ) ) {
				$item = $item['value'];
			}
			if ( is_numeric( $item ) && (int) $item > 0 ) {
				$ids[] = (int) $item;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
