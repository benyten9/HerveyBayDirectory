<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-templates.
 *
 * The store's own presentation, which lives in three places at once and
 * resolves in an order almost nobody can recite from memory: a block template
 * customised in the site editor (a wp_template row in the database) shadows a
 * .html file in the child theme, which shadows one in the parent theme, which
 * shadows the copy WooCommerce ships. Alongside that, the old PHP templates are
 * still overridden by dropping a file into the theme's woocommerce/ folder, and
 * such a file keeps serving whatever markup it was copied from — for years,
 * silently, while WooCommerce's own version moves on. That is the commonest way
 * a store stops showing a feature it paid for, and the only warning is a line in
 * a status report nobody opens.
 *
 * So the read half of this hub answers "what is actually serving this page, and
 * is it behind?", and the write half puts a customised copy in the theme or
 * takes it away again.
 *
 * Authorisation is the theme's, not the catalogue's: edit_theme_options is what
 * opens the site editor, and writing a file into the theme additionally asks
 * edit_themes and honours DISALLOW_FILE_EDIT, because a site that switched file
 * editing off did it deliberately and an MCP call is not an exception to it.
 *
 * Every path is resolved with realpath() against the resolved theme directory
 * and refused if it lands outside — the same wall lib/files.php puts around the
 * uploads directory, for the same reason.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';
require_once __DIR__ . '/../lib/files.php';

/**
 * The action enum. Single source of truth — the registration and
 * tests/skills/woocommerce-pro-check.php both read this, and the switch in
 * nibwp_wcpro_templates_execute() must match it exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_templates_actions(): array
{
    return [
        // Read-only overview.
        'template_status',
        // Block templates.
        'list_templates',
        'get_template',
        'save_template',
        'revert_template',
        // The old PHP templates, overridden by copying a file into the theme.
        'list_overrides',
        'get_override',
        'save_override',
    ];
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-templates',
    __('WooCommerce Pro — store templates and theme overrides', 'nibwp'),
    'Read and change the templates the shop is actually rendered with. Lists every WooCommerce block template the site resolves and says where each one comes from — the child theme, the parent theme, a site-editor customisation in the database, or the copy WooCommerce ships — reads one, saves a customised copy into the active theme, and reverts a customisation back to the default. Also reports which PHP templates the active theme overrides the old way, in its woocommerce/ folder, and whether each override is behind the version of the file it shadows, which is the commonest reason a store silently stops showing new markup. Reading and writing is confined to the active theme directory.',
    nibwp_wcpro_templates_actions(),
    [
        'slug' => ['type' => 'string', 'description' => 'Block template slug, e.g. single-product, archive-product, page-checkout, order-confirmation.'],
        'template_id' => ['type' => 'string', 'description' => 'Full block template id as list_templates returns it, e.g. "woocommerce/woocommerce//single-product". Given instead of slug when two sources share a slug.'],
        'template_type' => ['type' => 'string', 'enum' => ['wp_template', 'wp_template_part'], 'default' => 'wp_template', 'description' => 'wp_template for a page template, wp_template_part for a part such as the checkout header or the mini cart.'],
        'content' => ['type' => 'string', 'description' => 'For save_template / save_override: the whole file content to write. There is no partial write — read it first, change it, send it back.'],
        'path' => ['type' => 'string', 'description' => 'For get_override / save_override: the template path inside the theme\'s woocommerce/ folder, e.g. "single-product/add-to-cart/variable.php". Absolute paths, URLs and ../ escapes are refused.'],
        'delete_theme_file' => ['type' => 'boolean', 'default' => false, 'description' => 'For revert_template: also delete the theme\'s own .html copy, not just the site-editor customisation. This removes a file from the theme.'],
        'stale_only' => ['type' => 'boolean', 'default' => false, 'description' => 'For list_overrides: only the overrides that are behind the file they shadow.'],
        'per_page' => ['type' => 'integer', 'default' => 50],
        'page' => ['type' => 'integer', 'default' => 1],
        'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Required to write a block template into a theme that is not a block theme, where it would never be read.'],
    ],
    'nibwp_wcpro_templates_execute',
    implode("\n", [
        'Read first: action:"template_status" says whether this is a block theme, how many WooCommerce templates are customised and how many PHP overrides are stale. It writes nothing.',
        'A block template resolves in this order, and the first hit wins: a site-editor customisation in the database, then the child theme\'s templates/<slug>.html, then the parent theme\'s, then the copy WooCommerce ships. list_templates says which one answered for each slug, so a "my change does nothing" report is one call to diagnose.',
        'save_template writes <theme>/templates/<slug>.html. If a site-editor customisation exists for that slug it keeps winning, and the response says so — revert_template clears it.',
        'revert_template removes the site-editor customisation by default, which is what the editor\'s own Reset does. Pass delete_theme_file:true to also remove the theme\'s .html copy; that deletes a file from the theme.',
        'list_overrides is the one to run when a store "lost" a WooCommerce feature after an update. An override copied from WooCommerce 6 and never touched since keeps serving WooCommerce 6 markup; the response reports theme_version against core_version and marks it stale.',
        'get_override and save_override only reach files under the theme\'s woocommerce/ folder, and save_override only writes a path where WooCommerce actually ships a template — you can shadow a real template, not drop a new file into the theme.',
        'Writes need edit_themes and are refused outright when the site defines DISALLOW_FILE_EDIT. That is deliberate on the site\'s part; report it rather than working around it.',
    ]),
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_templates_execute(array $input): array|WP_Error
{
    $guard = nibwp_wcpro_guard($input, false);
    if (is_wp_error($guard)) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    // The object here is the active theme, and edit_theme_options is the
    // capability that opens it in wp-admin — both the site editor and the theme
    // file editor sit behind it. A template can carry anything the theme author
    // put in it, so reading is not a lower bar than listing.
    $denied = nibwp_wcpro_require_cap('edit_theme_options', 'Reading or changing the store templates');
    if ($denied !== null) {
        return $denied;
    }

    switch ($action) {
        case 'template_status':
            return nibwp_wcpro_template_status();

        case 'list_templates':
            return nibwp_wcpro_list_templates($input);

        case 'get_template':
            return nibwp_wcpro_get_template($input);

        case 'save_template':
            return nibwp_wcpro_save_template($input);

        case 'revert_template':
            return nibwp_wcpro_revert_template($input);

        case 'list_overrides':
            return nibwp_wcpro_list_overrides($input);

        case 'get_override':
            return nibwp_wcpro_get_override($input);

        case 'save_override':
            return nibwp_wcpro_save_override($input);
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_templates_actions());
}

/* ------------------------------------------------------- the theme as a wall */

