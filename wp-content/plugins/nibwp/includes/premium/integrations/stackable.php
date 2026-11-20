<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Stackable (Gutenberg blocks).
 *
 * Verified against Stackable 3.20.2, build "free" (plugin.php:10, plugin.php:45-46).
 * The plugin directory is stackable-ultimate-gutenberg-blocks; the integration
 * key is stackable.
 *
 * The point of this hub is that an agent asked to "add a pricing section with
 * Stackable" currently guesses: it guesses the block names, it guesses the
 * attribute names, and it does not know that the owner switched four of the
 * blocks off last March. All three guesses are answerable from this site, so
 * they are answered here.
 *
 * THE ATTRIBUTE SCHEMA, AND WHY IT IS USUALLY EMPTY. This is the one thing to
 * read before trusting list_blocks. Stackable registers its blocks from a
 * generated PHP array (src/stk-block-types.php:10) passed to register_block_type()
 * as an array, not a path (src/blocks.php:39) — so block.json is never read
 * server-side, and neither the PHP array nor the 75 block.json files declare
 * `attributes` at all (zero occurrences in src/stk-block-types.php; zero
 * block.json files contain the key). Every Stackable attribute is declared in
 * the editor bundle (dist/editor_blocks.js) and nowhere PHP can see it. So the
 * registry's attribute schema for a Stackable block is an empty array, and this
 * integration says so in the response rather than implying the block takes no
 * attributes. attribute_names_in_use is the honest substitute: it reads the
 * attribute names out of content this site has already saved. A fixture or a
 * future version that DOES register attributes is passed through whole — there
 * is no allow-list, so an attribute nobody here anticipated is reported.
 *
 * SWITCHING BLOCKS OFF. The state lives in the option `stackable_block_states`
 * (src/editor-settings.php:45-64): an object keyed by block name, value a number.
 * 1 = enabled, 2 = hidden, 3 = disabled (dist/stk.js and dist/admin_welcome.js:
 * `Object.freeze({ENABLED:1,HIDDEN:2,DISABLED:3})`; confirmed independently by
 * the v2 migration comment at src/deprecated/editor-settings.php:22-23, "In the
 * block_states, hidden is 2 and disabled is 3"). AN ABSENT KEY MEANS ENABLED —
 * the plugin stores only the exceptions, "Enabled blocks are not stored in the
 * object to save memory" (src/editor-settings.php:48-49), and the option
 * defaults to an empty array (src/editor-settings.php:62). Every read here
 * treats absent as enabled, and set_block_state REMOVES the key to enable a
 * block rather than writing 1 — see nibwp_stk_set_block_state() for the reason,
 * which is a real quirk of this version and not a tidiness preference.
 *
 * WHAT SWITCHING OFF ACTUALLY DOES, which is not what the support ticket says.
 * Nothing on the PHP side consults the option: src/blocks.php registers every
 * block regardless, so a disabled block's existing markup still renders on the
 * front end and still gets its CSS (src/init.php:287-299 keys off the content
 * string, not the state). The damage is in the editor, where
 * `registerBlockType` is skipped for a block whose state is DISABLED
 * (dist/editor_blocks.js). An author opening a page that uses it sees
 * unrecognised content where their section was, and a re-save or a "convert to
 * HTML" turns that into permanent damage. That is the "my section disappeared"
 * report, and audit_switched_off_in_use finds it before somebody re-saves.
 *
 * NOT HERE, and why. Writing the global colour palette or the global typography:
 * both are nested arrays-of-arrays (src/global-settings.php:200-232 and 355-383)
 * where a merge done slightly wrong silently drops a palette an owner spent an
 * afternoon on, and the editor's own panel is one click away. The deprecated
 * Block Defaults surface, including the `stackable_temp_post` post type: that CPT
 * is NOT dead code — it is hooked at admin_init and rest_api_init
 * (src/deprecated/block-defaults/custom-block-styles.php:43-44, :585) — but the
 * file is only required when the option `stackable_enable_block_defaults` is
 * truthy (src/deprecated/block-defaults.php:56-63), it defaults to false
 * (:24), and the whole surface is deprecated since 3.18.0 (:1-5). An action for
 * it would refuse on nearly every site, so there isn't one. The v2 compatibility
 * blocks and their separate `stackable_v2_disabled_blocks` option
 * (src/deprecated/v2/disabled-blocks.php:48) are likewise left alone: that option
 * governs only the deprecated v2 block set, behind
 * has_stackable_v2_*_compatibility() (:19), and confusing it with
 * stackable_block_states is exactly the mistake this integration exists to
 * prevent.
 */

/** Reading. The plugin's own settings route sets this bar (src/admin.php:38-40). */
const NIBWP_STK_READ_CAP = 'edit_posts';

/** Writing. A site-wide setting or a block switch changes the editor for every author. */
const NIBWP_STK_WRITE_CAP = 'manage_options';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_STK_MAX_ROWS = 200;

/** Where the per-block on/off state lives (src/editor-settings.php:47). */
const NIBWP_STK_STATES_OPTION = 'stackable_block_states';

/**
 * Is Stackable actually loaded?
 *
 * The constant is defined in the main plugin file before anything else
 * (plugin.php:46) and the generated catalog function is what every block read
 * here goes through (src/stk-block-types.php:9), so between them they
 * distinguish "not installed" from "installed and half-loaded".
 */
function nibwp_stk_guard(): ?WP_Error
{
    if (!defined('STACKABLE_VERSION') && !function_exists('stackable_get_blocks_array')) {
        return new WP_Error(
            'nibwp_stk_missing',
            __('Stackable is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about authority rather than about the request. */
function nibwp_stk_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_stk_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/** The capability gate. */
function nibwp_stk_require(string $what, string $cap): ?WP_Error
{
    return current_user_can($cap) ? null : nibwp_stk_forbidden($what, $cap);
}

/**
 * The per-post gate.
 *
 * edit_posts says the account may edit SOMETHING; edit_post says it may edit
 * THIS. A contributor can edit their own drafts and nobody else's, and a
 * listing that checked only edit_posts would hand them every page on the site.
 */
function nibwp_stk_can_edit(int $post_id): bool
{
    return $post_id > 0 && current_user_can('edit_post', $post_id);
}

/** A refusal about the request itself. */
function nibwp_stk_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_stk_' . $code, $message, ['status' => $status]);
}

/**
 * The numbers Stackable stores for each state.
 *
 * dist/stk.js and dist/admin_welcome.js both carry
 * Object.freeze({ENABLED:1,HIDDEN:2,DISABLED:3}); the v2 migration writes 2 for
 * a block the owner had disabled under the old scheme and names both numbers in
 * a comment (src/deprecated/editor-settings.php:22-23).
 *
 * @return array<string, int>
 */
function nibwp_stk_state_values(): array
{
    return [
        'enabled'  => 1,
        'hidden'   => 2,
        'disabled' => 3,
    ];
}

/**
 * The plugin's own block catalog, built fresh.
 *
 * stackable_get_blocks_array() builds its array locally and returns
 * array_merge() of it every call (src/stk-block-types.php:10, :1134) — no static,
 * no transient — so calling it directly is both authoritative and safe to call
 * after a write. The `stackable.blocks` filter is the fallback for a build that
 * renamed the function, and it is the same data: that filter has exactly one
 * subscriber (src/stk-block-types.php:1138).
 *
 * Deliberately NOT cached in a static here. A static would make
 * set_block_state followed by list_block_states in one request report the state
 * from before the write, which is the single most confusing thing an integration
 * like this can do.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_stk_catalog(): array
{
    $blocks = [];

    try {
        if (function_exists('stackable_get_blocks_array')) {
            $blocks = (array) stackable_get_blocks_array([]);
        } elseif (function_exists('apply_filters')) {
            $blocks = (array) apply_filters('stackable.blocks', []);
        }
    } catch (\Throwable $error) {
        return [];
    }

    $out = [];

    foreach ($blocks as $name => $metadata) {
        if (!is_string($name) || $name === '' || !is_array($metadata)) {
            continue;
        }
        $out[$name] = $metadata;
    }

    return $out;
}

/**
 * The stored on/off state, read straight from the option every time.
 *
 * No static, no wp_cache_get of our own, and no call into the plugin: the only
 * PHP that reads this option hands it to the editor as-is
 * (src/editor-settings.php:308), so there is nothing to ask. get_option() is
 * kept fresh by update_option() within a request, which is what makes a read
 * immediately after nibwp_stk_set_block_state() correct rather than stale.
 *
 * Keys are block names ("stackable/heading") and also block-plus-variant names
 * ("stackable/button-group|button") — the editor reads the second form by
 * prefix (dist/editor_blocks.js), and src/stk-block-types.php:83 points a
 * block at one with stk-block-dependency.
 *
 * @return array<string, int>
 */
function nibwp_stk_states(): array
{
    $raw = get_option(NIBWP_STK_STATES_OPTION, []);

    if (!is_array($raw)) {
        return [];
    }

    $states = [];

    foreach ($raw as $name => $value) {
        // The option's sanitiser only checks that the whole thing is an array
        // (src/editor-settings.php:282-284), so a stray key or a string "3" is
        // possible and is read rather than discarded.
        if (!is_string($name) || $name === '') {
            continue;
        }
        if (!is_numeric($value)) {
            continue;
        }

        $states[$name] = (int) $value;
    }

    return $states;
}

/**
 * The state of one block, as a word.
 *
 * ABSENT MEANS ENABLED. The plugin stores only the exceptions
 * (src/editor-settings.php:48-49) and the option's default is an empty array
 * (:62), so a block nobody has ever touched is not in the option and is on. Get
 * this backwards and the hub tells an owner their whole site is switched off.
 *
 * @param array<string, int> $states
 */
function nibwp_stk_state_of(string $name, array $states): string
{
    if (!array_key_exists($name, $states)) {
        return 'enabled';
    }

    $number = $states[$name];

    foreach (nibwp_stk_state_values() as $word => $value) {
        if ($value === $number) {
            return $word;
        }
    }

    // A number this version does not define. Reported rather than rounded to
    // the nearest known state, because guessing here would mean telling an
    // owner a block is on when the editor may be treating it as off.
    return 'unrecognised';
}

/**
 * Is the block kept out of the inserter?
 *
 * Careful, this is NOT the same question as "is it enabled". The editor sets
 * supports.inserter from `!states[name] === BLOCK_STATE.HIDDEN`
 * (dist/editor_blocks.js), and in JavaScript that compares a boolean with the
 * number 2, which is false whichever way it lands. The upshot in 3.20.2 is that
 * a block whose name appears in the option AT ALL drops out of the inserter,
 * including one explicitly stored as 1/enabled. That is why enabling a block
 * here unsets the key instead of writing 1.
 *
 * @param array<string, int> $states
 */
function nibwp_stk_hidden_from_inserter(string $name, array $states): bool
{
    return array_key_exists($name, $states);
}

/**
 * Which states this block is allowed to take.
 *
 * Three blocks declare stk-available-states and list only enabled and hidden —
 * stackable/column, stackable/columns and stackable/design-library
 * (src/stk-block-types.php:234, :261, :322). They are the blocks everything else
 * is built out of, so disabling them would strand saved layouts. Where the key
 * is absent all three states are allowed.
 *
 * @param array<string, mixed> $metadata
 * @return array<int, string>
 */
function nibwp_stk_allowed_states(array $metadata): array
{
    $declared = $metadata['stk-available-states'] ?? null;

    if (!is_array($declared) || $declared === []) {
        return array_keys(nibwp_stk_state_values());
    }

    $allowed = [];

    foreach ($declared as $state) {
        if (is_string($state) && isset(nibwp_stk_state_values()[$state])) {
            $allowed[] = $state;
        }
    }

    return $allowed === [] ? array_keys(nibwp_stk_state_values()) : $allowed;
}

/**
 * What the block registry holds for one block.
 *
 * The registry rather than the catalog, because the registry is what the REST
 * API and the editor actually serve, and a theme or another plugin can have
 * changed it since Stackable registered.
 *
 * @return array<string, mixed>
 */
function nibwp_stk_registry_info(string $name): array
{
    if (!class_exists('WP_Block_Type_Registry')) {
        return ['registered' => false, 'attributes' => []];
    }

    try {
        $registry = WP_Block_Type_Registry::get_instance();

        if (!is_object($registry) || !method_exists($registry, 'get_registered')) {
            return ['registered' => false, 'attributes' => []];
        }

        $type = $registry->get_registered($name);
    } catch (\Throwable $error) {
        return ['registered' => false, 'attributes' => []];
    }

    if (!is_object($type)) {
        return ['registered' => false, 'attributes' => []];
    }

    // Whatever the registry holds, passed through whole. No allow-list of
    // attribute names: an attribute this integration has never heard of is
    // exactly what a caller most needs to be told about, and filtering to a
    // known list would hide every attribute a later Stackable version adds.
    $attributes = [];

    if (method_exists($type, 'get_attributes')) {
        $attributes = (array) $type->get_attributes();
    } elseif (isset($type->attributes) && is_array($type->attributes)) {
        $attributes = $type->attributes;
    }

    return [
        'registered'       => true,
        'api_version'      => isset($type->api_version) ? (int) $type->api_version : null,
        'attributes'       => $attributes,
        'supports'         => isset($type->supports) && is_array($type->supports) ? $type->supports : [],
        'parent'           => isset($type->parent) && is_array($type->parent) ? $type->parent : [],
        'ancestor'         => isset($type->ancestor) && is_array($type->ancestor) ? $type->ancestor : [],
        'uses_context'     => isset($type->uses_context) && is_array($type->uses_context) ? $type->uses_context : [],
        'provides_context' => isset($type->provides_context) && is_array($type->provides_context) ? $type->provides_context : [],
    ];
}

/**
 * One block, the way this integration reports a block.
 *
 * @param array<string, mixed> $metadata
 * @param array<string, int> $states
 * @return array<string, mixed>
 */
function nibwp_stk_block_row(string $name, array $metadata, array $states, bool $with_attributes = false): array
{
    $registry = nibwp_stk_registry_info($name);
    $state = nibwp_stk_state_of($name, $states);

    $row = [
        'name'                  => $name,
        'title'                 => isset($metadata['title']) && is_scalar($metadata['title']) ? (string) $metadata['title'] : $name,
        'description'           => isset($metadata['description']) && is_scalar($metadata['description']) ? (string) $metadata['description'] : '',
        'category'              => isset($metadata['category']) && is_scalar($metadata['category']) ? (string) $metadata['category'] : '',
        'kind'                  => isset($metadata['stk-type']) && is_scalar($metadata['stk-type']) ? (string) $metadata['stk-type'] : '',
        'state'                 => $state,
        'stored_value'          => $states[$name] ?? null,
        'hidden_from_inserter'  => nibwp_stk_hidden_from_inserter($name, $states),
        'allowed_states'        => nibwp_stk_allowed_states($metadata),
        'registered_in_php'     => (bool) $registry['registered'],
        'parent'                => nibwp_stk_string_list($metadata['parent'] ?? $registry['parent'] ?? []),
        'uses_context'          => nibwp_stk_string_list($metadata['uses_context'] ?? $registry['uses_context'] ?? []),
        'provides_context'      => is_array($metadata['provides_context'] ?? null) ? $metadata['provides_context'] : ($registry['provides_context'] ?? []),
        'requires_blocks'       => nibwp_stk_string_list($metadata['stk-required-blocks'] ?? []),
        'substitutes_with'      => nibwp_stk_string_list($metadata['stk-substitution-blocks'] ?? []),
        'depends_on_variant'    => isset($metadata['stk-block-dependency']) && is_scalar($metadata['stk-block-dependency'])
            ? (string) $metadata['stk-block-dependency']
            : null,
        'variants'              => nibwp_stk_variant_names($name, $metadata),
        'attribute_count'       => count((array) $registry['attributes']),
    ];

    if ($with_attributes) {
        $row['attributes'] = $registry['attributes'];
        $row['attribute_schema_available'] = $registry['attributes'] !== [];
        $row['supports'] = $registry['supports'];
    }

    return $row;
}

/**
 * A list of strings out of whatever the metadata actually held.
 *
 * @param mixed $value
 * @return array<int, string>
 */
function nibwp_stk_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];

    foreach ($value as $item) {
        if (is_scalar($item)) {
            $out[] = (string) $item;
        }
    }

    return $out;
}

/**
 * The variants of one block, each with its own on/off state.
 *
 * A variant is keyed in the option as "block|variant"
 * (src/stk-block-types.php:83, :555 and the prefix scan in
 * dist/editor_blocks.js), so the three button variants can be switched off one
 * at a time without touching the block.
 *
 * @param array<string, mixed> $metadata
 * @return array<int, string>
 */
function nibwp_stk_variant_names(string $name, array $metadata): array
{
    $variants = $metadata['stk-variants'] ?? null;

    if (!is_array($variants)) {
        return [];
    }

    $out = [];

    foreach ($variants as $variant) {
        if (is_array($variant) && isset($variant['name']) && is_scalar($variant['name'])) {
            $out[] = $name . '|' . (string) $variant['name'];
        }
    }

    return $out;
}

/** Clamp a row count that arrived over the wire. */
function nibwp_stk_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_STK_MAX_ROWS, $limit));
}

