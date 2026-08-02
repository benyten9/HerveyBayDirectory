<?php

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Coupon;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

class Coupon extends MergeTag {




	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Coupon';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'dynamic_id_';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Coupon';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'coupon';

	/**
	 * Constructor
	 *
	 * @param string $name Name.
	 * @param string $slug Slug.
	 */
	public function __construct( $name = 'Coupon', $slug = 'dynamic_id_' ) {
		$this->name = $name;
		$this->slug = $slug;
	}


	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model. Contact Model.
	 * @param string                 $merge_tag         Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$step_id = str_replace( 'dynamic_id_', '', $merge_tag );
		$step    = AutomationStepModel::find( $step_id );
		if ( ! $step ) {
			return '';
		}

		$automation_contact = AutomationContactModel::find( $contact->id );
		if ( ! $automation_contact ) {
			return '';
		}

		$coupon_codes = $automation_contact->get_data( 'coupon_codes', array() );
		if ( empty( $coupon_codes ) ) {
			return '';
		}

		foreach ( $coupon_codes as $coupon_code ) {
			if ( $coupon_code['step_id'] === $step->id ) {
				return $coupon_code['code'];
			}
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new Coupon() );
