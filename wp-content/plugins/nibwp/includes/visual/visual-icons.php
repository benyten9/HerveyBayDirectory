<?php

declare(strict_types=1);

/**
 * Agent View — the admin screens its address bar can jump to, captured from the
 * real admin menu so every screen a plugin adds is findable too.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Places worth jumping to, opened inside the workspace rather than away from it.
 *
 * Leaving the workspace to look at the Pages list and coming back is three
 * navigations for one glance. These load in a tab beside whatever the agent is
 * doing, and the same-origin rule that lets the agent drive a page lets these
 * be driven too.
 *
 * @return array<int, array{label: string, items: array<int, array{label: string, url: string}>}>
 */
/**
 * Remember this site's admin menu, exactly as WordPress built it.
 *
 * The workspace renders on admin-post.php, which never fires `admin_menu`, so
 * it cannot ask what the menu contains — and firing that action ourselves would
 * run every plugin's registration code for a list of links. Instead this rides
 * along on ordinary admin page loads and keeps a snapshot, which stays current
 * because it is rewritten on every one of them.
 */
add_action('admin_menu', 'nibwp_visual_capture_menu', 99999);

function nibwp_visual_capture_menu(): void
{
    global $menu, $submenu;

    if (!is_array($menu)) {
        return;
    }

    $strip = static function ($title): string {
        // Counts and screen-reader text live in spans: "Comments <span
        // class=awaiting-mod><span>0</span><span class=screen-reader-text>0
        // Comments in moderation</span></span>". Stripping tags alone glued all
        // of that into the label — dropping the spans whole is what was meant.
        $text = preg_replace('#<span\b.*$#is', '', (string) $title) ?? (string) $title;
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s*\d+\s*$/', '', $text) ?? $text);
    };

    $snapshot = [];

    foreach ($menu as $top) {
        if (!is_array($top) || count($top) < 3) {
            continue;
        }

        $label = $strip($top[0] ?? '');
        $slug = (string) ($top[2] ?? '');
        $cap = (string) ($top[1] ?? 'read');

        // Separators have no title and no destination.
        if ($label === '' || $slug === '' || str_starts_with($slug, 'separator')) {
            continue;
        }

        $children = [];
        foreach ((array) ($submenu[$slug] ?? []) as $sub) {
            if (!is_array($sub) || count($sub) < 3) {
                continue;
            }
            $child_label = $strip($sub[0] ?? '');
            $child_slug = (string) ($sub[2] ?? '');
            if ($child_label === '' || $child_slug === '' || $child_slug === '#') {
                continue;
            }
            $children[] = [
                'label' => $child_label,
                'slug' => $child_slug,
                'cap' => (string) ($sub[1] ?? $cap),
            ];
            if (count($children) >= 20) {
                break;
            }
        }

        $snapshot[] = ['label' => $label, 'slug' => $slug, 'cap' => $cap, 'items' => $children];

        if (count($snapshot) >= 40) {
            break;
        }
    }

    if ($snapshot === []) {
        return;
    }

    // Only write when it has actually changed. This runs on every admin page.
    if (get_option('nibwp_visual_menu_map') !== $snapshot) {
        update_option('nibwp_visual_menu_map', $snapshot, false);
    }
}

/**
 * A menu slug turned into somewhere to go.
 *
 * WordPress accepts three shapes here: a bare file (`edit.php`), a file with a
 * query (`edit.php?post_type=page`), and a page slug that belongs to admin.php.
 */
function nibwp_visual_menu_url(string $slug): string
{
    if (preg_match('#^https?://#i', $slug)) {
        return $slug;
    }

    $file = strtok($slug, '?');
    if (is_string($file) && str_ends_with($file, '.php')) {
        return admin_url($slug);
    }

    return admin_url('admin.php?page=' . $slug);
}

/**
 * The site's real admin menu, if one has been seen, filtered to what this
 * account may open.
 *
 * @return array<int, array{label: string, items: array<int, array{label: string, url: string}>}>
 */
/**
 * WordPress's own screens, and ours. Nothing else.
 *
 * A busy site puts twenty plugins in this menu, and a jump list that offers
 * SureCart's coupons beside NibWP's workflows is a plugin directory, not a way
 * around the site. Exact slugs rather than a file match: `edit.php` is Posts,
 * but `edit.php?post_type=…` is whatever a plugin registered.
 */
