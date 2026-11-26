<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Will this component still work somewhere else?
 *
 * The bricks-pro validator answers "is this well built". This answers the only
 * question a library adds: is anything in it true of this site alone. An
 * attachment id, an internal link to post 42, a global class only this site
 * defines, an uploads URL — each works perfectly here and produces nothing, or
 * the wrong thing, on the next site. That is the failure a library exists to
 * prevent.
 */

/** Settings keys whose numeric value is an id of something on this site. */
const NIBWP_BRICKSED_ID_KEYS = [
    'postId', 'post_id', 'pageId', 'page_id', 'templateId', 'template', 'termId', 'term_id',
    'userId', 'user_id', 'menu', 'formId', 'form_id', 'attachmentId', 'attachment_id', 'imageId',
];

/** Query keys that list or name posts, terms or people of this site. */
const NIBWP_BRICKSED_QUERY_ID_KEYS = ['post__in', 'post__not_in', 'post_parent', 'post_parent__in', 'post_parent__not_in', 'author', 'author__in', 'author__not_in'];

/**
 * @param array<string, mixed> $payload A stored block: Bricks data, or the shared format.
 * @return array{failed: array<int, array{rule:string,msg:string,path:string}>, warnings: array<int, array{rule:string,msg:string,path:string}>}
 */
function nibwp_bricksed_validate_portability(array $payload): array
{
    $failed = [];
    $warnings = [];

    $bricks = nibwp_bricksed_bricks_of($payload);
    if ($bricks !== null) {
        nibwp_bricksed_portability_bricks($bricks, $failed, $warnings);
    } else {
        nibwp_bricksed_portability_shared($payload, $failed, $warnings);
    }

    // Absolute URLs into a WordPress install, in either shape.
    //
    // JSON_UNESCAPED_SLASHES is not optional: escaped as "\/", no URL in the
    // payload would match. __libraryMeta is Bricksed's bookkeeping, not
    // content; read back through get, its preview comes as an absolute
    // .../wp-content/plugins/bricksed/data/previews/... URL.
    $scanned = $payload;
    unset($scanned['__libraryMeta']);
    $encoded = (string) wp_json_encode($scanned, JSON_UNESCAPED_SLASHES);
    $into_install = preg_match('#(?:https?:)?//[^"\')\s]+/wp-content/#i', $encoded)
        || preg_match('#["\'(=]\s*/wp-content/#i', $encoded)
        || preg_match('#/uploads/\d{4}/\d{2}/#', $encoded);
    if ($into_install) {
        $failed[] = [
            'rule' => 'site_specific_url',
            'msg' => 'A URL points into this install\'s wp-content (usually an uploaded image). It will 404 on any other site. Make the image a component property, or use a CSS custom property with a fallback.',
            'path' => 'payload',
        ];
    }

    return ['failed' => $failed, 'warnings' => $warnings];
}

/**
 * The Bricks-data rules.
 *
 * @param array<string, mixed>                                  $bricks
 * @param array<int, array{rule:string,msg:string,path:string}> $failed
 * @param array<int, array{rule:string,msg:string,path:string}> $warnings
 */
