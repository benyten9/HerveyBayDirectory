<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/tree.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/versions.php';
require_once __DIR__ . '/../lib/commit.php';

/**
 * Builderius Pro — build and commit.
 *
 * The order here is the whole value of the ability, so it is worth saying out
 * loud: capability, then validation, then the release guard, and only then the
 * integration. Each of those can refuse, and every one of them refuses before
 * anything has been written — because a Builderius commit is not something you
 * take back quietly. It is a version in a history somebody else will read.
 */

wp_register_ability('nibwp/builderius-pro-build', [
    'label'       => __('Builderius Pro — Build and commit', 'nibwp'),
    'description' => 'Validate a Builderius config and commit it as a template, component or fragment through the Builderius integration — onto a new version or a new branch, never over a published release.',
    'category'    => 'builderius-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'target'       => ['type' => 'string', 'enum' => ['template', 'component', 'fragment'], 'default' => 'template', 'description' => 'What to commit it as.'],
            'title'        => ['type' => 'string', 'description' => 'Required when creating something new.'],
            'template_id'  => ['type' => 'integer', 'description' => 'Commit onto this existing template instead of creating one.'],
            'type'         => ['type' => 'string', 'description' => 'Builderius template type taxonomy slug — single, archive, page, hook. A template with no type has nowhere to apply.'],
            'subtype'      => ['type' => 'string'],
            'tree'         => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'The nested authoring tree: [{name, label?, settings?, children?}].'],
            'config'       => ['type' => 'object', 'description' => 'Or a config already built by nibwp/builderius-pro-convert.'],
            'html'         => ['type' => 'string', 'description' => 'Or markup to convert here. Prefer converting first and reading the report.'],
            'section_type' => ['type' => 'string', 'description' => 'Or a section type to start from a skeleton.'],
            'version_mode' => [
                'type' => 'string',
                'enum' => ['new_version', 'new_branch', 'overwrite'],
                'default' => 'new_version',
                'description' => 'new_version commits onto the template branch and leaves the published release alone. new_branch puts the work on a branch of its own. overwrite asks to replace what the release serves, and is refused when a release is published for that template.',
            ],
            'branch_name'  => ['type' => 'string', 'description' => 'With new_branch: what to call it.'],
            'message'      => ['type' => 'string', 'description' => 'The commit message. Somebody will read it in the version list.'],
            'dry_run'      => ['type' => 'boolean', 'default' => true, 'description' => 'True reports the validation and the version plan and writes nothing.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_builderius_pro_build_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'dry_run true first, always. The response carries the version plan: which branch, which commit is at the head, which release is published and whether it serves this template. Show that to the user before writing.',
                'Builderius commits. There is no draft state to hide a bad attempt in, and the newest commit on a branch is the one the editor opens — so a half-finished build is what the next person sees.',
                'version_mode overwrite on a template the published release serves is refused. That is not a problem to work around: commit a new version, tell the user, and let them publish a release when they have looked at it.',
                'A template with no type taxonomy exists and applies nowhere. Set type, or say plainly that it is unassigned.',
                'This ability writes nothing itself. Every commit goes through the Builderius integration, so if the integration is switched off the honest answer is that it is switched off.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_builderius_pro_build_ability(array $input)
{
    // 1. Allowed to change the site at all?
    $gate = nibwp_builderius_pro_gate(true);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $target = (string) ($input['target'] ?? 'template');
    if (!in_array($target, ['template', 'component', 'fragment'], true)) {
        return new WP_Error(
            'nibwp_builderius_pro_bad_target',
            sprintf(
                /* translators: %s: the target given */
                __('target "%s" is not one of template, component, fragment.', 'nibwp'),
                $target
            )
        );
    }

    $template_id  = (int) ($input['template_id'] ?? 0);
    $version_mode = (string) ($input['version_mode'] ?? 'new_version');
    $dry_run      = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    // 2. Is what we were handed valid? Nothing is written until this passes,
    // because an unknown module does not fail at render time — it renders nothing.
    $resolved = nibwp_builderius_pro_resolve_payload($input);
    if ($resolved instanceof WP_Error) {
        return $resolved;
    }
    [$tree, $config, $report] = [$resolved['tree'], $resolved['config'], $resolved['report']];

    $refusal = nibwp_builderius_pro_report_error($report);
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }

    // 3. May it land where it was asked to land?
    if ($target === 'template') {
        $guard = nibwp_builderius_pro_version_guard($template_id, $version_mode);
        if ($guard instanceof WP_Error) {
            return $guard;
        }
    }

    $plan = $target === 'template'
        ? nibwp_builderius_pro_version_plan($template_id, $version_mode)
        : ['version_mode' => 'n/a', 'lands' => __('as a new record; components and fragments are not versioned the way templates are.', 'nibwp')];

    if ($config === null) {
        $built = nibwp_builderius_pro_tree_to_config($tree);
        if ($built instanceof WP_Error) {
            return $built;
        }
        $config = $built;
    }

    if ($template_id <= 0 && (string) ($input['title'] ?? '') === '') {
        return new WP_Error(
            'nibwp_builderius_pro_no_title',
            __('Give a title — this is creating something new and an untitled record is unfindable in the Builderius list.', 'nibwp')
        );
    }
    if ($target === 'template' && $template_id <= 0 && (string) ($input['type'] ?? '') === '') {
        $report['warnings'][] = [
            'rule'    => 'template_without_type',
            'message' => __('No template type was given. The template will be created and will apply nowhere until somebody sets its type in Builderius. Say that rather than reporting a finished page.', 'nibwp'),
        ];
    }

    // 4. Hand it to the integration, which owns every write.
    $result = nibwp_builderius_pro_commit($target, $config, [
        'title'        => (string) ($input['title'] ?? ''),
        'template_id'  => $template_id,
        'type'         => (string) ($input['type'] ?? ''),
        'subtype'      => (string) ($input['subtype'] ?? ''),
        'message'      => (string) ($input['message'] ?? ''),
        'version_mode' => $version_mode,
        'branch_name'  => (string) ($input['branch_name'] ?? ''),
        'dry_run'      => $dry_run,
    ]);
    if ($result instanceof WP_Error) {
        return $result;
    }

    return [
        'ok'              => true,
        'written'         => !$dry_run,
        'dry_run'         => $dry_run,
        'target'          => $target,
        'version_plan'    => $plan,
        'module_count'    => $report['module_count'],
        'modules_used'    => $report['modules_used'],
        'dynamic_tokens'  => $report['tokens'],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'integration'     => $result,
        'delegated_to'    => nibwp_builderius_pro_delegations(),
        'next_step'       => $dry_run
            ? __('Nothing was written. Show the version plan to the user, then call again with dry_run false.', 'nibwp')
            : __('Committed. Open it in Builderius to confirm, and remember that a visitor sees the published release, not this commit — publishing one is the user\'s decision.', 'nibwp'),
    ];
}

/**
 * Work out what was handed over, and validate it.
 *
 * @param array<string,mixed> $input
 * @return array{tree:array<int,mixed>,config:array<string,mixed>|null,report:array<string,mixed>}|WP_Error
 */
function nibwp_builderius_pro_resolve_payload(array $input)
{
    if (!empty($input['config']) && is_array($input['config'])) {
        $config = (array) $input['config'];
        $report = nibwp_builderius_pro_validate_config($config);

        return ['tree' => [], 'config' => $config, 'report' => $report];
    }

    $tree = [];
    $extra_warnings = [];
    $extra_recommendations = [];

    if (!empty($input['tree']) && is_array($input['tree'])) {
        $tree = array_values($input['tree']);
    } elseif (!empty($input['html']) && is_string($input['html'])) {
        $converted = nibwp_builderius_pro_html_to_tree((string) $input['html']);
        $tree = $converted['tree'];
        $extra_warnings = $converted['warnings'];
        $extra_recommendations = $converted['recommendations'];
    } elseif (!empty($input['section_type']) && is_string($input['section_type'])) {
        $tree = nibwp_builderius_pro_skeleton((string) $input['section_type']);
        $extra_recommendations[] = [
            'rule'    => 'skeleton_committed',
            'message' => __('This was built from a skeleton with placeholder copy. Committing it is fine as a first version, but say so — it is not the finished section.', 'nibwp'),
        ];
    } else {
        return new WP_Error(
            'nibwp_builderius_pro_no_input',
            __('Give a tree, a config, html, or a section_type.', 'nibwp')
        );
    }

    $report = nibwp_builderius_pro_validate_tree($tree);
    $report['warnings'] = array_merge($extra_warnings, $report['warnings']);
    $report['recommendations'] = array_merge($extra_recommendations, $report['recommendations']);

    return ['tree' => $tree, 'config' => null, 'report' => $report];
}
