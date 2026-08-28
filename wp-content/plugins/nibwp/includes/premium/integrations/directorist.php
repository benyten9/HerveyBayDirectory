<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Directorist integration for NIBWP (Pro tier).
 *
 * Surfaces Directorist's listings + taxonomies + orders + reviews + favorites
 * as MCP-callable abilities. Operates on the Directorist CPT/taxonomy
 * primitives (ATBDP_POST_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS,
 * atbdp_orders) using WP core APIs so the integration works whether or not
 * Directorist's own REST controllers are loaded for the current request.
 *
 * REQUIRES: Directorist plugin (free) v8.0+ active.
 * Pro license unlocks this integration via includes/premium/bootstrap.php.
 *
 * @see https://directorist.com/documentation/
 */

wp_register_ability('nibwp/directorist-manage', [
    'label' => __('Directorist – Directory Manager', domain: 'nibwp'),
    'description' => __(
        'Manage a Directorist business directory — listings, categories, locations, tags, listing reviews, orders, pricing plans, and user favorites. Create / publish / expire / feature listings, approve reviews, and pull directory reports.',
        domain: 'nibwp',
    ),
    'category' => 'directory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Listings
                    'list_listings', 'get_listing', 'create_listing', 'update_listing', 'delete_listing',
                    'set_listing_status', 'set_listing_featured', 'expire_listing', 'renew_listing',
                    // Categories
                    'list_categories', 'get_category', 'create_category', 'update_category', 'delete_category',
                    // Locations
                    'list_locations', 'get_location', 'create_location', 'update_location', 'delete_location',
                    // Tags
                    'list_tags', 'get_tag', 'create_tag', 'update_tag', 'delete_tag',
                    // Reviews (WP comments tied to listings)
                    'list_reviews', 'get_review', 'approve_review', 'spam_review', 'delete_review',
                    // Orders
                    'list_orders', 'get_order',
                    // Pricing Plans (Pricing Plans addon)
                    'list_plans', 'get_plan',
                    // Favorites
                    'list_favorites', 'add_favorite', 'remove_favorite',
                    // Reports
                    'top_listings', 'listing_stats', 'revenue_summary',
                ],
                'description' => 'The action to perform.',
            ],
            // Listing fields
            'listing_id'  => ['type' => 'integer', 'description' => 'Listing post ID.'],
            'listing_data'=> [
                'type' => 'object',
                'description' => 'Listing fields. Common keys: title, content (excerpt also accepted), author_id, status (publish/pending/draft/private/expired), address, location, phone, email, website, fax, zip, latitude, longitude, price, price_range, business_hours, is_featured (bool), expiry_date (Y-m-d), category_ids (int[]), location_ids (int[]), tag_ids (int[]), custom_fields (assoc of {field_id:value}).',
                'additionalProperties' => true,
            ],
            // Taxonomy fields
            'term_id'     => ['type' => 'integer', 'description' => 'Term ID (for category/location/tag).'],
            'term_data'   => [
                'type' => 'object',
                'description' => 'Term fields: name, slug, description, parent (int).',
                'additionalProperties' => true,
            ],
            // Review fields
            'review_id'   => ['type' => 'integer', 'description' => 'Review (comment) ID.'],
            'review_data' => [
                'type' => 'object',
                'description' => 'Review fields: listing_id, author, author_email, content, rating (1-5).',
                'additionalProperties' => true,
            ],
            // Order fields
            'order_id'    => ['type' => 'integer', 'description' => 'Order ID.'],
            // Plan fields
            'plan_id'     => ['type' => 'integer', 'description' => 'Pricing plan ID.'],
            // Favorites
            'user_id'     => ['type' => 'integer', 'description' => 'WordPress user ID (defaults to current user when omitted).'],
            // Common
            'status'      => ['type' => 'string', 'description' => 'Status filter / setter. Listings: publish, pending, draft, private, expired. Reviews: approved, hold, spam, trash.'],
            'search'      => ['type' => 'string', 'description' => 'Free-text search term.'],
            'category_id' => ['type' => 'integer', 'description' => 'Filter by category term ID.'],
            'location_id' => ['type' => 'integer', 'description' => 'Filter by location term ID.'],
            'tag_id'      => ['type' => 'integer', 'description' => 'Filter by tag term ID.'],
            'directory_type_id' => ['type' => 'integer', 'description' => 'Filter by directory type term ID.'],
            'is_featured' => ['type' => 'boolean', 'description' => 'Featured flag (set / filter).'],
            'date_from'   => ['type' => 'string', 'description' => 'Y-m-d, lower bound.'],
            'date_to'     => ['type' => 'string', 'description' => 'Y-m-d, upper bound.'],
            'per_page'    => ['type' => 'integer', 'description' => 'Results per page (1-100). Default 20.'],
            'page'        => ['type' => 'integer', 'description' => 'Page number (1-based). Default 1.'],
            'limit'       => ['type' => 'integer', 'description' => 'Limit for ranked reports. Default 10.'],
            'force'       => ['type' => 'boolean', 'description' => 'For delete_listing: bypass trash and hard-delete. Default false.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'listings'   => ['type' => 'array'],
            'listing'    => ['type' => 'object'],
            'categories' => ['type' => 'array'],
            'category'   => ['type' => 'object'],
            'locations'  => ['type' => 'array'],
            'location'   => ['type' => 'object'],
            'tags'       => ['type' => 'array'],
            'tag'        => ['type' => 'object'],
            'reviews'    => ['type' => 'array'],
            'review'     => ['type' => 'object'],
            'orders'     => ['type' => 'array'],
            'order'      => ['type' => 'object'],
            'plans'      => ['type' => 'array'],
            'plan'       => ['type' => 'object'],
            'favorites'  => ['type' => 'array'],
            'report'     => ['type' => 'object'],
            'total'      => ['type' => 'integer'],
            'updated'    => ['type' => 'boolean'],
            'deleted'    => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'nibwp_directorist_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage a Directorist business directory — listings + taxonomies + orders + reviews + favorites.',
                '',
                'LISTING ACTIONS:',
                '- list_listings: Filterable by status / category_id / location_id / tag_id / directory_type_id / is_featured / search / per_page / page / date_from / date_to.',
                '- get_listing: Requires listing_id. Returns full listing + meta + linked terms + reviews count + avg rating.',
                '- create_listing: Requires listing_data.title. Common fields: content, address, phone, email, website, latitude, longitude, price, category_ids, location_ids, tag_ids, custom_fields.',
                '- update_listing: Requires listing_id + listing_data. Pass only fields you want to change.',
                '- delete_listing: listing_id (+ force=true for hard delete). DESTRUCTIVE.',
                '- set_listing_status: listing_id + status. Status: publish / pending / draft / private / expired.',
                '- set_listing_featured: listing_id + is_featured (bool).',
                '- expire_listing: listing_id. Shortcut for status=expired + sets _featured to 0.',
                '- renew_listing: listing_id (+ optional expiry_date in listing_data). Updates _expiry_date, sets status=publish.',
                '',
                'TAXONOMY ACTIONS (categories / locations / tags follow the same shape):',
                '- list_*: Filterable by search / per_page / page. Returns id, name, slug, count, parent.',
                '- get_*: Requires term_id.',
                '- create_*: Requires term_data.name. Optional slug, description, parent.',
                '- update_*: Requires term_id + term_data fields to change.',
                '- delete_*: Requires term_id.',
                '',
                'REVIEW ACTIONS (WP comments on listings):',
                '- list_reviews: Filter by listing_id / status (approve/hold/spam/trash) / per_page / page.',
                '- get_review / approve_review / spam_review / delete_review: Require review_id.',
                '',
                'ORDER ACTIONS (atbdp_orders CPT):',
                '- list_orders: Filter by status / date_from / date_to / per_page / page.',
                '- get_order: Requires order_id. Returns full order meta + linked listing.',
                '',
                'PLAN ACTIONS (Pricing Plans addon):',
                '- list_plans / get_plan — read-only. Plan mutations belong in the addon UI; the agent should not silently change pricing.',
                '',
                'FAVORITE ACTIONS:',
                '- list_favorites: user_id (defaults to current user). Returns listings in their favorites array.',
                '- add_favorite / remove_favorite: listing_id (+ optional user_id).',
                '',
                'REPORT ACTIONS:',
                '- top_listings: Rank by views / reviews / favorites. limit + date_from/date_to.',
                '- listing_stats: Counts grouped by status + average rating + featured count.',
                '- revenue_summary: Sum of paid orders for a date range.',
                '',
                'REQUIRES: Directorist v8+ active. Many addons (Pricing Plans, Adverts, etc.) extend the surface — actions degrade gracefully when the addon is not installed.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

/**
 * Central dispatcher for nibwp/directorist-manage.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_directorist_manage(array $input): array|WP_Error
{
    if (!defined('ATBDP_VERSION')) {
        return new WP_Error('directorist_not_active', 'Directorist plugin is not active.');
    }

    $action   = (string) ($input['action'] ?? '');
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page     = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            // ─── LISTINGS ──────────────────────────────────────────────
            case 'list_listings':           return nibwp_dr__list_listings($input, $per_page, $page);
            case 'get_listing':             return nibwp_dr__get_listing($input);
            case 'create_listing':          return nibwp_dr__create_listing($input);
            case 'update_listing':          return nibwp_dr__update_listing($input);
            case 'delete_listing':          return nibwp_dr__delete_listing($input);
            case 'set_listing_status':      return nibwp_dr__set_listing_status($input);
            case 'set_listing_featured':    return nibwp_dr__set_listing_featured($input);
            case 'expire_listing':          return nibwp_dr__expire_listing($input);
            case 'renew_listing':           return nibwp_dr__renew_listing($input);

            // ─── CATEGORIES / LOCATIONS / TAGS ─────────────────────────
            case 'list_categories':         return nibwp_dr__list_terms(ATBDP_CATEGORY, $input, $per_page, $page, 'categories');
            case 'get_category':            return nibwp_dr__get_term(ATBDP_CATEGORY, $input, 'category');
            case 'create_category':         return nibwp_dr__create_term(ATBDP_CATEGORY, $input, 'category');
            case 'update_category':         return nibwp_dr__update_term(ATBDP_CATEGORY, $input, 'category');
            case 'delete_category':         return nibwp_dr__delete_term(ATBDP_CATEGORY, $input);

            case 'list_locations':          return nibwp_dr__list_terms(ATBDP_LOCATION, $input, $per_page, $page, 'locations');
            case 'get_location':            return nibwp_dr__get_term(ATBDP_LOCATION, $input, 'location');
            case 'create_location':         return nibwp_dr__create_term(ATBDP_LOCATION, $input, 'location');
            case 'update_location':         return nibwp_dr__update_term(ATBDP_LOCATION, $input, 'location');
            case 'delete_location':         return nibwp_dr__delete_term(ATBDP_LOCATION, $input);

            case 'list_tags':               return nibwp_dr__list_terms(ATBDP_TAGS, $input, $per_page, $page, 'tags');
            case 'get_tag':                 return nibwp_dr__get_term(ATBDP_TAGS, $input, 'tag');
            case 'create_tag':              return nibwp_dr__create_term(ATBDP_TAGS, $input, 'tag');
            case 'update_tag':              return nibwp_dr__update_term(ATBDP_TAGS, $input, 'tag');
            case 'delete_tag':              return nibwp_dr__delete_term(ATBDP_TAGS, $input);

            // ─── REVIEWS ───────────────────────────────────────────────
            case 'list_reviews':            return nibwp_dr__list_reviews($input, $per_page, $page);
            case 'get_review':              return nibwp_dr__get_review($input);
            case 'approve_review':          return nibwp_dr__set_review_status($input, 'approve');
            case 'spam_review':             return nibwp_dr__set_review_status($input, 'spam');
            case 'delete_review':           return nibwp_dr__delete_review($input);

            // ─── ORDERS ────────────────────────────────────────────────
            case 'list_orders':             return nibwp_dr__list_orders($input, $per_page, $page);
            case 'get_order':               return nibwp_dr__get_order($input);

            // ─── PLANS ─────────────────────────────────────────────────
            case 'list_plans':              return nibwp_dr__list_plans($input, $per_page, $page);
            case 'get_plan':                return nibwp_dr__get_plan($input);

            // ─── FAVORITES ─────────────────────────────────────────────
            case 'list_favorites':          return nibwp_dr__list_favorites($input);
            case 'add_favorite':            return nibwp_dr__set_favorite($input, true);
            case 'remove_favorite':         return nibwp_dr__set_favorite($input, false);

            // ─── REPORTS ───────────────────────────────────────────────
            case 'top_listings':            return nibwp_dr__top_listings($input);
            case 'listing_stats':           return nibwp_dr__listing_stats();
            case 'revenue_summary':         return nibwp_dr__revenue_summary($input);

            default:
                return new WP_Error('unknown_action', "Unknown action: {$action}");
        }
    } catch (Throwable $e) {
        return new WP_Error('directorist_error', $e->getMessage());
    }
}

// =====================================================================
// LISTING HELPERS
// =====================================================================

function nibwp_dr__serialize_listing(WP_Post $p): array
{
    $cats = wp_get_object_terms($p->ID, ATBDP_CATEGORY,  ['fields' => 'all']);
    $locs = wp_get_object_terms($p->ID, ATBDP_LOCATION, ['fields' => 'all']);
    $tags = wp_get_object_terms($p->ID, ATBDP_TAGS,     ['fields' => 'all']);
    return [
        'id'           => $p->ID,
        'title'        => $p->post_title,
        'status'       => $p->post_status,
        'content'      => $p->post_content,
        'excerpt'      => $p->post_excerpt,
        'author_id'    => (int) $p->post_author,
        'date'         => $p->post_date,
        'modified'     => $p->post_modified,
        'permalink'    => get_permalink($p->ID),
        'address'      => (string) get_post_meta($p->ID, '_address', true),
        'phone'        => (string) get_post_meta($p->ID, '_phone', true),
        'email'        => (string) get_post_meta($p->ID, '_email', true),
        'website'      => (string) get_post_meta($p->ID, '_website', true),
        'fax'          => (string) get_post_meta($p->ID, '_fax', true),
        'zip'          => (string) get_post_meta($p->ID, '_zip', true),
        'latitude'     => (string) get_post_meta($p->ID, '_manual_lat', true),
        'longitude'    => (string) get_post_meta($p->ID, '_manual_lng', true),
        'price'        => (string) get_post_meta($p->ID, '_price', true),
        'price_range'  => (string) get_post_meta($p->ID, '_price_range', true),
        'is_featured'  => (int)    get_post_meta($p->ID, '_featured', true) === 1,
        'expiry_date'  => (string) get_post_meta($p->ID, '_expiry_date', true),
        'views'        => (int)    get_post_meta($p->ID, '_atbdp_post_views_count', true),
        'avg_rating'   => (float)  get_post_meta($p->ID, '_rating', true),
        'review_count' => (int)    get_post_meta($p->ID, '_rating_count', true),
        'categories'   => array_map(fn($t) => ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug], is_array($cats) ? $cats : []),
        'locations'    => array_map(fn($t) => ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug], is_array($locs) ? $locs : []),
        'tags'         => array_map(fn($t) => ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug], is_array($tags) ? $tags : []),
    ];
}

function nibwp_dr__list_listings(array $in, int $per_page, int $page): array
{
    $args = [
        'post_type'      => ATBDP_POST_TYPE,
        'post_status'    => $in['status'] ?? 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if (!empty($in['search'])) {
        $args['s'] = (string) $in['search'];
    }
    $tax_query = [];
    if (!empty($in['category_id']))       { $tax_query[] = ['taxonomy' => ATBDP_CATEGORY,  'field' => 'term_id', 'terms' => (int) $in['category_id']]; }
    if (!empty($in['location_id']))       { $tax_query[] = ['taxonomy' => ATBDP_LOCATION, 'field' => 'term_id', 'terms' => (int) $in['location_id']]; }
    if (!empty($in['tag_id']))            { $tax_query[] = ['taxonomy' => ATBDP_TAGS,     'field' => 'term_id', 'terms' => (int) $in['tag_id']]; }
    if (!empty($in['directory_type_id'])) { $tax_query[] = ['taxonomy' => ATBDP_DIRECTORY_TYPE, 'field' => 'term_id', 'terms' => (int) $in['directory_type_id']]; }
    if ($tax_query) { $args['tax_query'] = $tax_query; }

    if (isset($in['is_featured'])) {
        $args['meta_query'][] = ['key' => '_featured', 'value' => $in['is_featured'] ? 1 : 0];
    }
    if (!empty($in['date_from']) || !empty($in['date_to'])) {
        $args['date_query'] = [array_filter(['after' => $in['date_from'] ?? null, 'before' => $in['date_to'] ?? null, 'inclusive' => true])];
    }

    $q = new WP_Query($args);
    return [
        'listings' => array_map('nibwp_dr__serialize_listing', $q->posts),
        'total'    => (int) $q->found_posts,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => (int) $q->max_num_pages,
    ];
}

function nibwp_dr__get_listing(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_listing_id', 'listing_id is required.');
    $p = get_post($id);
    if (!$p || $p->post_type !== ATBDP_POST_TYPE) return new WP_Error('not_found', 'Listing not found.');
    return ['listing' => nibwp_dr__serialize_listing($p)];
}

function nibwp_dr__create_listing(array $in): array|WP_Error
{
    $data = (array) ($in['listing_data'] ?? []);
    $title = (string) ($data['title'] ?? '');
    if ($title === '') return new WP_Error('missing_title', 'listing_data.title is required.');

    $post_id = wp_insert_post([
        'post_type'    => ATBDP_POST_TYPE,
        'post_title'   => $title,
        'post_content' => (string) ($data['content'] ?? ''),
        'post_excerpt' => (string) ($data['excerpt'] ?? ''),
        'post_status'  => (string) ($data['status'] ?? 'publish'),
        'post_author'  => (int) ($data['author_id'] ?? get_current_user_id() ?: 1),
    ], true);
    if (is_wp_error($post_id)) return $post_id;

    return nibwp_dr__apply_listing_data((int) $post_id, $data, /*is_create*/ true);
}

