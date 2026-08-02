<?php

/**
 * Class Actions Manager
 * This class is responsible for handling the actions
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Actions class
 */
final class ActionsManager {






	/**
	 * Registed actions
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $actions = array();

	/**
	 * Sources
	 *
	 * @var array
	 */
	protected $sources = array();


	/**
	 * @var ActionsManager|null
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
	 * @return ActionsManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * constructor
	 */
	private function __construct() {
		$this->set_sources();
		add_action( 'doublescale_ready', array( $this, 'load_actions' ) );
	}

	/**
	 * Load actions
	 */
	public function load_actions() {
		/** @var Action[] $actions */
		$actions = apply_filters( 'doublescale_automation_actions', $this->actions );

		// Re-register actions after filter to update sources array
		// This allows Pro versions to properly register in the frontend
		foreach ( $actions as $action ) {
			// For actions coming from filter that aren't Action instances, instantiate them
			if ( ! isset( $this->actions[ $action->slug ] ) ) {
				$this->actions[ $action->slug ] = $action;
			}

			$this->ensure_action_group_metadata( $action->source, $action->group );

			// Update the sources array with the action's fields
			$this->store_action_in_sources( $action );
		}

		// Refresh integration is_disabled status after Pro plugin has registered integrations.
		// This is necessary because set_sources() runs before doublescale_ready,
		// so integrations registered on doublescale_ready (like Pro Slack) would show as disabled.
		$this->refresh_integration_status();
	}

	/**
	 * Refresh the is_disabled status for send_data integration groups.
	 * Called after doublescale_ready to pick up Pro-registered integrations.
	 *
	 * @since 1.0.0
	 */
	private function refresh_integration_status() {
		if ( ! isset( $this->sources['send_data']['groups'] ) ) {
			return;
		}

		foreach ( $this->sources['send_data']['groups'] as $group => $data ) {
			// Zapier and HTTP Request don't require integration setup
			if ( $group === 'zapier' || $group === 'http_request' ) {
				$this->sources['send_data']['groups'][ $group ]['is_disabled'] = false;
			} else {
				$this->sources['send_data']['groups'][ $group ]['is_disabled'] = ! IntegrationsManager::instance()->is_active( $group );
			}
		}
	}


	/**
	 * Register Action
	 *
	 * @since 1.0.0
	 *
	 * @param Action $action
	 * @return void
	 */
	public function register( Action $action ) {
		if ( isset( $this->actions[ $action->slug ] ) ) {
			return;
		}

		$this->actions[ $action->slug ] = $action;
		$this->ensure_action_group_metadata( $action->source, $action->group );
		$this->store_action_in_sources( $action );
	}

