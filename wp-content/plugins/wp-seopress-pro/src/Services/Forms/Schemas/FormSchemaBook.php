<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;
use SEOPressPro\Helpers\Schemas\Book;

class FormSchemaBook extends FormApi {
	protected function getDetails( $post_id = null ) {
		return array_map( static function ( $key ) { return array( 'key' => $key ); }, array_values( Book::meta_keys() ) );
	}

	protected function getLabelByField( $field ) {
		$fields = Book::fields();
		return $fields[ substr( $field, strlen( Book::PREFIX ) ) ]['label'];
	}

	protected function getDescriptionByField( $field ) {
		$fields = Book::fields();
		return $fields[ substr( $field, strlen( Book::PREFIX ) ) ]['description'];
	}
}
