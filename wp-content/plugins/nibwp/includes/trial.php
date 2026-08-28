<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — 7-day Pro trial.
 *
 * On first plugin activation (or first time the user lands on a NIBWP admin
 * page without any stored license), auto-grant `pro` + `skill:*` entitlements
 * for 7 days so the user can taste every Pro feature without paying. After
 * the window expires, the trial silently retracts and the user falls back
 * to Free + the upsell.
 *
 * Persists a single option `nibwp_trial_started_at` (unix timestamp). Absence
 * of the option = trial not started. A value > 7 days old = trial expired.
 * If the user later activates a real license, the trial is treated as
 * "redeemed" and never starts a second time.
 */

const NIBWP_TRIAL_DURATION_SECONDS = 7 * DAY_IN_SECONDS;
const NIBWP_TRIAL_OPTION = 'nibwp_trial_started_at';

/**
 * Returns the trial state:
 *   - exists:     bool — trial has ever been started
 *   - active:     bool — currently inside the 7-day window
 *   - started_at: int|null — unix seconds when the trial began
 *   - ends_at:    int|null — unix seconds when the trial expires
 *   - seconds_left: int — clamps at 0
 *   - days_left:    int — ceil(seconds_left / 86400)
 *
 * @return array{exists:bool,active:bool,started_at:?int,ends_at:?int,seconds_left:int,days_left:int}
 */
function nibwp_trial_status(): array
{
    $started = (int) get_option(NIBWP_TRIAL_OPTION, 0);
    if ($started <= 0) {
        return [
            'exists'       => false,
            'active'       => false,
            'started_at'   => null,
            'ends_at'      => null,
            'seconds_left' => 0,
            'days_left'    => 0,
        ];
    }
    $ends_at = $started + NIBWP_TRIAL_DURATION_SECONDS;
    $seconds_left = max(0, $ends_at - time());
    return [
        'exists'       => true,
        'active'       => $seconds_left > 0,
        'started_at'   => $started,
        'ends_at'      => $ends_at,
        'seconds_left' => $seconds_left,
        'days_left'    => (int) ceil($seconds_left / DAY_IN_SECONDS),
    ];
}

/**
 * Start the trial if it hasn't been started yet AND the user does not already
 * own a license. Idempotent — calling repeatedly after start is a no-op.
 */
function nibwp_trial_maybe_start(): void
{
    if (get_option(NIBWP_TRIAL_OPTION, 0) > 0) {
        return; // Already started (or expired) once — no second taste.
    }
    // If the user already has stored licenses, skip the trial entirely.
    if (function_exists('nibwp_licenses_get')) {
        $licenses = (array) nibwp_licenses_get();
        if ($licenses !== []) {
            // Mark the trial as redeemed (use 1 as "started long ago, no trial").
            update_option(NIBWP_TRIAL_OPTION, 1, autoload: false);
            return;
        }
    }
    update_option(NIBWP_TRIAL_OPTION, time(), autoload: false);
}

/**
 * Inject `pro` + `skill:*` into the entitlements vector while the trial is
 * active. Hooks `nibwp_entitlements` filter (registered in includes/license.php
 * as `nibwp_entitlement_label` neighbours — see `nibwp_entitlements()` core).
 *
 * License entitlements still take precedence — the filter merges with the
 * stored entitlement list, dedupes, and returns. If a real license already
 * grants `pro`, the trial entry is a no-op duplicate.
 */
add_filter('nibwp_entitlements', static function (array $entitlements): array {
    $trial = nibwp_trial_status();
    if (!$trial['active']) {
        return $entitlements;
    }
    if (!in_array('pro',     $entitlements, true)) { $entitlements[] = 'pro'; }
    if (!in_array('skill:*', $entitlements, true)) { $entitlements[] = 'skill:*'; }
    return array_values(array_unique($entitlements));
});

/**
 * Auto-start the trial when the user first opens any NIBWP admin page.
 * We use admin_init rather than register_activation_hook so the trial
 * starts on first visit, not on plugin install — gives the user a moment
 * to actually see the UI before the countdown begins.
 */
add_action('admin_init', static function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
    if (!str_starts_with($page, 'nibwp')) {
        return;
    }
    nibwp_trial_maybe_start();
});

/**
 * Banner on NIBWP admin pages — countdown while active, upgrade nudge in the
 * final 48 hours, soft retraction notice after expiry.
 */
add_action('admin_notices', static function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
    if (!str_starts_with($page, 'nibwp')) {
        return;
    }
    $trial = nibwp_trial_status();
    if (!$trial['exists']) {
        return;
    }
    $pricing = function_exists('nibwp_pricing_url') ? nibwp_pricing_url() : 'https://nibwp.com/pricing';

    if ($trial['active']) {
        $is_final = $trial['seconds_left'] <= 2 * DAY_IN_SECONDS;
        $class = $is_final ? 'notice notice-warning nibwp-pro-notice' : 'notice notice-info nibwp-pro-notice';
        $msg = $is_final
            ? sprintf(esc_html__('Your NIBWP Pro trial ends in %d day(s). Activate a license to keep premium integrations.', 'nibwp'), $trial['days_left'])
            : sprintf(esc_html__('Pro trial active — %d day(s) left. Every premium integration + every skill unlocked.', 'nibwp'), $trial['days_left']);
        echo '<div class="' . esc_attr($class) . '"><p>'
            . $msg
            . ' <a href="' . esc_url(nibwp_item_url('bundle')) . '" target="_blank" rel="noopener">'
            . esc_html__('See plans →', 'nibwp')
            . '</a></p></div>';
        return;
    }

    // Expired — show once-per-session retraction notice.
    if (!get_transient('nibwp_trial_retraction_seen_' . get_current_user_id())) {
        set_transient('nibwp_trial_retraction_seen_' . get_current_user_id(), 1, DAY_IN_SECONDS);
        echo '<div class="notice notice-warning nibwp-pro-notice"><p>'
            . esc_html__('Your NIBWP Pro trial ended. Premium integrations are locked again — activate a license to restore access.', 'nibwp')
            . ' <a href="' . esc_url($pricing) . '" target="_blank" rel="noopener">'
            . esc_html__('View pricing →', 'nibwp')
            . '</a></p></div>';
    }
});
