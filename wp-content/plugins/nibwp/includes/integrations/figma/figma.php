<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP Figma integration — bootstrap.
 *
 * Connect a Figma account (personal-access token OR OAuth), or use the Figma
 * Dev Mode MCP (AI-assisted, requires the Figma desktop app). Provides a local
 * converter page (paste a Figma frame URL → a native WordPress draft) so the
 * read → NDO → builder → draft pipeline can be exercised end to end.
 *
 * Read-only against Figma. Token stored in the `nibwp_figma_token` option.
 */

require_once __DIR__ . '/class-figma-client.php';
require_once __DIR__ . '/class-figma-normalize.php';
require_once __DIR__ . '/class-figma-gutenberg.php';
require_once __DIR__ . '/class-figma-html.php';
require_once __DIR__ . '/class-figma-tokens.php';
require_once __DIR__ . '/class-figma-library.php';

/* ─────────────────────────── helpers ─────────────────────────── */

/* ── secret storage ──
 * Figma credentials must be REPLAYED against the API, so they cannot be hashed
 * (hashing is one-way). They are instead encrypted at rest with a key derived
 * from this install's WP salts, and never rendered to the browser — the UI only
 * ever shows a masked fingerprint. */

/** 32-byte key derived from the site's salts. Rotating salts invalidates stored secrets. */
function nibwp_figma_secret_key(): string
{
    $seed = (defined('AUTH_KEY') ? AUTH_KEY : '')
        . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '')
        . (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '');
    return hash('sha256', 'nibwp/figma/v1|' . $seed, true);
}

/** Encrypt a secret for storage. Returns a prefixed, base64 payload. */
function nibwp_figma_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'nbf1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, nibwp_figma_secret_key()));
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', nibwp_figma_secret_key(), OPENSSL_RAW_DATA, $iv);
        if (is_string($cipher)) {
            return 'nbf2:' . base64_encode($iv . $cipher);
        }
    }
    // No crypto available — store as-is rather than losing the connection.
    return $plain;
}

/** Decrypt a stored secret. Values without a known prefix are legacy plaintext. */
function nibwp_figma_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    // A known prefix means the value IS ciphertext. If it cannot be decrypted
    // (corrupt, rotated salts, missing extension) return empty — never hand the
    // raw ciphertext back as if it were a usable credential.
    if (str_starts_with($stored, 'nbf1:')) {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, nibwp_figma_secret_key());
        return is_string($out) ? $out : '';
    }
    if (str_starts_with($stored, 'nbf2:')) {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', nibwp_figma_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return is_string($out) ? $out : '';
    }
    return $stored; // legacy plaintext
}

/** Persist a secret option encrypted (empty string deletes it). */
function nibwp_figma_save_secret(string $option, string $plain): void
{
    if ($plain === '') {
        delete_option($option);
        return;
    }
    update_option($option, nibwp_figma_encrypt($plain), false);
}

function nibwp_figma_token(): string
{
    $stored = (string) get_option('nibwp_figma_token', '');
    if ($stored === '') {
        return '';
    }
    $plain = nibwp_figma_decrypt($stored);
    // Opportunistically upgrade connections saved before encryption existed.
    if ($plain !== '' && $plain === $stored) {
        update_option('nibwp_figma_token', nibwp_figma_encrypt($plain), false);
    }
    return $plain;
}

/**
 * Masked fingerprint for display — enough to recognize which credential is
 * saved, never enough to use it. e.g. "figd_••••••••3f9a".
 */
function nibwp_figma_token_mask(): string
{
    $token = nibwp_figma_token();
    if ($token === '') {
        return '';
    }
    $head = str_contains($token, '_') ? substr($token, 0, (int) strpos($token, '_') + 1) : '';
    $tail = strlen($token) > 4 ? substr($token, -4) : '';
    return $head . str_repeat('•', 8) . $tail;
}

/** Referenced by the Integrations page availability check. */
function nibwp_figma_is_connected(): bool
{
    return nibwp_figma_token() !== '';
}

/**
 * Which method established the current connection: 'token', 'oauth', or '' when
 * not connected. Recorded at connect time; falls back to sniffing the OAuth
 * refresh token for connections made before the marker existed.
 */
function nibwp_figma_auth_method(): string
{
    if (!nibwp_figma_is_connected()) {
        return '';
    }
    $method = (string) get_option('nibwp_figma_auth_method', '');
    if ($method === '') {
        $method = get_option('nibwp_figma_oauth_refresh', '') !== '' ? 'oauth' : 'token';
    }
    return $method;
}

function nibwp_figma_get_client(): ?NIBWP_Figma_Client
{
    $token = nibwp_figma_token();
    return $token !== '' ? new NIBWP_Figma_Client($token) : null;
}

function nibwp_figma_oauth_redirect_uri(): string
{
    return admin_url('admin-post.php?action=nibwp_figma_oauth_callback');
}

function nibwp_figma_page_url(): string
{
    return admin_url('admin.php?page=nibwp-figma');
}

/* The Figma page (connection + library) is registered by the main NibWP menu in
 * nibwp.php — visible under Jobs once the integration is activated, hidden but
 * URL-reachable otherwise. Nothing to register here. */

/* figma-pro abilities register through the skill registry (manifest
 * ability_files), gated on enabled + unlocked + deps like every Pro skill —
 * no side-channel loading here. Connect Figma → deps met → tools appear. */

/* ─────────────────────────── the page ─────────────────────────── */

/** Seconds → "about 4 days" / "2 hours" / "5 minutes". */
function nibwp_figma_human_wait(int $seconds): string
{
    if ($seconds >= DAY_IN_SECONDS) {
        $n = (int) max(1, round($seconds / DAY_IN_SECONDS));
        /* translators: %d: number of days */
        return sprintf(_n('%d day', '%d days', $n, 'nibwp'), $n);
    }
    if ($seconds >= HOUR_IN_SECONDS) {
        $n = (int) max(1, round($seconds / HOUR_IN_SECONDS));
        /* translators: %d: number of hours */
        return sprintf(_n('%d hour', '%d hours', $n, 'nibwp'), $n);
    }
    $n = (int) max(1, round($seconds / MINUTE_IN_SECONDS));
    /* translators: %d: number of minutes */
    return sprintf(_n('%d minute', '%d minutes', $n, 'nibwp'), $n);
}

/**
 * Render the rate-limit explainer.
 *
 * A raw "try again in 329772 seconds" reads like NibWP broke. It is Figma's
 * quota, it is per token, and it is fixable — so say all three, and link to the
 * three things that actually resolve it.
 */
function nibwp_figma_render_rate_limit_notice(string $message, int $retry_after = 0): void
{
    $wait = $retry_after > 0 ? nibwp_figma_human_wait($retry_after) : '';
    ?>
    <div class="nw-figma-limit">
        <div class="nw-figma-limit__head">
            <span class="nw-figma-limit__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>
            </span>
            <div>
                <strong><?php esc_html_e('Figma paused API access for this token', 'nibwp'); ?></strong>
                <span><?php esc_html_e('This is a limit set by Figma, not by NibWP. Nothing on your site is broken, and everything already in your library still works.', 'nibwp'); ?></span>
            </div>
            <?php if ($wait !== ''): ?>
                <span class="nw-figma-limit__wait"><?php
                    /* translators: %s: human readable duration */
                    printf(esc_html__('~%s left', 'nibwp'), esc_html($wait));
                ?></span>
            <?php endif; ?>
        </div>

        <p class="nw-figma-limit__why"><?php esc_html_e('Figma meters its API per token, and reading files, nodes and image renders are its most expensive calls. Pulling many frames in a short burst can use up the allowance.', 'nibwp'); ?></p>

        <ul class="nw-figma-limit__fixes">
            <li>
                <strong><?php esc_html_e('Use a different token', 'nibwp'); ?></strong>
                <span><?php esc_html_e('The limit follows the token, not your site. A token from another Figma account works right away.', 'nibwp'); ?></span>
                <a href="https://www.figma.com/developers/api#access-tokens" target="_blank" rel="noopener"><?php esc_html_e('How to create a token', 'nibwp'); ?></a>
            </li>
            <li>
                <strong><?php esc_html_e('Check the seat, not just the plan', 'nibwp'); ?></strong>
                <span><?php esc_html_e('View and Collab seats get a far smaller allowance than Dev or Full seats — often the real cause of a long wait.', 'nibwp'); ?></span>
                <a href="https://www.figma.com/pricing/" target="_blank" rel="noopener"><?php esc_html_e('Compare Figma plans & seats', 'nibwp'); ?></a>
            </li>
            <li>
                <strong><?php esc_html_e('Higher plans get higher limits', 'nibwp'); ?></strong>
                <span><?php esc_html_e('Professional and Organization plans raise the per-minute allowance on exactly the calls NibWP uses most.', 'nibwp'); ?></span>
                <a href="https://developers.figma.com/docs/rest-api/rate-limits/" target="_blank" rel="noopener"><?php esc_html_e('Figma rate limits', 'nibwp'); ?></a>
            </li>
        </ul>

        <p class="nw-figma-limit__ok"><?php esc_html_e('Meanwhile: frames already pulled convert normally — NibWP builds them from its own cache without calling Figma.', 'nibwp'); ?></p>

        <?php if ($message !== ''): ?>
            <details class="nw-figma-limit__raw">
                <summary><?php esc_html_e('Technical detail', 'nibwp'); ?></summary>
                <code><?php echo esc_html($message); ?></code>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

/* ── dashboard promo ──
 * A single, dismissible pitch for the Figma module. Dashboard only, and only
 * while it is not already connected — nobody needs an advert for the thing they
 * are already using. Dismissal is per user and permanent. */

/** AJAX: remember that this user dismissed the Figma promo. */
add_action('wp_ajax_nibwp_figma_dismiss_promo', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error([], 403);
    }
    check_ajax_referer('nibwp_figma_promo');
    update_user_meta(get_current_user_id(), 'nibwp_figma_promo_dismissed', 1);
    wp_send_json_success();
});

function nibwp_render_figma_promo(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    // ?figma_promo=1 forces it on for a look, whatever the real state.
    $preview = isset($_GET['figma_promo']);

    // Already connected, or already dismissed → say nothing.
    if (!$preview && nibwp_figma_is_connected()) {
        return;
    }
    if (!$preview && get_user_meta(get_current_user_id(), 'nibwp_figma_promo_dismissed', true)) {
        return;
    }

    $unlocked = nibwp_figma_unlocked();
    $cta_url  = $unlocked
        ? admin_url('admin.php?page=nibwp-figma')
        : admin_url('admin.php?page=nibwp-license');
    $cta_text = $unlocked ? __('Connect Figma', 'nibwp') : __('Unlock with Pro', 'nibwp');
    ?>
    <div class="nw-figma-promo" id="nw-figma-promo">
        <div class="nw-figma-promo__glow" aria-hidden="true"></div>

        <button type="button" class="nw-figma-promo__x" id="nw-figma-promo-x"
                aria-label="<?php esc_attr_e('Dismiss', 'nibwp'); ?>" title="<?php esc_attr_e('Dismiss', 'nibwp'); ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>

        <div class="nw-figma-promo__art" aria-hidden="true">
            <span class="nw-figma-promo__chip nw-figma-promo__chip--1"></span>
            <span class="nw-figma-promo__chip nw-figma-promo__chip--2"></span>
            <span class="nw-figma-promo__chip nw-figma-promo__chip--3"></span>
            <svg class="nw-figma-promo__mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H8.5a3.5 3.5 0 000 7H12z"/><path d="M12 2h3.5a3.5 3.5 0 110 7H12z"/><path d="M12 9H8.5a3.5 3.5 0 000 7H12z"/><path d="M12 16H8.5A3.5 3.5 0 1012 19.5z"/><circle cx="15.5" cy="12.5" r="3.5"/></svg>
            <svg class="nw-figma-promo__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h14"/><path d="m13 6 6 6-6 6"/></svg>
            <span class="nw-figma-promo__wp">
                <i class="nw-figma-promo__wpmark"></i>
            </span>
        </div>

        <div class="nw-figma-promo__copy">
            <span class="nw-figma-promo__tag"><?php esc_html_e('New', 'nibwp'); ?> · <?php esc_html_e('STUDIO', 'nibwp'); ?></span>
            <h3><?php esc_html_e('Your Figma designs, now inside WordPress', 'nibwp'); ?></h3>
            <p><?php esc_html_e('Pull frames — or an entire team — into a local library with their images and CSS tokens. Then just say the word: NibWP AI builds them with whichever page builder you already run.', 'nibwp'); ?></p>
            <ul class="nw-figma-promo__pills">
                <li><?php esc_html_e('Read-only, never edits your designs', 'nibwp'); ?></li>
                <li><?php esc_html_e('Colors &amp; type extracted automatically', 'nibwp'); ?></li>
                <li><?php esc_html_e('Etch · Bricks · Elementor · Gutenberg & more', 'nibwp'); ?></li>
            </ul>
        </div>

        <div class="nw-figma-promo__cta">
            <a class="nw-figma-promo__btn" href="<?php echo esc_url($cta_url); ?>"><?php echo esc_html($cta_text); ?></a>
            <span class="nw-figma-promo__hint"><?php esc_html_e('Takes about two minutes', 'nibwp'); ?></span>
        </div>
    </div>
    <script>
    (function(){
        var promo = document.getElementById('nw-figma-promo');
        var x = document.getElementById('nw-figma-promo-x');
        if (!promo || !x) { return; }
        x.addEventListener('click', function(){
            promo.classList.add('is-gone');
            setTimeout(function(){ promo.remove(); }, 260);
            <?php if ($preview): ?>
            return; // preview mode — dismissing here should not stick
            <?php endif; ?>
            var body = new URLSearchParams();
            body.set('action', 'nibwp_figma_dismiss_promo');
            body.set('_ajax_nonce', <?php echo wp_json_encode(wp_create_nonce('nibwp_figma_promo')); ?>);
            fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            });
        });
    })();
    </script>
    <?php
}

