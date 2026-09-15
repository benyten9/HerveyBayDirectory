<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// ---------------------------------------------------------------------------
// Ability: nibwp/bricks-create-template
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/bricks-create-template', [
    'label' => __('Bricks – Create / Update Template', domain: 'nibwp'),
    'description' => __(
        'Creates or updates a Bricks builder template (header, footer, content, section, or archive) with structured element data.',
        domain: 'nibwp',
    ),
    'category' => 'bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => [
                'type' => 'string',
                'description' => 'Template title.',
                'minLength' => 1,
            ],
            'template_type' => [
                'type' => 'string',
                'description' => 'The template type.',
                'enum' => ['header', 'footer', 'content', 'section', 'archive'],
                'default' => 'content',
            ],
            'template_id' => [
                'type' => 'integer',
                'description' => 'Existing template post ID to update. Omit to create a new template.',
            ],
            'elements' => [
                'type' => 'array',
                'description' => 'Array of Bricks element structures.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Element tag name, e.g. "section", "div", "heading", "text-basic".'],
                        'label' => ['type' => 'string', 'description' => 'Label shown in the Bricks structure panel.'],
                        'settings' => ['type' => 'object', 'description' => 'Element settings (content, styling, etc.).'],
                        'children' => ['type' => 'array', 'description' => 'Nested child element IDs.'],
                        'parent' => ['type' => 'integer', 'description' => 'Parent element ID (0 for root).'],
                    ],
                ],
            ],
            'conditions' => [
                'type' => 'array',
                'description' => 'Template display conditions. Each item is an object with condition rules.',
                'items' => [
                    'type' => 'object',
                ],
            ],
        ],
        'required' => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'template_id' => ['type' => 'integer', 'description' => 'The template post ID.'],
            'edit_url' => ['type' => 'string', 'description' => 'URL to edit the template in Bricks.'],
        ],
    ],
    'execute_callback' => 'nibwp_bricks_create_template',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Creates or updates a Bricks builder template with structured element data.',
                '',
                'TEMPLATE TYPES:',
                '- "header" — site header template.',
                '- "footer" — site footer template.',
                '- "content" — page/post content template (default).',
                '- "section" — reusable section template.',
                '- "archive" — archive listing template.',
                '',
                'ELEMENT STRUCTURE:',
                'Bricks uses a flat array of elements with parent/children references.',
                'Each element needs: name, settings. Optional: label, parent, children.',
                'IDs are auto-generated if missing.',
                '',
                'Example elements array:',
                '  [',
                '    { "name": "section", "settings": {}, "children": [] },',
                '    { "name": "heading", "settings": { "tag": "h1", "text": "Hello" }, "parent": 0 }',
                '  ]',
                '',
                'Parent/children references use the element\'s index in the array (0-based).',
                'The callback auto-generates proper Bricks IDs and wires parent/children links.',
                '',
                'CONDITIONS:',
                'Optional display conditions control where the template appears.',
                'Example: [{ "main": "post_type", "sub": "page", "compare": "==" }]',
                '',
                'UPDATING:',
                '- Pass template_id to update an existing template.',
                '- Omit template_id to create a new one.',
                '',
                'IMPORTANT:',
                '- Bricks must be active (BRICKS_VERSION defined).',
                '- Data is stored in _bricks_page_content_2 post meta.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Normalise Bricks elements: ensure IDs exist and parent/children references are consistent.
 *
 * @param array $elements Raw elements array.
 * @return array Normalised elements with proper IDs and references.
 */
function nibwp_bricks_normalise_elements(array $elements): array
{
    // First pass: assign IDs.
    $id_map = [];
    foreach ($elements as $index => &$el) {
        $generated_id = bin2hex(random_bytes(3));
        $id_map[$index] = $generated_id;
        $el['id'] = $generated_id;

        if (!isset($el['settings'])) {
            $el['settings'] = [];
        }
        if (!isset($el['name'])) {
            $el['name'] = 'div';
        }
    }
    unset($el);

    // Second pass: resolve parent/children references (index-based to ID-based).
    foreach ($elements as &$el) {
        if (isset($el['parent']) && is_int($el['parent']) && isset($id_map[$el['parent']])) {
            $el['parent'] = $id_map[$el['parent']];
        } elseif (isset($el['parent']) && is_int($el['parent'])) {
            $el['parent'] = 0;
        }

        if (!empty($el['children']) && is_array($el['children'])) {
            $el['children'] = array_map(
                fn($child_index) => is_int($child_index) && isset($id_map[$child_index])
                    ? $id_map[$child_index]
                    : $child_index,
                $el['children'],
            );
        } else {
            $el['children'] = [];
        }
    }
    unset($el);

    return $elements;
}

