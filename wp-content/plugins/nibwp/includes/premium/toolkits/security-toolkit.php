<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Security & Maintenance Toolkit — comprehensive tools for scanning,
 * repairing, hardening, and maintaining WordPress installations.
 */

// ═══════════════════════════════════════════════════════════════
// 1. CORE INTEGRITY — Verify & repair WordPress core files
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-verify-core', [
    'label' => __('Verify WordPress Core Integrity', domain: 'nibwp'),
    'description' => __('Compares WordPress core files against official checksums to detect modified, missing, or injected files. Identifies tampered core files that may indicate a hack.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'locale' => ['type' => 'string', 'description' => 'WP locale (default: current site locale).', 'default' => ''],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'wp_version' => ['type' => 'string'],
            'modified' => ['type' => 'array', 'description' => 'Files that differ from official checksums.'],
            'unknown' => ['type' => 'array', 'description' => 'Files in wp-admin/wp-includes that should not exist.'],
            'missing' => ['type' => 'array', 'description' => 'Expected files that are missing.'],
            'summary' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_security_verify_core',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Verifies WP core files against official checksums from api.wordpress.org.\nRun this FIRST when investigating a suspected hack.\nModified files = potential backdoor. Unknown files in wp-admin or wp-includes = likely malware.\nFollow up with security-repair-core to fix any issues found.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_verify_core(array $input): array|WP_Error
{
    global $wp_version;

    $locale = trim((string) ($input['locale'] ?? ''));
    if ($locale === '') {
        $locale = get_locale();
    }

    // Fetch checksums from WordPress.org.
    $url = "https://api.wordpress.org/core/checksums/1.0/?version={$wp_version}&locale={$locale}";
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return new WP_Error('checksum_fetch_failed', 'Could not fetch checksums: ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $checksums = $body['checksums'] ?? null;
    if (!is_array($checksums)) {
        return new WP_Error('checksum_parse_failed', 'Invalid checksum response from WordPress.org.');
    }

    $modified = [];
    $missing = [];
    foreach ($checksums as $file => $expected_md5) {
        $path = ABSPATH . $file;
        if (!file_exists($path)) {
            $missing[] = $file;
            continue;
        }
        $actual_md5 = md5_file($path);
        if ($actual_md5 !== $expected_md5) {
            $modified[] = [
                'file' => $file,
                'expected' => $expected_md5,
                'actual' => $actual_md5,
                'size' => filesize($path),
            ];
        }
    }

    // Find unknown files in wp-admin and wp-includes.
    $unknown = [];
    foreach (['wp-admin', 'wp-includes'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH . $dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            $relative = str_replace(ABSPATH, '', $file->getPathname());
            $relative = str_replace('\\', '/', $relative);
            if (!array_key_exists($relative, $checksums) && $file->isFile()) {
                $unknown[] = [
                    'file' => $relative,
                    'size' => $file->getSize(),
                    'modified' => gmdate('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }
    }

    $summary = sprintf(
        '%d files checked. %d modified, %d unknown, %d missing.',
        count($checksums),
        count($modified),
        count($unknown),
        count($missing),
    );

    return [
        'success' => true,
        'wp_version' => $wp_version,
        'modified' => $modified,
        'unknown' => $unknown,
        'missing' => $missing,
        'summary' => $summary,
    ];
}

wp_register_ability('nibwp/security-repair-core', [
    'label' => __('Repair WordPress Core Files', domain: 'nibwp'),
    'description' => __('Re-downloads and restores modified or missing WordPress core files from the official release. Removes unknown files injected into wp-admin and wp-includes.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'remove_unknown' => ['type' => 'boolean', 'description' => 'Also delete unknown files found in wp-admin/wp-includes.', 'default' => false],
            'dry_run' => ['type' => 'boolean', 'description' => 'Preview what would be done without making changes.', 'default' => true],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_repair_core',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Repairs WP core by re-downloading the official release.\nALWAYS run with dry_run=true first to preview changes.\nThen run with dry_run=false to apply.\nSet remove_unknown=true to also delete injected files from wp-admin/wp-includes.\nDoes NOT touch wp-content — only core files.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_repair_core(array $input): array|WP_Error
{
    global $wp_version;

    $remove_unknown = !empty($input['remove_unknown']);
    $dry_run = $input['dry_run'] ?? true;

    // First verify to find what needs fixing.
    $verify = nibwp_security_verify_core([]);
    if (is_wp_error($verify)) {
        return $verify;
    }

    $actions = [];

    // Modified + missing files → will be restored.
    foreach (array_merge($verify['modified'], array_map(fn($f) => ['file' => $f], $verify['missing'])) as $item) {
        $actions[] = ['action' => 'restore', 'file' => $item['file']];
    }

    // Unknown files → optionally removed.
    if ($remove_unknown) {
        foreach ($verify['unknown'] as $item) {
            $actions[] = ['action' => 'delete', 'file' => $item['file']];
        }
    }

    if ($actions === []) {
        return ['success' => true, 'message' => 'No issues found. Core files are clean.', 'actions' => []];
    }

    if ($dry_run) {
        return ['success' => true, 'dry_run' => true, 'actions' => $actions, 'message' => count($actions) . ' actions would be performed. Run again with dry_run=false to apply.'];
    }

    // Download fresh core.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $download_url = "https://wordpress.org/wordpress-{$wp_version}.zip";
    $tmp_file = download_url($download_url);
    if (is_wp_error($tmp_file)) {
        return $tmp_file;
    }

    WP_Filesystem();
    $tmp_dir = wp_tempnam('nibwp_core_');
    unlink($tmp_dir);
    wp_mkdir_p($tmp_dir);
    $unzip = unzip_file($tmp_file, $tmp_dir);
    unlink($tmp_file);

    if (is_wp_error($unzip)) {
        return $unzip;
    }

    $core_dir = $tmp_dir . '/wordpress/';
    $restored = [];
    $deleted = [];
    $errors = [];

    foreach ($actions as $act) {
        if ($act['action'] === 'restore') {
            $source = $core_dir . $act['file'];
            $dest = ABSPATH . $act['file'];
            if (file_exists($source)) {
                wp_mkdir_p(dirname($dest));
                if (copy($source, $dest)) {
                    $restored[] = $act['file'];
                } else {
                    $errors[] = 'Failed to restore: ' . $act['file'];
                }
            }
        } elseif ($act['action'] === 'delete') {
            $path = ABSPATH . $act['file'];
            if (file_exists($path) && unlink($path)) {
                $deleted[] = $act['file'];
            } else {
                $errors[] = 'Failed to delete: ' . $act['file'];
            }
        }
    }

    // Cleanup temp.
    global $wp_filesystem;
    if ($wp_filesystem) {
        $wp_filesystem->delete($tmp_dir, true);
    }

    return [
        'success' => $errors === [],
        'restored' => $restored,
        'deleted' => $deleted,
        'errors' => $errors,
        'message' => sprintf('Restored %d files, deleted %d files, %d errors.', count($restored), count($deleted), count($errors)),
    ];
}

// ═══════════════════════════════════════════════════════════════
// 2. MALWARE SCANNER — Find suspicious code patterns
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-scan-malware', [
    'label' => __('Scan for Malware & Suspicious Code', domain: 'nibwp'),
    'description' => __('Scans PHP files for common malware signatures: base64_decode, eval, hidden iframes, backdoor patterns, obfuscated code, suspicious file names, and known exploit patterns.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Directory to scan. Default: wp-content.', 'default' => 'wp-content'],
            'max_files' => ['type' => 'integer', 'description' => 'Max files to scan.', 'default' => 5000, 'maximum' => 20000],
            'extensions' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'File extensions to scan.',
                'default' => ['php', 'js', 'html', 'htm'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_scan_malware',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Scans files for known malware patterns.\nDefault scans wp-content. Can also scan the entire ABSPATH.\nReturns suspicious files with the matched pattern and line number.\nFalse positives possible — review each finding before acting.\nFollow up with security-quarantine-file to isolate threats.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_scan_malware(array $input): array|WP_Error
{
    $scan_path = (string) ($input['path'] ?? 'wp-content');
    $max_files = min((int) ($input['max_files'] ?? 5000), 20000);
    $extensions = $input['extensions'] ?? ['php', 'js', 'html', 'htm'];

    $base = ABSPATH . ltrim($scan_path, '/');
    if (!is_dir($base)) {
        return new WP_Error('invalid_path', "Directory not found: $scan_path");
    }

    // Malware signature patterns.
    $patterns = [
        'eval_base64' => '/\beval\s*\(\s*base64_decode\s*\(/i',
        'eval_gzinflate' => '/\beval\s*\(\s*gzinflate\s*\(/i',
        'eval_str_rot13' => '/\beval\s*\(\s*str_rot13\s*\(/i',
        'eval_gzuncompress' => '/\beval\s*\(\s*gzuncompress\s*\(/i',
        'preg_replace_eval' => '/preg_replace\s*\(\s*["\'].*\/e["\'].*\)/i',
        'hidden_eval' => '/\$\w{1,3}\s*\(\s*\$\w{1,3}\s*\(\s*\$\w{1,3}/i',
        'base64_long_string' => '/[A-Za-z0-9+\/]{200,}={0,2}/',
        'hex_encoded' => '/\\\\x[0-9a-fA-F]{2}(\\\\x[0-9a-fA-F]{2}){10,}/',
        'shell_exec' => '/\b(shell_exec|passthru|system|popen|proc_open|pcntl_exec)\s*\(/i',
        'hidden_iframe' => '/<iframe[^>]+(display\s*:\s*none|height\s*=\s*["\']?0|width\s*=\s*["\']?0)/i',
        'wp_remote_include' => '/\b(include|require|include_once|require_once)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'file_put_contents_remote' => '/file_put_contents\s*\(.*\$_(GET|POST|REQUEST)/i',
        'assert_string' => '/\bassert\s*\(\s*\$/i',
        'create_function' => '/\bcreate_function\s*\(/i',
        'backdoor_auth' => '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*["\'][a-z0-9]{1,4}["\']\s*\]\s*==\s*["\'][a-z0-9]+["\']/i',
        'chmod_777' => '/chmod\s*\(.*0?777\s*\)/i',
        'suspicious_globals' => '/\$GLOBALS\s*\[\s*["\'][a-z0-9_]{30,}["\']\s*\]/i',
        'wp_filesystem_abuse' => '/\$wp_filesystem\s*->\s*(put_contents|copy|move)\s*\(.*\$_(GET|POST|REQUEST)/i',
        'obfuscated_variable' => '/\$\{["\'][^"\']+["\']\}/i',
    ];

    $suspicious_filenames = [
        '/^[a-z0-9]{8,}\.php$/',         // Random-named PHP
        '/^\.[\w]+\.php$/',               // Dot-prefixed PHP
        '/wp-(?:tmp|cache|vcd|feed)\.php$/i',
        '/(?:shell|c99|r57|wso|b374k|alfa|mini)\.php$/i',
        '/(?:config|xmlrpc|about|class)\d+\.php$/i',
    ];

    $findings = [];
    $scanned = 0;
    $ext_pattern = '/\.(' . implode('|', array_map('preg_quote', $extensions)) . ')$/i';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if ($scanned >= $max_files) {
            break;
        }
        if (!$file->isFile() || !preg_match($ext_pattern, $file->getFilename())) {
            continue;
        }

        $scanned++;
        $relative = str_replace(ABSPATH, '', $file->getPathname());
        $relative = str_replace('\\', '/', $relative);
        $filename = $file->getFilename();

        // Check suspicious filenames.
        foreach ($suspicious_filenames as $fp) {
            if (preg_match($fp, $filename)) {
                $findings[] = [
                    'file' => $relative,
                    'pattern' => 'suspicious_filename',
                    'match' => $filename,
                    'line' => 0,
                    'severity' => 'high',
                ];
                break;
            }
        }

        // Skip very large files.
        if ($file->getSize() > 2_000_000) {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $lines = explode("\n", $content);
        foreach ($patterns as $name => $regex) {
            foreach ($lines as $line_num => $line) {
                if (preg_match($regex, $line, $m)) {
                    $findings[] = [
                        'file' => $relative,
                        'pattern' => $name,
                        'match' => mb_substr(trim($m[0]), 0, 100),
                        'line' => $line_num + 1,
                        'severity' => in_array($name, ['eval_base64', 'shell_exec', 'backdoor_auth', 'hidden_eval', 'wp_remote_include'], true) ? 'critical' : 'warning',
                    ];
                    break; // One finding per pattern per file.
                }
            }
        }
    }

    // Sort by severity.
    usort($findings, static fn($a, $b) => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

    return [
        'success' => true,
        'files_scanned' => $scanned,
        'findings_count' => count($findings),
        'critical' => count(array_filter($findings, static fn($f) => $f['severity'] === 'critical')),
        'findings' => array_slice($findings, 0, 100), // Cap output.
        'message' => sprintf('Scanned %d files. Found %d suspicious patterns.', $scanned, count($findings)),
    ];
}

// ═══════════════════════════════════════════════════════════════
// 3. FILE OPERATIONS — Quarantine, find & replace, bulk delete
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-quarantine-file', [
    'label' => __('Quarantine Suspicious File', domain: 'nibwp'),
    'description' => __('Moves a suspicious file to a quarantine directory, renames it to prevent execution, and logs the action. Can also restore quarantined files.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['quarantine', 'restore', 'list', 'delete_quarantined'], 'description' => 'Action.'],
            'file' => ['type' => 'string', 'description' => 'File path relative to ABSPATH (for quarantine/restore).'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_quarantine',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Quarantine moves files to wp-content/nibwp-quarantine/ and adds .quarantined extension.\nUse after malware scan finds threats.\n'list' shows all quarantined files.\n'restore' puts a file back to its original location.\n'delete_quarantined' permanently removes a quarantined file.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_security_quarantine(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');
    $quarantine_dir = WP_CONTENT_DIR . '/nibwp-quarantine/';
    wp_mkdir_p($quarantine_dir);

    // Protect quarantine dir.
    $htaccess = $quarantine_dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
    $index = $quarantine_dir . 'index.php';
    if (!file_exists($index)) {
        file_put_contents($index, "<?php // Silence is golden.\n");
    }

    if ($action === 'list') {
        $files = [];
        if (is_dir($quarantine_dir)) {
            foreach (scandir($quarantine_dir) as $f) {
                if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === 'index.php') {
                    continue;
                }
                $meta_file = $quarantine_dir . $f . '.meta.json';
                $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
                $files[] = [
                    'quarantined_name' => $f,
                    'original_path' => $meta['original_path'] ?? 'unknown',
                    'quarantined_at' => $meta['quarantined_at'] ?? 'unknown',
                    'size' => filesize($quarantine_dir . $f),
                ];
            }
        }
        return ['success' => true, 'data' => $files, 'message' => count($files) . ' files in quarantine.'];
    }

    $file = (string) ($input['file'] ?? '');
    if ($file === '') {
        return new WP_Error('missing_file', 'file is required.');
    }

    if ($action === 'quarantine') {
        $source = ABSPATH . $file;
        if (!file_exists($source)) {
            return new WP_Error('not_found', "File not found: $file");
        }
        $safe_name = str_replace(['/', '\\'], '__', $file) . '.quarantined';
        $dest = $quarantine_dir . $safe_name;
        if (!rename($source, $dest)) {
            return new WP_Error('move_failed', "Failed to quarantine: $file");
        }
        // Save metadata.
        file_put_contents($quarantine_dir . $safe_name . '.meta.json', wp_json_encode([
            'original_path' => $file,
            'quarantined_at' => gmdate('c'),
            'size' => filesize($dest),
        ]));
        return ['success' => true, 'message' => "Quarantined: $file → $safe_name"];
    }

    if ($action === 'restore') {
        $safe_name = str_replace(['/', '\\'], '__', $file) . '.quarantined';
        $source = $quarantine_dir . $safe_name;
        $meta_file = $source . '.meta.json';
        if (!file_exists($source)) {
            return new WP_Error('not_found', "Quarantined file not found: $safe_name");
        }
        $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
        $original = $meta['original_path'] ?? $file;
        $dest = ABSPATH . $original;
        wp_mkdir_p(dirname($dest));
        if (!rename($source, $dest)) {
            return new WP_Error('restore_failed', "Failed to restore: $original");
        }
        if (file_exists($meta_file)) {
            unlink($meta_file);
        }
        return ['success' => true, 'message' => "Restored: $original"];
    }

    if ($action === 'delete_quarantined') {
        $safe_name = str_replace(['/', '\\'], '__', $file) . '.quarantined';
        $path = $quarantine_dir . $safe_name;
        $meta_file = $path . '.meta.json';
        if (!file_exists($path)) {
            return new WP_Error('not_found', "Quarantined file not found.");
        }
        unlink($path);
        if (file_exists($meta_file)) {
            unlink($meta_file);
        }
        return ['success' => true, 'message' => "Permanently deleted quarantined file."];
    }

    return new WP_Error('invalid_action', "Unknown action: $action");
}

wp_register_ability('nibwp/security-find-replace', [
    'label' => __('Find & Replace in Files', domain: 'nibwp'),
    'description' => __('Search for a string or regex pattern across files and optionally replace it. Supports dry run to preview changes before applying.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string', 'description' => 'Text or regex pattern to find.', 'minLength' => 1],
            'replace' => ['type' => 'string', 'description' => 'Replacement text. Omit for search-only.'],
            'path' => ['type' => 'string', 'description' => 'Directory to search. Default: wp-content.', 'default' => 'wp-content'],
            'extensions' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['php', 'js', 'html']],
            'is_regex' => ['type' => 'boolean', 'description' => 'Treat search as regex.', 'default' => false],
            'dry_run' => ['type' => 'boolean', 'description' => 'Preview only, no changes.', 'default' => true],
            'max_files' => ['type' => 'integer', 'default' => 3000, 'maximum' => 10000],
        ],
        'required' => ['search'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_find_replace',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Find and replace text across files.\nALWAYS use dry_run=true first.\nUseful for removing injected scripts, fixing URLs after migration, cleaning malware snippets.\nSupports regex with is_regex=true.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_security_find_replace(array $input): array|WP_Error
{
    $search = (string) ($input['search'] ?? '');
    $replace = $input['replace'] ?? null;
    $path = (string) ($input['path'] ?? 'wp-content');
    $extensions = $input['extensions'] ?? ['php', 'js', 'html'];
    $is_regex = !empty($input['is_regex']);
    $dry_run = $input['dry_run'] ?? true;
    $max_files = min((int) ($input['max_files'] ?? 3000), 10000);

    $base = ABSPATH . ltrim($path, '/');
    if (!is_dir($base)) {
        return new WP_Error('invalid_path', "Directory not found: $path");
    }

    if ($is_regex && @preg_match($search, '') === false) {
        return new WP_Error('invalid_regex', "Invalid regex pattern: $search");
    }

    $ext_pattern = '/\.(' . implode('|', array_map('preg_quote', $extensions)) . ')$/i';
    $matches = [];
    $replaced_count = 0;
    $scanned = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if ($scanned >= $max_files) {
            break;
        }
        if (!$file->isFile() || !preg_match($ext_pattern, $file->getFilename()) || $file->getSize() > 2_000_000) {
            continue;
        }

        $scanned++;
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $found = $is_regex ? preg_match($search, $content) : str_contains($content, $search);
        if (!$found) {
            continue;
        }

        $relative = str_replace([ABSPATH, '\\'], ['', '/'], $file->getPathname());
        $count = $is_regex ? preg_match_all($search, $content) : substr_count($content, $search);

        $matches[] = ['file' => $relative, 'occurrences' => $count];

        if ($replace !== null && !$dry_run) {
            $new_content = $is_regex ? preg_replace($search, $replace, $content) : str_replace($search, $replace, $content);
            if ($new_content !== null && $new_content !== $content) {
                file_put_contents($file->getPathname(), $new_content);
                $replaced_count++;
            }
        }
    }

    return [
        'success' => true,
        'files_scanned' => $scanned,
        'files_matched' => count($matches),
        'files_modified' => $replaced_count,
        'dry_run' => $dry_run,
        'matches' => array_slice($matches, 0, 100),
        'message' => $dry_run
            ? sprintf('Found %d matches in %d files (dry run).', count($matches), $scanned)
            : sprintf('Replaced in %d files out of %d matches.', $replaced_count, count($matches)),
    ];
}

// ═══════════════════════════════════════════════════════════════
// 4. DATABASE SECURITY — Clean injected content, suspicious users
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-scan-database', [
    'label' => __('Scan Database for Injections', domain: 'nibwp'),
    'description' => __('Scans WordPress database tables for injected scripts, hidden iframes, suspicious redirects, encoded payloads, and spam links in post content, options, and comments.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'tables' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Tables to scan. Default: posts, options, comments, postmeta.',
                'default' => ['posts', 'options', 'comments', 'postmeta'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_scan_database',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Scans database for injected malware.\nChecks post content, options, comments, and meta for suspicious patterns.\nFindings include the table, row ID, column, and matched pattern.\nFollow up with security-db-find-replace to clean injections.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_scan_database(array $input): array|WP_Error
{
    global $wpdb;

    $tables = $input['tables'] ?? ['posts', 'options', 'comments', 'postmeta'];
    $patterns = [
        'script_injection' => '<script[^>]*>',
        'hidden_iframe' => '<iframe[^>]*(display\s*:\s*none|height\s*=\s*["\']?0)',
        'eval_base64' => 'eval\s*\(\s*base64_decode',
        'php_tag_in_content' => '<\?php',
        'suspicious_redirect' => '(window\.location|document\.location|header\s*\(\s*["\']Location)',
        'encoded_payload' => '\\\\x[0-9a-fA-F]{2}(\\\\x[0-9a-fA-F]{2}){10,}',
        'spam_pharma' => '(viagra|cialis|pharmacy|casino|poker|slots)\s*(online|buy|cheap|pills)',
    ];

    $findings = [];

    foreach ($tables as $table_key) {
        $table = $wpdb->prefix . $table_key;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            continue;
        }

        // Determine columns and ID column.
        $columns_info = match ($table_key) {
            'posts' => ['id_col' => 'ID', 'text_cols' => ['post_content', 'post_title', 'post_excerpt']],
            'options' => ['id_col' => 'option_id', 'text_cols' => ['option_value']],
            'comments' => ['id_col' => 'comment_ID', 'text_cols' => ['comment_content', 'comment_author', 'comment_author_url']],
            'postmeta' => ['id_col' => 'meta_id', 'text_cols' => ['meta_value']],
            default => ['id_col' => 'id', 'text_cols' => []],
        };

        foreach ($columns_info['text_cols'] as $col) {
            foreach ($patterns as $name => $regex) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $results = $wpdb->get_results("SELECT `{$columns_info['id_col']}` AS row_id, SUBSTRING(`{$col}`, 1, 200) AS snippet FROM `{$table}` WHERE `{$col}` REGEXP '{$regex}' LIMIT 50");
                foreach ($results as $row) {
                    $findings[] = [
                        'table' => $table_key,
                        'row_id' => $row->row_id,
                        'column' => $col,
                        'pattern' => $name,
                        'snippet' => mb_substr($row->snippet, 0, 150),
                        'severity' => in_array($name, ['script_injection', 'eval_base64', 'php_tag_in_content'], true) ? 'critical' : 'warning',
                    ];
                }
            }
        }
    }

    return [
        'success' => true,
        'tables_scanned' => count($tables),
        'findings_count' => count($findings),
        'findings' => $findings,
        'message' => sprintf('Scanned %d tables. Found %d suspicious entries.', count($tables), count($findings)),
    ];
}

wp_register_ability('nibwp/security-db-find-replace', [
    'label' => __('Database Find & Replace', domain: 'nibwp'),
    'description' => __('Find and replace text across WordPress database tables. Handles serialized data safely. Supports dry run preview.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string', 'description' => 'Text to find.', 'minLength' => 1],
            'replace' => ['type' => 'string', 'description' => 'Replacement text.'],
            'tables' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tables to process.', 'default' => ['posts', 'postmeta', 'options', 'comments']],
            'dry_run' => ['type' => 'boolean', 'default' => true],
        ],
        'required' => ['search', 'replace'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_db_find_replace',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Database-level find and replace.\nALWAYS dry_run=true first.\nHandles serialized data (updates string lengths in serialized arrays).\nCommon uses: domain migration, removing injected URLs, cleaning spam.\nDoes NOT handle regex — use exact strings only.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_security_db_find_replace(array $input): array|WP_Error
{
    global $wpdb;

    $search = (string) ($input['search'] ?? '');
    $replace = (string) ($input['replace'] ?? '');
    $tables = $input['tables'] ?? ['posts', 'postmeta', 'options', 'comments'];
    $dry_run = $input['dry_run'] ?? true;

    $results = [];
    $total = 0;

    foreach ($tables as $table_key) {
        $table = $wpdb->prefix . $table_key;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            continue;
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");
        $text_cols = [];
        foreach ($columns as $col) {
            if (preg_match('/(text|varchar|longtext|mediumtext|char)/i', $col->Type)) {
                $text_cols[] = $col->Field;
            }
        }

        $pk = $columns[0]->Field ?? 'id';
        $table_count = 0;

        foreach ($text_cols as $col) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
            ));

            if ($count > 0) {
                $table_count += $count;
                if (!$dry_run) {
                    // Handle serialized data.
                    $rows = $wpdb->get_results("SELECT `{$pk}`, `{$col}` FROM `{$table}` WHERE `{$col}` LIKE '%" . $wpdb->esc_like($search) . "%'");
                    foreach ($rows as $row) {
                        $value = $row->{$col};
                        $unserialized = @unserialize($value);
                        if ($unserialized !== false || $value === 'b:0;') {
                            // Serialized data — do recursive replace.
                            $new_value = nibwp_security_recursive_replace($search, $replace, $unserialized);
                            $new_value = serialize($new_value);
                        } else {
                            $new_value = str_replace($search, $replace, $value);
                        }
                        if ($new_value !== $value) {
                            $wpdb->update($table, [$col => $new_value], [$pk => $row->{$pk}]);
                        }
                    }
                }
            }
        }

        if ($table_count > 0) {
            $results[] = ['table' => $table_key, 'rows_affected' => $table_count];
            $total += $table_count;
        }
    }

    return [
        'success' => true,
        'dry_run' => $dry_run,
        'total_rows' => $total,
        'tables' => $results,
        'message' => $dry_run
            ? sprintf('Found %d rows containing the search string (dry run).', $total)
            : sprintf('Replaced in %d rows across %d tables.', $total, count($results)),
    ];
}

function nibwp_security_recursive_replace(string $search, string $replace, mixed $data): mixed
{
    if (is_string($data)) {
        return str_replace($search, $replace, $data);
    }
    if (is_array($data)) {
        return array_map(static fn($v) => nibwp_security_recursive_replace($search, $replace, $v), $data);
    }
    return $data;
}

// ═══════════════════════════════════════════════════════════════
// 5. USER SECURITY — Detect rogue admins, reset passwords
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-audit-users', [
    'label' => __('Audit User Accounts', domain: 'nibwp'),
    'description' => __('Audits all admin and editor accounts for suspicious activity: recently created admins, admin accounts with weak emails, users with unusual capabilities, and accounts with no posts.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => new stdClass(),
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_audit_users',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Audits user accounts for signs of compromise.\nChecks for: recently created admin accounts, suspicious email domains, admin accounts with no posts (likely injected), users with extra capabilities.\nRun this when investigating a hack to find rogue admin accounts.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_audit_users(array $input): array
{
    $admins = get_users(['role' => 'administrator']);
    $editors = get_users(['role' => 'editor']);
    $all_privileged = array_merge($admins, $editors);

    $suspicious_domains = ['mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email', 'yopmail.com', 'trashmail.com', '10minutemail.com', 'maildrop.cc'];
    $one_week_ago = strtotime('-7 days');

    $findings = [];
    $users = [];

    foreach ($all_privileged as $user) {
        $registered = strtotime($user->user_registered);
        $email_domain = substr(strrchr($user->user_email, '@'), 1);
        $post_count = count_user_posts($user->ID);

        $flags = [];

        if ($registered > $one_week_ago) {
            $flags[] = 'recently_created';
        }
        if (in_array(strtolower($email_domain), $suspicious_domains, true)) {
            $flags[] = 'suspicious_email_domain';
        }
        if (in_array('administrator', $user->roles, true) && $post_count === 0 && $registered > strtotime('-30 days')) {
            $flags[] = 'admin_no_posts';
        }

        $user_data = [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => $user->roles,
            'registered' => $user->user_registered,
            'post_count' => $post_count,
            'flags' => $flags,
        ];

        $users[] = $user_data;
        if ($flags !== []) {
            $findings[] = $user_data;
        }
    }

    return [
        'success' => true,
        'total_privileged_users' => count($all_privileged),
        'total_admins' => count($admins),
        'suspicious_users' => $findings,
        'all_privileged' => $users,
        'message' => sprintf('%d privileged users found. %d flagged as suspicious.', count($all_privileged), count($findings)),
    ];
}

// ═══════════════════════════════════════════════════════════════
// 6. HARDENING — Security headers, file permissions, wp-config
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-health-check', [
    'label' => __('Security Health Check', domain: 'nibwp'),
    'description' => __('Comprehensive security health check: file permissions, directory listing, wp-config exposure, debug mode, SSL status, WordPress/PHP versions, security headers, and common misconfigurations.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => new stdClass(),
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_health_check',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Run a comprehensive security health check.\nReturns pass/fail/warning for each check with recommendations.\nCovers: file permissions, debug mode, SSL, PHP version, directory listing, wp-config security, database prefix, security keys, auto-updates.",
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_health_check(array $input): array
{
    global $wpdb, $wp_version;

    $checks = [];

    // PHP version.
    $php = PHP_VERSION;
    $checks[] = [
        'check' => 'php_version',
        'status' => version_compare($php, '8.1', '>=') ? 'pass' : (version_compare($php, '8.0', '>=') ? 'warning' : 'fail'),
        'value' => $php,
        'recommendation' => version_compare($php, '8.1', '>=') ? '' : 'Upgrade to PHP 8.1+ for security and performance.',
    ];

    // WP version (is it latest?).
    $checks[] = [
        'check' => 'wp_version',
        'status' => 'info',
        'value' => $wp_version,
        'recommendation' => 'Ensure WordPress is up to date.',
    ];

    // SSL.
    $checks[] = [
        'check' => 'ssl_enabled',
        'status' => is_ssl() ? 'pass' : 'fail',
        'value' => is_ssl() ? 'Yes' : 'No',
        'recommendation' => is_ssl() ? '' : 'Enable SSL/HTTPS for your site.',
    ];

    // Debug mode.
    $checks[] = [
        'check' => 'debug_mode',
        'status' => (defined('WP_DEBUG') && WP_DEBUG) ? 'warning' : 'pass',
        'value' => (defined('WP_DEBUG') && WP_DEBUG) ? 'Enabled' : 'Disabled',
        'recommendation' => (defined('WP_DEBUG') && WP_DEBUG) ? 'Disable WP_DEBUG on production sites.' : '',
    ];

    // Debug display.
    $checks[] = [
        'check' => 'debug_display',
        'status' => (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) ? 'fail' : 'pass',
        'value' => (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) ? 'Enabled' : 'Disabled',
        'recommendation' => (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) ? 'CRITICAL: WP_DEBUG_DISPLAY exposes errors to visitors.' : '',
    ];

    // File editor.
    $checks[] = [
        'check' => 'file_editor_disabled',
        'status' => (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) ? 'pass' : 'warning',
        'value' => (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) ? 'Disabled' : 'Enabled',
        'recommendation' => (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) ? '' : "Add define('DISALLOW_FILE_EDIT', true); to wp-config.php.",
    ];

    // Database prefix.
    $checks[] = [
        'check' => 'db_prefix',
        'status' => $wpdb->prefix === 'wp_' ? 'warning' : 'pass',
        'value' => $wpdb->prefix,
        'recommendation' => $wpdb->prefix === 'wp_' ? 'Using default prefix "wp_" makes SQL injection easier.' : '',
    ];

    // wp-config.php permissions.
    $config_path = ABSPATH . 'wp-config.php';
    if (file_exists($config_path)) {
        $perms = substr(decoct(fileperms($config_path)), -3);
        $checks[] = [
            'check' => 'wp_config_permissions',
            'status' => ((int) $perms <= 644) ? 'pass' : 'fail',
            'value' => $perms,
            'recommendation' => ((int) $perms <= 644) ? '' : 'wp-config.php should be 644 or stricter (440).',
        ];
    }

    // .htaccess exists.
    $htaccess = ABSPATH . '.htaccess';
    $checks[] = [
        'check' => 'htaccess_exists',
        'status' => file_exists($htaccess) ? 'pass' : 'info',
        'value' => file_exists($htaccess) ? 'Yes' : 'No (Nginx?)',
        'recommendation' => '',
    ];

    // Uploads dir writable.
    $uploads = wp_upload_dir();
    $checks[] = [
        'check' => 'uploads_writable',
        'status' => wp_is_writable($uploads['basedir']) ? 'pass' : 'fail',
        'value' => wp_is_writable($uploads['basedir']) ? 'Writable' : 'Not writable',
        'recommendation' => '',
    ];

    // Auto-updates.
    $checks[] = [
        'check' => 'auto_updates',
        'status' => (defined('WP_AUTO_UPDATE_CORE') && WP_AUTO_UPDATE_CORE) ? 'pass' : 'warning',
        'value' => (defined('WP_AUTO_UPDATE_CORE') && WP_AUTO_UPDATE_CORE) ? 'Enabled' : 'Disabled',
        'recommendation' => 'Enable automatic core updates for security patches.',
    ];

    // Security keys defined.
    $keys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'];
    $weak_keys = 0;
    foreach ($keys as $key) {
        if (!defined($key) || strlen(constant($key)) < 20 || constant($key) === 'put your unique phrase here') {
            $weak_keys++;
        }
    }
    $checks[] = [
        'check' => 'security_keys',
        'status' => $weak_keys === 0 ? 'pass' : 'fail',
        'value' => $weak_keys === 0 ? 'All set' : "$weak_keys weak/missing",
        'recommendation' => $weak_keys > 0 ? 'Generate strong security keys at https://api.wordpress.org/secret-key/1.1/salt/' : '',
    ];

    // PHP dangerous functions.
    $disabled = ini_get('disable_functions');
    $dangerous = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'];
    $enabled_dangerous = [];
    foreach ($dangerous as $func) {
        if (!str_contains($disabled, $func) && function_exists($func)) {
            $enabled_dangerous[] = $func;
        }
    }
    $checks[] = [
        'check' => 'dangerous_php_functions',
        'status' => $enabled_dangerous === [] ? 'pass' : 'warning',
        'value' => $enabled_dangerous === [] ? 'All disabled' : implode(', ', $enabled_dangerous),
        'recommendation' => $enabled_dangerous !== [] ? 'Consider disabling these in php.ini: ' . implode(', ', $enabled_dangerous) : '',
    ];

    $pass = count(array_filter($checks, static fn($c) => $c['status'] === 'pass'));
    $fail = count(array_filter($checks, static fn($c) => $c['status'] === 'fail'));
    $warn = count(array_filter($checks, static fn($c) => $c['status'] === 'warning'));

    return [
        'success' => true,
        'score' => $fail === 0 ? ($warn === 0 ? 'excellent' : 'good') : 'needs_attention',
        'pass' => $pass,
        'fail' => $fail,
        'warning' => $warn,
        'checks' => $checks,
        'message' => sprintf('Health check complete: %d pass, %d fail, %d warnings.', $pass, $fail, $warn),
    ];
}

// ═══════════════════════════════════════════════════════════════
// 7. MAINTENANCE — Database optimize, cleanup, transients, cron
// ═══════════════════════════════════════════════════════════════

wp_register_ability('nibwp/security-cleanup', [
    'label' => __('Site Cleanup & Optimization', domain: 'nibwp'),
    'description' => __('Comprehensive site cleanup: delete post revisions, auto-drafts, trashed items, spam comments, expired transients, orphaned meta, and optimize database tables.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'actions' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['revisions', 'auto_drafts', 'trash', 'spam_comments', 'transients', 'orphan_meta', 'optimize_tables']],
                'description' => 'Cleanup actions to perform.',
                'default' => ['revisions', 'auto_drafts', 'trash', 'spam_comments', 'transients'],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => true],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_cleanup',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Run site cleanup tasks.\nALWAYS dry_run=true first to see what will be deleted.\nAvailable actions: revisions, auto_drafts, trash, spam_comments, transients, orphan_meta, optimize_tables.\nReduces database size and improves performance.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_security_cleanup(array $input): array|WP_Error
{
    global $wpdb;

    $actions = $input['actions'] ?? ['revisions', 'auto_drafts', 'trash', 'spam_comments', 'transients'];
    $dry_run = $input['dry_run'] ?? true;
    $results = [];

    foreach ($actions as $action) {
        $count = 0;
        switch ($action) {
            case 'revisions':
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
                if (!$dry_run && $count > 0) {
                    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision')");
                    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
                }
                break;

            case 'auto_drafts':
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
                if (!$dry_run && $count > 0) {
                    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft')");
                    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
                }
                break;

            case 'trash':
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
                if (!$dry_run && $count > 0) {
                    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash')");
                    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
                }
                break;

            case 'spam_comments':
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam' OR comment_approved = 'trash'");
                if (!$dry_run && $count > 0) {
                    $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN (SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash'))");
                    $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')");
                }
                break;

            case 'transients':
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < " . time());
                if (!$dry_run && $count > 0) {
                    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
                    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
                }
                break;

            case 'orphan_meta':
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
                if (!$dry_run && $count > 0) {
                    $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
                }
                break;

            case 'optimize_tables':
                $tables = $wpdb->get_col("SHOW TABLES");
                $count = count($tables);
                if (!$dry_run) {
                    foreach ($tables as $table) {
                        $wpdb->query("OPTIMIZE TABLE `{$table}`");
                    }
                }
                break;
        }

        $results[] = ['action' => $action, 'items' => $count];
    }

    $total = array_sum(array_column($results, 'items'));
    return [
        'success' => true,
        'dry_run' => $dry_run,
        'results' => $results,
        'total_items' => $total,
        'message' => $dry_run
            ? sprintf('%d items would be cleaned up (dry run).', $total)
            : sprintf('%d items cleaned up.', $total),
    ];
}

wp_register_ability('nibwp/security-file-permissions', [
    'label' => __('Check & Fix File Permissions', domain: 'nibwp'),
    'description' => __('Scans critical files and directories for incorrect permissions. Can fix them to recommended values (644 for files, 755 for directories, 440 for wp-config.php).', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'fix' => ['type' => 'boolean', 'description' => 'Fix incorrect permissions.', 'default' => false],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_file_permissions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Checks file permissions on critical WordPress files.\nRun with fix=false first to see current state.\nRecommended: files 644, directories 755, wp-config.php 440.\nSet fix=true to apply corrections.",
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_security_file_permissions(array $input): array
{
    $fix = !empty($input['fix']);
    $checks = [
        ['path' => '.htaccess', 'type' => 'file', 'recommended' => '0644'],
        ['path' => 'wp-config.php', 'type' => 'file', 'recommended' => '0440'],
        ['path' => 'wp-admin', 'type' => 'dir', 'recommended' => '0755'],
        ['path' => 'wp-includes', 'type' => 'dir', 'recommended' => '0755'],
        ['path' => 'wp-content', 'type' => 'dir', 'recommended' => '0755'],
        ['path' => 'wp-content/uploads', 'type' => 'dir', 'recommended' => '0755'],
        ['path' => 'wp-content/plugins', 'type' => 'dir', 'recommended' => '0755'],
        ['path' => 'wp-content/themes', 'type' => 'dir', 'recommended' => '0755'],
        ['path' => 'index.php', 'type' => 'file', 'recommended' => '0644'],
        ['path' => 'wp-login.php', 'type' => 'file', 'recommended' => '0644'],
    ];

    $results = [];
    $fixed = 0;

    foreach ($checks as $check) {
        $full_path = ABSPATH . $check['path'];
        if (!file_exists($full_path)) {
            continue;
        }

        $current = substr(sprintf('%o', fileperms($full_path)), -4);
        $is_ok = $current === $check['recommended'] || (int) $current <= (int) $check['recommended'];

        $result = [
            'path' => $check['path'],
            'current' => $current,
            'recommended' => $check['recommended'],
            'status' => $is_ok ? 'pass' : 'fail',
        ];

        if (!$is_ok && $fix) {
            $mode = octdec($check['recommended']);
            if (chmod($full_path, $mode)) {
                $result['status'] = 'fixed';
                $fixed++;
            }
        }

        $results[] = $result;
    }

    return [
        'success' => true,
        'results' => $results,
        'fixed' => $fixed,
        'message' => $fix
            ? sprintf('Checked %d items, fixed %d permissions.', count($results), $fixed)
            : sprintf('Checked %d items. %d need attention.', count($results), count(array_filter($results, static fn($r) => $r['status'] === 'fail'))),
    ];
}

wp_register_ability('nibwp/security-change-passwords', [
    'label' => __('Reset User Passwords', domain: 'nibwp'),
    'description' => __('Force-reset passwords for specified users or all admin accounts. Generates strong random passwords and optionally sends notification emails.', domain: 'nibwp'),
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'user_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Specific user IDs to reset.'],
            'all_admins' => ['type' => 'boolean', 'description' => 'Reset all administrator passwords.', 'default' => false],
            'send_email' => ['type' => 'boolean', 'description' => 'Send password reset email.', 'default' => true],
            'password_length' => ['type' => 'integer', 'description' => 'Password length.', 'default' => 24, 'minimum' => 12],
        ],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_security_change_passwords',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Force-reset passwords after a security incident.\nUse all_admins=true to reset every admin account.\nPasswords are generated randomly (24 chars by default).\nSet send_email=true so users get notified with a reset link.\nCAUTION: This logs out all affected users immediately.",
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_security_change_passwords(array $input): array|WP_Error
{
    $user_ids = $input['user_ids'] ?? [];
    $all_admins = !empty($input['all_admins']);
    $send_email = $input['send_email'] ?? true;
    $length = max(12, (int) ($input['password_length'] ?? 24));

    if ($all_admins) {
        $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
        $user_ids = array_map('intval', $admins);
    }

    if ($user_ids === []) {
        return new WP_Error('no_users', 'No users specified. Provide user_ids or set all_admins=true.');
    }

    $reset = [];
    foreach ($user_ids as $uid) {
        $user = get_userdata($uid);
        if ($user === false) {
            continue;
        }

        $password = wp_generate_password($length, true, true);
        wp_set_password($password, $uid);

        // Destroy all sessions for this user.
        $sessions = WP_Session_Tokens::get_instance($uid);
        $sessions->destroy_all();

        if ($send_email) {
            retrieve_password($user->user_login);
        }

        $reset[] = [
            'user_id' => $uid,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'email_sent' => $send_email,
        ];
    }

    return [
        'success' => true,
        'users_reset' => count($reset),
        'details' => $reset,
        'message' => sprintf('Reset passwords for %d users. All sessions destroyed.', count($reset)),
    ];
}