/**
 * Is the Figma integration unlocked for this install? Pro/Bundle license, an
 * explicit integration:figma entitlement, or an owned skill that declares it.
 */
function nibwp_figma_unlocked(): bool
{
    return !function_exists('nibwp_integration_is_unlocked') || nibwp_integration_is_unlocked('figma');
}

function nibwp_figma_render_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!nibwp_figma_unlocked()) {
        echo '<div class="wrap"><h1>' . esc_html__('Figma', 'nibwp') . '</h1>'
            . '<p>' . esc_html__('The Figma integration is a Pro feature. Activate a NibWP Pro, Bundle, or Figma Skill license to connect.', 'nibwp') . '</p>'
            . '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=nibwp-license')) . '">' . esc_html__('Manage license', 'nibwp') . '</a></p></div>';
        return;
    }

    $connected   = nibwp_figma_is_connected();
    $handle      = (string) get_option('nibwp_figma_handle', '');
    $client_id   = (string) get_option('nibwp_figma_oauth_client_id', '');
    $has_secret  = get_option('nibwp_figma_oauth_client_secret', '') !== '';
    $redirect    = nibwp_figma_oauth_redirect_uri();
    $notice      = isset($_GET['figma_msg']) ? sanitize_text_field((string) wp_unslash($_GET['figma_msg'])) : '';
    $err         = isset($_GET['figma_err']) ? sanitize_text_field((string) wp_unslash($_GET['figma_err'])) : '';
    $rate_wait   = isset($_GET['figma_retry']) ? max(0, (int) $_GET['figma_retry']) : 0;
    $converted   = isset($_GET['converted']) ? (int) $_GET['converted'] : 0;
    $modal       = isset($_GET['modal']);
    $pulled      = isset($_GET['pulled']) ? sanitize_text_field((string) wp_unslash($_GET['pulled'])) : '';
    $lib_count   = count(NIBWP_Figma_Library::all());

    if (!$modal && function_exists('nibwp_render_admin_header')) {
        nibwp_render_admin_header();
    }
    echo '<div class="wrap nibwp-wrap' . ($modal ? ' nw-figma--modal' : '') . '">';
    if (!$modal) {
        echo '<div class="nibwp-page-header"><div>';
        echo '<h1>' . esc_html__('Figma', 'nibwp') . ' <span class="nw-figma__beta">BETA</span></h1>';
        echo '<p class="nibwp-subtitle">' . sprintf(
            /* translators: %d: number of pulled frames */
            esc_html__('Pull frames into your library — cached as images + CSS tokens. Nothing is converted until you (or NibWP AI) decide, in any workflow or builder. %d in library.', 'nibwp'),
            $lib_count
        ) . '</p>';
        echo '</div></div>';
    } else {
        echo '<h2 class="nibwp-card-title">' . esc_html__('Connect Figma', 'nibwp') . '</h2>';
    }

    if ($err !== '') {
        if ($rate_wait > 0 || stripos($err, 'paused API access') !== false) {
            nibwp_figma_render_rate_limit_notice($err, $rate_wait);
        } else {
            echo '<p class="nw-figma-note nw-figma-note--err">' . esc_html($err) . '</p>';
        }
    }
    if ($notice !== '') {
        echo '<p class="nw-figma-note nw-figma-note--ok">' . esc_html($notice) . '</p>';
    }
    if ($converted > 0) {
        $edit = esc_url((string) get_edit_post_link($converted, 'url'));
        $view = esc_url((string) get_permalink($converted));
        echo '<p class="nw-figma-note nw-figma-note--ok"><strong>' . esc_html__('Draft created.', 'nibwp') . '</strong> '
            . '<a href="' . $edit . '">' . esc_html__('Edit', 'nibwp') . '</a> · '
            . '<a href="' . $view . '" target="_blank" rel="noreferrer">' . esc_html__('Preview', 'nibwp') . '</a></p>';
    }
    if ($pulled !== '') {
        echo '<p class="nw-figma-note nw-figma-note--ok"><strong>' . esc_html__('Pulled into library.', 'nibwp') . '</strong> ' . esc_html($pulled) . '</p>';
    }

    echo '<div class="nw-figma">';

    if ($modal) {
        echo '<div class="nibwp-card">';
        nibwp_figma_echo_connect_forms(false);
        echo '</div>';
    }

    if (!$modal) {
        $lib = NIBWP_Figma_Library::all();
        // Land on the tab that matters: not connected → Connection; otherwise the
        // action you came for. ?tab= wins so links/redirects can target a tab.
        $tab = sanitize_key((string) ($_GET['tab'] ?? ''));
        if (!in_array($tab, ['connection', 'pull', 'library', 'howto'], true)) {
            $tab = !$connected ? 'connection' : ($pulled !== '' ? 'library' : 'pull');
        }

        /* Tabs — the library search shares this row and only shows on that tab. */
        echo '<div class="nw-int-tabs-wrap nw-figma-tabsrow"><div class="nw-int-tabs" role="tablist">';
        $tabs = [
            'connection' => __('Connection', 'nibwp'),
            'pull'       => __('Pull', 'nibwp'),
            'library'    => __('Library', 'nibwp'),
            'howto'      => __('How to', 'nibwp'),
        ];
        foreach ($tabs as $key => $label) {
            $count = $key === 'library' && $lib !== [] ? '<span class="nw-int-tab-count">' . count($lib) . '</span>' : '';
            $dot   = $key === 'connection'
                ? '<span class="nw-figma-dot' . ($connected ? ' is-on' : '') . '"></span>'
                : '';
            printf(
                '<button type="button" class="nw-int-tab%s" role="tab" data-figtab="%s">%s%s%s</button>',
                $tab === $key ? ' is-active' : '',
                esc_attr($key),
                $dot,
                esc_html($label),
                $count
            );
        }
        echo '</div>';

        if ($lib !== []) {
            echo '<div class="nw-page-search nw-figma-search" id="nw-figma-search-wrap" role="search"' . ($tab === 'library' ? '' : ' hidden') . '>'
                . '<span class="nw-page-search__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg></span>'
                . '<label for="nw-figma-search" class="screen-reader-text">' . esc_html__('Search the library', 'nibwp') . '</label>'
                . '<input type="search" id="nw-figma-search" class="nw-page-search__input" placeholder="' . esc_attr__('Search frames…', 'nibwp') . '" autocomplete="off" spellcheck="false" />'
                . '<button type="button" class="nw-page-search__clear" id="nw-figma-search-clear" aria-label="' . esc_attr__('Clear search', 'nibwp') . '" hidden><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>'
                . '<span class="nw-page-search__count" id="nw-figma-search-count">' . count($lib) . ' / ' . count($lib) . '</span>'
                . '</div>';
        }
        echo '</div>';

        /* ── Connection ── */
        echo '<div class="nw-figma-panel' . ($tab === 'connection' ? ' is-active' : '') . '" data-figpanel="connection">';
        echo '<div class="nibwp-card nw-figma-card">';
        nibwp_figma_echo_connect_forms(false);
        echo '</div></div>';

        /* ── Pull ── */
        echo '<div class="nw-figma-panel' . ($tab === 'pull' ? ' is-active' : '') . '" data-figpanel="pull">';
        echo '<div class="nibwp-card nw-figma-card' . ($connected ? '' : ' is-disabled') . '">';
        echo '<h2 class="nibwp-card-title">' . esc_html__('Pull a frame', 'nibwp') . '</h2>';
        echo '<p class="nw-figma-card__sub">' . esc_html__('Paste a Figma frame or element link. NibWP caches it as an image + CSS tokens — no conversion, nothing touched in Figma.', 'nibwp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nw-figma-pull">';
        wp_nonce_field('nibwp_figma_pull');
        echo '<input type="hidden" name="action" value="nibwp_figma_pull" />';
        echo '<input type="url" name="figma_url" class="nw-figma-input" placeholder="https://www.figma.com/design/KEY/Name?node-id=1-234" required />';
        echo '<input type="text" name="figma_name" class="nw-figma-input nw-figma-input--sm" placeholder="' . esc_attr__('Label (optional)', 'nibwp') . '" />';
        echo '<button type="submit" class="nw-figma-btn nw-figma-pull__btn" data-busy-label="' . esc_attr__('Pulling…', 'nibwp') . '">'
            . '<span class="nw-figma-spin" aria-hidden="true"></span>'
            . '<span class="nw-figma-btn__label">' . esc_html__('Pull into library', 'nibwp') . '</span>'
            . '</button>';
        echo '</form>';
        // Batch: whole file, or refresh what's already in the library.
        echo '<div class="nw-figma-batch">';
        echo '<h3 class="nw-figma-batch__title">' . esc_html__('Or pull in bulk', 'nibwp') . '</h3>';
        echo '<p class="nw-figma-card__sub">' . esc_html__('Paste a link above and pull in bulk: a FILE link pulls every frame in that file, a TEAM or PROJECT link walks every file in it. Or refresh what you already have. Frames are pulled one at a time so nothing times out — you can watch it go.', 'nibwp') . '</p>';
        echo '<div class="nw-figma-batch__acts">';
        echo '<button type="button" class="nw-figma-btn nw-figma-btn--ghost" id="nw-figma-pullall">'
            . '<span class="nw-figma-spin nw-figma-spin--dark" aria-hidden="true"></span>'
            . '<svg class="nw-figma-btn__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="13" height="13" rx="2"/><path d="M19 8v11a2 2 0 0 1-2 2H8"/><path d="M9.5 7.5v5"/><path d="m7 10.5 2.5 2.5L12 10.5"/></svg>'
            . '<span class="nw-figma-btn__label">' . esc_html__('Pull all frames from this file', 'nibwp') . '</span></button>';
        echo '<button type="button" class="nw-figma-btn nw-figma-btn--ghost" id="nw-figma-pullws">'
            . '<span class="nw-figma-spin nw-figma-spin--dark" aria-hidden="true"></span>'
            . '<svg class="nw-figma-btn__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h3.6a2 2 0 0 1 1.4.6L11.5 7H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="10" cy="12.5" r="1.6"/><path d="M7 17c.4-1.5 1.6-2.3 3-2.3s2.6.8 3 2.3"/><circle cx="15.5" cy="11.5" r="1.2"/></svg>'
            . '<span class="nw-figma-btn__label">' . esc_html__('Pull a whole team / project', 'nibwp') . '</span></button>';
        echo '<button type="button" class="nw-figma-btn nw-figma-btn--ghost" id="nw-figma-sync" data-empty="' . ($lib === [] ? '1' : '0') . '"' . ($lib === [] ? ' disabled' : '') . '>'
            . '<span class="nw-figma-spin nw-figma-spin--dark" aria-hidden="true"></span>'
            . '<svg class="nw-figma-btn__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 0 1-9 9 9 9 0 0 1-7.4-3.9"/><path d="M3 12a9 9 0 0 1 9-9 9 9 0 0 1 7.4 3.9"/><path d="M20 3v4.5h-4.5"/><path d="M4 21v-4.5h4.5"/></svg>'
            . '<span class="nw-figma-btn__label">' . esc_html__('Re-sync library', 'nibwp') . ' (' . count($lib) . ')</span></button>';
        echo '<button type="button" class="nw-figma-btn nw-figma-btn--ghost nw-figma-batch__stop" id="nw-figma-batch-stop" hidden>'
            . '<svg class="nw-figma-btn__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.5"/></svg>'
            . '<span class="nw-figma-btn__label">' . esc_html__('Stop', 'nibwp') . '</span></button>';
        echo '</div>';
        echo '<div class="nw-figma-progress" id="nw-figma-progress" hidden>'
            . '<div class="nw-figma-progress__bar"><span id="nw-figma-progress-fill"></span></div>'
            . '<p class="nw-figma-progress__txt" id="nw-figma-progress-txt" role="status" aria-live="polite"></p>'
            . '</div>';
        echo '<p class="nw-figma-note nw-figma-note--info">'
            . esc_html__('Figma has no "everything I own" endpoint, and personal drafts cannot be listed at all — so bulk pulls work per file, project or team. Paste a team link to get the widest sweep.', 'nibwp')
            . '</p>';
        echo '</div>';

        echo '<ol class="nw-figma-steps"><li>' . esc_html__('In Figma, select a frame or section and copy its link (⌘/Ctrl-L).', 'nibwp') . '</li>'
            . '<li>' . esc_html__('Paste it above — NibWP renders a 2× image and extracts the palette + type ramp.', 'nibwp') . '</li>'
            . '<li>' . esc_html__('Ask NibWP AI to use it: reuse its tokens, or build it with your page builder.', 'nibwp') . '</li></ol>';
        if (!$connected) {
            echo '<p class="nw-figma-card__sub">' . esc_html__('Connect a Figma account first — see the Connection tab.', 'nibwp') . '</p>';
        }
        echo '</div></div>';

        /* ── Library ── */
        echo '<div class="nw-figma-panel' . ($tab === 'library' ? ' is-active' : '') . '" data-figpanel="library">';
        echo '<div class="nibwp-card nw-figma-card">';
        echo '<h2 class="nibwp-card-title">' . esc_html__('Library', 'nibwp') . ' <span class="nw-figma-count">' . count($lib) . '</span></h2>';
        if ($lib === []) {
            echo '<p class="nw-figma-card__sub">' . esc_html__('No frames pulled yet. Pull one from the Pull tab, then tell NibWP AI what to do with it.', 'nibwp') . '</p>';
        } else {
            echo '<div class="nw-figma-grid" id="nw-figma-grid">';
            foreach (array_reverse($lib) as $e) {
                nibwp_figma_render_lib_card((array) $e);
            }
            echo '</div>';
            echo '<p class="nw-figma-empty" id="nw-figma-empty" hidden>' . esc_html__('No frames match that search.', 'nibwp') . '</p>';
            // Progressive reveal — see nibwp_figma_echo_tabs_js().
            echo '<div class="nw-figma-more" id="nw-figma-more" hidden>'
                . '<button type="button" class="nw-figma-btn nw-figma-btn--ghost nw-figma-more__btn" id="nw-figma-more-btn">'
                . '<span class="nw-figma-spin nw-figma-spin--dark" aria-hidden="true"></span>'
                . '<span class="nw-figma-btn__label">' . esc_html__('Load more', 'nibwp') . '</span>'
                . '</button></div>';
        }
        echo '</div></div>';

        /* ── How to ── */
        echo '<div class="nw-figma-panel' . ($tab === 'howto' ? ' is-active' : '') . '" data-figpanel="howto">';
        nibwp_figma_echo_howto();
        echo '</div>';

        nibwp_figma_echo_tabs_js();
    }

    echo '</div></div>';

    // Closes the app shell and renders the sticky Help tab. Every other NibWP
    // screen calls this; without it the Figma page lost the help drawer.
    if (!$modal && function_exists('nibwp_render_admin_footer')) {
        nibwp_render_admin_footer();
    }
}

