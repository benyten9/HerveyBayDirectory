<?php
/**
 * Company: a client organisation whose member contacts share projects,
 * tickets, files and invoices in the client portal.
 *
 * @package DoubleScale\Pro\Modules\Companies\Models
 */

namespace DoubleScale\Pro\Modules\Companies\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * CompanyModel class
 */
class CompanyModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table = 'doublescale_companies';

	/**
	 * Fillable columns
	 *
	 * @var array
	 */
	protected $fillable = array(
		'name',
		'domain',
		'logo_id',
		'email',
		'phone',
		'website',
		'address',
		'registration_number',
		'tax_vat_number',
		'owner_user_id',
		'notes',
	);

	/**
	 * Attribute casts. Nullable ids stay uncast so NULL doesn't become 0.
	 *
	 * @var array
	 */
	protected $casts = array(
		'address' => 'array',
	);

	/**
	 * Member contacts, with their role.
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function contacts() {
		return $this->belongsToMany( ContactModel::class, 'doublescale_company_contact', 'company_id', 'contact_id' )
			->withPivot( 'role', 'created_at' );
	}

	/**
	 * The team member who owns the company.
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function owner() {
		return $this->belongsTo( UserModel::class, 'owner_user_id', 'ID' );
	}
}
