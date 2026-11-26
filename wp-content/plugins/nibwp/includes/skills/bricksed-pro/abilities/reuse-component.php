<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Take a component out of the library and put it on a page.
 *
 * On a copy, always. Reuse that mutates the stored artifact turns a library
 * into a working file, and the second site to use the component gets whatever
 * the first site needed.
 */

/*
 * The libs this ability calls. Loaded here, not left to the integration file:
 * the skill loader checks that Bricksed is present, not that its integration
 * card is switched on. Each guard names a function from its own file.
 */
$nibwp_bricksed_lib = dirname(__DIR__) . '/lib/';
if (!function_exists('nibwp_bricksed_available')) {
    require_once $nibwp_bricksed_lib . 'library-client.php';
}
if (!function_exists('nibwp_bricksed_write_body')) {
    require_once $nibwp_bricksed_lib . 'library.php';
}
if (!function_exists('nibwp_bricksed_place')) {
    require_once $nibwp_bricksed_lib . 'placement.php';
}
unset($nibwp_bricksed_lib);

wp_register_ability('nibwp/bricksed-pro-reuse-component', [
    'label' => __('Bricksed Pro — Reuse Component', 'nibwp'),
    'description' => 'Pull a component from the Bricksed library and place it on a page as native Bricks elements, optionally overriding its component properties everywhere or on chosen instances. Follows Bricksed\'s own insert rules: fresh element ids, the site\'s existing global classes and Bricks components are used as they are, only missing ones are added. The stored component is never modified. dry_run lists the properties and instances without placing anything.',
    'category' => 'bricksed',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'description' => 'Component id from the library tree.'],
            'target_post_id' => ['type' => 'integer', 'description' => 'The post, page or Bricks template to place it on. Not needed for mode new_page.'],
            'mode' => ['type' => 'string', 'enum' => ['append', 'replace_section', 'new_page'], 'description' => 'append (default) adds it at the end. replace_section replaces where THIS component was placed on that page before, and appends if it was not there. new_page creates a draft.'],
            'new_page_title' => ['type' => 'string', 'description' => 'For new_page: the title of the draft to create.'],
            'new_page_type' => ['type' => 'string', 'description' => 'For new_page: the post type. Default page.'],
            'switch_editor' => ['type' => 'boolean', 'description' => 'Only after the user agreed: switch a page built in the WordPress editor to Bricks (its WordPress content stops showing).'],
            'properties' => ['type' => 'object', 'description' => 'Property label or id => value, set on every instance in this placement whose component declares it. The component definition on the site is not changed.'],
            'instances' => [
                'type' => 'array',
                'description' => 'Override properties on chosen instances only (one card of five). Name an instance by its ref as dry_run lists it ("Accordion Row #3": the component and its position), or by its label.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'ref' => ['type' => 'string', 'description' => 'Component label and position, e.g. "Accordion Row #3".'],
                        'label' => ['type' => 'string', 'description' => 'The instance label, when it has one.'],
                        'id' => ['type' => 'string', 'description' => 'Element id. Only stable for items saved as Bricks data; prefer ref.'],
                        'properties' => ['type' => 'object'],
                    ],
                    'required' => ['properties'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'description' => 'List the component\'s properties and instances, place nothing.'],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_bricksed_pro_reuse_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
            'instructions' => implode("\n", [
                'Run with dry_run true first to see the property labels and the instances, then place.',
                'properties sets a value everywhere it appears. For one instance among several — one card of five — use instances instead.',
                'Overrides are written onto this placement\'s instances; the component definition and the library are not changed. To change the component for everyone, use bricksed-manage action "update".',
                'replace_section only replaces an earlier placement of the same component; there is no way to aim at another section.',
                'A component is placed once per page in replace_section mode; append always adds another.',
            ]),
        ],
    ],
]);

/**
 * The Bricks components in a payload, with their properties.
 *
 * @param array<string, mixed> $bricks
 * @return array<string, array{label: string, properties: array<string, array<string, mixed>>}>
 */
function nibwp_bricksed_component_index(array $bricks): array
{
    $index = [];
    foreach ((array) ($bricks['components'] ?? []) as $component) {
        if (!is_array($component) || empty($component['id'])) {
            continue;
        }
        $properties = [];
        foreach ((array) ($component['properties'] ?? []) as $property) {
            if (is_array($property) && !empty($property['id'])) {
                $properties[(string) $property['id']] = $property;
            }
        }
        $index[(string) $component['id']] = [
            'label' => (string) ($component['elements'][0]['label'] ?? $component['id']),
            'properties' => $properties,
        ];
    }

    return $index;
}

/**
 * The property of a component a key names: by id, or by label regardless of
 * case and surrounding space.
 *
 * @param array<string, array<string, mixed>> $properties
 */