/**
 * Validate a block name from input against this site's catalog.
 *
 * Checked against the catalog rather than against a pattern: "stackable/pricing"
 * looks perfectly well-formed and does not exist, and an action that accepted it
 * would report zero posts using it, which reads as an answer.
 *
 * @return string|WP_Error
 */
function nibwp_stk_block_name(mixed $raw, bool $allow_variant = false): string|WP_Error
{
    $name = is_string($raw) ? trim($raw) : '';

    if ($name === '') {
        return nibwp_stk_err('missing_block', __('Give the block name as block, for example stackable/heading.', 'nibwp'), 422);
    }

    $catalog = nibwp_stk_catalog();

    if (isset($catalog[$name])) {
        return $name;
    }

    if ($allow_variant && str_contains($name, '|')) {
        $base = substr($name, 0, (int) strpos($name, '|'));

        if (isset($catalog[$base]) && in_array($name, nibwp_stk_variant_names($base, $catalog[$base]), true)) {
            return $name;
        }
    }

    return nibwp_stk_err(
        'unknown_block',
        sprintf(
            /* translators: %s: the rejected block name */
            __('"%s" is not a block this Stackable version registers. Call list_blocks for the names it does.', 'nibwp'),
            $name
        ),
        422
    );
}

/**
 * Does this post's content really use this block?
 *
 * The database prefilter is a LIKE, which cannot tell stackable/icon-list from
 * stackable/icon-list-item, so every candidate is confirmed here with a boundary
 * that refuses a longer name. Getting this wrong would report the wrong block as
 * the one in use, which on the audit action means telling an owner to re-enable
 * something that was never the problem.
 */