/**
 * "How to" tab — the whole flow in plain language: pull a frame, then ask the
 * AI agent to convert it. Everything past the pull happens through NibWP's MCP
 * abilities, so this is written as prompts the user can actually copy.
 */
function nibwp_figma_echo_howto(): void
{
    $steps = [
        [
            'n'    => '1',
            'h'    => __('Connect Figma once', 'nibwp'),
            'p'    => __('Connection tab → paste a personal access token (read-only). NibWP can then read any file your Figma account can open, including private ones. Nothing in Figma is ever modified.', 'nibwp'),
        ],
        [
            'n'    => '2',
            'h'    => __('Pull the frames you care about', 'nibwp'),
            'p'    => __('In Figma select a frame or section, copy its link, and paste it in the Pull tab. NibWP caches a 2× image, extracts the color palette and type ramp, and stores the structure. It does NOT convert anything yet — pulling is cheap and reversible.', 'nibwp'),
        ],
        [
            'n'    => '3',
            'h'    => __('Give it a name you can say out loud', 'nibwp'),
            'p'    => __('Every pulled frame gets a handle like @figma/hero-section (click it in the Library to copy). That handle is how you refer to the design when talking to the AI agent.', 'nibwp'),
        ],
        [
            'n'    => '4',
            'h'    => __('Ask the AI agent to build it', 'nibwp'),
            'p'    => __('Connect your AI client to this site (NibWP → Connect), then ask in plain English. NibWP reads the design, establishes the tokens, picks the builder your site actually runs, and saves a DRAFT — never overwriting a live page.', 'nibwp'),
        ],
    ];

    echo '<div class="nibwp-card nw-figma-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('How it works', 'nibwp') . '</h2>';
    echo '<p class="nw-figma-card__sub">' . esc_html__('Pull is something you do here. Converting is something you ask the AI agent to do — that way the same design can become an Etch section, an Elementor page or core blocks, depending on what you need.', 'nibwp') . '</p>';

    echo '<div class="nw-figma-how">';
    foreach ($steps as $s) {
        echo '<div class="nw-figma-how__step">';
        echo '<span class="nw-figma-how__n">' . esc_html($s['n']) . '</span>';
        echo '<div><strong>' . esc_html($s['h']) . '</strong><p>' . esc_html($s['p']) . '</p></div>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';

    /* Prompts */
    echo '<div class="nibwp-card nw-figma-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('Things to say to the AI agent', 'nibwp') . '</h2>';
    echo '<p class="nw-figma-card__sub">' . esc_html__('Click any line to copy it. Replace the handle with one from your Library.', 'nibwp') . '</p>';

    $prompts = [
        __('Convert @figma/hero-section into a page on my site.', 'nibwp')            => __('Full build — NibWP picks the active builder, saves a draft.', 'nibwp'),
        __('Show me what @figma/hero-section would build, but do not save it.', 'nibwp') => __('Dry run — structure and diff report only, nothing written.', 'nibwp'),
        __('Use the colors and typography from @figma/hero-section on my site.', 'nibwp') => __('Design tokens only — no layout, just the palette and type ramp.', 'nibwp'),
        __('Build @figma/pricing-cards with Elementor instead.', 'nibwp')             => __('Force a specific builder rather than the auto-detected one.', 'nibwp'),
        __('What Figma frames do I have pulled?', 'nibwp')                            => __('Lists your library so the agent can pick one.', 'nibwp'),
        __('Pull https://www.figma.com/design/KEY/Name?node-id=1-234 into my library.', 'nibwp') => __('Pull straight from the chat — no need to come back to this screen.', 'nibwp'),
        __('What Figma commands can I use in NibWP?', 'nibwp')                         => __('Discovery — the agent lists every Figma ability available on this site.', 'nibwp'),
    ];
    echo '<ul class="nw-figma-prompts">';
    foreach ($prompts as $prompt => $why) {
        echo '<li><button type="button" class="nw-figma-copy nw-figma-prompt" data-copy="' . esc_attr($prompt) . '">'
            . '<span class="nw-figma-copy__text">' . esc_html($prompt) . '</span>'
            . '<svg class="nw-figma-copy__icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>'
            . '</button><span class="nw-figma-prompts__why">' . esc_html($why) . '</span></li>';
    }
    echo '</ul>';

    // Free text — the prompts above are examples, not a fixed command set.
    echo '<div class="nw-figma-free">';
    echo '<strong>' . esc_html__('Or just say it in your own words', 'nibwp') . '</strong>';
    echo '<p>' . esc_html__('None of these are fixed commands — the agent understands plain language. Reference a pulled frame with @ (type @figma/ and the handle), paste a Figma link directly to pull it on the spot, or describe what you want: "take the hero from @figma/homepage, but make the button green and build it with Etch".', 'nibwp') . '</p>';
    echo '<p>' . esc_html__('Not sure what is possible? Ask "what Figma commands can I use in NibWP?" and the agent will list every ability this site exposes — pull, list, get, analyze, convert, detect builder — with what each one does.', 'nibwp') . '</p>';
    echo '</div>';

    echo '</div>';

    /* What happens under the hood + honest limits */
    echo '<div class="nibwp-card nw-figma-card">';
    echo '<h2 class="nibwp-card-title">' . esc_html__('What happens when you ask', 'nibwp') . '</h2>';
    echo '<ol class="nw-figma-steps">'
        . '<li>' . esc_html__('NibWP reads the real Figma node tree — auto-layout, constraints, fills, text styles — not a screenshot.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('Design tokens are established first (colors, spacing, type ramp) so the build references variables instead of hardcoded values.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('Repeated components are detected once and reused, rather than duplicated for every instance.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('The active builder is detected (Etch, Bricks, Elementor, Oxygen, Kadence) and the build is handed to that builder\'s own skill. Core blocks are the fallback.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('The result is saved as a draft with images sideloaded into your Media Library. Review it before publishing.', 'nibwp') . '</li>'
        . '</ol>';
    echo '<p class="nw-figma-muted">' . esc_html__('Good to know: a design pulled today can be rebuilt any time — re-pulling the same frame updates it in place and keeps its handle. Frames laid out without auto-layout, or fonts your site does not have installed, are reported as warnings rather than silently approximated.', 'nibwp') . '</p>';
    echo '</div>';
}

/** Tab switching — no reload, keeps ?tab= in the URL so refresh/back behave. */
function nibwp_figma_echo_tabs_js(): void
{
    ?>
    <script>
    window.nibwpFigma = {
        ajax:  <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo wp_json_encode(wp_create_nonce('nibwp_figma_lib')); ?>,
        i18n:  {
            confirmRemove: <?php echo wp_json_encode(__('Remove this frame from the library?', 'nibwp')); ?>,
            removeTitle:   <?php echo wp_json_encode(__('Remove this frame?', 'nibwp')); ?>,
            /* %s is replaced with the frame name, or "this frame" when unnamed. */
            removeBody:    <?php echo wp_json_encode(__('This removes %s from your Figma library. The design in Figma is untouched, and you can pull it again at any time.', 'nibwp')); ?>,
            removeConfirm: <?php echo wp_json_encode(__('Remove', 'nibwp')); ?>,
            thisFrame:     <?php echo wp_json_encode(__('this frame', 'nibwp')); ?>,
            failed:        <?php echo wp_json_encode(__('That did not work. Try again.', 'nibwp')); ?>
        }
    };
    (function(){
        /* Pull is a full POST round-trip (Figma fetch + image render + sideload),
           so show progress on the button instead of a dead-looking page. The
           disable is deferred a tick — disabling during submit cancels it. */
        var pullForm = document.querySelector('.nw-figma-pull');
        if (pullForm) {
            pullForm.addEventListener('submit', function(){
                var btn = pullForm.querySelector('.nw-figma-pull__btn');
                if (!btn || btn.classList.contains('is-loading')) { return; }
                var label = btn.querySelector('.nw-figma-btn__label');
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
                if (label && btn.dataset.busyLabel) { label.textContent = btn.dataset.busyLabel; }
                setTimeout(function(){ btn.disabled = true; }, 0);
            });
        }

        /* Library: live filter + progressive reveal.
           The whole library is already in the DOM, so paging reveals rather than
           refetches — instant, and it keeps working while a search is active. */
        var search = document.getElementById('nw-figma-search');
        var grid   = document.getElementById('nw-figma-grid');
        if (grid) {
            var PAGE     = 18;   // ~3 rows — Load more only appears beyond this
            var clear    = document.getElementById('nw-figma-search-clear');
            var count    = document.getElementById('nw-figma-search-count');
            var empty    = document.getElementById('nw-figma-empty');
            var moreWrap = document.getElementById('nw-figma-more');
            var moreBtn  = document.getElementById('nw-figma-more-btn');
            var items    = Array.prototype.slice.call(grid.querySelectorAll('.nw-figma-item'));
            var limit    = PAGE;

            var apply = function(){
                var q = search ? search.value.trim().toLowerCase() : '';
                var matched = 0, shown = 0;
                items.forEach(function(item){
                    var hit = q === '' || (item.getAttribute('data-search') || '').indexOf(q) !== -1;
                    if (!hit) { item.hidden = true; return; }
                    matched++;
                    var within = matched <= limit;
                    item.hidden = !within;
                    if (within) { shown++; }
                });
                if (count) { count.textContent = shown + ' / ' + items.length; }
                if (clear) { clear.hidden = q === ''; }
                if (empty) { empty.hidden = matched !== 0; }
                if (moreWrap) { moreWrap.hidden = matched <= limit; }
            };

            if (search) {
                var onType = function(){ limit = PAGE; apply(); };  // new query, back to page 1
                search.addEventListener('input', onType);
                search.addEventListener('search', onType);
                if (clear) {
                    clear.addEventListener('click', function(){ search.value = ''; onType(); search.focus(); });
                }
            }

            if (moreBtn) {
                moreBtn.addEventListener('click', function(){
                    if (moreBtn.classList.contains('is-loading')) { return; }
                    moreBtn.classList.add('is-loading');
                    // Brief beat so the spinner registers as progress, then reveal.
                    setTimeout(function(){
                        limit += PAGE;
                        apply();
                        moreBtn.classList.remove('is-loading');
                    }, 220);
                });
            }

            apply();
        }

        /* Library item actions — rename + remove, both AJAX, no page reload. */
        var cfg = window.nibwpFigma || {};
        var post = function(action, data){
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('_ajax_nonce', cfg.nonce || '');
            Object.keys(data).forEach(function(k){ body.set(k, data[k]); });
            return fetch(cfg.ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then(function(r){ return r.json(); });
        };

        if (grid) {
            var startRename = function(item){
                item.classList.add('is-renaming');
                var input = item.querySelector('.nw-figma-renameform__input');
                if (input) { input.focus(); input.select(); }
            };
            var commitRename = function(item){
                var input = item.querySelector('.nw-figma-renameform__input');
                var label = item.querySelector('.nw-figma-item__label');
                if (!input) { return; }
                var name = input.value.trim();
                item.classList.remove('is-renaming');
                if (name === '' || (label && name === label.textContent.trim())) { return; }

                item.classList.add('is-busy');
                post('nibwp_figma_rename', {id: item.getAttribute('data-id'), name: name})
                    .then(function(res){
                        item.classList.remove('is-busy');
                        if (!res || !res.success) { window.alert((res && res.data && res.data.message) || cfg.i18n.failed); return; }
                        if (label) { label.textContent = res.data.name; }
                        var chip = item.querySelector('.nw-figma-hint');
                        if (chip) {
                            chip.setAttribute('data-copy', res.data.command);
                            var txt = chip.querySelector('.nw-figma-copy__text');
                            if (txt) { txt.textContent = res.data.command; }
                        }
                        // Keep search in sync with the new name/handle.
                        item.setAttribute('data-search', (res.data.name + ' ' + res.data.handle).toLowerCase());
                    })
                    .catch(function(){ item.classList.remove('is-busy'); window.alert(cfg.i18n.failed); });
            };

            grid.addEventListener('click', function(e){
                var item = e.target.closest ? e.target.closest('.nw-figma-item') : null;
                if (!item) { return; }
                if (e.target.closest('.nw-figma-rename')) { e.preventDefault(); startRename(item); return; }
                if (e.target.closest('.nw-figma-del')) {
                    e.preventDefault();
                    var removeFrame = function(){
                        item.classList.add('is-busy');
                        post('nibwp_figma_lib_remove', {id: item.getAttribute('data-id')})
                            .then(function(res){
                                if (!res || !res.success) { item.classList.remove('is-busy'); window.alert(cfg.i18n.failed); return; }
                                item.classList.add('is-removing');
                                setTimeout(function(){
                                    item.remove();
                                    items = items.filter(function(i){ return i !== item; });
                                    var tabCount = document.querySelector('.nw-int-tab[data-figtab="library"] .nw-int-tab-count');
                                    if (tabCount) { tabCount.textContent = res.data.count; }
                                    apply();
                                }, 200);
                            })
                            .catch(function(){ item.classList.remove('is-busy'); window.alert(cfg.i18n.failed); });
                    };

                    /* Use NibWP's own dialog. The embedded modal view renders
                       without the admin footer, so the shared one may not be
                       on the page — fall back rather than lose the guard. */
                    if (typeof window.nibwpConfirm === 'function') {
                        var nameEl = item.querySelector('.nw-figma-item__label');
                        var frame  = nameEl ? nameEl.textContent.trim() : '';
                        window.nibwpConfirm({
                            title: cfg.i18n.removeTitle,
                            message: cfg.i18n.removeBody.replace('%s', frame ? '<code>' + frame.replace(/[&<>"]/g, function(c){
                                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
                            }) + '</code>' : cfg.i18n.thisFrame),
                            confirmLabel: cfg.i18n.removeConfirm,
                            onConfirm: removeFrame
                        });
                        return;
                    }
                    if (window.confirm(cfg.i18n.confirmRemove)) { removeFrame(); }
                }
            });

            grid.addEventListener('keydown', function(e){
                if (!e.target.classList || !e.target.classList.contains('nw-figma-renameform__input')) { return; }
                var item = e.target.closest('.nw-figma-item');
                if (e.key === 'Enter') { e.preventDefault(); commitRename(item); }
                if (e.key === 'Escape') { item.classList.remove('is-renaming'); }
            });
            grid.addEventListener('blur', function(e){
                if (e.target.classList && e.target.classList.contains('nw-figma-renameform__input')) {
                    commitRename(e.target.closest('.nw-figma-item'));
                }
            }, true);
        }

        /* Bulk pull — scan for targets, then pull one frame per request so a big
           file can't blow the PHP time limit and progress is honest. */
        (function(){
            var pullAll = document.getElementById('nw-figma-pullall');
            var pullWs  = document.getElementById('nw-figma-pullws');
            var syncBtn = document.getElementById('nw-figma-sync');
            var stopBtn = document.getElementById('nw-figma-batch-stop');
            var wrap    = document.getElementById('nw-figma-progress');
            var fill    = document.getElementById('nw-figma-progress-fill');
            var txt     = document.getElementById('nw-figma-progress-txt');
            if (!pullAll && !syncBtn) { return; }

            var cancelled = false, running = false;

            var setBusy = function(btn, on){
                if (!btn) { return; }
                btn.classList.toggle('is-loading', on);
                btn.disabled = on;
            };
            var finish = function(btn, msg){
                running = false;
                setBusy(btn, false);
                [pullAll, pullWs, syncBtn].forEach(function(b){ if (b) { b.disabled = false; } });
                if (syncBtn && syncBtn.dataset.empty === '1') { syncBtn.disabled = true; }
                if (stopBtn) { stopBtn.hidden = true; }
                if (txt) { txt.textContent = msg; }
            };

            /* Walk the frames of many files, accumulating pull targets. */
            var expandFiles = function(files, onDone){
                var out = [], i = 0;
                var step = function(){
                    if (cancelled || i >= files.length) { onDone(out); return; }
                    var f = files[i++];
                    if (txt) { txt.textContent = '<?php echo esc_js(__('Reading file', 'nibwp')); ?> ' + i + ' / ' + files.length + ' — ' + (f.name || ''); }
                    if (fill) { fill.style.width = Math.round((i / files.length) * 30) + '%'; }
                    post('nibwp_figma_scan', {mode: 'file', key: f.key}).then(function(r){
                        if (r && r.success) {
                            (r.data.targets || []).forEach(function(t){ out.push(t); });
                        }
                        step();
                    }).catch(step);
                };
                step();
            };

            var run = function(btn, mode){
                if (running) { return; }
                var payload = {mode: mode};
                if (mode === 'file' || mode === 'workspace') {
                    var input = document.querySelector('.nw-figma-pull input[name="figma_url"]');
                    var url = input ? input.value.trim() : '';
                    if (url === '') { input && input.focus(); return; }
                    payload.url = url;
                }
                running = true; cancelled = false;
                setBusy(btn, true);
                [pullAll, pullWs, syncBtn].forEach(function(b){ if (b) { b.disabled = true; } });
                if (wrap) { wrap.hidden = false; }
                if (fill) { fill.style.width = '0%'; }
                if (txt) { txt.textContent = '<?php echo esc_js(__('Looking for frames…', 'nibwp')); ?>'; }

                var pullTargets = function(targets){
                    if (!targets.length) {
                        finish(btn, '<?php echo esc_js(__('Nothing to pull.', 'nibwp')); ?>');
                        return;
                    }
                    if (stopBtn) { stopBtn.hidden = false; }

                    var i = 0, ok = 0, failed = 0;
                    var next = function(){
                        if (cancelled || i >= targets.length) {
                            var msg = ok + '/' + targets.length + ' <?php echo esc_js(__('pulled', 'nibwp')); ?>'
                                + (failed ? ' · ' + failed + ' <?php echo esc_js(__('failed', 'nibwp')); ?>' : '')
                                + (cancelled ? ' · <?php echo esc_js(__('stopped', 'nibwp')); ?>' : '');
                            finish(btn, msg);
                            if (ok) { setTimeout(function(){ window.location.search = '?page=nibwp-figma&tab=library'; }, 700); }
                            return;
                        }
                        var t = targets[i++];
                        if (txt) { txt.textContent = i + ' / ' + targets.length + ' — ' + (t.name || ''); }
                        if (fill) { fill.style.width = Math.round((i / targets.length) * 100) + '%'; }
                        post('nibwp_figma_pull_one', {key: t.key, node: t.node, name: t.name || ''})
                            .then(function(r){ (r && r.success) ? ok++ : failed++; next(); })
                            .catch(function(){ failed++; next(); });
                    };
                    next();
                };

                if (mode === 'workspace') {
                    // team/project → files → frames → pull
                    post('nibwp_figma_scan_workspace', {url: payload.url}).then(function(res){
                        if (!res || !res.success) {
                            finish(btn, (res && res.data && res.data.message) || cfg.i18n.failed);
                            return;
                        }
                        var files = res.data.files || [];
                        if (!files.length) { finish(btn, '<?php echo esc_js(__('No files found there.', 'nibwp')); ?>'); return; }
                        if (stopBtn) { stopBtn.hidden = false; }
                        expandFiles(files, pullTargets);
                    }).catch(function(){ finish(btn, cfg.i18n.failed); });
                    return;
                }

                post('nibwp_figma_scan', payload).then(function(res){
                    if (!res || !res.success) {
                        finish(btn, (res && res.data && res.data.message) || cfg.i18n.failed);
                        return;
                    }
                    pullTargets(res.data.targets || []);
                }).catch(function(){ finish(btn, cfg.i18n.failed); });
            };

            if (pullAll) { pullAll.addEventListener('click', function(){ run(pullAll, 'file'); }); }
            if (pullWs)  { pullWs.addEventListener('click',  function(){ run(pullWs, 'workspace'); }); }
            if (syncBtn) { syncBtn.addEventListener('click', function(){ run(syncBtn, 'library'); }); }
            if (stopBtn) { stopBtn.addEventListener('click', function(){ cancelled = true; stopBtn.hidden = true; }); }
        })();

        /* Click-to-copy handles and prompts. */
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

        var tabs = document.querySelectorAll('.nw-int-tab[data-figtab]');
        var panels = document.querySelectorAll('.nw-figma-panel');
        var searchWrap = document.getElementById('nw-figma-search-wrap');
        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                var key = tab.getAttribute('data-figtab');
                tabs.forEach(function(t){ t.classList.toggle('is-active', t === tab); });
                panels.forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-figpanel') === key); });
                // The search lives in the tab row but only applies to the library.
                if (searchWrap) { searchWrap.hidden = key !== 'library'; }
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.set('tab', key);
                    history.replaceState({}, '', u);
                } catch (e) {}
            });
        });
    })();
    </script>
    <?php
}

