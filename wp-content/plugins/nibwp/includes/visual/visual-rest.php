<?php

declare(strict_types=1);

/**
 * The workspace side of the bus.
 *
 * The hot path runs on admin-ajax rather than REST, and that is not a style
 * preference. Every REST request fires `rest_api_init`, which boots the MCP
 * adapter and registers every ability on the site — measured at 278 of them —
 * on a request whose entire job is to ask "anything for me?". The workspace
 * polls continuously, so that cost landed several times a minute and was enough
 * to exhaust a local Apache's worker pool and return intermittent 500s.
 * admin-ajax boots WordPress without any of it.
 *
 * The REST routes stay registered so anything already pointed at them keeps
 * working; both paths share one collector, so they cannot drift.
 *
 * Cookie-authenticated throughout, because the only caller is a browser tab the
 * administrator has open. The agent never touches these — it goes through
 * abilities, which is what keeps OAuth scopes and the audit log in the path.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('wp_ajax_nibwp_visual_poll', 'nibwp_visual_ajax_poll');
add_action('wp_ajax_nibwp_visual_result', 'nibwp_visual_ajax_result');
add_action('wp_ajax_nibwp_visual_state', 'nibwp_visual_ajax_state');
add_action('wp_ajax_nibwp_visual_check', 'nibwp_visual_ajax_check');

function nibwp_visual_ajax_guard(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    check_ajax_referer('wp_rest', 'nonce');
}

function nibwp_visual_ajax_poll(): void
{
    nibwp_visual_ajax_guard();

    $user_id = get_current_user_id();
    $session = isset($_POST['session']) ? sanitize_key(wp_unslash($_POST['session'])) : '';

    // A tab that has been taken over stops polling rather than competing for
    // commands it would answer with whatever code it happens to be running.
    if (!nibwp_visual_holds($user_id, $session)) {
        wp_send_json(['commands' => [], 'standDown' => true]);
    }

    wp_send_json(nibwp_visual_collect($user_id));
}

function nibwp_visual_ajax_result(): void
{
    nibwp_visual_ajax_guard();

    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    if ($id === '') {
        wp_send_json_error(['message' => 'missing id'], 400);
    }

    $raw = isset($_POST['data']) ? (string) wp_unslash($_POST['data']) : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    $error = isset($_POST['error']) ? sanitize_textarea_field(wp_unslash($_POST['error'])) : '';

    nibwp_visual_answer($id, is_array($data) ? $data : null, $error);
    nibwp_visual_forget(get_current_user_id(), $id);

    wp_send_json(['ok' => true]);
}

function nibwp_visual_ajax_state(): void
{
    nibwp_visual_ajax_guard();

    // Opening the workspace takes it over from any older tab — and counts as
    // being open. The heartbeat used to start only once the first poll ran, so
    // for the round trip in between the workspace was reported shut while it
    // was sitting there ready.
    if (isset($_POST['claim'])) {
        nibwp_visual_claim(get_current_user_id(), sanitize_key(wp_unslash($_POST['claim'])));
        nibwp_visual_touch(get_current_user_id());
    }

    if (isset($_POST['kind'])) {
        nibwp_visual_set_kind(get_current_user_id(), sanitize_key(wp_unslash($_POST['kind'])));
    }

    if (isset($_POST['approval'])) {
        update_option('nibwp_visual_approval', wp_unslash($_POST['approval']) === '1', false);
    }

    wp_send_json(['approval' => nibwp_visual_approval_required()]);
}

/**
 * Answer "is this thing on?" for the person looking at the screen.
 *
 * Three separate things have to be true before an agent can drive this
 * workspace, and when one is missing the symptom is identical: nothing
 * happens. So each is reported on its own rather than as one verdict.
 */
function nibwp_visual_ajax_check(): void
{
    nibwp_visual_ajax_guard();

    $state = nibwp_visual_connection_state();
    $user_id = get_current_user_id();

    wp_send_json([
        'listening' => nibwp_visual_is_open($user_id),
        'abilities' => $state['enabled'],
        'clients' => $state['clients'],
        'connected' => $state['connected'],
        'connectUrl' => admin_url('admin.php?page=nibwp-connect'),
    ]);
}

/**
 * Hold briefly for a command.
 *
 * Answering "nothing" immediately would mean a tab polling every few seconds
 * either wastes requests or adds that lag to every agent action. Waiting here
 * means a command queued a moment after the poll starts is picked up almost at
 * once, and an idle workspace still costs one request every ten seconds.
 *
 * Ten seconds, not thirty: this request occupies a PHP worker for its whole
 * life and the waiting ability occupies a second one, so a workspace costs two
 * of them per action. On a host with four workers, a longer hold is how one
 * open tab starves the site it is trying to inspect.
 *
 * @return array<string, mixed>
 */
function nibwp_visual_collect(int $user_id): array
{
    nibwp_visual_touch($user_id);

    $deadline = microtime(true) + 10;
    $commands = nibwp_visual_take($user_id);

    while ($commands === [] && microtime(true) < $deadline) {
        usleep(300000);
        nibwp_visual_touch($user_id);
        $commands = nibwp_visual_take($user_id);
    }

    return [
        'commands' => $commands,
        'approval' => nibwp_visual_approval_required(),
    ];
}