function nibwp_stk_content_uses(string $content, string $block): int
{
    $pattern = '/<!--\s*wp:' . preg_quote($block, '/') . '(?![a-z0-9_\-])/i';
    $found = preg_match_all($pattern, $content);

    return is_int($found) ? $found : 0;
}

/**
 * Candidate post IDs whose content mentions the block.
 *
 * A LIKE over post_content rather than parse_blocks() over every post: parsing
 * the whole table to answer "which posts use this" is minutes of CPU on a real
 * site, and the LIKE narrows it to the handful that are then confirmed exactly.
 *
 * @return array<int, int>
 */
function nibwp_stk_candidate_ids(string $block, int $limit, int $offset): array
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'prepare')) {
        return [];
    }

    $like = '%' . $wpdb->esc_like('<!-- wp:' . $block) . '%';

    $sql = $wpdb->prepare(
        'SELECT ID FROM ' . $wpdb->posts
        . " WHERE post_status NOT IN ('trash', 'auto-draft')"
        . ' AND post_content LIKE %s ORDER BY ID DESC LIMIT %d OFFSET %d',
        $like,
        $limit,
        $offset
    );

    $ids = (array) $wpdb->get_col($sql);
    $out = [];

    foreach ($ids as $id) {
        if (is_numeric($id)) {
            $out[] = (int) $id;
        }
    }

    return $out;
}

/**
 * The posts using a block, with the ones this account may not edit removed.
 *
 * Removed AND COUNTED. A listing that silently drops rows reads as "nothing
 * else uses it", and on the audit action that is the difference between an owner
 * fixing one page and an owner thinking they are done.
 *
 * @return array<string, mixed>
 */
function nibwp_stk_posts_using(string $block, int $limit, int $offset): array
{
    $ids = nibwp_stk_candidate_ids($block, $limit, $offset);

    $rows = [];
    $withheld = 0;
    $not_confirmed = 0;

    foreach ($ids as $id) {
        if (!nibwp_stk_can_edit($id)) {
            $withheld++;
            continue;
        }

        $post = get_post($id);

        if (!is_object($post) || !isset($post->post_content)) {
            continue;
        }

        $uses = nibwp_stk_content_uses((string) $post->post_content, $block);

        if ($uses < 1) {
            // The LIKE matched a longer block name, or the string appears in
            // prose rather than in a block delimiter.
            $not_confirmed++;
            continue;
        }

        $rows[] = [
            'id'        => $id,
            'title'     => isset($post->post_title) ? (string) $post->post_title : '',
            'post_type' => isset($post->post_type) ? (string) $post->post_type : '',
            'status'    => isset($post->post_status) ? (string) $post->post_status : '',
            'uses'      => $uses,
            'edit_link' => function_exists('get_edit_post_link') ? (string) get_edit_post_link($id, 'raw') : '',
        ];
    }

    return [
        'posts'                   => $rows,
        'returned'                => count($rows),
        'withheld_not_editable'   => $withheld,
        'candidates_not_confirmed' => $not_confirmed,
    ];
}

/**
 * Read one site-wide option without pretending an absent option is a value.
 *
 * @return mixed
 */
function nibwp_stk_option(string $key, mixed $default = null): mixed
{
    return get_option($key, $default);
}

/**
 * The site-wide design settings this build has, as name => option key.
 *
 * Every one of these is registered by the plugin in the stackable_global_settings
 * group (src/global-settings.php:200-470 and the per-feature files under
 * src/plugins/global-settings/), so the list is the plugin's own and not a guess.
 *
 * @return array<string, string>
 */
function nibwp_stk_design_options(): array
{
    return [
        // src/global-settings.php
        'hide_theme_colors'            => 'stackable_global_hide_theme_colors',
        'hide_default_colors'          => 'stackable_global_hide_default_colors',
        'hide_site_editor_colors'      => 'stackable_global_hide_site_editor_colors',
        'typography_apply_to'          => 'stackable_global_typography_apply_to',
        'force_typography'             => 'stackable_global_force_typography',
        'selected_font_pair'           => 'stackable_selected_font_pair',
        'use_typography_as_presets'    => 'stackable_use_typography_as_presets',
        'apply_body_styles_to_html'    => 'stackable_is_apply_body_to_html',
        // src/plugins/global-settings/color-schemes/index.php
        'hide_color_scheme_colors'     => 'stackable_global_hide_color_scheme_colors',
        'base_color_scheme'            => 'stackable_global_base_color_scheme',
        'background_mode_color_scheme' => 'stackable_global_background_mode_color_scheme',
        'container_mode_color_scheme'  => 'stackable_global_container_mode_color_scheme',
        // src/plugins/global-settings/preset-controls/index.php:56
        'use_size_presets_by_default'  => 'stackable_use_size_presets_by_default',
        // src/plugins/theme-block-style-inheritance/index.php:32
        'disable_block_style_inheritance' => 'stackable_disable_block_style_inheritance',
        // src/icons.php:35
        'font_awesome_free_version'    => 'stackable_icons_fa_free_version',
    ];
}

