<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Paper screen in wp-admin: pair the bridge, then work the library.
 *
 * This is deliberately NOT the Figma connect flow with the token field filed
 * off. There is no token to file off: Paper's MCP server listens on 127.0.0.1
 * on the designer's own machine, so this site has nothing to authenticate
 * against and nothing to store. What it CAN do is record that an agent proved
 * it could see Paper, and that record is what "connected" means here — reported
 * by the agent, dated, attributed to an account, and clearable. The page says
 * that in as many words rather than showing a green light it cannot stand
 * behind.
 *
 * On the markup: the shell, the tabs, the cards, the copy chips and the library
 * grid are the Figma screen's own classes, because admin-styles.php points this
 * page at that stylesheet and the two design screens should be one surface. The
 * first version of this file linked its tabs as anchors, which cost a page load
 * per tab and put WordPress's link blue on top of the active tab's blue; all
 * four panels now ship in one response and switch in the browser.
 *
 * What Figma has no equivalent for gets its own component in admin-paper.css:
 * the setup walkthrough (used by both the Connect and How to panels), the two
 * capability lists, the builder cards, and a prompt block. That last one exists
 * because Figma's prompt chip is built for a one-line prompt and Paper's are
 * paragraphs — reusing it turned a 300-character instruction into a slab.
 */

/**
 * The prompt a designer pastes into their agent to pair the bridge.
 *
 * Pairing cannot be a button on this page, because the thing that has to be
 * checked is on the other side of the agent. So the button we CAN offer is one
 * that copies the instruction, and the agent does the part only it can do.
 */
function nibwp_paper_pair_prompt(): string
{
    return __(
        'Pair my Paper bridge with this WordPress site. First list the tools your Paper MCP connection exposes — the Paper desktop app has to be running, because that is what starts the server. Then call the NibWP tool nibwp/paper-manage with action "pair", passing paper_tools set to the tool names you found, paper_version if Paper reports one, and agent set to your own name. Tell me what came back.',
        'nibwp'
    );
}

/**
 * The prompt for one design already in the library, by its handle.
 *
 * This is the card's primary affordance. The library's job is to hold what the
 * agent pulled over and hand you the words that put it to work — the builder
 * choice belongs to the agent's plan, not to a dropdown on a card that cannot
 * honour it.
 */
function nibwp_paper_design_prompt(string $handle): string
{
    return sprintf(
        /* translators: %s: design handle, e.g. @paper/aurora-landing-hero */
        __('Build %s on this site. Read it from the library with nibwp/paper-manage action "get_design", plan the conversion with nibwp/paper-pro-plan, then show me the plan and wait for my go-ahead before writing anything.', 'nibwp'),
        $handle
    );
}

/** The prompt for the actual work, once the bridge is paired. */
function nibwp_paper_handover_prompt(): string
{
    return __(
        'Take the frame I have selected in Paper and build it on this site. Read it through your Paper MCP connection, send it to nibwp/paper-manage with action "ingest", then run nibwp/paper-pro-plan on what came back. Show me the plan and wait — do not convert anything until I say so.',
        'nibwp'
    );
}

/**
 * The handover, in order. Kept as data so the KB, the ability and this screen
 * cannot describe three different sequences.
 *
 * @return array<int, string>
 */
function nibwp_paper_handover_steps(): array
{
    return [
        __('Select the frame in Paper. The agent reads what is selected, so what you pick decides what arrives.', 'nibwp'),
        __('The agent reads it through its own Paper MCP connection — the markup, the computed styles, the fonts and any exported images.', 'nibwp'),
        __('It sends that to ingest here, where it is normalised into the shared design object and kept in the library.', 'nibwp'),
        __('paper-pro plans the conversion: which builder, what repeats, which colours already exist as tokens on this site.', 'nibwp'),
        __('You approve, and convert writes a draft. Nothing is published.', 'nibwp'),
        __('audit afterwards reports designs received but never built, and drafts whose design has changed since.', 'nibwp'),
    ];
}

/** Send the admin back to this page carrying a message. */
function nibwp_paper_page_redirect(array $args): void
{
    wp_safe_redirect(add_query_arg($args, nibwp_paper_page_url()));
    exit();
}

/**
 * Turn whatever the hub answered into one redirect argument.
 *
 * Every form on this page goes through nibwp_paper_manage(), so the guard, the
 * capability and the validation are enforced in exactly one place and this
 * screen cannot drift from what the ability allows.
 *
 * @param array<string, mixed> $input
 * @return array<string, string>
 */
function nibwp_paper_page_call(array $input, string $success): array
{
    $result = nibwp_paper_manage($input);

    if (is_wp_error($result)) {
        return ['paper_err' => $result->get_error_message()];
    }

    return ['paper_msg' => $success];
}

/* ───────────────────────────── form handlers ───────────────────────────── */

/** Clear the pairing record. */
function nibwp_paper_handle_unpair(): void
{
    check_admin_referer('nibwp_paper_unpair');

    nibwp_paper_page_redirect(nibwp_paper_page_call(
        ['action' => 'unpair'],
        __('Bridge unpaired. The design library was left alone.', 'nibwp')
    ));
}

/** Remove one design from the library. */
function nibwp_paper_handle_delete(): void
{
    check_admin_referer('nibwp_paper_delete');

    $design = isset($_POST['design_id']) ? sanitize_text_field((string) wp_unslash($_POST['design_id'])) : '';

    nibwp_paper_page_redirect(nibwp_paper_page_call(
        ['action' => 'delete_design', 'design_id' => $design],
        __('Design removed from the library.', 'nibwp')
    ));
}

