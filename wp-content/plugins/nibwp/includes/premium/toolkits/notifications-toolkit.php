<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Notifications Toolkit — tools for sending notifications via email,
 * SMS (Twilio), webhooks, and managing notification templates.
 */

// ═══════════════════════════════════════════════════════════════
// 1. SEND EMAIL — Send email via WordPress
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-send-email', [
    'label' => __('Send Email', domain: 'nibwp'),
    'description' => __('Sends an email via wp_mail() with support for HTML content, CC, BCC, custom sender, and file attachments.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'to' => ['oneOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]], 'description' => 'Recipient email address(es).'],
            'subject' => ['type' => 'string', 'description' => 'Email subject line.'],
            'body' => ['type' => 'string', 'description' => 'Email body content (HTML or plain text).'],
            'html' => ['type' => 'boolean', 'description' => 'Send as HTML email.', 'default' => true],
            'cc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'CC recipients.', 'default' => []],
            'bcc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'BCC recipients.', 'default' => []],
            'from_name' => ['type' => 'string', 'description' => 'Override sender name.', 'default' => ''],
            'from_email' => ['type' => 'string', 'description' => 'Override sender email address.', 'default' => ''],
            'attachments' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Array of absolute file paths to attach.', 'default' => []],
        ],
        'required' => ['to', 'subject', 'body'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'sent' => ['type' => 'boolean'],
            'to' => ['oneOf' => [['type' => 'string'], ['type' => 'array']]],
            'subject' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_send_email',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Sends an email using WordPress wp_mail().\nSupports HTML (default) and plain text.\nSet from_name and from_email to override the default WordPress sender.\nCC and BCC accept arrays of email addresses.\nAttachments must be absolute server file paths.\nThe email is sent through whatever mail handler WP is configured to use (default PHP mail, SMTP plugin, etc.).",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_notify_send_email(array $input): array|WP_Error
{
    $to = $input['to'] ?? '';
    $subject = trim((string) ($input['subject'] ?? ''));
    $body = (string) ($input['body'] ?? '');
    $html = $input['html'] ?? true;
    $cc = $input['cc'] ?? [];
    $bcc = $input['bcc'] ?? [];
    $from_name = trim((string) ($input['from_name'] ?? ''));
    $from_email = trim((string) ($input['from_email'] ?? ''));
    $attachments = $input['attachments'] ?? [];

    if (empty($to)) {
        return new WP_Error('missing_to', 'The "to" parameter is required.');
    }
    if ($subject === '') {
        return new WP_Error('missing_subject', 'The "subject" parameter is required.');
    }
    if ($body === '') {
        return new WP_Error('missing_body', 'The "body" parameter is required.');
    }

    // Build headers.
    $headers = [];
    if ($html) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    }
    if ($from_name !== '' || $from_email !== '') {
        $name = $from_name ?: get_bloginfo('name');
        $email = $from_email ?: get_option('admin_email');
        $headers[] = "From: {$name} <{$email}>";
    }
    foreach ($cc as $cc_addr) {
        $headers[] = 'Cc: ' . sanitize_email($cc_addr);
    }
    foreach ($bcc as $bcc_addr) {
        $headers[] = 'Bcc: ' . sanitize_email($bcc_addr);
    }

    // Validate attachments exist.
    $valid_attachments = [];
    foreach ($attachments as $path) {
        if (is_string($path) && file_exists($path)) {
            $valid_attachments[] = $path;
        }
    }

    $sent = wp_mail($to, $subject, $body, $headers, $valid_attachments);

    return [
        'success' => true,
        'sent' => $sent,
        'to' => $to,
        'subject' => $subject,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 2. SEND SMS — Send SMS via Twilio
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-send-sms', [
    'label' => __('Send SMS via Twilio', domain: 'nibwp'),
    'description' => __('Sends an SMS message via Twilio. Supports WP Twilio Core plugin or direct Twilio REST API with stored credentials.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'to' => ['type' => 'string', 'description' => 'Phone number with country code (e.g. +1234567890).'],
            'message' => ['type' => 'string', 'description' => 'SMS message body (max 1600 characters).', 'maxLength' => 1600],
            'from' => ['type' => 'string', 'description' => 'Override the default Twilio phone number.', 'default' => ''],
        ],
        'required' => ['to', 'message'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'sid' => ['type' => 'string'],
            'to' => ['type' => 'string'],
            'message_preview' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_send_sms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Sends an SMS via Twilio.\nRequires either WP Twilio Core plugin active OR Twilio credentials configured via notify-configure-twilio.\nPhone numbers must include country code (e.g. +1 for US).\nMessages are limited to 1600 characters.\nUse notify-configure-twilio to set up credentials first if not already configured.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_notify_send_sms(array $input): array|WP_Error
{
    $to = trim((string) ($input['to'] ?? ''));
    $message = trim((string) ($input['message'] ?? ''));
    $from_override = trim((string) ($input['from'] ?? ''));

    if ($to === '') {
        return new WP_Error('missing_to', 'The "to" phone number is required.');
    }
    if ($message === '') {
        return new WP_Error('missing_message', 'The "message" parameter is required.');
    }
    if (mb_strlen($message) > 1600) {
        return new WP_Error('message_too_long', 'Message exceeds 1600 characters.');
    }

    return nibwp_notify_twilio_send($to, $message, $from_override);
}

/**
 * Internal: Send SMS via Twilio — auto-detects WP Twilio Core or uses direct API.
 */
function nibwp_notify_twilio_send(string $to, string $message, string $from_override = ''): array|WP_Error
{
    // Method 1: WP Twilio Core plugin.
    if (function_exists('twl_send_sms')) {
        try {
            $result = twl_send_sms($to, $message);
            return [
                'success' => true,
                'sid' => is_string($result) ? $result : 'sent',
                'to' => $to,
                'message_preview' => mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''),
                'method' => 'wp_twilio_core',
            ];
        } catch (\Throwable $e) {
            return new WP_Error('twilio_plugin_error', 'WP Twilio Core failed: ' . $e->getMessage());
        }
    }

    // Method 2: Direct Twilio REST API.
    $account_sid = get_option('nibwp_twilio_sid', '');
    $auth_token = get_option('nibwp_twilio_token', '');
    $from_number = $from_override ?: get_option('nibwp_twilio_from', '');

    if ($account_sid === '' || $auth_token === '' || $from_number === '') {
        return new WP_Error(
            'twilio_not_configured',
            'Twilio is not configured. Install WP Twilio Core plugin or use notify-configure-twilio to set credentials.',
        );
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";

    $response = wp_remote_post($url, [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode("{$account_sid}:{$auth_token}"),
        ],
        'body' => [
            'To' => $to,
            'From' => $from_number,
            'Body' => $message,
        ],
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('twilio_request_failed', 'Twilio API request failed: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $error_msg = $body['message'] ?? "HTTP {$code}";
        return new WP_Error('twilio_api_error', "Twilio API error: {$error_msg}");
    }

    return [
        'success' => true,
        'sid' => $body['sid'] ?? '',
        'to' => $to,
        'message_preview' => mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''),
        'method' => 'twilio_api',
    ];
}

// ═══════════════════════════════════════════════════════════════
// 3. BULK EMAIL — Send bulk emails with variable substitution
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-bulk-email', [
    'label' => __('Send Bulk Emails', domain: 'nibwp'),
    'description' => __('Sends personalized emails to multiple recipients with {{variable}} placeholder support and rate limiting.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'recipients' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['email' => ['type' => 'string'], 'name' => ['type' => 'string'], 'variables' => ['type' => 'object']]], 'description' => 'Array of recipient objects with email, name, and variables.'],
            'subject' => ['type' => 'string', 'description' => 'Subject template with {{variable}} placeholders.'],
            'body' => ['type' => 'string', 'description' => 'Body template with {{variable}} placeholders.'],
            'html' => ['type' => 'boolean', 'description' => 'Send as HTML.', 'default' => true],
            'from_name' => ['type' => 'string', 'description' => 'Override sender name.', 'default' => ''],
            'from_email' => ['type' => 'string', 'description' => 'Override sender email.', 'default' => ''],
            'delay_ms' => ['type' => 'integer', 'description' => 'Delay in milliseconds between sends.', 'default' => 100],
        ],
        'required' => ['recipients', 'subject', 'body'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'sent_count' => ['type' => 'integer'],
            'failed_count' => ['type' => 'integer'],
            'failures' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_bulk_email',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Sends personalized emails to multiple recipients.\nUse {{variable}} placeholders in subject and body — they are replaced per-recipient.\nBuilt-in variables: {{email}}, {{name}}. Custom variables come from each recipient's 'variables' object.\ndelay_ms adds a pause between sends to avoid rate limits (default 100ms).\nReturns a list of failures with email and error details.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_notify_bulk_email(array $input): array|WP_Error
{
    $recipients = $input['recipients'] ?? [];
    if (!is_array($recipients) || empty($recipients)) {
        return new WP_Error('invalid_recipients', 'The recipients parameter must be a non-empty array.');
    }

    $subject_template = (string) ($input['subject'] ?? '');
    $body_template = (string) ($input['body'] ?? '');
    $html = $input['html'] ?? true;
    $from_name = trim((string) ($input['from_name'] ?? ''));
    $from_email = trim((string) ($input['from_email'] ?? ''));
    $delay_ms = max(0, (int) ($input['delay_ms'] ?? 100));

    $headers = [];
    if ($html) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    }
    if ($from_name !== '' || $from_email !== '') {
        $name = $from_name ?: get_bloginfo('name');
        $email = $from_email ?: get_option('admin_email');
        $headers[] = "From: {$name} <{$email}>";
    }

    $sent_count = 0;
    $failed_count = 0;
    $failures = [];

    foreach ($recipients as $recipient) {
        $email = sanitize_email($recipient['email'] ?? '');
        if ($email === '') {
            $failed_count++;
            $failures[] = ['email' => $recipient['email'] ?? '', 'error' => 'Invalid email address.'];
            continue;
        }

        // Build replacement map.
        $vars = $recipient['variables'] ?? [];
        $vars['email'] = $email;
        $vars['name'] = $recipient['name'] ?? '';

        $subject = nibwp_notify_replace_variables($subject_template, $vars);
        $body = nibwp_notify_replace_variables($body_template, $vars);

        $sent = wp_mail($email, $subject, $body, $headers);
        if ($sent) {
            $sent_count++;
        } else {
            $failed_count++;
            $failures[] = ['email' => $email, 'error' => 'wp_mail() returned false.'];
        }

        if ($delay_ms > 0) {
            usleep($delay_ms * 1000);
        }
    }

    return [
        'success' => true,
        'sent_count' => $sent_count,
        'failed_count' => $failed_count,
        'failures' => $failures,
    ];
}

/**
 * Replace {{variable}} placeholders with values from a map.
 */
function nibwp_notify_replace_variables(string $template, array $vars): string
{
    foreach ($vars as $key => $value) {
        if (is_scalar($value)) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
    }
    return $template;
}

// ═══════════════════════════════════════════════════════════════
// 4. BULK SMS — Send bulk SMS via Twilio
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-bulk-sms', [
    'label' => __('Send Bulk SMS', domain: 'nibwp'),
    'description' => __('Sends personalized SMS messages to multiple recipients via Twilio with {{variable}} placeholder support.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'recipients' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['phone' => ['type' => 'string'], 'variables' => ['type' => 'object']]], 'description' => 'Array of recipient objects with phone and variables.'],
            'message' => ['type' => 'string', 'description' => 'Message template with {{variable}} placeholders.', 'maxLength' => 1600],
            'from' => ['type' => 'string', 'description' => 'Override the default Twilio phone number.', 'default' => ''],
            'delay_ms' => ['type' => 'integer', 'description' => 'Delay in milliseconds between sends.', 'default' => 200],
        ],
        'required' => ['recipients', 'message'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'sent_count' => ['type' => 'integer'],
            'failed_count' => ['type' => 'integer'],
            'failures' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_bulk_sms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Sends personalized SMS to multiple recipients via Twilio.\nUse {{variable}} placeholders in the message — replaced per-recipient.\nBuilt-in variable: {{phone}}. Custom variables come from each recipient's 'variables' object.\nRequires Twilio to be configured (WP Twilio Core or notify-configure-twilio).\ndelay_ms defaults to 200ms to respect Twilio rate limits.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_notify_bulk_sms(array $input): array|WP_Error
{
    $recipients = $input['recipients'] ?? [];
    if (!is_array($recipients) || empty($recipients)) {
        return new WP_Error('invalid_recipients', 'The recipients parameter must be a non-empty array.');
    }

    $message_template = (string) ($input['message'] ?? '');
    $from_override = trim((string) ($input['from'] ?? ''));
    $delay_ms = max(0, (int) ($input['delay_ms'] ?? 200));

    $sent_count = 0;
    $failed_count = 0;
    $failures = [];

    foreach ($recipients as $recipient) {
        $phone = trim((string) ($recipient['phone'] ?? ''));
        if ($phone === '') {
            $failed_count++;
            $failures[] = ['phone' => '', 'error' => 'Missing phone number.'];
            continue;
        }

        $vars = $recipient['variables'] ?? [];
        $vars['phone'] = $phone;
        $message = nibwp_notify_replace_variables($message_template, $vars);

        if (mb_strlen($message) > 1600) {
            $failed_count++;
            $failures[] = ['phone' => $phone, 'error' => 'Resolved message exceeds 1600 characters.'];
            continue;
        }

        $result = nibwp_notify_twilio_send($phone, $message, $from_override);
        if (is_wp_error($result)) {
            $failed_count++;
            $failures[] = ['phone' => $phone, 'error' => $result->get_error_message()];
        } else {
            $sent_count++;
        }

        if ($delay_ms > 0) {
            usleep($delay_ms * 1000);
        }
    }

    return [
        'success' => true,
        'sent_count' => $sent_count,
        'failed_count' => $failed_count,
        'failures' => $failures,
    ];
}

// ═══════════════════════════════════════════════════════════════
// 5. MANAGE TEMPLATES — CRUD for notification templates
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-manage-templates', [
    'label' => __('Manage Notification Templates', domain: 'nibwp'),
    'description' => __('Create, read, update, and delete reusable notification templates stored in WordPress options.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete'], 'description' => 'Action to perform.'],
            'template_id' => ['type' => 'string', 'description' => 'Template identifier (required for get, update, delete).', 'default' => ''],
            'template_data' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['email', 'sms']], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'variables' => ['type' => 'array', 'items' => ['type' => 'string']]], 'description' => 'Template data (required for create, update).'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'templates' => ['type' => 'array'],
            'template' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_manage_templates',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Manages reusable notification templates stored in WP options.\nActions: list (all templates), get (by ID), create, update, delete.\nTemplates have: name, type (email/sms), subject (email only), body, and variables list.\nTemplate body supports {{variable}} placeholders.\nUse template_id as a slug-like identifier.\nCreate templates first, then use them with bulk-email or bulk-sms by fetching the template and passing its body/subject.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_notify_manage_templates(array $input): array|WP_Error
{
    $action = $input['action'] ?? '';
    $template_id = sanitize_key($input['template_id'] ?? '');
    $template_data = $input['template_data'] ?? [];

    $option_key = 'nibwp_notification_templates';
    $templates = get_option($option_key, []);
    if (!is_array($templates)) {
        $templates = [];
    }

    switch ($action) {
        case 'list':
            return [
                'success' => true,
                'count' => count($templates),
                'templates' => array_values(array_map(fn($id, $t) => array_merge(['id' => $id], $t), array_keys($templates), $templates)),
            ];

        case 'get':
            if ($template_id === '' || !isset($templates[$template_id])) {
                return new WP_Error('template_not_found', "Template '{$template_id}' not found.");
            }
            return [
                'success' => true,
                'template' => array_merge(['id' => $template_id], $templates[$template_id]),
            ];

        case 'create':
            if (empty($template_data['name'])) {
                return new WP_Error('missing_name', 'Template name is required.');
            }
            $id = $template_id ?: sanitize_key($template_data['name']);
            if (isset($templates[$id])) {
                return new WP_Error('template_exists', "Template '{$id}' already exists. Use 'update' action.");
            }
            $templates[$id] = [
                'name' => sanitize_text_field($template_data['name']),
                'type' => in_array($template_data['type'] ?? '', ['email', 'sms'], true) ? $template_data['type'] : 'email',
                'subject' => sanitize_text_field($template_data['subject'] ?? ''),
                'body' => $template_data['body'] ?? '',
                'variables' => array_map('sanitize_text_field', $template_data['variables'] ?? []),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];
            update_option($option_key, $templates);
            return [
                'success' => true,
                'template' => array_merge(['id' => $id], $templates[$id]),
            ];

        case 'update':
            if ($template_id === '' || !isset($templates[$template_id])) {
                return new WP_Error('template_not_found', "Template '{$template_id}' not found.");
            }
            if (!empty($template_data['name'])) {
                $templates[$template_id]['name'] = sanitize_text_field($template_data['name']);
            }
            if (!empty($template_data['type']) && in_array($template_data['type'], ['email', 'sms'], true)) {
                $templates[$template_id]['type'] = $template_data['type'];
            }
            if (isset($template_data['subject'])) {
                $templates[$template_id]['subject'] = sanitize_text_field($template_data['subject']);
            }
            if (isset($template_data['body'])) {
                $templates[$template_id]['body'] = $template_data['body'];
            }
            if (isset($template_data['variables'])) {
                $templates[$template_id]['variables'] = array_map('sanitize_text_field', $template_data['variables']);
            }
            $templates[$template_id]['updated_at'] = current_time('mysql');
            update_option($option_key, $templates);
            return [
                'success' => true,
                'template' => array_merge(['id' => $template_id], $templates[$template_id]),
            ];

        case 'delete':
            if ($template_id === '' || !isset($templates[$template_id])) {
                return new WP_Error('template_not_found', "Template '{$template_id}' not found.");
            }
            $deleted = $templates[$template_id];
            unset($templates[$template_id]);
            update_option($option_key, $templates);
            return [
                'success' => true,
                'deleted' => array_merge(['id' => $template_id], $deleted),
            ];

        default:
            return new WP_Error('invalid_action', "Invalid action '{$action}'. Use: list, get, create, update, delete.");
    }
}

// ═══════════════════════════════════════════════════════════════
// 6. CONFIGURE TWILIO — Manage Twilio credentials
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-configure-twilio', [
    'label' => __('Configure Twilio Credentials', domain: 'nibwp'),
    'description' => __('Manages Twilio API credentials for SMS sending. Supports saving credentials, checking status, and testing the connection.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get_status', 'save_credentials', 'test_connection'], 'description' => 'Action to perform.'],
            'credentials' => ['type' => 'object', 'properties' => ['account_sid' => ['type' => 'string'], 'auth_token' => ['type' => 'string'], 'from_number' => ['type' => 'string']], 'description' => 'Twilio credentials (required for save_credentials).'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'status' => ['type' => 'string'],
            'configured' => ['type' => 'boolean'],
            'from_number' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_configure_twilio',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Manages Twilio credentials for SMS.\nActions:\n- get_status: Check if Twilio is configured (does NOT reveal tokens).\n- save_credentials: Save account_sid, auth_token, and from_number.\n- test_connection: Verify stored credentials by calling the Twilio API.\nCredentials are stored in WordPress options.\nIf WP Twilio Core plugin is active, direct credentials are not needed.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_notify_configure_twilio(array $input): array|WP_Error
{
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'get_status':
            $has_plugin = function_exists('twl_send_sms');
            $sid = get_option('nibwp_twilio_sid', '');
            $from = get_option('nibwp_twilio_from', '');
            $configured = $has_plugin || ($sid !== '' && get_option('nibwp_twilio_token', '') !== '' && $from !== '');

            return [
                'success' => true,
                'status' => $configured ? 'configured' : 'not_configured',
                'configured' => $configured,
                'method' => $has_plugin ? 'wp_twilio_core' : ($configured ? 'direct_api' : 'none'),
                'from_number' => $from,
            ];

        case 'save_credentials':
            $creds = $input['credentials'] ?? [];
            $sid = trim((string) ($creds['account_sid'] ?? ''));
            $token = trim((string) ($creds['auth_token'] ?? ''));
            $from = trim((string) ($creds['from_number'] ?? ''));

            if ($sid === '' || $token === '' || $from === '') {
                return new WP_Error('missing_credentials', 'All three fields are required: account_sid, auth_token, from_number.');
            }

            update_option('nibwp_twilio_sid', $sid);
            update_option('nibwp_twilio_token', $token);
            update_option('nibwp_twilio_from', $from);

            return [
                'success' => true,
                'status' => 'saved',
                'configured' => true,
                'from_number' => $from,
            ];

        case 'test_connection':
            $sid = get_option('nibwp_twilio_sid', '');
            $token = get_option('nibwp_twilio_token', '');

            if ($sid === '' || $token === '') {
                return new WP_Error('not_configured', 'Twilio credentials are not saved. Use save_credentials first.');
            }

            // Test by fetching the account info.
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}.json";
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}"),
                ],
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('test_failed', 'Connection test failed: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $error_msg = $body['message'] ?? "HTTP {$code}";
                return new WP_Error('test_failed', "Twilio authentication failed: {$error_msg}");
            }

            return [
                'success' => true,
                'status' => 'connected',
                'configured' => true,
                'account_name' => $body['friendly_name'] ?? '',
                'account_status' => $body['status'] ?? '',
                'from_number' => get_option('nibwp_twilio_from', ''),
            ];

        default:
            return new WP_Error('invalid_action', "Invalid action '{$action}'. Use: get_status, save_credentials, test_connection.");
    }
}

