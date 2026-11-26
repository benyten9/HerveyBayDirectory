<?php
/**
 * Static site export: the whole site as HTML, CSS, JS and media files in a
 * zip, to host anywhere or open from a folder.
 *
 * A wizard in Agent View drives it through REST, in small steps so no
 * request runs long:
 *   scan   what there is to export, and what will not work without PHP
 *   start  a job: its folder, its options, the first pages to fetch
 *   step   fetch a few pages as a visitor sees them, copy the files they
 *          use, rewrite their links; follow internal links it finds
 *   zip    pack the folder
 * The zip downloads through admin-post, behind a nonce and the capability.
 *
 * Safety: administrators only; pages are fetched from this site's own
 * address and nowhere else; files are copied from inside the WordPress
 * folder only, by an allow-list of extensions (never PHP); the export
 * folder sits in uploads behind a deny-all rule and a random name, and old
 * exports are removed when a new one starts.
 *
 * @package NibWP
 */

if (!defined('ABSPATH')) {
    exit;
}

const NIBWP_STATIC_MAX_PAGES = 3000;
// Short steps: the window shows progress every few seconds, and no host time limit is reached.
const NIBWP_STATIC_STEP_SECONDS = 4;
// Pages read at once when the server allows it (the system check measures it).
const NIBWP_STATIC_PAR = 4;
const NIBWP_STATIC_EXT = 'css|js|mjs|json|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|ogv|mp3|wav|ogg|m4a|pdf|txt|xml|webmanifest|csv|zip|vtt|glb|gltf';

