<?php

declare(strict_types=1);

/**
 * Agent View — the agent's side of the bridge.
 *
 * An MCP server cannot start a conversation: it answers tool calls. So the
 * agent asks. studio-listen holds the call open until the person in Studio
 * sends something, hands it over with everything they pointed at, and the
 * agent answers with studio-reply and listens again. Any client that can call
 * a tool in a loop can work from Studio; nothing here needs a particular one.
 */

if (!defined('ABSPATH')) {
    exit();
}

function nibwp_studio_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('nibwp/studio-listen', [
        'label' => __('Listen for requests from Agent View', 'nibwp'),
        'description' => 'Waits for the next request the person sends from NibWP Agent View (wp-admin → NibWP → Agent View, marked New) and returns it: their text, the page they were looking at, numbered notes on elements they clicked (each with a CSS selector, the element text and an HTML excerpt), uploaded files, and any NibWP skill or workflow they asked you to use. Work on it with your other NibWP tools, report with nibwp/studio-reply, then call this again. Returns {waiting: true} when nothing arrived within the wait, so call it again; returns {stop: true} when the person ended the session, so stop calling it. A request with kind "answer" is the reply to a question you asked with nibwp/studio-ask. A result with a "buddy" field is not a request: it asks for one short line for Niby, the helper in Agent View; answer it with nibwp/studio-buddy-say as its "next" says.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'wait' => ['type' => 'integer', 'description' => 'Seconds to wait for a request before returning {waiting: true}. Default and maximum 20.'],
            ],
        ],
        'execute_callback' => 'nibwp_studio_listen',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/studio-reply', [
        'label' => __('Reply in Agent View', 'nibwp'),
        'description' => 'Posts a message to the person in Agent View. Use done: false for progress while you work, and done: true (the default) with your answer when the request is finished. Short markdown is rendered: paragraphs, **bold**, `code`, lists, links. To show the result, pass navigate with a URL on this site to open it in the page frame of their Agent View, or reload: true to refresh the page they are on.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'integer', 'description' => 'The id from nibwp/studio-listen. Defaults to the request you are working on.'],
                'text' => ['type' => 'string', 'description' => 'What to tell the person.'],
                'done' => ['type' => 'boolean', 'description' => 'True when the request is finished (default). False for a progress update.'],
                'navigate' => ['type' => 'string', 'description' => 'Optional URL or path on this site to open in the Agent View page frame.'],
                'reload' => ['type' => 'boolean', 'description' => 'Optional. Reload the page shown in the Agent View page frame.'],
                'highlight' => ['type' => 'string', 'description' => 'Optional CSS selector to outline on the page shown in the frame.'],
            ],
            'required' => ['text'],
        ],
        'execute_callback' => 'nibwp_studio_reply',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/studio-buddy-say', [
        'label' => __('Speak as Niby in Agent View', 'nibwp'),
        'description' => 'Answers a line request that nibwp/studio-listen returned in a "buddy" field: Niby, the small animated helper in the corner of Agent View, or Kind Guy, its expert, says your text. Only for those requests. It changes nothing on the site.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The buddy.id from nibwp/studio-listen.'],
                'text' => ['type' => 'string', 'description' => 'What is said: plain text, line breaks allowed. As long as the request\'s "next" allows (35 words for a one-liner, up to 120 for Kind Guy\'s answers and reviews).'],
                'actions' => [
                    'type' => 'array',
                    'description' => 'Kind Guy\'s answers only: up to four buttons, each with exactly one of request, reply, skill or workflow.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'Short button label, e.g. "Add focus rings".'],
                            'request' => ['type' => 'string', 'description' => 'The exact request to send to the agent when clicked.'],
                            'reply' => ['type' => 'string', 'description' => 'Instead of request: the person\'s answer to Kind Guy\'s question, sent back to him as their next message.'],
                            'skill' => ['type' => 'string', 'description' => 'Instead of request: a NibWP skill id to attach to the person\'s next request.'],
                            'workflow' => ['type' => 'string', 'description' => 'Instead of request: a NibWP workflow slug to attach to the person\'s next request.'],
                            'selector' => ['type' => 'string', 'description' => 'Optional CSS selector of the element on the page this action changes. Kind Guy points at it and highlights it while offering the action.'],
                        ],
                        'required' => ['label'],
                    ],
                ],
                'point' => ['type' => 'string', 'description' => 'Kind Guy\'s answers only: optional CSS selector of the element on the page he is talking about. He points at it with his hand and highlights it, with his comment beside it.'],
            ],
            'required' => ['id', 'text'],
        ],
        'execute_callback' => 'nibwp_studio_buddy_say',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/studio-ask', [
        'label' => __('Ask the person in Agent View', 'nibwp'),
        'description' => 'Shows a question in Agent View, with buttons when you pass options, and lets the person answer without leaving the page. Use it before anything they would want to confirm, or when the request is ambiguous. The answer arrives through nibwp/studio-listen as kind "answer" with this ask_id, so call nibwp/studio-listen next. Never offer a choice between two tools that do the same job, or between editions of one product (Etchedy and Etchedy Pro, Bricksed and Bricksed Pro): pick the right one yourself.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'integer', 'description' => 'The request this question is about. Defaults to the one you are working on.'],
                'question' => ['type' => 'string', 'description' => 'The question, in one or two sentences.'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to 6 answers, up to 120 characters each, shown as a list to pick from, e.g. ["Apply", "Cancel"].'],
                'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: a preview image URL (http or https) for each option, in the same order; an empty string for an option without one. Shown as a thumbnail, and large on hover, so the person sees what they pick. Use it whenever the choices are things with a picture, such as Etchedy or Bricksed components: their search results carry each one\'s preview URL.'],
                'allow_text' => ['type' => 'boolean', 'description' => 'Also offer a text field for a free answer. Default true when there are no options.'],
            ],
            'required' => ['question'],
        ],
        'execute_callback' => 'nibwp_studio_ask',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    // The Builder's CSS framework: read and set it for the person.
    if (function_exists('nibwp_framework_register_abilities')) {
        nibwp_framework_register_abilities();
    }
    // The static site: publish it and report on it.
    if (function_exists('nibwp_static_register_abilities')) {
        nibwp_static_register_abilities();
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_studio_listen(array $input = [])
{
    $uid = get_current_user_id();
    if ($uid <= 0) {
        return new WP_Error('nibwp_studio_no_user', 'Agent View requests belong to a WordPress account; this connection is not signed in as one.');
    }

    $wait = isset($input['wait']) ? (int) $input['wait'] : NIBWP_STUDIO_LISTEN_MAX;
    $deadline = microtime(true) + max(0, min(NIBWP_STUDIO_LISTEN_MAX, $wait));

    // A request the agent picked up and never closed would otherwise keep
    // Studio saying "working" for ten minutes after the agent moved on. Not
    // while a question is out, though: an agent that asked something listens
    // for the answer still holding its request.
    $close_held = static function () use ($uid): void {
        $held = (int) get_user_meta($uid, 'nibwp_studio_active', true);
        if ($held > 0) {
            nibwp_studio_set_state($held, 'done');
            delete_user_meta($uid, 'nibwp_studio_active');
        }
    };

    if (time() - (int) get_user_meta($uid, 'nibwp_studio_listen', true) > NIBWP_STUDIO_LISTENING_TTL) {
        nibwp_studio_mark_work($uid);
    }
    nibwp_studio_mark_listening($uid);

    while (true) {
        wp_cache_delete($uid, 'user_meta');
        $stop_at = (int) get_user_meta($uid, 'nibwp_studio_stop', true);
        $rest = get_user_meta($uid, 'nibwp_studio_stop_reason', true) === 'rest';
        if ($stop_at > 0) {
            delete_user_meta($uid, 'nibwp_studio_stop');
            delete_user_meta($uid, 'nibwp_studio_stop_reason');
        }
        // A word left for an agent that never came back is not for this one:
        // a rest is asked of an agent listening at that moment, and an end
        // reaches one finishing its job within minutes. Older, it would stop
        // the next agent the moment it arrives.
        if ($stop_at > 0 && time() - $stop_at <= ($rest ? 3 : 10) * MINUTE_IN_SECONDS) {
            delete_user_meta($uid, 'nibwp_studio_listen');
            delete_user_meta($uid, 'nibwp_studio_listeners');
            $close_held();
            nibwp_studio_add($uid, 'event', '', ['type' => 'stopped', 'reason' => $rest ? 'rest' : 'end']);

            return [
                'stop' => true,
                'message' => $rest
                    ? 'Agent View put you to rest after a quiet stretch. Stop calling nibwp/studio-listen and tell the person in one short line that you are resting and that pasting the wake-up line from Agent View brings you back.'
                    : 'The person ended the Agent View session. Stop calling nibwp/studio-listen.',
            ];
        }

        $item = nibwp_studio_claim_next($uid);
        if ($item !== null) {
            nibwp_studio_mark_listening($uid);
            nibwp_studio_mark_work($uid);
            if ($item['kind'] === 'prompt') {
                $close_held();
                delete_user_meta($uid, 'nibwp_studio_halt');
                update_user_meta($uid, 'nibwp_studio_active', $item['id']);
            } else {
                nibwp_studio_set_state($item['id'], 'done');
            }

            return nibwp_studio_for_agent($item);
        }

        $ask = nibwp_studio_buddy_take_ask($uid);
        if ($ask !== null) {
            nibwp_studio_mark_listening($uid);

            return nibwp_studio_buddy_for_agent($ask);
        }

        if (microtime(true) >= $deadline) {
            $idle = time() - (int) get_user_meta($uid, 'nibwp_studio_work', true);
            if ($idle > 15 * MINUTE_IN_SECONDS && time() > (int) get_user_meta($uid, 'nibwp_studio_keep', true) && !nibwp_studio_is_open($uid) && !nibwp_studio_has_open_ask($uid) && !(int) get_user_meta($uid, 'nibwp_studio_active', true)) {
                update_user_meta($uid, 'nibwp_studio_stop', time());
                update_user_meta($uid, 'nibwp_studio_stop_reason', 'rest');
                continue;
            }
            break;
        }
        usleep(800000);
    }

    if (!nibwp_studio_has_open_ask($uid)) {
        $close_held();
    }
    nibwp_studio_mark_listening($uid);

    return ['waiting' => true, 'next' => 'Nothing yet, and that is normal. Call nibwp/studio-listen again right away and keep listening: do not stop, summarise or ask the person whether to continue during quiet stretches. Agent View checks in with them and returns stop when it is time to rest.'];
}

/**
 * A request as the agent should read it: what was asked, what it refers to,
 * and what to do next.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function nibwp_studio_for_agent(array $item): array
{
    if ($item['kind'] === 'answer') {
        return [
            'kind' => 'answer',
            'ask_id' => (int) ($item['data']['ask_id'] ?? 0),
            'choice' => (string) ($item['data']['choice'] ?? ''),
            'text' => $item['text'],
            'next' => 'Continue the request this question was about, then reply with nibwp/studio-reply.',
        ];
    }

    $context = $item['data'];
    $out = [
        'kind' => 'request',
        'request_id' => $item['id'],
        'text' => $item['text'],
    ] + $context;

    if (!empty($context['annotations'])) {
        $out['about_annotations'] = 'Each annotation is a numbered note the person pinned on an element of the page. "note" is what they want there; "selector", "text" and "html" identify the element. Find the element in the post or template that renders it before changing anything. A note with "shape" was drawn: a box, circle or freehand "pen" line around part of the page. shape.x/y/w/h is the drawn area in page pixels at the width in page.viewport, and "selector" is the smallest element that holds it. The drawing stays on the page in Agent View, so a nibwp/visual-screenshot shows what was marked. A drawn mark can come without a note of its own: the person drew several and said once, in the message text or another note, what they are about. A note with "canvas" was made inside a page builder\'s editor canvas (for example #etch-iframe or #bricks-builder-iframe, the frame that selector names in page.url): its selector, box and shape belong to the page inside that frame, which is the page being edited.';
    }
    if (!empty($context['skills'])) {
        $out['about_skills'] = 'The person asked for these NibWP skills. Load each one with nibwp/load-skill-playbook before building.';
    }
    if (!empty($context['workflows'])) {
        $out['about_workflows'] = 'The person asked you to follow these NibWP workflows. Read each one with nibwp/get-workflow (slug) and follow its steps.';
    }
    // How the person wants this handled.
    if (function_exists('nibwp_studio_bypass_mark')) {
        nibwp_studio_bypass_mark(($context['mode'] ?? '') === 'bypass');
    }
    if (!empty($context['mode'])) {
        $out['mode'] = $context['mode'];
        $out['about_mode'] = $context['mode'] === 'plan'
            ? 'Plan only: look, read and think, then reply with what you found and a short step-by-step plan. Change nothing on the site until the person says go.'
            : 'Bypass: do not stop to ask the person questions. Make the sensible choice yourself, say briefly what you chose, and finish the job.';
    }
    if (!empty($context['title'])) {
        $out['title'] = $context['title'];
    }
    if (!empty($context['design']) && function_exists('nibwp_studio_builder_outline')) {
        $out['design'] = [
            'name' => $context['design']['name'],
            'brief' => $context['design']['brief'],
            'place' => $context['design']['place'] ?? ['mode' => 'end'],
            'part' => $context['design']['part'] ?? '',
            'drawn_at' => $context['design']['device'] . ', ' . $context['design']['width'] . 'px wide',
            'outline' => nibwp_studio_builder_outline($context['design']),
        ];
        $out['about_design'] = nibwp_studio_builder_brief($context['design']);
    }
    if (function_exists('nibwp_framework_agent_note')) {
        $out['about_css'] = nibwp_framework_agent_note();
    }
    if (($context['expert'] ?? '') === 'on_duty') {
        $out['about_expert'] = 'Kind Guy, the expert in Agent View, is on duty: he inspects this work once you finish. Build it the way he will check it: WordPress coding standards, design tokens with fallbacks and clean naming, accessible (contrast, labels, focus, keyboard, alt text), responsive at phone, tablet and desktop, and the right tool or workflow for the job. In your final reply, say in one line what you checked.';
    }

    $recent = [];
    foreach (array_slice(nibwp_studio_since((int) $item['user'], 0), -12) as $line) {
        if ($line['id'] === $item['id'] || !in_array($line['kind'], ['prompt', 'reply', 'ask', 'answer'], true)) {
            continue;
        }
        $recent[] = ['from' => in_array($line['kind'], ['prompt', 'answer'], true) ? 'person' : 'agent', 'text' => mb_substr($line['text'], 0, 400)];
    }
    if ($recent) {
        $out['earlier_in_thread'] = array_slice($recent, -6);
    }

    $out['next'] = 'Do the work with your NibWP tools. Post progress with nibwp/studio-reply {request_id, text, done: false}; ask with nibwp/studio-ask when you need a decision; finish with nibwp/studio-reply {request_id, text, done: true} and navigate or reload so they see the result. Then call nibwp/studio-listen again.';

    return $out;
}

/**
 * Accept a URL on this site, absolute or relative; refuse anywhere else.
 */
function nibwp_studio_site_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = home_url($url);
    }

    $host = wp_parse_url($url, PHP_URL_HOST);
    $home = wp_parse_url(home_url(), PHP_URL_HOST);

    return is_string($host) && strcasecmp($host, (string) $home) === 0 ? esc_url_raw($url) : '';
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_studio_reply(array $input)
{
    $uid = get_current_user_id();
    $request = (int) ($input['request_id'] ?? 0) ?: (int) get_user_meta($uid, 'nibwp_studio_active', true);
    $done = !array_key_exists('done', $input) || !empty($input['done']);
    $text = trim((string) ($input['text'] ?? ''));

    $navigate = nibwp_studio_site_url((string) ($input['navigate'] ?? ''));
    if (($input['navigate'] ?? '') !== '' && $navigate === '') {
        return new WP_Error('nibwp_studio_offsite', 'navigate must be a URL or path on this site.');
    }

    $data = array_filter([
        'request_id' => $request ?: null,
        'navigate' => $navigate ?: null,
        'reload' => !empty($input['reload']) ?: null,
        'highlight' => mb_substr(trim((string) ($input['highlight'] ?? '')), 0, 600) ?: null,
    ], static fn($v) => $v !== null);

    $id = nibwp_studio_add($uid, $done ? 'reply' : 'progress', $text, $data);
    if ($id === 0) {
        return new WP_Error('nibwp_studio_write', 'The reply could not be saved.');
    }
    nibwp_studio_mark_listening($uid);
    nibwp_studio_mark_work($uid);

    // The agent's last word on a request the person stopped: it stays stopped.
    $halted = (int) get_user_meta($uid, 'nibwp_studio_halt', true);
    if ($done) {
        delete_user_meta($uid, 'nibwp_studio_halt');
    }
    if ($done && $request > 0 && $request !== $halted) {
        nibwp_studio_set_state($request, 'done');
        if ((int) get_user_meta($uid, 'nibwp_studio_active', true) === $request) {
            delete_user_meta($uid, 'nibwp_studio_active');
        }
    }

    return [
        'posted' => $id,
        'shown' => nibwp_studio_is_open($uid),
        'next' => $done ? 'Call nibwp/studio-listen to wait for the next request.' : 'Keep working, then reply with done: true.',
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_studio_ask(array $input)
{
    $uid = get_current_user_id();
    $question = trim((string) ($input['question'] ?? ''));
    if ($question === '') {
        return new WP_Error('nibwp_studio_empty', 'question is required.');
    }

    $options = array_values(array_filter(array_map(
        static fn($o) => mb_substr(trim(is_scalar($o) ? (string) $o : ''), 0, 120),
        array_slice((array) ($input['options'] ?? []), 0, 6)
    )));
    $allow_text = array_key_exists('allow_text', $input) ? !empty($input['allow_text']) : !$options;

    // A picture per option: a link to where it already lives, never a copy.
    $raw_images = array_values((array) ($input['images'] ?? []));
    $images = [];
    foreach ($options as $i => $unused) {
        $url = is_string($raw_images[$i] ?? null) ? trim($raw_images[$i]) : '';
        $images[] = $url !== '' && strlen($url) <= 2000 ? esc_url_raw($url, ['http', 'https']) : '';
    }

    $data = [
        'request_id' => (int) ($input['request_id'] ?? 0) ?: (int) get_user_meta($uid, 'nibwp_studio_active', true),
        'options' => $options,
        'allow_text' => $allow_text,
    ];
    if (array_filter($images)) {
        $data['images'] = $images;
    }
    $id = nibwp_studio_add($uid, 'ask', $question, $data, 'open');

    if ($id === 0) {
        return new WP_Error('nibwp_studio_write', 'The question could not be saved.');
    }
    nibwp_studio_mark_listening($uid);

    return [
        'ask_id' => $id,
        'shown' => nibwp_studio_is_open($uid),
        'next' => 'Call nibwp/studio-listen: the answer arrives as kind "answer" with this ask_id.',
    ];
}

/* ---------------------------------------------------------------------------
 * Showing the work. While the agent holds a request, each tool it runs becomes
 * a line in the thread, and a post it saves reloads the frame if it is the one
 * on screen. The person sees the steps as they happen, not a still page and a
 * paragraph at the end.
 * ------------------------------------------------------------------------- */

add_filter('mcp_adapter_pre_tool_call', 'nibwp_studio_narrate', 30, 2);

/**
 * @param mixed $args
 * @return mixed
 */
function nibwp_studio_narrate($args, $tool_name = '')
{
    $uid = get_current_user_id();
    $tool = (string) $tool_name;
    $params = is_array($args) ? $args : [];

    // Called through the adapter's generic runner, the real ability is inside.
    if (isset($params['ability_name']) && is_string($params['ability_name'])) {
        $tool = $params['ability_name'];
        $params = is_array($params['parameters'] ?? null) ? $params['parameters'] : [];
    }

    if ($uid <= 0 || $tool === '' || str_contains($tool, 'studio-') || str_contains($tool, 'discover-abilities') || str_contains($tool, 'get-ability-info')) {
        return $args;
    }

    if ((int) get_user_meta($uid, 'nibwp_studio_halt', true) > 0) {
        return new WP_Error(
            'nibwp_studio_halted',
            'The person pressed Stop in Agent View. Stop working on that request now and call no more tools for it. Tell them in one short line with nibwp/studio-reply {done: true} what you finished before stopping, then call nibwp/studio-listen.'
        );
    }

    $active = (int) get_user_meta($uid, 'nibwp_studio_active', true);
    if ($active <= 0) {
        // Looking around to answer Kind Guy or Niby: still here, still busy.
        if (time() - (int) get_user_meta($uid, 'nibwp_studio_buddy_busy', true) <= 300) {
            nibwp_studio_mark_listening($uid);
        }

        return $args;
    }

    $short = str_contains($tool, '/') ? substr((string) strrchr($tool, '/'), 1) : $tool;
    nibwp_studio_mark_work($uid);
    nibwp_studio_add($uid, 'activity', $short, [
        'request_id' => $active,
        'detail' => function_exists('nibwp_visual_narrate_detail') ? nibwp_visual_narrate_detail($params) : '',
    ]);
    nibwp_studio_mark_listening($uid);

    return $args;
}

add_action('save_post', 'nibwp_studio_follow_save', 20, 3);

function nibwp_studio_follow_save(int $post_id, $post, bool $update): void
{
    if (!$post instanceof WP_Post || $post->post_type === NIBWP_STUDIO_CPT || !empty($GLOBALS['nibwp_studio_restoring'])) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status === 'auto-draft') {
        return;
    }
    if (!post_type_exists($post->post_type) || !is_post_type_viewable($post->post_type)) {
        return;
    }

    $uid = get_current_user_id();
    if ($uid <= 0 || !nibwp_studio_is_open($uid)) {
        return;
    }

    // One save fires save_post more than once (meta boxes, builders saving
    // twice). One line per post every few seconds is enough to reload by.
    $key = 'nibwp_studio_saved_' . $uid . '_' . $post_id;
    if (get_transient($key)) {
        return;
    }
    set_transient($key, 1, 5);

    // Undo goes back to the version this save replaced.
    $undo = nibwp_studio_previous_revision($post_id);

    nibwp_studio_add($uid, 'event', (string) $post->post_title, [
        'type' => 'saved',
        'post_id' => $post_id,
        'url' => (string) get_permalink($post_id),
        'edit' => (string) get_edit_post_link($post_id, 'raw'),
        'by_agent' => (int) get_user_meta($uid, 'nibwp_studio_active', true) > 0,
        // The job it belongs to: Undo on a finished job puts back all of its saves.
        'request_id' => (int) get_user_meta($uid, 'nibwp_studio_active', true),
        'undo' => $undo,
    ]);
}

/** Niby says the agent's line in Agent View. Plain text, kept short. */
function nibwp_studio_buddy_say(array $input)
{
    $uid = get_current_user_id();
    $id = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['id'] ?? ''));
    // Line breaks kept (a review is a verdict and a list); other runs of space folded.
    $text = wp_strip_all_tags((string) ($input['text'] ?? ''));
    $text = (string) preg_replace('/[ \t]+/u', ' ', (string) preg_replace('/\R{3,}/u', "\n\n", str_replace("\r", '', $text)));
    // Room for an answer with a short code block in it.
    $text = mb_substr(trim($text), 0, 3000);
    if ($id === '' || $text === '') {
        return new WP_Error('nibwp_studio_buddy_empty', 'Pass the buddy id from nibwp/studio-listen and the text for Niby.');
    }
    nibwp_studio_buddy_answer($uid, $id, $text, nibwp_studio_clean_actions($input['actions'] ?? []), nibwp_studio_clean_selector($input['point'] ?? ''));
    nibwp_studio_mark_listening($uid);

    return ['said' => true, 'next' => 'Call nibwp/studio-listen again to keep listening.'];
}
