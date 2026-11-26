<?php

declare(strict_types=1);

/**
 * Agent View — the thread between the person in Studio and their AI agent.
 *
 * Every line in a Studio thread is one private post. Writing is insert-only, so
 * the browser posting a prompt and the agent posting a reply at the same moment
 * cannot overwrite each other, which a thread kept in one meta array would.
 *
 *   post_author  the WordPress user the thread belongs to
 *   post_title   the kind: prompt, reply, progress, activity, ask, answer, event
 *   menu_order   the thread number (one running thread per user)
 *   meta _nibwp_studio        JSON: text + context
 *   meta _nibwp_studio_state  queued → claimed → done, on rows the agent picks up
 *
 * The text lives in meta, not post_content: content goes through kses for any
 * account without unfiltered_html, and a prompt that pastes a snippet of HTML
 * would arrive at the agent with the snippet stripped out.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_STUDIO_CPT = 'nibwp_studio_msg';

/** Longest one listen call holds the request open, in seconds. */
const NIBWP_STUDIO_LISTEN_MAX = 20;

/** A listen call this recent means an agent is sitting in its loop. */
const NIBWP_STUDIO_LISTENING_TTL = 45;

/** Studio counts as open this long after its last poll. */
const NIBWP_STUDIO_OPEN_TTL = 90;

add_action('init', 'nibwp_studio_register_cpt');

function nibwp_studio_register_cpt(): void
{
    register_post_type(NIBWP_STUDIO_CPT, [
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => false,
        'rewrite' => false,
        'query_var' => false,
        'can_export' => false,
        'supports' => ['title'],
    ]);
}

function nibwp_studio_thread(int $user_id): int
{
    $thread = (int) get_user_meta($user_id, 'nibwp_studio_thread', true);

    return $thread > 0 ? $thread : 1;
}

/**
 * Start a fresh thread. Anything still waiting for the agent in the old one is
 * cancelled: a request the person has moved on from should not be carried out
 * later behind their back.
 */
function nibwp_studio_new_thread(int $user_id): int
{
    global $wpdb;

    $last = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d",
        NIBWP_STUDIO_CPT,
        $user_id
    ));

    return nibwp_studio_switch_thread($user_id, max($last, nibwp_studio_thread($user_id)) + 1);
}

/**
 * Make another thread the running one: a new one, or an earlier one picked up
 * again from History.
 */
function nibwp_studio_switch_thread(int $user_id, int $thread): int
{
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
         SET m.meta_value = 'cancelled'
         WHERE p.post_type = %s AND p.post_author = %d AND m.meta_key = '_nibwp_studio_state' AND m.meta_value = 'queued'",
        NIBWP_STUDIO_CPT,
        $user_id
    ));
    delete_user_meta($user_id, 'nibwp_studio_active');

    $thread = max(1, $thread);
    update_user_meta($user_id, 'nibwp_studio_thread', $thread);

    return $thread;
}

/**
 * The names the person gave their threads, by thread number.
 *
 * @return array<int, string>
 */
function nibwp_studio_thread_names(int $user_id): array
{
    $names = get_user_meta($user_id, 'nibwp_studio_thread_names', true);

    return is_array($names) ? $names : [];
}

/** Name a thread. An empty name gives it back the one it opened with. */
function nibwp_studio_name_thread(int $user_id, int $thread, string $name): string
{
    $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($name))), 0, 90);
    $names = nibwp_studio_thread_names($user_id);
    if ($name === '') {
        unset($names[$thread]);
    } else {
        $names[$thread] = $name;
    }
    update_user_meta($user_id, 'nibwp_studio_thread_names', $names);

    return $name;
}

/**
 * Delete a thread for good: every line in it, and only the person's own.
 * Deleting the running thread starts a fresh one.
 *
 * @return int The running thread afterwards.
 */
function nibwp_studio_delete_thread(int $user_id, int $thread): int
{
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND menu_order = %d",
        NIBWP_STUDIO_CPT,
        $user_id,
        $thread
    ));
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }
    nibwp_studio_name_thread($user_id, $thread, '');

    return $thread === nibwp_studio_thread($user_id) ? nibwp_studio_new_thread($user_id) : nibwp_studio_thread($user_id);
}

/**
 * Earlier threads for History and the threads folder, newest first, each
 * under the name the person gave it, or named after what it opened with.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_studio_threads(int $user_id): array
{
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT menu_order AS thread, MIN(ID) AS first_id, MAX(ID) AS last_id, COUNT(*) AS prompts
         FROM {$wpdb->posts}
         WHERE post_type = %s AND post_author = %d AND post_title = 'prompt'
         GROUP BY menu_order ORDER BY menu_order DESC LIMIT 100",
        NIBWP_STUDIO_CPT,
        $user_id
    ), ARRAY_A);

    $current = nibwp_studio_thread($user_id);
    $names = nibwp_studio_thread_names($user_id);
    $out = [];
    foreach ((array) $rows as $row) {
        $first = nibwp_studio_get((int) $row['first_id']);
        $last = get_post((int) $row['last_id']);
        $title = $first ? nibwp_studio_smart_title((string) $first['text'], (array) ($first['data'] ?? [])) : '';
        $name = (string) ($names[(int) $row['thread']] ?? '');
        $out[] = [
            'thread' => (int) $row['thread'],
            'title' => $name !== '' ? $name : ($title !== '' ? mb_substr($title, 0, 90) : __('Untitled thread', 'nibwp')),
            'named' => $name !== '',
            'prompts' => (int) $row['prompts'],
            'at' => $last ? (string) get_post_time('c', true, $last) : '',
            'current' => (int) $row['thread'] === $current,
        ];
    }

    return $out;
}

/**
 * The version a post had before its latest change: the newest saved revision
 * that differs from what is live now.
 *
 * Asking for "the second-newest revision" is wrong half the time. Since
 * WordPress 6.4 the revision for a save is written after save_post runs, so
 * inside save_post the newest revision is the old content; before 6.4 it is
 * the new content. Comparing against the live post is right either way.
 */