/** Where exports live: a locked folder in uploads. */
function nibwp_static_base(): string
{
    $up = wp_upload_dir(null, false);
    $base = wp_normalize_path(trailingslashit($up['basedir']) . 'nibwp-static');
    if (!is_dir($base)) {
        wp_mkdir_p($base);
    }
    if (!file_exists($base . '/.htaccess')) {
        file_put_contents($base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a lock file in our own folder
    }
    if (!file_exists($base . '/index.php')) {
        file_put_contents($base . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a lock file in our own folder
    }

    return $base;
}

/**
 * The current job of this person, or null. The job itself is a JSON file in
 * its folder (it grows with every page and file); the person's meta only
 * says which folder.
 */
function nibwp_static_job(): ?array
{
    $p = get_user_meta(get_current_user_id(), 'nibwp_static_job', true);
    if (!is_array($p) || empty($p['id']) || empty($p['dir'])) {
        return null;
    }
    if (isset($p['queue'])) {
        return $p;
    }
    $base = realpath(nibwp_static_base());
    $dir = realpath((string) $p['dir']);
    if (!$base || !$dir || strpos($dir, $base . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    $job = nibwp_static_read_json($dir . '/job.json');

    return !empty($job['id']) && $job['id'] === $p['id'] ? $job : null;
}

function nibwp_static_save_job(array $job): void
{
    nibwp_static_write_json($job['dir'] . '/job.json', $job);
    // Meta values are unslashed on save: slash them so Windows paths survive.
    update_user_meta(get_current_user_id(), 'nibwp_static_job', wp_slash(['id' => $job['id'], 'dir' => $job['dir']]));
}

/** Remove a folder and all it holds, only inside the exports folder. */
function nibwp_static_rmdir(string $dir): void
{
    $base = realpath(nibwp_static_base());
    $real = realpath($dir);
    if (!$base || !$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir()) {
            rmdir($f->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- inside our own export folder
        } else {
            wp_delete_file($f->getPathname());
        }
    }
    rmdir($real); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- inside our own export folder
}

/* ------------------------------------------------------------------
 * What can be exported.
 * ---------------------------------------------------------------- */

/** @return array<int, array{type: string, label: string, count: int}> */
function nibwp_static_types(): array
{
    $out = [];
    foreach (get_post_types(['public' => true], 'objects') as $type) {
        if ($type->name === 'attachment') {
            continue;
        }
        $n = (int) (wp_count_posts($type->name)->publish ?? 0);
        $out[] = ['type' => $type->name, 'label' => (string) $type->labels->name, 'count' => $n];
    }

    return $out;
}

/**
 * Things that need PHP and will not work in a static copy.
 *
 * @return array<int, array{level: string, text: string}>
 */
function nibwp_static_warnings(): array
{
    $w = [];
    $open = (int) get_comments(['count' => true, 'status' => 'approve']);
    if (get_option('default_comment_status') === 'open') {
        $w[] = ['level' => 'warn', 'text' => __('Comments are open: the static copy shows existing comments, but new ones cannot be posted.', 'nibwp')];
    } elseif ($open > 0) {
        $w[] = ['level' => 'info', 'text' => __('Existing comments are kept as they are.', 'nibwp')];
    }
    $forms = array_filter([
        defined('WPCF7_VERSION') ? 'Contact Form 7' : '',
        class_exists('WPForms') ? 'WPForms' : '',
        class_exists('GFForms') ? 'Gravity Forms' : '',
        defined('FLUENTFORM') ? 'Fluent Forms' : '',
        class_exists('\Elementor\Plugin') && defined('ELEMENTOR_PRO_VERSION') ? 'Elementor forms' : '',
    ]);
    if ($forms) {
        /* translators: %s: form plugins, comma separated */
        $w[] = ['level' => 'warn', 'text' => sprintf(__('Forms (%s) will not send: point them to a form service, or keep that page on WordPress.', 'nibwp'), implode(', ', $forms))];
    }
    if (class_exists('WooCommerce')) {
        $w[] = ['level' => 'warn', 'text' => __('WooCommerce: product pages are copied, but cart, checkout and accounts need WordPress.', 'nibwp')];
    }
    if (!get_option('blog_public')) {
        $w[] = ['level' => 'warn', 'text' => __('Search engines are asked not to index this site (Settings › Reading), and the static copy asks the same. Untick it before you publish if the copy should be found.', 'nibwp')];
    }
    $w[] = ['level' => 'info', 'text' => __('Search, logins and anything that changes per visitor need WordPress; the copy shows the page as a visitor first sees it.', 'nibwp')];

    return $w;
}

/**
 * The system check: everything an export or a publish needs from this
 * server, each tested for real, with what to do when one fails.
 *
 * @return array<int, array{key: string, level: string, text: string, fix: string}>
 */
function nibwp_static_system(string $target): array
{
    $out = [];
    $add = static function (string $key, string $level, string $text, string $fix = '') use (&$out): void {
        $out[] = ['key' => $key, 'level' => $level, 'text' => $text, 'fix' => $fix];
    };

    // The site reads its own pages, as a visitor.
    $home = home_url('/');
    $t = microtime(true);
    $r = nibwp_static_fetch([$home], 1)[$home];
    $ms = (int) round((microtime(true) - $t) * 1000);
    $loop = false;
    if ($r['error'] !== '') {
        $add('loopback', 'bad', __('The server cannot open its own pages.', 'nibwp'), stripos($r['error'], 'timed out') !== false || strpos($r['error'], 'cURL error 28') !== false
            ? __('The request timed out. Ask your host to allow the server to reach its own address (a loopback request).', 'nibwp')
            /* translators: %s: the error the server gave */
            : sprintf(__('It said: %s. Ask your host to allow the server to reach its own address (a loopback request).', 'nibwp'), $r['error']));
    } elseif ($r['code'] >= 300 && $r['code'] < 400) {
        $to = $r['location'];
        $same = nibwp_static_norm($to) !== '' && wp_parse_url($to, PHP_URL_SCHEME) === wp_parse_url($home, PHP_URL_SCHEME);
        if ($same) {
            $loop = true;
            $add('loopback', 'ok', __('The site can open its own pages', 'nibwp'));
        } else {
            /* translators: 1: the WordPress address, 2: where the server sends visitors */
            $add('loopback', 'bad', __('The site address does not match where visitors are sent.', 'nibwp'), sprintf(__('WordPress is at %1$s but the server sends visitors to %2$s. Make them the same in Settings › General, then check again.', 'nibwp'), $home, $to));
        }
    } elseif ($r['code'] === 401) {
        $add('loopback', 'bad', __('The site is behind a password (an HTTP login).', 'nibwp'), __('Turn the password off while you export, or ask your host to let the server’s own requests through.', 'nibwp'));
    } elseif (in_array($r['code'], [403, 406, 429, 503], true)) {
        /* translators: %d: HTTP status */
        $add('loopback', 'bad', sprintf(__('A firewall or security plugin blocked the request (it answered %d).', 'nibwp'), $r['code']), __('Allow requests from the server itself (its own IP address) in the security plugin or the host’s firewall, then check again.', 'nibwp'));
    } elseif ($r['code'] !== 200) {
        /* translators: %d: HTTP status */
        $add('loopback', 'bad', sprintf(__('The home page answered with error %d.', 'nibwp'), $r['code']), __('Open the home page as a visitor and fix what it shows, then check again.', 'nibwp'));
    } elseif ($r['type'] !== '' && stripos($r['type'], 'html') === false) {
        $add('loopback', 'bad', __('The home page is not a web page.', 'nibwp'), __('Something answers the home page with another kind of file. Check the home page as a visitor.', 'nibwp'));
    } else {
        $loop = true;
        if ($ms > 4000) {
            /* translators: %s: seconds */
            $add('loopback', 'warn', sprintf(__('Pages are slow to make: the home page took %s seconds.', 'nibwp'), number_format_i18n($ms / 1000, 1)), __('The export works, only slower. A page cache plugin makes it much faster.', 'nibwp'));
        } else {
            /* translators: %s: milliseconds */
            $add('loopback', 'ok', sprintf(__('The site can open its own pages (%s ms)', 'nibwp'), number_format_i18n($ms)));
        }
    }

    // Several pages at once: much faster when the server has the room.
    $par = 1;
    if ($loop) {
        $urls = [$home, add_query_arg('nibwp-check', '2', $home), add_query_arg('nibwp-check', '3', $home)];
        $t = microtime(true);
        $many = nibwp_static_fetch($urls, 3);
        $took = microtime(true) - $t;
        $good = count(array_filter($many, static fn($x) => $x['error'] === '' && $x['code'] > 0 && $x['code'] < 400));
        if ($good === 3 && $took < max(1.5, 2.4 * $ms / 1000)) {
            $par = NIBWP_STATIC_PAR;
            $add('parallel', 'ok', __('Several pages can be read at once, so it goes faster', 'nibwp'));
        } else {
            $add('parallel', 'ok', __('Pages are read one at a time on this server', 'nibwp'));
        }
    }
    update_option('nibwp_static_par', $par, false);

    // The export folder takes files.
    $base = nibwp_static_base();
    $probe = $base . '/.check-' . wp_generate_password(8, false);
    $wrote = @file_put_contents($probe, 'ok') === 2; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test file in our own folder
    if ($wrote) {
        wp_delete_file($probe);
        $add('write', 'ok', __('Files can be saved on the server', 'nibwp'));
    } else {
        /* translators: %s: a folder */
        $add('write', 'bad', __('Files cannot be saved on the server.', 'nibwp'), sprintf(__('Make %s writable (folders 755), or ask your host.', 'nibwp'), str_replace(wp_normalize_path(ABSPATH), '', $base)));
    }

    // Room on the disk, when the host tells.
    $free = function_exists('disk_free_space') ? @disk_free_space($base) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hosts may disable it
    if (is_numeric($free)) {
        if ($free < 100 * MB_IN_BYTES) {
            /* translators: %s: free space, like 80 MB */
            $add('space', 'bad', sprintf(__('Only %s free on the server.', 'nibwp'), size_format((int) $free)), __('Free some space (old backups often take the most), then check again.', 'nibwp'));
        } elseif ($free < GB_IN_BYTES) {
            /* translators: %s: free space, like 600 MB */
            $add('space', 'warn', sprintf(__('Only %s free on the server.', 'nibwp'), size_format((int) $free)), __('Enough for most sites. A big media library may need more.', 'nibwp'));
        } else {
            /* translators: %s: free space, like 12 GB */
            $add('space', 'ok', sprintf(__('%s free on the server', 'nibwp'), size_format((int) $free)));
        }
    }

    if ($target === 'zip') {
        if (class_exists('ZipArchive')) {
            $add('zip', 'ok', __('A zip can be made', 'nibwp'));
        } elseif (file_exists(ABSPATH . 'wp-admin/includes/class-pclzip.php')) {
            $add('zip', 'warn', __('PHP Zip is missing: a slower zip maker is used.', 'nibwp'), __('Big sites may stop while packing. Ask your host to turn on the PHP zip extension.', 'nibwp'));
        } else {
            $add('zip', 'bad', __('No zip can be made on this server.', 'nibwp'), __('Ask your host to turn on the PHP zip extension.', 'nibwp'));
        }
    }

    $mem = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
    if ($mem > 0 && $mem < 128 * MB_IN_BYTES) {
        /* translators: %s: memory, like 64 MB */
        $add('memory', 'warn', sprintf(__('PHP has %s of memory.', 'nibwp'), size_format($mem)), __('Big pages may not fit. 256 MB is safer: ask your host, or set WP_MEMORY_LIMIT.', 'nibwp'));
    } else {
        $add('memory', 'ok', $mem > 0
            /* translators: %s: memory, like 256 MB */
            ? sprintf(__('PHP has %s of memory', 'nibwp'), size_format($mem))
            : __('PHP has no memory limit', 'nibwp'));
    }
    $add('time', 'ok', __('Works in short steps, so server time limits are not a problem', 'nibwp'));

    if ((string) get_option('permalink_structure') === '') {
        $add('permalinks', 'bad', __('Plain permalinks (?p=123) cannot become files.', 'nibwp'), __('Choose a permalink structure in Settings › Permalinks, then check again.', 'nibwp'));
    } else {
        $add('permalinks', 'ok', __('Pretty permalinks are on', 'nibwp'));
    }

    return $out;
}

/**
 * Fetch pages of this site as a visitor: several at once when $par > 1.
 *
 * @return array<string, array{code: int, type: string, location: string, body: string, error: string}>
 */
function nibwp_static_fetch(array $urls, int $par): array
{
    $out = [];
    $headers = ['X-NibWP-Static' => '1'];
    $agent = 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/');
    if ($par > 1 && count($urls) > 1 && class_exists('\WpOrg\Requests\Requests')) {
        $reqs = [];
        foreach (array_values($urls) as $i => $u) {
            $reqs[$i] = ['url' => $u, 'headers' => $headers, 'type' => 'GET'];
        }
        try {
            $res = \WpOrg\Requests\Requests::request_multiple($reqs, ['timeout' => 25, 'connect_timeout' => 10, 'follow_redirects' => false, 'verify' => false, 'useragent' => $agent]);
        } catch (Throwable $e) {
            $res = [];
        }
        foreach (array_values($urls) as $i => $u) {
            $r = $res[$i] ?? null;
            if ($r instanceof \WpOrg\Requests\Response) {
                $out[$u] = ['code' => (int) $r->status_code, 'type' => (string) ($r->headers['content-type'] ?? ''), 'location' => (string) ($r->headers['location'] ?? ''), 'body' => (string) $r->body, 'error' => ''];
            } else {
                $out[$u] = ['code' => 0, 'type' => '', 'location' => '', 'body' => '', 'error' => $r instanceof Throwable ? $r->getMessage() : __('No answer.', 'nibwp')];
            }
        }

        return $out;
    }
    foreach ($urls as $u) {
        $r = wp_remote_get($u, ['timeout' => 25, 'redirection' => 0, 'sslverify' => false, 'headers' => $headers]);
        $out[$u] = is_wp_error($r)
            ? ['code' => 0, 'type' => '', 'location' => '', 'body' => '', 'error' => $r->get_error_message()]
            : ['code' => (int) wp_remote_retrieve_response_code($r), 'type' => (string) wp_remote_retrieve_header($r, 'content-type'), 'location' => (string) wp_remote_retrieve_header($r, 'location'), 'body' => (string) wp_remote_retrieve_body($r), 'error' => ''];
    }

    return $out;
}

/* ------------------------------------------------------------------
 * REST.
 * ---------------------------------------------------------------- */

add_action('rest_api_init', static function (): void {
    $can = static fn(): bool => current_user_can('manage_options');
    register_rest_route('nibwp/v1', '/studio/export/scan', ['methods' => 'GET', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_scan']);
    register_rest_route('nibwp/v1', '/studio/export/check', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => static function (WP_REST_Request $r) {
        return new WP_REST_Response(['checks' => nibwp_static_system($r->get_param('target') === 'zip' ? 'zip' : 'host')]);
    }]);
    register_rest_route('nibwp/v1', '/studio/export/start', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_start']);
    register_rest_route('nibwp/v1', '/studio/export/step', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_step']);
    register_rest_route('nibwp/v1', '/studio/export/zip', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_zip']);
    register_rest_route('nibwp/v1', '/studio/export/cancel', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_cancel']);
});

function nibwp_static_rest_scan(): WP_REST_Response
{
    $taxes = [];
    foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
        if ($tax->name === 'post_format') {
            continue;
        }
        $n = wp_count_terms(['taxonomy' => $tax->name, 'hide_empty' => true]);
        $n = is_wp_error($n) ? 0 : (int) $n;
        if ($n > 0) {
            $taxes[] = ['tax' => $tax->name, 'label' => (string) $tax->labels->name, 'count' => $n];
        }
    }
    return new WP_REST_Response([
        'types' => nibwp_static_types(),
        'taxonomies' => $taxes,
        'home' => home_url('/'),
        'warnings' => nibwp_static_warnings(),
    ]);
}

/** Options from the wizard, checked. */
function nibwp_static_options(WP_REST_Request $request): array
{
    return nibwp_static_clean_opts($request->get_params());
}

function nibwp_static_clean_opts(array $p): array
{
    $public = array_column(nibwp_static_types(), 'type');
    $types = array_values(array_intersect($public, array_map('sanitize_key', (array) ($p['types'] ?? []))));
    $taxes = array_values(array_intersect(get_taxonomies(['public' => true]), array_map('sanitize_key', (array) ($p['taxonomies'] ?? []))));
    $mode = ($p['links'] ?? '') === 'absolute' ? 'absolute' : 'relative';
    $base = '';
    if ($mode === 'absolute') {
        $base = untrailingslashit(esc_url_raw((string) ($p['base'] ?? ''), ['http', 'https']));
        if ($base === '' || !wp_http_validate_url($base)) {
            $mode = 'relative';
            $base = '';
        }
    }

    return [
        'types' => $types,
        'taxonomies' => $taxes,
        'archives' => !empty($p['archives']),
        'follow' => !empty($p['follow']),
        'media' => ($p['media'] ?? '') === 'all' ? 'all' : 'used',
        'links' => $mode,
        'base' => $base,
    ];
}

function nibwp_static_rest_start(WP_REST_Request $request): WP_REST_Response
{
    $opts = nibwp_static_options($request);
    if (!$opts['types']) {
        return new WP_REST_Response(['message' => __('Pick at least one kind of content to export.', 'nibwp')], 400);
    }
    // One export at a time per person: the last one goes.
    $old = nibwp_static_job();
    if ($old && !empty($old['dir'])) {
        nibwp_static_rmdir($old['dir']);
        if (!empty($old['zip'])) {
            wp_delete_file($old['zip']);
        }
    }
    // Exports left behind for a day (any person's) go too.
    foreach (glob(nibwp_static_base() . '/*', GLOB_ONLYDIR) ?: [] as $stale) {
        if (preg_match('/^[a-z0-9]{16}$/', basename($stale)) && filemtime($stale) < time() - DAY_IN_SECONDS) {
            nibwp_static_rmdir($stale);
        }
    }
    $id = strtolower(wp_generate_password(16, false));
    $dir = nibwp_static_base() . '/' . $id;
    if (!wp_mkdir_p($dir . '/site')) {
        return new WP_REST_Response(['message' => __('Could not create the export folder.', 'nibwp')], 500);
    }

    $ids = [];
    $queue = nibwp_static_queue($opts, $ids);

    $job = [
        'id' => $id,
        'dir' => $dir,
        'opts' => $opts,
        'queue' => array_slice($queue, 0, NIBWP_STATIC_MAX_PAGES),
        'seen' => array_fill_keys($queue, 1),
        'pages' => 0,
        'files' => 0,
        'bytes' => 0,
        'errors' => [],
        'log' => [],
        'done' => [],
        'copied' => [],
        'state' => 'crawl',
        'started' => time(),
        'zip' => '',
        'par' => nibwp_static_par(),
    ];
    if ($opts['media'] === 'all') {
        $job['media_all'] = 1;
    }
    nibwp_static_save_job($job);

    return new WP_REST_Response(nibwp_static_status($job));
}

/**
 * Every page to fetch: home, the chosen content, archives, term pages.
 * $ids gets each content page's post ID by its address.
 */
function nibwp_static_queue(array $opts, array &$ids): array
{
    $queue = [home_url('/')];
    foreach ($opts['types'] as $type) {
        $found = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => NIBWP_STATIC_MAX_PAGES, 'fields' => 'ids', 'has_password' => false, 'suppress_filters' => false]);
        foreach ($found as $pid) {
            $url = (string) get_permalink($pid);
            $ids[nibwp_static_norm($url)] = (int) $pid;
            $queue[] = $url;
        }
        if ($opts['archives'] && ($link = get_post_type_archive_link($type))) {
            $queue[] = (string) $link;
        }
    }
    foreach ($opts['taxonomies'] as $tax) {
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => true, 'number' => 500]);
        foreach (is_wp_error($terms) ? [] : $terms as $term) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                $queue[] = (string) $link;
            }
        }
    }
    $posts = (int) get_option('page_for_posts');
    if ($posts) {
        $queue[] = (string) get_permalink($posts);
    }
    unset($ids['']);

    return array_slice(array_values(array_unique(array_filter(array_map('nibwp_static_norm', $queue)))), 0, NIBWP_STATIC_MAX_PAGES);
}

/** Pages read at once, as the system check measured. */
function nibwp_static_par(): int
{
    return max(1, min(NIBWP_STATIC_PAR, (int) get_option('nibwp_static_par', 1)));
}

/** The next pages in line, as paths, for the window to show what is being read. */
function nibwp_static_next(array $queue): array
{
    return array_map(static fn($u) => (string) (wp_parse_url($u, PHP_URL_PATH) ?: '/'), array_slice($queue, 0, 6));
}

/** What the wizard shows. */
function nibwp_static_status(array $job): array
{
    $total = $job['pages'] + count($job['queue']);

    return [
        'state' => $job['state'],
        'pages' => $job['pages'],
        'total' => $total,
        'files' => $job['files'],
        'bytes' => $job['bytes'],
        'errors' => array_slice($job['errors'], -20),
        'log' => array_slice($job['log'], -12),
        'next' => nibwp_static_next($job['queue']),
        'media' => ['left' => count($job['mq'] ?? []), 'total' => (int) ($job['mtotal'] ?? 0)],
        'zipped' => (int) ($job['zat'] ?? 0),
        'ztotal' => (int) ($job['ztotal'] ?? 0),
        'started' => (int) $job['started'],
        'now' => time(),
        'download' => $job['state'] === 'done' && $job['zip'] ? add_query_arg(['action' => 'nibwp_static_download', 'id' => $job['id'], '_wpnonce' => wp_create_nonce('nibwp_static_download_' . $job['id'])], admin_url('admin-post.php')) : '',
        'size' => $job['state'] === 'done' && $job['zip'] && file_exists($job['zip']) ? size_format((int) filesize($job['zip']), 1) : '',
    ];
}

function nibwp_static_rest_step(): WP_REST_Response
{
    $job = nibwp_static_job();
    if (!$job) {
        return new WP_REST_Response(['message' => __('No export is running.', 'nibwp')], 404);
    }
    if ($job['state'] !== 'crawl' && $job['state'] !== 'finish') {
        return new WP_REST_Response(nibwp_static_status($job));
    }
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }
    // The first step is short, so the window shows the first pages at once.
    $until = microtime(true) + ($job['pages'] ? NIBWP_STATIC_STEP_SECONDS : 1);
    while (microtime(true) < $until) {
        if ($job['state'] === 'crawl') {
            if ($job['queue']) {
                nibwp_static_pages($job);
                continue;
            }
            $job['state'] = 'finish';
        }
        if (nibwp_static_finish($job, $until)) {
            $job['state'] = 'zip';
        }
        break;
    }
    nibwp_static_save_job($job);

    return new WP_REST_Response(nibwp_static_status($job));
}

function nibwp_static_rest_cancel(): WP_REST_Response
{
    $job = nibwp_static_job();
    if ($job) {
        nibwp_static_rmdir($job['dir']);
        delete_user_meta(get_current_user_id(), 'nibwp_static_job');
    }

    return new WP_REST_Response(['cancelled' => true]);
}

/* ------------------------------------------------------------------
 * Pages.
 * ---------------------------------------------------------------- */

/** An address of this site, without query or fragment; '' for anything else. */
function nibwp_static_norm(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES);
    if ($url === '' || $url[0] === '#' || preg_match('#^(mailto|tel|javascript|data):#i', $url)) {
        return '';
    }
    $home = wp_parse_url(home_url('/'));
    if (strpos($url, '//') === 0) {
        $url = ($home['scheme'] ?? 'https') . ':' . $url;
    } elseif ($url[0] === '/') {
        $url = ($home['scheme'] ?? 'https') . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $url;
    }
    $p = wp_parse_url($url);
    if (empty($p['host']) || strcasecmp($p['host'], (string) $home['host']) !== 0) {
        return '';
    }
    $path = $p['path'] ?? '/';
    $homePath = $home['path'] ?? '/';
    if (strpos($path, untrailingslashit($homePath)) !== 0) {
        return '';
    }
    if (preg_match('#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|feed|comments/feed|trackback)(/|$)#', $path) || preg_match('#\.php$#', $path)) {
        return '';
    }

    return ($home['scheme'] ?? 'https') . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $path;
}

