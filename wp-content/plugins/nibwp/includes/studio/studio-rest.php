<?php

declare(strict_types=1);

/**
 * Agent View — the browser's side of the bridge.
 *
 * Studio never changes the site itself. It queues what the person asked, reads
 * the thread back, and searches for pages to open. Every change to the site is
 * made by the agent, through its own abilities and their permissions.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', 'nibwp_studio_rest_routes');

function nibwp_studio_rest_routes(): void
{
    $can = static fn(): bool => current_user_can('manage_options');

    $routes = [
        ['/studio/thread', 'GET', 'nibwp_studio_rest_thread'],
        ['/studio/prompt', 'POST', 'nibwp_studio_rest_prompt'],
        ['/studio/note', 'POST', 'nibwp_studio_rest_note'],
        ['/studio/answer', 'POST', 'nibwp_studio_rest_answer'],
        ['/studio/new', 'POST', 'nibwp_studio_rest_new'],
        ['/studio/stop', 'POST', 'nibwp_studio_rest_stop'],
        ['/studio/keep', 'POST', 'nibwp_studio_rest_keep'],
        ['/studio/halt', 'POST', 'nibwp_studio_rest_halt'],
        ['/studio/job', 'POST', 'nibwp_studio_rest_job'],
        ['/studio/queue', 'POST', 'nibwp_studio_rest_queue'],
        ['/studio/requeue', 'POST', 'nibwp_studio_rest_requeue'],
        ['/studio/cancel', 'POST', 'nibwp_studio_rest_cancel'],
        ['/studio/search', 'GET', 'nibwp_studio_rest_search'],
        ['/studio/threads', 'GET', 'nibwp_studio_rest_threads'],
        ['/studio/switch', 'POST', 'nibwp_studio_rest_switch'],
        ['/studio/thread-name', 'POST', 'nibwp_studio_rest_thread_name'],
        ['/studio/thread-delete', 'POST', 'nibwp_studio_rest_thread_delete'],
        ['/studio/versions', 'GET', 'nibwp_studio_rest_versions'],
        ['/studio/restore', 'POST', 'nibwp_studio_rest_restore'],
        ['/studio/media', 'GET', 'nibwp_studio_rest_media'],
        ['/studio/media/bulk', 'POST', 'nibwp_studio_rest_media_bulk'],
        ['/studio/buddy', 'POST', 'nibwp_studio_rest_buddy_ask'],
        ['/studio/buddy', 'GET', 'nibwp_studio_rest_buddy_line'],
    ];

    foreach ($routes as [$path, $method, $callback]) {
        register_rest_route('nibwp/v1', $path, [
            'methods' => $method,
            'permission_callback' => $can,
            'callback' => $callback,
        ]);
    }
}

function nibwp_studio_rest_threads(): WP_REST_Response
{
    return new WP_REST_Response(['threads' => nibwp_studio_threads(get_current_user_id())]);
}

/** Pick an earlier thread up again from History. */
function nibwp_studio_rest_switch(WP_REST_Request $request): WP_REST_Response
{
    $uid = get_current_user_id();
    $thread = nibwp_studio_switch_thread($uid, (int) $request->get_param('thread'));

    return new WP_REST_Response(['thread' => $thread, 'name' => (string) (nibwp_studio_thread_names($uid)[$thread] ?? '')]);
}

/** Rename a thread; an empty name brings back the automatic one. */
function nibwp_studio_rest_thread_name(WP_REST_Request $request): WP_REST_Response
{
    $uid = get_current_user_id();
    $thread = max(1, (int) $request->get_param('thread'));
    $name = nibwp_studio_name_thread($uid, $thread, (string) $request->get_param('name'));
    $row = array_values(array_filter(nibwp_studio_threads($uid), static fn($t) => $t['thread'] === $thread));

    return new WP_REST_Response(['thread' => $thread, 'name' => $name, 'title' => $row ? $row[0]['title'] : $name]);
}

/** Delete a thread for good. */
function nibwp_studio_rest_thread_delete(WP_REST_Request $request): WP_REST_Response
{
    $thread = (int) $request->get_param('thread');
    if ($thread < 1) {
        return new WP_REST_Response(['message' => __('Pick a thread to delete.', 'nibwp')], 400);
    }

    return new WP_REST_Response(['thread' => nibwp_studio_delete_thread(get_current_user_id(), $thread), 'deleted' => $thread]);
}