/**
 * Which meta key holds this template type's element tree.
 *
 * Bricks stores header and footer templates in their own keys and everything
 * else in the content key. The distinction is invisible from the post type -
 * a footer is a bricks_template like any other - which is why writing them
 * all to the content key failed silently rather than erroring.
 */
function nibwp_bricks_content_meta_key(string $template_type): string
{
    return match ($template_type) {
        'header' => '_bricks_page_header_2',
        'footer' => '_bricks_page_footer_2',
        default  => '_bricks_page_content_2',
    };
}

/**
 * Create or update a Bricks template.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_bricks_create_template(array $input): array|WP_Error
{
    if (!defined('BRICKS_VERSION')) {
        return new WP_Error(
            'bricks_not_active',
            __('Bricks is not active on this site.', domain: 'nibwp'),
        );
    }

    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        return new WP_Error('missing_title', __('title is required.', domain: 'nibwp'));
    }

    // Nothing to build is a failure, and it has to be said before the template
    // exists. The element write below is guarded by `if (!empty($elements))`,
    // so an empty tree used to create the template, set its type, write no
    // content and return success — a caller then reported a finished build over
    // a blank page. Same fault the EtchWP persister had; a customer hit both.
    if (empty($input['elements']) || !is_array($input['elements'])) {
        return new WP_Error(
            'bricks_no_elements',
            __('elements is missing or empty: there is nothing to build. Nothing was created or changed.', domain: 'nibwp')
        );
    }

    $template_type = (string) ($input['template_type'] ?? 'content');
    // Kept in step with NIBWP_BRICKS_TEMPLATE_TYPES in the bricks-pro
    // validator. They disagreed: the validator accepted `error` and `popup`,
    // this list did not, so a payload could pass a dry run and then be
    // refused at commit.
    $allowed_types = ['header', 'footer', 'content', 'section', 'archive', 'error', 'popup'];
    if (!in_array($template_type, $allowed_types, true)) {
        return new WP_Error(
            'invalid_template_type',
            sprintf(__('template_type must be one of: %s', domain: 'nibwp'), implode(', ', $allowed_types)),
        );
    }

    $elements = (array) ($input['elements'] ?? []);
    $conditions = $input['conditions'] ?? null;
    $template_id = isset($input['template_id']) ? (int) $input['template_id'] : 0;

    // Update or create.
    $is_update = $template_id > 0;
    if ($is_update) {
        $existing = get_post($template_id);
        if (!$existing || $existing->post_type !== 'bricks_template') {
            return new WP_Error(
                'template_not_found',
                sprintf(__('Bricks template %d not found.', domain: 'nibwp'), $template_id),
            );
        }
        wp_update_post([
            'ID' => $template_id,
            'post_title' => $title,
        ]);
    } else {
        $template_id = wp_insert_post([
            'post_title' => $title,
            'post_type' => 'bricks_template',
            'post_status' => 'publish',
            'post_content' => '',
        ], true);

        if (is_wp_error($template_id)) {
            return $template_id;
        }
    }

    // Bricks keeps a template's tree in a different meta key per template
    // type. Writing everything to the content key put a second, orphaned tree
    // beside a header or footer template's real one, reported success, and
    // left the template rendering exactly what it rendered before — while
    // _bricks_template_type was overwritten on the way past, changing what
    // Bricks thought the template was. A customer found this on a live footer.
    $meta_key = nibwp_bricks_content_meta_key($template_type);

    // On an existing template, the tree already on disk decides the key. An
    // explicit template_type may legitimately convert one, but a caller who
    // did not name a type must never silently move a footer's content.
    if ($is_update && !isset($input['template_type'])) {
        $stored_type = (string) get_post_meta($template_id, '_bricks_template_type', true);
        if ($stored_type !== '') {
            $template_type = $stored_type;
            $meta_key      = nibwp_bricks_content_meta_key($stored_type);
        }
    }

    // Set template type meta.
    update_post_meta($template_id, '_bricks_template_type', $template_type);

    // Store elements.
    if (!empty($elements)) {
        $normalised = nibwp_bricks_normalise_elements($elements);
        // update_metadata() unslashes what it is given, so a backslash inside
        // an element setting - a CSS content:"C", an escaped quote - is
        // stripped on the way in unless it is slashed first.
        update_post_meta($template_id, $meta_key, wp_slash($normalised));
    }

    // Store conditions if provided.
    if (is_array($conditions)) {
        update_post_meta($template_id, '_bricks_template_conditions', wp_slash($conditions));
    }

    // Report what actually landed in the meta, not what was asked for. A
    // caller that only knows the template id cannot tell a built page from an
    // empty one, and every summary written from this return said "persisted".
    nibwp_bricks_regenerate_css($template_id, $meta_key);

    // Read back the key actually written, or the count describes a tree the
    // template does not render from.
    $stored = get_post_meta($template_id, $meta_key, true);

    return [
        'template_id'    => $template_id,
        'edit_url'       => add_query_arg(['bricks' => 'run'], get_permalink($template_id)),
        'elements_saved' => is_array($stored) ? count($stored) : 0,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/bricks-update-element
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/bricks-update-element', [
    'label' => __('Bricks – Update One Element', domain: 'nibwp'),
    'description' => __(
        'Changes settings on a single existing Bricks element, on a page or a template, leaving the rest of the tree untouched.',
        domain: 'nibwp',
    ),
    'category' => 'bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post holding the element — a page, a post, or a bricks_template.',
            ],
            'element_id' => [
                'type' => 'string',
                'description' => 'The element\'s Bricks id, e.g. "wqlhxj". Element ids are only unique WITHIN a post, so post_id and element_id are both required and are matched together.',
            ],
            'settings_patch' => [
                'type' => 'object',
                'description' => 'Settings to merge into the element. Keys not named here keep their current value. Nested objects merge recursively; a null value deletes that setting.',
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Default true. Returns the before/after diff and the validation result without writing.',
                'default' => true,
            ],
        ],
        'required' => ['post_id', 'element_id', 'settings_patch'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'written'    => ['type' => 'boolean', 'description' => 'False on a dry run.'],
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'meta_key'   => ['type' => 'string', 'description' => 'The meta key the tree actually lives in.'],
            'before'     => ['type' => 'object', 'description' => 'The element as it is now.'],
            'after'      => ['type' => 'object', 'description' => 'The element as it would be, or now is.'],
            'changed'    => ['type' => 'array', 'description' => 'Setting keys this patch alters.', 'items' => ['type' => 'string']],
            'validation' => ['type' => 'object', 'description' => 'Validator verdict for the patched element.'],
        ],
    ],
    'execute_callback' => 'nibwp_bricks_update_element',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Edits ONE element in place. Use this for small, surgical changes — adding a link, fixing a URL,',
                'correcting a typo, changing a class — on a page or a template.',
                '',
                'Prefer this over bricks-pro-html-to-component for small edits. That ability replaces a whole',
                'template tree, which is the wrong instrument for a one-setting change and loses anything the',
                'payload does not restate.',
                '',
                'FINDING THE ELEMENT:',
                'Read the tree first (nibwp/wp-get-post-meta on _bricks_page_content_2, or _bricks_page_header_2 /',
                '_bricks_page_footer_2 for header and footer templates) and take the `id` of the element you want.',
                'Element ids are NOT unique across posts — the same id can name a different element on another',
                'page — so always pass the post_id you read the tree from.',
                '',
                'THE PATCH:',
                'settings_patch merges into the element\'s existing settings. Only the keys you name change.',
                'Nested objects merge recursively, so you can set settings_patch = {"_typography":{"font-size":"18px"}}',
                'without restating the rest of _typography. A null value deletes that key.',
                '',
                'ALWAYS run with dry_run=true first, read the `before`/`after` diff, then re-run with dry_run=false.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Rebuild the post's CSS file after a write that bypassed Bricks' own save.
 *
 * Bricks renders styles inline by default, in which case a change shows up on
 * the next request and there is nothing to do. On installs with "CSS loading
 * method" set to External Files the stylesheet is a file on disk, regenerated
 * when the builder saves — which a direct meta write never triggers, so the
 * edit lands in the database and the page keeps serving the old CSS.
 *
 * Bricks' own generator returns immediately unless that setting is on, so this
 * is safe to call either way and needs no setting check of its own.
 */
