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
 * the skill loader checks that Etchedy is present, not that its integration
 * card is switched on. Each guard names a function from its own file.
 */
$nibwp_etchedy_lib = dirname(__DIR__) . '/lib/';
if (!function_exists('nibwp_etchedy_available')) {
    require_once $nibwp_etchedy_lib . 'library-client.php';
}
if (!function_exists('nibwp_etchedy_normalize_findings')) {
    require_once $nibwp_etchedy_lib . 'findings.php';
}
unset($nibwp_etchedy_lib);

wp_register_ability('nibwp/etchedy-pro-reuse-component', [
    'label' => __('Etchedy Pro — Reuse Component', 'nibwp'),
    'description' => 'Pull a component from the Etchedy library and place it on a page, optionally overriding its properties everywhere or on named instances. The stored component is never modified. Placing the same component on a page twice replaces the earlier placement.',
    'category' => 'etchedy',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'description' => 'Component id from the library tree.'],
            'target_post_id' => ['type' => 'integer', 'description' => 'The post or page to place it on. Not needed for mode new_page.'],
            'mode' => ['type' => 'string', 'enum' => ['append', 'replace_section', 'new_page'], 'description' => 'append (default) adds it at the end. replace_section replaces where THIS component was placed on that page before, and appends if it was not there. new_page creates a draft.'],
            'new_page_title' => ['type' => 'string', 'description' => 'For new_page: the title of the draft to create.'],
            'new_page_type' => ['type' => 'string', 'description' => 'For new_page: the post type. Default page.'],
            'properties' => ['type' => 'object', 'description' => 'key => value, set on every instance in this placement whose component declares the key. The stored definition, which other pages share, is not changed. Keys must be declared by the component.'],
            'instances' => [
                'type' => 'array',
                'description' => 'Override properties on named instances only.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'The instance\'s name in the tree, exactly as get shows it.'],
                        'properties' => ['type' => 'object'],
                    ],
                    'required' => ['name', 'properties'],
                ],
            ],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_etchedy_pro_reuse_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
            'instructions' => implode("\n", [
                'Call nibwp/etchedy-manage with action "get" first if you want to show the user the properties and instance names before placing it.',
                'properties sets a value everywhere it appears. For one instance among several — one card of five — use instances instead.',
                'Overrides are written onto this placement\'s instances; the shared definition and the library are not changed. To change the component for everyone, use etchedy-manage action "update".',
                'There is no way to target an arbitrary section: replace_section only replaces an earlier placement of the same component.',
                'A component is placed once per page. Placing the same one again replaces the earlier placement rather than adding a second.',
            ]),
        ],
    ],
]);

const NIBWP_ETCHEDY_REUSE_MODES = ['append', 'replace_section', 'new_page'];

/**
 * Every property key any component in the payload declares.
 *
 * @param array<string, mixed> $payload
 * @return array<int, string>
 */