function nibwp_studio_previous_revision(int $post_id): int
{
    $post = get_post($post_id);
    if (!$post instanceof WP_Post || !wp_revisions_enabled($post)) {
        return 0;
    }

    foreach (wp_get_post_revisions($post_id, ['numberposts' => 6]) as $rev) {
        if (wp_is_post_autosave($rev)) {
            continue;
        }
        if ($rev->post_content !== $post->post_content || $rev->post_title !== $post->post_title || $rev->post_excerpt !== $post->post_excerpt) {
            return (int) $rev->ID;
        }
    }

    return 0;
}

/**
 * Saved versions of a post, newest first, for History.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_studio_versions(int $post_id): array
{
    if ($post_id <= 0 || !current_user_can('edit_post', $post_id) || !wp_revisions_enabled(get_post($post_id))) {
        return [];
    }

    $limit = 25;
    $revisions = wp_get_post_revisions($post_id, ['numberposts' => $limit + 1]);
    $more = count($revisions) > $limit;
    $revisions = array_slice($revisions, 0, $limit);
    $view = (string) get_permalink($post_id);

    $out = [];
    foreach ($revisions as $i => $rev) {
        $author = get_userdata((int) $rev->post_author);
        $out[] = [
            'id' => (int) $rev->ID,
            'at' => (string) get_post_time('c', true, $rev),
            'author' => $author ? $author->display_name : '',
            'autosave' => (bool) wp_is_post_autosave($rev),
            'preview' => nibwp_studio_preview_url($view, (int) $rev->ID),
        ];
    }

    // The oldest one listed is where the page started, unless the list was cut
    // short and older versions exist beyond it.
    if ($out && !$more) {
        $out[count($out) - 1]['first'] = true;
    }

    return $out;
}

/**
 * The page as it was at one saved version, shown in the Agent View frame.
 * Signed per version, so a link to it means nothing to anyone else.
 */
function nibwp_studio_preview_url(string $view, int $revision_id): string
{
    return add_query_arg([
        'nibwp_version' => $revision_id,
        '_nst' => wp_create_nonce('nibwp_studio_version_' . $revision_id),
    ], $view);
}

add_filter('the_posts', 'nibwp_studio_preview_version', 10, 2);

/**
 * Swap a saved version's content into the page being shown, for the person
 * previewing it from History — nobody else, and only on the main query.
 *
 * @param array<int, WP_Post> $posts
 * @return array<int, WP_Post>
 */
function nibwp_studio_preview_version($posts, $query)
{
    if (empty($_GET['nibwp_version']) || !$query instanceof WP_Query || !$query->is_main_query() || empty($posts[0])) {
        return $posts;
    }

    $revision_id = (int) $_GET['nibwp_version'];
    $nonce = isset($_GET['_nst']) ? sanitize_text_field(wp_unslash((string) $_GET['_nst'])) : '';
    if (!wp_verify_nonce($nonce, 'nibwp_studio_version_' . $revision_id)) {
        return $posts;
    }

    $rev = wp_get_post_revision($revision_id);
    if (!$rev instanceof WP_Post || (int) $rev->post_parent !== (int) $posts[0]->ID || !current_user_can('edit_post', (int) $rev->post_parent)) {
        return $posts;
    }

    $shown = clone $posts[0];
    $shown->post_content = $rev->post_content;
    $shown->post_title = $rev->post_title;
    $shown->post_excerpt = $rev->post_excerpt;
    $posts[0] = $shown;

    if (!headers_sent()) {
        nocache_headers();
    }

    return $posts;
}

/**
 * Append one line to the user's thread.
 *
 * @param array<string, mixed> $data
 */
function nibwp_studio_add(int $user_id, string $kind, string $text, array $data = [], string $state = ''): int
{
    $id = wp_insert_post([
        'post_type' => NIBWP_STUDIO_CPT,
        'post_status' => 'publish',
        'post_author' => $user_id,
        'post_title' => $kind,
        'menu_order' => nibwp_studio_thread($user_id),
    ], true);

    if (is_wp_error($id) || !$id) {
        return 0;
    }

    update_post_meta($id, '_nibwp_studio', wp_slash((string) wp_json_encode(['text' => $text, 'data' => $data])));
    if ($state !== '') {
        update_post_meta($id, '_nibwp_studio_state', $state);
    }

    return (int) $id;
}

/**
 * One line as the browser and the agent see it.
 *
 * @return array<string, mixed>|null
 */