function nibwp_bricks_regenerate_css(int $post_id, string $meta_key): void
{
    if (!class_exists('\Bricks\Assets_Files') || !method_exists('\Bricks\Assets_Files', 'generate_post_css_file')) {
        return;
    }

    $area = match ($meta_key) {
        '_bricks_page_header_2' => 'header',
        '_bricks_page_footer_2' => 'footer',
        default                 => 'content',
    };

    $elements = get_post_meta($post_id, $meta_key, true);
    if (!is_array($elements) || $elements === []) {
        return;
    }

    \Bricks\Assets_Files::generate_post_css_file($post_id, $area, $elements);
}

/**
 * Change settings on one element, in place.
 *
 * The tree-replacing abilities were the only way to change a Bricks page, and
 * for a one-setting edit that is the wrong instrument: it rewrites everything
 * and loses whatever the caller did not restate. What actually happened is
 * that agents reached for raw execute-php on the meta instead, which skips
 * every guardrail the validator provides. This is the small tool that was
 * missing.
 *
 * Two things it deliberately does not assume:
 *
 *   - which meta key holds the tree. A header or footer template keeps its
 *     elements in its own key, and guessing the content key is what let a
 *     previous write land beside a footer's real tree instead of in it. All
 *     three are searched, and the one actually holding the element wins.
 *
 *   - that an element id identifies an element. Bricks ids are unique within a
 *     post and not across posts — the same id names different elements on two
 *     pages of the same site — so the key is (post_id, element_id) and the
 *     post is never inferred.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_bricks_update_element(array $input): array|WP_Error
{
    if (!defined('BRICKS_VERSION')) {
        return new WP_Error('bricks_not_active', __('Bricks is not active on this site.', domain: 'nibwp'));
    }

    $post_id    = (int) ($input['post_id'] ?? 0);
    $element_id = trim((string) ($input['element_id'] ?? ''));
    $patch      = $input['settings_patch'] ?? null;
    $dry_run    = array_key_exists('dry_run', $input) ? (bool) $input['dry_run'] : true;

    if ($post_id <= 0 || $element_id === '') {
        return new WP_Error('bricks_bad_target', __('post_id and element_id are both required.', domain: 'nibwp'));
    }
    if (!is_array($patch) || $patch === []) {
        return new WP_Error(
            'bricks_empty_patch',
            __('settings_patch is missing or empty, so there is nothing to change. Nothing was written.', domain: 'nibwp')
        );
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('bricks_post_missing', sprintf(__('Post %d does not exist.', domain: 'nibwp'), $post_id));
    }
    // The generic ability capability is not enough on its own: this writes to
    // one specific post, so the check has to name it.
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('bricks_no_caps', sprintf(__('You are not allowed to edit post %d.', domain: 'nibwp'), $post_id));
    }

    // Find the element, without assuming which key holds the tree.
    $found = null;
    foreach (['_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2'] as $key) {
        $tree = get_post_meta($post_id, $key, true);
        if (!is_array($tree) || $tree === []) {
            continue;
        }
        $hits = [];
        foreach ($tree as $index => $element) {
            if (is_array($element) && (string) ($element['id'] ?? '') === $element_id) {
                $hits[] = $index;
            }
        }
        if ($hits === []) {
            continue;
        }
        if (count($hits) > 1) {
            return new WP_Error(
                'bricks_ambiguous_element',
                sprintf(
                    __('Element id "%1$s" appears %2$d times in %3$s on post %4$d. That tree is malformed — ids must be unique within a post — so nothing was changed.', domain: 'nibwp'),
                    $element_id,
                    count($hits),
                    $key,
                    $post_id
                )
            );
        }
        $found = ['key' => $key, 'index' => $hits[0], 'tree' => $tree];
        break;
    }

    if ($found === null) {
        return new WP_Error(
            'bricks_element_not_found',
            sprintf(
                __('No element with id "%1$s" on post %2$d. Element ids are unique only within a post, so an id read from another page will not be found here — re-read this post\'s tree and take the id from it.', domain: 'nibwp'),
                $element_id,
                $post_id
            )
        );
    }

    $before  = $found['tree'][$found['index']];
    $after   = $before;
    $after['settings'] = nibwp_bricks_merge_settings((array) ($before['settings'] ?? []), $patch);

    $changed = nibwp_bricks_changed_keys((array) ($before['settings'] ?? []), $after['settings']);
    if ($changed === []) {
        return [
            'written'    => false,
            'post_id'    => $post_id,
            'element_id' => $element_id,
            'meta_key'   => $found['key'],
            'before'     => $before,
            'after'      => $after,
            'changed'    => [],
            'validation' => ['passed' => true, 'failed' => [], 'warnings' => []],
            'summary'    => __('The patch matches what is already stored, so nothing was written.', domain: 'nibwp'),
        ];
    }

    // Validate the patched element alone. The rest of the tree is untouched
    // and is not this call's to answer for.
    $validation = ['passed' => true, 'failed' => [], 'warnings' => []];
    $validator  = WP_PLUGIN_DIR . '/nibwp/includes/skills/bricks-pro/lib/validator.php';
    if (is_readable($validator)) {
        require_once $validator;
        if (function_exists('nibwp_bricks_pro_validate_payload')) {
            $validation = nibwp_bricks_pro_validate_payload(['elements' => [$after]]);
        }
    }
    if (empty($validation['passed'])) {
        return new WP_Error(
            'bricks_patch_invalid',
            __('The patched element does not validate, so nothing was written.', domain: 'nibwp'),
            ['failed' => $validation['failed'] ?? [], 'warnings' => $validation['warnings'] ?? []]
        );
    }

    $result = [
        'written'    => false,
        'post_id'    => $post_id,
        'element_id' => $element_id,
        'meta_key'   => $found['key'],
        'before'     => $before,
        'after'      => $after,
        'changed'    => $changed,
        'validation' => $validation,
    ];

    if ($dry_run) {
        $result['summary'] = sprintf(
            /* translators: 1: comma-separated setting names, 2: element id */
            __('Would change %1$s on element %2$s. Re-run with dry_run=false to write.', domain: 'nibwp'),
            implode(', ', $changed),
            $element_id
        );

        return $result;
    }

    $tree = $found['tree'];
    $tree[$found['index']] = $after;

    // update_metadata() unslashes what it is given, so a backslash anywhere in
    // the tree — a CSS content:"\201C", an escaped quote — is stripped unless
    // it is slashed on the way in. That applies to the whole tree, not just
    // the element being patched.
    update_post_meta($post_id, $found['key'], wp_slash($tree));

    // Read back rather than trust the write: a caller that is told "changed"
    // and got nothing is exactly the failure this ability exists to end.
    $stored = get_post_meta($post_id, $found['key'], true);
    $landed = is_array($stored) && isset($stored[$found['index']]['settings'])
        ? $stored[$found['index']]['settings']
        : null;
    if ($landed !== $after['settings']) {
        return new WP_Error(
            'bricks_write_unconfirmed',
            __('The element was written but reading it back did not return the patched settings. Nothing further was changed; inspect the post before retrying.', domain: 'nibwp')
        );
    }

    nibwp_bricks_regenerate_css($post_id, $found['key']);

    $result['written'] = true;
    $result['summary'] = sprintf(
        /* translators: 1: comma-separated setting names, 2: element id */
        __('Changed %1$s on element %2$s.', domain: 'nibwp'),
        implode(', ', $changed),
        $element_id
    );

    return $result;
}

