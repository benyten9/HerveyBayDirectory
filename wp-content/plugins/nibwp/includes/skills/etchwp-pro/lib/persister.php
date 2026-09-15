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

    // 1b) Mint a wp_block post per payload.components entry and rewrite every
    //     etch/component instance to its ref. Must happen before serialization:
    //     `ref` is the only thing Etch resolves a component by.
    $materialized = nibwp_etchwp_materialize_components($payload);
    if (isset($materialized['error'])) {
        return $materialized['error'];
    }

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
        // The real number of blocks written. This was a hard-coded 1, and the
        // playbook tells agents to echo the diff verbatim.
        $blocks_added = nibwp_etchwp_count_blocks($block_tree);
    }

    update_post_meta($post_id, '_nibwp_etchwp_component_' . $component_id, [
        'persisted_at' => time(),
        'styles_added' => $added,
        'styles_updated' => $updated,
    ]);

    // Components are the wp_block posts materialized above. The option this
    // used to report from (nibwp_etchwp_components) is read by nothing, Etch
    // included, so the diff said "no components added" while posts were being
    // created, and agents echoing it verbatim told the customer the same.
    return [
        'component_id'      => $component_id,
        'post_id'           => $post_id,
        'styles_added'      => $added,
        'styles_updated'    => $updated,
        'blocks_added'      => $blocks_added,
        'components_added'  => array_values(array_map('strval', $materialized['created'])),
        'components_updated'=> array_values(array_map('strval', $materialized['updated'])),
        'component_posts'   => $materialized['map'],
    ];
}

/**
 * Turn `payload.components` into components Etch can actually resolve.
 *
 * A component in Etch is a `wp_block` post, and an `etch/component` block
 * finds it through `ref` — that post's id. Nothing else resolves: the block
 * declares only `ref` and `attributes`, and returns an empty string when `ref`
 * is null.
 *
 * This used to write the definitions to `wp_options['nibwp_etchwp_components']`
 * and leave the instances carrying our own `componentId`. That option is ours;
 * Etch never reads it. So the definitions registered nowhere, every instance
 * resolved to nothing, and the section rendered blank on a payload the
 * validator had passed and the persister had reported as a success.
 *
 * Two passes, because a component may reference another component: mint every
 * post first so an id exists for each, then write the trees with instance
 * references rewritten to the refs those posts got.
 *
 * @param array $payload  Mutated in place: componentId → ref, props → attributes.
 * @return array{map:array<string,int>,created:array<int,string>,updated:array<int,string>}
 */
function nibwp_etchwp_materialize_components(array &$payload): array
{
    $components = (array) ($payload['components'] ?? []);
    if ($components === []) {
        return ['map' => [], 'created' => [], 'updated' => []];
    }

    $known   = (array) get_option('nibwp_etchwp_component_posts', []);
    $map     = [];
    $idents  = [];
    $created = [];
    $updated = [];

    // Pass 1 — every component gets a wp_block post, so every id is known
    // before any tree that might reference it is written.
    foreach ($components as $cid => $def) {
        $cid = (string) $cid;
        if ($cid === '' || !is_array($def)) {
            continue;
        }

        // Remembered by what the component is, not by its key in this payload:
        // payload keys are "1", "2" in every build, and keying on them made a
        // second build overwrite the first build's components site-wide.
        $ident = (string) ($def['key'] ?? '') !== ''
            ? (string) $def['key']
            : ((string) ($def['name'] ?? '') !== '' ? (string) $def['name'] : $cid);
        $idents[$cid] = $ident;

        $existing_id = isset($known[$ident]) ? (int) $known[$ident] : 0;

        // Posts saved by 1.2.9 and earlier sit under their payload key. Adopt
        // one only when it is visibly the same component, so a rebuild still
        // updates it in place while an unrelated "1" never takes it over.
        if ($existing_id === 0 && $ident !== $cid && isset($known[$cid])) {
            $legacy = get_post((int) $known[$cid]);
            if ($legacy && $legacy->post_title === sanitize_text_field((string) ($def['name'] ?? $cid))) {
                $existing_id = (int) $known[$cid];
            }
        }
        if ($existing_id > 0) {
            $post = get_post($existing_id);
            if (!$post || $post->post_type !== 'wp_block' || $post->post_status === 'trash') {
                $existing_id = 0; // Deleted or trashed since we last wrote it.
            }
        }

        if ($existing_id > 0) {
            $map[$cid] = $existing_id;
            $updated[] = $cid;
            continue;
        }

        $title = (string) ($def['name'] ?? $cid);
        $inserted = wp_insert_post([
            'post_title'   => sanitize_text_field($title),
            'post_name'    => sanitize_title($cid),
            'post_type'    => 'wp_block',
            'post_status'  => 'publish',
            'post_content' => '',
        ], true);

        if (is_wp_error($inserted)) {
            return ['map' => $map, 'created' => $created, 'updated' => $updated, 'error' => $inserted];
        }

        $map[$cid] = (int) $inserted;
        $created[] = $cid;
    }

    // Pass 2 — write each component's tree, with nested instances resolved.
    foreach ($components as $cid => $def) {
        $cid = (string) $cid;
        if (!isset($map[$cid]) || !is_array($def)) {
            continue;
        }

        $blocks = nibwp_etchwp_component_blocks($def, $cid);
        if ($blocks === []) {
            continue;
        }

        foreach ($blocks as &$block) {
            nibwp_etchwp_rewrite_component_refs($block, $map, $components);
        }
        unset($block);

        $content = implode("\n\n", array_map('nibwp_etchwp_serialize_block', $blocks));
        wp_update_post([
            'ID' => $map[$cid],
            // Same reason as the page write below: wp_update_post unslashes,
            // and the \uXXXX escapes in serialized block attributes must survive.
            'post_content' => wp_slash($content),
        ], true);

        // Where Etch reads a component's props from (CachedPattern). Without
        // it every instance's attributes have no property to land on.
        $name = (string) ($def['name'] ?? $cid);
        $html_key = (string) ($def['key'] ?? '') !== ''
            ? (string) $def['key']
            : str_replace(' ', '', ucwords(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $name))));
        update_post_meta($map[$cid], 'etch_component_properties', wp_slash(nibwp_etchwp_component_properties_for_etch($def)));
        update_post_meta($map[$cid], 'etch_component_html_key', wp_slash($html_key));
    }

    // The page tree's own instances.
    if (isset($payload['gutenbergBlock']) && is_array($payload['gutenbergBlock'])) {
        nibwp_etchwp_rewrite_component_refs($payload['gutenbergBlock'], $map, $components);
    }

    if ($map !== []) {
        $remember = [];
        foreach ($map as $cid => $post_id) {
            $remember[$idents[$cid]] = $post_id;
        }
        // array_replace, not array_merge: merge renumbers numeric keys.
        update_option('nibwp_etchwp_component_posts', array_replace($known, $remember), false);
    }

    return ['map' => $map, 'created' => $created, 'updated' => $updated];
}

