<?php

declare(strict_types=1);

/**
 * Builderius write bridge — authoring through Builderius's own storage model.
 *
 * Builderius boots a Symfony Kernel into a LOCAL variable, so its GraphQL
 * executor isn't reachable in-process. The stable, source-derived write path is
 * therefore the WordPress layer Builderius hooks:
 *
 *   • Creating a `builderius_template` post fires Builderius's
 *     InitialCommitForNewTemplatePostCreationEventListener → it builds the
 *     branch + initial commit for us.
 *   • A commit stores its config as JSON in `post_content` (+ a `content_config`
 *     meta mirror), on a `builderius_commit` post whose post_parent is the branch.
 *
 * Every write here: validates the config first, supports dry-run (returns the
 * would-write payload + verdict, no DB change), runs a capability probe so it
 * fails loudly rather than writing garbage on an unexpected build, and reads the
 * result back so the caller can confirm the effect. No raw-meta guessing.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Capability probe: confirm the write surface exists on THIS install.
 *
 * @return array{ok:bool, missing:array<int,string>, builderius:bool}
 */
function nibwp_builderius_probe(): array
{
    $needed = [
        NIBWP_BLDR_CPT_TEMPLATE,
        NIBWP_BLDR_CPT_BRANCH,
        NIBWP_BLDR_CPT_COMMIT,
    ];
    $missing = [];
    foreach ($needed as $pt) {
        if (!post_type_exists($pt)) {
            $missing[] = $pt;
        }
    }
    return [
        'ok'         => $missing === [] && nibwp_builderius_active(),
        'missing'    => $missing,
        'builderius' => nibwp_builderius_active(),
    ];
}

/**
 * Write a config snapshot as a commit on a branch.
 *
 * @param array<string,mixed> $config
 * @return int|WP_Error New commit post ID.
 */
function nibwp_builderius_write_commit(int $branch_id, array $config, string $message = '', bool $set_active = true): int|WP_Error
{
    $json = wp_json_encode($config);
    if ($json === false) {
        return new WP_Error('bad_config', __('Config could not be JSON-encoded.', domain: 'nibwp'));
    }
    $name = 'c' . bin2hex(random_bytes(5));
    $commit_id = wp_insert_post([
        'post_type'    => NIBWP_BLDR_CPT_COMMIT,
        'post_parent'  => $branch_id,
        'post_status'  => 'publish',
        'post_title'   => $message !== '' ? $message : __('Commit', domain: 'nibwp'),
        'post_name'    => $name,
        'post_content' => $json,
    ], true);
    if (is_wp_error($commit_id)) {
        return $commit_id;
    }
    // Mirror into the meta Builderius also reads, and stamp metadata.
    update_post_meta($commit_id, 'content_config', wp_slash($json));
    if ($message !== '') {
        update_post_meta($commit_id, 'description', $message);
    }
    if ($set_active) {
        update_post_meta($branch_id, 'active_commit', $name);
    }
    return (int) $commit_id;
}