/**
 * The directory this hub is allowed to read and write, resolved.
 *
 * The active theme, not the parent: a change belongs in the theme that is
 * running, and writing into a parent theme is how a customisation gets deleted
 * by the next theme update.
 */
function nibwp_wcpro_theme_dir(): string|WP_Error
{
    if (!function_exists('get_stylesheet_directory')) {
        return nibwp_wcpro_err('no_theme_dir', 'WordPress has not resolved a theme directory, so no template can be read or written.', 500);
    }

    $dir = (string) get_stylesheet_directory();
    if (trim($dir) === '') {
        return nibwp_wcpro_err('no_theme_dir', 'The active theme has no directory on disk, so no template can be read or written.', 500);
    }

    $real = realpath($dir);

    return nibwp_wcpro_normalize_path($real === false ? $dir : $real);
}

/** The parent theme's directory, or '' when the active theme is not a child. */
function nibwp_wcpro_parent_theme_dir(): string
{
    if (!function_exists('get_template_directory')) {
        return '';
    }
    $parent = nibwp_wcpro_normalize_path((string) get_template_directory());
    $child = nibwp_wcpro_theme_dir();

    return is_wp_error($child) || $parent === $child ? '' : $parent;
}

/**
 * Resolve a caller-supplied path to an absolute one inside the active theme, or
 * refuse.
 *
 * The same shape as nibwp_wcpro_uploads_path() in lib/files.php, and for the
 * same reason: a template read that accepts an arbitrary path reads wp-config.php
 * over MCP, and a template write that accepts one puts executable PHP anywhere
 * on the server. The checks above realpath() are shortcuts; the path_within()
 * call at the bottom is the wall, and it is the one that catches a symlink out
 * of the theme and a sibling directory whose name merely starts with the
 * theme's.
 *
 * @param array<int, string> $allowed_extensions lowercase, no dot
 * @param string             $require_prefix     a directory the path must be under, e.g. "woocommerce"
 */
function nibwp_wcpro_theme_path(
    mixed $relative,
    bool $must_exist = true,
    array $allowed_extensions = ['html', 'php'],
    string $require_prefix = '',
): string|WP_Error {
    $base = nibwp_wcpro_theme_dir();
    if (is_wp_error($base)) {
        return $base;
    }

    $raw = str_replace('\\', '/', trim((string) ($relative ?? '')));
    if ($raw === '') {
        return nibwp_wcpro_err('missing_path', 'Provide a path relative to the active theme, e.g. "woocommerce/single-product/price.php".', 422);
    }
    if (str_contains($raw, "\0")) {
        return nibwp_wcpro_forbidden('The path contains a null byte and will not be opened.');
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $raw)) {
        return nibwp_wcpro_forbidden('A URL is not a template path. Give a path relative to the active theme.');
    }
    if (str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:/#', $raw)) {
        return nibwp_wcpro_forbidden('Absolute paths are refused. Give a path relative to the active theme, e.g. "woocommerce/single-product/price.php".');
    }

    $extension = strtolower((string) pathinfo($raw, PATHINFO_EXTENSION));
    if ($allowed_extensions !== [] && !in_array($extension, $allowed_extensions, true)) {
        return nibwp_wcpro_err(
            'bad_extension',
            sprintf('Only %s files are handled here, got ".%s".', implode('/', $allowed_extensions), $extension),
            422,
        );
    }

    $trimmed = ltrim($raw, '/');
    if ($require_prefix !== '') {
        $prefix = trim(nibwp_wcpro_normalize_path($require_prefix), '/');
        if ($prefix !== '' && !str_starts_with($trimmed, $prefix . '/')) {
            return nibwp_wcpro_forbidden(sprintf('Only files under the theme\'s %s/ folder are reachable here, and "%s" is not one.', $prefix, $raw));
        }
    }

    $candidate = $base . '/' . $trimmed;

    if ($must_exist) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return nibwp_wcpro_err('file_not_found', sprintf('No file at %s in the active theme.', $trimmed), 404);
        }
        $resolved = nibwp_wcpro_normalize_path($resolved);
    } else {
        // A file about to be written need not exist, but a real directory to put
        // it in does — so the parent is resolved and the basename appended.
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            return nibwp_wcpro_err('directory_not_found', sprintf('The folder %s does not exist in the active theme. Create it first, or save a path whose folder is already there.', ltrim(dirname($trimmed), '/')), 404);
        }
        $resolved = nibwp_wcpro_normalize_path($parent) . '/' . basename($candidate);
    }

    // The check that matters. Everything above is a shortcut; this is the wall.
    if (!nibwp_wcpro_path_within($resolved, $base)) {
        return nibwp_wcpro_forbidden(sprintf('"%s" resolves outside the active theme directory, so it will not be opened.', $raw));
    }
    if ($must_exist && !is_file($resolved)) {
        return nibwp_wcpro_err('not_a_file', sprintf('%s is not a file.', $trimmed), 422);
    }

    return $resolved;
}

