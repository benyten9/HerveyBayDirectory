<?php
/**
 * Abstract Trigger
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\PluginKernel;

/**
 * Trigger class
 */
abstract class Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source;

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group;

	/**
	 * Is PRO only
	 *
	 * @var bool
	 */
	public $is_pro = false;

	/**
	 * Highlight this trigger in the automation UI (recommended / powerful).
	 *
	 * @var bool
	 */
	public $is_featured = false;

	/**
	 * Optional in-product documentation shown when configuring the trigger.
	 *
	 * @return array{title?: string, intro?: string, steps?: array<int, string>, tip?: string}
	 */
	public function get_documentation() {
		return array();
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->attributes = $this->get_attributes_schema();
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function load_hooks();

	/**
	 * Process automations
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments
	 *
	 * @return void
	 */
	public function process( $args ) {
		if ( $this->is_pro ) {
			return;
		}
		try {
			$automations = AutomationModel::get_automations_by_trigger( $this->slug );
			foreach ( $automations as $automation ) {
				if ( ! $this->is_processable( $automation, $args ) ) {
					continue;
				}

				PluginKernel::instance()->automations_tasks->enqueue_async( 'process_automations', $automation, $args );
			}
		} catch ( Exception $e ) {
			// Log error
		}
	}

	/**
	 * Check if trigger should be processed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 * @param array           $args Arguments
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		return true;
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array();
	}

	/**
	 * Set automation attributes
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 *
	 * @return void
	 */
	public function set_settings( AutomationModel $automation ) {}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array();
	}
}
