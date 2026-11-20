<?php

/**
 * Class Merge Tag Manager
 *
 * This class is responsible for handling the merge tags
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\MergeTags;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Merge Tag Manager
 */
final class MergeTagsManager {

	use \DoubleScale\Core\DefersRegistration;

	/**
	 * Registed merge tags
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $merge_tags = array();

	/**
	 * Groups
	 *
	 * @var array
	 */
	protected $groups = array();

	/**
	 * Current post context for event-based campaign merge tags
	 *
	 * @var int|null
	 */
	protected $current_post_id = null;

	/**
	 * @var MergeTagsManager|null
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
	 * @return MergeTagsManager
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
		$this->set_groups();
		add_action( 'init', array( $this, 'register_forms_merge_tags' ) );
	}

	/**
	 * Set or clear the current post ID for merge tag resolution.
	 *
	 * Called by AutomatedCampaignHandler before/after processing.
	 *
	 * @param int|null $post_id Post ID or null to clear.
	 */
	public function set_current_post_id( $post_id ) {
		$this->current_post_id = $post_id ? (int) $post_id : null;
	}

	/**
	 * Restore campaign execution context from the event queue.
	 *
	 * Called by async continuations (Action Scheduler / AJAX) that run in
	 * a separate PHP process where the in-memory singleton state is lost.
	 *
	 * The source of truth is the campaign_events row that is currently in
	 * "processing" status for this campaign. Because the drain worker
	 * processes events sequentially, there is at most one such row.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public function restore_campaign_context( $campaign_id ) {
		global $wpdb;

		$events_table = esc_sql( $wpdb->prefix . 'doublescale_campaign_events' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_id FROM {$events_table} WHERE campaign_id = %d AND status = 'processing' ORDER BY created_at ASC LIMIT 1",
				$campaign_id
			)
		);

		if ( $post_id ) {
			$this->current_post_id = (int) $post_id;
		}
	}

	/**
	 * Get the current post ID from campaign context
	 *
	 * @return int|null
	 */
	public function get_current_post_id() {
		return $this->current_post_id;
	}


	/**
	 * Register Forms Merge Tags
	 */
	public function register_forms_merge_tags() {
		if ( ! class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' ) ) {
			return;
		}
		$forms = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms();
		foreach ( $forms as $form ) {
			// Ensure the form group is properly initialized with its name
			// This handles the case where set_groups() ran before forms were loaded
			if ( ! isset( $this->groups[ $form->slug ] ) || ! isset( $this->groups[ $form->slug ]['name'] ) ) {
				$this->groups[ $form->slug ] = array(
					'name'        => $form->name,
					'mergeTags'   => isset( $this->groups[ $form->slug ]['mergeTags'] ) ? $this->groups[ $form->slug ]['mergeTags'] : array(),
					'triggers'    => array( $form->slug ),
					'is_disabled' => ! $form->is_enabled(),
				);
			}

			if ( class_exists( '\DoubleScale\Pro\Modules\Forms\MergeTags\Forms\FormsFieldBackend' ) ) {
				$this->register( new \DoubleScale\Pro\Modules\Forms\MergeTags\Forms\FormsFieldBackend( $form->slug ) );
			}
			if ( class_exists( '\DoubleScale\Pro\Modules\Forms\MergeTags\Forms\FormsMetadataBackEnd' ) ) {
				$this->register( new \DoubleScale\Pro\Modules\Forms\MergeTags\Forms\FormsMetadataBackEnd( $form->slug ) );
			}
		}
	}

	/**
	 * Register Merge Tag
	 *
	 * @since 1.0.0
	 *
	 * @param MergeTag $merge_tag Merge Tag.
	 */
	public function register( MergeTag $merge_tag ) {
		if ( ! $merge_tag instanceof MergeTag ) {
			return;
		}

		if ( ! $this->claim_registration( $merge_tag->group . ':' . $merge_tag->slug, isset( $this->merge_tags[ $merge_tag->group ][ $merge_tag->slug ] ) ) ) {
			return;
		}

		// Ensure the group exists with a default structure
		if ( ! isset( $this->groups[ $merge_tag->group ] ) ) {
			$this->groups[ $merge_tag->group ] = array(
				'name'      => ucwords( str_replace( array( '_', '-' ), ' ', $merge_tag->group ) ),
				'mergeTags' => array(),
			);
		}

		// Merge tag will be like {{group:slug}}
		$this->merge_tags[ $merge_tag->group ][ $merge_tag->slug ]          = $merge_tag;
		$this->groups[ $merge_tag->group ]['mergeTags'][ $merge_tag->slug ] = array(
			'name'              => $merge_tag->name,
			// 'description' => $merge_tag->description,
			'value'             => "{{{$merge_tag->group}:{$merge_tag->slug}}}",
			'required_triggers' => $merge_tag->required_triggers,
		);
	}

