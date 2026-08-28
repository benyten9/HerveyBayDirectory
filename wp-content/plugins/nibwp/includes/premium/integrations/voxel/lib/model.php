<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel integration — read layer.
 *
 * Everything here turns Voxel's internal objects into plain arrays an agent
 * can act on. Two shapes matter most:
 *
 *   - the schema map: what fields a post type has, keyed and typed, because
 *     an agent cannot write a listing it cannot describe;
 *   - the post snapshot: every field value read back through Voxel's own
 *     get_value(), because reading raw meta lies for half the field types
 *     (work-hours, relations, taxonomies live elsewhere).
 */

/**
 * Agent-friendly schema for one post type: field keys, types, and the props
 * that change what a caller may send.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_voxel_schema_map(\Voxel\Post_Type $pt): array
{
    $out = [];

    foreach ($pt->get_fields() as $field) {
        $type = (string) $field->get_type();

        // UI-only rows (steps, headings, decorative html) take no value; an
        // agent offered these as writable keys will try to write them.
        if (str_starts_with($type, 'ui-')) {
            continue;
        }

        $entry = [
            'key' => (string) $field->get_key(),
            'type' => $type,
            'label' => (string) $field->get_label(),
            'required' => (bool) $field->is_required(),
        ];

        // Per-type extras: only what changes the caller's payload.
        switch ($type) {
            case 'select':
            case 'multiselect':
                $choices = $field->get_prop('choices');
                if (is_array($choices)) {
                    $entry['choices'] = array_keys($choices);
                }
                break;

            case 'taxonomy':
                $entry['taxonomy'] = (string) $field->get_prop('taxonomy');
                $entry['note'] = 'Send an array of term SLUGS.';
                break;

            case 'repeater':
                $rows = [];
                $subfields = $field->get_prop('fields');
                foreach (is_array($subfields) ? $subfields : [] as $sub) {
                    if (is_array($sub) && isset($sub['key'], $sub['type'])) {
                        $rows[] = ['key' => (string) $sub['key'], 'type' => (string) $sub['type']];
                    }
                }
                $entry['row_fields'] = $rows;
                $entry['note'] = 'Send an array of row objects keyed by row_fields keys.';
                break;

            case 'image':
            case 'file':
            case 'profile-avatar':
                $entry['note'] = 'Send attachment IDs, or https URLs to sideload.';
                $entry['max_count'] = $field->get_prop('max-count');
                break;

            case 'location':
                $entry['note'] = 'Send {address, latitude, longitude} (map_picker optional).';
                break;

            case 'post-relation':
                $entry['relation_post_types'] = (array) $field->get_prop('post_types');
                $entry['note'] = 'Send an array of post IDs.';
                break;

            case 'number':
                $entry['min'] = $field->get_prop('min');
                $entry['max'] = $field->get_prop('max');
                break;
        }

        $out[] = $entry;
    }

    return $out;
}

/**
 * One listing, every field read through Voxel itself.
 *
 * @return array<string,mixed>
 */
function nibwp_voxel_post_snapshot(\Voxel\Post $post, bool $with_fields = true): array
{
    $out = [
        'id' => $post->get_id(),
        'post_type' => (string) $post->post_type->get_key(),
        'title' => (string) $post->get_title(),
        'slug' => (string) $post->get_slug(),
        'status' => (string) $post->get_status(),
        'link' => (string) $post->get_link(),
        'author_id' => $post->get_author_id(),
        'is_verified' => (bool) get_post_meta($post->get_id(), 'voxel:verified', true),
    ];

    if (!$with_fields) {
        return $out;
    }

    $fields = [];
    foreach ($post->get_fields() as $field) {
        $type = (string) $field->get_type();
        if (str_starts_with($type, 'ui-')) {
            continue;
        }

        // One broken field must not take the whole snapshot down — a listing
        // with a malformed meta row is exactly the listing someone will ask
        // an agent to look at.
        try {
            $value = $field->get_value();

            // Taxonomy fields return \Voxel\Term objects, which JSON-encode to
            // nothing useful. Slugs are what the write side accepts, so the
            // read side speaks the same language.
            if ($type === 'taxonomy' && is_array($value)) {
                $value = array_values(array_map(static fn ($term) => is_object($term) ? [
                    'slug' => (string) $term->get_slug(),
                    'label' => (string) $term->get_label(),
                ] : $term, $value));
            }

            $fields[(string) $field->get_key()] = $value;
        } catch (\Throwable $e) {
            $fields[(string) $field->get_key()] = ['__error' => $e->getMessage()];
        }
    }
    $out['fields'] = $fields;

    return $out;
}

/**
 * Search through Voxel's own index tables.
 *
 * Filters are passed through by their Voxel filter key, so whatever the site
 * has configured (keywords, location radius, price, availability, terms) is
 * usable without this file knowing the list.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_search(array $input)
{
    $pt = nibwp_voxel_post_type((string) ($input['post_type'] ?? ''));
    if (is_wp_error($pt)) {
        return $pt;
    }

    [$per, $page] = nibwp_voxel_paginate($input);

    $args = is_array($input['filters'] ?? null) ? $input['filters'] : [];
    $args['limit'] = $per;
    $args['offset'] = ($page - 1) * $per;

    return nibwp_voxel_try(static function () use ($pt, $args, $per, $page, $input) {
        // query() returns post IDs from the index table, not Post objects.
        $ids = $pt->query($args);

        $items = [];
        foreach ($ids as $id) {
            $post = \Voxel\Post::get(is_object($id) ? $id->get_id() : (int) $id);
            if ($post !== null) {
                $items[] = nibwp_voxel_post_snapshot($post, !empty($input['with_fields']));
            }
        }

        // Which filters exist, so a caller that guessed a wrong key can see
        // the vocabulary without another round trip.
        $filters = [];
        foreach ($pt->get_filters() as $filter) {
            $filters[] = ['key' => (string) $filter->get_key(), 'type' => (string) $filter->get_type()];
        }

        return [
            'items' => $items,
            'count' => count($items),
            'page' => $page,
            'per_page' => $per,
            'available_filters' => $filters,
        ];
    });
}

/**
 * One listing by ID, checked to belong to a Voxel post type.
 *
 * @return \Voxel\Post|WP_Error
 */
function nibwp_voxel_post(int $post_id)
{
    if ($post_id <= 0) {
        return nibwp_voxel_err('no_post_id', 'Provide a numeric "post_id".');
    }

    $post = nibwp_voxel_try(static fn () => \Voxel\Post::get($post_id));
    if (is_wp_error($post)) {
        return $post;
    }

    if ($post === null || !$post->is_managed_by_voxel()) {
        return nibwp_voxel_err(
            'not_voxel_post',
            sprintf('Post %d does not exist or is not managed by Voxel. Use the ordinary WordPress abilities for non-Voxel content.', $post_id),
            404
        );
    }

    return $post;
}
