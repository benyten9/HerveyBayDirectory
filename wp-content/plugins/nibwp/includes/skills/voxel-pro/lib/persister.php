<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — write the document.
 *
 * Everything a Voxel template needs lives in four places, and forgetting any
 * one of them produces a template that looks written and behaves broken: the
 * element tree, the page settings, the search wiring in `_voxel_page_settings`,
 * and Elementor's generated-CSS cache, which has to be dropped or the old
 * styles keep being served.
 */

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/relations.php';

const NIBWP_VOXEL_PRO_BACKUP_META = '_nibwp_voxel_pro_backup';

/**
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_pro_persist(array $elements, array $template, array $relations, array $opts = []): array|WP_Error
{
    $kind      = (string) ($template['kind'] ?? '');
    $spec      = nibwp_voxel_pro_kinds()[$kind] ?? null;
    if ($spec === null) {
        return new WP_Error('unknown_kind', sprintf('Unknown template kind "%s".', $kind));
    }
    $post_type = sanitize_key((string) ($template['post_type'] ?? ''));
    $title     = trim((string) ($template['title'] ?? ''));
    if ($title === '') {
        $title = trim(sprintf('%s%s', $post_type !== '' ? ucfirst($post_type) . ': ' : '', $spec['label']));
    }

    $existing = (int) ($template['existing_id'] ?? 0);
    $created  = false;
    $backup   = false;

    if ($existing > 0) {
        $post = get_post($existing);
        if (!$post || !in_array($post->post_type, ['elementor_library', 'page'], true)) {
            return new WP_Error('unknown_template', sprintf('There is no template or page with id %d to rebuild.', $existing));
        }
        $backup = nibwp_voxel_pro_backup($existing);
        $template_id = $existing;
        $title = $post->post_title;
    } else {
        $template_id = $spec['doc'] === 'page'
            ? \Voxel\create_page($title, sanitize_title($title))
            : \Voxel\create_template($title, $spec['type']);
        if (is_wp_error($template_id)) {
            return $template_id;
        }
        if (!$template_id) {
            return new WP_Error('create_failed', 'The template could not be created.');
        }
        $template_id = (int) $template_id;
        $created = true;
    }

    // The element tree.
    update_post_meta($template_id, '_elementor_data', wp_slash((string) wp_json_encode($elements)));
    update_post_meta($template_id, '_elementor_edit_mode', 'builder');
    if (defined('ELEMENTOR_VERSION')) {
        update_post_meta($template_id, '_elementor_version', ELEMENTOR_VERSION);
    }

    // Page settings, plus the listing a card previews against in the editor.
    $page_settings = (array) get_post_meta($template_id, '_elementor_page_settings', true);
    $page_settings = array_merge($page_settings, (array) ($opts['page_settings'] ?? []));
    if (in_array($kind, ['card', 'single-post', 'archive'], true)) {
        $preview = (int) ($opts['preview_post_id'] ?? 0);
        if ($preview <= 0 && $post_type !== '') {
            $ids = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
            $preview = $ids === [] ? 0 : (int) $ids[0];
        }
        if ($preview > 0) {
            $page_settings['voxel_preview_post'] = $preview;
        }
    }
    if ($page_settings !== []) {
        update_post_meta($template_id, '_elementor_page_settings', $page_settings);
    }

    // The search wiring. Paths were computed from the tree just written.
    $wired = count((array) $relations['feedToSearch']) + count((array) $relations['mapToSearch']);
    $previous = \Voxel\get_custom_page_settings($template_id);
    update_post_meta($template_id, '_voxel_page_settings', wp_slash((string) wp_json_encode(nibwp_voxel_pro_relations_payload($relations, $previous))));
    delete_post_meta($template_id, '_voxel_page_settings_tmp');

    // Elementor serves cached CSS until these are gone.
    delete_post_meta($template_id, '_elementor_css');
    delete_post_meta($template_id, '_elementor_page_assets');

    $out = [
        'template_id'       => $template_id,
        'kind'              => $kind,
        'title'             => $title,
        'created'           => $created,
        'backup_taken'      => $backup,
        'relations_written' => $wired,
        'edit_link'         => admin_url('post.php?post=' . $template_id . '&action=elementor'),
        'preview_link'      => (string) get_permalink($template_id),
        'assigned'          => null,
    ];

    $assign = (array) ($opts['assign'] ?? []);
    if ($assign !== []) {
        $assigned = nibwp_voxel_pro_assign($template_id, $assign);
        if (is_wp_error($assigned)) {
            // The template is written and sound; only the pointer failed.
            $out['assign_error'] = $assigned->get_error_message();
        } else {
            $out['assigned'] = $assigned;
        }
    }

    return $out;
}

/** Keep the previous document so a rebuild is never a one-way door. */
function nibwp_voxel_pro_backup(int $template_id): bool
{
    $data = (string) get_post_meta($template_id, '_elementor_data', true);
    if ($data === '') {
        return false;
    }
    update_post_meta($template_id, NIBWP_VOXEL_PRO_BACKUP_META, wp_slash((string) wp_json_encode([
        'saved_at'       => time(),
        'elementor_data' => $data,
        'page_settings'  => get_post_meta($template_id, '_elementor_page_settings', true),
        'voxel_settings' => (string) get_post_meta($template_id, '_voxel_page_settings', true),
    ])));
    return true;
}

