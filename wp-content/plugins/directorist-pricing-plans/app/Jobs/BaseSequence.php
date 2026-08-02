<?php

namespace DirectoristPricingPlan\App\Jobs;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Queue\Sequence;

abstract class BaseSequence extends Sequence {
    private const INLINE_FALLBACK_DELAY = 30;

    public function __construct() {
        parent::__construct();
        
        // Hook into completion event
        add_action( $this->identifier . '_completed', [ $this, 'mark_as_completed' ] );
    }

    /**
     * Check if the queue has finished processing all tasks.
     * Returns true only if the queue was dispatched AND has completed.
     * 
     * @return bool True if queue finished processing
     */
    public function is_finished() {
        return (bool) get_option( "{$this->prefix}_completed", false );
    }

    public function before_dispatch() {
        update_option( "{$this->prefix}_completed", false );
        delete_option( $this->get_inline_fallback_started_key() );
    }

    public function mark_as_completed() {
        update_option( "{$this->prefix}_completed", true );
        delete_option( $this->get_inline_fallback_started_key() );
    }

    public function reset() {
        delete_option( "{$this->prefix}_completed" );
        delete_option( $this->get_inline_fallback_started_key() );
    }

    protected function sleep_on_rest_time() {
        return true;
    }

    /**
     * Dispatch the background processor and recover inline if loopback requests
     * are not starting the queue in this WordPress environment.
     *
     * @return array|\WP_Error|false
     */
    public function dispatch_or_process( bool $process_inline_immediately = false ) {
        if ( ! $this->is_active() ) {
            delete_option( $this->get_inline_fallback_started_key() );
            return false;
        }

        if ( $this->is_processing() ) {
            delete_option( $this->get_inline_fallback_started_key() );
            return false;
        }

        $fallback_started_at = $this->get_inline_fallback_started_at();
        $response            = $this->dispatch();

        if ( $this->should_process_inline( $response, $fallback_started_at, $process_inline_immediately ) ) {
            $this->process_inline();
        }

        return $response;
    }

    /**
     * Get the option key used to track when the queue became stalled.
     *
     * @return string
     */
    private function get_inline_fallback_started_key(): string {
        return "{$this->prefix}_inline_fallback_started_at";
    }

    /**
     * Get or initialize the timestamp used by the inline fallback.
     *
     * @return int
     */
    private function get_inline_fallback_started_at(): int {
        $key        = $this->get_inline_fallback_started_key();
        $started_at = (int) get_option( $key, 0 );

        if ( $started_at <= 0 ) {
            $started_at = time();
            update_option( $key, $started_at );
        }

        return $started_at;
    }

    /**
     * Determine whether the queue should be processed inline.
     *
     * @param mixed $response
     * @param int   $fallback_started_at
     * @return bool
     */
    private function should_process_inline( $response, int $fallback_started_at, bool $process_inline_immediately = false ): bool {
        if ( $this->is_processing() || $this->is_paused() || $this->is_cancelled() || ! $this->is_queued() ) {
            return false;
        }

        if ( $process_inline_immediately ) {
            return true;
        }

        if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
            return true;
        }

        if ( false === $response ) {
            return true;
        }

        return ( time() - $fallback_started_at ) >= self::INLINE_FALLBACK_DELAY;
    }

    /**
     * Process the next queue slice inline without terminating the current page.
     */
    private function process_inline(): void {
        if ( $this->is_processing() || $this->is_paused() || $this->is_cancelled() || ! $this->is_queued() ) {
            return;
        }

        add_filter( $this->identifier . '_wp_die', '__return_false' );

        try {
            $this->handle();
        } finally {
            remove_filter( $this->identifier . '_wp_die', '__return_false' );
        }
    }

    /**
     * Process queued work directly from cron instead of relying on another
     * loopback request to admin-ajax.php.
     */
    public function handle_cron_healthcheck() {
        if ( $this->is_processing() ) {
            exit;
        }

        if ( ! $this->is_queued() ) {
            $this->clear_scheduled_event();
            exit;
        }

        $this->process_inline();
        exit;
    }
    
    /**
     * Is queue empty.
     * 
     * Override to fix SQL syntax issue in vendor WP_Background_Process.
     *
     * @return bool
     */
    protected function is_queue_empty() {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        $key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are safe WordPress core properties.

        return ( $count > 0 ) ? false : true;
    }

    /**
     * Get batch.
     * 
     * Override to fix SQL syntax issue in vendor WP_Background_Process.
     *
     * @return \stdClass Return the first batch from the queue.
     */
    protected function get_batch() {
        global $wpdb;

        $table        = $wpdb->options;
        $column       = 'option_name';
        $key_column   = 'option_id';
        $value_column = 'option_value';

        if ( is_multisite() ) {
            $table        = $wpdb->sitemeta;
            $column       = 'meta_key';
            $key_column   = 'meta_id';
            $value_column = 'meta_value';
        }

        $key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are safe WordPress core properties.
        $query = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$column} LIKE %s ORDER BY {$key_column} ASC LIMIT 1",
                $key
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $batch       = new \stdClass();
        $batch->key  = $query->$column;
        $batch->data = maybe_unserialize( $query->$value_column );

        return $batch;
    }
}