/**
 * The editor-behaviour switches, as name => [option key, type].
 *
 * These are the stackable_editor_settings group (src/editor-settings.php:32-280).
 * They are separated from the design options above because these change how the
 * editor behaves rather than how the site looks, and because this is the
 * allow-list set_editor_setting writes through.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function nibwp_stk_editor_options(): array
{
    return [
        'design_library_button'            => ['stackable_enable_design_library', 'bool'],
        'optimize_inline_css'              => ['stackable_optimize_inline_css', 'bool'],
        'auto_collapse_panels'             => ['stackable_auto_collapse_panels', 'bool'],
        'global_settings_panel'            => ['stackable_enable_global_settings', 'bool'],
        'block_linking'                    => ['stackable_enable_block_linking', 'bool'],
        'text_highlight_button'            => ['stackable_enable_text_highlight', 'bool'],
        'dynamic_content_button'           => ['stackable_enable_dynamic_content', 'bool'],
        'copy_paste_styles_button'         => ['stackable_enable_copy_paste_styles', 'bool'],
        'reset_layout_button'              => ['stackable_enable_reset_layout', 'bool'],
        'save_as_default_block_button'     => ['stackable_enable_save_as_default_block', 'bool'],
        'stackable_text_is_default_block'  => ['stackable_enable_text_default_block', 'bool'],
        'heading_theme_margins_posts'      => ['stackable_enable_heading_default_theme_margins_posts', 'bool'],
        'heading_theme_margins_non_posts'  => ['stackable_enable_heading_default_theme_margins_non_posts', 'bool'],
        'columns_default_width'            => ['stackable_block_default_width', 'string'],
        'columns_wide_width'               => ['stackable_block_wide_width', 'string'],
        'icon_list_default_icon'           => ['stackable_icon_list_block_default_icon', 'string'],
    ];
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_stk_actions(): array
{
    return [
        // Reads — the blocks themselves.
        'overview',
        'list_blocks',
        'get_block',
        'block_attributes',
        'attribute_names_in_use',
        'list_variants',
        // Reads — what is switched on.
        'list_block_states',
        'get_block_state',
        'blocks_switched_off',
        // Reads — site-wide design.
        'global_colors',
        'global_typography',
        'design_settings',
        'editor_settings',
        'breakpoints',
        // Reads — what the content actually uses.
        'find_posts_using_block',
        'block_usage',
        'post_blocks',
        'audit_switched_off_in_use',
        // Writes — three, and no more.
        'set_block_state',
        'set_editor_setting',
        'set_breakpoints',
    ];
}

wp_register_ability('nibwp/stackable-manage', [
    'label' => __('Stackable – Blocks & Global Design', 'nibwp'),
    'description' => 'Read this site\'s Stackable setup before building with it: every block it registers with the attribute schema the registry actually holds, '
        . 'which blocks the owner has switched off or hidden from the inserter, the global colour palette and global typography, '
        . 'the site-wide design and editor settings, and which posts use a given block. '
        . 'Includes an audit that finds posts still using a block that is switched off — the saved markup keeps rendering on the front end but the editor can no longer open it, '
        . 'which is what a "my section disappeared" report usually is. '
        . 'Three write operations and no others: switch one block on, hidden or off; change one editor setting; set the responsive breakpoints. '
        . 'Nothing here rewrites the global colour palette or the global typography, and nothing touches post content.',
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_stk_actions(),
                'description' => 'Which operation to run. Start with overview.',
            ],
            'block' => ['type' => 'string', 'description' => 'A block name, for example stackable/heading. For set_block_state it may also be a variant, for example stackable/button-group|button.'],
            'state' => ['type' => 'string', 'description' => 'For set_block_state: enabled, hidden or disabled.'],
            'post_id' => ['type' => 'integer', 'description' => 'For post_blocks: the post to read.'],
            'setting' => ['type' => 'string', 'description' => 'For set_editor_setting: the setting name, from editor_settings.'],
            'value' => ['description' => 'For set_editor_setting: the new value.'],
            'tablet' => ['type' => 'string', 'description' => 'For set_breakpoints: the tablet breakpoint in pixels. Empty string clears it.'],
            'mobile' => ['type' => 'string', 'description' => 'For set_breakpoints: the mobile breakpoint in pixels. Empty string clears it.'],
            'include_attributes' => ['type' => 'boolean', 'description' => 'For list_blocks: include each block\'s attribute schema. Off by default, because it is a large response.'],
            'kind' => ['type' => 'string', 'description' => 'For list_blocks: essential, section, special or hidden.'],
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
    'execute_callback' => 'nibwp_stk_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Stackable blocks and global design, read-mostly.',
                '',
                'START WITH overview. It says which Stackable version this is, how many',
                'blocks are switched off or hidden, whether the global palette and global',
                'typography are configured, and whether any post is using a block that is',
                'switched off.',
                '',
                'BEFORE YOU BUILD WITH A STACKABLE BLOCK:',
                'Call list_blocks and check the state. A block that is "disabled" cannot be',
                'opened in the editor on this site, so building with it produces a page the',
                'owner cannot edit.',
                '',
                'ATTRIBUTE NAMES — read this once:',
                'Stackable 3.x declares its block attributes in the editor JavaScript, not in',
                'PHP, so the block registry holds NO attribute schema for a Stackable block.',
                'block_attributes tells you this honestly, with attribute_schema_available',
                'false. It does not mean the block takes no attributes. Use',
                'attribute_names_in_use, which reads the attribute names out of content this',
                'site has already saved, or copy an existing block from a page that works.',
                'Do not invent attribute names from the block title.',
                '',
                'THE ON/OFF STATE:',
                'enabled, hidden or disabled. Only the exceptions are stored, so a block not',
                'in the option is enabled. "hidden" keeps existing blocks working and only',
                'removes the block from the inserter. "disabled" also stops the editor',
                'registering the block, so a page that already uses it shows unrecognised',
                'content where the section was — the markup still renders on the front end,',
                'but an author re-saving that page can destroy it.',
                '',
                'WHEN SOMEBODY SAYS A SECTION DISAPPEARED:',
                'Run audit_switched_off_in_use before anything else. It lists the posts that',
                'use a block which is currently switched off, which is the usual cause, and it',
                'counts the posts the current account may not edit instead of hiding them.',
                '',
                'THE THREE WRITES:',
                '- set_block_state switches one block on, hidden or off. It changes the editor',
                '  for every author on the site, so say which block and which state back.',
                '- set_editor_setting changes one named editor setting.',
                '- set_breakpoints sets the tablet and mobile breakpoints.',
                '',
                'WHAT THIS CANNOT DO: it cannot rewrite the global colour palette or the',
                'global typography, and it cannot edit post content. Those stay in the editor.',
                'Saying so is the correct answer when asked.',
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
function nibwp_stk_manage(array $input): array|WP_Error
{
    $guard = nibwp_stk_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_stk_actions(), true)) {
        return nibwp_stk_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Stackable action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_stk_actions())
            )
        );
    }

    // Stackable's generated catalog and the block registry are both
    // version-sensitive and both reach code we do not control. One catch here
    // turns any of that into a named refusal rather than a fatal that takes the
    // whole REST request down.
    try {
        return nibwp_stk_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_stk_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from Stackable */
                __('Stackable could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_stk_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'overview' => nibwp_stk_overview(),
        'list_blocks' => nibwp_stk_list_blocks($input),
        'get_block' => nibwp_stk_get_block($input),
        'block_attributes' => nibwp_stk_block_attributes($input),
        'attribute_names_in_use' => nibwp_stk_attribute_names_in_use($input),
        'list_variants' => nibwp_stk_list_variants(),
        'list_block_states' => nibwp_stk_list_block_states(),
        'get_block_state' => nibwp_stk_get_block_state($input),
        'blocks_switched_off' => nibwp_stk_blocks_switched_off(),
        'global_colors' => nibwp_stk_global_colors(),
        'global_typography' => nibwp_stk_global_typography(),
        'design_settings' => nibwp_stk_design_settings(),
        'editor_settings' => nibwp_stk_editor_settings(),
        'breakpoints' => nibwp_stk_breakpoints(),
        'find_posts_using_block' => nibwp_stk_find_posts_using_block($input),
        'block_usage' => nibwp_stk_block_usage($input),
        'post_blocks' => nibwp_stk_post_blocks($input),
        'audit_switched_off_in_use' => nibwp_stk_audit_switched_off_in_use($input),
        'set_block_state' => nibwp_stk_set_block_state($input),
        'set_editor_setting' => nibwp_stk_set_editor_setting($input),
        'set_breakpoints' => nibwp_stk_set_breakpoints($input),
        default => nibwp_stk_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * The handful of things worth knowing before building anything.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_overview(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable overview', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();

    $counts = ['enabled' => 0, 'hidden' => 0, 'disabled' => 0, 'unrecognised' => 0];

    foreach (array_keys($catalog) as $name) {
        $state = nibwp_stk_state_of((string) $name, $states);
        $counts[$state] = ($counts[$state] ?? 0) + 1;
    }

    $colors = nibwp_stk_option('stackable_global_colors');
    $typography = nibwp_stk_option('stackable_global_typography');

    $act_on = [];

    if ($counts['disabled'] > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of disabled blocks */
            _n(
                '%d block is switched off. Run audit_switched_off_in_use before anything else: a post that still uses it cannot be opened in the editor.',
                '%d blocks are switched off. Run audit_switched_off_in_use before anything else: a post that still uses one of them cannot be opened in the editor.',
                $counts['disabled'],
                'nibwp'
            ),
            $counts['disabled']
        );
    }
    if ($counts['unrecognised'] > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of blocks with an unrecognised stored state */
            _n(
                '%d block has a stored state this Stackable version does not define, so what the editor does with it cannot be reported here.',
                '%d blocks have a stored state this Stackable version does not define, so what the editor does with them cannot be reported here.',
                $counts['unrecognised'],
                'nibwp'
            ),
            $counts['unrecognised']
        );
    }

    return [
        'plugin_version' => defined('STACKABLE_VERSION') ? (string) constant('STACKABLE_VERSION') : null,
        'build' => defined('STACKABLE_BUILD') ? (string) constant('STACKABLE_BUILD') : null,
        'blocks' => [
            'total' => count($catalog),
            'by_state' => $counts,
            'hidden_from_inserter' => count($states),
        ],
        'global_design' => [
            'palette_configured' => is_array($colors) && $colors !== [],
            'typography_configured' => is_array($typography) && $typography !== [],
            'font_pair' => (string) nibwp_stk_option('stackable_selected_font_pair', ''),
        ],
        'attribute_schema_in_registry' => false,
        'act_on' => $act_on,
        'note' => __('Stackable declares its block attributes in the editor JavaScript, so the block registry holds no attribute schema for a Stackable block on any site. Use attribute_names_in_use rather than guessing attribute names.', 'nibwp'),
    ];
}

