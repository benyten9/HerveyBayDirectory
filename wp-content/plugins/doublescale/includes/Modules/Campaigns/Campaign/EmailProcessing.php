<?php

/**
 * Email Campaign Processing
 * This class is responsible for handling Email campaign processing
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Campaign;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignProcessing;
use DoubleScale\Modules\Emails\BulkEmailSender;
use DoubleScale\Modules\Emails\CurlMultiEmailSender;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Emails\EmailTrackingHelper;
use DoubleScale\Modules\Campaigns\Pipeline\CampaignProcessingPipeline;
use DoubleScale\Modules\Campaigns\Pipeline\Strategies\IndividualDispatchStrategy;
use DoubleScale\Modules\Campaigns\Pipeline\Strategies\BulkEmailDispatchStrategy;
use DoubleScale\Modules\Campaigns\Pipeline\Strategies\CurlMultiEmailDispatchStrategy;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\InitialiseStep;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\CheckCompletionStep;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\ProcessBatchesStep;
use DoubleScale\Modules\Campaigns\Services\CampaignEmailLogSource;
use DoubleScale\Modules\Smtp\EmailLog\EmailLogContext;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\ScheduleContinuationStep;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Tracking\Email;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Modules\Contacts\Filters\ConditionEvaluator;

/**
 * Email Campaign Processing class
 */
class EmailProcessing extends AbstractCampaignProcessing {
	/**
	 * Communication channel
	 *
	 * @var string
	 */
	protected $channel = CampaignChannel::STR_EMAIL;

	/**
	 * Whether to use bulk sending for this campaign
	 *
	 * @var bool|null
	 */
	private $use_bulk_sending = null;

	/**
	 * Whether to use curl multi sending for this campaign
	 *
	 * @var bool|null
	 */
	private $use_curl_multi_sending = null;

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	public function add_hooks() {
		$this->register_campaign_processing_hooks();
	}

	/**
	 * Check if bulk sending should be used
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return bool True if bulk sending should be used
	 */
	protected function should_use_bulk_sending( CampaignModel $campaign ) {
		if ( $this->use_bulk_sending !== null ) {
			return $this->use_bulk_sending;
		}

		// Bulk batch class must be present (bundled with CRM).
		if ( ! class_exists( '\DoubleScale\Modules\Emails\BulkEmailSender' ) ) {
			$this->use_bulk_sending = false;
			return false;
		}

		// Get from_email from campaign template for smart routing
		$from_email = $this->get_campaign_from_email( $campaign );

		// Check if bulk sending is available (smtp with bulk-capable mailer)
		if ( ! \DoubleScale\Modules\Emails\BulkEmailSender::is_available( $from_email ) ) {
			$this->use_bulk_sending = false;
			return false;
		}

		// Allow filtering whether to use bulk sending
		$this->use_bulk_sending = apply_filters(
			'doublescale_use_bulk_email_sending',
			true,
			$campaign
		);

		if ( $this->use_bulk_sending ) {
			doublescale_get_logger()->info(
				__( 'Bulk email sending enabled for campaign', 'doublescale' ),
				array(
					'code'        => 'bulk_email_enabled',
					'campaign_id' => $campaign->id,
					'mailer'      => \DoubleScale\Modules\Emails\BulkEmailSender::get_active_mailer_slug( $from_email ),
				)
			);
		}

		return $this->use_bulk_sending;
	}

	/**
	 * Check if curl multi sending should be used
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return bool True if curl multi sending should be used
	 */
	protected function should_use_curl_multi_sending( CampaignModel $campaign ) {
		if ( $this->use_curl_multi_sending !== null ) {
			return $this->use_curl_multi_sending;
		}

		// Curl multi batch class must be present (bundled with CRM).
		if ( ! class_exists( '\DoubleScale\Modules\Emails\CurlMultiEmailSender' ) ) {
			$this->use_curl_multi_sending = false;
			return false;
		}

		// Get from_email from campaign template for smart routing
		$from_email = $this->get_campaign_from_email( $campaign );

		// Check if curl multi sending is available (smtp with curl multi-capable mailer like SMTP2GO)
		if ( ! \DoubleScale\Modules\Emails\CurlMultiEmailSender::is_available( $from_email ) ) {
			$this->use_curl_multi_sending = false;
			return false;
		}

		// Allow filtering whether to use curl multi sending
		$this->use_curl_multi_sending = apply_filters(
			'doublescale_use_curl_multi_email_sending',
			true,
			$campaign
		);

		if ( $this->use_curl_multi_sending ) {
			doublescale_get_logger()->info(
				__( 'Curl Multi email sending enabled for campaign', 'doublescale' ),
				array(
					'code'        => 'curl_multi_email_enabled',
					'campaign_id' => $campaign->id,
					'mailer'      => \DoubleScale\Modules\Emails\CurlMultiEmailSender::get_active_mailer_slug( $from_email ),
				)
			);
		}

		return $this->use_curl_multi_sending;
	}

	/**
	 * Get the from_email from campaign template for smart routing
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return string|null From email address or null if not set
	 */
	protected function get_campaign_from_email( CampaignModel $campaign ) {
		$template_ids = $campaign->get_template_ids();

		if ( empty( $template_ids ) ) {
			return null;
		}

		$template_id = reset( $template_ids );
		$template    = TemplateModel::find( $template_id );

		if ( ! $template ) {
			return null;
		}

		$from_email = $template->get_setting( 'from_email' );

		return $from_email ?: null;
	}

	/**
	 * Override do_process_campaign to inject the right dispatch strategy.
	 *
	 * Routes to BulkEmailDispatchStrategy (Mailgun/SendGrid/etc.),
	 * CurlMultiEmailDispatchStrategy (SMTP2GO/etc.), or falls back to the
	 * inherited IndividualDispatchStrategy (wp_mail).  All three paths run
	 * through the same Pipeline, so locking, status checks, progress
	 * tracking, and continuation scheduling are identical.
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return void
	 */
	protected function do_process_campaign( CampaignModel $campaign ) {
		$this->reset_per_campaign_state();

		$ctx = $this->build_campaign_context( $campaign );

		if ( $this->should_use_bulk_sending( $campaign ) ) {
			$strategy_name = 'bulk';
			$strategy      = new BulkEmailDispatchStrategy(
				$this->channel,
				\Closure::fromCallable( array( $this, 'send_email_batch' ) )
			);
		} elseif ( $this->should_use_curl_multi_sending( $campaign ) ) {
			$strategy_name = 'curl_multi';
			$strategy      = new CurlMultiEmailDispatchStrategy(
				$this->channel,
				\Closure::fromCallable( array( $this, 'send_email_batch_curl_multi' ) )
			);
		} else {
			$strategy_name = 'individual_wp_mail';
			$strategy      = new IndividualDispatchStrategy();
		}

		$this->log_campaign_email_dispatch_strategy( $campaign, $strategy_name );

		$pipeline = new CampaignProcessingPipeline(
			array(
				new InitialiseStep(),
				new CheckCompletionStep(),
				new ProcessBatchesStep( $strategy ),
				new ScheduleContinuationStep(),
			)
		);

		$pipeline->run( $ctx );
	}

	/**
	 * Log how outbound campaign email will be sent (bulk API vs wp_mail) and SMTP routing context.
	 *
	 * Helps diagnose "campaign not sending" without mistaking unrelated automation model logs for failures.
	 *
	 * @param string $strategy_name bulk|curl_multi|individual_wp_mail
	 */
	private function log_campaign_email_dispatch_strategy( CampaignModel $campaign, $strategy_name ) {
		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}

		$from = $this->get_campaign_from_email( $campaign );
		$data = array(
			'code'                => 'campaign_email_dispatch_strategy',
			'campaign_id'         => $campaign->id,
			'campaign_status'     => $campaign->status ?? '',
			'strategy'            => $strategy_name,
			'template_from_email' => $from,
			'smtp_module_present' => class_exists( '\DoubleScale\Modules\Smtp\Settings' ),
		);

