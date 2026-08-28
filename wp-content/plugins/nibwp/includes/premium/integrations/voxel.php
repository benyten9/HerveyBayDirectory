<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel theme integration.
 *
 * Voxel (voxel theme, \Voxel\ namespace) is a directory/listing theme that
 * keeps its whole world outside the WordPress surface an AI client can see:
 * post types live in the voxel:post_types option, field values in typed meta
 * plus five custom tables, orders and timeline and messages in eleven more —
 * and it exposes ZERO REST routes. Its CPTs are not even in the WP REST API
 * unless a per-type Gutenberg switch is on. Without this file, an assistant
 * on a Voxel site can edit vanilla posts and nothing that makes the site a
 * Voxel site.
 *
 * Everything here calls Voxel's own PHP internals (\Voxel\Post, field
 * sanitize→validate→update, \Voxel\Order, Timeline\Status, Chat/Message) —
 * never raw meta writes, because half of Voxel's field types keep their truth
 * somewhere other than the meta value (work-hours and relations in tables,
 * taxonomies in terms, title in wp_posts).
 *
 * Storage: options voxel:{post_types,taxonomies,product_types,plans,...} (JSON,
 * via \Voxel\get/set) · tables {p}vx_orders, {p}vx_order_items,
 * {p}voxel_timeline*, {p}voxel_messages, {p}voxel_followers,
 * {p}voxel_index_{type} (per-CPT search index), {p}voxel_work_hours,
 * {p}voxel_relations, {p}voxel_recurring_dates.
 *
 * Detection: \Voxel\Post class, or active (parent) theme template 'voxel'.
 * Verified against Voxel 1.7.8.6.
 */

require_once __DIR__ . '/voxel/lib/config.php';
require_once __DIR__ . '/voxel/lib/model.php';
require_once __DIR__ . '/voxel/lib/writer.php';
require_once __DIR__ . '/voxel/lib/social.php';

/* ----------------------------------------------------------------------------
 * Registrar
 * ------------------------------------------------------------------------- */

/**
 * Register one voxel ability with the house defaults.
 *
 * @param array<string,mixed> $args
 */
function nibwp_voxel_register(string $slug, array $args): void
{
    $defaults = [
        'category' => 'voxel',
        'output_schema' => ['type' => 'object'],
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
                'idempotent' => false,
            ],
        ],
    ];

    $args = array_replace_recursive($defaults, $args);

    wp_register_ability('nibwp/' . $slug, $args);
}