/* ── Keep the Figma screen clean ──
 * Third-party plugins dump review nags, upsells and update banners onto every
 * admin page. On our own screen that is noise the user did not ask for, so we
 * strip every notice callback that does not originate from NibWP itself. Runs on
 * in_admin_header (after plugins registered, before notices render) and only on
 * page=nibwp-figma — every other screen is left untouched. */
add_action('in_admin_header', static function (): void {
    if (!is_admin() || ($_GET['page'] ?? '') !== 'nibwp-figma') {
        return;
    }
    global $wp_filter;
    foreach (['admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices'] as $hook) {
        if (empty($wp_filter[$hook]) || !isset($wp_filter[$hook]->callbacks)) {
            continue;
        }
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $id => $cb) {
                if (!nibwp_figma_notice_is_ours($cb['function'] ?? null)) {
                    unset($wp_filter[$hook]->callbacks[$priority][$id]);
                }
            }
        }
    }
}, 1);

/**
 * Does this notice callback live inside NibWP (free or Pro)? Resolved by the
 * file that declares it, so closures, functions and class methods all work.
 * Anything we cannot resolve is treated as foreign and dropped.
 */
function nibwp_figma_notice_is_ours($callback): bool
{
    if ($callback === null) {
        return false;
    }
    try {
        if (is_string($callback) && str_contains($callback, '::')) {
            [$class, $method] = explode('::', $callback, 2);
            $ref = new ReflectionMethod($class, $method);
        } elseif (is_array($callback) && count($callback) === 2) {
            $ref = new ReflectionMethod($callback[0], (string) $callback[1]);
        } elseif (is_object($callback) && !$callback instanceof Closure && method_exists($callback, '__invoke')) {
            $ref = new ReflectionMethod($callback, '__invoke');
        } else {
            $ref = new ReflectionFunction($callback);
        }
        $file = (string) $ref->getFileName();
    } catch (Throwable $e) {
        return false;
    }
    if ($file === '') {
        return false;
    }
    $file = wp_normalize_path($file);
    // Our own directory, plus sibling NibWP plugins (nibwp-pro, skill add-ons).
    if (defined('NIBWP_PLUGIN_DIR') && str_starts_with($file, wp_normalize_path(NIBWP_PLUGIN_DIR))) {
        return true;
    }
    return (bool) preg_match('#/plugins/nibwp[^/]*/#', $file);
}

