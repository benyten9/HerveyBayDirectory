<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/tree.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/commit.php';

/**
 * Builderius Pro — conversion, with nothing written.
 *
 * The whole point of a separate convert step is that a Builderius config can be
 * read before it is committed. Once committed it is a version in a history, and
 * while that history is the reason Builderius is pleasant to work in, it is not a
 * place to leave five attempts at the same hero.
 */

wp_register_ability('nibwp/builderius-pro-convert', [
    'label'       => __('Builderius Pro — Convert to a config', 'nibwp'),
    'description' => 'Turn HTML, a URL, a described screenshot or a plain brief into a validated Builderius config — the flat module map, the authoring tree it came from, and every error, warning and recommendation. Writes nothing (read-only).',
    'category'    => 'builderius-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'html'         => ['type' => 'string', 'description' => 'A fragment of HTML to convert. Not a whole document.'],
            'url'          => ['type' => 'string', 'description' => 'A page to fetch and convert instead.'],
            'selector_hint' => ['type' => 'string', 'description' => 'With url: the part of the page wanted, in words ("the hero", "the footer"). Reported back so the caller can narrow the HTML itself — nothing is guessed from it.'],
            'section_type' => [
                'type' => 'string',
                'enum' => ['hero', 'navbar', 'footer', 'cta', 'pricing', 'card-grid', 'testimonial', 'form', 'section', 'generic'],
                'description' => 'For a screenshot or a brief with no markup: return a skeleton of real modules for this kind of section, to be filled in.',
            ],
            'brief'        => ['type' => 'string', 'description' => 'What the section should contain, in words. Used with section_type.'],
            'tree'         => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Or validate a tree you authored yourself, without converting anything.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_builderius_pro_convert_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run this before nibwp/builderius-pro-build, every time. Errors here are errors that would otherwise be a committed version nobody wants.',
                'An error is a stop. A module name or a setting name that does not exist on this install is not a style question; Builderius drops what it does not understand and renders the rest, so the symptom is an empty section rather than a failure.',
                'A screenshot has no markup. Pass section_type and brief, take the skeleton, and fill it in — do not invent module names to fit what the picture looks like.',
                'The config this returns is what to hand to build. Do not re-author it between the two calls.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_builderius_pro_convert_ability(array $input)
{
    $gate = nibwp_builderius_pro_gate(false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $source = '';
    $warnings = [];
    $recommendations = [];
    $tree = [];

    if (!empty($input['tree']) && is_array($input['tree'])) {
        $source = 'tree';
        $tree = array_values($input['tree']);
    } elseif (!empty($input['html']) && is_string($input['html'])) {
        $source = 'html';
        $converted = nibwp_builderius_pro_html_to_tree((string) $input['html']);
        $tree = $converted['tree'];
        $warnings = $converted['warnings'];
        $recommendations = $converted['recommendations'];
    } elseif (!empty($input['url']) && is_string($input['url'])) {
        $source = 'url';
        $fetched = nibwp_builderius_pro_fetch((string) $input['url']);
        if ($fetched instanceof WP_Error) {
            return $fetched;
        }
        $converted = nibwp_builderius_pro_html_to_tree($fetched);
        $tree = $converted['tree'];
        $warnings = $converted['warnings'];
        $recommendations = $converted['recommendations'];
        if (!empty($input['selector_hint'])) {
            $recommendations[] = [
                'rule'    => 'selector_hint',
                'message' => sprintf(
                    /* translators: %s: the hint the caller gave */
                    __('The whole page was converted. "%s" was not used to narrow it — pass the markup of just that part as html for a tree that is not the entire page.', 'nibwp'),
                    (string) $input['selector_hint']
                ),
            ];
        }
    } elseif (!empty($input['section_type']) || !empty($input['brief'])) {
        $source = 'skeleton';
        $tree = nibwp_builderius_pro_skeleton((string) ($input['section_type'] ?? 'section'));
        $recommendations[] = [
            'rule'    => 'skeleton_needs_filling',
            'message' => __('This is a skeleton of real modules, not the finished section. Replace the placeholder copy, add the classes this site uses, and read the matching checklist in the skill\'s references before committing it.', 'nibwp'),
        ];
    } else {
        return new WP_Error(
            'nibwp_builderius_pro_no_input',
            __('Give html, a url, a tree, or a section_type with a brief.', 'nibwp')
        );
    }

    $report = nibwp_builderius_pro_validate_tree($tree);
    $report['warnings'] = array_merge($warnings, $report['warnings']);
    $report['recommendations'] = array_merge($recommendations, $report['recommendations']);

    $config = null;
    if ($report['passed']) {
        $built = nibwp_builderius_pro_tree_to_config($tree);
        if ($built instanceof WP_Error) {
            return $built;
        }
        $config = $built;
    }

    return [
        'ok'              => $report['passed'],
        'written'         => false,
        'source'          => $source,
        'tree'            => $tree,
        'config'          => $config,
        'module_count'    => $report['module_count'],
        'modules_used'    => $report['modules_used'],
        'dynamic_tokens'  => $report['tokens'],
        'errors'          => $report['errors'],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'next_step'       => $report['passed']
            ? __('Hand this config to nibwp/builderius-pro-build with dry_run true, read the version plan, then write it.', 'nibwp')
            : __('Fix every error, then convert again. Nothing was written.', 'nibwp'),
    ];
}

/**
 * Fetch a page's markup.
 *
 * @return string|WP_Error
 */
function nibwp_builderius_pro_fetch(string $url)
{
    if (!function_exists('wp_remote_get') || !function_exists('wp_http_validate_url')) {
        return new WP_Error(
            'nibwp_builderius_pro_no_http',
            __('The HTTP API is not available here. Paste the markup as html instead.', 'nibwp')
        );
    }
    if (wp_http_validate_url($url) === false) {
        return new WP_Error(
            'nibwp_builderius_pro_bad_url',
            __('That is not a URL WordPress will fetch.', 'nibwp')
        );
    }

    $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 3]);
    if ($response instanceof WP_Error) {
        return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'nibwp_builderius_pro_fetch_failed',
            sprintf(
                /* translators: 1: URL, 2: HTTP status code */
                __('%1$s answered %2$d.', 'nibwp'),
                $url,
                $code
            )
        );
    }
    $body = (string) wp_remote_retrieve_body($response);
    if (trim($body) === '') {
        return new WP_Error(
            'nibwp_builderius_pro_fetch_empty',
            sprintf(
                /* translators: %s: URL */
                __('%s returned nothing to convert — it is probably rendered in the browser. Paste the markup as html instead.', 'nibwp'),
                $url
            )
        );
    }

    return $body;
}