	/**
	 * Get Action
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @return Action
	 */
	public function get_action( $slug ) {
		if ( isset( $this->actions[ $slug ] ) ) {
			return $this->actions[ $slug ];
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
		/* translators: %s: action slug */
		throw new Exception( sprintf( esc_html__( 'Action %s not found', 'doublescale' ), esc_html( $slug ) ) );
	}

	/**
	 * Get Actions
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_actions() {
		return $this->actions;
	}

	/**
	 * Get sources
	 *
	 * @return array
	 */
	public function set_sources() {
		$this->sources = array(
			'crm'         => array(
				'label'  => __( 'CRM', 'doublescale' ),
				'groups' => array(
					'contact' => array(
						'label'   => __( 'Contact', 'doublescale' ),
						'actions' => array(),
					),
					'delay'   => array(
						'label'   => __( 'Delay', 'doublescale' ),
						'actions' => array(),
					),
				),
			),
			'sales'       => array(
				'label'  => __( 'Sales', 'doublescale' ),
				'groups' => array(
					'deal' => array(
						'label'       => __( 'Deal', 'doublescale' ),
						'actions'     => array(),
						'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
							|| ! doublescale_is_module_active( 'deals' ),
					),
				),
			),
			'support'     => array(
				'label'  => __( 'Helpdesk', 'doublescale' ),
				'groups' => array(
					'support' => array(
						'label'       => __( 'Ticket', 'doublescale' ),
						'actions'     => array(),
						'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
							|| ! doublescale_is_module_active( 'support' ),
					),
				),
			),
			'tasks'       => array(
				'label'  => __( 'Tasks', 'doublescale' ),
				'groups' => array(
					'task' => array(
						'label'       => __( 'Task', 'doublescale' ),
						'actions'     => array(),
						'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
							|| ! doublescale_is_module_active( 'tasks' ),
					),
				),
			),
			'ecommerce'   => array(
				'label' => __( 'E-commerce', 'doublescale' ),
				'tabs'  => array(
					'woocommerce' => array(
						'label'  => __( 'WooCommerce', 'doublescale' ),
						'groups' => array(
							'order'  => array(
								'label'       => __( 'Order', 'doublescale' ),
								'actions'     => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
							),
							'coupon' => array(
								'label'       => __( 'Coupon', 'doublescale' ),
								'actions'     => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
							),
						),
					),
				),
			),
			'wp'          => array(
				'label'  => __( 'WordPress', 'doublescale' ),
				'groups' => array(
					'user' => array(
						'label'   => __( 'User', 'doublescale' ),
						'actions' => array(),
					),
				),
			),
			'lms'         => array(
				'label'  => __( 'LMS', 'doublescale' ),
				'groups' => array(
					'learndash'  => array(
						'is_disabled' => ! doublescale_is_plugin_active( 'sfwd-lms/sfwd_lms.php' ),
						'label'       => __( 'LearnDash', 'doublescale' ),
						'actions'     => array(),
					),
					'tutorlms'   => array(
						'is_disabled' => ! doublescale_is_plugin_active( 'tutor/tutor.php' ),
						'label'       => __( 'Tutor LMS', 'doublescale' ),
						'actions'     => array(),
					),
					'lifterlms'  => array(
						'is_disabled' => ! doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ),
						'label'       => __( 'LifterLMS', 'doublescale' ),
						'actions'     => array(),
					),
					'learnpress' => array(
						'is_disabled' => ! doublescale_is_plugin_active( 'learnpress/learnpress.php' ),
						'label'       => __( 'LearnPress', 'doublescale' ),
						'actions'     => array(),
					),
				),
			),
			'membership'  => array(
				'label' => __( 'Membership', 'doublescale' ),
				'tabs'  => array(
					'memberpress' => array(
						'label'  => __( 'MemberPress', 'doublescale' ),
						'groups' => array(
							'memberpress' => array(
								'label'       => __( 'MemberPress', 'doublescale' ),
								'actions'     => array(),
								'is_disabled' => ! defined( 'MEPR_PLUGIN_NAME' ),
							),
						),
					),
					'pmpro'       => array(
						'label'  => __( 'Paid Memberships Pro', 'doublescale' ),
						'groups' => array(
							'pmpro' => array(
								'label'       => __( 'Paid Memberships Pro', 'doublescale' ),
								'actions'     => array(),
								'is_disabled' => ! defined( 'PMPRO_VERSION' ),
							),
						),
					),
				),
			),
			'email'       => array(
				'label'  => __( 'Email', 'doublescale' ),
				'groups' => array(
					'email' => array(
						'label'   => __( 'Email', 'doublescale' ),
						'actions' => array(),
					),
				),
			),
			'message'     => array(
				'label'  => __( 'Messaging', 'doublescale' ),
				'groups' => array(
					'sms'      => array(
						'label'   => __( 'Sms', 'doublescale' ),
						'actions' => array(),
					),
					'whatsapp' => array(
						'label'   => __( 'WhatsApp', 'doublescale' ),
						'actions' => array(),
					),
				),
			),
			'send_data'   => array(
				'label'  => __( 'Send Data', 'doublescale' ),
				'groups' => array(
					// 'activecampaign' => array(
					// 'label'   => __( 'ActiveCampaign', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'convertkit'     => array(
					// 'label'   => __( 'ConvertKit', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'drip'           => array(
					// 'label'   => __( 'Drip', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'getresponse'    => array(
					// 'label'   => __( 'GetResponse', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'hubspot'        => array(
					// 'label'   => __( 'Hubspot', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'keap'           => array(
					// 'label'   => __( 'Keap', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'klaviyo'        => array(
					// 'label'   => __( 'Klaviyo', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'mailchimp'      => array(
					// 'label'   => __( 'Mailchimp', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'mailerlite'     => array(
					// 'label'   => __( 'MailerLite', 'doublescale'),
					// 'actions' => array(),
					// ),
					// 'mautic'         => array(
					// 'label'   => __( 'Mautic', 'doublescale'),
					// 'actions' => array(),
					// ),
				'slack'            => array(
					'label'   => __( 'Slack', 'doublescale' ),
					'actions' => array(),
				),
					'zapier'       => array(
						'label'   => __( 'Zapier', 'doublescale' ),
						'actions' => array(),
					),
					'http_request' => array(
						'label'   => __( 'HTTP Request', 'doublescale' ),
						'actions' => array(),
					),
				),
			),
		);

		// Note: Presto Player contributes triggers only (see TriggersManager). It
		// has no actions, so no `video` action source is declared here — an empty
		// source would render as a permanently empty category in the builder.

		$this->sources = apply_filters( 'doublescale_automation_action_sources', $this->sources );
	}

