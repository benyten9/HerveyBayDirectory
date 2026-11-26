<?php

declare(strict_types=1);

/**
 * Agent View — the screen.
 *
 * The person browses their own site in a frame, pins notes on what they see,
 * and asks for changes in one composer; their AI agent picks the request up
 * through NibWP and works while they watch. It is also where the agent's
 * browser commands run (assets/js/studio-bus.js), so nibwp/visual-* abilities
 * drive the same frame the person is looking at.
 *
 * Full screen and outside the admin shell: this page is a window onto the
 * site, and the admin menu wrapped around it would only take room from it.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('admin_menu', 'nibwp_studio_menu', 80);
add_action('admin_post_nibwp_studio', 'nibwp_studio_render');
add_action('admin_post_nopriv_nibwp_studio', static function (): void {
    auth_redirect();
});
add_action('admin_post_nibwp_studio_snapshot', 'nibwp_studio_snapshot');

// Addresses Agent View had before: links in emails, bookmarks and runners
// configured with the old URL all land here rather than on a blank response.
foreach (['nibwp_agent_view', 'nibwp_visual'] as $nibwp_studio_old_action) {
    add_action('admin_post_' . $nibwp_studio_old_action, static function (): void {
        wp_safe_redirect(nibwp_studio_url());
        exit;
    });
    add_action('admin_post_nopriv_' . $nibwp_studio_old_action, static function (): void {
        auth_redirect();
    });
}
unset($nibwp_studio_old_action);

function nibwp_studio_url(): string
{
    return admin_url('admin-post.php?action=nibwp_studio');
}

/** Agent View, wherever the rest of the plugin links to it. */
function nibwp_visual_url(): string
{
    return nibwp_studio_url();
}

/**
 * Cache-bust Agent View's assets on file change, not just on a version bump:
 * a fix that ships without a version change would otherwise leave browsers
 * running the old script.
 */
function nibwp_visual_asset_version(string $relative): string
{
    $version = defined('NIBWP_VERSION') ? (string) NIBWP_VERSION : '1';
    $file = NIBWP_PLUGIN_DIR . $relative;
    $stamp = file_exists($file) ? filemtime($file) : false;

    return $stamp === false ? $version : $version . '.' . $stamp;
}

/**
 * The mark beside Agent View in the menus. A sparkle rather than a screen: this
 * is the one place in the plugin where something happens by itself while you
 * watch, and it should read as the lively one in a column of settings pages.
 */
function nibwp_visual_spark_svg(int $size = 13, string $class = ''): string
{
    // Each copy needs its own gradient id: two menus render on one admin page,
    // and a shared id paints one sparkle with the other's gradient or nothing.
    static $seq = 0;
    $id = 'nibwp-spark-' . (++$seq);

    $paths = '<path d="M11.2 2.6a.85.85 0 0 1 1.6 0l1.5 4.1a.85.85 0 0 0 .5.5l4.1 1.5a.85.85 0 0 1 0 1.6l-4.1 1.5a.85.85 0 0 0-.5.5l-1.5 4.1a.85.85 0 0 1-1.6 0l-1.5-4.1a.85.85 0 0 0-.5-.5l-4.1-1.5a.85.85 0 0 1 0-1.6l4.1-1.5a.85.85 0 0 0 .5-.5l1.5-4.1z"/>'
        . '<path d="M18.4 15.1a.5.5 0 0 1 .95 0l.62 1.7a.5.5 0 0 0 .3.3l1.7.62a.5.5 0 0 1 0 .95l-1.7.62a.5.5 0 0 0-.3.3l-.62 1.7a.5.5 0 0 1-.95 0l-.62-1.7a.5.5 0 0 0-.3-.3l-1.7-.62a.5.5 0 0 1 0-.95l1.7-.62a.5.5 0 0 0 .3-.3l.62-1.7z"/>';

    return sprintf(
        '<svg class="%4$s" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="url(#%2$s)" focusable="false" aria-hidden="true">'
            . '<defs><linearGradient id="%2$s" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0%%" stop-color="#ffd166"/>'
            . '<stop offset="38%%" stop-color="#ffa62b"/>'
            . '<stop offset="70%%" stop-color="#ff5f9e"/>'
            . '<stop offset="100%%" stop-color="#8b5cf6"/>'
            . '</linearGradient></defs>%3$s</svg>',
        $size,
        $id,
        $paths,
        esc_attr(trim('nibwp-spark ' . $class))
    );
}

function nibwp_studio_menu(): void
{
    // The mark is part of the title because WordPress gives a submenu item no
    // other place to put one. The slug stays nibwp-visual, the one the menu
    // order and every existing link already use.
    $label = '<span class="nibwp-menu-spark__text">' . esc_html__('Agent View', 'nibwp') . '</span>'
        . '<span class="nibwp-menu-spark" aria-hidden="true">' . nibwp_visual_spark_svg(13) . '</span>';

    $hook = add_submenu_page('nibwp-dashboard', __('Agent View', 'nibwp'), $label, 'manage_options', 'nibwp-visual', '__return_null');
    if ($hook === false) {
        return;
    }

    // A doorway, not a page: land straight in Agent View.
    add_action('load-' . $hook, static function (): void {
        wp_safe_redirect(nibwp_studio_url());
        exit;
    });
}

/**
 * The WordPress top bar: Launch Agent View on admin screens, and Edit with
 * NibWP on a page of the site, which opens that page in Agent View with the
 * Builder on (studio-builder.js reads nibwp_builder and open). Inside Agent
 * View's frame the top bar is hidden, so neither shows there.
 */
add_action('admin_bar_menu', static function (WP_Admin_Bar $bar): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (is_admin()) {
        $bar->add_node([
            'id' => 'nibwp-agent-view',
            'title' => nibwp_admin_bar_logo() . '<span class="nibwp-ab__t">' . esc_html__('Launch Agent View', 'nibwp') . '</span>',
            'href' => nibwp_studio_url(),
            'meta' => ['class' => 'nibwp-ab', 'title' => __('Your site, with your AI agent beside it', 'nibwp')],
        ]);

        return;
    }
    if (is_404() || is_search() || is_feed() || is_embed() || is_preview() || is_customize_preview()) {
        return;
    }
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    $path = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $here = set_url_scheme('http://' . $host . $path);
    $bar->add_node([
        'id' => 'nibwp-edit',
        'title' => nibwp_admin_bar_logo() . '<span class="nibwp-ab__t">' . esc_html__('Edit with NibWP', 'nibwp') . '</span>',
        'href' => add_query_arg(['nibwp_builder' => '1', 'open' => rawurlencode($here)], nibwp_studio_url()),
        'meta' => ['class' => 'nibwp-ab', 'title' => __('Edit this page in the NibWP Builder', 'nibwp')],
    ]);
}, 81);

/** The NibWP mark, in the top bar's own gray (it takes the text colour). */
function nibwp_admin_bar_logo(): string
{
    static $svg = null;
    if ($svg === null) {
        $raw = (string) file_get_contents(NIBWP_PLUGIN_DIR . 'assets/nibwp-icon.svg'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own logo
        $raw = (string) preg_replace('#\s(width|height)="[^"]*"#', '', $raw, 2);
        $raw = str_replace(['fill="#ff9148"', '<svg '], ['fill="currentColor"', '<svg class="nibwp-ab__i" aria-hidden="true" focusable="false" '], $raw);
        $svg = (string) preg_replace('#\s(id|data-name)="[^"]*"#', '', $raw);
    }

    return $svg;
}

foreach (['admin_enqueue_scripts', 'wp_enqueue_scripts'] as $nibwp_ab_hook) {
    add_action($nibwp_ab_hook, static function (): void {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }
        wp_add_inline_style('admin-bar', '#wpadminbar .nibwp-ab>.ab-item{display:flex!important;align-items:center;gap:6px}'
            . '#wpadminbar .nibwp-ab .nibwp-ab__i{flex:none;width:18px;height:18px;fill:currentColor;color:#a7aaad;transition:color .1s}'
            . '#wpadminbar .nibwp-ab:hover .nibwp-ab__i,#wpadminbar .nibwp-ab>.ab-item:focus .nibwp-ab__i{color:#72aee6}'
            . '@media screen and (max-width:782px){#wpadminbar li.nibwp-ab{display:block}#wpadminbar .nibwp-ab>.ab-item{padding:0 9px}#wpadminbar .nibwp-ab .nibwp-ab__i{width:26px;height:26px}#wpadminbar .nibwp-ab .nibwp-ab__t{display:none}}');
    });
}
unset($nibwp_ab_hook);

/**
 * Line icons, shared by the markup here and the script, so both sides draw the
 * same set. Paths follow the Lucide 24px grid.
 *
 * @return array<string, string>
 */
