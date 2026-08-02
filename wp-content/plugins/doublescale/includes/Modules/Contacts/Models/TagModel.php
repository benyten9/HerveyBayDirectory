<?php

/**
 * Class TagModel
 * This class is responsible for handling the tag model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\TaxonomyModel;

/**
 * TagModel class
 */
class TagModel extends TaxonomyModel {

	/**
	 * Boot model: unified `terms` table + global scope for tags.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		static::addGlobalScope(
			'taxonomy_tag',
			function ( $builder ) {
				$builder->where( ( new static() )->getTable() . '.type', 'tag' );
			}
		);
		parent::boot();
	}

	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table = 'doublescale_terms';

	/**
	 * Model name
	 *
	 * @var string
	 */
	protected $model_name = 'Tag';

	/**
	 * Model slug
	 *
	 * @var string
	 */
	protected $model_slug = 'tag';

	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required' => 'Tag name is required',
	);
}