/** The file a page goes to, relative to the site folder. */
function nibwp_static_page_file(string $url): string
{
    $home = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/');
    $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
    $rel = ltrim(substr($path, strlen(untrailingslashit($home))), '/');
    if ($rel === '' || substr($rel, -1) === '/') {
        return $rel . 'index.html';
    }
    if (preg_match('#\.(html?|xml|txt)$#', $rel)) {
        return $rel;
    }

    return $rel . '/index.html';
}

/** The next pages in line: read together when the server allows it. */
function nibwp_static_pages(array &$job): void
{
    $par = max(1, (int) ($job['par'] ?? 1));
    $urls = array_splice($job['queue'], 0, $par);
    $got = nibwp_static_fetch($urls, $par);
    // The server could not keep up with several at once: one at a time from now on.
    $lost = count(array_filter($got, static fn($r) => $r['code'] === 0 || $r['code'] === 429 || $r['code'] === 503));
    if ($par > 1 && $lost >= 2) {
        $job['par'] = 1;
    }
    foreach ($got as $url => $r) {
        try {
            nibwp_static_take($job, (string) $url, $r);
        } catch (Throwable $e) {
            $job['errors'][] = $url . ' — ' . $e->getMessage();
        }
    }
}

function nibwp_static_page(array &$job, string $url): void
{
    nibwp_static_take($job, $url, nibwp_static_fetch([$url], 1)[$url]);
}