function nibwp_bricksed_portability_bricks(array $bricks, array &$failed, array &$warnings): void
{
    $class_ids = [];
    foreach ((array) $bricks['globalClasses'] as $class) {
        if (is_array($class) && isset($class['id'])) {
            $class_ids[(string) $class['id']] = true;
        }
    }

    $components = (array) ($bricks['components'] ?? []);
    $component_ids = [];
    foreach ($components as $component) {
        if (is_array($component) && isset($component['id'])) {
            $component_ids[(string) $component['id']] = true;
        }
    }

    $check_element = static function ($element, string $path) use (&$failed, &$warnings, $class_ids, $component_ids): void {
        if (!is_array($element)) {
            return;
        }

        // An instance of a component the payload does not carry.
        if (!empty($element['cid']) && !isset($component_ids[(string) $element['cid']])) {
            $failed[] = [
                'rule' => 'site_specific_id',
                'msg' => sprintf('This element is an instance of Bricks component "%s", which the payload does not include. On another site it renders nothing. Include the component definition under bricks.components.', (string) $element['cid']),
                'path' => $path . '.cid',
            ];
        }

        $settings = $element['settings'] ?? [];
        if (!is_array($settings)) {
            return;
        }

        // A global class only this site defines.
        foreach ((array) ($settings['_cssGlobalClasses'] ?? []) as $i => $class_id) {
            if (is_scalar($class_id) && !isset($class_ids[(string) $class_id])) {
                $failed[] = [
                    'rule' => 'site_specific_class',
                    'msg' => sprintf('Global class "%s" is used here but not included in bricks.globalClasses. It exists on this site only, so the element arrives unstyled anywhere else. Include the class.', (string) $class_id),
                    'path' => $path . '.settings._cssGlobalClasses.' . $i,
                ];
            }
        }

        nibwp_bricksed_scan_settings($settings, $path . '.settings', $failed, $warnings);
    };

    foreach ((array) $bricks['content'] as $i => $element) {
        $check_element($element, 'bricks.content.' . $i);
    }

    foreach ($components as $c => $component) {
        if (!is_array($component)) {
            continue;
        }
        foreach ((array) ($component['elements'] ?? []) as $i => $element) {
            $check_element($element, 'bricks.components.' . $c . '.elements.' . $i);
        }
    }

    // Properties: the difference between a library item and a snapshot.
    if ($components === []) {
        $warnings[] = [
            'rule' => 'no_properties',
            'msg' => 'Nothing in this payload is a Bricks component, so every text, image and link in it is fixed. Reusing it means editing it. Make the part that repeats or varies a component and expose what changes as properties.',
            'path' => 'bricks.components',
        ];
    }
    foreach ($components as $c => $component) {
        if (!is_array($component)) {
            continue;
        }
        $label = (string) ($component['elements'][0]['label'] ?? $component['id'] ?? $c);
        $properties = (array) ($component['properties'] ?? []);
        if ($properties === []) {
            $warnings[] = [
                'rule' => 'no_properties',
                'msg' => sprintf('Component "%s" declares no properties, so every value in it is fixed. Expose the text, images and links that would change between uses.', $label),
                'path' => 'bricks.components.' . $c . '.properties',
            ];
            continue;
        }
        foreach ($properties as $p => $property) {
            if (is_array($property) && !array_key_exists('default', $property)) {
                $warnings[] = [
                    'rule' => 'property_no_default',
                    'msg' => sprintf('Property "%s" has no default, so a fresh instance renders it blank. Give it one; use "" when it is meant to start empty.', (string) ($property['label'] ?? $property['id'] ?? $p)),
                    'path' => 'bricks.components.' . $c . '.properties.' . $p,
                ];
            }
        }
    }
}

/**
 * Walk element settings for ids and custom-field tags of this site.
 *
 * @param array<mixed>                                          $settings
 * @param array<int, array{rule:string,msg:string,path:string}> $failed
 * @param array<int, array{rule:string,msg:string,path:string}> $warnings
 */
function nibwp_bricksed_scan_settings(array $settings, string $path, array &$failed, array &$warnings): void
{
    // A media-library image: Bricks keeps its attachment id beside the URL.
    if (isset($settings['id']) && is_numeric($settings['id']) && (int) $settings['id'] > 0
        && (isset($settings['url']) || isset($settings['filename']) || isset($settings['full']))) {
        $failed[] = [
            'rule' => 'site_specific_id',
            'msg' => sprintf('Image attachment %d is from this site\'s media library; on another site that id is a different file or none. Make the image a component property, or use an external URL.', (int) $settings['id']),
            'path' => $path . '.id',
        ];
    }

    // An internal link to a post of this site.
    if (($settings['type'] ?? '') === 'internal' && !empty($settings['postId'])) {
        $failed[] = [
            'rule' => 'site_specific_id',
            'msg' => sprintf('Internal link to post %s on this site. Use an external or relative URL, or make the link a component property.', (string) $settings['postId']),
            'path' => $path . '.postId',
        ];
    }

    foreach ($settings as $key => $value) {
        $key_path = $path . '.' . $key;

        if (is_string($key) && in_array($key, NIBWP_BRICKSED_ID_KEYS, true) && is_numeric($value) && (int) $value > 0
            && !(($settings['type'] ?? '') === 'internal' && $key === 'postId')) {
            $failed[] = [
                'rule' => 'site_specific_id',
                'msg' => sprintf('%s %d is an id of something on this site (a post, template, term, menu or form). On another site it points at something else or nothing. Expose it as a component property, or leave it for the person placing the component.', $key, (int) $value),
                'path' => $key_path,
            ];
        }

        if ($key === 'query' && is_array($value)) {
            foreach (NIBWP_BRICKSED_QUERY_ID_KEYS as $query_key) {
                $ids = $value[$query_key] ?? null;
                $has_ids = is_array($ids) ? $ids !== [] : (is_numeric($ids) && (int) $ids > 0);
                if ($has_ids) {
                    $failed[] = [
                        'rule' => 'site_specific_id',
                        'msg' => sprintf('The query names posts or people of this site by id (%s). Query by post type, taxonomy or field instead, so the loop fills itself on any site.', $query_key),
                        'path' => $key_path . '.' . $query_key,
                    ];
                }
            }
        }

        if (is_string($value) && preg_match_all('/\{(acf|mb|metabox|jet|pods|cf|toolset|post_meta|term_meta|wp_user_meta|wp:user_meta)[_:]([a-z0-9_\-:]+)\}/i', $value, $tags, PREG_SET_ORDER)) {
            foreach ($tags as $tag) {
                $warnings[] = [
                    'rule' => 'site_field_binding',
                    'msg' => sprintf('"%s" reads a custom field of this site. Another site needs a field of the same name, or the text comes out empty. Say so in the description, or expose the value as a component property.', $tag[0]),
                    'path' => $key_path,
                ];
            }
        }

        if (is_array($value)) {
            nibwp_bricksed_scan_settings($value, $key_path, $failed, $warnings);
        }
    }
}