function nibwp_dr__update_listing(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_listing_id', 'listing_id is required.');
    $p = get_post($id);
    if (!$p || $p->post_type !== ATBDP_POST_TYPE) return new WP_Error('not_found', 'Listing not found.');

    $data = (array) ($in['listing_data'] ?? []);
    $update = ['ID' => $id];
    foreach (['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'author_id' => 'post_author'] as $k => $col) {
        if (array_key_exists($k, $data)) { $update[$col] = $k === 'author_id' ? (int) $data[$k] : $data[$k]; }
    }
    if (count($update) > 1) {
        $ret = wp_update_post($update, true);
        if (is_wp_error($ret)) return $ret;
    }
    return nibwp_dr__apply_listing_data($id, $data, /*is_create*/ false);
}

function nibwp_dr__apply_listing_data(int $listing_id, array $data, bool $is_create): array
{
    $meta_map = [
        'address'     => '_address',
        'phone'       => '_phone',
        'email'       => '_email',
        'website'     => '_website',
        'fax'         => '_fax',
        'zip'         => '_zip',
        'latitude'    => '_manual_lat',
        'longitude'   => '_manual_lng',
        'price'       => '_price',
        'price_range' => '_price_range',
        'business_hours' => '_hours',
        'expiry_date' => '_expiry_date',
    ];
    foreach ($meta_map as $k => $meta_key) {
        if (array_key_exists($k, $data)) {
            update_post_meta($listing_id, $meta_key, $data[$k]);
        }
    }
    if (array_key_exists('is_featured', $data)) {
        update_post_meta($listing_id, '_featured', $data['is_featured'] ? 1 : 0);
    }

    // Custom fields — pairs of {field_id: value} addressed by ACF or ATBDP fm.
    if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
        foreach ($data['custom_fields'] as $field_id => $value) {
            update_post_meta($listing_id, sanitize_key((string) $field_id), $value);
        }
    }

    foreach ([
        'category_ids' => ATBDP_CATEGORY,
        'location_ids' => ATBDP_LOCATION,
        'tag_ids'      => ATBDP_TAGS,
        'directory_type_ids' => ATBDP_DIRECTORY_TYPE,
    ] as $k => $tax) {
        if (array_key_exists($k, $data)) {
            $ids = array_map('intval', (array) $data[$k]);
            wp_set_object_terms($listing_id, $ids, $tax, /*append*/ false);
        }
    }

    return [
        'listing' => nibwp_dr__serialize_listing(get_post($listing_id)),
        'created' => $is_create,
        'updated' => !$is_create,
    ];
}

