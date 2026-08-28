<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — orchestrator recommender.
 *
 * Looks at the agent-built payload and proposes follow-up ability chains
 * that would raise output quality. The recommendations are surfaced to the
 * AGENT, who in turn surfaces them to the USER for a yes/no decision. The
 * server never auto-executes a recommendation — it only suggests.
 *
 * Categories of recommendations:
 *   - loop_to_cpt             : ≥3 repeating sibling structures → CPT + ACF + Etch loop (DYNAMIC, content grows)
 *   - extract_to_component    : ≥2 sibling structures sharing skeleton but differing in copy → etch/component with properties (STATIC variants, reusable)
 *   - condition_candidate     : visibility/state hints in source (display:none, hidden attr, is-active class) → etch/condition wrapper with isTruthy operator
 *   - image_no_alt            : <img> without alt attribute → propose alt + upload via wp-upload-media
 *   - iframe_youtube_raw      : raw YouTube/Vimeo iframe → propose etch/embed block
 *   - iframe_provider         : any third-party iframe → propose proper embed block
 *   - form_detected           : raw <form> → propose forms-manage list_plugins + etch/shortcode
 *   - gallery_grid            : ≥4 sibling <img> children → propose CPT + ACF gallery field
 *   - div_soup                : deeply nested <div>-only tree → propose semantic landmarks
 *   - dynamic_data_hint       : hardcoded business data (prices, dates, names) → propose ACF fields
 *
 * Component vs Loop decision tree:
 *   - Repeating cards, content GROWS over time (admin adds entries) → loop_to_cpt (dynamic)
 *   - Repeating cards, content is FIXED + finite (3 plan tiers, 4 features) → extract_to_component (static variants with props)
 *   - Both relevant → agent surfaces both choices to the user
 *
 * Each recommendation carries:
 *   {
 *     type, severity:'suggestion'|'strong'|'warning',
 *     summary, count?, sample_paths?, sample_classes?,
 *     suggested_chain: [{ability, args_hint, why}, ...],
 *     choices: ['accept', 'reject', '<custom_string>']
 *   }
 */

require_once __DIR__ . '/loop-detector.php';

/**
 * Build the recommendations array for the response.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $ctx     { source_html?, element_type?, brand?, post_type? }
 * @return array<int,array<string,mixed>>
 */
