<?php
/**
 * Plugin Name: Mail Trace Logger
 * Description: Logs wp_mail calls with backtrace + request context.
 */

if (!defined('ABSPATH')) { exit; }

add_filter('wp_mail', function($args) {

    $subject = isset($args['subject']) ? (string)$args['subject'] : '';
    $uri     = $_SERVER['REQUEST_URI'] ?? '';

    $is_suspicious =
        (strlen($subject) >= 12 && preg_match('/^[A-Za-z0-9]+$/', $subject)) ||
        stripos($subject, 'listing') !== false ||
        stripos($subject, 'directorist') !== false ||
        stripos($subject, 'new submission') !== false ||
        stripos($subject, 'submission') !== false ||
        stripos($uri, 'admin-ajax') !== false ||
        stripos($uri, 'wp-json') !== false ||
        stripos($uri, 'add-listing') !== false;

    if (!$is_suspicious) {
        return $args;
    }

    $ctx = [
        'time_utc' => gmdate('c'),
        'host'     => $_SERVER['HTTP_HOST'] ?? '',
        'uri'      => $uri,
        'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
        'referer'  => $_SERVER['HTTP_REFERER'] ?? '',
        'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        'to'       => $args['to'] ?? '',
        'subject'  => $subject,
    ];

    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
    $frames = [];
    foreach ($bt as $f) {
        $file = isset($f['file']) ? str_replace(ABSPATH, '', $f['file']) : '';
        $line = $f['line'] ?? '';
        $func = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
        $frames[] = trim("$file:$line $func");
    }

    $log = "=== wp_mail TRACE ===\n" .
           json_encode($ctx, JSON_PRETTY_PRINT) . "\n" .
           implode("\n", $frames) . "\n\n";

    @file_put_contents(WP_CONTENT_DIR . '/mail-trace.log', $log, FILE_APPEND);

    return $args;
});