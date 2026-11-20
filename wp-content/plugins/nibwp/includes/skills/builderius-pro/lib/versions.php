<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builderius Pro — the version graph, and the one refusal that matters.
 *
 * Builderius does not save content. It commits it, and what a visitor sees is a
 * *release*: a frozen copy of a commit, stored separately from the commit. Every
 * fact below was read out of the Builderius source (v1.3.5-beta), because getting
 * this wrong is how published work disappears.
 *
 *   • A release is a `builderius_release` post, and it is PUBLISHED when its
 *     post_status is 'publish' or 'future'. The front end resolves exactly that
 *     set — ReleaseBundle/Provider/BuilderiusDeliverableReleaseProvider.php:25-30
 *     — the publish mutation refuses one already in it
 *     (ReleaseBundle/GraphQL/Resolver/BuilderiusRootMutationFieldPublishReleaseResolver.php:37)
 *     and the model collapses 'future' to 'publish'
 *     (ReleaseBundle/Factory/BuilderiusReleaseFromPostFactory.php:33).
 *
 *   • Exactly one release is published at a time: publishing one unpublishes
 *     every release that was published before it
 *     (BuilderiusRootMutationFieldPublishReleaseResolver.php:60-73).
 *
 *   • The version a human recognises is the release post_title — "1.0"
 *     (BuilderiusReleaseFromPostFactory.php:27).
 *
 *   • A release holds one `builderius_dsm` child per template, post_parent = the
 *     release, post_status 'draft', post_name = the template's slug, carrying a
 *     frozen copy of the commit config in post_content and in `content_config`
 *     meta (ReleaseBundle/EventListener/CommitAutopublishEventListener.php:279-313
 *     writes it; BuilderiusReleaseFromPostFactory.php:42-50 reads it back, draft
 *     status and all).
 *
 *   • Autopublishing a commit does not create a new release. It deletes the live
 *     release's frozen copy for that template, writes a new one in its place and
 *     republishes the same release post
 *     (CommitAutopublishEventListener.php:234-258). That is the overwrite this
 *     file exists to refuse: the release keeps its version number, so afterwards
 *     nothing on the site says the published 1.0 is no longer the 1.0 anybody
 *     reviewed.
 *
 *   • Which commit a release froze is recorded on the commit itself, meta
 *     `autopublished` (CommitAutopublishEventListener.php:52-68). The branch meta
 *     `autopublished_commit` carries the same answer but is only ever READ in the
 *     free plugin (VCSBundle/Factory/BuilderiusBranchFromPostFactory.php:108-109),
 *     so it is treated as a hint and never as the authority.
 *
 *   • Builderius computes a branch's active commit as the newest commit post by
 *     ID, ignoring the `active_commit` meta entirely
 *     (BuilderiusBranchFromPostFactory.php:88-106) — and that meta is a per-user
 *     JSON map, not a commit name
 *     (VCSBundle/GraphQL/Resolver/BuilderiusRootMutationFieldCreateCommitResolver.php:125-140).
 *     So any new commit becomes the branch head for everyone the moment it is
 *     written. There is no "draft commit" to hide work in.
 */

/** Post type names, resolved through the integration's constants where present. */
function nibwp_builderius_pro_cpt(string $which): string
{
    // Defined by the integration's lib/model.php. Falling back to the literal
    // slugs keeps inspection working when the skill is loaded without the
    // integration — the slugs are Builderius's own registered post types
    // (ReleaseBundle/Registration/BulderiusReleasePostType.php:9,
    // DeliverableBundle/Registration/BulderiusDeliverableSubModulePostType.php:9).
    $map = [
        'template' => defined('NIBWP_BLDR_CPT_TEMPLATE') ? NIBWP_BLDR_CPT_TEMPLATE : 'builderius_template',
        'branch'   => defined('NIBWP_BLDR_CPT_BRANCH') ? NIBWP_BLDR_CPT_BRANCH : 'builderius_branch',
        'commit'   => defined('NIBWP_BLDR_CPT_COMMIT') ? NIBWP_BLDR_CPT_COMMIT : 'builderius_commit',
        'release'  => defined('NIBWP_BLDR_CPT_RELEASE') ? NIBWP_BLDR_CPT_RELEASE : 'builderius_release',
        'dsm'      => 'builderius_dsm',
    ];

    return (string) ($map[$which] ?? '');
}

/**
 * The release that is live right now, or null.
 *
 * @return array{id:int,version:string,status:string}|null
 */
