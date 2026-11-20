<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Free build's one upgrade notice: NibWP Pro, and the lifetime deal.
 *
 * Shown to people who can act on it (manage_options), on the screens where it
 * belongs — the Dashboard, the Plugins screen and NibWP's own — and never again
 * for that person once dismissed. That is what WordPress.org asks of an upgrade
 * notice, and a notice that returns after "No thanks" costs more goodwill than
 * it earns.
 *
 * The Pro build carries the premium code and never advertises itself; neither
 * does a site with an active licence.
 */

const NIBWP_FREE_NOTICE_META = 'nibwp_free_pro_notice_dismissed';
const NIBWP_FREE_NOTICE_PRO_URL = 'https://nibwp.com/item/pro';
const NIBWP_FREE_NOTICE_LTD_URL = 'https://nibwp.com/early-bird/';

/**
 * @param string|null $screen_id   The current screen id; read from WordPress when null.
 * @param bool|null   $has_premium Whether the premium code is present; detected when null.
 */
function nibwp_free_notice_should_show(?string $screen_id = null, ?bool $has_premium = null): bool
{
    $has_premium = $has_premium ?? defined('NIBWP_PREMIUM_AVAILABLE');
    if ($has_premium || nibwp_is_pro()) {
        return false;
    }

    if (!current_user_can('manage_options')) {
        return false;
    }

    if ((string) get_user_meta(get_current_user_id(), NIBWP_FREE_NOTICE_META, true) === '1') {
        return false;
    }

    if ($screen_id === null) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';
    }

    return in_array($screen_id, ['dashboard', 'plugins'], true) || str_contains($screen_id, 'nibwp');
}

add_action('admin_notices', static function (): void {
    if (nibwp_free_notice_should_show()) {
        nibwp_free_notice_render();
    }
});

function nibwp_free_notice_render(): void
{
    $dismiss_url = wp_nonce_url(add_query_arg('nibwp_dismiss_free_notice', '1', admin_url('index.php')), 'nibwp_dismiss_free_notice');
    $icon = plugins_url('assets/nibwp-icon.svg', dirname(__DIR__) . '/nibwp.php');
    ?>
    <div class="notice nibwp-free-notice is-dismissible" data-nonce="<?php echo esc_attr(wp_create_nonce('nibwp_dismiss_free_notice')); ?>">
        <div class="nibwp-free-notice__icon"><img src="<?php echo esc_url($icon); ?>" alt="" width="34" height="44"></div>
        <div class="nibwp-free-notice__body">
            <p class="nibwp-free-notice__title"><?php esc_html_e('Let your AI build the whole site with NibWP Pro', 'nibwp'); ?></p>
            <p class="nibwp-free-notice__text"><?php esc_html_e('100+ premium integrations — Elementor, Bricks, Etch, WooCommerce, ACF and more — plus 15 Pro skills that turn a design, a screenshot or a URL into real, editable pages.', 'nibwp'); ?></p>
            <p class="nibwp-free-notice__ltd"><span class="nibwp-free-notice__badge"><?php esc_html_e('Lifetime deal', 'nibwp'); ?></span> <?php esc_html_e('Pay once, no renewals — every integration and skill, including the ones still to come.', 'nibwp'); ?></p>
        </div>
        <div class="nibwp-free-notice__actions">
            <a class="button button-primary" href="<?php echo esc_url(NIBWP_FREE_NOTICE_PRO_URL); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get NibWP Pro', 'nibwp'); ?></a>
            <a class="button" href="<?php echo esc_url(NIBWP_FREE_NOTICE_LTD_URL); ?>" target="_blank" rel="noopener"><?php esc_html_e('See the lifetime deal', 'nibwp'); ?></a>
            <a class="nibwp-free-notice__dismiss" href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('No thanks', 'nibwp'); ?></a>
        </div>
    </div>
    <style>
        .nibwp-free-notice { display: flex; align-items: center; gap: 16px; padding: 14px 40px 14px 16px; border-inline-start-color: #4f46e5; }
        .nibwp-free-notice__icon { flex: 0 0 auto; display: flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 12px; background: #eef2ff; }
        .nibwp-free-notice__icon img { width: 26px; height: auto; display: block; }
        .nibwp-free-notice__body { flex: 1 1 auto; min-width: 0; }
        .nibwp-free-notice .nibwp-free-notice__body p { margin: 0; padding: 0; }
        .nibwp-free-notice__title { font-size: 14px; font-weight: 600; color: #1d2327; }
        .nibwp-free-notice .nibwp-free-notice__text { margin-top: 4px; color: #50575e; }
        .nibwp-free-notice .nibwp-free-notice__ltd { margin-top: 6px; color: #1d2327; }
        .nibwp-free-notice__badge { display: inline-block; margin-inline-end: 4px; padding: 1px 8px; border-radius: 99px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; vertical-align: 1px; }
        .nibwp-free-notice__actions { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .nibwp-free-notice .nibwp-free-notice__dismiss { color: #646970; text-decoration: none; font-size: 13px; padding: 0 4px; }
        .nibwp-free-notice .nibwp-free-notice__dismiss:hover, .nibwp-free-notice .nibwp-free-notice__dismiss:focus { color: #1d2327; text-decoration: underline; }
        @media (max-width: 960px) {
            .nibwp-free-notice { flex-wrap: wrap; align-items: flex-start; }
            .nibwp-free-notice__body { flex-basis: calc(100% - 68px); }
            .nibwp-free-notice__actions { flex-basis: 100%; justify-content: flex-start; padding-inline-start: 68px; }
        }
        @media (max-width: 600px) {
            .nibwp-free-notice__icon { display: none; }
            .nibwp-free-notice__body { flex-basis: 100%; }
            .nibwp-free-notice__actions { padding-inline-start: 0; }
        }
    </style>
    <script>
        (function () {
            function dismiss(notice) {
                var data = new FormData();
                data.append('action', 'nibwp_dismiss_free_notice');
                data.append('_ajax_nonce', notice.getAttribute('data-nonce'));
                if (window.fetch) {
                    fetch(window.ajaxurl || <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: data });
                }
                notice.parentNode && notice.parentNode.removeChild(notice);
            }
            document.addEventListener('click', function (e) {
                var target = e.target;
                var notice = target && target.closest ? target.closest('.nibwp-free-notice') : null;
                if (!notice) { return; }
                if (target.closest('.notice-dismiss') || target.closest('.nibwp-free-notice__dismiss')) {
                    e.preventDefault();
                    dismiss(notice);
                }
            });
        })();
    </script>
    <?php
}

add_action('wp_ajax_nibwp_dismiss_free_notice', 'nibwp_free_notice_dismiss_ajax');

function nibwp_free_notice_dismiss_ajax(): void
{
    check_ajax_referer('nibwp_dismiss_free_notice');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);

        return;
    }

    update_user_meta(get_current_user_id(), NIBWP_FREE_NOTICE_META, '1');
    wp_send_json_success();
}

// Without JavaScript the "No thanks" link lands here.
add_action('admin_init', static function (): void {
    if (!isset($_GET['nibwp_dismiss_free_notice'])) {
        return;
    }

    check_admin_referer('nibwp_dismiss_free_notice');

    if (current_user_can('manage_options')) {
        update_user_meta(get_current_user_id(), NIBWP_FREE_NOTICE_META, '1');
    }

    wp_safe_redirect(remove_query_arg(['nibwp_dismiss_free_notice', '_wpnonce']));
    exit;
});
