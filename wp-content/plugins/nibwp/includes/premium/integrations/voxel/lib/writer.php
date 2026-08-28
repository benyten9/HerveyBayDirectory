<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel integration — write layer.
 *
 * The one rule: values go through Voxel's own field objects
 * (sanitize → check_validity → update), never through raw meta writes. Half of
 * Voxel's field types keep their truth somewhere other than the meta value —
 * work-hours and relations in custom tables, taxonomies in terms, the title in
 * wp_posts — and Voxel's update() is the only code that knows all of it.
 *
 * Two-pass, all-or-nothing: every submitted value is validated before any
 * value is written. A partial write is worse than a refusal, because the
 * agent reading "3 of 7 fields saved" has no honest next move.
 */

/**
 * Statuses a listing may be moved to here. Trash is deliberately absent —
 * every destructive act lives in voxel-delete, behind the danger scope.
 *
 * @return array<int,string>
 */
function nibwp_voxel_writable_statuses(): array
{
    return ['draft', 'pending', 'publish', 'private', 'unpublished', 'rejected', 'expired'];
}

/**
 * Apply field values to a listing, through Voxel.
 *
 * @param \Voxel\Post $post
 * @param array<string,mixed> $values field key => raw value
 * @return array{updated: array<int,string>, warnings: array<int,string>}|WP_Error
 */
function nibwp_voxel_apply_fields(\Voxel\Post $post, array $values)
{
    $warnings = [];

    // ── Resolve every key first ──────────────────────────────────────────────
    // All unknown keys are collected before anything else happens, and the
    // error carries the full valid vocabulary: an agent that guessed wrong
    // learns everything it needs from one refusal instead of seven.
    $title_value = null;
    $resolved = [];
    $unknown = [];

    foreach ($values as $key => $raw) {
        $key = (string) $key;

        if ($key === 'title') {
            $title_value = $raw;
            continue;
        }

        $field = $post->get_field($key);
        $type = $field ? (string) $field->get_type() : '';

        if ($field === null || str_starts_with($type, 'ui-')) {
            $unknown[] = $key;
            continue;
        }

        // Voxel's own title field update() is a no-op; route it to WordPress.
        if ($type === 'title') {
            $title_value = $raw;
            continue;
        }

        $resolved[$key] = ['field' => $field, 'raw' => $raw];
    }

    if ($unknown !== []) {
        return nibwp_voxel_err(
            'voxel_unknown_field',
            sprintf('Unknown field(s): %s.', implode(', ', $unknown)),
            400,
            ['valid_fields' => nibwp_voxel_schema_map($post->post_type)]
        );
    }

    // ── Normalize the shapes agents actually send ────────────────────────────
    foreach ($resolved as $key => &$entry) {
        $type = (string) $entry['field']->get_type();

        // Taxonomy terms by slug, validated before Voxel sees them: Voxel's
        // sanitize silently drops unknown slugs, and a silent drop reads as
        // success to the caller.
        if ($type === 'taxonomy') {
            $taxonomy = (string) $entry['field']->get_prop('taxonomy');
            $slugs = array_map('strval', (array) $entry['raw']);
            $missing = [];
            foreach ($slugs as $slug) {
                if (!get_term_by('slug', $slug, $taxonomy)) {
                    $missing[] = $slug;
                }
            }
            if ($missing !== []) {
                $available = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'slugs']);
                return nibwp_voxel_err(
                    'voxel_unknown_term',
                    sprintf('Unknown term slug(s) for "%s": %s. Create terms first with nibwp/voxel-terms.', $key, implode(', ', $missing)),
                    400,
                    ['available_slugs' => is_array($available) ? $available : []]
                );
            }
            $entry['raw'] = $slugs;
        }

        // Files by attachment ID, or by URL — agents almost never hold IDs.
        if (in_array($type, ['image', 'file', 'gallery', 'profile-avatar'], true)) {
            $ids = [];
            foreach ((array) $entry['raw'] as $item) {
                if (is_numeric($item)) {
                    $ids[] = (int) $item;
                    continue;
                }
                if (is_string($item) && preg_match('#^https?://#i', $item)) {
                    $sideloaded = nibwp_voxel_sideload($item, $post->get_id());
                    if (is_wp_error($sideloaded)) {
                        return $sideloaded;
                    }
                    $ids[] = $sideloaded;
                    continue;
                }
                return nibwp_voxel_err(
                    'voxel_bad_file',
                    sprintf('Field "%s" takes attachment IDs or https URLs; got %s.', $key, wp_json_encode($item))
                );
            }
            $entry['raw'] = $ids;
        }
    }
    unset($entry);

    // ── Pass 1: validate everything, write nothing ───────────────────────────
    $errors = [];
    foreach ($resolved as $key => &$entry) {
        try {
            $entry['sanitized'] = $entry['field']->sanitize($entry['raw']);
            $entry['field']->check_validity($entry['sanitized']);

            // Voxel's sanitizers turn some invalid input into nothing (an
            // email that is not an email becomes ''), and validation then
            // passes on the nothing. To the caller that reads as "saved"
            // while the value quietly vanished — the one failure mode an
            // agent cannot recover from. A non-empty value that sanitizes to
            // empty is an error here.
            $raw_empty = $entry['raw'] === null || $entry['raw'] === '' || $entry['raw'] === [];
            if (!$raw_empty && $entry['field']->is_empty($entry['sanitized'])) {
                $errors[$key] = sprintf(
                    'Value %s was rejected by sanitization (it is not a valid %s).',
                    wp_json_encode($entry['raw']),
                    (string) $entry['field']->get_type()
                );
            }
        } catch (\Throwable $e) {
            $errors[$key] = $e->getMessage();
        }
    }
    unset($entry);

    if ($errors !== []) {
        return nibwp_voxel_err(
            'voxel_validation_failed',
            'Validation failed; nothing was written.',
            400,
            ['field_errors' => $errors]
        );
    }

    // ── Pass 2: write ────────────────────────────────────────────────────────
    $updated = [];

    if ($title_value !== null) {
        $result = wp_update_post(['ID' => $post->get_id(), 'post_title' => sanitize_text_field((string) $title_value)], true);
        if (is_wp_error($result)) {
            return $result;
        }
        $updated[] = 'title';
    }

    foreach ($resolved as $key => $entry) {
        try {
            $entry['field']->update($entry['sanitized']);
            $updated[] = $key;
        } catch (\Throwable $e) {
            // Validation passed, the write threw anyway: report exactly where
            // it stopped rather than pretending all-or-nothing survived.
            return nibwp_voxel_err(
                'voxel_write_failed',
                sprintf('Writing "%s" failed after %d field(s) were written: %s', $key, count($updated), $e->getMessage()),
                500,
                ['written_before_failure' => $updated]
            );
        }
    }

    return ['updated' => $updated, 'warnings' => $warnings];
}