		if ( class_exists( BulkEmailSender::class ) ) {
			$data['bulk_available']     = BulkEmailSender::is_available( $from );
			$data['bulk_active_mailer'] = BulkEmailSender::get_active_mailer_slug( $from );
		}
		if ( class_exists( CurlMultiEmailSender::class ) ) {
			$data['curl_multi_available']     = CurlMultiEmailSender::is_available( $from );
			$data['curl_multi_active_mailer'] = CurlMultiEmailSender::get_active_mailer_slug( $from );
		}

		doublescale_get_logger()->info(
			__( 'Campaign email dispatch strategy selected for batch run', 'doublescale' ),
			$data
		);
	}

	/**
	 * Send a batch of emails via cURL Multi
	 *
	 * This method reuses the same logic as send_email_batch but uses
	 * CurlMultiEmailSender instead of BulkEmailSender.
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel                            $campaign Campaign model
	 * @param TemplateModel                            $template Template model
	 * @param \Illuminate\Database\Eloquent\Collection $contacts Collection of contacts
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_curl_multi( CampaignModel $campaign, TemplateModel $template, $contacts ) {
		$skipped_contacts = array();
		$subject          = $template->subject ?? '';
		$body             = $template->body ?? $this->get_default_campaign_content();
		// Include the (raw) footer so its merge tags (e.g. {{contact:unsubscribe_link}})
		// are registered as recipient variables. Without this the footer link is
		// injected after key extraction and the mailer substitutes it with an empty value.
		$content          = $subject . ' ' . $body . ' ' . $this->get_raw_footer_content();

		// Extract merge tags from content
		$merge_tag_keys = MergeTagsManager::instance()->extract_merge_tag_keys( $content );

		// Check if template has conditional sections
		$has_conditional_sections = $this->template_has_conditional_sections( $body );

		if ( $has_conditional_sections ) {
			// Use conditional sections aware processing
			return $this->send_email_batch_curl_multi_with_conditional_sections(
				$campaign,
				$template,
				$contacts,
				$subject,
				$body,
				$merge_tag_keys,
				$skipped_contacts
			);
		}

		// No conditional sections - use standard curl multi processing
		return $this->send_email_batch_curl_multi_standard(
			$campaign,
			$template,
			$contacts,
			$subject,
			$body,
			$merge_tag_keys,
			$skipped_contacts
		);
	}

	/**
	 * Send standard batch via cURL Multi without conditional sections
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel                            $campaign        Campaign model
	 * @param TemplateModel                            $template        Template model
	 * @param \Illuminate\Database\Eloquent\Collection $contacts        Collection of contacts
	 * @param string                                   $subject         Email subject
	 * @param string                                   $body            Template body
	 * @param array                                    $merge_tag_keys  Merge tag keys
	 * @param array                                    &$skipped_contacts Skipped contacts array (by reference)
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_curl_multi_standard(
		CampaignModel $campaign,
		TemplateModel $template,
		$contacts,
		$subject,
		$body,
		$merge_tag_keys,
		&$skipped_contacts
	) {
		$tracking_records    = array();
		$recipients          = array();
		$recipient_variables = array();

		// Create tracking records and prepare recipient data
		foreach ( $contacts as $contact ) {
			$email = $this->get_recipient( $contact );

			// Skip contacts without valid email
			if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$skipped_contacts[] = $contact->id;
				$this->contact_filter->log_skipped_contact(
					$contact->id,
					$campaign->id,
					$this->channel,
					'invalid or missing email'
				);
				continue;
			}

			// Create tracking record
			$tracking = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template->id,
					'mode'        => $this->get_message_mode(),
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::CAMPAIGN,
					'source_id'   => $campaign->id,
					'recipient'   => $email,
					'status'      => TrackingStatus::PENDING,
					'hash_key'    => Utils::generate_hash_key(),
				)
			);

			$tracking_records[ $email ] = $tracking;
			$recipients[]               = $email;
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$tracking->id,
				$merge_tag_keys,
				$contact
			);

			// Get recipient variables with tracking pixel URL
			$variables = MergeTagsManager::instance()->get_merge_tag_values_for_keys_slug_only( $merge_tag_keys, $contact );

			// Add tracking pixel URL for this contact
			$variables['tracking_pixel'] = home_url( '?doublescale=email_open&hash_key=' . $tracking->hash_key );

			// Add unsubscribe URL
			$variables['unsubscribe_url'] = add_query_arg(
				array(
					'doublescale' => 'email_unsubscribe',
					'hash_key'    => $tracking->hash_key,
				),
				home_url()
			);

			$recipient_variables[ $email ] = $variables;
		}

		// If no valid recipients, return early
		if ( empty( $recipients ) ) {
			doublescale_get_logger()->info(
				__( 'No valid recipients in curl multi batch', 'doublescale' ),
				array(
					'code'          => 'curl_multi_email_no_recipients',
					'campaign_id'   => $campaign->id,
					'skipped_count' => count( $skipped_contacts ),
				)
			);
			return array(
				'success' => true,
				'skipped' => count( $skipped_contacts ),
			);
		}

		// Render builder content if needed
		$body = $this->render_builder_content_for_bulk( $body );

		// Get footer content (with merge tags - will be processed per contact)
		$footer = $this->get_curl_multi_footer_content();

		// Inject footer into body
		if ( strpos( $body, '</body>' ) !== false ) {
			$body = str_replace( '</body>', $footer . '</body>', $body );
		} else {
			$body .= $footer;
		}

		// Add tracking pixel placeholder (will be replaced per contact)
		$tracking_pixel = '<img src="{{tracking:tracking_pixel}}" width="1" height="1" style="width:1px;height:1px;" alt="" />';
		if ( strpos( $body, '</body>' ) !== false ) {
			$body = str_replace( '</body>', $tracking_pixel . '</body>', $body );
		} else {
			$body .= $tracking_pixel;
		}

		// Prepare batch data
		$batch_data = array(
			'subject'             => $subject,
			'html'                => $body,
			'from_email'          => $template->get_setting( 'from_email' ) ?: get_option( 'admin_email' ),
			'from_name'           => $template->get_setting( 'from_name' ) ?: get_bloginfo( 'name' ),
			'reply_to'            => $template->get_setting( 'reply_to' ) ?: '',
			'recipients'          => $recipients,
			'recipient_variables' => $recipient_variables,
			'campaign_id'         => $campaign->id,
			'tags'                => array( 'doublescale', 'campaign-' . $campaign->id ),
		);

		// Send via cURL Multi
		$result = $this->with_smtp_campaign_log_context(
			$campaign,
			function () use ( $batch_data ) {
				return \DoubleScale\Modules\Emails\CurlMultiEmailSender::send_batch( $batch_data );
			}
		);

		// Update tracking records based on result
		if ( is_wp_error( $result ) ) {
			// Mark all as failed
			foreach ( $tracking_records as $email => $tracking ) {
				$tracking->status = TrackingStatus::FAILED;
				$tracking->save();
			}

			doublescale_get_logger()->error(
				__( 'Curl Multi email batch failed', 'doublescale' ),
				array(
					'code'            => 'curl_multi_email_batch_failed',
					'campaign_id'     => $campaign->id,
					'error'           => $result->get_error_message(),
					'recipient_count' => count( $recipients ),
				)
			);

			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
				'fatal'   => false,
			);
		}

		// Process individual results
		$sent_count  = $result['sent_count'] ?? 0;
		$failed      = $result['failed'] ?? array();
		$message_ids = $result['message_ids'] ?? array();

		foreach ( $tracking_records as $email => $tracking ) {
			if ( isset( $failed[ $email ] ) ) {
				$tracking->status = TrackingStatus::FAILED;
			} else {
				$tracking->status      = TrackingStatus::SENT;
				$tracking->sent_at     = current_time( 'mysql', true );
				$tracking->external_id = $message_ids[ $email ] ?? '';
			}
			$tracking->save();
		}

		doublescale_get_logger()->info(
			__( 'Curl Multi email batch completed', 'doublescale' ),
			array(
				'code'          => 'curl_multi_email_batch_success',
				'campaign_id'   => $campaign->id,
				'sent_count'    => $sent_count,
				'failed_count'  => count( $failed ),
				'skipped_count' => count( $skipped_contacts ),
			)
		);

		return array(
			'success'    => empty( $failed ),
			'sent_count' => $sent_count,
			'skipped'    => count( $skipped_contacts ),
			'failed'     => $failed,
		);
	}

	/**
	 * Send batch with conditional sections support via cURL Multi
	 *
	 * Groups contacts by template hash and sends separate batches for each group.
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel                            $campaign        Campaign model
	 * @param TemplateModel                            $template        Template model
	 * @param \Illuminate\Database\Eloquent\Collection $contacts        Collection of contacts
	 * @param string                                   $subject         Email subject
	 * @param string                                   $body            Template body (JSON)
	 * @param array                                    $merge_tag_keys  Merge tag keys
	 * @param array                                    &$skipped_contacts Skipped contacts array (by reference)
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_curl_multi_with_conditional_sections(
		CampaignModel $campaign,
		TemplateModel $template,
		$contacts,
		$subject,
		$body,
		$merge_tag_keys,
		&$skipped_contacts
	) {
		// Pre-load relationships for all contacts to optimize condition evaluation
		$this->preload_relationships_for_conditions( $contacts, $body );

		// Group contacts by their template hash
		$contact_groups = array(); // hash => array of contact data

		foreach ( $contacts as $contact ) {
			$email = $this->get_recipient( $contact );

			// Skip contacts without valid email
			if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$skipped_contacts[] = $contact->id;
				$this->contact_filter->log_skipped_contact(
					$contact->id,
					$campaign->id,
					$this->channel,
					'invalid or missing email'
				);
				continue;
			}

			// Compute template hash for this contact
			$hash_result = $this->compute_template_hash_for_contact( $body, $contact );
			$hash        = $hash_result['hash'];
			$section_ids = $hash_result['section_ids'];

			if ( ! isset( $contact_groups[ $hash ] ) ) {
				$contact_groups[ $hash ] = array(
					'section_ids' => $section_ids,
					'contacts'    => array(),
				);
			}

			$contact_groups[ $hash ]['contacts'][] = array(
				'contact' => $contact,
				'email'   => $email,
			);
		}

		if ( empty( $contact_groups ) ) {
			return array(
				'success' => true,
				'skipped' => count( $skipped_contacts ),
			);
		}

		// Log grouping info
		doublescale_get_logger()->info(
			__( 'Contacts grouped by conditional sections for curl multi', 'doublescale' ),
			array(
				'code'        => 'curl_multi_email_conditional_groups',
				'campaign_id' => $campaign->id,
				'group_count' => count( $contact_groups ),
			)
		);

		// Send batch for each group
		$total_sent      = 0;
		$total_failed    = 0;
		$last_error      = null;
		$all_message_ids = array();

		foreach ( $contact_groups as $hash => $group ) {
			$result = $this->send_email_batch_curl_multi_for_group(
				$campaign,
				$template,
				$group['contacts'],
				$group['section_ids'],
				$subject,
				$body,
				$merge_tag_keys
			);

			if ( isset( $result['sent_count'] ) ) {
				$total_sent += $result['sent_count'];
			}
			if ( ! empty( $result['failed'] ) ) {
				$total_failed += count( $result['failed'] );
				$last_error    = is_array( $result['failed'] ) ? reset( $result['failed'] ) : 'Unknown error';
			}
			if ( ! empty( $result['message_ids'] ) ) {
				$all_message_ids = array_merge( $all_message_ids, $result['message_ids'] );
			}
		}

		return array(
			'success'     => $total_failed === 0,
			'message_ids' => $all_message_ids,
			'sent_count'  => $total_sent,
			'skipped'     => count( $skipped_contacts ),
			'error'       => $last_error,
			'fatal'       => false,
		);
	}

	/**
	 * Send cURL Multi batch for a group of contacts with the same template hash
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel $campaign        Campaign model
	 * @param TemplateModel $template        Template model
	 * @param array         $contact_data    Array of contact data (contact, email)
	 * @param array         $section_ids     Array of section IDs that should render
	 * @param string        $subject         Email subject
	 * @param string        $body            Template body (JSON)
	 * @param array         $merge_tag_keys  Merge tag keys
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_curl_multi_for_group(
		CampaignModel $campaign,
		TemplateModel $template,
		$contact_data,
		$section_ids,
		$subject,
		$body,
		$merge_tag_keys
	) {
		$tracking_records    = array();
		$recipients          = array();
		$recipient_variables = array();

		// Create tracking records for all contacts in this group
		foreach ( $contact_data as $data ) {
			$contact = $data['contact'];
			$email   = $data['email'];

			// Create tracking record
			$tracking = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template->id,
					'mode'        => $this->get_message_mode(),
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::CAMPAIGN,
					'source_id'   => $campaign->id,
					'recipient'   => $email,
					'status'      => TrackingStatus::PENDING,
					'hash_key'    => Utils::generate_hash_key(),
				)
			);

			$tracking_records[ $email ] = $tracking;
			$recipients[]               = $email;

			// Capture merge tags for this contact
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$tracking->id,
				$merge_tag_keys,
				$contact
			);

			// Store conditional section IDs for this tracking record
			if ( ! empty( $section_ids ) ) {
				CommunicationTrackingMetaModel::create(
					array(
						'communication_tracking_id' => $tracking->id,
						'meta_key'                  => 'conditional_sections',
						'meta_value'                => $section_ids,
					)
				);
			}

			// Get recipient variables with tracking info
			$variables = MergeTagsManager::instance()->get_merge_tag_values_for_keys_slug_only( $merge_tag_keys, $contact );

			// Add tracking pixel URL for this contact
			$variables['tracking_pixel'] = home_url( '?doublescale=email_open&hash_key=' . $tracking->hash_key );

			// Add unsubscribe URL
			$variables['unsubscribe_url'] = add_query_arg(
				array(
					'doublescale' => 'email_unsubscribe',
					'hash_key'    => $tracking->hash_key,
				),
				home_url()
			);

			$recipient_variables[ $email ] = $variables;
		}

		// Render body with specific section IDs
		$rendered_body = $this->render_builder_content_for_bulk_with_sections( $body, $section_ids );

		// Get footer content
		$footer = $this->get_curl_multi_footer_content();

		// Inject footer into body
		if ( strpos( $rendered_body, '</body>' ) !== false ) {
			$rendered_body = str_replace( '</body>', $footer . '</body>', $rendered_body );
		} else {
			$rendered_body .= $footer;
		}

		// Add tracking pixel placeholder
		$tracking_pixel = '<img src="{{tracking:tracking_pixel}}" width="1" height="1" style="width:1px;height:1px;" alt="" />';
		if ( strpos( $rendered_body, '</body>' ) !== false ) {
			$rendered_body = str_replace( '</body>', $tracking_pixel . '</body>', $rendered_body );
		} else {
			$rendered_body .= $tracking_pixel;
		}

		// Prepare batch data
		$batch_data = array(
			'subject'             => $subject,
			'html'                => $rendered_body,
			'from_email'          => $template->get_setting( 'from_email' ) ?: get_option( 'admin_email' ),
			'from_name'           => $template->get_setting( 'from_name' ) ?: get_bloginfo( 'name' ),
			'reply_to'            => $template->get_setting( 'reply_to' ) ?: '',
			'recipients'          => $recipients,
			'recipient_variables' => $recipient_variables,
			'campaign_id'         => $campaign->id,
			'tags'                => array( 'doublescale', 'campaign-' . $campaign->id ),
		);

		// Send via cURL Multi
		$result = $this->with_smtp_campaign_log_context(
			$campaign,
			function () use ( $batch_data ) {
				return \DoubleScale\Modules\Emails\CurlMultiEmailSender::send_batch( $batch_data );
			}
		);

		// Update tracking records based on result
		if ( is_wp_error( $result ) ) {
			foreach ( $tracking_records as $email => $tracking ) {
				$tracking->status = TrackingStatus::FAILED;
				$tracking->save();
			}

			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		// Process individual results
		$failed      = $result['failed'] ?? array();
		$message_ids = $result['message_ids'] ?? array();

		foreach ( $tracking_records as $email => $tracking ) {
			if ( isset( $failed[ $email ] ) ) {
				$tracking->status = TrackingStatus::FAILED;
			} else {
				$tracking->status      = TrackingStatus::SENT;
				$tracking->sent_at     = current_time( 'mysql', true );
				$tracking->external_id = $message_ids[ $email ] ?? '';
			}
			$tracking->save();
		}

		return array(
			'success'     => empty( $failed ),
			'message_ids' => $message_ids,
			'sent_count'  => $result['sent_count'] ?? 0,
			'failed'      => $failed,
		);
	}

	/**
	 * Get footer content for cURL Multi emails
	 *
	 * Unlike bulk email which converts merge tags to mailer format,
	 * cURL Multi keeps Plugin merge tags as they will be processed per contact.
	 *
	 * @since 1.0.0
	 *
	 * @return string Footer HTML with merge tag placeholders
	 */
	protected function get_curl_multi_footer_content() {
		// Get footer from settings
		if ( ! empty( $this->settings['email_footer'] ) ) {
			$footer = $this->settings['email_footer'];
		} else {
			$global_settings = \DoubleScale\Core\Settings\Settings::get( 'email', array() );
			if ( ! empty( $global_settings['email_footer'] ) ) {
				$footer = $global_settings['email_footer'];
			} else {
				$footer = EmailTrackingHelper::get_default_footer();
			}
		}

		// Keep merge tags as-is for curl multi (they will be processed per contact)
		return $footer;
	}

	/**
	 * Send a batch of emails via bulk Api
	 *
	 * This method supports conditional sections by:
	 * 1. Evaluating which conditional sections apply to each contact
	 * 2. Creating a hash based on the rendered section IDs
	 * 3. Grouping contacts with the same hash (same email body)
	 * 4. Sending one bulk email per group for efficiency
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel                            $campaign Campaign model
	 * @param TemplateModel                            $template Template model
	 * @param \Illuminate\Database\Eloquent\Collection $contacts Collection of contacts
	 *
	 * @return array Result array
	 */
	protected function send_email_batch( CampaignModel $campaign, TemplateModel $template, $contacts ) {
		$skipped_contacts = array();
		$subject          = $template->subject ?? '';
		$body             = $template->body ?? $this->get_default_campaign_content();
		// Include the (raw) footer so its merge tags (e.g. {{contact:unsubscribe_link}})
		// are registered as recipient variables. Without this the footer link is
		// injected after key extraction and the mailer substitutes it with an empty value.
		$content          = $subject . ' ' . $body . ' ' . $this->get_raw_footer_content();

		// Extract merge tags from content
		$merge_tag_keys = MergeTagsManager::instance()->extract_merge_tag_keys( $content );

		// Check if template has conditional sections
		$has_conditional_sections = $this->template_has_conditional_sections( $body );

		if ( $has_conditional_sections ) {
			// Use conditional sections aware processing
			return $this->send_email_batch_with_conditional_sections(
				$campaign,
				$template,
				$contacts,
				$subject,
				$body,
				$merge_tag_keys,
				$skipped_contacts
			);
		}

		// No conditional sections - use standard bulk processing
		return $this->send_email_batch_standard(
			$campaign,
			$template,
			$contacts,
			$subject,
			$body,
			$merge_tag_keys,
			$skipped_contacts
		);
	}

	/**
	 * Check if template body contains conditional sections
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Template body (JSON or HTML)
	 *
	 * @return bool True if template has conditional sections
	 */
	protected function template_has_conditional_sections( $body ) {
		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		if ( ! isset( $decoded['type'] ) || $decoded['type'] !== 'builder' || ! isset( $decoded['value'] ) ) {
			return false;
		}

		$builder_data = $decoded['value'];

		if ( ! isset( $builder_data['sections'] ) || ! is_array( $builder_data['sections'] ) ) {
			return false;
		}

		// Check if any section has conditions
		foreach ( $builder_data['sections'] as $section ) {
			if ( ! empty( $section['conditions'] ) && is_array( $section['conditions'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compute template hash for a contact based on which conditional sections will render
	 *
	 * Uses the shared ConditionEvaluator for in-memory condition evaluation.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $body    Template body (JSON)
	 * @param ContactModel $contact Contact model
	 *
	 * @return array Array with 'hash' (string) and 'section_ids' (array of rendered section IDs)
	 */
	protected function compute_template_hash_for_contact( $body, ContactModel $contact ) {
		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $decoded['value']['sections'] ) ) {
			return array(
				'hash'        => 'default',
				'section_ids' => array(),
			);
		}

		$sections             = $decoded['value']['sections'];
		$rendered_section_ids = array();

		// Check Pro availability
		$is_pro_active = doublescale_is_pro_addon_active();

		// Get condition evaluator instance
		$evaluator = ConditionEvaluator::instance();

		foreach ( $sections as $section ) {
			$section_id     = $section['id'] ?? null;
			$has_conditions = ! empty( $section['conditions'] ) && is_array( $section['conditions'] );

			if ( ! $has_conditions ) {
				// No conditions - section always renders
				if ( $section_id ) {
					$rendered_section_ids[] = $section_id;
				}
				continue;
			}

			// Has conditions - evaluate if should render
			if ( ! $is_pro_active ) {
				// Pro not active - render all sections
				if ( $section_id ) {
					$rendered_section_ids[] = $section_id;
				}
				continue;
			}

			// Use shared ConditionEvaluator for in-memory evaluation
			$matches = $evaluator->evaluate( $section['conditions'], $contact );

			if ( $matches && $section_id ) {
				$rendered_section_ids[] = $section_id;
			}
		}

		// Create hash from rendered section IDs
		sort( $rendered_section_ids );
		$hash = md5( implode( '|', $rendered_section_ids ) );

		return array(
			'hash'        => $hash,
			'section_ids' => $rendered_section_ids,
		);
	}

	/**
	 * Send batch with conditional sections support
	 *
	 * Groups contacts by template hash and sends separate bulk emails for each group.
	 * Pre-loads relationships for all contacts to avoid N+1 queries.
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel                            $campaign        Campaign model
	 * @param TemplateModel                            $template        Template model
	 * @param \Illuminate\Database\Eloquent\Collection $contacts        Collection of contacts
	 * @param string                                   $subject         Email subject
	 * @param string                                   $body            Template body (JSON)
	 * @param array                                    $merge_tag_keys  Merge tag keys
	 * @param array                                    &$skipped_contacts Skipped contacts array (by reference)
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_with_conditional_sections(
		CampaignModel $campaign,
		TemplateModel $template,
		$contacts,
		$subject,
		$body,
		$merge_tag_keys,
		&$skipped_contacts
	) {
		// Pre-load relationships for all contacts to optimize condition evaluation
		$this->preload_relationships_for_conditions( $contacts, $body );

		// Group contacts by their template hash
		$contact_groups = array(); // hash => array of contact data

		foreach ( $contacts as $contact ) {
			$email = $this->get_recipient( $contact );

			// Skip contacts without valid email
			if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$skipped_contacts[] = $contact->id;
				$this->contact_filter->log_skipped_contact(
					$contact->id,
					$campaign->id,
					$this->channel,
					'invalid or missing email'
				);
				continue;
			}

			// Compute template hash for this contact
			$hash_result = $this->compute_template_hash_for_contact( $body, $contact );
			$hash        = $hash_result['hash'];
			$section_ids = $hash_result['section_ids'];

			if ( ! isset( $contact_groups[ $hash ] ) ) {
				$contact_groups[ $hash ] = array(
					'section_ids' => $section_ids,
					'contacts'    => array(),
				);
			}

			$contact_groups[ $hash ]['contacts'][] = array(
				'contact' => $contact,
				'email'   => $email,
			);
		}

		if ( empty( $contact_groups ) ) {
			doublescale_get_logger()->info(
				__( 'No valid recipients in batch (conditional sections)', 'doublescale' ),
				array(
					'code'          => 'bulk_email_no_recipients_conditional',
					'campaign_id'   => $campaign->id,
					'skipped_count' => count( $skipped_contacts ),
				)
			);
			return array(
				'success' => true,
				'skipped' => count( $skipped_contacts ),
			);
		}

		// Log grouping info
		doublescale_get_logger()->info(
			__( 'Contacts grouped by conditional sections', 'doublescale' ),
			array(
				'code'        => 'bulk_email_conditional_groups',
				'campaign_id' => $campaign->id,
				'group_count' => count( $contact_groups ),
				'group_sizes' => array_map(
					function ( $group ) {
						return count( $group['contacts'] );
					},
					$contact_groups
				),
			)
		);

		// Send bulk email for each group
		$total_sent      = 0;
		$total_failed    = 0;
		$last_error      = null;
		$all_message_ids = array();

		foreach ( $contact_groups as $hash => $group ) {
			$result = $this->send_email_batch_for_group(
				$campaign,
				$template,
				$group['contacts'],
				$group['section_ids'],
				$subject,
				$body,
				$merge_tag_keys
			);

			if ( $result['success'] ) {
				$total_sent += $result['sent_count'] ?? 0;
				if ( ! empty( $result['message_id'] ) ) {
					$all_message_ids[] = $result['message_id'];
				}
			} else {
				$total_failed += count( $group['contacts'] );
				$last_error    = $result['error'] ?? 'Unknown error';
			}
		}

		doublescale_get_logger()->info(
			__( 'Bulk email with conditional sections completed', 'doublescale' ),
			array(
				'code'          => 'bulk_email_conditional_complete',
				'campaign_id'   => $campaign->id,
				'groups_sent'   => count( $contact_groups ),
				'total_sent'    => $total_sent,
				'total_failed'  => $total_failed,
				'skipped_count' => count( $skipped_contacts ),
			)
		);

		return array(
			'success'     => $total_failed === 0,
			'message_ids' => $all_message_ids,
			'sent_count'  => $total_sent,
			'skipped'     => count( $skipped_contacts ),
			'error'       => $last_error,
			'fatal'       => false,
		);
	}

	/**
	 * Send bulk email for a group of contacts with the same template hash
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel $campaign        Campaign model
	 * @param TemplateModel $template        Template model
	 * @param array         $contact_data    Array of contact data (contact, email)
	 * @param array         $section_ids     Array of section IDs that should render
	 * @param string        $subject         Email subject
	 * @param string        $body            Template body (JSON)
	 * @param array         $merge_tag_keys  Merge tag keys
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_for_group(
		CampaignModel $campaign,
		TemplateModel $template,
		$contact_data,
		$section_ids,
		$subject,
		$body,
		$merge_tag_keys
	) {
		$tracking_records    = array();
		$recipients          = array();
		$recipient_variables = array();

		// Create tracking records for all contacts in this group
		foreach ( $contact_data as $data ) {
			$contact = $data['contact'];
			$email   = $data['email'];

			// Create tracking record
			$tracking = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template->id,
					'mode'        => $this->get_message_mode(),
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::CAMPAIGN,
					'source_id'   => $campaign->id,
					'recipient'   => $email,
					'status'      => TrackingStatus::PENDING,
					'hash_key'    => Utils::generate_hash_key(),
				)
			);

			$tracking_records[ $email ] = $tracking;
			$recipients[]               = $email;

			// Capture merge tags for this contact
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$tracking->id,
				$merge_tag_keys,
				$contact
			);

			// Store conditional section IDs for this tracking record
			if ( ! empty( $section_ids ) ) {
				CommunicationTrackingMetaModel::create(
					array(
						'communication_tracking_id' => $tracking->id,
						'meta_key'                  => 'conditional_sections',
						'meta_value'                => $section_ids,
					)
				);
			}

			// Get recipient variables for this contact
			$recipient_variables[ $email ] = MergeTagsManager::instance()->get_merge_tag_values_for_keys_slug_only( $merge_tag_keys, $contact );
		}

		// Render body with specific section IDs
		$rendered_body = $this->render_builder_content_for_bulk_with_sections( $body, $section_ids );

		// Get footer content
		$footer = $this->get_bulk_footer_content();

		// Inject footer into body
		if ( strpos( $rendered_body, '</body>' ) !== false ) {
			$rendered_body = str_replace( '</body>', $footer . '</body>', $rendered_body );
		} else {
			$rendered_body .= $footer;
		}

		// Convert Plugin merge tags to mailer-specific recipient variables
		$converted_subject = \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( $subject );
		$converted_body    = \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( $rendered_body );

		// Add tracking pixel
		$tracking_pixel_tag = \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( '{{tracking:tracking_pixel}}' );
		$tracking_pixel     = '<img src="' . $tracking_pixel_tag . '" width="1" height="1" style="width:1px;height:1px;" alt="" />';
		if ( strpos( $converted_body, '</body>' ) !== false ) {
			$converted_body = str_replace( '</body>', $tracking_pixel . '</body>', $converted_body );
		} else {
			$converted_body .= $tracking_pixel;
		}

		// Prepare batch data
		$batch_data = array(
			'subject'             => $converted_subject,
			'html'                => $converted_body,
			'from_email'          => $template->get_setting( 'from_email' ) ?: get_option( 'admin_email' ),
			'from_name'           => $template->get_setting( 'from_name' ) ?: get_bloginfo( 'name' ),
			'reply_to'            => $template->get_setting( 'reply_to' ) ?: '',
			'recipients'          => $recipients,
			'recipient_variables' => $recipient_variables,
			'campaign_id'         => $campaign->id,
			'tags'                => array( 'doublescale', 'campaign-' . $campaign->id ),
		);

		// Send via bulk Api
		$result = $this->with_smtp_campaign_log_context(
			$campaign,
			function () use ( $batch_data ) {
				return \DoubleScale\Modules\Emails\BulkEmailSender::send_batch( $batch_data );
			}
		);

		// Update tracking records based on result
		if ( is_wp_error( $result ) ) {
			foreach ( $tracking_records as $email => $tracking ) {
				$tracking->status = TrackingStatus::FAILED;
				$tracking->save();
			}

			doublescale_get_logger()->error(
				__( 'Bulk email group batch failed', 'doublescale' ),
				array(
					'code'            => 'bulk_email_group_failed',
					'campaign_id'     => $campaign->id,
					'error'           => $result->get_error_message(),
					'recipient_count' => count( $recipients ),
					'section_ids'     => $section_ids,
				)
			);

			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		// Success - mark all tracking records as sent
		$message_id = $result['message_id'] ?? '';
		foreach ( $tracking_records as $email => $tracking ) {
			$tracking->status      = TrackingStatus::SENT;
			$tracking->sent_at     = current_time( 'mysql', true );
			$tracking->external_id = $message_id;
			$tracking->save();
		}

		return array(
			'success'    => true,
			'message_id' => $message_id,
			'sent_count' => count( $recipients ),
		);
	}

	/**
	 * Send standard batch without conditional sections
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel                            $campaign        Campaign model
	 * @param TemplateModel                            $template        Template model
	 * @param \Illuminate\Database\Eloquent\Collection $contacts        Collection of contacts
	 * @param string                                   $subject         Email subject
	 * @param string                                   $body            Template body
	 * @param array                                    $merge_tag_keys  Merge tag keys
	 * @param array                                    &$skipped_contacts Skipped contacts array (by reference)
	 *
	 * @return array Result array
	 */
	protected function send_email_batch_standard(
		CampaignModel $campaign,
		TemplateModel $template,
		$contacts,
		$subject,
		$body,
		$merge_tag_keys,
		&$skipped_contacts
	) {
		$tracking_records    = array();
		$recipients          = array();
		$recipient_variables = array();

		// Create tracking records and prepare recipient data
		foreach ( $contacts as $contact ) {
			$email = $this->get_recipient( $contact );

			// Skip contacts without valid email
			if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$skipped_contacts[] = $contact->id;
				$this->contact_filter->log_skipped_contact(
					$contact->id,
					$campaign->id,
					$this->channel,
					'invalid or missing email'
				);
				continue;
			}

			// Create tracking record
			$tracking = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template->id,
					'mode'        => $this->get_message_mode(),
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::CAMPAIGN,
					'source_id'   => $campaign->id,
					'recipient'   => $email,
					'status'      => TrackingStatus::PENDING,
					'hash_key'    => Utils::generate_hash_key(),
				)
			);

			$tracking_records[ $email ] = $tracking;
			$recipients[]               = $email;
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$tracking->id,
				$merge_tag_keys,
				$contact
			);
			$recipient_variables[ $email ] = MergeTagsManager::instance()->get_merge_tag_values_for_keys_slug_only( $merge_tag_keys, $contact );
		}

		// If no valid recipients, return early
		if ( empty( $recipients ) ) {
			doublescale_get_logger()->info(
				__( 'No valid recipients in batch', 'doublescale' ),
				array(
					'code'          => 'bulk_email_no_recipients',
					'campaign_id'   => $campaign->id,
					'skipped_count' => count( $skipped_contacts ),
				)
			);
			return array(
				'success' => true,
				'skipped' => count( $skipped_contacts ),
			);
		}

		// Render builder content if needed (without personalization - that's handled by recipient variables)
		$body = $this->render_builder_content_for_bulk( $body ); // to return html without change format

		// Get footer content
		$footer = $this->get_bulk_footer_content(); // to return html with convert merge tags format to mailer format

		// Inject footer into body (for builder emails)
		if ( strpos( $body, '</body>' ) !== false ) {
			$body = str_replace( '</body>', $footer . '</body>', $body );
		} else {
			$body .= $footer;
		}

		// Convert Plugin merge tags to mailer-specific recipient variables
		$subject = \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( $subject );
		$body    = \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( $body );

		// Add tracking pixel using recipient variable (mailer-specific format)
		$tracking_pixel_tag = \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( '{{tracking:tracking_pixel}}' );
		$tracking_pixel     = '<img src="' . $tracking_pixel_tag . '" width="1" height="1" style="width:1px;height:1px;" alt="" />';
		if ( strpos( $body, '</body>' ) !== false ) {
			$body = str_replace( '</body>', $tracking_pixel . '</body>', $body );
		} else {
			$body .= $tracking_pixel;
		}

		// Prepare batch data
		$batch_data = array(
			'subject'             => $subject,
			'html'                => $body,
			'from_email'          => $template->get_setting( 'from_email' ) ?: get_option( 'admin_email' ),
			'from_name'           => $template->get_setting( 'from_name' ) ?: get_bloginfo( 'name' ),
			'reply_to'            => $template->get_setting( 'reply_to' ) ?: '',
			'recipients'          => $recipients,
			'recipient_variables' => $recipient_variables,
			'campaign_id'         => $campaign->id,
			'tags'                => array( 'doublescale', 'campaign-' . $campaign->id ),
		);

		// Send via bulk Api
		$result = $this->with_smtp_campaign_log_context(
			$campaign,
			function () use ( $batch_data ) {
				return \DoubleScale\Modules\Emails\BulkEmailSender::send_batch( $batch_data );
			}
		);

		// Update tracking records based on result
		if ( is_wp_error( $result ) ) {
			// Mark all as failed
			foreach ( $tracking_records as $email => $tracking ) {
				$tracking->status = TrackingStatus::FAILED;
				$tracking->save();
			}

			doublescale_get_logger()->error(
				__( 'Bulk email batch failed', 'doublescale' ),
				array(
					'code'            => 'bulk_email_batch_failed',
					'campaign_id'     => $campaign->id,
					'error'           => $result->get_error_message(),
					'recipient_count' => count( $recipients ),
				)
			);

			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
				'fatal'   => false,
			);
		}

		// Success - mark all tracking records as sent
		$message_id = $result['message_id'] ?? '';
		foreach ( $tracking_records as $email => $tracking ) {
			$tracking->status      = TrackingStatus::SENT;
			$tracking->sent_at     = current_time( 'mysql', true );
			$tracking->external_id = $message_id;
			$tracking->save();
		}

		doublescale_get_logger()->info(
			__( 'Bulk email batch sent successfully', 'doublescale' ),
			array(
				'code'          => 'bulk_email_batch_success',
				'campaign_id'   => $campaign->id,
				'message_id'    => $message_id,
				'sent_count'    => count( $recipients ),
				'skipped_count' => count( $skipped_contacts ),
			)
		);

		return array(
			'success'    => true,
			'message_id' => $message_id,
			'sent_count' => count( $recipients ),
			'skipped'    => count( $skipped_contacts ),
		);
	}

	/**
	 * Render builder content for bulk sending (without personalization)
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content to render
	 *
	 * @return string Rendered HTML
	 */
	protected function render_builder_content_for_bulk( $content ) {
		// Try to decode as JSON
		$decoded = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $content; // Not JSON, return as-is
		}

		// Check if it's builder format
		if ( ! isset( $decoded['type'] ) || $decoded['type'] !== 'builder' || ! isset( $decoded['value'] ) ) {
			return $content; // Not builder format
		}

		// Render without contact context (merge tags will be handled by Mailgun)
		if ( class_exists( '\DoubleScale\Modules\Emails\EmailRenderer' ) ) {
			$renderer     = new \DoubleScale\Modules\Emails\EmailRenderer();
			$builder_data = $decoded['value'];

			// Render with null contact - merge tags stay as placeholders
			$html = $renderer->render_from_builder_data( $builder_data, null, '', '' );

			return $html;
		}

		return $content;
	}

	/**
	 * Render builder content for bulk sending with specific section IDs
	 *
	 * This method renders only the sections whose IDs are in the provided array.
	 * Used for conditional sections where different contacts see different sections.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content     Content to render (JSON)
	 * @param array  $section_ids Array of section IDs that should be rendered
	 *
	 * @return string Rendered HTML
	 */
	protected function render_builder_content_for_bulk_with_sections( $content, $section_ids ) {
		// Try to decode as JSON
		$decoded = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $content; // Not JSON, return as-is
		}

		// Check if it's builder format
		if ( ! isset( $decoded['type'] ) || $decoded['type'] !== 'builder' || ! isset( $decoded['value'] ) ) {
			return $content; // Not builder format
		}

		$builder_data = $decoded['value'];

		// Filter sections based on provided section IDs
		if ( isset( $builder_data['sections'] ) && is_array( $builder_data['sections'] ) ) {
			$filtered_sections = array();

			foreach ( $builder_data['sections'] as $section ) {
				$section_id     = $section['id'] ?? null;
				$has_conditions = ! empty( $section['conditions'] ) && is_array( $section['conditions'] );

				if ( ! $has_conditions ) {
					// No conditions - always include
					$filtered_sections[] = $section;
				} elseif ( $section_id && in_array( $section_id, $section_ids, true ) ) {
					// Has conditions and is in the render list - include
					// Remove conditions from section to prevent re-evaluation during render
					unset( $section['conditions'] );
					$filtered_sections[] = $section;
				}
				// If has conditions but not in section_ids, skip (don't include)
			}

			$builder_data['sections'] = $filtered_sections;
		}

		// Render without contact context (merge tags will be handled by Mailgun)
		if ( class_exists( '\DoubleScale\Modules\Emails\EmailRenderer' ) ) {
			$renderer = new \DoubleScale\Modules\Emails\EmailRenderer();

			// Render with null contact - merge tags stay as placeholders
			$html = $renderer->render_from_builder_data( $builder_data, null, '', '' );

			return $html;
		}

		return $content;
	}

	/**
	 * Get footer content for bulk emails
	 *
	 * @since 1.0.0
	 *
	 * @return string Footer HTML with recipient variable placeholders
	 */
	protected function get_bulk_footer_content() {
		// Convert merge tags to mailer-specific format
		return \DoubleScale\Modules\Emails\BulkEmailSender::convert_merge_tags_to_recipient_variables( $this->get_raw_footer_content() );
	}

	/**
	 * Get the raw (unconverted) email footer content, respecting the settings hierarchy.
	 *
	 * Returns the footer with its merge tags intact (e.g. {{contact:unsubscribe_link}}),
	 * before any mailer-specific recipient-variable conversion. Used both for rendering
	 * and for merge-tag key extraction so footer-only tags get per-recipient values.
	 *
	 * @since 1.1.10
	 *
	 * @return string Raw footer HTML with merge tags intact.
	 */
	protected function get_raw_footer_content() {
		if ( ! empty( $this->settings['email_footer'] ) ) {
			return $this->settings['email_footer'];
		}

		$global_settings = \DoubleScale\Core\Settings\Settings::get( 'email', array() );
		if ( ! empty( $global_settings['email_footer'] ) ) {
			return $global_settings['email_footer'];
		}

		return EmailTrackingHelper::get_default_footer();
	}

	/**
	 * Pre-load relationships needed for condition evaluation
	 *
	 * Analyzes template conditions to determine which relationships need to be loaded,
	 * then loads them all at once to avoid N+1 queries.
	 *
	 * @since 1.0.0
	 *
	 * @param \Illuminate\Database\Eloquent\Collection $contacts Contacts collection
	 * @param string                                   $body     Template body (JSON)
	 *
	 * @return void
	 */
	protected function preload_relationships_for_conditions( $contacts, $body ) {
		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $decoded['value']['sections'] ) ) {
			return;
		}

		// Collect all conditions from all sections
		$all_conditions = array();
		foreach ( $decoded['value']['sections'] as $section ) {
			if ( ! empty( $section['conditions'] ) && is_array( $section['conditions'] ) ) {
				$all_conditions = array_merge( $all_conditions, $section['conditions'] );
			}
		}

		if ( empty( $all_conditions ) ) {
			return;
		}

		// Use the ConditionEvaluator to preload needed relationships
		ConditionEvaluator::instance()->preload_relationships( $contacts, $all_conditions );
	}

	/**
	 * Get campaign message mode
	 *
	 * @return int
	 */
	public function get_message_mode() {
		return CommunicationTrackingModel::MODE_EMAIL;
	}

	/**
	 * Get channel context for merge tags
	 *
	 * @return string
	 */
	public function get_channel_context() {
		return 'email';
	}

	/**
	 * Get recipient field from contact
	 *
	 * @param ContactModel $contact
	 * @return string|null
	 */
	protected function get_recipient( ContactModel $contact ) {
		return $contact->email;
	}

	/**
	 * Prepare message content - Override to inject footer during builder rendering
	 *
	 * This method follows the same flow as the parent but adds builder email support:
	 * 1. Prepare footer (with merge tags) before rendering
	 * 2. Render builder content with footer injection
	 * 3. Process all merge tags (body + footer)
	 * 4. Add click tracking and unsubscribe links
	 *
	 * @param TemplateModel                                                               $template Template model
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model
	 * @param CommunicationTrackingModel                                                  $campaign_message Campaign tracking record
	 * @return array Message data array with subject, body, recipient, hash_key
	 */
	protected function prepare_message_content( TemplateModel $template, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ) {
		$subject         = $template->subject ?? '';
		$message         = $template->body ?? $this->get_default_campaign_content();
		$add_unsubscribe = $template->get_setting( 'add_unsubscribe', true );

		// Extract actual contact for operations that need ContactModel
		$contact = $contact_or_automation_contact instanceof \DoubleScale\Modules\Automations\Models\AutomationContactModel
			? $contact_or_automation_contact->contact
			: $contact_or_automation_contact;

		// STEP 1: Extract merge tag keys if not already cached
		// Include the (raw) footer so footer-only merge tags (e.g. {{contact:unsubscribe_link}})
		// are captured into stored values and resolve correctly in historical/preview renders.
		if ( is_null( $this->template_merge_tag_keys ) ) {
			$combined_content              = $subject . ' ' . $message . ' ' . $this->get_raw_footer_content();
			$this->template_merge_tag_keys = MergeTagsManager::instance()->extract_merge_tag_keys( $combined_content );
		}

		// STEP 2: Capture merge tag values for this contact using pre-extracted keys
		if ( ! empty( $this->template_merge_tag_keys ) ) {
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$campaign_message->id,
				$this->template_merge_tag_keys,
				$contact_or_automation_contact
			);
		}

		// Prepare footer HTML before rendering (for builder emails only)
		// Footer contains merge tags that will be processed after rendering
		$footer_html = $this->prepare_footer_html( $message, $contact, $campaign_message );

		// Check if the message is in builder JSON format and render it to HTML
		// Pass footer_html so it gets injected before </body> tag
		// Use the original contact model for merge tags
		// IMPORTANT: Use render_builder_content_with_tracking() to capture conditional section IDs
		$renderer = null;
		$message  = $this->render_builder_content_with_tracking( $message, $contact_or_automation_contact, $campaign_message->id, $renderer, $footer_html );

		// Set channel context for merge tags
		add_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

		// Process merge tags in both body and footer (if footer was injected)
		// Use the original contact model to support automation merge tags
		$processed_message = MergeTagsManager::instance()->process_merge_tags( $message, $contact_or_automation_contact );
		$processed_subject = MergeTagsManager::instance()->process_merge_tags( $subject, $contact_or_automation_contact );

		// Remove filter to prevent pollution
		remove_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

		// Add click tracking to URLs in the message (if tracking class supports it)
		$tracking_class = $this->get_tracking_class();
		if ( method_exists( $tracking_class, 'add_click_tracking' ) ) {
			$tracked_message = $tracking_class::add_click_tracking( $processed_message, $campaign_message->hash_key );
		} else {
			$tracked_message = $processed_message;
		}

		// Add unsubscribe link if enabled (if tracking class supports it)
		if ( $add_unsubscribe && method_exists( $tracking_class, 'add_unsubscribe_link' ) ) {
			$tracked_message = $tracking_class::add_unsubscribe_link( $tracked_message, $campaign_message->hash_key );
		}

		return array(
			'subject'   => $processed_subject,
			'body'      => $tracked_message,
			'recipient' => $campaign_message->recipient,
			'hash_key'  => $campaign_message->hash_key,
		);
	}

	/**
	 * Prepare footer HTML for injection into builder emails
	 *
	 * IMPORTANT: This method is called BEFORE merge tags are processed in the body.
	 * We prepare the footer with merge tags here, but they will be processed later
	 * in prepare_message_content() along with the body content.
	 *
	 * @param string                     $message Original message content (JSON for builder emails, HTML otherwise).
	 * @param ContactModel               $contact Contact model
	 * @param CommunicationTrackingModel $campaign_message Campaign tracking record
	 * @return string Footer HTML with tracking pixel (or empty if not builder email)
	 */
	private function prepare_footer_html( $message, ContactModel $contact, CommunicationTrackingModel $campaign_message ) {
		// Check if message is builder format (JSON)
		$decoded          = json_decode( $message, true );
		$is_builder_email = ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['type'] ) && $decoded['type'] === 'builder' );

		// Only prepare footer for builder emails
		// Non-builder emails use the old add_footer_and_tracking() method in send_message()
		if ( ! $is_builder_email ) {
			doublescale_get_logger()->debug(
				'Skipping footer preparation - not a builder email',
				array(
					'source'   => 'email-campaign-processing',
					'is_json'  => ( json_last_error() === JSON_ERROR_NONE ),
					'has_type' => isset( $decoded['type'] ),
				)
			);
			return '';
		}

		// Get footer content (respecting settings hierarchy)
		if ( ! empty( $this->settings['email_footer'] ) ) {
			$email_footer  = $this->settings['email_footer'];
			$footer_source = 'campaign_settings';
		} else {
			$global_settings = \DoubleScale\Core\Settings\Settings::get( 'email', array() );
			// Check if global setting has non-empty footer.
			if ( ! empty( $global_settings['email_footer'] ) ) {
				$email_footer  = $global_settings['email_footer'];
				$footer_source = 'global_settings';
			} else {
				// Use default footer if campaign and global settings are both empty.
				$email_footer  = EmailTrackingHelper::get_default_footer();
				$footer_source = 'default';
			}
		}

		// Add tracking pixel to footer
		$tracking_pixel = sprintf(
			'<img src="%s" width="1" height="1" style="width:1px;height:1px;" alt="" />',
			home_url( '?doublescale=email_open&hash_key=' . $campaign_message->hash_key )
		);

		$footer_html = $email_footer . $tracking_pixel;

		// Log footer preparation for debugging
		doublescale_get_logger()->debug(
			'Prepared footer for builder email',
			array(
				'source'                    => 'email-campaign-processing',
				'footer_source'             => $footer_source,
				'footer_length'             => strlen( $footer_html ),
				'email_footer_length'       => strlen( $email_footer ),
				'has_unsubscribe_merge_tag' => ( strpos( $email_footer, '{{contact:unsubscribe_link}}' ) !== false ),
				'campaign_settings_empty'   => empty( $this->settings['email_footer'] ),
				'global_settings_empty'     => empty( $global_settings['email_footer'] ),
				'email_footer_preview'      => substr( $email_footer, 0, 100 ),
			)
		);

		// Return footer with tracking pixel
		// NOTE: Merge tags in footer will be processed in prepare_message_content()
		// after the builder content is rendered, ensuring consistent processing
		return $footer_html;
	}

	/**
	 * Merge CRM deep-link info into the SMTP module email log for this send.
	 *
	 * @template T
	 * @param CampaignModel $campaign Campaign (broadcast, email sequence, or sequence step).
	 * @param callable(): T $callback Operation that triggers wp_mail / SMTP send + log.
	 * @return T
	 */
	protected function with_smtp_campaign_log_context( CampaignModel $campaign, callable $callback ) {
		if ( ! class_exists( EmailLogContext::class ) ) {
			return $callback();
		}
		EmailLogContext::push( CampaignEmailLogSource::for_campaign( $campaign ) );
		try {
			return $callback();
		} finally {
			EmailLogContext::pop();
		}
	}

	/**
	 * Send message
	 *
	 * @param array                      $message_data Prepared message data
	 * @param ContactModel               $contact Contact model
	 * @param CommunicationTrackingModel $campaign_message Campaign tracking record
	 * @return array Result array with 'success' boolean and optional data
	 */
	protected function send_message( $message_data, ContactModel $contact, CommunicationTrackingModel $campaign_message ) {
		$template = null;
		$emails   = null;

		try {
			// Validate recipient email
			if ( ! filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
				throw new \Exception( "Invalid email address: {$contact->email}" );
			}

			// Get template to access from_email settings early for debugging
			$template = $campaign_message->template;

			// Check if this is a builder email (complete HTML document)
			$is_builder_email = ( strpos( $message_data['body'], '<!DOCTYPE html' ) !== false || strpos( $message_data['body'], '<html' ) !== false );

			// Builder emails inject the footer and tracking pixel during render;
			// for plain-HTML emails the helper appends them here instead.
			if ( ! $is_builder_email ) {
				doublescale_get_logger()->debug(
					'Appending footer to plain-HTML email',
					array(
						'source'      => 'email-campaign-processing',
						'contact_id'  => $contact->id,
						'body_length' => strlen( $message_data['body'] ),
					)
				);

				// Build complete email message with footer and tracking (using shared helper)
				$complete_message = EmailTrackingHelper::add_footer_and_tracking(
					$message_data['body'],
					$campaign_message,
					$contact,
					$this->settings
				);
			} else {
				doublescale_get_logger()->debug(
					'Builder email detected - footer should already be injected',
					array(
						'source'             => 'email-campaign-processing',
						'contact_id'         => $contact->id,
						'body_length'        => strlen( $message_data['body'] ),
						'has_email_footer'   => ( strpos( $message_data['body'], '<!-- Email Footer -->' ) !== false ),
						'has_tracking_pixel' => ( strpos( $message_data['body'], 'doublescale=email_open' ) !== false ),
					)
				);

				// Builder email - footer and tracking already injected
				$complete_message = $message_data['body'];
			}

			// Add click tracking to all links (using shared helper with UTM support)
			$complete_message = EmailTrackingHelper::add_click_tracking(
				$complete_message,
				$campaign_message->hash_key,
				$contact,
				$template
			);

			$emails = new Emails();
			// Set from_email, from_name, and reply_to from template if available
			if ( $template && $template->get_setting( 'from_email' ) ) {
				$emails->from_address = $template->get_setting( 'from_email' );
			}
			if ( $template && $template->get_setting( 'from_name' ) ) {
				$emails->from_name = $template->get_setting( 'from_name' );
			}
			if ( $template && $template->get_setting( 'reply_to' ) ) {
				$emails->reply_to = $template->get_setting( 'reply_to' );
			}

			// Set unsubscribe URL for List-Unsubscribe header (RFC 8058 compliance)
			$emails->unsubscribe_url = add_query_arg(
				array(
					'doublescale' => 'email_unsubscribe',
					'hash_key'    => $campaign_message->hash_key,
				),
				home_url()
			);

			$campaign_for_log = $campaign_message->get_campaign();
			if ( $campaign_for_log && class_exists( EmailLogContext::class ) ) {
				$result = $this->with_smtp_campaign_log_context(
					$campaign_for_log,
					function () use ( $emails, $contact, $message_data, $complete_message ) {
						return $emails->send(
							$contact->email,
							$message_data['subject'],
							$complete_message
						);
					}
				);
			} else {
				$result = $emails->send(
					$contact->email,
					$message_data['subject'],
					$complete_message
				);
			}

			// Proper result validation - prevent false positives
			if ( is_wp_error( $result ) ) {
				throw new \Exception( 'WP Mail Error: ' . $result->get_error_message() );
			} elseif ( $result === false || $result === null ) {
				$detail = \DoubleScale\Modules\Emails\Emails::get_last_send_failure_detail();
				$msg    = 'Email sending failed - wp_mail returned false';
				if ( '' !== $detail ) {
					$msg .= ': ' . $detail;
				}
				throw new \Exception( $msg );
			}

			return array(
				'success'    => true,
				'message_id' => $result,
			);
		} catch ( \Exception $e ) {
			// Enhanced error logging with debugging information
			$debug_info = array(
				'code'                => 'email_send_error',
				'error'               => $e->getMessage(),
				'contact_id'          => $contact->id,
				'campaign_message_id' => $campaign_message->id,
				'recipient'           => $contact->email,
				'template_id'         => $campaign_message->template_id,
				'from_address'        => $emails->from_address ?? 'not set',
				'from_name'           => $emails->from_name ?? 'not set',
				'admin_email'         => get_option( 'admin_email' ),
				'smtp_active'         => class_exists( '\DoubleScale\Modules\Smtp\Settings', false )
					|| class_exists( '\DoubleScale\Modules\Smtp\Module', false ),
				'wp_mail_available'   => function_exists( 'wp_mail' ),
				'template_settings'   => $template ? json_encode( $template->settings ) : 'no template',
			);

			doublescale_get_logger()->error(
				__( 'Email send error with debug info.', 'doublescale' ),
				$debug_info
			);

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
				'debug'   => $debug_info,
			);
		}
	}

	/**
	 * Get tracking class
	 *
	 * @return string
	 */
	protected function get_tracking_class() {
		return Email::class;
	}

	/**
	 * Get default campaign content
	 *
	 * @return string
	 */
	protected function get_default_campaign_content() {
		if ( method_exists( $this, 'get_default_email_content' ) ) {
			return $this->get_default_email_content();
		}

		$greeting = sprintf(
			/* translators: %1$s: first name merge tag, %2$s: last name merge tag */
			__( 'Hi %1$s %2$s,', 'doublescale' ),
			'{{contact:first_name}}',
			'{{contact:last_name}}'
		);
		$thank_you       = __( 'Thank you for subscribing to our updates.', 'doublescale' );
		$unsubscribe_txt = __( 'Unsubscribe', 'doublescale' );

		return '<p>' . esc_html( $greeting ) . '</p><p>' . esc_html( $thank_you ) . '</p><p><a href="{{contact:unsubscribe_link}}">' . esc_html( $unsubscribe_txt ) . '</a></p>';
	}

	/**
	 * Get default email content
	 *
	 * @return string
	 */
	protected function get_default_email_content() {
		$greeting = sprintf(
			/* translators: %1$s: first name merge tag, %2$s: last name merge tag */
			__( 'Hi %1$s %2$s,', 'doublescale' ),
			'{{contact:first_name}}',
			'{{contact:last_name}}'
		);
		$thank_you       = __( 'Thank you for subscribing to our updates.', 'doublescale' );
		$unsubscribe_msg = __( "Don't want to stay in the loop? We'll be sad to see you go, but you can click here to", 'doublescale' );
		$unsubscribe_txt = __( 'unsubscribe', 'doublescale' );

		$default_content = '<div><p>' . esc_html( $greeting ) . '</p><p>' . esc_html( $thank_you ) . '</p><p>' . esc_html( $unsubscribe_msg ) . ' <a href="{{contact:unsubscribe_link}}" target="_blank">' . esc_html( $unsubscribe_txt ) . '</a>.</p></div>';

		return apply_filters( 'doublescale_default_mail_content', $default_content );
	}
}