/**
 * The shared-format rules: refs, post ids and field bindings in a block tree.
 *
 * @param array<string, mixed>                                  $payload
 * @param array<int, array{rule:string,msg:string,path:string}> $failed
 * @param array<int, array{rule:string,msg:string,path:string}> $warnings
 */
function nibwp_bricksed_portability_shared(array $payload, array &$failed, array &$warnings): void
{
    $defined = (array) ($payload['components'] ?? []);
    $property_keys = [];
    foreach ($defined as $component) {
        foreach ((array) ($component['properties'] ?? []) as $property) {
            if (!empty($property['key'])) {
                $property_keys[] = (string) $property['key'];
            }
        }
    }

    $id_attributes = ['data-post-id', 'data-page-id', 'data-term-id', 'data-user-id', 'postId', 'post_id'];
    $walk = static function ($node, string $path) use (&$walk, &$failed, $id_attributes, $property_keys, $defined): void {
        if (!is_array($node)) {
            return;
        }
        $attrs = (array) ($node['attrs'] ?? []);

        // A ref is fine when the payload defines what it points at.
        $ref = $attrs['ref'] ?? null;
        $dangling = is_scalar($ref) ? ((int) $ref > 0 && !array_key_exists($ref, $defined)) : $ref !== null;
        if ($dangling) {
            $failed[] = [
                'rule' => 'site_specific_id',
                'msg' => sprintf('attrs.ref %s points at a post on this site, not at a component this payload defines. Add the definition under components and reference it by its key.', is_scalar($ref) ? (string) $ref : '(not a number)'),
                'path' => $path . '.attrs.ref',
            ];
        }

        $attributes = (array) ($attrs['attributes'] ?? []);
        foreach ($id_attributes as $key) {
            if (isset($attributes[$key]) && (string) $attributes[$key] !== '') {
                $failed[] = [
                    'rule' => 'site_specific_id',
                    'msg' => sprintf('%s is a post id from this site. Expose it as a component property with a default.', $key),
                    'path' => $path . '.attrs.attributes.' . $key,
                ];
            }
        }

        foreach (['data-bind', 'data-field', 'binding'] as $bind_key) {
            if (empty($attributes[$bind_key])) {
                continue;
            }
            $binding = (string) $attributes[$bind_key];
            $field = str_contains($binding, ':') ? substr($binding, (int) strpos($binding, ':') + 1) : $binding;
            if ($field !== '' && !in_array($field, $property_keys, true)) {
                $failed[] = [
                    'rule' => 'unbound_field',
                    'msg' => sprintf('"%s" is bound to a field this component does not declare. Add a property with key "%s".', $binding, $field),
                    'path' => $path . '.attrs.attributes.' . $bind_key,
                ];
            }
        }

        foreach ((array) ($node['innerBlocks'] ?? []) as $i => $child) {
            $walk($child, $path . '.innerBlocks.' . $i);
        }
    };

    $walk($payload['gutenbergBlock'] ?? [], 'gutenbergBlock');
    foreach ($defined as $key => $component) {
        foreach ((array) ($component['blocks'] ?? []) as $i => $block) {
            $walk($block, 'components.' . $key . '.blocks.' . $i);
        }
        $properties = (array) ($component['properties'] ?? []);
        if ($properties === []) {
            $warnings[] = [
                'rule' => 'no_properties',
                'msg' => sprintf('Component "%s" declares no properties, so every value in it is fixed.', (string) ($component['name'] ?? $key)),
                'path' => 'components.' . $key . '.properties',
            ];
        }
        foreach ($properties as $i => $property) {
            if (!array_key_exists('default', (array) $property)) {
                $warnings[] = [
                    'rule' => 'property_no_default',
                    'msg' => sprintf('Property "%s" has no default, so a fresh instance renders blank.', (string) ($property['key'] ?? $i)),
                    'path' => 'components.' . $key . '.properties.' . $i,
                ];
            }
        }
    }
}
