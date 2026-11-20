<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Will this component still work somewhere else?
 *
 * The etchwp-pro validator answers "is this well built". This answers the only
 * question a library adds: is anything in it true of this site alone. A post
 * id, a wp_block ref, an uploads URL and a field binding with no matching
 * property all work perfectly here and produce nothing at all on the next
 * site — which is the failure a library exists to prevent.
 */

/**
 * @param array<string, mixed> $payload
 * @return array{failed: array<int, array{rule:string,msg:string,path:string}>, warnings: array<int, array{rule:string,msg:string,path:string}>}
 */
function nibwp_etchedy_validate_portability(array $payload): array
{
    $failed = [];
    $warnings = [];

    $property_keys = [];
    foreach ((array) ($payload['components'] ?? []) as $component) {
        foreach ((array) ($component['properties'] ?? []) as $property) {
            if (!empty($property['key'])) {
                $property_keys[] = (string) $property['key'];
            }
        }
    }

    // 1. Post ids, block refs and unbound field bindings, anywhere in a tree.
    //
    // A ref is only a problem when nothing in the payload backs it. Etchedy
    // files point an instance at a definition the same file carries — "ref":
    // 947301 is a key in components{}, and the persister rewrites it to a
    // wp_block id on the way in — so those are the format working as designed.
    // A ref with no matching definition is a post id from this site.
    $defined_components = (array) ($payload['components'] ?? []);
    $id_attributes = ['data-post-id', 'data-page-id', 'data-term-id', 'data-user-id', 'postId', 'post_id'];
    $walk = static function ($node, string $path) use (&$walk, &$failed, $id_attributes, $property_keys, $defined_components): void {
        if (!is_array($node)) {
            return;
        }

        $attrs = (array) ($node['attrs'] ?? []);

        // A ref that is not a number or a string cannot be a key into anything —
        // and as an array it would be a TypeError in array_key_exists().
        $ref = $attrs['ref'] ?? null;
        $ref_is_dangling = is_scalar($ref)
            ? ((int) $ref > 0 && !array_key_exists($ref, $defined_components))
            : $ref !== null;
        if ($ref_is_dangling) {
            $failed[] = [
                'rule' => 'site_specific_id',
                'msg' => sprintf('attrs.ref %s points at a post on this site, not at a component this payload defines. On any other site that id is a different post or none at all. Add the definition under payload.components and reference it by its key.', is_scalar($ref) ? (string) $ref : '(not a number)'),
                'path' => $path . '.attrs.ref',
            ];
        }

        $attributes = (array) ($attrs['attributes'] ?? []);
        foreach ($id_attributes as $key) {
            if (isset($attributes[$key]) && (string) $attributes[$key] !== '') {
                $failed[] = [
                    'rule' => 'site_specific_id',
                    'msg' => sprintf('%s is a post id from this site. Expose it as a component property with a default so the next site can set its own.', $key),
                    'path' => $path . '.attrs.attributes.' . $key,
                ];
            }
        }

        // A field binding the component does not declare cannot resolve elsewhere.
        foreach (['data-bind', 'data-field', 'binding'] as $bind_key) {
            if (empty($attributes[$bind_key])) {
                continue;
            }
            $binding = (string) $attributes[$bind_key];
            $field = str_contains($binding, ':') ? substr($binding, (int) strpos($binding, ':') + 1) : $binding;
            if ($field !== '' && !in_array($field, $property_keys, true)) {
                $failed[] = [
                    'rule' => 'unbound_field',
                    'msg' => sprintf('"%s" is bound to a field this component does not declare. Add a property with key "%s" so the binding travels with it.', $binding, $field),
                    'path' => $path . '.attrs.attributes.' . $bind_key,
                ];
            }
        }

        foreach ((array) ($node['innerBlocks'] ?? []) as $i => $child) {
            $walk($child, $path . '.innerBlocks.' . $i);
        }
    };

    $walk($payload['gutenbergBlock'] ?? [], 'gutenbergBlock');

    foreach ((array) ($payload['components'] ?? []) as $key => $component) {
        foreach ((array) ($component['blocks'] ?? []) as $i => $block) {
            $walk($block, 'components.' . $key . '.blocks.' . $i);
        }
    }

    // 2. Absolute URLs into a WordPress install.
    //
    // JSON_UNESCAPED_SLASHES is not optional. wp_json_encode() escapes "/" as
    // "\/" by default, which turns every URL in the payload into text this
    // pattern cannot match — the check would pass on exactly the payloads it
    // exists to refuse.
    // __libraryMeta is Etchedy's bookkeeping, not content. Read back through
    // get, its preview path comes as an absolute .../wp-content/plugins/etchedy/
    // data/previews/... URL; scanning it flagged most of the official library.
    $scanned = $payload;
    unset($scanned['__libraryMeta']);
    $encoded = (string) wp_json_encode($scanned, JSON_UNESCAPED_SLASHES);
    //
    // Three shapes: an absolute URL into wp-content, a root-relative path into
    // it, and an uploads folder by its dated layout whatever the content
    // directory is called — WordPress lets it be renamed, and a relative path
    // into it is as dead elsewhere as an absolute one.
    $into_install = preg_match('#(?:https?:)?//[^"\')\s]+/wp-content/#i', $encoded)
        || preg_match('#["\'(=]\s*/wp-content/#i', $encoded)
        || preg_match('#/uploads/\d{4}/\d{2}/#', $encoded);
    if ($into_install) {
        $failed[] = [
            'rule' => 'site_specific_url',
            'msg' => 'An absolute wp-content URL points at this install\'s uploads. It will 404 on any other site. Use a component property for the image, or a CSS custom property with a fallback.',
            'path' => 'payload',
        ];
    }

    // 3. Properties: the difference between a library item and a snapshot.
    foreach ((array) ($payload['components'] ?? []) as $key => $component) {
        $properties = (array) ($component['properties'] ?? []);
        if ($properties === []) {
            $warnings[] = [
                'rule' => 'no_properties',
                'msg' => sprintf('Component "%s" declares no properties, so every value in it is fixed. Reusing it means editing it. Expose the text, images and links that would change between uses.', (string) ($component['name'] ?? $key)),
                'path' => 'components.' . $key . '.properties',
            ];
            continue;
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

    return ['failed' => $failed, 'warnings' => $warnings];
}