/* ----------------------------------------------------------------------------
 * Schema writes — the one surface that can break a live site
 * ------------------------------------------------------------------------- */

const NIBWP_VOXEL_BACKUPS_OPTION = 'nibwp_voxel_schema_backups';

/**
 * Compute the effect of a patch without applying it.
 *
 * The config's `fields` is a LIST of entries each carrying a `key`; a patch
 * addresses fields by key. Everything else (`settings`, `search`, …) merges
 * shallowly per section. Removal is never implicit — only `remove_fields`
 * removes anything.
 *
 * @param array<string,mixed> $current
 * @param array<string,mixed> $patch
 * @param array<int,string> $remove_fields
 * @return array{config: array<string,mixed>, diff: array<string,mixed>}|WP_Error
 */
function nibwp_voxel_schema_apply_patch(array $current, array $patch, array $remove_fields = [])
{
    $next = $current;
    $diff = ['fields_added' => [], 'fields_changed' => [], 'fields_removed' => [], 'sections_changed' => []];

    // Fields: match by key.
    $by_key = [];
    foreach ((array) ($next['fields'] ?? []) as $i => $field) {
        if (is_array($field) && isset($field['key'])) {
            $by_key[(string) $field['key']] = $i;
        }
    }

    $registry = array_keys((array) \Voxel\config('post_types.field_types'));

    foreach ((array) ($patch['fields'] ?? []) as $field) {
        if (!is_array($field) || empty($field['key'])) {
            return nibwp_voxel_err('bad_field', 'Every patched field needs a "key".');
        }
        $key = (string) $field['key'];

        if (isset($by_key[$key])) {
            $merged = array_merge($next['fields'][$by_key[$key]], $field);
            if ($merged !== $next['fields'][$by_key[$key]]) {
                $next['fields'][$by_key[$key]] = $merged;
                $diff['fields_changed'][] = $key;
            }
            continue;
        }

        // A new field must name a type Voxel actually has — an unknown type
        // in the option breaks the post type editor, which is the definition
        // of bricking the site.
        $type = (string) ($field['type'] ?? '');
        if (!in_array($type, $registry, true)) {
            return nibwp_voxel_err('unknown_field_type', sprintf('Field "%s": type "%s" is not registered.', $key, $type), 400, [
                'valid_types' => $registry,
            ]);
        }
        if (empty($field['label'])) {
            $field['label'] = ucwords(str_replace(['-', '_'], ' ', $key));
        }
        $next['fields'][] = $field;
        $diff['fields_added'][] = $key;
    }

    foreach ($remove_fields as $key) {
        $key = (string) $key;
        foreach ((array) $next['fields'] as $i => $field) {
            if (is_array($field) && ($field['key'] ?? null) === $key) {
                unset($next['fields'][$i]);
                $diff['fields_removed'][] = $key;
            }
        }
    }
    $next['fields'] = array_values((array) $next['fields']);

    // Other sections: shallow merge per section. The post type's identity is
    // not patchable — renaming the key would orphan every post.
    foreach (['settings', 'search', 'templates', 'custom_templates', 'filters'] as $section) {
        if (!array_key_exists($section, $patch)) {
            continue;
        }
        $incoming = (array) $patch[$section];
        if ($section === 'settings') {
            unset($incoming['key']);
        }
        $merged = array_replace((array) ($next[$section] ?? []), $incoming);
        if ($merged !== (array) ($next[$section] ?? [])) {
            $next[$section] = $merged;
            $diff['sections_changed'][] = $section;
        }
    }

    return ['config' => $next, 'diff' => $diff];
}

