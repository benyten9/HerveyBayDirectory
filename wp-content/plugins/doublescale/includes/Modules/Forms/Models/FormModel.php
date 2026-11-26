<?php

/**
 * Class FormModel
 *
 * This class is responsible for handling the form model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

/**
 * FormModel class
 */
class FormModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_forms';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'name',
		'form_type',
		'form_id',
		'data',
		'status',
		'hash',
		'slug',
		'layout',
		'form_schema',
		'style',
		'settings',
		'version',
		'published_at',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'data'        => 'array',
		'form_schema' => 'array',
		'style'       => 'array',
		'settings'    => 'array',
		'version'     => 'integer',
	);

	/**
	 * Form type of forms built with DoubleScale's own builder.
	 */
	public const NATIVE_TYPE = 'doublescale';

	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array(
		'name' => 'required',
	);

	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required' => 'From name is required',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = true;

	/**
	 * Get form by form id
	 *
	 * @param int    $form_id Form ID
	 * @param string $form_type Form Type
	 * @param string $status Status
	 *
	 * @return FormModel
	 */
	public static function get_form_by_form_id( $form_id, $form_type, $status = 'active' ) {
		return self::where( 'form_id', $form_id )
			->where( 'form_type', $form_type )
			->where( 'status', $status )
			->firstOrFail();
	}

	/**
	 * Whether this form was built with DoubleScale's own builder (not a connected plugin).
	 *
	 * @return bool
	 */
	public function is_native(): bool {
		return self::NATIVE_TYPE === $this->form_type;
	}

	/**
	 * Native form by its public hash.
	 *
	 * @param string $hash Public id.
	 * @return self|null
	 */
	public static function find_native_by_hash( string $hash ) {
		if ( '' === $hash ) {
			return null;
		}
		return self::where( 'form_type', self::NATIVE_TYPE )->where( 'hash', $hash )->first();
	}

	/**
	 * Native form by its link slug.
	 *
	 * @param string $slug Link slug.
	 * @return self|null
	 */
	public static function find_native_by_slug( string $slug ) {
		if ( '' === $slug ) {
			return null;
		}
		return self::where( 'form_type', self::NATIVE_TYPE )->where( 'slug', $slug )->first();
	}

	/**
	 * A new public hash for a native form (12 lowercase letters and digits, unused).
	 *
	 * @return string
	 */
	public static function new_hash(): string {
		do {
			$hash = strtolower( wp_generate_password( 12, false, false ) );
		} while ( self::where( 'hash', $hash )->exists() );

		return $hash;
	}

	/**
	 * Submissions of this form.
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function submissions() {
		return $this->hasMany( FormSubmissionModel::class, 'form_id', 'id' );
	}

	/**
	 * A link slug no other form uses, made from the wanted slug or the name.
	 *
	 * @param string   $wanted  Wanted slug.
	 * @param int|null $exclude Form id that may keep it.
	 * @return string
	 */
	public static function unique_slug( string $wanted, $exclude = null ): string {
		$base = sanitize_title( $wanted );
		if ( '' === $base ) {
			$base = 'form';
		}
		$base = substr( $base, 0, 180 );
		$slug = $base;
		$n    = 2;
		while ( true ) {
			$query = self::where( 'form_type', self::NATIVE_TYPE )->where( 'slug', $slug );
			if ( $exclude ) {
				$query->where( 'id', '!=', (int) $exclude );
			}
			if ( ! $query->exists() ) {
				return $slug;
			}
			$slug = $base . '-' . $n++;
		}
	}
}