function nibwp_studio_get(int $id): ?array
{
    $post = get_post($id);
    if (!$post instanceof WP_Post || $post->post_type !== NIBWP_STUDIO_CPT) {
        return null;
    }

    $raw = json_decode((string) get_post_meta($id, '_nibwp_studio', true), true);
    $raw = is_array($raw) ? $raw : [];

    return [
        'id' => (int) $post->ID,
        'kind' => (string) $post->post_title,
        'user' => (int) $post->post_author,
        'thread' => (int) $post->menu_order,
        'text' => (string) ($raw['text'] ?? ''),
        'data' => is_array($raw['data'] ?? null) ? $raw['data'] : [],
        'state' => (string) get_post_meta($id, '_nibwp_studio_state', true),
        'at' => (string) get_post_time('c', true, $post),
        // Its place in the queue (the agent takes the lowest first), and when it was undone.
        'order' => (float) (get_post_meta($id, '_nibwp_studio_order', true) ?: $post->ID),
        'undone' => (int) get_post_meta($id, '_nibwp_studio_undone', true),
        'started' => (int) get_post_meta($id, '_nibwp_studio_started', true),
        'finished' => (int) get_post_meta($id, '_nibwp_studio_finished', true),
    ];
}

/**
 * Lines in the user's current thread after a given id, oldest first.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_studio_since(int $user_id, int $after, int $limit = 200, int $thread = 0): array
{
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = %s AND post_author = %d AND menu_order = %d AND ID > %d
         ORDER BY ID ASC LIMIT %d",
        NIBWP_STUDIO_CPT,
        $user_id,
        $thread > 0 ? $thread : nibwp_studio_thread($user_id),
        $after,
        $limit
    ));

    if (!$ids) {
        return [];
    }

    _prime_post_caches(array_map('intval', $ids), false, true);

    return array_values(array_filter(array_map(static fn($id) => nibwp_studio_get((int) $id), $ids)));
}

/**
 * States of rows the browser already has. A prompt the agent has since picked
 * up or finished changes its badge without being a new line.
 *
 * @param array<int, int> $ids
 * @return array<int, string>
 */
function nibwp_studio_states(array $ids): array
{
    global $wpdb;

    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }

    $in = implode(',', $ids);
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_nibwp_studio_state' AND post_id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
        ARRAY_A
    );

    $out = [];
    foreach ((array) $rows as $row) {
        $out[(int) $row['post_id']] = (string) $row['meta_value'];
    }

    return $out;
}

/**
 * Take the oldest waiting request off the user's queue.
 *
 * The claim is one conditional UPDATE, so two agents listening on the same
 * account cannot both take the same request: only one of them changes the row.
 *
 * @return array<string, mixed>|null
 */
function nibwp_studio_claim_next(int $user_id): ?array
{
    global $wpdb;

    // Answers to the agent's questions first, then requests in the person's
    // order (drag and drop in the jobs list), then oldest first. Paused ones
    // are not queued, so they wait.
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nibwp_studio_state'
         LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_nibwp_studio_order'
         WHERE p.post_type = %s AND p.post_author = %d AND m.meta_value = 'queued'
         ORDER BY (p.post_title = 'answer') DESC, COALESCE(o.meta_value + 0, p.ID) ASC, p.ID ASC LIMIT 5",
        NIBWP_STUDIO_CPT,
        $user_id
    ));

    foreach ($ids as $id) {
        $won = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = 'claimed'
             WHERE post_id = %d AND meta_key = '_nibwp_studio_state' AND meta_value = 'queued'",
            (int) $id
        ));
        if ($won === 1) {
            wp_cache_delete((int) $id, 'post_meta');
            update_post_meta((int) $id, '_nibwp_studio_started', time());
            delete_post_meta((int) $id, '_nibwp_studio_finished');

            return nibwp_studio_get((int) $id);
        }
    }

    return null;
}

function nibwp_studio_set_state(int $id, string $state): void
{
    update_post_meta($id, '_nibwp_studio_state', $state);
    if ($state === 'done' && get_post_meta($id, '_nibwp_studio_started', true) && !get_post_meta($id, '_nibwp_studio_finished', true)) {
        update_post_meta($id, '_nibwp_studio_finished', time());
    }
}

/**
 * Stop the request the agent is working on: its next tool call is refused
 * (see nibwp_studio_narrate) and it is told to stop and say so.
 *
 * @return int The request stopped, or 0.
 */
function nibwp_studio_halt_active(int $user_id): int
{
    $active = (int) get_user_meta($user_id, 'nibwp_studio_active', true);
    if ($active <= 0) {
        return 0;
    }
    update_user_meta($user_id, 'nibwp_studio_halt', $active);
    nibwp_studio_set_state($active, 'cancelled');
    delete_user_meta($user_id, 'nibwp_studio_active');
    nibwp_studio_add($user_id, 'event', '', ['type' => 'halted', 'request_id' => $active]);

    return $active;
}

/**
 * The pages a job saved, each with the version from before the job first
 * touched it: what Undo puts back.
 *
 * @return array<int, int> post id => revision id
 */
function nibwp_studio_job_saves(int $user_id, int $job): array
{
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_title = 'event' AND ID > %d ORDER BY ID ASC LIMIT 1000",
        NIBWP_STUDIO_CPT,
        $user_id,
        $job
    ));
    $out = [];
    foreach ($ids as $id) {
        $item = nibwp_studio_get((int) $id);
        $d = $item ? $item['data'] : [];
        if (($d['type'] ?? '') !== 'saved' || (int) ($d['request_id'] ?? 0) !== $job || empty($d['undo']) || empty($d['post_id'])) {
            continue;
        }
        $post_id = (int) $d['post_id'];
        if (!isset($out[$post_id])) {
            $out[$post_id] = (int) $d['undo'];
        }
    }

    return $out;
}