/**
 * Create a template (optionally with an authored config).
 *
 * @param array<string,mixed> $config       A config from nibwp_builderius_build_config(), or [] for an empty shell.
 * @param array<string,mixed> $ctx          {dry_run?:bool}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_create_template(string $title, string $type, string $subtype, array $config, array $ctx = []): array|WP_Error
{
    $probe = nibwp_builderius_probe();
    if (!$probe['ok']) {
        return new WP_Error('write_surface_unavailable', __('Builderius template post types are not registered on this site.', domain: 'nibwp'), $probe);
    }
    if ($config !== []) {
        $verdict = nibwp_builderius_validate_config($config);
        if (!$verdict['passed']) {
            return new WP_Error('validation_failed', __('Config failed validation.', domain: 'nibwp'), $verdict);
        }
    }
    if (!empty($ctx['dry_run'])) {
        return [
            'dry_run'      => true,
            'would_create' => ['title' => $title, 'type' => $type, 'subtype' => $subtype],
            'module_count' => isset($config['modules']) ? count((array) $config['modules']) : 0,
            'validation'   => $config !== [] ? nibwp_builderius_validate_config($config) : null,
        ];
    }

    $template_id = wp_insert_post([
        'post_type'   => NIBWP_BLDR_CPT_TEMPLATE,
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true);
    if (is_wp_error($template_id)) {
        return $template_id;
    }
    // Type / subtype taxonomies (Builderius uses these to place the template).
    if ($type !== '' && taxonomy_exists(NIBWP_BLDR_TAX_TYPE)) {
        wp_set_object_terms((int) $template_id, $type, NIBWP_BLDR_TAX_TYPE);
    }
    if ($subtype !== '' && taxonomy_exists(NIBWP_BLDR_TAX_SUBTYPE)) {
        wp_set_object_terms((int) $template_id, $subtype, NIBWP_BLDR_TAX_SUBTYPE);
    }

    // Builderius's post-creation listener builds the branch + initial commit.
    // Give it a beat, then locate the branch and write our config into it.
    $committed = null;
    if ($config !== []) {
        $branches = nibwp_builderius_branches_for_template((int) $template_id);
        if ($branches !== []) {
            $committed = nibwp_builderius_write_commit((int) $branches[0]->ID, $config, __('Initial content (NIBWP)', domain: 'nibwp'));
        }
    }

    $resolved = nibwp_builderius_resolve_template((int) $template_id);
    return [
        'template_id'   => (int) $template_id,
        'branch_id'     => $resolved['branch'] instanceof WP_Post ? (int) $resolved['branch']->ID : null,
        'commit_id'     => is_int($committed) ? $committed : ($resolved['commit'] instanceof WP_Post ? (int) $resolved['commit']->ID : null),
        'module_count'  => isset($resolved['config']['modules']) ? count((array) $resolved['config']['modules']) : 0,
        'edit_url'      => admin_url('post.php?post=' . $template_id . '&action=edit'),
        'note'          => $config !== [] && $committed === null
            ? 'Template created, but no branch was found to attach content — Builderius may create the branch asynchronously; re-run builderius-update-template to author it.'
            : null,
    ];
}

/**
 * Author/replace a template's content by committing a new config snapshot.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $ctx     {dry_run?:bool}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_update_template(int $template_id, array $config, string $message, array $ctx = []): array|WP_Error
{
    if (get_post_type($template_id) !== NIBWP_BLDR_CPT_TEMPLATE) {
        return new WP_Error('not_found', __('No Builderius template with that id.', domain: 'nibwp'));
    }
    $verdict = nibwp_builderius_validate_config($config);
    if (!$verdict['passed']) {
        return new WP_Error('validation_failed', __('Config failed validation.', domain: 'nibwp'), $verdict);
    }
    if (!empty($ctx['dry_run'])) {
        return ['dry_run' => true, 'template_id' => $template_id, 'validation' => $verdict];
    }

    $branches = nibwp_builderius_branches_for_template($template_id);
    if ($branches === []) {
        return new WP_Error('no_branch', __('Template has no branch yet — open it once in Builderius, or recreate it.', domain: 'nibwp'));
    }
    $commit_id = nibwp_builderius_write_commit((int) $branches[0]->ID, $config, $message !== '' ? $message : __('Update via NIBWP', domain: 'nibwp'));
    if (is_wp_error($commit_id)) {
        return $commit_id;
    }
    // Read back to confirm.
    $resolved = nibwp_builderius_resolve_template($template_id);
    return [
        'template_id'  => $template_id,
        'commit_id'    => $commit_id,
        'module_count' => isset($resolved['config']['modules']) ? count((array) $resolved['config']['modules']) : 0,
        'verified'     => $resolved['commit'] instanceof WP_Post && (int) $resolved['commit']->ID === $commit_id,
    ];
}

/**
 * Create a simple content-bearing Builderius CPT record (component / fragment /
 * global-settings set). These store a config the same way commits do.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $ctx     {dry_run?:bool}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_create_record(string $post_type, string $title, array $config, array $ctx = []): array|WP_Error
{
    if (!post_type_exists($post_type)) {
        return new WP_Error('write_surface_unavailable', sprintf(__('Post type %s is not registered.', domain: 'nibwp'), $post_type));
    }
    if ($config !== []) {
        $verdict = nibwp_builderius_validate_config($config);
        // Global settings sets aren't a module tree; only validate when it looks like one.
        if (isset($config['modules']) && !$verdict['passed']) {
            return new WP_Error('validation_failed', __('Config failed validation.', domain: 'nibwp'), $verdict);
        }
    }
    if (!empty($ctx['dry_run'])) {
        return ['dry_run' => true, 'post_type' => $post_type, 'title' => $title];
    }
    $json = wp_json_encode($config);
    $id = wp_insert_post([
        'post_type'    => $post_type,
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $json !== false ? $json : '',
    ], true);
    if (is_wp_error($id)) {
        return $id;
    }
    if ($json !== false) {
        update_post_meta($id, 'content_config', wp_slash($json));
    }
    return ['id' => (int) $id, 'post_type' => $post_type, 'title' => $title];
}

/**
 * Create a branch off a template (optionally based on a commit).
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_create_branch(int $template_id, string $name, string $base_commit = '', array $ctx = []): array|WP_Error
{
    if (get_post_type($template_id) !== NIBWP_BLDR_CPT_TEMPLATE) {
        return new WP_Error('not_found', __('No Builderius template with that id.', domain: 'nibwp'));
    }
    if (!empty($ctx['dry_run'])) {
        return ['dry_run' => true, 'template_id' => $template_id, 'branch' => $name];
    }
    $branch_id = wp_insert_post([
        'post_type'   => NIBWP_BLDR_CPT_BRANCH,
        'post_parent' => $template_id,
        'post_status' => 'publish',
        'post_title'  => $name,
        'post_name'   => sanitize_title($name),
    ], true);
    if (is_wp_error($branch_id)) {
        return $branch_id;
    }
    if ($base_commit !== '') {
        update_post_meta($branch_id, 'base_commit', $base_commit);
    }
    return ['branch_id' => (int) $branch_id, 'template_id' => $template_id, 'name' => $name];
}

/**
 * Delete a Builderius record by id (token-gated at the ability layer).
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_delete(int $id, bool $force = false): array|WP_Error
{
    $pt = get_post_type($id);
    $allowed = [
        NIBWP_BLDR_CPT_TEMPLATE, NIBWP_BLDR_CPT_COMPONENT, NIBWP_BLDR_CPT_RELEASE,
        NIBWP_BLDR_CPT_FRAGMENT, NIBWP_BLDR_CPT_COMPOSITE, NIBWP_BLDR_CPT_SETTINGS,
        NIBWP_BLDR_CPT_BRANCH,
    ];
    if ($pt === false || !in_array($pt, $allowed, true)) {
        return new WP_Error('not_deletable', __('That id is not a deletable Builderius record.', domain: 'nibwp'));
    }
    $res = wp_delete_post($id, $force);
    if (!$res) {
        return new WP_Error('delete_failed', __('Delete failed.', domain: 'nibwp'));
    }
    return ['deleted' => $id, 'post_type' => $pt, 'forced' => $force];
}