/**
 * The bar wp-admin's theme file editor sets, and the one reason it refuses even
 * an administrator: DISALLOW_FILE_EDIT. A site that set it does not want files
 * changed from inside WordPress, and that includes this.
 */
function nibwp_wcpro_require_theme_write(string $what): ?WP_Error
{
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        return nibwp_wcpro_err(
            'file_edit_disallowed',
            sprintf('%s writes a file into the active theme, and this site defines DISALLOW_FILE_EDIT. Make the change over SFTP or in version control instead — this is deliberate on the site\'s part.', $what),
            409,
        );
    }

    return nibwp_wcpro_require_cap('edit_themes', $what);
}

/** Where the theme is expected to keep its block templates. */
function nibwp_wcpro_block_template_folder(string $type): string
{
    return $type === 'wp_template_part' ? 'parts' : 'templates';
}

/* --------------------------------------------------------- block templates */

/**
 * The block templates WooCommerce is responsible for.
 *
 * Detected where possible — a template whose source names the WooCommerce plugin
 * is WooCommerce's whatever it is called — and matched against this list
 * otherwise, because once a theme or the site editor has taken a template over,
 * nothing in the object still says which plugin it started life as.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_woo_template_slugs(): array
{
    return [
        'single-product',
        'archive-product',
        'taxonomy-product_cat',
        'taxonomy-product_tag',
        'taxonomy-product_attribute',
        'product-search-results',
        'page-cart',
        'page-cart-no-block',
        'page-checkout',
        'page-checkout-no-block',
        'order-confirmation',
        'checkout-header',
        'mini-cart',
        'coming-soon',
        'product-filters-overlay',
        'product-filters-overlay-heading',
    ];
}

/** Is this template one of WooCommerce's, however it is being served now? */
function nibwp_wcpro_is_woo_template(object $template): bool
{
    $theme = strtolower((string) ($template->theme ?? ''));
    if (str_contains($theme, 'woocommerce')) {
        return true;
    }

    return in_array((string) ($template->slug ?? ''), nibwp_wcpro_woo_template_slugs(), true);
}

