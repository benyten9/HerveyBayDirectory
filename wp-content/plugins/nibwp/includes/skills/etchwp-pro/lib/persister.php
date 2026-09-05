<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — payload persister.
 *
 * Takes a validated agent-built Etch payload and writes it to two places:
 *   1. wp_options['etch_styles']   — the deep-merge of payload styles.
 *   2. wp_posts[$post_id].post_content — the appended / replaced block markup.
 *
 * Idempotent on retries: component_id is a deterministic hash, and existing
 * component sections inside the post are replaced rather than appended.
 */

/**
 * Persist the payload to the target post.
 *
 * Defense-in-depth gate: independently re-runs the validator on entry and
 * refuses to write anything if the payload fails. This means REST shims,
 * WP-CLI callers, and any future direct caller inherit the same quality
 * floor as nibwp/etchwp-pro-html-to-component. There is NO bypass flag.
 *
 * @param array<string,mixed> $payload Validated Etch artifact.
 * @param array{post_id?:int,section_anchor?:string,mode?:string} $target
 * @param array<string,mixed> $ctx Optional validator ctx (brand/acss_active/etc.).
 *                                  When empty, ctx is derived from payload + site.
 * @return array{component_id:string,post_id:int,styles_added:array<int,string>,styles_updated:array<int,string>,blocks_added:int}|WP_Error
 */
