<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: SiteOrigin Page Builder (siteorigin-panels 2.36.1).
 *
 * THE LAYOUT FORMAT, because everything here turns on it.
 *
 * A page's layout lives in a single post meta row, key `panels_data`
 * (inc/renderer.php:185, siteorigin-panels.php:299). The value is a FLAT array
 * of three parallel lists — not a tree:
 *
 *   panels_data = [
 *     'grids'      => [ 0 => ['cells' => 2, 'style' => [...], 'label' => '',
 *                             'color_label' => '', 'ratio' => '',
 *                             'ratio_direction' => ''], ... ],   // one per ROW
 *     'grid_cells' => [ ['grid' => 0, 'index' => 0, 'weight' => 0.5,
 *                        'style' => [...]], ... ],               // flat, ALL rows
 *     'widgets'    => [ 0 => [ ...the widget's own settings at the TOP level...,
 *                              'panels_info' => ['class' => 'WP_Widget_Text',
 *                                                'grid' => 0, 'cell' => 1,
 *                                                'id' => 0,
 *                                                'widget_id' => '<uuid>',
 *                                                'style' => [...], 'label' => '',
 *                                                'raw' => false] ], ... ],
 *   ]
 *
 * Confirmed from the editor's own serialiser (js/siteorigin-panels.js:3756-3806)
 * and from the renderer that consumes it (inc/renderer.php:1178-1210).
 *
 * The three things that catch people out:
 *   - `grids[$i]['cells']` is a COUNT, not a list. The cells themselves are rows
 *     in the flat `grid_cells` list, found by their `grid` key
 *     (inc/renderer.php:1194-1200). Two places to keep in step.
 *   - a widget's identity is `panels_info['class']` — the PHP widget class the
 *     instance is rendered through (siteorigin-panels.php:732) — while its
 *     SETTINGS are the widget array's own top-level keys, siblings of
 *     `panels_info`, not nested under it. `panels_info['widget_id']` is the
 *     stable per-instance id the sanitiser pairs old settings against
 *     (inc/admin.php:1368-1375), so it must survive every edit.
 *   - placement is carried ON THE WIDGET (`panels_info['grid']` /
 *     `['cell']`), so moving a row renumbers every widget in the layout. There
 *     is no back-pointer from a cell to its widgets.
 *   - older layouts spell `panels_info` as `info` (inc/admin.php:1388). Reads
 *     here accept both; writes never invent one.
 *
 * WHY WRITES GO THROUGH THE PLUGIN AND NEVER TOUCH THE META DIRECTLY.
 *
 * Persisting `panels_data` correctly is not "call update_post_meta". The
 * plugin's own write seam runs the widget sanitiser, the sidebars emulator, the
 * style sanitiser, a public pre-save filter, a kses floor, DELETES the meta when
 * the layout ends up empty, double-slashes the whole structure because
 * update_post_meta() unslashes its input, and then refreshes the rendered
 * post_content mirror (inc/abilities.php:378-473). Skip any one of those and the
 * page renders empty or silently loses a widget's content — which is why every
 * layout write here hands the finished array to
 * SiteOrigin_Panels_Abilities::layout_update() and lets the plugin persist it.
 *
 * That seam arrived in 2.36.1. On an older build there is no sanitised write
 * path to borrow, so layout writes refuse by name rather than re-implementing
 * six sanitise passes from memory — the same call metform.php made, for the same
 * reason: a layout written in the wrong shape loses the page.
 *
 * STRUCTURE IS OURS, CONTENT IS NOT. The transforms here move rows, cells and
 * widgets around and edit named settings. They copy every key they do not
 * understand through untouched, because an unrecognised key is far more likely
 * to be a newer plugin version's or a third-party widget's than to be junk, and
 * dropping it destroys the page it came from. A test pins that.
 *
 * SECRETS: the settings option is reported through an allow-list. The affiliate
 * id lives in there (inc/settings.php:108) and never travels.
 */

/** Reading or editing one page's layout is gated per-post, never site-wide. */
const NIBWP_SITEORIGIN_POST_CAP = 'edit_post';

/** Plugin-wide settings change the editor for every author on the site. */
const NIBWP_SITEORIGIN_ADMIN_CAP = 'manage_options';

/** The post meta key holding a layout. */
const NIBWP_SITEORIGIN_META = 'panels_data';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_SITEORIGIN_MAX_ROWS = 200;

/** What a listing returns when no limit was given. */
const NIBWP_SITEORIGIN_DEFAULT_ROWS = 50;

/**
 * Is Page Builder actually loaded?
 *
 * SITEORIGIN_PANELS_VERSION is defined by the main plugin file before anything
 * else, and SiteOrigin_Panels is the class every read here goes through, so
 * between them they separate "not installed" from "installed and half-booted".
 */