/**
 * Every block Stackable registers here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_list_blocks(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Listing the Stackable blocks', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();
    $with_attributes = !empty($input['include_attributes']);

    $wanted = isset($input['kind']) ? strtolower(trim((string) $input['kind'])) : '';
    $kinds = ['essential', 'section', 'special', 'hidden'];

    if ($wanted !== '' && !in_array($wanted, $kinds, true)) {
        return nibwp_stk_err(
            'bad_kind',
            sprintf(
                /* translators: %s: list of valid kinds */
                __('kind must be one of: %s.', 'nibwp'),
                implode(', ', $kinds)
            ),
            422
        );
    }

    $limit = nibwp_stk_limit($input['limit'] ?? null, NIBWP_STK_MAX_ROWS);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $rows = [];
    $seen = 0;

    foreach ($catalog as $name => $metadata) {
        $row = nibwp_stk_block_row((string) $name, $metadata, $states, $with_attributes);

        if ($wanted !== '' && $row['kind'] !== $wanted) {
            continue;
        }

        $seen++;

        if ($seen <= $offset) {
            continue;
        }
        if (count($rows) >= $limit) {
            continue;
        }

        $rows[] = $row;
    }

    return [
        'total' => $seen,
        'returned' => count($rows),
        'blocks' => $rows,
        'attributes_included' => $with_attributes,
        'note' => $with_attributes
            ? __('An empty attributes array does not mean the block takes no attributes: Stackable declares them in the editor JavaScript, where PHP cannot read them. Use attribute_names_in_use.', 'nibwp')
            : __('Pass include_attributes to add each block\'s registry attribute schema. Read the note on block_attributes first — for Stackable it is empty by design.', 'nibwp'),
    ];
}

/**
 * One block in full.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_get_block(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading a Stackable block', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $name = nibwp_stk_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $catalog = nibwp_stk_catalog();
    $row = nibwp_stk_block_row($name, $catalog[$name], nibwp_stk_states(), true);

    $row['demo_url'] = isset($catalog[$name]['stk-demo']) && is_scalar($catalog[$name]['stk-demo'])
        ? (string) $catalog[$name]['stk-demo']
        : null;
    $row['keywords'] = nibwp_stk_string_list($catalog[$name]['keywords'] ?? []);

    return ['block' => $row];
}

/**
 * The attribute schema the registry holds, and nothing else.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_block_attributes(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading a Stackable block\'s attributes', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $name = nibwp_stk_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $registry = nibwp_stk_registry_info($name);
    $attributes = (array) $registry['attributes'];

    return [
        'block' => $name,
        'registered_in_php' => (bool) $registry['registered'],
        'attribute_schema_available' => $attributes !== [],
        'attribute_count' => count($attributes),
        'attributes' => $attributes,
        'supports' => $registry['supports'] ?? [],
        'source' => 'WP_Block_Type_Registry',
        'note' => $attributes === []
            ? __('The registry holds no attribute schema for this block, which is normal for Stackable: the plugin registers its blocks from a PHP array that declares no attributes, and every attribute is declared in the editor JavaScript instead. This is not a sign the block takes no attributes. Use attribute_names_in_use.', 'nibwp')
            : __('These come straight from the block registry, including any attribute this integration has no knowledge of.', 'nibwp'),
    ];
}

/**
 * The attribute names this site has actually saved for a block.
 *
 * The practical answer to "what are this block's attributes called" on a plugin
 * that keeps its schema in JavaScript: read real saved markup. Posts the account
 * cannot edit are skipped and counted, because a page's block attributes are its
 * content.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_attribute_names_in_use(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable attribute names in use', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $name = nibwp_stk_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    if (!function_exists('parse_blocks')) {
        return nibwp_stk_err('no_parser', __('This WordPress version does not expose parse_blocks(), so saved attribute names cannot be read.', 'nibwp'), 500);
    }

    $limit = nibwp_stk_limit($input['limit'] ?? null, 20);
    $ids = nibwp_stk_candidate_ids($name, $limit, isset($input['offset']) ? max(0, (int) $input['offset']) : 0);

    $names = [];
    $sampled = 0;
    $withheld = 0;

    foreach ($ids as $id) {
        if (!nibwp_stk_can_edit($id)) {
            $withheld++;
            continue;
        }

        $post = get_post($id);

        if (!is_object($post) || !isset($post->post_content)) {
            continue;
        }

        $sampled++;

        foreach (nibwp_stk_collect_attribute_names((array) parse_blocks((string) $post->post_content), $name) as $key) {
            $names[$key] = ($names[$key] ?? 0) + 1;
        }
    }

    ksort($names);

    $rows = [];

    foreach ($names as $key => $hits) {
        $rows[] = ['name' => (string) $key, 'posts' => (int) $hits];
    }

    return [
        'block' => $name,
        'sampled_posts' => $sampled,
        'withheld_not_editable' => $withheld,
        'attribute_names' => $rows,
        'source' => 'saved post content',
        'note' => $rows === []
            ? __('No saved instance of this block was found in content this account can edit, so no attribute names could be read. Add the block to a draft once and ask again, or copy an existing block from a page that works.', 'nibwp')
            : __('These are the attribute names present in saved markup on this site. A block only stores an attribute that differs from its default, so this is a floor and not the full schema.', 'nibwp'),
    ];
}

/**
 * Attribute names for one block name, anywhere in a parsed tree.
 *
 * @param array<int, mixed> $blocks
 * @return array<int, string>
 */
function nibwp_stk_collect_attribute_names(array $blocks, string $wanted): array
{
    $found = [];

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        if (($block['blockName'] ?? null) === $wanted && is_array($block['attrs'] ?? null)) {
            foreach (array_keys($block['attrs']) as $key) {
                if (is_string($key)) {
                    $found[] = $key;
                }
            }
        }

        if (is_array($block['innerBlocks'] ?? null)) {
            foreach (nibwp_stk_collect_attribute_names($block['innerBlocks'], $wanted) as $key) {
                $found[] = $key;
            }
        }
    }

    return array_values(array_unique($found));
}

