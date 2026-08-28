<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Breakdance Pro — validator and recommender.
 *
 * Errors block a write. Warnings do not, because a rule that fires on a site
 * whose conventions differ from ours should inform rather than refuse.
 *
 * Every rule here exists because the failure it catches is silent in
 * Breakdance: an unknown element slug, a mistyped property path, a backslash
 * that arrived single-escaped through JSON — none of them error, they just
 * render nothing, and the user is left looking at a blank section wondering
 * what the agent did.
 */

/**
 * @param list<array<string, mixed>> $nodes Flat payload nodes.
 * @return array{errors: list<array>, warnings: list<array>, recommendations: list<array>}
 */
function nibwp_bdpro_validate(array $nodes, array $context = []): array
{
    $errors = [];
    $warnings = [];
    $recommendations = [];

    $registry_available = nibwp_bdpro_elements() !== [];
    $strict_tokens = nibwp_bdpro_has_token_layer();

    foreach ($nodes as $i => $node) {
        $ref = (string) ($node['ref'] ?? ('node_' . $i));
        $type = (string) ($node['type'] ?? '');
        $properties = (array) ($node['properties'] ?? []);

        // ── The slug itself ──────────────────────────────────────────────
        if ($type === '') {
            $errors[] = ['ref' => $ref, 'rule' => 'missing_type', 'message' => 'Node has no element type.'];
            continue;
        }

        // A single backslash survives PHP but not a JSON round trip, and the
        // result — "EssentialElementsHeading" — is not a registered element.
        // Caught explicitly because the message "unknown element" would send
        // the agent looking for the wrong problem.
        if (!str_contains($type, '\\') && preg_match('/^[A-Z][A-Za-z]*Elements[A-Z]/', $type)) {
            $errors[] = [
                'ref'     => $ref,
                'rule'    => 'lost_backslash',
                'message' => sprintf(
                    'Element type "%s" has lost its namespace backslash. Breakdance slugs look like EssentialElements\\Heading — in JSON that must be written "EssentialElements\\\\Heading".',
                    $type
                ),
            ];
            continue;
        }

        if ($registry_available && !nibwp_bdpro_element_exists($type)) {
            $suggestions = nibwp_bdpro_suggest_slugs($type);
            $errors[] = [
                'ref'     => $ref,
                'rule'    => 'unknown_element',
                'message' => sprintf('"%s" is not registered on this site.', $type),
                'did_you_mean' => $suggestions,
            ];
            continue;
        }

        // ── Property paths ───────────────────────────────────────────────
        if ($registry_available) {
            $allowed = nibwp_bdpro_element_property_paths($type);

            if ($allowed !== []) {
                foreach (nibwp_bdpro_leaf_paths($properties) as $path) {
                    if (nibwp_bdpro_path_allowed($path, $allowed)) {
                        continue;
                    }

                    // A warning, not an error: the schema walk cannot see
                    // repeater indices or conditionally-registered controls, so
                    // a false positive here must not block a legitimate build.
                    $warnings[] = [
                        'ref'     => $ref,
                        'rule'    => 'unknown_property',
                        'message' => sprintf('"%s" is not a control %s declares. Breakdance drops unknown properties silently.', $path, $type),
                    ];
                }
            }
        }

        // ── Inline styling ───────────────────────────────────────────────
        $flat = wp_json_encode($properties) ?: '';

        if (preg_match('/@media[^"]*\{/', $flat)) {
            $errors[] = [
                'ref'     => $ref,
                'rule'    => 'inline_media_query',
                'message' => 'Inline @media found. Breakdance stores per-breakpoint values on the control itself — set them there instead.',
            ];
        }

        if (preg_match('/"style"\s*:\s*"[^"]+"/', $flat)) {
            $warnings[] = [
                'ref'     => $ref,
                'rule'    => 'inline_style',
                'message' => 'A raw style string is set. Prefer the element\'s design controls so the builder can edit it.',
            ];
        }

        // ── Tokens ───────────────────────────────────────────────────────
        if ($strict_tokens) {
            foreach (nibwp_bdpro_tokenisable($properties) as $hit) {
                $recommendations[] = [
                    'ref'     => $ref,
                    'rule'    => 'use_token',
                    'message' => sprintf(
                        '%s is set to %s, which is exactly the variable "%s" already on this site. Use the variable so the section follows the palette when it changes.',
                        $hit['path'],
                        $hit['value'],
                        $hit['token']
                    ),
                ];
            }
        }

        // ── Raw markup where an element exists ───────────────────────────
        if (preg_match('/<iframe[^>]*(youtube|vimeo)/i', $flat)) {
            $recommendations[] = [
                'ref'     => $ref,
                'rule'    => 'use_video_element',
                'message' => 'A raw video iframe was embedded. Breakdance has a Video element that handles lazy loading and aspect ratio.',
            ];
        }

        if (preg_match('/<form[\s>]/i', $flat)) {
            $recommendations[] = [
                'ref'     => $ref,
                'rule'    => 'use_form_element',
                'message' => 'Raw <form> markup was embedded. Use Breakdance\'s form builder element so submissions are captured and actions run.',
            ];
        }

        // ── Accessibility ────────────────────────────────────────────────
        $short = strtolower(nibwp_bdpro_short_name($type));

        if ($short === 'image') {
            $alt = nibwp_bdpro_dig($properties, ['content.image.alt', 'content.alt', 'image.alt', 'alt']);
            if ($alt === null || trim((string) $alt) === '') {
                $warnings[] = [
                    'ref'     => $ref,
                    'rule'    => 'missing_alt',
                    'message' => 'Image has no alt text. Describe it, or mark it decorative deliberately.',
                ];
            }
        }
    }

    // ── Whole-payload rules ──────────────────────────────────────────────
    $roots = array_filter($nodes, static fn(array $n): bool => ($n['parent'] ?? null) === null || ($n['parent'] ?? '') === '');

    foreach ($roots as $root) {
        $type = (string) ($root['type'] ?? '');
        if ($type !== '' && !nibwp_bdpro_is_container($type)) {
            $warnings[] = [
                'ref'     => (string) ($root['ref'] ?? ''),
                'rule'    => 'bare_root_element',
                'message' => sprintf('%s sits at the top level. Wrap page content in a Section so spacing and width behave as they do elsewhere.', $type),
            ];
        }
    }

    $headings = nibwp_bdpro_heading_levels($nodes);
    if ($headings !== [] && !in_array(1, $headings, strict: true) && ($context['template_role'] ?? '') === 'page') {
        $warnings[] = [
            'ref'     => '',
            'rule'    => 'no_h1',
            'message' => 'No H1 on the page. One top-level heading should describe what the page is.',
        ];
    }

    foreach (nibwp_bdpro_detect_repetition($nodes) as $repeat) {
        $recommendations[] = [
            'ref'     => $repeat['refs'][0] ?? '',
            'rule'    => 'use_loop',
            'message' => sprintf(
                '%d sibling nodes repeat the same structure. A Posts Loop bound to a post type would keep them in one place instead of %d copies to edit by hand.',
                $repeat['count'],
                $repeat['count']
            ),
            'refs'    => $repeat['refs'],
            'loop_elements' => nibwp_bdpro_loop_elements(),
        ];
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'recommendations' => $recommendations];
}

