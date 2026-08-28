<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — orchestrator recommender.
 *
 * Same categories as etchwp-pro, Bricks-specific suggested chains:
 *   - loop_to_query_cpt           : ≥3 repeating sibling structures, content GROWS over time → Query Loop + CPT + ACF + Bricks "posts" element
 *   - extract_to_pseudo_component : 2-8 sibling structures sharing skeleton, differing in copy → section template + CPT + ACF (Bricks' closest analog to "components with props")
 *   - extract_to_section_template : ≥3 sibling structures forming a clean reusable unit → Bricks `section` template + embed via `template` element across pages
 *   - conditional_visibility      : source has display:none / hidden / is-active hints → Bricks element `_conditions` array with operator/value
 *   - image_no_alt                : <img> without alt OR settings.image without title/alt
 *   - iframe_provider             : raw iframe → Bricks "video" element
 *   - form_detected               : raw <form> → Bricks "form" element OR shortcode wrapping forms-manage output
 *   - gallery_grid                : 4+ sibling image elements → "image-gallery" element OR ACF gallery on CPT
 *   - div_soup                    : deeply nested div-only chain → semantic landmark elements (section/article/header/footer)
 *   - dynamic_data_hint           : hardcoded business data → bind to {post_title}/{acf:field}
 *
 * Decision tree:
 *   - Repeating cards whose content grows → loop_to_query_cpt (dynamic CPT)
 *   - Repeating cards whose content is fixed but reused across pages → extract_to_section_template
 *   - Per-element show/hide logic → conditional_visibility (Bricks _conditions)
 */

require_once __DIR__ . '/loop-detector.php';

/**
 * @param array<string,mixed> $payload {elements: [...], global_classes: [...], template_type?: string}
 * @param array<string,mixed> $ctx     {source_html?, element_type?, brand?, has_cpt_context?}
 * @return array<int,array<string,mixed>>
 */
function nibwp_bricks_pro_recommend_abilities(array $payload, array $ctx = []): array
{
    $recs = [];
    $elements = (array) ($payload['elements'] ?? []);
    $source_html = (string) ($ctx['source_html'] ?? '');

    // ── Pseudo-component candidates (Bricks lacks native props, this is the workaround) ─
    // When ≥2 sibling structures share skeleton but differ in copy AND the
    // count is small + fixed (e.g. 3 plan tiers, 4 services), recommend the
    // pseudo-component pattern:
    //   - One section template containing the per-instance subtree
    //   - The subtree uses dynamic data tags ({acf:title}, {acf:body}, {acf:icon})
    //   - One CPT row per instance carries the prop values via ACF fields
    //   - On the consuming template: N `template` elements OR a `posts` query loop
    //     pointing at the CPT
    // This is the closest Bricks-native analog to Etch components with props.
    $pseudo_candidates = nibwp_bricks_pro_detect_loops($elements, 2);
    foreach ($pseudo_candidates as $pcand) {
        if ($pcand['count'] < 2 || $pcand['count'] > 8) {
            continue; // Loop_to_query_cpt covers >5; 0-1 isn't a pattern
        }
        $family   = nibwp_bricks_pro_loop_family_name($pcand);
        $cpt_slug = nibwp_bricks_pro_propose_cpt_slug($family);
        $recs[] = [
            'type'           => 'extract_to_pseudo_component',
            'severity'       => 'suggestion',
            'summary'        => sprintf(
                'Detected %d sibling "%s" structures with same skeleton, different copy. Bricks has no native component-with-props system — the closest analog is: section template + CPT + ACF fields + dynamic data tags. Each instance becomes one CPT entry; the section template renders with {acf:field} bindings. Editing the section template updates every instance.',
                $pcand['count'],
                $family
            ),
            'count'          => $pcand['count'],
            'sample_classes' => $pcand['sample_classes'],
            'sample_paths'   => array_slice($pcand['paths'], 0, 3),
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/wp-register-cpt',
                    'args_hint' => [
                        'post_type'    => $cpt_slug,
                        'label_plural' => ucfirst($family) . 's',
                        'label_single' => ucfirst($family),
                        'supports'     => ['title'],
                        'public'       => false,
                        'show_ui'      => true,
                    ],
                    'why' => 'A non-public CPT named "' . $cpt_slug . '" stores the per-instance prop values. Each entry = one card/tier/service.',
                ],
                [
                    'ability'   => 'nibwp/acf-manage-fields',
                    'args_hint' => [
                        'action'     => 'create_group',
                        'group_name' => ucfirst($family) . ' props',
                        'location'   => ['post_type' => $cpt_slug],
                        'fields'     => [
                            ['name' => 'title',     'label' => 'Title',     'type' => 'text'],
                            ['name' => 'subtitle',  'label' => 'Subtitle',  'type' => 'text'],
                            ['name' => 'icon',      'label' => 'Icon',      'type' => 'image'],
                            ['name' => 'body',      'label' => 'Body',      'type' => 'wysiwyg'],
                            ['name' => 'cta_label', 'label' => 'CTA Label', 'type' => 'text'],
                            ['name' => 'cta_url',   'label' => 'CTA URL',   'type' => 'url'],
                            ['name' => 'variant',   'label' => 'Variant',   'type' => 'select', 'choices' => ['default' => 'Default', 'featured' => 'Featured']],
                        ],
                    ],
                    'why' => 'ACF fields = the component props. The variant select drives conditional content via Bricks _conditions.',
                ],
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'First call: payload.template_type="section" + ONE subtree representing the per-instance render. Inside the subtree use dynamic data: heading.text="{acf:title}", text.text="{acf:body}", image.useFeaturedImage=false + settings.image="{acf:icon}", button.link.url="{acf:cta_url}". Featured ribbon: child element with _conditions=[{key:"acf-field",acfFieldKey:"variant",operator:"==",value:"featured"}]. Server returns { template_id: N }.',
                    ],
                    'why' => 'The section template becomes the "component definition" — dynamic data tags resolve against each CPT entry at render time.',
                ],
                [
                    'ability'   => 'nibwp/wp-create-post',
                    'args_hint' => [
                        'post_type' => $cpt_slug,
                        'note'      => 'Seed ' . $pcand['count'] . ' CPT entries with the prop values from the original ' . $pcand['count'] . ' static cards (title/subtitle/icon/body/cta_label/cta_url/variant per entry).',
                    ],
                    'why' => 'Each entry is one "instance". Editors manage these from the WP admin without re-running the converter.',
                ],
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'Second call on the consuming template: replace the ' . $pcand['count'] . ' static subtrees with ONE `posts` element where settings.query.post_type=["' . $cpt_slug . '"], posts_per_page=' . $pcand['count'] . ', orderby="menu_order". Its child = a single `template` element with settings.template = template_id from step 3.',
                    ],
                    'why' => 'One Query Loop iterates the CPT and embeds the section template per entry. Editing either template updates everywhere.',
                ],
            ],
            'choices' => [
                'pseudo_component_section_acf',
                'use_dynamic_loop_grows_over_time',
                'use_section_template_static_embed',
                'keep_inline_repeat',
            ],
        ];
    }

    // ── Section-template extraction candidates ────────────────────────────
    // ≥3 sibling structures that look like a "clean reusable unit" — a wrapper
    // with a heading + body + CTA — likely a section the user will want to embed
    // on other pages too. Suggest creating a Bricks `section` template + using
    // the `template` element to embed it where needed.
    $section_candidates = nibwp_bricks_pro_detect_loops($elements, 3);
    foreach ($section_candidates as $cand) {
        $family = nibwp_bricks_pro_loop_family_name($cand);
        $recs[] = [
            'type'           => 'extract_to_section_template',
            'severity'       => 'suggestion',
            'summary'        => sprintf(
                'Detected %d sibling "%s" structures that look like a clean reusable unit. Consider creating a Bricks `section` template (template_type="section") with these elements + embedding it via the `template` element. One source of truth across pages.',
                $cand['count'],
                $family
            ),
            'count'          => $cand['count'],
            'sample_classes' => $cand['sample_classes'],
            'sample_paths'   => array_slice($cand['paths'], 0, 3),
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'First submit: payload.template_type="section" + the single ' . $family . ' subtree (one card, not ' . $cand['count'] . '). Server returns { template_id: N }. Then on the original template, replace the ' . $cand['count'] . ' static blocks with N `template` elements where settings.template = template_id from step 1.',
                    ],
                    'why' => 'Section templates are first-class Bricks objects. Embedding via `template` element keeps every consumer in sync when the section is edited.',
                ],
            ],
            'choices' => [
                'extract_to_section_template',
                'use_query_loop_dynamic_instead',
                'keep_inline_repeat',
            ],
        ];
    }

    // ── Conditional-visibility candidates ─────────────────────────────────
    // Source HTML carries explicit visibility intent (display:none / hidden /
    // is-active / aria-hidden / x-show / v-if leftovers). Suggest Bricks element
    // `_conditions` array — Bricks evaluates them server-side and skips the
    // entire element + descendants when false.
    if ($source_html !== '') {
        $cond_hits = [];
        if (preg_match_all('/(\bhidden\b|\bstyle\s*=\s*"[^"]*display\s*:\s*none[^"]*"|\bclass\s*=\s*"[^"]*\b(?:is-active|is-open|is-hidden|x-show|v-if|aria-hidden)[^"]*")/i', $source_html, $cm)) {
            foreach ($cm[0] as $hit) {
                $cond_hits[] = substr($hit, 0, 80);
            }
        }
        if ($cond_hits !== []) {
            $recs[] = [
                'type'         => 'conditional_visibility',
                'severity'     => 'suggestion',
                'summary'      => sprintf(
                    'Detected %d visibility/state hint(s) in source. Use Bricks element `_conditions` array — Bricks skips false branches at render time, no CSS hide hack.',
                    count($cond_hits)
                ),
                'count'        => count($cond_hits),
                'sample_hints' => array_slice($cond_hits, 0, 5),
                'suggested_chain' => [
                    [
                        'ability'   => 'nibwp/bricks-pro-html-to-component',
                        'args_hint' => [
                            'note' => 'Add `_conditions` to the affected element settings: { _conditions: [{ key: "user-role", operator: "==", value: "subscriber" }, { key: "post-meta", postMetaKey: "featured", operator: "==", value: "1" }] }. Bricks evaluates each row server-side; element + descendants render only when ALL rows match (or use group with operator: "any" for OR logic).',
                        ],
                        'why' => 'Bricks _conditions resolve before render. CSS display:none still pays parse + a11y cost.',
                    ],
                ],
                'choices' => [
                    'use_bricks_conditions',
                    'keep_css_visibility',
                    'remove_hidden_branch',
                ],
            ];
        }
    }

    // ── Loops → Query Loop + CPT + ACF ────────────────────────────────────
    $loops = nibwp_bricks_pro_detect_loops($elements, 3);
    foreach ($loops as $loop) {
        $family   = nibwp_bricks_pro_loop_family_name($loop);
        $cpt_slug = nibwp_bricks_pro_propose_cpt_slug($family);
        $fields   = nibwp_bricks_pro_propose_acf_fields($loop);
        $recs[] = [
            'type'           => 'loop_to_query_cpt',
            'severity'       => $loop['count'] >= 6 ? 'strong' : 'suggestion',
            'summary'        => sprintf(
                'Detected %d repeating "%s" structures under one parent. Persisting them statically locks the content into this template. Convert to a Bricks Query Loop backed by a CPT + ACF and editors can manage entries from the WP admin without re-running the converter.',
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
                        'post_type'    => $cpt_slug,
                        'label_plural' => ucfirst($family) . 's',
                        'label_single' => ucfirst($family),
                        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt'],
                        'public'       => true,
                        'has_archive'  => true,
                        'show_in_rest' => true,
                    ],
                    'why' => 'A CPT named "' . $cpt_slug . '" holds one entry per card.',
                ],
                [
                    'ability'   => 'nibwp/acf-manage-fields',
                    'args_hint' => [
                        'action'     => 'create_group',
                        'group_name' => ucfirst($family) . ' fields',
                        'location'   => ['post_type' => $cpt_slug],
                        'fields'     => $fields,
                    ],
                    'why' => 'ACF fields back the dynamic tokens the Bricks Query Loop renders. Confirm field types with the user before persisting.',
                ],
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => [
                        'note' => 'Resubmit the payload replacing the ' . $loop['count'] . ' static elements with a single Bricks "posts" element whose settings.query = { post_type: ["' . $cpt_slug . '"], posts_per_page: ' . $loop['count'] . ' }. Its child elements use dynamic tags like {post_title}, {post_excerpt}, {acf:image}.',
                    ],
                    'why' => 'One Query Loop replaces N static repetitions. Future entries appear automatically.',
                ],
                [
                    'ability'   => 'nibwp/wp-create-post',
                    'args_hint' => [
                        'post_type' => $cpt_slug,
                        'note'      => 'Seed ' . $loop['count'] . ' posts so the loop has data on first paint.',
                    ],
                    'why' => 'Migrates the static copy into CPT entries.',
                ],
            ],
            'choices' => [
                'make_dynamic_cpt_acf',
                'use_existing_cpt:<slug>',
                'keep_static',
            ],
        ];
    }

    // ── Images / iframes / forms / div-soup scans ────────────────────────
    // Bricks payloads don't carry raw HTML in elements (settings.text or
    // settings.image instead). We scan source_html for these conditions.
    $img_no_alt = 0;
    $iframe_youtube = [];
    $iframe_vimeo   = [];
    $iframe_other   = [];
    $form_count     = 0;
    $div_depth_max  = 0;
    $img_total      = 0;

    if ($source_html !== '') {
        if (preg_match_all('/<img\b([^>]*)>/i', $source_html, $m_img, PREG_SET_ORDER)) {
            $img_total = count($m_img);
            foreach ($m_img as $im) {
                $attrs = (string) $im[1];
                if (!preg_match('/\balt\s*=/i', $attrs) || preg_match('/\balt\s*=\s*"\s*"/i', $attrs)) {
                    $img_no_alt++;
                }
            }
        }
        if (preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', $source_html, $ifm, PREG_SET_ORDER)) {
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
        if (preg_match_all('/<form\b/i', $source_html, $m_fm)) {
            $form_count = count($m_fm[0]);
        }
        $div_depth_max = nibwp_bricks_pro_estimate_div_depth($source_html);
    }

    // Also count any code/html element-inner forms + iframes for completeness.
    foreach ($elements as $el) {
        if (!is_array($el)) {
            continue;
        }
        if (in_array((string) ($el['name'] ?? ''), ['code', 'html'], true)) {
            $code = (string) (($el['settings']['code']) ?? '');
            if (preg_match('/<form\b/i', $code)) {
                $form_count++;
            }
            if (preg_match_all('~<iframe\b[^>]*\bsrc\s*=\s*"([^"]+)"~i', $code, $ifm, PREG_SET_ORDER)) {
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
        }
    }

    if ($img_no_alt > 0) {
        $recs[] = [
            'type'         => 'image_no_alt',
            'severity'     => 'warning',
            'summary'      => sprintf('%d / %d <img> tag(s) without an alt attribute. Bricks image elements require alt for a11y + SEO.', $img_no_alt, $img_total),
            'count'        => $img_no_alt,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/wp-upload-media',
                    'args_hint' => ['note' => 'Re-upload each image with a generated alt = nearest heading | image-vision description | sibling caption. Bricks "image" elements pull alt from the attachment\'s post_meta.'],
                    'why' => 'Persisting <img> without alt fails WCAG 1.1.1.',
                ],
            ],
            'choices' => ['generate_alts', 'keep_empty_alt', 'mark_decorative'],
        ];
    }

    if ($iframe_youtube !== [] || $iframe_vimeo !== [] || $iframe_other !== []) {
        $recs[] = [
            'type'      => 'iframe_provider',
            'severity'  => 'strong',
            'summary'   => sprintf(
                'Detected %d YouTube + %d Vimeo + %d other iframe(s). Bricks has a native "video" element that handles responsive shells, privacy mode, and lazy load — never persist raw <iframe>.',
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
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => ['note' => 'Replace each iframe with a Bricks "video" element. YouTube: { name: "video", settings: { videoType: "youtube", url: "https://youtube.com/watch?v=..." } }. Vimeo: videoType=vimeo. Other: videoType=mp4 with direct url + poster.'],
                    'why' => 'Native video element plays nicely with Bricks breakpoints + Bricks Settings → Privacy.',
                ],
            ],
            'choices' => ['use_bricks_video', 'keep_raw_iframe'],
        ];
    }

    if ($form_count > 0) {
        $recs[] = [
            'type'    => 'form_detected',
            'severity'=> 'strong',
            'summary' => sprintf('Detected %d <form> element(s). Bricks has a native "form" element AND a "shortcode" element. Pick one; never persist raw <form> HTML.', $form_count),
            'count'   => $form_count,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/forms-manage',
                    'args_hint' => ['action' => 'list_plugins'],
                    'why' => 'Returns installed form plugins (Fluent Forms / Gravity / WPForms / etc.).',
                ],
                [
                    'ability'   => 'nibwp/forms-manage',
                    'args_hint' => ['action' => 'create_form', 'plugin' => '<from previous>', 'fields' => '<from source>'],
                    'why' => 'Creates a real form in the chosen plugin. Returns the shortcode.',
                ],
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => ['note' => 'Option A: use Bricks native "form" element with settings.fields = [{name, label, type, required, ...}]. Option B: use Bricks "shortcode" element wrapping the chosen plugin shortcode. Most agencies pick B because shipped form plugins handle spam + GDPR + email routing.'],
                    'why' => 'Form rendering stays with a real form plugin OR Bricks native form — never raw HTML.',
                ],
            ],
            'choices' => ['route_to_form_plugin', 'use_bricks_native_form', 'keep_raw_form_html'],
        ];
    }

    // Gallery — ≥4 sibling image elements under one parent.
    $by_parent_image_count = [];
    foreach ($elements as $idx => $el) {
        if (!is_array($el)) {
            continue;
        }
        if (($el['name'] ?? '') === 'image') {
            $p = (string) ($el['parent'] ?? 0);
            $by_parent_image_count[$p] = ($by_parent_image_count[$p] ?? 0) + 1;
        }
    }
    $gallery_groups = 0;
    foreach ($by_parent_image_count as $cnt) {
        if ($cnt >= 4) {
            $gallery_groups++;
        }
    }
    if ($gallery_groups > 0) {
        $recs[] = [
            'type'    => 'gallery_grid',
            'severity'=> 'suggestion',
            'summary' => sprintf('Detected %d sibling-image group(s) of 4+ images — looks like a gallery. Use the Bricks "image-gallery" element OR an ACF gallery field on a CPT.', $gallery_groups),
            'count'   => $gallery_groups,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => ['note' => 'Replace the N image elements with one "image-gallery" element. Settings: { items: [{id, url, alt}, ...], columns: 3, gap: "1rem" }.'],
                    'why' => 'Bricks-native gallery element renders Lightbox + lazy load + responsive cols.',
                ],
                [
                    'ability'   => 'nibwp/wp-register-cpt',
                    'args_hint' => ['post_type' => 'gallery', 'supports' => ['title', 'thumbnail']],
                    'why' => 'For curated long-term galleries: CPT entry per gallery + ACF "gallery" field.',
                ],
            ],
            'choices' => ['bricks_image_gallery', 'acf_gallery_cpt', 'static_image_elements'],
        ];
    }

    if ($div_depth_max >= 6) {
        $recs[] = [
            'type'    => 'div_soup',
            'severity'=> 'suggestion',
            'summary' => sprintf('Source HTML has %d-deep nested <div> chain (likely Tailwind/Webflow dump). Upgrade outer landmarks: section/container with semantic tags + Bricks-native heading/text/button elements instead of div soup.', $div_depth_max),
            'depth'   => $div_depth_max,
            'suggested_chain' => [
                [
                    'ability'   => 'nibwp/bricks-pro-html-to-component',
                    'args_hint' => ['note' => 'Re-walk the source: top-level wrapper → Bricks "section". Hero/CTA wrapper with heading → "section" with semantic tag="article". Nav → "section" tag="nav" with "nav-menu" inside. Footer → "section" tag="footer". Each heading/text gets its own Bricks element.'],
                    'why' => 'Bricks renders any HTML5 tag via settings.tag; semantic landmarks are free quality.',
                ],
            ],
            'choices' => ['semantic_upgrade', 'keep_div_soup'],
        ];
    }

    // dynamic_data_hint — when the template_type is 'content' (single post) and
    // the payload has static text that looks like post fields.
    $template_type = (string) ($payload['template_type'] ?? '');
    if (in_array($template_type, ['content', 'archive'], true)) {
        $static_title_count = 0;
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if (($el['name'] ?? '') === 'heading' && !empty($el['settings']['text']) && is_string($el['settings']['text'])) {
                $text = (string) $el['settings']['text'];
                if (strlen($text) <= 80 && !str_contains($text, '{')) {
                    $static_title_count++;
                }
            }
        }
        if ($static_title_count > 0) {
            $recs[] = [
                'type'    => 'dynamic_data_hint',
                'severity'=> 'suggestion',
                'summary' => sprintf('Template type is `%s` but %d heading(s) contain static text. Bind to {post_title}, {acf:field_name}, {wp:user_meta} so the same template renders every post correctly.', $template_type, $static_title_count),
                'count'   => $static_title_count,
                'suggested_chain' => [
                    [
                        'ability'   => 'nibwp/bricks-pro-html-to-component',
                        'args_hint' => ['note' => 'Replace static text with dynamic tags: heading.text = "{post_title}". Body excerpts: "{post_excerpt:25}". Author: "{post_author_name}". Date: "{post_date}". Custom: "{acf:project_client}".'],
                        'why' => 'Bricks dynamic data resolves at render time from the current post in scope.',
                    ],
                ],
                'choices' => ['use_dynamic_data', 'keep_static'],
            ];
        }
    }

    return $recs;
}

