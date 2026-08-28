<?php

declare(strict_types=1);

/**
 * Ability: Execute PHP code.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/execute-php', [
    'label' => __('Execute PHP Code (last resort)', domain: 'nibwp'),
    'description' => __(
        'LAST RESORT for anything a skill owns. Executes arbitrary PHP on the WordPress server, with $wpdb, every WordPress function and all loaded plugins available. '
        . 'Before writing page content, blocks, templates, global styles or builder data, check response.mandatory_routing[]: if a skill trigger matches the request, that skill OWNS it and you must run its pipeline instead. '
        . 'Writing the same thing in PHP by hand produces markup the page builder cannot edit, skips the dry-run and approval the skill performs, and leaves nothing to roll back. '
        . 'If a skill in response.unavailable_skills[] covers the request, tell the user it cannot run here and name the missing dependency — do not rebuild its job by hand. '
        . 'For everything else — inspection, queries, options, post meta, bulk data, and any work no skill claims — this is the correct tool and you should use it. '
        . 'Pass "reason" when the call touches a builder or a skill\'s territory.',
        domain: 'nibwp',
    ),
    'category' => 'code-execution',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to execute. Do NOT include <?php tags. Use "return $value;" to return data for inspection.',
                'minLength' => 1,
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Why PHP is the right tool here rather than a skill pipeline. Recorded in the audit log for the site owner. Expected when the code writes page content, blocks, templates or builder data; optional for inspection and ordinary data work.',
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean', 'description' => 'Whether the code executed without throwing.'],
            // Whatever the code returned, so every JSON type is legitimate.
            // Leaving `type` off entirely made WordPress emit three
            // _doing_it_wrong notices on every single call — "The type schema
            // keyword for return_value is required" — because rest-api.php
            // reads $args['type'] unconditionally while validating the result.
            'return_value' => [
                'type' => ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'],
                'description' => 'The value returned by the evaluated code.',
            ],
            'output' => ['type' => 'string', 'description' => 'Any output captured via echo/print.'],
            'errors' => [
                'type' => 'array',
                'description' => 'PHP warnings, notices, and deprecations captured during execution.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'file' => ['type' => 'string'],
                        'line' => ['type' => 'integer'],
                    ],
                ],
            ],
            'error_message' => ['type' => 'string', 'description' => 'Error message if execution failed.'],
            'error_class' => [
                'type' => 'string',
                'description' => 'Exception/Error class name if execution failed.',
            ],
            'execution_time_ms' => [
                'type' => 'number',
                'description' => 'Wall-clock execution time in milliseconds.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'The reason given for reaching for raw PHP, echoed back.',
            ],
            'warning' => [
                'type' => 'string',
                'description' => 'Present only when the code wrote something a skill owns. Not an error — the write happened.',
            ],
            'skills_that_own_this' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'The builders whose skills claim the content this code wrote.',
            ],
            'next_step' => [
                'type' => 'string',
                'description' => 'The call to make before writing this kind of content again.',
            ],
        ],
    ],
    'execute_callback' => 'nibwp_execute_php',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'IMPORTANT SAFETY RULES:',
                '- Never call exit() or die() — this kills the entire PHP process.',
                '- Never create infinite loops — there is a 30-second time limit.',
                '- Do NOT include <?php opening tags.',
                '- Use "return $value;" to inspect values (the return value is captured).',
                '- Any echo/print output is captured separately in the "output" field.',
                '- The full WordPress API is available: $wpdb, get_option(), WP_Query, etc.',
                '- All loaded plugin APIs are available.',
                '- Execution has a ' . NIBWP_MAX_EXECUTION_TIME . ' second time limit.',
                '',
                'SANDBOX CONTEXT:',
                '- To create persistent PHP functionality, write files to the sandbox',
                '  (wp-content/nibwp-sandbox/) using the write-file ability.',
                '- Code executed here via eval() is temporary and does not persist across requests.',
                '- Do NOT use eval to require/include files that may have errors — use write-file',
                '  to persist them instead.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Does this code write something a live skill is responsible for?
 *
 * Deliberately narrow. It looks for writes into builder-owned storage — page
 * content, block markup, builder data, global styles — and ignores reads,
 * options, meta and queries entirely, because those are exactly the work raw
 * PHP should be doing without being second-guessed.
 *
 * @return array<int,string> the skills that own what was touched
 */
