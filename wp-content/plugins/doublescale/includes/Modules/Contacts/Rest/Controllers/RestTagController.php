<?php

/**
 * REST Api: Tag Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Modules\Contacts\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestTaxonomyController;
use DoubleScale\Modules\Contacts\Models\TagModel;

/**
 * Tag Controller class
 */
class RestTagController extends RestTaxonomyController {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->rest_base     = 'tags';
		$this->model_class   = TagModel::class;
		$this->singular_name = 'Tag';
		$this->plural_name   = 'Tags';
	}
}