/* ----------------------------------------------------------------------------
 * voxel-info — the self-index every session starts from
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-info', [
    'label' => __('Voxel — Info', 'nibwp'),
    'description' => __(
        'Discover what this Voxel site is made of: theme version, which modules are on (timeline, direct messages, paid memberships, paid listings, product types), every Voxel post type with its field count and index health, taxonomies, and which nibwp/voxel-* abilities to use for what. Call this FIRST — Voxel sites differ per install, and every other voxel ability depends on keys this returns.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => new stdClass(),
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_info_execute',
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_info_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    return nibwp_voxel_try(static function () {
        global $wpdb;

        $theme = wp_get_theme('voxel');

        $post_types = [];
        foreach (\Voxel\Post_Type::get_voxel_types() as $pt) {
            $key = $pt->get_key();

            // Index health: a row-count that disagrees with the post count is
            // how "search finds nothing" looks from the inside.
            $index_rows = null;
            $table = $wpdb->prefix . 'voxel_index_' . $key;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $index_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL -- table name built from prefix + validated key
            }

            $post_types[] = [
                'key' => $key,
                'label' => $pt->get_label(),
                'field_count' => count($pt->get_fields()),
                'published_posts' => (int) wp_count_posts($key)->publish,
                'index_rows' => $index_rows,
                'submissions_enabled' => $pt->get_setting('submissions.enabled'),
            ];
        }

        $taxonomies = [];
        foreach (\Voxel\Taxonomy::get_voxel_taxonomies() as $tax) {
            $taxonomies[] = [
                'key' => $tax->get_key(),
                'label' => $tax->get_label(),
                'post_types' => (array) $tax->get_post_types(),
            ];
        }

        return [
            'voxel_version' => (string) $theme->get('Version'),
            'is_child_theme' => wp_get_theme()->get_stylesheet() !== 'voxel',
            'modules' => [
                'timeline' => (bool) \Voxel\get('settings.timeline.enabled', true),
                'direct_messages' => (bool) \Voxel\get('settings.addons.direct_messages.enabled', false),
                'paid_memberships' => (bool) \Voxel\get('settings.addons.paid_memberships.enabled', false),
                'paid_listings' => (bool) \Voxel\get('paid_listings.settings.enabled', false),
                'product_types' => array_keys((array) \Voxel\get('product_types', [])),
                'payment_provider' => (string) \Voxel\get('payments.provider', ''),
            ],
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
            'abilities' => [
                'voxel-post-types' => 'Read a post type schema before writing anything — field keys, types, required flags.',
                'voxel-posts' => 'Search (geo/price/availability via Voxel\'s own index) and read listings.',
                'voxel-posts-write' => 'Create and update listings with typed Voxel fields.',
                'voxel-terms' => 'Taxonomy terms: list, create, update, reorder.',
                'voxel-orders' => 'Orders and bookings: list, inspect, change status.',
                'voxel-plans' => 'Membership plans, user memberships, paid-listing packages.',
                'voxel-timeline' => 'Timeline statuses, replies, likes, follows.',
                'voxel-messages' => 'Direct messages and notifications.',
                'voxel-templates' => 'Voxel template map: list, create, assign (Elementor edits its content).',
                'voxel-render' => 'VoxelScript dynamic tags: catalog and render.',
                'voxel-schema' => 'Change post type / taxonomy definitions. Destructive scope; preview first.',
                'voxel-delete' => 'Every delete lives here, and only here. Destructive scope.',
            ],
        ];
    });
}

/* ----------------------------------------------------------------------------
 * voxel-post-types — the schema, read
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-post-types', [
    'label' => __('Voxel — Post Types (read)', 'nibwp'),
    'description' => __(
        'Read Voxel post type definitions. Actions: "list" (all Voxel types with labels and counts), "fields" (the writable field map for one type — key, type, required, choices, notes on what shape to send; READ THIS before voxel-posts-write), "schema" (the full raw editor config for one type), "templates" (template IDs for one type). Changing definitions is nibwp/voxel-schema, not this.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'fields', 'schema', 'templates'], 'description' => 'What to read.'],
            'post_type' => ['type' => 'string', 'description' => 'Post type key (required for everything except "list").'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_post_types_execute',
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_post_types_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'list') {
        return nibwp_voxel_try(static function () {
            $items = [];
            foreach (\Voxel\Post_Type::get_voxel_types() as $pt) {
                $counts = wp_count_posts($pt->get_key());
                $items[] = [
                    'key' => $pt->get_key(),
                    'label' => $pt->get_label(),
                    'field_count' => count($pt->get_fields()),
                    'published' => (int) ($counts->publish ?? 0),
                    'drafts' => (int) ($counts->draft ?? 0),
                ];
            }
            return ['post_types' => $items];
        });
    }

    $pt = nibwp_voxel_post_type((string) ($input['post_type'] ?? ''));
    if (is_wp_error($pt)) {
        return $pt;
    }

    return match ($action) {
        'fields' => nibwp_voxel_try(static fn () => [
            'post_type' => $pt->get_key(),
            'fields' => nibwp_voxel_schema_map($pt),
        ]),
        'schema' => nibwp_voxel_try(static fn () => [
            'post_type' => $pt->get_key(),
            'config' => $pt->repository->get_config(),
        ]),
        'templates' => nibwp_voxel_try(static fn () => [
            'post_type' => $pt->get_key(),
            'templates' => $pt->get_templates(),
        ]),
        default => nibwp_voxel_err('bad_action', 'Unknown action. Use list, fields, schema, or templates.'),
    };
}

/* ----------------------------------------------------------------------------
 * voxel-posts — listings, read, through Voxel's index
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-posts', [
    'label' => __('Voxel — Listings (read)', 'nibwp'),
    'description' => __(
        'Read Voxel listings. Actions: "search" (through Voxel\'s own index tables — supports the site\'s configured filters incl. keywords, location radius, price, availability; pass them in "filters" keyed by filter key; the response lists available_filters), "get" (one listing with every field value read through Voxel itself — the only reliable way, since work hours, relations and taxonomies do not live in plain meta).',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['search', 'get'], 'description' => 'What to read.'],
            'post_type' => ['type' => 'string', 'description' => 'Post type key (required for "search").'],
            'post_id' => ['type' => 'integer', 'description' => 'Listing ID (required for "get").'],
            'filters' => ['type' => 'object', 'description' => 'search: Voxel filter values keyed by filter key.'],
            'with_fields' => ['type' => 'boolean', 'default' => false, 'description' => 'search: include full field values per result (slower).'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_posts_execute',
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_posts_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    switch ((string) ($input['action'] ?? '')) {
        case 'search':
            return nibwp_voxel_search($input);

        case 'get':
            $post = nibwp_voxel_post((int) ($input['post_id'] ?? 0));
            return is_wp_error($post) ? $post : nibwp_voxel_try(
                static fn () => ['item' => nibwp_voxel_post_snapshot($post)]
            );

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action. Use search or get.');
    }
}

/* ----------------------------------------------------------------------------
 * voxel-render — VoxelScript dynamic tags
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-render', [
    'label' => __('Voxel — Dynamic Tags', 'nibwp'),
    'description' => __(
        'VoxelScript dynamic tags (the @post(:title).date_format(F j) syntax used across Voxel templates and notifications). Actions: "catalog" (every tag group, property and modifier this site exposes — the vocabulary), "render" (evaluate a VoxelScript string, optionally against a post via post_id, and return the output — use to verify an expression before putting it in a template or email).',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['catalog', 'render']],
            'content' => ['type' => 'string', 'description' => 'render: the VoxelScript string, e.g. "@post(:title) — @post(location.address)".'],
            'post_id' => ['type' => 'integer', 'description' => 'render: post to evaluate @post() against.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_render_execute',
    'meta' => [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_render_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    switch ((string) ($input['action'] ?? '')) {
        case 'catalog':
            return nibwp_voxel_try(static function () {
                $exporter = \Voxel\Dynamic_Data\Exporter::get();
                $exporter->add_group_by_key('post');
                $exporter->add_group_by_key('user');
                $exporter->add_group_by_key('site');
                return ['catalog' => $exporter->export()];
            });

        case 'render':
            $content = (string) ($input['content'] ?? '');
            if ($content === '') {
                return nibwp_voxel_err('no_content', 'Provide "content" — a VoxelScript string.');
            }

            $groups = null;
            if (!empty($input['post_id'])) {
                $post = nibwp_voxel_post((int) $input['post_id']);
                if (is_wp_error($post)) {
                    return $post;
                }
                $groups = ['post' => \Voxel\Dynamic_Data\Group::Post($post)];
            }

            return nibwp_voxel_try(static fn () => [
                'input' => $content,
                'output' => \Voxel\render($content, $groups),
            ]);

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action. Use catalog or render.');
    }
}

/* ----------------------------------------------------------------------------
 * voxel-posts-write — create and update listings, through the field engine
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-posts-write', [
    'label' => __('Voxel — Listings (write)', 'nibwp'),
    'description' => __(
        'Create and update Voxel listings with typed fields. Read nibwp/voxel-post-types action=fields FIRST — it tells you every writable key and the shape each takes (taxonomies want term slugs, images take IDs or https URLs to import, location wants {address, latitude, longitude}). Actions: "create" (post_type + fields, status defaults to draft; on validation failure the draft is KEPT and its id returned so you can repair it with update), "update" (post_id + fields and/or status). All-or-nothing: if any field fails validation, nothing is written. Values run through Voxel\'s own sanitize→validate→update, and the search index is refreshed. Frontend visibility/conditional rules are not applied — this writes at admin level. Trash and delete live in nibwp/voxel-delete.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['create', 'update']],
            'post_type' => ['type' => 'string', 'description' => 'create: Voxel post type key.'],
            'post_id' => ['type' => 'integer', 'description' => 'update: listing ID.'],
            'fields' => ['type' => 'object', 'description' => 'Field values keyed by field key. Include "title" on create.'],
            'status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'publish', 'private', 'unpublished', 'rejected', 'expired'], 'description' => 'Target status. create defaults to draft.'],
            'author_id' => ['type' => 'integer', 'description' => 'create: author user ID (defaults to the connected user).'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_posts_write_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_posts_write_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    return match ((string) ($input['action'] ?? '')) {
        'create' => nibwp_voxel_create_listing($input),
        'update' => nibwp_voxel_update_listing($input),
        default => nibwp_voxel_err('bad_action', 'Unknown action. Use create or update.'),
    };
}

/* ----------------------------------------------------------------------------
 * voxel-terms — taxonomy content (definitions live in voxel-schema)
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-terms', [
    'label' => __('Voxel — Terms', 'nibwp'),
    'description' => __(
        'Taxonomy terms for Voxel taxonomies. Actions: "list" (terms of one taxonomy, in Voxel\'s display order), "create" (name + optional slug/parent/description), "update" (rename, move, describe), "reorder" (set the display order Voxel\'s filters use — pass term_ids in the desired order). Taxonomy DEFINITIONS are nibwp/voxel-schema; deleting a term is nibwp/voxel-delete.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'reorder']],
            'taxonomy' => ['type' => 'string', 'description' => 'Voxel taxonomy key. voxel-info lists them.'],
            'term_id' => ['type' => 'integer', 'description' => 'update: the term.'],
            'name' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'parent' => ['type' => 'integer', 'description' => 'Parent term ID, 0 for top level.'],
            'description' => ['type' => 'string'],
            'term_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'reorder: every term of the taxonomy, in display order.'],
        ],
        'required' => ['action', 'taxonomy'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_terms_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_terms_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    $taxonomy = (string) ($input['taxonomy'] ?? '');
    $vox_tax = nibwp_voxel_try(static fn () => \Voxel\Taxonomy::get($taxonomy));
    if (is_wp_error($vox_tax)) {
        return $vox_tax;
    }
    if ($vox_tax === null) {
        $valid = nibwp_voxel_try(static fn () => array_map(static fn ($t) => $t->get_key(), \Voxel\Taxonomy::get_voxel_taxonomies()));
        return nibwp_voxel_err('unknown_taxonomy', sprintf('"%s" is not a Voxel taxonomy.', $taxonomy), 404, [
            'valid_taxonomies' => is_wp_error($valid) ? [] : array_values($valid),
        ]);
    }

    global $wpdb;

    switch ((string) ($input['action'] ?? '')) {
        case 'list':
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (is_wp_error($terms)) {
                return $terms;
            }
            usort($terms, static fn ($a, $b) => ((int) ($a->voxel_order ?? 0)) <=> ((int) ($b->voxel_order ?? 0)));
            return ['terms' => array_map(static fn ($t) => [
                'id' => $t->term_id,
                'name' => $t->name,
                'slug' => $t->slug,
                'parent' => $t->parent,
                'count' => $t->count,
                'order' => (int) ($t->voxel_order ?? 0),
            ], $terms)];

        case 'create':
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                return nibwp_voxel_err('no_name', 'Provide "name".');
            }
            $args = array_filter([
                'slug' => (string) ($input['slug'] ?? ''),
                'description' => (string) ($input['description'] ?? ''),
                'parent' => (int) ($input['parent'] ?? 0),
            ]);
            $res = wp_insert_term($name, $taxonomy, $args);
            if (is_wp_error($res)) {
                return $res;
            }
            $term = get_term((int) $res['term_id'], $taxonomy);
            return ['created' => true, 'term' => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug]];

        case 'update':
            $term_id = (int) ($input['term_id'] ?? 0);
            if ($term_id <= 0 || !get_term($term_id, $taxonomy) instanceof WP_Term) {
                return nibwp_voxel_err('no_term', 'Provide a valid "term_id" belonging to this taxonomy.', 404);
            }
            $args = [];
            foreach (['name', 'slug', 'description'] as $k) {
                if (isset($input[$k])) {
                    $args[$k] = (string) $input[$k];
                }
            }
            if (isset($input['parent'])) {
                $args['parent'] = (int) $input['parent'];
            }
            if ($args === []) {
                return nibwp_voxel_err('nothing_to_do', 'Provide name, slug, description, or parent.');
            }
            $res = wp_update_term($term_id, $taxonomy, $args);
            if (is_wp_error($res)) {
                return $res;
            }
            $term = get_term($term_id, $taxonomy);
            return ['updated' => true, 'term' => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent]];

        case 'reorder':
            $ids = array_map('intval', (array) ($input['term_ids'] ?? []));
            if ($ids === []) {
                return nibwp_voxel_err('no_ids', 'Provide "term_ids" in the desired display order.');
            }
            // Voxel keeps display order in a voxel_order column it adds to
            // wp_terms; its own admin writes it the same way.
            foreach ($ids as $position => $tid) {
                $wpdb->update($wpdb->terms, ['voxel_order' => $position], ['term_id' => $tid], ['%d'], ['%d']);
            }
            clean_taxonomy_cache($taxonomy);
            return ['reordered' => true, 'count' => count($ids)];

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action. Use list, create, update, or reorder.');
    }
}

/* ----------------------------------------------------------------------------
 * voxel-delete — every destructive act, in one place, behind the danger scope
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-delete', [
    'label' => __('Voxel — Delete', 'nibwp'),
    'description' => __(
        'Every Voxel delete lives here, and only here, so a default read+write connection cannot perform any of them. Actions: "post-trash" (reversible), "post-restore", "post-delete" (permanent — prefer trash), "term-delete", "plan-delete" (removes a membership plan definition; members keep their meta but the plan stops resolving), "status-delete" (a timeline post), "reply-delete" (a timeline comment), "template-delete" (trashes the Elementor template post). Deletions other than trash are not undoable.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['post-trash', 'post-restore', 'post-delete', 'term-delete', 'plan-delete', 'status-delete', 'reply-delete', 'template-delete']],
            'post_id' => ['type' => 'integer', 'description' => 'post-* / template-delete: the post.'],
            'term_id' => ['type' => 'integer', 'description' => 'term-delete.'],
            'taxonomy' => ['type' => 'string', 'description' => 'term-delete.'],
            'plan_key' => ['type' => 'string', 'description' => 'plan-delete.'],
            'status_id' => ['type' => 'integer', 'description' => 'status-delete.'],
            'reply_id' => ['type' => 'integer', 'description' => 'reply-delete.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_delete_execute',
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_delete_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'post-trash':
        case 'post-restore':
        case 'post-delete':
            $post = nibwp_voxel_post((int) ($input['post_id'] ?? 0));
            if (is_wp_error($post)) {
                return $post;
            }
            $id = $post->get_id();

            if ($action === 'post-restore') {
                $res = wp_untrash_post($id);
                if (!$res) {
                    return nibwp_voxel_err('restore_failed', 'WordPress refused to restore the post.', 500);
                }
                nibwp_voxel_after_write($id);
                return ['restored' => true, 'post_id' => $id, 'status' => get_post_status($id)];
            }

            // Out of the index first: a trashed listing that still answers
            // searches looks like data corruption from the front end.
            nibwp_voxel_try(static fn () => $post->unindex());

            if ($action === 'post-trash') {
                $res = wp_trash_post($id);
                return $res
                    ? ['trashed' => true, 'post_id' => $id, 'note' => 'Reversible with post-restore.']
                    : nibwp_voxel_err('trash_failed', 'WordPress refused to trash the post.', 500);
            }

            $res = wp_delete_post($id, true);
            return $res
                ? ['deleted' => true, 'post_id' => $id]
                : nibwp_voxel_err('delete_failed', 'WordPress refused to delete the post.', 500);

        case 'term-delete':
            $taxonomy = (string) ($input['taxonomy'] ?? '');
            $term_id = (int) ($input['term_id'] ?? 0);
            if ($taxonomy === '' || $term_id <= 0) {
                return nibwp_voxel_err('no_term', 'Provide "taxonomy" and "term_id".');
            }
            $res = wp_delete_term($term_id, $taxonomy);
            if (is_wp_error($res)) {
                return $res;
            }
            return $res === true
                ? ['deleted' => true, 'term_id' => $term_id]
                : nibwp_voxel_err('term_delete_failed', 'Term not found, or it is the default term.', 404);

        case 'plan-delete':
            $key = (string) ($input['plan_key'] ?? '');
            if ($key === '' || $key === 'default') {
                return nibwp_voxel_err('bad_plan', 'Provide "plan_key" (the default plan cannot be deleted).');
            }
            return nibwp_voxel_try(static function () use ($key) {
                if (!class_exists('\\Voxel\\Plan') || \Voxel\Plan::get($key) === null) {
                    return nibwp_voxel_err('unknown_plan', sprintf('No membership plan "%s".', $key), 404);
                }
                // Voxel's own backend deletes a plan exactly this way: unset
                // from the voxel:plans option.
                $plans = (array) \Voxel\get('plans');
                unset($plans[$key]);
                \Voxel\set('plans', $plans);
                return ['deleted' => true, 'plan_key' => $key];
            });

        case 'status-delete':
        case 'reply-delete':
            $cls = $action === 'status-delete' ? '\\Voxel\\Timeline\\Status' : '\\Voxel\\Timeline\\Reply';
            $id = (int) ($input[$action === 'status-delete' ? 'status_id' : 'reply_id'] ?? 0);
            if ($id <= 0) {
                return nibwp_voxel_err('no_id', 'Provide the numeric id.');
            }
            return nibwp_voxel_try(static function () use ($cls, $id) {
                $item = $cls::get($id);
                if ($item === null) {
                    return nibwp_voxel_err('not_found', 'No timeline item with that id.', 404);
                }
                $item->delete();
                return ['deleted' => true, 'id' => $id];
            });

        case 'template-delete':
            $id = (int) ($input['post_id'] ?? 0);
            $post = get_post($id);
            if (!$post || $post->post_type !== 'elementor_library') {
                return nibwp_voxel_err('not_template', 'post_id is not an Elementor template.', 404);
            }
            return wp_trash_post($id)
                ? ['trashed' => true, 'post_id' => $id, 'note' => 'Template trashed, not erased. Template assignments pointing at it will fall back.']
                : nibwp_voxel_err('trash_failed', 'WordPress refused to trash the template.', 500);

        default:
            return nibwp_voxel_err('bad_action', 'Unknown delete action.');
    }
}

/* ----------------------------------------------------------------------------
 * voxel-schema — post type and taxonomy definitions
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-schema', [
    'label' => __('Voxel — Schema (write)', 'nibwp'),
    'description' => __(
        'Change Voxel post type and taxonomy DEFINITIONS — the site\'s data model, not its content. Always run "preview" first: it returns the exact diff without writing. Actions: "preview" (patch + nothing written), "patch" (merge changes: fields addressed by key, new fields need a registered type, removal only via remove_fields; requires confirm:true), "rollback" (restore one of the last 5 automatic backups), "create-taxonomy" / "delete-taxonomy", "reindex" (rebuild the search index in batches — returns next_offset until complete; run after any field change). Every apply backs up the previous config first. Changing schema on a live site affects every existing listing of that type.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['preview', 'patch', 'rollback', 'create-taxonomy', 'delete-taxonomy', 'reindex']],
            'post_type' => ['type' => 'string', 'description' => 'The Voxel post type (preview/patch/rollback/reindex).'],
            'patch' => ['type' => 'object', 'description' => 'Sections to merge: fields (list, matched by key), settings, search, templates. settings.key is never patchable.'],
            'remove_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Field keys to remove. Removal never happens implicitly.'],
            'confirm' => ['type' => 'boolean', 'description' => 'patch/delete-taxonomy: must be true. Preview first.'],
            'backup_index' => ['type' => 'integer', 'description' => 'rollback: which backup (0 = newest). Omit to list them.'],
            'taxonomy' => ['type' => 'string', 'description' => 'create/delete-taxonomy: key.'],
            'label' => ['type' => 'string', 'description' => 'create-taxonomy: plural label.'],
            'singular' => ['type' => 'string', 'description' => 'create-taxonomy: singular label.'],
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'create-taxonomy: which post types it attaches to.'],
            'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'reindex: cursor from the previous call.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_schema_execute',
    'meta' => [
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_schema_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    $action = (string) ($input['action'] ?? '');

    // Taxonomy definition arms need no post type.
    if ($action === 'create-taxonomy') {
        $key = sanitize_key((string) ($input['taxonomy'] ?? ''));
        $label = (string) ($input['label'] ?? '');
        $types = array_map('strval', (array) ($input['post_types'] ?? []));
        if ($key === '' || $label === '' || $types === []) {
            return nibwp_voxel_err('missing_args', 'Provide taxonomy (key), label, and post_types.');
        }
        return nibwp_voxel_try(static function () use ($key, $label, $types, $input) {
            if (\Voxel\Taxonomy::get($key) !== null || taxonomy_exists($key)) {
                return nibwp_voxel_err('taxonomy_exists', sprintf('Taxonomy "%s" already exists.', $key), 409);
            }
            $taxonomies = (array) \Voxel\get('taxonomies');
            $taxonomies[$key] = [
                'settings' => [
                    'key' => $key,
                    'plural' => $label,
                    'singular' => (string) ($input['singular'] ?? $label),
                    'post_types' => $types,
                ],
            ];
            \Voxel\set('taxonomies', $taxonomies);
            return ['created' => true, 'taxonomy' => $key, 'note' => 'Registered on the next request.'];
        });
    }

    if ($action === 'delete-taxonomy') {
        if (empty($input['confirm'])) {
            return nibwp_voxel_err('confirm_required', 'Deleting a taxonomy definition needs confirm:true. Its terms stay in the database but stop being reachable.');
        }
        $key = (string) ($input['taxonomy'] ?? '');
        return nibwp_voxel_try(static function () use ($key) {
            $tax = \Voxel\Taxonomy::get($key);
            if ($tax === null || !$tax->is_managed_by_voxel()) {
                return nibwp_voxel_err('unknown_taxonomy', sprintf('"%s" is not a Voxel-managed taxonomy.', $key), 404);
            }
            $taxonomies = (array) \Voxel\get('taxonomies');
            unset($taxonomies[$key]);
            \Voxel\set('taxonomies', $taxonomies);
            return ['deleted' => true, 'taxonomy' => $key];
        });
    }

    // Everything else operates on one post type.
    $pt = nibwp_voxel_post_type((string) ($input['post_type'] ?? ''));
    if (is_wp_error($pt)) {
        return $pt;
    }

    switch ($action) {
        case 'preview':
        case 'patch':
            $current = nibwp_voxel_try(static fn () => $pt->repository->get_config());
            if (is_wp_error($current)) {
                return $current;
            }

            $result = nibwp_voxel_schema_apply_patch(
                (array) $current,
                is_array($input['patch'] ?? null) ? $input['patch'] : [],
                array_map('strval', (array) ($input['remove_fields'] ?? []))
            );
            if (is_wp_error($result)) {
                return $result;
            }

            $changed = $result['diff']['fields_added'] !== []
                || $result['diff']['fields_changed'] !== []
                || $result['diff']['fields_removed'] !== []
                || $result['diff']['sections_changed'] !== [];

            if ($action === 'preview') {
                return ['diff' => $result['diff'], 'would_change' => $changed];
            }

            if (empty($input['confirm'])) {
                return nibwp_voxel_err('confirm_required', 'Schema writes need confirm:true. Run action=preview first and show the user the diff.', 400, [
                    'diff' => $result['diff'],
                ]);
            }
            if (!$changed) {
                return ['applied' => false, 'note' => 'The patch changes nothing.'];
            }

            nibwp_voxel_schema_backup($pt->get_key(), (array) $current);

            $apply = nibwp_voxel_try(static function () use ($pt, $result) {
                $pt->repository->set_config($result['config']);
                \Voxel\Post_Type::force_get($pt->get_key());
                return true;
            });
            if (is_wp_error($apply)) {
                return $apply;
            }

            return [
                'applied' => true,
                'diff' => $result['diff'],
                'note' => ($result['diff']['fields_added'] !== [] || $result['diff']['fields_removed'] !== [])
                    ? 'Field set changed — run action=reindex now, or search will not see the change.'
                    : 'Applied.',
            ];

        case 'rollback':
            $all = (array) get_option(NIBWP_VOXEL_BACKUPS_OPTION, []);
            $list = (array) ($all[$pt->get_key()] ?? []);
            if ($list === []) {
                return nibwp_voxel_err('no_backups', 'No schema backups exist for this post type yet.', 404);
            }
            if (!isset($input['backup_index'])) {
                return ['backups' => array_map(static fn ($b, $i) => [
                    'index' => $i,
                    'saved_at' => gmdate('Y-m-d H:i:s', (int) $b['at']),
                    'field_count' => count((array) ($b['config']['fields'] ?? [])),
                ], $list, array_keys($list))];
            }
            $idx = (int) $input['backup_index'];
            if (!isset($list[$idx])) {
                return nibwp_voxel_err('bad_backup', sprintf('No backup at index %d (0-%d exist).', $idx, count($list) - 1), 404);
            }
            $restore = nibwp_voxel_try(static function () use ($pt, $list, $idx) {
                nibwp_voxel_schema_backup($pt->get_key(), (array) $pt->repository->get_config());
                $pt->repository->set_config((array) $list[$idx]['config']);
                \Voxel\Post_Type::force_get($pt->get_key());
                return true;
            });
            return is_wp_error($restore) ? $restore : [
                'restored' => true,
                'from' => gmdate('Y-m-d H:i:s', (int) $list[$idx]['at']),
                'note' => 'Run action=reindex if the field set differed.',
            ];

        case 'reindex':
            return nibwp_voxel_try(static fn () => nibwp_voxel_schema_reindex($pt, max(0, (int) ($input['offset'] ?? 0))));

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action.');
    }
}

/* ----------------------------------------------------------------------------
 * voxel-orders — orders and bookings
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-orders', [
    'label' => __('Voxel — Orders', 'nibwp'),
    'description' => __(
        'Voxel orders and bookings (bookings are order items). Actions: "list" (filter by status, customer_id, vendor_id, product_type, search), "get" (one order with items and details), "set-status" (updates the ORDER RECORD only — it does not refund or capture at the payment provider; say so to the user), "actions" (what Voxel would offer the current user on this order).',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'get', 'set-status', 'actions']],
            'order_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'description' => 'set-status / list filter. list response names the valid ones.'],
            'shipping_status' => ['type' => 'string', 'description' => 'list filter.'],
            'customer_id' => ['type' => 'integer', 'description' => 'list filter.'],
            'vendor_id' => ['type' => 'integer', 'description' => 'list filter.'],
            'product_type' => ['type' => 'string', 'description' => 'list filter.'],
            'search' => ['type' => 'string', 'description' => 'list filter.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_orders_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_orders_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    return nibwp_voxel_orders_dispatch($input);
}

/* ----------------------------------------------------------------------------
 * voxel-plans — memberships and paid listings
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-plans', [
    'label' => __('Voxel — Plans & Memberships', 'nibwp'),
    'description' => __(
        'Membership plans and paid-listing packages. Actions: "list-plans" / "get-plan" (plan definitions from the paid-memberships module), "create-plan" / "update-plan" (plan_key + plan object; pricing wired to the payment provider still needs prices created there), "get-membership" (a user\'s current plan and state), "list-listing-plans" (paid-listings module). Deleting a plan is nibwp/voxel-delete. Modules that are switched off answer with a clear 404 naming the switch.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list-plans', 'get-plan', 'create-plan', 'update-plan', 'get-membership', 'list-listing-plans']],
            'plan_key' => ['type' => 'string'],
            'plan' => ['type' => 'object', 'description' => 'create/update: {label, description, pricing, submissions, ...}.'],
            'user_id' => ['type' => 'integer', 'description' => 'get-membership: defaults to the connected user.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_plans_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_plans_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    return nibwp_voxel_plans_dispatch($input);
}

/* ----------------------------------------------------------------------------
 * voxel-timeline — statuses, replies, follows
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-timeline', [
    'label' => __('Voxel — Timeline', 'nibwp'),
    'description' => __(
        'Voxel timeline: statuses, reviews, wall posts, replies and follows. Actions: "list" (filter by feed — user_timeline, post_timeline, post_reviews, post_wall — moderation state, or search), "get", "create" (content, optional post_id and review_score), "update", "approve" (clear moderation), "reply", "follow" / "unfollow" (object_type post|user + object_id). Posts published here are attributed to the connected user unless user_id says otherwise — say whose voice you are writing in. Deleting is nibwp/voxel-delete.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'approve', 'reply', 'follow', 'unfollow']],
            'status_id' => ['type' => 'integer'],
            'content' => ['type' => 'string'],
            'post_id' => ['type' => 'integer', 'description' => 'create: attach to a listing.'],
            'feed' => ['type' => 'string', 'description' => 'user_timeline | post_timeline | post_reviews | post_wall.'],
            'review_score' => ['type' => 'integer', 'description' => 'create: for review feeds.'],
            'moderation' => ['type' => 'integer', 'description' => 'list filter.'],
            'search' => ['type' => 'string', 'description' => 'list filter.'],
            'user_id' => ['type' => 'integer', 'description' => 'Act as this user (defaults to the connected one).'],
            'object_type' => ['type' => 'string', 'enum' => ['post', 'user'], 'description' => 'follow/unfollow.'],
            'object_id' => ['type' => 'integer', 'description' => 'follow/unfollow.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_timeline_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_timeline_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    return nibwp_voxel_timeline_dispatch($input);
}

/* ----------------------------------------------------------------------------
 * voxel-messages — direct messages and notifications
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-messages', [
    'label' => __('Voxel — Messages', 'nibwp'),
    'description' => __(
        'Direct messages and notifications. PRIVACY: an authorized connection can read any user\'s inbox on this site — treat what you read as confidential, quote it only to the person entitled to it, and say when you are reading someone else\'s messages. Actions: "inbox" (threads for a user), "load" (messages between two parties — a party is a user or a post), "send", "mark-seen", "notifications", "unread-count".',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['inbox', 'load', 'send', 'mark-seen', 'notifications', 'unread-count']],
            'user_id' => ['type' => 'integer', 'description' => 'Whose inbox/notifications (defaults to the connected user).'],
            'party1_type' => ['type' => 'string', 'enum' => ['user', 'post'], 'default' => 'user'],
            'party1_id' => ['type' => 'integer'],
            'party2_type' => ['type' => 'string', 'enum' => ['user', 'post'], 'default' => 'user'],
            'party2_id' => ['type' => 'integer'],
            'cursor' => ['type' => 'string', 'description' => 'load: pagination cursor.'],
            'since' => ['type' => 'string', 'description' => 'unread-count: how far back to count (strtotime string, default "-1 year").'],
            'sender_type' => ['type' => 'string', 'enum' => ['user', 'post'], 'default' => 'user'],
            'sender_id' => ['type' => 'integer'],
            'receiver_type' => ['type' => 'string', 'enum' => ['user', 'post'], 'default' => 'user'],
            'receiver_id' => ['type' => 'integer'],
            'content' => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_messages_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_messages_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    return nibwp_voxel_messages_dispatch($input);
}

/* ----------------------------------------------------------------------------
 * voxel-templates — the template map (Elementor owns the content)
 * ------------------------------------------------------------------------- */

