<?php

/**
 * REST AI Controller
 *
 * Proxies user prompts to configured AI providers to generate
 * email builder-compatible JSON templates, plain text, and sequences.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Ai\Rest\Controllers;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Emails\EmailRenderer;
use DoubleScale\Pro\Modules\Ai\AiContextSummarizer;

/**
 * RestAiController class.
 *
 * @since 1.0.0
 */
class RestAiController extends RestController
{

	/**
	 * REST Base
	 *
	 * @var string
	 */
	protected $rest_base = 'ai';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes()
	{
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/generate-email",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'generate_email'),
				'permission_callback' => array($this, 'generate_email_permissions_check'),
				'args'                => array(
					'prompt'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => function ($param) {
							return is_string($param) && strlen(trim($param)) >= 10;
						},
					),
					'tone'        => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'professional',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array('professional', 'casual', 'friendly', 'urgent', 'formal'),
					),
					'industry'    => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				'primary_color' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				'button_style'  => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'rounded',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'rounded', 'pill', 'square' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/generate-text",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'generate_text'),
				'permission_callback' => array($this, 'generate_text_permissions_check'),
				'args'                => array(
					'prompt'          => array(
						// Optional: one-click drafting works from context alone
						// (e.g. a contact_id). When present, it steers the draft.
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'contact_id'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'deal_id'         => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'context'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'tone'            => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'professional',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array('professional', 'casual', 'friendly', 'urgent', 'formal'),
					),
					'include_subject' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => true,
					),
					'use_merge_tags'  => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'max_length'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/generate-email-sequence",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'generate_email_sequence'),
				'permission_callback' => array($this, 'generate_email_permissions_check'),
				'args'                => array(
					'prompt'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => function ($param) {
							return is_string($param) && strlen(trim($param)) >= 10;
						},
					),
					'email_count'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ($param) {
							$val = (int) $param;
							return $val >= 2 && $val <= 10;
						},
					),
					'tone'          => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'professional',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array('professional', 'casual', 'friendly', 'urgent', 'formal'),
					),
					'industry'      => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'primary_color' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'button_style'  => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'rounded',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array('rounded', 'pill', 'square'),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/test-connection",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'test_connection'),
				'permission_callback' => array($this, 'generate_email_permissions_check'),
				'args'                => array(
					'provider' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'api_key'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'base_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_url',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/models",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'get_models'),
				'permission_callback' => array($this, 'generate_email_permissions_check'),
				'args'                => array(
					'provider' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'api_key'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'base_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_url',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/draft-ticket-reply",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'draft_ticket_reply'),
				'permission_callback' => array($this, 'draft_ticket_reply_permissions_check'),
				'args'                => array(
					'ticket_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ($param) {
							return (int) $param > 0;
						},
					),
					'instruction' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'tone'        => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'professional',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array('professional', 'casual', 'friendly', 'urgent', 'formal'),
					),
				),
			)
		);
	}

	/**
	 * Permission check for email builder and admin-only AI routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function generate_email_permissions_check($request)
	{
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Permission check for AI text generation (used in send-email dialogs).
	 *
	 * Sales reps need access since they use AI when composing individual messages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function generate_text_permissions_check($request)
	{
		return Permissions::has_sales_rep_access() || Permissions::has_project_access();
	}

	/**
	 * Permission check for AI ticket-reply drafting (support agents + managers).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function draft_ticket_reply_permissions_check($request)
	{
		return Permissions::has_support_access();
	}

	/**
	 * Resolve the active AI provider/model/key/base_url from settings.
	 *
	 * Shared by the generation handlers so the read-settings + decrypt + default
	 * model logic lives in one place.
	 *
	 * @return array|WP_Error { provider, model, api_key, base_url } or a
	 *                        not-configured WP_Error.
	 */
	private function resolve_provider_config()
	{
		$ai_settings = Settings::get('ai', array());

		if (empty($ai_settings['provider']) || ('custom' !== $ai_settings['provider'] && empty($ai_settings['api_key']))) {
			return new WP_Error(
				'ai_not_configured',
				__('AI is not configured. Please set your AI provider and Api key in Settings > AI.', 'doublescale'),
				array('status' => 400)
			);
		}

		$provider = sanitize_text_field($ai_settings['provider']);

		return array(
			'provider' => $provider,
			'model'    => sanitize_text_field($ai_settings['model'] ?: $this->get_default_model($provider)),
			'api_key'  => Settings::decrypt_value($ai_settings['api_key'] ?? ''),
			'base_url' => sanitize_url($ai_settings['base_url'] ?? ''),
		);
	}

	/**
	 * Generate email template via AI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_email($request)
	{
		$ai_settings = Settings::get('ai', array());

		if (empty($ai_settings['provider']) || ('custom' !== $ai_settings['provider'] && empty($ai_settings['api_key']))) {
			return new WP_Error(
				'ai_not_configured',
				__('AI is not configured. Please set your AI provider and Api key in Settings > AI.', 'doublescale'),
				array('status' => 400)
			);
		}

		$prompt   = $request->get_param('prompt');
		$tone     = $request->get_param('tone');
		$industry = $request->get_param('industry');

		$provider = sanitize_text_field($ai_settings['provider']);
		$model    = sanitize_text_field($ai_settings['model'] ?: $this->get_default_model($provider));
		$api_key  = Settings::decrypt_value($ai_settings['api_key'] ?? '');
		$base_url = sanitize_url($ai_settings['base_url'] ?? '');

		$customization = array(
			'primary_color' => $request->get_param( 'primary_color' ),
			'button_style'  => $request->get_param( 'button_style' ),
		);

		$system_prompt = $this->build_system_prompt( $customization );
		$user_prompt   = $this->build_user_prompt($prompt, $tone, $industry);

		$result = $this->call_ai_provider($provider, $model, $api_key, $system_prompt, $user_prompt, $base_url, true, 8192, 300);

		if (is_wp_error($result)) {
			return $result;
		}

		// Parse and validate the AI response.
		$parsed = $this->parse_ai_response($result);

		if (is_wp_error($parsed)) {
			return $parsed;
		}

		$preview_html = '';
		if ( isset( $parsed['value'] ) && is_array( $parsed['value'] ) ) {
			$renderer     = new EmailRenderer();
			$preview_html = $renderer->render_from_builder_data( $parsed['value'] );
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'template'     => $parsed,
				'preview_html' => $preview_html,
			),
			200
		);
	}

	/**
	 * Generate plain text/HTML content via AI.
	 *
	 * Used by individual email send dialogs (contact profile, deal detail).
	 * Returns a plain email body and, optionally, a subject line — not a builder JSON template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_text($request)
	{
		$config = $this->resolve_provider_config();
		if (is_wp_error($config)) {
			return $config;
		}
		$provider = $config['provider'];
		$model    = $config['model'];
		$api_key  = $config['api_key'];
		$base_url = $config['base_url'];

		$prompt          = (string) $request->get_param('prompt');
		$context         = $request->get_param('context') ?: '';
		$tone            = $request->get_param('tone') ?: 'professional';
		$include_subject = (bool) $request->get_param('include_subject');
		$use_merge_tags  = (bool) $request->get_param('use_merge_tags');
		$max_length      = (int) $request->get_param('max_length');
		$contact_id      = (int) $request->get_param('contact_id');
		$deal_id         = (int) $request->get_param('deal_id');

		// Fold live CRM context into the prompt so the draft references real
		// details, not just a name. Honors the data_access governance toggles
		// inside the summarizer; returns '' when access is denied or absent.
		$crm_context = '';
		if ($deal_id > 0) {
			$crm_context = AiContextSummarizer::for_deal($deal_id);
		} elseif ($contact_id > 0) {
			$crm_context = AiContextSummarizer::for_contact($contact_id);
		}
		if ('' !== $crm_context) {
			$context = trim($context . "\n" . $crm_context);
		}

		// One-click drafting: with no typed prompt we still need a goal. Require
		// at least *some* signal so the model isn't asked to write from nothing.
		if ('' === trim($prompt) && '' === trim($context)) {
			return new WP_Error(
				'ai_no_input',
				__('Provide an instruction or a contact to draft from.', 'doublescale'),
				array('status' => 400)
			);
		}
		if ('' === trim($prompt)) {
			$prompt = __('Write a helpful, relevant message to this contact based on the context below.', 'doublescale');
		}

		$merge_tags_instruction = $use_merge_tags ? $this->get_merge_tags_for_prompt() : '';

		// When not using merge tags the email is sent directly to a real person,
		// so instruct the AI to write naturally using their actual name/details.
		$personalization_note = $use_merge_tags
			? $merge_tags_instruction
			: ' Write naturally using the contact\'s real name and details from the context. Do NOT use placeholder tokens like {{first_name}} or [Name].';

		$length_constraint = '';
		if ($max_length > 0) {
			$length_constraint = " IMPORTANT: The response MUST be {$max_length} characters or fewer. This is a strict limit.";
		}

		if ($include_subject) {
			$system = "You are a helpful writing assistant for email content. Write concise, well-formatted emails. Keep the tone {$tone}. You MUST respond with a JSON object with exactly two keys: \"subject\" (a short email subject line, plain text, no HTML) and \"body\" (the email body using HTML tags: <p>, <strong>, <em>, <ul>, <li>, <h2>, <h3>, <a>). Return ONLY valid JSON, no code fences, no explanations.{$personalization_note}{$length_constraint}";
		} else {
			$system = "You are a helpful writing assistant for email and messaging content. Write concise, well-formatted text. Return ONLY the requested text, no JSON, no code fences, no explanations. Use HTML tags (<p>, <strong>, <em>, <ul>, <li>, <h2>, <h3>, <a>) for formatting when appropriate. Keep the tone {$tone}.{$personalization_note}{$length_constraint}";
		}

		$user_parts = array($prompt);
		if (!empty($context)) {
			$user_parts[] = "\nContext: " . $context;
		}
		if ($include_subject) {
			$user_parts[] = "\nRespond with ONLY a JSON object: {\"subject\": \"...\", \"body\": \"...\"}";
		} else {
			$user_parts[] = "\nRespond with ONLY the generated text content (HTML formatted). No JSON, no wrappers.";
		}

		$result = $this->call_ai_provider($provider, $model, $api_key, $system, implode('', $user_parts), $base_url, false);

		if (is_wp_error($result)) {
			return $result;
		}

		$text = trim($result);
		$text = preg_replace('/^```(?:json|html)?\s*/i', '', $text);
		$text = preg_replace('/\s*```$/', '', $text);
		$text = trim($text);

		$response_data = array('success' => true);

		if ($include_subject) {
			$parsed = json_decode($text, true);
			if (is_array($parsed) && isset($parsed['subject'], $parsed['body'])) {
				$response_data['subject'] = sanitize_text_field($parsed['subject']);
				$response_data['text']    = wp_kses_post($parsed['body']);
			} else {
				$response_data['text'] = wp_kses_post($text);
			}
		} else {
			$response_data['text'] = wp_kses_post($text);
		}

		return new WP_REST_Response($response_data, 200);
	}

	/**
	 * Draft an AI reply for a support ticket.
	 *
	 * Reads the ticket's conversation (via AiContextSummarizer) and returns a
	 * suggested agent reply for the human to review/edit/send. It NEVER posts
	 * the reply — sending stays a human action through the normal reply flow.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function draft_ticket_reply($request)
	{
		$ticket_model = '\\DoubleScale\\Modules\\Support\\Models\\TicketModel';
		if (
			! class_exists($ticket_model)
			|| (function_exists('doublescale_is_module_active') && ! doublescale_is_module_active('support'))
		) {
			return new WP_Error(
				'support_unavailable',
				__('The Support module is not available on this site.', 'doublescale'),
				array('status' => 400)
			);
		}

		$ai_settings = Settings::get('ai', array());
		if (! ($ai_settings['data_access']['support_data'] ?? true)) {
			return new WP_Error(
				'support_data_disabled',
				__('Support data access is disabled by your administrator.', 'doublescale'),
				array('status' => 403)
			);
		}

		$ticket_id = (int) $request->get_param('ticket_id');
		$ticket    = $ticket_model::find($ticket_id);
		if (! $ticket) {
			return new WP_Error(
				'ticket_not_found',
				__('Ticket not found.', 'doublescale'),
				array('status' => 404)
			);
		}

		// Per-ticket scoping: agents without manage-all may only draft on their
		// own tickets (mirrors the Support REST + AI-tool authorization rule).
		if (
			! Permissions::can_manage_all_tickets()
			&& (int) $ticket->agent_user_id !== get_current_user_id()
		) {
			return new WP_Error(
				'ticket_forbidden',
				__('You can only draft replies for tickets assigned to you.', 'doublescale'),
				array('status' => 403)
			);
		}

		$ticket_context = AiContextSummarizer::for_ticket($ticket_id);
		if ('' === $ticket_context) {
			return new WP_Error(
				'ticket_context_unavailable',
				__('Unable to read this ticket to draft a reply.', 'doublescale'),
				array('status' => 400)
			);
		}

		$config = $this->resolve_provider_config();
		if (is_wp_error($config)) {
			return $config;
		}

		$tone        = $request->get_param('tone') ?: 'professional';
		$instruction = trim((string) $request->get_param('instruction'));

		$system = "You are a customer support agent drafting the next reply on a support ticket. "
			. "Write a clear, empathetic, accurate reply addressed to the customer. Keep the tone {$tone}. "
			. "Use the conversation below as the source of truth; do not invent facts, order numbers, or policies. "
			. "If you cannot resolve the issue from the information given, write a reply that asks for the specific details you need. "
			. "Return ONLY the reply body using HTML tags (<p>, <strong>, <em>, <ul>, <li>, <a>) where helpful. "
			. "Do NOT include a subject line, the word 'Subject:', email headers, or placeholder tokens like {{first_name}}.";

		$user  = "Ticket and conversation:\n" . $ticket_context;
		$user .= "\n\n" . ('' !== $instruction
			? 'Agent instruction for this reply: ' . $instruction
			: 'Draft the next agent reply to the customer.');

		$result = $this->call_ai_provider($config['provider'], $config['model'], $config['api_key'], $system, $user, $config['base_url'], false);

		if (is_wp_error($result)) {
			return $result;
		}

		$text = trim($result);
		$text = preg_replace('/^```(?:json|html)?\s*/i', '', $text);
		$text = preg_replace('/\s*```$/', '', $text);
		$text = wp_kses_post(trim($text));

		return new WP_REST_Response(
			array(
				'success'   => true,
				'text'      => $text,
				'ticket_id' => $ticket_id,
			),
			200
		);
	}

	/**
	 * Test AI connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection($request)
	{
		// Accept inline params (like get_models) so user can test before saving.
		$ai_settings = Settings::get('ai', array());
		$connections  = $ai_settings['connections'] ?? array();

		$provider = sanitize_text_field($request->get_param('provider') ?: ($ai_settings['provider'] ?? ''));
		// Only fall back to the saved key when the provider matches, to avoid
		// cross-provider key leaks (e.g. sending a Gemini key to OpenAI).
		$saved_is_same_provider = ($provider === ($ai_settings['provider'] ?? ''));
		$conn_key     = $connections[ $provider ]['api_key'] ?? '';
		$fallback_key = $saved_is_same_provider ? ($ai_settings['api_key'] ?? '') : '';
		$api_key  = $request->get_param('api_key') ?: Settings::decrypt_value($conn_key ?: $fallback_key);
		$base_url = sanitize_url($request->get_param('base_url') ?: ($ai_settings['base_url'] ?? ''));

		if (empty($provider) || ($provider !== 'custom' && empty($api_key))) {
			return new WP_Error(
				'ai_not_configured',
				__('AI is not configured. Please set your AI provider and Api key.', 'doublescale'),
				array('status' => 400)
			);
		}

		$model = sanitize_text_field(
			$request->get_param('model')
				?: ($ai_settings['model'] ?: $this->get_default_model($provider))
		);

		$result = $this->call_ai_provider($provider, $model, $api_key, 'You are a helpful assistant.', 'Say hello.', $base_url, false);

		if (is_wp_error($result)) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __('Connection successful! Your AI provider is configured correctly.', 'doublescale'),
			),
			200
		);
	}

	/**
	 * Fetch available models from the configured provider's Api.
	 *
	 * Supports a preview mode: pass `provider` + `api_key` query params to
	 * check models before saving settings. Falls back to saved settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_models($request)
	{
		// Allow passing provider+api_key directly (e.g. when user is still configuring).
		$provider = $request->get_param('provider') ?: '';
		$api_key  = $request->get_param('api_key') ?: '';
		$base_url = $request->get_param('base_url') ?: '';

		// Fall back to saved settings when values are missing.
		if (empty($provider) || empty($api_key) || ( $provider === 'custom' && empty($base_url) )) {
			$ai_settings  = Settings::get('ai', array());
			$provider     = $provider ?: ($ai_settings['provider'] ?? '');
			$connections  = $ai_settings['connections'] ?? array();
			$saved_is_same_provider = ($provider === ($ai_settings['provider'] ?? ''));
			// Only fall back to the global api_key when the requested provider
			// matches the saved provider. Otherwise use the provider's connection
			// only — prevents sending e.g. a Gemini key to OpenAI.
			$conn_key     = $connections[ $provider ]['api_key'] ?? '';
			$fallback_key = $saved_is_same_provider ? ($ai_settings['api_key'] ?? '') : '';
			$api_key      = $api_key ?: Settings::decrypt_value($conn_key ?: $fallback_key);
			$base_url     = $base_url ?: ($connections['custom']['base_url'] ?? ($ai_settings['base_url'] ?? ''));
		}

		if (empty($provider)) {
			return new WP_Error(
				'ai_not_configured',
				__('Provider is required to fetch models.', 'doublescale'),
				array('status' => 400)
			);
		}

		switch ($provider) {
			case 'openai':
				if (empty($api_key)) {
					return new WP_Error('missing_key', __('Api key is required.', 'doublescale'), array('status' => 400));
				}
				$models = $this->fetch_openai_models($api_key);
				break;
			case 'anthropic':
				if (empty($api_key)) {
					return new WP_Error('missing_key', __('Api key is required.', 'doublescale'), array('status' => 400));
				}
				$models = $this->fetch_anthropic_models($api_key);
				break;
			case 'gemini':
				if (empty($api_key)) {
					return new WP_Error('missing_key', __('Api key is required.', 'doublescale'), array('status' => 400));
				}
				$models = $this->fetch_gemini_models($api_key);
				break;
			case 'custom':
				if (empty($base_url)) {
					return new WP_Error('missing_base_url', __('Base URL is required for custom providers.', 'doublescale'), array('status' => 400));
				}
				$models = $this->fetch_custom_models($api_key, $base_url);
				break;
			default:
				return new WP_Error(
					'invalid_provider',
					__('Invalid provider.', 'doublescale'),
					array('status' => 400)
				);
		}

		if (is_wp_error($models)) {
			return $models;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch available GPT models from OpenAI.
	 *
	 * @param string $api_key Api key.
	 * @return array|WP_Error List of { value, label } model objects.
	 */
	private function fetch_openai_models($api_key)
	{
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error('fetch_failed', $response->get_error_message(), array('status' => 502));
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			return new WP_Error(
				'openai_error',
				$body['error']['message'] ?? __('Failed to fetch OpenAI models.', 'doublescale'),
				array('status' => $code)
			);
		}

		// Allow GPT-4o, GPT-4.1 (non-reasoning, fast) and GPT-5 (reasoning) chat models.
		$models = array();
		foreach ($body['data'] ?? array() as $model) {
			$id = $model['id'] ?? '';
			if ( ! preg_match('/^gpt-(4o(-mini)?|4\.1(-mini|-nano)?|5(\.\d+)?(-mini)?)$/', $id) ) {
				continue;
			}
			$models[] = array(
				'value' => $id,
				'label' => $id,
			);
		}

		usort($models, function ($a, $b) {
			return strcmp($b['value'], $a['value']);
		});

		return $models;
	}

	/**
	 * Return Anthropic Claude models.
	 *
	 * Anthropic does not have a public list-models Api, so we fetch their
	 * /v1/models endpoint (requires beta header) and fall back to known models.
	 *
	 * @param string $api_key Api key.
	 * @return array|WP_Error List of { value, label } model objects.
	 */
	private function fetch_anthropic_models($api_key)
	{
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'anthropic-beta'    => 'models-list-2025-02-19',
				),
			)
		);

		if (! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
			$body   = json_decode(wp_remote_retrieve_body($response), true);
			$models = array();
			foreach ($body['data'] ?? array() as $model) {
				$id = $model['id'] ?? '';
				if (strpos($id, 'claude') === 0) {
					$models[] = array(
						'value' => $id,
						'label' => $model['display_name'] ?? $id,
					);
				}
			}
			if (! empty($models)) {
				return $models;
			}
		}

		// Fallback to well-known current models if Api is unavailable.
		return array(
			array('value' => 'claude-opus-4-6',           'label' => 'Claude Opus 4.6'),
			array('value' => 'claude-sonnet-4-6',         'label' => 'Claude Sonnet 4.6'),
			array('value' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5'),
		);
	}

	/**
	 * Fetch available Gemini models from Google.
	 *
	 * @param string $api_key Api key.
	 * @return array|WP_Error List of { value, label } model objects.
	 */
	private function fetch_gemini_models($api_key)
	{
		// API key goes in the x-goog-api-key header (not the query string) so
		// it never lands in server access logs or proxy logs.
		$url = 'https://generativelanguage.googleapis.com/v1beta/models';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array('x-goog-api-key' => $api_key),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error('fetch_failed', $response->get_error_message(), array('status' => 502));
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			return new WP_Error(
				'gemini_error',
				$body['error']['message'] ?? __('Failed to fetch Gemini models.', 'doublescale'),
				array('status' => $code)
			);
		}

		$models = array();
		foreach ($body['models'] ?? array() as $model) {
			// Only include models that support generateContent.
			if (! in_array('generateContent', $model['supportedGenerationMethods'] ?? array(), true)) {
				continue;
			}

			$id = str_replace('models/', '', $model['name'] ?? '');

			// Skip non-text models (TTS, image-gen, robotics, computer-use, etc.).
			if (preg_match('/tts|image|robotics|computer.use|research|banana|nano/', $id)) {
				continue;
			}

			// Skip very old or experimental gemma models for cleaner list.
			if (strpos($id, 'gemma') === 0) {
				continue;
			}

			$label = $model['displayName'] ?? $id;
			$models[] = array(
				'value' => $id,
				'label' => $label,
			);
		}

		return $models;
	}

	/**
	 * Fetch models from any OpenAI-compatible /v1/models endpoint.
	 *
	 * Works with OpenRouter, Ollama, LM Studio, Groq, etc.
	 * For Ollama running locally: pass empty $api_key.
	 *
	 * @param string $api_key  Api key (can be empty for local providers like Ollama).
	 * @param string $base_url Base URL, e.g. http://localhost:11434/v1
	 * @return array|WP_Error List of { value, label } model objects.
	 */
	private function fetch_custom_models($api_key, $base_url)
	{
		$base_url = rtrim($base_url, '/');
		$endpoint = $base_url . '/models';

		$headers = array();
		if (! empty($api_key)) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %s: base URL */
					__('Could not reach %s. Is the server running?', 'doublescale'),
					$base_url
				),
				array('status' => 502)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			return new WP_Error(
				'provider_error',
				$body['error']['message'] ?? __('Failed to fetch models from the provider.', 'doublescale'),
				array('status' => $code)
			);
		}

		$models = array();
		foreach ($body['data'] ?? array() as $model) {
			$id = $model['id'] ?? '';
			if (empty($id)) {
				continue;
			}
			$models[] = array(
				'value' => $id,
				'label' => $model['name'] ?? $id,
			);
		}

		// Sort alphabetically for consistent display.
		usort($models, function ($a, $b) {
			return strcmp($a['value'], $b['value']);
		});

		return $models;
	}

	/**
	 * Call the AI provider Api.
	 *
	 * @param string $provider Provider name.
	 * @param string $model    Model identifier.
	 * @param string $api_key  Api key.
	 * @param string $system   System prompt.
	 * @param string $user     User prompt.
	 * @param string  $base_url  Optional custom base URL (for OpenAI-compatible providers).
	 * @param bool    $json_mode Whether to request JSON response format (default true). Pass false for test calls.
	 * @return string|WP_Error Response text or error.
	 */
	private function call_ai_provider($provider, $model, $api_key, $system, $user, $base_url = '', $json_mode = true, $max_tokens = 4096, $timeout = 120)
	{
		switch ($provider) {
			case 'openai':
				return $this->call_openai($model, $api_key, $system, $user, $json_mode, $max_tokens, $timeout);
			case 'anthropic':
				return $this->call_anthropic($model, $api_key, $system, $user, $max_tokens, $timeout);
			case 'gemini':
				return $this->call_gemini($model, $api_key, $system, $user, $max_tokens, $timeout);
			case 'custom':
				if (empty($base_url)) {
					return new WP_Error(
						'missing_base_url',
						__('A Base URL is required for custom/compatible providers.', 'doublescale'),
						array('status' => 400)
					);
				}
				return $this->call_openai_compatible($model, $api_key, $system, $user, $base_url, $json_mode, $max_tokens, $timeout);
			default:
				return new WP_Error(
					'invalid_provider',
					__('Invalid AI provider configured.', 'doublescale'),
					array('status' => 400)
				);
		}
	}

	/**
	 * Call OpenAI Api.
	 *
	 * @param string $model   Model ID.
	 * @param string $api_key Api key.
	 * @param string $system  System prompt.
	 * @param string $user      User prompt.
	 * @param bool   $json_mode Whether to request JSON response format.
	 * @return string|WP_Error
	 */
	private function call_openai($model, $api_key, $system, $user, $json_mode = true, $max_tokens = 4096, $timeout = 120)
	{
		// Reasoning models (gpt-5, o1, o3) use max_completion_tokens which covers
		// both internal reasoning AND visible output. Non-reasoning models use
		// max_tokens which only counts output. Reasoning models need ~3-4x the
		// budget so thinking tokens don't starve the actual response.
		$is_reasoning  = (bool) preg_match('/^(gpt-5|o[1-9])/', $model);
		$token_key     = $is_reasoning ? 'max_completion_tokens' : 'max_tokens';
		$token_budget  = $is_reasoning ? max($max_tokens * 4, 32768) : $max_tokens;

		$body = array(
			'model'      => $model,
			'messages'   => array(
				array('role' => 'system', 'content' => $system),
				array('role' => 'user',   'content' => $user),
			),
			$token_key   => $token_budget,
		);
		if ($json_mode) {
			$body['response_format'] = array('type' => 'json_object');
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode($body),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf(__('AI request failed: %s', 'doublescale'), $response->get_error_message()),
				array('status' => 502)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			$error_msg = $body['error']['message'] ?? __('Unknown error from OpenAI', 'doublescale');
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf(__('OpenAI Api error: %s', 'doublescale'), $error_msg),
				array('status' => $code)
			);
		}

		return $body['choices'][0]['message']['content'] ?? '';
	}

	/**
	 * Call any OpenAI-compatible Api (OpenRouter, Ollama, LM Studio, Groq, Azure, etc.).
	 *
	 * These providers all implement the POST /v1/chat/completions spec.
	 * For Ollama running locally: base_url = http://localhost:11434/v1
	 * For OpenRouter:            base_url = https://openrouter.ai/api/v1
	 * For LM Studio:             base_url = http://localhost:1234/v1
	 * For Groq:                  base_url = https://api.groq.com/openai/v1
	 *
	 * @param string $model    Model identifier.
	 * @param string $api_key  Api key (pass empty string for Ollama — no key needed).
	 * @param string $system   System prompt.
	 * @param string $user     User prompt.
	 * @param string $base_url  Base URL of the compatible endpoint (no trailing slash).
	 * @param bool   $json_mode Whether to request JSON response format.
	 * @return string|WP_Error
	 */
	private function call_openai_compatible($model, $api_key, $system, $user, $base_url, $json_mode = true, $max_tokens = 4096, $timeout = 120)
	{
		$base_url = rtrim($base_url, '/');
		$endpoint = $base_url . '/chat/completions';

		$headers = array(
			'Content-Type' => 'application/json',
		);

		// Bearer auth — skip for Ollama which doesn't require a key.
		if (! empty($api_key)) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		// OpenRouter requires HTTP-Referer and X-Title headers for ranking.
		if (strpos($base_url, 'openrouter.ai') !== false) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = get_bloginfo('name');
		}

		$body = array(
			'model'       => $model,
			'messages'    => array(
				array('role' => 'system', 'content' => $system),
				array('role' => 'user',   'content' => $user),
			),
			'max_tokens'  => $max_tokens,
		);

		// response_format json_object — only OpenAI and compatible providers that explicitly support it.
		// OpenRouter passes it through to models that support it. Ollama supports it since v0.1.22.
		if ($json_mode) {
			$body['response_format'] = array('type' => 'json_object');
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode($body),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf(__('AI request failed: %s', 'doublescale'), $response->get_error_message()),
				array('status' => 502)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$parsed = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			$error_msg = $parsed['error']['message'] ?? __('Unknown error from AI provider', 'doublescale');
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf(__('AI provider error: %s', 'doublescale'), $error_msg),
				array('status' => $code)
			);
		}

		return $parsed['choices'][0]['message']['content'] ?? '';
	}

	/**
	 * Call Anthropic (Claude) Api.
	 *
	 * @param string $model   Model ID.
	 * @param string $api_key Api key.
	 * @param string $system  System prompt.
	 * @param string $user    User prompt.
	 * @return string|WP_Error
	 */
	private function call_anthropic($model, $api_key, $system, $user, $max_tokens = 4096, $timeout = 120)
	{
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => $max_tokens,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
					)
				),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf(__('AI request failed: %s', 'doublescale'), $response->get_error_message()),
				array('status' => 502)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			$error_msg = $body['error']['message'] ?? __('Unknown error from Anthropic', 'doublescale');
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf(__('Anthropic Api error: %s', 'doublescale'), $error_msg),
				array('status' => $code)
			);
		}

		return $body['content'][0]['text'] ?? '';
	}

	/**
	 * Call Google Gemini Api.
	 *
	 * @param string $model   Model ID.
	 * @param string $api_key Api key.
	 * @param string $system  System prompt.
	 * @param string $user    User prompt.
	 * @return string|WP_Error
	 */
	private function call_gemini($model, $api_key, $system, $user, $max_tokens = 4096, $timeout = 120)
	{
		// API key goes in the x-goog-api-key header (not the query string) so
		// it never lands in server access logs or proxy logs.
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array(
								array('text' => $system),
							),
						),
						'contents'           => array(
							array(
								'parts' => array(
									array('text' => $user),
								),
							),
						),
						'generationConfig'   => array(
							'maxOutputTokens' => $max_tokens,
						),
					)
				),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf(__('AI request failed: %s', 'doublescale'), $response->get_error_message()),
				array('status' => 502)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			$error_msg = $body['error']['message'] ?? __('Unknown error from Gemini', 'doublescale');
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf(__('Gemini Api error: %s', 'doublescale'), $error_msg),
				array('status' => $code)
			);
		}

		return $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
	}

	/**
	 * Build the system prompt that instructs the AI to output valid email builder JSON.
	 *
	 * @return string
	 */
	private function build_system_prompt( $customization = array() )
	{
		$merge_tags_section = $this->get_merge_tags_for_prompt();

		$prompt = <<<'PROMPT'
You are an expert email template designer who creates polished, professional marketing emails. You generate email templates as structured JSON for a drag-and-drop email builder.

You MUST respond with ONLY valid JSON (no markdown, no code fences, no explanation). The JSON must follow this exact structure:

{
  "sections": [ ... ],
  "globalSettings": {
    "canvasColor": "#ffffff",
    "backgroundImage": null,
    "backgroundRepeat": "no-repeat",
    "backgroundSize": "cover",
    "backgroundPosition": "center",
    "canvasWidth": 900
  },
  "buttonSettings": {
    "primary": {
      "font": "Arial, sans-serif",
      "size": 18,
      "letterSpacing": "0px",
      "borderRadius": 6,
      "textColor": "#ffffff",
      "backgroundColor": "#1E3A8A",
      "borderWidth": 0,
      "borderColor": "transparent",
      "padding": { "top": 12, "right": 32, "bottom": 12, "left": 32 },
      "bold": false, "italic": false, "underline": false, "strikethrough": false
    },
    "secondary": {
      "font": "Arial, sans-serif",
      "size": 16,
      "letterSpacing": "0px",
      "borderRadius": 6,
      "textColor": "#1E3A8A",
      "backgroundColor": "transparent",
      "borderWidth": 1,
      "borderColor": "#1E3A8A",
      "padding": { "top": 8, "right": 20, "bottom": 8, "left": 20 },
      "bold": false, "italic": false, "underline": false, "strikethrough": false
    },
    "tertiary": {
      "font": "Arial, sans-serif",
      "size": 16,
      "letterSpacing": "0px",
      "borderRadius": 0,
      "textColor": "#1E3A8A",
      "backgroundColor": "#ffffff",
      "borderWidth": 0,
      "borderColor": "transparent",
      "padding": { "top": 6, "right": 8, "bottom": 6, "left": 8 },
      "bold": false, "italic": false, "underline": false, "strikethrough": false
    }
  }
}

SECTION STRUCTURE:
{
  "id": "section_<number>",
  "columns": [
    {
      "id": "column_<number>",
      "width": 100,
      "blocks": [ { "id": "block_<number>", "type": "<type>", "props": { ... } } ]
    }
  ],
  "layout": {
    "name": "Single Column",
    "width": [100],
    "value": "one-column"
  },
  "styles": {
    "backgroundColor": "#ffffff",
    "padding": "40px 32px 40px 32px",
    "hideAddBlockButton": true
  }
}

AVAILABLE BLOCK TYPES AND THEIR PROPS:

1. "text" block:
{
  "content": "<p>HTML content here</p>",
  "fontSize": 16,
  "color": "#333333",
  "fontFamily": "Arial",
  "bold": false,
  "italic": false,
  "underline": false,
  "line-through": false,
  "lineHeight": "1.5",
  "letterSpacing": "0px",
  "borderRadius": "0px",
  "borderWidth": "0px",
  "linkColor": "#1E3A8A",
  "backgroundColor": "transparent",
  "textAlign": "left",
  "listType": "none",
  "headingStyle": "p",
  "padding": { "top": 4, "right": 8, "bottom": 4, "left": 8 }
}
IMPORTANT text block rules:
- "fontSize" sets the wrapper font-size in px. The "content" HTML inherits this size.
- "headingStyle" controls the wrapper element type. Use "p" for body text, "h1"/"h2"/"h3" ONLY for headings.
- NEVER put <h1>/<h2>/<h3> tags inside "content" — that causes double-sized text. Use <p>, <strong>, <em>, <a>, <ul>, <li> only.
- For headings: set headingStyle to "h1"/"h2"/"h3" and use a larger fontSize (20-24 for h1, 17-20 for h2, 15-17 for h3). The content should just be plain text wrapped in <p> tags.
- For body text: keep headingStyle "p" and fontSize 14-16.
- For small/footer text: keep headingStyle "p" and fontSize 12-13.

2. "button" block:
{
  "text": "Button Text",
  "url": "#",
  "containerPadding": { "top": 10, "right": 0, "bottom": 10, "left": 0 },
  "containerBackgroundColor": "transparent",
  "align": "center",
  "buttonStyle": "primary"
}
buttonStyle values: "primary", "secondary", "tertiary" — styled via buttonSettings above.

3. "image" block:
{
  "src": "",
  "alt": "Description",
  "width": "100%",
  "height": "auto",
  "align": "center",
  "backgroundColor": "transparent",
  "padding": { "top": 0, "right": 0, "bottom": 0, "left": 0 },
  "link": "",
  "borderRadius": "0",
  "shape": "rectangle"
}

4. "divider" block:
{
  "height": "2",
  "color": "#E2E2E2",
  "backgroundColor": "transparent",
  "style": "solid",
  "padding": { "top": 0, "right": 0, "bottom": 0, "left": 0 },
  "align": "center",
  "width": "100",
  "borderRadius": "0",
  "opacity": 1
}

5. "social_media" block:
{
  "platforms": {
    "instagram": { "enabled": true, "link": "https://instagram.com" },
    "facebook": { "enabled": true, "link": "https://facebook.com" },
    "x": { "enabled": true, "link": "https://x.com" },
    "linkedin": { "enabled": true, "link": "https://linkedin.com" },
    "youtube": { "enabled": false, "link": "" },
    "tiktok": { "enabled": false, "link": "" },
    "threads": { "enabled": false, "link": "" },
    "pinterest": { "enabled": false, "link": "" },
    "snapchat": { "enabled": false, "link": "" },
    "soundcloud": { "enabled": false, "link": "" },
    "mail": { "enabled": false, "link": "" },
    "website": { "enabled": false, "link": "" },
    "vimeo": { "enabled": false, "link": "" },
    "medium": { "enabled": false, "link": "" },
    "spotify": { "enabled": false, "link": "" },
    "discord": { "enabled": false, "link": "" }
  },
  "iconSize": "medium",
  "align": "center",
  "shape": "rounded",
  "colorMode": "colored",
  "color": "#1E3A8A",
  "padding": { "top": 8, "right": 0, "bottom": 8, "left": 0 }
}

LAYOUT OPTIONS (for sections with multiple columns, each column has its own width):
- Single Column: one column with width 100
- Two Equal: two columns each width 50
- Two Uneven: two columns with widths 33.33 and 66.67 (or 66.67 and 33.33)
- Three Columns: three columns with widths 33.33, 33.33, 33.34

EMAIL DESIGN GUIDANCE (adapt to the specific email type and goal — do NOT use the same structure every time):

HEADER (choose based on context, do not always default to logo+social):
  - Option A — Branded: two-column (33/67) with logo image left + social_media right; add divider after
  - Option B — Minimal: single-column centered logo image only; skip social icons in header
  - Option C — Bold intro: full-width colored section with a large centered headline text block instead of a logo
  - Option D — No header: jump straight into the hero content for short transactional emails

HERO (tailor to the email's specific purpose):
  - Promotional/Sale: colored background + hero image + bold headline (fontSize 20-24, h1) + supporting text + CTA button
  - Welcome email: warm greeting text (fontSize 20-22, h1) + intro paragraph + CTA; hero image optional
  - Newsletter: clean white background + issue headline + brief summary text + "Read more" button
  - Announcement: large centered text headline + one supporting sentence + single CTA button; no image needed
  - Transactional/Notification: lead with the key info text block (no hero image needed)

CONTENT SECTIONS (mix and vary — do not repeat the same block pattern):
  - Single-column: text only, or text + button, for narrative or announcement sections
  - Two-column: image + text (alternating left/right) for features, benefits, or team highlights
  - Three-column: three equal blocks for icon-feature lists, step-by-step, or product highlights
  - Use alternating section backgrounds for visual rhythm (white ↔ light brand tint)

FOOTER (always include as the last section):
  - Company info text (fontSize 12-13, color "#999999", textAlign "center")
  - Social media icons (centered)
  - Unsubscribe link text with merge tag
  - Section backgroundColor: dark tone (#1E1E1E, #2D2D2D, or a dark brand shade)

COLOR STRATEGY:
- Pick a cohesive brand color (based on industry/tone) and use it for buttons, headings, accents
- Use its light tint (mix with white at ~10%) for alternating section backgrounds
- Keep body text dark (#333333 or #1A1A1A) for readability
- Use the brand color in buttonSettings for primary buttons
- Match linkColor in text blocks to the brand color

RULES:
- Each ID must be unique. Use incrementing numbers: section_1, column_1, block_1, etc.
- For text blocks, use HTML in "content". Use ONLY <p>, <strong>, <em>, <a>, <ul>, <li>. NEVER use <h1>/<h2>/<h3> inside content — use headingStyle prop instead.
- Leave image "src" empty but provide descriptive "alt" text.
- Always include "hideAddBlockButton": true in section styles.
- Create 5-8 sections for a complete professional email.
- Vary section padding (e.g., "40px 32px 40px 32px", "24px 32px 24px 32px") for rhythm.
- Use multi-column layouts for at least 2 sections (header + one content section).
- Always include buttonSettings with colors matching the template's color scheme.
- Always include a footer with unsubscribe text.
- Write SPECIFIC, compelling copy based on the actual email purpose. NEVER use generic filler phrases like "Introducing Our Revolutionary New...", "Welcome to Our Community", "Discover the future of...", or "We are excited to announce...". Write real, direct, contextual headlines and body text.
- Choose a layout structure that fits the email type — welcome, promotional, newsletter, and transactional emails should each look and feel different.
PROMPT;

		$customization_section = '';

		$primary_color = $customization['primary_color'] ?? '';
		$button_style  = $customization['button_style'] ?? 'rounded';

		if ( ! empty( $primary_color ) ) {
			$customization_section .= "\n\nUSER COLOR PREFERENCE:\n";
			$customization_section .= "- The user chose \"{$primary_color}\" as the primary brand color.\n";
			$customization_section .= "- You MUST use this exact color for: primary button backgroundColor, heading accents, link colors, social_media icon color.\n";
			$customization_section .= "- Derive a light tint (mix with white at ~10%) for alternating section backgrounds.\n";
			$customization_section .= "- Keep the provided buttonSettings primary.backgroundColor set to \"{$primary_color}\".";
		}

		$radius_map = array(
			'rounded' => 6,
			'pill'    => 50,
			'square'  => 0,
		);
		$radius = $radius_map[ $button_style ] ?? 6;

		// Only inject the instruction when the user picked a non-default style.
		// 'rounded' (borderRadius 6) is already the default shown in the inline JSON example above.
		if ( ! empty( $button_style ) && $button_style !== 'rounded' ) {
			$customization_section .= "\n\nUSER BUTTON STYLE PREFERENCE:\n";
			$customization_section .= "- The user chose \"{$button_style}\" buttons.\n";
			$customization_section .= "- Set borderRadius to {$radius} in all buttonSettings entries.";
		}

		return $prompt . $customization_section . $merge_tags_section;
	}

	/**
	 * Build the user prompt.
	 *
	 * @param string $prompt   User's description.
	 * @param string $tone     Desired tone.
	 * @param string $industry Industry context.
	 * @return string
	 */
	private function build_user_prompt($prompt, $tone, $industry)
	{
		$parts = array();
		$parts[] = "Create an email template for the following request:\n\n" . $prompt;

		if (! empty($tone)) {
			$parts[] = "\nTone: " . $tone;
		}

		if (! empty($industry)) {
			$parts[] = "\nIndustry/Context: " . $industry;
		}

		$parts[] = "\nRespond with ONLY the JSON object. No markdown, no code fences, no additional text.";

		return implode('', $parts);
	}

	/**
	 * Parse and validate the AI response into builder-compatible format.
	 *
	 * validate_and_enhance_form(), this merges default props
	 * into every block to ensure the builder receives complete, valid data
	 * even if the AI omits some properties.
	 *
	 * @param string $response Raw AI response text.
	 * @return array|WP_Error Parsed template data or error.
	 */
	private function parse_ai_response($response)
	{
		// Strip markdown code fences if present (Anthropic/Gemini may add them).
		$response = trim($response);
		$response = preg_replace('/^```(?:json)?\s*/i', '', $response);
		$response = preg_replace('/\s*```$/', '', $response);
		$response = trim($response);

		$data = json_decode($response, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new WP_Error(
				'ai_invalid_response',
				__('The AI returned an invalid response. Please try again.', 'doublescale'),
				array('status' => 422)
			);
		}

		// Validate required structure.
		if (! isset($data['sections']) || ! is_array($data['sections']) || empty($data['sections'])) {
			return new WP_Error(
				'ai_invalid_structure',
				__('The AI response is missing the required sections structure. Please try again.', 'doublescale'),
				array('status' => 422)
			);
		}

		$default_props = $this->get_block_default_props();
		$valid_block_types = array_keys($default_props);
		$id_counter  = 1;

		foreach ($data['sections'] as &$section) {
			// Ensure section has an ID.
			if (empty($section['id'])) {
				$section['id'] = 'section_' . $id_counter;
			}

			// Ensure section has layout.
			if (empty($section['layout'])) {
				$section['layout'] = array(
					'name'  => 'Single Column',
					'width' => array(100),
					'value' => 'one-column',
				);
			}

			// Ensure section has styles.
			if (empty($section['styles'])) {
				$section['styles'] = array(
					'backgroundColor' => 'transparent',
					'padding'         => '40px 40px 40px 40px',
				);
			}

			if (! isset($section['columns']) || ! is_array($section['columns'])) {
				$section['columns'] = array(
					array(
						'id'     => 'column_' . $id_counter,
						'width'  => 100,
						'blocks' => array(),
					),
				);
			}

			foreach ($section['columns'] as &$column) {
				// Ensure column has an ID.
				if (empty($column['id'])) {
					$column['id'] = 'column_' . $id_counter;
				}

				// Ensure column has width.
				if (! isset($column['width'])) {
					$column['width'] = 100;
				}

				if (! isset($column['blocks']) || ! is_array($column['blocks'])) {
					$column['blocks'] = array();
					continue;
				}

				// Filter invalid block types and merge default props.
				$valid_blocks = array();
				foreach ($column['blocks'] as $block) {
					if (! isset($block['type']) || ! in_array($block['type'], $valid_block_types, true)) {
						continue;
					}

					// Ensure block has an ID.
					if (empty($block['id'])) {
						$block['id'] = 'block_' . $id_counter;
						$id_counter++;
					}

					// Merge default props — AI-provided values take priority.
					$block['props'] = array_replace(
						$default_props[$block['type']],
						$block['props'] ?? array()
					);

					// Enhance specific block types.
					$block = $this->enhance_block($block, $default_props);

					$valid_blocks[] = $block;
				}

				$column['blocks'] = $valid_blocks;
			}

			$id_counter++;
		}

		// Remove empty sections (no blocks in any column).
		$data['sections'] = array_values(
			array_filter(
				$data['sections'],
				function ($section) {
					foreach ($section['columns'] as $column) {
						if (! empty($column['blocks'])) {
							return true;
						}
					}
					return false;
				}
			)
		);

		if (empty($data['sections'])) {
			return new WP_Error(
				'ai_empty_template',
				__('The AI generated an empty template. Please try a more detailed prompt.', 'doublescale'),
				array('status' => 422)
			);
		}

		return array(
			'type'  => 'builder',
			'value' => array(
				'sections'       => $data['sections'],
				'globalSettings' => $data['globalSettings'] ?? array(
					'canvasColor'        => '#ffffff',
					'backgroundImage'    => null,
					'backgroundRepeat'   => 'no-repeat',
					'backgroundSize'     => 'cover',
					'backgroundPosition' => 'center',
					'canvasWidth'        => 900,
				),
				'buttonSettings' => $data['buttonSettings'] ?? array(),
			),
		);
	}

	/**
	 * Get default props for each block type.
	 *
	 * These mirror the defaultProps defined in the TypeScript block definitions
	 * (e.g., TextBlock/index.tsx, ButtonBlock/index.tsx) so the builder receives
	 * complete, valid data even if the AI omits some properties.
	 *
	 * @return array Map of block type => default props.
	 */
	private function get_block_default_props()
	{
		return array(
			'text'    => array(
				'content'         => '<p>Your text here</p>',
				'hyperlink'       => 'https://',
				'fontSize'        => 16,
				'color'           => '#333',
				'align'           => 'center',
				'fontFamily'      => 'Arial',
				'bold'            => false,
				'italic'          => false,
				'underline'       => false,
				'line-through'    => false,
				'lineHeight'      => '1.5',
				'letterSpacing'   => '0px',
				'borderRadius'    => '0px',
				'borderWidth'     => '0px',
				'linkColor'       => '#333',
				'backgroundColor' => 'transparent',
				'textAlign'       => 'left',
				'listType'        => 'none',
				'headingStyle'    => 'p',
				'padding'         => array(
					'top'    => 4,
					'right'  => 8,
					'bottom' => 4,
					'left'   => 8,
				),
			),
			'button'  => array(
				'text'                     => 'Click Here',
				'url'                      => '#',
				'containerPadding'         => array(
					'top'    => 0,
					'right'  => 0,
					'bottom' => 0,
					'left'   => 0,
				),
				'containerBackgroundColor' => 'transparent',
				'align'                    => 'center',
				'buttonStyle'              => 'primary',
			),
			'image'   => array(
				'src'             => '',
				'alt'             => 'Image',
				'width'           => '100%',
				'height'          => 'auto',
				'align'           => 'center',
				'backgroundColor' => 'transparent',
				'padding'         => array(
					'top'    => 0,
					'right'  => 0,
					'bottom' => 0,
					'left'   => 0,
				),
				'link'            => '',
				'borderRadius'    => '0',
				'shape'           => 'rectangle',
			),
			'divider' => array(
				'height'          => '1',
				'color'           => '#cccccc',
				'backgroundColor' => 'transparent',
				'style'           => 'solid',
				'padding'         => array(
					'top'    => 0,
					'right'  => 0,
					'bottom' => 0,
					'left'   => 0,
				),
				'align'           => 'center',
				'width'           => '100',
				'borderRadius'    => '0',
				'opacity'         => 1,
			),
			'social_media' => array(
				'platforms' => array(
					'instagram'  => array( 'enabled' => true, 'link' => 'https://instagram.com' ),
					'facebook'   => array( 'enabled' => true, 'link' => 'https://facebook.com' ),
					'x'          => array( 'enabled' => true, 'link' => 'https://x.com' ),
					'linkedin'   => array( 'enabled' => true, 'link' => 'https://linkedin.com' ),
					'youtube'    => array( 'enabled' => false, 'link' => '' ),
					'tiktok'     => array( 'enabled' => false, 'link' => '' ),
					'threads'    => array( 'enabled' => false, 'link' => '' ),
					'pinterest'  => array( 'enabled' => false, 'link' => '' ),
					'snapchat'   => array( 'enabled' => false, 'link' => '' ),
					'soundcloud' => array( 'enabled' => false, 'link' => '' ),
					'mail'       => array( 'enabled' => false, 'link' => '' ),
					'website'    => array( 'enabled' => false, 'link' => '' ),
					'vimeo'      => array( 'enabled' => false, 'link' => '' ),
					'medium'     => array( 'enabled' => false, 'link' => '' ),
					'spotify'    => array( 'enabled' => false, 'link' => '' ),
					'discord'    => array( 'enabled' => false, 'link' => '' ),
					'whatsapp'   => array( 'enabled' => false, 'link' => '' ),
				),
				'customIcons' => array(),
				'iconSize'  => 'medium',
				'align'     => 'center',
				'shape'     => 'rounded',
				'colorMode' => 'colored',
				'color'     => '#333333',
				'padding'   => array(
					'top'    => 8,
					'right'  => 0,
					'bottom' => 8,
					'left'   => 0,
				),
			),
		);
	}

	/**
	 * Enhance specific block types with intelligent defaults.
	 *
	 * enhance_block_by_type() — ensures critical
	 * properties have sensible values even if the AI returned odd ones.
	 *
	 * @param array $block Block data.
	 * @return array Enhanced block.
	 */
	private function enhance_block($block, $default_props = array())
	{
		switch ($block['type']) {
			case 'text':
				// Ensure content is wrapped in HTML tags.
				if (! empty($block['props']['content']) && strpos($block['props']['content'], '<') === false) {
					$block['props']['content'] = '<p>' . esc_html($block['props']['content']) . '</p>';
				}
				// Strip <h1>-<h6> from content to prevent double-sizing with headingStyle.
				if ( ! empty( $block['props']['content'] ) ) {
					$block['props']['content'] = preg_replace(
						'/<\/?h[1-6][^>]*>/i',
						'',
						$block['props']['content']
					);
					$content = trim( $block['props']['content'] );
					if ( ! empty( $content ) && strpos( $content, '<' ) !== 0 ) {
						$block['props']['content'] = '<p>' . $content . '</p>';
					}
				}
				// Cap fontSize to prevent excessively large text.
				if ( isset( $block['props']['fontSize'] ) ) {
					$fs = (int) $block['props']['fontSize'];
					if ( $fs > 26 ) {
						$block['props']['fontSize'] = 26;
					}
				}
				break;

			case 'button':
				// Ensure button has text.
				if (empty($block['props']['text'])) {
					$block['props']['text'] = 'Click Here';
				}
				// Validate buttonStyle.
				$valid_styles = array('primary', 'secondary', 'tertiary');
				if (! in_array($block['props']['buttonStyle'] ?? '', $valid_styles, true)) {
					$block['props']['buttonStyle'] = 'primary';
				}
				break;

			case 'image':
				// Ensure alt text.
				if (empty($block['props']['alt'])) {
					$block['props']['alt'] = 'Image';
				}
				break;

			case 'divider':
				// Ensure valid style.
				$valid_divider_styles = array('solid', 'dashed', 'dotted');
				if (! in_array($block['props']['style'] ?? '', $valid_divider_styles, true)) {
					$block['props']['style'] = 'solid';
				}
				break;

			case 'social_media':
				// Deep-merge platforms: AI may only specify a few, fill in the rest from defaults.
				$default_platforms = $default_props['social_media']['platforms'] ?? $this->get_block_default_props()['social_media']['platforms'];
				if ( isset( $block['props']['platforms'] ) && is_array( $block['props']['platforms'] ) ) {
					foreach ( $default_platforms as $key => $default_val ) {
						if ( ! isset( $block['props']['platforms'][ $key ] ) ) {
							$block['props']['platforms'][ $key ] = $default_val;
						}
					}
				} else {
					$block['props']['platforms'] = $default_platforms;
				}
				if ( ! isset( $block['props']['customIcons'] ) || ! is_array( $block['props']['customIcons'] ) ) {
					$block['props']['customIcons'] = array();
				}
				$valid_sizes = array( 'small', 'medium', 'large' );
				if ( ! in_array( $block['props']['iconSize'] ?? '', $valid_sizes, true ) ) {
					$block['props']['iconSize'] = 'medium';
				}
				$valid_shapes = array( 'rounded', 'circle', 'square' );
				if ( ! in_array( $block['props']['shape'] ?? '', $valid_shapes, true ) ) {
					$block['props']['shape'] = 'rounded';
				}
				break;
		}

		return $block;
	}

	/**
	 * Generate a complete email sequence via AI.
	 *
	 * Returns N email objects, each with a subject, delay, and full builder template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_email_sequence($request)
	{
		$ai_settings = Settings::get('ai', array());

		if (empty($ai_settings['provider']) || ('custom' !== $ai_settings['provider'] && empty($ai_settings['api_key']))) {
			return new WP_Error(
				'ai_not_configured',
				__('AI is not configured. Please set your AI provider and Api key in Settings > AI.', 'doublescale'),
				array('status' => 400)
			);
		}

		$prompt      = $request->get_param('prompt');
		$email_count = (int) $request->get_param('email_count');
		$tone        = $request->get_param('tone');
		$industry    = $request->get_param('industry');

		$provider = sanitize_text_field($ai_settings['provider']);
		$model    = sanitize_text_field($ai_settings['model'] ?: $this->get_default_model($provider));
		$api_key  = Settings::decrypt_value($ai_settings['api_key'] ?? '');
		$base_url = sanitize_url($ai_settings['base_url'] ?? '');

		$customization = array(
			'primary_color' => $request->get_param('primary_color'),
			'button_style'  => $request->get_param('button_style'),
		);

		$system_prompt = $this->build_sequence_system_prompt($customization, $email_count);

		$user_parts   = array();
		$user_parts[] = "Create an email sequence of exactly {$email_count} emails for:\n\n" . $prompt;
		if (! empty($tone)) {
			$user_parts[] = "\nTone: " . $tone;
		}
		if (! empty($industry)) {
			$user_parts[] = "\nIndustry/Context: " . $industry;
		}
		$user_parts[] = "\nRespond with ONLY a JSON array of exactly {$email_count} email objects. No markdown, no code fences, no additional text.";

		$max_tokens = min($email_count * 4096, 32768);

		$result = $this->call_ai_provider($provider, $model, $api_key, $system_prompt, implode('', $user_parts), $base_url, true, $max_tokens, 600);

		if (is_wp_error($result)) {
			return $result;
		}

		$text = trim($result);
		$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
		$text = preg_replace('/\s*```$/', '', $text);
		$text = trim($text);

		$data = json_decode($text, true);

		if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
			return new WP_Error(
				'ai_invalid_response',
				__('The AI returned an invalid response. Please try again.', 'doublescale'),
				array('status' => 422)
			);
		}

		// Handle both { "emails": [...] } wrapper and plain array.
		if (isset($data['emails']) && is_array($data['emails'])) {
			$data = $data['emails'];
		}

		if (empty($data) || ! isset($data[0])) {
			return new WP_Error(
				'ai_empty_sequence',
				__('The AI generated an empty sequence. Please try a more detailed prompt.', 'doublescale'),
				array('status' => 422)
			);
		}

		$renderer = new EmailRenderer();
		$emails   = array();

		foreach ($data as $index => $email_data) {
			/* translators: %d: sequence index of the email */
			$subject    = sanitize_text_field($email_data['subject'] ?? sprintf(__('Email %d', 'doublescale'), $index + 1));
			$delay_days = max(0, (int) ($email_data['delay_days'] ?? $index));

			$template_data = $email_data['template'] ?? $email_data;

			// If the AI nested it under "template", use that; otherwise the email_data itself may be the template.
			if (! isset($template_data['sections']) && isset($email_data['template']['sections'])) {
				$template_data = $email_data['template'];
			}

			$parsed = $this->parse_ai_response(wp_json_encode($template_data));

			if (is_wp_error($parsed)) {
				continue;
			}

			$preview_html = '';
			if (isset($parsed['value']) && is_array($parsed['value'])) {
				$preview_html = $renderer->render_from_builder_data($parsed['value']);
			}

			$emails[] = array(
				'subject'      => $subject,
				'delay_days'   => $delay_days,
				'template'     => $parsed,
				'preview_html' => $preview_html,
			);
		}

		if (empty($emails)) {
			return new WP_Error(
				'ai_sequence_failed',
				__('Failed to parse any emails from the AI response. Please try again with a different prompt.', 'doublescale'),
				array('status' => 422)
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'emails'  => $emails,
			),
			200
		);
	}

	/**
	 * Build the system prompt for email sequence generation.
	 *
	 * Reuses the builder block schema from build_system_prompt() but wraps it
	 * in sequence-level instructions for generating multiple emails.
	 *
	 * @param array $customization Color/button preferences.
	 * @param int   $email_count   Number of emails to generate.
	 * @return string
	 */
	private function build_sequence_system_prompt($customization = array(), $email_count = 5)
	{
		$single_email_prompt = $this->build_system_prompt($customization);

		$sequence_lines = array(
			"You are an expert email sequence designer. You must generate a complete email sequence of exactly {$email_count} emails as a JSON array.",
			'',
			'Each email in the array must be a JSON object with exactly these keys:',
			'- "subject": A compelling, unique subject line (plain text, no HTML)',
			'- "delay_days": Number of days after the PREVIOUS email to send this one (0 for the first email, then realistic increasing values like 1, 2, 3, 5, 7)',
			'- "template": A complete email builder template object following the schema described below',
			'',
			"You MUST respond with ONLY a valid JSON array [...] containing exactly {$email_count} email objects. No markdown, no code fences, no explanation.",
			'',
			'Example structure (with 2 emails):',
			'[',
			'  {',
			'    "subject": "Welcome aboard!",',
			'    "delay_days": 0,',
			'    "template": { "sections": [...], "globalSettings": {...}, "buttonSettings": {...} }',
			'  },',
			'  {',
			'    "subject": "Here\'s what you can do next",',
			'    "delay_days": 2,',
			'    "template": { "sections": [...], "globalSettings": {...}, "buttonSettings": {...} }',
			'  }',
			']',
			'',
			'SEQUENCE STRATEGY:',
			'- Email 1 (delay_days: 0): Introduction / welcome / first impression',
			"- Emails 2 through {$email_count}: Progressively build engagement — each email should have a distinct purpose",
			'- Vary the visual layout between emails (different header styles, content arrangements, section backgrounds)',
			'- Keep branding consistent (same primary color, button styles, footer) but vary the design structure',
			'- Suggest realistic delays: 1-3 days for urgent sequences, 2-7 days for nurture sequences',
			'- Each email subject should be unique and compelling',
			'- Make emails progressively shorter as the sequence continues',
			'',
			'TEMPLATE SCHEMA FOR EACH EMAIL\'S "template" VALUE:',
			"The following describes the exact JSON structure for each email's template object.",
			'',
		);
		$sequence_wrapper = implode( "\n", $sequence_lines ) . "\n";

		// Extract just the schema portion from the single-email prompt (after the first line about being an expert).
		$schema_start = strpos($single_email_prompt, 'You MUST respond with ONLY valid JSON');
		if ($schema_start !== false) {
			$schema = substr($single_email_prompt, $schema_start);
			// Replace the single-email JSON response instruction with our array instruction.
			$schema = str_replace(
				'You MUST respond with ONLY valid JSON (no markdown, no code fences, no explanation). The JSON must follow this exact structure:',
				'Each email\'s "template" value must follow this exact structure:',
				$schema
			);
		} else {
			$schema = $single_email_prompt;
		}

		return $sequence_wrapper . "\n" . $schema;
	}

	/**
	 * Build merge tag instructions for the AI prompt.
	 *
	 * @return string
	 */
	private function get_merge_tags_for_prompt()
	{
		$manager = MergeTagsManager::instance();
		$groups  = $manager->get_groups();

		$allowed_groups = array('contact', 'general');

		$lines = array();
		foreach ($allowed_groups as $group_key) {
			if (!isset($groups[$group_key])) {
				continue;
			}
			$group = $groups[$group_key];
			if (!empty($group['is_disabled'])) {
				continue;
			}
			if (empty($group['mergeTags'])) {
				continue;
			}
			foreach ($group['mergeTags'] as $tag) {
				if (!empty($tag['required_triggers'])) {
					continue;
				}
				$lines[] = "  {$tag['value']} - {$tag['name']}";
			}
		}

		if (empty($lines)) {
			return '';
		}

		return "\n\nAvailable merge tags for personalization (use exactly as shown):\n" . implode("\n", $lines);
	}

	/**
	 * Get default model for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return string
	 */
	private function get_default_model($provider)
	{
		$defaults = array(
			'openai'    => 'gpt-4.1-mini',
			'anthropic' => 'claude-haiku-4-5-20251001',
			'gemini'    => 'gemini-2.5-flash-lite',
		);

		return $defaults[$provider] ?? 'gpt-4.1-mini';
	}
}
