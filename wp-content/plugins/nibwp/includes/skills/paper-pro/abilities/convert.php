<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/tokens.php';
require_once __DIR__ . '/../lib/repeats.php';
require_once __DIR__ . '/../lib/builder.php';

/**
 * Paper Pro — the handover, turned into a page.
 *
 * This ability orchestrates and nothing more. It validates the design, settles the
 * builder, asks the integration to preview the conversion, and — only when that came
 * back clean and the caller said so out loud — asks it to convert. Three delegations
 * in a fixed order, and a dry run makes exactly one of them.
 *
 * dry_run defaults to TRUE, and the default is the point. The agent calling this has
 * just assembled a tree by hand out of another tool's MCP responses; the chance that
 * it is exactly right the first time is not high enough to make the destructive
 * reading of a missing argument the kind one. A caller who means it says so.
 *
 * The order of the delegations is deliberate too. preview_convert runs first because
 * it is the read-only one: a design that the integration cannot convert should be
 * found out before a row exists for it. Then ingest, so the design is recorded and
 * the audit step has something to compare the page against later. Then convert.
 */

wp_register_ability('nibwp/paper-pro-convert', [
    'label'       => __('Paper Pro — Convert a handed-over design', 'nibwp'),
    'description' => 'Validate a design handed over from Paper as an NDO 1.0 document and convert it into a native page through the Paper integration. Previews first, defaults to a dry run, and refuses an invalid document before the integration is reached. Writes nothing itself — every write is the integration\'s.',
    'category'    => 'paper-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'design' => [
                'type'        => 'object',
                'description' => 'The NDO 1.0 document: {ndo_version:"1.0", target:{type,title}, tokens, root, warnings}. The same document nibwp/paper-pro-plan validated — do not re-author it between the two calls.',
            ],
            'builder' => [
                'type'        => 'string',
                'enum'        => ['auto', 'etchwp', 'bricks', 'elementor', 'gutenberg'],
                'description' => 'Target builder, or auto to use whatever this site already builds with.',
            ],
            'available_builders' => [
                'type'        => 'array',
                'items'       => ['type' => 'object'],
                'description' => 'What list_builders reported, if you already have it. Fetched here when omitted and builder is auto.',
            ],
            'push_mode' => [
                'type'        => 'string',
                'enum'        => ['new_page', 'new_post', 'update'],
                'description' => 'Land it as a new page, a new post, or an update to something that exists. Defaults to new_page.',
            ],
            'target_post_id' => ['type' => 'integer', 'description' => 'With push_mode update: which post or page to change.'],
            'post_status'    => ['type' => 'string', 'enum' => ['draft', 'pending', 'publish'], 'description' => 'Defaults to draft. A converted design is a first attempt, not a publication.'],
            'design_id'      => ['type' => 'integer', 'description' => 'An already-ingested design to convert again, instead of recording this one afresh.'],
            'sync_tokens'    => ['type' => 'boolean', 'description' => 'Also sync the design\'s colours and type sizes into the site\'s token system. Off by default: it restyles every page, and it needs the edit_theme_options capability.'],
            'site_tokens'    => ['type' => 'object', 'description' => 'What list_tokens returned, so matched literals can be reported with the token to use.'],
            'dry_run'        => ['type' => 'boolean', 'default' => true, 'description' => 'True (the default) previews and writes nothing. Pass false only after a clean dry run.'],
        ],
        'required' => ['design'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_paper_pro_convert_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run nibwp/paper-pro-plan first and show the user the plan. This ability converts; it does not advise.',
                'dry_run is true unless you say otherwise. The dry run delegates only the integration\'s read-only preview — nothing is recorded and nothing is written.',
                'An invalid NDO document is refused here, before the integration is reached. Fix the node the error names; do not retry the same tree.',
                'If the preview comes back with errors, stop and read them. Converting anyway produces a page with the holes the preview described.',
                'sync_tokens restyles every page on the site and needs edit_theme_options. Leave it off unless the user asked for it in those terms.',
                'Nothing here can check the result against your Paper file afterwards. Use nibwp/paper-pro-audit for internal drift, and look at the page.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_paper_pro_convert_ability(array $input)
{
    // Absent means true. A caller who forgot the argument gets the preview, not the
    // page.
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];
    $sync_tokens = !empty($input['sync_tokens']);

    // The capability is required for the dry run as well as the real one. A preview
    // runs the integration's conversion machinery over site content and reports what
    // would land where; that is not a thing a subscriber should be able to ask for.
    $gate = nibwp_paper_pro_gate(true, $sync_tokens);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $envelope = nibwp_paper_pro_validate_payload($input);
    if ($envelope instanceof WP_Error) {
        return $envelope;
    }

    $design = (array) $input['design'];
    $report = nibwp_paper_pro_validate_design($design);
    $refusal = nibwp_paper_pro_design_error($report);
    if ($refusal instanceof WP_Error) {
        // Before any delegation. An invalid tree that reached the integration would
        // be a half-built page to clean up instead of a message to act on.
        return $refusal;
    }

    $push_mode = (string) ($input['push_mode'] ?? 'new_page');
    $target_post_id = (int) ($input['target_post_id'] ?? 0);
    if ($push_mode === 'update' && $target_post_id <= 0) {
        return new WP_Error(
            'nibwp_paper_pro_invalid_payload',
            __('push_mode is update but no target_post_id was given. Converting over "whichever page" is not something to guess at.', 'nibwp')
        );
    }

    $builders = $input['available_builders'] ?? null;
    if (!is_array($builders)) {
        $listed = nibwp_paper_pro_delegate('list_builders');
        $builders = $listed instanceof WP_Error ? [] : (array) ($listed['builders'] ?? $listed);
    }
    $builder = nibwp_paper_pro_choose_builder((string) ($input['builder'] ?? 'auto'), (array) $builders);

    $repeats = nibwp_paper_pro_find_repeats($design);
    $tokens = nibwp_paper_pro_match_tokens($design, (array) ($input['site_tokens'] ?? []));

    $payload = [
        'design'         => $design,
        'builder'        => $builder['target'],
        'push_mode'      => $push_mode,
        'target_post_id' => $target_post_id,
        'post_status'    => (string) ($input['post_status'] ?? 'draft'),
        'design_id'      => (int) ($input['design_id'] ?? 0),
        'token_matches'  => $tokens['matched'],
        'repeats'        => $repeats['groups'],
    ];

    $preview = nibwp_paper_pro_delegate('preview_convert', $payload);
    if ($preview instanceof WP_Error) {
        return $preview;
    }

    $preview_errors = array_values((array) ($preview['errors'] ?? []));
    if ($preview_errors !== [] || (array_key_exists('ok', $preview) && empty($preview['ok']))) {
        // Returned rather than thrown: the errors are the useful part, and a WP_Error
        // would flatten them into one string.
        return [
            'ok'       => false,
            'written'  => false,
            'dry_run'  => $dry_run,
            'stage'    => 'preview',
            'builder'  => $builder,
            'preview'  => $preview,
            'errors'   => $preview_errors,
            'handover' => ['counts' => $report['counts'], 'depth' => $report['depth']],
            'delegated_to' => nibwp_paper_pro_delegations(),
            'next_step'    => __('The integration could not convert this design as it stands. Fix what the preview errors name and run the dry run again — nothing was recorded and nothing was written.', 'nibwp'),
        ];
    }

    if ($dry_run) {
        return [
            'ok'       => true,
            'written'  => false,
            'dry_run'  => true,
            'stage'    => 'preview',
            'builder'  => $builder,
            'preview'  => $preview,
            'handover' => [
                'counts' => $report['counts'],
                'types'  => $report['types'],
                'depth'  => $report['depth'],
            ],
            'repeats'  => $repeats,
            'tokens'   => $tokens,
            'warnings' => array_merge((array) ($design['warnings'] ?? []), (array) $report['warnings']),
            'would_land_as' => [
                'push_mode'      => $push_mode,
                'target_post_id' => $target_post_id,
                'post_status'    => $payload['post_status'],
                'title'          => (string) (($design['target'] ?? [])['title'] ?? ''),
                'builder'        => $builder['target'],
                'sync_tokens'    => $sync_tokens,
            ],
            'delegated_to' => nibwp_paper_pro_delegations(),
            'next_step'    => __('Nothing was written and nothing was recorded. Read the preview, confirm where it would land, then call again with dry_run false.', 'nibwp'),
        ];
    }

    // Record the design before converting it, so the audit step has the thing the
    // page was built from. Skipped on a dry run, which is why the dry run delegates
    // one action and this path delegates three.
    $ingested = null;
    if ((int) ($input['design_id'] ?? 0) <= 0) {
        $ingested = nibwp_paper_pro_delegate('ingest', [
            'design' => $design,
            'title'  => (string) (($design['target'] ?? [])['title'] ?? ''),
            'source' => 'paper-mcp-handover',
        ]);
        if ($ingested instanceof WP_Error) {
            return $ingested;
        }
        $payload['design_id'] = (int) ($ingested['design_id'] ?? $ingested['id'] ?? 0);
    }

    $converted = nibwp_paper_pro_delegate('convert', $payload);
    if ($converted instanceof WP_Error) {
        return $converted;
    }

    $synced = null;
    if ($sync_tokens) {
        $synced = nibwp_paper_pro_delegate('sync_tokens', [
            'design'    => $design,
            'tokens'    => (array) ($design['tokens'] ?? []),
            'design_id' => (int) ($payload['design_id'] ?? 0),
        ]);
        if ($synced instanceof WP_Error) {
            // The page exists; the token sync did not happen. Reported rather than
            // raised, because losing the conversion report to describe a secondary
            // failure would be the worse trade.
            $synced = ['ok' => false, 'error' => $synced->get_error_code(), 'message' => $synced->get_error_message()];
        }
    }

    return [
        'ok'        => true,
        'written'   => true,
        'dry_run'   => false,
        'stage'     => 'converted',
        'builder'   => $builder,
        'design_id' => (int) ($payload['design_id'] ?? 0),
        'post_id'   => (int) ($converted['post_id'] ?? 0),
        'ingested'  => $ingested,
        'converted' => $converted,
        'synced_tokens' => $synced,
        'handover'  => ['counts' => $report['counts'], 'types' => $report['types'], 'depth' => $report['depth']],
        'repeats'   => $repeats,
        'tokens'    => $tokens,
        'warnings'  => array_merge((array) ($design['warnings'] ?? []), (array) $report['warnings']),
        'delegated_to' => nibwp_paper_pro_delegations(),
        'next_step' => __('Read the page back with nibwp/paper-pro-audit, then look at it. Attach the design\'s images with the integration\'s attach_asset action — until that happens the image boxes are empty, which looks like a failed conversion and is not one.', 'nibwp'),
    ];
}