/** A page fetched: made static and saved; a hiccup is tried once more. */
function nibwp_static_take(array &$job, string $url, array $r): void
{
    $code = $r['code'];
    if ($r['error'] !== '' || $code === 0 || $code === 429 || $code >= 500) {
        if (empty($job['tries'][$url])) {
            $job['tries'][$url] = 1;
            $job['queue'][] = $url;
            return;
        }
        /* translators: 1: address, 2: HTTP status */
        $job['errors'][] = $r['error'] !== '' ? $url . ' — ' . $r['error'] : sprintf(__('%1$s answered %2$d', 'nibwp'), $url, $code);
        return;
    }
    if ($code >= 300 && $code < 400) {
        $to = nibwp_static_norm($r['location']);
        if ($to && empty($job['seen'][$to]) && count($job['seen']) < NIBWP_STATIC_MAX_PAGES) {
            $job['seen'][$to] = 1;
            $job['queue'][] = $to;
        }
        return;
    }
    if ($code !== 200 || ($r['type'] !== '' && stripos($r['type'], 'html') === false)) {
        /* translators: 1: address, 2: HTTP status */
        $job['errors'][] = sprintf(__('%1$s answered %2$d', 'nibwp'), $url, $code);
        return;
    }
    $file = nibwp_static_page_file($url);
    $html = nibwp_static_rewrite_html($job, $r['body'], $file);
    nibwp_static_write($job, $file, $html);
    $job['pages']++;
    $job['done'][] = $url;
    nibwp_static_log($job, '/' . ltrim(substr($file, 0, -10), '/'));
}

