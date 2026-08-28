<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/converter.php';

/**
 * Elementor Pro skill — persister (the part that actually makes pages render).
 *
 * Elementor stores its tree JSON-encoded AND wp_slash'd in `_elementor_data`.
 * WordPress's update_post_meta() runs stripslashes on the value, so if you save
 * a plain wp_json_encode() string the `\/` and `\uXXXX` escapes get mangled and
 * the JSON becomes invalid → the editor shows nothing and the front end is blank.
 * We wp_slash() so the value survives intact. We also:
 *   - set edit-mode / version / template-type / page-template meta,
 *   - back up the previous data (so an update can be reverted),
 *   - round-trip check (re-read, decode, element count) and REVERT on mismatch,
 *   - regenerate the page CSS so it renders immediately without opening the editor.
 *
 * @param array $tree   Normalised element tree (from nibwp_elementor_pro_build).
 * @param array $target { mode:new_page|new_post|update, post_id?, title?, template? }
 * @return array|WP_Error
 */
function nibwp_elementor_pro_persist(array $tree, array $target): array|WP_Error
{
    if (!defined('ELEMENTOR_VERSION')) {
        return new WP_Error('elementor_missing', 'Elementor is not active on this site.');
    }

    $mode     = (string) ($target['mode'] ?? 'new_page');
    $title    = trim((string) ($target['title'] ?? '')) ?: __('Elementor page', 'nibwp');
    $template = (string) ($target['template'] ?? 'elementor_canvas');
    $post_id  = (int) ($target['post_id'] ?? 0);
    $post_type = $mode === 'new_post' ? 'post' : 'page';

    // ── resolve the post ──
    if ($mode === 'update') {
        if ($post_id <= 0 || !get_post($post_id)) {
            return new WP_Error('not_found', 'update mode needs a valid post_id.');
        }
    } else {
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => $post_type,
            'post_status'  => 'draft',
            'post_content' => '',
        ], true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
    }

    // ── encode + backup ──
    $encoded = wp_json_encode($tree);
    if ($encoded === false) {
        return new WP_Error('encode_failed', 'Could not JSON-encode the element tree.');
    }
    $expected = nibwp_elementor_pro_count($tree);
    $backup   = get_post_meta($post_id, '_elementor_data', true);
    update_post_meta($post_id, '_nibwp_elementor_backup', $backup);

    // ── write (wp_slash is the whole point) ──
    update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
    update_post_meta($post_id, '_elementor_template_type', $post_type === 'post' ? 'wp-post' : 'wp-page');
    if ($mode !== 'update') {
        update_post_meta($post_id, '_wp_page_template', $template);
    }

    // ── round-trip guard ──
    $stored  = (string) get_post_meta($post_id, '_elementor_data', true);
    $decoded = json_decode($stored, true);
    $got     = is_array($decoded) ? nibwp_elementor_pro_count($decoded) : -1;
    if (!is_array($decoded) || $got !== $expected) {
        // revert to backup, do not leave a broken page
        if ($backup !== '') {
            update_post_meta($post_id, '_elementor_data', wp_slash((string) $backup));
        } else {
            delete_post_meta($post_id, '_elementor_data');
        }
        return new WP_Error(
            'roundtrip_failed',
            sprintf('Save aborted: stored tree did not round-trip (expected %d elements, got %d). Nothing was overwritten.', $expected, $got)
        );
    }

    // ── regenerate CSS so the front end renders without opening the editor ──
    $css_regenerated = false;
    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        try {
            \Elementor\Core\Files\CSS\Post::create($post_id)->update();
            $css_regenerated = true;
        } catch (\Throwable $e) {
            // non-fatal — the editor will regenerate on first open
        }
    }
    if (
        class_exists('\Elementor\Plugin')
        && isset(\Elementor\Plugin::instance()->files_manager)
        && method_exists(\Elementor\Plugin::instance()->files_manager, 'clear_cache')
    ) {
        \Elementor\Plugin::instance()->files_manager->clear_cache();
    }

    return [
        'post_id'         => $post_id,
        'mode'            => $mode,
        'elements'        => $expected,
        'css_regenerated' => $css_regenerated,
        'edit_url'        => admin_url('post.php?post=' . $post_id . '&action=elementor'),
        'view_url'        => get_permalink($post_id),
        'note'            => 'Data saved slashed + CSS regenerated. The page renders on the front end now; open in Elementor to fine-tune.',
    ];
}

/**
 * Sideload a remote image into the Media Library and return a real attachment.
 * Use this before authoring so image widgets carry an attachment id (not a
 * hotlinked URL). Returns { id, url } or WP_Error.
 */
function nibwp_elementor_pro_sideload_image(string $url, int $parent_post = 0): array|WP_Error
{
    if (!preg_match('#^https?://#i', $url)) {
        return new WP_Error('bad_url', 'Provide an absolute http(s) image URL.');
    }
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $id = media_sideload_image($url, $parent_post, null, 'id');
    if (is_wp_error($id)) {
        return $id;
    }
    return ['id' => (int) $id, 'url' => (string) wp_get_attachment_url((int) $id)];
}