// ═══════════════════════════════════════════════════════════════
// 7. WP NOTIFICATION — Send WordPress admin notifications
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-send-wp-notification', [
    'label' => __('Send WordPress Admin Notification', domain: 'nibwp'),
    'description' => __('Sends persistent admin notifications to specific users or all administrators, displayed as admin notices in the WordPress dashboard.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'user_ids' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'string', 'enum' => ['all_admins']]], 'description' => 'Array of user IDs or "all_admins".'],
            'message' => ['type' => 'string', 'description' => 'Notification message.'],
            'type' => ['type' => 'string', 'enum' => ['info', 'warning', 'error', 'success'], 'description' => 'Notification type.', 'default' => 'info'],
        ],
        'required' => ['user_ids', 'message'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'sent_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_send_wp_notification',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Sends persistent admin dashboard notifications to WordPress users.\nPass user_ids as an array of user IDs, or 'all_admins' to notify all administrators.\nNotifications are stored as user meta and displayed as admin notices.\nTypes: info (blue), success (green), warning (yellow), error (red).\nNotifications persist until dismissed by the user.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_notify_send_wp_notification(array $input): array|WP_Error
{
    $user_ids = $input['user_ids'] ?? [];
    $message = trim((string) ($input['message'] ?? ''));
    $type = $input['type'] ?? 'info';

    if ($message === '') {
        return new WP_Error('missing_message', 'The message parameter is required.');
    }

    if (!in_array($type, ['info', 'warning', 'error', 'success'], true)) {
        $type = 'info';
    }

    // Resolve user IDs.
    if ($user_ids === 'all_admins') {
        $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
        $user_ids = array_map('intval', $admins);
    }

    if (!is_array($user_ids) || empty($user_ids)) {
        return new WP_Error('invalid_user_ids', 'Provide an array of user IDs or "all_admins".');
    }

    $notification = [
        'id' => wp_generate_uuid4(),
        'message' => sanitize_text_field($message),
        'type' => $type,
        'created_at' => current_time('mysql'),
        'dismissed' => false,
    ];

    $sent_count = 0;
    foreach ($user_ids as $user_id) {
        $user_id = (int) $user_id;
        if (!get_userdata($user_id)) {
            continue;
        }

        $notifications = get_user_meta($user_id, 'nibwp_notifications', true);
        if (!is_array($notifications)) {
            $notifications = [];
        }
        $notifications[] = $notification;
        update_user_meta($user_id, 'nibwp_notifications', $notifications);
        $sent_count++;
    }

    return [
        'success' => true,
        'sent_count' => $sent_count,
        'notification_id' => $notification['id'],
    ];
}

// ═══════════════════════════════════════════════════════════════
// 8. WEBHOOK — Send webhook notification
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/notify-webhook', [
    'label' => __('Send Webhook Notification', domain: 'nibwp'),
    'description' => __('Sends an HTTP webhook request to an external URL with custom headers, body, and method. Useful for integrating with Slack, Discord, Zapier, and other services.', domain: 'nibwp'),
    'category' => 'notifications',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'Webhook URL.'],
            'method' => ['type' => 'string', 'enum' => ['POST', 'GET', 'PUT'], 'description' => 'HTTP method.', 'default' => 'POST'],
            'headers' => ['type' => 'object', 'description' => 'Custom HTTP headers.', 'default' => []],
            'body' => ['oneOf' => [['type' => 'object'], ['type' => 'string']], 'description' => 'Request body (object will be JSON-encoded).', 'default' => ''],
            'timeout' => ['type' => 'integer', 'description' => 'Request timeout in seconds.', 'default' => 10],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'status_code' => ['type' => 'integer'],
            'response_body' => ['type' => 'string', 'description' => 'Response body (truncated to 2000 chars).'],
        ],
    ],
    'execute_callback' => 'nibwp_notify_webhook',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Sends an HTTP request to a webhook URL.\nSupports POST (default), GET, and PUT methods.\nIf body is an object, it is automatically JSON-encoded and Content-Type is set to application/json.\nIf body is a string, it is sent as-is.\nCustom headers can be added via the headers parameter.\nResponse body is truncated to 2000 characters.\nCommon uses: Slack incoming webhooks, Discord webhooks, Zapier triggers, custom endpoints.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_notify_webhook(array $input): array|WP_Error
{
    $url = trim((string) ($input['url'] ?? ''));
    $method = strtoupper(trim((string) ($input['method'] ?? 'POST')));
    $headers = $input['headers'] ?? [];
    $body = $input['body'] ?? '';
    $timeout = max(1, min(60, (int) ($input['timeout'] ?? 10)));

    if ($url === '') {
        return new WP_Error('missing_url', 'The URL parameter is required.');
    }

    if (!in_array($method, ['POST', 'GET', 'PUT'], true)) {
        return new WP_Error('invalid_method', "Method must be POST, GET, or PUT. Got: {$method}");
    }

    // Ensure headers is a key-value array.
    if (!is_array($headers)) {
        $headers = [];
    }

    // JSON-encode object bodies.
    $request_body = $body;
    if (is_array($body) || is_object($body)) {
        $request_body = wp_json_encode($body);
        if (!isset($headers['Content-Type']) && !isset($headers['content-type'])) {
            $headers['Content-Type'] = 'application/json';
        }
    }

    $args = [
        'method' => $method,
        'timeout' => $timeout,
        'headers' => $headers,
        'body' => $method !== 'GET' ? $request_body : null,
    ];

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        return new WP_Error('webhook_failed', 'Webhook request failed: ' . $response->get_error_message());
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // Truncate response body.
    if (mb_strlen($response_body) > 2000) {
        $response_body = mb_substr($response_body, 0, 2000) . '... (truncated)';
    }

    return [
        'success' => $status_code >= 200 && $status_code < 300,
        'status_code' => $status_code,
        'response_body' => $response_body,
    ];
}