function nibwp_dr__delete_listing(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_listing_id', 'listing_id is required.');
    $force = (bool) ($in['force'] ?? false);
    $ret = wp_delete_post($id, $force);
    return ['deleted' => (bool) $ret, 'force' => $force, 'listing_id' => $id];
}

function nibwp_dr__set_listing_status(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    $status = (string) ($in['status'] ?? '');
    if ($id <= 0 || $status === '') return new WP_Error('invalid', 'listing_id + status required.');
    $ret = wp_update_post(['ID' => $id, 'post_status' => $status], true);
    if (is_wp_error($ret)) return $ret;
    return ['updated' => true, 'listing' => nibwp_dr__serialize_listing(get_post($id))];
}

function nibwp_dr__set_listing_featured(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_listing_id', 'listing_id is required.');
    $val = (bool) ($in['is_featured'] ?? true);
    update_post_meta($id, '_featured', $val ? 1 : 0);
    return ['updated' => true, 'listing' => nibwp_dr__serialize_listing(get_post($id))];
}

function nibwp_dr__expire_listing(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_listing_id', 'listing_id is required.');
    wp_update_post(['ID' => $id, 'post_status' => 'expired']);
    update_post_meta($id, '_featured', 0);
    return ['updated' => true, 'listing' => nibwp_dr__serialize_listing(get_post($id))];
}