/**
 * Which of the four possible sources is actually serving this template.
 *
 * WP_Block_Template says `source` — 'custom' for a site-editor edit stored in
 * the database, 'theme' for a file, 'plugin' for the copy a plugin ships — and
 * `theme` for whose it is. Neither distinguishes the child theme from the
 * parent, so the file is looked for on disk.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_template_origin(object $template, string $type): array
{
    $slug = (string) ($template->slug ?? '');
    $source = (string) ($template->source ?? '');
    $folder = nibwp_wcpro_block_template_folder($type);

    $child_dir = nibwp_wcpro_theme_dir();
    $child_file = is_wp_error($child_dir) ? '' : $child_dir . '/' . $folder . '/' . $slug . '.html';
    $parent_dir = nibwp_wcpro_parent_theme_dir();
    $parent_file = $parent_dir === '' ? '' : $parent_dir . '/' . $folder . '/' . $slug . '.html';

    $in_child = $child_file !== '' && is_file($child_file);
    $in_parent = $parent_file !== '' && is_file($parent_file);

    if ($source === 'custom') {
        $provided_by = 'database';
    } elseif ($source === 'theme' && $in_child && $parent_dir !== '') {
        $provided_by = 'child_theme';
    } elseif ($source === 'theme') {
        $provided_by = $in_child ? 'theme' : 'theme_unresolved';
    } elseif ($source === 'plugin') {
        $provided_by = 'plugin';
    } else {
        $provided_by = $source === '' ? 'unknown' : $source;
    }

    return [
        'provided_by'      => $provided_by,
        'source'           => $source,
        'origin'           => (string) ($template->origin ?? ''),
        'owner'            => (string) ($template->theme ?? ''),
        'has_theme_file'   => (bool) ($template->has_theme_file ?? ($in_child || $in_parent)),
        'child_theme_file' => $in_child ? $child_file : '',
        'parent_theme_file' => $in_parent ? $parent_file : '',
        'shadowed'         => $provided_by === 'database' && ($in_child || $in_parent),
    ];
}

/** @return array<int, object>|WP_Error */
function nibwp_wcpro_block_templates(string $type): array|WP_Error
{
    if (!function_exists('get_block_templates')) {
        return nibwp_wcpro_err(
            'block_templates_unavailable',
            'This WordPress does not expose get_block_templates(), so block templates cannot be listed. The PHP template overrides are still readable with action:"list_overrides".',
            409,
        );
    }

    $templates = get_block_templates([], $type);

    return is_array($templates) ? array_values($templates) : [];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_templates(array $input): array|WP_Error
{
    $type = nibwp_wcpro_enum($input['template_type'] ?? 'wp_template', ['wp_template', 'wp_template_part'], 'template_type', 'wp_template');
    if (is_wp_error($type)) {
        return $type;
    }

    $templates = nibwp_wcpro_block_templates($type);
    if (is_wp_error($templates)) {
        return $templates;
    }

    $page = nibwp_wcpro_paginate($input, 50, 200);
    $items = [];
    $customised = 0;
    foreach ($templates as $template) {
        if (!is_object($template) || !nibwp_wcpro_is_woo_template($template)) {
            continue;
        }
        $origin = nibwp_wcpro_template_origin($template, $type);
        if ($origin['provided_by'] !== 'plugin') {
            $customised++;
        }
        $items[] = [
            'id'          => (string) ($template->id ?? ''),
            'slug'        => (string) ($template->slug ?? ''),
            'title'       => (string) (is_object($template->title ?? null) ? '' : ($template->title ?? '')),
            'description' => (string) ($template->description ?? ''),
            'type'        => $type,
            'status'      => (string) ($template->status ?? ''),
        ] + $origin;
    }

    $total = count($items);
    $items = array_slice($items, $page['offset'], $page['per_page']);

    return nibwp_wcpro_ok(
        'list_templates',
        sprintf('%d WooCommerce %s(s) resolve on this site; %d of them are not the copy WooCommerce ships.', $total, $type === 'wp_template_part' ? 'template part' : 'template', $customised),
        [
            'items' => $items,
            'count' => $total,
            'result' => [
                'template_type' => $type,
                'customised'    => $customised,
                'resolution_order' => 'database customisation → child theme file → parent theme file → the copy WooCommerce ships',
            ],
        ],
    );
}

/**
 * One template, resolved, with its content.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_get_template(array $input): array|WP_Error
{
    $type = nibwp_wcpro_enum($input['template_type'] ?? 'wp_template', ['wp_template', 'wp_template_part'], 'template_type', 'wp_template');
    if (is_wp_error($type)) {
        return $type;
    }

    $found = nibwp_wcpro_find_template($input, $type);
    if (is_wp_error($found)) {
        return $found;
    }

    $origin = nibwp_wcpro_template_origin($found, $type);
    $content = (string) ($found->content ?? '');

    return nibwp_wcpro_ok(
        'get_template',
        sprintf('Template "%s" is served from %s (%d characters).', (string) ($found->slug ?? ''), (string) $origin['provided_by'], strlen($content)),
        [
            'result' => [
                'id'      => (string) ($found->id ?? ''),
                'slug'    => (string) ($found->slug ?? ''),
                'type'    => $type,
                'content' => $content,
            ] + $origin,
            'next_steps' => [
                'To customise it: change this content and send it back with action:"save_template", slug and content. It is written to the active theme as ' . nibwp_wcpro_block_template_folder($type) . '/' . (string) ($found->slug ?? '') . '.html.',
            ],
        ],
    );
}

/**
 * Find one template by id or by slug.
 *
 * get_block_template() wants the full "owner//slug" id, which a caller rarely
 * has; a slug is what they do have, and the slug may be served by any of the
 * four sources, so the resolved list is searched rather than an id guessed.
 *
 * @return object|WP_Error A block template object, or the reason there is none.
 */
function nibwp_wcpro_find_template(array $input, string $type): object
{
    // Declared `object` rather than `object|WP_Error`: WP_Error IS an object, and
    // PHP rejects a union that names both. Callers check with instanceof.
    $id = nibwp_wcpro_text($input['template_id'] ?? '', 200);
    $slug = sanitize_title((string) ($input['slug'] ?? ''));

    if ($id === '' && $slug === '') {
        return nibwp_wcpro_err('missing_slug', 'Provide the template "slug" (e.g. "single-product") or the full "template_id" list_templates returned.', 422);
    }

    $templates = nibwp_wcpro_block_templates($type);
    if (is_wp_error($templates)) {
        return $templates;
    }

    foreach ($templates as $template) {
        if (!is_object($template)) {
            continue;
        }
        if ($id !== '' && (string) ($template->id ?? '') === $id) {
            return $template;
        }
        if ($id === '' && (string) ($template->slug ?? '') === $slug) {
            return $template;
        }
    }

    return nibwp_wcpro_err(
        'template_not_found',
        sprintf('No %s resolves for "%s" on this site. action:"list_templates" shows what does.', $type, $id !== '' ? $id : $slug),
        404,
    );
}

/**
 * Write a customised copy of a block template into the active theme.
 *
 * The path is derived from the slug rather than taken from the caller, so there
 * is no path to escape with, and it is still put through the theme confinement
 * afterwards because a slug is caller input too.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_save_template(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_theme_write('Saving a template into the theme');
    if ($denied !== null) {
        return $denied;
    }

    $type = nibwp_wcpro_enum($input['template_type'] ?? 'wp_template', ['wp_template', 'wp_template_part'], 'template_type', 'wp_template');
    if (is_wp_error($type)) {
        return $type;
    }

    $slug = sanitize_title((string) ($input['slug'] ?? ''));
    if ($slug === '') {
        return nibwp_wcpro_err('missing_slug', 'Provide the template "slug" to save, e.g. "single-product".', 422);
    }

    $content = (string) ($input['content'] ?? '');
    if (trim($content) === '') {
        return nibwp_wcpro_err('missing_content', 'Provide the whole template "content". There is no partial write — read it with action:"get_template", change it, and send all of it back.', 422);
    }

    // A block template in a classic theme is a file nothing ever reads. Say so
    // rather than writing it and reporting success.
    $block_theme = function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : null;
    if ($block_theme === false && !nibwp_wcpro_bool($input['force'] ?? false)) {
        return nibwp_wcpro_err(
            'not_a_block_theme',
            'The active theme is not a block theme, so a block template written into it would never be read. Override the PHP template instead with action:"save_override", or pass force:true if you know the theme opts in another way.',
            409,
        );
    }

    $folder = nibwp_wcpro_block_template_folder($type);
    $base = nibwp_wcpro_theme_dir();
    if (is_wp_error($base)) {
        return $base;
    }
    $dir = $base . '/' . $folder;
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return nibwp_wcpro_err('mkdir_failed', sprintf('Could not create %s/ in the active theme — check the theme directory is writable.', $folder), 500);
    }

    $target = nibwp_wcpro_theme_path($folder . '/' . $slug . '.html', false, ['html'], $folder);
    if (is_wp_error($target)) {
        return $target;
    }

    $existed = is_file($target);
    if (file_put_contents($target, $content) === false) {
        return nibwp_wcpro_err('unwritable', sprintf('Could not write %s/%s.html — check the theme directory is writable.', $folder, $slug), 500);
    }

    $notes = [];
    // The trap: a site-editor customisation outranks the file that was just
    // written, so the change appears to do nothing at all.
    $customisation = nibwp_wcpro_find_template_customisation($slug, $type);
    if ($customisation > 0) {
        $notes[] = sprintf('A site-editor customisation of "%s" is stored in the database (post %d) and still outranks this file. Clear it with action:"revert_template" for the change to show.', $slug, $customisation);
    }
    if ($block_theme === null) {
        $notes[] = 'This WordPress does not expose wp_is_block_theme(), so whether the theme reads block templates at all was not verified.';
    }
    $notes[] = 'Re-read it with action:"get_template" to confirm which source now answers.';

    return nibwp_wcpro_ok(
        'save_template',
        sprintf('%s %s/%s.html in the active theme (%d characters).', $existed ? 'Replaced' : 'Wrote', $folder, $slug, strlen($content)),
        [
            'result' => [
                'slug'      => $slug,
                'type'      => $type,
                'path'      => $folder . '/' . $slug . '.html',
                'replaced'  => $existed,
                'bytes'     => strlen($content),
                'shadowed_by_database_customisation' => $customisation > 0,
            ],
            'next_steps' => $notes,
        ],
    );
}

/**
 * The post id of a site-editor customisation of this slug for the active theme,
 * or 0.
 *
 * A customised block template is a wp_template post whose post_name is the slug
 * and which is filed under a wp_theme term naming the theme. That is the row the
 * editor's own Reset deletes.
 */
function nibwp_wcpro_find_template_customisation(string $slug, string $type): int
{
    if (!function_exists('get_posts') || !function_exists('get_stylesheet')) {
        return 0;
    }

    $posts = get_posts([
        'post_type'        => $type,
        'post_status'      => ['publish', 'draft', 'auto-draft', 'pending', 'private'],
        'name'             => $slug,
        'numberposts'      => 1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => false,
        'tax_query'        => [[
            'taxonomy' => 'wp_theme',
            'field'    => 'name',
            'terms'    => (string) get_stylesheet(),
        ]],
    ]);

    $id = is_array($posts) && $posts !== [] ? (int) $posts[0] : 0;

    return $id > 0 ? $id : 0;
}

/**
 * Put a template back to the default.
 *
 * By default this removes the site-editor customisation only, which is exactly
 * what Reset does in the editor. Deleting the theme's own .html copy is a second
 * thing — it removes a file somebody put in the theme on purpose — so it is
 * asked for separately and needs the file-write bar.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_revert_template(array $input): array|WP_Error
{
    $type = nibwp_wcpro_enum($input['template_type'] ?? 'wp_template', ['wp_template', 'wp_template_part'], 'template_type', 'wp_template');
    if (is_wp_error($type)) {
        return $type;
    }

    $slug = sanitize_title((string) ($input['slug'] ?? ''));
    if ($slug === '') {
        return nibwp_wcpro_err('missing_slug', 'Provide the template "slug" to revert, e.g. "single-product".', 422);
    }

    $also_file = nibwp_wcpro_bool($input['delete_theme_file'] ?? false);
    if ($also_file) {
        $denied = nibwp_wcpro_require_theme_write('Deleting a template file from the theme');
        if ($denied !== null) {
            return $denied;
        }
    }

    $removed = [];

    $customisation = nibwp_wcpro_find_template_customisation($slug, $type);
    if ($customisation > 0) {
        if (!function_exists('wp_delete_post')) {
            return nibwp_wcpro_err('wp_api_missing', 'wp_delete_post() is not available, so the customisation cannot be removed.', 500);
        }
        // Object-level, because this is a post: a user who may not delete it in
        // wp-admin does not delete it here either.
        if (!current_user_can('delete_post', $customisation)) {
            return nibwp_wcpro_forbidden(sprintf('Not allowed to delete the stored customisation of "%s" (post %d).', $slug, $customisation));
        }
        wp_delete_post($customisation, true);
        $removed[] = sprintf('the site-editor customisation (post %d)', $customisation);
    }

    $folder = nibwp_wcpro_block_template_folder($type);
    $theme_file = nibwp_wcpro_theme_path($folder . '/' . $slug . '.html', true, ['html'], $folder);
    $theme_file_exists = !is_wp_error($theme_file);

    if ($also_file && $theme_file_exists) {
        if (!@unlink((string) $theme_file)) {
            return nibwp_wcpro_err('undeletable', sprintf('Could not delete %s/%s.html from the active theme.', $folder, $slug), 500);
        }
        $removed[] = sprintf('the theme\'s own %s/%s.html', $folder, $slug);
        $theme_file_exists = false;
    }

    $notes = [];
    if ($theme_file_exists) {
        $notes[] = sprintf('The theme still has its own %s/%s.html, which now serves this page. Pass delete_theme_file:true to remove that too and fall all the way back to WooCommerce\'s copy.', $folder, $slug);
    }
    $notes[] = 'Confirm with action:"get_template" — provided_by should now read "plugin" if you wanted WooCommerce\'s default.';

    return nibwp_wcpro_ok(
        'revert_template',
        $removed === []
            ? sprintf('Nothing to revert: "%s" had no customisation stored for this theme.', $slug)
            : sprintf('Reverted "%s" by removing %s.', $slug, implode(' and ', $removed)),
        [
            'result' => [
                'slug'    => $slug,
                'type'    => $type,
                'removed' => $removed,
                'theme_file_remains' => $theme_file_exists,
            ],
            'next_steps' => $notes,
        ],
    );
}

/* ----------------------------------------------- the old PHP template overrides */

/** The folder inside a theme that shadows WooCommerce's PHP templates. */
function nibwp_wcpro_woo_template_path(): string
{
    if (function_exists('WC')) {
        $wc = WC();
        if (is_object($wc) && method_exists($wc, 'template_path')) {
            $path = trim((string) $wc->template_path(), '/');
            if ($path !== '') {
                return $path;
            }
        }
    }

    return 'woocommerce';
}

/** Where WooCommerce keeps the originals. */
function nibwp_wcpro_core_templates_dir(): string
{
    if (defined('WC_ABSPATH')) {
        $dir = nibwp_wcpro_normalize_path((string) constant('WC_ABSPATH')) . '/templates';
        if (is_dir($dir)) {
            return $dir;
        }
    }
    if (function_exists('WC')) {
        $wc = WC();
        if (is_object($wc) && method_exists($wc, 'plugin_path')) {
            $dir = nibwp_wcpro_normalize_path((string) $wc->plugin_path()) . '/templates';
            if (is_dir($dir)) {
                return $dir;
            }
        }
    }

    return '';
}

/**
 * The @version a WooCommerce template declares in its docblock.
 *
 * WooCommerce bumps it whenever the markup changes, and it is the only signal
 * that an override is behind. A template with none has been edited by hand past
 * the point where the comparison means anything, which is worth reporting as
 * "unknown" rather than as "fine".
 */
function nibwp_wcpro_template_version(string $path): string
{
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return '';
    }
    // The docblock is at the top; reading the whole file to find it would mean
    // reading every override on a big theme into memory.
    $head = (string) fread($handle, 8192);
    fclose($handle);

    if (preg_match('/^[\s*#\/]*@version\s+([0-9][0-9a-zA-Z.\-]*)/mi', $head, $match) === 1) {
        return trim($match[1]);
    }

    return '';
}