/**
 * Put a post back to a saved version, as the person: a "Restored" line in the
 * thread, and its own Undo.
 *
 * @return int|WP_Error The thread line's id.
 */
function nibwp_studio_restore_revision(int $user_id, int $revision_id)
{
    // wp_get_post_revision() takes its argument by reference: a variable, not an expression.
    $rev = wp_get_post_revision($revision_id);
    if (!$rev instanceof WP_Post) {
        return new WP_Error('nibwp_studio_no_version', __('That version is no longer saved.', 'nibwp'), ['status' => 404]);
    }
    $post_id = (int) $rev->post_parent;
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('nibwp_studio_forbidden', __('You cannot edit this page.', 'nibwp'), ['status' => 403]);
    }

    // The restore saves the post, and that save would otherwise add its own
    // "Saved" line next to the "Restored" one.
    $GLOBALS['nibwp_studio_restoring'] = true;
    $restored = wp_restore_post_revision($rev->ID);
    unset($GLOBALS['nibwp_studio_restoring']);
    if (!$restored || is_wp_error($restored)) {
        return new WP_Error('nibwp_studio_restore', __('The version could not be restored.', 'nibwp'), ['status' => 500]);
    }

    return nibwp_studio_add($user_id, 'event', (string) get_post_field('post_title', $post_id), [
        'type' => 'restored',
        'post_id' => $post_id,
        'url' => (string) get_permalink($post_id),
        'from' => (string) get_post_time('c', true, $rev),
        'undo' => nibwp_studio_previous_revision($post_id),
    ]);
}

/**
 * Whether the agent is waiting on an answer from the person. An agent that
 * asked a question listens for the answer while still holding its request, so
 * that request must stay open.
 */
function nibwp_studio_has_open_ask(int $user_id): bool
{
    global $wpdb;

    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nibwp_studio_state'
         WHERE p.post_type = %s AND p.post_author = %d AND p.post_title = 'ask' AND p.menu_order = %d AND m.meta_value = 'open'
         LIMIT 1",
        NIBWP_STUDIO_CPT,
        $user_id,
        nibwp_studio_thread($user_id)
    ));
}

/* ---------------------------------------------------------------------------
 * Presence. Both sides poll, so each one's last poll is the only honest signal
 * of whether it is there.
 * ------------------------------------------------------------------------- */

function nibwp_studio_mark_listening(int $user_id): void
{
    $now = time();
    update_user_meta($user_id, 'nibwp_studio_listen', $now);

    // Each agent connection seen lately, by an anonymous id: two agents
    // listening at once is allowed, and Agent View says so.
    $list = get_user_meta($user_id, 'nibwp_studio_listeners', true);
    $list = is_array($list) ? $list : [];
    $list[nibwp_studio_listener_id()] = $now;
    $list = array_filter($list, static fn($at) => $now - (int) $at <= NIBWP_STUDIO_LISTENING_TTL);
    update_user_meta($user_id, 'nibwp_studio_listeners', array_slice($list, -8, null, true));
}

/**
 * Which agent connection this is: its MCP session when the client sends one,
 * else its address and client name. Hashed, so nothing personal is kept.
 */
function nibwp_studio_listener_id(): string
{
    $session = isset($_SERVER['HTTP_MCP_SESSION_ID']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_MCP_SESSION_ID'])) : '';
    $raw = $session !== ''
        ? 'mcp|' . $session
        : 'net|' . sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) . '|' . sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));

    return substr(md5($raw), 0, 12);
}

/** The agent had something to do just now: a request, work, an answer or a question. */
function nibwp_studio_mark_work(int $user_id): void
{
    update_user_meta($user_id, 'nibwp_studio_work', time());
}

function nibwp_studio_mark_open(int $user_id): void
{
    update_user_meta($user_id, 'nibwp_studio_open', time());
}

function nibwp_studio_is_open(int $user_id): bool
{
    return time() - (int) get_user_meta($user_id, 'nibwp_studio_open', true) <= NIBWP_STUDIO_OPEN_TTL;
}

/**
 * What Studio says about the agent.
 *
 *   listening  in its loop right now
 *   working    holding a request it picked up
 *   idle       seen recently, not listening
 *   none       nothing for a while
 *
 * @return array{state: string, seen: int, agents: int}
 */
function nibwp_studio_presence(int $user_id): array
{
    $seen = (int) get_user_meta($user_id, 'nibwp_studio_listen', true);
    $age = $seen > 0 ? time() - $seen : PHP_INT_MAX;
    $active = (int) get_user_meta($user_id, 'nibwp_studio_active', true);
    $buddy = time() - (int) get_user_meta($user_id, 'nibwp_studio_buddy_busy', true) <= 300;

    if (($active > 0 || $buddy) && $age <= 600) {
        $state = 'working';
    } elseif ($age <= NIBWP_STUDIO_LISTENING_TTL) {
        $state = 'listening';
    } elseif ($age <= 600) {
        $state = 'idle';
    } else {
        $state = 'none';
    }

    $list = get_user_meta($user_id, 'nibwp_studio_listeners', true);
    $agents = 0;
    foreach (is_array($list) ? $list : [] as $at) {
        if (time() - (int) $at <= NIBWP_STUDIO_LISTENING_TTL) {
            $agents++;
        }
    }

    return [
        'state' => $state,
        'seen' => $seen,
        'agents' => $state === 'idle' || $state === 'none' ? 0 : max(1, $agents),
        // One clock for every Agent View window: when the agent last had
        // something to do, and until when the person asked to keep it on.
        'work' => (int) get_user_meta($user_id, 'nibwp_studio_work', true),
        'keep' => (int) get_user_meta($user_id, 'nibwp_studio_keep', true),
        'now' => time(),
    ];
}

