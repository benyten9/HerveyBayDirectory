<?php
/**
 * REST Api: MCP Settings Controller
 *
 * Backs the Settings → MCP screen: connection status, endpoint, tool list,
 * and the on/off switch for the MCP endpoint.
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilitiesBootstrap;
use DoubleScale\Core\Abilities\AbilityRegistrar;
use DoubleScale\Core\Abilities\Mcp\ApiKeyStore;
use DoubleScale\Core\Abilities\Mcp\KeySubject;
use DoubleScale\Core\Abilities\Mcp\SetupMailer;
use DoubleScale\Core\Abilities\McpServer;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestMcpSettingsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'mcp';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'key_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'enabled' => array(
							'required' => true,
							'type'     => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/keys',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_key' ),
					'permission_callback' => array( $this, 'key_permissions_check' ),
					'args'                => array(
						'label'   => array(
							'required' => false,
							'type'     => 'string',
						),
						// Omitted means "for me" — the original behaviour.
						'user_id' => array(
							'required' => false,
							'type'     => 'integer',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/keys/(?P<id>[a-f0-9]+)/email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'email_setup' ),
					'permission_callback' => array( $this, 'key_permissions_check' ),
					'args'                => array(
						'client'      => array(
							'required' => true,
							'type'     => 'string',
						),
						'os'          => array(
							'required' => true,
							'type'     => 'string',
						),
						// The browser builds the config and sends it back: the
						// per-client/per-OS templates live in the settings page,
						// and duplicating them in PHP would let the two drift
						// apart silently.
						'config'      => array(
							'required' => true,
							'type'     => 'string',
						),
						'config_path' => array(
							'required' => false,
							'type'     => 'string',
						),
						// The plaintext key exists only in the browser — the
						// server stores a hash — so including it in the email
						// requires the caller to hand it back.
						'secret'      => array(
							'required' => false,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/keys/(?P<id>[a-f0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_key' ),
					'permission_callback' => array( $this, 'key_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Issue a new API key.
	 *
	 * The key acts as the user it is bound to, so its reach is exactly theirs.
	 * Without `user_id` it binds to the caller, which is the common case. An
	 * administrator may name another user instead — see KeySubject for which
	 * users qualify and why. Binding the key to the person who will actually
	 * use it is what keeps owner scoping meaningful: handing a sales rep the
	 * administrator's key would silently show them everyone's records.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_key( $request ) {
		$label     = (string) $request->get_param( 'label' );
		$requested = $request->get_param( 'user_id' );

		$subject = KeySubject::validate(
			null === $requested ? get_current_user_id() : (int) $requested
		);

		if ( is_wp_error( $subject ) ) {
			return $subject;
		}

		$created = ApiKeyStore::create( $label, $subject );
		$owner   = get_userdata( $subject );

		return new WP_REST_Response(
			array(
				// Returned exactly once — only the hash is stored.
				'key'        => $created['key'],
				'id'         => $created['id'],
				'label'      => $created['label'],
				'user_id'    => $subject,
				'user_login' => $owner ? $owner->user_login : '',
				// Scoped exactly as the status route is. Returning the site-wide
				// list here would hand a non-administrator every key on the site
				// as a side effect of creating their own.
				'api_keys'   => Permissions::can_manage_mcp()
					? ApiKeyStore::list_for_display()
					: ApiKeyStore::list_for_user( get_current_user_id() ),
				'message'    => get_current_user_id() === $subject
					? __( 'API key created. Copy it now — it will not be shown again.', 'doublescale' )
					: sprintf(
						/* translators: %s: WordPress username the key acts as. */
						__( 'API key created for %s. It carries their permissions. Copy it now — it will not be shown again.', 'doublescale' ),
						$owner ? $owner->user_login : ''
					),
			),
			201
		);
	}

	/**
	 * Email connection instructions to the user a key belongs to.
	 *
	 * The recipient is resolved from the key, never taken from the request: a
	 * free-text address would let one typo mail a working credential to a
	 * stranger, with no way to recall it.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function email_setup( $request ) {
		$key_id  = (string) $request->get_param( 'id' );
		$user_id = ApiKeyStore::user_for( $key_id );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'doublescale_mcp_unknown_key',
				__( 'That API key no longer exists.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		// This mails a live credential, so a non-administrator may only trigger
		// it for their own key. Reported as "no longer exists" rather than
		// "forbidden": confirming that someone else's key id is real is itself
		// the leak worth avoiding here.
		if ( $user_id !== get_current_user_id() && ! Permissions::can_manage_mcp() ) {
			return new WP_Error(
				'doublescale_mcp_unknown_key',
				__( 'That API key no longer exists.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$result = SetupMailer::send(
			$user_id,
			sanitize_text_field( (string) $request->get_param( 'client' ) ),
			sanitize_text_field( (string) $request->get_param( 'os' ) ),
			// Not sanitize_text_field: the configuration is multi-line JSON or
			// TOML and would be flattened. SetupMailer escapes it for output.
			(string) $request->get_param( 'config' ),
			sanitize_text_field( (string) $request->get_param( 'config_path' ) ),
			(string) $request->get_param( 'secret' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user = get_userdata( $user_id );

		return new WP_REST_Response(
			array(
				'sent'    => true,
				'message' => sprintf(
					/* translators: %s: recipient email address. */
					__( 'Setup instructions sent to %s.', 'doublescale' ),
					$user ? $user->user_email : ''
				),
			),
			200
		);
	}

	/**
	 * Revoke an API key.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_key( $request ) {
		$key_id = (string) $request->get_param( 'id' );

		// An administrator revokes any key; everyone else only their own.
		// Without the owner check the weaker route gate would let one sales rep
		// revoke another's key by guessing an id.
		if ( Permissions::can_manage_mcp() ) {
			$deleted = ApiKeyStore::delete( $key_id );
			$keys    = ApiKeyStore::list_for_display();
		} else {
			$user_id = get_current_user_id();
			$deleted = ApiKeyStore::delete_own( $key_id, $user_id );
			$keys    = ApiKeyStore::list_for_user( $user_id );
		}

		return new WP_REST_Response(
			array(
				'deleted'  => $deleted,
				'api_keys' => $keys,
			),
			200
		);
	}

	/**
	 * Only administrators may see or change the MCP surface: it governs which
	 * CRM data leaves the site.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function admin_permissions_check( $request ) {
		unset( $request );

		if ( ! Permissions::can_manage_mcp() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage MCP settings.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Gate for the self-service key routes.
	 *
	 * Weaker than {@see admin_permissions_check()} on purpose: any user holding
	 * a DoubleScale role may read the MCP status and manage their OWN keys, but
	 * the handlers behind this gate must scope every read and write to the
	 * caller unless they are an administrator. A key grants its owner exactly
	 * the access they already have, because every ability re-checks role and
	 * owner scope on each call.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function key_permissions_check( $request ) {
		unset( $request );

		if ( ! Permissions::can_manage_own_mcp_key() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You need a DoubleScale role to create an MCP API key.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Everything the settings screen needs to explain the current state.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) {
		unset( $request );

		// The Abilities API ships in WordPress 6.9. Test for the functions
		// rather than the version number: a site can be on 6.9+ with the API
		// removed by another plugin, or on an older build that provides it.
		// The version is reported only so the admin knows why.
		$api_available = function_exists( 'wp_register_ability' )
			&& function_exists( 'wp_register_ability_category' );

		$abilities_enabled = $api_available && ! get_option( AbilitiesBootstrap::DISABLE_OPTION, false );
		$mcp_enabled       = McpServer::is_enabled();

		// An administrator manages the whole surface; everyone else sees only
		// their own keys and cannot toggle the endpoint. `can_manage_mcp` is
		// what the UI keys its admin-only controls off.
		$is_admin = Permissions::can_manage_mcp();

		return new WP_REST_Response(
			array(
				'abilities_api_available' => $api_available,
				'wp_version'              => get_bloginfo( 'version' ),
				'required_wp_version'     => '6.9',
				'abilities_enabled'       => $abilities_enabled,
				'mcp_enabled'             => $mcp_enabled,
				'connected'               => $abilities_enabled && $mcp_enabled,
				'endpoint_url'            => McpServer::endpoint_url(),
				'tools'                   => $this->tool_summaries(),
				'api_keys'                => $is_admin
					? ApiKeyStore::list_for_display()
					: ApiKeyStore::list_for_user( get_current_user_id() ),
				'can_manage_mcp'          => $is_admin,
				'current_user'            => wp_get_current_user()->user_login,
				'current_user_id'         => get_current_user_id(),
				// Users this administrator may issue a key on behalf of. Empty
				// for a non-administrator, who may only issue for themselves.
				'eligible_key_users'      => KeySubject::eligible(),
				'app_passwords_url'       => admin_url( 'profile.php#application-passwords-section' ),
				// Application passwords need HTTPS. Without this the connect
				// instructions would send an admin down a path that silently
				// fails on their local or staging site.
				'app_passwords_available' => function_exists( 'wp_is_application_passwords_available' )
					? (bool) wp_is_application_passwords_available()
					: false,
			),
			200
		);
	}

	/**
	 * Toggle the MCP endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );

		update_option( McpServer::ENABLE_OPTION, $enabled );

		return new WP_REST_Response(
			array(
				'mcp_enabled' => $enabled,
				'message'     => $enabled
					? __( 'MCP endpoint enabled.', 'doublescale' )
					: __( 'MCP endpoint disabled.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * The tools published on the endpoint, grouped for display.
	 *
	 * Reads the live ability registry, so a disabled module's tools are absent
	 * here exactly as they are absent from the endpoint itself.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tool_summaries(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$tools = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}

			$name = (string) $ability->get_name();
			if ( 0 !== strpos( $name, AbilityRegistrar::NAMESPACE_PREFIX ) ) {
				continue;
			}

			$tools[] = array(
				'name'        => $name,
				'label'       => method_exists( $ability, 'get_label' ) ? $ability->get_label() : $name,
				'description' => method_exists( $ability, 'get_description' ) ? $ability->get_description() : '',
				'category'    => method_exists( $ability, 'get_category' ) ? $ability->get_category() : '',
			);
		}

		return $tools;
	}
}