function nibwp_etchwp_persist_payload(array $payload, array $target, array $ctx = [])
{
    require_once __DIR__ . '/validator.php';

    // Nothing to write is a failure, and it has to be caught before the
    // first side effect. A payload with no block tree used to fall straight
    // through: the page got created, the styles got merged, no content was
    // written, and a success shape came back with blocks_added: 0. The
    // caller reported a finished build and the customer opened a blank page.
    //
    // A components-only payload is legitimate — it registers reusable
    // definitions and touches no page.
    $tree_in = $payload['gutenbergBlock'] ?? null;
    $components_in = !empty($payload['components']) && is_array($payload['components']);
    if ((!is_array($tree_in) || $tree_in === []) && !$components_in) {
        return new WP_Error(
            'persist_no_blocks',
            'payload.gutenbergBlock is missing or empty: there is nothing to write to the page. Nothing was created or changed.'
        );
    }

    // Re-validate unconditionally before any write. No --force, no skip.
    $derived_ctx = [
        'brand'               => (string) ($payload['__libraryMeta']['brand'] ?? ''),
        'acss_active'         => nibwp_etchwp_acss_active(),
        'has_raw_html_block'  => nibwp_etchwp_payload_has_wp_html($payload),
    ];
    $verdict = nibwp_etchwp_validate_payload($payload, array_merge($derived_ctx, $ctx));
    if (!$verdict['passed']) {
        return new WP_Error(
            'persist_validation_failed',
            'Validator rejected payload at persist gate. Re-validate before retrying.',
            [
                'failed'   => $verdict['failed'],
                'warnings' => $verdict['warnings'],
            ]
        );
    }

    $post_id = (int) ($target['post_id'] ?? 0);
    $mode    = (string) ($target['mode'] ?? 'append');
    if (!in_array($mode, ['append', 'replace_section', 'new_page'], true)) {
        $mode = 'append';
    }

    // B2 fix: new_page branch — create a draft when no post_id is given.
    // Uses the user-confirmed title from the preflight cache. Permission is
    // re-asserted here (defense in depth: the calling ability already
    // permission_callback'd manage_options, but this is the only place we
    // mint a new post so explicitness wins).
    if ($post_id <= 0 && $mode === 'new_page') {
        if (!current_user_can('publish_pages') && !current_user_can('edit_pages') && !current_user_can('manage_options')) {
            return new WP_Error('persist_no_caps', 'User lacks the capability to create pages.');
        }
        $new_title = (string) ($target['new_page_title'] ?? '');
        if ($new_title === '') {
            $new_title = trim((string) ($payload['__libraryMeta']['name'] ?? ''));
        }
        if ($new_title === '') {
            $new_title = 'New EtchWP Page';
        }
        $new_type = (string) ($target['new_page_type'] ?? 'page');
        if (!post_type_exists($new_type)) {
            $new_type = 'page';
        }
        $inserted = wp_insert_post([
            'post_title'   => sanitize_text_field($new_title),
            'post_type'    => $new_type,
            'post_status'  => 'draft',
            'post_content' => '',
        ], true);
        if (is_wp_error($inserted)) {
            return $inserted;
        }
        $post_id = (int) $inserted;
    }

    if ($post_id <= 0) {
        return new WP_Error('persist_no_post', 'target.post_id is required (or use mode=new_page with a new_page_title).');
    }
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('persist_post_missing', sprintf('Post %d does not exist.', $post_id));
    }

    // Compute deterministic component id from meta.
    $meta = (array) ($payload['__libraryMeta'] ?? []);
    $component_id = sha1(implode('|', [
        (string) ($meta['name']     ?? ''),
        (string) ($meta['brand']    ?? ''),
        (string) ($meta['category'] ?? ''),
    ]));

    // 1) Merge styles dict.
    $existing  = (array) get_option('etch_styles', []);
    $incoming  = (array) ($payload['styles'] ?? []);
    $readonly  = ['etch-section-style', 'etch-container-style', 'etch-flex-div-style', 'etch-iframe-style', 'etch-global-variable-style'];

    $added   = [];
    $updated = [];
    foreach ($incoming as $sid => $def) {
        if (in_array($sid, $readonly, true)) {
            continue; // Never mutate readonly scaffold styles.
        }
        if (!is_string($sid) || !is_array($def)) {
            continue;
        }

        // Into Etch's shape before it reaches the option, whichever shape the
        // payload used. Writing it through unconverted is what put warnings on
        // the front end of every page a component landed on.
        $normalized = nibwp_etchwp_normalize_style($sid, $def);
        if ($normalized === null) {
            continue;
        }

        $id    = $normalized['id'];
        $style = $normalized['style'];

        // Clear the entry the old code wrote under the raw selector, or the
        // repair lands beside the broken one instead of replacing it.
        if ($normalized['legacy_key'] !== null
            && $normalized['legacy_key'] !== $id
            && array_key_exists($normalized['legacy_key'], $existing)
        ) {
            unset($existing[$normalized['legacy_key']]);
        }

        if (array_key_exists($id, $existing)) {
            if ($existing[$id] !== $style) {
                // Keep anything Etch itself added to the entry — a collection,
                // a readonly flag — and change only what we own.
                $existing[$id] = array_merge((array) $existing[$id], $style);
                $updated[] = $sid;
            }
        } else {
            $existing[$id] = $style;
            $added[] = $sid;
        }
    }
    update_option('etch_styles', $existing, autoload: false);

    // 2) Serialize the block tree to post_content.
    $block_tree = $payload['gutenbergBlock'] ?? null;
    $blocks_added = 0;
    if (is_array($block_tree) && $block_tree !== []) {
        $markup = nibwp_etchwp_serialize_block($block_tree);

        // Safety net: serialization must never lose blocks. Parse the markup back
        // and compare the block count to the payload tree — if anything was dropped
        // (e.g. a future serialize_block/innerContent regression), fail loud with a
        // WP_Error instead of persisting a truncated page.
        if (function_exists('parse_blocks')) {
            $expected = nibwp_etchwp_count_blocks($block_tree);
            $got      = nibwp_etchwp_count_blocks(parse_blocks($markup));
            if ($got < $expected) {
                return new WP_Error(
                    'serialize_truncated',
                    sprintf(
                        'Block serialization dropped content (%1$d of %2$d blocks survived) — write aborted to avoid a partial page.',
                        $got,
                        $expected,
                    ),
                    ['status' => 500, 'expected' => $expected, 'got' => $got],
                );
            }
        }

        $markup = sprintf(
            "\n<!-- nibwp:etchwp-component id=\"%s\" -->\n%s\n<!-- /nibwp:etchwp-component id=\"%s\" -->\n",
            esc_attr($component_id),
            $markup,
            esc_attr($component_id),
        );

        $current = (string) $post->post_content;

        if ($mode === 'replace_section') {
            $pattern = sprintf(
                '/\n?<!-- nibwp:etchwp-component id="%s" -->.*?<!-- \/nibwp:etchwp-component id="%s" -->\n?/s',
                preg_quote($component_id, '/'),
                preg_quote($component_id, '/'),
            );
            if (preg_match($pattern, $current)) {
                $new_content = (string) preg_replace($pattern, $markup, $current, 1);
            } else {
                $new_content = $current . $markup;
            }
        } else {
            // Replace if marker already exists (idempotency on append).
            $pattern = sprintf(
                '/\n?<!-- nibwp:etchwp-component id="%s" -->.*?<!-- \/nibwp:etchwp-component id="%s" -->\n?/s',
                preg_quote($component_id, '/'),
                preg_quote($component_id, '/'),
            );
            if (preg_match($pattern, $current)) {
                $new_content = (string) preg_replace($pattern, $markup, $current, 1);
            } else {
                $new_content = $current . $markup;
            }
        }

        $updated_id = wp_update_post([
            'ID'           => $post_id,
            // wp_update_post() runs wp_unslash() on the array, which would strip the
            // backslashes from the \uXXXX escapes that serialize_block_attributes()
            // legitimately produces for --, <, >, &, ". Pre-slash so they survive.
            'post_content' => wp_slash($new_content),
        ], true);

        if (is_wp_error($updated_id)) {
            return $updated_id;
        }
        $blocks_added = 1;
    }

    update_post_meta($post_id, '_nibwp_etchwp_component_' . $component_id, [
        'persisted_at' => time(),
        'styles_added' => $added,
        'styles_updated' => $updated,
    ]);

    // Persist reusable etch/component definitions from payload.components.
    // Stored in wp_options['nibwp_etchwp_components'] keyed by component id.
    // Future conversions can reference these by componentId; the validator
    // checks {props.X} against the registered properties array.
    $components_diff = nibwp_etchwp_persist_components($payload);

    return [
        'component_id'      => $component_id,
        'post_id'           => $post_id,
        'styles_added'      => $added,
        'styles_updated'    => $updated,
        'blocks_added'      => $blocks_added,
        'components_added'  => $components_diff['added'],
        'components_updated'=> $components_diff['updated'],
    ];
}