function nibwp_etchwp_recommend_abilities(array $payload, array $ctx = []): array
{
    $recs = [];

    // ── Component-extraction candidates (≥2 siblings with structural sameness) ─
    // Loop-detector counts ≥2 by default; we use its same signature work but
    // surface ≥2 as static-component candidates AND ≥3 as dynamic-loop candidates.
    // Both can coexist for the same group — the agent surfaces both to the user.
    $component_candidates = nibwp_etchwp_detect_loops($payload, 2);
    foreach ($component_candidates as $cand) {
        if ($cand['count'] >= 3) {
            // Will also be surfaced as loop_to_cpt below; skip the static variant
            // unless count is small (≤5) which is more component-like than loop-like.
            if ($cand['count'] > 5) {
                continue;
            }
        }
        $family = nibwp_etchwp_loop_family_name($cand);
        $component_slug = strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $family));
        $recs[] = [
            'type'           => 'extract_to_component',
            'severity'       => $cand['count'] >= 3 ? 'suggestion' : 'strong',
            'summary'        => sprintf(
                'Detected %d sibling "%s" structures sharing the same skeleton but differing in copy. Extract them into an etch/component with properties (label, icon, link, etc.) so future edits change one place instead of N.',
                $cand['count'],
                $family
            ),
            'count'          => $cand['count'],
            'sample_classes' => $cand['sample_classes'],
            'sample_paths'   => array_slice($cand['paths'], 0, 3),
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'Define ONE etch/component named "' . $component_slug . '" in payload.components. Schema: properties = [{name:"title",type:"string"},{name:"body",type:"string"},{name:"icon",type:"image"},{name:"cta_label",type:"string"},{name:"cta_url",type:"url"}]. Replace the ' . $cand['count'] . ' static blocks with ' . $cand['count'] . ' etch/component INSTANCE blocks where each instance differs only in its `props` payload (title/body/icon/cta_label/cta_url values).',
                    ],
                    'why' => 'Component + props makes the markup DRY. Each instance is a thin wrapper of property values. Editing the component template updates every instance.',
                ],
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'For variant styling (e.g. featured card has gold ribbon), add a property `variant: enum(default|featured)` + an etch/condition inside the component checking `{props.variant} === "featured"` to render the ribbon. See SKILL.md §6 component system + etch/condition pattern.',
                    ],
                    'why' => 'Conditional variants stay inside the component; the instance only sets one property value.',
                ],
            ],
            'choices' => [
                'extract_to_component',
                'extract_to_component_with_variants',
                'use_loop_dynamic_cpt_acf_instead',
                'keep_inline_repeat',
            ],
        ];
    }

    // ── Conditional-visibility candidates ─────────────────────────────────
    // Source HTML or block payload contains visibility hints that mean "show on
    // condition X". Examples: style="display:none", hidden attr, is-active /
    // is-open / is-hidden class atoms, x-show / v-if leftovers from Alpine/Vue
    // imports. Suggest etch/condition wrapper with isTruthy/&&/|| operators.
    $cond_hits = [];
    $cond_seen_paths = [];
    $inner_strings_for_cond = [];
    nibwp_etchwp_collect_inner_html_simple($payload['gutenbergBlock'] ?? null, $inner_strings_for_cond);
    if ($inner_strings_for_cond === [] && !empty($ctx['source_html'])) {
        $inner_strings_for_cond[] = (string) $ctx['source_html'];
    }
    foreach ($inner_strings_for_cond as $idx => $html) {
        if (preg_match_all('/(\bhidden\b|\bstyle\s*=\s*"[^"]*display\s*:\s*none[^"]*"|\bclass\s*=\s*"[^"]*\b(?:is-active|is-open|is-hidden|x-show|v-if|aria-hidden)[^"]*")/i', $html, $cm)) {
            foreach ($cm[0] as $hit) {
                if (!in_array("$idx:$hit", $cond_seen_paths, true)) {
                    $cond_seen_paths[] = "$idx:$hit";
                    $cond_hits[] = ['path' => "innerHTML[$idx]", 'hint' => substr($hit, 0, 80)];
                }
            }
        }
    }
    if ($cond_hits !== []) {
        $recs[] = [
            'type'           => 'condition_candidate',
            'severity'       => 'suggestion',
            'summary'        => sprintf(
                'Detected %d visibility/state hint(s) (display:none, hidden, is-active, etc.). Bake these into etch/condition blocks with explicit operators instead of relying on CSS classes the agent inferred from source.',
                count($cond_hits)
            ),
            'count'         => count($cond_hits),
            'sample_paths'  => array_slice(array_column($cond_hits, 'path'), 0, 5),
            'sample_hints'  => array_slice(array_column($cond_hits, 'hint'), 0, 5),
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'Wrap each conditionally-visible block in an etch/condition with explicit shape: { blockName: "etch/condition", attrs: { conditions: [{ source: "{props.show_extras}", operator: "isTruthy" }] }, innerBlocks: [<wrapped content>] }. Operators: isTruthy / isFalsy / == / != / contains / && / ||.',
                    ],
                    'why' => 'etch/condition resolves at render time. Bricks/EtchWP know which branch to ship; CSS-driven visibility hides DOM that still pays render + a11y cost.',
                ],
            ],
            'choices' => [
                'wrap_in_etch_condition',
                'keep_css_visibility',
                'remove_hidden_branch_entirely',
            ],
        ];
    }

    // ── Loops → CPT + ACF + Etch loop ─────────────────────────────────────
    $loops = nibwp_etchwp_detect_loops($payload, 3);
    foreach ($loops as $loop) {
        $family = nibwp_etchwp_loop_family_name($loop);
        $cpt_slug = nibwp_etchwp_propose_cpt_slug($family);
        $fields   = nibwp_etchwp_propose_acf_fields($loop, $ctx);

        $recs[] = [
            'type'           => 'loop_to_cpt',
            'severity'       => $loop['count'] >= 6 ? 'strong' : 'suggestion',
            'summary'        => sprintf(
                'Detected %d repeating "%s" structures. Persisting them statically locks the content into the post. Convert to a CPT + ACF loop and the user can manage entries without re-running the converter.',
                $loop['count'],
                $family
            ),
            'count'          => $loop['count'],
            'sample_classes' => $loop['sample_classes'],
            'sample_paths'   => array_slice($loop['paths'], 0, 3),
            'suggested_chain'=> [
                [
                    'ability'   => 'nibwp/wp-register-cpt',
                    'args_hint' => [
                        'post_type'     => $cpt_slug,
                        'label_plural'  => ucfirst($family) . 's',
                        'label_single'  => ucfirst($family),
                        'supports'      => ['title', 'editor', 'thumbnail', 'excerpt'],
                        'public'        => true,
                        'has_archive'   => true,
                        'show_in_rest'  => true,
                    ],
                    'why' => 'A CPT named "' . $cpt_slug . '" will hold one entry per card. Editors get the standard WP UI; the agent can seed sample data later.',
                ],
                [
                    'ability'   => 'nibwp/acf-manage-fields',
                    'args_hint' => [
                        'action'     => 'create_group',
                        'group_name' => ucfirst($family) . ' fields',
                        'location'   => ['post_type' => $cpt_slug],
                        'fields'     => $fields,
                    ],
                    'why' => 'ACF fields back the dynamic tokens the Etch loop renders. Field shape was guessed from the repeating children — confirm before persisting.',
                ],
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'Resubmit with payload.gutenbergBlock replacing the ' . $loop['count'] . ' static blocks with ONE etch/loop-block that iterates the "' . $cpt_slug . '" CPT and renders one card per entry using ACF tokens.',
                    ],
                    'why' => 'One dynamic loop replaces N static repetitions. Future entries appear automatically.',
                ],
                [
                    'ability'   => 'nibwp/wp-create-post',
                    'args_hint' => [
                        'post_type' => $cpt_slug,
                        'note'      => 'Seed ' . $loop['count'] . ' posts from the original card content so the loop has data on first paint.',
                    ],
                    'why' => 'Migrates the static copy into CPT entries so the page looks identical after the dynamic swap.',
                ],
            ],
            'choices' => [
                'make_dynamic_cpt_acf',
                'use_existing_cpt:<slug>',
                'keep_static',
            ],
        ];
    }

    // ── Images / iframes / gallery / form / div-soup scans ────────────────
    $inner_strings = [];
    nibwp_etchwp_collect_inner_html_simple($payload['gutenbergBlock'] ?? null, $inner_strings);
    // Also fall through to source_html if payload didn't carry the original HTML.
    if ($inner_strings === [] && !empty($ctx['source_html'])) {
        $inner_strings[] = (string) $ctx['source_html'];
    }

    $img_count_no_alt = 0;
    $img_paths_no_alt = [];
    $iframe_youtube   = [];
    $iframe_vimeo     = [];
    $iframe_other     = [];
    $gallery_groups   = 0;
    $form_count       = 0;
    $div_depth_max    = 0;

    foreach ($inner_strings as $idx => $html) {
        // Images without alt.
        if (preg_match_all('/<img\b([^>]*)>/i', $html, $img_matches, PREG_SET_ORDER)) {
            // Loose siblings-of-img heuristic for gallery detection.
            if (count($img_matches) >= 4) {
                $gallery_groups++;
            }
            foreach ($img_matches as $im) {
                $attrs = (string) $im[1];
                if (!preg_match('/\balt\s*=/i', $attrs) || preg_match('/\balt\s*=\s*"\s*"/i', $attrs)) {
                    $img_count_no_alt++;
                    $img_paths_no_alt[] = 'innerHTML[' . $idx . ']';
                }
            }
        }

        // Iframes by provider.
        if (preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', $html, $ifm, PREG_SET_ORDER)) {
            foreach ($ifm as $f) {
                $src = $f[1];
                if (preg_match('~(?:youtube\.com|youtu\.be)~i', $src)) {
                    $iframe_youtube[] = $src;
                } elseif (stripos($src, 'vimeo.com') !== false) {
                    $iframe_vimeo[] = $src;
                } else {
                    $iframe_other[] = $src;
                }
            }
        }

        // Forms (deduplicate with the existing form-detector but flag at orchestrator level too).
        if (preg_match_all('/<form\b/i', $html, $fm)) {
            $form_count += count($fm[0]);
        }

        // Div-soup heuristic: max consecutive nested `<div` without a semantic landmark.
        $div_depth_max = max($div_depth_max, nibwp_etchwp_estimate_div_depth($html));
    }

    if ($img_count_no_alt > 0) {
        $recs[] = [
            'type'         => 'image_no_alt',
            'severity'     => 'warning',
            'summary'      => sprintf('%d <img> tag(s) without an alt attribute. Required for a11y + SEO.', $img_count_no_alt),
            'count'        => $img_count_no_alt,
            'sample_paths' => array_slice($img_paths_no_alt, 0, 5),
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/wp-upload-media',
                    'args_hint' => ['note' => 'Re-upload each image with a generated alt = filename | nearest heading | image-vision description.'],
                    'why' => 'Persisting <img> without alt fails WCAG 1.1.1.',
                ],
            ],
            'choices' => ['generate_alts', 'keep_empty_alt', 'mark_decorative'],
        ];
    }

    if ($iframe_youtube !== [] || $iframe_vimeo !== [] || $iframe_other !== []) {
        $recs[] = [
            'type'      => 'iframe_provider',
            'severity'  => 'suggestion',
            'summary'   => sprintf(
                'Detected %d YouTube + %d Vimeo + %d other iframes. Raw iframes break Etch responsive behavior and lazy-load — use the etch/embed (or core/embed) block per provider.',
                count($iframe_youtube),
                count($iframe_vimeo),
                count($iframe_other),
            ),
            'samples' => [
                'youtube' => array_slice($iframe_youtube, 0, 3),
                'vimeo'   => array_slice($iframe_vimeo, 0, 3),
                'other'   => array_slice($iframe_other, 0, 3),
            ],
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => ['note' => 'Replace each iframe with etch/embed block; pass src + provider. For YouTube/Vimeo, etch/embed auto-renders the responsive shell.'],
                    'why' => 'Native embed block plays nicely with Etch container queries + privacy mode.',
                ],
            ],
            'choices' => ['use_etch_embed', 'use_core_embed', 'keep_raw_iframe'],
        ];
    }

    if ($gallery_groups > 0) {
        $recs[] = [
            'type'    => 'gallery_grid',
            'severity'=> 'suggestion',
            'summary' => sprintf('Detected %d sibling-image group(s) of 4+ images — looks like a gallery. Consider an ACF gallery field on a CPT instead of N static img tags.', $gallery_groups),
            'count'   => $gallery_groups,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/wp-register-cpt',
                    'args_hint' => ['post_type' => 'gallery', 'supports' => ['title', 'thumbnail']],
                    'why' => 'CPT entry per gallery so editors can add/remove without re-running the converter.',
                ],
                [
                    'ability'   => 'nibwp/acf-manage-fields',
                    'args_hint' => [
                        'action'     => 'create_group',
                        'group_name' => 'Gallery fields',
                        'fields'     => [
                            ['name' => 'images',  'type' => 'gallery'],
                            ['name' => 'caption', 'type' => 'text'],
                        ],
                    ],
                    'why' => 'ACF gallery field stores the image set; the Etch loop iterates it.',
                ],
            ],
            'choices' => ['acf_gallery_cpt', 'static_image_block', 'core_gallery_block'],
        ];
    }

    if ($form_count > 0) {
        $recs[] = [
            'type'    => 'form_detected',
            'severity'=> 'strong',
            'summary' => sprintf('Detected %d <form> element(s). Raw <form> HTML inside Etch breaks AJAX validation + spam protection. Use an installed form plugin via nibwp/forms-manage.', $form_count),
            'count'   => $form_count,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/forms-manage',
                    'args_hint' => ['action' => 'list_plugins'],
                    'why' => 'Returns the form plugins installed on this site so the user can pick one (Fluent Forms, Gravity Forms, CF7, etc.).',
                ],
                [
                    'ability'   => 'nibwp/forms-manage',
                    'args_hint' => ['action' => 'create_form', 'plugin' => '<from previous step>', 'fields' => '<from source>'],
                    'why' => 'Creates a real form in the chosen plugin. Returns the shortcode.',
                ],
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => ['note' => 'Resubmit with etch/shortcode block wrapping the returned shortcode + a style-hoist block for the wrapper classes.'],
                    'why' => 'Hands the form rendering back to the plugin while keeping Etch styling on the wrapper.',
                ],
            ],
            'choices' => ['route_to_form_plugin', 'keep_raw_form_html'],
        ];
    }

    if ($div_depth_max >= 6) {
        $recs[] = [
            'type'    => 'div_soup',
            'severity'=> 'suggestion',
            'summary' => sprintf('Source HTML has %d-deep nested <div> chain. Likely a Tailwind / generator dump. Upgrade outer landmarks to <article>/<section>/<header>/<main> for a11y + SEO.', $div_depth_max),
            'depth'   => $div_depth_max,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/etchwp-pro-html-to-component',
                    'args_hint' => ['note' => 'Re-walk the source HTML: top-level wrapper → <section>, hero/CTA wrapper → <article> if it has a heading, repeated cards → <article>, nav → <nav>, footer → <footer>.'],
                    'why' => 'Etch renders any HTML5 tag; semantic landmarks are free quality.',
                ],
            ],
            'choices' => ['semantic_upgrade', 'keep_div_soup'],
        ];
    }

    return $recs;
}