/** The latest pages and files done, for the window's live list. */
function nibwp_static_log(array &$job, string $line): void
{
    $job['log'][] = $line;
    if (count($job['log']) > 40) {
        $job['log'] = array_slice($job['log'], -20);
    }
}

/** Write a file in the export, creating its folders. */
function nibwp_static_write(array &$job, string $rel, string $data): void
{
    $rel = ltrim(str_replace(['\\', '..'], ['/', ''], $rel), '/');
    $dest = $job['dir'] . '/site/' . $rel;
    wp_mkdir_p(dirname($dest));
    file_put_contents($dest, $data); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the export inside our locked folder
    $job['bytes'] += strlen($data);
}

/** From one exported file to another, as a relative path. */
function nibwp_static_relpath(string $from, string $to): string
{
    $depth = substr_count(ltrim($from, '/'), '/');

    return str_repeat('../', $depth) . ltrim($to, '/');
}

/**
 * A link in a page (or CSS file at $from) made to work in the export: pages
 * become their index.html, files are copied and pointed at.
 */
function nibwp_static_link(array &$job, string $raw, string $from, bool $page_ok = true): string
{
    $url = html_entity_decode(trim($raw), ENT_QUOTES);
    $hash = '';
    if (($h = strpos($url, '#')) !== false) {
        $hash = substr($url, $h);
        $url = substr($url, 0, $h);
    }
    $norm = nibwp_static_norm($url);
    if ($norm === '') {
        return $raw;
    }
    $path = (string) wp_parse_url($norm, PHP_URL_PATH);
    $isFile = (bool) preg_match('#\.(' . NIBWP_STATIC_EXT . ')$#i', $path);
    if ($isFile) {
        $rel = nibwp_static_copy($job, $norm);
        if ($rel === '') {
            return $raw;
        }
    } else {
        if (!$page_ok) {
            return $raw;
        }
        // Pages: crawl the ones not seen yet, when following links.
        $q = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($q !== '') {
            return $raw;
        }
        $follow = $job['opts']['follow'] ?? false;
        if ($follow === 'paged') {
            // Rebuilding changes: a list's next pages, when the build has them or follows links.
            $follow = preg_match('#/page/\d+/?$#', $path) && (!empty($job['opts']['follow_all']) || is_file($job['dir'] . '/site/' . nibwp_static_page_file($norm)));
        }
        if ($follow && empty($job['seen'][$norm]) && count($job['seen']) < NIBWP_STATIC_MAX_PAGES) {
            $job['seen'][$norm] = 1;
            $job['queue'][] = $norm;
        }
        $rel = nibwp_static_page_file($norm);
    }
    if ($job['opts']['links'] === 'absolute') {
        $out = $job['opts']['base'] . '/' . ltrim($isFile ? $rel : preg_replace('#index\.html$#', '', $rel), '/');
    } else {
        $out = nibwp_static_relpath($from, $rel);
    }

    return $out . $hash;
}