/** Rename one design. */
function nibwp_paper_handle_rename(): void
{
    check_admin_referer('nibwp_paper_rename');

    $design = isset($_POST['design_id']) ? sanitize_text_field((string) wp_unslash($_POST['design_id'])) : '';
    $name   = isset($_POST['name']) ? sanitize_text_field((string) wp_unslash($_POST['name'])) : '';

    nibwp_paper_page_redirect(nibwp_paper_page_call(
        ['action' => 'rename_design', 'design_id' => $design, 'name' => $name],
        __('Design renamed.', 'nibwp')
    ));
}

/** Build a draft from one design. */
function nibwp_paper_handle_convert(): void
{
    check_admin_referer('nibwp_paper_convert');

    $design  = isset($_POST['design_id']) ? sanitize_text_field((string) wp_unslash($_POST['design_id'])) : '';
    $builder = isset($_POST['builder']) ? sanitize_key((string) wp_unslash($_POST['builder'])) : '';
    $title   = isset($_POST['title']) ? sanitize_text_field((string) wp_unslash($_POST['title'])) : '';

    $result = nibwp_paper_manage([
        'action'    => 'convert',
        'design_id' => $design,
        'builder'   => $builder,
        'title'     => $title,
        // The screen's convert button is the explicit one. A dry run has its own
        // place in the agent flow; a person who clicked "Create draft" asked for
        // a draft.
        'dry_run'   => false,
    ]);

    if (is_wp_error($result)) {
        nibwp_paper_page_redirect(['paper_err' => $result->get_error_message()]);
    }

    /*
     * Straight into the editor, where the draft is. Coming back to the library
     * to be told a draft exists, with a link to go and look at it, was a step
     * for its own sake. Only a conversion that produced no page — a handoff
     * rather than a draft — leaves anything for this screen to report.
     */
    $post_id = (int) ($result['post_id'] ?? 0);
    if ($post_id > 0) {
        wp_safe_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
        exit();
    }

    nibwp_paper_page_redirect(['paper_msg' => __('Converted.', 'nibwp')]);
}

/**
 * Load or remove the sample designs.
 *
 * Deliberately NOT an action on the hub. Seeding fake designs is an affordance
 * for somebody judging this screen, not something an agent should ever do to a
 * live library — so it lives here, behind manage_options and a nonce, and the
 * MCP surface has no way to reach it.
 */
function nibwp_paper_handle_samples(): void
{
    check_admin_referer('nibwp_paper_samples');

    if (!current_user_can('manage_options')) {
        nibwp_paper_page_redirect(['paper_err' => __('Loading sample designs needs the manage_options capability.', 'nibwp')]);
    }

    if (!method_exists('NIBWP_Paper_Library', 'samples_load')) {
        nibwp_paper_page_redirect(['paper_err' => __('This build has no sample designs.', 'nibwp')]);
    }

    $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : '';

    if ($mode === 'remove') {
        $removed = (int) NIBWP_Paper_Library::samples_remove();
        nibwp_paper_page_redirect(['paper_msg' => sprintf(
            /* translators: %d: number of sample designs removed */
            _n('%d sample removed. Your own designs were left alone.', '%d samples removed. Your own designs were left alone.', $removed, 'nibwp'),
            $removed
        )]);
    }

    if ($mode !== 'load') {
        nibwp_paper_page_redirect(['paper_err' => __('Unknown sample action.', 'nibwp')]);
    }

    $added = (int) NIBWP_Paper_Library::samples_load();
    nibwp_paper_page_redirect($added > 0
        ? ['paper_msg' => sprintf(
            /* translators: %d: number of sample designs loaded */
            _n('%d sample design loaded. Remove them whenever you like.', '%d sample designs loaded. Remove them whenever you like.', $added, 'nibwp'),
            $added
        )]
        : ['paper_msg' => __('The samples are already loaded.', 'nibwp')]);
}

add_action('admin_post_nibwp_paper_samples', 'nibwp_paper_handle_samples');
add_action('admin_post_nibwp_paper_rename', 'nibwp_paper_handle_rename');
add_action('admin_post_nibwp_paper_unpair', 'nibwp_paper_handle_unpair');
add_action('admin_post_nibwp_paper_delete', 'nibwp_paper_handle_delete');
add_action('admin_post_nibwp_paper_convert', 'nibwp_paper_handle_convert');

/* ───────────────────────────── copy chips ───────────────────────────── */

/** The clipboard icon both chips carry. */
function nibwp_paper_copy_icon(): string
{
    return '<svg class="nw-figma-copy__icon" width="13" height="13" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
}

/**
 * A terminal command, click to copy.
 *
 * nw-figma-hint isolates its text direction, which matters: a command pasted
 * into a terminal reads left to right even on the Arabic admin.
 */
function nibwp_paper_echo_command(string $command): void
{
    echo '<button type="button" class="nw-figma-hint nw-figma-copy" data-copy="' . esc_attr($command) . '"'
        . ' title="' . esc_attr__('Copy', 'nibwp') . '">'
        . '<span class="nw-figma-copy__text" data-copied-label="' . esc_attr__('copied', 'nibwp') . '">'
        . esc_html($command) . '</span>'
        . nibwp_paper_copy_icon()
        . '</button>';
}

/**
 * A prompt to hand to the agent: the text as a quoted block, with an ordinary
 * small copy button.
 *
 * Not Figma's prompt chip. That one is a full-width button holding its own text,
 * which works for "build @figma/hero-section" and turns Paper's paragraph-length
 * instructions into an enormous slab of clickable text.
 */
function nibwp_paper_echo_prompt(string $prompt): void
{
    echo '<div class="nw-paper-prompt">';
    echo '<p class="nw-paper-prompt__text">' . esc_html($prompt) . '</p>';
    echo '<button type="button" class="nw-paper-prompt__copy nw-figma-copy" data-copy="' . esc_attr($prompt) . '">'
        . '<span class="nw-figma-copy__text" data-copied-label="' . esc_attr__('copied', 'nibwp') . '">'
        . esc_html__('Copy prompt', 'nibwp') . '</span>'
        . nibwp_paper_copy_icon()
        . '</button>';
    echo '</div>';
}

