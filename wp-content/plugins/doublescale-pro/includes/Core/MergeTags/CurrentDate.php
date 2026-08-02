<?php
/**
 * Class CurrentDate
 *
 * Merge tag for current date
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Core\MergeTags;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Current Date Merge Tag
 */
class CurrentDate extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Current Date';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'current_date';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Current Date';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'general';

	/**
	 * Is automation merge tag
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Get Merge Tag Value
	 *
	 * @param ContactModel $contact Contact Model.
	 * @param string        $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		// Merge tag will be like this: {{current_date}} or {{current_date format='Y-m-d'}}.
		$format       = $this->get_format( $merge_tag );
		$current_date = wp_date( $format );

		return $current_date;
	}

	/**
	 * Get the format from the merge tag
	 *
	 * @param string $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	private function get_format( $merge_tag ) {
		$format  = 'Y-m-d';
		$matches = array();
		preg_match( '/format=\'(.*?)\'/', $merge_tag, $matches );
		if ( ! empty( $matches[1] ) ) {
			$format = $matches[1];
		}

		return $format;
	}
}

MergeTagsManager::instance()->register( new CurrentDate() );
