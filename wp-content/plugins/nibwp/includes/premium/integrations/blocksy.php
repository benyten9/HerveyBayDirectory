<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Blocksy.
 *
 * Blocksy is a THEME, not a plugin, and that changes what this file can honestly
 * promise. Its settings are WordPress theme mods — the Customizer's own storage —
 * so every write here lands in the option `theme_mods_<stylesheet>` and changes
 * EVERY PAGE on the site at once. There is no per-screen blast radius to hide
 * behind, which is why the door capability is `edit_theme_options`: the exact
 * capability WordPress itself gates the Customizer on, for reads as well as
 * writes, because the Customizer is not viewable without it either.
 *
 * Verified against Blocksy Companion 2.1.58 (blocksy-companion.php:6).
 *
 * WHERE EVERYTHING LIVES, and how we know:
 *
 *   - THEME OPTIONS are theme mods. The companion exports them with
 *     get_theme_mods() (framework/features/demo-install/export/options-export.php:55)
 *     and imports them with set_theme_mod() one key at a time
 *     (framework/features/demo-install/options-import.php:107). The raw blob is
 *     the option `theme_mods_<stylesheet>`, read exactly that way by the
 *     companion's own helper (framework/features/customizer-options-manager.php:259).
 *     So writes here go through set_theme_mod() per key — never through a
 *     wholesale update_option() of the blob, which would drop `nav_menu_locations`
 *     and `custom_css_post_id` and silently unassign every menu on the site.
 *
 *   - SAVED COLOUR PALETTES are the option `blocksy_custom_palettes`
 *     (framework/features/customizer-options-manager.php:205, written at
 *     framework/features/demo-install/options-import.php:123). The LIVE palette is
 *     a theme mod the theme owns; it surfaces in CSS as
 *     `var(--theme-palette-color-N)` (framework/extensions/cookies-consent/global.php:73)
 *     and in block markup as the `palette-color-N` preset
 *     (framework/features/blocks/query/block-patterns/posts-layout-2.php:17).
 *
 *   - COMPANION EXTENSIONS are the option `blocksy_active_extensions`, a flat list
 *     of slugs (framework/includes/extensions-manager.php:19). Switching one is
 *     activate_extension()/deactivate_extension()
 *     (framework/includes/extensions-manager.php:185 and :229), and the companion
 *     gates both on `edit_theme_options`
 *     (framework/includes/extensions-manager-api.php:76 and :96) — the same door
 *     this file uses, deliberately.
 *
 *   - PER-POST OVERRIDES are Customizer-shaped values stored as post meta. The
 *     companion names the inner payload field `ct_options`
 *     (framework/helpers/helpers.php:61-63) and reads overrides back through the
 *     THEME's blocksy_get_post_options() (framework/helpers/theme-functions.php:27),
 *     which the companion package does not contain. So the meta key is
 *     DISCOVERED from the post rather than assumed: see
 *     nibwp_blocksy_post_meta_keys(). An explicit `meta_key` overrides the guess.
 *
 *   - CONTENT BLOCKS are the post type `ct_content_block`
 *     (framework/features/header/items/account/options.php:275), driven by
 *     Blocksy\Plugin::instance()->premium->content_blocks
 *     (framework/features/header/items/account/views/login.php:744-755) and gated
 *     behind function_exists('blocksy_companion_get_content_blocks')
 *     (.../options.php:263). In THIS build — Companion 2.1.58 free — that class and
 *     that post type DO NOT EXIST, so every content-block action refuses with
 *     `nibwp_blocksy_content_blocks_unavailable` rather than pretending.
 *
 * IF THE THEME IS NOT ACTIVE, nothing here works, and the guard says so by name.
 * This is not a plugin integration wearing a theme's hat: theme mods are keyed by
 * stylesheet, so writing Blocksy's options while another theme is active writes
 * them into a store nothing reads. The companion can also be active WITHOUT the
 * theme — it says so in its own plugin header — so the two are guarded
 * SEPARATELY and reported separately by get_theme_info.
 *
 * SECRETS. Two different surfaces, two different rules, because the risks differ:
 *
 *   - COMPANION OPTIONS go through a strict ALLOW-LIST
 *     (nibwp_blocksy_public_options()). Everything else is a name and whether it
 *     is set. The credentials are real and they are in there:
 *     `blocksy_ext_mailchimp_credentials` is a third-party API key
 *     (framework/helpers/exts-configs.php, newsletter extension),
 *     `blocksy_ext_adobe_typekit_settings` is a Typekit project token, and
 *     `fs_accounts` is the licensing store holding the licence key and the site
 *     secret (framework/includes/capabilities.php:35). An allow-list rather than a
 *     denylist because `blocksy_ext_*` is an open key space that grows with every
 *     extension, so a denylist would default to leaking the next one added.
 *
 *   - THEME MODS are design values and are returned as a tree, because a header
 *     row or a typography object is nested by nature and flattening it would make
 *     the reads useless. They pass through nibwp_blocksy_scrub(), which replaces
 *     any value whose KEY names a credential with {set: bool} at every nesting
 *     depth, and DROPS a non-scalar leaf rather than casting it — `(string) []` is
 *     the word "Array" and `(string) $object` runs somebody's __toString().
 *
 * A VALUE THAT IS NOT A SCALAR IS DROPPED, never cast, everywhere in this file.
 *
 * WHAT IS NOT HERE, and will not be:
 *   - installing a demo / starter site. The companion's own importer loops
 *     set_theme_mod() over an entire exported blob, replaces the palettes option
 *     and creates content (framework/features/demo-install/options-import.php:104-146).
 *     That is "replace this site with a different one", not a settings change.
 *   - resetting every theme option at once. reset_theme_options takes named keys
 *     and nothing else.
 *   - writing the white-label settings, which decide whose name the owner's
 *     clients see.
 *   - exporting the options blob, which would carry the credentials this file
 *     spends its allow-list withholding.
 * A test parses the enum and fails if an action whose name reads like any of those
 * ever appears, so a later edit has to argue with it.
 */

/** Every action here reads or writes something that renders on every page. */
const NIBWP_BLOCKSY_CAP = 'edit_theme_options';

/** The door for the three per-post override actions — one post, not the site. */
const NIBWP_BLOCKSY_CAP_POST = 'edit_posts';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_BLOCKSY_MAX_ROWS = 200;

/** The default a listing uses when the caller names no limit. */
const NIBWP_BLOCKSY_DEFAULT_ROWS = 50;

/** The post type Blocksy Pro registers for Content Blocks / hooks. */
const NIBWP_BLOCKSY_CB_CPT = 'ct_content_block';

/* ----------------------------------------------------------------------------
 * Detection — the theme and the companion are separate claims
 * ------------------------------------------------------------------------- */

/**
 * Is the Blocksy theme the active (or parent) theme?
 *
 * blocksy_get_theme_mod() and blocksy_manager() are theme functions the companion
 * itself has to proxy because they may not exist yet
 * (framework/helpers/theme-functions.php:16 and :30), so their presence is the
 * strongest in-process signal. The stylesheet/template check catches the moment
 * before the theme's functions.php has run, and catches child themes.
 */
function nibwp_blocksy_available(): bool
{
    if (function_exists('blocksy_get_theme_mod') || function_exists('blocksy_manager')) {
        return true;
    }

    if (!function_exists('wp_get_theme')) {
        return false;
    }

    try {
        $theme = wp_get_theme();
    } catch (\Throwable $error) {
        return false;
    }

    return $theme->get_template() === 'blocksy' || $theme->get_stylesheet() === 'blocksy';
}

/**
 * Is the Blocksy Companion plugin active?
 *
 * BLOCKSY__FILE__ is defined by the plugin's main file before anything else
 * (blocksy-companion.php:116) and Blocksy\Plugin is the class every companion
 * feature hangs off (plugin.php:9), so between them they separate "not installed"
 * from "installed and half-loaded".
 */
function nibwp_blocksy_companion_active(): bool
{
    return defined('BLOCKSY__FILE__') || defined('BLOCKSY_PATH') || class_exists('Blocksy\\Plugin');
}

/**
 * Is the paid companion (Blocksy Pro) loaded?
 *
 * The companion's own test, verbatim: class_exists('Blocksy\Premium')
 * (framework/helpers/helpers.php:167-169).
 */
function nibwp_blocksy_premium(): bool
{
    return class_exists('Blocksy\\Premium');
}

/**
 * The refusal when the theme is not there.
 *
 * Separate from the companion's refusal because the fix is different: one is
 * "switch the theme", the other is "activate the plugin".
 */