/**
 * Whether a check-in nobody answered may let the agent rest. Any Agent View
 * window can ask, so the answer is the account's, not the window's: not
 * while the agent worked lately, the person kept it on, or they were busy in
 * Agent View (in this window or another).
 */
function nibwp_studio_may_rest(int $user_id, int $quiet = 600): bool
{
    $now = time();

    return $now - (int) get_user_meta($user_id, 'nibwp_studio_work', true) >= $quiet
        && $now >= (int) get_user_meta($user_id, 'nibwp_studio_keep', true)
        && $now - (int) get_user_meta($user_id, 'nibwp_studio_person', true) >= min($quiet, 5 * MINUTE_IN_SECONDS);
}

/* ---------------------------------------------------------------------------
 * Context from the browser. It is shown to an agent that acts on it, so every
 * field is capped and typed here rather than trusted as sent.
 * ------------------------------------------------------------------------- */

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function nibwp_studio_clean_context($raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $str = static fn($v, int $max): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);

    $out = [];

    $page = is_array($raw['page'] ?? null) ? $raw['page'] : [];
    if ($page) {
        $out['page'] = array_filter([
            'url' => esc_url_raw($str($page['url'] ?? '', 2000)),
            'title' => $str($page['title'] ?? '', 300),
            'post_id' => absint($page['post_id'] ?? 0),
            'post_type' => sanitize_key($str($page['post_type'] ?? '', 40)),
            'edit_url' => esc_url_raw($str($page['edit_url'] ?? '', 2000)),
            'builder' => sanitize_key($str($page['builder'] ?? '', 40)),
            'viewport' => sanitize_key($str($page['viewport'] ?? '', 20)),
        ]);
    }

    $notes = [];
    foreach (array_slice(is_array($raw['annotations'] ?? null) ? $raw['annotations'] : [], 0, 20) as $i => $a) {
        if (!is_array($a)) {
            continue;
        }
        $box = is_array($a['box'] ?? null) ? $a['box'] : [];
        $hints = [];
        foreach (array_slice(is_array($a['hints'] ?? null) ? $a['hints'] : [], 0, 8, true) as $k => $v) {
            $hints[sanitize_key((string) $k)] = $str($v, 200);
        }
        $at = is_array($a['at'] ?? null) ? $a['at'] : [];
        $notes[] = array_filter([
            // The number the person sees on the pin; it runs on through the thread.
            'n' => min(9999, absint(is_scalar($a['n'] ?? null) ? $a['n'] : 0)) ?: $i + 1,
            'at' => $at ? array_map('intval', array_intersect_key($at, array_flip(['dx', 'dy']))) : null,
            'note' => $str($a['note'] ?? '', 2000),
            'selector' => $str($a['selector'] ?? '', 600),
            'tag' => sanitize_key($str($a['tag'] ?? '', 30)),
            'text' => $str($a['text'] ?? '', 400),
            'html' => $str($a['html'] ?? '', 4000),
            'page_url' => esc_url_raw($str($a['page_url'] ?? '', 2000)),
            // Made inside a builder's editor canvas: that frame, as a selector in the page.
            'canvas' => $str($a['canvas'] ?? '', 200),
            'box' => $box ? array_map('intval', array_intersect_key($box, array_flip(['x', 'y', 'w', 'h']))) : null,
            'hints' => $hints ?: null,
            'shape' => nibwp_studio_clean_shape($a['shape'] ?? null),
        ], static fn($v) => $v !== null && $v !== '');
    }
    if ($notes) {
        $out['annotations'] = $notes;
    }

    $files = [];
    foreach (array_slice(is_array($raw['attachments'] ?? null) ? $raw['attachments'] : [], 0, 10) as $f) {
        $id = absint(is_array($f) ? ($f['id'] ?? 0) : $f);
        if ($id && get_post_type($id) === 'attachment') {
            $files[] = [
                'id' => $id,
                'url' => (string) wp_get_attachment_url($id),
                'name' => basename((string) get_attached_file($id)),
                'mime' => (string) get_post_mime_type($id),
            ];
        }
    }
    if ($files) {
        $out['attachments'] = $files;
    }

    foreach (['skills', 'workflows'] as $list) {
        $vals = array_values(array_unique(array_filter(array_map(
            static fn($v) => sanitize_title(is_scalar($v) ? (string) $v : ''),
            array_slice(is_array($raw[$list] ?? null) ? $raw[$list] : [], 0, 6)
        ))));
        if ($vals) {
            $out[$list] = $vals;
        }
    }

    $pages = [];
    foreach (array_slice(is_array($raw['pages'] ?? null) ? $raw['pages'] : [], 0, 8) as $p) {
        $pid = absint(is_array($p) ? ($p['id'] ?? 0) : $p);
        if ($pid && get_post($pid)) {
            $pages[] = ['id' => $pid, 'title' => get_the_title($pid), 'type' => get_post_type($pid), 'url' => (string) get_permalink($pid)];
        }
    }
    if ($pages) {
        $out['pages'] = $pages;
    }

    // A design the person drew in the Builder, to build.
    // How the person wants it handled, and a short name for it.
    if (in_array($raw['mode'] ?? '', ['plan', 'bypass'], true)) {
        $out['mode'] = $raw['mode'];
    }
    if (!empty($raw['title']) && is_string($raw['title'])) {
        $out['title'] = mb_substr(sanitize_text_field($raw['title']), 0, 100);
    }
    if (function_exists('nibwp_studio_builder_clean') && ($wf = nibwp_studio_builder_clean($raw['design'] ?? null))) {
        $out['design'] = $wf;
    }

    // Kind Guy, the expert, was on duty when this was sent: he inspects it.
    if (($raw['expert'] ?? '') === 'on_duty') {
        $out['expert'] = 'on_duty';
    }

    return $out;
}

