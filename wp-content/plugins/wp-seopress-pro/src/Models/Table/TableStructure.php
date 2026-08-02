<?php

namespace SEOPressPro\Models\Table;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableStructureInterface;

class TableStructure implements TableStructureInterface {

	protected $columns;

	public function __construct( $columns ) {
		// Ensure the columns array is valid
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			throw new \InvalidArgumentException( 'Invalid columns array provided to TableStructure' );
		}
		$this->columns = $columns;
	}

	public function getColumns() {
		return $this->columns;
	}
}