function nibwp_blocksy_guard(): ?WP_Error
{
    if (!nibwp_blocksy_available()) {
        return new WP_Error(
            'nibwp_blocksy_missing',
            __('The Blocksy theme is not active on this site. Blocksy keeps its settings in WordPress theme mods, which are keyed by the active stylesheet, so writing them under another theme would write into a store nothing reads.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** The refusal when an action needs the companion plugin and it is not there. */
function nibwp_blocksy_companion_guard(): ?WP_Error
{
    if (!nibwp_blocksy_companion_active()) {
        return new WP_Error(
            'nibwp_blocksy_companion_missing',
            __('The Blocksy Companion plugin is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_blocksy_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_blocksy_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_blocksy_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_blocksy_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/* ----------------------------------------------------------------------------
 * The action list and its capability map
 * ------------------------------------------------------------------------- */

/**
 * Every action, in the order an agent should meet them.
 *
 * @return string[]
 */
function nibwp_blocksy_actions(): array
{
    return [
        'get_theme_info',
        'list_theme_options',
        'get_theme_options',
        'set_theme_options',
        'reset_theme_options',
        'get_color_palette',
        'set_color_palette',
        'list_saved_palettes',
        'save_palette',
        'delete_saved_palette',
        'get_typography',
        'set_typography',
        'get_header_structure',
        'get_footer_structure',
        'list_header_elements',
        'get_post_overrides',
        'set_post_overrides',
        'clear_post_overrides',
        'list_content_blocks',
        'get_content_block',
        'create_content_block',
        'set_content_block_conditions',
        'delete_content_block',
        'list_extensions',
        'set_extension_state',
        'audit_customization',
    ];
}

/**
 * The door capability for one action.
 *
 * A per-post override changes one post, so its door is the site-wide
 * `edit_posts` and the real gate is `edit_post` on the id — checked after the
 * post has been proved to exist, so the gate is never asked about a claim.
 * Everything else moves the whole site and takes `edit_theme_options`.
 */
function nibwp_blocksy_action_cap(string $action): string
{
    $per_post = ['get_post_overrides', 'set_post_overrides', 'clear_post_overrides'];

    return in_array($action, $per_post, true) ? NIBWP_BLOCKSY_CAP_POST : NIBWP_BLOCKSY_CAP;
}

/* ----------------------------------------------------------------------------
 * Theme-mod storage (mirrors kadence.php's vocabulary deliberately)
 * ------------------------------------------------------------------------- */

/** The option name holding this stylesheet's theme mods. */
function nibwp_blocksy_mods_option(): string
{
    return 'theme_mods_' . (string) get_option('stylesheet');
}

/**
 * Core theme_mod plumbing that is not a Blocksy setting.
 *
 * Reported never, written never. `nav_menu_locations` is the menu assignments and
 * `custom_css_post_id` points at the Additional CSS post; both live in the same
 * blob and neither belongs in a design read.
 *
 * @return string[]
 */
function nibwp_blocksy_core_mods(): array
{
    return ['nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets'];
}

/**
 * The raw saved theme mods — customized values only, defaults not merged.
 *
 * Read the same way the companion reads them
 * (framework/features/customizer-options-manager.php:259) so a mismatch with the
 * companion is impossible.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_saved(): array
{
    $mods = get_option(nibwp_blocksy_mods_option(), []);

    if (!is_array($mods)) {
        $mods = [];
    }

    foreach (nibwp_blocksy_core_mods() as $core) {
        unset($mods[$core]);
    }

    return $mods;
}

/**
 * Read one effective theme mod.
 *
 * Prefers the theme's own helper, which merges the theme's defaults, so a key the
 * owner never touched still reports what the site actually renders. Falls back to
 * get_theme_mod() when the theme's functions have not loaded yet.
 *
 * @param mixed $default
 * @return mixed
 */
function nibwp_blocksy_get(string $key, $default = null)
{
    if (function_exists('blocksy_get_theme_mod')) {
        try {
            $value = blocksy_get_theme_mod($key, $default);

            if ($value !== null) {
                return $value;
            }
        } catch (\Throwable $error) {
            // Fall through to core.
        }
    }

    return get_theme_mod($key, $default);
}

/**
 * Persist one theme mod.
 *
 * set_theme_mod() per key, never update_option() on the blob: WordPress merges
 * into the existing mods, so every key this integration does not understand
 * survives the write. That is the whole reason this helper exists.
 *
 * @param mixed $value
 */
function nibwp_blocksy_set(string $key, $value): void
{
    set_theme_mod($key, nibwp_blocksy_sanitize($value));
}

/**
 * Recursively sanitize a settings value while keeping its scalar type.
 *
 * Bools, ints, floats and null survive as themselves because a theme mod of 0 and
 * a theme mod of "0" mean different things to a Customizer control. Strings go
 * through sanitize_text_field, which leaves hex colours, CSS units and font names
 * intact. Anything that is neither scalar nor array — an object, a resource, a
 * closure — is DROPPED by returning null, because there is no honest cast.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_blocksy_sanitize($value)
{
    if (is_array($value)) {
        $out = [];

        foreach ($value as $key => $item) {
            $out[is_string($key) ? sanitize_key($key) : $key] = nibwp_blocksy_sanitize($item);
        }

        return $out;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if (is_string($value)) {
        return sanitize_text_field($value);
    }

    return null;
}

/**
 * Ask Blocksy to regenerate its dynamic CSS after a settings change.
 *
 * Without this a colour or a font changes in the database and the site keeps
 * serving the cached stylesheet. Both action names are the companion's own
 * (framework/features/customizer-options-manager.php:150 and :157), and
 * `customize_save_after` is what the companion fires alongside them (:167).
 */
function nibwp_blocksy_flush_css(): void
{
    try {
        do_action('customize_save_after');
        do_action('blocksy:dynamic-css:refresh-caches');
        do_action('blocksy:cache-manager:purge-all');
    } catch (\Throwable $error) {
        // A listener throwing must not turn a saved setting into a failed write.
    }
}

/* ----------------------------------------------------------------------------
 * Reporting: scrubbing, grouping, bounds
 * ------------------------------------------------------------------------- */

/**
 * Does this key name a credential?
 *
 * Matched against the key, not the value, and applied at every nesting depth, so
 * a token buried three levels inside a header row is caught as surely as a
 * top-level one. Deliberately broad: a false positive costs one design value
 * reported as {set: true}, a false negative leaks somebody's API key.
 */
function nibwp_blocksy_is_secret_key(string $key): bool
{
    return (bool) preg_match(
        '/(api|secret|token|password|passwd|licen[cs]e|credential|private|salt|nonce|_key$|^key$|key_|_hash$|access|bearer|client_id|webhook)/i',
        $key
    );
}

/**
 * Report a value tree with credentials withheld and non-scalars dropped.
 *
 * Returns the scrubbed value. Keys naming a credential become ['set' => bool].
 * A leaf that is not a scalar is replaced with null and counted in $dropped, so
 * the caller can say what went missing instead of silently shipping "Array".
 *
 * @param mixed    $value
 * @param string[] $dropped Collects the paths of values that were dropped.
 * @return mixed
 */
function nibwp_blocksy_scrub($value, array &$dropped, string $path = '', int $depth = 0)
{
    // 12 is deeper than any Customizer value Blocksy stores and stops a
    // self-referential array from recursing until the stack gives out.
    if ($depth > 12) {
        $dropped[] = $path . ' (too deep)';

        return null;
    }

    if (is_array($value)) {
        $out = [];

        foreach ($value as $key => $item) {
            $child = $path === '' ? (string) $key : $path . '.' . (string) $key;

            if (is_string($key) && nibwp_blocksy_is_secret_key($key)) {
                $out[$key] = ['set' => $item !== '' && $item !== null && $item !== []];

                continue;
            }

            $out[$key] = nibwp_blocksy_scrub($item, $dropped, $child, $depth + 1);
        }

        return $out;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        return $value;
    }

    $dropped[] = $path === '' ? '(root)' : $path;

    return null;
}

/**
 * Scrub a value that arrived under a known key.
 *
 * The recursion in nibwp_blocksy_scrub() can only see the keys INSIDE a value, so
 * a secret sitting at the top of one — a meta key called `blocksy_remote_api_token`
 * holding a bare token — would walk straight through it. This wrapper closes that
 * hole, and every read in this file goes through it rather than calling scrub()
 * directly with a key it has not tested.
 *
 * @param mixed    $value
 * @param string[] $dropped
 * @return mixed
 */
function nibwp_blocksy_scrub_named(string $key, $value, array &$dropped)
{
    if (nibwp_blocksy_is_secret_key($key)) {
        return ['set' => $value !== '' && $value !== null && $value !== false && $value !== []];
    }

    return nibwp_blocksy_scrub($value, $dropped, $key);
}

/**
 * Which group a theme-mod key belongs to.
 *
 * Blocksy mixes snake_case and camelCase in the same key space
 * (`cookie_consent_type` beside `trendingBlockHeadingFont`), so every test here is
 * case-insensitive. The groups are for navigation, not for authority: there is no
 * group this integration refuses to read.
 */
function nibwp_blocksy_group(string $key): string
{
    $k = strtolower($key);

    if (str_contains($k, 'header')) {
        return 'header';
    }

    if (str_contains($k, 'footer')) {
        return 'footer';
    }

    if (str_contains($k, 'font') || str_contains($k, 'typography') || str_contains($k, 'letterspacing') || str_contains($k, 'texttransform')) {
        return 'typography';
    }

    if (str_contains($k, 'color') || str_contains($k, 'colour') || str_contains($k, 'palette') || str_contains($k, 'background')) {
        return 'colors';
    }

    foreach (['layout', 'structure', 'container', 'sidebar', 'width', 'spacing', 'padding', 'margin', 'content_area'] as $needle) {
        if (str_contains($k, $needle)) {
            return 'layout';
        }
    }

    return 'other';
}

/** The groups list_theme_options and get_theme_options accept. */
function nibwp_blocksy_groups(): array
{
    return ['header', 'footer', 'typography', 'colors', 'layout', 'other'];
}

/**
 * How many rows to return, clamped from input with the default included.
 *
 * The default goes through the same clamp as a caller's number so a later edit to
 * NIBWP_BLOCKSY_DEFAULT_ROWS can never exceed the maximum.
 *
 * @param array<string, mixed> $input
 */
function nibwp_blocksy_limit(array $input): int
{
    $limit = isset($input['limit']) ? (int) $input['limit'] : NIBWP_BLOCKSY_DEFAULT_ROWS;

    if ($limit < 1) {
        $limit = NIBWP_BLOCKSY_DEFAULT_ROWS;
    }

    return min($limit, NIBWP_BLOCKSY_MAX_ROWS);
}

/** Where a listing starts. */
function nibwp_blocksy_offset(array $input): int
{
    $offset = isset($input['offset']) ? (int) $input['offset'] : 0;

    return $offset > 0 ? $offset : 0;
}

/* ----------------------------------------------------------------------------
 * Companion options — the allow-list
 * ------------------------------------------------------------------------- */

/**
 * Companion option keys whose VALUE may be reported, and how to shape it.
 *
 * Short on purpose. Everything else in the `blocksy_*` key space is reported as a
 * name and whether it is set, because that key space grows with every extension
 * and a denylist would default to leaking whatever ships next.
 *
 * @return array<string, string>
 */
function nibwp_blocksy_public_options(): array
{
    return [
        'blocksy_active_extensions'   => 'list',
        'blocksy_custom_palettes'     => 'tree',
        'blocksy_ext_demo_install_enabled' => 'bool',
        'blocksy_ext_trending'        => 'tree',
        'blocksy_ext_newsletter_subscribe' => 'tree',
    ];
}

/**
 * Option keys whose value must never travel, however it is asked for.
 *
 * A second layer rather than the only one — the allow-list above already governs
 * what leaves. A name landing in both lists is a mistake worth failing loudly on,
 * which the check asserts.
 *
 * @return string[]
 */
function nibwp_blocksy_secret_options(): array
{
    return [
        // The newsletter extension's third-party API credentials.
        'blocksy_ext_mailchimp_credentials',
        // An Adobe Typekit project token.
        'blocksy_ext_adobe_typekit_settings',
        // The licensing store: licence key, site secret, account email
        // (framework/includes/capabilities.php:35).
        'fs_accounts',
        'blocksy_companion_fs_accounts',
    ];
}

/**
 * Every companion option this integration knows the name of.
 *
 * Harvested from the companion source so the report is the plugin's own key space
 * and not a guess: framework/includes/extensions-manager.php:19,
 * framework/features/customizer-options-manager.php:205 and
 * framework/features/demo-install/export/options-export.php:11-24.
 *
 * @return string[]
 */
function nibwp_blocksy_known_options(): array
{
    return [
        'blocksy_active_extensions',
        'blocksy_custom_palettes',
        'blocksy_ext_adobe_typekit_settings',
        'blocksy_ext_custom_fonts_settings',
        'blocksy_ext_demo_install_enabled',
        'blocksy_ext_demos_current_demo',
        'blocksy_ext_local_google_fonts_settings',
        'blocksy_ext_mailchimp_credentials',
        'blocksy_ext_newsletter_subscribe',
        'blocksy_ext_post_types_extra_settings',
        'blocksy_ext_product_reviews_settings',
        'blocksy_ext_trending',
        'blocksy_ext_white_label_settings',
        'blocksy_ext_woocommerce_extra_settings',
        // Reported so an owner can see the site IS licensed. Never as a value:
        // this is the store holding the licence key and the site secret.
        'fs_accounts',
    ];
}

/**
 * Report one companion option the way this integration reports options.
 *
 * A non-scalar in a 'bool'/'string' slot is DROPPED, not cast. `(string) []` is
 * the literal word "Array" and would be reported as if it were the setting.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_option_row(string $key): array
{
    $allowed = nibwp_blocksy_public_options();
    $raw = get_option($key, null);
    $is_set = $raw !== null && $raw !== false && $raw !== '' && $raw !== [];

    if (in_array($key, nibwp_blocksy_secret_options(), true) || !isset($allowed[$key])) {
        return ['key' => $key, 'set' => $is_set, 'withheld' => true];
    }

    $row = ['key' => $key, 'set' => $is_set, 'withheld' => false];
    $dropped = [];

    switch ($allowed[$key]) {
        case 'bool':
            // A non-scalar here is DROPPED and named, never cast: (bool) on an
            // array holding a token is true, and reporting that as the setting
            // hides the fact that the stored value is not a switch at all.
            $row['value'] = is_scalar($raw) ? (bool) $raw : null;

            if (!is_scalar($raw) && $raw !== null && $raw !== false) {
                $dropped[] = $key;
            }
            break;

        case 'list':
            $row['value'] = [];

            foreach ((array) (is_array($raw) ? $raw : []) as $index => $item) {
                if (is_scalar($item)) {
                    $row['value'][] = (string) $item;

                    continue;
                }

                if ($item !== null) {
                    $dropped[] = $key . '.' . (string) $index;
                }
            }
            break;

        default:
            $row['value'] = nibwp_blocksy_scrub_named($key, $raw, $dropped);
            break;
    }

    if ($dropped !== []) {
        $row['dropped'] = $dropped;
    }

    return $row;
}

/* ----------------------------------------------------------------------------
 * Per-post overrides
 * ------------------------------------------------------------------------- */

/**
 * The candidate meta keys a Blocksy per-post override is stored under.
 *
 * The companion names the payload FIELD `ct_options`
 * (framework/helpers/helpers.php:61-63) and the extension that ships its own
 * per-post options stores them under a `blocksy_*_options` meta key with that
 * field inside it (framework/extensions/product-reviews/extension.php:709 and
 * :754). The theme owns the main one and the theme is not in the companion
 * package, so this is a candidate list checked against the post, not an
 * assumption — and `meta_key` on the action overrides it outright.
 *
 * @return string[]
 */
function nibwp_blocksy_post_meta_keys(): array
{
    return [
        'blocksy_post_meta_options',
        'blocksy_product_review_options',
        'ct_options',
    ];
}

/**
 * Which meta key actually holds this post's overrides, or '' if none does.
 *
 * Checks the candidates in order and then, failing that, looks for any meta key
 * on the post that is shaped like a Blocksy options key. Discovery beats a
 * hardcoded guess here because the theme can rename it between major versions
 * and a wrong key would report "no overrides" on a post that has them.
 */
function nibwp_blocksy_post_override_key(int $post_id): string
{
    foreach (nibwp_blocksy_post_meta_keys() as $candidate) {
        $value = get_post_meta($post_id, $candidate, true);

        if ($value !== '' && $value !== false && $value !== null && $value !== []) {
            return $candidate;
        }
    }

    foreach (array_keys((array) get_post_meta($post_id)) as $key) {
        $key = (string) $key;

        if (preg_match('/^(blocksy|ct)_.*(options|settings)$/i', $key) === 1) {
            return $key;
        }
    }

    return '';
}

/**
 * The meta key a write should use: the caller's, the discovered one, or the
 * theme's default. sanitize_key() because this value becomes a meta key, and an
 * unsanitized one from input is a write into whatever the caller names.
 *
 * @param array<string, mixed> $input
 */
function nibwp_blocksy_post_write_key(int $post_id, array $input): string
{
    if (isset($input['meta_key']) && is_string($input['meta_key']) && $input['meta_key'] !== '') {
        return sanitize_key($input['meta_key']);
    }

    $found = nibwp_blocksy_post_override_key($post_id);

    return $found !== '' ? $found : 'blocksy_post_meta_options';
}

/**
 * Resolve a post id from a caller into a real post, or a refusal.
 *
 * The id is a CLAIM. Existence and type are proved first, and only then is
 * `edit_post` consulted — so the per-object capability is never asked about an id
 * that does not exist, and the answer never tells an unauthorised caller whether
 * it does. The door capability has already been checked by the dispatcher before
 * this function runs.
 *
 * @param string[] $deny_types Post types that are never a valid target.
 * @return WP_Post|WP_Error
 */
function nibwp_blocksy_resolve_post(int $post_id, string $what, array $deny_types = ['revision'])
{
    if ($post_id < 1) {
        return nibwp_blocksy_err('bad_request', __('A post id is required and must be a positive integer.', 'nibwp'));
    }

    $post = get_post($post_id);

    if (!$post instanceof WP_Post) {
        return nibwp_blocksy_err('not_found', __('No post exists with that id.', 'nibwp'), 404);
    }

    if (in_array($post->post_type, $deny_types, true)) {
        return nibwp_blocksy_err(
            'bad_post_type',
            sprintf(
                /* translators: %s: post type name */
                __('That id is a %s, which cannot carry Blocksy per-post overrides.', 'nibwp'),
                $post->post_type
            ),
            404
        );
    }

    if (!current_user_can('edit_post', $post_id)) {
        return nibwp_blocksy_forbidden($what, 'edit_post');
    }

    return $post;
}

/* ----------------------------------------------------------------------------
 * Content Blocks (Blocksy Pro)
 * ------------------------------------------------------------------------- */

/**
 * Is the Content Blocks post type registered on this build?
 *
 * It is a Pro feature: the free companion only links to its admin screen
 * (framework/features/header/items/account/options.php:275) and guards every use
 * behind function_exists('blocksy_companion_get_content_blocks') (:263).
 */
function nibwp_blocksy_content_blocks_available(): bool
{
    return post_type_exists(NIBWP_BLOCKSY_CB_CPT);
}

/** The refusal when this build has no Content Blocks. */
function nibwp_blocksy_content_blocks_guard(): ?WP_Error
{
    if (nibwp_blocksy_content_blocks_available()) {
        return null;
    }

    return nibwp_blocksy_err(
        'content_blocks_unavailable',
        __('Content Blocks are not available on this site. They are a Blocksy Pro feature and register the ct_content_block post type; the free Companion only links to their admin screen.', 'nibwp'),
        404
    );
}

/**
 * Meta keys that look like a Content Block's display conditions.
 *
 * Blocksy Pro owns the exact name and Pro is not in the package, so a content
 * block's conditions are DISCOVERED from its own meta and the result reports
 * which key it found. `conditions_meta_key` on the action overrides it.
 *
 * @return string[]
 */
function nibwp_blocksy_cb_condition_keys(): array
{
    return [
        'blocksy_content_block_conditions',
        'blocksy_hook_conditions',
        'ct_conditions',
    ];
}

/**
 * Every meta key on a content block that belongs to Blocksy, with its value.
 *
 * Self-describing on purpose: an agent that reads one existing block learns the
 * exact key names this build uses, which is the only reliable way to write one.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_cb_meta(int $post_id, array &$dropped): array
{
    $out = [];

    foreach ((array) get_post_meta($post_id) as $key => $values) {
        $key = (string) $key;

        if (preg_match('/^_?(blocksy|ct)_/i', $key) !== 1) {
            continue;
        }

        $raw = is_array($values) ? ($values[0] ?? '') : $values;
        $out[$key] = nibwp_blocksy_scrub_named($key, maybe_unserialize($raw), $dropped);
    }

    return $out;
}

/** Which key holds this block's conditions, or '' if none is set. */
function nibwp_blocksy_cb_condition_key(int $post_id): string
{
    foreach (nibwp_blocksy_cb_condition_keys() as $candidate) {
        $value = get_post_meta($post_id, $candidate, true);

        if ($value !== '' && $value !== false && $value !== null && $value !== []) {
            return $candidate;
        }
    }

    return '';
}

/**
 * Resolve a content-block id, type-checked before any per-object capability.
 *
 * @return WP_Post|WP_Error
 */
function nibwp_blocksy_resolve_content_block(int $post_id, string $what)
{
    if ($post_id < 1) {
        return nibwp_blocksy_err('bad_request', __('A content block id is required and must be a positive integer.', 'nibwp'));
    }

    $post = get_post($post_id);

    if (!$post instanceof WP_Post) {
        return nibwp_blocksy_err('not_found', __('No post exists with that id.', 'nibwp'), 404);
    }

    if ($post->post_type !== NIBWP_BLOCKSY_CB_CPT) {
        return nibwp_blocksy_err(
            'not_a_content_block',
            sprintf(
                /* translators: 1: post type name, 2: the expected post type */
                __('That id is a %1$s, not a %2$s. Deleting or rewriting it would destroy unrelated content.', 'nibwp'),
                $post->post_type,
                NIBWP_BLOCKSY_CB_CPT
            ),
            404
        );
    }

    if (!current_user_can('edit_post', $post_id)) {
        return nibwp_blocksy_forbidden($what, 'edit_post');
    }

    return $post;
}

/* ----------------------------------------------------------------------------
 * Companion extensions
 * ------------------------------------------------------------------------- */

/**
 * The extensions this Companion version ships, and where each one's settings are.
 *
 * The four that ship in the free package are directories under
 * framework/extensions/; the rest are declared in
 * framework/helpers/exts-configs.php:10-57 and only register when Pro is loaded.
 * The settings option is what the "switched on with no configuration" audit looks
 * at — an empty string means the extension keeps its settings in theme mods
 * rather than in its own option, so an empty settings option is not a finding.
 *
 * @return array<string, array{pro: bool, option: string}>
 */
function nibwp_blocksy_extension_catalog(): array
{
    return [
        'cookies-consent'      => ['pro' => false, 'option' => ''],
        'newsletter-subscribe' => ['pro' => false, 'option' => 'blocksy_ext_mailchimp_credentials'],
        'product-reviews'      => ['pro' => false, 'option' => 'blocksy_ext_product_reviews_settings'],
        'trending'             => ['pro' => false, 'option' => 'blocksy_ext_trending'],
        'adobe-typekit'        => ['pro' => true, 'option' => 'blocksy_ext_adobe_typekit_settings'],
        'code-snippets'        => ['pro' => true, 'option' => ''],
        'color-mode-switch'    => ['pro' => true, 'option' => ''],
        'custom-fonts'         => ['pro' => true, 'option' => 'blocksy_ext_custom_fonts_settings'],
        'local-google-fonts'   => ['pro' => true, 'option' => 'blocksy_ext_local_google_fonts_settings'],
        'mega-menu'            => ['pro' => true, 'option' => ''],
        'post-types-extra'     => ['pro' => true, 'option' => 'blocksy_ext_post_types_extra_settings'],
        'shortcuts'            => ['pro' => true, 'option' => ''],
        'sidebars'             => ['pro' => true, 'option' => ''],
        'white-label'          => ['pro' => true, 'option' => 'blocksy_ext_white_label_settings'],
        'woocommerce-extra'    => ['pro' => true, 'option' => 'blocksy_ext_woocommerce_extra_settings'],
    ];
}

/**
 * The slugs currently switched on.
 *
 * The option is a flat list of lowercase slugs
 * (framework/includes/extensions-manager.php:19 and :213).
 *
 * @return string[]
 */
function nibwp_blocksy_active_extensions(): array
{
    $raw = get_option('blocksy_active_extensions', []);
    $out = [];

    foreach ((array) (is_array($raw) ? $raw : []) as $slug) {
        if (is_scalar($slug)) {
            $out[] = strtolower((string) $slug);
        }
    }

    return array_values(array_unique($out));
}

/* ----------------------------------------------------------------------------
 * The hub
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/blocksy-manage', [
    'label' => __('Blocksy – Theme Options, Palette, Typography and Content Blocks', 'nibwp'),
    'description' => 'Read and manage a Blocksy site: theme options (colours, typography, layout, header and footer structure), the global colour palette and saved palettes, per-post Customizer overrides, Blocksy Pro Content Blocks with their display conditions, and which Companion extensions are switched on. '
        . 'Blocksy is a THEME and its settings are WordPress theme mods, so every write outside the per-post actions changes EVERY PAGE on the site and takes the edit_theme_options capability WordPress gates the Customizer on. '
        . 'Writes go through set_theme_mod() one key at a time, so settings this integration does not understand survive untouched. '
        . 'Licence keys and third-party credentials never leave: Companion options report a name and whether they are set unless they are on a short allow-list. '
        . 'audit_customization reports stale per-post overrides, Content Blocks with no display condition, extensions switched on with nothing configured, and palette colours nothing references.',
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_blocksy_actions(),
                'description' => 'Which operation to run. Start with get_theme_info, then audit_customization.',
            ],
            'keys' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'For get_theme_options / reset_theme_options: the theme-mod keys to act on.',
            ],
            'group' => [
                'type' => 'string',
                'description' => 'For list_theme_options / get_theme_options: one of header, footer, typography, colors, layout, other.',
            ],
            'options' => [
                'type' => 'object',
                'description' => 'For set_theme_options / set_typography: a map of theme-mod key to value. Merged one key at a time; every other setting survives.',
            ],
            'palette' => [
                'type' => 'object',
                'description' => 'For set_color_palette / save_palette: the palette value, normally color1..color8 each holding {color: "#hex"}.',
            ],
            'palette_key' => [
                'type' => 'string',
                'description' => 'For get_color_palette / set_color_palette: the theme-mod key holding the live palette. Defaults to the one get_color_palette reports as in use.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'For save_palette / delete_saved_palette: the saved palette name.',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'For the per-post override actions: the post whose Blocksy overrides to read or write.',
            ],
            'meta_key' => [
                'type' => 'string',
                'description' => 'For the per-post override actions: force the override meta key instead of letting get_post_overrides discover it.',
            ],
            'overrides' => [
                'type' => 'object',
                'description' => 'For set_post_overrides: a map of override key to value, merged into whatever the post already has.',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'For the Content Block actions: the ct_content_block post id.',
            ],
            'title' => ['type' => 'string', 'description' => 'For create_content_block: the block title.'],
            'content' => ['type' => 'string', 'description' => 'For create_content_block: the block body (block markup, HTML or shortcodes).'],
            'hook' => ['type' => 'string', 'description' => 'For create_content_block: the template position. Read an existing block first — this build names its own positions and they are not invented here.'],
            'status' => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'For create_content_block. Default draft, so a new block does not go live unseen.'],
            'conditions' => [
                'type' => 'array',
                'description' => 'For set_content_block_conditions: the include rules, in the shape get_content_block reports.',
            ],
            'conditions_meta_key' => [
                'type' => 'string',
                'description' => 'For set_content_block_conditions: force the conditions meta key instead of the discovered one.',
            ],
            'extension' => ['type' => 'string', 'description' => 'For set_extension_state: the extension slug, as list_extensions reports it.'],
            'enabled' => ['type' => 'boolean', 'description' => 'For set_extension_state: true to switch on, false to switch off.'],
            'confirm' => ['type' => 'string', 'description' => 'For delete_content_block: must be the exact block title.'],
            'stale_days' => ['type' => 'integer', 'description' => 'For audit_customization: how long since a post was edited before its overrides count as stale. Default 365.'],
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
    'execute_callback' => 'nibwp_blocksy_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Blocksy is a THEME, not a plugin. Everything here needs the theme active, and',
                'the refusal says so by name: theme mods are keyed by the active stylesheet, so',
                'writing Blocksy settings under another theme writes into a store nothing reads.',
                'The Blocksy Companion plugin is separate and is reported separately by',
                'get_theme_info — it can be active without the theme.',
                '',
                'THEME OPTIONS CHANGE EVERY PAGE ON THE SITE. set_theme_options,',
                'set_color_palette, set_typography and set_extension_state are not scoped to a',
                'screen; they are the Customizer. Each takes edit_theme_options, which is what',
                'WordPress itself gates the Customizer on. Say what you are about to change and',
                'what it affects BEFORE you change it, and name the keys.',
                '',
                'READ BEFORE YOU WRITE. Blocksy does not publish a fixed list of theme-mod keys',
                'and this integration does not invent one: list_theme_options reports the keys',
                'this site actually has, grouped into header, footer, typography, colors, layout',
                'and other. Write the keys you read. A key you guessed is a key that does',
                'nothing, and it still counts as a change to the site.',
                '',
                'A WRITE PRESERVES EVERYTHING ELSE. set_theme_options merges one key at a time',
                'through set_theme_mod(), so settings this integration has never heard of — and',
                'the menu assignments, which live in the same blob — survive. Never ask for an',
                'action that replaces the whole options blob; there is not one.',
                '',
                'PER-POST OVERRIDES are a different blast radius: one post, not the site. Their',
                'door is edit_posts and the real gate is edit_post on that id, checked after the',
                'post has been proved to exist. The meta key is DISCOVERED from the post because',
                'the theme owns it; get_post_overrides tells you which key it found, and',
                'set_post_overrides writes to that same key unless you name another.',
                '',
                'CONTENT BLOCKS ARE A BLOCKSY PRO FEATURE. On a free Companion the',
                'ct_content_block post type is not registered and all five content-block',
                'actions refuse with nibwp_blocksy_content_blocks_unavailable. That is the',
                'correct answer, not a bug. Where they do work, run get_content_block on an',
                'existing block FIRST: this build names its own template positions and its own',
                'conditions meta key, and neither is guessed here.',
                '',
                'create_content_block defaults to draft on purpose — a block with no conditions',
                'either shows nowhere or shows everywhere, and publishing one unseen is how a',
                'site ends up with a banner on every page. Set its conditions, then publish.',
                '',
                'delete_content_block requires the block\'s exact title in `confirm`, and it',
                'trashes rather than destroys.',
                '',
                'SECRETS: Companion options report a name and whether they are set unless they',
                'are on a short allow-list. The newsletter extension\'s API credentials, the',
                'Adobe Typekit token and the licence store never come back as values, at any',
                'capability, and asking differently will not change that. A value that is not a',
                'scalar is DROPPED and named in `dropped`, never cast — "Array" is a lie.',
                '',
                'START WITH audit_customization on a site you did not build. It reports four',
                'things that are invisible in wp-admin: per-post overrides still sitting on',
                'posts nobody has edited in a year, Content Blocks with no display condition,',
                'extensions switched on with nothing configured, and palette colours nothing',
                'references.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * The one entry point: guard, enum, capability, then dispatch inside one catch.
 *
 * The ORDER matters and is asserted by the check. The capability comes before any
 * input is validated, because a refusal that validated first would tell an
 * unauthorised caller which post ids and which option keys exist.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_manage(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_blocksy_actions(), true)) {
        return nibwp_blocksy_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Blocksy action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_blocksy_actions())
            )
        );
    }

    $cap = nibwp_blocksy_action_cap($action);

    if (!current_user_can($cap)) {
        return nibwp_blocksy_forbidden($action, $cap);
    }

    // The theme's own helpers reach the options table and the Customizer, and the
    // companion's extension manager requires files off disk. On a site mid-update
    // any of that raises, and an uncaught Error in an ability takes the whole REST
    // request with it.
    try {
        return nibwp_blocksy_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_blocksy_err(
            'theme_error',
            sprintf(
                /* translators: 1: action name, 2: error message from Blocksy */
                __('Blocksy could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_blocksy_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'get_theme_info' => nibwp_blocksy_get_theme_info(),
        'list_theme_options' => nibwp_blocksy_list_theme_options($input),
        'get_theme_options' => nibwp_blocksy_get_theme_options($input),
        'set_theme_options' => nibwp_blocksy_set_theme_options($input),
        'reset_theme_options' => nibwp_blocksy_reset_theme_options($input),
        'get_color_palette' => nibwp_blocksy_get_color_palette($input),
        'set_color_palette' => nibwp_blocksy_set_color_palette($input),
        'list_saved_palettes' => nibwp_blocksy_list_saved_palettes($input),
        'save_palette' => nibwp_blocksy_save_palette($input),
        'delete_saved_palette' => nibwp_blocksy_delete_saved_palette($input),
        'get_typography' => nibwp_blocksy_get_typography($input),
        'set_typography' => nibwp_blocksy_set_typography($input),
        'get_header_structure' => nibwp_blocksy_get_placements('header'),
        'get_footer_structure' => nibwp_blocksy_get_placements('footer'),
        'list_header_elements' => nibwp_blocksy_list_header_elements($input),
        'get_post_overrides' => nibwp_blocksy_get_post_overrides($input),
        'set_post_overrides' => nibwp_blocksy_set_post_overrides($input),
        'clear_post_overrides' => nibwp_blocksy_clear_post_overrides($input),
        'list_content_blocks' => nibwp_blocksy_list_content_blocks($input),
        'get_content_block' => nibwp_blocksy_get_content_block($input),
        'create_content_block' => nibwp_blocksy_create_content_block($input),
        'set_content_block_conditions' => nibwp_blocksy_set_content_block_conditions($input),
        'delete_content_block' => nibwp_blocksy_delete_content_block($input),
        'list_extensions' => nibwp_blocksy_list_extensions($input),
        'set_extension_state' => nibwp_blocksy_set_extension_state($input),
        'audit_customization' => nibwp_blocksy_audit_customization($input),
        default => nibwp_blocksy_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------------- theme options */

/**
 * Discovery: what is actually installed and where its settings live.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_get_theme_info(): array
{
    $theme = wp_get_theme();
    $mods = nibwp_blocksy_saved();
    $groups = [];

    foreach (array_keys($mods) as $key) {
        $group = nibwp_blocksy_group((string) $key);
        $groups[$group] = (int) ($groups[$group] ?? 0) + 1;
    }

    $catalog = nibwp_blocksy_extension_catalog();
    $active = nibwp_blocksy_active_extensions();

    // Every Companion option this integration knows the name of, through the
    // allow-list: a value only for the handful that are safe, a name and whether
    // it is set for the rest. This is the surface the credentials live on.
    $options = [];

    foreach (nibwp_blocksy_known_options() as $option) {
        $options[] = nibwp_blocksy_option_row($option);
    }

    return [
        'theme_active' => true,
        'stylesheet' => $theme->get_stylesheet(),
        'template' => $theme->get_template(),
        'is_child_theme' => $theme->get_stylesheet() !== $theme->get_template(),
        'theme_version' => (string) $theme->get('Version'),
        'companion_active' => nibwp_blocksy_companion_active(),
        'companion_version' => nibwp_blocksy_companion_version(),
        'premium_active' => nibwp_blocksy_premium(),
        // A plan NAME, never the licence key behind it.
        'licence_plan' => nibwp_blocksy_plan(),
        'options_store' => nibwp_blocksy_mods_option(),
        'options_store_kind' => 'theme_mod',
        'saved_option_count' => count($mods),
        'groups' => $groups,
        'live_palette_key' => nibwp_blocksy_palette_key(),
        'saved_palettes_option' => 'blocksy_custom_palettes',
        'post_override_meta_candidates' => nibwp_blocksy_post_meta_keys(),
        'content_blocks_post_type' => NIBWP_BLOCKSY_CB_CPT,
        'content_blocks_available' => nibwp_blocksy_content_blocks_available(),
        'extensions_option' => 'blocksy_active_extensions',
        'extensions_known' => count($catalog),
        'extensions_active' => count($active),
        'companion_options' => $options,
        'capability' => NIBWP_BLOCKSY_CAP,
        'capability_per_post' => NIBWP_BLOCKSY_CAP_POST,
    ];
}

/**
 * The Companion's version, from its own plugin header.
 *
 * There is no version CONSTANT to read — the companion defines BLOCKSY__FILE__,
 * BLOCKSY_PATH and BLOCKSY_URL and nothing else (blocksy-companion.php:116-119) —
 * so the header is the only honest source.
 */
function nibwp_blocksy_companion_version(): string
{
    if (!defined('BLOCKSY__FILE__') || !function_exists('get_file_data')) {
        return '';
    }

    try {
        $data = get_file_data((string) constant('BLOCKSY__FILE__'), ['Version' => 'Version'], 'plugin');
    } catch (\Throwable $error) {
        return '';
    }

    return is_array($data) && isset($data['Version']) && is_scalar($data['Version']) ? (string) $data['Version'] : '';
}

/**
 * The licence PLAN name, from the companion's own capability object.
 *
 * Never the licence key: get_plan() returns one of free/personal/professional/
 * agency (framework/includes/capabilities.php:143-205), which is what an owner
 * asking "what can this site do" actually wants.
 */
function nibwp_blocksy_plan(): string
{
    if (!function_exists('blocksy_companion_get_capabilities')) {
        return '';
    }

    try {
        $caps = blocksy_companion_get_capabilities();

        if (!is_object($caps) || !method_exists($caps, 'get_plan')) {
            return '';
        }

        $plan = $caps->get_plan();
    } catch (\Throwable $error) {
        return '';
    }

    return is_scalar($plan) ? (string) $plan : '';
}

/**
 * Bounded listing of the theme-mod keys this site actually has.
 *
 * Keys and shapes, not values — this is the map an agent reads before deciding
 * what to write. get_theme_options returns the values.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_list_theme_options(array $input): array|WP_Error
{
    $group = isset($input['group']) ? (string) $input['group'] : '';

    if ($group !== '' && !in_array($group, nibwp_blocksy_groups(), true)) {
        return nibwp_blocksy_err(
            'bad_request',
            sprintf(
                /* translators: 1: the group name given, 2: list of valid groups */
                __('Unknown option group "%1$s". Valid groups: %2$s.', 'nibwp'),
                $group,
                implode(', ', nibwp_blocksy_groups())
            )
        );
    }

    $mods = nibwp_blocksy_saved();
    ksort($mods);

    $rows = [];

    foreach ($mods as $key => $value) {
        $key = (string) $key;
        $key_group = nibwp_blocksy_group($key);

        if ($group !== '' && $key_group !== $group) {
            continue;
        }

        $rows[] = [
            'key' => $key,
            'group' => $key_group,
            'shape' => is_array($value) ? 'object' : (is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : gettype($value))),
            'withheld' => nibwp_blocksy_is_secret_key($key),
        ];
    }

    $total = count($rows);
    $limit = nibwp_blocksy_limit($input);
    $offset = nibwp_blocksy_offset($input);

    return [
        'group' => $group !== '' ? $group : 'all',
        'groups' => nibwp_blocksy_groups(),
        'options' => array_slice($rows, $offset, $limit),
        'returned' => count(array_slice($rows, $offset, $limit)),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'store' => nibwp_blocksy_mods_option(),
    ];
}

/**
 * Read theme-mod values, by key or by group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_get_theme_options(array $input): array|WP_Error
{
    $keys = [];

    foreach ((array) ($input['keys'] ?? []) as $key) {
        if (is_scalar($key) && (string) $key !== '') {
            $keys[] = (string) $key;
        }
    }

    $group = isset($input['group']) ? (string) $input['group'] : '';

    if ($group !== '' && !in_array($group, nibwp_blocksy_groups(), true)) {
        return nibwp_blocksy_err(
            'bad_request',
            sprintf(
                /* translators: 1: the group name given, 2: list of valid groups */
                __('Unknown option group "%1$s". Valid groups: %2$s.', 'nibwp'),
                $group,
                implode(', ', nibwp_blocksy_groups())
            )
        );
    }

    if ($keys === [] && $group === '') {
        return nibwp_blocksy_err('bad_request', __('Name some keys, or a group. Reading every theme mod at once is how a response becomes unreadable.', 'nibwp'));
    }

    $mods = nibwp_blocksy_saved();
    $dropped = [];
    $values = [];
    $withheld = [];

    if ($keys === []) {
        foreach (array_keys($mods) as $key) {
            if (nibwp_blocksy_group((string) $key) === $group) {
                $keys[] = (string) $key;
            }
        }

        sort($keys);
        $keys = array_slice($keys, 0, nibwp_blocksy_limit($input));
    }

    foreach ($keys as $key) {
        if (nibwp_blocksy_is_secret_key($key)) {
            $withheld[] = $key;
            $values[$key] = ['set' => array_key_exists($key, $mods) && $mods[$key] !== '' && $mods[$key] !== null];

            continue;
        }

        $values[$key] = nibwp_blocksy_scrub_named($key, nibwp_blocksy_get($key, null), $dropped);
    }

    return [
        'options' => $values,
        'returned' => count($values),
        'withheld' => $withheld,
        'dropped' => $dropped,
        'store' => nibwp_blocksy_mods_option(),
    ];
}