function nibwp_visual_menu_is_ours(string $slug): bool
{
    $core = [
        'index.php',
        'edit.php',
        'edit.php?post_type=page',
        'upload.php',
        'edit-comments.php',
        'link-manager.php',
        'themes.php',
        'plugins.php',
        'users.php',
        'profile.php',
        'tools.php',
        'options-general.php',
    ];

    if (in_array($slug, $core, true)) {
        return true;
    }

    /**
     * Filters whether a top-level admin menu belongs in the workspace's jump
     * list. Third-party menus are excluded by default.
     *
     * @param bool   $ours Whether to offer it.
     * @param string $slug The menu slug.
     */
    return (bool) apply_filters('nibwp_visual_menu_is_ours', str_starts_with($slug, 'nibwp'), $slug);
}

function nibwp_visual_menu_groups(): array
{
    $snapshot = get_option('nibwp_visual_menu_map', []);
    if (!is_array($snapshot) || $snapshot === []) {
        return [];
    }

    $groups = [];

    foreach ($snapshot as $top) {
        if (!is_array($top) || empty($top['label']) || empty($top['slug'])) {
            continue;
        }
        if (!nibwp_visual_menu_is_ours((string) $top['slug'])) {
            continue;
        }
        // The snapshot was taken by whoever last loaded an admin page, who may
        // have had more rights than whoever is reading it now.
        if (!current_user_can((string) ($top['cap'] ?? 'read'))) {
            continue;
        }

        $clean = static function (string $text): string {
            // Snapshots taken before the capture learned to drop count markup
            // still carry "Comments 00 Comments in moderation". Rewriting the
            // option only helps sites whose menu happens to change afterwards.
            $text = preg_replace('/\s*\d+\s+Comments? in moderation$/i', '', $text) ?? $text;
            $text = preg_replace('/\s+\d+$/', '', $text) ?? $text;

            return trim($text);
        };

        $items = [];
        foreach ((array) ($top['items'] ?? []) as $child) {
            if (empty($child['label']) || empty($child['slug'])) {
                continue;
            }
            if (!current_user_can((string) ($child['cap'] ?? 'read'))) {
                continue;
            }
            $items[] = [
                'label' => $clean((string) $child['label']),
                'url' => nibwp_visual_menu_url((string) $child['slug']),
            ];
        }

        // A top-level with no children of its own is still a destination.
        if ($items === []) {
            $items[] = [
                'label' => $clean((string) $top['label']),
                'url' => nibwp_visual_menu_url((string) $top['slug']),
            ];
        }

        $groups[] = ['label' => $clean((string) $top['label']), 'items' => $items];
    }

    return $groups;
}

function nibwp_visual_shortcuts(): array
{
    $site = [
        ['label' => __('Front page', 'nibwp'), 'url' => home_url('/')],
        ['label' => __('Dashboard', 'nibwp'), 'url' => admin_url()],
        ['label' => __('Pages', 'nibwp'), 'url' => admin_url('edit.php?post_type=page')],
        ['label' => __('Posts', 'nibwp'), 'url' => admin_url('edit.php')],
        ['label' => __('New page', 'nibwp'), 'url' => admin_url('post-new.php?post_type=page')],
        ['label' => __('Media', 'nibwp'), 'url' => admin_url('upload.php')],
        ['label' => __('Menus', 'nibwp'), 'url' => admin_url('nav-menus.php')],
        ['label' => __('Plugins', 'nibwp'), 'url' => admin_url('plugins.php')],
    ];

    // The site editor only exists on a block theme; offering it on a classic
    // theme opens a screen that redirects and looks broken.
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        $site[] = ['label' => __('Site editor', 'nibwp'), 'url' => admin_url('site-editor.php')];
    } else {
        $site[] = ['label' => __('Customizer', 'nibwp'), 'url' => admin_url('customize.php')];
    }

    $brand = function_exists('nibwp_branding_display_name') ? nibwp_branding_display_name() : 'NibWP';

    $own = [
        ['label' => __('Dashboard', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-dashboard')],
        ['label' => __('Connect', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-connect')],
        // The abilities screen is the bare 'nibwp' slug, not 'nibwp-abilities'.
        ['label' => __('AI Abilities', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp')],
        ['label' => __('Workflows', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-workflows')],
        ['label' => __('Skills', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-skills')],
        ['label' => __('Integrations', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-integrations')],
        ['label' => __('Status', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-status')],
        ['label' => __('Activity log', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-audit-log')],
    ];

    // The site's own menu, whatever plugins put in it, after the handful of
    // places nearly everyone wants. A list that stops at Pages and Posts is a
    // list that sends people back to wp-admin the moment they need anything
    // else — which is the entire thing this was built to avoid.
    $real = nibwp_visual_menu_groups();

    if ($real !== []) {
        return array_merge(
            [['label' => __('Jump to', 'nibwp'), 'items' => $site]],
            $real
        );
    }

    return [
        ['label' => __('This site', 'nibwp'), 'items' => $site],
        ['label' => $brand, 'items' => $own],
    ];
}
