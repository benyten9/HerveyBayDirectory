<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paper Pro — the one place this skill reaches anything outside itself.
 *
 * Two boundaries meet in this file and both of them matter.
 *
 * The first is the database. The skill does not write. Every design row, every
 * page, every token and every asset is created by the Paper integration's hub
 * ability, nibwp/paper-manage, which is where the storage model lives. This file
 * is the only place that reaches across, and it reaches across by action name — so
 * if the integration changes how it persists something, this skill changes
 * nothing. Two routes, in order of preference:
 *   1. the Abilities API, which is how any other client would call it and which
 *      runs the integration's own permission callback;
 *   2. the hub's callback function, for the case where this skill's ability files
 *      are loaded in the same request before the integration has registered
 *      (ability files are required in manifest order).
 * When neither exists the answer is a refusal naming the integration, not an
 * improvised write.
 *
 * The second is Paper itself, and this one is a boundary the skill does not cross
 * at all. Paper's MCP server belongs to the Paper Desktop app on the designer's
 * machine: stdio through `paper mcp`, a legacy HTTP endpoint on 127.0.0.1:29979,
 * no key and no cloud API. A WordPress process calling its own loopback address
 * would reach its own web server, not Paper — on a managed host it would reach
 * nothing at all, and on a shared host it could reach somebody else's service.
 * So there is no fetch here and never will be. The agent reads the canvas and
 * hands it over; this skill checks what arrived.
 */

/**
 * The actions the Paper integration's hub accepts. Fixed by the integration
 * contract — this skill does not invent action names, and an action that is not on
 * this list is a bug in the caller rather than something to try and see.
 *
 * @return array<int,string>
 */
function nibwp_paper_pro_integration_actions(): array
{
    return [
        'status',
        'ingest',
        'list_designs',
        'get_design',
        'rename_design',
        'delete_design',
        'preview_convert',
        'convert',
        'list_builders',
        'list_tokens',
        'sync_tokens',
        'attach_asset',
        'list_assets',
        'audit',
    ];
}

/**
 * Hand an action to the Paper integration's hub.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_delegate(string $action, array $input = [])
{
    if (!in_array($action, nibwp_paper_pro_integration_actions(), true)) {
        return new WP_Error(
            'nibwp_paper_pro_bad_delegate',
            sprintf(
                /* translators: 1: the action asked for, 2: comma-separated list of valid actions */
                __('"%1$s" is not an action the Paper integration accepts. It accepts: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_paper_pro_integration_actions())
            )
        );
    }

    $input['action'] = $action;

    // Recorded before the call, not after, so a delegation that fatals or returns
    // an error still shows up in the report — and so a test can prove that a
    // refusal happened BEFORE the integration was reached rather than after.
    $GLOBALS['nibwp_paper_pro_delegated'][] = ['ability' => 'nibwp/paper-manage', 'input' => $input];

    if (function_exists('wp_get_ability')) {
        $ability = wp_get_ability('nibwp/paper-manage');
        if (is_object($ability) && method_exists($ability, 'execute')) {
            $result = $ability->execute($input);

            return is_array($result) || $result instanceof WP_Error
                ? $result
                : ['result' => $result];
        }
    }

    if (function_exists('nibwp_paper_manage')) {
        $result = nibwp_paper_manage($input);

        return is_array($result) || $result instanceof WP_Error
            ? $result
            : ['result' => $result];
    }

    return new WP_Error(
        'nibwp_paper_pro_no_integration',
        sprintf(
            /* translators: %s: the action that was being delegated */
            __('The Paper integration ability nibwp/paper-manage is not available, so there is nothing to run "%s" through. Enable the Paper integration — this skill will not write to the database itself, and it cannot read your Paper file either: that part is yours, with Paper\'s own MCP tools.', 'nibwp'),
            $action
        )
    );
}

/**
 * What this request has delegated so far, for the report the caller reads.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_paper_pro_delegations(): array
{
    return (array) ($GLOBALS['nibwp_paper_pro_delegated'] ?? []);
}

/**
 * The gate every ability opens with.
 *
 * Three levels, three codes, deliberately distinct. "Refused" is not an answer
 * worth anything if every refusal looks the same from the outside: a caller that
 * cannot tell a licence problem from a capability problem from a missing plugin
 * retries the wrong thing.
 *
 *   - $writes   — the ability lands content, so the user needs to be allowed to
 *                 edit content: edit_posts, the capability WordPress gates the
 *                 editor on.
 *   - $tokens   — the call would also sync the design's colours and sizes into the
 *                 site's token system, which changes every page on the site and is
 *                 therefore edit_theme_options, not edit_posts. An author who may
 *                 publish a post has no business repainting the whole site.
 *
 * @return WP_Error|null
 */
function nibwp_paper_pro_gate(bool $writes = false, bool $tokens = false)
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('paper-pro');
        if ($gate instanceof WP_Error) {
            return $gate;
        }
    }

    // Only consulted when the integration is actually on disk. While it is not,
    // the refusal that matters is the one the delegation seam gives, which names
    // the ability the caller needs rather than a plugin they may not have heard of.
    if (function_exists('nibwp_paper_active') && !nibwp_paper_active()) {
        return new WP_Error(
            'nibwp_paper_pro_missing',
            __('The Paper integration is not active on this site, so a handed-over design has nowhere to land. Say so rather than building the page another way.', 'nibwp')
        );
    }

    if ($writes && function_exists('current_user_can') && !current_user_can('edit_posts')) {
        return new WP_Error(
            'nibwp_paper_pro_denied',
            __('Converting a design creates or changes content, which needs the edit_posts capability.', 'nibwp')
        );
    }

    if ($tokens && function_exists('current_user_can') && !current_user_can('edit_theme_options')) {
        return new WP_Error(
            'nibwp_paper_pro_tokens_denied',
            __('Syncing a design\'s colours and type sizes into the site\'s token system restyles every page, which needs the edit_theme_options capability. Convert without sync_tokens, or ask someone who has it.', 'nibwp')
        );
    }

    return null;
}