function nibwp_builderius_pro_published_release(): ?array
{
    if (!function_exists('get_posts')) {
        return null;
    }

    $posts = get_posts([
        'post_type'        => nibwp_builderius_pro_cpt('release'),
        // The exact status set the front end resolves. 'future' counts as
        // published: a scheduled release is already serving as far as the
        // provider is concerned.
        'post_status'      => ['publish', 'future'],
        'posts_per_page'   => 1,
        'orderby'          => 'ID',
        'order'            => 'DESC',
        'suppress_filters' => true,
    ]);

    $post = is_array($posts) ? ($posts[0] ?? null) : null;
    if (!$post) {
        return null;
    }

    return [
        'id'      => (int) $post->ID,
        'version' => (string) $post->post_title,
        'status'  => (string) $post->post_status,
    ];
}

/**
 * The frozen copy of one template inside a release, if the release carries one.
 *
 * Matched on the sub-module's post_name, which Builderius sets to the template's
 * own slug when it freezes the commit (CommitAutopublishEventListener.php:283).
 *
 * @return array{id:int,slug:string,module_count:int}|null
 */
function nibwp_builderius_pro_release_submodule(int $release_id, string $template_slug): ?array
{
    if ($template_slug === '' || !function_exists('get_posts')) {
        return null;
    }

    $posts = get_posts([
        'post_type'        => nibwp_builderius_pro_cpt('dsm'),
        'post_parent'      => $release_id,
        // Sub-modules of a release are stored as drafts — the release post holds
        // the status, the children never do.
        'post_status'      => ['draft', 'publish', 'any'],
        'name'             => $template_slug,
        'posts_per_page'   => 1,
        'suppress_filters' => true,
    ]);

    $post = is_array($posts) ? ($posts[0] ?? null) : null;
    if (!$post) {
        return null;
    }

    $config = [];
    $raw = (string) $post->post_content;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    return [
        'id'           => (int) $post->ID,
        'slug'         => (string) $post->post_name,
        'module_count' => is_array($config['modules'] ?? null) ? count($config['modules']) : 0,
    ];
}

/**
 * The commit a release froze, found on the commit itself.
 *
 * @return array{id:int,name:string}|null
 */
function nibwp_builderius_pro_published_commit(int $branch_id): ?array
{
    if ($branch_id <= 0 || !function_exists('get_posts')) {
        return null;
    }

    $posts = get_posts([
        'post_type'        => nibwp_builderius_pro_cpt('commit'),
        'post_parent'      => $branch_id,
        'post_status'      => 'any',
        'posts_per_page'   => 1,
        'orderby'          => 'ID',
        'order'            => 'DESC',
        'meta_key'         => 'autopublished',
        'meta_value'       => '1',
        'suppress_filters' => true,
    ]);

    $post = is_array($posts) ? ($posts[0] ?? null) : null;
    if (!$post) {
        return null;
    }

    return ['id' => (int) $post->ID, 'name' => (string) $post->post_name];
}

/**
 * Everything the caller needs to decide where a build may land.
 *
 * @return array<string,mixed>
 */
function nibwp_builderius_pro_release_state(int $template_id = 0): array
{
    $release = nibwp_builderius_pro_published_release();

    $state = [
        'published_release' => $release,
        'template_id'       => $template_id,
        'template_slug'     => '',
        'serves_template'   => null,
        'branch_id'         => null,
        'head_commit'       => '',
        'published_commit'  => null,
        'unreleased'        => false,
        'notes'             => [],
    ];

    if ($release === null) {
        $state['notes'][] = __('No release is published on this site, so nothing a visitor sees depends on a commit yet. Committing is safe; publishing a release is a separate decision the user makes in Builderius.', 'nibwp');
    }

    if ($template_id <= 0) {
        return $state;
    }

    $post = function_exists('get_post') ? get_post($template_id) : null;
    if (!$post || (string) $post->post_type !== nibwp_builderius_pro_cpt('template')) {
        $state['notes'][] = __('That id is not a Builderius template, so there is no version graph to read.', 'nibwp');

        return $state;
    }

    $state['template_slug'] = (string) $post->post_name;

    if (function_exists('nibwp_builderius_resolve_template')) {
        $resolved = nibwp_builderius_resolve_template($template_id);
        $branch = $resolved['branch'] ?? null;
        if (is_object($branch) && isset($branch->ID)) {
            $state['branch_id'] = (int) $branch->ID;
        }
        $commit = $resolved['commit'] ?? null;
        if (is_object($commit) && isset($commit->post_name)) {
            $state['head_commit'] = (string) $commit->post_name;
        }
    }

    if (is_int($state['branch_id'])) {
        $state['published_commit'] = nibwp_builderius_pro_published_commit($state['branch_id']);
    }

    if ($release !== null) {
        $state['serves_template'] = nibwp_builderius_pro_release_submodule((int) $release['id'], $state['template_slug']);
    }

    // "Unreleased" means the branch head is not the commit the live release
    // froze: somebody has committed work that is not on the site. Worth saying,
    // because it is the usual reason a template looks right in the editor and
    // wrong to a visitor.
    $published_name = is_array($state['published_commit']) ? (string) $state['published_commit']['name'] : '';
    if ($state['head_commit'] !== '' && $published_name !== '' && $state['head_commit'] !== $published_name) {
        $state['unreleased'] = true;
        $state['notes'][] = sprintf(
            /* translators: 1: branch head commit name, 2: published commit name */
            __('This template is carrying unreleased work: the branch head is %1$s but the published release still serves %2$s.', 'nibwp'),
            $state['head_commit'],
            $published_name
        );
    }
    if ($state['serves_template'] !== null && $release !== null) {
        $state['notes'][] = sprintf(
            /* translators: 1: release version, 2: release post ID */
            __('The published release %1$s (#%2$d) serves this template. A new version is safe; replacing the release is not.', 'nibwp'),
            (string) $release['version'],
            (int) $release['id']
        );
    }

    return $state;
}