/**
 * One step of the setup walkthrough.
 *
 * The Connect panel and the How to panel are the same component: a numbered
 * sequence where one step is the one to act on. $state is '', 'is-done',
 * 'is-current' or 'is-todo' — empty for the How to panel, whose steps describe
 * what happens rather than asking anybody to do something.
 */
function nibwp_paper_echo_step(int $number, string $title, string $text, string $state = '', callable $extra = null): void
{
    printf('<li class="nw-paper-walk__step%s">', $state === '' ? '' : ' ' . esc_attr($state));
    echo '<span class="nw-paper-walk__marker">'
        . ($state === 'is-done' ? '&#10003;' : esc_html((string) $number))
        . '</span>';
    echo '<div class="nw-paper-walk__body">';
    if ($title !== '') {
        echo '<strong class="nw-paper-walk__title">' . esc_html($title) . '</strong>';
    }
    if ($text !== '') {
        echo '<p class="nw-paper-walk__text">' . esc_html($text) . '</p>';
    }
    if ($extra !== null) {
        $extra();
    }
    echo '</div></li>';
}

/* ───────────────────────────── the screen ───────────────────────────── */

function nibwp_paper_render_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!nibwp_paper_unlocked()) {
        echo '<div class="wrap"><h1>' . esc_html__('Paper.design', 'nibwp') . '</h1>'
            . '<p>' . esc_html__('The Paper bridge is a Pro feature. Activate a NibWP Pro or Bundle license to use it.', 'nibwp') . '</p>'
            . '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=nibwp-license')) . '">'
            . esc_html__('Manage license', 'nibwp') . '</a></p></div>';
        return;
    }

    $bridge    = nibwp_paper_bridge();
    $paired    = nibwp_paper_is_connected();
    $setup     = nibwp_paper_setup_lines();
    $verify    = nibwp_paper_verifiability();
    $library   = NIBWP_Paper_Library::summary();
    $builders  = nibwp_paper_list_builders();
    $notice    = isset($_GET['paper_msg']) ? sanitize_text_field((string) wp_unslash($_GET['paper_msg'])) : '';
    $error     = isset($_GET['paper_err']) ? sanitize_text_field((string) wp_unslash($_GET['paper_err'])) : '';
    $converted = isset($_GET['converted']) ? (int) $_GET['converted'] : 0;
    $designs   = (int) $library['designs'];

    $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
    if (!in_array($tab, ['connect', 'library', 'howto'], true)) {
        // Land where the work is: the connect panel until the bridge is paired,
        // the library once it is.
        $tab = $paired ? 'library' : 'connect';
    }

    if (function_exists('nibwp_render_admin_header')) {
        nibwp_render_admin_header();
    }

    echo '<div class="wrap nibwp-wrap">';
    echo '<div class="nibwp-page-header"><div>';
    echo '<h1>' . esc_html__('Paper.design', 'nibwp') . ' <span class="nw-figma__beta">'
        . esc_html_x('BETA', 'badge', 'nibwp') . '</span></h1>';
    echo '<p class="nibwp-subtitle">'
        . esc_html__('Designs handed over from paper.design by your agent, normalised and kept here until you convert them.', 'nibwp')
        . ' ' . esc_html(sprintf(
            /* translators: %d: number of designs in the library */
            _n('%d design in the library.', '%d designs in the library.', $designs, 'nibwp'),
            $designs
        )) . '</p>';
    echo '</div></div>';

    if ($error !== '') {
        echo '<p class="nw-figma-note nw-figma-note--err">' . esc_html($error) . '</p>';
    }
    if ($notice !== '') {
        echo '<p class="nw-figma-note nw-figma-note--ok">' . esc_html($notice) . '</p>';
    }
    if ($converted > 0) {
        echo '<p class="nw-figma-note nw-figma-note--ok"><strong>' . esc_html__('Draft created.', 'nibwp') . '</strong> '
            . '<a href="' . esc_url((string) get_edit_post_link($converted, 'url')) . '">' . esc_html__('Edit', 'nibwp') . '</a> · '
            . '<a href="' . esc_url((string) get_permalink($converted)) . '" target="_blank" rel="noreferrer">'
            . esc_html__('Preview', 'nibwp') . '</a></p>';
    }

    echo '<div class="nw-figma">';

    /* The status strip, before the tabs: the one line somebody opening this
       screen needs, whichever panel they then go to. */
    echo '<div class="nw-figma-status' . ($paired ? ' is-on' : '') . '">';
    echo '<span class="nw-figma-dot' . ($paired ? ' is-on' : '') . '"></span>';
    echo '<div>';
    if ($paired) {
        echo '<strong>' . esc_html__('Bridge paired', 'nibwp') . '</strong>';
        echo '<span>' . esc_html(sprintf(
            /* translators: 1: agent name, 2: number of Paper tools, 3: human-readable time ago */
            __('%1$s reported %2$d Paper tools %3$s ago.', 'nibwp'),
            (string) ($bridge['agent'] ?? __('An agent', 'nibwp')),
            (int) ($bridge['tool_count'] ?? 0),
            human_time_diff((int) ($bridge['paired_at'] ?? 0))
        )) . '</span>';
    } else {
        echo '<strong>' . esc_html__('Not paired yet', 'nibwp') . '</strong>';
        echo '<span>' . esc_html__('Three steps on the Connect tab, about a minute.', 'nibwp') . '</span>';
    }
    echo '</div></div>';

    /* Tabs. Buttons, not links: the panels are all on the page and the switch is
       a class toggle, so there is no page load and no anchor styling fighting
       the active state. */
    /*
     * "Connection", not "Connect", because that is what the Figma screen calls
     * the same tab. The two design screens are one surface and should not use
     * two words for one idea; the tab KEY stays `connect` so existing links and
     * the Integrations card's Connect button keep resolving.
     */
    /*
     * Three tabs, matching the Figma screen. Builders had one of its own and did
     * not earn it: on most sites it was eight cards of which six said "Not
     * installed". The half that mattered — a builder present here whose skill is
     * switched off, which is why a handover lands as core blocks — now sits in
     * How to, beside the explanation of what happens.
     */
    $tabs = [
        'connect'  => __('Connection', 'nibwp'),
        'library'  => __('Library', 'nibwp'),
        'howto'    => __('How to', 'nibwp'),
    ];
    echo '<div class="nw-int-tabs-wrap nw-figma-tabsrow"><div class="nw-int-tabs" role="tablist">';
    foreach ($tabs as $key => $label) {
        $dot = $key === 'connect'
            ? '<span class="nw-figma-dot' . ($paired ? ' is-on' : '') . '"></span>'
            : '';
        $count = $key === 'library' && $designs > 0
            ? '<span class="nw-int-tab-count">' . esc_html((string) $designs) . '</span>'
            : '';
        printf(
            '<button type="button" class="nw-int-tab%s" role="tab" data-papertab="%s">%s%s%s</button>',
            $tab === $key ? ' is-active' : '',
            esc_attr($key),
            $dot,
            esc_html($label),
            $count
        );
    }
    echo '</div></div>';

    foreach (array_keys($tabs) as $key) {
        printf(
            '<div class="nw-figma-panel%s" data-paperpanel="%s">',
            $tab === $key ? ' is-active' : '',
            esc_attr($key)
        );
        if ($key === 'connect') {
            nibwp_paper_echo_connect_panel($paired, $bridge, $setup, $verify);
        } elseif ($key === 'library') {
            nibwp_paper_echo_library_panel();
        } else {
            nibwp_paper_echo_howto_panel($setup, $builders);
        }
        echo '</div>';
    }

    echo '</div></div>';

    nibwp_paper_echo_script();
}

