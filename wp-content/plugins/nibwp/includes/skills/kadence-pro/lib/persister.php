<?php

declare(strict_types=1);

/**
 * Kadence Pro — persister.
 *
 * Serializes a validated Kadence block tree to Gutenberg markup and writes it to
 * the chosen target: a page/post, a Kadence `kadence_element` (header/footer/hook,
 * when Kadence Pro registers that CPT), or a reusable pattern (`wp_block`).
 *
 * Defense in depth: re-runs the validator on entry and refuses to write on
 * failure — no bypass. Serialization reuses the proven authoritative-innerContent
 * rebuild + a post-serialize round-trip guard so a block is never silently
 * dropped (the "first-child spine" class of bug).
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/validator.php';

/**
 * @param array<int,array<string,mixed>> $blocks Built Kadence block nodes.
 * @param array{mode?:string,post_id?:int,title?:string,post_type?:string,element_type?:string} $target
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>|WP_Error
 */
function nibwp_kadence_persist(array $blocks, array $target, array $ctx = [])
{
    // Re-validate unconditionally.
    $verdict = nibwp_kadence_validate_blocks($blocks, $ctx);
    if (!$verdict['passed']) {
        return new WP_Error('persist_validation_failed', 'Validator rejected the block tree at the persist gate.', [
            'failed' => $verdict['failed'], 'warnings' => $verdict['warnings'],
        ]);
    }

    // Serialize the whole tree.
    $expected = 0;
    $markup_parts = [];
    foreach ($blocks as $node) {
        if (!is_array($node)) {
            continue;
        }
        $expected += nibwp_kadence_count_blocks($node);
        $markup_parts[] = nibwp_kadence_serialize_block($node);
    }
    $markup = implode("\n", $markup_parts);

    // Round-trip guard: never persist a truncated tree.
    if (function_exists('parse_blocks')) {
        $got = nibwp_kadence_count_blocks(parse_blocks($markup));
        if ($got < $expected) {
            return new WP_Error('serialize_truncated', sprintf(
                'Block serialization dropped content (%d of %d survived) — write aborted.', $got, $expected
            ), ['expected' => $expected, 'got' => $got]);
        }
    }

    $mode = (string) ($target['mode'] ?? 'new_page');
    $title = trim((string) ($target['title'] ?? '')) ?: 'Kadence Layout (NIBWP)';

    // Resolve the post type for the target.
    $post_type = match ($mode) {
        'element' => post_type_exists('kadence_element') ? 'kadence_element' : 'page',
        'pattern' => 'wp_block',
        'new_post' => 'post',
        'update'  => '', // resolved from the existing post
        default   => (string) ($target['post_type'] ?? 'page'),
    };

    // Update an existing post. Default: replace the NIBWP-authored section behind
    // a marker that is STABLE per post_id (re-running replaces it — never appends
    // a duplicate, the bug in troubleshooting.md). target.replace_all replaces the
    // whole post_content instead.
    if ($mode === 'update') {
        $post_id = (int) ($target['post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof WP_Post) {
            return new WP_Error('not_found', 'target.post_id does not exist.');
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('cannot_edit', 'User cannot edit that post.');
        }
        if (!empty($target['replace_all'])) {
            $new_content = $markup;
        } else {
            $marker_id = sha1('kadence|' . $post_id); // stable per post → replace, not append
            $wrapped = sprintf("\n<!-- nibwp:kadence id=\"%s\" -->\n%s\n<!-- /nibwp:kadence id=\"%s\" -->\n", esc_attr($marker_id), $markup, esc_attr($marker_id));
            $current = (string) $post->post_content;
            $pattern = sprintf('/\n?<!-- nibwp:kadence id="%1$s" -->.*?<!-- \/nibwp:kadence id="%1$s" -->\n?/s', preg_quote($marker_id, '/'));
            $new_content = preg_match($pattern, $current)
                ? (string) preg_replace($pattern, $wrapped, $current, 1)
                : $current . $wrapped;
        }
        $res = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($new_content)], true);
        if (is_wp_error($res)) {
            return $res;
        }
        return ['mode' => 'update', 'post_id' => $post_id, 'blocks' => $expected, 'replaced_all' => !empty($target['replace_all']), 'edit_url' => get_edit_post_link($post_id, 'raw'), 'warnings' => $verdict['warnings']];
    }

    // Create a new post/page/element/pattern.
    if (!current_user_can('edit_posts')) {
        return new WP_Error('cannot_create', 'User cannot create content.');
    }
    $postarr = [
        'post_type'    => $post_type,
        'post_status'  => $mode === 'pattern' ? 'publish' : 'draft',
        'post_title'   => $title,
        'post_content' => wp_slash($markup),
    ];
    $new_id = wp_insert_post($postarr, true);
    if (is_wp_error($new_id)) {
        return $new_id;
    }

    $out = [
        'mode'      => $mode,
        'post_id'   => (int) $new_id,
        'post_type' => $post_type,
        'blocks'    => $expected,
        'edit_url'  => get_edit_post_link((int) $new_id, 'raw'),
        'warnings'  => $verdict['warnings'],
    ];
    if ($mode === 'element' && $post_type === 'kadence_element') {
        // Placement (hook/header/footer) is set in the Kadence Elements UI — we do
        // NOT guess Kadence Pro's placement meta schema. Flag it for the user.
        $out['note'] = 'Kadence Element created as a draft. Set its placement/display conditions in Appearance → Kadence → Elements, then publish.';
    } elseif ($mode === 'element') {
        $out['note'] = 'Kadence Pro Elements CPT not found — created a page instead. Install Kadence Pro for header/footer/hook elements.';
    }
    return $out;
}

