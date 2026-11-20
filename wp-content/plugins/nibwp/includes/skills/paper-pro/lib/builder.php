<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Paper Pro — settling which builder this design should become.
 *
 * Shared by the plan and the conversion on purpose: the two must agree, and the way
 * they drifted apart in every version of this that was written twice is that one of
 * them learned about a new builder and the other did not.
 */

/**
 * Which builder, and why.
 *
 * Preference order matters less than honesty about where the answer came from. A
 * builder chosen because the site already uses it is a different claim from one
 * chosen because nothing else was reported, and the second one should not be dressed
 * up as the first.
 *
 * @param array<mixed> $available
 * @return array<string,mixed>
 */
function nibwp_paper_pro_choose_builder(string $requested, array $available): array
{
    $known = ['etchwp', 'bricks', 'elementor', 'gutenberg'];
    $catalog = [];

    foreach ($available as $key => $entry) {
        if (is_array($entry)) {
            $slug = (string) ($entry['key'] ?? $entry['slug'] ?? $entry['id'] ?? (is_string($key) ? $key : ''));
            if ($slug === '') {
                continue;
            }
            $catalog[$slug] = [
                'key'    => $slug,
                'label'  => (string) ($entry['label'] ?? $entry['name'] ?? $slug),
                'active' => !empty($entry['active']) || !empty($entry['is_active']),
            ];
            continue;
        }
        if (is_string($entry) && $entry !== '') {
            $catalog[$entry] = ['key' => $entry, 'label' => $entry, 'active' => false];
        }
    }

    if ($requested !== '' && $requested !== 'auto') {
        $present = $catalog === [] ? null : isset($catalog[$requested]);

        return [
            'target'  => $requested,
            'reason'  => __('You asked for this one.', 'nibwp'),
            'source'  => 'requested',
            'warning' => $present === false
                ? sprintf(
                    /* translators: 1: the builder asked for, 2: comma-separated builders reported */
                    __('%1$s was not in the list this site reported (%2$s). The conversion will be refused by the integration rather than landing as something else — switch the builder on, or target one that is there.', 'nibwp'),
                    $requested,
                    implode(', ', array_keys($catalog))
                )
                : '',
            'alternatives' => array_values(array_diff(array_keys($catalog) ?: $known, [$requested])),
        ];
    }

    foreach ($catalog as $entry) {
        if ($entry['active']) {
            return [
                'target'       => $entry['key'],
                'reason'       => sprintf(
                    /* translators: %s: the builder label */
                    __('This site already builds with %s, so the converted page will be editable by whoever maintains the rest of it. Matching the site matters more than any ranking of builders.', 'nibwp'),
                    $entry['label']
                ),
                'source'       => 'active_on_this_site',
                'warning'      => '',
                'alternatives' => array_values(array_diff(array_keys($catalog), [$entry['key']])),
            ];
        }
    }

    foreach ($known as $slug) {
        if (isset($catalog[$slug])) {
            return [
                'target'       => $slug,
                'reason'       => sprintf(
                    /* translators: %s: the builder key */
                    __('No builder reported itself as the active one, and %s is available here. Confirm it with the user before converting rather than assuming.', 'nibwp'),
                    $slug
                ),
                'source'       => 'available_not_confirmed',
                'warning'      => __('This is a guess from a list, not a reading of what the site uses. Ask.', 'nibwp'),
                'alternatives' => array_values(array_diff(array_keys($catalog), [$slug])),
            ];
        }
    }

    return [
        'target'       => 'gutenberg',
        'reason'       => __('Nothing was reported about this site\'s builders, so the answer is core blocks: they are present on every WordPress install and a page built from them survives a builder being switched off later.', 'nibwp'),
        'source'       => 'fallback',
        'warning'      => __('Chosen because nothing else is known, not because it suits this design best. Read the integration\'s list_builders action and plan again if the site has a builder.', 'nibwp'),
        'alternatives' => $known,
    ];
}