/**
 * @param array<string, mixed> $bridge
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $verify
 */
function nibwp_paper_echo_connect_panel(bool $paired, array $bridge, array $setup, array $verify): void
{
    echo '<div class="nibwp-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('Connect paper.design', 'nibwp') . '</h2>';
    echo '<p class="nw-figma-muted">' . esc_html((string) $verify['why']) . '</p>';
    echo '<p class="nw-figma-muted"><strong>' . esc_html((string) $setup['no_credential']) . '</strong></p>';

    /*
     * Step state, honestly: paired means all three were done, because pairing is
     * impossible without the first two. Not paired means we genuinely do not know
     * how far they got — nothing here can see their laptop — so the sequence is
     * presented top-down with the first step as the one to act on rather than
     * guessing at progress we cannot observe.
     */
    echo '<ol class="nw-paper-walk">';

    nibwp_paper_echo_step(
        1,
        __('Open the Paper desktop app', 'nibwp'),
        (string) $setup['requires'],
        $paired ? 'is-done' : 'is-current',
        static function (): void {
            echo '<p class="nw-paper-walk__text"><a href="https://paper.design/" target="_blank" rel="noopener">'
                . esc_html__('Download Paper', 'nibwp') . '</a></p>';
        }
    );

    nibwp_paper_echo_step(
        2,
        __('Add Paper to your agent', 'nibwp'),
        __('One line, run on the machine the agent runs on. The first is for agents that speak HTTP; the second for stdio.', 'nibwp'),
        $paired ? 'is-done' : 'is-todo',
        static function () use ($setup): void {
            nibwp_paper_echo_command((string) $setup['http']);
            nibwp_paper_echo_command((string) $setup['stdio']);
        }
    );

    nibwp_paper_echo_step(
        3,
        __('Ask the agent to pair', 'nibwp'),
        __('Paste this into the same conversation that has this site connected. The agent checks Paper and reports back here — the only direction this check can run.', 'nibwp'),
        $paired ? 'is-done' : 'is-todo',
        static function (): void {
            nibwp_paper_echo_prompt(nibwp_paper_pair_prompt());
        }
    );

    echo '</ol>';

    if ($paired) {
        $by   = (int) ($bridge['paired_by'] ?? 0);
        $user = $by > 0 ? get_userdata($by) : null;

        /*
         * The pairing record, as one panel rather than four loose paragraphs
         * trailing off the end of the walkthrough.
         *
         * Plain text inside it, not a success note: the strip above the tabs
         * already says "Bridge paired" in green, and nw-figma-note--ok renders
         * dark green on near-black in dark mode — fine as a badge on the Figma
         * screen, poor for a sentence somebody has to read.
         */
        echo '<div class="nw-paper-paired">';
        echo '<h3 class="nw-paper-subhead">' . esc_html__('What was reported', 'nibwp') . '</h3>';
        echo '<p class="nw-figma-muted">'
            . esc_html(sprintf(
                /* translators: 1: agent name, 2: number of Paper tools */
                __('%1$s reported %2$d Paper tools.', 'nibwp'),
                (string) ($bridge['agent'] ?? __('An agent', 'nibwp')),
                (int) ($bridge['tool_count'] ?? 0)
            ))
            . ($user instanceof WP_User ? ' ' . esc_html(sprintf(
                /* translators: %s: WordPress display name */
                __('Signed in as %s.', 'nibwp'),
                $user->display_name
            )) : '')
            . '</p>';

        // Said once and said plainly: this is the agent's word, recorded. The
        // alternative — a green light implying we checked — would be a lie, and
        // the person debugging a failed handover needs to know which it is.
        echo '<p class="nw-figma-muted">' . esc_html__('This is what the agent reported, with the time and the account it came from. This site cannot reach Paper to confirm it independently.', 'nibwp') . '</p>';

        $tools = array_slice((array) ($bridge['tools'] ?? []), 0, 40);
        if ($tools !== []) {
            echo '<p class="nw-figma-muted">' . esc_html__('Tools reported:', 'nibwp') . ' '
                . esc_html(implode(', ', array_map('strval', $tools))) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nw-figma-pull">';
        wp_nonce_field('nibwp_paper_unpair');
        echo '<input type="hidden" name="action" value="nibwp_paper_unpair" />';
        echo '<button type="submit" class="nw-figma-btn nw-figma-btn--ghost">'
            . '<span class="nw-figma-btn__label">' . esc_html__('Unpair', 'nibwp') . '</span></button>';
        echo '<span class="nw-figma-muted">' . esc_html__('Clears the record only. Your designs stay in the library.', 'nibwp') . '</span>';
        echo '</form>';
        echo '</div>';
    }

    echo '</div>';

    /* What we can and cannot see. This is the panel that saves a support ticket:
       somebody whose handover failed can tell in one glance which side to look at. */
    echo '<div class="nibwp-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('What this site can check', 'nibwp') . '</h2>';

    /*
     * Two halves, not a card grid. This used nw-figma-grid, whose auto-fill
     * minimum squeezed both lists into one corner of a wide card.
     *
     * The right-hand column is styled as a plain fact, not a warning: "we cannot
     * see whether Paper is running" is a statement about where that software
     * lives, and dressing it as an error would send somebody looking for a fault
     * on this site.
     */
    echo '<div class="nw-paper-checks">';

    $columns = [
        ['can', __('We can see', 'nibwp'), (array) $verify['can_verify']],
        ['cant', __('We cannot see', 'nibwp'), (array) $verify['cannot_verify']],
    ];
    foreach ($columns as [$kind, $heading, $lines]) {
        printf('<div class="nw-paper-check nw-paper-check--%s">', esc_attr($kind));
        echo '<div class="nw-paper-check__head">' . esc_html($heading) . '</div>';
        echo '<ul class="nw-paper-check__list">';
        foreach ($lines as $line) {
            echo '<li>' . esc_html((string) $line) . '</li>';
        }
        echo '</ul></div>';
    }

    echo '</div></div>';
}

function nibwp_paper_echo_library_panel(): void
{
    $listing = nibwp_paper_manage(['action' => 'list_designs', 'limit' => 50]);

    echo '<div class="nibwp-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('Design library', 'nibwp') . '</h2>';

    if (is_wp_error($listing)) {
        echo '<p class="nw-figma-note nw-figma-note--err">' . esc_html($listing->get_error_message()) . '</p></div>';
        return;
    }

    $rows = (array) ($listing['designs'] ?? []);

    nibwp_paper_echo_library_bar(count($rows));

    if ($rows === []) {
        echo '<p class="nw-figma-empty">' . esc_html__('Nothing here yet. A design arrives when your agent reads it out of Paper and hands it over.', 'nibwp') . '</p>';
        nibwp_paper_echo_prompt(nibwp_paper_handover_prompt());
        echo '</div>';
        return;
    }

    echo '<div class="nw-figma-grid">';
    foreach ($rows as $row) {
        nibwp_paper_echo_design_card((array) $row);
    }
    echo '</div>';
    echo '</div>';
}

/**
 * The toolbar above the grid — counts, and the sample designs.
 *
 * Samples exist so this screen can be judged before anybody has wired an agent
 * to it. They are ordinary library entries carrying a flag, so they convert like
 * real ones, and removal goes by that flag alone — never by name or position, so
 * a real design cannot be swept up with them.
 */
function nibwp_paper_echo_library_bar(int $shown): void
{
    $has_samples = method_exists('NIBWP_Paper_Library', 'samples_count');
    $samples = $has_samples ? (int) NIBWP_Paper_Library::samples_count() : 0;

    echo '<div class="nw-paper-libbar">';
    echo '<p class="nw-paper-libbar__text">' . esc_html($shown > 0
        ? sprintf(
            /* translators: %d: number of designs shown */
            _n('%d design.', '%d designs.', $shown, 'nibwp'),
            $shown
        )
        : __('No designs yet.', 'nibwp'));
    if ($samples > 0) {
        echo ' ' . esc_html(sprintf(
            /* translators: %d: number of sample designs */
            _n('%d of them is a sample.', '%d of them are samples.', $samples, 'nibwp'),
            $samples
        ));
    }
    echo '</p>';

    if (!$has_samples) {
        echo '</div>';
        return;
    }

    echo '<div class="nw-paper-libbar__acts">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('nibwp_paper_samples');
    echo '<input type="hidden" name="action" value="nibwp_paper_samples" />';
    if ($samples > 0) {
        echo '<input type="hidden" name="mode" value="remove" />';
        echo '<button type="submit" class="nw-figma-btn nw-figma-btn--ghost">'
            . '<span class="nw-figma-btn__label">' . esc_html__('Remove the samples', 'nibwp') . '</span></button>';
    } else {
        echo '<input type="hidden" name="mode" value="load" />';
        echo '<button type="submit" class="nw-figma-btn nw-figma-btn--ghost">'
            . '<span class="nw-figma-btn__label">' . esc_html__('Load sample designs', 'nibwp') . '</span></button>';
    }
    echo '</form>';
    echo '</div></div>';
}

/**
 * One design card, in the shape the Figma library already uses: thumbnail, name,
 * its colours, the handle you call it by, and what you can do with it.
 *
 * @param array<string, mixed> $row
 */
function nibwp_paper_echo_design_card(array $row): void
{
    $id     = (string) ($row['design_id'] ?? '');
    $name   = (string) ($row['name'] ?? __('Design', 'nibwp'));
    $handle = (string) ($row['handle'] ?? '');
    $thumb  = (string) ($row['screenshot_url'] ?? '');
    $colors = array_slice((array) ($row['colors'] ?? []), 0, 6);
    $counts = (array) ($row['counts'] ?? []);

    echo '<div class="nw-figma-item" data-id="' . esc_attr($id) . '">';

    // Direct child of the card, before the thumbnail: that is what admin-paper.css
    // keys its overlay off. Inside the body it falls back to an inline pill, which
    // pushed the name into an ellipsis on a narrow card.
    if (!empty($row['sample'])) {
        echo '<span class="nw-paper-sample">' . esc_html__('Sample', 'nibwp') . '</span>';
    }

    if ($thumb !== '') {
        // Inline data: URIs are how a sample carries its thumbnail, and esc_url()
        // strips those — so the attribute is built with esc_attr() and the scheme
        // is checked instead of trusting whatever is in the entry.
        $safe = str_starts_with($thumb, 'data:image/')
            ? esc_attr($thumb)
            : esc_url($thumb);
        if ($safe !== '') {
            echo '<div class="nw-figma-item__thumb" style="background-image:url(' . $safe . ');"></div>';
        }
    }

    echo '<div class="nw-figma-item__body">';
    /*
     * Name, with the same rename affordance the Figma library card has: a pencil
     * that reveals an inline field. The chrome is Figma's own — the stylesheet
     * hides .nw-figma-renameform until the card carries .is-renaming — so the two
     * libraries behave identically. Figma renames over AJAX; this posts a form,
     * which is the mechanism the rest of this screen already uses.
     */
    echo '<span class="nw-figma-item__name"><span class="nw-figma-item__label">' . esc_html($name) . '</span>';
    echo '<button type="button" class="nw-figma-rename nw-paper-rename" aria-label="' . esc_attr__('Rename', 'nibwp') . '"'
        . ' title="' . esc_attr__('Rename', 'nibwp') . '">'
        . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>'
        . '</button></span>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nw-figma-renameform">';
    wp_nonce_field('nibwp_paper_rename');
    echo '<input type="hidden" name="action" value="nibwp_paper_rename" />';
    echo '<input type="hidden" name="design_id" value="' . esc_attr($id) . '" />';
    echo '<input type="text" name="name" value="' . esc_attr($name) . '" maxlength="80" aria-label="'
        . esc_attr__('Design name', 'nibwp') . '" />';
    echo '<button type="submit" class="nw-figma-btn nw-figma-btn--ghost">'
        . '<span class="nw-figma-btn__label">' . esc_html__('Save', 'nibwp') . '</span></button>';
    echo '</form>';

    if ($colors !== []) {
        echo '<div class="nw-figma-swatches">';
        foreach ($colors as $hex) {
            $hex = (string) $hex;
            // Only a hex colour reaches a style attribute. Anything else is
            // dropped rather than printed: this value came in over the wire.
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex) !== 1) {
                continue;
            }
            echo '<span class="nw-figma-swatch" title="' . esc_attr($hex) . '" style="background:'
                . esc_attr($hex) . ';"></span>';
        }
        echo '</div>';
    }

    echo '<button type="button" class="nw-figma-hint nw-figma-copy" data-copy="' . esc_attr($handle) . '"'
        . ' title="' . esc_attr__('Copy handle', 'nibwp') . '">'
        . '<span class="nw-figma-copy__text" data-copied-label="' . esc_attr__('copied', 'nibwp') . '">'
        . esc_html($handle) . '</span>'
        . nibwp_paper_copy_icon()
        . '</button>';

    $updated = (int) ($row['updated'] ?? 0);
    echo '<p class="nw-figma-muted">' . esc_html(sprintf(
        /* translators: 1: node count, 2: asset count, 3: human-readable time ago */
        __('%1$d nodes · %2$d assets · received %3$s ago', 'nibwp'),
        (int) ($row['nodes'] ?? 0),
        (int) ($counts['assets'] ?? 0),
        $updated > 0 ? human_time_diff($updated) : '—'
    )) . '</p>';

    if (!empty($row['stale'])) {
        echo '<p class="nw-figma-note nw-figma-note--err">'
            . esc_html__('Re-received since it was last built', 'nibwp') . '</p>';
    } elseif (!empty($row['converted'])) {
        echo '<p class="nw-figma-note nw-figma-note--ok">'
            . esc_html__('Already built once', 'nibwp') . '</p>';
    }

    /*
     * One row of icons, each with a tooltip, pinned to the foot of the card so
     * the rows line up across the grid however much text a card carries above
     * them. Where it goes first, what it does second: source and prompt on the
     * leading edge, the two that change this site on the trailing one.
     *
     * The builder dropdown is gone on purpose. It offered a choice this path
     * cannot honour: asking for a page builder returns a core-blocks draft and
     * still reports success, because writing to a builder needs that builder's
     * own skill and an agent to drive it, not a form POST. Offering the choice
     * anyway was the screen lying about what the button does.
     */
    echo '<div class="nw-figma-item__acts nw-figma-cardacts">';
    echo '<div class="nw-figma-cardacts__start">';

    // Only when the agent recorded where it came from: a Figma frame always has
    // a URL, a handed-over design may not, and a dead link is worse than none.
    $source_url = (string) ($row['paper_url'] ?? '');
    if ($source_url !== '' && preg_match('#^https?://#i', $source_url) === 1) {
        echo '<a class="nw-figma-iconbtn" href="' . esc_url($source_url) . '" target="_blank" rel="noopener"'
            . ' aria-label="' . esc_attr__('Open in Paper', 'nibwp') . '" title="' . esc_attr__('Open in Paper', 'nibwp') . '">'
            . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h9l5 5v13H5z"/><path d="M14 3v5h5"/></svg>'
            . '</a>';
    }

    echo '<button type="button" class="nw-figma-iconbtn nw-figma-copy" data-copy="'
        . esc_attr(nibwp_paper_design_prompt($handle)) . '" aria-label="' . esc_attr__('Copy prompt', 'nibwp') . '"'
        . ' title="' . esc_attr__('Copy prompt for your agent', 'nibwp') . '">'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 10h8M8 14h5"/><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8Z"/></svg>'
        . '</button>';

    echo '</div><div class="nw-figma-cardacts__end">';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nw-figma-item__form">';
    wp_nonce_field('nibwp_paper_convert');
    echo '<input type="hidden" name="action" value="nibwp_paper_convert" />';
    echo '<input type="hidden" name="design_id" value="' . esc_attr($id) . '" />';
    /*
     * Pinned to core blocks, not left blank. An absent builder resolves to
     * "auto", which picks the first active page builder and returns a handoff
     * payload with no page written — the button would report success and leave
     * nothing behind, which is the exact failure this row was rebuilt to remove.
     */
    echo '<input type="hidden" name="builder" value="gutenberg" />';
    echo '<button type="submit" class="nw-figma-iconbtn" aria-label="' . esc_attr__('Create a draft', 'nibwp') . '"'
        . ' title="' . esc_attr__('Create a draft (core blocks)', 'nibwp') . '">'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6"/><path d="M12 12v6M9 15h6"/></svg>'
        . '</button>';
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nw-figma-item__form">';
    wp_nonce_field('nibwp_paper_delete');
    echo '<input type="hidden" name="action" value="nibwp_paper_delete" />';
    echo '<input type="hidden" name="design_id" value="' . esc_attr($id) . '" />';
    echo '<button type="submit" class="nw-figma-iconbtn nw-figma-iconbtn--danger" aria-label="'
        . esc_attr__('Remove from library', 'nibwp') . '" title="' . esc_attr__('Remove from library', 'nibwp') . '">'
        . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M6 6v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6"/></svg>'
        . '</button>';
    echo '</form>';

    echo '</div>';
    echo '</div>';
    echo '</div></div>';
}

