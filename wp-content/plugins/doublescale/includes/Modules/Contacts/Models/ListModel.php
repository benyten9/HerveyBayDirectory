<?php

/**
 * Class ListModel
 * This class is responsible for handling the list model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\TaxonomyModel;

/**
 * ListModel class
 */
class ListModel extends TaxonomyModel {

	/**
	 * Fillable columns (extends taxonomy defaults).
	 *
	 * @var array
	 */
	protected $fillable = array(
		'type',
		'name',
		'slug',
		'description',
		'status',
		'is_public',
		'created_at',
		'updated_at',
	);

	/**
	 * Attribute casts.
	 *
	 * @var array
	 */
	protected $casts = array(
		'is_public' => 'boolean',
	);

	/**
	 * Boot model: unified `terms` table + global scope for lists.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		static::addGlobalScope(
			'taxonomy_list',
			function ( $builder ) {
				$builder->where( ( new static() )->getTable() . '.type', 'list' );
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
	protected $model_name = 'List';

	/**
	 * Model slug
	 *
	 * @var string
	 */
	protected $model_slug = 'list';


	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required' => 'List name is required',
	);
}