/**
 * Rewrite `etch/component` instances into the shape Etch reads.
 *
 * An instance naming a component in this payload - by `componentId`, or by a
 * `ref` equal to the component's id or key - gets `ref` set to the minted
 * wp_block post id, and `props` folded into `attributes`. An instance whose
 * ref points at a post already on the site is left exactly as it is.
 *
 * @param array<string,int>   $map        component key => wp_block post id
 * @param array<string,mixed> $components payload.components
 */
function nibwp_etchwp_rewrite_component_refs(array &$node, array $map, array $components): void
{
    if (($node['blockName'] ?? '') === 'etch/component') {
        $attrs = (array) ($node['attrs'] ?? []);
        $local = nibwp_etchwp_local_component_key($attrs, $components);

        if ($local !== '' && isset($map[$local])) {
            $attrs['ref'] = $map[$local];
            unset($attrs['componentId']);

            if (isset($attrs['props'])) {
                // Merge rather than overwrite: an instance may legitimately
                // carry both, and Etch reads only `attributes`.
                $attrs['attributes'] = (array) ($attrs['attributes'] ?? []) + (array) $attrs['props'];
                unset($attrs['props']);
            }

            $node['attrs'] = $attrs;
        }
    }

    foreach (['innerBlocks', 'inner_blocks'] as $key) {
        if (!isset($node[$key]) || !is_array($node[$key])) {
            continue;
        }
        foreach ($node[$key] as &$child) {
            if (is_array($child)) {
                nibwp_etchwp_rewrite_component_refs($child, $map, $components);
            }
        }
        unset($child);
    }
}

/**
 * Property definitions in the shape Etch reads.
 *
 * ComponentProperty::from_array() returns null for a property without a `key`
 * and treats a `type` that is not {primitive, specialized?} as no type at all,
 * so the instance's values have nowhere to land. The skill's older shorthand
 * ({name, type: "string"}) is converted rather than silently lost.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_etchwp_component_properties_for_etch(array $def): array
{
    $out = [];
    foreach ((array) ($def['properties'] ?? []) as $p) {
        $key = nibwp_etchwp_property_key($p);
        if ($key === '') {
            continue;
        }

        $p['key']  = $key;
        $p['name'] = (string) ($p['name'] ?? '') !== '' ? (string) $p['name'] : $key;

        if (!is_array($p['type'] ?? null)) {
            $type = strtolower((string) ($p['type'] ?? 'string'));
            $p['type'] = match ($type) {
                'boolean', 'bool' => ['primitive' => 'boolean'],
                'number'          => ['primitive' => 'number'],
                'image'           => ['primitive' => 'string', 'specialized' => 'image'],
                'select'          => ['primitive' => 'string', 'specialized' => 'select'],
                default           => ['primitive' => 'string'],
            };
            if ($type === 'select' && !isset($p['selectOptionsString']) && is_array($p['options'] ?? null)) {
                $p['selectOptionsString'] = implode("\n", array_map('strval', $p['options']));
            }
        }

        $out[] = $p;
    }

    return $out;
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
