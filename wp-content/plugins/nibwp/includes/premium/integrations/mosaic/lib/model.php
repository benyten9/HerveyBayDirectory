<?php

declare(strict_types=1);

/**
 * Mosaic read model — straight off Mosaic's own tables, never through a write path.
 *
 * Mosaic is a theme builder with its own data layer (Database/Schemas/Schema-1.0.12.php):
 * a Mosaic theme owns masters (page shells), templates (what a URL renders, by
 * path such as single-page.php, assigned automatically or by hand to one post
 * through mosaic_template_assigns), components, universal classes, collection
 * variables and breakpoints. Every element of every document is one row of
 * mosaic_nodes: {documentType, documentID, ID, parentType, parentID, ordering,
 * type, data(json)}, ordered by a fractional index string.
 *
 * Nothing renders unless the Mosaic theme is the active WordPress theme (or an
 * admin has it in Mosaic's test mode). That is reported, never changed here.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Mosaic's plugin booted on this request (its requirement check passed). */
function nibwp_mosaic_active(): bool
{
    return defined('MOSAIC_VERSION') && class_exists('Mosaic\\Database\\Schema');
}

function nibwp_mosaic_table(string $name): string
{
    global $wpdb;

    return $wpdb->prefix . 'mosaic_' . $name;
}

/** Mosaic's tables exist (its first admin visit installs them). */
function nibwp_mosaic_installed(): bool
{
    global $wpdb;
    static $installed = null;
    if ($installed === null) {
        $installed = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', nibwp_mosaic_table('nodes'))) !== '';
    }

    return $installed;
}