/**
 * Keep the last five configs per post type, so a bad apply is one rollback
 * away instead of a support ticket.
 *
 * @param array<string,mixed> $config
 */
function nibwp_voxel_schema_backup(string $post_type, array $config): void
{
    $all = (array) get_option(NIBWP_VOXEL_BACKUPS_OPTION, []);
    $list = (array) ($all[$post_type] ?? []);
    array_unshift($list, ['at' => time(), 'config' => $config]);
    $all[$post_type] = array_slice($list, 0, 5);
    update_option(NIBWP_VOXEL_BACKUPS_OPTION, $all, false);
}

/**
 * Rebuild and refill the index after a schema change, in batches with a
 * cursor so a 10k-post site cannot blow the request time limit.
 *
 * @return array<string,mixed>
 */
function nibwp_voxel_schema_reindex(\Voxel\Post_Type $pt, int $offset = 0, int $batch = 100): array
{
    if ($offset === 0) {
        $pt->get_index_table()->recreate();
    }

    $ids = get_posts([
        'post_type' => $pt->get_key(),
        'post_status' => array_keys($pt->repository->get_indexable_statuses()),
        'fields' => 'ids',
        'posts_per_page' => $batch,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);

    if ($ids !== []) {
        $pt->get_index_table()->index(array_map('intval', $ids));
    }

    $done = count($ids) < $batch;

    return [
        'indexed' => count($ids),
        'next_offset' => $done ? null : $offset + $batch,
        'complete' => $done,
    ];
}

/**
 * Sideload a URL into the media library.
 *
 * @return int|WP_Error attachment ID
 */
function nibwp_voxel_sideload(string $url, int $post_id)
{
    // Deduplicate per request: a gallery that repeats a URL should not import
    // the same file twice.
    static $seen = [];
    if (isset($seen[$url])) {
        return $seen[$url];
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $id = media_sideload_image($url, $post_id, null, 'id');
    if (is_wp_error($id)) {
        return nibwp_voxel_err('voxel_sideload_failed', sprintf('Could not import %s: %s', $url, $id->get_error_message()), 502);
    }

    $seen[$url] = (int) $id;

    return (int) $id;
}

/**
 * Refresh Voxel's caches and search index after a write. Centralised so no
 * call site can forget — a listing that saves but does not index is invisible
 * to every search on the site, which reads as data loss.
 *
 * @return \Voxel\Post
 */
function nibwp_voxel_after_write(int $post_id): \Voxel\Post
{
    $post = \Voxel\Post::force_get($post_id);

    try {
        if ($post->should_index()) {
            $post->index();
        } else {
            $post->unindex();
        }
    } catch (\Throwable $e) {
        // Indexing failure must not undo a successful save; the caller sees it
        // in the snapshot (voxel-info reports index drift).
    }

    return $post;
}

/**
 * Create a listing: insert as draft, apply fields, then move to the requested
 * status only once everything validated — an invalid submission must never
 * yield a broken *published* post.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_create_listing(array $input)
{
    $pt = nibwp_voxel_post_type((string) ($input['post_type'] ?? ''));
    if (is_wp_error($pt)) {
        return $pt;
    }

    $status = (string) ($input['status'] ?? 'draft');
    if (!in_array($status, nibwp_voxel_writable_statuses(), true)) {
        return nibwp_voxel_err('bad_status', sprintf('Status must be one of: %s.', implode(', ', nibwp_voxel_writable_statuses())));
    }

    $values = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    $title = (string) ($values['title'] ?? '');
    if ($title === '') {
        return nibwp_voxel_err('no_title', 'Provide fields.title — a listing needs a name.');
    }

    $author = (int) ($input['author_id'] ?? get_current_user_id());

    $post_id = wp_insert_post([
        'post_type' => $pt->get_key(),
        'post_status' => 'draft',
        'post_title' => sanitize_text_field($title),
        'post_author' => $author > 0 ? $author : get_current_user_id(),
    ], true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    $post = \Voxel\Post::force_get((int) $post_id);
    if ($post === null) {
        return nibwp_voxel_err('voxel_internal_error', 'Voxel could not load the post it just created.', 500);
    }

    unset($values['title']); // already set at insert
    $applied = nibwp_voxel_apply_fields($post, $values);
    if (is_wp_error($applied)) {
        // The draft is kept on purpose: deleting it would be a hidden
        // destructive act, and the agent can repair it with a follow-up
        // update against this id.
        $applied->add_data(array_merge((array) $applied->get_error_data(), [
            'draft_post_id' => (int) $post_id,
            'note' => 'A draft was created and kept. Fix the fields with voxel-posts-write action=update on this id.',
        ]));
        return $applied;
    }

    if ($status !== 'draft') {
        wp_update_post(['ID' => (int) $post_id, 'post_status' => $status]);
    }

    $post = nibwp_voxel_after_write((int) $post_id);

    return [
        'created' => true,
        'item' => nibwp_voxel_post_snapshot($post),
        'updated_fields' => $applied['updated'],
        'warnings' => $applied['warnings'],
    ];
}

/**
 * Update fields and/or status on an existing listing.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_update_listing(array $input)
{
    $post = nibwp_voxel_post((int) ($input['post_id'] ?? 0));
    if (is_wp_error($post)) {
        return $post;
    }

    $applied = ['updated' => [], 'warnings' => []];
    $values = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    if ($values !== []) {
        $applied = nibwp_voxel_apply_fields($post, $values);
        if (is_wp_error($applied)) {
            return $applied;
        }
    }

    $status = (string) ($input['status'] ?? '');
    if ($status !== '') {
        if (!in_array($status, nibwp_voxel_writable_statuses(), true)) {
            return nibwp_voxel_err('bad_status', sprintf('Status must be one of: %s. Trash is nibwp/voxel-delete.', implode(', ', nibwp_voxel_writable_statuses())));
        }
        wp_update_post(['ID' => $post->get_id(), 'post_status' => $status]);
        $applied['updated'][] = 'status';
    }

    if ($applied['updated'] === []) {
        return nibwp_voxel_err('nothing_to_do', 'Provide "fields" and/or "status".');
    }

    $post = nibwp_voxel_after_write($post->get_id());

    return [
        'item' => nibwp_voxel_post_snapshot($post),
        'updated_fields' => $applied['updated'],
        'warnings' => $applied['warnings'],
    ];
}
