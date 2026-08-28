<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEO Pro — shared ability bootstrap.
 *
 * Loaded first (it is the first entry in the manifest ability_files list). Pulls
 * in the libs and provides the common license-gate + preflight-token guard every
 * skill ability uses, plus the JSON-LD injector that renders schema the skill
 * persists.
 */

require_once __DIR__ . '/../lib/engine.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scorer.php';
require_once __DIR__ . '/../lib/persister.php';

/** House WP_Error wrapper. */
function nibwp_seo_pro_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/**
 * Gate + (optionally) consume the preflight token. Read-only abilities pass
 * $require_token=false. Destructive abilities pass true; on a successful COMMIT
 * the caller clears the token via nibwp_seo_pro_clear_token().
 *
 * @return array{answers:array<string,mixed>,token:string}|WP_Error
 */
function nibwp_seo_pro_guard(array $input, bool $require_token = true): array|WP_Error
{
    $gate = nibwp_skill_gate('seo-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    if (!$require_token) {
        return ['answers' => [], 'token' => ''];
    }
    $token = (string) ($input['_preflight_token'] ?? '');
    $answers = nibwp_skill_preflight_consume_token($token, 'seo-pro');
    if (is_wp_error($answers)) {
        return $answers;
    }
    nibwp_skill_preflight_bump_attempts($token);
    return ['answers' => is_array($answers) ? $answers : [], 'token' => $token];
}

/** Clear a preflight token after a successful commit. */
function nibwp_seo_pro_clear_token(string $token): void
{
    if ($token !== '' && function_exists('nibwp_skill_preflight_clear_token')) {
        nibwp_skill_preflight_clear_token($token);
    }
}

/** Is the given post the site front page? */
function nibwp_seo_pro_is_front(int $post_id): bool
{
    return $post_id > 0 && (
        (int) get_option('page_on_front') === $post_id
        || (int) get_option('page_for_posts') === $post_id
    );
}

/** Standard skill meta block for an ability registration. */
function nibwp_seo_pro_ability_meta(bool $readonly, bool $destructive, string $instructions = ''): array
{
    return [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => array_filter([
            'readonly' => $readonly,
            'destructive' => $destructive,
            'idempotent' => false,
            'instructions' => $instructions !== '' ? $instructions : null,
        ], static fn ($v) => $v !== null),
    ];
}

/**
 * Shared dry-run/commit applier for the bulk meta-writing abilities (meta, fix).
 * On dry_run it validates each item and returns the verdict; on commit it
 * persists through the validate-gated persister. $allowed limits which
 * normalized fields the ability may touch.
 *
 * @param array<string,mixed> $input
 * @param array<string,mixed> $answers preflight answers
 * @param array<int,string>   $allowed normalized field allowlist
 * @return array{dry_run:bool,all_ok:bool,results:array,count:int}|WP_Error
 */
function nibwp_seo_pro_apply_items(array $input, array $answers, array $allowed): array|WP_Error
{
    $items = (array) ($input['items'] ?? []);
    if ($items === []) {
        return nibwp_seo_pro_err('no_items', 'Provide a non-empty "items" array (each with an id + fields).');
    }
    $dry  = !empty($input['dry_run']);
    $opts = nibwp_seo_pro_opts($answers);
    $index = nibwp_seo_pro_title_index($opts['post_types']);
    $results = [];

    foreach ($items as $it) {
        $it  = (array) $it;
        $pid = (int) ($it['id'] ?? $it['post_id'] ?? 0);
        if (!$pid || !get_post($pid)) {
            $results[] = ['post_id' => $pid, 'error' => 'not found'];
            continue;
        }
        $fields = array_intersect_key($it, array_flip($allowed));
        $ctx = [
            'title_max' => $opts['title_max'], 'title_min' => $opts['title_min'],
            'desc_max'  => $opts['desc_max'], 'desc_min' => $opts['desc_min'],
            'post_id'   => $pid, 'is_front' => nibwp_seo_pro_is_front($pid),
            'existing_titles' => $index,
        ];
        if ($dry) {
            $v = nibwp_seo_pro_validate($fields, $ctx);
            $results[] = ['post_id' => $pid, 'passed' => $v['passed'], 'failed' => $v['failed'], 'warnings' => $v['warnings'], 'preview' => $fields];
        } else {
            $p = nibwp_seo_pro_persist($pid, $fields, $ctx);
            if (is_wp_error($p)) {
                $data = $p->get_error_data();
                $results[] = ['post_id' => $pid, 'persisted' => false, 'error' => $p->get_error_message(), 'failed' => $data['failed'] ?? []];
            } else {
                $results[] = ['post_id' => $pid, 'persisted' => true, 'changed' => $p['changed'], 'warnings' => $p['warnings'] ?? []];
            }
        }
    }

    $all_ok = true;
    foreach ($results as $r) {
        if (isset($r['passed']) && !$r['passed']) { $all_ok = false; }
        if (isset($r['persisted']) && !$r['persisted']) { $all_ok = false; }
        if (isset($r['error'])) { $all_ok = false; }
    }
    return ['dry_run' => $dry, 'all_ok' => $all_ok, 'results' => $results, 'count' => count($results)];
}

/* ----------------------------------------------------------------------------
 * JSON-LD injector — renders schema the skill persisted to _nibwp_seo_pro_schema
 * so structured data works on every engine (including sites with no SEO plugin
 * schema module). Engine-native schema is left untouched.
 * ------------------------------------------------------------------------- */

add_action('wp_head', 'nibwp_seo_pro_render_schema', 20);

function nibwp_seo_pro_render_schema(): void
{
    if (!is_singular()) {
        return;
    }
    $id = get_queried_object_id();
    if (!$id) {
        return;
    }
    $schema = get_post_meta($id, '_nibwp_seo_pro_schema', true);
    if ($schema === '' || $schema === null) {
        return;
    }
    if (is_array($schema)) {
        $schema = wp_json_encode($schema);
    }
    if (!is_string($schema) || $schema === '') {
        return;
    }
    // Validate it still decodes before printing.
    if (json_decode($schema) === null) {
        return;
    }
    echo "\n<script type=\"application/ld+json\">" . $schema . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD validated above.
}