/**
 * Merge a patch into an element's settings.
 *
 * Only the named keys change. Nested associative arrays merge recursively, so
 * a caller can set one typography property without restating the rest; a null
 * deletes the key, which is the only way to remove a setting rather than blank
 * it. Lists (Bricks stores repeaters as sequential arrays) are replaced
 * wholesale, because merging them by index silently mixes two different rows.
 *
 * @param array $settings Current settings.
 * @param array $patch    Incoming patch.
 * @return array
 */
function nibwp_bricks_merge_settings(array $settings, array $patch): array
{
    foreach ($patch as $key => $value) {
        if ($value === null) {
            unset($settings[$key]);
            continue;
        }
        $existing = $settings[$key] ?? null;
        if (is_array($value) && is_array($existing)
            && !array_is_list($value) && !array_is_list($existing)
        ) {
            $settings[$key] = nibwp_bricks_merge_settings($existing, $value);
            continue;
        }
        $settings[$key] = $value;
    }

    return $settings;
}

/**
 * The setting names a patch actually alters, so a caller can see the change
 * without diffing two objects — and so a patch that changes nothing can say so
 * instead of writing.
 *
 * @return array<int,string>
 */
function nibwp_bricks_changed_keys(array $before, array $after): array
{
    $changed = [];
    foreach (array_keys($before + $after) as $key) {
        $was = $before[$key] ?? null;
        $now = $after[$key] ?? null;
        if ($was !== $now) {
            $changed[] = (string) $key;
        }
    }
    sort($changed);

    return $changed;
}

