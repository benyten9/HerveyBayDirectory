<?php

namespace SEOPressPro\Models\Table;

defined( 'ABSPATH' ) || exit;


interface TableInterface {


	/**
	 * @return string
	 */
	public function getName();

	public function getColumns();
}
