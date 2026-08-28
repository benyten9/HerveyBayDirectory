<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-redirects', [
    'label'       => __('SEO Pro — Redirects', 'nibwp'),
    'description' => __('Turn 404s into 301s. Actions: list_404 (recent 404s when a logger is present — SEOPress / Slim SEO / Redirection), suggest (match a 404 path to the most similar existing URL), create (create a redirect via the available redirect backend). create writes and is token-gated.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list_404', 'suggest', 'create']],
            'path'   => ['type' => 'string', 'description' => 'For suggest: the 404 request path (e.g. /old-page/).'],
            'items'  => ['type' => 'array', 'description' => 'For create: [{ source, target, type }] (type 301|302|307).', 'items' => ['type' => 'object']],
            'limit'  => ['type' => 'integer', 'default' => 50],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_redirects_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, true, 'list_404 + suggest are read-only. create needs a redirect backend (SEOPress Pro, Slim SEO, Redirection, or the NIBWP seo-redirect-manager) and a preflight token.'),
]);

function nibwp_seo_pro_redirects_execute(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');
    $g = nibwp_seo_pro_guard($input, $action === 'create');
    if (is_wp_error($g)) {
        return $g;
    }
    global $wpdb;

    if ($action === 'list_404') {
        $limit = min(max((int) ($input['limit'] ?? 50), 1), 200);
        // Find any table whose name references 404 logging.
        $tables = $wpdb->get_col("SHOW TABLES LIKE '%404%'");
        if (!$tables) {
            return ['source' => 'none', 'rows' => [], 'note' => 'No 404-log table found. Enable 404 monitoring in SEOPress, Slim SEO Redirects, or the Redirection plugin, then retry.'];
        }
        $table = $tables[0];
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY 1 DESC LIMIT {$limit}", ARRAY_A);
        return ['source' => $table, 'rows' => $rows ?: [], 'count' => count($rows ?: [])];
    }

    if ($action === 'suggest') {
        $path = (string) ($input['path'] ?? '');
        if ($path === '') {
            return nibwp_seo_pro_err('no_path', 'Provide a 404 "path".');
        }
        $slug = sanitize_title(basename(rtrim(parse_url($path, PHP_URL_PATH) ?: $path, '/')));
        $matches = [];
        if ($slug !== '') {
            // Exact slug.
            $exact = get_posts(['name' => $slug, 'post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
            foreach ($exact as $pid) {
                $matches[] = ['post_id' => (int) $pid, 'title' => get_the_title((int) $pid), 'url' => get_permalink((int) $pid), 'confidence' => 'exact'];
            }
            // Fuzzy: search by the slug words.
            if (!$matches) {
                $words = str_replace('-', ' ', $slug);
                $fuzzy = get_posts(['s' => $words, 'post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 5, 'fields' => 'ids']);
                foreach ($fuzzy as $pid) {
                    $matches[] = ['post_id' => (int) $pid, 'title' => get_the_title((int) $pid), 'url' => get_permalink((int) $pid), 'confidence' => 'fuzzy'];
                }
            }
        }
        return ['path' => $path, 'slug' => $slug, 'suggestions' => $matches];
    }

    // create
    $items = (array) ($input['items'] ?? []);
    if ($items === []) {
        return nibwp_seo_pro_err('no_items', 'Provide [{ source, target, type }] items.');
    }
    $backend = nibwp_seo_pro_redirect_backend();
    if ($backend === null) {
        return nibwp_seo_pro_err('no_backend', 'No redirect backend available. Install/activate SEOPress Pro, Slim SEO Redirects, the Redirection plugin, or the NIBWP SEO redirect manager.', 409);
    }
    $created = [];
    foreach ($items as $it) {
        $it = (array) $it;
        $source = (string) ($it['source'] ?? '');
        $target = (string) ($it['target'] ?? '');
        $type   = in_array((string) ($it['type'] ?? '301'), ['301', '302', '307'], true) ? (string) $it['type'] : '301';
        if ($source === '' || $target === '') {
            $created[] = ['source' => $source, 'error' => 'need source + target']; continue;
        }
        $res = $backend($source, $target, $type);
        $created[] = is_wp_error($res) ? ['source' => $source, 'error' => $res->get_error_message()] : ['source' => $source, 'target' => $target, 'type' => $type, 'created' => true];
    }
    nibwp_seo_pro_clear_token($g['token']);
    return ['backend' => 'ok', 'results' => $created];
}

/**
 * Resolve a redirect-creation backend. Returns a callable(source,target,type)
 * or null when nothing can create redirects. Prefers the existing NIBWP
 * seo-redirect-manager ability (it ships its own front-end handler).
 *
 * @return callable|null
 */
function nibwp_seo_pro_redirect_backend(): ?callable
{
    if (nibwp_has_ability('nibwp/seo-redirect-manager')) {
        $ability = wp_get_ability('nibwp/seo-redirect-manager');
        if (is_object($ability) && method_exists($ability, 'execute')) {
            return static function (string $source, string $target, string $type) use ($ability) {
                return $ability->execute([
                    'action'        => 'create',
                    'redirect_data' => ['source' => $source, 'target' => $target, 'type' => $type],
                ]);
            };
        }
    }
    // SEOPress Pro stores redirects as the seopress_404 CPT.
    if (post_type_exists('seopress_404')) {
        return static function (string $source, string $target, string $type) {
            $id = wp_insert_post(['post_type' => 'seopress_404', 'post_status' => 'publish', 'post_title' => $source], true);
            if (is_wp_error($id)) { return $id; }
            update_post_meta((int) $id, '_seopress_redirections_enabled', 'yes');
            update_post_meta((int) $id, '_seopress_redirections_value', esc_url_raw($target));
            update_post_meta((int) $id, '_seopress_redirections_type', $type);
            return true;
        };
    }
    return null;
}