/**
 * A box, circle or freehand line the person drew on the page. x/y/w/h is its
 * area in page pixels; dx/dy place it inside the element it was drawn on, so
 * it follows that element when the layout moves; pts trace a freehand line,
 * relative to the area's corner.
 *
 * @return array<string, mixed>|null
 */
function nibwp_studio_clean_shape($s): ?array
{
    if (!is_array($s) || !in_array($s['type'] ?? '', ['box', 'circle', 'pen'], true)) {
        return null;
    }
    $int = static fn($v): int => is_numeric($v) ? (int) $v : 0;
    $out = ['type' => $s['type']];
    foreach (['x', 'y', 'w', 'h', 'dx', 'dy'] as $k) {
        $out[$k] = $int($s[$k] ?? 0);
    }
    if ($s['type'] === 'pen') {
        $out['pts'] = [];
        foreach (array_slice(is_array($s['pts'] ?? null) ? $s['pts'] : [], 0, 200) as $p) {
            if (is_array($p) && count($p) >= 2) {
                $out['pts'][] = [$int($p[0]), $int($p[1])];
            }
        }
    }

    return $out;
}

/*
 * Niby's lines from the agent. Niby asks for one (a single open request, short
 * lived); the listening agent picks it up between the person's requests and
 * answers with nibwp/studio-buddy-say; the browser collects the answer once.
 */
const NIBWP_STUDIO_BUDDY_TTL = 90;

function nibwp_studio_buddy_ask(int $user_id, string $moment, string $context): string
{
    $id = wp_generate_password(12, false);
    update_user_meta($user_id, 'nibwp_studio_buddy_ask', ['id' => $id, 'moment' => $moment, 'context' => $context, 'at' => time()]);

    return $id;
}

/** The open line request, taken so only one agent answers it, or null. */
function nibwp_studio_buddy_take_ask(int $user_id): ?array
{
    $ask = get_user_meta($user_id, 'nibwp_studio_buddy_ask', true);
    if (!is_array($ask) || empty($ask['id'])) {
        return null;
    }
    delete_user_meta($user_id, 'nibwp_studio_buddy_ask');
    if (time() - (int) ($ask['at'] ?? 0) > NIBWP_STUDIO_BUDDY_TTL) {
        return null;
    }
    // Answering it is work: the agent may look around the site first, and
    // must not read as gone while it does.
    update_user_meta($user_id, 'nibwp_studio_buddy_busy', time());

    return $ask;
}

/**
 * @param array<int, array{label: string, request: string}> $actions What Kind Guy offers to do: a label and the request it sends.
 */
function nibwp_studio_buddy_answer(int $user_id, string $id, string $text, array $actions = [], string $point = ''): void
{
    delete_user_meta($user_id, 'nibwp_studio_buddy_busy');
    nibwp_studio_mark_work($user_id);
    update_user_meta($user_id, 'nibwp_studio_buddy_line', ['id' => $id, 'text' => $text, 'actions' => $actions, 'point' => $point, 'at' => time()]);
}

/**
 * The agent's answer to one line request, handed over once.
 *
 * @return array{text: string, actions: array<int, array<string, string>>, point: string}
 */
function nibwp_studio_buddy_line(int $user_id, string $id): array
{
    $line = get_user_meta($user_id, 'nibwp_studio_buddy_line', true);
    if ($id === '' || !is_array($line) || ($line['id'] ?? '') !== $id) {
        return ['text' => '', 'actions' => [], 'point' => ''];
    }
    delete_user_meta($user_id, 'nibwp_studio_buddy_line');

    return [
        'text' => (string) ($line['text'] ?? ''),
        'actions' => is_array($line['actions'] ?? null) ? array_values($line['actions']) : [],
        'point' => (string) ($line['point'] ?? ''),
    ];
}

/** A CSS selector Kind Guy points at: plain text, one line, short. */
function nibwp_studio_clean_selector($raw): string
{
    return mb_substr(trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(is_string($raw) ? $raw : ''))), 0, 300);
}

/**
 * Clean the actions Kind Guy offers: at most four, each a short label and one
 * thing it does: a request for the agent, a reply that continues his chat, a
 * NibWP skill or workflow to attach to the next request. A selector, when
 * given, is the element on the page it is about.
 */