function nibwp_mosaic_guard(): ?WP_Error
{
    if (!empty($GLOBALS['mosaicPlugins']) && !defined('MOSAIC_VERSION')) {
        return new WP_Error(
            'nibwp_mosaic_requirements',
            __('Mosaic is installed but did not start: its system requirements are not met on this server (PHP 8.2+, WordPress 6.9+, and the zip, gd, dom and curl PHP extensions). Mosaic lists what is missing on the Plugins screen.', 'nibwp')
        );
    }
    if (!nibwp_mosaic_active()) {
        return new WP_Error('nibwp_mosaic_inactive', __('Mosaic is not active on this site.', 'nibwp'));
    }
    if (!nibwp_mosaic_installed()) {
        return new WP_Error(
            'nibwp_mosaic_not_installed',
            __('Mosaic is active but has not been set up yet. Open Mosaic in wp-admin once: it installs its tables and walks through creating a theme.', 'nibwp')
        );
    }

    return null;
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_list_themes(): array
{
    global $wpdb;
    $live = nibwp_mosaic_live_theme_id();
    $rows = $wpdb->get_results('SELECT ID, name, status, modified_gmt FROM ' . nibwp_mosaic_table('themes') . " WHERE status = 'publish' ORDER BY ordering, name");
    $out = [];
    foreach ((array) $rows as $row) {
        $out[] = [
            'id'       => (string) $row->ID,
            'name'     => (string) $row->name,
            'live'     => $live === (string) $row->ID,
            'modified' => (string) $row->modified_gmt,
        ];
    }

    return $out;
}

/** The Mosaic theme the site is serving, or '' when the active WordPress theme is not a Mosaic one. */
function nibwp_mosaic_live_theme_id(): string
{
    if (class_exists('Mosaic\\WPTheme\\WPThemeManager')) {
        $id = \Mosaic\WPTheme\WPThemeManager::getMosaicThemeIDFromThemeSlug((string) get_option('stylesheet'));

        return is_string($id) ? $id : '';
    }

    return '';
}

/**
 * The theme a call means: the one given, else the live one, else the only one.
 *
 * @return string|WP_Error
 */
function nibwp_mosaic_resolve_theme(string $theme_id = '')
{
    $themes = nibwp_mosaic_list_themes();
    if ($theme_id !== '') {
        foreach ($themes as $t) {
            if ($t['id'] === $theme_id) {
                return $theme_id;
            }
        }

        return new WP_Error('nibwp_mosaic_theme_not_found', __('No Mosaic theme with that id. List them with nibwp/mosaic-list-themes.', 'nibwp'));
    }
    if ($themes === []) {
        return new WP_Error(
            'nibwp_mosaic_no_theme',
            __('Mosaic has no theme yet. Open Mosaic in wp-admin and create or import one; everything Mosaic renders lives inside a theme.', 'nibwp')
        );
    }
    foreach ($themes as $t) {
        if ($t['live']) {
            return $t['id'];
        }
    }
    if (count($themes) === 1) {
        return $themes[0]['id'];
    }

    return new WP_Error(
        'nibwp_mosaic_theme_ambiguous',
        __('Several Mosaic themes exist and none is live. Pass theme_id.', 'nibwp'),
        ['themes' => $themes]
    );
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_list_masters(string $theme_id): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT ID, name FROM ' . nibwp_mosaic_table('masters') . " WHERE themeID = %s AND status = 'publish' ORDER BY ordering", $theme_id));
    $out = [];
    foreach ((array) $rows as $row) {
        $out[] = ['id' => (string) $row->ID, 'name' => (string) $row->name];
    }

    return $out;
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_list_templates(string $theme_id): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT ID, name, masterID, path, assign FROM ' . nibwp_mosaic_table('templates') . " WHERE themeID = %s AND status = 'publish' ORDER BY path, ordering", $theme_id));
    $assigns = $wpdb->get_results($wpdb->prepare('SELECT parentID, type, typeIdentifier FROM ' . nibwp_mosaic_table('template_assigns') . " WHERE themeID = %s AND parentType = 'template'", $theme_id));

    $byTemplate = [];
    foreach ((array) $assigns as $a) {
        $entry = ['type' => (string) $a->type, 'id' => (string) $a->typeIdentifier];
        if ($a->type === 'post' && ctype_digit((string) $a->typeIdentifier)) {
            $entry['title'] = get_the_title((int) $a->typeIdentifier);
            $entry['post_type'] = (string) get_post_type((int) $a->typeIdentifier);
        }
        $byTemplate[(string) $a->parentID][] = $entry;
    }

    $out = [];
    foreach ((array) $rows as $row) {
        $out[] = [
            'id'          => (string) $row->ID,
            'name'        => (string) $row->name,
            'path'        => (string) $row->path,
            'assign'      => (string) $row->assign,
            'master_id'   => (string) $row->masterID,
            'assigned_to' => $byTemplate[(string) $row->ID] ?? [],
        ];
    }

    return $out;
}

/** The template a post is hand-assigned to, or ''. */
function nibwp_mosaic_template_for_post(string $theme_id, int $post_id): string
{
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare(
        'SELECT parentID FROM ' . nibwp_mosaic_table('template_assigns') . " WHERE themeID = %s AND parentType = 'template' AND type = 'post' AND typeIdentifier = %s",
        $theme_id,
        (string) $post_id
    ));
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_list_components(string $theme_id): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT ID, name, path, parentID FROM ' . nibwp_mosaic_table('components') . " WHERE themeID = %s AND status = 'publish' ORDER BY ordering", $theme_id));
    $cats = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT ID, data FROM ' . nibwp_mosaic_table('component_categories') . " WHERE themeID = %s AND status = 'publish'", $theme_id)) as $c) {
        $d = json_decode((string) $c->data, true);
        $cats[(string) $c->ID] = (string) ($d['name'] ?? '');
    }
    $out = [];
    foreach ((array) $rows as $row) {
        $out[] = [
            'id'            => (string) $row->ID,
            'name'          => (string) $row->name,
            'category'      => $cats[(string) $row->parentID] ?? '',
            'instance_type' => 'component-instance/' . $row->ID,
        ];
    }

    return $out;
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_list_classes(string $theme_id): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT ID, data, emittedName FROM ' . nibwp_mosaic_table('universal_classes') . " WHERE themeID = %s AND status = 'publish' ORDER BY ordering", $theme_id));
    $out = [];
    foreach ((array) $rows as $row) {
        $d = json_decode((string) $row->data, true) ?: [];
        $out[] = [
            'id'     => (string) $row->ID,
            'name'   => (string) ($d['name'] ?? ''),
            'label'  => (string) ($d['label'] ?? ''),
            'states' => $d['states'] ?? new stdClass(),
            'apply'  => ['style' => ['universalClasses' => [(string) $row->ID => (string) $row->ID]]],
        ];
    }

    return $out;
}

/** @return array<string,string> class name → id */
function nibwp_mosaic_class_ids_by_name(string $theme_id): array
{
    $map = [];
    foreach (nibwp_mosaic_list_classes($theme_id) as $c) {
        if ($c['name'] !== '') {
            $map[$c['name']] = $c['id'];
        }
    }

    return $map;
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_list_variables(string $theme_id): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT ID, data, emittedName FROM ' . nibwp_mosaic_table('collection_variables') . " WHERE themeID = %s AND status = 'publish' ORDER BY ordering", $theme_id));
    $out = [];
    foreach ((array) $rows as $row) {
        $d = json_decode((string) $row->data, true) ?: [];
        $out[] = [
            'id'    => (string) $row->ID,
            'name'  => (string) ($d['name'] ?? ''),
            'usage' => $d['usage'] ?? null,
            'value' => $d['value'] ?? null,
            // In a style value Mosaic references a variable as {"var": id}; in customDeclarations, as plain CSS.
            'use'   => ['var' => (string) $row->ID],
            'css'   => $row->emittedName ? 'var(' . $row->emittedName . ')' : '',
        ];
    }

    return $out;
}

