<?php
/**
 * Publish the static site: build it, send it to a host, keep it fresh.
 *
 * The export (studio-export.php) turns the site into files. This takes it
 * the rest of the way, the way people run WordPress as a static site:
 *   1. build   the pages as visitors see them, into a kept build folder;
 *              after the first build only what changed is fetched again
 *   2. send    the files to the host: Cloudflare Pages or Netlify (only
 *              files the host does not have yet travel), or any host over
 *              SFTP, FTPS or FTP (only files that changed)
 *   3. watch   edits to content, menus, design and settings are noted as
 *              "changes waiting"; with auto publish on, they go live by
 *              themselves a couple of minutes after the last edit.
 *
 * A run is one site-wide job kept in an option. It moves in short steps:
 * from the Agent View window while it is open, and on its own through a
 * chain of background requests (with WP-Cron as a safety net) when it is
 * not, so closing the window does not stop it. A lock keeps two steps
 * from running at once.
 *
 * Safety: administrators only; host keys and passwords are sealed with the
 * site's own salts before they are stored and never sent back to the
 * browser; the background request carries a key tied to the run; only the
 * build folder's files are sent.
 *
 * @package NibWP
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/studio-blake3.php';

const NIBWP_STATIC_TICK_SECONDS = 20;
const NIBWP_STATIC_AUTO_DELAY = 120;
const NIBWP_STATIC_CF_MAX_FILE = 25 * 1024 * 1024;
const NIBWP_STATIC_CF_BUCKET = 8 * 1024 * 1024;

/* ------------------------------------------------------------------
 * Settings and sealed secrets.
 * ---------------------------------------------------------------- */

function nibwp_static_settings(): array
{
    $s = get_option('nibwp_static_site', []);

    return array_replace_recursive([
        'target' => '',
        'cloudflare' => ['token' => '', 'account' => '', 'project' => '', 'branch' => 'main'],
        'netlify' => ['token' => '', 'site' => '', 'id' => ''],
        'ftp' => ['proto' => 'sftp', 'host' => '', 'port' => 0, 'user' => '', 'pass' => '', 'path' => ''],
        'live' => '',
        'domain' => '',
        'opts' => ['types' => [], 'taxonomies' => [], 'archives' => true, 'follow' => true, 'media' => 'used'],
        'auto' => false,
    ], is_array($s) ? $s : []);
}

function nibwp_static_save_settings(array $s): void
{
    update_option('nibwp_static_site', $s, false);
}

function nibwp_static_key(): string
{
    return sodium_crypto_generichash('nibwp-static|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function nibwp_static_seal(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return 'nb1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, nibwp_static_key()));
}

function nibwp_static_unseal(string $sealed): string
{
    if (strpos($sealed, 'nb1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($sealed, 4), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), nibwp_static_key());

    return $plain === false ? '' : $plain;
}

/** A site address as typed: https://host.tld, a port at most, no path. */
function nibwp_static_url(string $u): string
{
    $u = untrailingslashit(esc_url_raw(trim($u), ['https', 'http']));

    return preg_match('#^https?://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d{2,5})?$#i', $u) ? $u : '';
}

/** The address the static site lives at: the person's own domain, or the host's. */
function nibwp_static_live_url(array $s): string
{
    $u = $s['domain'] !== '' ? $s['domain'] : $s['live'];

    return $u === '' ? '' : untrailingslashit($u);
}

/** Settings as the browser sees them: secrets only as "saved". */
function nibwp_static_public_settings(array $s): array
{
    $tail = static function (string $sealed): string {
        $p = nibwp_static_unseal($sealed);

        return $p === '' ? '' : substr($p, -4);
    };
    $cf = $s['cloudflare'];
    $nl = $s['netlify'];
    $ftp = $s['ftp'];

    return [
        'target' => $s['target'],
        'cloudflare' => ['account' => $cf['account'], 'project' => $cf['project'], 'saved' => $tail($cf['token'])],
        'netlify' => ['site' => $nl['site'], 'saved' => $tail($nl['token'])],
        'ftp' => ['proto' => $ftp['proto'], 'host' => $ftp['host'], 'port' => (int) $ftp['port'], 'user' => $ftp['user'], 'path' => $ftp['path'], 'saved' => $ftp['pass'] !== ''],
        'live' => $s['live'],
        'domain' => $s['domain'],
        'opts' => $s['opts'],
        'auto' => (bool) $s['auto'],
    ];
}

/** Build options: what goes in, and links made for the live address. */
function nibwp_static_build_opts(array $s): array
{
    $o = nibwp_static_clean_opts($s['opts']);
    $live = nibwp_static_live_url($s);
    $o['links'] = $live !== '' ? 'absolute' : 'relative';
    $o['base'] = $live;

    return $o;
}

/* ------------------------------------------------------------------
 * Changes waiting: what was edited since the last build.
 * ---------------------------------------------------------------- */

function nibwp_static_dirty(): array
{
    $d = get_option('nibwp_static_dirty', []);

    return (is_array($d) ? $d : []) + ['all' => false, 'ids' => [], 'gone' => [], 'what' => [], 'at' => 0];
}

/**
 * Note a change. $id: a post whose page changed (gone: it left the site);
 * $all: something every page shows (menus, design, settings).
 */
function nibwp_static_mark(int $id, bool $all, string $what, bool $gone = false): void
{
    if (!get_option('nibwp_static_live')) {
        return;
    }
    $d = nibwp_static_dirty();
    if ($all) {
        $d['all'] = true;
    }
    if ($id) {
        $d['ids'][$id] = 1;
        if ($gone) {
            $d['gone'][$id] = 1;
        } else {
            unset($d['gone'][$id]);
        }
    }
    $what = trim(wp_strip_all_tags($what));
    if ($what !== '') {
        $d['what'] = array_slice(array_values(array_unique(array_merge([mb_substr($what, 0, 48)], $d['what']))), 0, 6);
    }
    $d['at'] = time();
    update_option('nibwp_static_dirty', $d, false);
    if (nibwp_static_settings()['auto']) {
        wp_clear_scheduled_hook('nibwp_static_auto');
        wp_schedule_single_event(time() + NIBWP_STATIC_AUTO_DELAY, 'nibwp_static_auto');
    }
}

function nibwp_static_dirty_count(array $d): int
{
    return count($d['ids']) + (!empty($d['all']) ? 1 : 0);
}

/** Public, viewable post types: each is a page of its own. */
function nibwp_static_is_page_type(string $type): bool
{
    return $type !== 'attachment' && is_post_type_viewable($type);
}

/** Post types that hold design every page shows: templates, parts, menus, builders' templates. */
function nibwp_static_is_design_type(string $type): bool
{
    return in_array($type, ['wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_block', 'custom_css', 'bricks_template', 'elementor_library', 'ct_template', 'breakdance_template', 'breakdance_header', 'breakdance_footer', 'kadence_element', 'gp_elements', 'fl-builder-template'], true);
}

add_action('transition_post_status', static function (string $new, string $old, $post): void {
    if (!$post instanceof WP_Post || wp_is_post_revision($post) || wp_is_post_autosave($post) || ($new !== 'publish' && $old !== 'publish')) {
        return;
    }
    if (nibwp_static_is_page_type($post->post_type)) {
        nibwp_static_mark((int) $post->ID, false, get_the_title($post), $new !== 'publish');
    } elseif (nibwp_static_is_design_type($post->post_type)) {
        nibwp_static_mark(0, true, __('Site design', 'nibwp'));
    }
}, 10, 3);

add_action('wp_update_nav_menu', static fn() => nibwp_static_mark(0, true, __('Menus', 'nibwp')));
add_action('customize_save_after', static fn() => nibwp_static_mark(0, true, __('Site design', 'nibwp')));
add_action('switch_theme', static fn() => nibwp_static_mark(0, true, __('Theme', 'nibwp')));
foreach (['created_term', 'edited_term', 'delete_term'] as $nibwp_hook) {
    add_action($nibwp_hook, static fn() => nibwp_static_mark(0, true, __('Categories and tags', 'nibwp')));
}
unset($nibwp_hook);
add_action('transition_comment_status', static function ($new, $old, $c): void {
    if ($c instanceof WP_Comment && ($new === 'approved' || $old === 'approved')) {
        nibwp_static_mark((int) $c->comment_post_ID, false, __('Comments', 'nibwp'));
    }
}, 10, 3);
add_action('comment_post', static function ($id, $approved): void {
    $c = get_comment($id);
    if ($approved === 1 && $c) {
        nibwp_static_mark((int) $c->comment_post_ID, false, __('Comments', 'nibwp'));
    }
}, 10, 2);

/** Options every page shows: site name, front page, widgets, design, the Builder's CSS… */
function nibwp_static_option_changed(string $option): void
{
    if (preg_match('/^(blogname|blogdescription|site_icon|show_on_front|page_on_front|page_for_posts|permalink_structure|sidebars_widgets|widget_.+|theme_mods_.+|nibwp_framework|nibwp_builder_.+|etch_styles|bricks_global_.+|automatic_css_settings)$/', $option)) {
        nibwp_static_mark(0, true, $option === 'blogname' || $option === 'blogdescription' ? __('Settings', 'nibwp') : __('Site design', 'nibwp'));
    }
}
add_action('updated_option', static fn($o) => nibwp_static_option_changed((string) $o));
add_action('added_option', static fn($o) => nibwp_static_option_changed((string) $o));

/** Page builders keep a page's design in its meta, often without saving the post. */
function nibwp_static_meta_changed($mid, $post_id, $key): void
{
    if (!preg_match('/^_(nibwp_builder_|bricks_page_|elementor_data|etch|breakdance_data|fl_builder_data|kad_)/', (string) $key)) {
        return;
    }
    $post = get_post((int) $post_id);
    if (!$post) {
        return;
    }
    if (nibwp_static_is_design_type($post->post_type)) {
        nibwp_static_mark(0, true, __('Site design', 'nibwp'));
    } elseif ($post->post_status === 'publish' && nibwp_static_is_page_type($post->post_type)) {
        nibwp_static_mark((int) $post->ID, false, get_the_title($post));
    }
}
add_action('updated_post_meta', 'nibwp_static_meta_changed', 10, 3);
add_action('added_post_meta', 'nibwp_static_meta_changed', 10, 3);

/* ------------------------------------------------------------------
 * The build folder.
 * ---------------------------------------------------------------- */

function nibwp_static_live_dir(): string
{
    return nibwp_static_base() . '/live';
}

/** Every file in a folder, as forward-slash paths relative to it. */
function nibwp_static_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
        }
    }
    sort($out);

    return $out;
}