/**
 * Every block variant, with its own state.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_list_variants(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Listing the Stackable block variants', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();
    $rows = [];

    foreach ($catalog as $name => $metadata) {
        $variants = $metadata['stk-variants'] ?? null;

        if (!is_array($variants)) {
            continue;
        }

        foreach ($variants as $variant) {
            if (!is_array($variant) || !isset($variant['name']) || !is_scalar($variant['name'])) {
                continue;
            }

            $key = (string) $name . '|' . (string) $variant['name'];

            $rows[] = [
                'key' => $key,
                'block' => (string) $name,
                'title' => isset($variant['title']) && is_scalar($variant['title']) ? (string) $variant['title'] : (string) $variant['name'],
                'kind' => isset($variant['stk-type']) && is_scalar($variant['stk-type']) ? (string) $variant['stk-type'] : '',
                'state' => nibwp_stk_state_of($key, $states),
                'requires_blocks' => nibwp_stk_string_list($variant['stk-required-blocks'] ?? []),
            ];
        }
    }

    return [
        'returned' => count($rows),
        'variants' => $rows,
        'note' => __('A variant is switched on and off under its own key, "block|variant", in the same option as the blocks.', 'nibwp'),
    ];
}

/**
 * Which blocks are switched on, hidden, or off.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_list_block_states(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable block states', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();

    $rows = [];

    foreach ($catalog as $name => $metadata) {
        $rows[] = [
            'name' => (string) $name,
            'state' => nibwp_stk_state_of((string) $name, $states),
            'stored_value' => $states[(string) $name] ?? null,
            'hidden_from_inserter' => nibwp_stk_hidden_from_inserter((string) $name, $states),
            'allowed_states' => nibwp_stk_allowed_states($metadata),
        ];
    }

    // Keys in the option that are not blocks in this catalog: variants, and
    // blocks a version that is no longer installed used to register. Reported
    // rather than dropped, because an orphan here is the reason an owner's "I
    // already turned that back on" does not match what the editor does.
    $extra = [];

    foreach ($states as $name => $value) {
        if (!isset($catalog[$name])) {
            $extra[] = [
                'name' => $name,
                'state' => nibwp_stk_state_of($name, $states),
                'stored_value' => $value,
                'is_variant' => str_contains($name, '|'),
            ];
        }
    }

    return [
        'option' => NIBWP_STK_STATES_OPTION,
        'absent_means' => 'enabled',
        'state_numbers' => nibwp_stk_state_values(),
        'stored_keys' => count($states),
        'blocks' => $rows,
        'keys_not_in_catalog' => $extra,
        'note' => __('Only the exceptions are stored. A block that does not appear in the option is enabled, which is why most sites store an empty option.', 'nibwp'),
    ];
}

/**
 * One block's state.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_get_block_state(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading a Stackable block state', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $name = nibwp_stk_block_name($input['block'] ?? null, true);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $states = nibwp_stk_states();
    $state = nibwp_stk_state_of($name, $states);

    return [
        'block' => $name,
        'state' => $state,
        'stored' => array_key_exists($name, $states),
        'stored_value' => $states[$name] ?? null,
        'hidden_from_inserter' => nibwp_stk_hidden_from_inserter($name, $states),
        'editor_registers_block' => $state !== 'disabled',
        'note' => $state === 'enabled' && !array_key_exists($name, $states)
            ? __('Not stored in the option at all, which is how an enabled block looks.', 'nibwp')
            : __('Stored in the option. Only the exceptions are stored, so a stored block is one somebody changed.', 'nibwp'),
    ];
}

/**
 * Just the blocks that are not plainly enabled.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_blocks_switched_off(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable blocks that are switched off', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();
    $rows = [];

    foreach ($states as $name => $value) {
        $state = nibwp_stk_state_of($name, $states);

        $rows[] = [
            'name' => $name,
            'state' => $state,
            'stored_value' => $value,
            'is_variant' => str_contains($name, '|'),
            'in_catalog' => isset($catalog[$name]),
            'editor_registers_block' => $state !== 'disabled',
        ];
    }

    return [
        'returned' => count($rows),
        'blocks' => $rows,
        'note' => $rows === []
            ? __('Nothing is switched off: the option is empty, which means every block Stackable registers is enabled.', 'nibwp')
            : __('Every key stored in the option, whatever its value — a stored key keeps the block out of the inserter in this version even when the value says enabled.', 'nibwp'),
    ];
}

/**
 * The global colour palette.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_global_colors(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable global colours', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    // The option is a list of palettes, each a list of colours with name, slug,
    // color and rgb — the frontend CSS walks exactly those two levels and skips
    // anything missing one of the three keys (src/global-settings.php:523-545).
    $raw = nibwp_stk_option('stackable_global_colors');
    $palettes = [];

    if (is_array($raw)) {
        foreach ($raw as $index => $palette) {
            if (!is_array($palette)) {
                continue;
            }

            $colors = [];

            foreach ($palette as $color) {
                if (!is_array($color)) {
                    continue;
                }

                $colors[] = [
                    'name' => isset($color['name']) && is_scalar($color['name']) ? (string) $color['name'] : '',
                    'slug' => isset($color['slug']) && is_scalar($color['slug']) ? (string) $color['slug'] : '',
                    'color' => isset($color['color']) && is_scalar($color['color']) ? (string) $color['color'] : '',
                    'rgb' => isset($color['rgb']) && is_scalar($color['rgb']) ? (string) $color['rgb'] : '',
                    'css_variable' => isset($color['slug']) && is_scalar($color['slug']) ? '--' . (string) $color['slug'] : '',
                ];
            }

            $palettes[] = ['index' => is_numeric($index) ? (int) $index : 0, 'colors' => $colors];
        }
    }

    return [
        'option' => 'stackable_global_colors',
        'palettes' => $palettes,
        'hide_theme_colors' => (bool) nibwp_stk_option('stackable_global_hide_theme_colors'),
        'hide_default_colors' => (bool) nibwp_stk_option('stackable_global_hide_default_colors'),
        'hide_site_editor_colors' => (bool) nibwp_stk_option('stackable_global_hide_site_editor_colors'),
        'note' => __('Each colour becomes a CSS custom property named after its slug, so a block can reference var(--slug) rather than a literal hex. Nothing here rewrites the palette.', 'nibwp'),
    ];
}

/**
 * The global typography.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_global_typography(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable global typography', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    // The option is a list and the plugin uses only the first entry
    // (src/global-settings.php:578-580, "We can have multiple entries in the
    // future, use the first one"). Reporting the whole list would describe
    // something the site does not apply.
    $raw = nibwp_stk_option('stackable_global_typography');
    $active = is_array($raw) && isset($raw[0]) && is_array($raw[0]) ? $raw[0] : [];

    $rows = [];

    foreach ($active as $tag => $styles) {
        if (!is_array($styles)) {
            continue;
        }

        $rows[] = [
            'selector' => (string) $tag,
            'font_family' => isset($styles['fontFamily']) && is_scalar($styles['fontFamily']) ? (string) $styles['fontFamily'] : null,
            'font_size' => isset($styles['fontSize']) && is_scalar($styles['fontSize']) ? (string) $styles['fontSize'] : null,
            'font_size_unit' => isset($styles['fontSizeUnit']) && is_scalar($styles['fontSizeUnit']) ? (string) $styles['fontSizeUnit'] : null,
            'font_weight' => isset($styles['fontWeight']) && is_scalar($styles['fontWeight']) ? (string) $styles['fontWeight'] : null,
            'text_transform' => isset($styles['textTransform']) && is_scalar($styles['textTransform']) ? (string) $styles['textTransform'] : null,
            'line_height' => isset($styles['lineHeight']) && is_scalar($styles['lineHeight']) ? (string) $styles['lineHeight'] : null,
            'letter_spacing' => isset($styles['letterSpacing']) && is_scalar($styles['letterSpacing']) ? (string) $styles['letterSpacing'] : null,
        ];
    }

    return [
        'option' => 'stackable_global_typography',
        'entries_stored' => is_array($raw) ? count($raw) : 0,
        'active_entry' => 0,
        'styles' => $rows,
        'apply_to' => (string) nibwp_stk_option('stackable_global_typography_apply_to', ''),
        'force_important' => (bool) nibwp_stk_option('stackable_global_force_typography'),
        'apply_body_styles_to_html' => (bool) nibwp_stk_option('stackable_is_apply_body_to_html'),
        'selected_font_pair' => (string) nibwp_stk_option('stackable_selected_font_pair', ''),
        'use_as_size_presets' => (bool) nibwp_stk_option('stackable_use_typography_as_presets'),
        'note' => __('Only the first stored entry is applied; the plugin keeps the list for future use. Nothing here rewrites the typography.', 'nibwp'),
    ];
}

/**
 * The site-wide design settings.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_design_settings(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable design settings', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $rows = [];

    foreach (nibwp_stk_design_options() as $label => $key) {
        $value = nibwp_stk_option($key);

        $rows[] = [
            'name' => $label,
            'option' => $key,
            'value' => is_scalar($value) ? $value : null,
            'is_set' => $value !== false && $value !== '' && $value !== null,
        ];
    }

    // These four hold nested structures rather than a scalar, so they are
    // reported as "configured, and how many" — dumping them whole would bury
    // the answer in a wall of generated CSS.
    $structured = [];

    foreach ([
        'spacing_and_borders' => 'stackable_global_spacing_and_borders',
        'buttons_and_icons' => 'stackable_global_buttons_and_icons',
        'color_schemes' => 'stackable_global_color_schemes',
        'block_styles' => 'stackable_global_block_styles',
        'custom_font_pairs' => 'stackable_custom_font_pairs',
        'icon_library' => 'stackable_icon_library',
    ] as $label => $key) {
        $value = nibwp_stk_option($key);

        $structured[] = [
            'name' => $label,
            'option' => $key,
            'configured' => is_array($value) && $value !== [],
            'entries' => is_array($value) ? count($value) : 0,
        ];
    }

    return [
        'settings' => $rows,
        'structured' => $structured,
        'note' => __('Reading these takes the same capability the plugin\'s own settings route takes. Changing one takes manage_options, because it changes the editor for every author.', 'nibwp'),
    ];
}

/**
 * The editor-behaviour switches.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_editor_settings(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable editor settings', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $rows = [];

    foreach (nibwp_stk_editor_options() as $label => $pair) {
        $value = nibwp_stk_option($pair[0]);

        $rows[] = [
            'name' => $label,
            'option' => $pair[0],
            'type' => $pair[1],
            'value' => $pair[1] === 'bool' ? (bool) $value : (is_scalar($value) ? (string) $value : ''),
            'writable' => true,
        ];
    }

    return [
        'settings' => $rows,
        'note' => __('set_editor_setting writes any of these by the name in the name field. Anything not in this list is not writable from here.', 'nibwp'),
    ];
}

/**
 * The responsive breakpoints.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_breakpoints(): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading the Stackable breakpoints', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $saved = nibwp_stk_option('stackable_dynamic_breakpoints');
    $saved = is_array($saved) ? $saved : [];

    $tablet = isset($saved['tablet']) && is_scalar($saved['tablet']) ? (string) $saved['tablet'] : '';
    $mobile = isset($saved['mobile']) && is_scalar($saved['mobile']) ? (string) $saved['mobile'] : '';

    return [
        'option' => 'stackable_dynamic_breakpoints',
        'tablet' => $tablet,
        'mobile' => $mobile,
        // The plugin's own placeholders, which are what the site uses when the
        // option is empty (src/dynamic-breakpoints.php:93-97).
        'defaults' => ['tablet' => '1024', 'mobile' => '768'],
        'customised' => $tablet !== '' || $mobile !== '',
        'note' => __('An empty value means the default is in force rather than zero.', 'nibwp'),
    ];
}

/**
 * Which posts use a given block.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_find_posts_using_block(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Finding the posts that use a Stackable block', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $name = nibwp_stk_block_name($input['block'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $limit = nibwp_stk_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $found = nibwp_stk_posts_using($name, $limit, $offset);
    $states = nibwp_stk_states();

    return $found + [
        'block' => $name,
        'state' => nibwp_stk_state_of($name, $states),
        'note' => $found['withheld_not_editable'] > 0
            ? __('Some posts using this block were left out because this account cannot edit them. The count above is how many, so an answer based on this list is not mistaken for the whole site.', 'nibwp')
            : __('Posts are matched on the saved block delimiter, so a mention of the block name in prose does not count.', 'nibwp'),
    ];
}

/**
 * How much each block is used, across the blocks that are not plainly enabled
 * plus any block asked about by name.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_block_usage(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Counting Stackable block usage', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();

    // One block if asked, otherwise the ones whose state somebody changed. Not
    // the whole catalog: fifty LIKE scans over post_content in one request is a
    // way to take a site down, and the blocks an owner is asking about are the
    // ones that are switched off.
    $names = [];

    if (isset($input['block'])) {
        $one = nibwp_stk_block_name($input['block']);
        if ($one instanceof WP_Error) {
            return $one;
        }
        $names = [$one];
    } else {
        foreach (array_keys($states) as $name) {
            if (isset($catalog[$name])) {
                $names[] = $name;
            }
        }
    }

    $limit = nibwp_stk_limit($input['limit'] ?? null);
    $rows = [];
    $withheld = 0;

    foreach ($names as $name) {
        $found = nibwp_stk_posts_using($name, $limit, 0);
        $withheld += (int) $found['withheld_not_editable'];

        $rows[] = [
            'block' => $name,
            'state' => nibwp_stk_state_of($name, $states),
            'posts' => (int) $found['returned'],
            'withheld_not_editable' => (int) $found['withheld_not_editable'],
        ];
    }

    return [
        'returned' => count($rows),
        'usage' => $rows,
        'withheld_not_editable' => $withheld,
        'scope' => isset($input['block']) ? 'one_block' : 'blocks_with_a_stored_state',
        'note' => isset($input['block'])
            ? __('Counts posts whose saved content uses this block, excluding any this account cannot edit.', 'nibwp')
            : __('Counts only the blocks whose state somebody changed, which is the question worth asking, and never scans the whole catalog in one request. Pass block to count one.', 'nibwp'),
    ];
}

/**
 * Which Stackable blocks one post uses, with the attribute names it saved.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_post_blocks(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Reading a post\'s Stackable blocks', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

    if ($post_id < 1) {
        return nibwp_stk_err('missing_post', __('Give the post as post_id.', 'nibwp'), 422);
    }

    // The per-post check comes before the lookup, so the refusal is the same
    // whether the post exists or not and cannot be used to enumerate drafts.
    if (!nibwp_stk_can_edit($post_id)) {
        return nibwp_stk_forbidden(__('Reading that post\'s Stackable blocks', 'nibwp'), 'edit_post');
    }

    $post = get_post($post_id);

    if (!is_object($post) || !isset($post->post_content)) {
        return nibwp_stk_err('post_not_found', __('No post with that id.', 'nibwp'), 404);
    }

    if (!function_exists('parse_blocks')) {
        return nibwp_stk_err('no_parser', __('This WordPress version does not expose parse_blocks().', 'nibwp'), 500);
    }

    $states = nibwp_stk_states();
    $used = nibwp_stk_walk_post_blocks((array) parse_blocks((string) $post->post_content));

    $rows = [];

    foreach ($used as $name => $info) {
        $state = nibwp_stk_state_of((string) $name, $states);

        $rows[] = [
            'block' => (string) $name,
            'count' => (int) $info['count'],
            'state' => $state,
            'editor_registers_block' => $state !== 'disabled',
            'attribute_names' => $info['attrs'],
        ];
    }

    $broken = [];

    foreach ($rows as $row) {
        if ($row['state'] === 'disabled') {
            $broken[] = $row['block'];
        }
    }

    return [
        'post_id' => $post_id,
        'title' => isset($post->post_title) ? (string) $post->post_title : '',
        'returned' => count($rows),
        'blocks' => $rows,
        'blocks_the_editor_will_not_open' => $broken,
        'note' => $broken === []
            ? __('Every Stackable block in this post is one the editor will register.', 'nibwp')
            : __('The listed blocks are switched off, so the editor will not register them: this post shows unrecognised content where those sections are, and re-saving it there can destroy them. Switch the block back on before anybody edits the page.', 'nibwp'),
    ];
}

/**
 * Stackable block names in a parsed tree, with counts and attribute names.
 *
 * @param array<int, mixed> $blocks
 * @param array<string, array{count: int, attrs: array<int, string>}> $found
 * @return array<string, array{count: int, attrs: array<int, string>}>
 */