/**
 * Persist the components map (etch/component definitions) into a dedicated
 * option so they're reusable across post conversions.
 *
 * Storage shape:
 *   wp_options['nibwp_etchwp_components'] = [
 *     'my-card' => [
 *       'properties' => [{name, type, default?, options?, required?}, ...],
 *       'gutenbergBlock' => {...},
 *       'category' => 'Cards',
 *       'updated_at' => 1780332100,
 *     ],
 *     ...
 *   ]
 *
 * @return array{added:array<int,string>,updated:array<int,string>}
 */
function nibwp_etchwp_persist_components(array $payload): array
{
    $incoming = (array) ($payload['components'] ?? []);
    if ($incoming === []) {
        return ['added' => [], 'updated' => []];
    }
    $existing = (array) get_option('nibwp_etchwp_components', []);
    $added = [];
    $updated = [];
    $now = time();
    foreach ($incoming as $component_id => $def) {
        if (!is_string($component_id) || $component_id === '' || !is_array($def)) {
            continue;
        }
        $stamped = $def + ['updated_at' => $now];
        if (array_key_exists($component_id, $existing)) {
            if ($existing[$component_id] !== $stamped) {
                $existing[$component_id] = $stamped;
                $updated[] = $component_id;
            }
        } else {
            $existing[$component_id] = $stamped;
            $added[] = $component_id;
        }
    }
    if ($added !== [] || $updated !== []) {
        update_option('nibwp_etchwp_components', $existing, false);
    }
    return ['added' => $added, 'updated' => $updated];
}

/**
 * Serialize a block tree to Gutenberg block markup.
 *
 * Prefers WP core `serialize_block()` (WP 5.3+). Falls back to a minimal
 * implementation when the function is unavailable (test/CLI shims).
 *
 * @param array<string,mixed> $block
 */
