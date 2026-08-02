<?php

/**
 * REST Api: List Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Modules\Contacts\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestTaxonomyController;
use DoubleScale\Modules\Contacts\Models\ListModel;
use WP_REST_Request;

/**
 * List Controller class
 */
class RestListController extends RestTaxonomyController {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->rest_base     = 'lists';
		$this->model_class   = ListModel::class;
		$this->singular_name = 'List';
		$this->plural_name   = 'Lists';
	}

	/**
	 * Schema for the list item.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		$schema['properties']['is_public'] = array(
			'description' => __( 'Whether the list appears on the subscription preference page.', 'doublescale' ),
			'type'        => 'boolean',
			'default'     => true,
		);

		return $schema;
	}

	/**
	 * Prepare item data for database.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return array
	 */
	protected function prepare_item_for_database( $request ) {
		$item = parent::prepare_item_for_database( $request );

		if ( null !== $request->get_param( 'is_public' ) ) {
			$item['is_public'] = rest_sanitize_boolean( $request->get_param( 'is_public' ) ) ? 1 : 0;
		}

		return $item;
	}
}
