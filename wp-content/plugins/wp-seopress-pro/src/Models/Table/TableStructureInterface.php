<?php

namespace SEOPressPro\Models\Table;

defined( 'ABSPATH' ) || exit;


interface TableStructureInterface {


	/**
	 * @return array
	 */
	public function getColumns();
}
