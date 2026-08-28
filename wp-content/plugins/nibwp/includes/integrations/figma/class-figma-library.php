<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Figma_Library — a small local cache of PULLED Figma frames/elements.
 *
 * The core idea: NibWP pulls a design (image + CSS tokens + neutral structure) and
 * stores it here. It does NOT convert. Conversion happens later, on demand, when a
 * workflow or builder asks — driven by the user + AI. So the user can "grab this
 * frame", talk to NibWP about it, and only build when they decide.
 *
 * Backed by a single autoload-off option. Fine for a personal design library;
 * ponytail: move to a CPT if libraries grow into the hundreds.
 */
class NIBWP_Figma_Library
{
    private const OPTION = 'nibwp_figma_library';

    /** @return array<string,array<string,mixed>> id → entry */
    public static function all(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }

    public static function get(string $id): ?array
    {
        $all = self::all();
        return isset($all[$id]) && is_array($all[$id]) ? $all[$id] : null;
    }

    /** Stable id for a file_key + node so re-pulling updates in place. */
    public static function id(string $file_key, string $node_id): string
    {
        return substr(md5($file_key . '|' . $node_id), 0, 16);
    }

    /**
     * Human handle used to call a frame by name from NibWP / an AI agent —
     * e.g. "hero-section". Derived from the label, kept unique across the
     * library so `figma-get { handle: "hero-section" }` is unambiguous.
     */
    public static function make_handle(string $name, string $id): string
    {
        $base = sanitize_title($name);
        if ($base === '') {
            $base = 'frame';
        }
        $handle = $base;
        $n = 2;
        // Handles taken by OTHER frames (re-pulling the same frame keeps its own).
        $taken = [];
        foreach (self::all() as $entry) {
            if (($entry['id'] ?? '') !== $id && ($entry['handle'] ?? '') !== '') {
                $taken[(string) $entry['handle']] = true;
            }
        }
        while (isset($taken[$handle])) {
            $handle = $base . '-' . $n++;
        }
        return $handle;
    }

    /** Look an entry up by its human handle. */
    public static function by_handle(string $handle): ?array
    {
        $handle = sanitize_title($handle);
        foreach (self::all() as $entry) {
            if (($entry['handle'] ?? '') === $handle) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Resolve however a person or an agent refers to a pulled frame.
     *
     * The library advertises handles like `@figma/home`, but by_handle() ran
     * that straight through sanitize_title(), which turns it into "figmahome"
     * and matches nothing — so a frame that had definitely been pulled came
     * back as "did not resolve into anything I can read", and the agent asked
     * for a Figma URL it had no way to know. Accepts the handle with or without
     * the @figma/ prefix, the raw id, or the frame's name.
     */
    public static function resolve(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        // @figma/home · figma/home · @home → home
        $bare = preg_replace('#^@?(?:figma/)?#i', '', $ref) ?? $ref;

        foreach ([$bare, $ref] as $candidate) {
            $hit = self::by_handle($candidate);
            if ($hit !== null) {
                return $hit;
            }
        }

        $all = self::all();
        if (isset($all[$bare])) {
            return $all[$bare];
        }
        if (isset($all[$ref])) {
            return $all[$ref];
        }

        // Fall back to the human name, which is what people usually type.
        $slug = sanitize_title($bare);
        foreach ($all as $entry) {
            if (sanitize_title((string) ($entry['name'] ?? '')) === $slug) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Every way the caller could have referred to a frame, for an error message
     * that tells them what IS available instead of just refusing.
     *
     * @return array<int,string>
     */
    public static function known_handles(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            $h = (string) ($entry['handle'] ?? '');
            if ($h !== '') {
                $out[] = '@figma/' . $h;
            }
        }
        return $out;
    }

    /**
     * Insert/update an entry. Returns its id.
     *
     * @param array<string,mixed> $entry
     */
    public static function save(array $entry): string
    {
        $id = (string) ($entry['id'] ?? self::id((string) ($entry['file_key'] ?? ''), (string) ($entry['node_id'] ?? '')));
        $entry['id'] = $id;
        $all = self::all();
        $all[$id] = $entry;
        update_option(self::OPTION, $all, false);
        return $id;
    }

    public static function delete(string $id): void
    {
        $all = self::all();
        if (isset($all[$id])) {
            // Best-effort: remove the cached image attachment too.
            $att = (int) ($all[$id]['image_id'] ?? 0);
            if ($att > 0) {
                wp_delete_attachment($att, true);
            }
            // The node tree lives in its own option — drop it too.
            if (function_exists('nibwp_figma_ndo_option')) {
                delete_option(nibwp_figma_ndo_option($id));
            }
            unset($all[$id]);
            update_option(self::OPTION, $all, false);
        }
    }
}
