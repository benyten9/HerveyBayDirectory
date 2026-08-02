<?php
/**
 * Class Merge Tag Manager
 *
 * This class is responsible for handling the merge tags
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Managers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Abstracts\Manager;
use DoubleScale\Modules\Booking\Traits\Singleton;

final class MergeTagsManager extends \DoubleScale\Modules\Booking\Abstracts\Manager {

	use \DoubleScale\Modules\Booking\Traits\Singleton;

	/**
	 * Merge Tag Groups
	 *
	 * @var array
	 */
	private $groups = array();

	/**
	 * Register Merge Tag
	 *
	 * @since 1.0.0
	 *
	 * @param MergeTag $merge_tag Merge Tag.
	 */
	public function register_merge_tag( MergeTag $merge_tag ) {
		$slug            = $merge_tag->slug;
		$merge_tag->slug = $merge_tag->group . '_' . $merge_tag->slug;
		parent::register( $merge_tag, MergeTag::class, 'slug' );

		$this->groups[ $merge_tag->group ]['mergeTags'][ $slug ] = array(
			'name'  => $merge_tag->name,
			'value' => "{{{$merge_tag->group}:{$slug}}}",
		);
	}

	/**
	 * Get Merge Tag
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Slug.
	 *
	 * @return MergeTag|null
	 */
	public function get_merge_tag( $slug ) {
		return $this->get_item( $slug );
	}

	/**
	 * Get Merge Tags
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_tags() {
		return $this->get_items();
	}

	/**
	 * Get Merge Tag Groups
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_groups() {
		return $this->groups;
	}

	/**
	 * Process Merge Tags
	 *
	 * @since 1.0.0
	 *
	 * @param string            $content Content.
	 * @param BookingModel|null $booking Booking Model.
	 *
	 * @return string
	 */
	public function process_merge_tags( $content, $booking ) {
		if ( ! is_string( $content ) || empty( $content ) ) {
			return '';
		}

		return preg_replace_callback(
			'/{{(.*?):(.*?)}}/',
			function ( $matches ) use ( $booking ) {
				$group   = $matches[1];
				$slug    = $matches[2];
				$options = array();

				// Extract options from the slug
				if ( preg_match_all( '/(\w+)="([^"]+)"/', $slug, $option_matches ) ) {
					foreach ( $option_matches[1] as $key => $option_name ) {
						$options[ $option_name ] = $option_matches[2][ $key ];
					}

					// Remove options from the slug
					$slug = strtok( $slug, ' ' );
				}

				// Get the merge tag instance by group and slug
				$merge_tag = $this->get_item( $group . '_' . $slug );

				if ( ! $merge_tag ) {
					return '';
				}

				return $merge_tag->get_value( $booking, $options );
			},
			$content
		);
	}
}