function nibwp_etchedy_declared_property_keys(array $payload): array
{
    $keys = [];
    foreach ((array) ($payload['components'] ?? []) as $component) {
        foreach ((array) ($component['properties'] ?? []) as $property) {
            if (!empty($property['key'])) {
                $keys[] = (string) $property['key'];
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Apply a function to every etch/component instance in a tree, by reference.
 *
 * @param mixed    $node
 * @param callable $apply Receives the instance block by reference.
 */
function nibwp_etchedy_each_instance(&$node, callable $apply): void
{
    if (!is_array($node)) {
        return;
    }

    if (($node['blockName'] ?? '') === 'etch/component') {
        $apply($node);
    }

    if (isset($node['innerBlocks']) && is_array($node['innerBlocks'])) {
        foreach ($node['innerBlocks'] as $i => $_child) {
            nibwp_etchedy_each_instance($node['innerBlocks'][$i], $apply);
        }
    }
}

/**
 * Every instance's name in the payload, from the main tree and from inside
 * component definitions, in document order.
 *
 * @param array<string, mixed> $payload
 * @return array<int, string>
 */
function nibwp_etchedy_instance_names(array $payload): array
{
    $names = [];
    $collect = static function (array &$block) use (&$names): void {
        $names[] = (string) ($block['attrs']['metadata']['name'] ?? '');
    };

    $tree = $payload['gutenbergBlock'] ?? [];
    nibwp_etchedy_each_instance($tree, $collect);
    foreach ((array) ($payload['components'] ?? []) as $component) {
        foreach ((array) ($component['blocks'] ?? []) as $block) {
            nibwp_etchedy_each_instance($block, $collect);
        }
    }

    return array_values(array_filter($names, static function (string $n): bool {
        return $n !== '';
    }));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_etchedy_pro_reuse_execute(array $input)
{
    $id = (string) ($input['id'] ?? '');
    $mode = (string) ($input['mode'] ?? 'append');
    $target_post_id = (int) ($input['target_post_id'] ?? 0);

    // Refused here rather than left to the persister, which quietly turns an
    // unknown mode into "append" — not what someone who typed a typo meant.
    if (!in_array($mode, NIBWP_ETCHEDY_REUSE_MODES, true)) {
        return new WP_Error(
            'etchedy_invalid_mode',
            sprintf(__('"mode" must be one of: %s.', 'nibwp'), implode(', ', NIBWP_ETCHEDY_REUSE_MODES)),
            ['status' => 400]
        );
    }

    // The persister has no notion of a section anchor. Accepting one and
    // ignoring it would let "replace the hero" add a second hero at the bottom
    // while the answer said it had replaced the first.
    if (isset($input['section_anchor']) && (string) $input['section_anchor'] !== '') {
        return new WP_Error(
            'etchedy_section_anchor_unsupported',
            __('Placing into a named section is not supported. replace_section replaces where this same component was placed on the page before, and appends if it was not there. Leave section_anchor out.', 'nibwp'),
            ['status' => 400]
        );
    }

    // new_page makes a page. Given a target id as well, the persister would
    // append to that post and create nothing.
    if ($mode === 'new_page' && $target_post_id > 0) {
        return new WP_Error(
            'etchedy_conflicting_target',
            __('mode "new_page" creates a new page, so it takes no "target_post_id". Leave the id out to create a page, or use mode "append" to add to that one.', 'nibwp'),
            ['status' => 400]
        );
    }

    if ($target_post_id <= 0 && $mode !== 'new_page') {
        return new WP_Error(
            'etchedy_missing_target',
            __('A "target_post_id" is required. There is no default page to place a component on — or use mode "new_page" to create one.', 'nibwp'),
            ['status' => 400]
        );
    }

    $missing = nibwp_etchedy_require_etchwp();
    if (is_wp_error($missing)) {
        return $missing;
    }

    $stored = nibwp_etchedy_request('/component/' . $id, 'GET');
    if (is_wp_error($stored)) {
        return $stored;
    }

    // The copy. Everything below operates on this, never on the stored array.
    //
    // __libraryMeta stays. The persister re-runs the etchwp validator on entry
    // and that validator requires it, so a copy without it fails every reuse
    // with "__libraryMeta.brand is required" — and the persister also derives
    // its idempotent component id from the name, brand and category in it.
    $payload = $stored;

    $known = nibwp_etchedy_declared_property_keys($payload);
    $overrides = (array) ($input['properties'] ?? []);
    $instance_overrides = (array) ($input['instances'] ?? []);

    $unknown_message = static function (string $key) use ($known): WP_Error {
        return new WP_Error(
            'etchedy_unknown_property',
            sprintf(
                __('This component has no property "%1$s". It declares: %2$s.', 'nibwp'),
                $key,
                $known === [] ? __('none', 'nibwp') : implode(', ', $known)
            ),
            ['status' => 400, 'available' => $known]
        );
    };

    foreach (array_keys($overrides) as $key) {
        if (!in_array((string) $key, $known, true)) {
            return $unknown_message((string) $key);
        }
    }

    $available_instances = nibwp_etchedy_instance_names($payload);
    foreach ($instance_overrides as $override) {
        $override = (array) $override;
        $name = (string) ($override['name'] ?? '');

        if (!in_array($name, $available_instances, true)) {
            return new WP_Error(
                'etchedy_unknown_instance',
                sprintf(
                    __('This component has no instance named "%1$s". Its instances are: %2$s.', 'nibwp'),
                    $name,
                    $available_instances === [] ? __('none', 'nibwp') : implode(', ', $available_instances)
                ),
                ['status' => 400, 'available' => $available_instances]
            );
        }

        foreach (array_keys((array) ($override['properties'] ?? [])) as $key) {
            if (!in_array((string) $key, $known, true)) {
                return $unknown_message((string) $key);
            }
        }
    }

    // Global overrides go on this placement's instances, never on the
    // definition. The persister stores each definition as one site-wide
    // wp_block post, so changing its default here would change what every
    // other page using it falls back to.
    //
    // An instance gets the value when its component declares the key — added
    // if the instance was relying on the default — or when it already carries
    // the key.
    if ($overrides !== []) {
        $declares = [];
        foreach ((array) ($payload['components'] ?? []) as $component_key => $component) {
            foreach ((array) ($component['properties'] ?? []) as $property) {
                if (!empty($property['key'])) {
                    $declares[(string) $component_key][(string) $property['key']] = true;
                }
            }
        }

        $set_everywhere = static function (array &$block) use ($overrides, $declares): void {
            $ref = $block['attrs']['ref'] ?? null;
            $ref_key = is_scalar($ref) ? (string) $ref : '';
            foreach ($overrides as $key => $value) {
                $key = (string) $key;
                $carries = isset($block['attrs']['attributes']) && array_key_exists($key, (array) $block['attrs']['attributes']);
                if ($carries || isset($declares[$ref_key][$key])) {
                    $block['attrs']['attributes'][$key] = $value;
                }
            }
        };

        nibwp_etchedy_each_instance($payload['gutenbergBlock'], $set_everywhere);
        foreach (array_keys((array) ($payload['components'] ?? [])) as $component_key) {
            foreach (array_keys((array) ($payload['components'][$component_key]['blocks'] ?? [])) as $b) {
                nibwp_etchedy_each_instance($payload['components'][$component_key]['blocks'][$b], $set_everywhere);
            }
        }
    }

    // Instance overrides: only the instance with that name.
    foreach ($instance_overrides as $override) {
        $override = (array) $override;
        $name = (string) ($override['name'] ?? '');
        $values = (array) ($override['properties'] ?? []);

        $set_named = static function (array &$block) use ($name, $values): void {
            if ((string) ($block['attrs']['metadata']['name'] ?? '') !== $name) {
                return;
            }
            foreach ($values as $key => $value) {
                $block['attrs']['attributes'][(string) $key] = $value;
            }
        };

        nibwp_etchedy_each_instance($payload['gutenbergBlock'], $set_named);
        foreach (array_keys((array) ($payload['components'] ?? [])) as $component_key) {
            foreach (array_keys((array) ($payload['components'][$component_key]['blocks'] ?? [])) as $b) {
                nibwp_etchedy_each_instance($payload['components'][$component_key]['blocks'][$b], $set_named);
            }
        }
    }

    $target = ['post_id' => $target_post_id, 'mode' => $mode];
    foreach (['new_page_title', 'new_page_type'] as $optional) {
        if (isset($input[$optional]) && (string) $input[$optional] !== '') {
            $target[$optional] = (string) $input[$optional];
        }
    }

    $result = nibwp_etchwp_persist_payload($payload, $target);
    if (is_wp_error($result)) {
        // The persister holds every placement to the EtchWP build rules, with
        // no bypass. A stored component that predates them — including much of
        // the official library, which uses its own class prefix, literal
        // colours and clamp() type — is refused. Say that plainly: the request
        // was fine, the component is what does not pass.
        if ($result->get_error_code() === 'persist_validation_failed') {
            $data = (array) $result->get_error_data();

            return new WP_Error(
                'etchedy_component_not_buildable',
                __('This component does not pass the EtchWP build rules that every page placement is held to, so it was not placed. Nothing on the page changed. To use it, rebuild it with the EtchWP Pro skill (refine-component) and save the result as your own library component.', 'nibwp'),
                [
                    'status' => 422,
                    'failed' => nibwp_etchedy_normalize_findings((array) ($data['failed'] ?? [])),
                    'warnings' => nibwp_etchedy_normalize_findings((array) ($data['warnings'] ?? [])),
                ]
            );
        }

        return $result;
    }

    $result['source_component'] = $id;

    return $result;
}
