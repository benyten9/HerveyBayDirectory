<?php
/**
 * REST WhatsApp Templates Controller
 * Fetches WhatsApp templates from Twilio Content Api
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Rest\Controllers;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use DoubleScale\Modules\Campaigns\Services\MetaTemplateFetcher;
use DoubleScale\Modules\Campaigns\Services\MetaTemplateSaver;
use DoubleScale\Modules\Campaigns\Services\WhatsappConversationWindow;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * RestWhatsappTemplatesController class
 */
class RestWhatsappTemplatesController extends WP_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * Rest base
	 *
	 * @var string
	 */
	protected $rest_base = 'whatsapp/templates';

	/**
	 * Register routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// GET /doublescale/v1/whatsapp/templates - Fetch templates from Twilio
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// POST /doublescale/v1/whatsapp/templates/save - Save template on use
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => $this->get_save_args(),
				),
			)
		);

		// GET /doublescale/v1/whatsapp/conversation-window/{contact_id} - Check 24h window status
		register_rest_route(
			$this->namespace,
			'/whatsapp/conversation-window/(?P<contact_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation_window' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'contact_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'description'       => __( 'Contact ID', 'doublescale'),
							'validate_callback' => function( $value ) {
								return is_numeric( $value ) && $value > 0;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Get save endpoint arguments
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_save_args() {
		return array(
			'sid'               => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Twilio ContentSid', 'doublescale'),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'name'              => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Template name', 'doublescale'),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'body'              => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'Template body text', 'doublescale'),
			),
			'category'          => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'UTILITY',
				'description'       => __( 'Whatsapp template category', 'doublescale'),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'language'          => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'en',
				'description'       => __( 'Template language code', 'doublescale'),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'variables'         => array(
				'required'    => false,
				'type'        => 'object',
				'default'     => array(),
				'description' => __( 'Template variable definitions', 'doublescale'),
			),
			// The full template settings from Meta: components, buttons,
			// template_type and header data. An interactive template (catalog,
			// coupon, media header) cannot be sent without these — Meta rejects
			// the send with "(#131008) Required parameter is missing" when the
			// cached row declares no buttons. Persisting only variables drops
			// them, so the whole settings object is carried through here.
			'settings'          => array(
				'required'    => false,
				'type'        => 'object',
				'default'     => array(),
				'description' => __( 'Full template settings (components, buttons, header) from Meta', 'doublescale'),
			),
			'variable_mappings' => array(
				'required'    => false,
				'type'        => 'object',
				'default'     => array(),
				'description' => __( 'Variable mappings (slot to value/merge tag)', 'doublescale'),
			),
		);
	}

	/**
	 * Build the template_data array MetaTemplateSaver::save_on_use() consumes.
	 *
	 * Extracted so the forwarding contract is unit-testable without a database:
	 * the interactive component data (components, buttons, template_type, header
	 * media) has to survive the trip from client to saver, or a catalog/coupon
	 * template caches with no buttons and every send is rejected by Meta.
	 *
	 * When the client supplies the full `settings` object it is carried through
	 * so the saver persists the declared buttons; when it does not, the legacy
	 * variables-only shape is preserved for backward compatibility.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array Template data for the saver.
	 */
	public static function build_template_data( $request ) {
		$settings = $request->get_param( 'settings' );
		$settings = is_array( $settings ) ? $settings : array();

		$template_data = array(
			'sid'       => $request->get_param( 'sid' ),
			'name'      => $request->get_param( 'name' ),
			'body'      => $request->get_param( 'body' ) ?? '',
			'category'  => $request->get_param( 'category' ),
			'language'  => $request->get_param( 'language' ),
			'variables' => $request->get_param( 'variables' ),
		);

		if ( ! empty( $settings ) ) {
			$template_data['settings'] = $settings;
		}

		return $template_data;
	}

	/**
	 * Check permissions
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get templates from Twilio
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_templates( $request ) {
		try {
			$fetcher   = new MetaTemplateFetcher();
			$templates = $fetcher->fetch_approved_templates();

			return new WP_REST_Response(
				array(
					'success'   => true,
					'templates' => $templates,
				),
				200
			);

		} catch ( \Exception $e ) {
			$is_config_error = str_contains( $e->getMessage(), 'not configured' );

			if ( ! $is_config_error ) {
				doublescale_get_logger()->error(
					'Failed to fetch WhatsApp templates',
					array(
						'error' => $e->getMessage(),
						'code'  => 'whatsapp_templates_fetch_failed',
					)
				);
			}

			return new WP_Error(
				'fetch_failed',
				$e->getMessage(),
				array( 'status' => $is_config_error ? 400 : 500 )
			);
		}
	}

	/**
	 * Save template to database on use
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_template( $request ) {
		try {
			$template_data = self::build_template_data( $request );

			// Get variable mappings if provided
			$variable_mappings = $request->get_param( 'variable_mappings' );

			$saver    = new MetaTemplateSaver();
			$template = $saver->save_on_use( $template_data );

			// Update variable mappings if provided
			if ( ! empty( $variable_mappings ) ) {
				$saver->update_variable_mappings( $template, $variable_mappings );
			}

			return new WP_REST_Response(
				array(
					'success'     => true,
					'template_id' => $template->id,
					'template'    => array(
						'id'       => $template->id,
						'name'     => $template->name,
						'type'     => $template->type,
						'category' => $template->category,
						'body'     => $template->body,
					),
				),
				200
			);

		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				'Failed to save WhatsApp template',
				array(
					'error' => $e->getMessage(),
					'code'  => 'whatsapp_template_save_failed',
				)
			);

			return new WP_Error(
				'save_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get conversation window status for a contact
	 *
	 * Checks if the 24-hour WhatsApp conversation window is active,
	 * which determines if free-text session messages can be sent.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation_window( $request ) {
		$contact_id = absint( $request->get_param( 'contact_id' ) );

		// Validate contact exists
		$contact = ContactModel::find( $contact_id );
		if ( ! $contact ) {
			return new WP_Error(
				'not_found',
				__( 'Contact not found', 'doublescale'),
				array( 'status' => 404 )
			);
		}

		$window = WhatsappConversationWindow::check( $contact_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'window'  => $window,
			),
			200
		);
	}
}