/**
 * Cheap div-depth estimator. Counts the longest consecutive `<div` chain
 * (no semantic landmark in between).
 */
function nibwp_etchwp_estimate_div_depth(string $html): int
{
    $max = 0;
    $cur = 0;
    $tokens = preg_split('/(<\/?[a-z][a-z0-9-]*)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    foreach ($tokens as $tok) {
        $lc = strtolower($tok);
        if ($lc === '<div') {
            $cur++;
            $max = max($max, $cur);
        } elseif (preg_match('/^<(article|section|header|footer|nav|main|aside)$/', $lc)) {
            $cur = 0; // Reset chain at any landmark.
        } elseif ($lc === '</div') {
            $cur = max(0, $cur - 1);
        }
    }
    return $max;
}

/**
 * Collect innerHTML / innerContent strings simply (separate from the validator's
 * helper to keep this file self-contained for include order).
 *
 * @param mixed             $node
 * @param array<int,string> $out (in/out)
 */
function nibwp_etchwp_collect_inner_html_simple($node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }
    $inner = (string) ($node['innerHTML'] ?? '');
    if ($inner !== '') {
        $out[] = $inner;
    }
    foreach ((array) ($node['innerContent'] ?? []) as $piece) {
        if (is_string($piece) && $piece !== '') {
            $out[] = $piece;
        }
    }
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_collect_inner_html_simple($child, $out);
    }
}