/**
 * Write theme mods.
 *
 * AFFECTS EVERY PAGE ON THE SITE. Each key goes through set_theme_mod()
 * separately, so every other setting in the blob — including the ones this
 * integration has never heard of, and the menu assignments — is untouched.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_set_theme_options(array $input): array|WP_Error
{
    $patch = (array) ($input['options'] ?? []);

    if ($patch === []) {
        return nibwp_blocksy_err('bad_request', __('Provide a non-empty "options" object of theme-mod key to value.', 'nibwp'));
    }

    $before = nibwp_blocksy_saved();
    $changed = [];
    $refused = [];

    foreach ($patch as $key => $value) {
        $key = (string) $key;

        if ($key === '' || in_array($key, nibwp_blocksy_core_mods(), true)) {
            // Writing nav_menu_locations through a theme-options action would
            // reassign menus as a side effect of a colour change.
            $refused[] = $key;

            continue;
        }

        nibwp_blocksy_set($key, $value);
        $changed[] = $key;
    }

    if ($changed !== []) {
        nibwp_blocksy_flush_css();
    }

    return [
        'changed' => $changed,
        'refused' => $refused,
        'preserved' => count(array_diff(array_keys($before), $changed)),
        'affects' => 'every page on the site',
        'store' => nibwp_blocksy_mods_option(),
    ];
}

/**
 * Remove named theme mods so the theme's own default applies again.
 *
 * AFFECTS EVERY PAGE ON THE SITE. Named keys only — there is deliberately no
 * "reset everything", because that is a theme reinstall wearing a friendly name.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_reset_theme_options(array $input): array|WP_Error
{
    $keys = [];

    foreach ((array) ($input['keys'] ?? []) as $key) {
        if (is_scalar($key) && (string) $key !== '') {
            $keys[] = (string) $key;
        }
    }

    if ($keys === []) {
        return nibwp_blocksy_err('bad_request', __('Name the theme-mod keys to reset. There is no action that resets every option at once.', 'nibwp'));
    }

    $saved = nibwp_blocksy_saved();
    $removed = [];
    $absent = [];
    $refused = [];

    foreach ($keys as $key) {
        if (in_array($key, nibwp_blocksy_core_mods(), true)) {
            $refused[] = $key;

            continue;
        }

        if (!array_key_exists($key, $saved)) {
            $absent[] = $key;

            continue;
        }

        remove_theme_mod($key);
        $removed[] = $key;
    }

    if ($removed !== []) {
        nibwp_blocksy_flush_css();
    }

    return [
        'removed' => $removed,
        'already_default' => $absent,
        'refused' => $refused,
        'affects' => 'every page on the site',
    ];
}

/* ------------------------------------------------------------------ palettes */

