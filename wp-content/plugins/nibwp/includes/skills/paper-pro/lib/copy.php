<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paper Pro — telling real copy from the words that were standing in for it.
 *
 * Every design has both, and the difference matters more after conversion than
 * before it. In Paper, "Lorem ipsum dolor sit amet" is obviously a placeholder,
 * because it is sitting on a canvas next to a designer who knows what it is. On a
 * published page it is a paragraph, indexed, read by a visitor, and quoted back by
 * the client in an email that begins "I thought this was finished".
 *
 * So the plan says which strings it believes are real and which it believes are
 * standing in, and it says that it is a belief. The classification is a heuristic
 * and it is wrong sometimes in both directions: a law firm's page may legitimately
 * contain the word "Title", and a designer may write placeholder copy good enough
 * to pass for the real thing. What it is for is the list — the one the person
 * approving the conversion reads before the page goes live, instead of finding
 * "Button" in the footer three weeks later.
 */

/**
 * Strings that are almost always a stand-in on their own. Matched whole, after
 * trimming and lowercasing — "Title" alone is a placeholder, "Title IX compliance"
 * is not, and a substring match cannot tell those apart.
 *
 * @return array<int,string>
 */
function nibwp_paper_pro_placeholder_exact(): array
{
    return [
        'title', 'subtitle', 'heading', 'subheading', 'headline', 'subhead',
        'button', 'button text', 'cta', 'label', 'caption', 'body', 'body copy',
        'text', 'your text here', 'add text', 'edit text', 'placeholder',
        'click here', 'learn more here', 'link', 'menu item', 'nav item',
        'name', 'name surname', 'first last', 'full name', 'company', 'company name',
        'logo', 'image', 'photo', 'icon', 'untitled', 'tbd', 'tba', 'todo', 'xxx',
        'description', 'short description', 'paragraph', 'quote', 'testimonial',
    ];
}

/**
 * Fragments that give a stand-in away wherever they appear in the string.
 *
 * @return array<int,string>
 */
function nibwp_paper_pro_placeholder_fragments(): array
{
    return [
        'lorem ipsum', 'dolor sit amet', 'consectetur adipiscing', 'sed do eiusmod',
        'tempor incididunt', 'ut labore et dolore', 'excepteur sint occaecat',
        'the quick brown fox', 'example.com', 'example.org', 'john doe', 'jane doe',
        'your company', 'your brand', 'your headline', 'your name', 'your email',
        'insert text', 'sample text', 'dummy text', 'placeholder text',
        'coming soon', 'lorem', '555-0', '123-456-7890', '+1 234 567',
    ];
}

/**
 * Classify the text that came across.
 *
 * @param array<int,array<string,mixed>> $text_nodes as collected by the validator
 * @return array{placeholder:array<int,array<string,mixed>>,real:array<int,array<string,mixed>>,empty:array<int,array<string,mixed>>,duplicated:array<int,array<string,mixed>>,counts:array<string,int>,recommendations:array<int,array<string,string>>}
 */