/**
 * Every PHP template the active theme (or its parent) overrides, and whether it
 * is behind the file it shadows.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_list_overrides(array $input): array|WP_Error
{
    $core = nibwp_wcpro_core_templates_dir();
    if ($core === '') {
        return nibwp_wcpro_err(
            'wc_templates_missing',
            'WooCommerce\'s own templates directory could not be located, so an override cannot be compared against anything.',
            409,
        );
    }

    $prefix = nibwp_wcpro_woo_template_path();
    $stale_only = nibwp_wcpro_bool($input['stale_only'] ?? false);

    $roots = [];
    $child = nibwp_wcpro_theme_dir();
    if (is_wp_error($child)) {
        return $child;
    }
    $parent = nibwp_wcpro_parent_theme_dir();
    if ($parent !== '') {
        // Parent first, so a child override of the same file overwrites the entry
        // and the report names the one that actually wins.
        $roots['parent_theme'] = $parent;
    }
    $roots[$parent === '' ? 'theme' : 'child_theme'] = $child;

    $items = [];
    foreach ($roots as $where => $root) {
        $dir = $root . '/' . $prefix;
        if (!is_dir($dir)) {
            continue;
        }
        foreach (nibwp_wcpro_php_files_in($dir) as $file) {
            $relative = ltrim(substr(nibwp_wcpro_normalize_path($file), strlen($dir)), '/');
            $original = $core . '/' . $relative;
            $theme_version = nibwp_wcpro_template_version($file);
            $core_version = is_file($original) ? nibwp_wcpro_template_version($original) : '';

            if (!is_file($original)) {
                $status = 'not_a_core_template';
            } elseif ($theme_version === '' || $core_version === '') {
                $status = 'unknown';
            } elseif (version_compare($theme_version, $core_version, '<')) {
                $status = 'stale';
            } else {
                $status = 'current';
            }

            $items[$relative] = [
                'file'          => $relative,
                'path'          => $prefix . '/' . $relative,
                'where'         => $where,
                'theme_version' => $theme_version,
                'core_version'  => $core_version,
                'status'        => $status,
                'modified'      => (string) gmdate('Y-m-d', (int) @filemtime($file)),
            ];
        }
    }

    ksort($items);
    $all = array_values($items);
    $stale = array_values(array_filter($all, static fn (array $row): bool => $row['status'] === 'stale'));
    $unknown = array_values(array_filter($all, static fn (array $row): bool => $row['status'] === 'unknown'));
    $orphans = array_values(array_filter($all, static fn (array $row): bool => $row['status'] === 'not_a_core_template'));

    $shown = $stale_only ? $stale : $all;
    $page = nibwp_wcpro_paginate($input, 50, 500);
    $total = count($shown);
    $shown = array_slice($shown, $page['offset'], $page['per_page']);

    $notes = [];
    foreach (array_slice($stale, 0, 10) as $row) {
        $notes[] = sprintf(
            '%s is at version %s but WooCommerce ships %s. Everything WooCommerce added to that file since %s is missing from this shop.',
            (string) $row['file'],
            (string) $row['theme_version'],
            (string) $row['core_version'],
            (string) $row['theme_version'],
        );
    }
    if (count($stale) > 10) {
        $notes[] = sprintf('…and %d more stale override(s). Pass stale_only:true to list only those.', count($stale) - 10);
    }
    if ($orphans !== []) {
        $notes[] = sprintf('%d file(s) in the theme\'s %s/ folder shadow nothing WooCommerce ships — a renamed template, or a leftover from an older version that is simply dead.', count($orphans), $prefix);
    }

    return nibwp_wcpro_ok(
        'list_overrides',
        $all === []
            ? sprintf('The active theme overrides no WooCommerce PHP templates (no %s/ folder with templates in it).', $prefix)
            : sprintf('%d PHP template override(s): %d stale, %d unknown version, %d shadowing nothing.', count($all), count($stale), count($unknown), count($orphans)),
        [
            'items' => $shown,
            'count' => $total,
            'result' => [
                'theme_template_folder' => $prefix,
                'total'    => count($all),
                'stale'    => count($stale),
                'unknown'  => count($unknown),
                'orphaned' => count($orphans),
            ],
            'next_steps' => $notes,
        ],
    );
}

/**
 * Every .php file under a directory, depth first.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_php_files_in(string $dir): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($iterator as $file) {
        $path = nibwp_wcpro_normalize_path((string) $file);
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $out[] = $path;
        }
    }
    sort($out);

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_get_override(array $input): array|WP_Error
{
    $prefix = nibwp_wcpro_woo_template_path();
    $relative = nibwp_wcpro_override_relative($input, $prefix);
    if (is_wp_error($relative)) {
        return $relative;
    }

    $path = nibwp_wcpro_theme_path($relative, true, ['php'], $prefix);
    if (is_wp_error($path)) {
        return $path;
    }

    $content = @file_get_contents((string) $path);
    if ($content === false) {
        return nibwp_wcpro_err('unreadable', sprintf('Could not read %s from the active theme.', $relative), 500);
    }

    $core = nibwp_wcpro_core_templates_dir();
    $inner = ltrim(substr($relative, strlen($prefix)), '/');
    $original = $core === '' ? '' : $core . '/' . $inner;
    $theme_version = nibwp_wcpro_template_version((string) $path);
    $core_version = $original !== '' && is_file($original) ? nibwp_wcpro_template_version($original) : '';
    $stale = $theme_version !== '' && $core_version !== '' && version_compare($theme_version, $core_version, '<');

    return nibwp_wcpro_ok(
        'get_override',
        sprintf(
            '%s in the active theme, version %s against WooCommerce\'s %s%s.',
            $relative,
            $theme_version === '' ? '(none declared)' : $theme_version,
            $core_version === '' ? '(unknown)' : $core_version,
            $stale ? ' — behind, so this shop is missing everything added since' : '',
        ),
        [
            'result' => [
                'file'          => $relative,
                'content'       => $content,
                'theme_version' => $theme_version,
                'core_version'  => $core_version,
                'stale'         => $stale,
                'shadows'       => $inner,
            ],
            'next_steps' => $stale
                ? ['Bring it up to date by starting from WooCommerce\'s current copy of ' . $inner . ' and re-applying the shop\'s changes, then write it back with action:"save_override".']
                : [],
        ],
    );
}

/**
 * The override path a caller asked for, normalised to include the theme's
 * WooCommerce folder whether or not they typed it.
 *
 * @return string|WP_Error
 */
