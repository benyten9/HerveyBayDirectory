<?php

declare(strict_types=1);

/**
 * Sandbox Loader
 * Loads AI-written PHP plugins from the sandbox directory. Includes automatic crash recovery in dev mode.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shutdown handler that creates a .crashed marker when a fatal error occurs while a sandbox file is loading.
 *
 * @param string      $crashed_file        Path to the .crashed marker file.
 * @param string|null $current_sandbox_file The sandbox file currently being loaded, or null if loading is complete.
 */
function nibwp_sandbox_crash_handler(string $crashed_file, ?string $current_sandbox_file): void
{
    if ($current_sandbox_file === null) {
        return;
    }

    $error = error_get_last();
    if ($error === null) {
        return;
    }

    // Only react to fatal error types that kill execution.
    if (!($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        return;
    }

    $error['sandbox_file'] = $current_sandbox_file;
    file_put_contents($crashed_file, (string) wp_json_encode($error), LOCK_EX);
}

add_action('plugins_loaded', static function (): void {
    // Helpers (nibwp_is_enabled) live in the Free plugin's includes/helpers.php
    // which loads later in nibwp.php than premium/bootstrap.php. Defer the
    // sandbox-loader pass until plugins_loaded so helpers are guaranteed
    // available and any WP API we touch (admin_notices etc.) is wired up.
    if (!function_exists('nibwp_is_enabled')) {
        return;
    }
    $sandbox_dir = NIBWP_SANDBOX_DIR;

    // Ensure sandbox directory exists.
    if (!is_dir($sandbox_dir)) {
        return;
    }

    $loading_file = $sandbox_dir . '.loading';
    $crashed_file = $sandbox_dir . '.crashed';
    $abilities_enabled = nibwp_is_enabled();

    // When abilities are disabled, load sandbox files without crash-recovery overhead.
    if (!$abilities_enabled) {
        $files = glob($sandbox_dir . '*.php');
        if ($files) {
            foreach ($files as $file) {
                require_once $file;
            }
        }
        return;
    }

    // Clean up legacy .loading marker if present.
    if (file_exists($loading_file)) {
        unlink($loading_file);
    }

    // Crash recovery: .crashed exists → stay in safe mode.
    $is_safe_mode = file_exists($crashed_file);

    // Manual safe mode via URL parameter.
    if (!$is_safe_mode && ($_GET['nibwp_safe_mode'] ?? null) === '1') {
        $is_safe_mode = true;
    }

    // Dashboard warnings.
    add_action('admin_notices', static function () use ($crashed_file) {
        // This notice used to render for every logged-in user, with no
        // capability check at all — a subscriber saw the sandbox internals of
        // a site they cannot administer.
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('nibwp_user_access_shows_notices') && !nibwp_user_access_shows_notices()) {
            return;
        }
        if (!file_exists($crashed_file)) {
            return;
        }

        $report = function_exists('nibwp_sandbox_crash_report') ? nibwp_sandbox_crash_report() : null;

        // Do not assert that a sandbox plugin is at fault when the recorded error says
        // otherwise. The sandbox is suspended either way, but the operator needs to know
        // where to look, and pointing them at the wrong plugin costs them an afternoon.
        $lead = ($report && $report['is_external'])
            ? esc_html__(
                'A fatal error during startup suspended every sandbox plugin. The error came from outside the sandbox, so another plugin is the likely cause.',
                domain: 'nibwp',
            )
            : esc_html__(
                'A fatal error during startup suspended every sandbox plugin.',
                domain: 'nibwp',
            );

        $detail = '';
        if ($report && $report['message'] !== '') {
            $where = $report['file'] !== ''
                ? sprintf(' %s:%d', $report['file'], $report['line'])
                : '';
            $detail .= sprintf(
                '<br><code>%s</code>',
                esc_html(trim($report['message'] . $where)),
            );
        }
        if ($report && $report['sandbox_file'] !== '') {
            $detail .= sprintf(
                '<br><small>%s</small>',
                sprintf(
                    /* translators: %s: sandbox file name */
                    esc_html__('Loading at the time: %s', domain: 'nibwp'),
                    '<code>' . esc_html($report['sandbox_file']) . '</code>',
                ),
            );
        }

        wp_admin_notice(
            sprintf(
                '<strong>%s</strong> %s%s<br><a href="%s" class="button button-primary" style="margin-top:8px;">%s</a>',
                esc_html__('NIBWP Sandbox: Safe mode is active.', domain: 'nibwp'),
                $lead,
                $detail,
                esc_url(admin_url('admin.php?page=nibwp-sandbox')),
                esc_html__('Review and exit safe mode', domain: 'nibwp'),
            ),
            [
                'type' => 'error',
                'dismissible' => false,
            ],
        );
    });

    // Safe mode: skip loading all sandbox files.
    if ($is_safe_mode) {
        return;
    }

    // Normal load with shutdown-based crash detection.
    $files = glob($sandbox_dir . '*.php');
    if (!$files) {
        return;
    }

    // Tracks which sandbox file is currently being loaded. The shutdown handler uses this to
    // detect crashes even when the fatal error is thrown from a core or third-party file in the
    // call chain (e.g. sandbox file → get_header() → wp_head() → fatal in wp-includes/).
    // Set to null after the loop completes, which makes the handler a no-op.
    $current_sandbox_file = null;

    register_shutdown_function(static function () use ($crashed_file, &$current_sandbox_file) {
        nibwp_sandbox_crash_handler($crashed_file, $current_sandbox_file);
    });

    foreach ($files as $file) {
        $current_sandbox_file = $file;
        require_once $file;
    }
    $current_sandbox_file = null;
}, 8);
