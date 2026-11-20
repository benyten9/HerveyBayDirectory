<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/registry.php';

/**
 * Builderius Pro — markup in, authoring tree out.
 *
 * The authoring tree is the nested shape the integration takes as `tree`:
 * [{name, label?, settings?, children?}]. The integration flattens it into the
 * stored config — a map of modules keyed by id, linked by parent and index — and
 * that conversion is deliberately left to it, so there is exactly one place where
 * ids are minted and index is assigned.
 *
 * The mapping is narrow on purpose. Builderius's workhorse is HtmlElement with a
 * `tag`, so most markup maps one-to-one and keeps the structure the author wrote.
 * Where Builderius has a real module for something — inline SVG, a raw HTML
 * block, a form — that module is used, because the alternative renders but cannot
 * be edited afterwards.
 */

/**
 * HTML → authoring tree.
 *
 * @return array{tree:array<int,array<string,mixed>>,warnings:array<int,array<string,string>>,recommendations:array<int,array<string,string>>}
 */
function nibwp_builderius_pro_html_to_tree(string $html): array
{
    $result = ['tree' => [], 'warnings' => [], 'recommendations' => []];

    $html = trim($html);
    if ($html === '') {
        return $result;
    }

    if (!class_exists('DOMDocument')) {
        // Honest degradation: one HtmlCode module keeps the markup intact and
        // visible, and the warning says why it is not a module tree. Silently
        // returning an empty tree would be worse.
        $result['tree'] = [[
            'name'     => 'HtmlCode',
            'label'    => 'imported markup',
            'settings' => ['contentHtml' => $html],
        ]];
        $result['warnings'][] = [
            'rule'    => 'no_dom_extension',
            'message' => __('PHP\'s DOM extension is not available here, so the markup was kept whole in one HtmlCode module instead of being converted into editable modules.', 'nibwp'),
        ];

        return $result;
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    // The encoding hint is the reliable way to stop DOMDocument guessing Latin-1
    // and mangling anything accented.
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="nibwp-builderius-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $dom->getElementById('nibwp-builderius-root');
    if (!$root instanceof DOMElement) {
        $result['warnings'][] = [
            'rule'    => 'unparseable_markup',
            'message' => __('The markup could not be parsed. Pass a fragment of HTML, not a whole document with a doctype.', 'nibwp'),
        ];

        return $result;
    }

    $result['tree'] = nibwp_builderius_pro_convert_children($root, $result);

    return $result;
}

/**
 * @param array<string,mixed> $result
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_pro_convert_children(DOMElement $parent, array &$result): array
{
    $nodes = [];

    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text = trim((string) $child->textContent);
            if ($text !== '') {
                // Loose text between elements has nowhere to live in a module
                // tree, so it becomes a span rather than being dropped.
                $nodes[] = [
                    'name'     => 'HtmlElement',
                    'label'    => 'text',
                    'settings' => ['tag' => 'span', 'content' => $text],
                ];
            }
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }
        $node = nibwp_builderius_pro_convert_element($child, $result);
        if ($node !== null) {
            $nodes[] = $node;
        }
    }

    return $nodes;
}

/**
 * One element → one module.
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>|null
 */
function nibwp_builderius_pro_convert_element(DOMElement $element, array &$result): ?array
{
    $tag = strtolower($element->tagName);

    if (in_array($tag, ['script', 'style', 'noscript', 'link', 'meta'], true)) {
        $result['warnings'][] = [
            'rule'    => 'dropped_element',
            'message' => sprintf(
                /* translators: %s: HTML tag name */
                __('A <%s> was dropped. Scripts, styles and document metadata belong in the template\'s own settings or the theme, not in a module tree.', 'nibwp'),
                $tag
            ),
        ];

        return null;
    }

    if ($tag === 'svg') {
        return [
            'name'     => 'SvgCode',
            'label'    => nibwp_builderius_pro_label($element, 'icon'),
            'settings' => ['contentSvg' => nibwp_builderius_pro_outer_html($element)],
        ];
    }

    if ($tag === 'form') {
        $result['recommendations'][] = [
            'rule'    => 'form_module',
            'message' => __('A <form> became a SmartForm module. Its fields and its action are configured in Builderius, not in the markup — the original inputs were not carried over, because a SmartForm that renders hand-written inputs does not collect anything.', 'nibwp'),
        ];

        return [
            'name'     => 'SmartForm',
            'label'    => nibwp_builderius_pro_label($element, 'form'),
            'settings' => nibwp_builderius_pro_common_settings($element, $result),
        ];
    }

    if ($tag === 'table') {
        $result['warnings'][] = [
            'rule'    => 'raw_table',
            'message' => __('A <table> was kept whole in an HtmlCode module. Tables nest in a way a module tree makes painful to edit, and splitting one up rarely survives the next content change.', 'nibwp'),
        ];

        return [
            'name'     => 'HtmlCode',
            'label'    => nibwp_builderius_pro_label($element, 'table'),
            'settings' => ['contentHtml' => nibwp_builderius_pro_outer_html($element)],
        ];
    }

    if ($tag === 'nav') {
        $links = $element->getElementsByTagName('a');
        if ($links->length >= 3) {
            $result['recommendations'][] = [
                'rule'    => 'menu_module',
                'message' => sprintf(
                    /* translators: %d: number of links found */
                    __('This nav holds %d hand-written links. A MenuBuilder module renders a WordPress menu instead, so the client can change it without opening the builder.', 'nibwp'),
                    $links->length
                ),
            ];
        }
    }

    $settings = nibwp_builderius_pro_common_settings($element, $result);
    $settings['tag'] = $tag;

    if ($tag === 'img') {
        $src = (string) $element->getAttribute('src');
        if ($src !== '') {
            $settings['mediaUrl'] = $src;
        }
        $alt = (string) $element->getAttribute('alt');
        if ($alt !== '') {
            $settings['attrAlt'] = $alt;
        } else {
            $result['warnings'][] = [
                'rule'    => 'missing_alt',
                'message' => sprintf(
                    /* translators: %s: image source */
                    __('The image %s has no alt text. Builderius has a setting for it (attrAlt) and a screen reader has nothing without it.', 'nibwp'),
                    $src !== '' ? $src : __('(no src)', 'nibwp')
                ),
            ];
        }
        unset($settings['content']);

        return [
            'name'     => 'HtmlElement',
            'label'    => nibwp_builderius_pro_label($element, 'image'),
            'settings' => $settings,
        ];
    }

    $children = nibwp_builderius_pro_convert_children($element, $result);

    // A node with element children keeps them; a node with only text keeps the
    // text in the `content` setting. Doing both would render the text twice.
    if ($children === []) {
        $text = trim((string) $element->textContent);
        if ($text !== '') {
            $settings['content'] = $text;
        }

        return [
            'name'     => 'HtmlElement',
            'label'    => nibwp_builderius_pro_label($element, $tag),
            'settings' => $settings,
        ];
    }

    $node = [
        'name'     => 'HtmlElement',
        'label'    => nibwp_builderius_pro_label($element, $tag),
        'settings' => $settings,
        'children' => $children,
    ];

    // A wrapper whose only child is loose text collapses: two modules where one
    // would do is two things to style.
    if (count($children) === 1
        && ($children[0]['label'] ?? '') === 'text'
        && ($children[0]['children'] ?? []) === []) {
        $node['settings']['content'] = (string) (($children[0]['settings'] ?? [])['content'] ?? '');
        unset($node['children']);
    }

    return $node;
}

/**
 * tagClass / tagId / htmlAttribute / css, from the attributes everybody writes.
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function nibwp_builderius_pro_common_settings(DOMElement $element, array &$result): array
{
    $settings = [];

    $class = trim((string) $element->getAttribute('class'));
    if ($class !== '') {
        // Declared as an array in Builderius's setting registry, so it is written
        // as one — a space-separated string works until somebody edits it.
        $settings['tagClass'] = array_values(array_filter(preg_split('/\s+/', $class) ?: []));
    }

    $id = trim((string) $element->getAttribute('id'));
    if ($id !== '') {
        $settings['tagId'] = $id;
    }

    $style = trim((string) $element->getAttribute('style'));
    if ($style !== '') {
        $settings['css'] = '%local% {' . "\n    " . rtrim($style, '; ') . "\n" . '}';
        $result['recommendations'][] = [
            'rule'    => 'inline_style_moved',
            'message' => __('An inline style attribute became a css setting on %local%. Builderius has no inline-style setting, and %local% is how it scopes CSS to one module.', 'nibwp'),
        ];
    }

    $attributes = [];
    foreach ($element->attributes ?? [] as $attribute) {
        if (!$attribute instanceof DOMAttr) {
            continue;
        }
        $name = strtolower($attribute->name);
        if (in_array($name, ['class', 'id', 'style', 'src', 'alt'], true)) {
            continue;
        }
        $attributes[] = ['name' => $attribute->name, 'value' => $attribute->value];
    }
    if ($attributes !== []) {
        $settings['htmlAttribute'] = $attributes;
    }

    return $settings;
}

/** A readable label: the first class, the id, or the tag. */
function nibwp_builderius_pro_label(DOMElement $element, string $fallback): string
{
    $class = trim((string) $element->getAttribute('class'));
    if ($class !== '') {
        $parts = preg_split('/\s+/', $class) ?: [];
        if (($parts[0] ?? '') !== '') {
            return (string) $parts[0];
        }
    }
    $id = trim((string) $element->getAttribute('id'));
    if ($id !== '') {
        return $id;
    }

    return $fallback;
}

/** The element's own markup, including the element. */
function nibwp_builderius_pro_outer_html(DOMElement $element): string
{
    $doc = $element->ownerDocument;
    if (!$doc instanceof DOMDocument) {
        return '';
    }
    $html = $doc->saveHTML($element);

    return is_string($html) ? trim($html) : '';
}

/**
 * A starting skeleton for a named section type.
 *
 * For a screenshot or a brief there is no markup to convert, and an agent asked
 * to invent a tree from nothing tends to invent module names too. A skeleton of
 * real modules with real settings gives it somewhere to start; the checklist in
 * authoring/references/checklists/ says what still has to be filled in.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_pro_skeleton(string $section_type): array
{
    $heading = static fn (string $tag, string $text, string $label): array => [
        'name'     => 'HtmlElement',
        'label'    => $label,
        'settings' => ['tag' => $tag, 'content' => $text],
    ];

    $wrap = static fn (string $label, array $children, string $tag = 'div'): array => [
        'name'     => 'HtmlElement',
        'label'    => $label,
        'settings' => ['tag' => $tag, 'tagClass' => [$label]],
        'children' => $children,
    ];

    return match ($section_type) {
        'hero' => [$wrap('hero', [
            $wrap('hero__inner', [
                $heading('h1', 'Headline', 'hero__title'),
                $heading('p', 'Supporting line.', 'hero__lede'),
                [
                    'name'     => 'HtmlElement',
                    'label'    => 'hero__cta',
                    'settings' => ['tag' => 'a', 'content' => 'Call to action', 'htmlAttribute' => [['name' => 'href', 'value' => '#']]],
                ],
            ]),
        ], 'section')],
        'navbar' => [$wrap('navbar', [
            [
                'name'     => 'HtmlElement',
                'label'    => 'navbar__logo',
                'settings' => ['tag' => 'a', 'htmlAttribute' => [['name' => 'href', 'value' => '[[[wp.site_url]]]']]],
                'children' => [['name' => 'SvgCode', 'label' => 'logo', 'settings' => ['contentSvg' => '<svg viewBox="0 0 24 24"></svg>']]],
            ],
            ['name' => 'MenuBuilder', 'label' => 'navbar__menu', 'settings' => []],
            ['name' => 'MenuToggle', 'label' => 'navbar__toggle', 'settings' => []],
        ], 'header')],
        'footer' => [$wrap('footer', [
            $heading('p', '© [[[wp.site_title]]]', 'footer__copyright'),
            ['name' => 'MenuBuilder', 'label' => 'footer__menu', 'settings' => []],
        ], 'footer')],
        'cta' => [$wrap('cta', [
            $heading('h2', 'One clear ask', 'cta__title'),
            [
                'name'     => 'HtmlElement',
                'label'    => 'cta__button',
                'settings' => ['tag' => 'a', 'content' => 'Do the thing', 'htmlAttribute' => [['name' => 'href', 'value' => '#']]],
            ],
        ], 'section')],
        'card-grid', 'pricing', 'testimonial' => [$wrap($section_type, [
            $heading('h2', 'Section title', $section_type . '__title'),
            [
                'name'     => 'Collection',
                'label'    => $section_type . '__items',
                'settings' => ['tag' => 'div'],
                'children' => [$wrap($section_type . '__item', [
                    $heading('h3', 'Item title', $section_type . '__item-title'),
                    $heading('p', 'Item body.', $section_type . '__item-body'),
                ], 'article')],
            ],
        ], 'section')],
        'form' => [$wrap('form-section', [
            $heading('h2', 'Get in touch', 'form-section__title'),
            ['name' => 'SmartForm', 'label' => 'contact-form', 'settings' => []],
        ], 'section')],
        default => [$wrap($section_type !== '' ? $section_type : 'section', [
            $heading('h2', 'Section title', 'section__title'),
            $heading('p', 'Section body.', 'section__body'),
        ], 'section')],
    };
}

/**
 * A stored config read back as an outline a human can follow.
 *
 * The stored shape is a flat map linked by `parent` and ordered by `index`, which
 * is efficient and unreadable. This rebuilds the nesting for reporting only —
 * nothing is written from it.
 *
 * @param array<string,mixed> $config
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_pro_config_outline(array $config): array
{
    $modules = (array) ($config['modules'] ?? []);
    $by_parent = [];

    foreach ($modules as $id => $module) {
        if (!is_array($module)) {
            continue;
        }
        $parent = (string) ($module['parent'] ?? '');
        $by_parent[$parent][] = ['id' => (string) $id, 'module' => $module];
    }

    foreach ($by_parent as $parent => $children) {
        usort($children, static fn (array $a, array $b): int => ((int) ($a['module']['index'] ?? 0)) <=> ((int) ($b['module']['index'] ?? 0)));
        $by_parent[$parent] = $children;
    }

    $build = function (string $parent, int $depth) use (&$build, $by_parent): array {
        if ($depth > 30) {
            // A config whose parent chain loops would recurse forever. Stored
            // configs are written by a builder and should not, but this is read
            // from the database and the database has seen worse.
            return [];
        }
        $out = [];
        foreach ((array) ($by_parent[$parent] ?? []) as $entry) {
            $module = (array) $entry['module'];
            $settings = [];
            foreach ((array) ($module['settings'] ?? []) as $setting) {
                if (is_array($setting) && isset($setting['name'])) {
                    $settings[] = (string) $setting['name'];
                }
            }
            $out[] = [
                'id'       => (string) $entry['id'],
                'name'     => (string) ($module['name'] ?? ''),
                'label'    => (string) ($module['label'] ?? ''),
                'settings' => $settings,
                'children' => $build((string) $entry['id'], $depth + 1),
            ];
        }

        return $out;
    };

    return $build('', 0);
}

/**
 * Turn an authoring tree into a stored config — through the integration, which
 * owns id minting and the parent/index linking.
 *
 * @param array<int,mixed> $tree
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_pro_tree_to_config(array $tree)
{
    if (!function_exists('nibwp_builderius_build_config')) {
        return new WP_Error(
            'nibwp_builderius_pro_no_integration',
            __('The Builderius integration is not loaded, so there is nothing to build the config with. Enable the Builderius integration.', 'nibwp')
        );
    }

    return nibwp_builderius_build_config($tree);
}
