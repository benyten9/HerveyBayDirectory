<?php

/**
 * Abstract Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Services\ActionsManager;

/**
 * Action class
 */
abstract class Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Auto enqueue step
	 *
	 * @var bool
	 */
	public $auto_enqueue = true;

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
	 * Merge Tags Manager
	 *
	 * @var MergeTagsManager
	 */
	public $merge_tags_manager;

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = false;

	/**
	 * Instances
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	private static $instances = array();

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array();

	/**
	 * Get Instance
	 *
	 * @since 1.0.0
	 *
	 * @return Action
	 */
	public static function instance() {
		$class = get_called_class();
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}


	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->attributes         = $this->get_attributes_schema();
		$this->merge_tags_manager = MergeTagsManager::instance();
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register
	 *
	 * @since 1.0.0
	 */
	public function register() {
		ActionsManager::instance()->register( $this );
	}

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel        $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	abstract public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact );

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