/** Put the previous document back. */
function nibwp_voxel_pro_restore(int $template_id): array|WP_Error
{
    $raw = (string) get_post_meta($template_id, NIBWP_VOXEL_PRO_BACKUP_META, true);
    if ($raw === '') {
        return new WP_Error('no_backup', sprintf('No backup is stored for template %d — it has not been rebuilt by this skill.', $template_id));
    }
    $backup = json_decode($raw, true);
    if (!is_array($backup) || ($backup['elementor_data'] ?? '') === '') {
        return new WP_Error('bad_backup', 'The stored backup could not be read.');
    }

    update_post_meta($template_id, '_elementor_data', wp_slash((string) $backup['elementor_data']));
    if (isset($backup['page_settings']) && $backup['page_settings'] !== '') {
        update_post_meta($template_id, '_elementor_page_settings', $backup['page_settings']);
    }
    if (($backup['voxel_settings'] ?? '') !== '') {
        update_post_meta($template_id, '_voxel_page_settings', wp_slash((string) $backup['voxel_settings']));
    }
    delete_post_meta($template_id, '_elementor_css');
    delete_post_meta($template_id, '_elementor_page_assets');

    return [
        'restored'    => true,
        'template_id' => $template_id,
        'saved_at'    => gmdate('c', (int) ($backup['saved_at'] ?? 0)),
        'edit_link'   => admin_url('post.php?post=' . $template_id . '&action=elementor'),
    ];
}

/**
 * Point something at this template.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_pro_assign(int $template_id, array $assign): array|WP_Error
{
    $post_type = sanitize_key((string) ($assign['post_type'] ?? ''));
    $taxonomy  = sanitize_key((string) ($assign['taxonomy'] ?? ''));
    $slot      = (string) ($assign['slot'] ?? '');
    $mode      = (string) ($assign['mode'] ?? 'main');

    try {
        if ($post_type !== '') {
            $pt = \Voxel\Post_Type::get($post_type);
            if (!$pt) {
                return new WP_Error('unknown_post_type', sprintf('No Voxel post type "%s".', $post_type));
            }
            $config = (array) $pt->repository->get_config();

            if ($mode === 'custom') {
                $custom = (array) ($config['custom_templates'] ?? []);
                $cards  = (array) ($custom['card'] ?? []);
                $label  = (string) ($assign['label'] ?? 'Alternate card');
                $cards[] = [
                    'label'      => $label,
                    'id'         => $template_id,
                    'unique_key' => strtolower(\Voxel\random_string(8)),
                ];
                $custom['card'] = $cards;
                $config['custom_templates'] = $custom;
                $pt->repository->set_config($config);
                \Voxel\Post_Type::force_get($post_type);
                return [
                    'where'   => sprintf('%s custom card "%s"', $post_type, $label),
                    'summary' => sprintf('Added as a named alternate card "%s" on %s — pick it per feed with ts_card_template__%s = %d.', $label, $post_type, $post_type, $template_id),
                ];
            }

            $pt_slot   = (string) ($assign['pt_slot'] ?? 'card');
            $templates = (array) ($config['templates'] ?? []);
            $previous  = (int) ($templates[$pt_slot] ?? 0);
            $config['templates'] = array_merge($templates, [$pt_slot => $template_id]);
            $pt->repository->set_config($config);
            \Voxel\Post_Type::force_get($post_type);
            return [
                'where'    => sprintf('%s.%s', $post_type, $pt_slot),
                'replaced' => $previous,
                'summary'  => sprintf(
                    'Assigned as the %s %s template%s.',
                    $post_type,
                    $pt_slot,
                    $previous > 0 ? sprintf(', replacing template #%d', $previous) : ''
                ),
            ];
        }

        if ($taxonomy !== '') {
            $tax_slot = (string) ($assign['tax_slot'] ?? 'card');
            $all = (array) \Voxel\get('taxonomies', []);
            $cfg = (array) ($all[$taxonomy] ?? []);
            $cfg['templates'] = array_merge((array) ($cfg['templates'] ?? []), [$tax_slot => $template_id]);
            $all[$taxonomy] = $cfg;
            \Voxel\set('taxonomies', $all);
            return [
                'where'   => sprintf('%s.%s', $taxonomy, $tax_slot),
                'summary' => sprintf('Assigned as the %s %s template.', $taxonomy, $tax_slot),
            ];
        }

        if ($slot !== '') {
            $previous = (int) \Voxel\get('templates.' . $slot, 0);
            \Voxel\set('templates.' . $slot, $template_id);
            return [
                'where'    => 'templates.' . $slot,
                'replaced' => $previous,
                'summary'  => sprintf(
                    'Assigned to the site %s slot%s.',
                    $slot,
                    $previous > 0 ? sprintf(', replacing template #%d — pass that id as existing_id to put it back', $previous) : ''
                ),
            ];
        }
    } catch (\Throwable $e) {
        return new WP_Error('assign_failed', $e->getMessage());
    }

    return new WP_Error('nothing_to_assign', 'The assign block named neither a site slot, a post type, nor a taxonomy.');
}
