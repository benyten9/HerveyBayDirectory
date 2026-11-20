<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builderius Pro — the boundary between this skill and the database.
 *
 * The skill does not write. Every commit, every template, every component and
 * every branch is created by the Builderius integration's own abilities, which is
 * where the storage model lives and where it was verified against Builderius's
 * source. This file is the only place that reaches across, and it reaches across
 * by ability name — so if the integration changes how it persists something, this
 * skill changes nothing.
 *
 * Two routes, in order of preference:
 *   1. The Abilities API, which is how any other client would call it, and which
 *      runs the integration's own permission callback.
 *   2. The integration's callback function, for the case where this skill's
 *      abilities are loaded in the same request before the integration has
 *      registered (ability files are required in manifest order).
 * When neither is available the answer is a refusal naming the integration, not
 * an improvised write.
 */

/**
 * Hand an input to one of the integration's abilities.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_pro_delegate(string $slug, array $input)
{
    if (!str_starts_with($slug, 'builderius-')) {
        return new WP_Error(
            'nibwp_builderius_pro_bad_delegate',
            sprintf(
                /* translators: %s: ability slug */
                __('"%s" is not a Builderius integration ability.', 'nibwp'),
                $slug
            )
        );
    }

    $GLOBALS['nibwp_builderius_pro_delegated'][] = ['ability' => 'nibwp/' . $slug, 'input' => $input];

    if (function_exists('wp_get_ability')) {
        $ability = wp_get_ability('nibwp/' . $slug);
        if (is_object($ability) && method_exists($ability, 'execute')) {
            $result = $ability->execute($input);

            return is_array($result) || $result instanceof WP_Error
                ? $result
                : ['result' => $result];
        }
    }

    $callback = 'nibwp_builderius_ability_' . str_replace('-', '_', substr($slug, strlen('builderius-')));
    if (function_exists($callback)) {
        $result = $callback($input);

        return is_array($result) || $result instanceof WP_Error
            ? $result
            : ['result' => $result];
    }

    return new WP_Error(
        'nibwp_builderius_pro_no_integration',
        sprintf(
            /* translators: %s: ability name */
            __('The Builderius integration ability %s is not available, so there is nothing to commit through. Enable the Builderius integration — this skill will not write to the database itself.', 'nibwp'),
            'nibwp/' . $slug
        )
    );
}

/** What this request has delegated so far, for the report the caller reads. */
function nibwp_builderius_pro_delegations(): array
{
    return (array) ($GLOBALS['nibwp_builderius_pro_delegated'] ?? []);
}

/**
 * The gate every ability opens with: licensed, Builderius present, and — for the
 * ones that commit — allowed to change the site's design.
 *
 * `edit_theme_options` rather than `edit_posts`: a Builderius template is part of
 * how the whole site renders, and an author who may publish a post has no
 * business replacing the site header.
 *
 * @return WP_Error|null
 */
function nibwp_builderius_pro_gate(bool $writes = false)
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('builderius-pro');
        if ($gate instanceof WP_Error) {
            return $gate;
        }
    }

    if (function_exists('nibwp_builderius_active') && !nibwp_builderius_active()) {
        return new WP_Error(
            'nibwp_builderius_pro_missing',
            __('Builderius is not active on this site, so there is nothing to build on. Say so rather than building this another way.', 'nibwp')
        );
    }

    if ($writes && function_exists('current_user_can') && !current_user_can('edit_theme_options')) {
        return new WP_Error(
            'nibwp_builderius_pro_denied',
            __('Committing a Builderius template changes how the site renders, which needs the edit_theme_options capability.', 'nibwp')
        );
    }

    return null;
}

/**
 * Commit a config, by target kind, through the integration.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $args   {title, template_id, type, subtype, message, dry_run, version_mode, branch_name}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_pro_commit(string $target, array $config, array $args)
{
    $dry_run = (bool) ($args['dry_run'] ?? true);
    $title   = (string) ($args['title'] ?? '');
    $message = (string) ($args['message'] ?? '');

    if ($target === 'component') {
        return nibwp_builderius_pro_delegate('builderius-create-component', [
            'title'   => $title,
            'config'  => $config,
            'dry_run' => $dry_run,
        ]);
    }

    if ($target === 'fragment') {
        return nibwp_builderius_pro_delegate('builderius-create-fragment', [
            'title'   => $title,
            'config'  => $config,
            'dry_run' => $dry_run,
        ]);
    }

    $template_id = (int) ($args['template_id'] ?? 0);

    if ($template_id <= 0) {
        return nibwp_builderius_pro_delegate('builderius-create-template', [
            'title'   => $title,
            'type'    => (string) ($args['type'] ?? ''),
            'subtype' => (string) ($args['subtype'] ?? ''),
            'config'  => $config,
            'dry_run' => $dry_run,
        ]);
    }

    if ((string) ($args['version_mode'] ?? 'new_version') === 'new_branch') {
        $branch = (string) ($args['branch_name'] ?? '');
        if ($branch === '') {
            // Named after what it is for, not after the date: a branch list of
            // timestamps tells nobody which one held the work they wanted.
            $branch = 'nibwp-' . ($title !== '' ? sanitize_title($title) : 'build');
        }
        $created = nibwp_builderius_pro_delegate('builderius-create-branch', [
            'template_id' => $template_id,
            'name'        => $branch,
            'dry_run'     => $dry_run,
        ]);
        if ($created instanceof WP_Error) {
            return $created;
        }
        // The integration commits onto the template's first branch, so the new
        // branch is reported and the config still lands as a new version. Said
        // plainly rather than implied, because "it went on a branch" and "it went
        // on the branch you were looking at" are different outcomes.
        $committed = nibwp_builderius_pro_delegate('builderius-update-template', [
            'template_id' => $template_id,
            'config'      => $config,
            'message'     => $message !== '' ? $message : 'NIBWP build',
            'dry_run'     => $dry_run,
        ]);
        if ($committed instanceof WP_Error) {
            return $committed;
        }

        return [
            'branch'    => $created,
            'committed' => $committed,
            'note'      => __('A branch was created and the config was committed as a new version. Builderius applies a commit to the template\'s first branch, so check in the builder that the work is on the branch you meant before publishing a release.', 'nibwp'),
        ];
    }

    return nibwp_builderius_pro_delegate('builderius-update-template', [
        'template_id' => $template_id,
        'config'      => $config,
        'message'     => $message !== '' ? $message : 'NIBWP build',
        'dry_run'     => $dry_run,
    ]);
}