function nibwp_wcpro_override_relative(array $input, string $prefix): string|WP_Error
{
    $raw = str_replace('\\', '/', trim((string) ($input['path'] ?? '')));
    if ($raw === '') {
        return nibwp_wcpro_err(
            'missing_path',
            sprintf('Provide the template "path", e.g. "single-product/price.php" or "%s/single-product/price.php". action:"list_overrides" lists what the theme has.', $prefix),
            422,
        );
    }

    $trimmed = ltrim($raw, '/');

    // A path that already names the folder is left alone — including one that
    // tries to escape, which the confinement below refuses rather than this
    // rewriting into something that looks safe.
    return str_starts_with($trimmed, $prefix . '/') ? $trimmed : $prefix . '/' . $trimmed;
}

/**
 * Write an override into the theme.
 *
 * Only a path where WooCommerce actually ships a template is accepted, so this
 * shadows a real file and cannot be used to drop a new PHP file into the theme.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_save_override(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_theme_write('Saving a template override into the theme');
    if ($denied !== null) {
        return $denied;
    }

    $prefix = nibwp_wcpro_woo_template_path();
    $relative = nibwp_wcpro_override_relative($input, $prefix);
    if (is_wp_error($relative)) {
        return $relative;
    }

    $content = (string) ($input['content'] ?? '');
    if (trim($content) === '') {
        return nibwp_wcpro_err('missing_content', 'Provide the whole file "content". Read the current one with action:"get_override" first — there is no partial write.', 422);
    }

    $core = nibwp_wcpro_core_templates_dir();
    if ($core === '') {
        return nibwp_wcpro_err('wc_templates_missing', 'WooCommerce\'s own templates directory could not be located, so there is nothing to prove this path shadows.', 409);
    }

    // The path has to be one WooCommerce ships. An override of a file that does
    // not exist is not an override — it is a new PHP file in the theme, and this
    // ability does not write those.
    $inner = ltrim(substr($relative, strlen($prefix)), '/');
    $original = $core . '/' . $inner;
    $original_real = realpath($original);
    if ($original_real === false || !nibwp_wcpro_path_within(nibwp_wcpro_normalize_path($original_real), $core)) {
        return nibwp_wcpro_err(
            'not_a_core_template',
            sprintf('WooCommerce ships no template at "%s", so there is nothing to override. Only a path that shadows a real WooCommerce template is written.', $inner),
            422,
        );
    }

    $base = nibwp_wcpro_theme_dir();
    if (is_wp_error($base)) {
        return $base;
    }
    $dir = dirname($base . '/' . $relative);
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return nibwp_wcpro_err('mkdir_failed', sprintf('Could not create %s in the active theme — check the theme directory is writable.', dirname($relative)), 500);
    }

    $target = nibwp_wcpro_theme_path($relative, false, ['php'], $prefix);
    if (is_wp_error($target)) {
        return $target;
    }

    $existed = is_file($target);
    if (file_put_contents($target, $content) === false) {
        return nibwp_wcpro_err('unwritable', sprintf('Could not write %s into the active theme.', $relative), 500);
    }

    $core_version = nibwp_wcpro_template_version($original);
    $written_version = nibwp_wcpro_template_version((string) $target);
    $notes = [];
    if ($core_version !== '' && $written_version === '') {
        $notes[] = sprintf('The file you wrote declares no @version. WooCommerce\'s is %s — keep the @version line, or nothing will ever be able to tell you this override has fallen behind.', $core_version);
    } elseif ($core_version !== '' && $written_version !== '' && version_compare($written_version, $core_version, '<')) {
        $notes[] = sprintf('The file you wrote declares @version %s while WooCommerce ships %s, so it is already recorded as stale. That is correct if the copy really is older.', $written_version, $core_version);
    }
    $notes[] = 'Load the affected shop page and check it renders before telling anyone it is done. A PHP override with a mistake in it is a fatal on that page only.';

    return nibwp_wcpro_ok(
        'save_override',
        sprintf('%s %s in the active theme (%d characters), shadowing WooCommerce\'s %s.', $existed ? 'Replaced' : 'Wrote', $relative, strlen($content), $inner),
        [
            'result' => [
                'file'     => $relative,
                'shadows'  => $inner,
                'replaced' => $existed,
                'bytes'    => strlen($content),
                'core_version'  => $core_version,
                'theme_version' => $written_version,
            ],
            'next_steps' => $notes,
        ],
    );
}

/* ------------------------------------------------------------------ status */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_template_status(): array|WP_Error
{
    $block_theme = function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : null;
    $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
    $parent = nibwp_wcpro_parent_theme_dir();

    $templates = nibwp_wcpro_block_templates('wp_template');
    $by_source = ['plugin' => 0, 'theme' => 0, 'child_theme' => 0, 'database' => 0, 'other' => 0];
    $customised_slugs = [];
    if (!is_wp_error($templates)) {
        foreach ($templates as $template) {
            if (!is_object($template) || !nibwp_wcpro_is_woo_template($template)) {
                continue;
            }
            $origin = nibwp_wcpro_template_origin($template, 'wp_template');
            $key = array_key_exists((string) $origin['provided_by'], $by_source) ? (string) $origin['provided_by'] : 'other';
            $by_source[$key]++;
            if ($key !== 'plugin') {
                $customised_slugs[] = (string) ($template->slug ?? '');
            }
        }
    }

    $overrides = nibwp_wcpro_list_overrides(['stale_only' => false, 'per_page' => 1]);
    $override_counts = is_wp_error($overrides)
        ? ['total' => 0, 'stale' => 0, 'unknown' => 0, 'orphaned' => 0]
        : (array) ($overrides['result'] ?? []);

    $notes = [];
    if (is_wp_error($templates)) {
        $notes[] = 'Block templates could not be listed: ' . $templates->get_error_message();
    }
    if ((int) ($override_counts['stale'] ?? 0) > 0) {
        $notes[] = sprintf('%d PHP override(s) are behind the file they shadow. action:"list_overrides" with stale_only:true names them — this is the usual reason a shop is missing markup WooCommerce added.', (int) $override_counts['stale']);
    }
    if ($by_source['database'] > 0) {
        $notes[] = sprintf('%d template(s) are customised in the database by the site editor. Those outrank any file, including one in the theme.', $by_source['database']);
    }
    if ($block_theme === false && $by_source['database'] + $by_source['theme'] + $by_source['child_theme'] > 0) {
        $notes[] = 'The theme is not a block theme, so block-template customisations here may not be what the shop actually renders — the PHP templates are.';
    }
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        $notes[] = 'DISALLOW_FILE_EDIT is set on this site, so save_template, save_override and delete_theme_file will be refused. That is deliberate; changes have to go through SFTP or version control.';
    }

    return nibwp_wcpro_ok(
        'template_status',
        sprintf(
            '%s theme%s; %d WooCommerce block template(s) resolved, %d customised; %d PHP override(s), %d stale.',
            $block_theme === null ? 'Unknown' : ($block_theme ? 'Block' : 'Classic'),
            $parent === '' ? '' : ' (a child theme)',
            array_sum($by_source),
            array_sum($by_source) - $by_source['plugin'],
            (int) ($override_counts['total'] ?? 0),
            (int) ($override_counts['stale'] ?? 0),
        ),
        [
            'result' => [
                'theme'         => is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Name') : '',
                'is_block_theme' => $block_theme,
                'is_child_theme' => $parent !== '',
                'templates_by_source' => $by_source,
                'customised_templates' => $customised_slugs,
                'php_overrides' => [
                    'folder'   => (string) ($override_counts['theme_template_folder'] ?? nibwp_wcpro_woo_template_path()),
                    'total'    => (int) ($override_counts['total'] ?? 0),
                    'stale'    => (int) ($override_counts['stale'] ?? 0),
                    'unknown'  => (int) ($override_counts['unknown'] ?? 0),
                    'orphaned' => (int) ($override_counts['orphaned'] ?? 0),
                ],
                'file_editing_allowed' => !(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT),
            ],
            'next_steps' => $notes,
        ],
    );
}