// ---------------------------------------------------------------------------
// Ability: nibwp/bricks-create-element
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/bricks-create-element', [
    'label' => __('Bricks – Create Custom Element', domain: 'nibwp'),
    'description' => __(
        'Generates a custom Bricks element PHP class and writes it to the NIBWP sandbox directory so it is auto-loaded on every request.',
        domain: 'nibwp',
    ),
    'category' => 'bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'element_name' => [
                'type' => 'string',
                'description' => 'Machine name for the element (lowercase, hyphens). E.g. "pricing-card".',
                'minLength' => 1,
                'pattern' => '^[a-z0-9][a-z0-9-]*$',
            ],
            'element_label' => [
                'type' => 'string',
                'description' => 'Human-readable label shown in the Bricks panel.',
                'minLength' => 1,
            ],
            'element_icon' => [
                'type' => 'string',
                'description' => 'Themify icon class for the element.',
                'default' => 'ti-layout-media-center-alt',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Element category in the Bricks panel.',
                'default' => 'general',
            ],
            'controls' => [
                'type' => 'array',
                'description' => 'Array of control definitions for the element settings.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Control ID / settings key.'],
                        'type' => ['type' => 'string', 'description' => 'Control type: "text", "textarea", "number", "color", "select", "checkbox", "image", "icon", "editor".'],
                        'label' => ['type' => 'string', 'description' => 'Control label.'],
                        'default' => ['description' => 'Default value.'],
                        'options' => ['type' => 'object', 'description' => 'Key-value options for select controls.'],
                        'placeholder' => ['type' => 'string', 'description' => 'Placeholder text.'],
                    ],
                    'required' => ['id', 'type', 'label'],
                ],
            ],
            'render_template' => [
                'type' => 'string',
                'description' => 'PHP code for the render() method body. Use $settings = $this->settings; to access control values.',
            ],
        ],
        'required' => ['element_name', 'element_label'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Absolute path to the generated element file.'],
            'element_class' => ['type' => 'string', 'description' => 'The generated PHP class name.'],
        ],
    ],
    'execute_callback' => 'nibwp_bricks_create_element',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Generates a custom Bricks Element class extending \\Bricks\\Element and saves it to the sandbox.',
                '',
                'CONTROLS:',
                'Each control needs: id, type, label. Optional: default, options, placeholder.',
                'Common type values:',
                '  "text", "textarea", "editor" (WYSIWYG), "number", "color",',
                '  "select", "checkbox", "image", "icon", "link", "code".',
                '',
                'For "select" controls, provide options as { "value": "Label" } pairs.',
                '',
                'RENDER TEMPLATE:',
                'The render_template is placed inside the render() method body.',
                'Access settings via: $settings = $this->settings;',
                'Output HTML inside <div> wrapper that Bricks provides automatically.',
                '',
                'If render_template is omitted, a placeholder <p> is generated.',
                '',
                'REGISTRATION:',
                'The generated file registers the element via the init action at priority 11,',
                'which is after Bricks has loaded its own elements.',
                '',
                'NAMING:',
                '- element_name should be lowercase with hyphens: "pricing-card".',
                '- The PHP class is auto-derived with a "NIBWP_Bricks_" prefix.',
                '  E.g. "pricing-card" becomes NIBWP_Bricks_Pricing_Card.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Generate and write a Bricks element class to the sandbox.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
