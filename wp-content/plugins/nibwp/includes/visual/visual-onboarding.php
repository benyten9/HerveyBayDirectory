<?php

declare(strict_types=1);

/**
 * Agent View — whether an AI client can reach this site, and the credential-free
 * Claude Desktop bundle the Connect dialog offers.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether an AI client could reach this site at all, and how.
 *
 * @return array{enabled: bool, connected: bool, clients: array<int, string>, count: int}
 */
function nibwp_visual_connection_state(): array
{
    $enabled = function_exists('nibwp_is_enabled') && (bool) nibwp_is_enabled();

    $clients = [];
    foreach (function_exists('nibwp_oauth_user_connections') ? nibwp_oauth_user_connections() : [] as $c) {
        $clients[] = (string) ($c['name'] ?? __('Unknown application', 'nibwp'));
    }

    if (class_exists('WP_Application_Passwords')) {
        foreach (WP_Application_Passwords::get_user_application_passwords(get_current_user_id()) as $pw) {
            $name = trim((string) ($pw['name'] ?? ''));
            if (!str_starts_with($name, 'NIBWP')) {
                continue;
            }
            // Keep the label the user actually gave it. Stripping the prefix
            // turned "NIBWP 2" into "2", which names nothing.
            $clients[] = $name;
        }
    }

    $clients = array_values(array_unique($clients));

    return [
        'enabled' => $enabled,
        'connected' => $clients !== [],
        'clients' => array_slice($clients, 0, 6),
        'count' => count($clients),
    ];
}

/**
 * A one-click bundle for Claude Desktop, carrying no credentials.
 *
 * The existing bundle on the Connect screen embeds an application password, so
 * it is a secret you must not forward to anyone. This one points the desktop
 * app at this site's MCP address and nothing else: the sign-in happens in the
 * browser, through OAuth, the first time it connects. It is therefore safe to
 * keep in a downloads folder or hand to a colleague — they still have to be
 * able to sign in to the site.
 *
 * Offered only when the site can actually be signed in to, since a bundle that
 * cannot complete its own flow is worse than no bundle.
 */
function nibwp_visual_bundle_available(): bool
{
    if (!class_exists('ZipArchive') && !function_exists('nibwp_zip_store_bytes')) {
        return false;
    }

    return function_exists('nibwp_oauth_availability')
        ? (bool) (nibwp_oauth_availability()['ok'] ?? false)
        : false;
}

function nibwp_visual_bundle_url(): string
{
    return wp_nonce_url(
        admin_url('admin-post.php?action=nibwp_visual_bundle'),
        'nibwp_visual_bundle'
    );
}

add_action('admin_post_nibwp_visual_bundle', 'nibwp_visual_send_bundle');

function nibwp_visual_send_bundle(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to download this.', 'nibwp'));
    }

    check_admin_referer('nibwp_visual_bundle');

    if (!function_exists('nibwp_zip_store_bytes')) {
        wp_die(esc_html__('This server cannot build the bundle. Add the connector by hand from the Connect screen instead.', 'nibwp'));
    }

    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $mcp = function_exists('nibwp_oauth_resource_id')
        ? nibwp_oauth_resource_id()
        : rest_url('mcp/nibwp');

    $manifest = [
        'manifest_version' => '0.3',
        'name' => 'nibwp-' . preg_replace('/[^a-z0-9.-]+/i', '-', $host),
        'display_name' => 'NibWP — ' . $host,
        'version' => defined('NIBWP_VERSION') ? (string) NIBWP_VERSION : '1.0.0',
        'description' => sprintf(
            /* translators: %s: site host */
            __('Work on %s with your AI assistant, and watch it happen in NibWP Agent View.', 'nibwp'),
            $host
        ),
        'author' => ['name' => 'NibWP', 'url' => 'https://nibwp.com'],
        'server' => [
            // The entry point is required by the manifest schema even though the
            // server is reached over HTTP; nothing in the bundle is executed.
            'type' => 'node',
            'entry_point' => 'server/index.js',
            'mcp_config' => [
                'type' => 'http',
                'url' => $mcp,
            ],
        ],
    ];

    $readme = sprintf(
        /* translators: %s: this site's MCP address */
        __("This bundle points Claude Desktop at:\n\n    %s\n\nIt contains no password. The first time it connects, your browser opens\nand you approve exactly what it may do — so this file is safe to keep,\nand useless to anyone who cannot sign in to the site.\n", 'nibwp'),
        $mcp
    );

    $bytes = nibwp_zip_store_bytes([
        'manifest.json' => (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'server/index.js' => "// Not executed. The server is reached over HTTP; see manifest.json.\n",
        'README.txt' => $readme,
    ]);

    $file = 'nibwp-' . sanitize_file_name($host !== '' ? $host : 'site') . '.mcpb';

    nocache_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary archive
    exit;
}
