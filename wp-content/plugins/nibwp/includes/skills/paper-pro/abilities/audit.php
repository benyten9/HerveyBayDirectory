<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/tokens.php';
require_once __DIR__ . '/../lib/copy.php';

/**
 * Paper Pro — reading the page back.
 *
 * A write that returned success is not a page that matches the design, and on this
 * path that gap is wider than usual. The Figma skill can settle an argument by
 * exporting the frame again and diffing the pixels. Nothing here can: there is no
 * cloud API for Paper files, so the only record of what the designer drew is the
 * document that was handed over, and the only honest comparison is between the page
 * and that document.
 *
 * Which is still worth a great deal, because the drift that actually happens is not
 * subtle. Copy that never made it onto the page. Placeholder words that did. A
 * section the builder dropped because the emitter had nothing for that node type. A
 * hex code sitting where a token should be. All of those are visible in a comparison
 * between the stored design and what the page reports, and all of them are things a
 * client notices before anyone else does.
 *
 * What this ability will not do is imply more than it checked. When the integration's
 * audit cannot report the page's own text, the answer says the comparison could not be
 * made — rather than returning no findings and letting that read as a clean bill.
 */

wp_register_ability('nibwp/paper-pro-audit', [
    'label'       => __('Paper Pro — Audit a converted page', 'nibwp'),
    'description' => 'Read a page converted from a Paper design back and report where it drifted from the design it came from: copy that did not arrive, placeholder words that did, parts of the tree with nothing on the page, and literal values that should be tokens — together with the Paper integration\'s own audit findings. Read-only.',
    'category'    => 'paper-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'   => ['type' => 'integer', 'description' => 'The converted page to read back.'],
            'design_id' => ['type' => 'integer', 'description' => 'The stored design to compare against. Resolved from post_id when omitted.'],
            'design'    => ['type' => 'object', 'description' => 'Or compare against a design you are holding — the NDO document you handed over, when nothing was stored.'],
            'site_tokens' => ['type' => 'object', 'description' => 'What list_tokens returned, so literals on the page that have a token can be named.'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_paper_pro_audit_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run this after every conversion, before telling the user it is done.',
                'Read the findings out. "Converted successfully" plus a page missing a third of its copy is a worse answer than a list of what is missing.',
                'The paths in the findings are the ones nibwp/paper-pro-refine takes, so a finding can be fixed without rebuilding the page.',
                'This compares the page to the design that was handed over, never to the Paper file — nothing here can open that. Say so if the user asks whether it matches the design.',
                'A comparison that could not be made is reported as not made. Do not read an empty findings list as a clean page unless compared is true.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_audit_ability(array $input)
{
    $gate = nibwp_paper_pro_gate(false, false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            __('Give the post_id of the page to read back.', 'nibwp')
        );
    }

    $audit = nibwp_paper_pro_delegate('audit', [
        'post_id'   => $post_id,
        'design_id' => (int) ($input['design_id'] ?? 0),
    ]);
    if ($audit instanceof WP_Error) {
        return $audit;
    }

    $design = [];
    $design_source = 'none';
    if (isset($input['design']) && is_array($input['design'])) {
        $design = $input['design'];
        $design_source = 'supplied';
    } elseif (is_array($audit['design'] ?? null)) {
        $design = (array) $audit['design'];
        $design_source = 'stored';
    } else {
        $stored = nibwp_paper_pro_delegate('get_design', [
            'post_id'   => $post_id,
            'design_id' => (int) ($input['design_id'] ?? 0),
        ]);
        if (!$stored instanceof WP_Error && is_array($stored['design'] ?? null)) {
            $design = (array) $stored['design'];
            $design_source = 'stored';
        }
    }

    $notes = [];
    $report = null;
    if ($design !== []) {
        $report = nibwp_paper_pro_validate_design($design);
        $refusal = nibwp_paper_pro_design_error($report);
        if ($refusal instanceof WP_Error) {
            // A stored design that no longer validates is itself the finding, and a
            // comparison against it would be meaningless.
            return $refusal;
        }
    } else {
        $notes[] = __('No design was available to compare against — none supplied, and none stored for this page. The integration\'s own findings below still apply; the drift comparison was not made.', 'nibwp');
    }

    $page_strings = nibwp_paper_pro_page_strings($audit);
    $findings = [];
    $compared = false;

    if ($report !== null && $page_strings !== null) {
        $compared = true;
        $findings = nibwp_paper_pro_drift($report, $page_strings, $audit, (array) ($input['site_tokens'] ?? []), $design);
    } elseif ($report !== null) {
        $notes[] = __('The integration\'s audit did not report the page\'s own text, so copy drift could not be computed. An empty findings list here does not mean the page matches the design.', 'nibwp');
    }

    $severity = ['high' => 0, 'medium' => 0, 'low' => 0];
    foreach ($findings as $finding) {
        $level = (string) ($finding['severity'] ?? 'low');
        $severity[$level] = ($severity[$level] ?? 0) + 1;
    }

    return [
        'ok'       => true,
        'written'  => false,
        'post_id'  => $post_id,
        'design_source' => $design_source,
        'compared' => $compared,
        'findings' => $findings,
        'severity' => $severity,
        'design_counts' => $report === null ? [] : $report['counts'],
        'integration_audit' => $audit,
        'notes'    => $notes,
        'caveat'   => __('This compares the page to the design that was handed over. It cannot compare either of them to your Paper file — there is no cloud API for Paper files and nothing on this side can open one.', 'nibwp'),
        'delegated_to' => nibwp_paper_pro_delegations(),
        'next_step' => $findings === []
            ? ($compared
                ? __('No drift found between the page and the design. Still worth looking at the page — a comparison of strings and counts is not a comparison of appearances.', 'nibwp')
                : __('No comparison was made, so there is nothing to conclude from an empty list. Supply the design, or convert again so one is stored.', 'nibwp'))
            : __('Fix the findings with nibwp/paper-pro-refine — the paths above are the parts it takes. Rebuilding the page would discard anything edited by hand since the conversion.', 'nibwp'),
    ];
}

/**
 * The page's own text, however the integration reported it, or null when it did not.
 *
 * Null and [] are different answers: the first means the comparison cannot be made,
 * the second means the page has no text on it, which is itself a finding.
 *
 * @param array<string,mixed> $audit
 * @return array<int,string>|null
 */
function nibwp_paper_pro_page_strings(array $audit): ?array
{
    $candidates = [
        $audit['text_strings'] ?? null,
        $audit['strings'] ?? null,
        ($audit['page'] ?? [])['text_strings'] ?? null,
        ($audit['page'] ?? [])['strings'] ?? null,
        ($audit['page'] ?? [])['text'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $out = [];
        foreach ($candidate as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
                continue;
            }
            if (is_array($entry) && isset($entry['text']) && is_string($entry['text'])) {
                $out[] = $entry['text'];
            }
        }

        return $out;
    }

    return null;
}

/**
 * Compare the design to what the page reports.
 *
 * @param array<string,mixed> $report      the validated design report
 * @param array<int,string>   $page_strings
 * @param array<string,mixed> $audit
 * @param array<mixed>        $site_tokens
 * @param array<string,mixed> $design
 * @return array<int,array<string,mixed>>
 */
function nibwp_paper_pro_drift(array $report, array $page_strings, array $audit, array $site_tokens, array $design): array
{
    $findings = [];

    $normalised_page = [];
    foreach ($page_strings as $string) {
        $key = nibwp_paper_pro_compare_key($string);
        if ($key !== '') {
            $normalised_page[$key] = true;
        }
    }

    // Copy in the design that is nowhere on the page. The highest-value finding there
    // is: it is the one a client reports as "you missed a bit".
    $missing = [];
    foreach ((array) $report['text_nodes'] as $node) {
        $text = (string) ($node['text'] ?? '');
        $key = nibwp_paper_pro_compare_key($text);
        if ($key === '' || isset($normalised_page[$key])) {
            continue;
        }
        $missing[] = [
            'path' => (string) ($node['path'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'text' => mb_strlen($text) > 80 ? mb_substr($text, 0, 77) . '…' : $text,
        ];
    }
    if ($missing !== []) {
        $findings[] = [
            'finding'  => 'copy_missing_from_the_page',
            'severity' => 'high',
            'count'    => count($missing),
            'items'    => array_slice($missing, 0, 25),
            'message'  => sprintf(
                /* translators: %d: how many strings are missing */
                __('%d string(s) from the design are not on the page. Either the emitter had nothing for the node that carried them, or the part was dropped — the paths say which parts to look at.', 'nibwp'),
                count($missing)
            ),
        ];
    }

    // Placeholder words that survived the conversion. Checked against the page's own
    // text, not the design's: a stand-in in the design is a plan, a stand-in on the
    // page is published.
    $page_nodes = [];
    foreach ($page_strings as $string) {
        $page_nodes[] = ['path' => '', 'name' => '', 'text' => $string, 'tag' => ''];
    }
    $copy = nibwp_paper_pro_classify_copy($page_nodes);
    if ($copy['placeholder'] !== []) {
        $findings[] = [
            'finding'  => 'placeholder_copy_on_the_page',
            'severity' => 'high',
            'count'    => count($copy['placeholder']),
            'items'    => array_slice($copy['placeholder'], 0, 25),
            'message'  => sprintf(
                /* translators: %d: how many strings look like stand-ins */
                __('%d string(s) on the live page look like stand-ins rather than copy. In the design that was a note to self; here it is published.', 'nibwp'),
                count($copy['placeholder'])
            ),
        ];
    }

    // Node counts. A page emitting a quarter of the design's nodes is not a styling
    // problem.
    $page_nodes_count = (int) ($audit['node_count'] ?? ($audit['page'] ?? [])['node_count'] ?? 0);
    $design_nodes = (int) ($report['counts']['nodes'] ?? 0);
    if ($page_nodes_count > 0 && $design_nodes > 0) {
        $ratio = $page_nodes_count / $design_nodes;
        if ($ratio < 0.5) {
            $findings[] = [
                'finding'  => 'page_is_much_smaller_than_the_design',
                'severity' => 'high',
                'count'    => $design_nodes - $page_nodes_count,
                'items'    => [['design_nodes' => $design_nodes, 'page_nodes' => $page_nodes_count]],
                'message'  => sprintf(
                    /* translators: 1: nodes on the page, 2: nodes in the design */
                    __('The page has %1$d elements against the design\'s %2$d nodes. Some flattening is normal — half of them going missing is not.', 'nibwp'),
                    $page_nodes_count,
                    $design_nodes
                ),
            ];
        }
    }

    // Literals on the page that the site has a token for. Only reported when the
    // integration told us what the page emitted; guessing would be worse than silence.
    $tokens = nibwp_paper_pro_match_tokens($design, $site_tokens);
    $page_literals = $audit['literals'] ?? ($audit['page'] ?? [])['literals'] ?? null;
    if (is_array($page_literals) && $tokens['matched'] !== []) {
        $should_be_tokens = [];
        foreach ($tokens['matched'] as $match) {
            foreach ($page_literals as $literal) {
                $value = is_array($literal) ? (string) ($literal['value'] ?? '') : (string) $literal;
                if (strcasecmp(trim($value), trim((string) $match['value'])) === 0) {
                    $should_be_tokens[] = [
                        'value'    => (string) $match['value'],
                        'property' => (string) $match['property'],
                        'token'    => (string) ($match['token'] ?? ''),
                        'use'      => (string) ($match['use_instead'] ?? ''),
                    ];
                    break;
                }
            }
        }
        if ($should_be_tokens !== []) {
            $findings[] = [
                'finding'  => 'literal_where_a_token_exists',
                'severity' => 'medium',
                'count'    => count($should_be_tokens),
                'items'    => array_slice($should_be_tokens, 0, 25),
                'message'  => __('The page emitted literal values that this site already has tokens for. It looks right today and stops looking right the day the palette changes.', 'nibwp'),
            ];
        }
    }

    // Anything else the design itself warned about, carried forward rather than
    // quietly dropped once the page exists.
    $design_warnings = array_merge((array) ($design['warnings'] ?? []), (array) $report['warnings']);
    if ($design_warnings !== []) {
        $findings[] = [
            'finding'  => 'warnings_from_the_handover',
            'severity' => 'low',
            'count'    => count($design_warnings),
            'items'    => array_slice($design_warnings, 0, 25),
            'message'  => __('The handover came with warnings. They are still true of the page that was built from it.', 'nibwp'),
        ];
    }

    return $findings;
}

/**
 * A comparable form of a string: case folded, whitespace collapsed, and the
 * punctuation a builder is entitled to change stripped. Comparing raw strings reports
 * a curly apostrophe as missing copy, and an audit that cries wolf is one nobody reads.
 */
function nibwp_paper_pro_compare_key(string $text): string
{
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    $text = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}"], ["'", "'", '"', '"', '-', '-', ' '], $text);
    $text = (string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $text);

    return trim(mb_strtolower($text));
}