/**
 * The theme-mod key holding the live palette.
 *
 * Blocksy's palette surfaces as `var(--theme-palette-color-N)`
 * (framework/extensions/cookies-consent/global.php:73) but the theme owns the
 * theme-mod name, so the key is discovered from the site's own mods and falls
 * back to the conventional one.
 */
function nibwp_blocksy_palette_key(): string
{
    $candidates = ['colorPalette', 'color_palette', 'palette'];
    $saved = nibwp_blocksy_saved();

    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $saved)) {
            return $candidate;
        }
    }

    foreach (array_keys($saved) as $key) {
        if (preg_match('/palette/i', (string) $key) === 1) {
            return (string) $key;
        }
    }

    return 'colorPalette';
}

/**
 * The live palette as stored, plus the CSS tokens each entry answers to.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_blocksy_get_color_palette(array $input): array
{
    $key = isset($input['palette_key']) && (string) $input['palette_key'] !== ''
        ? sanitize_key((string) $input['palette_key'])
        : nibwp_blocksy_palette_key();

    $dropped = [];
    $raw = nibwp_blocksy_get($key, []);
    $palette = nibwp_blocksy_scrub_named($key, is_array($raw) ? $raw : [], $dropped);

    $entries = [];
    $index = 0;

    foreach ((array) $palette as $slot => $value) {
        $index++;
        $colour = null;

        if (is_array($value) && isset($value['color']) && is_scalar($value['color'])) {
            $colour = (string) $value['color'];
        } elseif (is_scalar($value)) {
            $colour = (string) $value;
        }

        $entries[] = [
            'slot' => (string) $slot,
            'color' => $colour,
            'css_variable' => '--theme-palette-color-' . $index,
            'block_preset' => 'palette-color-' . $index,
        ];
    }

    return [
        'palette_key' => $key,
        'palette' => $palette,
        'entries' => $entries,
        'count' => count($entries),
        'dropped' => $dropped,
    ];
}

/**
 * Replace the live palette.
 *
 * AFFECTS EVERY PAGE ON THE SITE — every colour on the site resolves through
 * these tokens, including inside saved block markup, which keeps referring to
 * `palette-color-N` whatever the colour behind it becomes.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_set_color_palette(array $input): array|WP_Error
{
    $palette = (array) ($input['palette'] ?? []);

    if ($palette === []) {
        return nibwp_blocksy_err('bad_request', __('Provide a non-empty "palette" object, normally color1..color8 each holding {color: "#hex"}.', 'nibwp'));
    }

    $key = isset($input['palette_key']) && (string) $input['palette_key'] !== ''
        ? sanitize_key((string) $input['palette_key'])
        : nibwp_blocksy_palette_key();

    $current = nibwp_blocksy_get($key, []);
    $current = is_array($current) ? $current : [];

    // Merge rather than replace: a palette the owner has eight slots of should not
    // lose five of them because the caller named three.
    foreach ($palette as $slot => $value) {
        $current[(string) $slot] = $value;
    }

    nibwp_blocksy_set($key, $current);
    nibwp_blocksy_flush_css();

    return [
        'palette_key' => $key,
        'changed' => array_map('strval', array_keys($palette)),
        'slots' => count($current),
        'affects' => 'every page on the site',
    ];
}

/** The saved-palette library, as an array. */
function nibwp_blocksy_saved_palettes(): array
{
    $raw = get_option('blocksy_custom_palettes', []);

    return is_array($raw) ? $raw : [];
}