function nibwp_paper_pro_classify_copy(array $text_nodes): array
{
    $placeholder = [];
    $real = [];
    $blank = [];
    $by_text = [];

    foreach ($text_nodes as $node) {
        $text = (string) ($node['text'] ?? '');
        $entry = [
            'path' => (string) ($node['path'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'tag'  => (string) ($node['tag'] ?? ''),
            'text' => mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text,
        ];

        $trimmed = trim($text);
        if ($trimmed === '') {
            $blank[] = $entry;
            continue;
        }

        $key = mb_strtolower($trimmed);
        $by_text[$key] = ($by_text[$key] ?? 0) + 1;

        $verdict = nibwp_paper_pro_copy_verdict($trimmed);
        if ($verdict !== null) {
            $entry['reason'] = $verdict;
            $placeholder[] = $entry;
            continue;
        }
        $real[] = $entry;
    }

    // The same string three times over is usually a card that was duplicated and
    // never filled in, which is a different problem from a stand-in word.
    $duplicated = [];
    foreach ($by_text as $text => $count) {
        if ($count < 3 || mb_strlen((string) $text) < 8) {
            continue;
        }
        $duplicated[] = [
            'text'  => mb_strlen((string) $text) > 80 ? mb_substr((string) $text, 0, 77) . '…' : (string) $text,
            'count' => (int) $count,
        ];
    }
    usort($duplicated, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    $recommendations = [];
    if ($placeholder !== []) {
        $recommendations[] = [
            'rule'    => 'placeholder_copy',
            'message' => sprintf(
                /* translators: %d: how many text layers look like stand-ins */
                __('%d text layer(s) look like stand-ins rather than copy. Ask for the real words before this is published, or convert it as a draft and say which strings still need replacing — do not quietly ship them.', 'nibwp'),
                count($placeholder)
            ),
        ];
    }
    if ($duplicated !== []) {
        $recommendations[] = [
            'rule'    => 'repeated_copy',
            'message' => __('Some strings appear three times or more. Either the section repeats content that should come from one place, or several cards were duplicated and never filled in — worth checking which before converting.', 'nibwp'),
        ];
    }
    if ($blank !== []) {
        $recommendations[] = [
            'rule'    => 'empty_text_layers',
            'message' => sprintf(
                /* translators: %d: how many text layers arrived empty */
                __('%d text layer(s) arrived with no characters at all. That is usually the handover rather than the design — read the frame again before deciding the design is like that.', 'nibwp'),
                count($blank)
            ),
        ];
    }

    return [
        'placeholder' => $placeholder,
        'real'        => $real,
        'empty'       => $blank,
        'duplicated'  => $duplicated,
        'counts'      => [
            'placeholder' => count($placeholder),
            'real'        => count($real),
            'empty'       => count($blank),
        ],
        'recommendations' => $recommendations,
    ];
}

/**
 * Why this string looks like a stand-in, or null if it reads like real copy.
 */
function nibwp_paper_pro_copy_verdict(string $text): ?string
{
    $lower = mb_strtolower($text);
    $bare = trim($lower, " \t\n\r\0\x0B.:;!?-–—*");

    if (in_array($bare, nibwp_paper_pro_placeholder_exact(), true)) {
        return __('a stand-in word on its own', 'nibwp');
    }

    // "Heading 1", "Card 3", "Button 02" — a stand-in with a number stuck on it,
    // which is what a duplicated layer looks like.
    if (preg_match('/^(' . implode('|', array_map('preg_quote', nibwp_paper_pro_placeholder_exact())) . ')\s*[#]?\d{1,3}$/u', $bare) === 1) {
        return __('a numbered stand-in, the shape a duplicated layer leaves behind', 'nibwp');
    }

    foreach (nibwp_paper_pro_placeholder_fragments() as $fragment) {
        if (str_contains($lower, $fragment)) {
            return sprintf(
                /* translators: %s: the give-away fragment found in the string */
                __('contains "%s"', 'nibwp'),
                $fragment
            );
        }
    }

    // A long run of Latin filler that is not the famous opening. Checked by
    // vocabulary rather than by the first three words, because designers paste from
    // the middle of it as often as from the start.
    $latin = 0;
    $words = preg_split('/\s+/u', $bare) ?: [];
    foreach ($words as $word) {
        if (in_array(trim($word, '.,;:'), [
            'ipsum', 'dolor', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed',
            'eiusmod', 'tempor', 'incididunt', 'labore', 'aliqua', 'enim', 'minim',
            'veniam', 'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris',
            'aliquip', 'commodo', 'consequat', 'velit', 'esse', 'cillum', 'fugiat',
            'pariatur', 'cupidatat', 'proident', 'culpa', 'officia', 'deserunt',
            'mollit', 'laborum', 'vitae', 'nulla', 'vel', 'nunc', 'quam',
        ], true)) {
            $latin++;
        }
    }
    if ($latin >= 3) {
        return __('Latin filler vocabulary', 'nibwp');
    }

    // Repeated single characters, and the keyboard-mashing that stands in for a
    // paragraph when nobody had copy yet.
    if (preg_match('/^(.)\1{3,}$/u', $bare) === 1) {
        return __('one character repeated', 'nibwp');
    }
    if (preg_match('/^(?:asdf|qwerty|test|testing|aaa|bbb|zzz)[\s\d]*$/u', $bare) === 1) {
        return __('typing rather than writing', 'nibwp');
    }

    return null;
}