/** The saved versions of the post on screen. */
function nibwp_studio_rest_versions(WP_REST_Request $request): WP_REST_Response
{
    $post_id = (int) $request->get_param('post_id');
    $post = get_post($post_id);

    return new WP_REST_Response([
        'post_id' => $post_id,
        'title' => $post ? html_entity_decode(get_the_title($post), ENT_QUOTES) : '',
        'enabled' => $post ? wp_revisions_enabled($post) : false,
        'versions' => nibwp_studio_versions($post_id),
    ]);
}

/**
 * Put a post back to one of its saved versions. The one change Studio makes
 * itself: going back is the person's call, and waiting on an agent to undo
 * what that agent just did would be the wrong way round.
 *
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_restore(WP_REST_Request $request)
{
    $id = nibwp_studio_restore_revision(get_current_user_id(), (int) $request->get_param('revision_id'));
    if (is_wp_error($id)) {
        return $id;
    }
    $item = nibwp_studio_get($id);

    return new WP_REST_Response(['post_id' => (int) ($item['data']['post_id'] ?? 0), 'item' => $item]);
}

/**
 * One job in the queue: pause it, run it again, run it next, remove it (a
 * running one is stopped), or undo a finished one (the pages it saved go back
 * to how they were before it).
 *
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_job(WP_REST_Request $request)
{
    global $wpdb;

    $uid = get_current_user_id();
    $job = nibwp_studio_get((int) $request->get_param('id'));
    if ($job === null || $job['user'] !== $uid || $job['kind'] !== 'prompt') {
        return new WP_Error('nibwp_studio_no_job', __('That job is no longer here.', 'nibwp'), ['status' => 404]);
    }
    $id = $job['id'];
    $state = $job['state'];
    $items = [];

    // Conditional, like the agent's claim: a job it took a moment ago is running.
    $move = static function (string $from, string $to) use ($wpdb, $id): bool {
        $won = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_nibwp_studio_state' AND meta_value = %s",
            $to,
            $id,
            $from
        ));
        wp_cache_delete($id, 'post_meta');

        return $won === 1;
    };
    $taken = new WP_Error('nibwp_studio_taken', __('Your agent already started this job.', 'nibwp'), ['status' => 409]);

    switch ((string) $request->get_param('act')) {
        case 'pause':
            if ($state !== 'queued' || !$move('queued', 'paused')) {
                return $taken;
            }
            break;

        case 'run':
            if ($state === 'paused') {
                $move('paused', 'queued');
            }
            break;

        case 'next':
            if ($state === 'paused') {
                $move('paused', 'queued');
            } elseif ($state !== 'queued') {
                return $taken;
            }
            $first = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(COALESCE(o.meta_value + 0, p.ID)) FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nibwp_studio_state' AND m.meta_value = 'queued'
                 LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_nibwp_studio_order'
                 WHERE p.post_type = %s AND p.post_author = %d AND p.post_title = 'prompt'",
                NIBWP_STUDIO_CPT,
                $uid
            ));
            update_post_meta($id, '_nibwp_studio_order', $first - 1);
            break;

        case 'remove':
            if ($state === 'claimed') {
                if (nibwp_studio_halt_active($uid) !== $id) {
                    nibwp_studio_set_state($id, 'cancelled');
                }
            } elseif (!($state === 'queued' && $move('queued', 'cancelled')) && !($state === 'paused' && $move('paused', 'cancelled'))) {
                return $taken;
            }
            break;

        case 'undo':
            if ($state === 'claimed' || $state === 'queued') {
                return new WP_Error('nibwp_studio_undo_running', __('Stop the job first, then undo it.', 'nibwp'), ['status' => 409]);
            }
            $saves = nibwp_studio_job_saves($uid, $id);
            if (!$saves) {
                return new WP_Error('nibwp_studio_undo_nothing', __('This job saved no pages, so there is nothing to put back.', 'nibwp'), ['status' => 409]);
            }
            $put_back = 0;
            foreach ($saves as $revision) {
                $line = nibwp_studio_restore_revision($uid, $revision);
                if (!is_wp_error($line)) {
                    $put_back++;
                    $items[] = nibwp_studio_get($line);
                }
            }
            if ($put_back === 0) {
                return new WP_Error('nibwp_studio_undo_failed', __('The pages this job saved could not be put back.', 'nibwp'), ['status' => 500]);
            }
            update_post_meta($id, '_nibwp_studio_undone', time());
            $items[] = nibwp_studio_get(nibwp_studio_add($uid, 'event', '', ['type' => 'undone', 'request_id' => $id, 'pages' => $put_back]));
            break;

        default:
            return new WP_Error('nibwp_studio_job_act', __('Unknown job action.', 'nibwp'), ['status' => 400]);
    }

    return new WP_REST_Response(['job' => nibwp_studio_get($id), 'items' => array_values(array_filter($items))]);
}

/** The order of the waiting jobs, as dragged in the jobs list. */
function nibwp_studio_rest_queue(WP_REST_Request $request): WP_REST_Response
{
    $uid = get_current_user_id();
    $ids = array_slice(array_map('intval', (array) $request->get_param('ids')), 0, 100);
    $i = 0;
    foreach ($ids as $id) {
        $job = nibwp_studio_get($id);
        if ($job === null || $job['user'] !== $uid || $job['kind'] !== 'prompt' || !in_array($job['state'], ['queued', 'paused'], true)) {
            continue;
        }
        update_post_meta($id, '_nibwp_studio_order', ++$i * 10);
    }

    return new WP_REST_Response(['ordered' => $i]);
}