function nibwp_bricks_create_element(array $input): array|WP_Error
{
    if (!defined('BRICKS_VERSION')) {
        return new WP_Error(
            'bricks_not_active',
            __('Bricks is not active on this site.', domain: 'nibwp'),
        );
    }

    $element_name = trim((string) ($input['element_name'] ?? ''));
    $element_label = trim((string) ($input['element_label'] ?? ''));

    if ($element_name === '' || $element_label === '') {
        return new WP_Error('missing_fields', __('element_name and element_label are required.', domain: 'nibwp'));
    }

    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $element_name)) {
        return new WP_Error('invalid_name', __('element_name must be lowercase alphanumeric with hyphens.', domain: 'nibwp'));
    }

    $element_icon = (string) ($input['element_icon'] ?? 'ti-layout-media-center-alt');
    $category = (string) ($input['category'] ?? 'general');
    $controls = (array) ($input['controls'] ?? []);
    $render_template = (string) ($input['render_template'] ?? '');

    // Derive class name: "pricing-card" -> "NIBWP_Bricks_Pricing_Card".
    $class_suffix = str_replace(' ', '_', ucwords(str_replace('-', ' ', $element_name)));
    $class_name = 'NIBWP_Bricks_' . $class_suffix;

    // Build controls code for set_controls().
    $controls_code = '';
    foreach ($controls as $control) {
        if (empty($control['id']) || empty($control['type']) || empty($control['label'])) {
            continue;
        }
        $ctrl_id = var_export((string) $control['id'], true);
        $ctrl_type = var_export((string) $control['type'], true);
        $ctrl_label = var_export((string) $control['label'], true);

        $ctrl_array = "[\n                'tab' => 'content',\n                'label' => {$ctrl_label},\n                'type' => {$ctrl_type},\n";

        if (isset($control['default'])) {
            $ctrl_default = var_export($control['default'], true);
            $ctrl_array .= "                'default' => {$ctrl_default},\n";
        }
        if (!empty($control['placeholder'])) {
            $ctrl_placeholder = var_export((string) $control['placeholder'], true);
            $ctrl_array .= "                'placeholder' => {$ctrl_placeholder},\n";
        }
        if (!empty($control['options']) && is_array($control['options'])) {
            $ctrl_options = var_export($control['options'], true);
            $ctrl_array .= "                'options' => {$ctrl_options},\n";
        }
        $ctrl_array .= '            ]';

        $controls_code .= <<<PHP

            \$this->controls[{$ctrl_id}] = {$ctrl_array};

        PHP;
    }

    // Render body.
    if ($render_template === '') {
        $render_template = '        $settings = $this->settings;' . "\n"
            . '        echo \'<p>Custom Bricks element: ' . esc_html($element_label) . '</p>\';';
    } else {
        $render_template = '        ' . str_replace("\n", "\n        ", $render_template);
    }

    $safe_label = addslashes($element_label);
    $safe_icon = addslashes($element_icon);
    $safe_category = addslashes($category);

    $php = <<<PHP
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

