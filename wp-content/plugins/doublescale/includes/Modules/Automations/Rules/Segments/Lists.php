<?php

/**
 * Class Lists
 *
 * This class is responsible for handling the contact class lists rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Segments;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Lists class
 */
class Lists extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Lists';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lists_segment';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'segments';

	/**
	 * Is automation rule
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	public $is_automation = false;

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'lists';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'               => __( 'Is', 'doublescale' ),
			'is_not'           => __( 'Is not', 'doublescale' ),
			'contains'         => __( 'Contains', 'doublescale' ),
			'does_not_contain' => __( 'Does not contain', 'doublescale' ),
			'is_empty'         => __( 'Is empty', 'doublescale' ),
			'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
		);
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @param string $list_name The list name
	 *
	 * @return array
	 */
	public function get_options( $list_name = '' ) {
		$lists = array();

		if ( '' === $list_name ) {
			$lists = ListModel::paginate( 10, array( '*' ), 'page', 1 );
		} else {
			$lists = ListModel::where( 'name', 'LIKE', '%' . $list_name . '%' )->paginate( 10, array( '*' ), 'page', 1 );
		}

		return $lists;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;
		$ids     = $contact->lists->pluck( 'id' )->toArray();
		return $ids;
	}
}

RulesManager::instance()->register( new Lists() );