/**
 * Bounded listing of the saved palettes.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_blocksy_list_saved_palettes(array $input): array
{
    $all = nibwp_blocksy_saved_palettes();
    $dropped = [];
    $rows = [];

    foreach ($all as $name => $value) {
        $rows[] = [
            'name' => (string) $name,
            'palette' => nibwp_blocksy_scrub_named((string) $name, $value, $dropped),
            'slots' => is_array($value) ? count($value) : 0,
        ];
    }

    $limit = nibwp_blocksy_limit($input);
    $offset = nibwp_blocksy_offset($input);
    $page = array_slice($rows, $offset, $limit);

    return [
        'palettes' => $page,
        'returned' => count($page),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'option' => 'blocksy_custom_palettes',
        'dropped' => $dropped,
    ];
}

/**
 * Add or replace one saved palette.
 *
 * The option is a blob of named palettes, so the write merges by name and every
 * other entry survives — including one whose shape this integration does not
 * recognise.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_save_palette(array $input): array|WP_Error
{
    $name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';
    $palette = (array) ($input['palette'] ?? []);

    if ($name === '') {
        return nibwp_blocksy_err('bad_request', __('A palette "name" is required.', 'nibwp'));
    }

    if ($palette === []) {
        return nibwp_blocksy_err('bad_request', __('Provide a non-empty "palette" object to save.', 'nibwp'));
    }

    $all = nibwp_blocksy_saved_palettes();
    $replaced = array_key_exists($name, $all);
    $before = count($all);

    $all[$name] = nibwp_blocksy_sanitize($palette);

    update_option('blocksy_custom_palettes', $all);

    return [
        'name' => $name,
        'replaced' => $replaced,
        'total' => count($all),
        'preserved' => $before - ($replaced ? 1 : 0),
        'option' => 'blocksy_custom_palettes',
    ];
}

/**
 * Remove one saved palette by name.
 *
 * Only touches the library. The live palette is a theme mod and is not affected,
 * which is worth saying because the two look the same in the Customizer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_delete_saved_palette(array $input): array|WP_Error
{
    $name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';

    if ($name === '') {
        return nibwp_blocksy_err('bad_request', __('A palette "name" is required.', 'nibwp'));
    }

    $all = nibwp_blocksy_saved_palettes();

    if (!array_key_exists($name, $all)) {
        return nibwp_blocksy_err('not_found', __('No saved palette has that name.', 'nibwp'), 404);
    }

    unset($all[$name]);

    update_option('blocksy_custom_palettes', $all);

    return [
        'deleted' => $name,
        'remaining' => count($all),
        'live_palette_affected' => false,
    ];
}

/* ---------------------------------------------------------------- typography */