/** One library card: thumbnail + tokens + the exact AI hint to use it. */
function nibwp_figma_render_lib_card(array $e): void
{
    $id     = (string) ($e['id'] ?? '');
    $name   = (string) ($e['name'] ?? 'Frame');
    $handle = (string) ($e['handle'] ?? '');
    $img    = (string) ($e['image_url'] ?? '');
    $colors = (array) ($e['tokens']['colors'] ?? []);
    // What the user says to NibWP to act on this frame.
    $command = $handle !== '' ? '@figma/' . $handle : 'figma-get id:' . $id;

    $haystack = strtolower(trim($name . ' ' . $handle . ' ' . implode(' ', array_map('strval', $colors))));

    echo '<div class="nw-figma-item" data-id="' . esc_attr($id) . '" data-search="' . esc_attr($haystack) . '">';
    if ($img !== '') {
        echo '<div class="nw-figma-item__thumb" style="background-image:url(' . esc_url($img) . ');"></div>';
    }
    echo '<div class="nw-figma-item__body">';
    echo '<span class="nw-figma-item__name">'
        . '<span class="nw-figma-item__label">' . esc_html($name) . '</span>'
        . '<button type="button" class="nw-figma-rename" aria-label="' . esc_attr__('Rename', 'nibwp') . '" title="' . esc_attr__('Rename — this sets the name you call it by', 'nibwp') . '">'
        . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>'
        . '</button></span>';
    echo '<div class="nw-figma-renameform">'
        . '<input type="text" class="nw-figma-renameform__input" value="' . esc_attr($name) . '" maxlength="80" aria-label="' . esc_attr__('Frame name', 'nibwp') . '" />'
        . '</div>';

    if ($colors !== []) {
        echo '<div class="nw-figma-swatches">';
        foreach (array_slice($colors, 0, 6) as $hex) {
            echo '<span class="nw-figma-swatch" title="' . esc_attr((string) $hex) . '" style="background:' . esc_attr((string) $hex) . ';"></span>';
        }
        echo '</div>';
    }

    // Click-to-copy handle — this is how you refer to the frame in a prompt.
    echo '<button type="button" class="nw-figma-hint nw-figma-copy" data-copy="' . esc_attr($command) . '"'
        . ' title="' . esc_attr__('Copy — use this name when you ask NibWP to build it', 'nibwp') . '">'
        . '<span class="nw-figma-copy__text">' . esc_html($command) . '</span>'
        . '<svg class="nw-figma-copy__icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>'
        . '</button>';

    echo '<div class="nw-figma-item__acts">';
    echo '<a class="nw-figma-item__link" href="' . esc_url((string) ($e['url'] ?? '')) . '" target="_blank" rel="noopener">'
        . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H8.5a3.5 3.5 0 000 7H12z"/><path d="M12 2h3.5a3.5 3.5 0 110 7H12z"/><path d="M12 9H8.5a3.5 3.5 0 000 7H12z"/><path d="M12 16H8.5A3.5 3.5 0 1012 19.5z"/><circle cx="15.5" cy="12.5" r="3.5"/></svg>'
        . esc_html__('Open in Figma', 'nibwp') . '</a>';
    echo '<button type="button" class="nw-figma-iconbtn nw-figma-iconbtn--danger nw-figma-del" aria-label="' . esc_attr__('Remove from library', 'nibwp') . '" title="' . esc_attr__('Remove from library', 'nibwp') . '">'
        . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M6 6v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6"/></svg>'
        . '</button>';
    echo '</div>';

    echo '</div></div>';
}

/**
 * The connect forms (status + token / OAuth / Dev-Mode) on the full Figma page.
 * The Integrations-page lightbox renders its own nw-req-styled copy; its forms
 * carry from_modal=1 so handlers land back in that lightbox.
 */
function nibwp_figma_echo_connect_forms(bool $from_modal = false): void
{
    $connected  = nibwp_figma_is_connected();
    $handle     = (string) get_option('nibwp_figma_handle', '');
    $client_id  = (string) get_option('nibwp_figma_oauth_client_id', '');
    $has_secret = get_option('nibwp_figma_oauth_client_secret', '') !== '';
    $redirect   = nibwp_figma_oauth_redirect_uri();
    $post       = esc_url(admin_url('admin-post.php'));
    $m          = $from_modal ? '<input type="hidden" name="from_modal" value="1" />' : '';

    // Status strip — always first, so "am I connected?" is answered instantly.
    echo '<div class="nw-figma-status' . ($connected ? ' is-on' : '') . '">';
    echo '<span class="nw-figma-dot' . ($connected ? ' is-on' : '') . '"></span>';
    if ($connected) {
        $mask = nibwp_figma_token_mask();
        echo '<div><strong>' . esc_html__('Connected to Figma', 'nibwp') . '</strong>'
            . '<span>' . ($handle !== '' ? esc_html($handle) . ' · ' : '')
            . '<code class="nw-figma-mask">' . esc_html($mask) . '</code></span></div>';
        echo '<form method="post" action="' . $post . '" class="nw-figma-status__act"><input type="hidden" name="action" value="nibwp_figma_disconnect" />' . $m;
        wp_nonce_field('nibwp_figma_disconnect');
        echo '<button class="nw-figma-btn nw-figma-btn--ghost" type="submit">' . esc_html__('Disconnect', 'nibwp') . '</button></form>';
    } else {
        echo '<div><strong>' . esc_html__('Not connected', 'nibwp') . '</strong><span>'
            . esc_html__('Pick a method below — the access token takes about two minutes.', 'nibwp') . '</span></div>';
    }
    echo '</div>';

    // Accordions: the method actually in use is open and flagged Connected; when
    // nothing is connected the recommended one (token) opens.
    // <details> = native, keyboard-accessible, zero JS.
    $method = nibwp_figma_auth_method();

    // These are alternatives, not a checklist — say so, or the three stacked
    // forms read as "fill everything in".
    echo '<p class="nw-figma-choose">' . ($connected
        ? esc_html__('Pick one — you are already connected. The other methods below are alternatives you can switch to.', 'nibwp')
        : esc_html__('Pick one method below — you only need a single one to connect.', 'nibwp')) . '</p>';
    $in_use = static fn (string $m): string => '<span class="nw-figma-acc__tag nw-figma-acc__tag--on"><span class="nw-figma-dot is-on"></span>' . esc_html__('Connected', 'nibwp') . '</span>';

    // A · token
    echo '<details class="nw-figma-acc' . ($method === 'token' ? ' is-connected' : '') . '"' . (($method === 'token' || $method === '') ? ' open' : '') . '>';
    echo '<summary><span class="nw-figma-acc__n">1</span><span class="nw-figma-acc__t">' . esc_html__('Personal access token', 'nibwp') . '</span>'
        . ($method === 'token' ? $in_use('token') : '<span class="nw-figma-acc__tag">' . esc_html__('Recommended', 'nibwp') . '</span>')
        . '</summary>';
    echo '<div class="nw-figma-acc__body">';
    echo '<ol class="nw-figma-steps">'
        . '<li>' . esc_html__('In Figma, click your profile avatar (top-left) → Settings. A settings window opens.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('Go to the Security tab → Personal access tokens → Generate new token.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('Name it NibWP, set File content to Read-only, then Generate.', 'nibwp') . '</li>'
        . '<li>' . esc_html__('Copy the token (figd_…, shown only once) and paste it below.', 'nibwp') . '</li></ol>';
    echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="nibwp_figma_save_token" />' . $m;
    wp_nonce_field('nibwp_figma_save_token');
    echo '<input class="nw-figma-field" type="password" name="figma_token" placeholder="figd_…" autocomplete="off" />';
    echo '<button class="nw-figma-btn" type="submit">' . esc_html__('Save & Connect', 'nibwp') . '</button></form>';
    echo '<p class="nw-figma-muted">' . esc_html__('Read-only — NibWP can read the files your account can open, never change them. Validated with Figma before saving.', 'nibwp') . '</p>';
    echo '</div></details>';

    // B · OAuth
    echo '<details class="nw-figma-acc' . ($method === 'oauth' ? ' is-connected' : '') . '"' . ($method === 'oauth' ? ' open' : '') . '>';
    echo '<summary><span class="nw-figma-acc__n">2</span><span class="nw-figma-acc__t">' . esc_html__('OAuth app', 'nibwp') . '</span>'
        . ($method === 'oauth' ? $in_use('oauth') : '<span class="nw-figma-acc__tag">' . esc_html__('Teams', 'nibwp') . '</span>')
        . '</summary>';
    echo '<div class="nw-figma-acc__body">';
    echo '<ol class="nw-figma-steps"><li>' . sprintf(
        /* translators: %s: link to Figma developer apps */
        esc_html__('Create an app at %s.', 'nibwp'),
        '<a href="https://www.figma.com/developers/apps" target="_blank" rel="noopener">figma.com/developers/apps</a>'
    ) . '</li><li>' . esc_html__('Add this exact callback URL:', 'nibwp')
        . '<code class="nw-figma-code nw-figma-code--block">' . esc_html($redirect) . '</code></li>'
        . '<li>' . esc_html__('Paste the Client ID + Secret, save, then authorize.', 'nibwp') . '</li></ol>';
    echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="nibwp_figma_save_oauth" />' . $m;
    wp_nonce_field('nibwp_figma_save_oauth');
    echo '<input class="nw-figma-field" type="text" name="figma_client_id" placeholder="Client ID" value="' . esc_attr($client_id) . '" />';
    echo '<input class="nw-figma-field" type="password" name="figma_client_secret" placeholder="' . ($has_secret ? esc_attr__('•••••• (saved — leave blank to keep)', 'nibwp') : 'Client Secret') . '" autocomplete="off" />';
    echo '<button class="nw-figma-btn nw-figma-btn--ghost" type="submit">' . esc_html__('Save app', 'nibwp') . '</button></form>';
    if ($client_id !== '' && $has_secret) {
        // target=_top so Figma's OAuth screen replaces the whole window (it can't be iframed).
        echo '<form method="post" action="' . $post . '" target="_top" class="nw-figma-form-gap"><input type="hidden" name="action" value="nibwp_figma_oauth_start" />' . $m;
        wp_nonce_field('nibwp_figma_oauth_start');
        echo '<button class="nw-figma-btn" type="submit">' . esc_html__('Connect with Figma', 'nibwp') . '</button></form>';
    }
    echo '</div></details>';

    // C · Dev Mode MCP
    echo '<details class="nw-figma-acc">';
    echo '<summary><span class="nw-figma-acc__n">3</span><span class="nw-figma-acc__t">' . esc_html__('Dev Mode MCP', 'nibwp') . '</span><span class="nw-figma-acc__tag">' . esc_html__('AI in Figma', 'nibwp') . '</span></summary>';
    echo '<div class="nw-figma-acc__body">';
    echo '<ol class="nw-figma-steps"><li>' . sprintf(
        /* translators: %s: link to the Figma desktop app download */
        esc_html__('Install the %s (Dev or Full seat).', 'nibwp'),
        '<a href="https://www.figma.com/downloads/" target="_blank" rel="noopener">' . esc_html__('Figma desktop app', 'nibwp') . '</a>'
    ) . '</li><li>' . sprintf(
        /* translators: %s: link to Figma's MCP guide */
        esc_html__('Enable the Dev Mode MCP server — %s.', 'nibwp'),
        '<a href="https://help.figma.com/hc/en-us/articles/32132100833559" target="_blank" rel="noopener">' . esc_html__('Figma\'s guide', 'nibwp') . '</a>'
    ) . '</li><li>' . esc_html__('Your AI then reads the frame selected in Figma and works through NibWP — no token needed for that flow.', 'nibwp') . '</li></ol>';
    echo '<p class="nw-figma-muted">' . esc_html__('Best of both: keep a token connected for headless pulls, use Dev Mode MCP while designing live.', 'nibwp') . '</p>';
    echo '</div></details>';
}

/* ─────────────────────────── handlers ─────────────────────────── */

function nibwp_figma_redirect(array $args, bool $modal = false): void
{
    // Modal submits come from the Integrations-page lightbox — land back there
    // with figma_modal=1 so the lightbox re-opens showing the result in place.
    $base = $modal
        ? add_query_arg('figma_modal', '1', admin_url('admin.php?page=nibwp-integrations'))
        : nibwp_figma_page_url();
    wp_safe_redirect(add_query_arg($args, $base));
    exit();
}

add_action('admin_post_nibwp_figma_save_token', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_save_token');
    $fm = !empty($_POST['from_modal']);
    $token = sanitize_text_field((string) wp_unslash($_POST['figma_token'] ?? ''));
    if ($token === '') {
        nibwp_figma_redirect(['figma_err' => 'Empty token.'], $fm);
    }
    // Validate against Figma before saving.
    $client = new NIBWP_Figma_Client($token);
    $me = $client->me();
    if (is_wp_error($me)) {
        nibwp_figma_redirect(['figma_err' => 'Figma rejected the token: ' . $me->get_error_message()], $fm);
    }
    nibwp_figma_save_secret('nibwp_figma_token', $token);
    update_option('nibwp_figma_auth_method', 'token', false);
    update_option('nibwp_figma_handle', (string) ($me['handle'] ?? $me['email'] ?? ''), false);
    nibwp_figma_redirect(['figma_msg' => 'Connected to Figma.'], $fm);
});