nibwp_voxel_register('voxel-templates', [
    'label' => __('Voxel — Templates', 'nibwp'),
    'description' => __(
        'Voxel\'s template map: which Elementor template is used for what. Actions: "list" (site-wide templates plus every post type\'s single/card/archive/form), "create" (a new Elementor template post, returns its id and edit link), "assign" (point a site-wide slot or a post type slot at a template id). This assigns templates; their CONTENT is edited through Elementor — use the Elementor integration or the visual workspace for that. Deleting is nibwp/voxel-delete.',
        'nibwp'
    ),
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'create', 'assign']],
            'title' => ['type' => 'string', 'description' => 'create: template name.'],
            'template_type' => ['type' => 'string', 'default' => 'page', 'description' => 'create: Elementor document type (page, section, header, footer).'],
            'slot' => ['type' => 'string', 'description' => 'assign: site-wide slot key (header, footer, timeline, inbox, 404, auth, pricing, ...) or a post type slot (single, card, archive, form).'],
            'post_type' => ['type' => 'string', 'description' => 'assign: set when the slot belongs to a post type.'],
            'template_id' => ['type' => 'integer', 'description' => 'assign: the Elementor template post id.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'execute_callback' => 'nibwp_voxel_templates_execute',
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_templates_execute(array $input): array|WP_Error
{
    if ($e = nibwp_voxel_guard()) {
        return $e;
    }

    switch ((string) ($input['action'] ?? '')) {
        case 'list':
            return nibwp_voxel_try(static function () {
                $per_type = [];
                foreach (\Voxel\Post_Type::get_voxel_types() as $pt) {
                    $per_type[$pt->get_key()] = $pt->get_templates();
                }
                return [
                    'site_templates' => (array) \Voxel\get('templates'),
                    'post_type_templates' => $per_type,
                    'note' => 'Values are Elementor template post IDs.',
                ];
            });

        case 'create':
            $title = trim((string) ($input['title'] ?? ''));
            if ($title === '') {
                return nibwp_voxel_err('no_title', 'Provide "title".');
            }
            return nibwp_voxel_try(static function () use ($input, $title) {
                $id = \Voxel\create_template($title, (string) ($input['template_type'] ?? 'page'));
                if (!$id || is_wp_error($id)) {
                    return nibwp_voxel_err('create_failed', 'Voxel could not create the template.', 500);
                }
                return [
                    'created' => true,
                    'template_id' => (int) $id,
                    'edit_link' => admin_url('post.php?post=' . (int) $id . '&action=elementor'),
                    'note' => 'Empty until something builds it — assign it with action=assign, then edit in Elementor.',
                ];
            });

        case 'assign':
            $slot = (string) ($input['slot'] ?? '');
            $template_id = (int) ($input['template_id'] ?? 0);
            if ($slot === '' || $template_id <= 0) {
                return nibwp_voxel_err('missing_args', 'Provide "slot" and "template_id".');
            }
            if (get_post_type($template_id) !== 'elementor_library') {
                return nibwp_voxel_err('not_template', sprintf('Post %d is not an Elementor template.', $template_id), 404);
            }

            $post_type_key = (string) ($input['post_type'] ?? '');
            if ($post_type_key !== '') {
                $pt = nibwp_voxel_post_type($post_type_key);
                if (is_wp_error($pt)) {
                    return $pt;
                }
                return nibwp_voxel_try(static function () use ($pt, $slot, $template_id) {
                    $config = $pt->repository->get_config();
                    $config['templates'] = array_merge((array) ($config['templates'] ?? []), [$slot => $template_id]);
                    $pt->repository->set_config($config);
                    \Voxel\Post_Type::force_get($pt->get_key());
                    return ['assigned' => true, 'post_type' => $pt->get_key(), 'slot' => $slot, 'template_id' => $template_id];
                });
            }

            return nibwp_voxel_try(static function () use ($slot, $template_id) {
                \Voxel\set('templates.' . $slot, $template_id);
                return ['assigned' => true, 'slot' => $slot, 'template_id' => $template_id];
            });

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action. Use list, create, or assign.');
    }
}