/**
 * Custom Bricks element: {$element_label}
 * Generated by NIBWP.
 */

if (!class_exists('\\Bricks\\Element')) {
    return;
}

class {$class_name} extends \\Bricks\\Element {

    public \$category = '{$safe_category}';
    public \$name = 'nibwp-{$element_name}';
    public \$icon = '{$safe_icon}';

    public function get_label(): string {
        return '{$safe_label}';
    }

    public function set_controls(): void {
{$controls_code}
    }

    public function render(): void {
        \$root = \$this->render_attributes('_root');
        echo "<div {\$root}>";
{$render_template}
        echo '</div>';
    }
}

add_action('init', function () {
    if (!class_exists('\\Bricks\\Elements') || !method_exists('\\Bricks\\Elements', 'register_element')) {
        return;
    }
    \\Bricks\\Elements::register_element(__FILE__);
}, 11);

PHP;

    // Write to sandbox.
    $sandbox_dir = WP_CONTENT_DIR . '/nibwp-sandbox';
    if (!is_dir($sandbox_dir)) {
        wp_mkdir_p($sandbox_dir);
    }

    $file_path = $sandbox_dir . '/bricks-element-' . $element_name . '.php';
    $written = file_put_contents($file_path, $php, LOCK_EX);

    if ($written === false) {
        return new WP_Error('write_failed', sprintf(__('Failed to write element file: %s', domain: 'nibwp'), $file_path));
    }

    return [
        'file_path' => $file_path,
        'element_class' => $class_name,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/bricks-manage-styles
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/bricks-manage-styles', [
    'label' => __('Bricks – Manage Global Styles', domain: 'nibwp'),
    'description' => __(
        'Manages Bricks global CSS classes and styles. Supports listing, creating, updating, and deleting global classes.',
        domain: 'nibwp',
    ),
    'category' => 'bricks',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'The operation to perform.',
                'enum' => ['list', 'create', 'update', 'delete'],
            ],
            'style_id' => [
                'type' => 'string',
                'description' => 'The style/class ID. Required for update and delete.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Class name for creation. E.g. "btn-primary".',
            ],
            'settings' => [
                'type' => 'object',
                'description' => 'CSS properties object. Keys are CSS property names in Bricks format.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'styles' => [
                'type' => 'array',
                'description' => 'Array of global style objects (for list/create/update).',
            ],
            'deleted' => [
                'type' => 'boolean',
                'description' => 'Whether the deletion succeeded (for delete).',
            ],
        ],
    ],
    'execute_callback' => 'nibwp_bricks_manage_styles',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manages Bricks global CSS classes stored in the WordPress options table.',
                '',
                'ACTIONS:',
                '- "list" — Returns all global classes with their IDs, names, and settings.',
                '- "create" — Creates a new global class. Requires name. Optionally pass settings.',
                '- "update" — Updates an existing class. Requires style_id. Pass name and/or settings.',
                '- "delete" — Deletes a global class by style_id.',
                '',
                'SETTINGS FORMAT:',
                'Bricks stores CSS settings as nested objects. Common patterns:',
                '  {',
                '    "_typography": {',
                '      "font-family": "Inter",',
                '      "font-size": "16px",',
                '      "font-weight": "600",',
                '      "color": { "hex": "#333333" }',
                '    },',
                '    "_background": {',
                '      "color": { "hex": "#3B82F6" }',
                '    },',
                '    "_border": {',
                '      "radius": { "top": "8px", "right": "8px", "bottom": "8px", "left": "8px" }',
                '    },',
                '    "_padding": { "top": "12px", "right": "24px", "bottom": "12px", "left": "24px" }',
                '  }',
                '',
                'TIPS:',
                '- Use "list" first to see existing classes and their IDs.',
                '- Class names should follow CSS naming conventions (lowercase, hyphens).',
                '- The style_id is auto-generated on create.',
                '- Bricks must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Manage Bricks global styles/classes.
 *
 * @param array $input Input data.
 * @return array|WP_Error
 */
