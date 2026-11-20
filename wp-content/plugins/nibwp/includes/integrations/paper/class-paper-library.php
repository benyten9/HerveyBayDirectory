<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Paper_Library — the local store of designs the agent has handed over.
 *
 * Two options, not one, and for the same reason the Figma library splits them: the
 * card metadata is read on every listing and every admin page load, while the
 * normalized tree is read only when something is actually converted. A full tree
 * is orders of magnitude bigger than the card beside it, so it lives in its own
 * autoload-off option keyed by design id and the index stays cheap.
 *
 * The index is capped. An option that grows without a ceiling is a slow outage,
 * and a design library with two hundred entries in it is already past the point
 * where anyone is finding things by scrolling — so the two-hundred-and-first
 * ingest is refused by name rather than accepted and regretted.
 */
class NIBWP_Paper_Library
{
    private const OPTION = 'nibwp_paper_library';

    /** Designs kept. Past this, ingest refuses and says which to delete. */
    public const MAX_DESIGNS = 200;

    /** Conversions remembered per design, newest first. */
    private const MAX_CONVERSIONS = 50;

    /** Warnings kept per design. The full set came back from ingest already. */
    private const MAX_WARNINGS = 50;

    /** Swatches a card shows. Six reads as a palette and still fits one row. */
    private const CARD_COLORS = 6;

    /**
     * The file id every sample design is stored under.
     *
     * It seeds the design id, so loading the samples twice lands on the same four
     * ids instead of making copies — and a real design, which arrives with Paper's
     * own file id, cannot collide with one.
     */
    private const SAMPLE_FILE_ID = 'nibwp-paper-samples';

    /** @return array<string, array<string, mixed>> id → entry */
    public static function all(): array
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? $all : [];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $all = self::all();