/** A file of this site, copied into the export; its path there, or ''. */
function nibwp_static_copy(array &$job, string $url): string
{
    $path = rawurldecode((string) wp_parse_url($url, PHP_URL_PATH));
    $home = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/');
    $rel = ltrim(substr($path, strlen(untrailingslashit($home))), '/');
    if ($rel === '' || !preg_match('#\.(' . NIBWP_STATIC_EXT . ')$#i', $rel)) {
        return '';
    }
    if (!empty($job['copied'][$rel])) {
        return $rel;
    }
    // Where it is on disk: content, includes, or the site root.
    $src = '';
    $content = (string) wp_parse_url(content_url('/'), PHP_URL_PATH);
    $includes = (string) wp_parse_url(includes_url('/'), PHP_URL_PATH);
    if (strpos($path, $content) === 0) {
        $src = trailingslashit(WP_CONTENT_DIR) . substr($path, strlen($content));
    } elseif (strpos($path, $includes) === 0) {
        $src = ABSPATH . WPINC . '/' . substr($path, strlen($includes));
    } else {
        $src = ABSPATH . $rel;
    }
    $real = realpath($src);
    $roots = array_filter([realpath(ABSPATH), realpath(WP_CONTENT_DIR)]);
    $inside = false;
    foreach ($roots as $root) {
        if ($real && strpos($real, $root . DIRECTORY_SEPARATOR) === 0) {
            $inside = true;
        }
    }
    if (!$real) {
        // Not on disk: made on the fly (a plugin's CSS on first view, a resized image, a
        // virtual file like robots.txt). Fetched as a visitor gets it, once.
        return nibwp_static_fetch_file($job, $url, $rel);
    }
    if (!$inside || !is_file($real) || preg_match('#\.(php|phtml|phar|inc)$#i', $real)) {
        return '';
    }
    $job['copied'][$rel] = 1;
    $dest = $job['dir'] . '/site/' . $rel;
    wp_mkdir_p(dirname($dest));
    if (preg_match('#\.css$#i', $rel)) {
        $css = (string) file_get_contents($real); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local stylesheet of this site
        $css = nibwp_static_rewrite_css($job, $css, $rel, $url);
        file_put_contents($dest, $css); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the export inside our locked folder
        $job['bytes'] += strlen($css);
    } else {
        copy($real, $dest);
        $job['bytes'] += (int) filesize($real);
    }
    $job['files']++;
    nibwp_static_log($job, '/' . $rel);

    return $rel;
}