function nibwp_bricksed_property_id(array $properties, string $key): ?string
{
    if (isset($properties[$key])) {
        return $key;
    }
    $wanted = strtolower(trim($key));
    foreach ($properties as $id => $property) {
        if (strtolower(trim((string) ($property['label'] ?? ''))) === $wanted) {
            return (string) $id;
        }
    }

    return null;
}

/**
 * The instances in the placed tree, in order, with their component.
 *
 * @param array<string, mixed>                $bricks
 * @param array<string, array<string, mixed>> $index
 * @return array<int, array{ref: string, id: string, label: string, component: string, position: int}>
 */
function nibwp_bricksed_instances(array $bricks, array $index): array
{
    $out = [];
    $count = [];
    foreach ((array) $bricks['content'] as $element) {
        if (!is_array($element) || empty($element['cid'])) {
            continue;
        }
        $cid = (string) $element['cid'];
        $count[$cid] = ($count[$cid] ?? 0) + 1;
        $component = (string) ($index[$cid]['label'] ?? $cid);
        $out[] = [
            // Element ids are drawn afresh each time the shared format is
            // converted, so the component and its position are what name an
            // instance from one read to the next.
            'ref' => $component . ' #' . $count[$cid],
            'id' => (string) ($element['id'] ?? ''),
            'label' => (string) ($element['label'] ?? ''),
            'component' => $component,
            'position' => $count[$cid],
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_pro_reuse_execute(array $input)
{
    $id = (string) ($input['id'] ?? '');
    $mode = (string) ($input['mode'] ?? 'append');
    $target_post_id = (int) ($input['target_post_id'] ?? 0);
    $dry_run = !empty($input['dry_run']);

    if ($id === '') {
        return new WP_Error('bricksed_missing_id', __('An "id" is required. Search the library to find it.', 'nibwp'), ['status' => 400]);
    }

    if (!in_array($mode, NIBWP_BRICKSED_REUSE_MODES, true)) {
        return new WP_Error(
            'bricksed_invalid_mode',
            sprintf(
                /* translators: %s: allowed modes */
                __('"mode" must be one of: %s.', 'nibwp'),
                implode(', ', NIBWP_BRICKSED_REUSE_MODES)
            ),
            ['status' => 400]
        );
    }

    // Accepting an anchor and ignoring it would let "replace the hero" add a
    // second hero at the bottom while the answer said it had replaced the first.
    if (isset($input['section_anchor']) && (string) $input['section_anchor'] !== '') {
        return new WP_Error(
            'bricksed_section_anchor_unsupported',
            __('Placing into a named section is not supported. replace_section replaces where this same component was placed on the page before, and appends if it was not there. Leave section_anchor out.', 'nibwp'),
            ['status' => 400]
        );
    }

    if (!$dry_run) {
        if ($mode === 'new_page' && $target_post_id > 0) {
            return new WP_Error(
                'bricksed_conflicting_target',
                __('mode "new_page" creates a new page, so it takes no "target_post_id". Leave the id out to create a page, or use mode "append" to add to that one.', 'nibwp'),
                ['status' => 400]
            );
        }
        if ($target_post_id <= 0 && $mode !== 'new_page') {
            return new WP_Error(
                'bricksed_missing_target',
                __('A "target_post_id" is required. There is no default page to place a component on — or use mode "new_page" to create one.', 'nibwp'),
                ['status' => 400]
            );
        }
    }

    // Bricksed hands the item over as Bricks elements, converting the shared
    // library format on the way out, under its own permission and licence checks.
    $stored = nibwp_bricksed_request('/component/' . $id, 'GET', ['format' => 'bricks']);
    if (is_wp_error($stored)) {
        return $stored;
    }

    // The copy. Everything below operates on this, never on the stored item.
    $bricks = nibwp_bricksed_bricks_of(['bricks' => $stored]);
    if ($bricks === null || $bricks['content'] === []) {
        return new WP_Error('bricksed_nothing_to_place', __('This library item has no Bricks elements to place.', 'nibwp'), ['status' => 422]);
    }
    $bricks['globalClassesCategories'] = (array) ($stored['globalClassesCategories'] ?? []);

    $index = nibwp_bricksed_component_index($bricks);
    $instances = nibwp_bricksed_instances($bricks, $index);

    $available = static function () use ($index): array {
        $out = [];
        foreach ($index as $component) {
            foreach ($component['properties'] as $property) {
                $out[] = (string) ($property['label'] ?? $property['id']);
            }
        }

        return array_values(array_unique($out));
    };

    if ($dry_run) {
        $properties = [];
        foreach ($index as $cid => $component) {
            foreach ($component['properties'] as $pid => $property) {
                $properties[] = [
                    'component' => $component['label'],
                    'id' => $pid,
                    'label' => (string) ($property['label'] ?? ''),
                    'type' => (string) ($property['type'] ?? ''),
                    'default' => $property['default'] ?? '',
                ];
            }
        }

        return [
            'dry_run' => true,
            'id' => $id,
            'name' => (string) ($stored['name'] ?? $id),
            'elements' => count($bricks['content']),
            'global_classes' => count($bricks['globalClasses']),
            'properties' => $properties,
            'instances' => $instances,
            'message' => $index === []
                ? __('This item has no Bricks components, so it has no properties to override: it is placed as it is.', 'nibwp')
                : __('Nothing was placed. Use these property labels in "properties", and these instance refs in "instances".', 'nibwp'),
        ];
    }

    $overrides = (array) ($input['properties'] ?? []);
    $instance_overrides = array_values((array) ($input['instances'] ?? []));

    $unknown_property = static function (string $key) use ($available): WP_Error {
        $known = $available();

        return new WP_Error(
            'bricksed_unknown_property',
            sprintf(
                /* translators: 1: property, 2: the properties it has */
                __('This component has no property "%1$s". It declares: %2$s.', 'nibwp'),
                $key,
                $known === [] ? __('none', 'nibwp') : implode(', ', $known)
            ),
            ['status' => 400, 'available' => $known]
        );
    };

    foreach (array_keys($overrides) as $key) {
        $found = false;
        foreach ($index as $component) {
            if (nibwp_bricksed_property_id($component['properties'], (string) $key) !== null) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $unknown_property((string) $key);
        }
    }

    // Which element each instance override names: by ref, label or id.
    $targets = [];
    foreach ($instance_overrides as $override) {
        $override = (array) $override;
        $want_ref = strtolower(preg_replace('/\s+/', ' ', trim((string) ($override['ref'] ?? ''))) ?? '');
        $want_label = strtolower(trim((string) ($override['label'] ?? '')));
        $want_id = trim((string) ($override['id'] ?? ''));
        $hits = array_values(array_filter($instances, static function (array $instance) use ($want_ref, $want_label, $want_id): bool {
            if ($want_ref !== '') {
                return strtolower($instance['ref']) === $want_ref;
            }
            if ($want_label !== '') {
                return strtolower($instance['label']) === $want_label;
            }

            return $want_id !== '' && $instance['id'] === $want_id;
        }));
        if ($hits === []) {
            return new WP_Error(
                'bricksed_unknown_instance',
                sprintf(
                    /* translators: %s: the instances it has */
                    __('No instance matches that. Its instances are: %s.', 'nibwp'),
                    $instances === [] ? __('none', 'nibwp') : implode(', ', array_map(static function (array $i): string {
                        return '"' . $i['ref'] . '"' . ($i['label'] !== '' ? ' (label "' . $i['label'] . '")' : '');
                    }, $instances))
                ),
                ['status' => 400, 'available' => $instances]
            );
        }
        foreach ($hits as $hit) {
            $targets[$hit['id']][] = (array) ($override['properties'] ?? []);
        }
    }

    // Overrides go on this placement's instances, never on the definition:
    // a site keeps one copy of each Bricks component, shared by every page.
    foreach ($bricks['content'] as $i => $element) {
        if (!is_array($element) || empty($element['cid']) || !isset($index[(string) $element['cid']])) {
            continue;
        }
        $properties = $index[(string) $element['cid']]['properties'];
        $values = isset($element['properties']) && is_array($element['properties']) ? $element['properties'] : [];

        foreach ($overrides as $key => $value) {
            $pid = nibwp_bricksed_property_id($properties, (string) $key);
            if ($pid !== null) {
                $values[$pid] = $value;
            }
        }
        foreach ($targets[(string) ($element['id'] ?? '')] ?? [] as $set) {
            foreach ($set as $key => $value) {
                $pid = nibwp_bricksed_property_id($properties, (string) $key);
                if ($pid === null) {
                    return $unknown_property((string) $key);
                }
                $values[$pid] = $value;
            }
        }

        if ($values !== []) {
            $bricks['content'][$i]['properties'] = $values;
        }
    }

    $target = ['post_id' => $target_post_id, 'mode' => $mode, 'switch_editor' => !empty($input['switch_editor'])];
    foreach (['new_page_title', 'new_page_type'] as $optional) {
        if (isset($input[$optional]) && (string) $input[$optional] !== '') {
            $target[$optional] = (string) $input[$optional];
        }
    }

    $result = nibwp_bricksed_place($bricks, $target, $id);
    if (is_wp_error($result)) {
        return $result;
    }

    $result['source_component'] = $id;

    return $result;
}