/**
 * New lines since the browser last asked, the badges on lines it already has,
 * and whether an agent is there to read any of it.
 */
function nibwp_studio_rest_thread(WP_REST_Request $request): WP_REST_Response
{
    $uid = get_current_user_id();
    nibwp_studio_mark_open($uid);
    // The person used Agent View since the last poll.
    if ($request->get_param('here')) {
        update_user_meta($uid, 'nibwp_studio_person', time());
    }

    $known = array_filter(array_map('intval', explode(',', (string) $request->get_param('known'))));

    return new WP_REST_Response([
        'thread' => nibwp_studio_thread($uid),
        'items' => nibwp_studio_since($uid, (int) $request->get_param('after')),
        'states' => (object) nibwp_studio_states(array_slice($known, 0, 100)),
        'presence' => nibwp_studio_presence($uid),
    ]);
}

/**
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_prompt(WP_REST_Request $request)
{
    $uid = get_current_user_id();
    $text = trim((string) $request->get_param('text'));
    $context = nibwp_studio_clean_context($request->get_param('context'));

    if ($text === '' && empty($context['annotations']) && empty($context['attachments'])) {
        return new WP_Error('nibwp_studio_empty', __('Write what you want, or pin a note on the page.', 'nibwp'), ['status' => 400]);
    }
    if (mb_strlen($text) > 20000) {
        return new WP_Error('nibwp_studio_long', __('That message is too long. Keep it under 20,000 characters.', 'nibwp'), ['status' => 400]);
    }

    $id = nibwp_studio_add($uid, 'prompt', $text, $context, 'queued');
    if ($id === 0) {
        return new WP_Error('nibwp_studio_write', __('The message could not be saved.', 'nibwp'), ['status' => 500]);
    }

    return new WP_REST_Response(['item' => nibwp_studio_get($id), 'presence' => nibwp_studio_presence($uid)]);
}

/**
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_answer(WP_REST_Request $request)
{
    $uid = get_current_user_id();
    $ask = nibwp_studio_get((int) $request->get_param('ask_id'));

    if ($ask === null || $ask['user'] !== $uid || $ask['kind'] !== 'ask') {
        return new WP_Error('nibwp_studio_no_ask', __('That question is no longer here.', 'nibwp'), ['status' => 404]);
    }
    if ($ask['state'] !== 'open') {
        return new WP_Error('nibwp_studio_answered', __('That question was already answered.', 'nibwp'), ['status' => 409]);
    }

    // Same cap as the options nibwp/studio-ask stores, or a long option comes back cut.
    $choice = mb_substr(trim((string) $request->get_param('choice')), 0, 120);
    $text = mb_substr(trim((string) $request->get_param('text')), 0, 4000);
    if ($choice === '' && $text === '') {
        return new WP_Error('nibwp_studio_empty', __('Pick an answer or write one.', 'nibwp'), ['status' => 400]);
    }

    $id = nibwp_studio_add($uid, 'answer', $text, ['ask_id' => $ask['id'], 'choice' => $choice], 'queued');
    nibwp_studio_set_state($ask['id'], 'answered');

    return new WP_REST_Response(['item' => nibwp_studio_get($id)]);
}

function nibwp_studio_rest_new(): WP_REST_Response
{
    return new WP_REST_Response(['thread' => nibwp_studio_new_thread(get_current_user_id())]);
}

/** Ask the listening agent to leave its loop at its next listen call. */
function nibwp_studio_rest_stop(WP_REST_Request $request): WP_REST_Response
{
    $uid = get_current_user_id();
    $rest = $request->get_param('reason') === 'rest';
    // A check-in nobody answered, in some window: the account decides.
    if ($rest && $request->get_param('auto') && !nibwp_studio_may_rest($uid, max(30, min(600, (int) ($request->get_param('quiet') ?: 600))))) {
        return new WP_REST_Response(['ok' => false, 'kept' => true]);
    }
    // No agent to tell: the word would stop the next one as it arrives.
    if (nibwp_studio_presence($uid)['state'] === 'none') {
        return new WP_REST_Response(['ok' => true, 'none' => true]);
    }
    update_user_meta($uid, 'nibwp_studio_stop', time());
    // A rest after a quiet stretch, or the end of the session.
    update_user_meta($uid, 'nibwp_studio_stop_reason', $rest ? 'rest' : 'end');

    return new WP_REST_Response(['ok' => true]);
}