add_action('admin_post_nibwp_figma_save_oauth', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_save_oauth');
    $fm = !empty($_POST['from_modal']);
    $cid = sanitize_text_field((string) wp_unslash($_POST['figma_client_id'] ?? ''));
    $sec = sanitize_text_field((string) wp_unslash($_POST['figma_client_secret'] ?? ''));
    update_option('nibwp_figma_oauth_client_id', $cid, false);
    if ($sec !== '') {
        nibwp_figma_save_secret('nibwp_figma_oauth_client_secret', $sec);
    }
    nibwp_figma_redirect(['figma_msg' => 'App credentials saved.'], $fm);
});

add_action('admin_post_nibwp_figma_oauth_start', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_oauth_start');
    $cid = (string) get_option('nibwp_figma_oauth_client_id', '');
    if ($cid === '') {
        nibwp_figma_redirect(['figma_err' => 'Set the OAuth Client ID first.']);
    }
    $state = wp_generate_password(20, false);
    set_transient('nibwp_figma_oauth_state_' . get_current_user_id(), $state, 900);
    $authorize = add_query_arg([
        'client_id'     => $cid,
        'redirect_uri'  => nibwp_figma_oauth_redirect_uri(),
        'scope'         => 'file_read',
        'state'         => $state,
        'response_type' => 'code',
    ], 'https://www.figma.com/oauth');
    wp_redirect($authorize);
    exit();
});

add_action('admin_post_nibwp_figma_oauth_callback', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    $code  = sanitize_text_field((string) wp_unslash($_GET['code'] ?? ''));
    $state = sanitize_text_field((string) wp_unslash($_GET['state'] ?? ''));
    $saved = get_transient('nibwp_figma_oauth_state_' . get_current_user_id());
    delete_transient('nibwp_figma_oauth_state_' . get_current_user_id());
    if ($code === '' || $state === '' || !hash_equals((string) $saved, $state)) {
        nibwp_figma_redirect(['figma_err' => 'OAuth state mismatch or missing code.']);
    }
    $cid = (string) get_option('nibwp_figma_oauth_client_id', '');
    $sec = nibwp_figma_decrypt((string) get_option('nibwp_figma_oauth_client_secret', ''));
    $res = wp_remote_post('https://api.figma.com/v1/oauth/token', [
        'timeout' => 30,
        'body'    => [
            'client_id'     => $cid,
            'client_secret' => $sec,
            'redirect_uri'  => nibwp_figma_oauth_redirect_uri(),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ],
    ]);
    if (is_wp_error($res)) {
        nibwp_figma_redirect(['figma_err' => 'Token exchange failed: ' . $res->get_error_message()]);
    }
    $data = json_decode((string) wp_remote_retrieve_body($res), true);
    $access = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
    if ($access === '') {
        nibwp_figma_redirect(['figma_err' => 'No access token returned by Figma.']);
    }
    nibwp_figma_save_secret('nibwp_figma_token', $access);
    update_option('nibwp_figma_auth_method', 'oauth', false);
    if (is_array($data) && isset($data['refresh_token'])) {
        nibwp_figma_save_secret('nibwp_figma_oauth_refresh', (string) $data['refresh_token']);
    }
    // Fetch the handle.
    $me = (new NIBWP_Figma_Client($access))->me();
    if (!is_wp_error($me)) {
        update_option('nibwp_figma_handle', (string) ($me['handle'] ?? ''), false);
    }
    nibwp_figma_redirect(['figma_msg' => 'Connected to Figma via OAuth.']);
});

add_action('admin_post_nibwp_figma_disconnect', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_disconnect');
    $fm = !empty($_POST['from_modal']);
    delete_option('nibwp_figma_token');
    delete_option('nibwp_figma_handle');
    delete_option('nibwp_figma_oauth_refresh');
    delete_option('nibwp_figma_auth_method');
    nibwp_figma_redirect(['figma_msg' => 'Disconnected.'], $fm);
});

add_action('admin_post_nibwp_figma_convert', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_convert');
    if (!nibwp_figma_unlocked()) {
        nibwp_figma_redirect(['figma_err' => 'The Figma integration requires a Pro / Bundle / Figma Skill license.']);
    }

    $url = esc_url_raw((string) wp_unslash($_POST['figma_url'] ?? ''));
    $title = sanitize_text_field((string) wp_unslash($_POST['figma_title'] ?? ''));

    $out = nibwp_figma_do_convert($url, $title, 'auto', false);
    if (is_wp_error($out)) {
        $d = (array) $out->get_error_data();
        nibwp_figma_redirect(array_filter([
            'figma_err'   => $out->get_error_message(),
            'figma_retry' => (int) ($d['retry_after'] ?? 0),
        ]));
    }

    nibwp_figma_redirect(['converted' => (int) ($out['post_id'] ?? 0), 'figma_msg' => 'Converted.']);
});

add_action('admin_post_nibwp_figma_pull', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_pull');
    if (!nibwp_figma_unlocked()) {
        nibwp_figma_redirect(['figma_err' => 'The Figma integration requires a Pro / Bundle / Figma Skill license.']);
    }
    $url  = esc_url_raw((string) wp_unslash($_POST['figma_url'] ?? ''));
    $name = sanitize_text_field((string) wp_unslash($_POST['figma_name'] ?? ''));

    $out = nibwp_figma_pull($url, $name);
    if (is_wp_error($out)) {
        $d = (array) $out->get_error_data();
        nibwp_figma_redirect(array_filter([
            'figma_err'   => $out->get_error_message(),
            'figma_retry' => (int) ($d['retry_after'] ?? 0),
        ]));
    }
    nibwp_figma_redirect(['pulled' => (string) ($out['name'] ?? 'Frame')]);
});

/**
 * AJAX: rename a library frame. The name drives the handle the user speaks
 * ("@figma/hero-section"), so renaming regenerates the handle too — that is how
 * a frame stops being addressed by a raw id.
 */
add_action('wp_ajax_nibwp_figma_rename', static function (): void {
    if (!current_user_can('manage_options') || !nibwp_figma_unlocked()) {
        wp_send_json_error(['message' => __('Not allowed.', 'nibwp')], 403);
    }
    check_ajax_referer('nibwp_figma_lib');

    $id   = sanitize_text_field((string) wp_unslash($_POST['id'] ?? ''));
    $name = sanitize_text_field((string) wp_unslash($_POST['name'] ?? ''));
    $name = trim($name);

    $entry = $id !== '' ? NIBWP_Figma_Library::get($id) : null;
    if ($entry === null) {
        wp_send_json_error(['message' => __('That frame is no longer in the library.', 'nibwp')], 404);
    }
    if ($name === '') {
        wp_send_json_error(['message' => __('Give the frame a name.', 'nibwp')], 400);
    }

    $entry['name'] = $name;
    // Re-slug from the new name, keeping it unique against the other frames.
    $entry['handle'] = NIBWP_Figma_Library::make_handle($name, $id);
    NIBWP_Figma_Library::save($entry);

    wp_send_json_success([
        'id'      => $id,
        'name'    => $entry['name'],
        'handle'  => $entry['handle'],
        'command' => '@figma/' . $entry['handle'],
    ]);
});

/** AJAX: remove a frame from the library (also drops its cached image). */
add_action('wp_ajax_nibwp_figma_lib_remove', static function (): void {
    if (!current_user_can('manage_options') || !nibwp_figma_unlocked()) {
        wp_send_json_error(['message' => __('Not allowed.', 'nibwp')], 403);
    }
    check_ajax_referer('nibwp_figma_lib');

    $id = sanitize_text_field((string) wp_unslash($_POST['id'] ?? ''));
    if ($id === '') {
        wp_send_json_error(['message' => __('Missing frame.', 'nibwp')], 400);
    }
    NIBWP_Figma_Library::delete($id);
    wp_send_json_success(['id' => $id, 'count' => count(NIBWP_Figma_Library::all())]);
});

add_action('admin_post_nibwp_figma_lib_delete', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('nibwp_figma_lib_delete');
    $id = sanitize_text_field((string) wp_unslash($_POST['lib_id'] ?? ''));
    if ($id !== '') {
        NIBWP_Figma_Library::delete($id);
    }
    nibwp_figma_redirect(['figma_msg' => 'Removed from library.']);
});

/* ─────────────────── conversion core (shared) ─────────────────── */

/**
 * Which builders are active on this site + which enhancer skills are on.
 *
 * @return array<string,mixed>
 */
function nibwp_figma_detect_builders(): array
{
    // Reuse NibWP's own plugin detection rather than guessing at constants —
    // Etch, for one, defines neither ETCH_VERSION nor etchit().
    $probe = static function (string $key, callable $fallback): bool {
        if (function_exists('nibwp_is_integration_available')) {
            return nibwp_is_integration_available($key);
        }
        return $fallback();
    };

    $builders = [];
    if ($probe('etchwp', static fn (): bool => class_exists('Etch\\Plugin'))) {
        $builders[] = 'etchwp';
    }
    if ($probe('bricks', static fn (): bool => defined('BRICKS_VERSION'))) {
        $builders[] = 'bricks';
    }
    if ($probe('elementor', static fn (): bool => defined('ELEMENTOR_VERSION'))) {
        $builders[] = 'elementor';
    }
    if ($probe('kadence', static fn (): bool => class_exists('Kadence_Blocks_Frontend'))) {
        $builders[] = 'kadence';
    }
    if (defined('CT_VERSION')) {
        $builders[] = 'oxygen';
    }
    $builders[] = 'gutenberg'; // always available

    // A builder is only usable for a handoff once its skill has registered the
    // html-to-* ability — installed but disabled is not the same as ready.
    $map     = nibwp_figma_builder_ability_map();
    $ready   = [];
    $blocked = [];
    foreach ($builders as $b) {
        if ($b === 'gutenberg') {
            continue;
        }
        if (isset($map[$b]) && function_exists('nibwp_has_ability') && nibwp_has_ability($map[$b])) {
            $ready[] = $b;
        } else {
            $blocked[$b] = sprintf('%s is active, but its skill is not enabled — turn it on under Skills to build natively.', $b);
        }
    }
    $ready[] = 'gutenberg'; // always last: the fallback, never the recommendation

    $enhancers = [];
    foreach (['acss-pro', 'seo-pro'] as $s) {
        if (function_exists('nibwp_skill_is_enabled') && nibwp_skill_is_enabled($s)) {
            $enhancers[] = $s;
        }
    }

    return [
        'active_builders'   => $builders,
        'ready_builders'    => $ready,     // handoff possible right now
        'blocked_builders'  => $blocked,   // installed, but the skill is off
        'enhancers_active'  => $enhancers,
        'recommended'       => $ready[0],
    ];
}

/**
 * builder key → that builder skill's own HTML→native ability. figma-pro does NOT
 * re-implement per-builder emitters; it hands the neutral HTML to whichever of
 * these is active (chosen by the plan/workflow). Gutenberg core is the only
 * built-in fallback (needs no builder skill).
 *
 * @return array<string,string>
 */