function nibwp_dr__renew_listing(array $in): array|WP_Error
{
    $id = (int) ($in['listing_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_listing_id', 'listing_id is required.');
    $data = (array) ($in['listing_data'] ?? []);
    $new_expiry = (string) ($data['expiry_date'] ?? gmdate('Y-m-d', strtotime('+1 year')));
    wp_update_post(['ID' => $id, 'post_status' => 'publish']);
    update_post_meta($id, '_expiry_date', $new_expiry);
    return ['updated' => true, 'expiry_date' => $new_expiry, 'listing' => nibwp_dr__serialize_listing(get_post($id))];
}

// =====================================================================
// TAXONOMY HELPERS  (categories / locations / tags)
// =====================================================================

function nibwp_dr__serialize_term(WP_Term $t): array
{
    return [
        'id'          => (int) $t->term_id,
        'name'        => $t->name,
        'slug'        => $t->slug,
        'description' => $t->description,
        'count'       => (int) $t->count,
        'parent'      => (int) $t->parent,
        'taxonomy'    => $t->taxonomy,
    ];
}

function nibwp_dr__list_terms(string $tax, array $in, int $per_page, int $page, string $out_key): array
{
    $args = [
        'taxonomy'   => $tax,
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];
    if (!empty($in['search'])) { $args['search'] = (string) $in['search']; }

    $terms = get_terms($args);
    $total = wp_count_terms(['taxonomy' => $tax, 'hide_empty' => false, 'search' => (string) ($in['search'] ?? '')]);
    if (is_wp_error($terms)) return ['error' => $terms->get_error_message()];

    return [
        $out_key   => array_map('nibwp_dr__serialize_term', is_array($terms) ? $terms : []),
        'total'    => (int) $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

function nibwp_dr__get_term(string $tax, array $in, string $out_key): array|WP_Error
{
    $id = (int) ($in['term_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_term_id', 'term_id is required.');
    $t = get_term($id, $tax);
    if (!$t || is_wp_error($t)) return new WP_Error('not_found', 'Term not found.');
    return [$out_key => nibwp_dr__serialize_term($t)];
}

function nibwp_dr__create_term(string $tax, array $in, string $out_key): array|WP_Error
{
    $d = (array) ($in['term_data'] ?? []);
    $name = (string) ($d['name'] ?? '');
    if ($name === '') return new WP_Error('missing_name', 'term_data.name is required.');
    $args = array_filter(['slug' => $d['slug'] ?? null, 'description' => $d['description'] ?? null, 'parent' => isset($d['parent']) ? (int) $d['parent'] : null]);
    $ret = wp_insert_term($name, $tax, $args);
    if (is_wp_error($ret)) return $ret;
    return [$out_key => nibwp_dr__serialize_term(get_term((int) $ret['term_id'], $tax)), 'created' => true];
}

function nibwp_dr__update_term(string $tax, array $in, string $out_key): array|WP_Error
{
    $id = (int) ($in['term_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_term_id', 'term_id is required.');
    $d = (array) ($in['term_data'] ?? []);
    $args = array_filter([
        'name'        => $d['name']        ?? null,
        'slug'        => $d['slug']        ?? null,
        'description' => $d['description'] ?? null,
        'parent'      => isset($d['parent']) ? (int) $d['parent'] : null,
    ], fn($v) => $v !== null);
    $ret = wp_update_term($id, $tax, $args);
    if (is_wp_error($ret)) return $ret;
    return [$out_key => nibwp_dr__serialize_term(get_term($id, $tax)), 'updated' => true];
}

function nibwp_dr__delete_term(string $tax, array $in): array|WP_Error
{
    $id = (int) ($in['term_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_term_id', 'term_id is required.');
    $ret = wp_delete_term($id, $tax);
    if (is_wp_error($ret)) return $ret;
    return ['deleted' => (bool) $ret];
}

// =====================================================================
// REVIEW HELPERS (WP comments on listings)
// =====================================================================

function nibwp_dr__serialize_review(WP_Comment $c): array
{
    return [
        'id'           => (int) $c->comment_ID,
        'listing_id'   => (int) $c->comment_post_ID,
        'author'       => $c->comment_author,
        'author_email' => $c->comment_author_email,
        'content'      => $c->comment_content,
        'rating'       => (int) get_comment_meta($c->comment_ID, 'rating', true),
        'status'       => $c->comment_approved === '1' ? 'approved' : ($c->comment_approved === 'spam' ? 'spam' : 'hold'),
        'date'         => $c->comment_date_gmt,
    ];
}

function nibwp_dr__list_reviews(array $in, int $per_page, int $page): array
{
    $args = [
        'post_type' => ATBDP_POST_TYPE,
        'status'    => $in['status'] ?? 'all',
        'number'    => $per_page,
        'offset'    => ($page - 1) * $per_page,
    ];
    if (!empty($in['listing_id'])) { $args['post_id'] = (int) $in['listing_id']; }
    $comments = get_comments($args);
    $total = get_comments(array_merge($args, ['count' => true, 'number' => null, 'offset' => null]));
    return [
        'reviews'  => array_map('nibwp_dr__serialize_review', $comments),
        'total'    => (int) $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

function nibwp_dr__get_review(array $in): array|WP_Error
{
    $id = (int) ($in['review_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_review_id', 'review_id is required.');
    $c = get_comment($id);
    if (!$c) return new WP_Error('not_found', 'Review not found.');
    return ['review' => nibwp_dr__serialize_review($c)];
}

function nibwp_dr__set_review_status(array $in, string $action): array|WP_Error
{
    $id = (int) ($in['review_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_review_id', 'review_id is required.');
    $status = $action === 'approve' ? '1' : ($action === 'spam' ? 'spam' : '0');
    $ret = wp_set_comment_status($id, $status);
    return ['updated' => (bool) $ret, 'review' => nibwp_dr__serialize_review(get_comment($id))];
}

function nibwp_dr__delete_review(array $in): array|WP_Error
{
    $id = (int) ($in['review_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_review_id', 'review_id is required.');
    $ret = wp_delete_comment($id, true);
    return ['deleted' => (bool) $ret];
}

// =====================================================================
// ORDER HELPERS  (atbdp_orders CPT)
// =====================================================================

function nibwp_dr__serialize_order(WP_Post $p): array
{
    return [
        'id'         => $p->ID,
        'title'      => $p->post_title,
        'status'     => $p->post_status,
        'author_id'  => (int) $p->post_author,
        'date'       => $p->post_date,
        'amount'     => (float) get_post_meta($p->ID, '_atbdp_total_amount', true),
        'plan_id'    => (int)   get_post_meta($p->ID, '_fees_plan', true),
        'listing_id' => (int)   get_post_meta($p->ID, '_listing_id', true),
        'gateway'    => (string) get_post_meta($p->ID, '_payment_gateway', true),
        'currency'   => (string) get_post_meta($p->ID, '_atbdp_currency', true),
    ];
}

function nibwp_dr__list_orders(array $in, int $per_page, int $page): array
{
    $args = [
        'post_type'      => 'atbdp_orders',
        'post_status'    => $in['status'] ?? 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if (!empty($in['date_from']) || !empty($in['date_to'])) {
        $args['date_query'] = [array_filter(['after' => $in['date_from'] ?? null, 'before' => $in['date_to'] ?? null, 'inclusive' => true])];
    }
    $q = new WP_Query($args);
    return [
        'orders'   => array_map('nibwp_dr__serialize_order', $q->posts),
        'total'    => (int) $q->found_posts,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

function nibwp_dr__get_order(array $in): array|WP_Error
{
    $id = (int) ($in['order_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_order_id', 'order_id is required.');
    $p = get_post($id);
    if (!$p || $p->post_type !== 'atbdp_orders') return new WP_Error('not_found', 'Order not found.');
    return ['order' => nibwp_dr__serialize_order($p)];
}

// =====================================================================
// PLAN HELPERS  (Pricing Plans addon)
// =====================================================================

function nibwp_dr__list_plans(array $in, int $per_page, int $page): array
{
    // Pricing Plans addon stores plans as a CPT. Most addons use `atbdp_pricing_plans`,
    // some older builds use `directorist_pricing_plans`. Try both.
    foreach (['atbdp_pricing_plans', 'directorist_pricing_plans'] as $pt) {
        if (post_type_exists($pt)) {
            $q = new WP_Query([
                'post_type'      => $pt,
                'post_status'    => 'any',
                'posts_per_page' => $per_page,
                'paged'          => $page,
            ]);
            return [
                'plans' => array_map(fn(WP_Post $p) => [
                    'id'       => $p->ID,
                    'name'     => $p->post_title,
                    'status'   => $p->post_status,
                    'price'    => (float)  get_post_meta($p->ID, '_plan_price', true),
                    'duration' => (string) get_post_meta($p->ID, '_plan_duration', true),
                    'meta'     => array_map(fn($v) => is_array($v) && count($v) === 1 ? $v[0] : $v, get_post_meta($p->ID)),
                ], $q->posts),
                'total'    => (int) $q->found_posts,
                'page'     => $page,
                'per_page' => $per_page,
                'post_type'=> $pt,
            ];
        }
    }
    return ['plans' => [], 'total' => 0, 'note' => 'Pricing Plans addon not detected (no matching CPT registered).'];
}

function nibwp_dr__get_plan(array $in): array|WP_Error
{
    $id = (int) ($in['plan_id'] ?? 0);
    if ($id <= 0) return new WP_Error('missing_plan_id', 'plan_id is required.');
    $p = get_post($id);
    if (!$p) return new WP_Error('not_found', 'Plan not found.');
    return [
        'plan' => [
            'id'       => $p->ID,
            'name'     => $p->post_title,
            'status'   => $p->post_status,
            'price'    => (float)  get_post_meta($p->ID, '_plan_price', true),
            'duration' => (string) get_post_meta($p->ID, '_plan_duration', true),
            'meta'     => array_map(fn($v) => is_array($v) && count($v) === 1 ? $v[0] : $v, get_post_meta($p->ID)),
        ],
    ];
}

// =====================================================================
// FAVORITE HELPERS
// =====================================================================

function nibwp_dr__user_id_from(array $in): int
{
    $id = (int) ($in['user_id'] ?? 0);
    return $id > 0 ? $id : (int) get_current_user_id();
}

function nibwp_dr__list_favorites(array $in): array|WP_Error
{
    $user_id = nibwp_dr__user_id_from($in);
    if ($user_id <= 0) return new WP_Error('no_user', 'No user_id supplied and no current user.');
    $ids = (array) get_user_meta($user_id, 'atbdp_favorites', true);
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) return ['favorites' => [], 'total' => 0, 'user_id' => $user_id];
    $posts = get_posts(['post_type' => ATBDP_POST_TYPE, 'post__in' => $ids, 'posts_per_page' => -1, 'orderby' => 'post__in']);
    return [
        'favorites' => array_map('nibwp_dr__serialize_listing', $posts),
        'total'     => count($posts),
        'user_id'   => $user_id,
    ];
}

function nibwp_dr__set_favorite(array $in, bool $add): array|WP_Error
{
    $user_id = nibwp_dr__user_id_from($in);
    $listing_id = (int) ($in['listing_id'] ?? 0);
    if ($user_id <= 0 || $listing_id <= 0) return new WP_Error('invalid', 'user_id + listing_id required.');
    $ids = (array) get_user_meta($user_id, 'atbdp_favorites', true);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($add) {
        if (!in_array($listing_id, $ids, true)) { $ids[] = $listing_id; }
    } else {
        $ids = array_values(array_filter($ids, fn($i) => $i !== $listing_id));
    }
    update_user_meta($user_id, 'atbdp_favorites', $ids);
    return ['updated' => true, 'user_id' => $user_id, 'listing_id' => $listing_id, 'count' => count($ids)];
}

// =====================================================================
// REPORT HELPERS
// =====================================================================

function nibwp_dr__top_listings(array $in): array
{
    $limit = min(max((int) ($in['limit'] ?? 10), 1), 100);
    $orderby_meta = '_atbdp_post_views_count';
    $q = new WP_Query([
        'post_type'      => ATBDP_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'meta_key'       => $orderby_meta,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
    ]);
    return [
        'report' => [
            'metric' => 'views',
            'top'    => array_map(fn(WP_Post $p) => [
                'id'           => $p->ID,
                'title'        => $p->post_title,
                'views'        => (int) get_post_meta($p->ID, '_atbdp_post_views_count', true),
                'avg_rating'   => (float) get_post_meta($p->ID, '_rating', true),
                'review_count' => (int) get_post_meta($p->ID, '_rating_count', true),
            ], $q->posts),
        ],
    ];
}

function nibwp_dr__listing_stats(): array
{
    global $wpdb;
    $by_status = $wpdb->get_results($wpdb->prepare(
        "SELECT post_status, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status",
        ATBDP_POST_TYPE
    ), ARRAY_A);
    $featured  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_featured' AND m.meta_value = '1'
         WHERE p.post_type = %s",
        ATBDP_POST_TYPE
    ));
    $avg_rating_global = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(m.meta_value) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_rating'
         WHERE p.post_type = %s AND m.meta_value > 0",
        ATBDP_POST_TYPE
    ));
    $by_status_map = [];
    foreach ($by_status as $row) { $by_status_map[(string) $row['post_status']] = (int) $row['c']; }
    return [
        'report' => [
            'by_status'      => $by_status_map,
            'featured_count' => $featured,
            'avg_rating'     => round($avg_rating_global, 2),
        ],
    ];
}

function nibwp_dr__revenue_summary(array $in): array
{
    global $wpdb;
    $from = (string) ($in['date_from'] ?? '1970-01-01');
    $to   = (string) ($in['date_to']   ?? gmdate('Y-m-d'));
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(p.ID) AS order_count,
            COALESCE(SUM(CAST(m.meta_value AS DECIMAL(12,2))), 0) AS revenue
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_atbdp_total_amount'
         WHERE p.post_type = 'atbdp_orders'
           AND p.post_status IN ('publish', 'completed')
           AND p.post_date >= %s AND p.post_date <= %s",
        $from . ' 00:00:00',
        $to   . ' 23:59:59'
    ), ARRAY_A);
    return [
        'report' => [
            'date_from'   => $from,
            'date_to'     => $to,
            'order_count' => (int) ($row['order_count'] ?? 0),
            'revenue'     => (float) ($row['revenue'] ?? 0),
        ],
    ];
}