/** "Keep listening": every Agent View window, and the listen loop, leave the agent on for 20 minutes. */
function nibwp_studio_rest_keep(): WP_REST_Response
{
    $uid = get_current_user_id();
    update_user_meta($uid, 'nibwp_studio_keep', time() + 20 * MINUTE_IN_SECONDS);
    nibwp_studio_mark_work($uid);

    return new WP_REST_Response(['ok' => true]);
}

/**
 * Stop what is running: the request the agent is working on is halted (its
 * next tool call is refused and it is told to stop), and anything still
 * waiting in the queue is taken back.
 */
function nibwp_studio_rest_halt(): WP_REST_Response
{
    global $wpdb;

    $uid = get_current_user_id();
    $halted = nibwp_studio_halt_active($uid);

    // Anything still queued for the agent is taken back too.
    $queued = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_type = %s AND p.post_author = %d AND p.post_title = 'prompt' AND m.meta_key = '_nibwp_studio_state' AND m.meta_value = 'queued'",
        NIBWP_STUDIO_CPT,
        $uid
    ));
    foreach ($queued as $id) {
        nibwp_studio_set_state((int) $id, 'cancelled');
    }

    return new WP_REST_Response(['halted' => $halted, 'cancelled' => array_map('intval', $queued)]);
}

/**
 * Put a request the agent took and never finished back in the queue — the
 * agent's session ended, or the call that carried it was cut off.
 *
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_requeue(WP_REST_Request $request)
{
    $uid = get_current_user_id();
    $item = nibwp_studio_get((int) $request->get_param('id'));

    if ($item === null || $item['user'] !== $uid || $item['kind'] !== 'prompt' || !in_array($item['state'], ['claimed', 'done'], true)) {
        return new WP_Error('nibwp_studio_requeue', __('That message cannot be sent again.', 'nibwp'), ['status' => 409]);
    }

    nibwp_studio_set_state($item['id'], 'queued');
    if ((int) get_user_meta($uid, 'nibwp_studio_active', true) === $item['id']) {
        delete_user_meta($uid, 'nibwp_studio_active');
    }

    return new WP_REST_Response(['id' => $item['id'], 'state' => 'queued']);
}

/**
 * Take back a message the agent has not picked up yet.
 *
 * @return WP_REST_Response|WP_Error
 */
/**
 * Hides a comment the person sent, or shows it again. The message keeps it
 * (the thread is history); the comment only stops showing on the page and in
 * the list of comments.
 */
function nibwp_studio_rest_note(WP_REST_Request $request)
{
    $uid = get_current_user_id();
    $id = (int) $request->get_param('id');
    $n = (int) $request->get_param('n');
    $item = nibwp_studio_get($id);
    if ($item === null || $item['user'] !== $uid || $item['kind'] !== 'prompt') {
        return new WP_Error('nibwp_studio_note', __('That comment is no longer here.', 'nibwp'), ['status' => 404]);
    }
    $raw = json_decode((string) get_post_meta($id, '_nibwp_studio', true), true);
    $raw = is_array($raw) ? $raw : [];
    $hidden = (bool) $request->get_param('hidden');
    $found = false;
    foreach (($raw['data']['annotations'] ?? []) as $i => $a) {
        if (is_array($a) && (int) ($a['n'] ?? 0) === $n) {
            $raw['data']['annotations'][$i]['hidden'] = $hidden;
            $found = true;
        }
    }
    if (!$found) {
        return new WP_Error('nibwp_studio_note', __('That comment is no longer here.', 'nibwp'), ['status' => 404]);
    }
    update_post_meta($id, '_nibwp_studio', wp_slash((string) wp_json_encode($raw)));

    return new WP_REST_Response(['id' => $id, 'n' => $n, 'hidden' => $hidden]);
}

