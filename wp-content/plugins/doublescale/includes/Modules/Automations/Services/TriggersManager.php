<?php

/**
 * Class Triggers Manager
 * This class is responsible for handling the triggers
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
use DoubleScale\Core\Settings\PhoneAsWhatsappSetting;
use DoubleScale\Modules\Automations\Abstracts\Trigger;

/**
 * Triggers class
 */
final class TriggersManager {


	/**
	 * Registed triggers
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $triggers = array();

	/**
	 * Sources
	 *
	 * @var array
	 */
	protected $sources = array();

	/**
	 * @var TriggersManager|null
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
	 * @return TriggersManager
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
		$this->set_forms_sources();
		// After {@see FormsManager::load_forms()} (priority 5 on doublescale_ready) so
		// `set_forms_sources()` inside `load_triggers()` sees registered form integrations.
		add_action( 'doublescale_ready', array( $this, 'load_triggers' ), 10 );
	}

	/**
	 * Load triggers
	 */
	public function load_triggers() {
		// Forms (and other Pro extensions) register on module boot / doublescale_ready.
		// Rebuild form trigger sources here so they are not missed when this singleton
		// was constructed before the Pro `forms` module finished booting.
		$this->set_forms_sources();

		/** @var Trigger[] $triggers */
		// Pro add-on merges catalog triggers on `doublescale_automation_triggers` (late priority): each catalog
		// instance uses the same `slug` as the free stub (e.g. form integrations: contactform7,
		// fluentforms, …), so the filtered array replaces TriggerPro definitions with real Trigger hooks.
		$triggers = apply_filters( 'doublescale_automation_triggers', $this->triggers );

		// Re-register triggers after filter to update sources array
		// This allows Pro versions to properly replace free versions in the frontend
		foreach ( $triggers as $slug => $trigger ) {
			// Update the trigger in the internal array
			$this->triggers[ $slug ] = $trigger;

			$this->store_trigger_in_sources( $trigger );

			if ( ! $trigger->is_pro ) {
				$trigger->load_hooks();
			}
		}
	}

