<?php
/**
 * Minimal read-only model for template rows referenced by communication tracking.
 * Full template builder lives in the Pro plugin.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * Maps to `doublescale_templates` for tracking and automation step template linkage.
 */
class TrackingTemplateModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_templates';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array
	 */
	protected $fillable = array(
		'name',
		'type',
		'body',
		'settings',
		'hidden',
		'thumbnail',
		'category',
		'is_pro',
		'created_by',
		'created_at',
		'updated_at',
	);

	/**
	 * @var array
	 */
	protected $casts = array(
		'settings' => 'array',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @param mixed $value Raw integer value from database.
	 * @return string
	 */
	public function getTypeAttribute( $value ) {
		return CampaignChannel::to_string( (int) $value ) ?? 'email';
	}

	/**
	 * @param string|int $value Template type.
	 * @return void
	 */
	public function setTypeAttribute( $value ) {
		if ( is_int( $value ) ) {
			$this->attributes['type'] = $value;
			return;
		}
		$this->attributes['type'] = CampaignChannel::to_integer( $value ) ?? CampaignChannel::CHANNEL_EMAIL;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		$settings = is_array( $this->settings ) ? $this->settings : array();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * @param int $template_id Template ID.
	 * @return bool
	 */
	public static function is_used_in_tracking( $template_id ) {
		return CommunicationTrackingModel::where( 'template_id', $template_id )->exists();
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function tracking_records() {
		return $this->hasMany( CommunicationTrackingModel::class, 'template_id' );
	}
}