function nibwp_static_read_json(string $file): array
{
    $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache file

    return is_array($j) ? $j : [];
}

function nibwp_static_write_json(string $file, array $data): void
{
    wp_mkdir_p(dirname($file));
    file_put_contents($file, wp_json_encode($data)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own cache file in the locked folder
}

/** The sitemap and robots.txt, from the pages in the build. */
function nibwp_static_write_sitemap(string $site, string $base): void
{
    if ($base === '') {
        return;
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (nibwp_static_files($site) as $rel) {
        if (preg_match('#(^|/)index\.html$#', $rel)) {
            $xml .= '  <url><loc>' . esc_url($base . '/' . preg_replace('#index\.html$#', '', $rel)) . '</loc></url>' . "\n";
        }
    }
    file_put_contents($site . '/sitemap.xml', $xml . '</urlset>' . "\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the build in our locked folder
    file_put_contents($site . '/robots.txt', "User-agent: *\nAllow: /\n\nSitemap: " . $base . "/sitemap.xml\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the build in our locked folder
}

/* ------------------------------------------------------------------
 * The run: start, step, stop.
 * ---------------------------------------------------------------- */

function nibwp_static_run(): ?array
{
    wp_cache_delete('nibwp_static_run', 'options');
    $r = get_option('nibwp_static_run');

    return is_array($r) && !empty($r['id']) ? $r : null;
}

function nibwp_static_save_run(array $run): void
{
    update_option('nibwp_static_run', $run, false);
}

function nibwp_static_active(?array $run): bool
{
    return $run && in_array($run['state'], ['build', 'finish', 'send'], true);
}

/** One step at a time, site-wide. */
function nibwp_static_lock(): bool
{
    if (add_option('nibwp_static_lock', time() + 120, '', false)) {
        return true;
    }
    wp_cache_delete('nibwp_static_lock', 'options');
    if ((int) get_option('nibwp_static_lock') < time()) {
        delete_option('nibwp_static_lock');

        return add_option('nibwp_static_lock', time() + 120, '', false);
    }

    return false;
}

function nibwp_static_unlock(): void
{
    delete_option('nibwp_static_lock');
}

/**
 * Start publishing. $mode 'changes' builds only what changed (it becomes a
 * full build when design, settings or the address changed, or there is no
 * build yet); 'full' builds every page.
 *
 * @return array|WP_Error The run status.
 */
function nibwp_static_start(string $mode, string $by)
{
    $s = nibwp_static_settings();
    if (!in_array($s['target'], ['cloudflare', 'netlify', 'ftp'], true)) {
        return new WP_Error('nibwp_static_target', __('Choose where the site goes first.', 'nibwp'));
    }
    $run = nibwp_static_run();
    if (nibwp_static_active($run) && (int) ($run['touched'] ?? 0) > time() - 600) {
        return nibwp_static_run_status($run);
    }
    $opts = nibwp_static_build_opts($s);
    if (!$opts['types']) {
        return new WP_Error('nibwp_static_types', __('Pick at least one kind of content to publish.', 'nibwp'));
    }
    $live = get_option('nibwp_static_live');
    $hash = md5((string) wp_json_encode($opts));
    $dirty = nibwp_static_dirty();
    $site = nibwp_static_live_dir() . '/site';
    $full = $mode === 'full' || !is_array($live) || ($live['hash'] ?? '') !== $hash || !is_dir($site) || !empty($dirty['all']);
    delete_option('nibwp_static_dirty');

    $ids = [];
    if ($full) {
        $dir = nibwp_static_base() . '/live-next';
        if (is_dir($dir)) {
            nibwp_static_rmdir($dir);
        }
        wp_mkdir_p($dir . '/site');
        $queue = nibwp_static_queue($opts, $ids);
    } else {
        $dir = nibwp_static_live_dir();
        $queue = [home_url('/')];
        $posts = (int) get_option('page_for_posts');
        if ($posts) {
            $queue[] = (string) get_permalink($posts);
        }
        foreach (array_keys($dirty['ids']) as $pid) {
            $post = get_post((int) $pid);
            if (!$post || !empty($dirty['gone'][$pid]) || $post->post_status !== 'publish' || !in_array($post->post_type, $opts['types'], true)) {
                continue;
            }
            $url = (string) get_permalink($post);
            $ids[nibwp_static_norm($url)] = (int) $pid;
            $queue[] = $url;
            if ($opts['archives'] && ($a = get_post_type_archive_link($post->post_type))) {
                $queue[] = (string) $a;
            }
            foreach ($opts['taxonomies'] as $tax) {
                foreach (wp_get_post_terms((int) $pid, $tax) ?: [] as $term) {
                    $link = is_wp_error($term) ? '' : get_term_link($term);
                    if ($link && !is_wp_error($link)) {
                        $queue[] = (string) $link;
                    }
                }
            }
        }
        $queue = array_values(array_unique(array_filter(array_map('nibwp_static_norm', $queue))));
        $opts['follow_all'] = $opts['follow'];
        $opts['follow'] = 'paged';
    }

    $run = [
        'id' => strtolower(wp_generate_password(20, false)),
        'mode' => $full ? 'full' : 'changes',
        'by' => $by,
        'target' => $s['target'],
        'state' => 'build',
        'started' => time(),
        'touched' => time(),
        'hash' => $hash,
        'snap' => $dirty,
        'url' => nibwp_static_live_url($s),
        'msg' => '',
        'send' => ['phase' => '', 'sent' => 0, 'need' => 0],
        'job' => [
            'id' => 'live',
            'dir' => $dir,
            'opts' => $opts,
            'queue' => $queue,
            'seen' => array_fill_keys($queue, 1),
            'ids' => $ids,
            'pages' => 0,
            'files' => 0,
            'bytes' => 0,
            'errors' => [],
            'log' => [],
            'done' => [],
            'copied' => [],
            'notes' => [],
            'media_all' => $opts['media'] === 'all' ? 1 : 0,
            'par' => nibwp_static_par(),
        ],
    ];
    nibwp_static_save_run($run);
    wp_clear_scheduled_hook('nibwp_static_tick_cron');
    wp_schedule_single_event(time() + 300, 'nibwp_static_tick_cron');
    nibwp_static_kick($run);

    return nibwp_static_run_status($run);
}

/** Run steps for up to $budget seconds. Returns false when another step holds the lock. */
function nibwp_static_step(float $budget): bool
{
    if (!nibwp_static_active(nibwp_static_run())) {
        return true;
    }
    if (!nibwp_static_lock()) {
        return false;
    }
    $run = nibwp_static_run();
    $until = microtime(true) + $budget;
    try {
        while (nibwp_static_active($run) && microtime(true) < $until) {
            if ($run['state'] === 'build') {
                if ($run['job']['queue']) {
                    nibwp_static_pages($run['job']);
                } else {
                    $run['state'] = 'finish';
                }
            } elseif ($run['state'] === 'finish') {
                if (nibwp_static_finish_build($run, $until)) {
                    $run['state'] = 'send';
                }
            } else {
                $done = nibwp_static_send($run, $until);
                if (is_wp_error($done)) {
                    nibwp_static_end($run, false, $done->get_error_message());
                } elseif ($done) {
                    nibwp_static_end($run, true, '');
                }
            }
        }
    } catch (Throwable $e) {
        nibwp_static_end($run, false, $e->getMessage());
    }
    $run['touched'] = time();
    nibwp_static_save_run($run);
    nibwp_static_unlock();

    return true;
}

/** The build is complete: the 404 page and sitemap, the new build put in place. False while media is still being copied. */
function nibwp_static_finish_build(array &$run, float $until = 0.0): bool
{
    $job = &$run['job'];
    $live = nibwp_static_live_dir();
    $map = nibwp_static_read_json($live . '/map.json');
    if ($run['mode'] === 'full') {
        if (!nibwp_static_finish($job, $until)) {
            return false;
        }
        if (is_dir($live . '/site')) {
            nibwp_static_rmdir($live . '/site');
        }
        wp_mkdir_p($live);
        rename($job['dir'] . '/site', $live . '/site'); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- swapping builds inside our locked folder
        nibwp_static_rmdir($job['dir']);
        $job['dir'] = $live;
        $map = [];
    } else {
        foreach (array_keys($run['snap']['gone'] ?? []) as $pid) {
            $rel = $map[$pid] ?? '';
            if ($rel !== '' && is_file($live . '/site/' . $rel)) {
                wp_delete_file($live . '/site/' . $rel);
                @rmdir(dirname($live . '/site/' . $rel)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the folder only when empty
            }
            unset($map[$pid]);
        }
    }
    foreach ($job['ids'] as $url => $pid) {
        $rel = nibwp_static_page_file($url);
        if (isset($map[$pid]) && $map[$pid] !== $rel && is_file($live . '/site/' . $map[$pid])) {
            wp_delete_file($live . '/site/' . $map[$pid]);
        }
        if (in_array($url, $job['done'], true)) {
            $map[$pid] = $rel;
        }
    }
    nibwp_static_write_json($live . '/map.json', $map);
    nibwp_static_write_sitemap($live . '/site', $job['opts']['base']);
    $pages = count(array_filter(nibwp_static_files($live . '/site'), static fn($f) => (bool) preg_match('#(^|/)index\.html$#', $f)));
    update_option('nibwp_static_live', ['hash' => $run['hash'], 'built' => time(), 'pages' => $pages], false);
    $run['pages'] = $pages;

    return true;
}

function nibwp_static_end(array &$run, bool $ok, string $msg): void
{
    $run['state'] = $ok ? 'done' : 'failed';
    $run['msg'] = $msg;
    $run['ended'] = time();
    if (!$ok) {
        nibwp_static_restore_dirty($run['snap'] ?? []);
    }
    $h = get_option('nibwp_static_history', []);
    array_unshift($h, ['at' => time(), 'ok' => $ok, 'mode' => $run['mode'], 'by' => $run['by'], 'target' => $run['target'], 'pages' => (int) ($run['pages'] ?? $run['job']['pages']), 'fetched' => (int) $run['job']['pages'], 'sent' => (int) $run['send']['sent'], 'url' => $run['url'], 'msg' => $msg]);
    update_option('nibwp_static_history', array_slice(is_array($h) ? $h : [], 0, 20), false);
    wp_clear_scheduled_hook('nibwp_static_tick_cron');
}

/** A run that did not make it: what it was meant to publish is waiting again. */
function nibwp_static_restore_dirty(array $snap): void
{
    if (!$snap || !get_option('nibwp_static_live')) {
        return;
    }
    $d = nibwp_static_dirty();
    $d['all'] = !empty($d['all']) || !empty($snap['all']);
    $d['ids'] = ($d['ids'] ?? []) + ($snap['ids'] ?? []);
    $d['gone'] = ($d['gone'] ?? []) + ($snap['gone'] ?? []);
    $d['what'] = array_slice(array_values(array_unique(array_merge($d['what'] ?? [], $snap['what'] ?? []))), 0, 6);
    $d['at'] = max((int) ($d['at'] ?? 0), (int) ($snap['at'] ?? 0));
    update_option('nibwp_static_dirty', $d, false);
}

function nibwp_static_stop(): void
{
    $run = nibwp_static_run();
    if (nibwp_static_active($run)) {
        nibwp_static_end($run, false, __('Stopped.', 'nibwp'));
        $run['state'] = 'stopped';
        nibwp_static_save_run($run);
        if ($run['mode'] === 'full' && $run['job']['dir'] !== nibwp_static_live_dir()) {
            nibwp_static_rmdir($run['job']['dir']);
        }
    }
    nibwp_static_unlock();
}

/** What the window shows of a run. */
function nibwp_static_run_status(?array $run): array
{
    if (!$run) {
        return ['state' => 'idle'];
    }
    $j = $run['job'];
    $notes = [];
    foreach (['forms', 'search', 'calls'] as $k) {
        $list = array_keys($j['notes'][$k] ?? []);
        $notes[$k] = ['n' => count($list), 'pages' => array_slice($list, 0, 6)];
    }

    return [
        'id' => $run['id'],
        'state' => $run['state'],
        'mode' => $run['mode'],
        'by' => $run['by'],
        'target' => $run['target'],
        'pages' => (int) $j['pages'],
        'total' => (int) $j['pages'] + count($j['queue']),
        'files' => (int) $j['files'],
        'bytes' => (int) $j['bytes'],
        'phase' => (string) $run['send']['phase'],
        'sent' => (int) $run['send']['sent'],
        'need' => (int) $run['send']['need'],
        'errors' => array_slice($j['errors'], -20),
        'log' => array_slice($j['log'], -12),
        'next' => nibwp_static_next($j['queue']),
        'media' => ['left' => count($j['mq'] ?? []), 'total' => (int) ($j['mtotal'] ?? 0)],
        'notes' => $notes,
        'url' => (string) $run['url'],
        'msg' => (string) $run['msg'],
        'started' => (int) $run['started'],
        'ended' => (int) ($run['ended'] ?? 0),
        'now' => time(),
    ];
}

/* ------------------------------------------------------------------
 * Running in the background: a chain of requests to itself, with a cron
 * event in case the chain breaks; the auto publish after edits.
 * ---------------------------------------------------------------- */

function nibwp_static_tick_key(array $run): string
{
    return hash_hmac('sha256', 'nibwp-static-tick|' . $run['id'], wp_salt('nonce'));
}

function nibwp_static_kick(array $run): void
{
    wp_remote_post(admin_url('admin-ajax.php'), [
        'blocking' => false,
        'timeout' => 0.01,
        'sslverify' => false,
        'body' => ['action' => 'nibwp_static_tick', 'k' => nibwp_static_tick_key($run)],
    ]);
}

function nibwp_static_tick(): void
{
    $run = nibwp_static_run();
    $key = isset($_POST['k']) ? sanitize_text_field(wp_unslash($_POST['k'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the key is the check: it is tied to the run
    if (!$run || !hash_equals(nibwp_static_tick_key($run), $key)) {
        wp_die('', '', ['response' => 403]);
    }
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        set_time_limit(NIBWP_STATIC_TICK_SECONDS + 60);
    }
    nibwp_static_step(NIBWP_STATIC_TICK_SECONDS);
    $run = nibwp_static_run();
    if (nibwp_static_active($run)) {
        nibwp_static_kick($run);
    }
    wp_die('', '', ['response' => 200]);
}
add_action('wp_ajax_nibwp_static_tick', 'nibwp_static_tick');
add_action('wp_ajax_nopriv_nibwp_static_tick', 'nibwp_static_tick');

add_action('nibwp_static_tick_cron', static function (): void {
    $run = nibwp_static_run();
    if (!nibwp_static_active($run)) {
        return;
    }
    wp_schedule_single_event(time() + 300, 'nibwp_static_tick_cron');
    nibwp_static_step(NIBWP_STATIC_TICK_SECONDS);
    $run = nibwp_static_run();
    if (nibwp_static_active($run)) {
        nibwp_static_kick($run);
    }
});

add_action('nibwp_static_auto', static function (): void {
    $s = nibwp_static_settings();
    if (!$s['auto'] || !nibwp_static_dirty_count(nibwp_static_dirty())) {
        return;
    }
    if (nibwp_static_active(nibwp_static_run())) {
        wp_schedule_single_event(time() + NIBWP_STATIC_AUTO_DELAY, 'nibwp_static_auto');
        return;
    }
    nibwp_static_start('changes', 'auto');
});

/* ------------------------------------------------------------------
 * Sending: to the host the person chose.
 * ---------------------------------------------------------------- */

/** @return bool|WP_Error true when the site is live. */
function nibwp_static_send(array &$run, float $until)
{
    $s = nibwp_static_settings();
    if ($run['target'] === 'cloudflare') {
        return nibwp_static_send_cloudflare($run, $s, $until);
    }
    if ($run['target'] === 'netlify') {
        return nibwp_static_send_netlify($run, $s, $until);
    }

    return nibwp_static_send_ftp($run, $s, $until);
}

function nibwp_static_mime(string $rel): string
{
    static $map = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json', 'map' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8', 'svg' => 'image/svg+xml', 'webmanifest' => 'application/manifest+json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'pdf' => 'application/pdf', 'csv' => 'text/csv', 'zip' => 'application/zip', 'vtt' => 'text/vtt', 'glb' => 'model/gltf-binary', 'gltf' => 'model/gltf+json',
    ];

    return $map[strtolower((string) pathinfo($rel, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** A call to a host's API: JSON in and out, or a raw body. */
function nibwp_static_api(string $method, string $url, string $bearer, $body = null, string $type = 'application/json', int $timeout = 90): array
{
    $args = ['method' => $method, 'timeout' => $timeout, 'headers' => ['Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/json']];
    if ($body !== null) {
        $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        $args['headers']['Content-Type'] = $type;
    }
    $r = wp_remote_request($url, $args);
    if (is_wp_error($r)) {
        return ['ok' => false, 'code' => 0, 'msg' => $r->get_error_message(), 'json' => null];
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    $json = json_decode((string) wp_remote_retrieve_body($r), true);

    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'msg' => '', 'json' => $json];
}

/* Cloudflare Pages ------------------------------------------------- */

function nibwp_static_cf_base(): string
{
    return (string) apply_filters('nibwp_static_cloudflare_api', 'https://api.cloudflare.com/client/v4');
}

/** Cloudflare's answer: its result, or its own error message. */
function nibwp_static_cf(string $method, string $path, string $bearer, $body = null, string $type = 'application/json')
{
    $r = nibwp_static_api($method, nibwp_static_cf_base() . $path, $bearer, $body, $type, 120);
    $j = is_array($r['json']) ? $r['json'] : [];
    if ($r['ok'] && !empty($j['success'])) {
        return $j['result'] ?? [];
    }
    $e = $j['errors'][0] ?? [];
    $msg = $r['msg'] !== '' ? $r['msg'] : (string) ($e['message'] ?? sprintf('HTTP %d', $r['code']));

    return new WP_Error('nibwp_cf', sprintf(/* translators: %s: the host's error message */ __('Cloudflare said: %s', 'nibwp'), $msg), ['status' => $r['code'], 'code' => (int) ($e['code'] ?? 0)]);
}

function nibwp_static_cf_name(string $name): string
{
    return substr(trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-'), 0, 58);
}

/**
 * Connect: the token finds the account, the project is found or made.
 *
 * @return array|WP_Error ['account', 'project', 'branch', 'live'] or ['accounts' => list] to pick from.
 */
function nibwp_static_cf_connect(string $token, string $account, string $project)
{
    $project = nibwp_static_cf_name($project);
    if ($token === '' || $project === '') {
        return new WP_Error('nibwp_cf', __('Paste the API token and name the project.', 'nibwp'));
    }
    if ($account === '') {
        $list = nibwp_static_cf('GET', '/accounts?per_page=50', $token);
        if (is_wp_error($list)) {
            return $list;
        }
        if (!$list) {
            return new WP_Error('nibwp_cf', __('This token cannot see any account. Make it with the permission Account › Cloudflare Pages › Edit.', 'nibwp'));
        }
        if (count($list) > 1) {
            return ['accounts' => array_map(static fn($a) => ['id' => (string) $a['id'], 'name' => (string) $a['name']], $list)];
        }
        $account = (string) $list[0]['id'];
    }
    $account = preg_replace('/[^a-f0-9]/', '', strtolower($account));
    $p = nibwp_static_cf('GET', '/accounts/' . $account . '/pages/projects/' . $project, $token);
    if (is_wp_error($p) && (int) ($p->get_error_data()['status'] ?? 0) === 404) {
        $p = nibwp_static_cf('POST', '/accounts/' . $account . '/pages/projects', $token, ['name' => $project, 'production_branch' => 'main']);
    }
    if (is_wp_error($p)) {
        return $p;
    }
    $live = 'https://' . (string) ($p['subdomain'] ?? $project . '.pages.dev');
    foreach ((array) ($p['domains'] ?? []) as $d) {
        if (is_string($d) && substr($d, -10) !== '.pages.dev') {
            $live = 'https://' . $d;
            break;
        }
    }

    return ['account' => $account, 'project' => (string) ($p['name'] ?? $project), 'branch' => (string) ($p['production_branch'] ?? 'main'), 'live' => $live];
}

/**
 * Files go up by content: Cloudflare is asked which it lacks, only those
 * travel, then one deployment names every file.
 */
function nibwp_static_send_cloudflare(array &$run, array $s, float $until)
{
    $c = $s['cloudflare'];
    $token = nibwp_static_unseal($c['token']);
    $acct = '/accounts/' . $c['account'] . '/pages/projects/' . $c['project'];
    $live = nibwp_static_live_dir();
    $site = $live . '/site';
    $sendFile = $live . '/send.json';
    $st = &$run['send'];
    if ($st['phase'] === '') {
        $st['phase'] = 'hash';
        $st['files'] = nibwp_static_files($site);
        $st['at'] = 0;
        nibwp_static_write_json($sendFile, []);
    }
    $jwt = static function () use (&$st, $token, $acct) {
        if (!empty($st['jwt']) && (int) $st['jwt_at'] > time() - 240) {
            return $st['jwt'];
        }
        $t = nibwp_static_cf('GET', $acct . '/upload-token', $token);
        if (is_wp_error($t)) {
            return $t;
        }
        $st['jwt'] = (string) ($t['jwt'] ?? '');
        $st['jwt_at'] = time();

        return $st['jwt'];
    };

    if ($st['phase'] === 'hash') {
        $cache = nibwp_static_read_json($live . '/hashes.json');
        $have = nibwp_static_read_json($sendFile);
        while ($st['at'] < count($st['files']) && microtime(true) < $until) {
            $rel = $st['files'][$st['at']++];
            $path = $site . '/' . $rel;
            if (filesize($path) > NIBWP_STATIC_CF_MAX_FILE) {
                /* translators: %s: a file */
                $run['job']['errors'][] = sprintf(__('%s is over 25 MB, the most Cloudflare Pages takes for one file; it was left out.', 'nibwp'), $rel);
                continue;
            }
            $data = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of our build
            $md5 = md5($data);
            if (($cache[$rel][0] ?? '') !== $md5) {
                $cache[$rel] = [$md5, nibwp_static_cf_hash($data, $rel)];
            }
            $have[$rel] = $cache[$rel][1];
        }
        $done = $st['at'] >= count($st['files']);
        nibwp_static_write_json($live . '/hashes.json', $done ? array_intersect_key($cache, array_flip($st['files'])) : $cache);
        nibwp_static_write_json($sendFile, $have);
        if (!$done) {
            return false;
        }
        if (count($have) > 20000) {
            return new WP_Error('nibwp_cf', __('Cloudflare Pages takes up to 20,000 files a site. Leave the whole media library out, or some content types.', 'nibwp'));
        }
        $st['phase'] = 'check';
    }

    $have = nibwp_static_read_json($sendFile);
    if ($st['phase'] === 'check') {
        $j = $jwt();
        if (is_wp_error($j)) {
            return $j;
        }
        $missing = nibwp_static_cf('POST', '/pages/assets/check-missing', $j, ['hashes' => array_values(array_unique(array_values($have)))]);
        if (is_wp_error($missing)) {
            return $missing;
        }
        $st['missing'] = array_values((array) $missing);
        $st['need'] = count($st['missing']);
        $st['sent'] = 0;
        $st['phase'] = 'upload';
    }

    if ($st['phase'] === 'upload') {
        $byHash = array_flip($have);
        while ($st['missing'] && microtime(true) < $until) {
            $bucket = [];
            $size = 0;
            while ($st['missing'] && $size < NIBWP_STATIC_CF_BUCKET && count($bucket) < 500) {
                $h = $st['missing'][0];
                $rel = $byHash[$h] ?? '';
                $data = $rel !== '' ? (string) file_get_contents($site . '/' . $rel) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of our build
                if ($bucket && $size + strlen($data) > NIBWP_STATIC_CF_BUCKET) {
                    break;
                }
                array_shift($st['missing']);
                if ($rel === '') {
                    continue;
                }
                $bucket[] = ['key' => $h, 'value' => base64_encode($data), 'metadata' => ['contentType' => nibwp_static_mime($rel)], 'base64' => true];
                $size += strlen($data);
            }
            if (!$bucket) {
                continue;
            }
            $j = $jwt();
            if (is_wp_error($j)) {
                return $j;
            }
            $up = nibwp_static_cf('POST', '/pages/assets/upload', $j, $bucket);
            if (is_wp_error($up)) {
                if ((int) ($up->get_error_data()['status'] ?? 0) === 401) {
                    $st['jwt'] = '';
                    foreach (array_reverse($bucket) as $b) {
                        array_unshift($st['missing'], $b['key']);
                    }
                    continue;
                }
                return $up;
            }
            $st['sent'] += count($bucket);
        }
        if ($st['missing']) {
            return false;
        }
        $st['phase'] = 'upsert';
    }

    if ($st['phase'] === 'upsert') {
        $j = $jwt();
        if (!is_wp_error($j)) {
            nibwp_static_cf('POST', '/pages/assets/upsert-hashes', $j, ['hashes' => array_values(array_unique(array_values($have)))]);
        }
        $st['phase'] = 'deploy';
    }

    if ($st['phase'] === 'deploy') {
        $manifest = [];
        foreach ($have as $rel => $h) {
            $manifest['/' . $rel] = $h;
        }
        $type = '';
        $body = nibwp_static_multipart(['manifest' => (string) wp_json_encode($manifest), 'branch' => $c['branch'] ?: 'main', 'commit_message' => sprintf(/* translators: %s: site name */ __('Published from %s with NibWP', 'nibwp'), get_bloginfo('name'))], $type);
        $d = nibwp_static_cf('POST', $acct . '/deployments', $token, $body, $type);
        if (is_wp_error($d)) {
            return $d;
        }
        $st['deployment'] = (string) ($d['url'] ?? '');
        wp_delete_file($sendFile);

        return true;
    }

    return false;
}

/** A multipart/form-data body of plain fields. */
function nibwp_static_multipart(array $fields, string &$type): string
{
    $b = '----nibwp' . wp_generate_password(24, false);
    $out = '';
    foreach ($fields as $name => $value) {
        $out .= '--' . $b . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n";
    }
    $type = 'multipart/form-data; boundary=' . $b;

    return $out . '--' . $b . "--\r\n";
}

/* Netlify ----------------------------------------------------------- */

function nibwp_static_nl_base(): string
{
    return (string) apply_filters('nibwp_static_netlify_api', 'https://api.netlify.com/api/v1');
}

function nibwp_static_nl(string $method, string $path, string $token, $body = null, string $type = 'application/json')
{
    $r = nibwp_static_api($method, nibwp_static_nl_base() . $path, $token, $body, $type, 120);
    if ($r['ok']) {
        return is_array($r['json']) ? $r['json'] : [];
    }
    $msg = $r['msg'] !== '' ? $r['msg'] : (string) ($r['json']['message'] ?? sprintf('HTTP %d', $r['code']));

    return new WP_Error('nibwp_nl', sprintf(/* translators: %s: the host's error message */ __('Netlify said: %s', 'nibwp'), $msg), ['status' => $r['code']]);
}

/** Connect: the site is found by name or address, or made. */
function nibwp_static_nl_connect(string $token, string $site)
{
    $name = nibwp_static_cf_name((string) preg_replace('#^https?://|\.netlify\.app.*$#', '', strtolower(trim($site))));
    if ($token === '' || $name === '') {
        return new WP_Error('nibwp_nl', __('Paste the access token and name the site.', 'nibwp'));
    }
    $r = nibwp_static_nl('GET', '/sites/' . $name . '.netlify.app', $token);
    if (is_wp_error($r) && (int) ($r->get_error_data()['status'] ?? 0) === 404) {
        $r = nibwp_static_nl('POST', '/sites', $token, ['name' => $name]);
    }
    if (is_wp_error($r)) {
        return $r;
    }

    return ['site' => (string) ($r['name'] ?? $name), 'id' => (string) ($r['id'] ?? ''), 'live' => untrailingslashit((string) ($r['ssl_url'] ?? $r['url'] ?? 'https://' . $name . '.netlify.app'))];
}

/** Files go up by content too: Netlify answers which it lacks. */
function nibwp_static_send_netlify(array &$run, array $s, float $until)
{
    $token = nibwp_static_unseal($s['netlify']['token']);
    $site = nibwp_static_live_dir() . '/site';
    $sendFile = nibwp_static_live_dir() . '/send.json';
    $st = &$run['send'];
    if ($st['phase'] === '') {
        $st['phase'] = 'digest';
        $st['files'] = nibwp_static_files($site);
        $st['at'] = 0;
        nibwp_static_write_json($sendFile, []);
    }
    $have = nibwp_static_read_json($sendFile);
    if ($st['phase'] === 'digest') {
        while ($st['at'] < count($st['files']) && microtime(true) < $until) {
            $rel = $st['files'][$st['at']++];
            $have['/' . $rel] = (string) sha1_file($site . '/' . $rel);
        }
        nibwp_static_write_json($sendFile, $have);
        if ($st['at'] < count($st['files'])) {
            return false;
        }
        $d = nibwp_static_nl('POST', '/sites/' . rawurlencode($s['netlify']['id']) . '/deploys', $token, ['files' => (object) $have]);
        if (is_wp_error($d)) {
            return $d;
        }
        $st['deploy'] = (string) ($d['id'] ?? '');
        $st['missing'] = array_values((array) ($d['required'] ?? []));
        $st['need'] = count($st['missing']);
        $st['sent'] = 0;
        $st['phase'] = 'upload';
    }
    if ($st['phase'] === 'upload') {
        $byHash = array_flip($have);
        while ($st['missing'] && microtime(true) < $until) {
            $h = array_shift($st['missing']);
            $rel = ltrim((string) ($byHash[$h] ?? ''), '/');
            if ($rel === '') {
                continue;
            }
            $put = nibwp_static_nl('PUT', '/deploys/' . rawurlencode($st['deploy']) . '/files/' . implode('/', array_map('rawurlencode', explode('/', $rel))), $token, (string) file_get_contents($site . '/' . $rel), 'application/octet-stream'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of our build
            if (is_wp_error($put)) {
                return $put;
            }
            $st['sent']++;
        }
        if ($st['missing']) {
            return false;
        }
        $st['phase'] = 'wait';
    }
    if ($st['phase'] === 'wait') {
        while (microtime(true) < $until) {
            $d = nibwp_static_nl('GET', '/deploys/' . rawurlencode($st['deploy']), $token);
            if (is_wp_error($d)) {
                return $d;
            }
            if (($d['state'] ?? '') === 'ready') {
                wp_delete_file($sendFile);
                return true;
            }
            if (($d['state'] ?? '') === 'error') {
                return new WP_Error('nibwp_nl', sprintf(__('Netlify said: %s', 'nibwp'), (string) ($d['error_message'] ?? 'error')));
            }
            sleep(2);
        }
    }

    return false;
}

/* SFTP, FTPS, FTP: any host ------------------------------------------ */

/** The folder on the server as a URL, and as the path its own commands take. */
function nibwp_static_ftp_where(array $f): array
{
    $proto = in_array($f['proto'], ['sftp', 'ftps', 'ftp'], true) ? $f['proto'] : 'sftp';
    $port = (int) $f['port'] ?: ($proto === 'sftp' ? 22 : 21);
    $path = trim((string) $f['path']);
    $abs = $path !== '' && $path[0] === '/';
    $path = trim($path, '/');
    $segs = $path === '' ? [] : array_map('rawurlencode', explode('/', $path));
    if ($proto === 'sftp') {
        $urlPath = $abs ? '/' . implode('/', $segs) : '/~/' . implode('/', $segs);
    } else {
        $urlPath = $abs ? '/%2F' . implode('/', $segs) : '/' . implode('/', $segs);
    }

    return [
        'url' => ($proto === 'sftp' ? 'sftp' : 'ftp') . '://' . $f['host'] . ':' . $port . rtrim($urlPath, '/') . '/',
        'root' => ($abs ? '/' : '') . $path,
        'proto' => $proto,
    ];
}

/** One curl handle, kept between files so the connection is reused. */
function nibwp_static_ftp_curl(array $f, string $pass)
{
    $ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- FTP and SFTP are outside the WordPress HTTP API
    curl_setopt_array($ch, [ // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
        CURLOPT_USERPWD => $f['user'] . ':' . $pass,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
    ]);
    if ($f['proto'] === 'ftps') {
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
    }

    return $ch;
}

/** @return true|string true, or the error. */
function nibwp_static_ftp_put($ch, string $url, string $file)
{
    $fh = fopen($file, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a build file to the host
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => (int) filesize($file)]); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
    $ok = curl_exec($ch) !== false; // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
    fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

    return $ok ?: curl_error($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
}

/** Remove files (best effort: one that is already gone is fine). */
function nibwp_static_ftp_delete(array $f, string $pass, array $where, array $rels): void
{
    $cmd = $where['proto'] === 'sftp' ? 'rm ' : 'DELE ';
    $root = $where['root'] === '' ? '' : rtrim($where['root'], '/') . '/';
    $ch = nibwp_static_ftp_curl($f, $pass);
    curl_setopt_array($ch, [CURLOPT_URL => $where['url'], CURLOPT_NOBODY => true, CURLOPT_QUOTE => array_map(static fn($r) => '*' . $cmd . $root . $r, $rels)]); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
    curl_exec($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
    curl_close($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
}

/** Connect: a test file goes up and is removed again. */
function nibwp_static_ftp_connect(array $f, string $pass)
{
    if (!function_exists('curl_init')) {
        return new WP_Error('nibwp_ftp', __('This server has no PHP curl, which sending over FTP needs.', 'nibwp'));
    }
    if ($f['host'] === '' || $f['user'] === '' || $pass === '') {
        return new WP_Error('nibwp_ftp', __('Fill in the server, user and password.', 'nibwp'));
    }
    $where = nibwp_static_ftp_where($f);
    if ($where['proto'] === 'sftp' && !in_array('sftp', (array) (curl_version()['protocols'] ?? []), true)) {
        return new WP_Error('nibwp_ftp', __('This server’s curl cannot speak SFTP. Use FTPS instead.', 'nibwp'));
    }
    $tmp = wp_tempnam('nibwp-check');
    file_put_contents($tmp, 'NibWP connection check'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file
    $ch = nibwp_static_ftp_curl($f, $pass);
    $ok = nibwp_static_ftp_put($ch, $where['url'] . '.nibwp-check', $tmp);
    curl_close($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
    if ($ok === true) {
        nibwp_static_ftp_delete($f, $pass, $where, ['.nibwp-check']);
    }
    wp_delete_file($tmp);
    if ($ok !== true) {
        /* translators: %s: the connection error */
        return new WP_Error('nibwp_ftp', sprintf(__('Could not write to the folder: %s', 'nibwp'), $ok));
    }

    return ['ok' => true];
}

/** Only files that changed since the last send travel; removed ones are deleted. */
function nibwp_static_send_ftp(array &$run, array $s, float $until)
{
    $f = $s['ftp'];
    $where = nibwp_static_ftp_where($f);
    $live = nibwp_static_live_dir();
    $site = $live . '/site';
    $sentFile = $live . '/ftp.json';
    $st = &$run['send'];
    $sent = nibwp_static_read_json($sentFile);
    if (($sent['for'] ?? '') !== $where['url'] . '|' . $f['user']) {
        $sent = ['for' => $where['url'] . '|' . $f['user'], 'files' => []];
    }
    if ($st['phase'] === '') {
        $files = nibwp_static_files($site);
        $st['todo'] = [];
        foreach ($files as $rel) {
            if (($sent['files'][$rel] ?? '') !== md5_file($site . '/' . $rel)) {
                $st['todo'][] = $rel;
            }
        }
        $st['gone'] = array_values(array_diff(array_keys($sent['files']), $files));
        $st['need'] = count($st['todo']);
        $st['sent'] = 0;
        $st['phase'] = 'upload';
    }
    $ch = nibwp_static_ftp_curl($f, nibwp_static_unseal($f['pass']));
    while ($st['todo'] && microtime(true) < $until) {
        $rel = $st['todo'][0];
        $ok = nibwp_static_ftp_put($ch, $where['url'] . implode('/', array_map('rawurlencode', explode('/', $rel))), $site . '/' . $rel);
        if ($ok !== true) {
            curl_close($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
            nibwp_static_write_json($sentFile, $sent);
            /* translators: 1: a file, 2: the error */
            return new WP_Error('nibwp_ftp', sprintf(__('Could not send %1$s: %2$s', 'nibwp'), $rel, $ok));
        }
        array_shift($st['todo']);
        $sent['files'][$rel] = md5_file($site . '/' . $rel);
        $st['sent']++;
    }
    if (!$st['todo'] && $st['gone']) {
        foreach (array_chunk($st['gone'], 50) as $chunk) {
            nibwp_static_ftp_delete($f, nibwp_static_unseal($f['pass']), $where, $chunk);
            foreach ($chunk as $rel) {
                unset($sent['files'][$rel]);
            }
        }
        $st['gone'] = [];
    }
    curl_close($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
    nibwp_static_write_json($sentFile, $sent);

    return !$st['todo'];
}

/* ------------------------------------------------------------------
 * REST for the Agent View window.
 * ---------------------------------------------------------------- */

function nibwp_static_payload(): array
{
    $s = nibwp_static_settings();
    $d = nibwp_static_dirty();
    $built = get_option('nibwp_static_live');
    $curl = function_exists('curl_version') ? (array) (curl_version()['protocols'] ?? []) : [];

    return [
        'settings' => nibwp_static_public_settings($s),
        'live' => nibwp_static_live_url($s),
        'built' => is_array($built) ? $built : null,
        'dirty' => ['n' => nibwp_static_dirty_count($d), 'all' => (bool) $d['all'], 'what' => $d['what'], 'at' => (int) $d['at']],
        'run' => nibwp_static_run_status(nibwp_static_run()),
        'history' => array_slice((array) get_option('nibwp_static_history', []), 0, 8),
        'can' => ['sftp' => in_array('sftp', $curl, true), 'ftp' => in_array('ftp', $curl, true), 'cloudflare' => PHP_INT_SIZE >= 8],
        'host' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
        'now' => time(),
    ];
}

add_action('rest_api_init', static function (): void {
    $can = static fn(): bool => current_user_can('manage_options');
    register_rest_route('nibwp/v1', '/studio/publish', ['methods' => 'GET', 'permission_callback' => $can, 'callback' => static fn() => new WP_REST_Response(nibwp_static_payload())]);
    register_rest_route('nibwp/v1', '/studio/publish/settings', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_settings']);
    register_rest_route('nibwp/v1', '/studio/publish/connect', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_static_rest_connect']);
    register_rest_route('nibwp/v1', '/studio/publish/start', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => static function (WP_REST_Request $r) {
        $st = nibwp_static_start($r->get_param('mode') === 'full' ? 'full' : 'changes', 'you');

        return is_wp_error($st) ? new WP_REST_Response(['message' => $st->get_error_message()], 400) : new WP_REST_Response($st);
    }]);
    register_rest_route('nibwp/v1', '/studio/publish/step', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => static function () {
        $ran = nibwp_static_step(NIBWP_STATIC_STEP_SECONDS);

        return new WP_REST_Response(nibwp_static_run_status(nibwp_static_run()) + ['busy' => !$ran]);
    }]);
    register_rest_route('nibwp/v1', '/studio/publish/stop', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => static function () {
        nibwp_static_stop();

        return new WP_REST_Response(nibwp_static_payload());
    }]);
});

/** Plain settings: what goes in, auto publish, the person's own domain, or the zip-only choice. */
function nibwp_static_rest_settings(WP_REST_Request $r): WP_REST_Response
{
    $s = nibwp_static_settings();
    if ($r->has_param('opts')) {
        $s['opts'] = array_intersect_key(nibwp_static_clean_opts((array) $r->get_param('opts')), $s['opts']);
    }
    if ($r->has_param('auto')) {
        $s['auto'] = (bool) $r->get_param('auto');
        if (!$s['auto']) {
            wp_clear_scheduled_hook('nibwp_static_auto');
        }
    }
    if ($r->has_param('domain')) {
        $s['domain'] = nibwp_static_url((string) $r->get_param('domain'));
    }
    if ($r->get_param('target') === 'zip') {
        $s['target'] = '';
    }
    nibwp_static_save_settings($s);

    return new WP_REST_Response(nibwp_static_payload());
}

/** Save a host's details and check them; the host's own address becomes the live one. */
function nibwp_static_rest_connect(WP_REST_Request $r): WP_REST_Response
{
    $s = nibwp_static_settings();
    $target = (string) $r->get_param('target');
    $in = (array) $r->get_param($target);
    $secret = static fn(string $key, string $old): string => trim((string) ($in[$key] ?? '')) !== '' ? nibwp_static_seal(trim((string) $in[$key])) : $old;

    if ($target === 'cloudflare') {
        $s['cloudflare']['token'] = $secret('token', $s['cloudflare']['token']);
        $res = nibwp_static_cf_connect(nibwp_static_unseal($s['cloudflare']['token']), sanitize_text_field((string) ($in['account'] ?? '')), sanitize_text_field((string) ($in['project'] ?? '')));
        if (is_array($res) && isset($res['accounts'])) {
            nibwp_static_save_settings($s);

            return new WP_REST_Response(['accounts' => $res['accounts']] + nibwp_static_payload());
        }
        if (!is_wp_error($res)) {
            $s['cloudflare'] = array_merge($s['cloudflare'], array_intersect_key($res, ['account' => 1, 'project' => 1, 'branch' => 1]));
        }
    } elseif ($target === 'netlify') {
        $s['netlify']['token'] = $secret('token', $s['netlify']['token']);
        $res = nibwp_static_nl_connect(nibwp_static_unseal($s['netlify']['token']), sanitize_text_field((string) ($in['site'] ?? '')));
        if (!is_wp_error($res)) {
            $s['netlify'] = array_merge($s['netlify'], ['site' => $res['site'], 'id' => $res['id']]);
        }
    } elseif ($target === 'ftp') {
        $s['ftp'] = [
            'proto' => in_array($in['proto'] ?? '', ['sftp', 'ftps', 'ftp'], true) ? $in['proto'] : 'sftp',
            'host' => preg_replace('#^[a-z]+://|[/:].*$#i', '', sanitize_text_field((string) ($in['host'] ?? ''))),
            'port' => max(0, min(65535, (int) ($in['port'] ?? 0))),
            'user' => sanitize_text_field((string) ($in['user'] ?? '')),
            'pass' => $secret('pass', $s['ftp']['pass']),
            'path' => sanitize_text_field((string) ($in['path'] ?? '')),
        ];
        $res = nibwp_static_ftp_connect($s['ftp'], nibwp_static_unseal($s['ftp']['pass']));
        $d = nibwp_static_url((string) $r->get_param('domain'));
        if (!is_wp_error($res)) {
            if ($d === '') {
                $res = new WP_Error('nibwp_ftp', __('Connected. Now write the address the site will have, like https://example.com', 'nibwp'));
            } else {
                $res['live'] = $d;
            }
        }
    } else {
        return new WP_REST_Response(['message' => __('Choose where the site goes.', 'nibwp')], 400);
    }

    if (is_wp_error($res)) {
        nibwp_static_save_settings($s);

        return new WP_REST_Response(['message' => $res->get_error_message()] + nibwp_static_payload(), 400);
    }
    $s['target'] = $target;
    $s['live'] = (string) $res['live'];
    if ($target === 'ftp') {
        $s['domain'] = '';
    }
    nibwp_static_save_settings($s);

    return new WP_REST_Response(['connected' => true] + nibwp_static_payload());
}

/* ------------------------------------------------------------------
 * Abilities: NibWP can publish and report, when asked.
 * ---------------------------------------------------------------- */

function nibwp_static_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    wp_register_ability('nibwp/static-site-status', [
        'label' => __('Static site status', 'nibwp'),
        'description' => 'Where the static copy of this site is published (Cloudflare Pages, Netlify or a host over SFTP/FTP), its live address, when it was last published, how many changes are waiting to go live, whether auto publish is on, and the publish running now (with its progress and any problem). Read this before publishing, and to report on a publish you started.',
        'category' => 'visual',
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'execute_callback' => static function (): array {
            $p = nibwp_static_payload();
            unset($p['can'], $p['now']);

            return $p;
        },
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);
    wp_register_ability('nibwp/static-site-publish', [
        'label' => __('Publish the static site', 'nibwp'),
        'description' => 'Builds the static copy of the site and sends it to its host, making the current content live. By default only pages that changed are built again (a full build happens by itself when design, menus or settings changed); everything: true builds every page. It runs in the background: it returns at once with the run, then check nibwp/static-site-status. Needs a host chosen first in Agent View (Export button); if none is set, tell the person to set it up there.',
        'category' => 'visual',
        'input_schema' => ['type' => 'object', 'properties' => ['everything' => ['type' => 'boolean', 'description' => 'Build every page, not only what changed.']]],
        'execute_callback' => static function (array $input = []) {
            return nibwp_static_start(!empty($input['everything']) ? 'full' : 'changes', 'agent');
        },
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);
}