/** A file of this site that is not on disk, fetched; its path in the export, or ''. */
function nibwp_static_fetch_file(array &$job, string $url, string $rel): string
{
    if (!empty($job['miss'][$rel])) {
        return '';
    }
    $r = wp_remote_get($url, ['timeout' => 20, 'redirection' => 2, 'sslverify' => false, 'headers' => ['X-NibWP-Static' => '1']]);
    $type = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'content-type');
    // A page in place of the file (a 404 answered as a page) is not the file.
    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200 || (stripos($type, 'text/html') !== false && !preg_match('#\.html?$#i', $rel))) {
        $job['miss'][$rel] = 1;
        return '';
    }
    $data = (string) wp_remote_retrieve_body($r);
    $job['copied'][$rel] = 1;
    if (preg_match('#\.css$#i', $rel)) {
        $data = nibwp_static_rewrite_css($job, $data, $rel, $url);
    }
    nibwp_static_write($job, $rel, $data);
    $job['files']++;
    nibwp_static_log($job, '/' . $rel);

    return $rel;
}

/** Links in CSS: url(), @import. Relative ones resolve against the CSS file. */
function nibwp_static_rewrite_css(array &$job, string $css, string $file, string $cssUrl): string
{
    $baseDir = trailingslashit(dirname($cssUrl));

    return (string) preg_replace_callback('#url\(\s*([\'"]?)([^\'")]+)\1\s*\)|@import\s+([\'"])([^\'"]+)\3#i', static function ($m) use (&$job, $file, $baseDir) {
        $raw = $m[2] !== '' ? $m[2] : $m[4];
        if (preg_match('#^(data:|\#)#i', $raw)) {
            return $m[0];
        }
        $abs = preg_match('#^(https?:)?//|^/#i', $raw) ? $raw : nibwp_static_resolve($baseDir, $raw);
        $new = nibwp_static_link($job, $abs, $file, false);
        if ($new === $abs) {
            return $m[0];
        }

        return $m[2] !== '' ? 'url(' . $m[1] . $new . $m[1] . ')' : '@import ' . $m[3] . $new . $m[3];
    }, $css);
}

/** A relative address resolved against a folder address. */
function nibwp_static_resolve(string $base, string $rel): string
{
    $rel = preg_replace('#[?\#].*$#', '', $rel);
    $p = wp_parse_url($base);
    $parts = explode('/', trim((string) ($p['path'] ?? '/'), '/'));
    foreach (explode('/', (string) $rel) as $seg) {
        if ($seg === '..') {
            array_pop($parts);
        } elseif ($seg !== '.' && $seg !== '') {
            $parts[] = $seg;
        }
    }

    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . implode('/', $parts);
}

/** A page made static: links, sources and styles pointing inside the export. */
function nibwp_static_rewrite_html(array &$job, string $html, string $file): string
{
    $page = '/' . preg_replace('#index\.html$#', '', $file);
    if (preg_match('#<form\b#i', $html) && preg_match('#wpcf7|wpforms|gform_|fluentform|elementor-form|wp-comments-post\.php|admin-post\.php|admin-ajax\.php#i', $html)) {
        $job['notes']['forms'][$page] = 1;
    }
    if (preg_match('#<form[^>]+role=["\']search|<input[^>]+name=["\']s["\']#i', $html)) {
        $job['notes']['search'][$page] = 1;
    }
    $html = nibwp_static_rewrite_html_links($job, $html, $file);
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $base = (string) wp_parse_url((string) ($job['opts']['base'] ?? ''), PHP_URL_HOST);
    if ($host !== $base && (strpos($html, '//' . $host) !== false || strpos($html, '\\/\\/' . $host) !== false)) {
        $job['notes']['calls'][$page] = 1;
    }

    return $html;
}

/** Links, sources and styles pointing inside the export. */
function nibwp_static_rewrite_html_links(array &$job, string $html, string $file): string
{
    // Endpoints that need WordPress go: discovery links, REST, XML-RPC, oEmbed, shortlinks.
    $html = (string) preg_replace('#<link[^>]+rel=["\'](?:EditURI|wlwmanifest|pingback|shortlink|https://api\.w\.org/|alternate)["\'][^>]*>\s*#i', '', $html);
    $html = (string) preg_replace('#<link[^>]+type=["\']application/(?:json\+oembed|xml\+oembed|rss\+xml|atom\+xml)["\'][^>]*>\s*#i', '', $html);

    // Attributes that hold an address.
    $html = (string) preg_replace_callback('#\s(href|src|poster|data-src|data-bg|action|content)=(["\'])(.*?)\2#is', static function ($m) use (&$job, $file) {
        if ($m[1] === 'content' && ($job['opts']['links'] !== 'absolute' || !preg_match('#^(https?:)?//#i', $m[3]))) {
            return $m[0];
        }
        $new = nibwp_static_link($job, $m[3], $file, $m[1] !== 'src' && $m[1] !== 'poster');

        return ' ' . $m[1] . '=' . $m[2] . esc_attr($new) . $m[2];
    }, $html);
    // srcset: several addresses.
    $html = (string) preg_replace_callback('#\s(srcset|data-srcset)=(["\'])(.*?)\2#is', static function ($m) use (&$job, $file) {
        $items = array_map(static function ($part) use (&$job, $file) {
            $bits = preg_split('#\s+#', trim($part), 2);
            if (!$bits || $bits[0] === '') {
                return $part;
            }

            return nibwp_static_link($job, $bits[0], $file, false) . (isset($bits[1]) ? ' ' . $bits[1] : '');
        }, explode(',', $m[3]));

        return ' ' . $m[1] . '=' . $m[2] . esc_attr(implode(', ', $items)) . $m[2];
    }, $html);
    // Styles written in the page.
    $html = (string) preg_replace_callback('#(<style[^>]*>)(.*?)(</style>)#is', static function ($m) use (&$job, $file) {
        return $m[1] . nibwp_static_rewrite_css($job, $m[2], $file, nibwp_static_norm(home_url('/' . $file))) . $m[3];
    }, $html);
    $html = (string) preg_replace_callback('#\sstyle=(["\'])(.*?)\1#is', static function ($m) use (&$job, $file) {
        return ' style=' . $m[1] . nibwp_static_rewrite_css($job, $m[2], $file, home_url('/')) . $m[1];
    }, $html);

    return $html;
}

