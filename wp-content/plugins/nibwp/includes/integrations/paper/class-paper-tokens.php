<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Paper_Tokens — a design's colours and type scale, and the narrow path from
 * there into whatever token system this site actually runs.
 *
 * Extraction follows the Figma tokens class: frequency-rank the colours actually
 * painted, collapse the text sizes into a ramp, hand back a named map plus a
 * ready :root{} block. Paper is CSS-native so the values arrive as CSS already,
 * which is the only difference.
 *
 * Writing is deliberately the smaller half. The site's token layer is somebody's
 * configured brand, and a sync that renamed or overwrote it because a design used
 * a different red would be a bug with a very long tail. So:
 *
 *   - ACSS is written through ACSS's own updater, never by reaching into its
 *     option, and only for keys the design names in ACSS's vocabulary. A design
 *     colour with no ACSS counterpart is reported as unmapped, not guessed at.
 *   - Global Styles gets palette entries and font sizes that are MISSING. Nothing
 *     existing is changed and nothing is removed.
 *   - No token system at all → a refusal that says so, rather than a new place to
 *     put tokens that nobody asked for.
 */
class NIBWP_Paper_Tokens
{
    /** @var array<string, int> colour → how often the design paints it */
    private array $colors = [];

    /** @var array<string, array<string, mixed>> signature → text style */
    private array $texts = [];

    /** Colour-bearing declarations worth ranking. */
    private const COLOR_PROPS = ['color', 'background', 'background-color', 'border-color', 'fill', 'stroke', 'outline-color'];

    /**
     * Rank what a canonical Paper tree actually paints.
     *
     * @param array<string, mixed> $tree
     * @return array{source: string, colors: array<string, string>, text: array<string, array<string, mixed>>, css: string}
     */
    public function extract(array $tree): array
    {
        $this->walk($tree);

        arsort($this->colors);
        $palette = [];
        $index = 0;
        foreach (array_keys($this->colors) as $value) {
            $name = match ($index) {
                0 => '--color-primary',
                1 => '--color-secondary',
                2 => '--color-accent',
                default => '--color-' . ($index + 1),
            };
            $palette[$name] = $value;
            if (++$index >= 8) {
                break;
            }
        }

        // One entry per RENDERED size: styles are collected by exact value, so
        // 14.6px and 15.2px are two signatures that both round to 15px, and
        // without collapsing them two ramp slots come out identical.
        $by_px = [];
        foreach ($this->texts as $text) {
            $px = (int) round((float) ($text['size'] ?? 16));
            if ($px <= 0) {
                continue;
            }
            if (!isset($by_px[$px])) {
                $by_px[$px] = ['size' => $px, 'weight' => (int) ($text['weight'] ?? 400), 'family' => (string) ($text['family'] ?? ''), 'count' => 0];
            }
            if ((int) ($text['count'] ?? 0) > (int) $by_px[$px]['count']) {
                $by_px[$px]['weight'] = (int) ($text['weight'] ?? 400);
                $by_px[$px]['family'] = (string) ($text['family'] ?? '');
            }
            $by_px[$px]['count'] += (int) ($text['count'] ?? 0);
        }

        krsort($by_px);
        $ramp = [];
        $slots = ['--text-display', '--text-h1', '--text-h2', '--text-h3', '--text-body', '--text-small'];
        $index = 0;
        foreach ($by_px as $text) {
            $slot = $slots[$index] ?? ('--text-' . ($index + 1));
            $ramp[$slot] = [
                'size' => $text['size'] . 'px',
                'weight' => (int) $text['weight'],
                'family' => (string) $text['family'],
            ];
            if (++$index >= count($slots)) {
                break;
            }
        }

        return [
            'source' => 'paper',
            'colors' => $palette,
            'text' => $ramp,
            'css' => self::css($palette, $ramp),
        ];
    }