function nibwp_figma_builder_ability_map(): array
{
    return [
        'etchwp'    => 'nibwp/etchwp-pro-html-to-component',
        'elementor' => 'nibwp/elementor-pro-html-to-page',
        'bricks'    => 'nibwp/bricks-pro-html-to-component',
        'oxygen'    => 'nibwp/oxygen-html-to-page',
        'kadence'   => 'nibwp/kadence-pro-html-to-page',
    ];
}

/**
 * Resolve which builder to target. 'auto' → the first active builder by
 * preference, else Gutenberg.
 *
 * @param array<int,string> $active
 */
function nibwp_figma_resolve_builder(string $requested, array $active): string
{
    $requested = strtolower($requested === '' ? 'auto' : $requested);
    if ($requested !== 'auto') {
        return $requested;
    }
    foreach (['etchwp', 'oxygen', 'bricks', 'elementor', 'kadence'] as $b) {
        if (in_array($b, $active, true)) {
            return $b;
        }
    }
    return 'gutenberg';
}

/** Option name holding the cached NDO for one library frame. */
function nibwp_figma_ndo_option(string $entry_id): string
{
    return 'nibwp_figma_ndo_' . $entry_id;
}

/**
 * The NDO stored when this node was pulled, or null if it was never pulled.
 *
 * Kept in its own option rather than inside the library array so listing the
 * library stays cheap — a full node tree is orders of magnitude bigger than the
 * card metadata beside it.
 *
 * @return array<string,mixed>|null
 */
function nibwp_figma_cached_ndo(string $url): ?array
{
    $parsed = NIBWP_Figma_Client::parse_url($url);
    if (is_wp_error($parsed) || $parsed['node'] === '') {
        return null;
    }
    $entry_id = NIBWP_Figma_Library::id($parsed['key'], $parsed['node']);
    $ndo = get_option(nibwp_figma_ndo_option($entry_id), null);
    return is_array($ndo) && isset($ndo['root']) ? $ndo : null;
}

/**
 * Fetch a Figma node by URL and normalize it into an NDO. Shared by convert +
 * fetch so the read path lives in one place.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_figma_build_ndo(string $url)
{
    $client = nibwp_figma_get_client();
    if ($client === null) {
        return new WP_Error('figma_not_connected', 'Connect Figma first (NIBWP → Figma).');
    }
    $parsed = NIBWP_Figma_Client::parse_url($url);
    if (is_wp_error($parsed)) {
        return $parsed;
    }
    $key = $parsed['key'];
    $node_id = $parsed['node'];

    if ($node_id !== '') {
        $res = $client->get_nodes($key, [$node_id]);
        if (is_wp_error($res)) {
            return $res;
        }
        $doc = $res['nodes'][$node_id]['document'] ?? null;
    } else {
        $res = $client->get_file($key);
        if (is_wp_error($res)) {
            return $res;
        }
        $doc = nibwp_figma_first_frame($res['document'] ?? []);
    }
    if (!is_array($doc)) {
        return new WP_Error('figma_no_node', 'No frame/node found. Select a frame in Figma and copy its link (…?node-id=…).');
    }

    // Only the image fills this node actually paints.
    $img_map = nibwp_figma_sideload_node_images($client, $key, $doc);

    // Design tokens (palette + type ramp + :root css) ride inside the NDO so
    // every consumer (fetch/handoff/builders) gets them without re-extracting.
    $tokens = (new NIBWP_Figma_Tokens())->extract($doc);

    return (new NIBWP_Figma_Normalize($img_map))->document($doc, $tokens);
}

/**
 * PULL — the primary verb. Fetch a Figma frame/element, render it to a cached
 * IMAGE, extract CSS tokens, keep the neutral structure, and store it in the local
 * library. Does NOT convert. NibWP + AI decide later what to build from it.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_figma_pull(string $url, string $name = '')
{
    $parsed = NIBWP_Figma_Client::parse_url($url);
    if (is_wp_error($parsed)) {
        return $parsed;
    }
    return nibwp_figma_pull_node($parsed['key'], $parsed['node'], $name);
}

/**
 * Pull one node by file key + node id. Batch operations (pull a whole file,
 * re-sync the library) drive this one frame at a time so each request stays
 * short and progress is reportable.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_figma_pull_node(string $key, string $node_id, string $name = '')
{
    $client = nibwp_figma_get_client();
    if ($client === null) {
        return new WP_Error('figma_not_connected', 'Connect Figma first (NIBWP → Figma).');
    }

    // Resolve the node doc.
    if ($node_id !== '') {
        $res = $client->get_nodes($key, [$node_id]);
        if (is_wp_error($res)) {
            return $res;
        }
        $doc = $res['nodes'][$node_id]['document'] ?? null;
    } else {
        $file = $client->get_file($key);
        if (is_wp_error($file)) {
            return $file;
        }
        $doc = nibwp_figma_first_frame($file['document'] ?? []);
        $node_id = (string) ($doc['id'] ?? '');
    }
    if (!is_array($doc)) {
        return new WP_Error('figma_no_node', 'No frame/node found. Select a frame in Figma and copy its link.');
    }

    // Render the frame to a cached 2× image.
    $image_id = 0;
    $image_url = '';
    $render = $client->get_images($key, [$node_id], 2, 'png');
    if (!is_wp_error($render)) {
        $remote = '';
        foreach ((array) ($render['images'] ?? []) as $u) {
            if ((string) $u !== '') {
                $remote = (string) $u;
                break;
            }
        }
        if ($remote !== '') {
            $image_id = nibwp_figma_sideload_id($remote);
            $image_url = $image_id > 0 ? (string) wp_get_attachment_url($image_id) : '';
        }
    }

    // Sideload the image fills this frame paints, so the cached NDO points at
    // local attachments. Figma's fill URLs expire, and the whole point of a
    // pull is that a later convert never has to call Figma again.
    $img_map = nibwp_figma_sideload_node_images($client, $key, $doc);

    // Extract CSS tokens + neutral structure (no build).
    $tokens = (new NIBWP_Figma_Tokens())->extract($doc);
    $ndo = (new NIBWP_Figma_Normalize($img_map))->document($doc, $tokens);

    $entry_id    = NIBWP_Figma_Library::id($key, $node_id);
    $entry_name  = $name !== '' ? $name : (string) ($doc['name'] ?? 'Figma frame');
    $existing    = NIBWP_Figma_Library::get($entry_id);

    $entry = [
        'id'         => $entry_id,
        'name'       => $entry_name,
        // Stable, human handle so the frame can be called by name from NibWP/AI.
        'handle'     => (string) ($existing['handle'] ?? '') !== ''
            ? (string) $existing['handle']
            : NIBWP_Figma_Library::make_handle($entry_name, $entry_id),
        'file_key'   => $key,
        'node_id'    => $node_id,
        // Rebuilt from key+node: batch pulls never had a URL to carry, and the
        // Library's "Open in Figma" link needs one either way.
        'url'        => 'https://www.figma.com/design/' . rawurlencode($key)
            . '/?node-id=' . rawurlencode(str_replace(':', '-', $node_id)),
        'image_id'   => $image_id,
        'image_url'  => $image_url,
        'tokens'     => $tokens,
        // The builder-neutral HTML is cached at pull time so a later convert
        // never has to call Figma again — that is the whole point of pulling
        // first, and it keeps converts working when the API is throttled.
        'html'       => (new NIBWP_Figma_Html())->render($ndo),
        'summary'    => nibwp_figma_ndo_summary($ndo),
        'pulled_at'  => current_time('mysql'),
    ];
    NIBWP_Figma_Library::save($entry);
    // Full node tree kept separately so a later convert needs no API call.
    update_option(nibwp_figma_ndo_option($entry_id), $ndo, false);

    return $entry;
}

/**
 * List the top-level frames of a Figma file — one entry per screen/section the
 * designer laid out on each page. This is what "pull the whole file" enumerates.
 *
 * Uses depth=2 so Figma returns pages + their direct children only; the full
 * document of a large file is megabytes and we only need names and ids here.
 *
 * @return array{key:string,file:string,frames:array<int,array{id:string,name:string,page:string}>}|WP_Error
 */
function nibwp_figma_list_file_frames(string $url, string $file_key = '')
{
    $client = nibwp_figma_get_client();
    if ($client === null) {
        return new WP_Error('figma_not_connected', 'Connect Figma first (NIBWP → Figma).');
    }
    // Workspace walks hand us a bare key; the Pull field hands us a URL.
    if ($file_key === '') {
        $parsed = NIBWP_Figma_Client::parse_url($url);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        $file_key = $parsed['key'];
    }
    $parsed = ['key' => $file_key];
    $file = $client->get_file($parsed['key'], 2);
    if (is_wp_error($file)) {
        return $file;
    }

    $frames = [];
    foreach ((array) ($file['document']['children'] ?? []) as $page) {
        if (!is_array($page)) {
            continue;
        }
        $page_name = (string) ($page['name'] ?? '');
        foreach ((array) ($page['children'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            // Only real screens/sections — not stray shapes or loose text.
            if (!in_array($type, ['FRAME', 'SECTION', 'COMPONENT', 'COMPONENT_SET'], true)) {
                continue;
            }
            if (($node['visible'] ?? true) === false) {
                continue;
            }
            $frames[] = [
                'id'   => (string) ($node['id'] ?? ''),
                'name' => (string) ($node['name'] ?? 'Frame'),
                'page' => $page_name,
            ];
        }
    }

    return [
        'key'    => $parsed['key'],
        'file'   => (string) ($file['name'] ?? ''),
        'frames' => $frames,
    ];
}

/**
 * List every file in a Figma team or project.
 *
 * Figma exposes no "list my teams" endpoint and drafts are not enumerable at
 * all, so the widest thing that can be walked is a team the user points at:
 * team → projects → files. A project URL skips straight to its files.
 *
 * @return array{files:array<int,array{key:string,name:string,project:string}>}|WP_Error
 */
function nibwp_figma_list_workspace_files(string $url)
{
    $client = nibwp_figma_get_client();
    if ($client === null) {
        return new WP_Error('figma_not_connected', 'Connect Figma first (NIBWP → Figma).');
    }
    $ws = NIBWP_Figma_Client::parse_workspace_url($url);
    if (is_wp_error($ws)) {
        return $ws;
    }

    $projects = [];
    if ($ws['type'] === 'project') {
        $projects[] = ['id' => $ws['id'], 'name' => ''];
    } else {
        $res = $client->get_team_projects($ws['id']);
        if (is_wp_error($res)) {
            return $res;
        }
        foreach ((array) ($res['projects'] ?? []) as $p) {
            if (is_array($p) && ($p['id'] ?? '') !== '') {
                $projects[] = ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? '')];
            }
        }
    }

    $files = [];
    foreach ($projects as $project) {
        $res = $client->get_project_files($project['id']);
        if (is_wp_error($res)) {
            continue; // a project we cannot read should not abort the whole walk
        }
        foreach ((array) ($res['files'] ?? []) as $f) {
            if (!is_array($f) || ($f['key'] ?? '') === '') {
                continue;
            }
            $files[] = [
                'key'     => (string) $f['key'],
                'name'    => (string) ($f['name'] ?? ''),
                'project' => $project['name'],
            ];
        }
    }

    return ['files' => $files];
}

/**
 * AJAX: list the files of a team/project so the browser can walk them one by
 * one. Kept separate from the frame scan because a team can hold many files and
 * each still needs its own frame lookup.
 */
add_action('wp_ajax_nibwp_figma_scan_workspace', static function (): void {
    if (!current_user_can('manage_options') || !nibwp_figma_unlocked()) {
        wp_send_json_error(['message' => __('Not allowed.', 'nibwp')], 403);
    }
    check_ajax_referer('nibwp_figma_lib');

    $url  = esc_url_raw((string) wp_unslash($_POST['url'] ?? ''));
    $list = nibwp_figma_list_workspace_files($url);
    if (is_wp_error($list)) {
        wp_send_json_error(['message' => $list->get_error_message()], 400);
    }
    wp_send_json_success(['files' => $list['files']]);
});

/**
 * AJAX: work out what a batch run will pull.
 *  - mode=file    → every top-level frame in the pasted file URL
 *  - mode=library → everything already pulled, for a re-sync
 * Returns targets only; the browser then pulls them one at a time so no single
 * request can time out and progress is real.
 */