/**
 * Typography-shaped theme-mod keys, found rather than assumed.
 *
 * Blocksy stores each typography control as its own theme mod and the names are
 * the theme's, so the list comes from the site: anything whose key mentions a
 * font or typography. Mirrors kadence.php's font_keys action, with discovery in
 * place of a hardcoded list because Blocksy publishes no such list.
 *
 * @return string[]
 */
function nibwp_blocksy_font_keys(): array
{
    $keys = [];

    foreach (array_keys(nibwp_blocksy_saved()) as $key) {
        if (nibwp_blocksy_group((string) $key) === 'typography') {
            $keys[] = (string) $key;
        }
    }

    sort($keys);

    return $keys;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_blocksy_get_typography(array $input): array
{
    $keys = nibwp_blocksy_font_keys();
    $limit = nibwp_blocksy_limit($input);
    $page = array_slice($keys, nibwp_blocksy_offset($input), $limit);
    $dropped = [];
    $values = [];

    foreach ($page as $key) {
        $values[$key] = nibwp_blocksy_scrub_named($key, nibwp_blocksy_get($key, null), $dropped);
    }

    return [
        'font_keys' => $page,
        'typography' => $values,
        'returned' => count($values),
        'total' => count($keys),
        'limit' => $limit,
        'dropped' => $dropped,
    ];
}

/**
 * Write typography theme mods.
 *
 * AFFECTS EVERY PAGE ON THE SITE: a font family or a type scale is site-wide.
 * Only keys that read as typography are accepted, so a mistyped key cannot
 * silently change a colour instead.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_set_typography(array $input): array|WP_Error
{
    $patch = (array) ($input['options'] ?? []);

    if ($patch === []) {
        return nibwp_blocksy_err('bad_request', __('Provide a non-empty "options" object of typography theme-mod key to value.', 'nibwp'));
    }

    $changed = [];
    $refused = [];

    foreach ($patch as $key => $value) {
        $key = (string) $key;

        if ($key === '' || nibwp_blocksy_group($key) !== 'typography') {
            $refused[] = $key;

            continue;
        }

        nibwp_blocksy_set($key, $value);
        $changed[] = $key;
    }

    if ($changed === []) {
        return nibwp_blocksy_err(
            'bad_request',
            sprintf(
                /* translators: %s: the keys that were refused */
                __('None of those keys read as typography: %s. Run get_typography to see the keys this site has.', 'nibwp'),
                implode(', ', $refused)
            )
        );
    }

    nibwp_blocksy_flush_css();

    return [
        'changed' => $changed,
        'refused' => $refused,
        'affects' => 'every page on the site',
    ];
}

/* ----------------------------------------------------------- header / footer */

/**
 * The header or footer builder structure, as far as it is readable.
 *
 * The companion walks `header_placements` itself as
 * $value['sections'][...]['items'][...]['values'] and
 * ['sections'][...]['settings'] (framework/features/demo-install/install-finish.php:403-425),
 * so that is the shape this reports. The footer uses the same theme-mod naming
 * convention; when the key is absent the answer says so rather than inventing
 * rows.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_get_placements(string $which): array
{
    $key = $which . '_placements';
    $saved = nibwp_blocksy_saved();

    if (!array_key_exists($key, $saved)) {
        // Try the site's own naming before concluding there is nothing.
        foreach (array_keys($saved) as $candidate) {
            $candidate = (string) $candidate;

            if (nibwp_blocksy_group($candidate) === $which && str_contains(strtolower($candidate), 'placement')) {
                $key = $candidate;

                break;
            }
        }
    }

    $raw = nibwp_blocksy_get($key, []);
    $raw = is_array($raw) ? $raw : [];
    $dropped = [];

    $rows = [];

    foreach ((array) ($raw['sections'] ?? []) as $index => $section) {
        $section = (array) $section;
        $items = [];

        foreach ((array) ($section['items'] ?? []) as $item) {
            $item = (array) $item;
            $items[] = [
                'id' => isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '',
                'column' => isset($item['column']) && is_scalar($item['column']) ? (string) $item['column'] : '',
                'has_values' => isset($item['values']) && is_array($item['values']) && $item['values'] !== [],
            ];
        }

        $rows[] = [
            'index' => (int) $index,
            'id' => isset($section['id']) && is_scalar($section['id']) ? (string) $section['id'] : '',
            'items' => $items,
            'item_count' => count($items),
            'settings' => nibwp_blocksy_scrub((array) ($section['settings'] ?? []), $dropped, $key . '.sections.' . (string) $index . '.settings'),
        ];
    }

    return [
        'placements_key' => $key,
        'configured' => array_key_exists($key, $saved),
        'rows' => $rows,
        'row_count' => count($rows),
        'dropped' => $dropped,
    ];
}

/**
 * Bounded flat listing of every header element across every row.
 *
 * Flat because "which elements are in my header" is the question, and the nesting
 * in get_header_structure makes that hard to read at a glance.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_blocksy_list_header_elements(array $input): array
{
    $structure = nibwp_blocksy_get_placements('header');
    $rows = [];

    foreach ($structure['rows'] as $row) {
        foreach ($row['items'] as $item) {
            $rows[] = [
                'row_index' => $row['index'],
                'row_id' => $row['id'],
                'element' => $item['id'],
                'column' => $item['column'],
                'configured' => $item['has_values'],
            ];
        }
    }

    $limit = nibwp_blocksy_limit($input);
    $offset = nibwp_blocksy_offset($input);
    $page = array_slice($rows, $offset, $limit);

    return [
        'placements_key' => $structure['placements_key'],
        'elements' => $page,
        'returned' => count($page),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/* ------------------------------------------------------------ post overrides */