/**
 * Recursively convert stdClass to array.
 *
 * Bricks stores global classes as a PHP-serialised array and accesses every
 * level with array syntax. Anything that arrives as an object — a json_decode
 * without assoc, or a (object) cast — becomes an uncatchable fatal inside
 * Bricks\Interactions during theme init, which takes down the front end,
 * wp-admin and the REST API at once.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_bricks_arrayify($value)
{
    if ($value instanceof stdClass) {
        $value = get_object_vars($value);
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = nibwp_bricks_arrayify($v);
        }
    }
    return $value;
}

function nibwp_bricks_manage_styles(array $input): array|WP_Error
{
    if (!defined('BRICKS_VERSION')) {
        return new WP_Error(
            'bricks_not_active',
            __('Bricks is not active on this site.', domain: 'nibwp'),
        );
    }

    $action = (string) ($input['action'] ?? '');
    if (!in_array($action, ['list', 'create', 'update', 'delete'], true)) {
        return new WP_Error('invalid_action', __('action must be one of: list, create, update, delete.', domain: 'nibwp'));
    }

    $option_key = 'bricks_global_classes';
    $classes = get_option($option_key, []);
    if (!is_array($classes)) {
        $classes = [];
    }

    // Bricks reads every global class with ARRAY syntax — Interactions,
    // for one, does $class['settings']['interactions'] during theme init.
    // A stdClass anywhere in here is a site-wide fatal ("Cannot use object of
    // type stdClass as array") on the very next request, before WordPress can
    // serve anything. Normalise on read so a previously-broken option repairs
    // itself the first time this ability runs.
    $classes = nibwp_bricks_arrayify($classes);

    // ---- LIST ----
    if ($action === 'list') {
        return ['styles' => array_values($classes)];
    }

    // ---- DELETE ----
    if ($action === 'delete') {
        $style_id = (string) ($input['style_id'] ?? '');
        if ($style_id === '') {
            return new WP_Error('missing_style_id', __('style_id is required for the delete action.', domain: 'nibwp'));
        }

        $found = false;
        $classes = array_values(array_filter($classes, function ($class) use ($style_id, &$found) {
            if (($class['id'] ?? '') === $style_id) {
                $found = true;
                return false;
            }
            return true;
        }));

        if (!$found) {
            return new WP_Error(
                'style_not_found',
                sprintf(__('Global class with ID "%s" not found.', domain: 'nibwp'), $style_id),
            );
        }

        update_option($option_key, $classes);

        return ['deleted' => true];
    }

    // ---- CREATE ----
    if ($action === 'create') {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('missing_name', __('name is required for the create action.', domain: 'nibwp'));
        }

        // Check for duplicate names.
        foreach ($classes as $existing) {
            if (($existing['name'] ?? '') === $name) {
                return new WP_Error(
                    'duplicate_name',
                    sprintf(__('A global class named "%s" already exists.', domain: 'nibwp'), $name),
                );
            }
        }

        $new_class = [
            'id' => bin2hex(random_bytes(6)),
            'name' => $name,
            'settings' => nibwp_bricks_arrayify((array) ($input['settings'] ?? [])),
        ];

        $classes[] = $new_class;
        update_option($option_key, $classes);

        return ['styles' => array_values($classes)];
    }

    // ---- UPDATE ----
    $style_id = (string) ($input['style_id'] ?? '');
    if ($style_id === '') {
        return new WP_Error('missing_style_id', __('style_id is required for the update action.', domain: 'nibwp'));
    }

    $found = false;
    foreach ($classes as &$class) {
        if (($class['id'] ?? '') === $style_id) {
            $found = true;
            if (!empty($input['name'])) {
                $class['name'] = (string) $input['name'];
            }
            if (isset($input['settings'])) {
                // Merge, never replace: a partial update that wipes sibling
                // keys (interactions, _cssCustom, breakpoint values) silently
                // destroys styling the caller never intended to touch.
                $class['settings'] = array_merge(
                    nibwp_bricks_arrayify((array) ($class['settings'] ?? [])),
                    nibwp_bricks_arrayify((array) $input['settings'])
                );
            }
            break;
        }
    }
    unset($class);

    if (!$found) {
        return new WP_Error(
            'style_not_found',
            sprintf(__('Global class with ID "%s" not found.', domain: 'nibwp'), $style_id),
        );
    }

    update_option($option_key, $classes);

    return ['styles' => array_values($classes)];
}
