<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Mini-site — persister.
 *
 * Renders the validated section tree into a self-contained, theme-neutral
 * landing page (semantic HTML + one scoped <style>), creates a WordPress page,
 * and links it to the course. Content is wp_slash()ed so the markup survives
 * wp_insert_post()'s wp_unslash().
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_tutorlms_minisite_persist(array $payload, array $answers = [])
{
    $page = (array) ($payload['page'] ?? []);
    $sections = array_values((array) ($payload['sections'] ?? []));
    $course_id = (int) ($answers['course_id'] ?? ($payload['course_id'] ?? 0));
    $status = in_array($answers['page_status'] ?? '', ['publish', 'draft'], true) ? (string) $answers['page_status'] : 'draft';

    $html = nibwp_tutorlms_minisite_render($sections);

    $page_id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => $status,
        'post_title'   => sanitize_text_field((string) ($page['title'] ?? 'Course landing page')),
        'post_name'    => sanitize_title((string) ($page['slug'] ?? '')),
        // wp_insert_post() wp_unslash()es the array — pre-slash so the HTML survives.
        'post_content' => wp_slash($html),
    ], true);

    if (is_wp_error($page_id)) {
        return $page_id;
    }
    $page_id = (int) $page_id;

    if ($course_id > 0) {
        update_post_meta($course_id, '_nibwp_course_minisite_page_id', $page_id);
        update_post_meta($page_id, '_nibwp_minisite_course_id', $course_id);
    }

    return [
        'page_id'    => $page_id,
        'course_id'  => $course_id,
        'status'     => $status,
        'url'        => (string) get_permalink($page_id),
        'edit_url'   => (string) get_edit_post_link($page_id, 'raw'),
        'sections'   => count($sections),
    ];
}

/** Render the section tree to a self-contained landing page. */
function nibwp_tutorlms_minisite_render(array $sections): string
{
    $css = '.nibwp-cms{--cms-accent:#2563eb;font-family:inherit;color:#1e293b;line-height:1.65}'
        . '.nibwp-cms__hero{padding:72px 24px;text-align:center;background:linear-gradient(180deg,#f8fafc,#eef2ff)}'
        . '.nibwp-cms__eyebrow{font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:var(--cms-accent);font-weight:700}'
        . '.nibwp-cms h1{font-size:clamp(2rem,5vw,3.25rem);line-height:1.1;margin:.4em 0;font-weight:800;color:#0f172a}'
        . '.nibwp-cms__sub{font-size:1.15rem;color:#475569;max-width:46ch;margin:0 auto}'
        . '.nibwp-cms__sec{padding:56px 24px;max-width:880px;margin:0 auto}'
        . '.nibwp-cms h2{font-size:clamp(1.5rem,3vw,2rem);font-weight:800;color:#0f172a;margin:0 0 .6em}'
        . '.nibwp-cms__btn{display:inline-block;margin-top:24px;padding:14px 28px;border-radius:10px;background:var(--cms-accent);color:#fff;font-weight:700;text-decoration:none}'
        . '.nibwp-cms__grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-top:8px}'
        . '.nibwp-cms__card{border:1px solid #e2e8f0;border-radius:12px;padding:20px;background:#fff}'
        . '.nibwp-cms ul{margin:.5em 0 0 1.2em}.nibwp-cms li{margin:.35em 0}'
        . '.nibwp-cms__quote{border-left:3px solid var(--cms-accent);padding:6px 0 6px 18px;font-style:italic;color:#334155;margin:16px 0}'
        . '.nibwp-cms__cta{text-align:center;background:#0f172a;color:#fff;border-radius:16px}'
        . '.nibwp-cms__cta h2{color:#fff}'
        . '.nibwp-cms details{border-bottom:1px solid #e2e8f0;padding:14px 0}.nibwp-cms summary{cursor:pointer;font-weight:600}';

    $out = "<!-- wp:html -->\n<div class=\"nibwp-cms\">\n<style>{$css}</style>\n";
    foreach ($sections as $section) {
        $out .= nibwp_tutorlms_minisite_render_section((array) $section);
    }
    $out .= "</div>\n<!-- /wp:html -->";
    return $out;
}