/**
 * Derive a human-friendly "family" name from a loop's sample classes.
 */
function nibwp_etchwp_loop_family_name(array $loop): string
{
    foreach ((array) ($loop['sample_classes'] ?? []) as $cls) {
        // Strip brand prefix + BEM modifier/element suffixes.
        $base = (string) preg_replace('/^[a-z0-9]+-/', '', (string) $cls);
        $base = (string) preg_replace('/(__[a-z0-9-]+|--[a-z0-9-]+)$/i', '', $base);
        if ($base !== '' && strlen($base) >= 3) {
            // "project-card" → "project"; "team-member" → "team-member".
            return $base;
        }
    }
    return 'item';
}

/**
 * Suggest a sane CPT slug (kebab-case, ≤20 chars, starts with letter).
 */
function nibwp_etchwp_propose_cpt_slug(string $family): string
{
    $slug = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $family));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'item';
    }
    if (!preg_match('/^[a-z]/', $slug)) {
        $slug = 'x-' . $slug;
    }
    return substr($slug, 0, 20);
}

/**
 * Propose a starter ACF field set from the loop sample. Heuristic only —
 * the agent + user are expected to confirm before persisting.
 *
 * @return array<int,array{name:string,label:string,type:string}>
 */
function nibwp_etchwp_propose_acf_fields(array $loop, array $ctx): array
{
    $fields = [
        ['name' => 'title',     'label' => 'Title',     'type' => 'text'],
        ['name' => 'subtitle',  'label' => 'Subtitle',  'type' => 'text'],
        ['name' => 'image',     'label' => 'Image',     'type' => 'image'],
        ['name' => 'body',      'label' => 'Body',      'type' => 'wysiwyg'],
        ['name' => 'cta_label', 'label' => 'CTA Label', 'type' => 'text'],
        ['name' => 'cta_url',   'label' => 'CTA URL',   'type' => 'url'],
    ];
    // Drop body for very-shallow patterns (likely a button row).
    if ((int) ($loop['depth'] ?? 0) >= 4) {
        return $fields;
    }
    if (in_array($loop['sample_block_name'] ?? '', ['html:a', 'html:li'], true)) {
        return [
            ['name' => 'label', 'label' => 'Label', 'type' => 'text'],
            ['name' => 'url',   'label' => 'URL',   'type' => 'url'],
        ];
    }
    return $fields;
}
