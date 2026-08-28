<?php

declare(strict_types=1);

/**
 * Builderius read model — resolve templates, the version graph (branch → commit
 * → release), components, fragments, global settings, form submissions, and
 * starters straight from Builderius's own custom post types.
 *
 * Hierarchy (verified from source, linked by post_parent):
 *   builderius_template  (owner)
 *     └─ builderius_branch   post_parent = template ID   (meta: active_commit, base_branch, …)
 *          └─ builderius_commit  post_parent = branch ID  (config in post_content JSON + `content_config` meta)
 *   builderius_release   — a published commit
 *   builderius_component, builderius_saved_fr, builderius_saved_cm,
 *   builderius_sett_set, builderius_form_subm, builderius_starter
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_BLDR_CPT_TEMPLATE   = 'builderius_template';
const NIBWP_BLDR_CPT_BRANCH     = 'builderius_branch';
const NIBWP_BLDR_CPT_COMMIT     = 'builderius_commit';
const NIBWP_BLDR_CPT_RELEASE    = 'builderius_release';
const NIBWP_BLDR_CPT_COMPONENT  = 'builderius_component';
const NIBWP_BLDR_CPT_FRAGMENT   = 'builderius_saved_fr';
const NIBWP_BLDR_CPT_COMPOSITE  = 'builderius_saved_cm';
const NIBWP_BLDR_CPT_SETTINGS   = 'builderius_sett_set';
const NIBWP_BLDR_CPT_FORM_SUBM  = 'builderius_form_subm';
const NIBWP_BLDR_CPT_STARTER    = 'builderius_starter';

const NIBWP_BLDR_TAX_TYPE       = 'builderius_template_type';
const NIBWP_BLDR_TAX_SUBTYPE    = 'builderius_template_subtype';

/** Is Builderius installed/active on this site? */
function nibwp_builderius_active(): bool
{
    return function_exists('builderius_check_requirements')
        || post_type_exists(NIBWP_BLDR_CPT_TEMPLATE);
}

/** Guard used by every ability callback. */
function nibwp_builderius_guard(): ?WP_Error
{
    if (!nibwp_builderius_active()) {
        return new WP_Error(
            'no_builderius',
            __('Builderius is not active on this site. Install/activate Builderius (wordpress.org/plugins/builderius) first.', domain: 'nibwp')
        );
    }
    return null;
}

/**
 * Decode a commit post's config ({modules:{…}}). Prefers post_content (the
 * canonical store Builderius reads back), falls back to the content_config meta.
 *
 * @return array<string,mixed>
 */
