<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What every write into the library shares: the body Etchedy expects, the
 * checks that body has to pass before it is sent, and the caveat that travels
 * back with it.
 *
 * Lives under the skill rather than in the integration file so the skill's
 * abilities can load it themselves. The skill loader checks that Etchedy is
 * present, not that the Etchedy integration card is switched on — with the card
 * off the integration file never loads, and abilities that relied on it for
 * these would fail with "call to undefined function" on the first save.
 */

if (!defined('NIBWP_ETCHEDY_TYPES')) {
    define('NIBWP_ETCHEDY_TYPES', ['layout', 'element', 'component', 'template']);
}

/**
 * Etchedy's own default for the largest file it will write.
 */
if (!defined('NIBWP_ETCHEDY_DEFAULT_MAX_BYTES')) {
    define('NIBWP_ETCHEDY_DEFAULT_MAX_BYTES', 8388608);
}

/**
 * The caveat that travels with every write.
 *
 * Etchedy keeps its library at ETCHEDY_PLUGIN_DIR . 'data/' — inside its own
 * plugin folder. WordPress deletes a plugin's folder when it updates it, so a
 * component saved there is gone the next time Etchedy updates unless Etchedy
 * itself preserves it. NibWP cannot fix where another plugin stores its data,
 * but it can refuse to let somebody find out the hard way.
 */
function nibwp_etchedy_update_warning(): string
{
    return __(
        'Saved into the Etchedy plugin folder. Back this component up before updating Etchedy, which may replace that folder.',
        'nibwp'
    );
}

/**
 * Whether a block carries content worth storing.
 *
 * Etchedy treats a block given as an array as "replace the content with this".
 * So an empty block means "wipe it", and a block holding only styles, or only
 * stray keys, replaces a component with fragments. Real content is a block tree
 * or component definitions.
 *
 * @param mixed $block
 */
function nibwp_etchedy_has_content($block): bool
{
    if (!is_array($block)) {
        return false;
    }

    return !empty($block['gutenbergBlock']) || !empty($block['components']);
}

function nibwp_etchedy_empty_block_error(): WP_Error
{
    return new WP_Error(
        'etchedy_empty_block',
        __('The "block" has no content: it needs a gutenbergBlock tree or component definitions. Sent as it is, it would replace the stored component with nothing. Send the whole component, or leave "block" out to change only the details.', 'nibwp'),
        ['status' => 400]
    );
}

/**
 * Two category names that Etchedy would put in the same folder.
 *
 * It matches folders regardless of case and turns "/" into " - ", so
 * "grids/cards" and " Grids - Cards " are the same place.
 */
function nibwp_etchedy_same_category(string $a, string $b): bool
{
    $normalise = static function (string $category): string {
        return strtolower(trim(str_replace('/', ' - ', $category)));
    };

    return $normalise($a) === $normalise($b);
}

/**
 * Build and check the body for a save or an update.
 *
 * Checked here rather than left to Etchedy because a round trip that comes
 * back "invalid type" has already cost the agent one of its attempts, and the
 * allow-list is a constant in both places.
 *
 * Two details of Etchedy's own handlers shape this:
 *
 *  - The `block` key is present only when the caller supplied one. Etchedy
 *    reads its absence as "leave the content alone" and any array as "replace
 *    it", so a default of [] would turn every rename into a wipe.
 *
 *  - save-component reads the preview image and the demo URL from the block's
 *    own __libraryMeta and nowhere else; a preview_image parameter is ignored.
 *    So when there is a block, both go inside it. update-component reads the
 *    parameters instead, so they are sent as parameters too.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_etchedy_write_body(array $input)
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        return new WP_Error('etchedy_missing_name', __('A "name" is required.', 'nibwp'), ['status' => 400]);
    }

    $type = (string) ($input['type'] ?? '');
    if (!in_array($type, NIBWP_ETCHEDY_TYPES, true)) {
        return new WP_Error(
            'etchedy_invalid_type',
            sprintf(__('"type" must be one of: %s.', 'nibwp'), implode(', ', NIBWP_ETCHEDY_TYPES)),
            ['status' => 400]
        );
    }

    $body = [
        'name' => $name,
        'type' => $type,
        'category' => (string) ($input['category'] ?? ''),
        'brand' => (string) ($input['brand'] ?? ''),
        'tags' => (array) ($input['tags'] ?? []),
        'description' => (string) ($input['description'] ?? ''),
    ];

    foreach (['demo_url', 'preview_image'] as $optional) {
        if (isset($input[$optional]) && $input[$optional] !== '') {
            $body[$optional] = (string) $input[$optional];
        }
    }

    if (array_key_exists('block', $input)) {
        $block = (array) $input['block'];
        if (isset($body['preview_image'])) {
            $block['__libraryMeta']['previewImage'] = $body['preview_image'];
        }
        if (isset($body['demo_url'])) {
            $block['__libraryMeta']['demoUrl'] = $body['demo_url'];
        }
        $body['block'] = $block;
    }

    return $body;
}

/**
 * Refuse a body Etchedy would refuse for its size, before sending it.
 *
 * Measured the way Etchedy measures what it writes — pretty-printed, slashes
 * unescaped, metadata first — against Etchedy's own filterable cap. A compact
 * encoding comes out well over half again smaller on real components, which
 * let three-to-eight-megabyte payloads past a check here only to come back as
 * Etchedy's bare "Component is too large to save."
 *
 * @param array<string, mixed> $body A body from nibwp_etchedy_write_body().
 */
function nibwp_etchedy_payload_too_large(array $body): ?WP_Error
{
    if (!isset($body['block']) || !is_array($body['block'])) {
        return null;
    }

    $block = $body['block'];
    $meta = (array) ($block['__libraryMeta'] ?? []);
    unset($block['__libraryMeta']);
    $meta = array_merge($meta, [
        'brand' => $body['brand'] ?? '',
        'type' => $body['type'] ?? '',
        'category' => $body['category'] ?? '',
        'tags' => $body['tags'] ?? [],
        'name' => $body['name'] ?? '',
        'description' => $body['description'] ?? '',
    ]);

    $cap = function_exists('apply_filters')
        ? (int) apply_filters('etchedy_rest_max_json_bytes', NIBWP_ETCHEDY_DEFAULT_MAX_BYTES)
        : NIBWP_ETCHEDY_DEFAULT_MAX_BYTES;
    if ($cap <= 0) {
        $cap = NIBWP_ETCHEDY_DEFAULT_MAX_BYTES;
    }

    $size = strlen((string) wp_json_encode(array_merge(['__libraryMeta' => $meta], $block), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($size <= $cap) {
        return null;
    }

    return new WP_Error(
        'etchedy_payload_too_large',
        sprintf(
            __('The component would be %1$s MB as Etchedy stores it, and Etchedy accepts %2$s MB. The preview_image is usually the cause — pass a URL rather than a data URI, or leave it out and add it later.', 'nibwp'),
            number_format($size / 1048576, 2),
            number_format($cap / 1048576, 2)
        ),
        ['status' => 413, 'bytes' => $size, 'limit' => $cap]
    );
}