	/**
	 * Register trigger
	 *
	 * @param Trigger $trigger
	 *
	 * @throws Exception If trigger is not an instance of Trigger
	 * @return void
	 */
	public function register( $trigger ) {
		if ( ! $trigger instanceof Trigger ) {
			throw new Exception(
				esc_html( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
					__( 'Invalid trigger', 'doublescale' )
				)
			);
		}

		if ( isset( $this->triggers[ $trigger->slug ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new Exception(
				esc_html( /* translators: %s: trigger name */
					sprintf( __( 'Trigger %s already registered', 'doublescale' ), $trigger->name )
				)
			);
		}

		$this->triggers[ $trigger->slug ] = $trigger;
		$this->store_trigger_in_sources( $trigger );
	}

	/**
	 * Get trigger
	 *
	 * @param string $slug
	 *
	 * @return Trigger
	 */
	public function get_trigger( $slug ) {
		return isset( $this->triggers[ $slug ] ) ? $this->triggers[ $slug ] : null;
	}

	/**
	 * Get all triggers
	 *
	 * @return array
	 */
	public function get_all_triggers() {
		return $this->triggers;
	}

	/**
	 * Get sources
	 *
	 * @return array
	 */
	public function set_sources() {
		$this->sources = array(
			'modules'     => array(
				'label' => __( 'DoubleScale', 'doublescale' ),
				'tabs'  => array(
					'crm'           => array(
						'label'  => __( 'Contacts', 'doublescale' ),
						'groups' => array(
							'contact' => array(
								'label'    => __( 'Contact', 'doublescale' ),
								'triggers' => array(),
							),
						),
					),
					'messaging'     => array(
						'label'  => __( 'Messaging', 'doublescale' ),
						'groups' => array(
							'messaging' => array(
								'label'    => __( 'Messaging', 'doublescale' ),
								'triggers' => array(),
							),
						),
					),
					'sales'         => array(
						'label'  => __( 'Sales', 'doublescale' ),
						'groups' => array(
							'sales'        => array(
								'label'       => __( 'Proposals & Invoices', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'documents' ) ),
							),
							'contracts'    => array(
								'label'       => __( 'Contracts', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'contracts' ) ),
							),
							'credit_notes' => array(
								'label'       => __( 'Credit Notes', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_automation_modules_available( array( 'sales', 'credit_notes' ) ),
							),
							'deal'         => array(
								'label'       => __( 'Deal', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'deals' ),
							),
						),
					),
					'projects'      => array(
						'label'  => __( 'Projects', 'doublescale' ),
						'groups' => array(
							'project'    => array(
								'label'       => __( 'Project', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'projects' ),
							),
							'discussion' => array(
								'label'       => __( 'Discussion', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'projects' ),
							),
						),
					),
					'booking'       => array(
						'label'  => __( 'Booking', 'doublescale' ),
						'groups' => array(
							'booking' => array(
								'label'       => __( 'Booking', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'booking' ),
							),
						),
					),
					'tasks'         => array(
						'label'  => __( 'Tasks', 'doublescale' ),
						'groups' => array(
							'task'    => array(
								'label'       => __( 'Task', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'tasks' ),
							),
							'subtask' => array(
								'label'       => __( 'Subtask', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'tasks' ),
							),
						),
					),
					'support'       => array(
						'label'  => __( 'Helpdesk', 'doublescale' ),
						'groups' => array(
							'support' => array(
								'label'       => __( 'Helpdesk', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! function_exists( 'doublescale_is_module_active' )
									|| ! doublescale_is_module_active( 'support' ),
							),
						),
					),
					'link_triggers' => array(
						'label'  => __( 'Link Triggers', 'doublescale' ),
						'groups' => array(
							'link_triggers' => array(
								'label'    => __( 'Link Triggers', 'doublescale' ),
								'triggers' => array(),
							),
						),
					),
				),
			),
			'webhooks'    => array(
				'label'  => __( 'Webhooks', 'doublescale' ),
				'groups' => array(
					'webhooks' => array(
						'label'    => __( 'Webhooks', 'doublescale' ),
						'triggers' => array(),
					),
				),
			),
			'ecommerce'   => array(
				'label' => __( 'E-commerce', 'doublescale' ),
				'tabs'  => array(
					'woocommerce' => array(
						'label'       => __( 'WooCommerce', 'doublescale' ),
						'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
						'groups'      => array(
							'order'        => array(
								'label'       => __( 'Order', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
							),
							'cart'         => array(
								'label'       => __( 'Cart', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
							),
							'review'       => array(
								'label'       => __( 'Review', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
							),
							'subscription' => array(
								'label'       => __( 'Subscription', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ),
							),
							'wishlist'     => array(
								'label'       => __( 'Wishlist', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce-wishlists/woocommerce-wishlists.php' ),
							),
							'membership'   => array(
								'label'       => __( 'Membership', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce-memberships/woocommerce-memberships.php' ),
							),
						),
					),
					'edd'         => array(
						'label'       => __( 'Easy Digital Downloads', 'doublescale' ),
						'is_disabled' => ! doublescale_is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ),
						'groups'      => array(
							'order' => array(
								'label'       => __( 'Order', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! doublescale_is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ),
							),
						),
					),
					'surecart'    => array(
						'label'       => __( 'SureCart', 'doublescale' ),
						'is_disabled' => ! defined( 'SURECART_PLUGIN_FILE' ),
						'groups'      => array(
							'order' => array(
								'label'       => __( 'Order', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! defined( 'SURECART_PLUGIN_FILE' ),
							),
						),
					),
				),
			),
			'lms'         => array(
				'label'  => __( 'LMS', 'doublescale' ),
				'groups' => array(
					'learndash'  => array(
						'label'       => __( 'LearnDash', 'doublescale' ),
						'triggers'    => array(),
						'is_disabled' => ! doublescale_is_plugin_active( 'sfwd-lms/sfwd_lms.php' ),
					),
					'tutorlms'   => array(
						'label'       => __( 'Tutor LMS', 'doublescale' ),
						'triggers'    => array(),
						'is_disabled' => ! doublescale_is_plugin_active( 'tutor/tutor.php' ),
					),
					'lifterlms'  => array(
						'label'       => __( 'LifterLMS', 'doublescale' ),
						'triggers'    => array(),
						'is_disabled' => ! doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ),
					),
					'learnpress' => array(
						'label'       => __( 'LearnPress', 'doublescale' ),
						'triggers'    => array(),
						'is_disabled' => ! doublescale_is_plugin_active( 'learnpress/learnpress.php' ),
					),
				),
			),
			'wp'          => array(
				'label'  => __( 'WordPress', 'doublescale' ),
				'groups' => array(
					'user' => array(
						'label'    => __( 'User', 'doublescale' ),
						'triggers' => array(),
					),
				),
			),
			'membership'  => array(
				'label' => __( 'Membership', 'doublescale' ),
				'tabs'  => array(
					'memberpress' => array(
						'label'       => __( 'MemberPress', 'doublescale' ),
						'is_disabled' => ! defined( 'MEPR_PLUGIN_NAME' ),
						'groups'      => array(
							'memberpress' => array(
								'label'       => __( 'MemberPress', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! defined( 'MEPR_PLUGIN_NAME' ),
							),
						),
					),
					'pmpro'       => array(
						'label'       => __( 'Paid Memberships Pro', 'doublescale' ),
						'is_disabled' => ! defined( 'PMPRO_VERSION' ),
						'groups'      => array(
							'pmpro' => array(
								'label'       => __( 'Paid Memberships Pro', 'doublescale' ),
								'triggers'    => array(),
								'is_disabled' => ! defined( 'PMPRO_VERSION' ),
							),
						),
					),
				),
			),
			'forms'       => array(
				'label'  => __( 'Forms', 'doublescale' ),
				'groups' => array(),
			),
			'video'       => array(
				'label'  => __( 'Video', 'doublescale' ),
				'groups' => array(
					'prestoplayer' => array(
						'label'       => __( 'Presto Player', 'doublescale' ),
						'triggers'    => array(),
						'is_disabled' => ! defined( 'PRESTO_PLAYER_PLUGIN_FILE' ),
					),
				),
			),
		);

		$this->sources = apply_filters( 'doublescale_automation_trigger_sources', $this->sources );
	}

	/**
	 * Rebuild automation trigger rows for every registered form integration.
	 * Safe to call from admin config after the stack is fully booted.
	 */
	public function sync_form_trigger_sources(): void {
		$this->set_forms_sources();
	}

	/**
	 * Get forms sources
	 *
	 * @return void
	 */
	public function set_forms_sources() {
		$forms_module_off = ! function_exists( 'doublescale_is_module_active' )
			|| ! doublescale_is_module_active( 'forms' );

		// Form integrations (WPForms, Gravity, Fluent, …) self-register via
		// TriggersManager::register() → store_trigger_in_sources(), which seeds
		// $this->sources['forms']['groups'][<vendor>] regardless of module state.
		// When the Forms module is off, FormsManager isn't loaded, so we just
		// force every already-registered vendor group to is_disabled = true and
		// let the frontend show each one with the "(Not Available)" tooltip.
		if ( $forms_module_off ) {
			if ( isset( $this->sources['forms']['groups'] ) && is_array( $this->sources['forms']['groups'] ) ) {
				foreach ( $this->sources['forms']['groups'] as &$group ) {
					$group['is_disabled']     = true;
					$group['disabled_reason'] = 'forms_module_off';
					if ( ! empty( $group['triggers'] ) && is_array( $group['triggers'] ) ) {
						foreach ( $group['triggers'] as &$trigger_row ) {
							$trigger_row['is_disabled'] = true;
						}
						unset( $trigger_row );
					}
				}
				unset( $group );
			}
			return;
		}

		if ( ! class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' ) ) {
			return;
		}
		$this->sources['forms']['groups'] = array();
		$forms                            = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms();

		$skip_slugs = array();
		if ( class_exists( '\DoubleScale\Modules\Automations\Triggers\Forms\AbstractFormSubmittedTrigger' ) ) {
			$skip_slugs = \DoubleScale\Modules\Automations\Triggers\Forms\AbstractFormSubmittedTrigger::integration_slugs();
		}

		foreach ( $forms as $form ) {
			if ( in_array( $form->slug, $skip_slugs, true ) ) {
				// These slugs are handled by catalog-registered Pro triggers.
				// Re-populate from the already-registered trigger so sources survive
				// any later call to this method (e.g. from sync_form_trigger_sources()).
				if ( isset( $this->triggers[ $form->slug ] ) ) {
					$this->store_trigger_in_sources( $this->triggers[ $form->slug ] );
				}
				continue;
			}
			$this->sources['forms']['groups'][ $form->slug ] = array(
				'label'       => $form->name,
				'is_disabled' => ! $form->is_enabled(),
				'triggers'    => array(
					$form->slug => array(
						'label'       => __( 'Form Submitted', 'doublescale' ),
						'description' => $form->description,
						'fields'      => $form->get_form_options(),
						'is_disabled' => ! $form->is_enabled(),
						'is_form'     => true,
						'is_pro'      => $form->is_pro,
					),
				),
			);
		}

		// Pro form vendors live in FormsManager only while the Pro add-on is active.
		// Free-plugin TriggerPro stubs still register for those vendors so the UI can
		// show them with a Pro badge when Pro is off — re-seed any missing rows after
		// the FormsManager rebuild (AdminConfig / REST call sync_form_trigger_sources).
		foreach ( $this->triggers as $trigger ) {
			if ( ! $trigger instanceof Trigger || 'forms' !== $trigger->source ) {
				continue;
			}
			if ( isset( $this->sources['forms']['groups'][ $trigger->group ]['triggers'][ $trigger->slug ] ) ) {
				continue;
			}
			$this->store_trigger_in_sources( $trigger );
		}
	}


	/**
	 * Get sources
	 *
	 * Vendor-pick categories are pruned to the integrations actually active on
	 * this site (and dropped when none are), so the builder never offers a
	 * plugin the site doesn't have. Module categories keep their `is_disabled`
	 * rows — those are DoubleScale features the user can switch on.
	 *
	 * @return array
	 */
	public function get_sources() {
		return SourceTreePruner::prune(
			$this->sources,
			array( 'forms', 'ecommerce', 'lms', 'membership', 'video' )
		);
	}

	/**
	 * Merge one trigger into {@see $this->sources} (form integrations need `is_form` + group shell).
	 *
	 * @param Trigger $trigger Trigger instance.
	 */
	private function store_trigger_in_sources( Trigger $trigger ): void {
		$fields = $trigger->get_fields();
		if ( 'woocommerce' === $trigger->source ) {
			$fields = array_merge( $fields, PhoneAsWhatsappSetting::get_fields_entry() );
		}

		$row = array(
			'label'       => $trigger->name,
			'description' => $trigger->description,
			'fields'      => $fields,
			'is_pro'      => $trigger->is_pro,
			'is_disabled' => false,
		);

		if ( ! empty( $trigger->is_featured ) ) {
			$row['is_featured'] = true;
		}

		$documentation = $trigger->get_documentation();
		if ( ! empty( $documentation ) ) {
			$row['documentation'] = $documentation;
		}

		if ( 'forms' === $trigger->source ) {
			$row['is_form'] = true;
			$form           = null;
			if ( class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' ) ) {
				$form = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_form( $trigger->slug );
			}
			if ( $form ) {
				$row['is_disabled'] = ! $form->is_enabled();
			} else {
				$row['is_disabled'] = ! $this->is_form_integration_available( $trigger->slug );
			}
			if ( ! isset( $this->sources['forms']['groups'][ $trigger->group ] ) ) {
				$this->sources['forms']['groups'][ $trigger->group ] = array(
					'label'       => $trigger->name,
					'is_disabled' => $row['is_disabled'],
					'triggers'    => array(),
				);
			}
			$this->sources['forms']['groups'][ $trigger->group ]['is_disabled']                = $row['is_disabled'];
			if ( $row['is_disabled'] && in_array( $trigger->slug, array( 'typeform', 'jotform' ), true ) ) {
				$this->sources['forms']['groups'][ $trigger->group ]['disabled_reason'] = $trigger->slug . '_not_connected';
			}
			$this->sources['forms']['groups'][ $trigger->group ]['triggers'][ $trigger->slug ] = $row;

			return;
		}

		$tabbed_sources = array(
			'ecommerce'  => array( 'woocommerce', 'edd', 'surecart' ),
			'membership' => array( 'memberpress', 'pmpro' ),
			'modules'    => array(
				'crm',
				'messaging',
				'sales',
				'projects',
				'booking',
				'tasks',
				'support',
				'link_triggers',
			),
		);

		foreach ( $tabbed_sources as $category_key => $source_keys ) {
			if ( in_array( $trigger->source, $source_keys, true ) ) {
				if ( ! isset( $this->sources[ $category_key ]['tabs'][ $trigger->source ]['groups'][ $trigger->group ] ) ) {
					$this->sources[ $category_key ]['tabs'][ $trigger->source ]['groups'][ $trigger->group ] = array(
						'label'    => $trigger->group,
						'triggers' => array(),
					);
				}
				$this->sources[ $category_key ]['tabs'][ $trigger->source ]['groups'][ $trigger->group ]['triggers'][ $trigger->slug ] = $row;

				return;
			}
		}

		$this->sources[ $trigger->source ]['groups'][ $trigger->group ]['triggers'][ $trigger->slug ] = $row;
	}

	/**
	 * Whether a form trigger is available (WP plugin installed or SaaS integration connected).
	 */
	private function is_form_integration_available( string $slug ): bool {
		if ( in_array( $slug, array( 'typeform', 'jotform' ), true ) ) {
			if ( ! class_exists( '\DoubleScale\Core\Managers\IntegrationsManager' ) ) {
				return false;
			}
			try {
				$integration = \DoubleScale\Core\Managers\IntegrationsManager::instance()->get_integration( $slug );
				return $integration && $integration->is_connected();
			} catch ( \Exception $e ) {
				return false;
			}
		}

		return $this->is_form_vendor_plugin_active( $slug );
	}

	/**
	 * Mirrors vendor {@see \DoubleScale\Pro\Modules\Forms\* \Form::is_enabled()} when FormsManager is not ready.
	 */
	private function is_form_vendor_plugin_active( string $slug ): bool {
		switch ( $slug ) {
			case 'bitform':
				return doublescale_is_plugin_active( 'bit-form/bitforms.php' );
			case 'contactform7':
				return doublescale_is_plugin_active( 'contact-form-7/wp-contact-form-7.php' );
			case 'elementor':
				return doublescale_is_plugin_active( 'elementor-pro/elementor-pro.php' );
			case 'fluentforms':
				return doublescale_is_plugin_active( 'fluentform/fluentform.php' );
			case 'formidable':
				return doublescale_is_plugin_active( 'formidable/formidable.php' );
			case 'forminator':
				return doublescale_is_plugin_active( 'forminator/forminator.php' );
			case 'gravityforms':
				return doublescale_is_plugin_active( 'gravityforms/gravityforms.php' );
			case 'metform':
				return doublescale_is_plugin_active( 'metform/metform.php' );
			case 'ninjaforms':
				return doublescale_is_plugin_active( 'ninja-forms/ninja-forms.php' );
			case 'quillforms':
				return doublescale_is_plugin_active( 'quillforms/quillforms.php' );
			case 'sureforms':
				return doublescale_is_plugin_active( 'sureforms/sureforms.php' );
			case 'wpforms':
				return doublescale_is_plugin_active( 'wpforms/wpforms.php' );
			case 'wsform':
				return doublescale_is_plugin_active( 'ws-form/ws-form.php' )
					|| doublescale_is_plugin_active( 'ws-form-pro/ws-form.php' );
			case 'eform':
				return doublescale_is_plugin_active( 'wp-fsqm-pro/ipt_fsqm.php' );
			case 'jetformbuilder':
				return doublescale_is_plugin_active( 'jetformbuilder/jet-form-builder.php' );
			default:
				return false;
		}
	}

	/**
	 * WordPress form-plugin slugs (matching the Forms page's Form Type picker)
	 * whose plugin is actually installed/active on this site — independent of
	 * whether Pro ships a full Form model for that vendor. Lets the Forms page
	 * distinguish "Pro-locked but you have it installed" from "Pro-locked, not
	 * installed", using the same detection {@see is_form_vendor_plugin_active()}
	 * already relies on for trigger availability.
	 *
	 * @return string[]
	 */
	public function get_active_form_vendor_slugs(): array {
		$slugs = array(
			'bitform',
			'contactform7',
			'elementor',
			'fluentforms',
			'formidable',
			'forminator',
			'gravityforms',
			'metform',
			'ninjaforms',
			'quillforms',
			'sureforms',
			'wpforms',
			'wsform',
			'eform',
			'jetformbuilder',
		);

		$active = array();
		foreach ( $slugs as $slug ) {
			if ( $this->is_form_vendor_plugin_active( $slug ) ) {
				$active[] = $slug;
			}
		}

		return $active;
	}
}
