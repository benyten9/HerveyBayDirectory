<?php

/**
 * Class RulesManager
 *
 * This class is responsible for handling the rules
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Automations\Abstracts\Rule;

/**
 * Rules class
 */
final class RulesManager {


	/**
	 * Registed rules
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $rules = array();

	/**
	 * Groups
	 *
	 * @var array
	 */
	protected $groups = array();

	/**
	 * @var RulesManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @since 1.0.0
	 *
	 * @return RulesManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->set_groups();
		add_action( 'init', array( $this, 'register_forms_rules' ) );
	}

	/**
	 * Register Forms Rules
	 */
	public function register_forms_rules() {
		if ( ! class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' )
			|| ! class_exists( '\DoubleScale\Pro\Modules\Automations\Rules\Forms\FormFieldRuleBackend' ) ) {
			return;
		}
		$forms = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms();
		foreach ( $forms as $form ) {
			$this->register( new \DoubleScale\Pro\Modules\Automations\Rules\Forms\FormFieldRuleBackend( $form ) );
		}
	}

	/**
	 * Set groups
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_groups() {
		$this->groups = array(
			'contact'                   => array(
				'name'  => __( 'Contact', 'doublescale' ),
				'key'   => 'contact',
				'rules' => array(),
			),
			'lead_scoring'              => array(
				'name'  => __( 'Lead Scoring', 'doublescale' ),
				'key'   => 'lead_scoring',
				'rules' => array(),
			),
			'contact_fields'            => array(
				'name'  => __( 'Contact Fields', 'doublescale' ),
				'key'   => 'contact_fields',
				'rules' => array(),
			),
			'segments'                  => array(
				'name'  => __( 'Segments', 'doublescale' ),
				'key'   => 'segments',
				'rules' => array(),
			),
			'user'                      => array(
				'name'  => __( 'User', 'doublescale' ),
				'key'   => 'user',
				'rules' => array(),
			),
			'automation'                => array(
				'name'  => __( 'Automation', 'doublescale' ),
				'key'   => 'automation',
				'rules' => array(),
			),
			'activity'                  => array(
				'name'  => __( 'Activity', 'doublescale' ),
				'key'   => 'activity',
				'rules' => array(),
			),
			'woocommerce_current_order' => array(
				'name'        => __( 'WooCommerce Current Order', 'doublescale' ),
				'key'         => 'woocommerce_current_order',
				'rules'       => array(),
				'triggers'    => array( 'wc_order_created', 'wc_order_completed', 'wc_order_refunded', 'wc_order_status_changed', 'wc_order_item_stock_reduced' ),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'woocommerce'               => array(
				'name'        => __( 'WooCommerce', 'doublescale' ),
				'key'         => 'woocommerce',
				'rules'       => array(),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'woocommerce_membership'    => array(
				'name'        => __( 'WooCommerce Membership', 'doublescale' ),
				'key'         => 'woocommerce_membership',
				'rules'       => array(),
				'triggers'    => array( 'wc_membership_created', 'wc_membership_status_changed' ),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce-memberships/woocommerce-memberships.php' ),
			),
			'woocommerce_whishlist'     => array(
				'name'        => __( 'WooCommerce Whishlist', 'doublescale' ),
				'key'         => 'woocommerce_whishlist',
				'rules'       => array(),
				'triggers'    => array( 'wc_user_adds_product_to_wishlist', 'wc_wishlist_item_on_sale', 'wc_wishlist_reminder' ),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce-wishlists/woocommerce-wishlists.php' ),
			),
			'woocommerce_subscription'  => array(
				'name'        => __( 'WooCommerce Subscription', 'doublescale' ),
				'key'         => 'woocommerce_subscription',
				'rules'       => array(),
				'triggers'    => array(
					'wc_subscription_created',
					'wc_subscription_status_changed',
					'wc_customer_before_card_expiry',
					'wc_subscription_renewal_payment_failed',
					'wc_subscription_renewal_payment_complete',
					'wc_subscription_trial_end',
					'wc_subscription_note_added',
					'wc_subscription_before_renewal',
					'wc_subscription_before_end',
				),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ),
			),
			'woocommerce_review'        => array(
				'name'        => __( 'WooCommerce Review', 'doublescale' ),
				'key'         => 'woocommerce_review',
				'rules'       => array(),
				'triggers'    => array( 'wc_review_received' ),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'cart'                      => array(
				'name'        => __( 'Cart', 'doublescale' ),
				'key'         => 'cart',
				'rules'       => array(),
				'triggers'    => array( 'wc_abandoned_cart_created' ),
				'is_disabled' => ! \doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'learndash'                 => array(
				'name'        => __( 'LearnDash', 'doublescale' ),
				'key'         => 'learn_dash',
				'rules'       => array(),
				'is_disabled' => ! \doublescale_is_plugin_active( 'sfwd-lms/sfwd_lms.php' ),
			),
			'tutorlms'                  => array(
				'name'        => __( 'Tutor LMS', 'doublescale' ),
				'key'         => 'tutorlms',
				'rules'       => array(),
				'is_disabled' => ! \doublescale_is_plugin_active( 'tutor/tutor.php' ),
			),
			'lifterlms'                 => array(
				'name'        => __( 'LifterLMS', 'doublescale' ),
				'key'         => 'lifterlms',
				'rules'       => array(),
				'is_disabled' => ! \doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ),
			),
			'learnpress'                => array(
				'name'        => __( 'LearnPress', 'doublescale' ),
				'key'         => 'learnpress',
				'rules'       => array(),
				'is_disabled' => ! \doublescale_is_plugin_active( 'learnpress/learnpress.php' ),
			),
			'memberpress'               => array(
				'name'        => __( 'MemberPress', 'doublescale' ),
				'key'         => 'memberpress',
				'rules'       => array(),
				'is_disabled' => ! defined( 'MEPR_PLUGIN_NAME' ),
			),
			'pmpro'                     => array(
				'name'        => __( 'Paid Memberships Pro', 'doublescale' ),
				'key'         => 'pmpro',
				'rules'       => array(),
				'triggers'    => array(
					'pmpro_checkout_completed',
					'pmpro_membership_level_changed',
					'pmpro_membership_cancelled',
					'pmpro_membership_expired',
					'pmpro_membership_expiring_soon',
					'pmpro_order_added',
					'pmpro_order_updated',
				),
				'is_disabled' => ! defined( 'PMPRO_VERSION' ),
			),
			'surecart_current_order'    => array(
				'name'        => __( 'SureCart Current Order', 'doublescale' ),
				'key'         => 'surecart_current_order',
				'rules'       => array(),
				'triggers'    => array( 'surecart_order_success', 'surecart_order_refunded' ),
				'is_disabled' => ! defined( 'SURECART_PLUGIN_FILE' ),
			),

			'submission'                => array(
				'name'  => __( 'Submissions', 'doublescale' ),
				'key'   => 'submission',
				'rules' => array(),
			),
			'deal'                      => array(
				'name'     => __( 'Deals', 'doublescale' ),
				'key'      => 'deal',
				'rules'    => array(),
				'triggers' => array( 'deal_owner_change', 'deal_value_change', 'deal_status_change', 'deal_stage_change' ),
			),
			'deal_fields'               => array(
				'name'     => __( 'Deal Fields', 'doublescale' ),
				'key'      => 'deal_fields',
				'rules'    => array(),
				'triggers' => array( 'deal_owner_change', 'deal_value_change', 'deal_status_change', 'deal_stage_change' ),
			),
			'task'                      => array(
				'name'        => __( 'Task', 'doublescale' ),
				'key'         => 'task',
				'rules'       => array(),
				'triggers'    => array(
					'task_created',
					'task_completed',
					'task_assigned',
					'task_status_changed',
					'task_overdue',
					'task_due_soon',
					'subtask_created',
					'subtask_completed',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
					|| ! doublescale_is_module_active( 'tasks' ),
			),
			'task_fields'               => array(
				'name'        => __( 'Task Fields', 'doublescale' ),
				'key'         => 'task_fields',
				'rules'       => array(),
				'triggers'    => array(
					'task_created',
					'task_completed',
					'task_assigned',
					'task_status_changed',
					'task_overdue',
					'task_due_soon',
					'subtask_created',
					'subtask_completed',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
					|| ! doublescale_is_module_active( 'tasks' ),
			),
			'project'                   => array(
				'name'        => __( 'Project', 'doublescale' ),
				'key'         => 'project',
				'rules'       => array(),
				'triggers'    => array(
					'project_created',
					'project_status_changed',
					'project_completed',
					'project_owner_changed',
					'project_due_soon',
					'project_overdue',
					'project_comment_posted',
					'project_converted_from_deal',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
					|| ! doublescale_is_module_active( 'projects' ),
			),
			'project_fields'            => array(
				'name'        => __( 'Project Fields', 'doublescale' ),
				'key'         => 'project_fields',
				'rules'       => array(),
				'triggers'    => array(
					'project_created',
					'project_status_changed',
					'project_completed',
					'project_owner_changed',
					'project_due_soon',
					'project_overdue',
					'project_comment_posted',
					'project_converted_from_deal',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
					|| ! doublescale_is_module_active( 'projects' ),
			),
			'support'                   => array(
				'name'        => __( 'Helpdesk', 'doublescale' ),
				'key'         => 'support',
				'rules'       => array(),
				'triggers'    => array(
					'ticket_created',
					'ticket_reply_added',
					'ticket_note_added',
					'ticket_status_changed',
					'ticket_priority_changed',
					'ticket_agent_assigned',
					'ticket_closed',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
					|| ! doublescale_is_module_active( 'support' ),
			),
			'proposal'                  => array(
				'name'        => __( 'Proposal', 'doublescale' ),
				'key'         => 'proposal',
				'rules'       => array(),
				'triggers'    => array(
					'proposal_sent',
					'proposal_accepted',
					'proposal_declined',
					'proposal_converted_to_invoice',
					'invoice_sent',
					'invoice_paid',
				),
				'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'documents' ) ),
			),
			'invoice'                   => array(
				'name'        => __( 'Invoice', 'doublescale' ),
				'key'         => 'invoice',
				'rules'       => array(),
				'triggers'    => array(
					'invoice_sent',
					'invoice_paid',
					'proposal_converted_to_invoice',
				),
				'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'documents' ) ),
			),
			'contract'                  => array(
				'name'        => __( 'Contract', 'doublescale' ),
				'key'         => 'contract',
				'rules'       => array(),
				'triggers'    => array(
					'contract_sent',
					'contract_signed',
				),
				'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'contracts' ) ),
			),
			'credit_note'               => array(
				'name'        => __( 'Credit Note', 'doublescale' ),
				'key'         => 'credit_note',
				'rules'       => array(),
				'triggers'    => array(
					'credit_note_sent',
					'credit_note_applied',
				),
				'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'credit_notes' ) ),
			),
		);

		if ( class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' ) ) {
			$forms = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms();
			foreach ( $forms as $form ) {
				$this->groups[ $form->slug ] = array(
					'name'        => $form->name,
					'rules'       => array(),
					'key'         => $form->slug,
					'triggers'    => array( $form->slug ),
					'is_disabled' => ! $form->is_enabled(),
				);
			}
		}
	}





	/**
	 * Register rule
	 *
	 * @since 1.0.0
	 *
	 * @param Rule $rule
	 *
	 * @throws Exception If trigger is not an instance of Trigger
	 * @return void
	 */
	public function register( Rule $rule ) {
		if ( ! $rule instanceof Rule ) {
			throw new Exception( 'Rule must be an instance of Rule' );
		}

		if ( isset( $this->rules[ $rule->slug ] ) ) {
			return;
		}

		$this->rules[ $rule->slug ]                           = $rule;
		$this->groups[ $rule->group ]['rules'][ $rule->slug ] = array(
			'name'              => $rule->name,
			'type'              => $rule->type,
			'operators'         => $rule->get_operators(),
			'options'           => $rule->get_options(),
			'required_triggers' => $rule->required_triggers,
			'is_automation'     => $rule->is_automation,
		);

		if ( ! empty( $rule->endpoint ) ) {
			$this->groups[ $rule->group ]['rules'][ $rule->slug ]['endpoint'] = $rule->endpoint;
		}
		if ( ! empty( $rule->settings ) && is_array( $rule->settings ) ) {
			$this->groups[ $rule->group ]['rules'][ $rule->slug ]['settings'] = $rule->settings;
		}
	}

