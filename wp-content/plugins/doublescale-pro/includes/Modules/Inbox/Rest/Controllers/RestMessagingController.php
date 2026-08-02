<?php
/**
 * REST Messaging Controller
 * Handles Email, Sms and WhatsApp messaging endpoints
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\UserRoles\Permissions;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * RestMessagingController class
 */
class RestMessagingController extends RestController {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->namespace = 'doublescale/v1';
		$this->rest_base = '';
	}

	/**
	 * Register routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Individual messaging endpoint. Pass override=true so Pro's registration
		// replaces Free's earlier registration for this URI rather than stacking
		// alongside it - otherwise Free's stricter args (e.g. required: body)
		// run validation first and reject WhatsApp/SMS calls that don't carry a body.
		register_rest_route(
			$this->namespace,
			'/contacts/(?P<id>\d+)/send-message',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_message' ),
					'permission_callback' => array( $this, 'send_message_permissions_check' ),
				'args'                => array(
					'id'      => array(
						'description' => __( 'Contact ID.', 'doublescale'),
						'type'        => 'integer',
						'required'    => true,
					),
					'channel' => array(
						'description' => __( 'Communication channel: email, sms or whatsapp.', 'doublescale'),
						'type'        => 'string',
						'required'    => true,
						'enum'        => array( 'email', 'sms', 'whatsapp' ),
					),
					'to'      => array(
						'description' => __( 'Recipient (email address or phone number in E.164 format).', 'doublescale'),
						'type'        => 'string',
						'required'    => true,
					),
					'body'    => array(
						'description' => __( 'Message body (not used for WhatsApp templates - use template_id instead).', 'doublescale'),
						'type'        => 'string',
						'required'    => false,
					),
					'subject' => array(
						'description' => __( 'Email subject (required for email channel).', 'doublescale'),
						'type'        => 'string',
						'required'    => false,
					),
					'template_id' => array(
						'description' => __( 'Template ID (required for WhatsApp template messages).', 'doublescale'),
						'type'        => 'integer',
						'required'    => false,
					),
					'template_variables' => array(
						'description' => __( 'Template variables for WhatsApp templates (e.g., {"1": "John", "2": "ORDER123"}).', 'doublescale'),
						'type'        => 'object',
						'required'    => false,
					),
					'message' => array(
						'description' => __( 'Free-text message for WhatsApp session messages (within 24h conversation window).', 'doublescale'),
						'type'        => 'string',
						'required'    => false,
					),
					'deal_id' => array(
						'description' => __( 'Link the resulting activity to this deal.', 'doublescale'),
						'type'        => 'integer',
						'required'    => false,
					),
					'project_id' => array(
						'description' => __( 'Link the resulting activity to this project.', 'doublescale'),
						'type'        => 'integer',
						'required'    => false,
					),
					'in_reply_to' => array(
						'description' => __( 'Message-ID of the email being replied to.', 'doublescale'),
						'type'        => 'string',
						'required'    => false,
					),
				),
				),
			),
			true
		);

	}

	/**
	 * Send message to contact (Email/Sms/Whatsapp)
	 *
	 * This endpoint extends the free version by handling Sms and WhatsApp channels.
	 * Both plugins register on /doublescale/v1/contacts/{id}/send-message, and Pro's
	 * registration takes precedence when active. Email messages are delegated
	 * to the free version's EmailIndividualSender class.
	 *
	 * Note: WhatsApp requires approved business templates. Free-text WhatsApp
	 * messages are not supported - only template-based messages.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_message( $request ) {
		$access = Permissions::validate_send_contact_message_access(
			$request->get_param( 'id' ),
			$request->get_param( 'project_id' ),
			$request->get_param( 'deal_id' )
		);
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$channel    = $request->get_param( 'channel' );
		$contact_id = $request->get_param( 'id' );

		// Validate channel
		if ( ! in_array( $channel, array( CampaignChannel::STR_EMAIL, CampaignChannel::STR_SMS, CampaignChannel::STR_WHATSAPP ), true ) ) {
			return new WP_Error(
				'invalid_channel',
				__( 'Invalid channel. Must be email, sms or whatsapp.', 'doublescale'),
				array( 'status' => 400 )
			);
		}

		// For email, delegate to free version's sender
		if ( CampaignChannel::STR_EMAIL === $channel ) {
			$sender = new \DoubleScale\Pro\Modules\Inbox\IndividualMessaging\EmailIndividualSender();
			return $sender->send( $request );
		}

		// Route to appropriate Pro sender for Sms/Whatsapp
		$sender_class = CampaignChannel::STR_SMS === $channel
			? '\DoubleScale\Pro\Modules\Inbox\IndividualMessaging\SmsIndividualSender'
			: '\DoubleScale\Pro\Modules\Inbox\IndividualMessaging\WhatsappIndividualSender';

		if ( ! class_exists( $sender_class ) ) {
			return new WP_Error(
				'sender_not_found',
				__( 'Message sender class not found.', 'doublescale'),
				array( 'status' => 500 )
			);
		}

		$sender = new $sender_class();
		return $sender->send( $request );
	}

	/**
	 * Check permissions for sending messages
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function send_message_permissions_check( $request ) {
		return Permissions::can_send_contact_message();
	}
}