function nibwp_siteorigin_guard(): ?WP_Error
{
    if (!defined('SITEORIGIN_PANELS_VERSION') && !class_exists('SiteOrigin_Panels')) {
        return new WP_Error(
            'nibwp_siteorigin_missing',
            __('SiteOrigin Page Builder is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_siteorigin_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_siteorigin_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority over one page. */
function nibwp_siteorigin_post_forbidden(int $post_id): WP_Error
{
    return new WP_Error(
        'nibwp_siteorigin_post_forbidden',
        sprintf(
            /* translators: %d: post ID */
            __('This account cannot edit post %d.', 'nibwp'),
            $post_id
        ),
        ['status' => 403]
    );
}

/** A refusal about authority over the whole site's builder configuration. */
function nibwp_siteorigin_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_siteorigin_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_SITEORIGIN_ADMIN_CAP
        ),
        ['status' => 403]
    );
}

/** The site-wide gate, for the plugin's own configuration only. */
function nibwp_siteorigin_require_admin(string $what): ?WP_Error
{
    return current_user_can(NIBWP_SITEORIGIN_ADMIN_CAP) ? null : nibwp_siteorigin_forbidden($what);
}

/**
 * Resolve a caller's post id claim into a layout we are allowed to work on.
 *
 * ORDER MATTERS AND IS DELIBERATE. The capability answers first, before anything
 * that could reveal whether the id exists or carries a layout: otherwise an
 * unauthorised caller walks the id space and learns the site's contents from the
 * shape of the refusals. `edit_post` with the id bound into it, never
 * `edit_posts` — the question is whether this account may edit THIS page, and on
 * a missing id core's map_meta_cap refuses it anyway, so nothing fatals.
 *
 * Only once authority is settled do existence and "is this actually a builder
 * page" get answered, each with its own code so a caller can tell them apart.
 *
 * @return array{post: object, layout: array<string, mixed>}|WP_Error
 */
function nibwp_siteorigin_resolve(mixed $raw_id): array|WP_Error
{
    $post_id = is_scalar($raw_id) ? (int) $raw_id : 0;

    if ($post_id <= 0) {
        return nibwp_siteorigin_err('bad_post_id', __('A post_id is required.', 'nibwp'));
    }

    if (!current_user_can(NIBWP_SITEORIGIN_POST_CAP, $post_id)) {
        return nibwp_siteorigin_post_forbidden($post_id);
    }

    $post = get_post($post_id);

    if (!is_object($post) || !isset($post->ID)) {
        return nibwp_siteorigin_err(
            'post_not_found',
            sprintf(
                /* translators: %d: post ID */
                __('No post %d exists.', 'nibwp'),
                $post_id
            ),
            404
        );
    }

    $layout = nibwp_siteorigin_read_meta($post_id);

    if ($layout === null) {
        return nibwp_siteorigin_err(
            'no_layout',
            sprintf(
                /* translators: %d: post ID */
                __('Post %d has no Page Builder layout; its content is plain editor content.', 'nibwp'),
                $post_id
            ),
            404
        );
    }

    return ['post' => $post, 'layout' => $layout];
}

/**
 * The stored layout for a post, or null when there is not one.
 *
 * get_post_meta() hands back whatever is in the row — WordPress has already
 * turned a serialised array back into an array, so nothing here needs to
 * unserialise anything, and nothing here does. A scalar (an empty string from a
 * half-written row, say) is not a layout.
 *
 * @return array<string, mixed>|null
 */
function nibwp_siteorigin_read_meta(int $post_id): ?array
{
    $raw = get_post_meta($post_id, NIBWP_SITEORIGIN_META, true);

    if (!is_array($raw) || $raw === []) {
        return null;
    }

    return nibwp_siteorigin_normalise($raw);
}

/**
 * Give a layout its three lists so every caller can index them without checking.
 *
 * Only the three containers are touched. Every other key the stored layout
 * carries stays exactly where it was — a later plugin version's field, or a
 * third party's, is not ours to tidy away.
 *
 * @param array<string, mixed> $layout
 * @return array<string, mixed>
 */
function nibwp_siteorigin_normalise(array $layout): array
{
    foreach (['grids', 'grid_cells', 'widgets'] as $key) {
        if (!isset($layout[$key]) || !is_array($layout[$key])) {
            $layout[$key] = [];
        } else {
            // Re-key only: a sparse list would make the row/cell indexes that
            // widgets point at mean something different from their position.
            $layout[$key] = array_values($layout[$key]);
        }
    }

    return $layout;
}

/**
 * The placement block of a widget, whatever this layout's vintage calls it.
 *
 * @param array<string, mixed> $widget
 * @return array<string, mixed>
 */
function nibwp_siteorigin_info(array $widget): array
{
    if (isset($widget['panels_info']) && is_array($widget['panels_info'])) {
        return $widget['panels_info'];
    }

    if (isset($widget['info']) && is_array($widget['info'])) {
        return $widget['info'];
    }

    return [];
}

/** Which key this widget stores its placement under, so a write puts it back there. */
function nibwp_siteorigin_info_key(array $widget): string
{
    return isset($widget['panels_info']) && is_array($widget['panels_info']) ? 'panels_info' : 'info';
}

/** The widget class an instance renders through, '' when the layout does not say. */
function nibwp_siteorigin_class(array $widget): string
{
    $info = nibwp_siteorigin_info($widget);

    return isset($info['class']) && is_scalar($info['class']) ? (string) $info['class'] : '';
}

/**
 * A widget's own settings — everything that is not its placement block.
 *
 * @param array<string, mixed> $widget
 * @return array<string, mixed>
 */
function nibwp_siteorigin_settings(array $widget): array
{
    unset($widget['panels_info'], $widget['info']);

    return $widget;
}

/** Clamp a caller's row count to something a response can carry. */
function nibwp_siteorigin_limit(mixed $raw): int
{
    if ($raw === null || $raw === '') {
        return NIBWP_SITEORIGIN_DEFAULT_ROWS;
    }

    $limit = is_scalar($raw) ? (int) $raw : NIBWP_SITEORIGIN_DEFAULT_ROWS;

    if ($limit < 1) {
        return NIBWP_SITEORIGIN_DEFAULT_ROWS;
    }

    return min($limit, NIBWP_SITEORIGIN_MAX_ROWS);
}

/** One plugin setting, without letting the plugin's own loader throw. */
function nibwp_siteorigin_setting(string $key): mixed
{
    if (!function_exists('siteorigin_panels_setting')) {
        return null;
    }

    try {
        return siteorigin_panels_setting($key);
    } catch (\Throwable $error) {
        return null;
    }
}

/**
 * Is this widget class something this site can actually render?
 *
 * A layout keeps the class name as a string, so a plugin that supplied the
 * widget and was then deactivated leaves a widget that resolves to nothing and
 * renders nothing — the page looks like the section vanished. The plugin's own
 * resolver is the authority (siteorigin-panels.php:732); class_exists is the
 * fallback for an environment where the factory has not been built yet.
 */
function nibwp_siteorigin_class_registered(string $class): bool
{
    if ($class === '') {
        return false;
    }

    if (class_exists('SiteOrigin_Panels') && method_exists('SiteOrigin_Panels', 'get_widget_instance')) {
        try {
            if (SiteOrigin_Panels::get_widget_instance($class) !== null) {
                return true;
            }
        } catch (\Throwable $error) {
            // Fall through to the factory and class checks below.
        }
    }

    $factory = $GLOBALS['wp_widget_factory'] ?? null;

    if (is_object($factory) && isset($factory->widgets) && is_array($factory->widgets)) {
        if (isset($factory->widgets[$class])) {
            return true;
        }

        foreach ($factory->widgets as $instance) {
            if (is_object($instance) && $instance instanceof $class) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Drop the renderer's in-request copy of a page's HTML.
 *
 * The renderer memoises rendered output in the global $siteorigin_panels_cache,
 * keyed by post id, and returns it before it ever looks at the meta
 * (inc/renderer.php:676-680). Write a layout and render the same page in the
 * same request — a sequence any agent produces the moment it edits and then
 * reads back — and it hands over the pre-write HTML and the write looks broken.
 * So every write here clears that entry, and only that entry.
 *
 * The PERSISTED mirror is a separate thing and is not re-rendered here. On a
 * copy-content site the layout's HTML is also kept in post_content, and
 * SiteOrigin_Panels_Admin::copy_content_to_post() is what refreshes it
 * (inc/admin.php:525). The plugin's own write seam already calls it, inside its
 * save-re-entrancy guard (inc/abilities.php:460-466); calling it again from here
 * would render the page a second time and fire save_post again for nothing.
 */
function nibwp_siteorigin_flush_render_cache(int $post_id): void
{
    if (isset($GLOBALS['siteorigin_panels_cache']) && is_array($GLOBALS['siteorigin_panels_cache'])) {
        unset($GLOBALS['siteorigin_panels_cache'][$post_id]);
    }
}

/**
 * Can this build persist a layout at all?
 *
 * The sanitised write seam is 2.36.1 and later. Older builds get a named
 * refusal: there is no safe seam to borrow, and hand-rolling the sanitiser, the
 * kses floor, the empty-layout delete and the double-slashing is how a page ends
 * up rendering nothing.
 */
function nibwp_siteorigin_can_write(): bool
{
    return class_exists('SiteOrigin_Panels_Abilities')
        && method_exists('SiteOrigin_Panels_Abilities', 'single')
        && method_exists('SiteOrigin_Panels_Abilities', 'layout_update');
}

/**
 * Persist a transformed layout and invalidate what a reader would otherwise see.
 *
 * @param array<string, mixed> $layout
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_write_layout(int $post_id, array $layout): array|WP_Error
{
    if (!nibwp_siteorigin_can_write()) {
        return nibwp_siteorigin_err(
            'write_unavailable',
            __(
                'This build of Page Builder has no sanitised layout write seam, so layout writes are read-only here. Writing the layout meta directly would mean re-implementing the widget sanitiser, the content floor and the rendered-content refresh, and a layout stored in the wrong shape renders the page empty. Page Builder 2.36.1 or later enables writes.',
                'nibwp'
            ),
            501
        );
    }

    $result = SiteOrigin_Panels_Abilities::single()->layout_update([
        'post_id' => $post_id,
        'panels_data' => $layout,
    ]);

    if ($result instanceof WP_Error) {
        return $result;
    }

    // The renderer's memo is now wrong for this page; see the helper for why the
    // persisted post_content mirror is deliberately not re-rendered here.
    nibwp_siteorigin_flush_render_cache($post_id);

    if (!is_array($result)) {
        return nibwp_siteorigin_err('write_failed', __('Page Builder did not report the result of the write.', 'nibwp'), 500);
    }

    if (isset($result['updated']) && !$result['updated']) {
        return nibwp_siteorigin_err(
            'write_declined',
            isset($result['message']) && is_scalar($result['message'])
                ? (string) $result['message']
                : __('Page Builder declined the layout write.', 'nibwp')
        );
    }

    return [
        'post_id' => $post_id,
        'written' => true,
        'storage' => isset($result['source']) && is_scalar($result['source']) ? (string) $result['source'] : 'meta',
        'render_cache_cleared' => true,
    ];
}

/**
 * Every action this hub serves.
 *
 * @return list<string>
 */
function nibwp_siteorigin_actions(): array
{
    return [
        // Pages and layouts.
        'summary',
        'list_pages',
        'get_layout',
        'layout_outline',
        'layout_raw',
        // Widgets.
        'list_widget_types',
        'get_widget',
        'update_widget',
        'add_widget',
        'remove_widget',
        'move_widget',
        // Rows and cells.
        'list_rows',
        'add_row',
        'remove_row',
        'reorder_rows',
        'set_row_cells',
        'set_cell_weights',
        // The plugin's own configuration.
        'get_settings',
        'update_settings',
        'widget_bundle',
        // Reusable layouts.
        'list_prebuilt_layouts',
        'get_prebuilt_layout',
        // Audit.
        'audit',
        'audit_missing_widgets',
        'audit_stale_content',
    ];
}

wp_register_ability('nibwp/siteorigin-manage', [
    'label' => __('SiteOrigin Page Builder – Layouts', 'nibwp'),
    'description' => 'Read and edit SiteOrigin Page Builder layouts: find the pages built with the builder, read one as rows, cells and widgets, '
        . 'edit a widget\'s settings, add, remove or move a widget between cells, add, remove, reorder rows and change a row\'s cell layout and cell weights. '
        . 'Also reports which widget types this site can render, the plugin\'s own settings, the widget bundle it ships, and the reusable layouts available. '
        . 'The audit finds the page faults that look like content vanishing: a widget whose class is no longer registered, empty rows and cells, a widget pointing at a row or cell that does not exist, '
        . 'a stale copy of the rendered page, and a layout that exists but is not what renders. '
        . 'Layout writes go through Page Builder\'s own sanitiser, never straight into post meta.',
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_siteorigin_actions(),
                'description' => 'Which operation to run. Start with summary, or list_pages.',
            ],
            'post_id' => ['type' => 'integer', 'description' => 'The page whose layout is being read or edited.'],
            'row' => ['type' => 'integer', 'description' => 'Row index, 0-based, as list_rows reports it.'],
            'cell' => ['type' => 'integer', 'description' => 'Cell index within the row, 0-based.'],
            'index' => ['type' => 'integer', 'description' => 'Position of a widget within its cell, 0-based.'],
            'widget_id' => ['type' => 'string', 'description' => 'A widget instance id from get_layout — preferred over row/cell/index, because it survives the layout moving around.'],
            'to_row' => ['type' => 'integer', 'description' => 'For move_widget: destination row index.'],
            'to_cell' => ['type' => 'integer', 'description' => 'For move_widget: destination cell index.'],
            'to_index' => ['type' => 'integer', 'description' => 'For move_widget / add_widget: position within the destination cell. Left out, appended.'],
            'widget_class' => ['type' => 'string', 'description' => 'For add_widget: the widget class, as list_widget_types reports it.'],
            'settings' => ['type' => 'object', 'description' => 'For add_widget / update_widget: the widget\'s own settings.'],
            'merge' => ['type' => 'boolean', 'description' => 'For update_widget: true merges into the existing settings, false replaces them. Default true.'],
            'cells' => ['type' => 'integer', 'description' => 'For add_row / set_row_cells: how many cells the row has.'],
            'weights' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Cell widths as fractions of the row. Normalised to sum to 1.'],
            'order' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'For reorder_rows: every current row index, in the order wanted.'],
            'position' => ['type' => 'integer', 'description' => 'For add_row: where to insert it. Left out, appended.'],
            'layout_id' => ['type' => 'string', 'description' => 'For get_prebuilt_layout: the reusable layout\'s id.'],
            'settings_update' => ['type' => 'object', 'description' => 'For update_settings: the allow-listed plugin settings to change.'],
            'post_type' => ['type' => 'string', 'description' => 'For list_pages and the audits: restrict to one post type.'],
            'check' => ['type' => 'string', 'description' => 'For audit: one check name, to look at a single fault class.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_siteorigin_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SiteOrigin Page Builder stores a page layout in post meta, not in the post content.',
                '',
                'START WITH summary for the site, or list_pages to find the pages that have a',
                'layout. Then get_layout for one page: it comes back as rows, each row holding',
                'cells, each cell holding widgets, which is the shape a person sees in the',
                'editor.',
                '',
                'HOW THE SHAPE WORKS, because it is easy to describe wrongly:',
                '- a row is a "grid". Its cells are side by side, and each cell has a WEIGHT,',
                '  a fraction of the row width. The weights of one row sum to 1.',
                '- a widget sits in exactly one cell and carries its own position. Move a row',
                '  and every widget in the page is renumbered, so prefer widget_id over',
                '  row/cell/index when naming a widget — row/cell/index describe where it was,',
                '  widget_id describes which one it is.',
                '- a widget is identified by its CLASS, a PHP class name. If the plugin that',
                '  supplied that class is gone, the widget renders nothing and the page looks',
                '  like a section disappeared. That is what audit_missing_widgets finds, and it',
                '  is the commonest cause of "my section vanished".',
                '',
                'WRITES: every one hands the whole layout to Page Builder\'s own sanitiser, so',
                'widget content is filtered exactly as an editor save filters it and the',
                'rendered copy of the page is refreshed. Keys this integration does not',
                'recognise are carried through untouched rather than dropped. If the installed',
                'Page Builder is older than 2.36.1 there is no sanitised write seam and layout',
                'writes refuse by name — say so rather than looking for another route.',
                '',
                'BEFORE EDITING A LAYOUT, read it. A write replaces the whole structure, so an',
                'edit built on a stale read will undo someone else\'s work. After a write, read',
                'back if the next step depends on positions.',
                '',
                'PER-PAGE AUTHORITY: every action naming a post_id checks that this account may',
                'edit THAT page. A listing silently leaves out pages the account cannot edit and',
                'reports skipped_no_permission; if that is not zero the picture is partial and',
                'the user should be told so.',
                '',
                'SETTINGS: get_settings reports the values of an allow-listed set of layout and',
                'behaviour switches. Anything else comes back as a name and whether it is set,',
                'and the affiliate id never travels. update_settings changes the editor for',
                'every author on the site and needs manage_options.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_manage(array $input): array|WP_Error
{
    $guard = nibwp_siteorigin_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) && is_scalar($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_siteorigin_actions(), true)) {
        return nibwp_siteorigin_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown SiteOrigin action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_siteorigin_actions())
            )
        );
    }

    // The plugin's internals are version-sensitive, and a widget's own update()
    // runs inside the write seam — third-party code we do not control. One catch
    // turns any of that into a named refusal rather than a fatal that takes the
    // whole REST request with it.
    try {
        return nibwp_siteorigin_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_siteorigin_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from the plugin */
                __('Page Builder could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'summary' => nibwp_siteorigin_summary($input),
        'list_pages' => nibwp_siteorigin_list_pages($input),
        'get_layout' => nibwp_siteorigin_get_layout($input),
        'layout_outline' => nibwp_siteorigin_layout_outline($input),
        'layout_raw' => nibwp_siteorigin_layout_raw($input),
        'list_widget_types' => nibwp_siteorigin_list_widget_types($input),
        'get_widget' => nibwp_siteorigin_get_widget($input),
        'update_widget' => nibwp_siteorigin_update_widget($input),
        'add_widget' => nibwp_siteorigin_add_widget($input),
        'remove_widget' => nibwp_siteorigin_remove_widget($input),
        'move_widget' => nibwp_siteorigin_move_widget($input),
        'list_rows' => nibwp_siteorigin_list_rows($input),
        'add_row' => nibwp_siteorigin_add_row($input),
        'remove_row' => nibwp_siteorigin_remove_row($input),
        'reorder_rows' => nibwp_siteorigin_reorder_rows($input),
        'set_row_cells' => nibwp_siteorigin_set_row_cells($input),
        'set_cell_weights' => nibwp_siteorigin_set_cell_weights($input),
        'get_settings' => nibwp_siteorigin_get_settings(),
        'update_settings' => nibwp_siteorigin_update_settings($input),
        'widget_bundle' => nibwp_siteorigin_widget_bundle(),
        'list_prebuilt_layouts' => nibwp_siteorigin_list_prebuilt_layouts($input),
        'get_prebuilt_layout' => nibwp_siteorigin_get_prebuilt_layout($input),
        'audit' => nibwp_siteorigin_audit($input),
        'audit_missing_widgets' => nibwp_siteorigin_audit_missing_widgets($input),
        'audit_stale_content' => nibwp_siteorigin_audit_stale_content($input),
        default => nibwp_siteorigin_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------- finding the pages */

/**
 * Post ids that have a layout, bounded, with the ones this account cannot edit
 * left out and counted.
 *
 * A silently shortened list is one an agent will describe as the whole site, so
 * the count travels with it.
 *
 * @param array<string, mixed> $input
 * @return array{ids: list<int>, candidates: int, skipped_no_permission: int, limit: int, offset: int, truncated: bool}|WP_Error
 */
function nibwp_siteorigin_find_pages(array $input): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'prepare')) {
        return nibwp_siteorigin_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_siteorigin_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) && is_scalar($input['offset']) ? max(0, (int) $input['offset']) : 0;
    $post_type = isset($input['post_type']) && is_scalar($input['post_type'])
        ? sanitize_key((string) $input['post_type'])
        : '';

    // Table names come off $wpdb, never from input. Every value is a placeholder.
    $sql = 'SELECT pm.post_id FROM ' . $wpdb->postmeta . ' pm'
        . ' INNER JOIN ' . $wpdb->posts . ' p ON p.ID = pm.post_id'
        . ' WHERE pm.meta_key = %s'
        . " AND pm.meta_value <> ''"
        . " AND p.post_type <> 'revision'"
        . " AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')";

    $args = [NIBWP_SITEORIGIN_META];

    if ($post_type !== '') {
        $sql .= ' AND p.post_type = %s';
        $args[] = $post_type;
    }

    $sql .= ' ORDER BY pm.post_id ASC LIMIT %d OFFSET %d';
    // One more than asked for, so truncation is a fact rather than a guess.
    $args[] = $limit + 1;
    $args[] = $offset;

    $rows = (array) $wpdb->get_col($wpdb->prepare($sql, $args));

    $truncated = count($rows) > $limit;

    if ($truncated) {
        array_pop($rows);
    }

    $ids = [];
    $skipped = 0;

    foreach ($rows as $row) {
        $id = (int) $row;

        if ($id <= 0) {
            continue;
        }

        if (!current_user_can(NIBWP_SITEORIGIN_POST_CAP, $id)) {
            $skipped++;

            continue;
        }

        $ids[] = $id;
    }

    return [
        'ids' => $ids,
        'candidates' => count($rows),
        'skipped_no_permission' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
        'truncated' => $truncated,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_list_pages(array $input): array|WP_Error
{
    $found = nibwp_siteorigin_find_pages($input);

    if ($found instanceof WP_Error) {
        return $found;
    }

    $pages = [];

    foreach ($found['ids'] as $id) {
        $layout = nibwp_siteorigin_read_meta($id);

        if ($layout === null) {
            continue;
        }

        $post = get_post($id);

        $pages[] = [
            'post_id' => $id,
            'title' => is_object($post) && isset($post->post_title) && is_scalar($post->post_title)
                ? (string) $post->post_title
                : '',
            'post_type' => is_object($post) && isset($post->post_type) && is_scalar($post->post_type)
                ? (string) $post->post_type
                : '',
            'status' => is_object($post) && isset($post->post_status) && is_scalar($post->post_status)
                ? (string) $post->post_status
                : '',
            'rows' => count($layout['grids']),
            'cells' => count($layout['grid_cells']),
            'widgets' => count($layout['widgets']),
        ];
    }

    return [
        'pages' => $pages,
        'candidates' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'limit' => $found['limit'],
        'offset' => $found['offset'],
        'truncated' => $found['truncated'],
    ];
}

/* ------------------------------------------------------- reading a layout */

/**
 * Turn the flat layout into the rows → cells → widgets shape a person sees.
 *
 * This mirrors what the renderer does (inc/renderer.php:1178-1210) but refuses
 * to assume the layout is coherent: a widget pointing at a row or cell that is
 * not there is collected as an orphan rather than creating one, because the
 * point of reading is to find out that the page is broken.
 *
 * @param array<string, mixed> $layout
 * @return array{rows: list<array<string, mixed>>, orphans: list<array<string, mixed>>}
 */
function nibwp_siteorigin_structure(array $layout): array
{
    $rows = [];

    foreach ($layout['grids'] as $ri => $grid) {
        $grid = is_array($grid) ? $grid : [];

        $rows[$ri] = [
            'row' => $ri,
            'label' => isset($grid['label']) && is_scalar($grid['label']) ? (string) $grid['label'] : '',
            'color_label' => isset($grid['color_label']) && is_scalar($grid['color_label']) ? (string) $grid['color_label'] : '',
            // The stored count, kept separate from the cells actually found, so a
            // mismatch between the two is visible rather than papered over.
            'declared_cells' => isset($grid['cells']) && is_scalar($grid['cells']) ? (int) $grid['cells'] : 0,
            'has_style' => isset($grid['style']) && is_array($grid['style']) && $grid['style'] !== [],
            'cells' => [],
        ];
    }

    foreach ($layout['grid_cells'] as $cell) {
        $cell = is_array($cell) ? $cell : [];
        $ri = isset($cell['grid']) && is_scalar($cell['grid']) ? (int) $cell['grid'] : -1;

        if (!isset($rows[$ri])) {
            continue;
        }

        $rows[$ri]['cells'][] = [
            'cell' => count($rows[$ri]['cells']),
            'weight' => isset($cell['weight']) && is_numeric($cell['weight']) ? (float) $cell['weight'] : 0.0,
            'has_style' => isset($cell['style']) && is_array($cell['style']) && $cell['style'] !== [],
            'widgets' => [],
        ];
    }

    $orphans = [];

    foreach ($layout['widgets'] as $wi => $widget) {
        $widget = is_array($widget) ? $widget : [];
        $info = nibwp_siteorigin_info($widget);
        $ri = isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : -1;
        $ci = isset($info['cell']) && is_scalar($info['cell']) ? (int) $info['cell'] : -1;
        $class = nibwp_siteorigin_class($widget);

        $entry = [
            'widget_index' => $wi,
            'widget_id' => isset($info['widget_id']) && is_scalar($info['widget_id']) ? (string) $info['widget_id'] : '',
            'class' => $class,
            'label' => isset($info['label']) && is_scalar($info['label']) ? (string) $info['label'] : '',
            'registered' => nibwp_siteorigin_class_registered($class),
            'setting_keys' => array_map('strval', array_keys(nibwp_siteorigin_settings($widget))),
        ];

        if (!isset($rows[$ri]['cells'][$ci])) {
            $orphans[] = $entry + ['row' => $ri, 'cell' => $ci];

            continue;
        }

        $entry['index'] = count($rows[$ri]['cells'][$ci]['widgets']);
        $rows[$ri]['cells'][$ci]['widgets'][] = $entry;
    }

    return ['rows' => array_values($rows), 'orphans' => $orphans];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_get_layout(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $structure = nibwp_siteorigin_structure($layout);
    $post = $resolved['post'];

    return [
        'post_id' => (int) $post->ID,
        'title' => isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '',
        'rows' => $structure['rows'],
        'orphan_widgets' => $structure['orphans'],
        'totals' => [
            'rows' => count($layout['grids']),
            'cells' => count($layout['grid_cells']),
            'widgets' => count($layout['widgets']),
        ],
        'writable' => nibwp_siteorigin_can_write(),
    ];
}

/**
 * The same page, small enough to read on a layout with a hundred widgets.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_layout_outline(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $structure = nibwp_siteorigin_structure($resolved['layout']);
    $outline = [];

    foreach ($structure['rows'] as $row) {
        $cells = [];

        foreach ($row['cells'] as $cell) {
            $classes = [];

            foreach ($cell['widgets'] as $widget) {
                $classes[] = $widget['class'] . ($widget['registered'] ? '' : ' (not registered)');
            }

            $cells[] = [
                'cell' => $cell['cell'],
                'weight' => $cell['weight'],
                'widgets' => $classes,
            ];
        }

        $outline[] = [
            'row' => $row['row'],
            'label' => $row['label'],
            'cells' => $cells,
        ];
    }

    return [
        'post_id' => (int) $resolved['post']->ID,
        'outline' => $outline,
        'orphan_widgets' => count($structure['orphans']),
    ];
}

/**
 * The canonical layout exactly as stored, for a caller that means to transform it.
 *
 * Returned whole and unredacted on purpose: this is a page's structure and its
 * widget settings, which is the thing the caller already has the capability to
 * edit. It is not a credential store.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_layout_raw(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    return [
        'post_id' => (int) $resolved['post']->ID,
        'meta_key' => NIBWP_SITEORIGIN_META,
        'panels_data' => $resolved['layout'],
        'writable' => nibwp_siteorigin_can_write(),
    ];
}

/* ---------------------------------------------------- locating one widget */

/**
 * The flat positions of the widgets in one cell, in the order they render.
 *
 * A cell holds no list of its widgets — the renderer collects them by walking
 * every widget and reading its placement (inc/renderer.php:1202-1206), so order
 * within a cell is order within the flat list, and nothing else.
 *
 * @param array<string, mixed> $layout
 * @return list<int>
 */
function nibwp_siteorigin_cell_positions(array $layout, int $row, int $cell): array
{
    $positions = [];

    foreach ($layout['widgets'] as $i => $widget) {
        if (!is_array($widget)) {
            continue;
        }

        $info = nibwp_siteorigin_info($widget);
        $ri = isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : -1;
        $ci = isset($info['cell']) && is_scalar($info['cell']) ? (int) $info['cell'] : -1;

        if ($ri === $row && $ci === $cell) {
            $positions[] = (int) $i;
        }
    }

    return $positions;
}

/**
 * Turn a caller's way of naming a widget into its position in the flat list.
 *
 * widget_id is preferred and is offered first, because it survives the layout
 * being reordered — row/cell/index describe where a widget WAS at the time of
 * the read, and an agent that edits twice without re-reading will otherwise hit
 * the wrong one.
 *
 * @param array<string, mixed> $layout
 * @param array<string, mixed> $input
 * @return int|WP_Error
 */
function nibwp_siteorigin_locate_widget(array $layout, array $input): int|WP_Error
{
    $widget_id = isset($input['widget_id']) && is_scalar($input['widget_id']) ? (string) $input['widget_id'] : '';

    if ($widget_id !== '') {
        foreach ($layout['widgets'] as $i => $widget) {
            if (!is_array($widget)) {
                continue;
            }

            $info = nibwp_siteorigin_info($widget);

            if (isset($info['widget_id']) && is_scalar($info['widget_id']) && (string) $info['widget_id'] === $widget_id) {
                return (int) $i;
            }
        }

        return nibwp_siteorigin_err(
            'widget_not_found',
            sprintf(
                /* translators: %s: widget instance id */
                __('No widget with id "%s" is in this layout.', 'nibwp'),
                $widget_id
            ),
            404
        );
    }

    if (!isset($input['row'], $input['cell'])) {
        return nibwp_siteorigin_err(
            'widget_not_named',
            __('Name the widget with widget_id, or with row, cell and index.', 'nibwp')
        );
    }

    $row = is_scalar($input['row']) ? (int) $input['row'] : -1;
    $cell = is_scalar($input['cell']) ? (int) $input['cell'] : -1;
    $index = isset($input['index']) && is_scalar($input['index']) ? (int) $input['index'] : 0;

    $positions = nibwp_siteorigin_cell_positions($layout, $row, $cell);

    if (!isset($positions[$index])) {
        return nibwp_siteorigin_err(
            'widget_not_found',
            sprintf(
                /* translators: 1: widget index, 2: row index, 3: cell index */
                __('There is no widget %1$d in row %2$d cell %3$d.', 'nibwp'),
                $index,
                $row,
                $cell
            ),
            404
        );
    }

    return $positions[$index];
}

/**
 * Does this row and cell exist?
 *
 * @param array<string, mixed> $layout
 */
function nibwp_siteorigin_cell_exists(array $layout, int $row, int $cell): bool
{
    if (!isset($layout['grids'][$row])) {
        return false;
    }

    $seen = 0;

    foreach ($layout['grid_cells'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $ri = isset($entry['grid']) && is_scalar($entry['grid']) ? (int) $entry['grid'] : -1;

        if ($ri === $row) {
            if ($seen === $cell) {
                return true;
            }

            $seen++;
        }
    }

    return false;
}

/**
 * Renumber every widget's sequential `id`.
 *
 * The editor hands out `panels_info['id']` as a running counter over the flat
 * list (js/siteorigin-panels.js:3775), so any structural change has to restate
 * it. `widget_id` is NOT touched — that is the stable identity the sanitiser
 * pairs old settings against (inc/admin.php:1368), and rewriting it would make
 * every widget look brand new and lose the pairing.
 *
 * @param array<string, mixed> $layout
 * @return array<string, mixed>
 */
function nibwp_siteorigin_renumber(array $layout): array
{
    foreach ($layout['widgets'] as $i => $widget) {
        if (!is_array($widget)) {
            continue;
        }

        $key = nibwp_siteorigin_info_key($widget);
        $info = nibwp_siteorigin_info($widget);
        $info['id'] = $i;
        $widget[$key] = $info;
        $layout['widgets'][$i] = $widget;
    }

    return $layout;
}

/**
 * A fresh instance id for a widget being added.
 *
 * wp_generate_uuid4() when WordPress offers it, which it has since 4.7; the
 * fallback keeps the same shape so nothing downstream can tell the difference.
 */
function nibwp_siteorigin_new_widget_id(): string
{
    if (function_exists('wp_generate_uuid4')) {
        return (string) wp_generate_uuid4();
    }

    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

/**
 * Settings a caller supplied for a widget, with nothing that is not data.
 *
 * `panels_info` and `info` are structure and are managed here, so a caller
 * cannot smuggle a placement change in through the settings object and move a
 * widget without the move being checked.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_input_settings(mixed $raw): array|WP_Error
{
    if ($raw === null) {
        return [];
    }

    if (is_object($raw)) {
        $raw = (array) $raw;
    }

    if (!is_array($raw)) {
        return nibwp_siteorigin_err('bad_settings', __('The settings must be given as an object.', 'nibwp'));
    }

    unset($raw['panels_info'], $raw['info']);

    return $raw;
}

/* ------------------------------------------------------------ widget types */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_list_widget_types(array $input): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_author(__('Listing the available widget types', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_siteorigin_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) && is_scalar($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $widgets = [];

    if (class_exists('SiteOrigin_Panels_Admin') && method_exists('SiteOrigin_Panels_Admin', 'single')) {
        $admin = SiteOrigin_Panels_Admin::single();

        if (method_exists($admin, 'get_widgets')) {
            $widgets = $admin->get_widgets();
        }
    }

    if (!is_array($widgets) || $widgets === []) {
        // The factory is the floor: it is what the renderer resolves against.
        $factory = $GLOBALS['wp_widget_factory'] ?? null;
        $widgets = [];

        if (is_object($factory) && isset($factory->widgets) && is_array($factory->widgets)) {
            foreach ($factory->widgets as $class => $instance) {
                $widgets[(string) $class] = [
                    'class' => (string) $class,
                    'title' => is_object($instance) && isset($instance->name) && is_scalar($instance->name)
                        ? (string) $instance->name
                        : (string) $class,
                    'installed' => true,
                ];
            }
        }
    }

    $total = count($widgets);
    $types = [];

    foreach (array_slice($widgets, $offset, $limit, true) as $class => $entry) {
        $entry = is_array($entry) ? $entry : [];

        $types[] = [
            'class' => (string) $class,
            'title' => isset($entry['title']) && is_scalar($entry['title']) ? (string) $entry['title'] : (string) $class,
            'description' => isset($entry['description']) && is_scalar($entry['description']) ? (string) $entry['description'] : '',
            // "installed" false means the plugin advertises the widget but the
            // class is not here, so adding one would render nothing.
            'installed' => !isset($entry['installed']) || (bool) $entry['installed'],
            'usable' => nibwp_siteorigin_class_registered((string) $class),
        ];
    }

    return [
        'widget_types' => $types,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'truncated' => $offset + count($types) < $total,
    ];
}

/* ----------------------------------------------------------- widget reads */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_get_widget(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $at = nibwp_siteorigin_locate_widget($layout, $input);

    if ($at instanceof WP_Error) {
        return $at;
    }

    $widget = is_array($layout['widgets'][$at]) ? $layout['widgets'][$at] : [];
    $info = nibwp_siteorigin_info($widget);
    $class = nibwp_siteorigin_class($widget);

    return [
        'post_id' => (int) $resolved['post']->ID,
        'widget_index' => $at,
        'widget_id' => isset($info['widget_id']) && is_scalar($info['widget_id']) ? (string) $info['widget_id'] : '',
        'class' => $class,
        'registered' => nibwp_siteorigin_class_registered($class),
        'row' => isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : null,
        'cell' => isset($info['cell']) && is_scalar($info['cell']) ? (int) $info['cell'] : null,
        'label' => isset($info['label']) && is_scalar($info['label']) ? (string) $info['label'] : '',
        'settings' => nibwp_siteorigin_settings($widget),
        'writable' => nibwp_siteorigin_can_write(),
    ];
}

/* ---------------------------------------------------------- widget writes */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_update_widget(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $at = nibwp_siteorigin_locate_widget($layout, $input);

    if ($at instanceof WP_Error) {
        return $at;
    }

    $settings = nibwp_siteorigin_input_settings($input['settings'] ?? null);

    if ($settings instanceof WP_Error) {
        return $settings;
    }

    if ($settings === []) {
        return nibwp_siteorigin_err('no_settings', __('Give the settings to write.', 'nibwp'));
    }

    $merge = !isset($input['merge']) || (bool) $input['merge'];
    $widget = is_array($layout['widgets'][$at]) ? $layout['widgets'][$at] : [];
    $key = nibwp_siteorigin_info_key($widget);
    $info = nibwp_siteorigin_info($widget);
    $existing = nibwp_siteorigin_settings($widget);

    // Merge keeps every setting the caller did not mention — including ones this
    // code has no idea about, which on a third-party widget is most of them.
    $next = $merge ? array_replace($existing, $settings) : $settings;
    $next[$key] = $info;

    $layout['widgets'][$at] = $next;

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + [
        'widget_index' => $at,
        'merged' => $merge,
        'settings_written' => array_map('strval', array_keys($settings)),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_add_widget(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $class = isset($input['widget_class']) && is_scalar($input['widget_class']) ? trim((string) $input['widget_class']) : '';

    if ($class === '') {
        return nibwp_siteorigin_err('no_widget_class', __('A widget_class is required.', 'nibwp'));
    }

    if (!nibwp_siteorigin_class_registered($class)) {
        return nibwp_siteorigin_err(
            'widget_class_unknown',
            sprintf(
                /* translators: %s: widget class name */
                __('No widget class "%s" is registered on this site, so it would render nothing. list_widget_types reports what is available.', 'nibwp'),
                $class
            )
        );
    }

    $row = isset($input['row']) && is_scalar($input['row']) ? (int) $input['row'] : -1;
    $cell = isset($input['cell']) && is_scalar($input['cell']) ? (int) $input['cell'] : -1;

    if (!nibwp_siteorigin_cell_exists($layout, $row, $cell)) {
        return nibwp_siteorigin_err(
            'cell_not_found',
            sprintf(
                /* translators: 1: row index, 2: cell index */
                __('Row %1$d cell %2$d is not in this layout.', 'nibwp'),
                $row,
                $cell
            ),
            404
        );
    }

    $settings = nibwp_siteorigin_input_settings($input['settings'] ?? null);

    if ($settings instanceof WP_Error) {
        return $settings;
    }

    $widget_id = nibwp_siteorigin_new_widget_id();

    // `panels_info` and not the legacy `info`: a widget being created now is a
    // current-format widget, whatever vintage the rest of the layout is.
    $settings['panels_info'] = [
        'class' => $class,
        'raw' => false,
        'grid' => $row,
        'cell' => $cell,
        'id' => count($layout['widgets']),
        'widget_id' => $widget_id,
        'style' => [],
        'label' => '',
    ];

    $positions = nibwp_siteorigin_cell_positions($layout, $row, $cell);
    $to_index = isset($input['to_index']) && is_scalar($input['to_index']) ? max(0, (int) $input['to_index']) : count($positions);

    $layout['widgets'] = nibwp_siteorigin_splice_in($layout['widgets'], $positions, $to_index, $settings);
    $layout = nibwp_siteorigin_renumber($layout);

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + [
        'widget_id' => $widget_id,
        'class' => $class,
        'row' => $row,
        'cell' => $cell,
    ];
}

/**
 * Put a widget into the flat list so that it lands at $to_index inside its cell.
 *
 * Order within a cell is order within the flat list, so an insert has to go at
 * the flat position of the widget it should precede. An empty cell has no such
 * position, and appending is correct there: flat order only decides sequence
 * among widgets that share a cell.
 *
 * @param list<mixed> $widgets
 * @param list<int> $positions
 * @param array<string, mixed> $widget
 * @return list<mixed>
 */
function nibwp_siteorigin_splice_in(array $widgets, array $positions, int $to_index, array $widget): array
{
    if ($positions === []) {
        $widgets[] = $widget;

        return array_values($widgets);
    }

    if (isset($positions[$to_index])) {
        $at = $positions[$to_index];
    } else {
        $at = $positions[count($positions) - 1] + 1;
    }

    array_splice($widgets, $at, 0, [$widget]);

    return array_values($widgets);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_remove_widget(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $at = nibwp_siteorigin_locate_widget($layout, $input);

    if ($at instanceof WP_Error) {
        return $at;
    }

    $widget = is_array($layout['widgets'][$at]) ? $layout['widgets'][$at] : [];
    $class = nibwp_siteorigin_class($widget);

    array_splice($layout['widgets'], $at, 1);
    $layout = nibwp_siteorigin_renumber($layout);

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + ['removed_class' => $class, 'widgets_remaining' => count($layout['widgets'])];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_move_widget(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $at = nibwp_siteorigin_locate_widget($layout, $input);

    if ($at instanceof WP_Error) {
        return $at;
    }

    $widget = is_array($layout['widgets'][$at]) ? $layout['widgets'][$at] : [];
    $key = nibwp_siteorigin_info_key($widget);
    $info = nibwp_siteorigin_info($widget);

    $to_row = isset($input['to_row']) && is_scalar($input['to_row'])
        ? (int) $input['to_row']
        : (isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : -1);
    $to_cell = isset($input['to_cell']) && is_scalar($input['to_cell'])
        ? (int) $input['to_cell']
        : (isset($info['cell']) && is_scalar($info['cell']) ? (int) $info['cell'] : -1);

    if (!nibwp_siteorigin_cell_exists($layout, $to_row, $to_cell)) {
        return nibwp_siteorigin_err(
            'cell_not_found',
            sprintf(
                /* translators: 1: row index, 2: cell index */
                __('Row %1$d cell %2$d is not in this layout.', 'nibwp'),
                $to_row,
                $to_cell
            ),
            404
        );
    }

    $info['grid'] = $to_row;
    $info['cell'] = $to_cell;
    $widget[$key] = $info;

    // Lift it out first, so the destination offsets are the ones that will exist
    // once it is gone — otherwise a move within one cell lands one place off.
    array_splice($layout['widgets'], $at, 1);
    $positions = nibwp_siteorigin_cell_positions($layout, $to_row, $to_cell);
    $to_index = isset($input['to_index']) && is_scalar($input['to_index']) ? max(0, (int) $input['to_index']) : count($positions);

    $layout['widgets'] = nibwp_siteorigin_splice_in($layout['widgets'], $positions, $to_index, $widget);
    $layout = nibwp_siteorigin_renumber($layout);

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + ['to_row' => $to_row, 'to_cell' => $to_cell, 'to_index' => $to_index];
}

/* ---------------------------------------------------------- rows and cells */

/** A refusal about authority to work with the builder at all. */
function nibwp_siteorigin_author_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_siteorigin_author_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            'edit_posts'
        ),
        ['status' => 403]
    );
}

/**
 * The entry bar for the reads that are not about one page.
 *
 * Deliberately a DIFFERENT code from the per-post and the site-wide refusals: a
 * caller has to be able to tell "you are not an author here" from "you cannot
 * edit that page" from "that is an administrator setting", and three checks
 * sharing one code are three checks of which only one is really tested.
 */
function nibwp_siteorigin_require_author(string $what): ?WP_Error
{
    return current_user_can('edit_posts') ? null : nibwp_siteorigin_author_forbidden($what);
}

/**
 * Normalise cell weights so a row's cells add up to the whole row.
 *
 * The renderer turns a weight into a percentage of the row directly
 * (inc/renderer.php:274), so weights that do not sum to 1 give a row that is too
 * narrow or overflows. A zero or negative weight is a cell with no width, which
 * is a mistake rather than a layout, so it is refused rather than silently fixed.
 *
 * @param list<mixed> $raw
 * @return list<float>|WP_Error
 */
function nibwp_siteorigin_weights(array $raw): array|WP_Error
{
    $weights = [];
    $total = 0.0;

    foreach ($raw as $value) {
        if (!is_numeric($value)) {
            return nibwp_siteorigin_err('bad_weight', __('Every cell weight must be a number.', 'nibwp'));
        }

        $weight = (float) $value;

        if ($weight <= 0) {
            return nibwp_siteorigin_err('bad_weight', __('Every cell weight must be greater than zero.', 'nibwp'));
        }

        $weights[] = $weight;
        $total += $weight;
    }

    if ($weights === [] || $total <= 0) {
        return nibwp_siteorigin_err('bad_weight', __('Give at least one cell weight.', 'nibwp'));
    }

    foreach ($weights as $i => $weight) {
        $weights[$i] = round($weight / $total, 6);
    }

    return $weights;
}

/** Equal weights for a row of $count cells. */
function nibwp_siteorigin_even_weights(int $count): array
{
    $weights = [];

    for ($i = 0; $i < $count; $i++) {
        $weights[] = round(1 / $count, 6);
    }

    return $weights;
}

/**
 * The flat grid_cells positions belonging to one row, in order.
 *
 * @param array<string, mixed> $layout
 * @return list<int>
 */
function nibwp_siteorigin_row_cell_positions(array $layout, int $row): array
{
    $positions = [];

    foreach ($layout['grid_cells'] as $i => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $ri = isset($entry['grid']) && is_scalar($entry['grid']) ? (int) $entry['grid'] : -1;

        if ($ri === $row) {
            $positions[] = (int) $i;
        }
    }

    return $positions;
}

/**
 * Rewrite every `grid` pointer in the layout through a row index map.
 *
 * Placement lives on the widget and on the cell, so a structural change to the
 * rows has to be followed through both lists or widgets end up in the wrong row,
 * or in no row at all. A row that the map does not mention is a row being
 * deleted, and anything pointing at it is dropped with it.
 *
 * @param array<string, mixed> $layout
 * @param array<int, int> $map old row index => new row index
 * @return array<string, mixed>
 */
function nibwp_siteorigin_remap_rows(array $layout, array $map): array
{
    $cells = [];

    foreach ($layout['grid_cells'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $ri = isset($entry['grid']) && is_scalar($entry['grid']) ? (int) $entry['grid'] : -1;

        if (!array_key_exists($ri, $map)) {
            continue;
        }

        $entry['grid'] = $map[$ri];
        $cells[] = $entry;
    }

    // grid_cells is read in order and the cell index of a widget is its ordinal
    // WITHIN ITS ROW, so the list has to come back out grouped by row and in the
    // original within-row order (inc/renderer.php:1194-1200).
    usort($cells, static function ($a, $b): int {
        return (int) $a['grid'] <=> (int) $b['grid'];
    });

    $layout['grid_cells'] = $cells;

    $widgets = [];

    foreach ($layout['widgets'] as $widget) {
        if (!is_array($widget)) {
            continue;
        }

        $key = nibwp_siteorigin_info_key($widget);
        $info = nibwp_siteorigin_info($widget);
        $ri = isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : -1;

        if (!array_key_exists($ri, $map)) {
            continue;
        }

        $info['grid'] = $map[$ri];
        $widget[$key] = $info;
        $widgets[] = $widget;
    }

    $layout['widgets'] = $widgets;

    return nibwp_siteorigin_renumber($layout);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_list_rows(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $structure = nibwp_siteorigin_structure($layout);
    $rows = [];

    foreach ($structure['rows'] as $row) {
        $widgets = 0;
        $weights = [];

        foreach ($row['cells'] as $cell) {
            $widgets += count($cell['widgets']);
            $weights[] = $cell['weight'];
        }

        $rows[] = [
            'row' => $row['row'],
            'label' => $row['label'],
            'cells' => count($row['cells']),
            'declared_cells' => $row['declared_cells'],
            'weights' => $weights,
            'widgets' => $widgets,
            'has_style' => $row['has_style'],
        ];
    }

    return [
        'post_id' => (int) $resolved['post']->ID,
        'rows' => $rows,
        'writable' => nibwp_siteorigin_can_write(),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_add_row(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $count = isset($input['cells']) && is_scalar($input['cells']) ? (int) $input['cells'] : 1;

    if ($count < 1 || $count > 12) {
        return nibwp_siteorigin_err('bad_cell_count', __('A row has between 1 and 12 cells.', 'nibwp'));
    }

    if (isset($input['weights'])) {
        if (!is_array($input['weights'])) {
            return nibwp_siteorigin_err('bad_weight', __('The weights must be given as a list of numbers.', 'nibwp'));
        }

        $weights = nibwp_siteorigin_weights(array_values($input['weights']));

        if ($weights instanceof WP_Error) {
            return $weights;
        }

        if (count($weights) !== $count) {
            return nibwp_siteorigin_err('weight_count', __('Give one weight per cell.', 'nibwp'));
        }
    } else {
        $weights = nibwp_siteorigin_even_weights($count);
    }

    $existing = count($layout['grids']);
    $position = isset($input['position']) && is_scalar($input['position'])
        ? max(0, min($existing, (int) $input['position']))
        : $existing;

    // Shift every row at or after the insertion point up by one, then drop the
    // new row into the gap.
    $map = [];

    for ($i = 0; $i < $existing; $i++) {
        $map[$i] = $i < $position ? $i : $i + 1;
    }

    $layout = nibwp_siteorigin_remap_rows($layout, $map);

    $grids = $layout['grids'];
    array_splice($grids, $position, 0, [[
        'cells' => $count,
        'style' => [],
        'color_label' => '',
        'label' => '',
    ]]);
    $layout['grids'] = array_values($grids);

    foreach ($weights as $i => $weight) {
        $layout['grid_cells'][] = ['grid' => $position, 'index' => $i, 'weight' => $weight, 'style' => []];
    }

    $cells = $layout['grid_cells'];
    usort($cells, static function ($a, $b): int {
        return (int) $a['grid'] <=> (int) $b['grid'];
    });
    $layout['grid_cells'] = array_values($cells);

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + ['row' => $position, 'cells' => $count, 'weights' => $weights];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_remove_row(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $row = isset($input['row']) && is_scalar($input['row']) ? (int) $input['row'] : -1;

    if (!isset($layout['grids'][$row])) {
        return nibwp_siteorigin_err(
            'row_not_found',
            sprintf(
                /* translators: %d: row index */
                __('Row %d is not in this layout.', 'nibwp'),
                $row
            ),
            404
        );
    }

    // Deleting a row deletes its cells, and a widget has nowhere else to be, so a
    // row holding content is refused rather than quietly taking the content with
    // it. Move or remove the widgets first and the intent is on the record.
    $holding = 0;

    foreach ($layout['widgets'] as $widget) {
        if (!is_array($widget)) {
            continue;
        }

        $info = nibwp_siteorigin_info($widget);

        if (isset($info['grid']) && is_scalar($info['grid']) && (int) $info['grid'] === $row) {
            $holding++;
        }
    }

    if ($holding > 0) {
        return nibwp_siteorigin_err(
            'row_not_empty',
            sprintf(
                /* translators: 1: row index, 2: number of widgets */
                __('Row %1$d still holds %2$d widget(s). Move them with move_widget or delete them with remove_widget first — removing the row would take them with it.', 'nibwp'),
                $row,
                $holding
            ),
            409
        );
    }

    $existing = count($layout['grids']);
    $map = [];

    for ($i = 0; $i < $existing; $i++) {
        if ($i === $row) {
            continue;
        }

        $map[$i] = $i < $row ? $i : $i - 1;
    }

    $layout = nibwp_siteorigin_remap_rows($layout, $map);

    $grids = $layout['grids'];
    array_splice($grids, $row, 1);
    $layout['grids'] = array_values($grids);

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + ['removed_row' => $row, 'rows_remaining' => count($layout['grids'])];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_reorder_rows(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $existing = count($layout['grids']);

    if (!isset($input['order']) || !is_array($input['order'])) {
        return nibwp_siteorigin_err('no_order', __('Give the order as a list of the current row indexes.', 'nibwp'));
    }

    $order = [];

    foreach (array_values($input['order']) as $value) {
        if (!is_numeric($value)) {
            return nibwp_siteorigin_err('bad_order', __('Every entry in the order must be a row index.', 'nibwp'));
        }

        $order[] = (int) $value;
    }

    // A partial order would silently delete the rows it left out, so the order
    // has to account for every row exactly once.
    $expected = range(0, max(0, $existing - 1));
    $sorted = $order;
    sort($sorted);

    if ($existing === 0 || $sorted !== $expected) {
        return nibwp_siteorigin_err(
            'bad_order',
            sprintf(
                /* translators: %d: number of rows */
                __('The order must list each of the %d row indexes exactly once.', 'nibwp'),
                $existing
            )
        );
    }

    $map = [];

    foreach ($order as $new => $old) {
        $map[$old] = $new;
    }

    $grids = [];

    foreach ($order as $old) {
        $grids[] = $layout['grids'][$old];
    }

    $layout = nibwp_siteorigin_remap_rows($layout, $map);
    $layout['grids'] = $grids;

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + ['order' => $order];
}

/**
 * Change how many cells a row has, and how wide each one is.
 *
 * Shrinking a row would strand the widgets in the cells that go, so they are
 * moved into the last surviving cell and the response says how many moved. That
 * is non-destructive and recoverable; dropping them would not be.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_set_row_cells(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $row = isset($input['row']) && is_scalar($input['row']) ? (int) $input['row'] : -1;

    if (!isset($layout['grids'][$row])) {
        return nibwp_siteorigin_err(
            'row_not_found',
            sprintf(
                /* translators: %d: row index */
                __('Row %d is not in this layout.', 'nibwp'),
                $row
            ),
            404
        );
    }

    $positions = nibwp_siteorigin_row_cell_positions($layout, $row);
    $current = count($positions);

    if (isset($input['weights'])) {
        if (!is_array($input['weights'])) {
            return nibwp_siteorigin_err('bad_weight', __('The weights must be given as a list of numbers.', 'nibwp'));
        }

        $weights = nibwp_siteorigin_weights(array_values($input['weights']));

        if ($weights instanceof WP_Error) {
            return $weights;
        }

        $count = isset($input['cells']) && is_scalar($input['cells']) ? (int) $input['cells'] : count($weights);

        if (count($weights) !== $count) {
            return nibwp_siteorigin_err('weight_count', __('Give one weight per cell.', 'nibwp'));
        }
    } else {
        $count = isset($input['cells']) && is_scalar($input['cells']) ? (int) $input['cells'] : $current;
        $weights = nibwp_siteorigin_even_weights(max(1, $count));
    }

    if ($count < 1 || $count > 12) {
        return nibwp_siteorigin_err('bad_cell_count', __('A row has between 1 and 12 cells.', 'nibwp'));
    }

    // Keep each surviving cell's own style: it is the owner's design, and a cell
    // count change is not a request to reset it.
    $kept = [];

    for ($i = 0; $i < $count; $i++) {
        $existing = isset($positions[$i]) && is_array($layout['grid_cells'][$positions[$i]])
            ? $layout['grid_cells'][$positions[$i]]
            : ['grid' => $row, 'index' => $i, 'style' => []];

        $existing['grid'] = $row;
        $existing['index'] = $i;
        $existing['weight'] = $weights[$i];
        $kept[] = $existing;
    }

    $cells = [];
    $written_row = false;

    foreach ($layout['grid_cells'] as $i => $entry) {
        if (in_array($i, $positions, true)) {
            if (!$written_row) {
                foreach ($kept as $cell) {
                    $cells[] = $cell;
                }

                $written_row = true;
            }

            continue;
        }

        $cells[] = $entry;
    }

    if (!$written_row) {
        foreach ($kept as $cell) {
            $cells[] = $cell;
        }
    }

    $layout['grid_cells'] = array_values($cells);

    $grid = is_array($layout['grids'][$row]) ? $layout['grids'][$row] : [];
    $grid['cells'] = $count;
    $layout['grids'][$row] = $grid;

    $moved = 0;
    $last = $count - 1;

    foreach ($layout['widgets'] as $i => $widget) {
        if (!is_array($widget)) {
            continue;
        }

        $key = nibwp_siteorigin_info_key($widget);
        $info = nibwp_siteorigin_info($widget);
        $ri = isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : -1;
        $ci = isset($info['cell']) && is_scalar($info['cell']) ? (int) $info['cell'] : -1;

        if ($ri !== $row || $ci <= $last) {
            continue;
        }

        $info['cell'] = $last;
        $widget[$key] = $info;
        $layout['widgets'][$i] = $widget;
        $moved++;
    }

    $layout = nibwp_siteorigin_renumber($layout);

    $result = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($result instanceof WP_Error) {
        return $result;
    }

    return $result + ['row' => $row, 'cells' => $count, 'weights' => $weights, 'widgets_moved' => $moved];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_set_cell_weights(array $input): array|WP_Error
{
    $resolved = nibwp_siteorigin_resolve($input['post_id'] ?? null);

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $layout = $resolved['layout'];
    $row = isset($input['row']) && is_scalar($input['row']) ? (int) $input['row'] : -1;

    if (!isset($layout['grids'][$row])) {
        return nibwp_siteorigin_err(
            'row_not_found',
            sprintf(
                /* translators: %d: row index */
                __('Row %d is not in this layout.', 'nibwp'),
                $row
            ),
            404
        );
    }

    if (!isset($input['weights']) || !is_array($input['weights'])) {
        return nibwp_siteorigin_err('no_weights', __('Give the weights as a list of numbers, one per cell.', 'nibwp'));
    }

    $weights = nibwp_siteorigin_weights(array_values($input['weights']));

    if ($weights instanceof WP_Error) {
        return $weights;
    }

    $positions = nibwp_siteorigin_row_cell_positions($layout, $row);

    if (count($weights) !== count($positions)) {
        return nibwp_siteorigin_err(
            'weight_count',
            sprintf(
                /* translators: 1: number of weights given, 2: number of cells in the row */
                __('%1$d weights were given for a row of %2$d cells. Use set_row_cells to change how many cells the row has.', 'nibwp'),
                count($weights),
                count($positions)
            )
        );
    }

    // Only the weight is touched. Everything else on the cell — its style, and
    // any key a newer version or a third party put there — is left exactly as it
    // was found.
    foreach ($positions as $i => $at) {
        $cell = is_array($layout['grid_cells'][$at]) ? $layout['grid_cells'][$at] : [];
        $cell['weight'] = $weights[$i];
        $layout['grid_cells'][$at] = $cell;
    }

    $written = nibwp_siteorigin_write_layout((int) $resolved['post']->ID, $layout);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return $written + ['row' => $row, 'weights' => $weights];
}

/* ----------------------------------------------- the plugin's own settings */

/**
 * Settings whose VALUE may be reported, and what kind of value each one is.
 *
 * An allow-list rather than a denylist. The settings array is one flat key space
 * that themes and third parties add to through a public filter
 * (inc/settings.php:76), so naming the handful that are safe is tractable and
 * guessing every key that is not is not — and a key added by a later version
 * would default to travelling.
 *
 * Deliberately absent: `affiliate-id` (inc/settings.php:108). It is a
 * third-party identifier belonging to whoever installed the site, it is of no
 * use to a caller, and the rule for anything of that kind is that it does not
 * leave.
 *
 * @return array<string, string>
 */
function nibwp_siteorigin_public_settings(): array
{
    return [
        'post-types' => 'string_list',
        'responsive' => 'bool',
        'tablet-layout' => 'bool',
        'legacy-layout' => 'string',
        'tablet-width' => 'int',
        'mobile-width' => 'int',
        'margin-bottom' => 'int',
        'margin-sides' => 'int',
        'mobile-cell-margin' => 'int',
        'margin-bottom-last-row' => 'bool',
        'display-empty-rows-with-background' => 'bool',
        'full-width-container' => 'string',
        'output-css-header' => 'string',
        'inline-styles' => 'bool',
        'copy-content' => 'bool',
        'copy-styles' => 'bool',
        'sidebars-emulator' => 'bool',
        'use-classic' => 'bool',
        'admin-post-state' => 'bool',
        'admin-widget-count' => 'bool',
        'instant-open-widgets' => 'bool',
        'recommended-widgets' => 'bool',
        'bundled-widgets' => 'bool',
        'add-widget-class' => 'bool',
        'title-html' => 'string',
        'parallax-type' => 'string',
        'parallax-mobile' => 'bool',
        'layout-block-default-mode' => 'string',
        'layout-block-quick-add' => 'bool',
        'home-page' => 'bool',
        'home-template' => 'string',
    ];
}

/**
 * Report one allow-listed setting, or report that it could not be reported.
 *
 * A non-scalar in a scalar slot is DROPPED, not cast. Casting an array to a
 * string is both a PHP notice and a lie about what is stored, and the honest
 * answer — the key is there, this is not something that can be shown — is more
 * use than "Array".
 *
 * @return array{value?: mixed, dropped?: bool}
 */
function nibwp_siteorigin_report_setting(string $type, mixed $value): array
{
    if ($type === 'string_list') {
        if (!is_array($value)) {
            return ['dropped' => true];
        }

        $list = [];

        foreach ($value as $entry) {
            if (is_scalar($entry)) {
                $list[] = (string) $entry;
            }
        }

        return ['value' => $list];
    }

    if (!is_scalar($value)) {
        return ['dropped' => true];
    }

    return ['value' => match ($type) {
        'bool' => (bool) $value,
        'int' => (int) $value,
        default => (string) $value,
    }];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_get_settings(): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_admin(__('Reading the Page Builder settings', 'nibwp'))) {
        return $denied;
    }

    $stored = get_option('siteorigin_panels_settings', []);
    $stored = is_array($stored) ? $stored : [];
    $allowed = nibwp_siteorigin_public_settings();

    $settings = [];
    $withheld = [];

    foreach ($allowed as $key => $type) {
        // siteorigin_panels_setting() is the resolved value, which is what the
        // editor actually behaves by: the stored option is only the overrides.
        $value = array_key_exists($key, $stored) ? $stored[$key] : nibwp_siteorigin_setting($key);
        $report = nibwp_siteorigin_report_setting($type, $value);

        if (array_key_exists('value', $report)) {
            $settings[$key] = $report['value'];

            continue;
        }

        $withheld[] = ['key' => $key, 'is_set' => array_key_exists($key, $stored), 'reason' => 'not_a_scalar'];
    }

    foreach ($stored as $key => $value) {
        $key = (string) $key;

        if (isset($allowed[$key])) {
            continue;
        }

        // Name and whether it is set, and nothing else. A key nobody has vouched
        // for could be anything, including somebody's credential.
        $withheld[] = ['key' => $key, 'is_set' => $value !== '' && $value !== null && $value !== false, 'reason' => 'not_allow_listed'];
    }

    return [
        'option' => 'siteorigin_panels_settings',
        'settings' => $settings,
        'withheld' => $withheld,
        'note' => __('Values are reported for an allow-listed set of layout and behaviour switches. Everything else is reported as a name and whether it is set.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_update_settings(array $input): array|WP_Error
{
    // These change the editor for every author on the site, which is an
    // administrator's decision and not an author's.
    if ($denied = nibwp_siteorigin_require_admin(__('Changing the Page Builder settings', 'nibwp'))) {
        return $denied;
    }

    $changes = $input['settings_update'] ?? null;

    if (is_object($changes)) {
        $changes = (array) $changes;
    }

    if (!is_array($changes) || $changes === []) {
        return nibwp_siteorigin_err('no_settings', __('Give the settings to change in settings_update.', 'nibwp'));
    }

    $allowed = nibwp_siteorigin_public_settings();
    $stored = get_option('siteorigin_panels_settings', []);
    $stored = is_array($stored) ? $stored : [];

    $written = [];
    $refused = [];

    foreach ($changes as $key => $value) {
        $key = (string) $key;

        if (!isset($allowed[$key])) {
            // The write allow-list is the read allow-list: a key this code cannot
            // describe is a key it should not be setting either.
            $refused[] = $key;

            continue;
        }

        $type = $allowed[$key];

        if ($type === 'string_list') {
            if (!is_array($value)) {
                $refused[] = $key;

                continue;
            }

            $list = [];

            foreach ($value as $entry) {
                if (is_scalar($entry)) {
                    $list[] = sanitize_key((string) $entry);
                }
            }

            $stored[$key] = $list;
            $written[$key] = $list;

            continue;
        }

        if (!is_scalar($value)) {
            $refused[] = $key;

            continue;
        }

        $stored[$key] = match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            default => sanitize_text_field((string) $value),
        };
        $written[$key] = $stored[$key];
    }

    if ($written === []) {
        return nibwp_siteorigin_err(
            'nothing_written',
            sprintf(
                /* translators: %s: comma-separated list of setting names */
                __('None of those settings can be written here: %s. get_settings lists the ones that can.', 'nibwp'),
                implode(', ', $refused)
            )
        );
    }

    update_option('siteorigin_panels_settings', $stored);

    return ['written' => $written, 'refused' => $refused];
}

/**
 * The widget bundle the plugin ships with, and whether it is in use.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_widget_bundle(): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_author(__('Reading the widget bundle status', 'nibwp'))) {
        return $denied;
    }

    $widgets = [];

    if (class_exists('SiteOrigin_Panels_Admin') && method_exists('SiteOrigin_Panels_Admin', 'single')) {
        $admin = SiteOrigin_Panels_Admin::single();

        if (method_exists($admin, 'get_widgets')) {
            $all = $admin->get_widgets();

            foreach (is_array($all) ? $all : [] as $class => $entry) {
                $entry = is_array($entry) ? $entry : [];
                $groups = isset($entry['groups']) && is_array($entry['groups']) ? $entry['groups'] : [];

                if (!in_array('so-widgets-bundle', $groups, true)) {
                    continue;
                }

                $widgets[] = [
                    'class' => (string) $class,
                    'title' => isset($entry['title']) && is_scalar($entry['title']) ? (string) $entry['title'] : (string) $class,
                    'installed' => !isset($entry['installed']) || (bool) $entry['installed'],
                ];
            }
        }
    }

    return [
        // The plugin bundles its own copy of the widget set and this setting says
        // whether the site is using it rather than the separate plugin.
        'using_bundled' => (bool) nibwp_siteorigin_setting('bundled-widgets'),
        'bundle_plugin_active' => class_exists('SiteOrigin_Widgets_Bundle'),
        'recommended_widgets_shown' => (bool) nibwp_siteorigin_setting('recommended-widgets'),
        'bundle_widgets' => $widgets,
    ];
}

/* -------------------------------------------------------- reusable layouts */

/**
 * The reusable layouts this build offers.
 *
 * These come in through a public filter rather than a table: the theme can ship
 * JSON layouts in a folder and the plugin collects them
 * (inc/admin-layouts.php:110-180), and other plugins add their own. An empty
 * list means this site has none, not that the feature is missing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_list_prebuilt_layouts(array $input): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_author(__('Listing the reusable layouts', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_siteorigin_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) && is_scalar($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $layouts = apply_filters('siteorigin_panels_prebuilt_layouts', []);
    $layouts = is_array($layouts) ? $layouts : [];
    $total = count($layouts);

    $list = [];

    foreach (array_slice($layouts, $offset, $limit, true) as $id => $layout) {
        $layout = is_array($layout) ? $layout : [];
        $normalised = nibwp_siteorigin_normalise($layout);

        $list[] = [
            'layout_id' => (string) $id,
            'name' => isset($layout['name']) && is_scalar($layout['name']) ? (string) $layout['name'] : (string) $id,
            'rows' => count($normalised['grids']),
            'widgets' => count($normalised['widgets']),
        ];
    }

    return [
        'layouts' => $list,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'truncated' => $offset + count($list) < $total,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_get_prebuilt_layout(array $input): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_author(__('Reading a reusable layout', 'nibwp'))) {
        return $denied;
    }

    $id = isset($input['layout_id']) && is_scalar($input['layout_id']) ? (string) $input['layout_id'] : '';

    if ($id === '') {
        return nibwp_siteorigin_err('no_layout_id', __('A layout_id is required.', 'nibwp'));
    }

    $layouts = apply_filters('siteorigin_panels_prebuilt_layouts', []);
    $layouts = is_array($layouts) ? $layouts : [];

    if (!isset($layouts[$id]) || !is_array($layouts[$id])) {
        return nibwp_siteorigin_err(
            'prebuilt_not_found',
            sprintf(
                /* translators: %s: reusable layout id */
                __('No reusable layout "%s" is available on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    $layout = nibwp_siteorigin_normalise($layouts[$id]);
    $structure = nibwp_siteorigin_structure($layout);

    return [
        'layout_id' => $id,
        'name' => isset($layouts[$id]['name']) && is_scalar($layouts[$id]['name']) ? (string) $layouts[$id]['name'] : $id,
        'rows' => $structure['rows'],
        'panels_data' => $layout,
    ];
}

/* ------------------------------------------------------------------- audit */

/**
 * Every fault class the audit knows how to find.
 *
 * @return array<string, string>
 */
function nibwp_siteorigin_checks(): array
{
    return [
        'missing_widget_class' => 'A widget whose class is not registered on this site. It renders nothing at all, which is what "my section vanished" almost always turns out to be.',
        'orphan_widget' => 'A widget pointing at a row or cell that is not in the layout. It renders nowhere.',
        'empty_row' => 'A row holding no widgets in any of its cells.',
        'empty_cell' => 'A cell holding no widgets. One or two are often deliberate spacing; a row of them is not.',
        'cell_count_mismatch' => "A row's stored cell count does not match the cells actually in the layout. The editor and the front end will disagree about the row.",
        'content_renders_instead' => 'A layout exists but has no rows, so Page Builder renders nothing and the plain editor content is what the visitor sees.',
        'stale_content' => "The rendered copy of the page kept in post_content does not match the layout, so visitors see the previous version until the page is saved again.",
    ];
}

/**
 * Audit one layout.
 *
 * @param array<string, mixed> $layout
 * @param object $post
 * @return list<array<string, mixed>>
 */
function nibwp_siteorigin_audit_layout(array $layout, object $post, ?string $only = null): array
{
    $findings = [];
    $post_id = isset($post->ID) ? (int) $post->ID : 0;

    $want = static function (string $check) use ($only): bool {
        return $only === null || $only === $check;
    };

    $structure = nibwp_siteorigin_structure($layout);

    if ($want('missing_widget_class')) {
        foreach ($layout['widgets'] as $i => $widget) {
            $widget = is_array($widget) ? $widget : [];
            $class = nibwp_siteorigin_class($widget);

            if (nibwp_siteorigin_class_registered($class)) {
                continue;
            }

            $info = nibwp_siteorigin_info($widget);

            $findings[] = [
                'check' => 'missing_widget_class',
                'post_id' => $post_id,
                'widget_index' => (int) $i,
                'widget_id' => isset($info['widget_id']) && is_scalar($info['widget_id']) ? (string) $info['widget_id'] : '',
                'class' => $class,
                'row' => isset($info['grid']) && is_scalar($info['grid']) ? (int) $info['grid'] : null,
                'cell' => isset($info['cell']) && is_scalar($info['cell']) ? (int) $info['cell'] : null,
            ];
        }
    }

    if ($want('orphan_widget')) {
        foreach ($structure['orphans'] as $orphan) {
            $findings[] = [
                'check' => 'orphan_widget',
                'post_id' => $post_id,
                'widget_index' => $orphan['widget_index'],
                'widget_id' => $orphan['widget_id'],
                'class' => $orphan['class'],
                'row' => $orphan['row'],
                'cell' => $orphan['cell'],
            ];
        }
    }

    foreach ($structure['rows'] as $row) {
        $widgets = 0;

        foreach ($row['cells'] as $cell) {
            $widgets += count($cell['widgets']);

            if ($want('empty_cell') && $cell['widgets'] === []) {
                $findings[] = [
                    'check' => 'empty_cell',
                    'post_id' => $post_id,
                    'row' => $row['row'],
                    'cell' => $cell['cell'],
                    'has_style' => $cell['has_style'],
                ];
            }
        }

        if ($want('empty_row') && $widgets === 0) {
            $findings[] = [
                'check' => 'empty_row',
                'post_id' => $post_id,
                'row' => $row['row'],
                'cells' => count($row['cells']),
                // A row with a background and no widgets can be deliberate: it is a
                // band of colour. Said here so nobody deletes one on this report.
                'has_style' => $row['has_style'],
            ];
        }

        if ($want('cell_count_mismatch') && $row['declared_cells'] !== count($row['cells'])) {
            $findings[] = [
                'check' => 'cell_count_mismatch',
                'post_id' => $post_id,
                'row' => $row['row'],
                'declared_cells' => $row['declared_cells'],
                'actual_cells' => count($row['cells']),
            ];
        }
    }

    // A layout with no rows renders as nothing (inc/renderer.php:711), and the
    // the_content filter then hands over the stored editor content instead. The
    // owner sees an old page they thought they had replaced.
    if ($want('content_renders_instead') && $layout['grids'] === []) {
        $findings[] = [
            'check' => 'content_renders_instead',
            'post_id' => $post_id,
            'widgets_stranded' => count($layout['widgets']),
        ];
    }

    if ($want('stale_content')) {
        $stale = nibwp_siteorigin_stale_content($layout, $post);

        if ($stale !== null) {
            $findings[] = ['check' => 'stale_content', 'post_id' => $post_id] + $stale;
        }
    }

    return $findings;
}

/**
 * Is the rendered copy of this page out of step with its layout?
 *
 * With the copy-content setting on — and it is on by default
 * (inc/settings.php:184) — the plugin keeps a rendered copy of the layout in
 * post_content so that search and feeds see real text. Every widget in that copy
 * is wrapped in an element whose id is panel-{post}-{row}-{cell}-{widget}
 * (inc/renderer.php:957), so counting those markers and comparing the count to
 * the layout's widget count tells us whether the copy is the current layout
 * without rendering anything.
 *
 * It is a count, not a diff: it catches a copy written before widgets were added
 * or removed, which is the case that matters, and it will not catch a settings
 * change inside an existing widget. Said plainly in the finding.
 *
 * @param array<string, mixed> $layout
 * @return array<string, mixed>|null
 */
function nibwp_siteorigin_stale_content(array $layout, object $post): ?array
{
    if (!nibwp_siteorigin_setting('copy-content')) {
        return null;
    }

    $post_id = isset($post->ID) ? (int) $post->ID : 0;
    $content = isset($post->post_content) && is_string($post->post_content) ? $post->post_content : '';
    $expected = 0;

    foreach ($layout['widgets'] as $widget) {
        if (is_array($widget)) {
            $expected++;
        }
    }

    $found = substr_count($content, 'id="panel-' . $post_id . '-');

    if ($found === $expected) {
        return null;
    }

    return [
        'widgets_in_layout' => $expected,
        'widgets_in_rendered_copy' => $found,
        'note' => __('The rendered copy in post_content holds a different number of widgets from the layout, so it was written before the layout last changed. Counting markers catches added and removed widgets, not an edit inside one.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_author(__('Auditing the Page Builder layouts', 'nibwp'))) {
        return $denied;
    }

    $only = isset($input['check']) && is_scalar($input['check']) ? (string) $input['check'] : null;

    if ($only !== null && !isset(nibwp_siteorigin_checks()[$only])) {
        return nibwp_siteorigin_err(
            'unknown_check',
            sprintf(
                /* translators: 1: check name, 2: list of valid check names */
                __('Unknown check "%1$s". Valid checks: %2$s.', 'nibwp'),
                $only,
                implode(', ', array_keys(nibwp_siteorigin_checks()))
            )
        );
    }

    $found = nibwp_siteorigin_find_pages($input);

    if ($found instanceof WP_Error) {
        return $found;
    }

    $findings = [];
    $counts = [];
    $checked = 0;

    foreach ($found['ids'] as $id) {
        $layout = nibwp_siteorigin_read_meta($id);
        $post = get_post($id);

        if ($layout === null || !is_object($post)) {
            continue;
        }

        $checked++;

        foreach (nibwp_siteorigin_audit_layout($layout, $post, $only) as $finding) {
            $findings[] = $finding;
            $check = (string) $finding['check'];
            $counts[$check] = ($counts[$check] ?? 0) + 1;
        }
    }

    return [
        'checks' => $only === null ? nibwp_siteorigin_checks() : [$only => nibwp_siteorigin_checks()[$only]],
        'pages_checked' => $checked,
        'findings' => $findings,
        'counts' => $counts,
        'candidates' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'limit' => $found['limit'],
        'offset' => $found['offset'],
        'truncated' => $found['truncated'],
    ];
}

/**
 * The one the owner is nearly always asking about.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_audit_missing_widgets(array $input): array|WP_Error
{
    $result = nibwp_siteorigin_audit(['check' => 'missing_widget_class'] + $input);

    if ($result instanceof WP_Error) {
        return $result;
    }

    $classes = [];

    foreach ($result['findings'] as $finding) {
        $class = isset($finding['class']) && is_scalar($finding['class']) ? (string) $finding['class'] : '';
        $classes[$class] = ($classes[$class] ?? 0) + 1;
    }

    return $result + ['classes' => $classes];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_audit_stale_content(array $input): array|WP_Error
{
    $result = nibwp_siteorigin_audit(['check' => 'stale_content'] + $input);

    if ($result instanceof WP_Error) {
        return $result;
    }

    return $result + ['copy_content_enabled' => (bool) nibwp_siteorigin_setting('copy-content')];
}

/* ----------------------------------------------------------------- summary */

/**
 * One response an owner can judge the site's builder usage from.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_siteorigin_summary(array $input): array|WP_Error
{
    if ($denied = nibwp_siteorigin_require_author(__('Reading the Page Builder summary', 'nibwp'))) {
        return $denied;
    }

    $found = nibwp_siteorigin_find_pages($input);

    if ($found instanceof WP_Error) {
        return $found;
    }

    $rows = 0;
    $cells = 0;
    $widgets = 0;
    $classes = [];
    $counts = [];
    $pages = 0;

    foreach ($found['ids'] as $id) {
        $layout = nibwp_siteorigin_read_meta($id);
        $post = get_post($id);

        if ($layout === null || !is_object($post)) {
            continue;
        }

        $pages++;
        $rows += count($layout['grids']);
        $cells += count($layout['grid_cells']);

        foreach ($layout['widgets'] as $widget) {
            if (!is_array($widget)) {
                continue;
            }

            $widgets++;
            $class = nibwp_siteorigin_class($widget);
            $classes[$class] = ($classes[$class] ?? 0) + 1;
        }

        foreach (nibwp_siteorigin_audit_layout($layout, $post, null) as $finding) {
            $check = (string) $finding['check'];
            $counts[$check] = ($counts[$check] ?? 0) + 1;
        }
    }

    arsort($classes);

    return [
        'plugin_version' => defined('SITEORIGIN_PANELS_VERSION') ? (string) SITEORIGIN_PANELS_VERSION : '',
        'meta_key' => NIBWP_SITEORIGIN_META,
        'writable' => nibwp_siteorigin_can_write(),
        'pages_with_layout' => $pages,
        'totals' => ['rows' => $rows, 'cells' => $cells, 'widgets' => $widgets],
        'widget_classes' => array_slice($classes, 0, 25, true),
        'audit_counts' => $counts,
        'post_types_enabled' => nibwp_siteorigin_report_setting('string_list', nibwp_siteorigin_setting('post-types'))['value'] ?? [],
        'copy_content_enabled' => (bool) nibwp_siteorigin_setting('copy-content'),
        'responsive' => (bool) nibwp_siteorigin_setting('responsive'),
        'candidates' => $found['candidates'],
        'skipped_no_permission' => $found['skipped_no_permission'],
        'limit' => $found['limit'],
        'offset' => $found['offset'],
        'truncated' => $found['truncated'],
    ];
}