    /** @param array<string, mixed> $node */
    private function walk(array $node): void
    {
        $css = (array) ($node['css'] ?? []);

        foreach (self::COLOR_PROPS as $prop) {
            $value = isset($css[$prop]) && is_scalar($css[$prop]) ? strtolower(trim((string) $css[$prop])) : '';
            if ($value === '' || !self::looks_like_color($value)) {
                continue;
            }
            $this->colors[$value] = ($this->colors[$value] ?? 0) + 1;
        }

        $text = (string) ($node['text'] ?? '');
        if ($text !== '') {
            $size = (float) preg_replace('/[^0-9.]/', '', (string) ($css['font-size'] ?? ''));
            if ($size > 0) {
                $weight = isset($css['font-weight']) && is_numeric($css['font-weight']) ? (int) $css['font-weight'] : 400;
                $family = isset($css['font-family']) ? (string) $css['font-family'] : '';
                $signature = $size . '|' . $weight . '|' . $family;
                if (!isset($this->texts[$signature])) {
                    $this->texts[$signature] = ['size' => $size, 'weight' => $weight, 'family' => $family, 'count' => 0];
                }
                $this->texts[$signature]['count'] = (int) $this->texts[$signature]['count'] + 1;
            }
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->walk($child);
            }
        }
    }

    /** A value that is a colour rather than a keyword like "transparent" or a gradient. */
    public static function looks_like_color(string $value): bool
    {
        if ($value === 'transparent' || $value === 'inherit' || $value === 'currentcolor' || $value === 'none') {
            return false;
        }
        if (str_contains($value, 'gradient(')) {
            return false;
        }

        return preg_match('/^(#[0-9a-f]{3,8}|(?:rgb|hsl|oklch|lab|color)a?\()/i', $value) === 1
            || preg_match('/^[a-z]{3,20}$/', $value) === 1;
    }

    /**
     * The :root{} block, so a consumer that only wants CSS does not rebuild it.
     *
     * @param array<string, string> $palette
     * @param array<string, array<string, mixed>> $ramp
     */
    public static function css(array $palette, array $ramp): string
    {
        $lines = [':root {'];
        foreach ($palette as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $lines[] = '  ' . (string) $name . ': ' . (string) $value . ';';
        }
        foreach ($ramp as $slot => $spec) {
            $size = is_array($spec) ? (string) ($spec['size'] ?? '') : (is_scalar($spec) ? (string) $spec : '');
            if ($size === '') {
                continue;
            }
            $lines[] = '  ' . (string) $slot . ': ' . $size . ';';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /* ─────────────────────────── the site's side ─────────────────────────── */

    /**
     * Which token systems this site has, and whether we can write to them.
     *
     * Detection goes through the shared design-system reader rather than probing
     * option names again: ACSS has stored its settings under three different names
     * across versions and every place that hard-coded one has been a bug.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function targets(): array
    {
        $detected = function_exists('nibwp_design_system_detect') ? nibwp_design_system_detect() : null;
        $candidates = is_array($detected) ? (array) ($detected['candidates'] ?? []) : [];

        $acss_present = in_array('acss', $candidates, true)
            || (function_exists('nibwp_acss_is_active') && nibwp_acss_is_active());
        $acss_writable = $acss_present
            && function_exists('nibwp_acss_update_variables')
            && function_exists('nibwp_acss_read_settings')
            && nibwp_acss_read_settings() !== [];

        $global_present = class_exists('WP_Theme_JSON_Resolver');
        $global_writable = $global_present
            && method_exists('WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id');

        return [
            [
                'target' => 'acss',
                'label' => 'Automatic.css',
                'present' => $acss_present,
                'configured' => is_array($detected) && ($detected['system'] ?? '') === 'acss' && (bool) ($detected['configured'] ?? false),
                'writable' => $acss_writable,
                'writes' => __('Only variables the design names in this system\'s own vocabulary. Anything else is reported, not guessed.', 'nibwp'),
            ],
            [
                'target' => 'global_styles',
                'label' => 'Global Styles / theme.json',
                'present' => $global_present,
                'configured' => is_array($detected) && ($detected['system'] ?? '') === 'theme-json' && (bool) ($detected['configured'] ?? false),
                'writable' => $global_writable,
                'writes' => __('Palette entries and font sizes that are missing. Nothing existing is changed or removed.', 'nibwp'),
            ],
        ];
    }

    /**
     * Every colour this site already defines, lowercased, for the audit.
     *
     * @return array<int, string>
     */
    public static function site_colors(): array
    {
        if (!function_exists('nibwp_design_system_detect')) {
            return [];
        }

        $detected = nibwp_design_system_detect();
        $colors = (array) (((array) ($detected['tokens'] ?? []))['colors'] ?? []);

        $out = [];
        foreach ($colors as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Push a design's tokens into the site's token layer.
     *
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>|WP_Error
     */
    public static function sync(array $tokens, string $target, bool $dry_run, string $design_id)
    {
        $colors = (array) ($tokens['colors'] ?? []);
        $text = (array) ($tokens['text'] ?? []);

        if ($colors === [] && $text === []) {
            return nibwp_paper_err(
                'no_tokens',
                __('That design carries no colours or type scale to sync. Ingest it with its tokens, or pass tokens explicitly.', 'nibwp'),
                422
            );
        }

        $targets = self::targets();
        $available = [];
        foreach ($targets as $row) {
            if ((bool) $row['writable']) {
                $available[(string) $row['target']] = $row;
            }
        }

        $target = strtolower(trim($target));
        if ($target === 'theme_json' || $target === 'theme-json') {
            // Two names for one place: what a caller means by theme.json is the
            // resolved Global Styles, which is what we can actually write.
            $target = 'global_styles';
        }

        if ($available === []) {
            return nibwp_paper_err(
                'no_token_target',
                __('This site has no token system we can write to — no configured Automatic.css and no writable Global Styles. The design\'s tokens are in list_tokens as CSS; paste them wherever this site keeps its variables.', 'nibwp'),
                409
            );
        }

        if ($target === '') {
            $target = isset($available['acss']) ? 'acss' : (string) array_key_first($available);
        }

        if (!isset($available[$target])) {
            return nibwp_paper_err(
                'unwritable_target',
                sprintf(
                    /* translators: 1: the requested target, 2: the targets that are writable */
                    __('"%1$s" is not a token target this site can write to. Writable here: %2$s.', 'nibwp'),
                    $target,
                    implode(', ', array_keys($available))
                ),
                409
            );
        }

        return $target === 'acss'
            ? self::sync_acss($colors, $text, $dry_run, $design_id)
            : self::sync_global_styles($colors, $text, $dry_run, $design_id);
    }

    /**
     * ACSS, through ACSS's own updater.
     *
     * The mapping is conservative on purpose: a token called --primary is clearly
     * the site's primary, and a token called --color-4 is clearly not anything in
     * particular. Guessing the second kind onto a configured brand colour would
     * silently repaint the site.
     *
     * @param array<string, string> $colors
     * @param array<string, mixed>  $text
     * @return array<string, mixed>|WP_Error
     */
    private static function sync_acss(array $colors, array $text, bool $dry_run, string $design_id)
    {
        $vocabulary = ['primary', 'secondary', 'accent', 'base', 'shade', 'success', 'warning', 'danger', 'info'];

        $variables = [];
        $unmapped = [];
        foreach ($colors as $name => $value) {
            $slug = strtolower(trim((string) $name, '-'));
            $slug = (string) preg_replace('/^color-/', '', $slug);
            if (in_array($slug, $vocabulary, true) && is_scalar($value)) {
                $variables[$slug] = (string) $value;
                continue;
            }
            $unmapped[] = ['token' => (string) $name, 'value' => is_scalar($value) ? (string) $value : '', 'reason' => __('No variable of that name in this system.', 'nibwp')];
        }

        foreach ($text as $slot => $spec) {
            $slug = strtolower(trim((string) $slot, '-'));
            $size = is_array($spec) ? (string) ($spec['size'] ?? '') : (is_scalar($spec) ? (string) $spec : '');
            if ($size === '') {
                continue;
            }
            // The scale is authored as fluid min/max pairs; a design value is the
            // size it was drawn at, so it belongs on the max.
            if (preg_match('/^text-(xs|s|m|l|xl|xxl|xxxl)$/', $slug) === 1) {
                $variables[$slug . '-max'] = $size;
                continue;
            }
            $unmapped[] = ['token' => (string) $slot, 'value' => $size, 'reason' => __('Not a step in this system\'s type scale.', 'nibwp')];
        }

        if ($variables === []) {
            return nibwp_paper_err(
                'nothing_mapped',
                __('None of the design\'s token names match this site\'s variable vocabulary, so nothing was written. Rename them in Paper to primary / secondary / accent, or sync into Global Styles instead.', 'nibwp'),
                422
            );
        }

        $result = nibwp_acss_update_variables(['variables' => $variables, 'dry_run' => $dry_run]);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return [
            'ok' => true,
            'target' => 'acss',
            'dry_run' => $dry_run,
            'design_id' => $design_id,
            'written' => $dry_run ? [] : array_keys($variables),
            'changes' => array_slice((array) ($result['changes'] ?? []), 0, NIBWP_PAPER_MAX_ROWS),
            'unmapped' => array_slice($unmapped, 0, NIBWP_PAPER_MAX_ROWS),
        ];
    }

    /**
     * Global Styles: add what is missing, change nothing that exists.
     *
     * Through the user Global Styles post, which is where the editor itself keeps
     * changes — writing theme.json on disk would be editing the theme, and an
     * update would then throw it away.
     *
     * @param array<string, string> $colors
     * @param array<string, mixed>  $text
     * @return array<string, mixed>|WP_Error
     */
    private static function sync_global_styles(array $colors, array $text, bool $dry_run, string $design_id)
    {
        if (!class_exists('WP_Theme_JSON_Resolver') || !method_exists('WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id')) {
            return nibwp_paper_err('no_token_target', __('This WordPress version exposes no writable Global Styles.', 'nibwp'), 409);
        }

        $post_id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if ($post_id <= 0) {
            return nibwp_paper_err('no_token_target', __('This site has no Global Styles record to write to yet. Open the Site Editor once and save, then try again.', 'nibwp'), 409);
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return nibwp_paper_err('no_token_target', __('The Global Styles record could not be read.', 'nibwp'), 409);
        }

        $data = json_decode((string) $post->post_content, true);
        if (!is_array($data)) {
            $data = ['version' => 2];
        }

        $palette = (array) ($data['settings']['color']['palette'] ?? []);
        $existing_slugs = [];
        foreach ($palette as $entry) {
            if (is_array($entry) && isset($entry['slug'])) {
                $existing_slugs[strtolower((string) $entry['slug'])] = true;
            }
        }

        $sizes = (array) ($data['settings']['typography']['fontSizes'] ?? []);
        $existing_sizes = [];
        foreach ($sizes as $entry) {
            if (is_array($entry) && isset($entry['slug'])) {
                $existing_sizes[strtolower((string) $entry['slug'])] = true;
            }
        }

        $added_colors = [];
        $skipped = [];
        foreach ($colors as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $slug = sanitize_title((string) preg_replace('/^--/', '', (string) $name));
            if ($slug === '') {
                continue;
            }
            if (isset($existing_slugs[$slug])) {
                $skipped[] = ['slug' => $slug, 'reason' => __('Already defined on this site; left alone.', 'nibwp')];
                continue;
            }
            $palette[] = ['slug' => $slug, 'color' => (string) $value, 'name' => (string) $name];
            $existing_slugs[$slug] = true;
            $added_colors[] = ['slug' => $slug, 'color' => (string) $value];
        }

        $added_sizes = [];
        foreach ($text as $slot => $spec) {
            $size = is_array($spec) ? (string) ($spec['size'] ?? '') : (is_scalar($spec) ? (string) $spec : '');
            if ($size === '') {
                continue;
            }
            $slug = sanitize_title((string) preg_replace('/^--/', '', (string) $slot));
            if ($slug === '' || isset($existing_sizes[$slug])) {
                if ($slug !== '') {
                    $skipped[] = ['slug' => $slug, 'reason' => __('Already defined on this site; left alone.', 'nibwp')];
                }
                continue;
            }
            $sizes[] = ['slug' => $slug, 'size' => $size, 'name' => (string) $slot];
            $existing_sizes[$slug] = true;
            $added_sizes[] = ['slug' => $slug, 'size' => $size];
        }

        if ($added_colors === [] && $added_sizes === []) {
            return [
                'ok' => true,
                'target' => 'global_styles',
                'dry_run' => $dry_run,
                'design_id' => $design_id,
                'written' => [],
                'added_colors' => [],
                'added_font_sizes' => [],
                'skipped' => array_slice($skipped, 0, NIBWP_PAPER_MAX_ROWS),
                'note' => __('Every token in that design already exists here, so nothing was written.', 'nibwp'),
            ];
        }

        if (!$dry_run) {
            $data['settings']['color']['palette'] = $palette;
            $data['settings']['typography']['fontSizes'] = $sizes;
            if (!isset($data['version'])) {
                $data['version'] = 2;
            }

            $encoded = wp_json_encode($data);
            if (!is_string($encoded)) {
                return nibwp_paper_err('encode_failed', __('The updated Global Styles could not be encoded, so nothing was written.', 'nibwp'), 500);
            }

            // wp_update_post() unslashes, and this content is JSON full of quotes
            // and escapes — slashed once here, with no filtering of our own.
            $updated = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($encoded)], true);
            if (is_wp_error($updated)) {
                return $updated;
            }
        }

        return [
            'ok' => true,
            'target' => 'global_styles',
            'dry_run' => $dry_run,
            'design_id' => $design_id,
            'post_id' => $post_id,
            'written' => $dry_run ? [] : array_merge(array_column($added_colors, 'slug'), array_column($added_sizes, 'slug')),
            'added_colors' => array_slice($added_colors, 0, NIBWP_PAPER_MAX_ROWS),
            'added_font_sizes' => array_slice($added_sizes, 0, NIBWP_PAPER_MAX_ROWS),
            'skipped' => array_slice($skipped, 0, NIBWP_PAPER_MAX_ROWS),
        ];
    }
}