function nibwp_studio_rest_cancel(WP_REST_Request $request)
{
    global $wpdb;

    $uid = get_current_user_id();
    $item = nibwp_studio_get((int) $request->get_param('id'));
    if ($item === null || $item['user'] !== $uid || $item['kind'] !== 'prompt') {
        return new WP_Error('nibwp_studio_cancel', __('That message is no longer here.', 'nibwp'), ['status' => 404]);
    }
    if ($item['state'] === 'cancelled') {
        return new WP_REST_Response(['id' => $item['id'], 'state' => 'cancelled']);
    }

    // Conditional, like the claim: if the agent took it a moment ago, it is
    // already being worked on and cancelling would only hide that.
    $won = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = 'cancelled' WHERE post_id = %d AND meta_key = '_nibwp_studio_state' AND meta_value = 'queued'",
        $item['id']
    ));
    wp_cache_delete($item['id'], 'post_meta');

    if ($won !== 1) {
        return new WP_Error('nibwp_studio_taken', __('The agent already picked this up.', 'nibwp'), ['status' => 409]);
    }

    return new WP_REST_Response(['id' => $item['id'], 'state' => 'cancelled']);
}

/**
 * Pages, posts and products on this install, drafts included, plus the admin
 * screens. With no query, the front page and what changed most recently.
 */
function nibwp_studio_rest_search(WP_REST_Request $request): WP_REST_Response
{
    $q = trim((string) $request->get_param('q'));

    $types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));
    $args = [
        'post_type' => $types,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => 12,
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
    ];
    if ($q !== '') {
        $args['s'] = $q;
    } else {
        $args['orderby'] = 'modified';
        $args['order'] = 'DESC';
    }

    $items = [];
    $seen = [];
    $front = (int) get_option('page_on_front');
    if ($q === '' && $front > 0) {
        $items[] = nibwp_studio_search_row($front);
        $seen[$front] = true;
    }

    foreach ((new WP_Query($args))->posts as $post) {
        if (!isset($seen[$post->ID])) {
            $items[] = nibwp_studio_search_row((int) $post->ID);
            $seen[$post->ID] = true;
        }
    }

    // Admin and plugin screens are matched in the browser (boot.shortcuts).
    return new WP_REST_Response(['items' => array_values(array_filter($items))]);
}

/**
 * @return array<string, mixed>|null
 */
function nibwp_studio_search_row(int $id): ?array
{
    $post = get_post($id);
    if (!$post instanceof WP_Post) {
        return null;
    }

    $type = get_post_type_object($post->post_type);
    $live = $post->post_status === 'publish';

    return [
        'id' => $post->ID,
        'title' => get_the_title($post) !== '' ? html_entity_decode(get_the_title($post), ENT_QUOTES) : __('(no title)', 'nibwp'),
        'type' => $post->post_type,
        'type_label' => $type ? (string) $type->labels->singular_name : $post->post_type,
        'status' => $post->post_status,
        'front' => $post->ID === (int) get_option('page_on_front'),
        'url' => $live ? (string) get_permalink($post) : (string) get_preview_post_link($post),
        'edit' => (string) get_edit_post_link($post, 'raw'),
    ];
}

/*
 * Media: the whole media library, browsed from Agent View. Uploads from here
 * go through the library's own route; the flag on that request marks them,
 * so "From Agent View" can list just those.
 */

add_action('rest_after_insert_attachment', 'nibwp_studio_mark_upload', 10, 3);

function nibwp_studio_mark_upload(WP_Post $attachment, WP_REST_Request $request, bool $creating): void
{
    if ($creating && $request->get_param('nibwp_studio')) {
        update_post_meta($attachment->ID, '_nibwp_studio_file', get_current_user_id());
    }
}

