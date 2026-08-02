<?php

namespace SEOPressPro\Core;

defined( 'ABSPATH' ) || exit;

abstract class FormApi {
	protected function getTypeByField( $field ) {
		return 'input';
	}

	protected function getLabelByField( $field ) {
		return '';
	}

	protected function getDescriptionByField( $field ) {
		return '';
	}

	protected function getPlaceholderByField( $field ) {
		return '';
	}

	protected function getOptions( $field ) {
		return array();
	}

	abstract protected function getDetails();

	public function getFields( $postId = null ) {
		$fields = $this->getDetails( $postId );

		foreach ( $fields as $key => $field ) {
			$fields[ $key ]['type']        = $this->getTypeByField( $field['key'] );
			$fields[ $key ]['label']       = $this->getLabelByField( $field['key'] );
			$fields[ $key ]['description'] = $this->getDescriptionByField( $field['key'] );
			$fields[ $key ]['placeholder'] = $this->getPlaceholderByField( $field['key'] );
			$fields[ $key ]['visible']     = true;

			if ( 'select' === $fields[ $key ]['type'] ) {
				$fields[ $key ]['options'] = $this->getOptions( $field['key'] );
			}
		}

		return $fields;
	}
}