/**
 * Write page-scoped CSS to Kadence Blocks Pro's OWN per-page Custom CSS field
 * (`_kad_blocks_custom_css` — Kadence registers + prints it). This is the ONLY
 * correct home for the rare CSS that genuinely can't be a block attribute
 * (post-loop .entry-* internals, third-party form markup, @keyframes, :hover).
 * Never Customizer Additional CSS (site-wide) and never a core/html block.
 *
 * @param 'replace'|'append' $mode
 * @return array<string,mixed>|WP_Error
 */
function nibwp_kadence_set_custom_css(int $post_id, string $css, string $mode = 'replace')
{
    if (get_post_status($post_id) === false) {
        return new WP_Error('not_found', 'post_id does not exist.');
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('cannot_edit', 'User cannot edit that post.');
    }
    $css = trim($css);
    if ($mode === 'append') {
        $existing = (string) get_post_meta($post_id, '_kad_blocks_custom_css', true);
        $css = trim($existing . "\n" . $css);
    }
    update_post_meta($post_id, '_kad_blocks_custom_css', $css);
    // Bump post_modified so Kadence's generated-CSS cache invalidates.
    wp_update_post(['ID' => $post_id]);
    return ['post_id' => $post_id, 'meta_key' => '_kad_blocks_custom_css', 'bytes' => strlen($css), 'mode' => $mode];
}

// ---------------------------------------------------------------------------
// Gutenberg block serialization (self-contained; proven authoritative-innerContent
// rebuild so serialize_block never drops sibling innerBlocks).
// ---------------------------------------------------------------------------

/** @param array<string,mixed> $block */
function nibwp_kadence_serialize_block(array $block): string
{
    if (function_exists('serialize_block')) {
        return (string) serialize_block(nibwp_kadence_normalize_block($block));
    }
    $name = (string) ($block['blockName'] ?? '');
    $attrs = (array) ($block['attrs'] ?? []);
    $inner = (array) ($block['innerBlocks'] ?? []);
    $html = (string) ($block['innerHTML'] ?? '');
    $attr_json = $attrs === [] ? '' : ' ' . wp_json_encode($attrs);
    if ($inner === [] && $html === '') {
        return sprintf('<!-- wp:%s%s /-->', $name, $attr_json);
    }
    $out = sprintf('<!-- wp:%s%s -->', $name, $attr_json) . "\n";
    foreach ($inner as $child) {
        if (is_array($child)) {
            $out .= nibwp_kadence_serialize_block($child) . "\n";
        }
    }
    if ($html !== '') {
        $out .= $html . "\n";
    }
    return $out . sprintf('<!-- /wp:%s -->', $name);
}

/** @param array<string,mixed> $block @return array<string,mixed> */
function nibwp_kadence_normalize_block(array $block): array
{
    $inner = (array) ($block['innerBlocks'] ?? []);
    $block['innerBlocks'] = array_values(array_map(
        static fn($b) => is_array($b) ? nibwp_kadence_normalize_block($b) : $b,
        $inner
    ));
    if (!isset($block['attrs']) || !is_array($block['attrs'])) {
        $block['attrs'] = [];
    }
    if (!isset($block['innerHTML']) || !is_string($block['innerHTML'])) {
        $block['innerHTML'] = '';
    }
    $child_count = count($block['innerBlocks']);
    $ic = $block['innerContent'] ?? null;
    $null_count = is_array($ic) ? count(array_filter($ic, static fn($c) => $c === null)) : -1;

    // A leaf block carrying text is the case the null-count test cannot see: no
    // children and no nulls means the counts agree, so innerContent = [] looked
    // correct and was left alone — and serialize_block emits a self-closing
    // block, dropping the innerHTML entirely. That silently deleted every
    // source:html field (advancedheading content, listitem text, core/heading
    // and core/paragraph), which is to say all authored copy on the page.
    $ic_html = is_array($ic)
        ? implode('', array_filter($ic, static fn($c) => is_string($c)))
        : '';
    $html_missing = $block['innerHTML'] !== '' && trim($ic_html) === '';

    if ($null_count !== $child_count || $html_missing) {
        if ($child_count === 0) {
            $block['innerContent'] = $block['innerHTML'] === '' ? [] : [$block['innerHTML']];
        } else {
            $built = [$block['innerHTML'] !== '' ? $block['innerHTML'] : ''];
            foreach ($block['innerBlocks'] as $_) {
                $built[] = null;
                $built[] = '';
            }
            $block['innerContent'] = $built;
        }
    }
    return $block;
}

/** @param mixed $node */
function nibwp_kadence_count_blocks($node): int
{
    if (!is_array($node)) {
        return 0;
    }
    if (!array_key_exists('blockName', $node)) {
        $sum = 0;
        foreach ($node as $b) {
            $sum += nibwp_kadence_count_blocks($b);
        }
        return $sum;
    }
    $self = (($node['blockName'] ?? null) === null || $node['blockName'] === '') ? 0 : 1;
    $count = $self;
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        $count += nibwp_kadence_count_blocks($child);
    }
    return $count;
}