function nibwp_builderius_commit_config(WP_Post $commit): array
{
    $raw = (string) $commit->post_content;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $meta = get_post_meta($commit->ID, 'content_config', true);
    if (is_string($meta) && $meta !== '') {
        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    if (is_array($meta)) {
        return $meta;
    }
    return [];
}

/**
 * The branch post(s) for a template (post_parent = template ID).
 *
 * @return WP_Post[]
 */
function nibwp_builderius_branches_for_template(int $template_id): array
{
    return get_posts([
        'post_type'        => NIBWP_BLDR_CPT_BRANCH,
        'post_parent'      => $template_id,
        'post_status'      => 'any',
        'posts_per_page'   => -1,
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ]);
}

/**
 * The commit post(s) on a branch (post_parent = branch ID).
 *
 * @return WP_Post[]
 */
function nibwp_builderius_commits_for_branch(int $branch_id): array
{
    return get_posts([
        'post_type'        => NIBWP_BLDR_CPT_COMMIT,
        'post_parent'      => $branch_id,
        'post_status'      => 'any',
        'posts_per_page'   => -1,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => true,
    ]);
}

/**
 * Resolve a template's active branch + its head/active commit + config.
 *
 * @return array{branch: ?WP_Post, commit: ?WP_Post, config: array<string,mixed>}
 */
function nibwp_builderius_resolve_template(int $template_id): array
{
    $branches = nibwp_builderius_branches_for_template($template_id);
    $branch = $branches[0] ?? null;
    if (!$branch) {
        return ['branch' => null, 'commit' => null, 'config' => []];
    }
    // Prefer the branch's declared active commit; else newest commit.
    $active_name = (string) get_post_meta($branch->ID, 'active_commit', true);
    $commit = null;
    $commits = nibwp_builderius_commits_for_branch((int) $branch->ID);
    if ($active_name !== '') {
        foreach ($commits as $c) {
            if ($c->post_name === $active_name) {
                $commit = $c;
                break;
            }
        }
    }
    $commit ??= $commits[0] ?? null;

    return [
        'branch' => $branch,
        'commit' => $commit,
        'config' => $commit ? nibwp_builderius_commit_config($commit) : [],
    ];
}

/**
 * List all Builderius templates with type/subtype + a version summary.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_list_templates(): array
{
    $posts = get_posts([
        'post_type'        => NIBWP_BLDR_CPT_TEMPLATE,
        'post_status'      => 'any',
        'posts_per_page'   => -1,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => true,
    ]);

    $out = [];
    foreach ($posts as $p) {
        $branches = nibwp_builderius_branches_for_template((int) $p->ID);
        $resolved = nibwp_builderius_resolve_template((int) $p->ID);
        $out[] = [
            'id'            => (int) $p->ID,
            'title'         => $p->post_title,
            'slug'          => $p->post_name,
            'status'        => $p->post_status,
            'type'          => nibwp_builderius_term_slugs($p->ID, NIBWP_BLDR_TAX_TYPE),
            'subtype'       => nibwp_builderius_term_slugs($p->ID, NIBWP_BLDR_TAX_SUBTYPE),
            'branches'      => count($branches),
            'active_commit' => $resolved['commit']?->post_name,
            'module_count'  => isset($resolved['config']['modules']) && is_array($resolved['config']['modules'])
                ? count($resolved['config']['modules'])
                : 0,
            'edit_url'      => admin_url('post.php?post=' . $p->ID . '&action=edit'),
        ];
    }
    return $out;
}

/**
 * Term slugs for a post on a taxonomy (empty array if none / taxonomy absent).
 *
 * @return array<int,string>
 */
function nibwp_builderius_term_slugs(int $post_id, string $taxonomy): array
{
    if (!taxonomy_exists($taxonomy)) {
        return [];
    }
    $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
    return is_wp_error($terms) ? [] : array_values($terms);
}

/**
 * The full version graph for one template: branches, each with its commits.
 *
 * @return array<string,mixed>
 */
function nibwp_builderius_list_versions(int $template_id): array
{
    $branches = [];
    foreach (nibwp_builderius_branches_for_template($template_id) as $b) {
        $active = (string) get_post_meta($b->ID, 'active_commit', true);
        $commits = [];
        foreach (nibwp_builderius_commits_for_branch((int) $b->ID) as $c) {
            $commits[] = [
                'id'          => (int) $c->ID,
                'name'        => $c->post_name,
                'title'       => $c->post_title,
                'description' => (string) get_post_meta($c->ID, 'description', true),
                'created_at'  => $c->post_date_gmt,
                'is_active'   => $c->post_name === $active,
                'autopublished' => (bool) get_post_meta($c->ID, 'autopublished', true),
            ];
        }
        $branches[] = [
            'id'            => (int) $b->ID,
            'name'          => $b->post_name,
            'title'         => $b->post_title,
            'base_branch'   => (string) get_post_meta($b->ID, 'base_branch', true),
            'base_commit'   => (string) get_post_meta($b->ID, 'base_commit', true),
            'active_commit' => $active,
            'commit_count'  => count($commits),
            'commits'       => $commits,
        ];
    }
    return ['template_id' => $template_id, 'branches' => $branches];
}

/**
 * Generic lister for the simpler Builderius CPTs.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_list_cpt(string $post_type, int $limit = 200): array
{
    $posts = get_posts([
        'post_type'        => $post_type,
        'post_status'      => 'any',
        'posts_per_page'   => $limit,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => true,
    ]);
    $out = [];
    foreach ($posts as $p) {
        $out[] = [
            'id'      => (int) $p->ID,
            'title'   => $p->post_title,
            'slug'    => $p->post_name,
            'status'  => $p->post_status,
            'parent'  => (int) $p->post_parent,
            'created' => $p->post_date_gmt,
        ];
    }
    return $out;
}