/**
 * Read one post's Blocksy overrides.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_get_post_overrides(array $input): array|WP_Error
{
    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
    $post = nibwp_blocksy_resolve_post($post_id, __('Reading a post\'s Blocksy overrides', 'nibwp'));

    if ($post instanceof WP_Error) {
        return $post;
    }

    $key = isset($input['meta_key']) && (string) $input['meta_key'] !== ''
        ? sanitize_key((string) $input['meta_key'])
        : nibwp_blocksy_post_override_key($post_id);

    $dropped = [];
    $raw = $key === '' ? [] : get_post_meta($post_id, $key, true);
    $overrides = nibwp_blocksy_scrub_named($key === '' ? 'overrides' : $key, is_array($raw) ? $raw : [], $dropped);

    return [
        'post_id' => $post_id,
        'post_type' => $post->post_type,
        'post_title' => $post->post_title,
        'meta_key' => $key,
        'discovered' => !isset($input['meta_key']),
        'has_overrides' => is_array($overrides) && $overrides !== [],
        'overrides' => $overrides,
        'override_count' => is_array($overrides) ? count($overrides) : 0,
        'candidates' => nibwp_blocksy_post_meta_keys(),
        'dropped' => $dropped,
    ];
}

/**
 * Merge values into one post's Blocksy overrides.
 *
 * Affects THIS POST only, not the site. The merge preserves every override key
 * already on the post, including ones this integration does not understand —
 * dropping one would silently reset part of that page's layout.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_set_post_overrides(array $input): array|WP_Error
{
    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
    $post = nibwp_blocksy_resolve_post($post_id, __('Writing a post\'s Blocksy overrides', 'nibwp'));

    if ($post instanceof WP_Error) {
        return $post;
    }

    $patch = (array) ($input['overrides'] ?? []);

    if ($patch === []) {
        return nibwp_blocksy_err('bad_request', __('Provide a non-empty "overrides" object.', 'nibwp'));
    }

    $key = nibwp_blocksy_post_write_key($post_id, $input);

    if ($key === '') {
        return nibwp_blocksy_err('bad_request', __('The override meta key could not be resolved. Pass "meta_key" explicitly.', 'nibwp'));
    }

    $current = get_post_meta($post_id, $key, true);
    $current = is_array($current) ? $current : [];
    $before = array_keys($current);

    foreach ($patch as $override_key => $value) {
        $override_key = sanitize_key((string) $override_key);

        if ($override_key === '') {
            continue;
        }

        $current[$override_key] = nibwp_blocksy_sanitize($value);
    }

    update_post_meta($post_id, $key, $current);

    return [
        'post_id' => $post_id,
        'meta_key' => $key,
        'changed' => array_map('strval', array_keys($patch)),
        'preserved' => count(array_diff($before, array_map('strval', array_keys($patch)))),
        'override_count' => count($current),
        'affects' => 'this post only',
    ];
}

/**
 * Remove one post's Blocksy overrides entirely.
 *
 * The post then renders with the site-wide theme options, which is usually what
 * someone wants when they say an inner page "looks wrong".
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_clear_post_overrides(array $input): array|WP_Error
{
    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
    $post = nibwp_blocksy_resolve_post($post_id, __('Clearing a post\'s Blocksy overrides', 'nibwp'));

    if ($post instanceof WP_Error) {
        return $post;
    }

    $key = isset($input['meta_key']) && (string) $input['meta_key'] !== ''
        ? sanitize_key((string) $input['meta_key'])
        : nibwp_blocksy_post_override_key($post_id);

    if ($key === '') {
        return [
            'post_id' => $post_id,
            'meta_key' => '',
            'cleared' => false,
            'reason' => 'this post has no Blocksy overrides',
        ];
    }

    $had = get_post_meta($post_id, $key, true);

    delete_post_meta($post_id, $key);

    return [
        'post_id' => $post_id,
        'meta_key' => $key,
        'cleared' => true,
        'had_override_count' => is_array($had) ? count($had) : 0,
        'affects' => 'this post only',
    ];
}

/* ------------------------------------------------------------ content blocks */

/**
 * Bounded listing of the Content Blocks on this site.
 *
 * The hook/position values are reported as the data has them rather than against
 * an invented catalogue, so the list doubles as the reference for creating one.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_list_content_blocks(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_content_blocks_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $limit = nibwp_blocksy_limit($input);
    $offset = nibwp_blocksy_offset($input);

    $posts = get_posts([
        'post_type' => NIBWP_BLOCKSY_CB_CPT,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => $limit,
        'offset' => $offset,
        'orderby' => 'title',
        'order' => 'ASC',
        'suppress_filters' => false,
    ]);

    $rows = [];
    $hooks = [];

    foreach ((array) $posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        $condition_key = nibwp_blocksy_cb_condition_key($post->ID);
        $hook = nibwp_blocksy_cb_hook($post->ID);

        if ($hook !== '') {
            $hooks[$hook] = true;
        }

        $rows[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'hook' => $hook,
            'conditions_meta_key' => $condition_key,
            'has_conditions' => $condition_key !== '',
            'has_content' => $post->post_content !== '',
        ];
    }

    return [
        'content_blocks' => $rows,
        'returned' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'post_type' => NIBWP_BLOCKSY_CB_CPT,
        'hooks_in_use' => array_keys($hooks),
    ];
}

/**
 * The template position a content block is attached to, if it says.
 *
 * Discovered from the block's own meta because Blocksy Pro owns the key name and
 * Pro is not in the package.
 */
function nibwp_blocksy_cb_hook(int $post_id): string
{
    foreach (['blocksy_content_block_hook', 'blocksy_hook', 'ct_hook'] as $candidate) {
        $value = get_post_meta($post_id, $candidate, true);

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }
    }

    return '';
}

/**
 * One content block in full, including every Blocksy meta key it carries.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_get_content_block(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_content_blocks_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $post = nibwp_blocksy_resolve_content_block($id, __('Reading a Content Block', 'nibwp'));

    if ($post instanceof WP_Error) {
        return $post;
    }

    $dropped = [];
    $condition_key = nibwp_blocksy_cb_condition_key($id);

    return [
        'id' => $id,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'hook' => nibwp_blocksy_cb_hook($id),
        'conditions_meta_key' => $condition_key,
        'has_conditions' => $condition_key !== '',
        'conditions' => $condition_key === '' ? [] : nibwp_blocksy_scrub_named($condition_key, maybe_unserialize(get_post_meta($id, $condition_key, true)), $dropped),
        'meta' => nibwp_blocksy_cb_meta($id, $dropped),
        'content_length' => strlen($post->post_content),
        'condition_key_candidates' => nibwp_blocksy_cb_condition_keys(),
        'dropped' => $dropped,
    ];
}

/**
 * Create a Content Block.
 *
 * Draft by default. A content block with no display condition either shows
 * nowhere or shows on every page, and the second is how a site ends up with a
 * banner above every post, so publishing one before its conditions are set is a
 * mistake this action declines to make easy.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_create_content_block(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_content_blocks_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_blocksy_err('bad_request', __('A "title" is required for a new Content Block.', 'nibwp'));
    }

    $status = isset($input['status']) && in_array((string) $input['status'], ['publish', 'draft'], true)
        ? (string) $input['status']
        : 'draft';

    $content = isset($input['content']) && is_scalar($input['content']) ? (string) $input['content'] : '';

    // wp_insert_post() unslashes what it is given, so content that is not slashed
    // loses a backslash and a quote. No kses of our own: the block body is
    // deliberate markup and this is a capability-gated write.
    $id = wp_insert_post([
        'post_type' => NIBWP_BLOCKSY_CB_CPT,
        'post_status' => $status,
        'post_title' => $title,
        'post_content' => wp_slash($content),
    ], true);

    if (is_wp_error($id)) {
        return nibwp_blocksy_err('create_failed', (string) $id->get_error_message(), 500);
    }

    $id = (int) $id;
    $hook = isset($input['hook']) ? sanitize_text_field((string) $input['hook']) : '';

    if ($hook !== '') {
        update_post_meta($id, 'blocksy_content_block_hook', $hook);
    }

    $applied = [];

    if (isset($input['conditions']) && is_array($input['conditions'])) {
        $applied = nibwp_blocksy_apply_conditions($id, $input);
    }

    return [
        'id' => $id,
        'title' => $title,
        'status' => $status,
        'hook' => $hook,
        'conditions_meta_key' => $applied === [] ? '' : (string) ($applied['meta_key'] ?? ''),
        'has_conditions' => $applied !== [],
        'warning' => $applied === []
            ? 'This block has no display condition. Set one with set_content_block_conditions before publishing it.'
            : '',
    ];
}

/**
 * Write a Content Block's display conditions.
 *
 * The meta key is the discovered one, or `conditions_meta_key` when the caller
 * names it. Mirrors the escape hatch in generatepress.php and kadence.php: the
 * vendor owns the key name and a future rename must be fixable without a release.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_set_content_block_conditions(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_content_blocks_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $post = nibwp_blocksy_resolve_content_block($id, __('Setting a Content Block\'s display conditions', 'nibwp'));

    if ($post instanceof WP_Error) {
        return $post;
    }

    if (!isset($input['conditions']) || !is_array($input['conditions'])) {
        return nibwp_blocksy_err('bad_request', __('Provide a "conditions" array, in the shape get_content_block reports.', 'nibwp'));
    }

    $applied = nibwp_blocksy_apply_conditions($id, $input);

    return [
        'id' => $id,
        'title' => $post->post_title,
        'meta_key' => (string) ($applied['meta_key'] ?? ''),
        'rule_count' => (int) ($applied['rule_count'] ?? 0),
        'affects' => 'where this block appears on the site',
    ];
}

/**
 * Store conditions on a content block and say where they went.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_blocksy_apply_conditions(int $id, array $input): array
{
    $key = isset($input['conditions_meta_key']) && (string) $input['conditions_meta_key'] !== ''
        ? sanitize_key((string) $input['conditions_meta_key'])
        : nibwp_blocksy_cb_condition_key($id);

    if ($key === '') {
        $candidates = nibwp_blocksy_cb_condition_keys();
        $key = (string) $candidates[0];
    }

    $rules = nibwp_blocksy_sanitize(array_values((array) ($input['conditions'] ?? [])));
    $rules = is_array($rules) ? $rules : [];

    update_post_meta($id, $key, $rules);

    return ['meta_key' => $key, 'rule_count' => count($rules)];
}

/**
 * Trash a Content Block, with its exact title as confirmation.
 *
 * Trash rather than destroy: a block is somebody's markup, and the only thing a
 * trashed block does differently from a deleted one is come back.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_delete_content_block(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_content_blocks_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $post = nibwp_blocksy_resolve_content_block($id, __('Deleting a Content Block', 'nibwp'));

    if ($post instanceof WP_Error) {
        return $post;
    }

    $confirm = isset($input['confirm']) ? (string) $input['confirm'] : '';

    if ($confirm !== $post->post_title) {
        return nibwp_blocksy_err(
            'confirm_required',
            sprintf(
                /* translators: %s: the content block's title */
                __('Pass the block\'s exact title in "confirm" to delete it: %s', 'nibwp'),
                $post->post_title
            )
        );
    }

    wp_trash_post($id);

    return [
        'id' => $id,
        'title' => $post->post_title,
        'trashed' => true,
        'recoverable' => true,
    ];
}

/* --------------------------------------------------------------- extensions */