/**
 * Estimate the longest consecutive <div> chain (no semantic landmark in between).
 */
function nibwp_bricks_pro_estimate_div_depth(string $html): int
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
            $cur = 0;
        } elseif ($lc === '</div') {
            $cur = max(0, $cur - 1);
        }
    }
    return $max;
}

/**
 * Derive a human-friendly family name from a loop's sample classes.
 */
function nibwp_bricks_pro_loop_family_name(array $loop): string
{
    foreach ((array) ($loop['sample_classes'] ?? []) as $cls) {
        $base = (string) preg_replace('/^[a-z0-9]+-/', '', (string) $cls);
        $base = (string) preg_replace('/(__[a-z0-9-]+|--[a-z0-9-]+)$/i', '', $base);
        if ($base !== '' && strlen($base) >= 3) {
            return $base;
        }
    }
    return $loop['sample_element_name'] ?: 'item';
}

function nibwp_bricks_pro_propose_cpt_slug(string $family): string
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
 * @return array<int,array{name:string,label:string,type:string}>
 */
function nibwp_bricks_pro_propose_acf_fields(array $loop): array
{
    return [
        ['name' => 'title',     'label' => 'Title',     'type' => 'text'],
        ['name' => 'subtitle',  'label' => 'Subtitle',  'type' => 'text'],
        ['name' => 'image',     'label' => 'Image',     'type' => 'image'],
        ['name' => 'body',      'label' => 'Body',      'type' => 'wysiwyg'],
        ['name' => 'cta_label', 'label' => 'CTA Label', 'type' => 'text'],
        ['name' => 'cta_url',   'label' => 'CTA URL',   'type' => 'url'],
    ];
}