function nibwp_etchwp_serialize_block(array $block): string
{
    if (function_exists('serialize_block')) {
        return (string) serialize_block(nibwp_etchwp_normalize_block_for_serialize($block));
    }
    // Minimal fallback — only used when running outside of WP runtime.
    $name        = (string) ($block['blockName'] ?? '');
    $attrs       = (array)  ($block['attrs']     ?? []);
    $innerBlocks = (array)  ($block['innerBlocks'] ?? []);
    $innerHTML   = (string) ($block['innerHTML']   ?? '');

    $attr_json = $attrs === [] ? '' : ' ' . wp_json_encode($attrs);
    $is_self_closing = empty($innerBlocks) && $innerHTML === '';

    if ($is_self_closing) {
        return sprintf('<!-- wp:%s%s /-->', $name, $attr_json);
    }
    $out = sprintf('<!-- wp:%s%s -->', $name, $attr_json) . "\n";
    foreach ($innerBlocks as $child) {
        if (is_array($child)) {
            $out .= nibwp_etchwp_serialize_block($child) . "\n";
        }
    }
    if ($innerHTML !== '') {
        $out .= $innerHTML . "\n";
    }
    $out .= sprintf('<!-- /wp:%s -->', $name);
    return $out;
}

/**
 * Ensure every block in the tree has an innerContent array consistent with
 * its innerBlocks. WP core serialize_block() walks innerContent only — when
 * agents omit it (as the playbook implies they may), serialize_block emits
 * a self-closing block and silently drops innerBlocks. Build innerContent
 * as N null placeholders interleaved with empty strings so each innerBlock
 * gets its serialize slot.
 *
 * @param array<string,mixed> $block
 * @return array<string,mixed>
 */
function nibwp_etchwp_normalize_block_for_serialize(array $block): array
{
    $inner_blocks = (array) ($block['innerBlocks'] ?? []);
    $block['innerBlocks'] = array_values(array_map(
        static fn ($b) => is_array($b) ? nibwp_etchwp_normalize_block_for_serialize($b) : $b,
        $inner_blocks
    ));
    if (!array_key_exists('attrs', $block) || !is_array($block['attrs'])) {
        $block['attrs'] = [];
    }
    if (!array_key_exists('innerHTML', $block) || !is_string($block['innerHTML'])) {
        $block['innerHTML'] = '';
    }
    // serialize_block() emits ONE innerBlock per null chunk in innerContent — not
    // one per innerBlocks entry. So a supplied innerContent whose null-count differs
    // from the real child count makes serialize_block() silently drop the extra
    // siblings (the html-to-component "first-child spine" bug). The validator can't
    // catch it because it walks innerBlocks, not innerContent. Treat innerBlocks as
    // the source of truth: (re)build innerContent whenever it doesn't line up — not
    // only when it's missing.
    $child_count   = count($block['innerBlocks']);
    $existing_ic   = (isset($block['innerContent']) && is_array($block['innerContent'])) ? $block['innerContent'] : null;
    $existing_null = $existing_ic === null ? -1 : count(array_filter($existing_ic, static fn ($c) => $c === null));
    if ($existing_null !== $child_count) {
        if ($child_count === 0) {
            $block['innerContent'] = $block['innerHTML'] === '' ? [] : [$block['innerHTML']];
        } else {
            // alternating "" + null — exactly one null per innerBlock, so
            // serialize_block() emits every child in order.
            $ic = [''];
            foreach ($block['innerBlocks'] as $_) {
                $ic[] = null;
                $ic[] = '';
            }
            $block['innerContent'] = $ic;
        }
    }
    return $block;
}

/**
 * Count real blocks in a tree. Accepts either a single block node (payload shape:
 * has a 'blockName' key) or an array of block nodes (parse_blocks() output).
 * Whitespace "blocks" parse_blocks() emits (blockName === null) are not counted,
 * so the number is comparable on both sides of a serialize → parse round-trip.
 *
 * @param mixed $node
 */
function nibwp_etchwp_count_blocks($node): int
{
    if (!is_array($node)) {
        return 0;
    }
    // An array of blocks (no 'blockName' key at this level).
    if (!array_key_exists('blockName', $node)) {
        $sum = 0;
        foreach ($node as $b) {
            $sum += nibwp_etchwp_count_blocks($b);
        }
        return $sum;
    }
    // A single block node.
    $self  = (($node['blockName'] ?? null) === null || $node['blockName'] === '') ? 0 : 1;
    $count = $self;
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        $count += nibwp_etchwp_count_blocks($child);
    }
    return $count;
}