/** Render a single section. */
function nibwp_tutorlms_minisite_render_section(array $s): string
{
    $type = (string) ($s['type'] ?? '');
    $h = static fn($v) => esc_html((string) $v);
    $k = static fn($v) => wp_kses_post((string) $v);
    $u = static fn($v) => esc_url((string) $v);
    $btn = static function (array $s) use ($h, $u): string {
        if (empty($s['cta_url'])) {
            return '';
        }
        return '<a class="nibwp-cms__btn" href="' . $u($s['cta_url']) . '">' . $h($s['cta_label'] ?? 'Enroll now') . '</a>';
    };

    switch ($type) {
        case 'hero':
            return '<header class="nibwp-cms__hero">'
                . (empty($s['eyebrow']) ? '' : '<div class="nibwp-cms__eyebrow">' . $h($s['eyebrow']) . '</div>')
                . '<h1>' . $h($s['heading'] ?? '') . '</h1>'
                . (empty($s['sub']) ? '' : '<p class="nibwp-cms__sub">' . $h($s['sub']) . '</p>')
                . $btn($s) . '</header>';

        case 'about':
        case 'text':
            return '<section class="nibwp-cms__sec">'
                . (empty($s['heading']) ? '' : '<h2>' . $h($s['heading']) . '</h2>')
                . '<div>' . $k($s['body'] ?? '') . '</div></section>';

        case 'learn':
            $items = array_map(static fn($i) => '<li>' . $h(is_array($i) ? ($i['title'] ?? '') : $i) . '</li>', (array) ($s['items'] ?? []));
            return '<section class="nibwp-cms__sec"><h2>' . $h($s['heading'] ?? "What you'll learn") . '</h2><ul>' . implode('', $items) . '</ul></section>';

        case 'curriculum':
            $cards = '';
            foreach ((array) ($s['modules'] ?? []) as $m) {
                $m = (array) $m;
                $lessons = array_map(static fn($l) => '<li>' . $h(is_array($l) ? ($l['title'] ?? '') : $l) . '</li>', (array) ($m['lessons'] ?? []));
                $cards .= '<div class="nibwp-cms__card"><strong>' . $h($m['title'] ?? '') . '</strong>'
                    . (empty($m['summary']) ? '' : '<p>' . $h($m['summary']) . '</p>')
                    . ($lessons ? '<ul>' . implode('', $lessons) . '</ul>' : '') . '</div>';
            }
            return '<section class="nibwp-cms__sec"><h2>' . $h($s['heading'] ?? 'Course curriculum') . '</h2><div class="nibwp-cms__grid">' . $cards . '</div></section>';

        case 'instructor':
            return '<section class="nibwp-cms__sec"><h2>' . $h($s['heading'] ?? 'Your instructor') . '</h2>'
                . '<div class="nibwp-cms__card"><strong>' . $h($s['name'] ?? '') . '</strong>'
                . (empty($s['bio']) ? '' : '<p>' . $k($s['bio']) . '</p>') . '</div></section>';

        case 'pricing':
            return '<section class="nibwp-cms__sec" style="text-align:center"><h2>' . $h($s['heading'] ?? 'Enroll today') . '</h2>'
                . (empty($s['price']) ? '' : '<p style="font-size:2rem;font-weight:800">' . $h($s['price']) . '</p>')
                . $btn($s) . '</section>';

        case 'testimonials':
            $q = '';
            foreach ((array) ($s['items'] ?? []) as $t) {
                $t = (array) $t;
                $q .= '<blockquote class="nibwp-cms__quote">' . $h($t['quote'] ?? '') . '<br><strong>' . $h($t['author'] ?? '') . '</strong>'
                    . (empty($t['role']) ? '' : ' — ' . $h($t['role'])) . '</blockquote>';
            }
            return '<section class="nibwp-cms__sec"><h2>' . $h($s['heading'] ?? 'What learners say') . '</h2>' . $q . '</section>';

        case 'faq':
            $f = '';
            foreach ((array) ($s['items'] ?? []) as $item) {
                $item = (array) $item;
                $f .= '<details><summary>' . $h($item['q'] ?? '') . '</summary><div>' . $k($item['a'] ?? '') . '</div></details>';
            }
            return '<section class="nibwp-cms__sec"><h2>' . $h($s['heading'] ?? 'FAQ') . '</h2>' . $f . '</section>';

        case 'cta':
            return '<section class="nibwp-cms__sec nibwp-cms__cta"><h2>' . $h($s['heading'] ?? 'Ready to start?') . '</h2>' . $btn($s) . '</section>';
    }
    return '';
}
