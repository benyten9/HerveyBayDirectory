<?php

declare(strict_types=1);

/**
 * Ability: Create a temporary self-authenticated upload link.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/create-upload-link', [
    'label' => __('Create Upload Link', domain: 'nibwp'),
    'description' => __(
        'Creates a temporary, self-authenticated URL that external tools can use to upload one file into the WordPress filesystem. Useful when the agent has a local ZIP, plugin, theme, or media file and wants to upload it with curl or another external tool. The URL accepts raw PUT/POST bodies and multipart/form-data with a field named "file". Set register_attachment to true to put a local image or other media file straight into the Media Library: the upload response then includes attachment_id and url.',
        domain: 'nibwp',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Destination file path. Relative paths are resolved from the WordPress root (ABSPATH).',
                'minLength' => 1,
            ],
            'expires_in' => [
                'type' => 'integer',
                'description' => 'Seconds before the upload URL expires. Minimum 30, maximum 3600.',
                'default' => 900,
                'minimum' => 30,
                'maximum' => 3600,
            ],
            'max_bytes' => [
                'type' => 'integer',
                'description' => 'Maximum number of bytes accepted by this URL. Default is 536870912 (512 MiB).',
                'default' => 536_870_912,
                'minimum' => 1,
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Whether the upload may replace an existing destination file.',
                'default' => false,
            ],
            'create_directories' => [
                'type' => 'boolean',
                'description' => 'Whether to create parent directories if they do not exist.',
                'default' => true,
            ],
            'register_attachment' => [
                'type' => 'boolean',
                'description' => 'Also add the uploaded file to the Media Library as an attachment, with metadata and thumbnails generated. The path must be inside the uploads directory, the file type must be one WordPress accepts as an upload, and you need the upload_files capability. The upload response then includes attachment_id and url.',
                'default' => false,
            ],
            'alt_text' => [
                'type' => 'string',
                'description' => 'Alt text for the attachment. Requires register_attachment.',
                'maxLength' => 1000,
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Attachment title. Defaults to the file name. Requires register_attachment.',
                'maxLength' => 255,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'upload_url' => ['type' => 'string', 'description' => 'Temporary self-authenticated upload URL.'],
            'method' => ['type' => 'string', 'description' => 'Recommended HTTP method.'],
            'path' => ['type' => 'string', 'description' => 'Absolute destination path.'],
            'expires_at' => ['type' => 'integer', 'description' => 'Unix timestamp when the URL expires.'],
            'max_bytes' => ['type' => 'integer', 'description' => 'Maximum upload size accepted by the URL.'],
            'overwrite' => ['type' => 'boolean', 'description' => 'Whether existing files may be replaced.'],
            'register_attachment' => [
                'type' => 'boolean',
                'description' => 'Whether the upload will be registered in the Media Library; if so the upload response includes attachment_id and url.',
            ],
            'curl_examples' => [
                'type' => 'array',
                'description' => 'Example curl commands. Replace /path/to/local-file with the local file to upload.',
                'items' => ['type' => 'string'],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_create_upload_link',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use this when a file is too large or inconvenient to send through the MCP JSON transport.',
                'Recommended curl form: curl -X PUT --data-binary @/path/to/local-file "$upload_url"',
                'Multipart form is also accepted: curl -F file=@/path/to/local-file "$upload_url"',
                'PHP files (*.php) can ONLY be uploaded to wp-content/nibwp-sandbox/.',
                'To put a local image (e.g. one you cropped) straight into the Media Library, set register_attachment: true with a path inside the uploads directory (e.g. wp-content/uploads/hero.jpg), plus optional alt_text and title. The upload response then includes attachment_id and url — no follow-up PHP is needed.',
                'If registration fails after the bytes arrive, the upload returns an error naming the uploaded path: the file is on disk but not in the Media Library.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Create a temporary upload URL.
 *
 * @param array $input Input with destination path and optional limits.
 * @return array|WP_Error
 */
function nibwp_create_upload_link($input)
{
    $resolved = nibwp_resolve_path(path: (string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'php') {
        $sandbox_error = nibwp_check_php_sandbox($resolved);
        if (is_wp_error($sandbox_error)) {
            return $sandbox_error;
        }
    }

    $expires_in = max(30, min(3_600, (int) ($input['expires_in'] ?? 900)));
    $max_bytes = max(1, (int) ($input['max_bytes'] ?? 536_870_912));
    $expires_at = time() + $expires_in;
    $overwrite = ($input['overwrite'] ?? false) === true;
    $create_directories = ($input['create_directories'] ?? true) !== false;

    // Validated here as well as by the schema: whatever is accepted is signed
    // into the link and later written to the database by an anonymous request,
    // so this is the last point where a bad value can be turned away.
    $register_attachment = $input['register_attachment'] ?? false;
    if (!is_bool($register_attachment)) {
        return new WP_Error('invalid_register_attachment', 'register_attachment must be a boolean.', ['status' => 400]);
    }
    $text = [];
    foreach (['alt_text' => 1000, 'title' => 255] as $field => $max_length) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            return new WP_Error('invalid_' . $field, sprintf('%s must be a string.', $field), ['status' => 400]);
        }
        $value = sanitize_text_field($value);
        if (mb_strlen($value) > $max_length) {
            return new WP_Error('invalid_' . $field, sprintf(
                '%s must be at most %d characters.',
                $field,
                $max_length,
            ), ['status' => 400]);
        }
        $text[$field] = $value;
    }
    if (!$register_attachment && ($text['alt_text'] !== '' || $text['title'] !== '')) {
        // Accepting these silently would report success for a title and alt
        // text that are never stored anywhere.
        return new WP_Error(
            'register_attachment_required',
            'alt_text and title are only stored when register_attachment is true.',
            ['status' => 400],
        );
    }

    $user_id = get_current_user_id();
    if ($register_attachment) {
        // Refused now so the agent finds out before spending an upload on it;
        // the upload checks again because the link outlives this request.
        $attachment_error = nibwp_check_upload_attachment_target($resolved, $user_id);
        if (is_wp_error($attachment_error)) {
            return $attachment_error;
        }
    }

    // Everything the upload will act on is signed, so none of it — least of
    // all the registration flag or who the attachment belongs to — can be
    // changed after the link is handed out.
    $payload = [
        'path' => $resolved,
        'expires_at' => $expires_at,
        'max_bytes' => $max_bytes,
        'overwrite' => $overwrite,
        'create_directories' => $create_directories,
        'register_attachment' => $register_attachment,
        'alt_text' => $text['alt_text'],
        'title' => $text['title'],
        // The upload request has no logged-in user; this is who the attachment
        // is attributed to and whose capability is re-checked at upload time.
        'user_id' => $user_id,
    ];
    $token = nibwp_sign_upload_payload($payload);
    if (is_wp_error($token)) {
        return $token;
    }

    $upload_url = add_query_arg('token', rawurlencode($token), rest_url('nibwp/v1/upload'));

    return [
        'upload_url' => $upload_url,
        'method' => 'PUT',
        'path' => $resolved,
        'expires_at' => $expires_at,
        'max_bytes' => $max_bytes,
        'overwrite' => $overwrite,
        'register_attachment' => $register_attachment,
        'curl_examples' => [
            'curl -X PUT --data-binary @/path/to/local-file ' . escapeshellarg($upload_url),
            'curl -F file=@/path/to/local-file ' . escapeshellarg($upload_url),
        ],
    ];
}
