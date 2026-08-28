<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Mini-site — section-tree validator. Returns the house shape:
 * { passed, failed[], warnings[], recommendations[], unchecked_items[{id,path,msg,fix_hint}], summary }
 */

function nibwp_tutorlms_minisite_section_types(): array
{
    return ['hero', 'about', 'learn', 'curriculum', 'instructor', 'pricing', 'testimonials', 'faq', 'cta'];
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $answers
 */
function nibwp_tutorlms_minisite_validate(array $payload, array $answers = []): array
{
    $unchecked = [];
    $warnings = [];
    $recommendations = [];
    $fail = static function (string $id, string $path, string $msg, string $fix) use (&$unchecked): void {
        $unchecked[] = ['id' => $id, 'path' => $path, 'msg' => $msg, 'fix_hint' => $fix];
    };

    $page = (array) ($payload['page'] ?? []);
    $sections = array_values((array) ($payload['sections'] ?? []));

    if (trim((string) ($page['title'] ?? '')) === '') {
        $fail('page_title', 'page.title', 'Page title is empty.', 'Set page.title (e.g. the course name + " — Course").');
    }
    if (count($sections) === 0) {
        $fail('no_sections', 'sections', 'No sections.', 'Add sections: at least a hero and an enroll cta.');
    }

    $has_hero = false;
    $has_cta = false;
    foreach ($sections as $i => $section) {
        $section = (array) $section;
        $type = (string) ($section['type'] ?? '');
        $p = "sections[$i]";
        if (!in_array($type, nibwp_tutorlms_minisite_section_types(), true)) {
            $fail('section_type', "$p.type", sprintf('Unknown section type "%s".', $type), 'Use one of: ' . implode(', ', nibwp_tutorlms_minisite_section_types()) . '.');
            continue;
        }
        // No scripts anywhere.
        $blob = wp_json_encode($section);
        if (is_string($blob) && preg_match('/<\s*script\b/i', $blob)) {
            $fail('section_script', $p, 'Section contains a <script> tag.', 'Remove any <script> markup.');
        }
        switch ($type) {
            case 'hero':
                $has_hero = true;
                if (trim((string) ($section['heading'] ?? '')) === '') {
                    $fail('hero_heading', "$p.heading", 'Hero has no heading.', 'Set a strong hero heading (the course promise).');
                }
                if (!empty($section['cta_url'])) {
                    $has_cta = true;
                }
                break;
            case 'cta':
                $has_cta = true;
                if (trim((string) ($section['cta_label'] ?? '')) === '' || trim((string) ($section['cta_url'] ?? '')) === '') {
                    $fail('cta_fields', $p, 'CTA needs cta_label + cta_url.', 'Set cta_label (e.g. "Enroll now") and cta_url (the course URL).');
                }
                break;
            case 'about':
            case 'text':
                if (trim((string) ($section['heading'] ?? '')) === '' && trim((string) ($section['body'] ?? '')) === '') {
                    $fail('about_empty', $p, 'About/text section is empty.', 'Provide a heading and body.');
                }
                break;
            case 'instructor':
                if (trim((string) ($section['name'] ?? '')) === '') {
                    $fail('instructor_name', "$p.name", 'Instructor section has no name.', 'Set the instructor name.');
                }
                break;
            case 'pricing':
                if (trim((string) ($section['cta_url'] ?? '')) === '') {
                    $warnings[] = "$p: pricing section has no cta_url (enroll link).";
                }
                break;
        }
    }

    if (!$has_hero) {
        $fail('missing_hero', 'sections', 'No hero section.', 'Add a { "type":"hero", "heading":"..." } section.');
    }
    if (!$has_cta) {
        $fail('missing_cta', 'sections', 'No enroll CTA.', 'Add a { "type":"cta", "cta_label":"Enroll now", "cta_url":"..." } section (or give the hero a cta_url).');
    }
    if (count($sections) < 3) {
        $recommendations[] = ['id' => 'thin_page', 'summary' => 'Only a couple of sections — add what-you-will-learn, curriculum and instructor for a convincing landing page.'];
    }

    return [
        'passed' => $unchecked === [],
        'failed' => [],
        'warnings' => $warnings,
        'recommendations' => $recommendations,
        'unchecked_items' => $unchecked,
        'summary' => ['sections' => count($sections)],
    ];
}
