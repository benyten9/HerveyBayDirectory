<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Jobs\OldDataMigrationQueue;
use DirectoristPricingPlan\App\Repositories\OldDataMigrationRepository;

class MigrationNoticeServiceProvider implements Provider {
    public function boot() {
        add_action( 'admin_notices', [ $this, 'show_migration_notice' ] );
        add_action( 'admin_init', [ $this, 'handle_migration_dismiss' ] );
        add_filter( 'directorist_should_show_plan_assignment_notice', [ $this, 'maybe_suppress_plan_assignment_notice' ] );
    }

    /**
     * Check if old atbdp_pricing_plans posts exist.
     *
     * @return bool
     */
    private function has_old_pricing_plans(): bool {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'atbdp_pricing_plans' AND post_status != 'trash'"
        );

        return $count > 0;
    }

    /**
     * Show admin notices related to migration.
     */
    public function show_migration_notice() {
        $screen = get_current_screen();

        if ( ! $screen || ! in_array( $screen->id, $this->get_relevant_screen_ids(), true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        /**
         * @var OldDataMigrationQueue $migration_queue
         */
        $migration_queue = directorist_pricing_plans_singleton( OldDataMigrationQueue::class );
        $repository      = $this->migration_repository();
        
        // If migration is completed, show status notice
        if ( $migration_queue->is_finished() ) {
            if ( $repository->is_migration_notice_dismissed() && ! $migration_queue->is_migration_needed() && 0 === $migration_queue->get_failed_count() ) {
                return;
            }

            $this->render_migration_completed_notice( $migration_queue );
            return;
        }

        // If migration is running, show progress notice
        if ( $migration_queue->is_active() ) {
            if ( ! $migration_queue->is_processing() ) {
                $migration_queue->dispatch_or_process();
            }

            $this->render_migration_in_progress_notice( $migration_queue );
            return;
        }

        // If migration is needed (un-migrated data exists), show migration needed notice
        if ( $migration_queue->is_migration_needed() ) {
            $this->render_migration_needed_notice( $migration_queue );
        }
    }

    /**
     * Handle migration notice dismiss / retry actions.
     */
    public function handle_migration_dismiss() {
        // Handle dismiss of completed migration notice
        if ( isset( $_GET['directorist_dismiss_migration_notice'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'directorist_dismiss_migration_notice' ) ) {
            if ( current_user_can( 'manage_options' ) ) {
                $this->migration_repository()->dismiss_migration_notice();

                wp_safe_redirect( remove_query_arg( [ 'directorist_dismiss_migration_notice', '_wpnonce' ] ) );
                exit;
            }
        }
    }

    private function migration_repository(): OldDataMigrationRepository {
        return directorist_pricing_plans_singleton( OldDataMigrationRepository::class );
    }

    /**
     * Suppress the plan assignment notice if migration is needed.
     *
     * @param bool $should_show
     * @return bool
     */
    public function maybe_suppress_plan_assignment_notice( bool $should_show ): bool {
        if ( ! $should_show ) {
            return false;
        }

        if ( $this->has_old_pricing_plans() ) {
            return false;
        }

        return true;
    }

    /**
     * Get relevant screen IDs where notices should be shown.
     *
     * @return array
     */
    private function get_relevant_screen_ids(): array {
        return [
            'edit-at_biz_dir',
            'at_biz_dir_page_atbdp-settings',
            'at_biz_dir_page_atbdp-directory-types',
            'at_biz_dir_page_directorist-status',
            'dashboard',
            'plugins',
        ];
    }

    /**
     * Determine the status of a single migration step based on progress and queue state.
     * Returns one of: 'waiting', 'in_progress', 'done', 'failed'.
     *
     * @param string $step        One of 'plans', 'orders', 'packages'.
     * @param array  $progress    Output of OldDataMigrationQueue::get_progress().
     * @param bool   $is_active   Whether the background queue is active.
     * @param bool   $is_finished Whether the migration queue has fully finished.
     * @return string
     */
    private function get_step_status( string $step, array $progress, bool $is_active, bool $is_finished = false ): string {
        $error_option = 'directorist_plans_migration_step_error_' . $step;

        if ( get_option( $error_option ) ) {
            return 'failed';
        }

        switch ( $step ) {
            case 'plans':
                if ( $progress['plans_total'] > 0 && $progress['plans_migrated'] >= $progress['plans_total'] ) {
                    return 'done';
                }
                if ( $is_finished && $progress['plans_total'] > 0 && $progress['plans_migrated'] < $progress['plans_total'] ) {
                    return 'failed';
                }
                // in_progress only while plans haven't finished; orders/users are still total=0
                // so they won't be shown — only one step is in_progress at a time.
                return $is_active ? 'in_progress' : 'waiting';

            case 'orders':
                if ( $progress['orders_total'] > 0 && $progress['orders_migrated'] >= $progress['orders_total'] ) {
                    return 'done';
                }
                if ( $is_finished && $progress['orders_total'] > 0 && $progress['orders_migrated'] < $progress['orders_total'] ) {
                    return 'failed';
                }
                // orders_total > 0 means the orders step has actually started.
                if ( $progress['orders_total'] > 0 && $is_active ) {
                    return 'in_progress';
                }
                return 'waiting';

            case 'packages':
                if ( $progress['users_total'] > 0 && $progress['users_migrated'] >= $progress['users_total'] ) {
                    return 'done';
                }
                if ( $is_finished && $progress['users_total'] > 0 && $progress['users_migrated'] < $progress['users_total'] ) {
                    return 'failed';
                }
                // users_total > 0 means the packages step has actually started.
                if ( $progress['users_total'] > 0 && $is_active ) {
                    return 'in_progress';
                }
                return 'waiting';
        }

        return 'waiting';
    }

    /**
     * Determine whether the packages step should be visible.
     *
     * User packages depend on migrated orders, so keep the row hidden until
     * the orders step is complete. Once order migration is done, reveal the row
     * only when there is package work or a recorded package failure.
     *
     * @param array  $progress
     * @param string $status
     * @return bool
     */
    private function should_render_packages_step( array $progress, string $status ): bool {
        if ( 'failed' === $status ) {
            return true;
        }

        $orders_done = $progress['orders_total'] > 0 && $progress['orders_migrated'] >= $progress['orders_total'];

        return $orders_done && ( $progress['users_total'] > 0 || $progress['users_migrated'] > 0 );
    }

    /**
     * Render a single step row inside the migration card.
     *
     * @param string $label
     * @param string $status    One of: 'waiting', 'in_progress', 'done', 'failed'.
     * @param int    $migrated
     * @param int    $total
     * @param string $step_key
     */
    private function render_step_row( string $label, string $status, int $migrated, int $total, string $step_key ) {
        $configs = [
            'waiting'     => [
                'bg'          => '#f6f7f7',
                'border'      => '#dcdcde',
                'label_color' => '#646970',
                'badge_bg'    => '#f0f0f1',
                'badge_color' => '#646970',
                'badge_text'  => __( 'Waiting', 'directorist-pricing-plans' ),
                'icon'        => '<svg viewBox="0 0 20 20" width="18" height="18" style="flex-shrink:0;"><circle cx="10" cy="10" r="9" fill="none" stroke="#8c8f94" stroke-width="2"/><circle cx="10" cy="10" r="2" fill="#8c8f94"/><line x1="10" y1="5" x2="10" y2="9" stroke="#8c8f94" stroke-width="1.5" stroke-linecap="round"/></svg>',
            ],
            'in_progress' => [
                'bg'          => '#f0f6fc',
                'border'      => '#bad2e8',
                'label_color' => '#1d2327',
                'badge_bg'    => '#dbeafe',
                'badge_color' => '#1d4ed8',
                'badge_text'  => __( 'In Progress', 'directorist-pricing-plans' ),
                'icon'        => '<svg viewBox="0 0 20 20" width="18" height="18" style="flex-shrink:0;animation:directorist-spin 1.2s linear infinite;"><circle cx="10" cy="10" r="8" fill="none" stroke="#dbeafe" stroke-width="2.5"/><path d="M10 2 A8 8 0 0 1 18 10" fill="none" stroke="#2271b1" stroke-width="2.5" stroke-linecap="round"/></svg>',
            ],
            'done'        => [
                'bg'          => '#edfaef',
                'border'      => '#b7e5bb',
                'label_color' => '#1d2327',
                'badge_bg'    => '#d1fae5',
                'badge_color' => '#065f46',
                'badge_text'  => __( 'Done', 'directorist-pricing-plans' ),
                'icon'        => '<svg viewBox="0 0 20 20" width="18" height="18" style="flex-shrink:0;"><circle cx="10" cy="10" r="9" fill="#00a32a"/><path d="M5.5 10 L8.5 13.5 L14.5 6.5" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            ],
            'failed'      => [
                'bg'          => '#fcf0f1',
                'border'      => '#f5c2c3',
                'label_color' => '#1d2327',
                'badge_bg'    => '#fee2e2',
                'badge_color' => '#991b1b',
                'badge_text'  => __( 'Failed', 'directorist-pricing-plans' ),
                'icon'        => '<svg viewBox="0 0 20 20" width="18" height="18" style="flex-shrink:0;"><circle cx="10" cy="10" r="9" fill="#d63638"/><path d="M6.5 6.5 L13.5 13.5 M13.5 6.5 L6.5 13.5" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
            ],
        ];

        $cfg        = $configs[ $status ] ?? $configs['waiting'];
        $count_text = ( $total > 0 ) ? esc_html( $migrated . ' / ' . $total ) : '&mdash;';
        ?>
        <div class="directorist-migration-step-row" data-directorist-migration-step="<?php echo esc_attr( $step_key ); ?>" style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:<?php echo esc_attr( $cfg['bg'] ); ?>;border-radius:6px;border:1px solid <?php echo esc_attr( $cfg['border'] ); ?>;">
            <?php echo $cfg['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded ?>
            <span style="font-weight:600;font-size:13px;color:<?php echo esc_attr( $cfg['label_color'] ); ?>;flex:1;"><?php echo esc_html( $label ); ?></span>
            <span class="directorist-migration-step-count" style="font-size:13px;color:#50575e;min-width:60px;text-align:right;"><?php echo $count_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe ?></span>
            <span class="directorist-migration-step-badge" style="font-size:11px;font-weight:600;color:<?php echo esc_attr( $cfg['badge_color'] ); ?>;background:<?php echo esc_attr( $cfg['badge_bg'] ); ?>;padding:2px 8px;border-radius:4px;white-space:nowrap;"><?php echo esc_html( $cfg['badge_text'] ); ?></span>
        </div>
        <?php
    }

    /**
     * Render the steps block (all three rows) inside a notice.
     *
     * @param array $progress
     * @param bool  $is_active
     * @param bool  $is_finished Whether the migration queue has fully finished.
     */
    private function render_steps_block( array $progress, bool $is_active, bool $is_finished = false ) {
        $steps = [
            'plans'    => [
                'label'    => __( 'Plans', 'directorist-pricing-plans' ),
                'migrated' => $progress['plans_migrated'],
                'total'    => $progress['plans_total'],
            ],
            'orders'   => [
                'label'    => __( 'Orders', 'directorist-pricing-plans' ),
                'migrated' => $progress['orders_migrated'],
                'total'    => $progress['orders_total'],
            ],
            'packages' => [
                'label'    => __( 'User Packages', 'directorist-pricing-plans' ),
                'migrated' => $progress['users_migrated'],
                'total'    => $progress['users_total'],
            ],
        ];
        ?>
        <style>@keyframes directorist-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ( $steps as $step_key => $step ) :
                $status = $this->get_step_status( $step_key, $progress, $is_active, $is_finished );
                if ( 'packages' === $step_key && ! $this->should_render_packages_step( $progress, $status ) ) {
                    continue;
                }
                // Skip empty steps outside the active notice, unless they have a recorded failure.
                if ( ! $is_active && $step['total'] === 0 && $status !== 'failed' ) {
                    continue;
                }
                $this->render_step_row( $step['label'], $status, $step['migrated'], $step['total'], $step_key );
            endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render the migration needed notice (all steps pending).
     *
     * @param OldDataMigrationQueue $migration_queue
     */
    private function render_migration_needed_notice( OldDataMigrationQueue $migration_queue ) {
        $progress = $migration_queue->get_progress();
        $api_url  = rest_url( 'directorist-pricing-plans/admin/migration/start' );
        ?>
        <div class="notice notice-warning directorist-migration-notice" id="directorist-migration-needed-notice" style="padding:0;overflow:hidden;">
            <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;background:#fff8e5;border-bottom:1px solid #f5c543;">
                <div>
                    <strong style="font-size:13px;color:#1d2327;"><?php esc_html_e( 'Directorist Pricing Plan:', 'directorist-pricing-plans' ); ?></strong>
                    <span style="font-size:13px;color:#50575e;margin-left:6px;"><?php esc_html_e( 'Old pricing plan data needs to be migrated to the new format.', 'directorist-pricing-plans' ); ?></span>
                </div>
                <button type="button"
                        class="button button-primary directorist-migrate-now-btn"
                        data-api-url="<?php echo esc_url( $api_url ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
                        style="flex-shrink:0;margin-left:16px;">
                    <?php esc_html_e( 'Migrate Now', 'directorist-pricing-plans' ); ?>
                </button>
            </div>
            <div style="padding:16px 20px;">
                <?php $this->render_steps_block( $progress, false ); ?>
            </div>
        </div>
        <script type="text/javascript">
        (function() {
            var btn = document.querySelector('.directorist-migrate-now-btn');
            if ( ! btn ) { return; }
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Starting...', 'directorist-pricing-plans' ) ); ?>';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', btn.getAttribute('data-api-url'), true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', btn.getAttribute('data-nonce'));
                xhr.onload = function() {
                    if ( xhr.status >= 200 && xhr.status < 300 ) {
                        location.reload();
                    } else {
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Migrate Now', 'directorist-pricing-plans' ) ); ?>';
                        alert('<?php echo esc_js( __( 'Failed to start migration. Please try again.', 'directorist-pricing-plans' ) ); ?>');
                    }
                };
                xhr.onerror = function() {
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js( __( 'Migrate Now', 'directorist-pricing-plans' ) ); ?>';
                    alert('<?php echo esc_js( __( 'Failed to start migration. Please try again.', 'directorist-pricing-plans' ) ); ?>');
                };
                xhr.send();
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the migration in-progress notice with per-step status rows.
     *
     * @param OldDataMigrationQueue $migration_queue
     */
    private function render_migration_in_progress_notice( OldDataMigrationQueue $migration_queue ) {
        $progress = $migration_queue->get_progress();
        
        ?>
        <div class="notice notice-info directorist-migration-notice" id="directorist-migration-in-progress-notice" data-status-url="<?php echo esc_url( rest_url( 'directorist-pricing-plans/admin/migration/status' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" style="padding:0;overflow:hidden;border-left-color:#2271b1;">
            <div style="padding:14px 20px;background:#f0f6fc;border-bottom:1px solid #bad2e8;">
                <strong style="font-size:13px;color:#1d2327;"><?php esc_html_e( 'Directorist Pricing Plan:', 'directorist-pricing-plans' ); ?></strong>
                <span style="font-size:13px;color:#50575e;margin-left:6px;"><?php esc_html_e( 'Migration is in progress. Please do not close this page.', 'directorist-pricing-plans' ); ?></span>
            </div>
            <div style="padding:16px 20px;">
                <?php $this->render_steps_block( $progress, true ); ?>
            </div>
        </div>
        <script type="text/javascript">
        (function() {
            var notice = document.getElementById('directorist-migration-in-progress-notice');
            if ( ! notice ) { return; }

            var statusUrl = notice.getAttribute('data-status-url');
            var nonce = notice.getAttribute('data-nonce');
            var isProcessing = false;
            var refreshDelay = 4000;

            function getPayload(responseText) {
                try {
                    var parsed = JSON.parse(responseText);
                    return parsed.data || parsed;
                } catch (e) {
                    return null;
                }
            }

            function countText(migrated, total) {
                return total > 0 ? migrated + ' / ' + total : '--';
            }

            function isOrdersDone(progress) {
                var ordersMigrated = parseInt(progress.orders_migrated, 10) || 0;
                var ordersTotal = parseInt(progress.orders_total, 10) || 0;

                return ordersTotal > 0 && ordersMigrated >= ordersTotal;
            }

            function shouldRevealPackages(progress) {
                var usersMigrated = parseInt(progress.users_migrated, 10) || 0;
                var usersTotal = parseInt(progress.users_total, 10) || 0;

                return isOrdersDone(progress) && ( usersTotal > 0 || usersMigrated > 0 );
            }

            function updateRow(step, migrated, total, shouldReveal) {
                var row = notice.querySelector('[data-directorist-migration-step="' + step + '"]');

                if ( ! row ) {
                    if ( shouldReveal ) {
                        location.reload();
                    }
                    return;
                }

                var count = row.querySelector('.directorist-migration-step-count');
                if ( count ) {
                    count.textContent = countText(migrated, total);
                }
            }

            function updateProgress(progress) {
                if ( ! progress ) { return; }

                updateRow('plans', parseInt(progress.plans_migrated, 10) || 0, parseInt(progress.plans_total, 10) || 0);
                updateRow('orders', parseInt(progress.orders_migrated, 10) || 0, parseInt(progress.orders_total, 10) || 0);
                updateRow(
                    'packages',
                    parseInt(progress.users_migrated, 10) || 0,
                    parseInt(progress.users_total, 10) || 0,
                    shouldRevealPackages(progress)
                );
            }

            function scheduleRefreshData() {
                window.setTimeout(refreshData, refreshDelay);
            }

            function refreshData() {
                if ( isProcessing ) { return; }

                isProcessing = true;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', statusUrl, true);
                xhr.timeout = 50000;
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', nonce);
                xhr.onload = function() {
                    isProcessing = false;

                    if ( xhr.status >= 200 && xhr.status < 300 ) {
                        var payload = getPayload(xhr.responseText);

                        if ( payload ) {
                            updateProgress(payload.progress);

                            if ( payload.finished || payload.failed_count > 0 || ( ! payload.active && ! payload.processing ) ) {
                                location.reload();
                                return;
                            }
                        }
                    }

                    scheduleRefreshData();
                };
                xhr.onerror = function() {
                    isProcessing = false;
                    scheduleRefreshData();
                };
                xhr.ontimeout = function() {
                    isProcessing = false;
                    scheduleRefreshData();
                };
                xhr.send();
            }

            scheduleRefreshData();
        })();
        </script>
        <?php
    }

    /**
     * Render the migration completed notice.
     *
     * @param OldDataMigrationQueue $migration_queue
     */
    private function render_migration_completed_notice( OldDataMigrationQueue $migration_queue ) {
        $failed_count = $migration_queue->get_failed_count();
        $progress     = $migration_queue->get_progress();
        $dismiss_url  = wp_nonce_url(
            add_query_arg( 'directorist_dismiss_migration_notice', '1' ),
            'directorist_dismiss_migration_notice'
        );

        $has_remaining = ( $progress['plans_total'] > 0 && $progress['plans_migrated'] < $progress['plans_total'] )
            || ( $progress['orders_total'] > 0 && $progress['orders_migrated'] < $progress['orders_total'] )
            || ( $progress['users_total'] > 0 && $progress['users_migrated'] < $progress['users_total'] );

        $has_failure  = $failed_count > 0 || $has_remaining;
        $header_bg    = $has_failure ? '#fcf0f1' : '#edfaef';
        $header_bdr   = $has_failure ? '#f5c2c3' : '#b7e5bb';
        $notice_class = $has_failure ? 'notice-warning' : 'notice-success';
        if ( ! $has_failure ) {
            $header_msg = __( 'Migration completed successfully.', 'directorist-pricing-plans' );
        } elseif ( $has_remaining && $failed_count === 0 ) {
            $header_msg = __( 'Migration completed with remaining items that were not migrated. Please retry again.', 'directorist-pricing-plans' );
        } else {
            $header_msg = sprintf(
                _n(
                    'Migration completed with %d failed step. Please retry again.',
                    'Migration completed with %d failed steps. Please retry again.',
                    $failed_count,
                    'directorist-pricing-plans'
                ),
                $failed_count
            );
        }
        ?>
        <div class="notice <?php echo esc_attr( $notice_class ); ?> directorist-migration-notice" id="directorist-migration-completed-notice" style="padding:0;overflow:hidden;">
            <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;background:<?php echo esc_attr( $header_bg ); ?>;border-bottom:1px solid <?php echo esc_attr( $header_bdr ); ?>;">
                <div>
                    <strong style="font-size:13px;color:#1d2327;"><?php esc_html_e( 'Directorist Pricing Plan:', 'directorist-pricing-plans' ); ?></strong>
                    <span style="font-size:13px;color:#50575e;margin-left:6px;"><?php echo esc_html( $header_msg ); ?></span>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;margin-left:16px;">
                    <?php if ( $has_failure ) : ?>
                    <button type="button"
                            class="button button-secondary directorist-migration-retry-btn"
                            data-api-url="<?php echo esc_url( rest_url( 'directorist-pricing-plans/admin/migration/retry' ) ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
                        <?php esc_html_e( 'Retry', 'directorist-pricing-plans' ); ?>
                    </button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-link"><?php esc_html_e( 'Dismiss', 'directorist-pricing-plans' ); ?></a>
                </div>
            </div>
            <div style="padding:16px 20px;">
                <?php $this->render_steps_block( $progress, false, true ); ?>
            </div>
        </div>
        <?php if ( $has_failure ) : ?>
        <script type="text/javascript">
        (function() {
            var btn = document.querySelector('.directorist-migration-retry-btn');
            if ( ! btn ) { return; }
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Retrying...', 'directorist-pricing-plans' ) ); ?>';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', btn.getAttribute('data-api-url'), true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', btn.getAttribute('data-nonce'));
                xhr.onload = function() {
                    if ( xhr.status >= 200 && xhr.status < 300 ) {
                        location.reload();
                    } else {
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Retry', 'directorist-pricing-plans' ) ); ?>';
                        alert('<?php echo esc_js( __( 'Failed to start retry. Please try again.', 'directorist-pricing-plans' ) ); ?>');
                    }
                };
                xhr.onerror = function() {
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js( __( 'Retry', 'directorist-pricing-plans' ) ); ?>';
                    alert('<?php echo esc_js( __( 'Failed to start retry. Please try again.', 'directorist-pricing-plans' ) ); ?>');
                };
                xhr.send();
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }
}