/** Files sent before uploads were marked: picked up once from the person's messages. */
function nibwp_studio_mark_sent_files(int $uid): void
{
    if (get_user_meta($uid, '_nibwp_studio_files_seen', true)) {
        return;
    }
    $msgs = get_posts(['post_type' => NIBWP_STUDIO_CPT, 'author' => $uid, 'post_status' => 'any', 'title' => 'prompt', 'fields' => 'ids', 'numberposts' => -1]);
    foreach ($msgs as $msg) {
        $row = json_decode((string) get_post_meta($msg, '_nibwp_studio', true), true);
        foreach ((array) ($row['data']['attachments'] ?? []) as $f) {
            $id = absint(is_array($f) ? ($f['id'] ?? 0) : $f);
            if ($id && get_post_type($id) === 'attachment' && !get_post_meta($id, '_nibwp_studio_file', true)) {
                update_post_meta($id, '_nibwp_studio_file', $uid);
            }
        }
    }
    update_user_meta($uid, '_nibwp_studio_files_seen', 1);
}

/** The media types the Media window filters by, as WP_Query mime filters. */
function nibwp_studio_media_types(): array
{
    return ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'document' => ['application', 'text']];
}

/**
 * One page of the media library: by source, type, month and words, newest
 * first. The first page also carries the counts and months for the sidebar.
 */
function nibwp_studio_rest_media(WP_REST_Request $request): WP_REST_Response
{
    nibwp_studio_mark_sent_files(get_current_user_id());
    $trash = $request->get_param('view') === 'trash';
    $page = max(1, absint($request->get_param('page')));
    $args = [
        'post_type' => 'attachment',
        'post_status' => $trash ? 'trash' : 'inherit',
        'posts_per_page' => 40,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
    ];
    $types = nibwp_studio_media_types();
    $type = sanitize_key((string) $request->get_param('type'));
    if (isset($types[$type])) {
        $args['post_mime_type'] = $types[$type];
    }
    $year = absint($request->get_param('y'));
    $month = absint($request->get_param('m'));
    if ($year) {
        $args['year'] = $year;
        if ($month >= 1 && $month <= 12) {
            $args['monthnum'] = $month;
        }
    }
    $words = mb_substr(trim(sanitize_text_field((string) $request->get_param('s'))), 0, 100);
    if ($words !== '') {
        $args['s'] = $words;
    }
    if ($request->get_param('source') === 'agent') {
        $args['meta_query'] = [['key' => '_nibwp_studio_file', 'compare' => 'EXISTS']];
    }

    // Search file names too, the way the media library's own grid does.
    add_filter('wp_allow_query_attachment_by_filename', '__return_true');
    $query = new WP_Query($args);
    remove_filter('wp_allow_query_attachment_by_filename', '__return_true');

    $out = [
        'items' => array_map('nibwp_studio_media_row', $query->posts),
        'total' => (int) $query->found_posts,
        'page' => $page,
        'pages' => (int) $query->max_num_pages,
        'days' => (int) EMPTY_TRASH_DAYS,
    ];
    if ($page === 1) {
        $out['counts'] = nibwp_studio_media_counts();
        $out['dates'] = nibwp_studio_media_dates($trash);
    }

    return new WP_REST_Response($out);
}

/** @return array<string, mixed> */
function nibwp_studio_media_row(WP_Post $post): array
{
    $path = (string) get_attached_file($post->ID);
    $mime = (string) $post->post_mime_type;
    $meta = wp_get_attachment_metadata($post->ID);
    $meta = is_array($meta) ? $meta : [];
    $bytes = (int) ($meta['filesize'] ?? 0);
    if (!$bytes && $path !== '' && is_file($path)) {
        $bytes = (int) filesize($path);
    }
    $top = strtok($mime, '/');

    return [
        'id' => $post->ID,
        'name' => $path !== '' ? wp_basename($path) : get_the_title($post),
        'title' => html_entity_decode(get_the_title($post), ENT_QUOTES),
        'url' => (string) wp_get_attachment_url($post->ID),
        'thumb' => $top === 'image' ? (string) (wp_get_attachment_image_url($post->ID, 'medium') ?: wp_get_attachment_url($post->ID)) : '',
        'mime' => $mime,
        'kind' => in_array($top, ['image', 'video', 'audio'], true) ? $top : 'document',
        'size' => $bytes ? size_format($bytes) : '',
        'dims' => !empty($meta['width']) && !empty($meta['height']) ? $meta['width'] . '×' . $meta['height'] : '',
        'at' => (string) get_post_time('c', true, $post),
        'agent' => (bool) get_post_meta($post->ID, '_nibwp_studio_file', true),
    ];
}