	/**
	 * Get sources
	 *
	 * Disabled groups (module off, plugin missing) stay in the payload so the
	 * builder can show them with an enable/install tooltip — same as triggers.
	 *
	 * @return array
	 */
	public function get_sources() {
		return $this->sources;
	}

	/**
	 * Ensure a group row exists with a human-readable label before actions are attached.
	 *
	 * @param string $source    Action source key (e.g. send_data).
	 * @param string $group_slug Group/integration slug.
	 */
	private function ensure_action_group_metadata( $source, $group_slug ) {
		$tabbed_category = $this->resolve_tabbed_action_category( $source );
		if ( $tabbed_category ) {
			$groups_path = &$this->sources[ $tabbed_category ]['tabs'][ $source ]['groups'];
			if ( ! isset( $groups_path[ $group_slug ] ) ) {
				$groups_path[ $group_slug ] = array(
					'label'   => $this->resolve_action_group_label( $source, $group_slug ),
					'actions' => array(),
				);
				return;
			}

			if ( empty( $groups_path[ $group_slug ]['label'] ) ) {
				$groups_path[ $group_slug ]['label'] = $this->resolve_action_group_label( $source, $group_slug );
			}

			if ( ! isset( $groups_path[ $group_slug ]['actions'] ) ) {
				$groups_path[ $group_slug ]['actions'] = array();
			}

			return;
		}

		if ( ! isset( $this->sources[ $source ]['groups'][ $group_slug ] ) ) {
			$this->sources[ $source ]['groups'][ $group_slug ] = array(
				'label'   => $this->resolve_action_group_label( $source, $group_slug ),
				'actions' => array(),
			);
			return;
		}

		if ( empty( $this->sources[ $source ]['groups'][ $group_slug ]['label'] ) ) {
			$this->sources[ $source ]['groups'][ $group_slug ]['label'] = $this->resolve_action_group_label( $source, $group_slug );
		}

		if ( ! isset( $this->sources[ $source ]['groups'][ $group_slug ]['actions'] ) ) {
			$this->sources[ $source ]['groups'][ $group_slug ]['actions'] = array();
		}
	}

	/**
	 * Resolve display label for an action group (Send Data integrations, LMS vendors, etc.).
	 *
	 * @param string $source     Action source key.
	 * @param string $group_slug Group slug.
	 * @return string
	 */
	private function resolve_action_group_label( $source, $group_slug ) {
		if ( 'send_data' === $source ) {
			$static_labels = array(
				'zapier'       => __( 'Zapier', 'doublescale' ),
				'http_request' => __( 'HTTP Request', 'doublescale' ),
				'slack'        => __( 'Slack', 'doublescale' ),
			);
			if ( isset( $static_labels[ $group_slug ] ) ) {
				return $static_labels[ $group_slug ];
			}

			$options = IntegrationsManager::instance()->get_options();
			if ( ! empty( $options[ $group_slug ]['label'] ) ) {
				return $options[ $group_slug ]['label'];
			}
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $group_slug ) );
	}

	/**
	 * Resolve tabbed category key for an action source (e.g. woocommerce → ecommerce).
	 *
	 * @param string $source Action source key.
	 * @return string|null Category key when the source lives under tabs.
	 */
	private function resolve_tabbed_action_category( $source ) {
		$tabbed_sources = array(
			'ecommerce'  => array( 'woocommerce' ),
			'membership' => array( 'memberpress', 'pmpro' ),
		);

		foreach ( $tabbed_sources as $category_key => $source_keys ) {
			if ( in_array( $source, $source_keys, true ) ) {
				return $category_key;
			}
		}

		return null;
	}

	/**
	 * Merge one action into {@see $this->sources}.
	 *
	 * @param Action $action Action instance.
	 */
	private function store_action_in_sources( Action $action ): void {
		$row = array(
			'label'             => $action->name,
			'description'       => $action->description,
			'fields'            => $action->get_fields(),
			'is_integration'    => $action->is_integration,
			'required_triggers' => $action->required_triggers,
			'is_pro'            => $action->is_pro,
		);

		$tabbed_category = $this->resolve_tabbed_action_category( $action->source );
		if ( $tabbed_category ) {
			$this->sources[ $tabbed_category ]['tabs'][ $action->source ]['groups'][ $action->group ]['actions'][ $action->slug ] = $row;
			return;
		}

		$this->sources[ $action->source ]['groups'][ $action->group ]['actions'][ $action->slug ] = $row;
	}

}