        return isset($all[$id]) && is_array($all[$id]) ? $all[$id] : null;
    }

    /**
     * A stable id, so re-ingesting the same Paper node updates in place instead of
     * filling the library with copies of one design.
     */
    public static function id(string $paper_url, string $file_id, string $node_id, string $name): string
    {
        $seed = $file_id . '|' . $node_id;
        if (trim($seed, '|') === '') {
            $seed = $paper_url;
        }
        if (trim($seed, '|') === '') {
            // Nothing identifying arrived, so the label is all there is. A second
            // ingest under the same label updates the same entry, which is what a
            // designer iterating on one frame expects.
            $seed = 'name:' . sanitize_title($name);
        }

        return substr(md5('paper|' . $seed), 0, 16);
    }

    /**
     * The handle a person or an agent calls a design by — @paper/hero-section.
     * Unique across the library, and a design keeps its own on re-ingest.
     */
    public static function make_handle(string $name, string $id): string
    {
        $base = sanitize_title($name);
        if ($base === '') {
            $base = 'design';
        }

        $taken = [];
        foreach (self::all() as $entry) {
            if (((string) ($entry['id'] ?? '')) !== $id && ((string) ($entry['handle'] ?? '')) !== '') {
                $taken[(string) $entry['handle']] = true;
            }
        }

        $handle = $base;
        $n = 2;
        while (isset($taken[$handle])) {
            $handle = $base . '-' . $n++;
        }

        return $handle;
    }

    /**
     * Resolve however a caller referred to a design.
     *
     * The library advertises handles as @paper/home, so that is the first thing
     * anyone types — running it straight through sanitize_title() would turn it
     * into "paperhome" and match nothing. The Figma library learned that the hard
     * way; this one accepts the prefixed handle, the bare handle, the id, or the
     * design's name.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        $bare = preg_replace('#^@?(?:paper/)?#i', '', $ref) ?? $ref;
        $all = self::all();

        foreach ([$bare, $ref] as $candidate) {
            if (isset($all[$candidate]) && is_array($all[$candidate])) {
                return $all[$candidate];
            }
        }

        foreach ([$bare, $ref] as $candidate) {
            $slug = sanitize_title($candidate);
            foreach ($all as $entry) {
                if (is_array($entry) && ((string) ($entry['handle'] ?? '')) === $slug) {
                    return $entry;
                }
            }
        }

        $slug = sanitize_title($bare);
        foreach ($all as $entry) {
            if (is_array($entry) && sanitize_title((string) ($entry['name'] ?? '')) === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every handle that does exist, for a refusal that tells the caller what IS
     * there instead of only what is not.
     *
     * @return array<int, string>
     */
    public static function known_handles(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            $handle = (string) (((array) $entry)['handle'] ?? '');
            if ($handle !== '') {
                $out[] = '@paper/' . $handle;
            }
        }

        return $out;
    }

    /**
     * The bounded card shape every listing returns.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function card(array $entry): array
    {
        $conversions = (array) ($entry['conversions'] ?? []);
        $last = $conversions === [] ? 0 : (int) (((array) reset($conversions))['at'] ?? 0);
        $colors = (array) (((array) ($entry['tokens'] ?? []))['colors'] ?? []);

        return [
            'design_id' => (string) ($entry['id'] ?? ''),
            'handle' => '@paper/' . (string) ($entry['handle'] ?? ''),
            'name' => (string) ($entry['name'] ?? ''),
            'source' => (string) ($entry['source'] ?? ''),
            'paper_url' => (string) ($entry['paper_url'] ?? ''),
            'screenshot_url' => (string) ($entry['screenshot_url'] ?? ''),
            'created' => (int) ($entry['created'] ?? 0),
            'updated' => (int) ($entry['updated'] ?? 0),
            'stats' => (array) ($entry['stats'] ?? []),
            'counts' => [
                'assets' => count((array) ($entry['assets'] ?? [])),
                'fonts' => count((array) ($entry['fonts'] ?? [])),
                'warnings' => count((array) ($entry['warnings'] ?? [])),
                'conversions' => count($conversions),
                'colors' => count($colors),
            ],
            // The design's own palette, so a card can show it rather than only say
            // how many colours there are.
            'colors' => self::swatches($colors),
            // What ingest counted, not a walk of the stored tree: a listing renders
            // every card on one page load, and reading each design's NDO option to
            // count nodes would turn a cheap screen into an expensive one. 0 means
            // the entry predates the stat, not that the design is empty.
            'nodes' => (int) (((array) ($entry['stats'] ?? []))['nodes'] ?? 0),
            // True only for something samples_load() put here.
            'sample' => !empty($entry['sample']),
            'converted' => $conversions !== [],
            'last_converted' => $last,
            // Re-ingested after the last build → whatever was built is behind.
            'stale' => $last > 0 && (int) ($entry['updated'] ?? 0) > $last,
        ];
    }

    /**
     * A card's swatch row: the design's colour tokens, deduplicated and capped.
     *
     * Values come out of ingest's colour cleaner, so they are already CSS colours
     * and nothing else — a token map is still outside input, and a swatch ends up
     * in a style attribute.
     *
     * @param array<string, mixed> $colors token name → colour
     * @return array<int, string>
     */
    private static function swatches(array $colors): array
    {
        $out = [];
        foreach ($colors as $value) {
            if (count($out) >= self::CARD_COLORS) {
                break;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || in_array($value, $out, true)) {
                continue;
            }
            $out[] = $value;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $all = self::all();
        $converted = 0;
        $stale = 0;
        $newest = 0;
        foreach ($all as $entry) {
            $entry = (array) $entry;
            $conversions = (array) ($entry['conversions'] ?? []);
            if ($conversions !== []) {
                $converted++;
                $last = (int) (((array) reset($conversions))['at'] ?? 0);
                if ((int) ($entry['updated'] ?? 0) > $last) {
                    $stale++;
                }
            }
            $newest = max($newest, (int) ($entry['updated'] ?? 0));
        }

        return [
            'designs' => count($all),
            'converted' => $converted,
            'stale' => $stale,
            'last_ingest' => $newest,
            'capacity' => self::MAX_DESIGNS,
        ];
    }

    /**
     * Store one prepared design plus its NDO. Returns the stored entry.
     *
     * @param array<string, mixed> $prepared from NIBWP_Paper_Ingest::prepare()
     * @param array<string, mixed> $ndo
     * @return array<string, mixed>|WP_Error
     */
    public static function save_design(array $prepared, array $ndo)
    {
        $id = self::id(
            (string) ($prepared['paper_url'] ?? ''),
            (string) ($prepared['file_id'] ?? ''),
            (string) ($prepared['node_id'] ?? ''),
            (string) ($prepared['name'] ?? '')
        );

        $all = self::all();
        $existing = isset($all[$id]) && is_array($all[$id]) ? $all[$id] : null;

        if ($existing === null && count($all) >= self::MAX_DESIGNS) {
            return nibwp_paper_err(
                'library_full',
                sprintf(
                    /* translators: %d: the library's maximum number of designs */
                    __('The design library holds its maximum of %d designs. Delete one with delete_design before ingesting another.', 'nibwp'),
                    self::MAX_DESIGNS
                ),
                409
            );
        }

        $now = time();
        $name = (string) ($prepared['name'] ?? '');

        // Re-ingest keeps the handle and the conversion history: the agent is
        // updating a design somebody already built a page from, and renaming it
        // under them would break every prompt that referred to it.
        $entry = [
            'id' => $id,
            'handle' => $existing !== null && (string) ($existing['handle'] ?? '') !== ''
                ? (string) $existing['handle']
                : self::make_handle($name, $id),
            'name' => $name,
            'source' => (string) ($prepared['source'] ?? ''),
            'paper_url' => (string) ($prepared['paper_url'] ?? ''),
            'file_id' => (string) ($prepared['file_id'] ?? ''),
            'node_id' => (string) ($prepared['node_id'] ?? ''),
            'screenshot_url' => (string) ($prepared['screenshot_url'] ?? ''),
            'created' => $existing !== null ? (int) ($existing['created'] ?? $now) : $now,
            'updated' => $now,
            'stats' => (array) ($prepared['stats'] ?? []),
            'tokens' => (array) ($ndo['tokens'] ?? []),
            'fonts' => (array) ($prepared['fonts'] ?? []),
            'assets' => self::merge_assets((array) ($existing['assets'] ?? []), (array) ($prepared['assets'] ?? [])),
            'warnings' => array_slice(array_values((array) ($ndo['warnings'] ?? [])), 0, self::MAX_WARNINGS),
            'conversions' => array_slice((array) ($existing['conversions'] ?? []), 0, self::MAX_CONVERSIONS),
        ];

        $all[$id] = $entry;
        update_option(self::OPTION, $all, false);
        update_option(nibwp_paper_ndo_option($id), $ndo, false);

        return $entry;
    }

    /**
     * Keep an attachment a previous ingest already resolved.
     *
     * Without this, re-ingesting a design would forget every image attach_asset had
     * put into the Media Library and the next convert would come back with "no URL
     * yet" for pictures that are sitting right there.
     *
     * @param array<int, array<string, mixed>> $old
     * @param array<int, array<string, mixed>> $new
     * @return array<int, array<string, mixed>>
     */
    private static function merge_assets(array $old, array $new): array
    {
        $resolved = [];
        foreach ($old as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $ref = (string) ($asset['ref'] ?? '');
            if ($ref !== '' && (int) ($asset['attachment_id'] ?? 0) > 0) {
                $resolved[$ref] = $asset;
            }
        }

        $out = [];
        foreach ($new as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $ref = (string) ($asset['ref'] ?? '');
            if ($ref !== '' && isset($resolved[$ref])) {
                $asset['attachment_id'] = (int) $resolved[$ref]['attachment_id'];
                $asset['url'] = (string) ($resolved[$ref]['url'] ?? '');
                unset($resolved[$ref]);
            }
            $out[] = $asset;
        }

        // An attachment whose reference is gone from the design is still worth
        // remembering: audit reports it rather than losing it silently.
        foreach ($resolved as $asset) {
            $out[] = $asset;
        }

        return array_slice($out, 0, 400);
    }

    /** @return array<string, mixed>|null */
    public static function ndo(string $id): ?array
    {
        $ndo = get_option(nibwp_paper_ndo_option($id), null);

        return is_array($ndo) && isset($ndo['root']) ? $ndo : null;
    }

    /** @return array<string, mixed>|null */
    public static function rename(string $id, string $name): ?array
    {
        $all = self::all();
        if (!isset($all[$id]) || !is_array($all[$id])) {
            return null;
        }
        $all[$id]['name'] = $name;
        $all[$id]['handle'] = self::make_handle($name, $id);
        $all[$id]['updated'] = time();
        update_option(self::OPTION, $all, false);

        return $all[$id];
    }

    public static function delete(string $id): void
    {
        $all = self::all();
        if (!isset($all[$id])) {
            return;
        }

        // The screenshot was sideloaded by us, so it goes with the entry. Images
        // attached with attach_asset do NOT: they are in the Media Library because
        // somebody put them in a page, and deleting a design is not a reason to
        // break that page.
        $screenshot = (int) (((array) $all[$id])['screenshot_id'] ?? 0);
        if ($screenshot > 0) {
            wp_delete_attachment($screenshot, true);
        }

        unset($all[$id]);
        update_option(self::OPTION, $all, false);
        delete_option(nibwp_paper_ndo_option($id));
    }

    /** Remember that a page was built from this design, newest first. */
    public static function record_conversion(string $id, int $post_id, string $builder, string $title): void
    {
        $all = self::all();
        if (!isset($all[$id]) || !is_array($all[$id])) {
            return;
        }

        $rows = (array) ($all[$id]['conversions'] ?? []);
        array_unshift($rows, [
            'post_id' => $post_id,
            'builder' => $builder,
            'title' => $title,
            'at' => time(),
        ]);

        $all[$id]['conversions'] = array_slice($rows, 0, self::MAX_CONVERSIONS);
        update_option(self::OPTION, $all, false);
    }

    /** Point one of a design's image references at the attachment it became. */
    public static function link_asset(string $id, string $ref, int $attachment_id, string $url): void
    {
        $all = self::all();
        if (!isset($all[$id]) || !is_array($all[$id])) {
            return;
        }

        $assets = (array) ($all[$id]['assets'] ?? []);
        $found = false;
        foreach ($assets as $index => $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if ($ref !== '' && (string) ($asset['ref'] ?? '') === $ref) {
                $assets[$index]['attachment_id'] = $attachment_id;
                $assets[$index]['url'] = $url;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $assets[] = ['ref' => $ref, 'source_url' => '', 'alt' => '', 'attachment_id' => $attachment_id, 'url' => $url];
        }

        $all[$id]['assets'] = array_slice($assets, 0, 400);
        update_option(self::OPTION, $all, false);

        // The stored NDO still carries the old "no URL yet" node, so refresh the
        // image it points at — otherwise converting again would ignore the
        // attachment that was just made.
        $ndo = self::ndo($id);
        if ($ndo !== null && $ref !== '') {
            self::retarget_images($ndo['root'], $ref, $url);
            update_option(nibwp_paper_ndo_option($id), $ndo, false);
        }
    }

    /** @param array<string, mixed> $node */
    private static function retarget_images(array &$node, string $ref, string $url): void
    {
        if ((string) ($node['image_ref'] ?? '') === $ref) {
            $node['image_url'] = $url;
            $node['style']['background-image'] = 'url(' . $url . ')';
            $node['style']['background-size'] = 'cover';
            $node['style']['background-position'] = 'center';
            $node['style']['background-repeat'] = 'no-repeat';
        }
        foreach ((array) ($node['children'] ?? []) as $index => $child) {
            if (is_array($child)) {
                self::retarget_images($node['children'][$index], $ref, $url);
            }
        }
    }

}