function nibwp_stk_walk_post_blocks(array $blocks, array $found = []): array
{
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        $name = $block['blockName'] ?? null;

        if (is_string($name) && str_starts_with($name, 'stackable/')) {
            if (!isset($found[$name])) {
                $found[$name] = ['count' => 0, 'attrs' => []];
            }

            $found[$name]['count']++;

            foreach (array_keys(is_array($block['attrs'] ?? null) ? $block['attrs'] : []) as $key) {
                if (is_string($key) && !in_array($key, $found[$name]['attrs'], true)) {
                    $found[$name]['attrs'][] = $key;
                }
            }
        }

        if (is_array($block['innerBlocks'] ?? null)) {
            $found = nibwp_stk_walk_post_blocks($block['innerBlocks'], $found);
        }
    }

    return $found;
}

/**
 * Posts using a block that is currently switched off.
 *
 * The commonest "my section disappeared" report. The markup is still in the
 * post and still renders on the front end — PHP registration ignores the option
 * entirely (src/blocks.php:21-40) — but the editor skips registerBlockType for a
 * disabled block, so an author opening the page sees unrecognised content where
 * their section was, and a re-save from there is permanent.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_audit_switched_off_in_use(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Auditing the Stackable blocks that are switched off', 'nibwp'), NIBWP_STK_READ_CAP)) {
        return $denied;
    }

    $catalog = nibwp_stk_catalog();
    $states = nibwp_stk_states();
    $limit = nibwp_stk_limit($input['limit'] ?? null);

    $findings = [];
    $withheld = 0;
    $checked = 0;

    foreach ($states as $name => $value) {
        $state = nibwp_stk_state_of($name, $states);

        if ($state === 'enabled') {
            continue;
        }

        // Variant keys are not block names, so no saved markup carries them;
        // the block they belong to is checked under its own key.
        if (str_contains($name, '|')) {
            continue;
        }

        $checked++;
        $found = nibwp_stk_posts_using($name, $limit, 0);
        $withheld += (int) $found['withheld_not_editable'];

        if ($found['returned'] < 1 && $found['withheld_not_editable'] < 1) {
            continue;
        }

        $findings[] = [
            'block' => $name,
            'state' => $state,
            'in_catalog' => isset($catalog[$name]),
            'severity' => $state === 'disabled' ? 'breaks_the_editor' : 'inserter_only',
            'posts' => $found['posts'],
            'posts_found' => (int) $found['returned'],
            'withheld_not_editable' => (int) $found['withheld_not_editable'],
            'what_happens' => $state === 'disabled'
                ? __('The editor will not register this block, so these posts show unrecognised content where the block was. The front end still renders the saved markup.', 'nibwp')
                : __('The block is only kept out of the inserter. These posts keep working and stay editable.', 'nibwp'),
        ];
    }

    $urgent = 0;

    foreach ($findings as $finding) {
        if ($finding['severity'] === 'breaks_the_editor') {
            $urgent++;
        }
    }

    return [
        'blocks_checked' => $checked,
        'findings' => $findings,
        'blocks_breaking_the_editor' => $urgent,
        'withheld_not_editable' => $withheld,
        'looks_reasonable' => $findings === [],
        'note' => $findings === []
            ? __('No post that this account can see uses a block that is switched off. If an author still reports a missing section, check that the account running this can edit the page in question — withheld_not_editable says how many were skipped.', 'nibwp')
            : __('Switch the block back on with set_block_state before anybody re-saves one of these pages. Re-saving a page whose block the editor cannot register is what turns a recoverable problem into lost content.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Switch one block on, hidden, or off.
 *
 * ENABLING UNSETS THE KEY rather than writing 1. Two reasons, and the second is
 * the binding one. First, the plugin itself stores only the exceptions
 * (src/editor-settings.php:48-49), so a stored 1 is a shape the plugin never
 * writes. Second, the editor computes inserter visibility from
 * `!states[name] === BLOCK_STATE.HIDDEN` (dist/editor_blocks.js), which in
 * JavaScript compares a boolean against the number 2 and is therefore false for
 * every key that is present — so a block stored as 1/enabled stays out of the
 * inserter. Writing 1 would report success and leave the block missing, which is
 * the worst of the available outcomes.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_set_block_state(array $input): array|WP_Error
{
    // manage_options, not edit_posts: this changes the editor for every author
    // on the site, and switching a block off can make a published page
    // uneditable for people who had nothing to do with the request.
    if ($denied = nibwp_stk_require(__('Switching a Stackable block on or off', 'nibwp'), NIBWP_STK_WRITE_CAP)) {
        return $denied;
    }

    $name = nibwp_stk_block_name($input['block'] ?? null, true);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $state = isset($input['state']) ? strtolower(trim((string) $input['state'])) : '';
    $values = nibwp_stk_state_values();

    if (!isset($values[$state])) {
        return nibwp_stk_err(
            'bad_state',
            sprintf(
                /* translators: %s: list of valid states */
                __('state must be one of: %s.', 'nibwp'),
                implode(', ', array_keys($values))
            ),
            422
        );
    }

    $catalog = nibwp_stk_catalog();
    $base = str_contains($name, '|') ? substr($name, 0, (int) strpos($name, '|')) : $name;
    $allowed = isset($catalog[$base]) ? nibwp_stk_allowed_states($catalog[$base]) : array_keys($values);

    if (!in_array($state, $allowed, true)) {
        return nibwp_stk_err(
            'state_not_allowed',
            sprintf(
                /* translators: 1: block name, 2: requested state, 3: list of allowed states */
                __('Stackable does not allow "%1$s" to be set to %2$s; it allows: %3$s. Other blocks are built out of this one, so switching it off would strand layouts that are already saved.', 'nibwp'),
                $name,
                $state,
                implode(', ', $allowed)
            ),
            422
        );
    }

    $before = nibwp_stk_states();
    $previous = nibwp_stk_state_of($name, $before);

    // Read the raw option rather than the normalised copy, so a key this
    // integration could not parse is carried through untouched instead of being
    // quietly deleted by a write that was about one other block.
    $raw = get_option(NIBWP_STK_STATES_OPTION, []);
    $raw = is_array($raw) ? $raw : [];

    if ($state === 'enabled') {
        unset($raw[$name]);
    } else {
        $raw[$name] = $values[$state];
    }

    update_option(NIBWP_STK_STATES_OPTION, $raw);

    $after = nibwp_stk_states();

    return [
        'block' => $name,
        'state' => nibwp_stk_state_of($name, $after),
        'previous_state' => $previous,
        'stored' => array_key_exists($name, $after),
        'key_removed' => $state === 'enabled',
        'keys_stored' => count($after),
        'note' => $state === 'enabled'
            ? __('The key was removed, which is how this plugin stores an enabled block. Writing the number for "enabled" would have left the block out of the inserter in this version.', 'nibwp')
            : __('This changes the editor for every author on the site. If any page already uses the block, run audit_switched_off_in_use and tell the owner which pages are affected.', 'nibwp'),
    ];
}