add_action('wp_ajax_nibwp_figma_scan', static function (): void {
    if (!current_user_can('manage_options') || !nibwp_figma_unlocked()) {
        wp_send_json_error(['message' => __('Not allowed.', 'nibwp')], 403);
    }
    check_ajax_referer('nibwp_figma_lib');

    $mode = sanitize_key((string) wp_unslash($_POST['mode'] ?? 'file'));

    if ($mode === 'library') {
        $targets = [];
        foreach (NIBWP_Figma_Library::all() as $entry) {
            $targets[] = [
                'key'  => (string) ($entry['file_key'] ?? ''),
                'node' => (string) ($entry['node_id'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
            ];
        }
        wp_send_json_success(['mode' => 'library', 'targets' => array_values(array_filter(
            $targets,
            static fn (array $t): bool => $t['key'] !== '' && $t['node'] !== ''
        ))]);
    }

    $url = esc_url_raw((string) wp_unslash($_POST['url'] ?? ''));
    $key = sanitize_text_field((string) wp_unslash($_POST['key'] ?? ''));
    $list = nibwp_figma_list_file_frames($url, $key);
    if (is_wp_error($list)) {
        wp_send_json_error(['message' => $list->get_error_message()], 400);
    }
    $targets = [];
    foreach ($list['frames'] as $frame) {
        if ($frame['id'] !== '') {
            $targets[] = ['key' => $list['key'], 'node' => $frame['id'], 'name' => $frame['name']];
        }
    }
    wp_send_json_success(['mode' => 'file', 'file' => $list['file'], 'targets' => $targets]);
});

/** AJAX: pull a single frame of a batch. One frame per request keeps it snappy. */
add_action('wp_ajax_nibwp_figma_pull_one', static function (): void {
    if (!current_user_can('manage_options') || !nibwp_figma_unlocked()) {
        wp_send_json_error(['message' => __('Not allowed.', 'nibwp')], 403);
    }
    check_ajax_referer('nibwp_figma_lib');

    $key  = sanitize_text_field((string) wp_unslash($_POST['key'] ?? ''));
    $node = sanitize_text_field((string) wp_unslash($_POST['node'] ?? ''));
    if ($key === '' || $node === '') {
        wp_send_json_error(['message' => __('Missing frame reference.', 'nibwp')], 400);
    }

    $out = nibwp_figma_pull_node($key, $node, sanitize_text_field((string) wp_unslash($_POST['name'] ?? '')));
    if (is_wp_error($out)) {
        wp_send_json_error(['message' => $out->get_error_message()], 400);
    }
    wp_send_json_success(['name' => $out['name'] ?? '', 'count' => count(NIBWP_Figma_Library::all())]);
});

/**
 * Read → neutral artifact (semantic HTML + summary + routing). No persist.
 * This is what an agent/workflow hands to any builder skill's html-to-* ability.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_figma_fetch(string $url)
{
    $ndo = nibwp_figma_build_ndo($url);
    if (is_wp_error($ndo)) {
        return $ndo;
    }
    $detect  = nibwp_figma_detect_builders();
    $target  = nibwp_figma_resolve_builder('auto', $detect['active_builders']);
    $map     = nibwp_figma_builder_ability_map();
    $html    = (new NIBWP_Figma_Html())->render($ndo);

    return [
        'ok'                 => true,
        'title'              => (string) ($ndo['target']['title'] ?? 'Figma Page'),
        'html'               => $html,
        'tokens_css'         => (string) ($ndo['tokens']['css'] ?? ''),
        'tokens'             => (array) ($ndo['tokens'] ?? []),
        'summary'            => nibwp_figma_ndo_summary($ndo),
        'active_builders'    => $detect['active_builders'],
        'recommended_builder' => $target,
        'recommended_ability' => $map[$target] ?? null,
        'note'               => 'Neutral HTML. Hand it to the active builder skill\'s html-to-* ability (recommended_ability) to build natively, or call nibwp/figma-pro-convert to route + persist.',
    ];
}

/**
 * Walk an NDO tree and summarize what was detected (for analyze/reporting).
 *
 * @param array<string,mixed> $ndo
 * @return array<string,mixed>
 */
function nibwp_figma_ndo_summary(array $ndo): array
{
    $counts = ['section' => 0, 'container' => 0, 'text' => 0, 'shape' => 0, 'component' => 0, 'component_instance' => 0];
    $sections = [];
    $walk = static function (array $node, callable $walk) use (&$counts, &$sections): void {
        $t = (string) ($node['type'] ?? 'container');
        if (isset($counts[$t])) {
            $counts[$t]++;
        }
        if ($t === 'section' && ($node['name'] ?? '') !== '') {
            $sections[] = (string) $node['name'];
        }
        foreach ((array) ($node['children'] ?? []) as $c) {
            if (is_array($c)) {
                $walk($c, $walk);
            }
        }
    };
    if (is_array($ndo['root'] ?? null)) {
        $walk($ndo['root'], $walk);
    }
    return [
        'title'        => (string) ($ndo['target']['title'] ?? ''),
        'counts'       => $counts,
        'top_sections' => array_slice($sections, 0, 20),
        'warnings'     => array_values((array) ($ndo['warnings'] ?? [])),
    ];
}

/**
 * The shared convert pipeline: URL → Figma read → NDO → builder → (draft).
 * Used by both the admin converter and the figma-pro abilities.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_figma_do_convert(string $url, string $title = '', string $builder = 'auto', bool $dry_run = false, bool $allow_handoff = false)
{
    // Prefer what was already pulled. Re-reading Figma for a frame that is
    // already in the library wastes the API budget and stops working the moment
    // the token is throttled — pulling first exists precisely to avoid that.
    $ndo = nibwp_figma_cached_ndo($url);
    if ($ndo === null) {
        $ndo = nibwp_figma_build_ndo($url);
        if (is_wp_error($ndo)) {
            return $ndo;
        }
    }
    if ($title === '') {
        $title = (string) ($ndo['target']['title'] ?? 'Figma Page');
    }

    $summary = nibwp_figma_ndo_summary($ndo);
    $detect  = nibwp_figma_detect_builders();
    $target  = nibwp_figma_resolve_builder($builder, $detect['active_builders']);
    $map     = nibwp_figma_builder_ability_map();
    $ability = $map[$target] ?? '';
    $ability_ready = $ability !== '' && function_exists('nibwp_has_ability') && nibwp_has_ability($ability);
    $do_handoff = $allow_handoff && $target !== 'gutenberg' && $ability_ready;

    if ($dry_run) {
        return [
            'ok'             => true,
            'dry_run'        => true,
            'title'          => $title,
            'target_builder' => $target,
            'route'          => $do_handoff ? 'handoff' : 'gutenberg',
            'next_ability'   => $do_handoff ? $ability : null,
            'active_builders' => $detect['active_builders'],
            'summary'        => $summary,
        ];
    }

    // Real builder present + routing allowed → hand the neutral HTML off to that
    // builder skill's own ability (the agent runs its preflight + build). figma-pro
    // does not fake-execute another skill's gated pipeline.
    if ($do_handoff) {
        return [
            'ok'             => true,
            'mode'           => 'handoff',
            'target_builder' => $target,
            'next_ability'   => $ability,
            'title'          => $title,
            'html'           => (new NIBWP_Figma_Html())->render($ndo),
            'tokens_css'     => (string) ($ndo['tokens']['css'] ?? ''),
            'summary'        => $summary,
            // Builder abilities are validators + writers, not converters: they
            // take a native payload the agent synthesizes, never raw HTML.
            'note'           => sprintf(
                'Do NOT pass this html to %1$s directly — it validates and persists a native payload, it does not convert. Routine: 1) nibwp/load-skill-playbook { skill_id:"%2$s" } to read the rules, 2) synthesize that builder\'s native payload from the html + tokens_css below, 3) submit to %1$s with dry_run:true and fix every failure, 4) re-submit with dry_run:false to commit. Or call nibwp/figma-pro-convert with builder="gutenberg" to skip synthesis and persist core blocks directly.',
                $ability,
                $target . '-pro'
            ),
            'handoff_contract' => [
                'ability'      => $ability,
                'accepts'      => 'native builder payload synthesized by the agent — not html',
                'playbook'     => 'nibwp/load-skill-playbook { skill_id: "' . $target . '-pro" }',
                'source_html'  => 'use the html field as the structure to convert FROM',
            ],
        ];
    }

    // Gutenberg direct — the fallback and the admin-page path (no agent to continue).
    $markup = (new NIBWP_Figma_Gutenberg())->render($ndo);
    if (trim($markup) === '') {
        return new WP_Error('figma_empty', 'Conversion produced no content (node may be empty or hidden).');
    }
    if ($target !== 'gutenberg' && !$ability_ready) {
        $summary['warnings'][] = sprintf('No installed builder skill for "%s"; built as Gutenberg core blocks.', $target);
    }

    $post_id = wp_insert_post([
        'post_title'   => $title,
        'post_content' => $markup,
        'post_status'  => 'draft',
        'post_type'    => 'page',
    ], true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    return [
        'ok'          => true,
        'post_id'     => (int) $post_id,
        'title'       => $title,
        'builder'     => 'gutenberg',
        'edit_url'    => (string) get_edit_post_link((int) $post_id, 'url'),
        'preview_url' => (string) get_permalink((int) $post_id),
        'summary'     => $summary,
    ];
}

/**
 * Read-only analyze: report the detected structure + intended route, no persist.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_figma_analyze(string $url)
{
    return nibwp_figma_do_convert($url, '', 'auto', true, true);
}

/* ─────────────────────────── util ─────────────────────────── */

/**
 * Depth-first: find the first FRAME/SECTION under a document node.
 *
 * @param array<string,mixed> $node
 * @return array<string,mixed>|null
 */
function nibwp_figma_first_frame(array $node): ?array
{
    $type = (string) ($node['type'] ?? '');
    if ($type === 'FRAME' || $type === 'SECTION') {
        return $node;
    }
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $found = nibwp_figma_first_frame($child);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Collect the imageRefs actually used inside a node subtree.
 *
 * /v1/files/{key}/images returns every image in the WHOLE file — 56 of them for
 * a modest portfolio file. Sideloading all of those to render one frame is what
 * turned a 5-second read into a two-minute timeout, so only the refs this node
 * really paints get downloaded.
 *
 * @param array<string,mixed> $node
 * @return array<string,bool> imageRef => true
 */
function nibwp_figma_collect_image_refs(array $node): array
{
    $refs = [];
    $walk = static function (array $n, callable $walk) use (&$refs): void {
        foreach ((array) ($n['fills'] ?? []) as $fill) {
            if (is_array($fill) && ($fill['imageRef'] ?? '') !== '') {
                $refs[(string) $fill['imageRef']] = true;
            }
        }
        foreach ((array) ($n['background'] ?? []) as $fill) {
            if (is_array($fill) && ($fill['imageRef'] ?? '') !== '') {
                $refs[(string) $fill['imageRef']] = true;
            }
        }
        foreach ((array) ($n['children'] ?? []) as $child) {
            if (is_array($child)) {
                $walk($child, $walk);
            }
        }
    };
    $walk($node, $walk);
    return $refs;
}

/**
 * Sideload only the image fills a node actually uses.
 *
 * @param array<string,mixed> $doc
 * @return array<string,string> imageRef => local URL
 */
function nibwp_figma_sideload_node_images(NIBWP_Figma_Client $client, string $key, array $doc): array
{
    $needed = nibwp_figma_collect_image_refs($doc);
    if ($needed === []) {
        return [];
    }
    $fills = $client->get_image_fills($key);
    if (is_wp_error($fills) || !is_array($fills)) {
        return [];
    }
    $map = [];
    foreach ($fills as $ref => $remote) {
        if (!isset($needed[(string) $ref])) {
            continue;
        }
        $local = nibwp_figma_sideload((string) $remote);
        if ($local !== '') {
            $map[(string) $ref] = $local;
        }
    }
    return $map;
}

/**
 * Sideload a remote image into the media library, deduped by source hash.
 * Returns the attachment id, or 0 on failure.
 */
function nibwp_figma_sideload_id(string $url): int
{
    if ($url === '') {
        return 0;
    }
    $hash = sha1($url);

    // Reuse an existing sideload of the same source.
    $existing = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => '_nibwp_figma_source_key',
        'meta_value'  => $hash,
    ]);
    if (!empty($existing)) {
        return (int) $existing[0];
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url, 30);
    if (is_wp_error($tmp)) {
        return 0;
    }
    $file = ['name' => 'figma-' . substr($hash, 0, 10) . '.png', 'tmp_name' => $tmp];
    $id = media_handle_sideload($file, 0);
    if (is_wp_error($id)) {
        if (file_exists($tmp)) {
            wp_delete_file($tmp);
        }
        return 0;
    }
    update_post_meta((int) $id, '_nibwp_figma_source_key', $hash);
    return (int) $id;
}

/**
 * Sideload a remote image; returns the local URL, or '' on failure.
 */
function nibwp_figma_sideload(string $url): string
{
    $id = nibwp_figma_sideload_id($url);
    return $id > 0 ? (string) wp_get_attachment_url($id) : '';
}
