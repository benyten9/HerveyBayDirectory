<?php

namespace DoubleScale\Modules\Tracking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

class CommunicationTrackingMetaModel extends Model {

	protected $table       = 'doublescale_communication_tracking_meta';
	protected $primary_key = 'id';
	protected $fillable    = array(
		'communication_tracking_id',
		'meta_key',
		'meta_value',
	);
	protected $casts       = array(
		'communication_tracking_id' => 'integer',
		'meta_value'                => 'array',
	);

	public $timestamps = true;

	public function communication_tracking() {
		return $this->belongsTo( CommunicationTrackingModel::class, 'communication_tracking_id' );
	}

	/**
	 * Capture merge tags using pre-extracted keys (optimized approach)
	 *
	 * @param int                                                                         $communication_tracking_id Communication tracking ID
	 * @param array                                                                       $merge_tag_keys Pre-extracted merge tag keys
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model
	 * @return CommunicationTrackingMetaModel
	 */
	public static function capture_merge_tags_from_keys( $communication_tracking_id, $merge_tag_keys, $contact_or_automation_contact ) {
		$merge_tag_values = MergeTagsManager::instance()->get_merge_tag_values_for_keys(
			$merge_tag_keys,
			$contact_or_automation_contact
		);

		return self::create(
			array(
				'communication_tracking_id' => $communication_tracking_id,
				'meta_key'                  => 'merge_tags',
				'meta_value'                => $merge_tag_values,
			)
		);
	}

	/**
	 * Store sections IDs
	 *
	 * @param int   $communication_tracking_id Communication tracking ID
	 * @param array $sections_ids Sections IDs
	 * @return CommunicationTrackingMetaModel
	 */
	public static function store_sections_ids( $communication_tracking_id, $sections_ids ) {
		return self::create(
			array(
				'communication_tracking_id' => $communication_tracking_id,
				'meta_key'                  => 'sections_ids',
				'meta_value'                => $sections_ids,
			)
		);
	}

	/**
	 * Get meta value by key for a communication tracking record
	 *
	 * @param int    $communication_tracking_id Communication tracking ID
	 * @param string $meta_key Meta key
	 * @return mixed|null
	 */
	public static function get_meta_value( $communication_tracking_id, $meta_key ) {
		$meta = self::where( 'communication_tracking_id', $communication_tracking_id )
			->where( 'meta_key', $meta_key )
			->first();

		return $meta ? $meta->meta_value : null;
	}

	/**
	 * Get merge tags for a communication tracking record
	 *
	 * @param int $communication_tracking_id Communication tracking ID
	 * @return array
	 */
	public static function get_merge_tags( $communication_tracking_id ) {
		return self::get_meta_value( $communication_tracking_id, 'merge_tags' ) ?: array();
	}

	/**
	 * Get sections IDs for a communication tracking record
	 *
	 * @param int $communication_tracking_id Communication tracking ID
	 * @return array
	 */
	public static function get_sections_ids( $communication_tracking_id ) {
		return self::get_meta_value( $communication_tracking_id, 'sections_ids' ) ?: array();
	}

	/**
	 * Store WhatsApp template parameters
	 *
	 * @since 1.0.0
	 *
	 * @param int   $communication_tracking_id Communication tracking ID
	 * @param array $params Template parameter values (keyed by slot: {"1": "John", "2": "ORD-123"})
	 * @return CommunicationTrackingMetaModel
	 */
	public static function store_whatsapp_template_params( $communication_tracking_id, $params ) {
		return self::create(
			array(
				'communication_tracking_id' => $communication_tracking_id,
				'meta_key'                  => 'whatsapp_template_params',
				'meta_value'                => $params,
			)
		);
	}

	/**
	 * Get WhatsApp template parameters
	 *
	 * @since 1.0.0
	 *
	 * @param int $communication_tracking_id Communication tracking ID
	 * @return array
	 */
	public static function get_whatsapp_template_params( $communication_tracking_id ) {
		return self::get_meta_value( $communication_tracking_id, 'whatsapp_template_params' ) ?: array();
	}

	/**
	 * Render WhatsApp template with stored parameters
	 *
	 * @since 1.0.0
	 *
	 * @param int    $communication_tracking_id Communication tracking ID
	 * @param string $template_body Template body with {{1}}, {{2}} placeholders
	 * @return string Rendered content
	 */
	public static function render_whatsapp_template( $communication_tracking_id, $template_body ) {
		$params = self::get_whatsapp_template_params( $communication_tracking_id );

		if ( empty( $params ) ) {
			return $template_body;
		}

		// Replace {{1}}, {{2}}, etc. with stored values
		foreach ( $params as $slot => $value ) {
			$template_body = str_replace( '{{' . $slot . '}}', $value, $template_body );
		}

		return $template_body;
	}

	/**
	 * Store error information for a failed message
	 *
	 * @since 1.0.0
	 *
	 * @param int    $communication_tracking_id Communication tracking ID
	 * @param string $error_code Provider error code
	 * @param string $error_message Provider error message
	 * @return CommunicationTrackingMetaModel
	 */
	public static function store_error_info( $communication_tracking_id, $error_code, $error_message ) {
		// Check if error info already exists and update it, otherwise create
		$existing = self::where( 'communication_tracking_id', $communication_tracking_id )
			->where( 'meta_key', 'error_info' )
			->first();

		$error_data = array(
			'code'       => $error_code,
			'message'    => $error_message,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $existing ) {
			$existing->meta_value = $error_data;
			$existing->save();
			return $existing;
		}

		return self::create(
			array(
				'communication_tracking_id' => $communication_tracking_id,
				'meta_key'                  => 'error_info',
				'meta_value'                => $error_data,
			)
		);
	}

	/**
	 * Get error information for a communication tracking record
	 *
	 * @since 1.0.0
	 *
	 * @param int $communication_tracking_id Communication tracking ID
	 * @return array|null Array with 'code' and 'message' keys, or null if no error
	 */
	public static function get_error_info( $communication_tracking_id ) {
		return self::get_meta_value( $communication_tracking_id, 'error_info' );
	}

	/**
	 * Render template content using stored merge tag values
	 *
	 * @param int    $communication_tracking_id Communication tracking ID
	 * @param string $template_content Original template content
	 * @return string Rendered content with stored values
	 */
	public static function render_with_stored_values( $communication_tracking_id, $template_content ) {
		$merge_tags = self::get_merge_tags( $communication_tracking_id );

		if ( empty( $merge_tags ) ) {
			return $template_content;
		}

		// Use the same regex pattern as MergeTagsManager
		return preg_replace_callback(
			'/{{(.*?):(.*?)}}/',
			function ( $matches ) use ( $merge_tags ) {
				$group          = $matches[1];
				$slug           = $matches[2];
				$slug_parts     = explode( ' ', $slug );
				$merge_tag_slug = $slug_parts[0];
				$full_tag       = "{$group}:{$merge_tag_slug}";

				// Return stored value if it was captured. If a tag was never captured
				// (e.g. a footer-only tag), keep the original placeholder rather than
				// silently blanking it to an empty string — an empty value would produce
				// broken markup such as an unsubscribe <a href="">. Leaving the tag intact
				// also lets the broken-merge-tag fallback in click tracking recover it.
				return isset( $merge_tags[ $full_tag ] ) ? $merge_tags[ $full_tag ] : $matches[0];
			},
			$template_content
		);
	}
}