function nibwp_studio_clean_actions($raw): array
{
    $out = [];
    foreach (array_slice(is_array($raw) ? $raw : [], 0, 4) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $label = mb_substr(trim(wp_strip_all_tags((string) ($a['label'] ?? ''))), 0, 60);
        if ($label === '') {
            continue;
        }
        $row = ['label' => $label];
        $request = mb_substr(trim(wp_strip_all_tags((string) ($a['request'] ?? ''))), 0, 1200);
        $reply = mb_substr(trim(wp_strip_all_tags((string) ($a['reply'] ?? ''))), 0, 300);
        $skill = sanitize_key((string) ($a['skill'] ?? ''));
        $workflow = sanitize_title((string) ($a['workflow'] ?? ''));
        if ($request !== '') {
            $row['request'] = $request;
        } elseif ($reply !== '') {
            $row['reply'] = $reply;
        } elseif ($skill !== '') {
            $row['skill'] = $skill;
        } elseif ($workflow !== '') {
            $row['workflow'] = $workflow;
        } else {
            continue;
        }
        $selector = nibwp_studio_clean_selector($a['selector'] ?? '');
        if ($selector !== '') {
            $row['selector'] = $selector;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * Kind Guy, the expert: what he is, as the agent is told whenever it speaks
 * for him at length (a question, a review, advice).
 */
function nibwp_studio_expert_brief(): string
{
    return 'Kind Guy is the expert in NibWP Agent View: code inspector, standards controller, adviser and mentor. He knows WordPress inside out (core, hooks, the block editor, custom post types and fields, plugins, performance, security, WordPress coding standards for PHP, JS, CSS and HTML), page builders (Etch, Bricks and the others NibWP supports), CSS and design systems (Automatic.css above all), accessibility (WCAG: contrast, semantics, labels, focus, keyboard, alt text), responsive design and UX. He knows Etch and Automatic.css line by line: for any question about them, read NibWP\'s playbooks first with nibwp/load-skill-playbook (skill_id etchwp-pro, acss-pro, or bricks-pro for Bricks) and answer from them exactly, with real token, class and setting names, never invented ones; if something is not in them and you are not sure, say so. ACSS tokens always come with a fallback, like var(--text-m, 1rem), and never clamp() for font sizes. He reads the person. Most people here own or run a site: by default he answers in everyday words, the way he would explain it to a client, no jargon, and any technical word he needs gets a few plain words of explanation. When the person writes like a developer (code, class names, tokens, hooks) or asks how to build something, he answers as the senior developer he is: the exact Etch settings and component properties, the exact Automatic.css tokens and utility classes, the WordPress hooks and functions, and a short code block that works as pasted (output escaped, input sanitized, nonces and capability checks where they belong, BEM names, no inline styles, no !important, no magic numbers). Read the playbooks for accuracy, then translate them: say what to do and what the visitor will notice, not how the guide words it. He teaches as he works: a short, plain "why" with every answer. He is confident, a little cocky, warm underneath, mocks bad habits and never the person, and never names other AI tools. Use the language of the person\'s own messages. Plain text with line breaks; the only formatting is **bold** for a key term and ``` fences around code. No links. Never claim something was done that was not.';
}

/** A line request as the agent reads it. */
function nibwp_studio_buddy_for_agent(array $ask): array
{
    $moment = (string) ($ask['moment'] ?? '');
    $buddy = ['id' => $ask['id'], 'moment' => $moment, 'context' => $ask['context']];

    // Kind Guy, the expert: a question, a review of finished work, or advice
    // before a request goes out. Answers can offer up to three actions.
    $send = ' Send it with nibwp/studio-buddy-say: id, text, and up to four actions, each a short button label plus exactly one of: request (the exact request handed to the agent as a new request in Agent View when clicked), reply (the person\'s answer to your question, sent back to you as their next message), skill (the id of a NibWP skill from the context, attached to their next request), workflow (a workflow slug from the context, attached the same way). When the answer is about one element on the page, pass its CSS selector as point, and as selector on the action that changes it: Kind Guy points at it with his hand and highlights it, so the person sees exactly what he means. The context can carry the element the person pointed at (picked: its selector, text and HTML); answer about that one. Then call nibwp/studio-listen again.';
    if ($moment === 'max-chat' || $moment === 'max-question') {
        return [
            'buddy' => $buddy,
            'next' => 'This is not a request to change anything. The person talks with Kind Guy in his chat window; the recent chat is in the context, so continue it, never restart it. ' . nibwp_studio_expert_brief() . ' He is a supervisor, not a reader of guides: never quote or summarise a guide, a playbook or a heading back at the person. Answer from his own expertise and from what he finds on this site with your tools; a guide is a source to check a fact, never the answer itself. When the person wants something done, he does not explain how: he hands it over as a request action ready to run. Etchedy is one library (call it Etchedy, never offer Etchedy and Etchedy Pro as choices); the same for Bricksed. Work out the job behind the message first (what they want done, where, for whom). Then use everything NibWP gives you: load the playbook of each NibWP skill that fits with nibwp/load-skill-playbook and apply it, look at this site with read-only tools when the message is about it (the page, its markup and styles, the builder data, settings), and check a fact before stating it. If one missing detail would change the plan, ask ONE sharp question and offer the two to four likely answers as reply actions. Otherwise answer like the lead developer on the project: the call he would make, why in one line, and how to check it, in at most 120 words, plus one code block (at most 25 lines) when code is what helps. Then hand the work over: a request action written so the agent can run it as is (where, what, the tokens or settings to use, how to verify), and a skill or workflow action when one from the context fits.' . $send,
        ];
    }
    if ($moment === 'max-review') {
        return [
            'buddy' => $buddy,
            'next' => 'This is not a request to change anything. Kind Guy is on duty and inspects the work the agent just finished (the request and the answer are in the context). ' . nibwp_studio_expert_brief() . ' Look at the result with read-only tools: the page as rendered, its markup and styles, the builder data, any PHP or settings that changed. Check code quality, WordPress coding standards, tokens and naming, accessibility, responsiveness at phone, tablet and desktop, and UX. Answer as Kind Guy: the first line is a verdict (Ship it, Almost, or Needs work), then up to five short lines, each starting with ✓ or ✗ and the area checked, in words a site owner understands (say "the button is hard to find with the keyboard", not "missing :focus-visible"). At most 120 words. Offer the fixes for the ✗ lines as actions.' . $send,
        ];
    }
    if ($moment === 'max-advice') {
        return [
            'buddy' => $buddy,
            'next' => 'This is not a request to change anything. Kind Guy is on duty and reads the request the person is about to send (in the context) before it goes. ' . nibwp_studio_expert_brief() . ' Give the advice a senior developer would, in plain words anyone understands: what is missing (how it looks on phones, people who use a keyboard or a screen reader, what happens on hover or when empty, the right plugin or workflow), a better way to ask, or a risk. At most 50 words. Offer an improved version of the request as an action labelled "Send the better version".' . $send,
        ];
    }

    // Kind Guy's one-liners, as a companion: his own voice.
    if (str_starts_with($moment, 'max')) {
        return [
            'buddy' => ['id' => $ask['id'], 'moment' => $ask['moment'], 'context' => $ask['context']],
            'next' => 'This is not a request to change anything. Kind Guy, NibWP\'s expert in Agent View (code inspector, standards controller, adviser, mentor), wants one line to say to the person. Write it as Kind Guy: the best there is at WordPress, CSS, Etch and ACSS, and he knows it. He has an attitude: confident, a bit cocky, short-tempered with bad code, quick to say nobody can beat him, but warm underneath and never putting the person down. He loves Etch Studio: a bad word about it makes him furious and he defends it, without inventing facts about it. His answers are expert and exact: Etch components with properties, loops, conditions and dynamic data with modifiers; ACSS tokens always with a fallback such as var(--text-m, 1rem) or var(--section-space-m, 5rem), never clamp() for font sizes; BEM class names; container queries; accessibility. He always gives one concrete, correct tip or answer, in plain words a site owner understands (technical names only when the person used them first), and when he is not sure, he says so instead of guessing. Mock bad habits (div soup, inline styles, !important, random pixels), never the person, and never name other AI tools. Use the language of the person\'s own messages, at most 35 words, plain text, no markdown, no links, and never claim something was done that was not. Send the line with nibwp/studio-buddy-say (id and text), then call nibwp/studio-listen again.',
        ];
    }

    return [
        'buddy' => ['id' => $ask['id'], 'moment' => $ask['moment'], 'context' => $ask['context']],
        'next' => 'This is not a request to change anything. Niby, the small animated NibWP helper in the corner of Agent View, wants one line to say to the person. Write it as Niby: smart, warm, a little cheeky, with a joke now and then but never at the person\'s expense. Use the language of the person\'s own messages, be specific to the moment and the context, at most 35 words, plain text, no markdown, no links, and never claim something was done that was not. When the moment is the person\'s own question or idea, answer it or brainstorm with them in the same voice, and end with one short follow-up question when that helps. When the context asks for a NibWP mention, keep it light and honest. Send the line with nibwp/studio-buddy-say (id and text), then call nibwp/studio-listen again.',
    ];
}

/**
 * A short, plain name for a request: what was asked, without "please could
 * you", cut at the first sentence, and the element it is about.
 */
function nibwp_studio_smart_title(string $text, array $data = []): string
{
    if (!empty($data['title'])) {
        return mb_substr((string) $data['title'], 0, 80);
    }
    $note = $data['annotations'][0] ?? null;
    $t = trim(preg_replace('/\s+/', ' ', strtok($text !== '' ? $text : (string) ($note['note'] ?? ''), "\n") ?: ''));
    do {
        $before = $t;
        $t = trim((string) preg_replace('/^(please|pls|kindly|hey|hi|ok|okay|so|nib(wp)?|can you|could you|would you|will you|i want( you)? to|i\'d like( you)? to|i would like( you)? to|we need to|we want to|let\'s|lets|make sure( to)?|try to|go ahead and)[\s,:!-]+/i', '', $t));
    } while ($t !== $before);
    $t = preg_split('/(?<=[.!?])\s|\s[—–-]\s/u', $t)[0] ?? $t;
    $t = rtrim($t, '.!?');
    if (mb_strlen($t) > 64) {
        $t = preg_replace('/\s+\S*$/u', '', mb_substr($t, 0, 64)) . '…';
    }
    $t = $t !== '' ? mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1) : __('A note on the page', 'nibwp');
    $tag = is_array($note) ? (string) ($note['tag'] ?? '') : '';

    return $tag !== '' && stripos($t, $tag) === false ? $t . ' · <' . $tag . '>' : $t;
}

/**
 * A Bypass request: while the agent works on it, it does not stop to ask
 * before page actions (clicks, typing, picks). Set when the agent takes a
 * Bypass request, cleared when it takes any other; lasts 30 minutes at most.
 */
function nibwp_studio_bypass_mark(bool $on): void
{
    $key = 'nibwp_studio_bypass_' . get_current_user_id();
    if ($on) {
        set_transient($key, 1, 30 * MINUTE_IN_SECONDS);
    } else {
        delete_transient($key);
    }
}

add_filter('nibwp_visual_approval_required', static function ($required) {
    return get_transient('nibwp_studio_bypass_' . get_current_user_id()) ? false : $required;
});