	/**
	 * Get Merge Tag
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Merge Tag Group.
	 * @param string $slug Merge Tag Slug.
	 *
	 * @return MergeTag
	 */
	public function get_merge_tag( $group, $slug ) {
		$this->load_deferred();
		if ( isset( $this->merge_tags[ $group ][ $slug ] ) ) {
			return $this->merge_tags[ $group ][ $slug ];
		}

		// Check for dynamic merge tags (e.g., dynamic_id_123 matches dynamic_id_)
		if ( isset( $this->merge_tags[ $group ] ) ) {
			foreach ( $this->merge_tags[ $group ] as $registered_slug => $merge_tag ) {

				$last_char = substr( $registered_slug, -1 );

				if ( ( $last_char === '_' || $last_char === ':' ) &&
					strpos( $slug, $registered_slug ) === 0
				) {
					return $merge_tag;
				}
			}
		}

		return null;
	}

	/**
	 * Get Merge Tags
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_tags() {
		$this->load_deferred();
		return $this->merge_tags;
	}

	/**
	 * Set Groups
	 */
	public function set_groups() {
		$this->groups = array(
			'contact'        => array(
				'name'      => __( 'Contact', 'doublescale' ),
				'mergeTags' => array(),
			),
			'general'        => array(
				'name'      => __( 'General', 'doublescale' ),
				'mergeTags' => array(),
			),
			'order'          => array(
				'name'        => __( 'Order', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'wc_order_completed', 'wc_order_created', 'wc_order_refunded', 'wc_order_status_changed', 'wc_cart_recovered' ),
				'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce' ),
			),
			'abandoned_cart' => array(
				'name'        => __( 'Abandoned Cart', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'wc_abandoned_cart_created' ),
				'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce' ),
			),
			'edd_customer'   => array(
				'name'        => __( 'Easy Digital Downloads Customer', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'edd_new_order_success' ),
				'is_disabled' => ! defined( 'EDD_PLUGIN_FILE' ),
			),
			'edd_order'      => array(
				'name'        => __( 'Easy Digital Downloads Order', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'edd_new_order_success' ),
				'is_disabled' => ! defined( 'EDD_PLUGIN_FILE' ),
			),
			'fluentcart_order' => array(
				'name'        => __( 'FluentCart Order', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'fluentcart_order_paid', 'fluentcart_order_refunded', 'fluentcart_order_status_changed' ),
				'is_disabled' => ! \DoubleScale\Core\Services\FluentCartOrders::is_active(),
			),
			'amelia_booking'   => array(
				'name'        => __( 'Amelia Booking', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'amelia_appointment_booked', 'amelia_appointment_status_changed', 'amelia_appointment_canceled', 'amelia_appointment_rescheduled', 'amelia_event_booked', 'amelia_event_booking_canceled' ),
				'is_disabled' => ! \DoubleScale\Core\Services\AmeliaBookings::is_active(),
			),
			'latepoint_booking' => array(
				'name'        => __( 'LatePoint Booking', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'latepoint_booking_created', 'latepoint_booking_status_changed', 'latepoint_booking_cancelled', 'latepoint_booking_rescheduled' ),
				'is_disabled' => ! \DoubleScale\Core\Services\LatepointBookings::is_active(),
			),
			'learndash'      => array(
				'name'        => __( 'LearnDash', 'doublescale' ),
				'mergeTags'   => array(),
				'is_disabled' => ! doublescale_is_plugin_active( 'learndash' ),
			),
			'memberpress'    => array(
				'name'        => __( 'MemberPress', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array(
					'memberpress_membership_enrolled',
					'memberpress_membership_level_expiry',
					'memberpress_subscription_created',
					'memberpress_subscription_paused',
					'memberpress_subscription_resumed',
					'memberpress_subscription_cancelled',
					'memberpress_transaction_completed',
					'memberpress_transaction_refunded',
					'memberpress_transaction_failed',
				),
				'is_disabled' => ! defined( 'MEPR_PLUGIN_NAME' ),
			),
			'pmpro'          => array(
				'name'        => __( 'Paid Memberships Pro', 'doublescale' ),
				'mergeTags'   => array(),
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
			'membership'     => array(
				'name'        => __( 'Membership', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'wc_membership_created', 'wc_membership_status_changed' ),
				'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce-memberships' ),
			),
			'wishlist'       => array(
				'name'        => __( 'Wishlist', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'wc_user_adds_product_to_wishlist', 'wc_wishlist_item_on_sale', 'wc_wishlist_reminder' ),
				'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce-wishlists' ),
			),
			'subscription'   => array(
				'name'        => __( 'Subscription', 'doublescale' ),
				'mergeTags'   => array(),
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
				'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce-subscriptions' ),
			),
			'review'         => array(
				'name'        => __( 'Review', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array( 'wc_review_received' ),
				'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce' ),
			),
			'coupon'         => array(
				'name'        => __( 'Coupon', 'doublescale' ),
				'mergeTags'   => array(),
				'is_disabled' => true,
			),
			'deal'           => array(
				'name'      => __( 'Deal', 'doublescale' ),
				'mergeTags' => array(),
				'triggers'  => array( 'deal_owner_change', 'deal_value_change', 'deal_status_change', 'deal_stage_change' ),
			),
			'task'           => array(
				'name'        => __( 'Task', 'doublescale' ),
				'mergeTags'   => array(),
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
			'project'        => array(
				'name'        => __( 'Project', 'doublescale' ),
				'mergeTags'   => array(),
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
			'booking'        => array(
				'name'        => __( 'Booking', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array(
					'booking_created',
					'booking_confirmed',
					'booking_cancelled',
					'booking_rescheduled',
					'booking_completed',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'booking' ),
			),
			'guest'          => array(
				'name'        => __( 'Booking Guest', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array(
					'booking_created',
					'booking_confirmed',
					'booking_cancelled',
					'booking_rescheduled',
					'booking_completed',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'booking' ),
			),
			'host'           => array(
				'name'        => __( 'Booking Host', 'doublescale' ),
				'mergeTags'   => array(),
				'triggers'    => array(
					'booking_created',
					'booking_confirmed',
					'booking_cancelled',
					'booking_rescheduled',
					'booking_completed',
				),
				'is_disabled' => ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'booking' ),
			),
			'last_post'      => array(
				'name'      => __( 'Last Post', 'doublescale' ),
				'mergeTags' => array(),
				'triggers'  => array( 'post_published' ),
			),
		);
		if ( class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' ) ) {
			$forms = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms();
			foreach ( $forms as $form ) {
				$this->groups[ $form->slug ] = array(
					'name'        => $form->name,
					'mergeTags'   => array(),
					'triggers'    => array( $form->slug ),
					'is_disabled' => ! $form->is_enabled(),
				);
			}
		}

		/**
		 * Filter merge tag groups
		 *
		 * Allows Pro and other extensions to register additional merge tag groups.
		 *
		 * @since 1.0.0
		 *
		 * @param array $groups Registered merge tag groups
		 */
		$this->groups = apply_filters( 'doublescale_mail_merge_tag_groups', $this->groups );
	}

	/**
	 * Get Groups
	 *
	 * Re-applies {@see 'doublescale_mail_merge_tag_groups'} so modules that boot
	 * after MergeTagsManager (e.g. Sales) can still attach `triggers` / labels.
	 * Without that late pass, self-registered tags create groups with no
	 * `triggers` key and the UI treats them as global (visible for every trigger).
	 *
	 * @return array
	 */
	public function get_groups() {
		$this->load_deferred();
		$groups = apply_filters( 'doublescale_mail_merge_tag_groups', $this->groups );

		if ( function_exists( 'doublescale_filter_merge_tag_groups_for_modules' ) ) {
			return doublescale_filter_merge_tag_groups_for_modules( $groups );
		}

		return $groups;
	}

	/**
	 * Process Merge Tags
	 * Context-aware processing that uses stored values when tracking context is available
	 *
	 * @since 1.0.0
	 *
	 * @param string                                   $content Content.
	 * @param AutomationContactModel|ContactModel|null $automation_contact Contact Model.
	 *
	 * @return string
	 */
	/**
	 * Split a merge tag's slug from its optional fallback.
	 *
	 * A tag may be written `{{group:slug|fallback}}`. The fallback is the copy
	 * to use when the contact has no value — without it a blank field renders
	 * as nothing, which reads badly in an email and is rejected outright by
	 * Meta when it is a WhatsApp template placeholder.
	 *
	 * Only the first pipe separates: a pipe inside the fallback is part of the
	 * author's text.
	 *
	 * @param string $slug Raw slug portion from the tag.
	 * @return array{0:string,1:string|null} Slug, and fallback or null.
	 */
	private function split_tag_fallback( $slug ) {
		$pipe = strpos( $slug, '|' );

		if ( false === $pipe ) {
			return array( $slug, null );
		}

		return array(
			substr( $slug, 0, $pipe ),
			substr( $slug, $pipe + 1 ),
		);
	}

	public function process_merge_tags( $content, $contact ) {
		$this->load_deferred();
		// Check if contact has tracking context - if so, use stored values
		if ( $contact && method_exists( $contact, 'has_tracking_context' ) && $contact->has_tracking_context() ) {
			$tracking_context = $contact->get_tracking_context();
			return $this->process_merge_tags_with_stored_values( $content, $tracking_context );
		}

		// Fall back to fresh processing for previews and non-tracking contexts
		return preg_replace_callback(
			'/{{(.*?):(.*?)}}/',
			function ( $matches ) use ( $contact ) {
				$group = $matches[1];

				list( $slug, $fallback ) = $this->split_tag_fallback( $matches[2] );

				$slug_parts     = explode( ' ', $slug );
				$merge_tag_slug = $slug_parts[0];
				$merge_tag      = $this->get_merge_tag( $group, $merge_tag_slug );

				// An unknown tag is as empty as an unset field, so it takes the
				// fallback too rather than silently blanking.
				if ( ! $merge_tag ) {
					return null === $fallback ? '' : $fallback;
				}

				// A tag resolves against whatever context the message carries,
				// so missing orders, deleted products or absent cart data are
				// normal input. Losing the whole email because one placeholder
				// could not resolve is never the right trade — blank the tag,
				// log it, and let the rest of the message go out.
				try {
					$value = $merge_tag->get_tag_value( $contact, $slug );

					// '0' is a real value; only an absent or blank one falls back.
					if ( null === $fallback || ( '' !== (string) $value && null !== $value ) ) {
						return $value;
					}

					return $fallback;
				} catch ( \Throwable $e ) {
					doublescale_get_logger()->error(
						__( 'Merge tag failed to resolve', 'doublescale' ),
						array(
							'code'  => 'merge_tag_resolution_failed',
							'error' => array(
								'tag'     => "{$group}:{$merge_tag_slug}",
								'message' => $e->getMessage(),
								'file'    => $e->getFile(),
								'line'    => $e->getLine(),
							),
						)
					);

					return '';
				}
			},
			$content
		);
	}

	/**
	 * Process merge tags using stored values from communication tracking meta
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content with merge tags
	 * @param int    $tracking_id Communication tracking ID
	 *
	 * @return string Content with merge tags replaced by stored values
	 */
	private function process_merge_tags_with_stored_values( $content, $tracking_id ) {
		if ( ! class_exists( '\DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel' ) ) {
			return $content;
		}
		$result = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::render_with_stored_values( $tracking_id, $content );

		return $result;
	}

	/**
	 * Extract merge tags keys
	 *
	 * @param string $content Content.
	 * @return array Array of merge tag keys found in content
	 */
	public function extract_merge_tag_keys( $content ) {
		$merge_tag_keys = array();

		// Simple regex to find all merge tags
		preg_match_all( '/{{(.*?):(.*?)}}/', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$group = $match[1];

			list( $slug ) = $this->split_tag_fallback( $match[2] );

			$slug_parts     = explode( ' ', $slug );
			$merge_tag_slug = $slug_parts[0];
			$full_tag       = "{$group}:{$merge_tag_slug}";

			// Store unique keys only
			if ( ! in_array( $full_tag, $merge_tag_keys ) ) {
				$merge_tag_keys[] = $full_tag;
			}
		}

		return $merge_tag_keys;
	}

	/**
	 * Get values for specific merge tag keys using contact
	 *
	 * @param array                                                                       $merge_tag_keys Array of merge tag keys to get values for
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model
	 * @return array Array of merge tag keys and their values
	 */
	public function get_merge_tag_values_for_keys( $merge_tag_keys, $contact_or_automation_contact ) {
		$merge_tags = array();

		foreach ( $merge_tag_keys as $tag_key ) {
			list($group, $slug) = explode( ':', $tag_key, 2 );

			$merge_tag = $this->get_merge_tag( $group, $slug );
			if ( $merge_tag ) {
				$value                  = $merge_tag->get_tag_value( $contact_or_automation_contact, $slug );
				$merge_tags[ $tag_key ] = $value;
			}
		}

		return $merge_tags;
	}

	/**
	 * Get values for specific merge tag keys using contact, with slug-only keys
	 *
	 * This is useful for bulk email APIs (like Mailgun) that use recipient variables
	 * with just the slug as the key (e.g., "first_name" instead of "contact:first_name").
	 *
	 * @since 1.0.0
	 *
	 * @param array                                                                       $merge_tag_keys Array of merge tag keys (format: "group:slug")
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model
	 * @return array Array of slug-only keys and their values (e.g., ["first_name" => "John"])
	 */
	public function get_merge_tag_values_for_keys_slug_only( $merge_tag_keys, $contact_or_automation_contact ) {
		$full_values = $this->get_merge_tag_values_for_keys( $merge_tag_keys, $contact_or_automation_contact );
		$slug_values = array();

		foreach ( $full_values as $tag_key => $value ) {
			// Extract just the slug from "group:slug"
			$parts = explode( ':', $tag_key, 2 );
			$slug  = isset( $parts[1] ) ? $parts[1] : $tag_key;

			$slug_values[ $slug ] = $value;
		}

		return $slug_values;
	}

	/**
	 * Rewrite `{{group:slug|fallback}}` tags for a batch sender.
	 *
	 * Batch senders (cURL multi, and providers' bulk APIs) substitute
	 * per-recipient variables keyed by slug and only understand plain
	 * `{{group:slug}}` tags, so a tag with a fallback was sent as written
	 * ("Hi {{contact:first_name|there}},"). Each one becomes a plain tag on a
	 * variable that carries its fallback in its name (hex-encoded), so the
	 * fallback survives any provider's tag conversion and
	 * resolve_batch_fallbacks() can give that variable its value per recipient.
	 *
	 * @param string $content Content with merge tags.
	 * @return string
	 */
	public static function alias_fallback_tags( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '|' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/\{\{([a-zA-Z0-9_]+):([a-zA-Z0-9_]+)\|([^}]*)\}\}/',
			function ( $matches ) {
				return '{{' . $matches[1] . ':' . $matches[2] . '__fallback_' . bin2hex( $matches[3] ) . '}}';
			},
			$content
		);
	}

	/**
	 * Give every fallback variable in a batch its value per recipient: the
	 * contact's value, or the fallback when that is empty, the same rule
	 * process_merge_tags() applies to single emails.
	 *
	 * @param array $batch_data Batch data with subject/html/text and recipient_variables.
	 * @return array Batch data with fallbacks resolved.
	 */
	public static function resolve_batch_fallbacks( array $batch_data ) {
		$aliases = array();

		foreach ( array( 'subject', 'html', 'text' ) as $field ) {
			if ( empty( $batch_data[ $field ] ) || ! is_string( $batch_data[ $field ] ) ) {
				continue;
			}
			$batch_data[ $field ] = self::alias_fallback_tags( $batch_data[ $field ] );

			if ( preg_match_all( '/([a-zA-Z0-9_]+?)__fallback_((?:[0-9a-f]{2})*)/', $batch_data[ $field ], $found, PREG_SET_ORDER ) ) {
				foreach ( $found as $match ) {
					$aliases[ $match[0] ] = array( $match[1], (string) hex2bin( $match[2] ) );
				}
			}
		}

		if ( empty( $aliases ) || empty( $batch_data['recipient_variables'] ) || ! is_array( $batch_data['recipient_variables'] ) ) {
			return $batch_data;
		}

		foreach ( $batch_data['recipient_variables'] as $recipient => $variables ) {
			if ( ! is_array( $variables ) ) {
				continue;
			}
			foreach ( $aliases as $alias => $source ) {
				list( $slug, $fallback ) = $source;
				$value                   = $variables[ $slug ] ?? null;

				$variables[ $alias ] = ( null === $value || '' === (string) $value ) ? $fallback : $value;
			}
			$batch_data['recipient_variables'][ $recipient ] = $variables;
		}

		return $batch_data;
	}
}