	/**
	 * Get rules
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_rules() {
		return $this->rules;
	}

	/**
	 * Get rule
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 *
	 * @return Rule|null
	 */
	public function get_rule( $slug ) {
		if ( isset( $this->rules[ $slug ] ) ) {
			return $this->rules[ $slug ];
		}

		foreach ( $this->rules as $registered_slug => $rule ) {
			if ( substr( $registered_slug, -1 ) === '_' && strpos( $slug, $registered_slug ) === 0 ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Get groups
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_groups() {
		$groups = $this->groups;

		if ( function_exists( 'doublescale_filter_automation_rules_groups_for_modules' ) ) {
			$groups = doublescale_filter_automation_rules_groups_for_modules( $groups );
		}

		return $groups;
	}

	/**
	 * Get groups by slugs
	 *
	 * @since 1.0.0
	 *
	 * @param array $slugs
	 *
	 * @return array
	 */
	public function get_groups_by_slugs( $slugs ) {
		$groups = array();
		$all    = $this->get_groups();

		foreach ( $slugs as $slug ) {
			if ( isset( $all[ $slug ] ) ) {
				$groups[ $slug ] = $all[ $slug ];
			}
		}

		return $groups;
	}

	/**
	 * Get group by slug
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 *
	 * @return array
	 */
	public function get_group_by_slug( $slug ) {
		$all = $this->get_groups();

		return $all[ $slug ] ?? null;
	}
}