function nibwp_studio_icons(): array
{
    return [
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'export' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        'queue' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M8 12h8M8 15.5h5"/>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'builder' => '<rect x="3" y="11" width="8" height="10" rx="1.5"/><rect x="13" y="15" width="8" height="6" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><path d="M7 2.5v6M4 5.5h6"/>',
        'back' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'forward' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'reload' => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
        'desktop' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'tablet' => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M12 18h.01"/>',
        'mobile' => '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
        'comment' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v6"/><path d="M9 10h6"/>',
        'pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'clip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'send' => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'minus' => '<path d="M5 12h14"/>',
        'square' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/>',
        'shapes' => '<path d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.198-.043L16.3 8.9a.7.7 0 0 1-.572 1.1Z"/><rect x="3" y="14" width="7" height="7" rx="1"/><circle cx="17.5" cy="17.5" r="3.5"/>',
        'circle' => '<ellipse cx="12" cy="12" rx="9" ry="7.5"/>',
        'pen' => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'edit' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'spark' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'skill' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'workflow' => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
        'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'stop' => '<rect width="14" height="14" x="5" y="5" rx="2"/>',
        'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'page' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'screen' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><path d="M22 12h-4"/><path d="M6 12H2"/><path d="M12 6V2"/><path d="M12 22v-4"/>',
        'newthread' => '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/>',
        'retry' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'panel' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'type' => '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>',
        'a11y' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="7" r="1"/><path d="m7.5 10 4.5 1.5 4.5-1.5"/><path d="M12 11.5V14"/><path d="m9.5 18 2.5-4 2.5 4"/>',
        'section' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 12h8"/><path d="M12 8v8"/>',
        'gauge' => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'palette' => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.55-2.5 5.55-5.55C21.97 6.01 17.46 2 12 2z"/>',
        'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'tool' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'chevdown' => '<path d="m6 9 6 6 6-6"/>',
        'eyeoff' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'foldall' => '<path d="m7 20 5-5 5 5"/><path d="m7 4 5 5 5-5"/>',
        'unfoldall' => '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>',
        'enter' => '<path d="M9 10 4 15l5 5"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/>',
        'jobs' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'pause' => '<rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/>',
        'play' => '<path d="M7 4.5v15l12-7.5z"/>',
        'grip' => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        'top' => '<path d="M5 4h14"/><path d="m7 14 5-5 5 5"/><path d="M12 9v11"/>',
    ];
}