/**
 * Which Companion extensions exist, and which are switched on.
 *
 * `installed` distinguishes "this version ships it" from "it is in the catalogue
 * but needs Pro" — a slug can be in `blocksy_active_extensions` and still do
 * nothing because the code that reads it is not in a free build.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_list_extensions(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_companion_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $catalog = nibwp_blocksy_extension_catalog();
    $active = nibwp_blocksy_active_extensions();
    $premium = nibwp_blocksy_premium();

    $rows = [];

    foreach ($catalog as $slug => $meta) {
        $option = (string) $meta['option'];
        $configured = null;

        if ($option !== '') {
            $value = get_option($option, null);
            $configured = $value !== null && $value !== false && $value !== '' && $value !== [];
        }

        $rows[] = [
            'slug' => (string) $slug,
            'enabled' => in_array((string) $slug, $active, true),
            'requires_premium' => (bool) $meta['pro'],
            'usable' => !$meta['pro'] || $premium,
            'settings_option' => $option,
            // Never the settings themselves: one of these options is a third-party
            // API credential. Whether it is set is the whole answer here.
            'configured' => $configured,
        ];
    }

    foreach ($active as $slug) {
        if (!isset($catalog[$slug])) {
            $rows[] = [
                'slug' => $slug,
                'enabled' => true,
                'requires_premium' => null,
                'usable' => null,
                'settings_option' => '',
                'configured' => null,
                'unknown_to_this_integration' => true,
            ];
        }
    }

    $limit = nibwp_blocksy_limit($input);
    $offset = nibwp_blocksy_offset($input);
    $page = array_slice($rows, $offset, $limit);

    return [
        'extensions' => $page,
        'returned' => count($page),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'option' => 'blocksy_active_extensions',
        'premium_active' => $premium,
    ];
}

/**
 * Switch one Companion extension on or off.
 *
 * AFFECTS THE WHOLE SITE: an extension adds Customizer options, front-end output
 * or a post type. Goes through the companion's own manager when it is loaded
 * (framework/includes/extensions-manager.php:185 and :229) so its onActivation
 * hook runs and its CSS cache is flushed; falls back to the option only when the
 * manager is not reachable, and says which path it took.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_blocksy_set_extension_state(array $input): array|WP_Error
{
    $guard = nibwp_blocksy_companion_guard();

    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $slug = isset($input['extension']) ? strtolower(sanitize_key((string) $input['extension'])) : '';

    if ($slug === '') {
        return nibwp_blocksy_err('bad_request', __('An "extension" slug is required. Run list_extensions for the slugs.', 'nibwp'));
    }

    $catalog = nibwp_blocksy_extension_catalog();

    if (!isset($catalog[$slug])) {
        return nibwp_blocksy_err(
            'unknown_extension',
            sprintf(
                /* translators: %s: the extension slug given */
                __('No Blocksy Companion extension is called "%s". Run list_extensions for the slugs this build knows.', 'nibwp'),
                $slug
            ),
            404
        );
    }

    if (!isset($input['enabled'])) {
        return nibwp_blocksy_err('bad_request', __('Pass "enabled" true or false.', 'nibwp'));
    }

    $enabled = (bool) $input['enabled'];
    $active = nibwp_blocksy_active_extensions();
    $was = in_array($slug, $active, true);
    $via = 'option';

    $manager = nibwp_blocksy_extensions_manager();

    if ($manager !== null) {
        $via = 'manager';

        if ($enabled) {
            $manager->activate_extension($slug);
        } else {
            $manager->deactivate_extension($slug);
        }
    } else {
        $next = $enabled
            ? array_values(array_unique(array_merge($active, [$slug])))
            : array_values(array_diff($active, [$slug]));

        update_option('blocksy_active_extensions', $next);
        nibwp_blocksy_flush_css();
    }

    return [
        'extension' => $slug,
        'enabled' => $enabled,
        'changed' => $was !== $enabled,
        'via' => $via,
        'requires_premium' => (bool) $catalog[$slug]['pro'],
        'settings_option' => (string) $catalog[$slug]['option'],
        'affects' => 'the whole site',
    ];
}

/**
 * The companion's extension manager, if it is reachable.
 *
 * Every hop is guarded: the class, the method, the property and the two methods
 * we call. A half-booted companion must become a fallback, not a fatal.
 */
function nibwp_blocksy_extensions_manager(): ?object
{
    if (!class_exists('Blocksy\\Plugin') || !method_exists('Blocksy\\Plugin', 'instance')) {
        return null;
    }

    try {
        $plugin = \Blocksy\Plugin::instance();

        if (!is_object($plugin) || !isset($plugin->extensions) || !is_object($plugin->extensions)) {
            return null;
        }

        $manager = $plugin->extensions;

        if (!method_exists($manager, 'activate_extension') || !method_exists($manager, 'deactivate_extension')) {
            return null;
        }

        return $manager;
    } catch (\Throwable $error) {
        return null;
    }
}

/* -------------------------------------------------------------------- audits */

/**
 * The four findings that are invisible in wp-admin.
 *
 * Each one is a thing a Blocksy site accumulates and nothing surfaces:
 *
 *   1. Per-post overrides on posts nobody has edited in a year. They still win
 *      over the site's theme options, so the owner changes a setting in the
 *      Customizer and those pages do not move.
 *   2. Content Blocks with no display condition. They show nowhere, or they show
 *      everywhere, and which one it is depends on the build.
 *   3. An extension switched on with nothing configured — a feature carrying its
 *      cost and doing nothing.
 *   4. Palette colours nothing references. Renaming or reusing one is safe; the
 *      point is knowing which.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function nibwp_blocksy_audit_customization(array $input): array
{
    $limit = nibwp_blocksy_limit($input);
    $stale_days = isset($input['stale_days']) ? (int) $input['stale_days'] : 365;

    if ($stale_days < 1) {
        $stale_days = 365;
    }

    $findings = [
        'stale_post_overrides' => nibwp_blocksy_audit_stale_overrides($limit, $stale_days),
        'content_blocks_without_conditions' => nibwp_blocksy_audit_content_blocks($limit),
        'extensions_without_configuration' => nibwp_blocksy_audit_extensions(),
        'unreferenced_palette_colors' => nibwp_blocksy_audit_palette($limit),
    ];

    $total = 0;

    foreach ($findings as $finding) {
        $total += (int) ($finding['count'] ?? 0);
    }

    return [
        'findings' => $findings,
        'finding_count' => $total,
        'stale_days' => $stale_days,
        'limit' => $limit,
    ];
}

/**
 * Posts carrying Blocksy overrides that nobody has edited in a long time.
 *
 * Raw SQL because the alternative is hydrating every post on the site to look at
 * one meta key. Every VALUE goes through prepare(); the meta keys come from
 * nibwp_blocksy_post_meta_keys(), which is a constant list in this file and never
 * anything a caller sent, because a table or column name cannot be prepared.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_audit_stale_overrides(int $limit, int $stale_days): array
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return ['count' => 0, 'rows' => [], 'unavailable' => true];
    }

    $keys = nibwp_blocksy_post_meta_keys();
    $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

    $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_modified_gmt, m.meta_key
        FROM {$wpdb->postmeta} m
        INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
        WHERE m.meta_key IN ({$placeholders})
          AND m.meta_value != ''
          AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
          AND p.post_modified_gmt < %s
        ORDER BY p.post_modified_gmt ASC
        LIMIT %d";

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($stale_days * DAY_IN_SECONDS));
    $args = array_merge($keys, [$cutoff, $limit]);

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    $out = [];

    foreach ((array) $rows as $row) {
        $row = (array) $row;
        $out[] = [
            'post_id' => (int) ($row['ID'] ?? 0),
            'title' => isset($row['post_title']) && is_scalar($row['post_title']) ? (string) $row['post_title'] : '',
            'post_type' => isset($row['post_type']) && is_scalar($row['post_type']) ? (string) $row['post_type'] : '',
            'status' => isset($row['post_status']) && is_scalar($row['post_status']) ? (string) $row['post_status'] : '',
            'last_modified_gmt' => isset($row['post_modified_gmt']) && is_scalar($row['post_modified_gmt']) ? (string) $row['post_modified_gmt'] : '',
            'meta_key' => isset($row['meta_key']) && is_scalar($row['meta_key']) ? (string) $row['meta_key'] : '',
        ];
    }

    return [
        'count' => count($out),
        'rows' => $out,
        'bounded_to' => $limit,
        'cutoff_gmt' => $cutoff,
        'why' => 'A per-post override beats the site-wide theme option, so these pages ignore Customizer changes.',
    ];
}

/**
 * Content Blocks with no display condition.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_audit_content_blocks(int $limit): array
{
    if (!nibwp_blocksy_content_blocks_available()) {
        return [
            'count' => 0,
            'rows' => [],
            'unavailable' => true,
            'why' => 'Content Blocks are a Blocksy Pro feature and the ct_content_block post type is not registered here.',
        ];
    }

    $posts = get_posts([
        'post_type' => NIBWP_BLOCKSY_CB_CPT,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => $limit,
        'orderby' => 'title',
        'order' => 'ASC',
        'suppress_filters' => false,
    ]);

    $rows = [];

    foreach ((array) $posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (nibwp_blocksy_cb_condition_key($post->ID) !== '') {
            continue;
        }

        $rows[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'hook' => nibwp_blocksy_cb_hook($post->ID),
        ];
    }

    return [
        'count' => count($rows),
        'rows' => $rows,
        'bounded_to' => $limit,
        'why' => 'A block with no condition shows nowhere, or shows on every page. Neither is usually what was meant.',
    ];
}

/**
 * Extensions switched on with an empty settings option.
 *
 * An extension whose settings option is '' keeps its configuration in theme mods
 * instead, so it is skipped rather than reported as a finding.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_audit_extensions(): array
{
    $catalog = nibwp_blocksy_extension_catalog();
    $rows = [];

    foreach (nibwp_blocksy_active_extensions() as $slug) {
        if (!isset($catalog[$slug])) {
            $rows[] = ['slug' => $slug, 'reason' => 'switched on but unknown to this Companion version'];

            continue;
        }

        $option = (string) $catalog[$slug]['option'];

        if ($option === '') {
            continue;
        }

        $value = get_option($option, null);

        if ($value === null || $value === false || $value === '' || $value === []) {
            $rows[] = ['slug' => $slug, 'settings_option' => $option, 'reason' => 'switched on with nothing configured'];
        }
    }

    return [
        'count' => count($rows),
        'rows' => $rows,
        'why' => 'An extension that is on and unconfigured carries its cost and does nothing.',
    ];
}

/**
 * Palette entries whose CSS token appears nowhere.
 *
 * Looks in the theme mods (where a Customizer control stores
 * `var(--theme-palette-color-N)`) and then, for anything still unreferenced, in
 * post content (where block markup stores the `palette-color-N` preset). The post
 * scan is one bounded prepared COUNT per candidate colour, not a table dump.
 *
 * @return array<string, mixed>
 */
function nibwp_blocksy_audit_palette(int $limit): array
{
    global $wpdb;

    $palette = nibwp_blocksy_get_color_palette([]);
    $mods_blob = wp_json_encode(nibwp_blocksy_saved());
    $mods_blob = is_string($mods_blob) ? $mods_blob : '';

    $rows = [];
    $checked = 0;

    foreach ($palette['entries'] as $entry) {
        if ($checked >= $limit) {
            break;
        }

        $checked++;

        $variable = (string) $entry['css_variable'];
        $preset = (string) $entry['block_preset'];

        if (str_contains($mods_blob, $variable) || str_contains($mods_blob, $preset)) {
            continue;
        }

        $in_content = 0;

        if (isset($wpdb) && is_object($wpdb)) {
            $sql = "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_status NOT IN ('trash', 'auto-draft')
                  AND (post_content LIKE %s OR post_content LIKE %s)";

            $in_content = (int) $wpdb->get_var($wpdb->prepare(
                $sql,
                [
                    '%' . $wpdb->esc_like($variable) . '%',
                    '%' . $wpdb->esc_like($preset) . '%',
                ]
            ));
        }

        if ($in_content > 0) {
            continue;
        }

        $rows[] = [
            'slot' => (string) $entry['slot'],
            'color' => $entry['color'],
            'css_variable' => $variable,
            'block_preset' => $preset,
        ];
    }

    return [
        'count' => count($rows),
        'rows' => $rows,
        'palette_key' => (string) $palette['palette_key'],
        'checked' => $checked,
        'bounded_to' => $limit,
        'why' => 'Nothing on the site resolves these tokens, so changing them changes nothing visible.',
    ];
}