/**
 * After the pages: the 404 page, all media if asked, sitemap and robots.
 * The media goes in steps: false while some is left for the next step.
 */
function nibwp_static_finish(array &$job, float $until = 0.0): bool
{
    if (empty($job['fin404'])) {
        $job['fin404'] = 1;
        $r = wp_remote_get(home_url('/nibwp-static-404-' . wp_generate_password(8, false) . '/'), ['timeout' => 25, 'sslverify' => false]);
        if (!is_wp_error($r) && wp_remote_retrieve_body($r) !== '') {
            nibwp_static_write($job, '404.html', nibwp_static_rewrite_html($job, (string) wp_remote_retrieve_body($r), '404.html'));
        }
    }
    if (!empty($job['media_all']) && !isset($job['mq'])) {
        $job['mq'] = array_map('intval', get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 5000, 'fields' => 'ids']));
        $job['mtotal'] = count($job['mq']);
    }
    while (!empty($job['mq'])) {
        if ($until > 0 && microtime(true) >= $until) {
            return false;
        }
        $u = wp_get_attachment_url((int) array_shift($job['mq']));
        if ($u) {
            nibwp_static_copy($job, $u);
        }
    }
    $urls = $job['done'] ?? [];
    if ($job['opts']['links'] === 'absolute') {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . esc_url($job['opts']['base'] . '/' . ltrim(preg_replace('#index\.html$#', '', nibwp_static_page_file($u)), '/')) . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        nibwp_static_write($job, 'sitemap.xml', $xml);
        nibwp_static_write($job, 'robots.txt', "User-agent: *\nAllow: /\n\nSitemap: " . $job['opts']['base'] . "/sitemap.xml\n");
    } else {
        nibwp_static_write($job, 'robots.txt', "User-agent: *\nAllow: /\n");
    }

    return true;
}

/* ------------------------------------------------------------------
 * Zip and download.
 * ---------------------------------------------------------------- */

/**
 * Pack the zip, in steps: each call adds files for a few seconds (and up to
 * a size) and closes the zip, so no request runs long. Files that are
 * already compressed (images, fonts, video) are stored as they are: the
 * same size, much faster.
 */
function nibwp_static_rest_zip(): WP_REST_Response
{
    $job = nibwp_static_job();
    if (!$job || $job['state'] !== 'zip') {
        return new WP_REST_Response(['message' => __('The export is not ready to pack.', 'nibwp')], 400);
    }
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }
    $site = $job['dir'] . '/site';
    if (!isset($job['zlist'])) {
        $job['zlist'] = nibwp_static_files($site);
        $job['ztotal'] = count($job['zlist']);
        $job['zat'] = 0;
        $job['zip'] = $job['dir'] . '/' . sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST) . '-static-' . gmdate('Ymd-His')) . '.zip';
    }
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($job['zip'], $job['zat'] === 0 ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE) !== true) {
            return new WP_REST_Response(['message' => __('Could not create the zip.', 'nibwp')], 500);
        }
        $until = microtime(true) + NIBWP_STATIC_STEP_SECONDS;
        $bytes = 0;
        while ($job['zat'] < $job['ztotal'] && microtime(true) < $until && $bytes < 128 * MB_IN_BYTES) {
            $rel = (string) $job['zlist'][$job['zat']++];
            $z->addFile($site . '/' . $rel, $rel);
            if (preg_match('#\.(png|jpe?g|gif|webp|avif|woff2?|mp4|webm|ogv|mp3|ogg|m4a|zip|pdf)$#i', $rel)) {
                $z->setCompressionName($rel, ZipArchive::CM_STORE);
            }
            $bytes += (int) filesize($site . '/' . $rel);
        }
        if (!$z->close()) {
            return new WP_REST_Response(['message' => __('Could not create the zip.', 'nibwp')], 500);
        }
    } else {
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $z = new PclZip($job['zip']);
        if ($z->create($site, PCLZIP_OPT_REMOVE_PATH, $site) === 0) {
            return new WP_REST_Response(['message' => $z->errorInfo(true)], 500);
        }
        $job['zat'] = $job['ztotal'];
    }
    if ($job['zat'] >= $job['ztotal']) {
        unset($job['zlist']);
        $job['state'] = 'done';
    }
    nibwp_static_save_job($job);

    return new WP_REST_Response(nibwp_static_status($job));
}

add_action('admin_post_nibwp_static_download', static function (): void {
    $id = isset($_GET['id']) ? sanitize_key(wp_unslash($_GET['id'])) : '';
    if (!current_user_can('manage_options') || $id === '' || !wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_key(wp_unslash($_GET['_wpnonce'])) : '', 'nibwp_static_download_' . $id)) {
        wp_die(esc_html__('This download link is not valid any more.', 'nibwp'), '', ['response' => 403]);
    }
    $job = nibwp_static_job();
    if (!$job || $job['id'] !== $id || empty($job['zip'])) {
        wp_die(esc_html__('That export is gone. Export the site again.', 'nibwp'), '', ['response' => 404]);
    }
    $real = realpath($job['zip']);
    $base = realpath(nibwp_static_base());
    if (!$real || !$base || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
        wp_die(esc_html__('That export is gone. Export the site again.', 'nibwp'), '', ['response' => 404]);
    }
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name(basename($real)) . '"');
    header('Content-Length: ' . (string) filesize($real));
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level()) {
        ob_end_clean();
    }
    readfile($real); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming the export zip to its owner
    exit;
});

require_once __DIR__ . '/studio-publish.php';
