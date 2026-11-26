<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validator.php';

/**
 * Mosaic Pro — inspect. Read a page the way a reviewer would: its outline, the
 * problems the validator finds in what is stored (missing alt text, skipped
 * heading levels, desktop sizes with no phone value), styles that should be a
 * class, which classes and components it uses, and whether anyone can see it.
 */

nibwp_mosaic_pro_register(
    'mosaic-pro-inspect',
    __('Mosaic Pro — Inspect a page', 'nibwp'),
    'Audit a Mosaic page (post_id), template, master or component: outline with node ids, problems, class and component usage, and whether its theme is live. Read-only.',
    [
        'post_id'       => ['type' => 'integer'],
        'document_type' => ['type' => 'string', 'enum' => ['template', 'master', 'component']],
        'document_id'   => ['type' => 'string'],
        'theme_id'      => ['type' => 'string'],
    ],
    'nibwp_mosaic_pro_inspect_ability',
    true,
    [
        'Use before refining or adding to a page; the outline carries the node ids refine needs.',
        'Warnings are what a visitor would notice. Fix them with nibwp/mosaic-pro-refine.',
    ]
);

function nibwp_mosaic_pro_inspect_ability(array $input)
{
    $gate = nibwp_mosaic_pro_gate(false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }
    $where = array_filter([
        'post_id'       => (int) ($input['post_id'] ?? 0),
        'document_type' => (string) ($input['document_type'] ?? ''),
        'document_id'   => (string) ($input['document_id'] ?? ''),
        'theme_id'      => (string) ($input['theme_id'] ?? ''),
    ], static fn($v) => $v !== '' && $v !== 0);
    $read = nibwp_mosaic_pro_delegate('mosaic-get-tree', $where);
    if ($read instanceof WP_Error) {
        return $read;
    }
    $tree = (array) ($read['tree'] ?? []);

    // The stored tree starts at Mosaic's own root (template-internal and so on); audit what is inside it.
    $content = [];
    foreach ($tree as $root) {
        if (in_array($root['type'] ?? '', nibwp_mosaic_catalog()['system'], true)) {
            $content = array_merge($content, (array) ($root['children'] ?? []));
        } else {
            $content[] = $root;
        }
    }
    $content = nibwp_mosaic_pro_strip_system($content);
    $report = nibwp_mosaic_pro_validate($content, ['live_types' => function_exists('nibwp_mosaic_active') && nibwp_mosaic_active()]);

    $class_names = [];
    if (function_exists('nibwp_mosaic_list_classes')) {
        foreach (nibwp_mosaic_list_classes((string) $read['theme_id']) as $c) {
            $class_names[$c['id']] = $c['name'];
        }
    }
    $used_classes = [];
    $components = [];
    $walk = static function (array $nodes) use (&$walk, &$used_classes, &$components, $class_names): void {
        foreach ($nodes as $n) {
            foreach (array_keys((array) ($n['data']['style']['universalClasses'] ?? [])) as $cid) {
                $name = $class_names[$cid] ?? $cid;
                $used_classes[$name] = ($used_classes[$name] ?? 0) + 1;
            }
            if (str_starts_with((string) ($n['type'] ?? ''), 'component-instance/')) {
                $cid = substr((string) $n['type'], strlen('component-instance/'));
                $components[$cid] = ($components[$cid] ?? 0) + 1;
            }
            $walk((array) ($n['children'] ?? []));
        }
    };
    $walk($content);

    $live = function_exists('nibwp_mosaic_live_theme_id') ? nibwp_mosaic_live_theme_id() : '';

    return [
        'theme_id'        => $read['theme_id'],
        'document_type'   => $read['document_type'],
        'document_id'     => $read['document_id'],
        'live'            => $live !== '' && $live === $read['theme_id'],
        'outline'         => $read['outline'] ?? [],
        'stats'           => $report['stats'],
        // A key Mosaic stored is a key Mosaic accepts, even when the catalog does not list it.
        'problems'        => array_values(array_filter(array_merge($report['errors'], $report['warnings']), static fn($p) => ($p['rule'] ?? '') !== 'unknown_data_key')),
        'recommendations' => $report['recommendations'],
        'classes_used'    => $used_classes,
        'components_used' => $components,
    ];
}

/** Drop node ids and Mosaic-owned slots so the stored tree validates like an authored one. */
function nibwp_mosaic_pro_strip_system(array $nodes): array
{
    $system = nibwp_mosaic_catalog()['system'];
    $out = [];
    foreach ($nodes as $n) {
        if (in_array($n['type'] ?? '', $system, true)) {
            continue;
        }
        $clean = ['type' => $n['type'] ?? '', 'data' => (array) ($n['data'] ?? [])];
        if (!empty($n['children'])) {
            $clean['children'] = nibwp_mosaic_pro_strip_system((array) $n['children']);
        }
        $out[] = $clean;
    }

    return $out;
}