/**
 * Where a design can go — the useful remainder of what was once its own tab.
 *
 * Only builders that are installed are shown. A card reading "Not installed" is
 * a fact about a plugin somebody chose not to have, and six of them buried the
 * one row that matters: a builder present here whose skill is switched off, and
 * the switch.
 *
 * @param array<string, mixed> $builders
 */
function nibwp_paper_echo_builders_panel(array $builders): void
{
    $rows = [];
    foreach ((array) ($builders['builders'] ?? []) as $row) {
        $row = (array) $row;
        if (!empty($row['installed']) || (string) ($row['state'] ?? '') !== 'missing') {
            $rows[] = $row;
        }
    }

    echo '<h3 class="nw-paper-subhead">' . esc_html__('Where a design can go', 'nibwp') . '</h3>';
    echo '<p class="nw-figma-muted">' . esc_html((string) ($builders['note'] ?? '')) . '</p>';

    if ($rows === []) {
        echo '<p class="nw-figma-muted">' . esc_html__('No page builder on this site. Core blocks are always available.', 'nibwp') . '</p>';
        return;
    }

    $recommended = (string) ($builders['recommended'] ?? '');

    /*
     * One card per builder, carrying its own state. The state that matters is
     * "installed but not ready": that is a builder one switch away from working,
     * and it is the only one that gets something to click. A builder that is not
     * installed is inert, not broken, and is styled to look it.
     */
    // One or two words each: these render as a small pill beside the builder's
    // name on a 240px card, and a sentence blows the pill out of shape.
    $states = [
        'ready'     => __('Ready', 'nibwp'),
        'installed' => __('Needs setup', 'nibwp'),
        'blocked'   => __('Blocked', 'nibwp'),
        'missing'   => __('Not installed', 'nibwp'),
    ];

    echo '<div class="nw-paper-builders">';
    foreach ($rows as $row) {
        $row   = (array) $row;
        $slug  = (string) ($row['builder'] ?? '');
        $state = (string) ($row['state'] ?? (!empty($row['ready']) ? 'ready' : 'installed'));
        if (!isset($states[$state])) {
            $state = 'blocked';
        }
        $label = (string) ($row['label'] ?? $slug);

        printf('<div class="nw-paper-builder is-%s">', esc_attr($state));

        echo '<div class="nw-paper-builder__head">';
        /*
         * A monogram, so the grid reads as a set of things rather than a wall of
         * identical boxes. Builders ship no mark we may use, and inventing a logo
         * for somebody else's product would be worse than a letter — so it is the
         * first character of the name this site already shows.
         */
        echo '<span class="nw-paper-builder__mark" aria-hidden="true">'
            . esc_html(mb_strtoupper(mb_substr($label, 0, 1))) . '</span>';
        echo '<span class="nw-paper-builder__name">' . esc_html($label) . '</span>';
        echo '<span class="nw-paper-builder__state">' . esc_html($states[$state]) . '</span>';
        if ($slug !== '' && $slug === $recommended) {
            echo '<span class="nw-paper-builder__rec">' . esc_html__('Recommended', 'nibwp') . '</span>';
        }
        echo '</div>';

        $why = (string) ($row['blocked_reason'] ?? '');
        if ($why === '') {
            $why = $state === 'ready'
                ? __('A design can be handed to it now.', 'nibwp')
                : ($state === 'missing'
                    ? __('Not on this site, so nothing can be handed to it.', 'nibwp')
                    : __('Installed, but it cannot take a handoff yet.', 'nibwp'));
        }
        echo '<p class="nw-paper-builder__why">' . esc_html($why) . '</p>';

        /*
         * Only actions that lead somewhere real. The handoff ability's name used
         * to print here, which wrapped mid-token on a narrow card and told an
         * administrator nothing they could act on — it is developer detail, and
         * list_builders still carries it for the agent that needs it.
         *
         * A builder that is not installed gets no button on purpose: there is
         * nothing on this site to switch, and a link to somewhere to buy it is
         * not this screen's job.
         */
        $actions = [];

        $fix = (array) ($row['fix'] ?? []);
        $fix_url = (string) ($fix['url'] ?? '');
        if ($fix_url !== '' && (string) ($fix['label'] ?? '') !== '') {
            $actions[] = [$fix_url, (string) $fix['label'], true];
        }

        if ($state === 'ready') {
            // The point of a ready builder is that you can build with it, so the
            // card says so and goes where that happens.
            $actions[] = [
                add_query_arg('tab', 'library', nibwp_paper_page_url()),
                __('Build with it', 'nibwp'),
                true,
            ];
        }

        if ($state !== 'missing' && (string) ($row['skill'] ?? '') !== '' && $fix_url === '') {
            $actions[] = [admin_url('admin.php?page=nibwp-skills'), __('Manage its skill', 'nibwp'), false];
        }

        if ($actions !== []) {
            echo '<div class="nw-paper-builder__foot">';
            foreach ($actions as [$action_url, $action_label, $primary]) {
                printf(
                    '<a class="nw-figma-btn%s" href="%s"><span class="nw-figma-btn__label">%s</span></a>',
                    $primary ? '' : ' nw-figma-btn--ghost',
                    esc_url($action_url),
                    esc_html($action_label)
                );
            }
            echo '</div>';
        }

        echo '</div>';
    }
    echo '</div>';
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $builders
 */
function nibwp_paper_echo_howto_panel(array $setup, array $builders): void
{
    echo '<div class="nibwp-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('How a handover runs', 'nibwp') . '</h2>';
    echo '<p class="nw-figma-muted">' . esc_html__('Five of these six steps happen in your agent. This site is the fourth one onwards.', 'nibwp') . '</p>';

    // The same walkthrough component as the Connect panel, with no step marked
    // current: these describe what happens, they are not a checklist to work.
    echo '<ol class="nw-paper-walk">';
    $number = 1;
    foreach (nibwp_paper_handover_steps() as $step) {
        nibwp_paper_echo_step($number, '', $step);
        $number++;
    }
    echo '</ol>';

    echo '<h3 class="nw-paper-subhead">' . esc_html__('Ask for it like this', 'nibwp') . '</h3>';
    nibwp_paper_echo_prompt(nibwp_paper_handover_prompt());

    // Step four says the agent plans which builder to use. This is that answer
    // for this site, which is why it sits here rather than in a tab of its own.
    nibwp_paper_echo_builders_panel($builders);

    echo '<p class="nw-figma-muted">' . esc_html__('Paper\'s own MCP endpoint, for reference:', 'nibwp') . ' <code class="nw-figma-code">'
        . esc_html((string) $setup['endpoint']) . '</code> — '
        . '<a href="https://paper.design/docs/mcp" target="_blank" rel="noopener">'
        . esc_html__('Paper\'s MCP documentation', 'nibwp') . '</a></p>';
    echo '</div>';
}

/**
 * Tab switching and click-to-copy.
 *
 * The same two behaviours the Figma screen has, and deliberately the same shape:
 * a class toggle over panels already in the DOM, and the tab written back into
 * the URL with replaceState so a reload or a shared link still opens the right
 * one. Without JavaScript the landing panel is the one marked active server
 * side, so the screen still works — it just stops switching.
 */
function nibwp_paper_echo_script(): void
{
    ?>
    <script>
    (function(){
        var tabs = document.querySelectorAll('.nw-int-tab[data-papertab]');
        var panels = document.querySelectorAll('.nw-figma-panel[data-paperpanel]');
        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                var key = tab.getAttribute('data-papertab');
                tabs.forEach(function(t){ t.classList.toggle('is-active', t === tab); });
                panels.forEach(function(p){
                    p.classList.toggle('is-active', p.getAttribute('data-paperpanel') === key);
                });
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.set('tab', key);
                    history.replaceState({}, '', u);
                } catch (e) {}
            });
        });

        /* The rename pencil reveals the inline field the stylesheet keeps hidden
           until the card carries is-renaming — the same chrome the Figma library
           uses, so the two behave alike. */
        document.addEventListener('click', function(e){
            var pencil = e.target.closest ? e.target.closest('.nw-paper-rename') : null;
            if (!pencil) { return; }
            var card = pencil.closest('.nw-figma-item');
            if (!card) { return; }
            card.classList.toggle('is-renaming');
            var field = card.querySelector('.nw-figma-renameform input[type="text"]');
            if (field && card.classList.contains('is-renaming')) { field.focus(); field.select(); }
        });

        document.addEventListener('click', function(e){
            var btn = e.target.closest ? e.target.closest('.nw-figma-copy') : null;
            if (!btn) { return; }
            e.preventDefault();
            var text = btn.getAttribute('data-copy') || '';
            var done = function(){
                btn.classList.add('is-copied');
                setTimeout(function(){ btn.classList.remove('is-copied'); }, 1400);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function(){});
                return;
            }
            var ta = document.createElement('textarea');   // http:// fallback
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (err) {}
            document.body.removeChild(ta);
        });
    })();
    </script>
    <?php
}
