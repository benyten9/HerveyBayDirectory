<?php
/**
 * Class TemplateModel
 * This class is responsible for handling the template model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * TemplateModel class
 */
class TemplateModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_templates';

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
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'settings' => 'array',
		// Note: 'type' conversion handled by getTypeAttribute/setTypeAttribute accessors
	);

	/**
	 * Get template type as string for Api/frontend
	 *
	 * @param int $value Raw integer value from database
	 * @return string Template type string ('email', 'sms', 'whatsapp')
	 */
	public function getTypeAttribute( $value ) {
		// Convert integer from database to string for Api
		return CampaignChannel::to_string( $value ) ?? 'email';
	}

	/**
	 * Set template type from string to integer for database
	 *
	 * @param string|int $value Template type as string or integer
	 */
	public function setTypeAttribute( $value ) {
		// If it's already an integer, store it directly
		if ( is_int( $value ) ) {
			$this->attributes['type'] = $value;
			return;
		}

		// Convert string to integer for database storage
		$integer_value            = CampaignChannel::to_integer( $value );
		$this->attributes['type'] = $integer_value ?? CampaignChannel::CHANNEL_EMAIL;
	}

	/**
	 * Get subject from settings
	 *
	 * @param mixed $value Raw value (kept for compatibility, not used)
	 * @return string Subject from settings
	 */
	public function getSubjectAttribute( $value ) {
		$settings = is_array( $this->settings ) ? $this->settings : json_decode( $this->settings, true );
		return $settings['subject'] ?? '';
	}

	/**
	 * Set subject to settings
	 *
	 * @param string $value Subject value
	 */
	public function setSubjectAttribute( $value ) {
		$settings = is_array( $this->settings ) ? $this->settings : json_decode( $this->settings, true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['subject']          = $value;
		$this->attributes['settings'] = $settings;
	}

	/**
	 * Get preview_text from settings
	 *
	 * @param mixed $value Raw value (kept for compatibility, not used)
	 * @return string Preview text from settings
	 */
	public function getPreviewTextAttribute( $value ) {
		$settings = is_array( $this->settings ) ? $this->settings : json_decode( $this->settings, true );
		return $settings['preview_text'] ?? '';
	}

	/**
	 * Set preview_text to settings
	 *
	 * @param string $value Preview text value
	 */
	public function setPreviewTextAttribute( $value ) {
		$settings = is_array( $this->settings ) ? $this->settings : json_decode( $this->settings, true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['preview_text']     = $value;
		$this->attributes['settings'] = $settings;
	}

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
		'name.required'             => 'Template name is required',
		'settings.from_email.email' => 'From email is not a valid email',
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
	 * Get validation rules dynamically based on template type
	 *
	 * @return array
	 */
	public function getRules() {
		$rules = $this->rules;

		// Only validate from_email for email templates
		if ( $this->type === 'email' ) {
			$rules['settings.from_email'] = 'email';
		}

		return $rules;
	}

	/**
	 * Get tracking records that use this template
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function tracking_records() {
		return $this->hasMany( CommunicationTrackingModel::class, 'template_id' );
	}

	/**
	 * Get setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		return $this->settings[ $key ] ?? $default;
	}

	/**
	 * Check if template is used in any tracking record
	 * Shared helper to determine if template can be safely updated or needs new version
	 *
	 * @param int $template_id Template ID
	 * @return bool True if template has been used in any sent message
	 */
	public static function is_used_in_tracking( $template_id ) {
		return CommunicationTrackingModel::where( 'template_id', $template_id )->exists();
	}

	/**
	 * Create or update template
	 *
	 * @since 1.0.0
	 *
	 * @param int   $id ID.
	 * @param array $data Data.
	 *
	 * @return TemplateModel
	 */
	public static function createOrUpdate( $id, $data ) {
		$template = self::find( $id );

		if ( ! $template ) {
			$template = new self();
		}

		$template->fill( $data );
		$template->save();

		return $template;
	}

	/**
	 * Get WhatsApp ContentSid for Twilio Api
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_whatsapp_content_sid() {
		return $this->get_setting( 'external_id' );
	}

	/**
	 * Get WhatsApp template variables
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_whatsapp_variables() {
		return $this->get_setting( 'variables', array() );
	}

	/**
	 * Get WhatsApp template variable mappings
	 * Format: {"1": "{{contact:first_name}}", "2": "Order #123"}
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_whatsapp_variable_mappings() {
		return $this->get_setting( 'variable_mappings', array() );
	}

	/**
	 * Check if this is a WhatsApp Business template
	 * (approved template from Twilio, not a user-created template)
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_whatsapp_business_template() {
		return $this->category === 'whatsapp_business'
			&& ! empty( $this->get_setting( 'external_id' ) );
	}
}