/**
 * Change one editor setting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_set_editor_setting(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Changing a Stackable editor setting', 'nibwp'), NIBWP_STK_WRITE_CAP)) {
        return $denied;
    }

    $setting = isset($input['setting']) ? trim((string) $input['setting']) : '';
    $allowed = nibwp_stk_editor_options();

    if (!isset($allowed[$setting])) {
        return nibwp_stk_err(
            'unknown_setting',
            sprintf(
                /* translators: 1: the rejected setting name, 2: list of writable settings */
                __('"%1$s" is not a setting this integration writes. Writable: %2$s.', 'nibwp'),
                $setting,
                implode(', ', array_keys($allowed))
            ),
            422
        );
    }

    if (!array_key_exists('value', $input)) {
        return nibwp_stk_err('missing_value', __('Give the new value as value.', 'nibwp'), 422);
    }

    [$option, $type] = $allowed[$setting];
    $before = nibwp_stk_option($option);

    if ($type === 'bool') {
        // Stackable stores its booleans as '' for false and '1' for true, which
        // is what register_setting's sanitize_text_field leaves behind
        // (src/editor-settings.php:80-88). Writing a PHP false would store '' as
        // well, but writing the strings keeps the option byte-identical to what
        // the plugin's own settings screen writes.
        $value = nibwp_stk_truthy($input['value']) ? '1' : '';
    } else {
        $value = sanitize_text_field((string) (is_scalar($input['value']) ? $input['value'] : ''));
    }

    update_option($option, $value);

    return [
        'setting' => $setting,
        'option' => $option,
        'value' => $type === 'bool' ? (bool) $value : (string) $value,
        'previous' => $type === 'bool' ? (bool) $before : (is_scalar($before) ? (string) $before : ''),
        'note' => __('This changes the editor for every author on the site.', 'nibwp'),
    ];
}

/** Whether a value that arrived over the wire means yes. */
function nibwp_stk_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value !== 0;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

/**
 * Set the responsive breakpoints.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_stk_set_breakpoints(array $input): array|WP_Error
{
    if ($denied = nibwp_stk_require(__('Changing the Stackable breakpoints', 'nibwp'), NIBWP_STK_WRITE_CAP)) {
        return $denied;
    }

    if (!array_key_exists('tablet', $input) && !array_key_exists('mobile', $input)) {
        return nibwp_stk_err('missing_breakpoint', __('Give tablet, mobile, or both. An empty string clears one back to the default.', 'nibwp'), 422);
    }

    $raw = get_option('stackable_dynamic_breakpoints', []);
    $raw = is_array($raw) ? $raw : [];

    foreach (['tablet', 'mobile'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        $value = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';

        if ($value !== '' && (!is_numeric($value) || (int) $value < 1)) {
            return nibwp_stk_err(
                'bad_breakpoint',
                sprintf(
                    /* translators: 1: tablet or mobile, 2: the rejected value */
                    __('%1$s must be a width in pixels or an empty string, not "%2$s".', 'nibwp'),
                    $key,
                    $value
                ),
                422
            );
        }

        $raw[$key] = $value === '' ? '' : (string) (int) $value;
    }

    update_option('stackable_dynamic_breakpoints', $raw);

    return [
        'tablet' => isset($raw['tablet']) && is_scalar($raw['tablet']) ? (string) $raw['tablet'] : '',
        'mobile' => isset($raw['mobile']) && is_scalar($raw['mobile']) ? (string) $raw['mobile'] : '',
        'note' => __('Every Stackable block on the site switches to its tablet or mobile styles at these widths. An empty value restores the default rather than setting zero.', 'nibwp'),
    ];
}
