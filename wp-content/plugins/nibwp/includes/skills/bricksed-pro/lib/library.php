<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What every write into the library shares: the body Bricksed expects, the
 * checks that body has to pass before it is sent, and the caveat that travels
 * back with it.
 *
 * Lives under the skill rather than in the integration file so the skill's
 * abilities can load it themselves. The skill loader checks that Bricksed is
 * present, not that the Bricksed integration card is switched on.
 *
 * Two content shapes live in a Bricksed library, and both are kept:
 *
 *  - Bricks data, the way Bricksed stores what is saved from the Bricks
 *    builder: { type: "block", bricks: { content, globalClasses, components } }.
 *    This is what an agent saves.
 *  - The shared library format (gutenbergBlock + components + styles), which
 *    Bricksed converts to Bricks elements whenever it is read with
 *    format=bricks. Most of the official library is stored this way.
 */

if (!defined('NIBWP_BRICKSED_TYPES')) {
    define('NIBWP_BRICKSED_TYPES', ['layout', 'element', 'component', 'template']);
}

/**
 * Bricksed's own default for the largest file it will write.
 */
if (!defined('NIBWP_BRICKSED_DEFAULT_MAX_BYTES')) {
    define('NIBWP_BRICKSED_DEFAULT_MAX_BYTES', 8388608);
}

/**
 * The caveat that travels with every write.
 *
 * Bricksed keeps its library at BRICKSED_PLUGIN_DIR . 'data/' — inside its own
 * plugin folder. A plugin update can replace that folder, so a component saved
 * there may be gone after the next update unless it was backed up.
 */
function nibwp_bricksed_update_warning(): string
{
    return __(
        'Saved into the Bricksed plugin folder. Back this component up before updating Bricksed, which may replace that folder.',
        'nibwp'
    );
}

/**
 * The Bricks data inside a block, in Bricksed's stored shape, or null when the
 * block holds the shared library format instead.
 *
 * Accepts the stored shape ({bricks: {content}}), and what Bricks itself puts
 * on the clipboard ({content, globalClasses, components}), since that is what
 * an agent reading a Bricks page naturally has in hand.
 *
 * @param mixed $block
 * @return array{content: array<int, mixed>, globalClasses: array<int, mixed>, components?: array<int, mixed>}|null
 */
function nibwp_bricksed_bricks_of($block): ?array
{
    if (!is_array($block)) {
        return null;
    }

    $data = null;
    if (isset($block['bricks']) && is_array($block['bricks']) && isset($block['bricks']['content'])) {
        $data = $block['bricks'];
    } elseif (isset($block['content']) && is_array($block['content']) && !isset($block['gutenbergBlock'])) {
        $data = $block;
    }
    if ($data === null) {
        return null;
    }

    $out = [
        'content' => array_values((array) $data['content']),
        'globalClasses' => array_values((array) ($data['globalClasses'] ?? [])),
    ];
    if (!empty($data['components']) && is_array($data['components'])) {
        $out['components'] = array_values($data['components']);
    }

    return $out;
}

/**
 * The block in the shape Bricksed stores: Bricks data under "bricks", with
 * the clipboard's own keys dropped, or the shared format untouched.
 *
 * @param array<string, mixed> $block
 * @return array<string, mixed>
 */
function nibwp_bricksed_normalize_block(array $block): array
{
    $bricks = nibwp_bricksed_bricks_of($block);
    if ($bricks === null) {
        return $block;
    }

    $out = ['type' => 'block', 'bricks' => $bricks];
    if (isset($block['__libraryMeta']) && is_array($block['__libraryMeta'])) {
        $out = ['__libraryMeta' => $block['__libraryMeta']] + $out;
    }

    return $out;
}

/**
 * Whether a block carries content worth storing.
 *
 * Bricksed treats a block given as an array as "replace the content with
 * this". So an empty block means "wipe it", and a block holding only classes
 * or stray keys replaces a component with fragments. Real content is a Bricks
 * element list, a block tree or component definitions.
 *
 * @param mixed $block
 */
function nibwp_bricksed_has_content($block): bool
{
    if (!is_array($block)) {
        return false;
    }

    $bricks = nibwp_bricksed_bricks_of($block);
    if ($bricks !== null) {
        return $bricks['content'] !== [];
    }

    return !empty($block['gutenbergBlock']) || !empty($block['components']);
}

function nibwp_bricksed_empty_block_error(): WP_Error
{
    return new WP_Error(
        'bricksed_empty_block',
        __('The "block" has no content: it needs Bricks elements (bricks.content), or a gutenbergBlock tree or component definitions. Sent as it is, it would replace the stored component with nothing. Send the whole component, or leave "block" out to change only the details.', 'nibwp'),
        ['status' => 400]
    );
}

/**
 * Two category names that Bricksed would put in the same folder.
 *
 * It matches folders regardless of case and turns "/" into " - ".
 */
function nibwp_bricksed_same_category(string $a, string $b): bool
{
    $normalise = static function (string $category): string {
        return strtolower(trim(str_replace('/', ' - ', $category)));
    };

    return $normalise($a) === $normalise($b);
}

/**
 * Build and check the body for a save or an update.
 *
 *  - The `block` key is present only when the caller supplied one. Bricksed
 *    reads its absence as "leave the content alone" and any array as "replace
 *    it", so a default of [] would turn every rename into a wipe.
 *
 *  - save-component reads the preview image and the demo URL from the block's
 *    own __libraryMeta and nowhere else. So when there is a block, both go
 *    inside it. update-component reads the parameters, so they are sent as
 *    parameters too.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_write_body(array $input)
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        return new WP_Error('bricksed_missing_name', __('A "name" is required.', 'nibwp'), ['status' => 400]);
    }

    $type = (string) ($input['type'] ?? '');
    if (!in_array($type, NIBWP_BRICKSED_TYPES, true)) {
        return new WP_Error(
            'bricksed_invalid_type',
            sprintf(
                /* translators: %s: allowed types */
                __('"type" must be one of: %s.', 'nibwp'),
                implode(', ', NIBWP_BRICKSED_TYPES)
            ),
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
        $block = nibwp_bricksed_normalize_block((array) $input['block']);
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
 * Refuse a body Bricksed would refuse for its size, before sending it.
 *
 * Measured the way Bricksed measures what it writes — pretty-printed, slashes
 * unescaped, metadata first — against Bricksed's own filterable cap.
 *
 * @param array<string, mixed> $body A body from nibwp_bricksed_write_body().
 */
function nibwp_bricksed_payload_too_large(array $body): ?WP_Error
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
        ? (int) apply_filters('bricksed_rest_max_json_bytes', NIBWP_BRICKSED_DEFAULT_MAX_BYTES)
        : NIBWP_BRICKSED_DEFAULT_MAX_BYTES;
    if ($cap <= 0) {
        $cap = NIBWP_BRICKSED_DEFAULT_MAX_BYTES;
    }

    $size = strlen((string) wp_json_encode(array_merge(['__libraryMeta' => $meta], $block), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($size <= $cap) {
        return null;
    }

    return new WP_Error(
        'bricksed_payload_too_large',
        sprintf(
            /* translators: 1: size in MB, 2: limit in MB */
            __('The component would be %1$s MB as Bricksed stores it, and Bricksed accepts %2$s MB. The preview_image is usually the cause — pass a URL rather than a data URI, or leave it out and add it later.', 'nibwp'),
            number_format($size / 1048576, 2),
            number_format($cap / 1048576, 2)
        ),
        ['status' => 413, 'bytes' => $size, 'limit' => $cap]
    );
}