/** @return array<string, int> */
function nibwp_studio_media_counts(): array
{
    $out = ['all' => 0, 'image' => 0, 'video' => 0, 'audio' => 0, 'document' => 0, 'trash' => 0, 'agent' => 0];
    foreach ((array) wp_count_attachments() as $mime => $n) {
        if ($mime === 'trash') {
            $out['trash'] = (int) $n;
            continue;
        }
        $top = strtok((string) $mime, '/');
        $out['all'] += (int) $n;
        $out[in_array($top, ['image', 'video', 'audio'], true) ? $top : 'document'] += (int) $n;
    }
    $agent = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_nibwp_studio_file', 'posts_per_page' => 1, 'fields' => 'ids']);
    $out['agent'] = (int) $agent->found_posts;

    return $out;
}

/**
 * Months that hold media, newest first, for the date tree.
 *
 * @return array<int, array<string, int>>
 */
function nibwp_studio_media_dates(bool $trash): array
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a grouped count the core API does not offer; read only
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = %s GROUP BY y, m ORDER BY y DESC, m DESC",
        $trash ? 'trash' : 'inherit'
    ), ARRAY_A);

    return array_map(static fn($r) => ['y' => (int) $r['y'], 'm' => (int) $r['m'], 'n' => (int) $r['n']], is_array($rows) ? $rows : []);
}

/**
 * One action on many files: trash, restore, or delete for good. Each file is
 * checked on its own, so one the person may not delete is skipped, not fatal.
 */
function nibwp_studio_rest_media_bulk(WP_REST_Request $request)
{
    $act = (string) $request->get_param('act');
    if (!in_array($act, ['trash', 'restore', 'delete'], true)) {
        return new WP_Error('nibwp_studio_bad_act', __('Unknown action.', 'nibwp'), ['status' => 400]);
    }
    // With the trash turned off, wp_trash_post() deletes for good. Never let
    // "Delete" do that behind the person's back.
    if ($act === 'trash' && !EMPTY_TRASH_DAYS) {
        return new WP_Error('nibwp_studio_no_trash', __('The trash is turned off on this site. Use Delete permanently instead.', 'nibwp'), ['status' => 409]);
    }

    $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $request->get_param('ids'))))), 0, 200);
    $done = [];
    $failed = [];
    foreach ($ids as $id) {
        $ok = get_post_type($id) === 'attachment' && current_user_can('delete_post', $id);
        // For good only from the trash, unless this site has no trash.
        if ($ok && $act === 'delete' && EMPTY_TRASH_DAYS && get_post_status($id) !== 'trash') {
            $ok = false;
        }
        if ($ok) {
            $ok = (bool) ($act === 'trash' ? wp_trash_post($id) : ($act === 'restore' ? wp_untrash_post($id) : wp_delete_attachment($id, true)));
        }
        if ($ok) {
            $done[] = $id;
        } else {
            $failed[] = $id;
        }
    }

    return new WP_REST_Response(['done' => $done, 'failed' => $failed]);
}

/*
 * Niby asks the listening agent for a line. Only an agent that is listening
 * right now, with nothing else in hand, gets asked; otherwise the browser
 * says one of its own.
 */

function nibwp_studio_rest_buddy_ask(WP_REST_Request $request): WP_REST_Response
{
    $uid = get_current_user_id();
    $presence = nibwp_studio_presence($uid);
    if ($presence['state'] !== 'listening') {
        return new WP_REST_Response(['id' => '', 'presence' => $presence]);
    }
    $moment = substr(sanitize_key((string) $request->get_param('moment')), 0, 40);
    // Room for the recent chat, the element pointed at, and the site's skills and workflows.
    $context = mb_substr(sanitize_textarea_field((string) $request->get_param('context')), 0, 6000);

    return new WP_REST_Response(['id' => nibwp_studio_buddy_ask($uid, $moment, $context)]);
}

function nibwp_studio_rest_buddy_line(WP_REST_Request $request): WP_REST_Response
{
    $id = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $request->get_param('id'));

    return new WP_REST_Response(nibwp_studio_buddy_line(get_current_user_id(), $id));
}
