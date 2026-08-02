<?php
/**
 * Email Individual Message Sender (Free).
 *
 * Sends a single email from the CRM admin to a contact via Free's Emails
 * helper, recording an Activity row plus a CommunicationTracking entry and
 * applying email-specific tracking (open pixel + click tracking) to the
 * outbound body.
 *
 * Standalone on purpose: Pro's AbstractIndividualMessageSender exists to share
 * provider plumbing across email / SMS / WhatsApp, but Free only ships email
 * and uses wp_mail rather than the MessageProviderRegistry, so a flat class
 * keeps the dependency surface small and avoids pulling Pro-only abstracts.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Inbox\IndividualMessaging;

use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Emails\EmailTrackingHelper;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

defined( 'ABSPATH' ) || exit;

class EmailIndividualSender {

	public function send( WP_REST_Request $request ) {
		$contact_id  = (int) $request->get_param( 'id' );
		$to          = (string) $request->get_param( 'to' );
		$body        = (string) ( $request->get_param( 'body' ) ?? $request->get_param( 'message' ) ?? '' );
		$subject     = $request->get_param( 'subject' );
		$deal_id     = $request->get_param( 'deal_id' );
		$project_id  = $request->get_param( 'project_id' );
		$in_reply_to = $request->get_param( 'in_reply_to' );

		if ( empty( $subject ) || ! trim( (string) $subject ) ) {
			return new WP_Error(
				'missing_subject',
				__( 'Subject is required for email messages.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( ! filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Invalid email address', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$contact = ContactModel::find( $contact_id );
		if ( ! $contact ) {
			return new WP_Error( 'not_found', __( 'Contact not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		$tracking_entry = null;
		$activity       = null;

		try {
			$processed_subject = MergeTagsManager::instance()->process_merge_tags( (string) $subject, $contact );
			$processed_body    = MergeTagsManager::instance()->process_merge_tags( $body, $contact );

			$activity = ActivityModel::create(
				array(
					'contact_id'    => $contact->id,
					'activity_type' => 'email_sent',
					'data'          => array_filter(
						array(
							'subject'       => $processed_subject,
							'body'          => $processed_body,
							'contact_email' => $to,
							'in_reply_to'   => $in_reply_to,
							'source'        => 'sent',
						),
						static function ( $value ) {
							return null !== $value;
						}
					),
					'user_id'       => get_current_user_id(),
				)
			);

			if ( $deal_id ) {
				ActivityAssociationModel::create(
					array(
						'activity_id' => $activity->id,
						'entity_type' => ActivityAssociationModel::ENTITY_TYPE_DEAL,
						'entity_id'   => (int) $deal_id,
						'created_at'  => current_time( 'mysql' ),
						'updated_at'  => current_time( 'mysql' ),
					)
				);
			}

			if ( $project_id ) {
				ActivityAssociationModel::create(
					array(
						'activity_id' => $activity->id,
						'entity_type' => ActivityAssociationModel::ENTITY_TYPE_PROJECT,
						'entity_id'   => (int) $project_id,
						'created_at'  => current_time( 'mysql' ),
						'updated_at'  => current_time( 'mysql' ),
					)
				);
			}

			$tracking_entry = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => null,
					'hash_key'    => Utils::generate_hash_key(),
					'mode'        => CommunicationTrackingModel::MODE_EMAIL,
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::INDIVIDUAL,
					'source_id'   => $activity->id,
					'author_id'   => get_current_user_id(),
					'recipient'   => $to,
					'status'      => TrackingStatus::PENDING,
				)
			);

			$sendable_body = EmailTrackingHelper::add_tracking_pixel( $processed_body, $tracking_entry );
			$sendable_body = EmailTrackingHelper::add_click_tracking( $sendable_body, $tracking_entry->hash_key, $contact );

			$result = $this->send_via_wp_mail( $to, $processed_subject, $sendable_body, $in_reply_to );

			if ( ! $result['success'] ) {
				throw new Exception( $result['error'] );
			}

			$tracking_entry->update(
				array(
					'status'      => TrackingStatus::SENT,
					'sent_at'     => current_time( 'mysql', true ),
					'external_id' => $result['message_id'] ?? null,
				)
			);

			if ( ! empty( $result['from_email'] ) || ! empty( $result['message_id'] ) ) {
				$activity_data = $activity->data;
				if ( ! is_array( $activity_data ) ) {
					$activity_data = json_decode( (string) $activity_data, true ) ?: array();
				}
				if ( ! empty( $result['from_email'] ) ) {
					$activity_data['from_email'] = $result['from_email'];
				}
				if ( ! empty( $result['from_name'] ) ) {
					$activity_data['from_name'] = $result['from_name'];
				}
				if ( ! empty( $result['message_id'] ) ) {
					$activity_data['message_id'] = $result['message_id'];
				}
				$activity->update( array( 'data' => $activity_data ) );
			}

			doublescale_get_logger()->info(
				__( 'Individual email sent successfully', 'doublescale' ),
				array(
					'source'      => 'inbox-individual-email',
					'contact_id'  => $contact->id,
					'activity_id' => $activity->id,
					'tracking_id' => $tracking_entry->id,
					'author_id'   => get_current_user_id(),
					'recipient'   => $to,
					'external_id' => $result['message_id'] ?? null,
				)
			);

			return new WP_REST_Response(
				array(
					'success'     => true,
					'message'     => __( 'Email sent successfully', 'doublescale' ),
					'activity_id' => $activity->id,
					'tracking_id' => $tracking_entry->id,
				),
				200
			);
		} catch ( Exception $e ) {
			if ( $tracking_entry ) {
				$tracking_entry->update( array( 'status' => TrackingStatus::FAILED ) );
				CommunicationTrackingMetaModel::store_error_info(
					$tracking_entry->id,
					$e->getCode() ? (string) $e->getCode() : 'send_error',
					$e->getMessage()
				);
			}

			doublescale_get_logger()->error(
				__( 'Individual email send exception', 'doublescale' ),
				array(
					'source'      => 'inbox-individual-email',
					'error'       => $e->getMessage(),
					'contact_id'  => $contact_id,
					'activity_id' => $activity->id ?? null,
					'tracking_id' => $tracking_entry->id ?? null,
				)
			);

			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	private function send_via_wp_mail( $to, $subject, $body, $in_reply_to ) {
		try {
			$emails = new Emails();

			$message_id         = '<' . md5( uniqid( (string) wp_rand(), true ) ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . '>';
			$emails->message_id = $message_id;

			if ( ! empty( $in_reply_to ) ) {
				$emails->in_reply_to = $in_reply_to;
			}

			$result = $emails->send( $to, $subject, $body );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => 'WP Mail Error: ' . $result->get_error_message(),
				);
			}

			if ( false === $result || null === $result ) {
				$error  = __( 'Email sending failed.', 'doublescale' );
				$detail = Emails::get_last_send_failure_detail();
				if ( '' !== $detail ) {
					$error .= ' ' . sprintf(
						/* translators: %s: technical detail from PHPMailer or WordPress */
						__( 'Details: %s', 'doublescale' ),
						$detail
					);
				}
				return array(
					'success' => false,
					'error'   => $error,
				);
			}

			return array(
				'success'    => true,
				'message_id' => $message_id,
				'from_email' => $emails->get_from_address(),
				'from_name'  => $emails->get_from_name(),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