/**
 * The version modes a build may ask for.
 *
 * @return array<int,string>
 */
function nibwp_builderius_pro_version_modes(): array
{
    return ['new_version', 'new_branch', 'overwrite'];
}

/**
 * May this build land where it wants to?
 *
 * `new_version` commits onto the template's branch, leaving the published
 * release exactly as it is — the normal answer. `new_branch` puts the work on a
 * branch of its own, which is the safe answer when the template is live and the
 * change needs reviewing. `overwrite` means "replace what the release serves for
 * this template", and when a release is published and serves it, that is refused
 * here rather than negotiated later: the release keeps its version number
 * through an autopublish, so afterwards nothing on the site records that the
 * published 1.0 is not the 1.0 anybody signed off.
 *
 * @return WP_Error|null  null when the build may proceed.
 */
function nibwp_builderius_pro_version_guard(int $template_id, string $version_mode)
{
    if (!in_array($version_mode, nibwp_builderius_pro_version_modes(), true)) {
        return new WP_Error(
            'nibwp_builderius_pro_bad_mode',
            sprintf(
                /* translators: 1: the mode given, 2: comma-separated list of valid modes */
                __('version_mode "%1$s" is not one of %2$s.', 'nibwp'),
                $version_mode,
                implode(', ', nibwp_builderius_pro_version_modes())
            )
        );
    }

    if ($version_mode === 'overwrite' && $template_id <= 0) {
        // A distinct code from the release refusal on purpose: "you asked to
        // overwrite nothing" and "you asked to overwrite something published"
        // are different mistakes and send the caller to different fixes.
        return new WP_Error(
            'nibwp_builderius_pro_bad_mode',
            __('version_mode overwrite needs an existing template_id — there is nothing to overwrite.', 'nibwp')
        );
    }

    if ($version_mode !== 'overwrite') {
        return null;
    }

    $state = nibwp_builderius_pro_release_state($template_id);
    $release = $state['published_release'];
    if (!is_array($release) || $state['serves_template'] === null) {
        return null;
    }

    $frozen = is_array($state['published_commit']) ? (string) $state['published_commit']['name'] : '';

    return new WP_Error(
        'nibwp_builderius_pro_released',
        sprintf(
            /* translators: 1: release version, 2: release post ID, 3: commit name or a placeholder */
            __('Refusing to overwrite the published release %1$s (#%2$d), which serves this template from commit %3$s. Commit a new version (version_mode new_version) or build on a branch (version_mode new_branch), then publish a release in Builderius when the user has seen it.', 'nibwp'),
            (string) $release['version'],
            (int) $release['id'],
            $frozen !== '' ? $frozen : __('(unknown)', 'nibwp')
        ),
        [
            'release_id'      => (int) $release['id'],
            'release_version' => (string) $release['version'],
            'release_status'  => (string) $release['status'],
            'frozen_commit'   => $frozen,
            'template_id'     => $template_id,
            'safe_modes'      => ['new_version', 'new_branch'],
        ]
    );
}

/**
 * Plain-language plan for where the work will land, for the report the user reads.
 *
 * @return array<string,mixed>
 */
function nibwp_builderius_pro_version_plan(int $template_id, string $version_mode): array
{
    $state = nibwp_builderius_pro_release_state($template_id);

    $lands = match ($version_mode) {
        'new_branch' => __('on a new branch of this template; the current branch and the published release are untouched.', 'nibwp'),
        'overwrite'  => __('on the template branch, replacing what the release serves for it.', 'nibwp'),
        default      => __('as a new version (a new commit) on the template branch; the published release is untouched until someone publishes a new one.', 'nibwp'),
    };

    return [
        'version_mode'      => $version_mode,
        'lands'             => $lands,
        'published_release' => $state['published_release'],
        'serves_template'   => $state['serves_template'],
        'branch_id'         => $state['branch_id'],
        'head_commit'       => $state['head_commit'],
        'published_commit'  => $state['published_commit'],
        'unreleased'        => $state['unreleased'],
        'notes'             => $state['notes'],
    ];
}