add_action('rest_api_init', static function (): void {
    $mine = static function (): bool {
        return is_user_logged_in() && current_user_can('manage_options');
    };

    register_rest_route('nibwp/v1', '/visual/poll', [
        'methods' => 'GET',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_poll',
    ]);

    register_rest_route('nibwp/v1', '/visual/result', [
        'methods' => 'POST',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_result',
    ]);

    register_rest_route('nibwp/v1', '/visual/state', [
        'methods' => 'POST',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_state',
    ]);

    register_rest_route('nibwp/v1', '/visual/session', [
        'methods' => 'POST',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_session',
    ]);
});

/**
 * Hand the headless runner a browser session for the account it already is.
 *
 * The workspace is an admin screen behind a cookie, so a runner needed a login
 * — and a login form takes the account password, which an application password
 * cannot stand in for. That put an administrator's real password in whatever
 * env file or crontab started the runner, to do a job that is mostly reading
 * pages. Wrong trade.
 *
 * This closes it: authenticate to the API with a revocable application
 * password, get back the cookies for that same account, and skip wp-login.php
 * entirely. No privilege is granted here that the caller did not already hold —
 * they authenticated as this user to reach this route, and all they receive is
 * a session for themselves. What changes is the blast radius of the secret left
 * sitting on the machine that runs it: an application password can be revoked
 * from Users -> Profile without locking anyone out, and cannot be used to sign
 * in to wp-admin by hand.
 *
 * Short-lived on purpose. The runner is up for hours, not weeks, and a session
 * minted for an unattended process should expire well before anyone would think
 * to go looking for it.
 */
function nibwp_visual_rest_session(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return new WP_REST_Response(['error' => 'no user'], 401);
    }

    $hours = (int) $request->get_param('hours');
    $hours = $hours > 0 ? min($hours, 12) : 4;
    $expiration = time() + $hours * HOUR_IN_SECONDS;

    $manager = WP_Session_Tokens::get_instance($user_id);
    $token = $manager->create($expiration);

    $secure = is_ssl();
    $scheme = $secure ? 'secure_auth' : 'auth';
    $auth_name = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

    $auth = wp_generate_auth_cookie($user_id, $expiration, $scheme, $token);
    $logged_in = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);

    $host = wp_parse_url(home_url(), PHP_URL_HOST);

    // Both paths for the auth cookie, or wp-admin and the plugins directory
    // disagree about whether anyone is signed in.
    $cookies = [
        ['name' => $auth_name, 'value' => $auth, 'path' => ADMIN_COOKIE_PATH],
        ['name' => $auth_name, 'value' => $auth, 'path' => PLUGINS_COOKIE_PATH],
        ['name' => LOGGED_IN_COOKIE, 'value' => $logged_in, 'path' => COOKIEPATH],
    ];

    if (SITECOOKIEPATH !== COOKIEPATH) {
        $cookies[] = ['name' => LOGGED_IN_COOKIE, 'value' => $logged_in, 'path' => SITECOOKIEPATH];
    }

    foreach ($cookies as $i => $cookie) {
        $cookies[$i] = $cookie + [
            'domain' => COOKIE_DOMAIN ?: $host,
            'secure' => $secure,
            'httpOnly' => true,
            'expires' => $expiration,
        ];
    }

    return new WP_REST_Response([
        'cookies' => $cookies,
        'expires' => $expiration,
        'expires_in' => $expiration - time(),
        'user' => wp_get_current_user()->user_login,
        'workspace_url' => admin_url('admin-post.php?action=nibwp_agent_view'),
    ]);
}

function nibwp_visual_rest_poll(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();

    // The poll is the request that already refreshes the heartbeat, so the kind
    // rides along with it. Sending it only on connect would leave capabilities
    // expiring under a workspace that is still there answering.
    $kind = (string) $request->get_param('kind');
    if ($kind !== '') {
        nibwp_visual_set_kind($user_id, sanitize_key($kind));
    }

    // Same stand-down as the ajax path. Without it a runner and a forgotten
    // browser tab both collect from one queue and each answers half the
    // commands, which looks like random failure from the agent's side.
    $session = (string) $request->get_param('session');
    if ($session !== '' && !nibwp_visual_holds($user_id, sanitize_key($session))) {
        return new WP_REST_Response(['commands' => [], 'standDown' => true]);
    }

    return new WP_REST_Response(nibwp_visual_collect($user_id));
}

function nibwp_visual_rest_result(WP_REST_Request $request): WP_REST_Response
{
    $id = sanitize_text_field((string) $request->get_param('id'));
    if ($id === '') {
        return new WP_REST_Response(['ok' => false], 400);
    }

    $data = $request->get_param('data');
    $error = (string) $request->get_param('error');

    nibwp_visual_answer($id, is_array($data) ? $data : null, $error);
    nibwp_visual_forget(get_current_user_id(), $id);

    return new WP_REST_Response(['ok' => true]);
}

function nibwp_visual_rest_state(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();

    if ($request->get_param('approval') !== null) {
        update_option('nibwp_visual_approval', (bool) $request->get_param('approval'), false);
    }

    // Claiming over REST is what lets the headless runner take the workspace
    // from a tab left open, rather than the two of them splitting the queue and
    // each answering half the commands.
    $claim = (string) $request->get_param('claim');
    if ($claim !== '') {
        nibwp_visual_claim($user_id, sanitize_key($claim));
        nibwp_visual_touch($user_id);
    }

    $kind = (string) $request->get_param('kind');
    if ($kind !== '') {
        nibwp_visual_set_kind($user_id, sanitize_key($kind));
    }

    return new WP_REST_Response([
        'approval' => nibwp_visual_approval_required(),
        'workspace' => nibwp_visual_kind($user_id),
    ]);
}
