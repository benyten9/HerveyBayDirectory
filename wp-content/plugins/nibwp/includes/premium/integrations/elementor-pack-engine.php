<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared engine for the per-pack Elementor add-on integrations.
 *
 * Every popular Elementor add-on pack manages the same two kinds of thing: a
 * set of saved templates (headers, footers, popups, mega menus, sliders) and a
 * list of widgets or modules the site owner can switch off. What differs is
 * where each pack keeps them — one uses a post type and three meta keys,
 * another an option holding a nested array, a third an option listing what is
 * DISABLED rather than what is on. Writing that out twenty times would be
 * twenty chances to get a capability check wrong.
 *
 * So a pack file is a descriptor: the post type, the meta keys, and two
 * closures for reading and writing its module list. This engine turns that into
 * one hub ability with the actions the pack actually supports, and owns the
 * parts that must not vary — object-level capability checks, input validation,
 * and never touching a post that belongs to a different pack.
 *
 * Widget inventory is not duplicated here. elementor-addons.php already resolves
 * every registered widget to the plugin directory its class was declared in,
 * which is true for premium and unreleased packs alike, so the engine asks it.
 */

if (!function_exists('nibwp_elpack_engine_loaded')) {
    /** Marker so a pack file can guard its own require. */
    function nibwp_elpack_engine_loaded(): bool
    {
        return true;
    }

    /** @var array<string, array<string, mixed>> Descriptors, keyed by pack key. */
    $GLOBALS['nibwp_elpack_registry'] = $GLOBALS['nibwp_elpack_registry'] ?? [];

    /**
     * The descriptor for one pack, or null.
     *
     * @return array<string, mixed>|null
     */
    function nibwp_elpack_get(string $key): ?array
    {
        return $GLOBALS['nibwp_elpack_registry'][$key] ?? null;
    }

    /**
     * Elementor itself has to be there before any of this means anything.
     */
    function nibwp_elpack_elementor_active(): bool
    {
        return defined('ELEMENTOR_VERSION') || did_action('elementor/loaded') > 0;
    }

    /**
     * Is the pack's own plugin running?
     *
     * A constant or a class is proof the plugin loaded, which is what callers
     * care about — an active row in the options table with a fatal on include
     * would pass a plugin-list check and fail everything after it.
     *
     * @param array<string, mixed> $pack
     */
    function nibwp_elpack_pack_active(array $pack): bool
    {
        foreach ((array) ($pack['constants'] ?? []) as $constant) {
            if (defined((string) $constant)) {
                return true;
            }
        }
        foreach ((array) ($pack['classes'] ?? []) as $class) {
            if (class_exists((string) $class)) {
                return true;
            }
        }
        foreach ((array) ($pack['functions'] ?? []) as $function) {
            if (function_exists((string) $function)) {
                return true;
            }
        }

        // No marker declared, or none of them present: fall back to the plugin
        // directory being active, which at least distinguishes "not installed"
        // from "installed and broken".
        $dir = (string) ($pack['dir'] ?? '');

        if ($dir === '' || !function_exists('nibwp_elementor_addons_active_plugin_files')) {
            return false;
        }

        foreach (nibwp_elementor_addons_active_plugin_files() as $file) {
            if (strtok((string) $file, '/') === $dir) {
                return true;
            }
        }

        return false;
    }

    /**
     * The refusal shared by every action: say which of the two is missing.
     *
     * @param array<string, mixed> $pack
     */
    function nibwp_elpack_guard(array $pack): ?WP_Error
    {
        if (!nibwp_elpack_elementor_active()) {
            return new WP_Error(
                'nibwp_elementor_missing',
                __('Elementor is not active on this site.', 'nibwp'),
                ['status' => 400]
            );
        }

        if (!nibwp_elpack_pack_active($pack)) {
            return new WP_Error(
                'nibwp_pack_missing',
                sprintf(
                    /* translators: %s: name of an Elementor add-on plugin */
                    __('%s is not active on this site.', 'nibwp'),
                    (string) ($pack['name'] ?? 'The add-on')
                ),
                ['status' => 400]
            );
        }

        return null;
    }

    /** A 403 in the shape the rest of the plugin uses. */
    function nibwp_elpack_forbidden(string $message): WP_Error
    {
        return new WP_Error('nibwp_forbidden', $message, ['status' => 403]);
    }

    /**
     * The template post types this pack owns, as registered post types only.
     *
     * A descriptor may name several (one pack keeps templates, mega menus and
     * popups apart). Filtering to registered ones keeps a pack version that
     * dropped a post type from producing a query for nothing.
     *
     * @param array<string, mixed> $pack
     * @return array<int, string>
     */
    function nibwp_elpack_template_types(array $pack): array
    {
        $types = [];

        foreach ((array) ($pack['templates'] ?? []) as $group) {
            $type = (string) ($group['post_type'] ?? '');
            if ($type !== '' && post_type_exists($type)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * The descriptor group for one post type.
     *
     * @param array<string, mixed> $pack
     * @return array<string, mixed>|null
     */
    function nibwp_elpack_template_group(array $pack, string $post_type): ?array
    {
        foreach ((array) ($pack['templates'] ?? []) as $group) {
            if ((string) ($group['post_type'] ?? '') === $post_type) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The display-condition names this template group accepts.
     *
     * Either the keys of a label => meta key map, or an explicit list when the
     * pack keeps its rules somewhere a meta key cannot name.
     *
     * @param array<string, mixed> $group
     * @return array<int, string>
     */
    function nibwp_elpack_condition_labels(array $group): array
    {
        $declared = (array) ($group['conditions'] ?? []);

        if ($declared !== []) {
            return array_map('strval', array_keys($declared));
        }

        return array_values(array_map('strval', (array) ($group['conditions_labels'] ?? [])));
    }

    /**
     * This template's display conditions, however the pack stores them.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    function nibwp_elpack_read_conditions(array $group, WP_Post $post): array
    {
        $reader = ($group['conditions_read'] ?? null);

        if (is_callable($reader)) {
            try {
                $read = $reader($post, $group);
            } catch (\Throwable $error) {
                return [];
            }

            $out = [];
            foreach (nibwp_elpack_condition_labels($group) as $label) {
                $out[$label] = is_array($read) ? ($read[$label] ?? null) : null;
            }

            return $out;
        }

        $out = [];
        foreach ((array) ($group['conditions'] ?? []) as $label => $meta_key) {
            $out[(string) $label] = get_post_meta($post->ID, (string) $meta_key, true);
        }

        return $out;
    }

    /**
     * Load a template post, refusing anything that is not this pack's.
     *
     * The id arrives from an agent, so it is a claim and not a fact. Without the
     * post-type check an agent could hand the "delete this header" action the id
     * of a page and have it deleted by a handler whose capability check passed.
     *
     * @param array<string, mixed> $pack
     * @return WP_Post|WP_Error
     */
    function nibwp_elpack_load_template(array $pack, int $post_id): WP_Post|WP_Error
    {
        $types = nibwp_elpack_template_types($pack);

        if ($types === []) {
            return new WP_Error(
                'nibwp_no_templates',
                sprintf(
                    /* translators: %s: name of an Elementor add-on plugin */
                    __('%s does not keep templates on this site.', 'nibwp'),
                    (string) ($pack['name'] ?? 'This add-on')
                ),
                ['status' => 400]
            );
        }

        $post = $post_id > 0 ? get_post($post_id) : null;

        if (!$post instanceof WP_Post || !in_array($post->post_type, $types, true)) {
            return new WP_Error(
                'nibwp_template_not_found',
                sprintf(
                    /* translators: %d: post id that was asked for */
                    __('No template with id %d belongs to this add-on.', 'nibwp'),
                    $post_id
                ),
                ['status' => 404]
            );
        }

        return $post;
    }

    /**
     * What a template looks like on the way out.
     *
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    function nibwp_elpack_template_row(array $pack, WP_Post $post, bool $with_conditions = true): array
    {
        $group = nibwp_elpack_template_group($pack, $post->post_type) ?? [];

        $row = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'post_type' => $post->post_type,
            'kind' => null,
            'modified' => $post->post_modified_gmt,
            'edit_url' => current_user_can('edit_post', $post->ID) ? get_edit_post_link($post->ID, 'raw') : null,
            'built_with_elementor' => get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder',
        ];

        $kind_meta = (string) ($group['kind_meta'] ?? '');
        if ($kind_meta !== '') {
            $row['kind'] = (string) get_post_meta($post->ID, $kind_meta, true);
        }

        if ($with_conditions) {
            $row['conditions'] = nibwp_elpack_read_conditions($group, $post);
        }

        return $row;
    }

    /**
     * The pack's switchable lists, as name => {label, read, write}.
     *
     * A descriptor may declare one list — which is what most packs have — or
     * several named ones. Several is not an edge case: one pack switches its
     * feature modules and its ninety-odd individual widgets through two options
     * of the same shape, and with a single pair the second was unreachable.
     *
     * @param array<string, mixed> $pack
     * @return array<string, array<string, mixed>>
     */
    function nibwp_elpack_module_groups(array $pack): array
    {
        $declared = (array) ($pack['modules'] ?? []);

        if ($declared === []) {
            return [];
        }

        // One pair, in the shape every descriptor written before this used.
        if (isset($declared['read']) || isset($declared['label']) || isset($declared['write'])) {
            return ['module' => $declared];
        }

        $groups = [];
        foreach ($declared as $name => $group) {
            if (is_string($name) && $name !== '' && is_array($group)) {
                $groups[$name] = $group;
            }
        }

        return $groups;
    }

    /**
     * Which group a request means.
     *
     * Naming one is optional while it is unambiguous — with a single list, or a
     * slug that exists in exactly one of them. It is required as soon as a slug
     * appears in two, because guessing there would switch the wrong thing off.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return string|WP_Error
     */
    function nibwp_elpack_pick_group(array $pack, array $input, string $slug = ''): string|WP_Error
    {
        $groups = nibwp_elpack_module_groups($pack);
        $names = array_keys($groups);

        if ($names === []) {
            return new WP_Error(
                'nibwp_no_modules',
                sprintf(
                    /* translators: %s: name of an Elementor add-on plugin */
                    __('%s has nothing this integration can switch on or off.', 'nibwp'),
                    (string) ($pack['name'] ?? 'This add-on')
                ),
                ['status' => 400]
            );
        }

        $asked = isset($input['group']) ? (string) $input['group'] : '';

        if ($asked !== '') {
            if (!in_array($asked, $names, true)) {
                return new WP_Error(
                    'nibwp_unknown_group',
                    sprintf(
                        /* translators: 1: group name that was asked for, 2: list of valid group names */
                        __('"%1$s" is not one of this add-on\'s lists (%2$s).', 'nibwp'),
                        $asked,
                        implode(', ', $names)
                    ),
                    ['status' => 400]
                );
            }

            return $asked;
        }

        if (count($names) === 1) {
            return $names[0];
        }

        if ($slug === '') {
            return new WP_Error(
                'nibwp_group_required',
                sprintf(
                    /* translators: %s: list of the add-on's switchable list names */
                    __('This add-on has more than one switchable list (%s). Say which with group.', 'nibwp'),
                    implode(', ', $names)
                ),
                ['status' => 400]
            );
        }

        $holding = [];
        foreach ($names as $name) {
            $state = nibwp_elpack_modules($pack, $name);
            if (!$state instanceof WP_Error && array_key_exists($slug, $state)) {
                $holding[] = $name;
            }
        }

        if (count($holding) === 1) {
            return $holding[0];
        }

        if ($holding === []) {
            return new WP_Error(
                'nibwp_unknown_module',
                sprintf(
                    /* translators: 1: module slug, 2: list of the add-on's switchable list names */
                    __('"%1$s" is not in any of this add-on\'s lists (%2$s). Run list_modules first.', 'nibwp'),
                    $slug,
                    implode(', ', $names)
                ),
                ['status' => 400]
            );
        }

        return new WP_Error(
            'nibwp_group_required',
            sprintf(
                /* translators: 1: module slug, 2: the lists it appears in */
                __('"%1$s" appears in more than one of this add-on\'s lists (%2$s). Say which with group.', 'nibwp'),
                $slug,
                implode(', ', $holding)
            ),
            ['status' => 400]
        );
    }

    /**
     * Read the pack's module list through its own closure.
     *
     * @param array<string, mixed> $pack
     * @return array<string, bool>|WP_Error Module slug => enabled.
     */
    function nibwp_elpack_modules(array $pack, string $group = ''): array|WP_Error
    {
        $groups = nibwp_elpack_module_groups($pack);

        if ($group === '') {
            $group = (string) (array_key_first($groups) ?? '');
        }

        $read = ($groups[$group]['read'] ?? null);

        if (!is_callable($read)) {
            return new WP_Error(
                'nibwp_no_modules',
                sprintf(
                    /* translators: %s: name of an Elementor add-on plugin */
                    __('%s has nothing this integration can switch on or off.', 'nibwp'),
                    (string) ($pack['name'] ?? 'This add-on')
                ),
                ['status' => 400]
            );
        }

        // A descriptor's reader talks to another plugin's code, which can throw on
        // a site mid-update or with a class half-loaded. One action refusing is a
        // poor answer; an uncaught Error takes down the whole request, so it is
        // caught here once rather than trusted in twenty descriptors.
        try {
            $modules = $read();
        } catch (\Throwable $error) {
            return new WP_Error(
                'nibwp_modules_unreadable',
                sprintf(
                    /* translators: %s: name of an Elementor add-on plugin */
                    __('%s could not report what it can switch on this request.', 'nibwp'),
                    (string) ($pack['name'] ?? 'The add-on')
                ),
                ['status' => 500]
            );
        }

        if ($modules instanceof WP_Error) {
            return $modules;
        }

        $clean = [];
        foreach ((array) $modules as $slug => $on) {
            $clean[(string) $slug] = (bool) $on;
        }

        return $clean;
    }

    /**
     * The live widget objects this pack registered, keyed by widget name.
     *
     * Provenance is the file the widget's class was declared in: a widget whose
     * class lives under the pack's own plugin directory is the pack's. That is
     * simply true, so it holds for a pack version released after this build and
     * for one that renames its widgets. A pack constant is never consulted.
     *
     * Deliberately not read from the add-ons integration's cached inventory:
     * that file registers its own abilities and an owner may have switched it
     * off, and a pack reporting zero widgets because a DIFFERENT integration was
     * disabled would be a bug nobody could see from here.
     *
     * @param array<string, mixed> $pack
     * @return array<string, object>
     */
    function nibwp_elpack_widget_objects(array $pack): array
    {
        $dir = (string) ($pack['dir'] ?? '');

        if ($dir === '' || !class_exists('\Elementor\Plugin')) {
            return [];
        }

        $plugin = \Elementor\Plugin::$instance ?? null;
        $manager = is_object($plugin) ? ($plugin->widgets_manager ?? null) : null;

        if (!is_object($manager) || !method_exists($manager, 'get_widget_types')) {
            return [];
        }

        try {
            $types = $manager->get_widget_types();
        } catch (\Throwable $error) {
            return [];
        }

        $needle = '/' . trim($dir, '/') . '/';
        $found = [];

        foreach ((array) $types as $name => $widget) {
            if (!is_object($widget)) {
                continue;
            }

            try {
                $file = (new \ReflectionClass($widget))->getFileName();
            } catch (\Throwable $error) {
                continue;
            }

            if (!is_string($file) || $file === '') {
                continue;
            }

            if (str_contains(str_replace('\\', '/', $file), $needle)) {
                $found[(string) $name] = $widget;
            }
        }

        return $found;
    }

    /**
     * One row per widget this pack contributes.
     *
     * @param array<string, mixed> $pack
     * @return array<int, array<string, mixed>>
     */
    function nibwp_elpack_widgets(array $pack): array
    {
        $rows = [];

        foreach (nibwp_elpack_widget_objects($pack) as $name => $widget) {
            $title = $name;
            $categories = [];

            try {
                if (method_exists($widget, 'get_title')) {
                    $title = (string) $widget->get_title();
                }
                if (method_exists($widget, 'get_categories')) {
                    $categories = array_values(array_map('strval', (array) $widget->get_categories()));
                }
            } catch (\Throwable $error) {
                $title = $name;
            }

            $rows[] = ['name' => $name, 'title' => $title, 'categories' => $categories];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Clamp a list length that came in over the wire.
     */
    function nibwp_elpack_limit(mixed $value, int $default = 50, int $max = 200): int
    {
        $limit = is_numeric($value) ? (int) $value : $default;

        return max(1, min($max, $limit));
    }

    /**
     * The actions a descriptor supports, in the order they are useful.
     *
     * @param array<string, mixed> $pack
     * @return array<int, string>
     */
    function nibwp_elpack_actions(array $pack): array
    {
        $actions = ['info', 'list_widgets', 'describe_widget', 'find_usage'];

        if (nibwp_elpack_declares_templates($pack)) {
            $actions = array_merge($actions, [
                'list_templates',
                'get_template',
                'create_template',
                'update_template',
                'delete_template',
            ]);
        }

        $groups = nibwp_elpack_module_groups($pack);
        $readable = array_filter($groups, static fn(array $group): bool => is_callable($group['read'] ?? null));

        if ($readable !== []) {
            $actions[] = 'list_modules';

            $writable = array_filter($readable, static fn(array $group): bool => is_callable($group['write'] ?? null));

            if ($writable !== []) {
                $actions[] = 'enable_module';
                $actions[] = 'disable_module';
            }
        }

        $actions[] = 'audit';

        foreach (array_keys((array) ($pack['actions'] ?? [])) as $extra) {
            $actions[] = (string) $extra;
        }

        return array_values(array_unique($actions));
    }

    /**
     * Does the descriptor claim template post types at all?
     *
     * Asked of the descriptor rather than the registry, because the enum has to
     * be the same on every site — an ability whose action list depends on
     * whether a post type happened to be registered yet would advertise
     * different tools to two clients on the same build.
     *
     * @param array<string, mixed> $pack
     */
    function nibwp_elpack_declares_templates(array $pack): bool
    {
        foreach ((array) ($pack['templates'] ?? []) as $group) {
            if ((string) ($group['post_type'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Register the hub ability for one pack.
     *
     * @param array<string, mixed> $pack
     */
    function nibwp_elpack_register(array $pack): void
    {
        $key = (string) ($pack['key'] ?? '');

        if ($key === '' || !function_exists('wp_register_ability')) {
            return;
        }

        $GLOBALS['nibwp_elpack_registry'][$key] = $pack;

        $name = (string) ($pack['name'] ?? $key);
        $actions = nibwp_elpack_actions($pack);
        $ability = 'nibwp/' . $key . '-manage';

        wp_register_ability($ability, [
            'label' => sprintf(
                /* translators: %s: name of an Elementor add-on plugin */
                __('%s – Manage', 'nibwp'),
                $name
            ),
            'description' => (string) ($pack['description'] ?? sprintf(
                /* translators: %s: name of an Elementor add-on plugin */
                __('Inspect and manage %s: the widgets it contributes, its saved templates, and which of its modules are switched on.', 'nibwp'),
                $name
            )),
            'category' => 'elementor',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => $actions,
                        'description' => 'Which operation to run. Start with info.',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'Template id, for get_template / update_template / delete_template.',
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'description' => 'Which of the pack\'s template post types to work with. Defaults to its first.',
                    ],
                    'title' => ['type' => 'string', 'description' => 'Template title, for create_template / update_template.'],
                    'kind' => ['type' => 'string', 'description' => 'Template kind where the pack has them, e.g. header or footer.'],
                    'status' => ['type' => 'string', 'description' => 'Post status. Defaults to publish on create.'],
                    'conditions' => [
                        'type' => 'object',
                        'description' => 'Display conditions, keyed as list_templates reports them.',
                    ],
                    'module' => ['type' => 'string', 'description' => 'Module or widget slug, for enable_module / disable_module.'],
                    'group' => [
                        'type' => 'string',
                        'description' => 'Which switchable list to work with, where the pack keeps more than one. list_modules names them.',
                    ],
                    'widget' => ['type' => 'string', 'description' => 'Widget name, for describe_widget and find_usage.'],
                    'search' => ['type' => 'string', 'description' => 'Filter list_templates and list_widgets by name.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum rows to return. Default 50, max 200.'],
                    'force' => ['type' => 'boolean', 'description' => 'delete_template: skip the trash and delete outright. Default false.'],
                ],
                'required' => ['action'],
                'additionalProperties' => true,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string'],
                    'pack' => ['type' => 'string'],
                ],
                'required' => ['action'],
            ],
            'execute_callback' => static fn(array $input): array|WP_Error => nibwp_elpack_execute($key, $input),
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true],
                'annotations' => [
                    'instructions' => nibwp_elpack_instructions($pack, $actions),
                    'readonly' => false,
                    'destructive' => true,
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    /**
     * Agent-facing notes for one pack's hub.
     *
     * @param array<string, mixed> $pack
     * @param array<int, string>   $actions
     */
    function nibwp_elpack_instructions(array $pack, array $actions): string
    {
        $name = (string) ($pack['name'] ?? '');
        $lines = [
            sprintf('Manage %s, an Elementor add-on pack, on this site.', $name),
            '',
            'START WITH info: it reports the pack version, how many widgets it',
            'contributes to the live registry, which template post types it has',
            'registered, and how many of its modules are on. Everything else',
            'reads better once you know that.',
            '',
            'ACTIONS: ' . implode(', ', $actions) . '.',
            '',
            'WIDGETS: list_widgets names what the pack registers, describe_widget',
            'gives one widget\'s full control schema, and find_usage says which posts',
            'are built with it. Ask find_usage before switching anything off.',
        ];

        if (nibwp_elpack_declares_templates($pack)) {
            $lines = array_merge($lines, [
                '',
                'TEMPLATES:',
                '- A template is a post of one of the pack\'s own post types. Passing an id',
                '  of any other type is refused, so you cannot delete a page by mistake.',
                '- create_template makes the post and marks it as built with Elementor; the',
                '  layout itself is built with the Elementor abilities afterwards.',
                '- Display conditions are the pack\'s own meta keys. list_templates reports',
                '  them under conditions; pass the same keys back to update_template.',
                '- delete_template trashes by default. force deletes outright and cannot be',
                '  undone. Where the pack files its rules under the template\'s slug, the',
                '  rules are cleared first, because after the delete there is no slug left',
                '  to find them by.',
            ]);
        }

        $groups = nibwp_elpack_module_groups($pack);

        if ($groups !== []) {
            $lines = array_merge($lines, [
                '',
                'MODULES:',
                '- list_modules reports what the pack can switch off and its current state.',
                count($groups) > 1
                    ? '- This pack keeps several separate lists (' . implode(', ', array_keys($groups))
                        . '). list_modules reports all of them; pass group to narrow it, and to'
                        . ' disambiguate a slug that appears in two.'
                    : '- It keeps one list.',
                '- A widget from a disabled module disappears from the editor but stays in',
                '  the saved page data, so audit flags pages that use one. Re-enable rather',
                '  than rebuild.',
            ]);
        }

        foreach ((array) ($pack['notes'] ?? []) as $note) {
            $lines[] = '';
            $lines[] = (string) $note;
        }

        return implode("\n", $lines);
    }

    /**
     * Dispatch one action for one pack.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_execute(string $key, array $input): array|WP_Error
    {
        $pack = nibwp_elpack_get($key);

        if ($pack === null) {
            return new WP_Error('nibwp_unknown_pack', __('This add-on integration is not loaded.', 'nibwp'), ['status' => 500]);
        }

        $guard = nibwp_elpack_guard($pack);
        if ($guard !== null) {
            return $guard;
        }

        $action = isset($input['action']) ? (string) $input['action'] : '';

        if (!in_array($action, nibwp_elpack_actions($pack), true)) {
            return new WP_Error(
                'nibwp_unknown_action',
                sprintf(
                    /* translators: 1: action name, 2: list of valid action names */
                    __('Unknown action "%1$s". Valid actions: %2$s.', 'nibwp'),
                    $action,
                    implode(', ', nibwp_elpack_actions($pack))
                ),
                ['status' => 400]
            );
        }

        $extra = ($pack['actions'][$action] ?? null);
        if (is_callable($extra)) {
            $result = $extra($input, $pack);

            return $result instanceof WP_Error ? $result : ['action' => $action, 'pack' => $key] + (array) $result;
        }

        $result = match ($action) {
            'info' => nibwp_elpack_do_info($pack),
            'list_widgets' => nibwp_elpack_do_list_widgets($pack, $input),
            'describe_widget' => nibwp_elpack_do_describe_widget($pack, $input),
            'find_usage' => nibwp_elpack_do_find_usage($pack, $input),
            'list_templates' => nibwp_elpack_do_list_templates($pack, $input),
            'get_template' => nibwp_elpack_do_get_template($pack, $input),
            'create_template' => nibwp_elpack_do_create_template($pack, $input),
            'update_template' => nibwp_elpack_do_update_template($pack, $input),
            'delete_template' => nibwp_elpack_do_delete_template($pack, $input),
            'list_modules' => nibwp_elpack_do_list_modules($pack, $input),
            'enable_module' => nibwp_elpack_do_set_module($pack, $input, true),
            'disable_module' => nibwp_elpack_do_set_module($pack, $input, false),
            'audit' => nibwp_elpack_do_audit($pack),
            default => new WP_Error('nibwp_unknown_action', __('Unhandled action.', 'nibwp'), ['status' => 400]),
        };

        if ($result instanceof WP_Error) {
            return $result;
        }

        return ['action' => $action, 'pack' => $key] + $result;
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_info(array $pack): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot read this site\'s builder configuration.', 'nibwp'));
        }

        $dir = (string) ($pack['dir'] ?? '');
        $meta = function_exists('nibwp_elementor_addons_provider_meta')
            ? nibwp_elementor_addons_provider_meta($dir)
            : [];

        $groups = nibwp_elpack_module_groups($pack);
        $module_total = 0;
        $module_on = 0;
        $group_names = [];

        foreach (array_keys($groups) as $name) {
            $state = nibwp_elpack_modules($pack, $name);
            if ($state instanceof WP_Error) {
                continue;
            }
            $group_names[] = $name;
            $module_total += count($state);
            $module_on += count(array_filter($state));
        }

        $types = [];

        foreach ((array) ($pack['templates'] ?? []) as $group) {
            $type = (string) ($group['post_type'] ?? '');
            if ($type === '') {
                continue;
            }
            $types[$type] = [
                'registered' => post_type_exists($type),
                'count' => post_type_exists($type)
                    ? array_sum((array) wp_count_posts($type))
                    : 0,
                'kind_meta' => (string) ($group['kind_meta'] ?? ''),
                'kinds' => array_values((array) ($group['kinds'] ?? [])),
            ];
        }

        return [
            'name' => (string) ($pack['name'] ?? ''),
            'plugin_dir' => $dir,
            'version' => (string) ($meta['version'] ?? ''),
            'widgets' => count(nibwp_elpack_widgets($pack)),
            'template_types' => $types,
            'module_lists' => $group_names,
            'modules_total' => $module_total,
            'modules_enabled' => $module_on,
            'manages_modules' => $group_names !== [],
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_list_widgets(array $pack, array $input): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot read this site\'s widget registry.', 'nibwp'));
        }

        $search = isset($input['search']) ? strtolower(trim((string) $input['search'])) : '';
        $limit = nibwp_elpack_limit($input['limit'] ?? null);
        $rows = nibwp_elpack_widgets($pack);

        if ($search !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => str_contains(strtolower($row['name'] . ' ' . $row['title']), $search)
            ));
        }

        return [
            'total' => count($rows),
            'widgets' => array_slice($rows, 0, $limit),
            'note' => $rows === []
                ? __('The pack is active but registers no widgets yet — Elementor builds its registry on the editor request, so an empty list from a front-end call is normal.', 'nibwp')
                : null,
        ];
    }

    /**
     * The control schema of one widget, so an agent can build with it.
     *
     * Restricted to the pack's own widgets on purpose: this is the ability a
     * client reaches for when it is working with THIS pack, and answering for any
     * widget on the site would make twenty abilities that all do the same thing.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_describe_widget(array $pack, array $input): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot read this site\'s widget registry.', 'nibwp'));
        }

        $name = isset($input['widget']) ? (string) $input['widget'] : '';

        if ($name === '') {
            return new WP_Error(
                'nibwp_missing_widget',
                __('Name the widget to describe. list_widgets reports the names.', 'nibwp'),
                ['status' => 400]
            );
        }

        $objects = nibwp_elpack_widget_objects($pack);

        if (!isset($objects[$name])) {
            return new WP_Error(
                'nibwp_widget_not_from_pack',
                sprintf(
                    /* translators: 1: widget name, 2: name of an Elementor add-on plugin */
                    __('"%1$s" is not a widget %2$s provides. Run list_widgets first.', 'nibwp'),
                    $name,
                    (string) ($pack['name'] ?? '')
                ),
                ['status' => 404]
            );
        }

        $widget = $objects[$name];
        $controls = [];

        try {
            if (method_exists($widget, 'get_controls')) {
                $controls = (array) $widget->get_controls();
            }
        } catch (\Throwable $error) {
            return new WP_Error(
                'nibwp_controls_unavailable',
                __('The widget refused to describe its controls on this request. Elementor builds some controls only inside the editor.', 'nibwp'),
                ['status' => 500]
            );
        }

        $limit = nibwp_elpack_limit($input['limit'] ?? null, 120, 400);
        $rows = [];

        foreach ($controls as $id => $control) {
            if (count($rows) >= $limit) {
                break;
            }
            $control = (array) $control;
            $rows[] = [
                'id' => (string) $id,
                'type' => (string) ($control['type'] ?? ''),
                'label' => (string) ($control['label'] ?? ''),
                'section' => (string) ($control['section'] ?? ''),
                'default' => $control['default'] ?? null,
            ];
        }

        return [
            'widget' => $name,
            'controls_total' => count($controls),
            'controls' => $rows,
        ];
    }

    /**
     * Which posts are built with this pack's widgets.
     *
     * A text match against Elementor's saved data, which is what that data
     * really is — one JSON blob in one meta row. It is exact enough for the
     * question that matters before switching a pack or a module off: what stops
     * rendering.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_find_usage(array $pack, array $input): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot read this site\'s builder content.', 'nibwp'));
        }

        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return new WP_Error('nibwp_no_db', __('No database handle is available.', 'nibwp'), ['status' => 500]);
        }

        $name = isset($input['widget']) ? (string) $input['widget'] : '';
        $objects = nibwp_elpack_widget_objects($pack);

        if ($name !== '' && $objects !== [] && !isset($objects[$name])) {
            return new WP_Error(
                'nibwp_widget_not_from_pack',
                sprintf(
                    /* translators: 1: widget name, 2: name of an Elementor add-on plugin */
                    __('"%1$s" is not a widget %2$s provides. Run list_widgets first.', 'nibwp'),
                    $name,
                    (string) ($pack['name'] ?? '')
                ),
                ['status' => 404]
            );
        }

        $names = $name !== '' ? [$name] : array_keys($objects);

        if ($names === []) {
            return [
                'widgets' => [],
                'posts' => [],
                'note' => __('The pack registers no widgets on this request, so there is nothing to look for. Elementor builds its registry inside the editor.', 'nibwp'),
            ];
        }

        // Bounded on both sides: how many widget names are looked for, and how
        // many rows come back. A pack with ninety widgets on a large site would
        // otherwise be one LIKE per widget across every page of content.
        $names = array_slice($names, 0, 60);
        $limit = nibwp_elpack_limit($input['limit'] ?? null, 50, 200);

        $clauses = [];
        $values = [];

        foreach ($names as $one) {
            $clauses[] = 'meta_value LIKE %s';
            $values[] = '%' . $wpdb->esc_like('"widgetType":"' . $one . '"') . '%';
        }

        $values[] = $limit;

        $sql = 'SELECT DISTINCT post_id FROM ' . $wpdb->postmeta
            . " WHERE meta_key = '_elementor_data' AND (" . implode(' OR ', $clauses) . ') LIMIT %d';

        $ids = (array) $wpdb->get_col($wpdb->prepare($sql, $values));
        $rows = [];

        foreach ($ids as $id) {
            $post = get_post((int) $id);

            // An account that cannot edit a post has no business learning it
            // exists from here, so it is dropped rather than merely hidden.
            if (!$post instanceof WP_Post || !current_user_can('edit_post', $post->ID)) {
                continue;
            }

            $rows[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'post_type' => $post->post_type,
                'status' => $post->post_status,
            ];
        }

        return [
            'widgets' => $names,
            'posts' => $rows,
            'returned' => count($rows),
            'note' => $name === ''
                ? __('Every widget this pack provides was looked for at once. Pass widget to narrow it to one.', 'nibwp')
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_list_templates(array $pack, array $input): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot list this add-on\'s templates.', 'nibwp'));
        }

        $types = nibwp_elpack_template_types($pack);

        if ($types === []) {
            return [
                'total' => 0,
                'templates' => [],
                'note' => __('None of this add-on\'s template post types are registered on this site.', 'nibwp'),
            ];
        }

        $wanted = isset($input['post_type']) ? (string) $input['post_type'] : '';
        if ($wanted !== '') {
            if (!in_array($wanted, $types, true)) {
                return new WP_Error(
                    'nibwp_unknown_post_type',
                    sprintf(
                        /* translators: 1: requested post type, 2: list of valid post types */
                        __('"%1$s" is not one of this add-on\'s template types (%2$s).', 'nibwp'),
                        $wanted,
                        implode(', ', $types)
                    ),
                    ['status' => 400]
                );
            }
            $types = [$wanted];
        }

        $query = [
            'post_type' => $types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => nibwp_elpack_limit($input['limit'] ?? null),
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
            'no_found_rows' => false,
        ];

        $search = isset($input['search']) ? trim((string) $input['search']) : '';
        if ($search !== '') {
            $query['s'] = $search;
        }

        $found = new WP_Query($query);
        $rows = [];

        foreach ($found->posts as $post) {
            if (!$post instanceof WP_Post || !current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $rows[] = nibwp_elpack_template_row($pack, $post);
        }

        return [
            'total' => (int) $found->found_posts,
            'returned' => count($rows),
            'post_types' => $types,
            'templates' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_get_template(array $pack, array $input): array|WP_Error
    {
        $post = nibwp_elpack_load_template($pack, isset($input['id']) ? (int) $input['id'] : 0);

        if ($post instanceof WP_Error) {
            return $post;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return nibwp_elpack_forbidden(__('You cannot read this template.', 'nibwp'));
        }

        return ['template' => nibwp_elpack_template_row($pack, $post)];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_create_template(array $pack, array $input): array|WP_Error
    {
        $types = nibwp_elpack_template_types($pack);

        if ($types === []) {
            return new WP_Error(
                'nibwp_no_templates',
                __('None of this add-on\'s template post types are registered on this site.', 'nibwp'),
                ['status' => 400]
            );
        }

        $type = isset($input['post_type']) ? (string) $input['post_type'] : $types[0];

        if (!in_array($type, $types, true)) {
            return new WP_Error(
                'nibwp_unknown_post_type',
                sprintf(
                    /* translators: 1: requested post type, 2: list of valid post types */
                    __('"%1$s" is not one of this add-on\'s template types (%2$s).', 'nibwp'),
                    $type,
                    implode(', ', $types)
                ),
                ['status' => 400]
            );
        }

        $object = get_post_type_object($type);
        $create_cap = $object !== null && isset($object->cap->create_posts)
            ? (string) $object->cap->create_posts
            : 'edit_posts';

        if (!current_user_can($create_cap)) {
            return nibwp_elpack_forbidden(__('You cannot create templates for this add-on.', 'nibwp'));
        }

        $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

        if ($title === '') {
            return new WP_Error('nibwp_missing_title', __('A template needs a title.', 'nibwp'), ['status' => 400]);
        }

        $status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'publish';
        if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
            $status = 'publish';
        }

        $post_id = wp_insert_post([
            'post_type' => $type,
            'post_title' => $title,
            'post_status' => $status,
            'post_content' => '',
        ], true);

        if ($post_id instanceof WP_Error) {
            return $post_id;
        }

        // Elementor only opens its editor for a post it recognises as its own,
        // and a template created without this flag looks empty and un-editable.
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        // Several packs stamp more than that: Elementor resolves which document
        // class the editor loads from _elementor_template_type, so a template
        // created without the pack's own value renders correctly but opens as a
        // generic post. The descriptor names whatever else the pack writes.
        $group = nibwp_elpack_template_group($pack, $type) ?? [];

        foreach ((array) ($group['create_meta'] ?? []) as $meta_key => $meta_value) {
            if (is_string($meta_key) && $meta_key !== '') {
                update_post_meta($post_id, $meta_key, $meta_value);
            }
        }

        $applied = nibwp_elpack_apply_template_fields($pack, (int) $post_id, $type, $input);

        if ($applied instanceof WP_Error) {
            return $applied;
        }

        nibwp_elpack_after_template_write($pack, (int) $post_id);

        $post = get_post((int) $post_id);

        return [
            'created' => true,
            'template' => $post instanceof WP_Post ? nibwp_elpack_template_row($pack, $post) : ['id' => (int) $post_id],
            'next' => __('The template exists but has no layout yet. Build it with the Elementor abilities, then set its display conditions with update_template.', 'nibwp'),
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_update_template(array $pack, array $input): array|WP_Error
    {
        $post = nibwp_elpack_load_template($pack, isset($input['id']) ? (int) $input['id'] : 0);

        if ($post instanceof WP_Error) {
            return $post;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return nibwp_elpack_forbidden(__('You cannot edit this template.', 'nibwp'));
        }

        $changed = [];
        $update = ['ID' => $post->ID];

        if (isset($input['title'])) {
            $update['post_title'] = sanitize_text_field((string) $input['title']);
            $changed[] = 'title';
        }

        if (isset($input['status'])) {
            $status = sanitize_key((string) $input['status']);
            if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
                return new WP_Error('nibwp_bad_status', __('Status must be publish, draft, pending or private.', 'nibwp'), ['status' => 400]);
            }
            $update['post_status'] = $status;
            $changed[] = 'status';
        }

        if (count($update) > 1) {
            $saved = wp_update_post($update, true);
            if ($saved instanceof WP_Error) {
                return $saved;
            }
        }

        $applied = nibwp_elpack_apply_template_fields($pack, $post->ID, $post->post_type, $input);

        if ($applied instanceof WP_Error) {
            return $applied;
        }

        $changed = array_merge($changed, $applied);

        // A pack caches which of its templates wins for a given request, and
        // flushes that cache on save_post. Writing only display conditions never
        // touched the post, so the stale answer stayed live and the change looked
        // like it had done nothing at all. Touch the post so the pack's own hooks
        // run, but only when something actually changed.
        if ($applied !== [] && count($update) === 1) {
            wp_update_post(['ID' => $post->ID], true);
        }

        if ($changed !== []) {
            nibwp_elpack_after_template_write($pack, $post->ID);
        }

        $fresh = get_post($post->ID);

        return [
            'updated' => $changed !== [],
            'changed' => $changed,
            'template' => $fresh instanceof WP_Post ? nibwp_elpack_template_row($pack, $fresh) : ['id' => $post->ID],
        ];
    }

    /**
     * Let the pack refresh whatever it resolves templates from.
     *
     * Touching the post covers a pack that flushes on save_post. Others rebuild
     * their resolution only from their own editor, so the change is stored
     * correctly and the site keeps following the old rules — which reads as the
     * write having done nothing. A descriptor names a callable for those.
     *
     * @param array<string, mixed> $pack
     */
    function nibwp_elpack_after_template_write(array $pack, int $post_id): void
    {
        $hook = ($pack['after_template_write'] ?? null);

        if (!is_callable($hook)) {
            return;
        }

        // A pack's own cache rebuild is not worth failing a write that already
        // succeeded, and the caller has no way to undo it.
        try {
            $hook($post_id, $pack);
        } catch (\Throwable $error) {
            return;
        }
    }

    /**
     * Let the pack clean up while the template still exists.
     *
     * A pack that files its display rules under the template's SLUG cannot do
     * this from after_template_write: that hook is handed an id, and a forced
     * delete has already taken the slug. An orphaned rule set is not harmless —
     * one still saying "everywhere" can win such a pack's last-one-wins
     * resolution and blank the header that replaced it.
     *
     * @param array<string, mixed> $pack
     */
    function nibwp_elpack_before_template_delete(array $pack, WP_Post $post): void
    {
        $hook = ($pack['before_template_delete'] ?? null);

        if (!is_callable($hook)) {
            return;
        }

        // A failed clean-up must not stop the delete the caller asked for: the
        // template is the thing they wanted gone, and refusing now would leave
        // them with neither outcome.
        try {
            $hook($post, $pack);
        } catch (\Throwable $error) {
            return;
        }
    }

    /**
     * Write the pack's own kind and condition meta, if the input asked for it.
     *
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<int, string>|WP_Error Names of what changed.
     */
    function nibwp_elpack_apply_template_fields(array $pack, int $post_id, string $post_type, array $input): array|WP_Error
    {
        $group = nibwp_elpack_template_group($pack, $post_type) ?? [];
        $changed = [];

        $kind_meta = (string) ($group['kind_meta'] ?? '');
        if ($kind_meta !== '' && isset($input['kind'])) {
            $kind = sanitize_key((string) $input['kind']);
            $kinds = array_map('strval', (array) ($group['kinds'] ?? []));

            if ($kinds !== [] && !in_array($kind, $kinds, true)) {
                return new WP_Error(
                    'nibwp_bad_kind',
                    sprintf(
                        /* translators: 1: requested kind, 2: list of valid kinds */
                        __('"%1$s" is not a kind this add-on understands (%2$s).', 'nibwp'),
                        $kind,
                        implode(', ', $kinds)
                    ),
                    ['status' => 400]
                );
            }

            update_post_meta($post_id, $kind_meta, $kind);
            $changed[] = 'kind';
        }

        $conditions = isset($input['conditions']) && is_array($input['conditions']) ? $input['conditions'] : [];
        $labels = nibwp_elpack_condition_labels($group);
        $writer = ($group['conditions_write'] ?? null);
        $post = $conditions === [] ? null : get_post($post_id);

        foreach ($conditions as $label => $value) {
            $label = (string) $label;

            if (!in_array($label, $labels, true)) {
                return new WP_Error(
                    'nibwp_bad_condition',
                    sprintf(
                        /* translators: 1: condition name, 2: list of valid condition names */
                        __('"%1$s" is not a display condition this add-on has (%2$s).', 'nibwp'),
                        $label,
                        $labels === [] ? __('none', 'nibwp') : implode(', ', $labels)
                    ),
                    ['status' => 400]
                );
            }

            // A condition value is the pack's own structure, so it is stored as
            // given rather than reshaped — but only after the label proved to be
            // one the descriptor declared, which is what stops this writing
            // wherever the caller names.
            if (is_callable($writer)) {
                if (!$post instanceof WP_Post) {
                    return new WP_Error('nibwp_template_not_found', __('The template disappeared while it was being written.', 'nibwp'), ['status' => 404]);
                }

                $wrote = $writer($post, $label, $value, $group);

                if ($wrote instanceof WP_Error) {
                    return $wrote;
                }

                $changed[] = 'conditions.' . $label;
                continue;
            }

            $meta_key = (string) (($group['conditions'] ?? [])[$label] ?? '');

            if ($meta_key === '') {
                return new WP_Error(
                    'nibwp_condition_unwritable',
                    sprintf(
                        /* translators: %s: condition name */
                        __('This add-on reports "%s" but this integration cannot write it.', 'nibwp'),
                        $label
                    ),
                    ['status' => 400]
                );
            }

            update_post_meta($post_id, $meta_key, $value);
            $changed[] = 'conditions.' . $label;
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_delete_template(array $pack, array $input): array|WP_Error
    {
        $post = nibwp_elpack_load_template($pack, isset($input['id']) ? (int) $input['id'] : 0);

        if ($post instanceof WP_Error) {
            return $post;
        }

        if (!current_user_can('delete_post', $post->ID)) {
            return nibwp_elpack_forbidden(__('You cannot delete this template.', 'nibwp'));
        }

        nibwp_elpack_before_template_delete($pack, $post);

        $force = isset($input['force']) && (bool) $input['force'];
        $gone = $force ? wp_delete_post($post->ID, true) : wp_trash_post($post->ID);

        if (!$gone) {
            return new WP_Error('nibwp_delete_failed', __('The template could not be deleted.', 'nibwp'), ['status' => 500]);
        }

        nibwp_elpack_after_template_write($pack, $post->ID);

        return [
            'deleted' => true,
            'id' => $post->ID,
            'title' => $post->post_title,
            'trashed' => !$force,
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_list_modules(array $pack, array $input): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot read this add-on\'s settings.', 'nibwp'));
        }

        $groups = nibwp_elpack_module_groups($pack);

        if ($groups === []) {
            return nibwp_elpack_pick_group($pack, $input);
        }

        $asked = isset($input['group']) ? (string) $input['group'] : '';

        if ($asked !== '') {
            $picked = nibwp_elpack_pick_group($pack, $input);
            if ($picked instanceof WP_Error) {
                return $picked;
            }
            $groups = [$picked => $groups[$picked]];
        }

        $out = [];
        $total = 0;
        $enabled = 0;

        foreach ($groups as $name => $group) {
            $modules = nibwp_elpack_modules($pack, $name);

            if ($modules instanceof WP_Error) {
                $out[$name] = ['error' => $modules->get_error_message()];
                continue;
            }

            $rows = [];
            foreach ($modules as $slug => $on) {
                $rows[] = ['slug' => $slug, 'enabled' => $on];
            }

            $total += count($rows);
            $enabled += count(array_filter($modules));

            $out[$name] = [
                'label' => (string) ($group['label'] ?? $name),
                'total' => count($rows),
                'enabled' => count(array_filter($modules)),
                'writable' => is_callable($group['write'] ?? null),
                'modules' => $rows,
            ];
        }

        return [
            'groups' => $out,
            'total' => $total,
            'enabled' => $enabled,
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_set_module(array $pack, array $input, bool $on): array|WP_Error
    {
        // Switching a module changes the editor for every author on the site, so
        // this is a settings write and takes the settings capability — not the
        // capability that merely lets someone edit a page.
        if (!current_user_can('manage_options')) {
            return nibwp_elpack_forbidden(__('You cannot change this add-on\'s settings.', 'nibwp'));
        }

        $slug = isset($input['module']) ? (string) $input['module'] : '';

        $group = nibwp_elpack_pick_group($pack, $input, $slug);

        if ($group instanceof WP_Error) {
            return $group;
        }

        $groups = nibwp_elpack_module_groups($pack);
        $write = ($groups[$group]['write'] ?? null);

        if (!is_callable($write)) {
            return new WP_Error(
                'nibwp_modules_readonly',
                __('This add-on\'s module list can be read but not changed from here.', 'nibwp'),
                ['status' => 400]
            );
        }

        $modules = nibwp_elpack_modules($pack, $group);

        if ($modules instanceof WP_Error) {
            return $modules;
        }

        if ($slug === '' || !array_key_exists($slug, $modules)) {
            return new WP_Error(
                'nibwp_unknown_module',
                sprintf(
                    /* translators: 1: module slug that was asked for, 2: how many the pack has */
                    __('"%1$s" is not one of this add-on\'s %2$d modules. Run list_modules first.', 'nibwp'),
                    $slug,
                    count($modules)
                ),
                ['status' => 400]
            );
        }

        if ($modules[$slug] === $on) {
            return [
                'group' => $group,
                'module' => $slug,
                'enabled' => $on,
                'changed' => false,
                'note' => __('Already in that state; nothing was written.', 'nibwp'),
            ];
        }

        $result = $write($slug, $on);

        if ($result instanceof WP_Error) {
            return $result;
        }

        $after = nibwp_elpack_modules($pack, $group);

        return [
            'group' => $group,
            'module' => $slug,
            'enabled' => $after instanceof WP_Error ? $on : ($after[$slug] ?? $on),
            'changed' => true,
        ];
    }

    /**
     * Problems worth reporting about one pack.
     *
     * The useful one is a page still using a widget whose module was switched
     * off: the widget is gone from the editor but the page data still holds it,
     * so the section renders as nothing with no error anywhere. Re-enabling the
     * module fixes it; rebuilding the page does not need to happen.
     *
     * @param array<string, mixed> $pack
     * @return array<string, mixed>|WP_Error
     */
    function nibwp_elpack_do_audit(array $pack): array|WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return nibwp_elpack_forbidden(__('You cannot audit this site\'s builder content.', 'nibwp'));
        }

        $findings = [];
        $widgets = nibwp_elpack_widgets($pack);

        if ($widgets === []) {
            $findings[] = [
                'kind' => 'no_widgets',
                'severity' => 'info',
                'detail' => __('The pack is active but contributes no widgets to the registry on this request. Elementor builds the registry in the editor, so check again from an editor context before reading anything into it.', 'nibwp'),
            ];
        }

        foreach (array_keys(nibwp_elpack_module_groups($pack)) as $group_name) {
            $modules = nibwp_elpack_modules($pack, $group_name);

            if ($modules instanceof WP_Error) {
                continue;
            }

            $off = array_keys(array_filter($modules, static fn(bool $on): bool => !$on));

            if ($off !== []) {
                $findings[] = [
                    'kind' => 'modules_disabled',
                    'severity' => 'info',
                    'group' => $group_name,
                    'detail' => sprintf(
                        /* translators: %d: how many of the add-on's modules are switched off */
                        __('%d of this add-on\'s modules are switched off. A page built with one of them keeps the widget in its saved data but renders nothing.', 'nibwp'),
                        count($off)
                    ),
                    'modules' => array_slice($off, 0, 40),
                ];
            }
        }

        foreach (nibwp_elpack_template_types($pack) as $type) {
            $group = nibwp_elpack_template_group($pack, $type) ?? [];

            $posts = get_posts([
                'post_type' => $type,
                'post_status' => ['publish', 'draft', 'pending', 'private'],
                'numberposts' => 100,
                'suppress_filters' => false,
            ]);

            foreach ($posts as $post) {
                if (!$post instanceof WP_Post || !current_user_can('edit_post', $post->ID)) {
                    continue;
                }

                if (get_post_meta($post->ID, '_elementor_edit_mode', true) !== 'builder') {
                    $findings[] = [
                        'kind' => 'template_not_built',
                        'severity' => 'warning',
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'detail' => __('The template exists but was never opened in Elementor, so it has no layout to output.', 'nibwp'),
                    ];
                    continue;
                }

                if (nibwp_elpack_condition_labels($group) === [] || $post->post_status !== 'publish') {
                    continue;
                }

                // Some kinds are output by something other than a display rule —
                // a shortcode, or a loop the theme places itself — so having no
                // conditions is correct for them and warning about it would train
                // the reader to ignore the audit.
                $kind_meta = (string) ($group['kind_meta'] ?? '');
                $kind = $kind_meta === '' ? '' : (string) get_post_meta($post->ID, $kind_meta, true);

                if ($kind !== '' && in_array($kind, array_map('strval', (array) ($group['kinds_without_conditions'] ?? [])), true)) {
                    continue;
                }

                // One pack reads a stored-but-empty rule list as "every page" and
                // a missing one as "nowhere" — the opposite way round. Warning
                // about its most deliberately configured templates is worse than
                // not warning at all, so the group says which way its pack reads.
                $empty_is_everywhere = (bool) ($group['conditions_empty_matches_all'] ?? false);

                $set = false;
                foreach (nibwp_elpack_read_conditions($group, $post) as $value) {
                    if ($empty_is_everywhere && is_array($value)) {
                        $set = true;
                        break;
                    }
                    if (!in_array($value, ['', null, [], false], true)) {
                        $set = true;
                        break;
                    }
                }

                if (!$set) {
                    $findings[] = [
                        'kind' => 'template_without_conditions',
                        'severity' => 'warning',
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'detail' => __('Published with no display conditions, so it is never shown anywhere. Set conditions with update_template.', 'nibwp'),
                    ];
                }
            }
        }

        return [
            'findings' => $findings,
            'clean' => $findings === [],
        ];
    }
}