function nibwp_studio_icon(string $name, int $size = 16): string
{
    $paths = nibwp_studio_icons()[$name] ?? '';
    // Arrows that point along the reading direction; the RTL stylesheet mirrors them.
    $dir = in_array($name, ['back', 'forward', 'chevron', 'enter', 'undo'], true) ? ' nst-dir' : '';

    return '<svg class="nst-i' . $dir . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

/**
 * Skills the person can point the agent at. Locked ones are listed too, marked,
 * so the person learns they exist instead of wondering why one is missing.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_studio_skills(): array
{
    if (!function_exists('nibwp_skills_discover')) {
        return [];
    }

    $out = [];
    foreach (nibwp_skills_discover() as $id => $skill) {
        $out[] = [
            'id' => (string) $id,
            'name' => (string) ($skill['name'] ?? $id),
            'tagline' => (string) ($skill['tagline'] ?? ''),
            'premium' => !empty($skill['premium']),
            'ready' => function_exists('nibwp_skill_is_unlocked') ? nibwp_skill_is_unlocked((string) $id) : empty($skill['premium']),
        ];
    }
    usort($out, static fn($a, $b) => [!$a['ready'], $a['name']] <=> [!$b['ready'], $b['name']]);

    return $out;
}

/**
 * @return array<int, array<string, string>>
 */
function nibwp_studio_workflows(): array
{
    if (!function_exists('nibwp_workflows_posts')) {
        return [];
    }

    $out = [];
    foreach (nibwp_workflows_posts() as $post) {
        if ($post->post_status !== 'publish') {
            continue;
        }
        $out[] = ['slug' => $post->post_name, 'title' => $post->post_title, 'summary' => wp_trim_words((string) $post->post_excerpt, 18)];
    }

    return array_slice($out, 0, 60);
}

/**
 * Niby, the helper: where its few suggestions lead. A renamed (white-label)
 * install never promotes NibWP, and neither does a site without the
 * affiliate page. The commission is read from the cached offer only, so
 * this screen never waits on nibwp.com.
 *
 * @return array<string, mixed>
 */
function nibwp_studio_buddy_config(): array
{
    $offer = get_transient('nibwp_affiliate_offer');
    $rate = is_array($offer) && !empty($offer['rate']) ? (string) $offer['rate'] : '';
    if ($rate === '' && function_exists('nibwp_affiliate_fallback_offer')) {
        $rate = (string) (nibwp_affiliate_fallback_offer()['rate'] ?? '');
    }

    return [
        'promote' => !(function_exists('nibwp_branding_active') && nibwp_branding_active()),
        'affiliate' => function_exists('nibwp_affiliate_page_url') ? esc_url_raw(nibwp_affiliate_page_url()) : '',
        'rate' => $rate,
        'review' => 'https://wordpress.org/support/plugin/nibwp/reviews/#new-post',
        'site' => 'https://www.nibwp.com/',
        // Kind Guy, the Etch pro who stands in for Niby on Etch work.
        'guest' => NIBWP_PLUGIN_URL . 'assets/images/kind-guy-',
    ];
}

/**
 * Ready-made requests for the empty composer. Each is something a site owner
 * asks for often, phrased the way they would phrase it.
 *
 * @return array<int, array<string, string>>
 */
function nibwp_studio_starters(): array
{
    return [
        ['group' => 'check', 'icon' => 'eye', 'label' => __('Review this page', 'nibwp'), 'hint' => __('The five fixes that matter most', 'nibwp'), 'text' => __('Review this page and list the five changes that would improve it most. Do not change anything yet.', 'nibwp')],
        ['group' => 'check', 'icon' => 'mobile', 'label' => __('Fix it on mobile', 'nibwp'), 'hint' => __('Spacing, sizes, overflow', 'nibwp'), 'text' => __('Make this page look right on a phone: check spacing, font sizes and anything that overflows, then fix what is wrong.', 'nibwp')],
        ['group' => 'check', 'icon' => 'a11y', 'label' => __('Check accessibility', 'nibwp'), 'hint' => __('Contrast, labels, focus', 'nibwp'), 'text' => __('Check this page for accessibility problems (contrast, headings, labels, focus) and fix them.', 'nibwp')],
        ['group' => 'check', 'icon' => 'search', 'label' => __('Check SEO', 'nibwp'), 'hint' => __('Title, meta, headings, alt text', 'nibwp'), 'text' => __('Check this page for SEO problems (title, meta description, headings, image alt text, internal links) and fix what you can.', 'nibwp')],
        ['group' => 'check', 'icon' => 'gauge', 'label' => __('Speed it up', 'nibwp'), 'hint' => __('Images, scripts, fonts', 'nibwp'), 'text' => __('Find what makes this page slow (large images, heavy scripts, too many fonts) and fix what you can without changing how it looks.', 'nibwp')],
        ['group' => 'check', 'icon' => 'link', 'label' => __('Fix broken links', 'nibwp'), 'hint' => __('Links, images, dead buttons', 'nibwp'), 'text' => __('Check this page for broken links, missing images and buttons that go nowhere, and fix them.', 'nibwp')],
        ['group' => 'polish', 'icon' => 'type', 'label' => __('Tighten the copy', 'nibwp'), 'hint' => __('Clearer and shorter, same voice', 'nibwp'), 'text' => __('Rewrite the text on this page to be clearer and shorter, in the same voice. Show me the changes before saving.', 'nibwp')],
        ['group' => 'polish', 'icon' => 'palette', 'label' => __('Match the brand', 'nibwp'), 'hint' => __('Colours, fonts, buttons', 'nibwp'), 'text' => __('Make this page match the rest of the site: the same colours, fonts, spacing and button styles.', 'nibwp')],
        ['group' => 'polish', 'icon' => 'spark', 'label' => __('Make the top shine', 'nibwp'), 'hint' => __('Headline, one button, a strong image', 'nibwp'), 'text' => __('Make the top of this page more striking: a clearer headline, one strong call to action, better spacing and a good image. Keep the brand.', 'nibwp')],
        ['group' => 'polish', 'icon' => 'tool', 'label' => __('Tidy the design', 'nibwp'), 'hint' => __('One spacing, type and colour system', 'nibwp'), 'text' => __('Tidy the design of this page: consistent spacing, font sizes and colours from the site\'s design system, and no one-off styles. Keep how it looks.', 'nibwp')],
        ['group' => 'build', 'icon' => 'section', 'label' => __('Add a section', 'nibwp'), 'hint' => __('Describe it, your agent builds it', 'nibwp'), 'text' => __('Add a new section to this page: ', 'nibwp')],
        ['group' => 'build', 'icon' => 'help', 'label' => __('Add an FAQ', 'nibwp'), 'hint' => __('Answered from the page itself', 'nibwp'), 'text' => __('Add a short FAQ section to this page with the questions visitors are most likely to ask, answered from what the page already says.', 'nibwp')],
        ['group' => 'build', 'icon' => 'chat', 'label' => __('Add testimonials', 'nibwp'), 'hint' => __('Three short quotes, real if possible', 'nibwp'), 'text' => __('Add a testimonials section to this page with three short quotes. Use real reviews from the site if there are any, otherwise placeholders I can replace.', 'nibwp')],
        ['group' => 'build', 'icon' => 'target', 'label' => __('Add a call to action', 'nibwp'), 'hint' => __('Tell visitors what to do next', 'nibwp'), 'text' => __('Add a clear call to action near the end of this page that tells visitors what to do next, with one button.', 'nibwp')],
        ['group' => 'learn', 'icon' => 'page', 'label' => __('Explain this page', 'nibwp'), 'hint' => __('How it is built, before you touch it', 'nibwp'), 'text' => __('Explain how this page is built: the builder or template, its main sections, and what I should know before changing it. Change nothing.', 'nibwp')],
        ['group' => 'learn', 'icon' => 'globe', 'label' => __('Translate this page', 'nibwp'), 'hint' => __('Say the language, keep the layout', 'nibwp'), 'text' => __('Translate this page, keeping the layout and the brand voice, into: ', 'nibwp')],
        ['group' => 'learn', 'icon' => 'edit', 'label' => __('Write a blog post', 'nibwp'), 'hint' => __('In the site\'s voice, saved as a draft', 'nibwp'), 'text' => __('Write a blog post for this site, in its voice, saved as a draft, about: ', 'nibwp')],
    ];
}

/**
 * Names of the starter groups, in the order they show.
 *
 * @return array<string, string>
 */
function nibwp_studio_starter_groups(): array
{
    return [
        'check' => __('Check', 'nibwp'),
        'polish' => __('Polish', 'nibwp'),
        'build' => __('Build', 'nibwp'),
        'learn' => __('Learn and write', 'nibwp'),
    ];
}

/**
 * Quick ways to get somewhere from the address bar: the site's main places,
 * its content types, NibWP's screens, and every plugin's screens from the
 * real admin menu (as last seen on an admin page). `top` rows show as soon
 * as the bar is clicked; the rest are found by typing. `group` heads the
 * row on click, `meta` says where a typed result lives.
 *
 * @return array<int, array{title: string, url: string, group: string, meta: string, kind: string, top: bool}>
 */
function nibwp_studio_shortcuts(): array
{
    $rows = [];
    $seen = [];
    $add = static function (string $title, string $url, string $group, string $meta, string $kind, bool $top) use (&$rows, &$seen): void {
        $key = $meta . "\0" . $title;
        // Agent View inside Agent View helps nobody.
        if ($title === '' || $url === '' || isset($seen[$url]) || isset($seen[$key]) || preg_match('/nibwp-visual|nibwp_studio/', $url)) {
            return;
        }
        $seen[$url] = $seen[$key] = true;
        $rows[] = ['title' => $title, 'url' => $url, 'group' => $group, 'meta' => $meta, 'kind' => $kind, 'top' => $top];
    };
    $clean = static function (string $text): string {
        $text = preg_replace('/\s*\d+\s+Comments? in moderation$/i', '', $text) ?? $text;
        $text = preg_replace('/\s+\d+$/', '', $text) ?? $text;

        return trim(html_entity_decode($text, ENT_QUOTES));
    };

    $groups = function_exists('nibwp_visual_shortcuts') ? nibwp_visual_shortcuts() : [];
    foreach ((array) ($groups[0]['items'] ?? []) as $item) {
        $add((string) ($item['label'] ?? ''), (string) ($item['url'] ?? ''), __('Jump to', 'nibwp'), '', 'site', true);
    }

    $menu = get_option('nibwp_visual_menu_map', []);
    $menu = is_array($menu) && function_exists('nibwp_visual_menu_url') ? $menu : [];
    $heads = ['content' => __('Content', 'nibwp'), 'plugin' => __('Plugins', 'nibwp')];
    foreach ($menu as $top) {
        if (!is_array($top) || empty($top['label']) || empty($top['slug']) || !current_user_can((string) ($top['cap'] ?? 'read'))) {
            continue;
        }
        $slug = (string) $top['slug'];
        $label = $clean((string) $top['label']);
        if (str_starts_with($slug, 'nibwp')) {
            $kind = 'nibwp';
        } elseif (function_exists('nibwp_visual_menu_is_ours') && nibwp_visual_menu_is_ours($slug)) {
            $kind = 'admin';
        } else {
            $kind = preg_match('/^edit\.php\?post_type=([\w-]+)$/', $slug, $pt) && post_type_exists($pt[1]) && is_post_type_viewable($pt[1]) ? 'content' : 'plugin';
        }
        // Content types and plugins show once by their own name; their
        // screens are found by typing.
        if (isset($heads[$kind])) {
            $add($label, nibwp_visual_menu_url($slug), $heads[$kind], '', $kind, true);
        }
        foreach ((array) ($top['items'] ?? []) as $child) {
            if (!is_array($child) || empty($child['label']) || empty($child['slug']) || !current_user_can((string) ($child['cap'] ?? 'read'))) {
                continue;
            }
            $add($clean((string) $child['label']), nibwp_visual_menu_url((string) $child['slug']), $label, $label, $kind, $kind === 'nibwp');
        }
        if (!isset($heads[$kind])) {
            $add($label, nibwp_visual_menu_url($slug), $label, '', $kind, false);
        }
    }

    // No admin menu seen yet: NibWP's own screens from the fixed list.
    if ($menu === [] && isset($groups[1])) {
        foreach ((array) $groups[1]['items'] as $item) {
            $add((string) ($item['label'] ?? ''), (string) ($item['url'] ?? ''), (string) $groups[1]['label'], (string) $groups[1]['label'], 'nibwp', true);
        }
    }

    return $rows;
}

/**
 * The integrations this site can use (their plugin or service is there),
 * switched-on ones first, for the address bar.
 *
 * @return array<int, array{key: string, name: string, on: bool}>
 */
function nibwp_studio_integrations(): array
{
    if (!function_exists('nibwp_get_integrations')) {
        return [];
    }

    $out = [];
    foreach (nibwp_get_integrations() as $key => $int) {
        if (!empty($int['hidden']) || empty($int['plugin_available'])) {
            continue;
        }
        $out[] = ['key' => (string) $key, 'name' => (string) ($int['name'] ?? $key), 'on' => !empty($int['enabled'])];
    }
    usort($out, static fn($a, $b) => [!$a['on'], strtolower($a['name'])] <=> [!$b['on'], strtolower($b['name'])]);

    return $out;
}

/** The message that starts an agent's listening loop. Pasted once per session. */
function nibwp_studio_start_prompt(): string
{
    return sprintf(
        /* translators: %s: site address */
        __('Work with me in NibWP Agent View on %s. Call nibwp/studio-listen and handle each request it returns: use your NibWP tools to do the work, post progress and your answer with nibwp/studio-reply (navigate or reload so I see the result), and ask me with nibwp/studio-ask when you need a decision. After each reply, call nibwp/studio-listen again, and keep going until it returns stop. Quiet stretches are normal: keep listening without stopping or asking me whether to continue; Agent View checks in with me and tells you when to rest.', 'nibwp'),
        home_url('/')
    );
}

function nibwp_studio_render(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to open Agent View.', 'nibwp'), esc_html__('Agent View', 'nibwp'), ['response' => 403]);
    }

    $uid = get_current_user_id();
    $user = wp_get_current_user();
    nibwp_studio_mark_open($uid);

    $boot = [
        'rest' => esc_url_raw(rest_url('nibwp/v1/studio/')),
        'snapshot' => esc_url_raw(admin_url('admin-post.php?action=nibwp_studio_snapshot&_nst=' . wp_create_nonce('nibwp_studio_snapshot'))),
        'media' => esc_url_raw(rest_url('wp/v2/media')),
        'nonce' => wp_create_nonce('wp_rest'),
        'home' => esc_url_raw(home_url('/')),
        'admin' => esc_url_raw(admin_url()),
        'connect' => esc_url_raw(admin_url('admin.php?page=nibwp-connect')),
        'approval' => function_exists('nibwp_visual_approval_required') ? nibwp_visual_approval_required() : true,
        'siteName' => html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES),
        'brand' => function_exists('nibwp_branding_display_name') ? nibwp_branding_display_name() : 'NibWP',
        'user' => ['name' => $user->display_name, 'avatar' => (string) get_avatar_url($uid, ['size' => 64])],
        'thread' => nibwp_studio_thread($uid),
        'threadName' => (string) (nibwp_studio_thread_names($uid)[nibwp_studio_thread($uid)] ?? ''),
        'presence' => nibwp_studio_presence($uid),
        'connected' => function_exists('nibwp_visual_connection_state') ? (bool) (nibwp_visual_connection_state()['connected'] ?? false) : false,
        'startPrompt' => nibwp_studio_start_prompt(),
        'skills' => nibwp_studio_skills(),
        'workflows' => nibwp_studio_workflows(),
        'starters' => nibwp_studio_starters(),
        'starterGroups' => nibwp_studio_starter_groups(),
        'shortcuts' => nibwp_studio_shortcuts(),
        'integrations' => nibwp_studio_integrations(),
        'integrationsUrl' => esc_url_raw(admin_url('admin.php?page=nibwp-integrations')),
        'maxUpload' => (int) wp_max_upload_size(),
        'icons' => nibwp_studio_icons(),
        'icon' => NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg',
        'buddy' => nibwp_studio_buddy_config(),
        'builder' => [
            'builders' => nibwp_studio_builder_targets(),
            'system' => nibwp_studio_builder_system(),
            'items' => nibwp_studio_builder_designs($uid),
            'templates' => nibwp_studio_builder_templates(),
            'components' => nibwp_studio_builder_components($uid),
            'css' => (object) nibwp_studio_builder_css(),
            'direct' => current_user_can('unfiltered_html'),
        ],
    ];

    $css = (function_exists('nibwp_css_url') ? nibwp_css_url('admin-studio.css') : NIBWP_PLUGIN_URL . 'assets/css/admin-studio.css');
    $version = function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/css/admin-studio.css') : NIBWP_VERSION;

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(sprintf(
    /* translators: %s: site name */
    __('Agent View — %s', 'nibwp'),
    $boot['siteName']
)); ?></title>
<link rel="icon" href="<?php echo esc_url($boot['icon']); ?>">
<link rel="stylesheet" href="<?php echo esc_url($css . '?v=' . $version); ?>">
<script>
(function () {
    try {
        var t = window.localStorage.getItem('nibwp-studio-theme');
        if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-theme', t); }
    } catch (e) {}
})();
</script>
</head>
<body class="nst-body">
<div class="nst" id="nst" data-pane="site">
    <?php // Runners from before this screen check for #nw-vs to know they reached Agent View. ?>
    <span id="nw-vs" hidden></span>
    <header class="nst-bar">
        <a class="nst-brand" href="<?php echo esc_url($boot['admin']); ?>" title="<?php esc_attr_e('Back to the dashboard', 'nibwp'); ?>">
            <img src="<?php echo esc_url($boot['icon']); ?>" alt="" width="22" height="22">
            <span class="nst-brand__name"><?php esc_html_e('Agent View', 'nibwp'); ?></span>
            <span class="nst-beta"><?php esc_html_e('New', 'nibwp'); ?></span>
        </a>

        <button type="button" class="nst-presence" id="nst-presence" data-state="none" aria-haspopup="dialog" aria-controls="nst-connect">
            <span class="nst-presence__dot" aria-hidden="true"></span>
            <span class="nst-presence__text" id="nst-presence-text"><?php esc_html_e('No agent listening', 'nibwp'); ?></span>
        </button>
        <button type="button" class="nst-jobs" id="nst-jobs" aria-haspopup="dialog" aria-expanded="false" aria-controls="nst-jobs-pop" aria-label="<?php esc_attr_e('Jobs for your agent', 'nibwp'); ?>">
            <?php echo nibwp_studio_icon('jobs', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
            <span id="nst-jobs-n">0</span>
        </button>
        <div class="nst-jobs-pop" id="nst-jobs-pop" role="dialog" aria-label="<?php esc_attr_e('Jobs for your agent', 'nibwp'); ?>" hidden></div>
        <button type="button" class="nst-jobs nst-queue" id="nst-queue" aria-haspopup="dialog" aria-expanded="false" aria-controls="nst-queue-pop" aria-label="<?php esc_attr_e('Queue: requests waiting to run together', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Queue', 'nibwp'); ?>">
            <?php echo nibwp_studio_icon('queue', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
            <span id="nst-queue-n">0</span>
        </button>
        <div class="nst-queue-pop" id="nst-queue-pop" role="dialog" aria-label="<?php esc_attr_e('Queue', 'nibwp'); ?>" hidden></div>
        <button type="button" class="nst-ib nst-buddy-btn" id="nst-buddy-btn" aria-pressed="true" aria-label="<?php esc_attr_e('Niby', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Niby', 'nibwp'); ?>"><img src="<?php echo esc_url($boot['icon']); ?>" alt="" width="18" height="18"></button>

        <nav class="nst-nav" aria-label="<?php esc_attr_e('Browse the site', 'nibwp'); ?>">
            <button type="button" class="nst-ib" id="nst-back" aria-label="<?php esc_attr_e('Back', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Back', 'nibwp'); ?>"><?php echo nibwp_studio_icon('back'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            <button type="button" class="nst-ib" id="nst-forward" aria-label="<?php esc_attr_e('Forward', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Forward', 'nibwp'); ?>"><?php echo nibwp_studio_icon('forward'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            <button type="button" class="nst-ib" id="nst-reload" aria-label="<?php esc_attr_e('Reload the page', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Reload', 'nibwp'); ?>"><?php echo nibwp_studio_icon('reload'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>

            <div class="nst-omni" id="nst-omni-wrap">
                <?php echo nibwp_studio_icon('search', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <input type="text" class="nst-omni__input" id="nst-omni" autocomplete="off" spellcheck="false"
                       role="combobox" aria-expanded="false" aria-controls="nst-omni-menu" aria-autocomplete="list"
                       placeholder="<?php esc_attr_e('Search your site, type a path, or paste any web address', 'nibwp'); ?>"
                       aria-label="<?php esc_attr_e('Search the site or type a path', 'nibwp'); ?>">
                <span class="nst-snap" id="nst-snap" hidden data-tip="<?php esc_attr_e('A copy of another site with its scripts off: read it, point at it, copy from it. Menus and forms that need scripts do not work.', 'nibwp'); ?>"><?php esc_html_e('Snapshot', 'nibwp'); ?></span>
                <kbd class="nst-kbd" id="nst-omni-kbd">Ctrl K</kbd>
                <div class="nst-omni__menu" id="nst-omni-menu" role="listbox" hidden></div>
            </div>
        </nav>

        <div class="nst-devices" role="radiogroup" aria-label="<?php esc_attr_e('Preview width', 'nibwp'); ?>">
            <?php foreach (['desktop' => __('Desktop', 'nibwp'), 'tablet' => __('Tablet', 'nibwp'), 'mobile' => __('Phone', 'nibwp')] as $nst_key => $nst_label): ?>
                <button type="button" class="nst-ib" role="radio" aria-checked="<?php echo $nst_key === 'desktop' ? 'true' : 'false'; ?>" data-device="<?php echo esc_attr($nst_key); ?>" aria-label="<?php echo esc_attr($nst_label); ?>" data-tip="<?php echo esc_attr($nst_label); ?>"><?php echo nibwp_studio_icon($nst_key); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            <?php endforeach; ?>
        </div>

        <div class="nst-modes" role="group" aria-label="<?php esc_attr_e('Mark the page', 'nibwp'); ?>">
            <button type="button" class="nst-mode" id="nst-comment" aria-pressed="false" data-tip="<?php esc_attr_e('Point at something on the page (Ctrl+Shift+M)', 'nibwp'); ?>"><?php echo nibwp_studio_icon('pin', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Comment', 'nibwp'); ?></span></button>
            <button type="button" class="nst-mode" id="nst-draw" aria-pressed="false" data-tip="<?php esc_attr_e('Draw on the page: box, circle or pen (Ctrl+Shift+D)', 'nibwp'); ?>"><?php echo nibwp_studio_icon('shapes', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Draw', 'nibwp'); ?></span></button>
            <div class="nst-notes">
                <button type="button" class="nst-mode nst-notes__btn" id="nst-notes-btn" aria-haspopup="true" aria-expanded="false" aria-controls="nst-notes-menu" aria-label="<?php esc_attr_e('All comments', 'nibwp'); ?>" data-tip="<?php esc_attr_e('All comments', 'nibwp'); ?>"><b class="nst-notes__n" id="nst-notes-n">0</b><?php echo nibwp_studio_icon('chevron', 13); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <div class="nst-menu nst-notes__menu" id="nst-notes-menu" role="menu" hidden>
                    <button type="button" role="menuitemcheckbox" aria-checked="true" id="nst-notes-all"><?php echo nibwp_studio_icon('check', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Show all comments', 'nibwp'); ?></button>
                    <div class="nst-notes__list" id="nst-notes-list"></div>
                </div>
            </div>
        </div>

        <button type="button" class="nst-mode nst-build" id="nst-wire" aria-pressed="false" data-tip="<?php esc_attr_e('Visual builder', 'nibwp'); ?>"><?php echo nibwp_studio_icon('builder', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Builder', 'nibwp'); ?></span><i class="nst-betatag"><?php esc_html_e('Beta', 'nibwp'); ?></i></button>

        <div class="nst-bar__end">
            <button type="button" class="nst-exportbtn" data-act="export" data-tip="<?php esc_attr_e('Static site', 'nibwp'); ?>"><?php echo nibwp_studio_icon('export', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Export', 'nibwp'); ?></span><i class="nst-betatag"><?php esc_html_e('Beta', 'nibwp'); ?></i></button>
            <button type="button" class="nst-ib" id="nst-media" aria-haspopup="dialog" aria-controls="nst-media-dlg" aria-label="<?php esc_attr_e('Media', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Media', 'nibwp'); ?>"><?php echo nibwp_studio_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            <a class="nst-ib" id="nst-edit" href="#" target="_blank" rel="noopener" hidden aria-label="<?php esc_attr_e('Edit this page', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Edit this page', 'nibwp'); ?>"><?php echo nibwp_studio_icon('edit'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></a>
            <a class="nst-ib" id="nst-open" href="<?php echo esc_url($boot['home']); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e('Open this page in a new tab', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Open in a new tab', 'nibwp'); ?>"><?php echo nibwp_studio_icon('external'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></a>
            <button type="button" class="nst-ib" id="nst-theme" aria-label="<?php esc_attr_e('Switch light or dark', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Light or dark', 'nibwp'); ?>"><span class="nst-when-dark"><?php echo nibwp_studio_icon('sun'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span><span class="nst-when-light"><?php echo nibwp_studio_icon('moon'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span></button>
            <div class="nst-more">
                <button type="button" class="nst-ib" id="nst-more" aria-haspopup="true" aria-expanded="false" aria-controls="nst-more-menu" aria-label="<?php esc_attr_e('More', 'nibwp'); ?>"><?php echo nibwp_studio_icon('more'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <div class="nst-menu" id="nst-more-menu" role="menu" hidden>
                    <button type="button" role="menuitem" data-act="new"><?php echo nibwp_studio_icon('newthread', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('New thread', 'nibwp'); ?></button>
                    <button type="button" role="menuitem" data-act="connect"><?php echo nibwp_studio_icon('plug', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Connect your agent', 'nibwp'); ?></button>
                    <button type="button" role="menuitem" data-act="stop"><?php echo nibwp_studio_icon('stop', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('End the agent session', 'nibwp'); ?></button>
                    <button type="button" role="menuitemcheckbox" aria-checked="true" data-act="approval" title="<?php esc_attr_e('Your agent asks you first before it clicks, types or picks something on a page. Edits it saves through other tools are not covered.', 'nibwp'); ?>"><?php echo nibwp_studio_icon('check', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Ask before page actions', 'nibwp'); ?></button>
                    <a role="menuitem" href="<?php echo esc_url($boot['admin']); ?>"><?php echo nibwp_studio_icon('back', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Back to WP Admin', 'nibwp'); ?></a>
                </div>
            </div>
        </div>

        <div class="nst-panes" role="tablist" aria-label="<?php esc_attr_e('Show', 'nibwp'); ?>">
            <button type="button" role="tab" data-pane="chat" aria-selected="false"><?php echo nibwp_studio_icon('chat', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Chat', 'nibwp'); ?></button>
            <button type="button" role="tab" data-pane="site" aria-selected="true"><?php echo nibwp_studio_icon('desktop', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Site', 'nibwp'); ?></button>
        </div>
    </header>

    <main class="nst-main">
        <section class="nst-side" id="nst-side" aria-label="<?php esc_attr_e('Conversation with your agent', 'nibwp'); ?>">
            <div class="nst-rail" id="nst-rail" aria-label="<?php esc_attr_e('Panel', 'nibwp'); ?>">
                <button type="button" class="nst-ib" data-rail="open" aria-label="<?php esc_attr_e('Open the panel', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Open the panel ([)', 'nibwp'); ?>"><?php echo nibwp_studio_icon('panel'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <button type="button" class="nst-ib nst-rail__chat" data-rail="chat" aria-label="<?php esc_attr_e('Chat', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Chat', 'nibwp'); ?>"><?php echo nibwp_studio_icon('chat'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span class="nst-rail__dot" hidden></span></button>
                <button type="button" class="nst-ib" data-rail="history" aria-label="<?php esc_attr_e('History', 'nibwp'); ?>" data-tip="<?php esc_attr_e('History', 'nibwp'); ?>"><?php echo nibwp_studio_icon('retry'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <button type="button" class="nst-ib" data-rail="new" aria-label="<?php esc_attr_e('New thread', 'nibwp'); ?>" data-tip="<?php esc_attr_e('New thread', 'nibwp'); ?>"><?php echo nibwp_studio_icon('newthread'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            </div>

            <div class="nst-side__head">
                <div class="nst-tabs" role="tablist" aria-label="<?php esc_attr_e('Panel', 'nibwp'); ?>">
                    <button type="button" role="tab" id="nst-tab-chat" data-tab="chat" aria-selected="true" aria-controls="nst-chat"><?php esc_html_e('Chat', 'nibwp'); ?></button>
                    <button type="button" role="tab" id="nst-tab-history" data-tab="history" aria-selected="false" aria-controls="nst-history"><?php esc_html_e('History', 'nibwp'); ?></button>
                </div>
                <button type="button" class="nst-side__title" id="nst-thread-title"></button>
                <button type="button" class="nst-ib nst-ib--sm" id="nst-threads-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="nst-tpop" aria-label="<?php esc_attr_e('Your threads', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Your threads', 'nibwp'); ?>"><?php echo nibwp_studio_icon('folder', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span class="nst-threads-n" id="nst-threads-n" hidden></span></button>
                <button type="button" class="nst-ib nst-ib--sm" id="nst-find-req" aria-expanded="false" aria-controls="nst-reqfind" aria-label="<?php esc_attr_e('Search the requests', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Search the requests', 'nibwp'); ?>"><?php echo nibwp_studio_icon('search', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <button type="button" class="nst-ib nst-ib--sm" id="nst-fold-all" aria-pressed="false" aria-label="<?php esc_attr_e('Fold all requests', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Fold all requests', 'nibwp'); ?>"><?php echo nibwp_studio_icon('foldall', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <button type="button" class="nst-ib nst-ib--sm" id="nst-new" aria-label="<?php esc_attr_e('New thread', 'nibwp'); ?>" data-tip="<?php esc_attr_e('New thread', 'nibwp'); ?>"><?php echo nibwp_studio_icon('newthread', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                <button type="button" class="nst-ib nst-ib--sm" id="nst-collapse" aria-expanded="true" aria-controls="nst-side" aria-label="<?php esc_attr_e('Collapse the panel', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Collapse ([)', 'nibwp'); ?>"><?php echo nibwp_studio_icon('panel', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            </div>

            <div class="nst-tpop" id="nst-tpop" role="dialog" aria-label="<?php esc_attr_e('Your threads', 'nibwp'); ?>" hidden>
                <div class="nst-tpop__head">
                    <strong class="nst-tpop__h"><?php esc_html_e('Your threads', 'nibwp'); ?> <span class="nst-tpop__n" id="nst-tpop-n"></span></strong>
                    <button type="button" class="nst-tpop__new" id="nst-tpop-new"><?php echo nibwp_studio_icon('newthread', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('New thread', 'nibwp'); ?></button>
                </div>
                <label class="nst-tpop__find"><?php echo nibwp_studio_icon('search', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><input type="search" id="nst-tpop-q" autocomplete="off" placeholder="<?php esc_attr_e('Find a thread…', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Find a thread', 'nibwp'); ?>"></label>
                <div class="nst-tpop__list" id="nst-tpop-list"></div>
                <p class="nst-tpop__foot"><?php esc_html_e('Names save as you type. Threads are your sessions with the agent.', 'nibwp'); ?></p>
            </div>

            <div class="nst-reqfind" id="nst-reqfind" role="search" hidden>
                <?php echo nibwp_studio_icon('search', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <input type="search" id="nst-reqfind-q" autocomplete="off" placeholder="<?php esc_attr_e('Search the requests…', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Search the requests', 'nibwp'); ?>">
                <span class="nst-reqfind__n" id="nst-reqfind-n" aria-live="polite"></span>
                <button type="button" class="nst-ib nst-ib--sm" id="nst-reqfind-x" aria-label="<?php esc_attr_e('Close the search', 'nibwp'); ?>"><?php echo nibwp_studio_icon('x', 13); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            </div>

            <div class="nst-history" id="nst-history" role="tabpanel" aria-labelledby="nst-tab-history" hidden>
                <section class="nst-hsec">
                    <h2 class="nst-hsec__h"><?php esc_html_e('Versions of this page', 'nibwp'); ?></h2>
                    <div id="nst-versions"></div>
                </section>
                <section class="nst-hsec">
                    <h2 class="nst-hsec__h"><?php esc_html_e('Threads', 'nibwp'); ?></h2>
                    <div id="nst-threads"></div>
                </section>
            </div>

            <div class="nst-chat" id="nst-chat" role="tabpanel" aria-labelledby="nst-tab-chat">
            <div class="nst-feed" id="nst-feed" role="log" aria-live="polite" aria-relevant="additions"></div>

            <div class="nst-welcome" id="nst-welcome">
                <h1 class="nst-welcome__h"><?php echo esc_html(sprintf(
                    /* translators: %s: site name */
                    __('What should change on %s?', 'nibwp'),
                    $boot['siteName']
                )); ?></h1>
                <p class="nst-welcome__p"><?php printf(
                    /* translators: %s: the Comment shortcut, like Ctrl+Shift+M */
                    esc_html__('Browse your site on the right. Press %s and click anything to leave a note.', 'nibwp'),
                    '<kbd class="nst-kbd" data-key>Ctrl+Shift+M</kbd>'
                ); ?></p>
                <p class="nst-welcome__label"><?php esc_html_e('Start from', 'nibwp'); ?></p>
                <div class="nst-starters" id="nst-starters"></div>
            </div>
            </div>

            <form class="nst-composer" id="nst-composer" autocomplete="off">
                <div class="nst-chips" id="nst-chips" aria-label="<?php esc_attr_e('Sent with this message', 'nibwp'); ?>"></div>
                <label class="screen-reader-text" for="nst-input"><?php esc_html_e('Message to your agent', 'nibwp'); ?></label>
                <textarea id="nst-input" class="nst-input" rows="1" placeholder="<?php esc_attr_e('Ask for a change, or point at the page (Ctrl+Shift+M)', 'nibwp'); ?>"></textarea>
                <div class="nst-composer__row">
                    <button type="button" class="nst-ib nst-ib--plus" id="nst-plus" aria-haspopup="dialog" aria-controls="nst-lightbox" aria-label="<?php esc_attr_e('Add a page, file, skill or workflow', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Add context', 'nibwp'); ?>"><?php echo nibwp_studio_icon('plus'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <button type="button" class="nst-ib" id="nst-pin" aria-pressed="false" aria-label="<?php esc_attr_e('Point at something on the page', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Point at page', 'nibwp'); ?>"><?php echo nibwp_studio_icon('pin'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <button type="button" class="nst-ib" id="nst-attach" aria-label="<?php esc_attr_e('Attach files', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Attach files', 'nibwp'); ?>"><?php echo nibwp_studio_icon('clip'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <input type="file" id="nst-file" multiple hidden>
                    <span class="nst-composer__hint" id="nst-hint" aria-live="polite"></span>
                    <button type="button" class="nst-modebtn" id="nst-mode" aria-haspopup="menu" aria-label="<?php esc_attr_e('How NibWP handles your request', 'nibwp'); ?>"></button>
                    <button type="button" class="nst-halt" id="nst-halt" hidden aria-label="<?php esc_attr_e('Stop what is running', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Stop', 'nibwp'); ?>"><?php echo nibwp_studio_icon('stop', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <button type="submit" class="nst-send" id="nst-send" aria-label="<?php esc_attr_e('Send', 'nibwp'); ?>"><?php echo nibwp_studio_icon('send', 18); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                </div>
            </form>
        </section>

        <section class="nst-stage" id="nst-stage" aria-label="<?php esc_attr_e('Your site', 'nibwp'); ?>">
            <div class="nst-tabbar" id="nst-tabbar" role="tablist" aria-label="<?php esc_attr_e('Open pages', 'nibwp'); ?>"></div>
            <div class="nst-tabmenu" id="nst-tabmenu" role="menu" aria-label="<?php esc_attr_e('Tab', 'nibwp'); ?>" hidden></div>
            <div class="nst-canvas" id="nst-canvas">
                <div class="nst-artboard" id="nst-artboard" data-device="desktop">
                    <?php // data-vs-frame is how the headless runner finds the frame to photograph. No allow-top-navigation: a page must not replace Agent View around it. ?>
                    <iframe id="nst-frame" class="nst-frame" data-vs-frame="main" sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-modals allow-downloads" title="<?php esc_attr_e('Your site', 'nibwp'); ?>" src="<?php echo esc_url($boot['home']); ?>"></iframe>
                    <div class="nst-progress" id="nst-progress" aria-hidden="true"></div>
                </div>
            </div>
            <div class="nst-versionbar" id="nst-versionbar" role="status" hidden>
                <?php echo nibwp_studio_icon('retry', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <span class="nst-versionbar__text" id="nst-versionbar-text"></span>
                <button type="button" class="nst-btn nst-btn--ghost" id="nst-version-back"><?php esc_html_e('Back to current', 'nibwp'); ?></button>
                <button type="button" class="nst-btn" id="nst-version-restore"><?php esc_html_e('Restore this version', 'nibwp'); ?></button>
            </div>
            <button type="button" class="nst-peek" id="nst-peek" hidden>
                <img src="<?php echo esc_url($boot['icon']); ?>" alt="" width="18" height="18">
                <span class="nst-peek__text" id="nst-peek-text"></span>
            </button>
            <div class="nst-stood" id="nst-stood" role="alert" hidden>
                <span><?php esc_html_e('Agent View is open in another tab, so your agent’s browser commands go there now.', 'nibwp'); ?></span>
                <button type="button" class="nst-btn" id="nst-stood-reload"><?php esc_html_e('Use this tab', 'nibwp'); ?></button>
            </div>
            <div class="nst-proofbar" id="nst-proofbar" hidden>
                <div class="nst-tools" role="radiogroup" aria-label="<?php esc_attr_e('How to mark the page', 'nibwp'); ?>">
                    <?php foreach (['point' => ['pin', __('Point at an element (V)', 'nibwp')], 'box' => ['square', __('Box (B)', 'nibwp')], 'circle' => ['circle', __('Circle (O)', 'nibwp')], 'pen' => ['pen', __('Pen (P)', 'nibwp')]] as $nst_tool => [$nst_icon, $nst_label]): ?>
                        <button type="button" class="nst-tool" role="radio" aria-checked="<?php echo $nst_tool === 'point' ? 'true' : 'false'; ?>" data-tool="<?php echo esc_attr($nst_tool); ?>" aria-label="<?php echo esc_attr($nst_label); ?>" title="<?php echo esc_attr($nst_label); ?>"><?php echo nibwp_studio_icon($nst_icon, 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <?php endforeach; ?>
                </div>
                <span id="nst-proof-hint"><?php esc_html_e('Click anything on the page to leave a note on it.', 'nibwp'); ?></span>
                <button type="button" class="nst-proofbar__done" id="nst-proof-done"><?php esc_html_e('Done', 'nibwp'); ?> <kbd class="nst-kbd">Esc</kbd></button>
            </div>
            <div class="nst-note" id="nst-note" role="dialog" aria-label="<?php esc_attr_e('Note on this element', 'nibwp'); ?>" hidden>
                <div class="nst-note__head" title="<?php esc_attr_e('Drag to move', 'nibwp'); ?>"><span class="nst-note__n" id="nst-note-n">1</span><code class="nst-note__what" id="nst-note-what"></code></div>
                <textarea class="nst-note__text" id="nst-note-text" rows="3" placeholder="<?php esc_attr_e('What should change here?', 'nibwp'); ?>"></textarea>
                <div class="nst-note__row">
                    <button type="button" class="nst-modebtn nst-modebtn--sm" id="nst-note-mode" aria-haspopup="menu" aria-label="<?php esc_attr_e('How NibWP handles your request', 'nibwp'); ?>"></button>
                    <button type="button" class="nst-btn nst-btn--ghost nst-btn--icon" id="nst-note-cancel" aria-label="<?php esc_attr_e('Remove this note', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Remove note', 'nibwp'); ?>" hidden><?php echo nibwp_studio_icon('trash', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <button type="button" class="nst-btn nst-btn--ghost nst-btn--icon" id="nst-note-add" aria-label="<?php esc_attr_e('Add another', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Add another', 'nibwp'); ?>"><?php echo nibwp_studio_icon('plus', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
                    <button type="button" class="nst-btn" id="nst-note-send"><?php echo nibwp_studio_icon('send', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Send', 'nibwp'); ?></button>
                </div>
                <button type="button" class="nst-closex" id="nst-note-x" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>" data-tip="<?php esc_attr_e('Close (Esc)', 'nibwp'); ?>"><?php echo nibwp_studio_icon('x', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
            </div>
        </section>
    </main>

    <dialog class="nst-dialog nst-dialog--cmd" id="nst-lightbox" aria-labelledby="nst-lightbox-h">
        <h2 id="nst-lightbox-h" class="screen-reader-text"><?php esc_html_e('Add to your message', 'nibwp'); ?></h2>
        <div class="nst-find">
            <?php echo nibwp_studio_icon('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
            <input type="search" id="nst-lb-find" placeholder="<?php esc_attr_e('Search pages, skills, workflows…', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Search', 'nibwp'); ?>" aria-controls="nst-lb-body" autocomplete="off">
            <form method="dialog"><button class="nst-kbd nst-find__esc" value="close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">Esc</button></form>
        </div>
        <div class="nst-lb" id="nst-lb-body"></div>
        <div class="nst-lb__foot" aria-hidden="true">
            <span><kbd class="nst-kbd">↑</kbd><kbd class="nst-kbd">↓</kbd> <?php esc_html_e('move', 'nibwp'); ?></span>
            <span><kbd class="nst-kbd">↵</kbd> <?php esc_html_e('pick', 'nibwp'); ?></span>
            <span><kbd class="nst-kbd">Esc</kbd> <?php esc_html_e('close', 'nibwp'); ?></span>
        </div>
    </dialog>

    <dialog class="nst-dialog" id="nst-connect" aria-labelledby="nst-connect-h">
        <form method="dialog" class="nst-dialog__head">
            <h2 id="nst-connect-h"><?php esc_html_e('Bring your agent into Agent View', 'nibwp'); ?></h2>
            <button class="nst-ib" value="close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>"><?php echo nibwp_studio_icon('x'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button>
        </form>
        <div class="nst-connect">
            <p class="nst-connect__state" id="nst-connect-state"></p>
            <ol class="nst-connect__steps">
                <li>
                    <strong><?php esc_html_e('Connect your AI client to this site.', 'nibwp'); ?></strong>
                    <span><?php esc_html_e('Claude, Cursor, ChatGPT or any MCP client. Skip this if it is already connected.', 'nibwp'); ?></span>
                    <?php $nibwp_bundle = function_exists('nibwp_visual_bundle_available') && nibwp_visual_bundle_available(); ?>
                    <div class="nst-connect__acts">
                        <a class="nst-btn" href="<?php echo esc_url($boot['connect']); ?>" target="_blank" rel="noopener"><?php echo nibwp_studio_icon('plug', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Open the Connect screen', 'nibwp'); ?></span><?php echo nibwp_studio_icon('external', 13); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></a>
                        <?php if ($nibwp_bundle): ?>
                            <a class="nst-btn nst-btn--ghost" href="<?php echo esc_url(nibwp_visual_bundle_url()); ?>"><?php echo nibwp_studio_icon('download', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Claude Desktop', 'nibwp'); ?></span></a>
                        <?php endif; ?>
                    </div>
                    <?php if ($nibwp_bundle): ?>
                        <small class="nst-connect__note"><?php echo nibwp_studio_icon('check', 12); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('The Claude Desktop file connects in one click and holds no password.', 'nibwp'); ?></small>
                    <?php endif; ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Paste this into your agent once.', 'nibwp'); ?></strong>
                    <span><?php esc_html_e('It starts listening for what you send from here, and keeps listening until you end the session.', 'nibwp'); ?></span>
                    <div class="nst-snippet">
                        <p id="nst-start-prompt"><?php echo esc_html($boot['startPrompt']); ?></p>
                        <button type="button" class="nst-btn" id="nst-copy-start"><?php echo nibwp_studio_icon('copy', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span><?php esc_html_e('Copy', 'nibwp'); ?></span></button>
                    </div>
                </li>
            </ol>
            <p class="nst-connect__foot"><?php esc_html_e('Without an agent you can still browse, open tabs, pin notes and restore from History. What you send waits here until an agent listens, and every message has "Copy as prompt" for any AI chat.', 'nibwp'); ?></p>
        </div>
    </dialog>

    <dialog class="nst-dialog nst-dialog--media" id="nst-media-dlg" aria-labelledby="nst-media-h">
        <div class="nst-media__head">
            <h2 id="nst-media-h"><?php esc_html_e('Media', 'nibwp'); ?></h2>
            <label class="nst-media__search">
                <?php echo nibwp_studio_icon('search', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <span class="screen-reader-text"><?php esc_html_e('Search media', 'nibwp'); ?></span>
                <input type="search" id="nst-media-q" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Search by name or title', 'nibwp'); ?>">
            </label>
            <button type="button" class="nst-btn nst-btn--ghost" id="nst-media-up"><?php echo nibwp_studio_icon('upload', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Upload', 'nibwp'); ?></button>
            <input type="file" id="nst-media-file" multiple hidden>
            <form method="dialog"><button class="nst-ib" value="close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>"><?php echo nibwp_studio_icon('x'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></button></form>
        </div>
        <div class="nst-media">
            <nav class="nst-media__side" id="nst-media-side" aria-label="<?php esc_attr_e('Browse media', 'nibwp'); ?>"></nav>
            <section class="nst-media__main" aria-labelledby="nst-media-h">
                <div class="nst-media__bar" id="nst-media-bar"></div>
                <div class="nst-files" id="nst-media-grid" aria-live="polite"></div>
                <div class="nst-media__more"><button type="button" class="nst-btn nst-btn--ghost" id="nst-media-more" hidden></button></div>
            </section>
        </div>
    </dialog>

    <dialog class="nst-dialog nst-dialog--sm" id="nst-confirm" aria-labelledby="nst-confirm-h" aria-describedby="nst-confirm-p">
        <form method="dialog" class="nst-confirm">
            <span class="nst-confirm__icon" aria-hidden="true"><?php echo nibwp_studio_icon('retry', 18); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span>
            <h2 class="nst-confirm__h" id="nst-confirm-h"></h2>
            <p class="nst-confirm__p" id="nst-confirm-p"></p>
            <div class="nst-confirm__row">
                <button type="submit" value="cancel" class="nst-btn nst-btn--ghost" autofocus><?php esc_html_e('Cancel', 'nibwp'); ?></button>
                <button type="submit" value="ok" class="nst-btn" id="nst-confirm-ok"></button>
            </div>
        </form>
    </dialog>

    <div class="nst-toast" id="nst-toast" role="status" aria-live="polite" popover="manual"></div>
</div>

<script>window.nibwpStudio = <?php echo wp_json_encode($boot); ?>;
window.nibwpVisual = <?php echo wp_json_encode([
    // The browser-command bus (studio-bus.js) and the headless runner read this.
    'ajax' => esc_url_raw(admin_url('admin-ajax.php')),
    'rest' => esc_url_raw(rest_url('nibwp/v1/visual/')),
    'nonce' => $boot['nonce'],
    'home' => $boot['home'],
]); ?>;</script>
<?php
wp_register_script(
    'nibwp-studio',
    NIBWP_PLUGIN_URL . 'assets/js/studio.js',
    ['wp-i18n'],
    function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/js/studio.js') : NIBWP_VERSION,
    true
);
wp_register_script(
    'nibwp-studio-bus',
    NIBWP_PLUGIN_URL . 'assets/js/studio-bus.js',
    ['wp-i18n', 'nibwp-studio'],
    function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/js/studio-bus.js') : NIBWP_VERSION,
    true
);
wp_register_script(
    'nibwp-studio-buddy',
    NIBWP_PLUGIN_URL . 'assets/js/studio-buddy.js',
    ['wp-i18n', 'nibwp-studio'],
    function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/js/studio-buddy.js') : NIBWP_VERSION,
    true
);
wp_register_script(
    'nibwp-studio-builder-sketch',
    NIBWP_PLUGIN_URL . 'assets/js/studio-builder-sketch.js',
    ['wp-i18n', 'nibwp-studio'],
    function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/js/studio-builder-sketch.js') : NIBWP_VERSION,
    true
);
wp_register_script(
    'nibwp-studio-builder',
    NIBWP_PLUGIN_URL . 'assets/js/studio-builder.js',
    ['wp-i18n', 'nibwp-studio', 'nibwp-studio-builder-sketch'],
    function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/js/studio-builder.js') : NIBWP_VERSION,
    true
);
wp_register_script(
    'nibwp-studio-export',
    NIBWP_PLUGIN_URL . 'assets/js/studio-export.js',
    ['wp-i18n', 'nibwp-studio'],
    function_exists('nibwp_visual_asset_version') ? nibwp_visual_asset_version('assets/js/studio-export.js') : NIBWP_VERSION,
    true
);
wp_set_script_translations('nibwp-studio-export', 'nibwp', NIBWP_PLUGIN_DIR . 'languages');
wp_set_script_translations('nibwp-studio-builder-sketch', 'nibwp', NIBWP_PLUGIN_DIR . 'languages');
wp_set_script_translations('nibwp-studio-builder', 'nibwp', NIBWP_PLUGIN_DIR . 'languages');
wp_set_script_translations('nibwp-studio', 'nibwp', NIBWP_PLUGIN_DIR . 'languages');
wp_set_script_translations('nibwp-studio-buddy', 'nibwp', NIBWP_PLUGIN_DIR . 'languages');
wp_set_script_translations('nibwp-studio-bus', 'nibwp', NIBWP_PLUGIN_DIR . 'languages');
wp_scripts()->do_items(['nibwp-studio', 'nibwp-studio-bus', 'nibwp-studio-buddy', 'nibwp-studio-builder-sketch', 'nibwp-studio-builder', 'nibwp-studio-export']);
?>
</body>
</html>
    <?php
    exit;
}

/**
 * Another website, opened in an Agent View tab as a still copy.
 *
 * A page from another site in a frame is out of reach: the browser keeps its
 * document from Agent View, so nothing on it can be pointed at, read or copied,
 * and many sites refuse to be framed at all. So the site fetches the page and
 * serves it from its own address, with every script taken out and a policy
 * that forbids running any, because a copy served from here shares the origin
 * of the admin and of the logged-in person. What is left reads, scrolls and
 * takes notes; menus, forms and logins that need scripts do not work.
 *
 * wp_safe_remote_get() refuses local and private addresses, redirects included,
 * so this cannot be turned on the network the site sits in.
 */
function nibwp_studio_snapshot(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to open Agent View.', 'nibwp'), esc_html__('Agent View', 'nibwp'), ['response' => 403]);
    }

    $url = esc_url_raw(wp_unslash((string) ($_GET['url'] ?? '')), ['http', 'https']);
    $nonce = sanitize_key(wp_unslash((string) ($_GET['_nst'] ?? '')));
    if (!wp_verify_nonce($nonce, 'nibwp_studio_snapshot')) {
        nibwp_studio_snapshot_page($url, __('This link has expired. Reload Agent View and open the page again.', 'nibwp'));
    }
    if ($url === '' || !wp_http_validate_url($url)) {
        nibwp_studio_snapshot_page($url, __('Agent View can only open public web addresses.', 'nibwp'));
    }

    $response = wp_safe_remote_get($url, [
        'timeout' => 15,
        'redirection' => 5,
        'limit_response_size' => 4 * MB_IN_BYTES,
        'user-agent' => 'Mozilla/5.0 (compatible; NibWP Agent View; +' . home_url('/') . ')',
        'headers' => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
    ]);
    if (is_wp_error($response)) {
        nibwp_studio_snapshot_page($url, sprintf(
            /* translators: %s: the error the request gave */
            __('The site did not answer: %s', 'nibwp'),
            $response->get_error_message()
        ));
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    if ($code >= 400) {
        nibwp_studio_snapshot_page($url, sprintf(
            /* translators: %d: HTTP status code */
            __('The site answered with an error (%d).', 'nibwp'),
            $code
        ));
    }
    if ($type !== '' && !str_contains($type, 'html')) {
        nibwp_studio_snapshot_page($url, __('This address is a file, not a web page. Open it in your browser instead.', 'nibwp'));
    }

    // Relative links and images resolve against where the page ended up.
    $final = $url;
    $http = $response['http_response'] ?? null;
    if (is_object($http) && method_exists($http, 'get_response_object')) {
        $raw = $http->get_response_object();
        if (is_object($raw) && !empty($raw->url) && wp_http_validate_url((string) $raw->url)) {
            $final = (string) $raw->url;
        }
    }

    $html = (string) wp_remote_retrieve_body($response);
    $charset = preg_match('/charset=([\w-]+)/', $type, $m) ? $m[1] : '';
    if ($charset === '' && preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
        $charset = $m[1];
    }
    if ($charset !== '' && strtolower($charset) !== 'utf-8' && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        if (is_string($converted)) {
            $html = $converted;
        }
    }

    $clean = nibwp_studio_snapshot_clean($html, $final);
    if ($clean === '') {
        nibwp_studio_snapshot_page($url, __('This page could not be read.', 'nibwp'));
    }

    nibwp_studio_snapshot_headers($final);
    echo $clean; // phpcs:ignore WordPress.Security.EscapeOutput -- scripts, handlers and script URLs are removed above, and the policy header forbids running any
    exit;
}

/**
 * The fetched page without anything that runs: scripts, frames, plugins,
 * event attributes and script URLs go; lazy images get their real source.
 */
function nibwp_studio_snapshot_clean(string $html, string $base): string
{
    if ($html === '' || !class_exists('DOMDocument')) {
        return '';
    }

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$doc->documentElement) {
        return '';
    }

    $xpath = new DOMXPath($doc);
    $drop = '//script|//noscript|//iframe|//frame|//frameset|//object|//embed|//applet|//portal|//base|//meta[@http-equiv]'
        . '|//link[@rel="preload" or @rel="modulepreload" or @rel="prefetch" or @rel="prerender" or @rel="manifest" or @rel="serviceworker" or @rel="import"]';
    foreach (iterator_to_array($xpath->query($drop)) as $node) {
        if (!$node->parentNode) {
            continue;
        }
        // libxml does not know <embed> is empty, so an unclosed one swallows
        // the rest of the page as its children: keep those, drop the embed.
        if (strtolower($node->nodeName) === 'embed') {
            while ($node->firstChild) {
                $node->parentNode->insertBefore($node->firstChild, $node);
            }
        }
        $node->parentNode->removeChild($node);
    }

    $urlish = ['href', 'src', 'action', 'formaction', 'xlink:href', 'data', 'poster', 'srcset', 'background'];
    foreach (iterator_to_array($xpath->query('//@*')) as $attr) {
        $name = strtolower($attr->nodeName);
        $owner = $attr->ownerElement;
        if (!$owner instanceof DOMElement) {
            continue;
        }
        if (str_starts_with($name, 'on') || $name === 'srcdoc') {
            $owner->removeAttribute($attr->nodeName);
            continue;
        }
        if (in_array($name, $urlish, true) && preg_match('/^\s*(javascript|vbscript|data\s*:\s*text\/html)/i', (string) $attr->value)) {
            $owner->removeAttribute($attr->nodeName);
        }
    }

    // Images that wait for a script to swap in their source would stay blank.
    foreach (iterator_to_array($xpath->query('//img|//source')) as $el) {
        if (!$el instanceof DOMElement) {
            continue;
        }
        foreach (['data-src' => 'src', 'data-lazy-src' => 'src', 'data-srcset' => 'srcset', 'data-lazy-srcset' => 'srcset'] as $from => $to) {
            $value = $el->getAttribute($from);
            if ($value !== '' && !preg_match('/^\s*javascript/i', $value)) {
                $el->setAttribute($to, $value);
            }
        }
        $el->removeAttribute('loading');
    }

    $head = $doc->getElementsByTagName('head')->item(0);
    if (!$head) {
        $head = $doc->createElement('head');
        $doc->documentElement->insertBefore($head, $doc->documentElement->firstChild);
    }
    $first = $head->firstChild;
    $tag = $doc->createElement('base');
    $tag->setAttribute('href', $base);
    $head->insertBefore($tag, $first);
    foreach ([['charset' => 'utf-8'], ['name' => 'referrer', 'content' => 'no-referrer']] as $attrs) {
        $meta = $doc->createElement('meta');
        foreach ($attrs as $k => $v) {
            $meta->setAttribute($k, $v);
        }
        $head->insertBefore($meta, $tag);
    }

    return '<!DOCTYPE html>' . $doc->saveHTML($doc->documentElement);
}

/** Headers every snapshot answer carries, the error page included. */
function nibwp_studio_snapshot_headers(string $base): void
{
    $origin = '';
    $parts = wp_parse_url($base);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $origin = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
    }
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: script-src 'none'; object-src 'none'; frame-src 'none'; child-src 'none'; worker-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri " . ($origin !== '' ? $origin : "'none'"));
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
}

/** A plain page saying why the snapshot could not be made, with a way out. */
function nibwp_studio_snapshot_page(string $url, string $message): void
{
    nibwp_studio_snapshot_headers('');
    $title = esc_html__('Agent View could not open this page', 'nibwp');
    ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head><meta charset="utf-8"><title><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></title>
<style>:root{color-scheme:light dark}body{margin:0;min-height:100vh;display:grid;place-items:center;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:Canvas;color:CanvasText}main{max-width:440px;padding:24px;text-align:center}h1{font-size:18px;margin:0 0 8px}p{margin:0 0 16px;opacity:.75}a{color:#e2582a;font-weight:600}</style></head>
<body><main><h1><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></h1><p><?php echo esc_html($message); ?></p>
<?php if ($url !== '') : ?><p><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open it in your browser', 'nibwp'); ?></a></p><?php endif; ?></main></body>
</html>
    <?php
    exit;
}

/*
 * The Agent View 2.0 banner on the NibWP dashboard. Dismissible per user, and
 * gone for good once they have clicked through: it exists to get them to look.
 * ?av_promo=1 shows it for a look, whatever the real state.
 */

add_action('wp_ajax_nibwp_agent_view_dismiss_promo', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error([], 403);
    }
    check_ajax_referer('nibwp_agent_view_promo');
    update_user_meta(get_current_user_id(), 'nibwp_agent_view_promo_dismissed', 1);
    wp_send_json_success();
});

function nibwp_render_agent_view_promo(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $preview = isset($_GET['av_promo']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display toggle only
    if (!$preview && get_user_meta(get_current_user_id(), 'nibwp_agent_view_promo_dismissed', true)) {
        return;
    }
    ?>
    <div class="nw-av-promo" id="nw-av-promo">
        <button type="button" class="nw-av-promo__x" id="nw-av-promo-x" aria-label="<?php esc_attr_e('Dismiss', 'nibwp'); ?>" title="<?php esc_attr_e('Dismiss', 'nibwp'); ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>

        <span class="nw-av-promo__icon" aria-hidden="true"><img src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg'); ?>" alt="" width="30" height="30"></span>

        <div class="nw-av-promo__copy">
            <span class="nw-av-promo__tag"><i aria-hidden="true"></i><?php esc_html_e('New', 'nibwp'); ?> · <?php esc_html_e('Agent View 2.0', 'nibwp'); ?></span>
            <h3><?php esc_html_e('Don\'t just tell AI what to do. See it happen.', 'nibwp'); ?></h3>
            <p><?php esc_html_e('What if you could manage your entire WordPress website without leaving your browser, while watching an AI agent do the work right in front of your eyes?', 'nibwp'); ?> <strong><?php esc_html_e('Try Agent View 2.0', 'nibwp'); ?></strong></p>
            <ul class="nw-av-promo__chips">
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><?php esc_html_e('Nothing to install', 'nibwp'); ?></li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></svg><?php esc_html_e('Your agent connects in one click', 'nibwp'); ?></li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/></svg><?php esc_html_e('Undo anything, anytime', 'nibwp'); ?></li>
            </ul>
        </div>

        <div class="nw-av-promo__cta">
            <a class="nw-av-promo__btn" id="nw-av-promo-go" href="<?php echo esc_url(nibwp_studio_url()); ?>">
                <span><?php esc_html_e('See it in action', 'nibwp'); ?></span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
            </a>
            <span class="nw-av-promo__hint"><?php esc_html_e('Curious? It takes 10 seconds.', 'nibwp'); ?></span>
        </div>
    </div>
    <script>
    (function () {
        var promo = document.getElementById('nw-av-promo');
        if (!promo) { return; }
        var preview = <?php echo $preview ? 'true' : 'false'; ?>;
        var send = function (beacon) {
            if (preview) { return; }
            var body = new URLSearchParams();
            body.set('action', 'nibwp_agent_view_dismiss_promo');
            body.set('_ajax_nonce', <?php echo wp_json_encode(wp_create_nonce('nibwp_agent_view_promo')); ?>);
            var url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            if (beacon && navigator.sendBeacon) {
                navigator.sendBeacon(url, new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' }));
                return;
            }
            fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
        };
        document.getElementById('nw-av-promo-x').addEventListener('click', function () {
            promo.classList.add('is-gone');
            setTimeout(function () { promo.remove(); }, 280);
            send(false);
        });
        // Clicking through is the point: once they have looked, the banner has done its job.
        document.getElementById('nw-av-promo-go').addEventListener('click', function () { send(true); });
    })();
    </script>
    <?php
}