/** @return array<string,array<string,mixed>> breakpoint id → {name, width, direction} */
function nibwp_mosaic_list_breakpoints(string $theme_id): array
{
    global $wpdb;
    $out = [
        '_'  => ['name' => 'Base', 'width' => null, 'direction' => null],
        '_t' => ['name' => 'Tablet', 'width' => 1079, 'direction' => 'down'],
        '_m' => ['name' => 'Mobile', 'width' => 767, 'direction' => 'down'],
    ];
    foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT ID, data FROM ' . nibwp_mosaic_table('breakpoints') . " WHERE themeID = %s AND status = 'publish'", $theme_id)) as $row) {
        $d = json_decode((string) $row->data, true) ?: [];
        $out[(string) $row->ID] = ['name' => (string) ($d['name'] ?? ''), 'width' => $d['width'] ?? null, 'direction' => $d['direction'] ?? null];
    }

    return $out;
}

/**
 * Every element row of one document (template, master or component).
 *
 * @return array<int,object>
 */
function nibwp_mosaic_document_rows(string $theme_id, string $document_type, string $document_id): array
{
    global $wpdb;

    return (array) $wpdb->get_results($wpdb->prepare(
        'SELECT ID, parentType, parentID, ordering, status, type, data FROM ' . nibwp_mosaic_table('nodes') . ' WHERE themeID = %s AND documentType = %s AND documentID = %s',
        $theme_id,
        $document_type,
        $document_id
    ));
}

/** Where a node lives: {theme_id, document_type, document_id, type, parent_id} or null. */
function nibwp_mosaic_locate_node(string $node_id, string $theme_id = ''): ?array
{
    global $wpdb;
    $sql = 'SELECT themeID, documentType, documentID, type, parentID, parentType FROM ' . nibwp_mosaic_table('nodes') . " WHERE ID = %s AND status = 'publish'";
    $row = $theme_id !== ''
        ? $wpdb->get_row($wpdb->prepare($sql . ' AND themeID = %s', $node_id, $theme_id))
        : $wpdb->get_row($wpdb->prepare($sql, $node_id));
    if (!$row) {
        return null;
    }

    return [
        'theme_id'      => (string) $row->themeID,
        'document_type' => (string) $row->documentType,
        'document_id'   => (string) $row->documentID,
        'type'          => (string) $row->type,
        'parent_id'     => (string) $row->parentID,
        'parent_type'   => (string) $row->parentType,
    ];
}

/** Every descendant id of a node, deepest first, so a delete never orphans a child. */
function nibwp_mosaic_descendant_ids(string $theme_id, string $document_type, string $document_id, string $node_id): array
{
    $children = [];
    foreach (nibwp_mosaic_document_rows($theme_id, $document_type, $document_id) as $row) {
        if ($row->status === 'publish' && $row->parentType === 'node') {
            $children[(string) $row->parentID][] = (string) $row->ID;
        }
    }
    $out = [];
    $walk = function (string $id) use (&$walk, &$children, &$out): void {
        foreach ($children[$id] ?? [] as $child) {
            $walk($child);
            $out[] = $child;
        }
    };
    $walk($node_id);

    return $out;
}

/** Status for agents: what is installed, what is live, what to do next. */
function nibwp_mosaic_status(): array
{
    $status = [
        'active'       => nibwp_mosaic_active(),
        'version'      => defined('MOSAIC_VERSION') ? MOSAIC_VERSION : null,
        'data_version' => defined('MOSAIC_DATA_VERSION') ? MOSAIC_DATA_VERSION : null,
        'pro'          => defined('MOSAIC_PRO') ? (bool) MOSAIC_PRO : null,
        'installed'    => false,
        'themes'       => [],
        'live_theme'   => '',
        'next_steps'   => [],
    ];
    if (!empty($GLOBALS['mosaicPlugins']) && !defined('MOSAIC_VERSION')) {
        $status['next_steps'][] = 'Mosaic did not start: its system requirements are not met (PHP 8.2+, zip, gd, dom, curl). See the Plugins screen.';

        return $status;
    }
    if (!$status['active']) {
        $status['next_steps'][] = 'Activate Mosaic.';

        return $status;
    }
    $status['installed'] = nibwp_mosaic_installed();
    if (!$status['installed']) {
        $status['next_steps'][] = 'Open Mosaic in wp-admin once to install its tables, then create or import a theme.';

        return $status;
    }
    $status['themes'] = nibwp_mosaic_list_themes();
    $status['live_theme'] = nibwp_mosaic_live_theme_id();
    if ($status['themes'] === []) {
        $status['next_steps'][] = 'Create or import a Mosaic theme in wp-admin → Mosaic.';
    } elseif ($status['live_theme'] === '') {
        $status['next_steps'][] = 'No Mosaic theme is live, so visitors do not see Mosaic pages yet. Make one live from wp-admin → Mosaic when the owner is ready; this integration never switches the site theme.';
    }

    return $status;
}