/**
 * Does a dotted path match one the element declares?
 *
 * Numeric segments are collapsed first, so `content.items.0.title` is checked
 * against `content.items.title` — repeaters index at runtime and the schema
 * never lists the indices.
 *
 * @param list<string> $allowed
 */
function nibwp_bdpro_path_allowed(string $path, array $allowed): bool
{
    $normalised = preg_replace('/\.\d+(\.|$)/', '$1', $path) ?? $path;

    foreach ($allowed as $candidate) {
        if ($candidate === $normalised || $candidate === $path) {
            return true;
        }
        // A declared leaf may itself hold a structure (a color control with
        // its own sub-keys), so anything beneath a known path is accepted.
        if (str_starts_with($normalised, $candidate . '.')) {
            return true;
        }
    }

    return false;
}

/** Every leaf path in a nested property array, dotted. */
function nibwp_bdpro_leaf_paths(array $properties, string $prefix = ''): array
{
    $paths = [];

    foreach ($properties as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value) && $value !== []) {
            $paths = array_merge($paths, nibwp_bdpro_leaf_paths($value, $path));
        } else {
            $paths[] = $path;
        }
    }

    return $paths;
}

/** Read the first of several candidate dotted paths that exists. */
function nibwp_bdpro_dig(array $properties, array $paths)
{
    foreach ($paths as $path) {
        $cursor = $properties;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                $cursor = null;
                break;
            }
            $cursor = $cursor[$segment];
        }
        if ($cursor !== null) {
            return $cursor;
        }
    }

    return null;
}

/** Heading levels used across a payload. */
function nibwp_bdpro_heading_levels(array $nodes): array
{
    $levels = [];

    foreach ($nodes as $node) {
        if (strtolower(nibwp_bdpro_short_name((string) ($node['type'] ?? ''))) !== 'heading') {
            continue;
        }
        $tag = nibwp_bdpro_dig((array) ($node['properties'] ?? []), ['content.tag', 'content.heading.tag', 'tag']);
        if (is_string($tag) && preg_match('/^h([1-6])$/i', $tag, $m)) {
            $levels[] = (int) $m[1];
        }
    }

    return $levels;
}

/**
 * Find sibling nodes that repeat the same shape.
 *
 * Signature is element type plus child type sequence, which is enough to spot
 * three identical cards without treating two unrelated sections as a pattern.
 *
 * @return list<array{count:int, refs:list<string>, signature:string}>
 */
function nibwp_bdpro_detect_repetition(array $nodes, int $threshold = 3): array
{
    $children = [];
    foreach ($nodes as $node) {
        $parent = (string) ($node['parent'] ?? '');
        $children[$parent][] = $node;
    }

    $by_ref = [];
    foreach ($nodes as $node) {
        $by_ref[(string) ($node['ref'] ?? '')] = $node;
    }

    $signature = static function (array $node) use ($children): string {
        $ref = (string) ($node['ref'] ?? '');
        $parts = [(string) ($node['type'] ?? '')];
        foreach ($children[$ref] ?? [] as $child) {
            $parts[] = (string) ($child['type'] ?? '');
        }

        return implode('>', $parts);
    };

    $out = [];

    foreach ($children as $siblings) {
        $groups = [];
        foreach ($siblings as $node) {
            $groups[$signature($node)][] = (string) ($node['ref'] ?? '');
        }

        foreach ($groups as $sig => $refs) {
            if (count($refs) >= $threshold) {
                $out[] = ['count' => count($refs), 'refs' => $refs, 'signature' => $sig];
            }
        }
    }

    return $out;
}
