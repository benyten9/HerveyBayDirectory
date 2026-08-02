<?php

namespace SEOPressPro\Models\Table;

defined( 'ABSPATH' ) || exit;

class TableColumn implements TableColumnInterface {

	protected $name;
	protected $type;
	protected $primaryKey;
	protected $index;
	protected $defaultValue;  // New default value property

	public function __construct( $name, $options = array() ) {
		$this->name         = $name;
		$this->type         = isset( $options['type'] ) ? $options['type'] : 'varchar(255)';
		$this->primaryKey   = isset( $options['primaryKey'] ) ? $options['primaryKey'] : false;
		$this->index        = isset( $options['index'] ) ? $options['index'] : false;
		$this->defaultValue = isset( $options['default'] ) ? $options['default'] : null; // Set default value if provided
	}

	public function getName() {
		return $this->name;
	}

	public function getType() {
		return $this->type;
	}

	public function getPrimaryKey() {
		return $this->primaryKey;
	}

	public function getIndex() {
		return $this->index;
	}

	public function getDefaultValue() {
		// Getter for default value
		return $this->defaultValue;
	}
}