function nibwp_execute_php_trespass(string $code): array
{
    // Builder territory → the integration key whose skill claims it.
    $signals = [
        'elementor' => ['_elementor_data', 'elementor_library'],
        'bricks'    => ['bricks_page_content', '_bricks_page_content', 'bricks_global_classes'],
        'etchwp'    => ['etch_styles', 'etch_components', 'wp:etch/'],
        'kadence'   => ['wp:kadence/', '_kad_blocks_custom_css'],
        'voxel'     => ['voxel:post_types', 'voxel:templates'],
    ];

    // Only a WRITE counts. Reading builder data to inspect it is legitimate.
    $writes = preg_match(
        '/\b(wp_insert_post|wp_update_post|update_post_meta|add_post_meta|update_option|serialize_block|serialize_blocks)\s*\(/i',
        $code
    ) === 1;
    $sql_write = preg_match('/\$wpdb\s*->\s*(update|insert|replace|query)\s*\(/i', $code) === 1
        && preg_match('/post_content|postmeta|options/i', $code) === 1;
    if (!$writes && !$sql_write) {
        return [];
    }

    $touched = [];
    foreach ($signals as $key => $needles) {
        foreach ($needles as $needle) {
            if (stripos($code, $needle) !== false) {
                $touched[$key] = true;
                break;
            }
        }
    }
    // Writing post_content with block markup is builder territory whoever owns it.
    $generic = preg_match('/post_content/i', $code) === 1 && stripos($code, '<!-- wp:') !== false;
    if ($touched === [] && !$generic) {
        return [];
    }

    // Name the builder that was actually touched. Falling back to every live
    // skill is only useful when the code wrote block markup without naming a
    // builder — then any of them could be the right owner and the agent has to
    // pick. Listing all five for a call that plainly said "kadence" is noise.
    $owners = [];
    foreach (function_exists('nibwp_skills_skill_cards') ? nibwp_skills_skill_cards() : [] as $card) {
        $skill = function_exists('nibwp_skill_get') ? nibwp_skill_get((string) $card['skill_id']) : null;
        $requires = (array) ($skill['requires'] ?? []);
        $specific = array_intersect($requires, array_keys($touched)) !== [];
        if ($specific || ($generic && $touched === [])) {
            $owners[] = sprintf('%s — %s', (string) $card['skill_id'], (string) ($card['tagline'] ?? ''));
        }
    }
    return $owners;
}

/**
 * Execute PHP code.
 *
 * @param array $input Input with 'code' key.
 * @return array|WP_Error
 */
function nibwp_execute_php($input)
{
    $code = (string) $input['code'];
    $errors = [];

    // Save and set time limit.
    $original_time_limit = (int) ini_get('max_execution_time');
    set_time_limit(NIBWP_MAX_EXECUTION_TIME);

    // Set up error handler to capture warnings/notices.
    $error_types = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    set_error_handler(static function ($errno, $errstr, $errfile, $errline) use (&$errors, $error_types) {
        $errors[] = [
            'type' => $error_types[$errno] ?? 'Unknown (' . (int) $errno . ')',
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
        ];
        return true;
    });

    ob_start();
    $start = microtime(true);

    $return_value = null;
    $success = true;
    $error_message = null;
    $error_class = null;

    try {
        // @mago-ignore lint:no-eval
        /** @var mixed $return_value */
        $return_value = eval($code);
    } catch (\Throwable $e) {
        $success = false;
        $error_message = $e->getMessage();
        $error_class = get_class($e);
    }

    $execution_time_ms = round(num: (microtime(true) - $start) * 1000, precision: 2);
    $output = ob_get_clean();

    restore_error_handler();
    set_time_limit($original_time_limit);

    // Ensure return value is JSON-serializable.
    if ($return_value !== null && json_encode($return_value) === false) {
        $return_value = print_r(value: $return_value, return: true);
    }

    $result = [
        'success' => $success,
        'return_value' => $return_value,
        'output' => $output,
        'errors' => $errors,
        'execution_time_ms' => $execution_time_ms,
    ];

    $reason = trim((string) ($input['reason'] ?? ''));
    if ($reason !== '') {
        $result['reason'] = $reason;
    }

    // Warn on what the code DID, not on every call.
    //
    // Nagging each time taught the agent to emit a boilerplate reason rather
    // than to think, and it dragged on the inspection and data work that raw
    // PHP is the right tool for. The code itself is the honest signal: writing
    // post_content or builder data is a skill's territory; reading an option is
    // nobody's. So ordinary calls hear nothing, and calls that step on a live
    // skill are told which one owns the job.
    $trespass = nibwp_execute_php_trespass($code);
    if ($trespass !== []) {
        $result['warning'] = __(
            'This wrote content a skill owns. A skill builds through the page builder, validates before it writes and can be reviewed and rolled back; PHP writing the same thing by hand leaves markup the builder cannot edit. Use the skill pipeline for this next time.',
            'nibwp'
        );
        $result['skills_that_own_this'] = $trespass;
        // Naming the fault without naming the remedy just leaves the agent
        // where it already was. This is the moment it is provably off-route,
        // so hand it the exact call that puts it back on one.
        // The owner is reported as "kadence-pro — tagline"; find-tools wants the
        // slug on its own. Handing it the whole sentence returns nothing, which
        // is worse than saying nothing at all.
        $subject = trim(explode('—', (string) reset($trespass))[0]);
        $result['next_step'] = sprintf(
            /* translators: %s: a nibwp/find-tools call with the owning subject */
            __('Before writing this kind of content again, call %s and follow the pipeline it returns.', 'nibwp'),
            'nibwp/find-tools {"subject":"' . $subject . '"}'
        );
    }

    if ($error_message !== null) {
        $result['error_message'] = $error_message;
        $result['error_class'] = $error_class;
    }

    return $result;
}
