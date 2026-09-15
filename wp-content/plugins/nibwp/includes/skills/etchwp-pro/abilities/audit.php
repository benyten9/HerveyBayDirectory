<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — find Etch content that renders unstyled.
 *
 * The validator can only refuse what passes through it. A payload an agent
 * hands to a person, who pastes it into the Etch builder, never touches this
 * plugin at all — and that is how a customer ended up with a hero of bare
 * semantic tags: correct structure, no class on anything, no CSS behind it.
 *
 * So this looks at what is already on the site and answers the question the
 * page cannot: will Etch actually style this? It reads the same way Etch does
 * — a style reaches the front end only when a block asks for it by id — so it
 * reports the three states that render bare:
 *
 *   - an element with no class and no style reference: nothing can style it,
 *     and nothing can target it later either
 *   - a reference to a style id the site does not have, so there is nothing to
 *     emit for it
 *   - a tree that references no styles at all
 *
 * Read-only. It reports; fixing is the author's call, through the normal
 * validated route.
 */

wp_register_ability('nibwp/etchwp-pro-audit', [
    'label'       => __('EtchWP Pro — Audit rendered styling', 'nibwp'),
    'description' => __('Finds Etch content that renders unstyled: elements with no class, references to styles this site does not have, and trees that reference no styles at all.', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'A single post to audit. Omit to scan every post that contains Etch blocks.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum posts to scan when no post_id is given. Default 50.',
                'default' => 50,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'scanned'  => ['type' => 'integer'],
            'affected' => ['type' => 'integer', 'description' => 'Posts with at least one finding.'],
            'posts'    => ['type' => 'array', 'items' => ['type' => 'object']],
            'summary'  => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_etchwp_pro_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Audit Etch content for styling that will never render.\n"
                . "Etch emits a style only when a block references its id, so a class on its own styles nothing.\n"
                . "Run this when a section was built but looks unstyled, or after content arrived by any route other than\n"
                . "nibwp/etchwp-pro-html-to-component. Read-only: it reports, it does not change anything.\n"
                . "To fix what it finds, rebuild the affected section through nibwp/etchwp-pro-html-to-component,\n"
                . "which validates the same rules before writing.",
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_etchwp_pro_audit(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }

    require_once __DIR__ . '/../lib/validator.php';

    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
    $limit   = max(1, min(500, (int) ($input['limit'] ?? 50)));

    if ($post_id > 0) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return new WP_Error('audit_post_missing', sprintf(__('Post %d does not exist.', 'nibwp'), $post_id));
        }
        $posts = [$post];
    } else {
        $posts = get_posts([
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'numberposts'    => $limit,
            's'              => 'wp:etch/',
            'suppress_filters' => false,
        ]);
    }

    // The site's real stylesheet. A reference that is not a key here has
    // nothing behind it, whatever the payload that wrote it believed.
    $known = array_keys((array) get_option('etch_styles', []));

    $report   = [];
    $scanned  = 0;

    foreach ($posts as $post) {
        $content = (string) $post->post_content;
        if (!str_contains($content, 'wp:etch/')) {
            continue;
        }
        $scanned++;

        $blocks = parse_blocks($content);
        // The walkers take one node or a list; a parsed post is a list.
        $payload = ['gutenbergBlock' => $blocks];

        $findings = [];

        foreach (nibwp_etchwp_validate_element_classes($payload) as $issue) {
            $findings[] = ['id' => $issue['id'], 'detail' => $issue['msg']];
        }

        $referenced = [];
        nibwp_etchwp_walk_block_nodes($blocks, static function (array $node) use (&$referenced): void {
            $attrs = (array) ($node['attrs'] ?? []);
            foreach (array_merge((array) ($attrs['styles'] ?? []), (array) ($attrs['metadata']['etchData']['styles'] ?? [])) as $sid) {
                if (is_string($sid) && $sid !== '') {
                    $referenced[$sid] = true;
                }
            }
        });

        $dangling = array_values(array_diff(array_keys($referenced), $known));
        if ($dangling !== []) {
            $findings[] = [
                'id'     => 'style_reference_dangling',
                'detail' => sprintf(
                    /* translators: %s: comma-separated style ids */
                    __('These style ids are referenced by blocks on this page but do not exist in this site\'s Etch styles, so nothing is emitted for them: %s.', 'nibwp'),
                    implode(', ', array_slice($dangling, 0, 8))
                ),
            ];
        }

        if ($referenced === []) {
            $findings[] = [
                'id'     => 'no_styles_referenced',
                'detail' => __('No block on this page references any style, so Etch emits no CSS for it at all. The page renders with browser defaults.', 'nibwp'),
            ];
        }

        if ($findings !== []) {
            $report[] = [
                'post_id'  => $post->ID,
                'title'    => get_the_title($post),
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
                'findings' => $findings,
            ];
        }
    }

    return [
        'scanned'  => $scanned,
        'affected' => count($report),
        'posts'    => $report,
        'summary'  => $report === []
            ? sprintf(
                /* translators: %d: number of posts */
                _n('Scanned %d post with Etch content; every element is addressable and every style it references exists.', 'Scanned %d posts with Etch content; every element is addressable and every style they reference exists.', $scanned, 'nibwp'),
                $scanned
            )
            : sprintf(
                /* translators: 1: affected count, 2: scanned count */
                __('%1$d of %2$d posts with Etch content have styling that will never render. Rebuild each affected section through nibwp/etchwp-pro-html-to-component, which refuses these before writing.', 'nibwp'),
                count($report),
                $scanned
            ),
    ];
}
