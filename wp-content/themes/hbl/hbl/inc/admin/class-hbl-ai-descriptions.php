<?php
/**
 * HBL AI Description Generator Tool
 *
 * Site tool under Directorist Tools. Finds all Directorist listings with
 * empty descriptions, calls the Claude AI API using business name and primary
 * subcategory as inputs, and writes the result back. Also outputs a log of
 * generated descriptions for Gabrielle to spot-check.
 *
 * @package HBL
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_AI_Descriptions {

	const OPTION_KEY = 'hbl_ai_desc_settings';
	const LOG_KEY    = 'hbl_ai_desc_log';
	const LOG_MAX    = 500;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'add_admin_menu' ), 34 );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_hbl_ai_desc_batch_run',      array( $this, 'ajax_batch_run' ) );
		add_action( 'wp_ajax_hbl_ai_desc_clear_log',      array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_hbl_ai_desc_count_empty',    array( $this, 'ajax_count_empty' ) );
		add_action( 'wp_ajax_hbl_ai_desc_apply_selected', array( $this, 'ajax_apply_selected' ) );
	}

	// ── Menu & assets ─────────────────────────────────────────────────────────

	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'AI Descriptions', 'hbl' ),
			__( 'AI Descriptions', 'hbl' ),
			'manage_options',
			'hbl-ai-descriptions',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'directorist-tools_page_hbl-ai-descriptions' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'hbl-admin-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style(
			'hbl-bulk-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css',
			array( 'hbl-admin-font-poppins' ),
			HBL_VERSION
		);

		wp_enqueue_style(
			'hbl-ai-descriptions',
			HBL_THEME_URI . '/inc/admin/css/ai-descriptions.css',
			array( 'hbl-bulk-reassign' ),
			HBL_VERSION
		);
	}

	public function register_settings() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		register_setting(
			'hbl_ai_desc',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	// ── Settings helpers ──────────────────────────────────────────────────────

	private function get_settings(): array {
		$defaults = array(
			'api_key'         => '',
			'model'           => 'claude-haiku-4-5-20251001',
			'prompt_template' => "Write a 2–3 sentence business description for {business_name}, a local {subcategory} business. Keep it friendly, professional, and suitable for a public business directory. Output only the description with no preamble or quotation marks.",
			'batch_size'      => 10,
			'dry_run'         => false,
		);
		return array_merge( $defaults, (array) get_option( self::OPTION_KEY, array() ) );
	}

	public function sanitize_settings( $input ): array {
		$allowed_models = array(
			'claude-haiku-4-5-20251001',
			'claude-sonnet-4-6',
			'claude-opus-4-8',
		);
		$model = sanitize_key( $input['model'] ?? 'claude-haiku-4-5-20251001' );
		if ( ! in_array( $model, $allowed_models, true ) ) {
			$model = 'claude-haiku-4-5-20251001';
		}
		return array(
			'api_key'         => sanitize_text_field( $input['api_key']         ?? '' ),
			'model'           => $model,
			'prompt_template' => sanitize_textarea_field( $input['prompt_template'] ?? '' ),
			'batch_size'      => max( 1, min( 50, (int) ( $input['batch_size'] ?? 10 ) ) ),
			'dry_run'         => ! empty( $input['dry_run'] ),
		);
	}

	// ── Listing helpers ───────────────────────────────────────────────────────

	/**
	 * Returns the primary subcategory name for a listing.
	 * Prefers child terms (true subcategories); falls back to top-level term.
	 */
	private function get_primary_subcategory( int $listing_id ): string {
		$terms = get_the_terms( $listing_id, 'at_biz_dir-category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			if ( $term->parent > 0 ) {
				return $term->name;
			}
		}
		return $terms[0]->name ?? '';
	}

	/**
	 * True when a listing's post_content is blank after stripping HTML/whitespace.
	 */
	private function has_empty_description( int $listing_id ): bool {
		$post = get_post( $listing_id );
		return $post && '' === trim( wp_strip_all_tags( $post->post_content ) );
	}

	/**
	 * Returns IDs of published listings with empty post_content.
	 *
	 * @return int[]
	 */
	private function query_empty_listings( int $offset = 0, int $per_page = 10 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type   = %s
			   AND post_status = 'publish'
			   AND LENGTH(TRIM(post_content)) = 0
			 ORDER BY ID ASC
			 LIMIT %d OFFSET %d",
			'at_biz_dir',
			$per_page,
			$offset
		) ) );
	}

	private function count_empty_listings(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type   = 'at_biz_dir'
			   AND post_status = 'publish'
			   AND LENGTH(TRIM(post_content)) = 0"
		);
	}

	// ── Claude API ────────────────────────────────────────────────────────────

	/**
	 * @return array{ok: bool, text: string, error: string}
	 */
	private function call_claude( int $listing_id, string $business_name, string $subcategory, array $settings ): array {
		$api_key = trim( $settings['api_key'] ?? '' );
		if ( ! $api_key ) {
			return array( 'ok' => false, 'text' => '', 'error' => 'No API key configured.' );
		}

		$prompt = str_replace(
			array( '{business_name}', '{subcategory}' ),
			array( $business_name, $subcategory ?: 'business' ),
			$settings['prompt_template'] ?? ''
		);

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => $settings['model'] ?? 'claude-haiku-4-5-20251001',
				'max_tokens' => 300,
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'text' => '', 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array( 'ok' => false, 'text' => '', 'error' => $data['error']['message'] ?? "API returned HTTP {$code}." );
		}

		$text = trim( $data['content'][0]['text'] ?? '' );
		if ( ! $text ) {
			return array( 'ok' => false, 'text' => '', 'error' => 'Claude returned an empty response.' );
		}

		return array( 'ok' => true, 'text' => $text, 'error' => '' );
	}

	// ── Log helpers ───────────────────────────────────────────────────────────

	private function append_log( array $entries ): void {
		$log = (array) get_option( self::LOG_KEY, array() );
		$log = array_merge( $log, $entries );
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_KEY, $log, false );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	public function ajax_count_empty(): void {
		check_ajax_referer( 'hbl_ai_desc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}
		wp_send_json_success( array( 'count' => $this->count_empty_listings() ) );
	}

	public function ajax_clear_log(): void {
		check_ajax_referer( 'hbl_ai_desc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}
		delete_option( self::LOG_KEY );
		wp_send_json_success();
	}

	/**
	 * Writes selected dry-run descriptions to their listings and marks them as written in the log.
	 * Receives: items = JSON-encoded [{listing_id: int, description: string}, ...]
	 */
	public function ajax_apply_selected(): void {
		check_ajax_referer( 'hbl_ai_desc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$items_raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items     = json_decode( $items_raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( 'No items provided.' );
		}

		$written     = 0;
		$failed      = 0;
		$written_ids = array();

		foreach ( $items as $item ) {
			$listing_id  = (int) ( $item['listing_id']  ?? 0 );
			$description = sanitize_textarea_field( $item['description'] ?? '' );

			if ( ! $listing_id || ! $description ) {
				$failed++;
				continue;
			}

			$post = get_post( $listing_id );
			if ( ! $post || $post->post_type !== 'at_biz_dir' ) {
				$failed++;
				continue;
			}

			$result = wp_update_post( array(
				'ID'           => $listing_id,
				'post_content' => wp_kses_post( $description ),
			) );

			if ( is_wp_error( $result ) || ! $result ) {
				$failed++;
			} else {
				$written++;
				$written_ids[] = $listing_id;
			}
		}

		// Flip the most-recent dry_run log entry to 'written' for each applied listing.
		if ( ! empty( $written_ids ) ) {
			$log     = (array) get_option( self::LOG_KEY, array() );
			$pending = array_flip( $written_ids );

			for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
				$lid = (int) ( $log[ $i ]['listing_id'] ?? 0 );
				if ( isset( $pending[ $lid ] ) && 'dry_run' === ( $log[ $i ]['status'] ?? '' ) ) {
					$log[ $i ]['status'] = 'written';
					unset( $pending[ $lid ] );
				}
			}

			update_option( self::LOG_KEY, $log, false );
		}

		wp_send_json_success( array(
			'written'     => $written,
			'failed'      => $failed,
			'written_ids' => $written_ids,
		) );
	}

	public function ajax_batch_run(): void {
		check_ajax_referer( 'hbl_ai_desc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$settings = $this->get_settings();
		$offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		$dry_run  = isset( $_POST['dry_run'] ) && 'true' === $_POST['dry_run'];
		$per_page = max( 1, min( 50, (int) ( $settings['batch_size'] ?? 10 ) ) );

		$ids   = $this->query_empty_listings( $offset, $per_page );
		$total = $this->count_empty_listings() + $offset;

		$generated = 0;
		$failed    = 0;
		$log_batch = array();

		foreach ( $ids as $listing_id ) {
			// PHP-level guard catches <p></p> etc. that slip past the SQL TRIM.
			if ( ! $this->has_empty_description( $listing_id ) ) {
				continue;
			}

			$post        = get_post( $listing_id );
			$title       = $post ? get_the_title( $post ) : "(#{$listing_id})";
			$subcategory = $this->get_primary_subcategory( $listing_id );

			$result = $this->call_claude( $listing_id, $title, $subcategory, $settings );

			if ( ! $result['ok'] ) {
				$failed++;
				$log_batch[] = array(
					'listing_id'  => $listing_id,
					'title'       => $title,
					'subcategory' => $subcategory,
					'description' => '',
					'status'      => 'error',
					'error'       => $result['error'],
					'timestamp'   => current_time( 'mysql' ),
				);
				continue;
			}

			$description = $result['text'];
			$status      = 'dry_run';

			if ( ! $dry_run ) {
				wp_update_post( array(
					'ID'           => $listing_id,
					'post_content' => wp_kses_post( $description ),
				) );
				$status = 'written';
			}

			$generated++;
			$log_batch[] = array(
				'listing_id'  => $listing_id,
				'title'       => $title,
				'subcategory' => $subcategory,
				'description' => $description,
				'status'      => $status,
				'error'       => '',
				'timestamp'   => current_time( 'mysql' ),
			);
		}

		$this->append_log( $log_batch );

		wp_send_json_success( array(
			'generated' => $generated,
			'failed'    => $failed,
			'total'     => $total,
			'processed' => $offset + count( $ids ),
			'done'      => count( $ids ) < $per_page,
			'log_batch' => $log_batch,
		) );
	}

	// ── Admin page HTML ───────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = $this->get_settings();
		$has_api_key = ! empty( $settings['api_key'] );
		$is_dry_run  = ! empty( $settings['dry_run'] );
		$log         = array_reverse( (array) get_option( self::LOG_KEY, array() ) ); // newest first
		$log_count   = count( $log );

		$nonce = wp_create_nonce( 'hbl_ai_desc_nonce' );

		$models = array(
			'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 — fastest, most cost-efficient',
			'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 — balanced quality and speed',
			'claude-opus-4-8'           => 'Claude Opus 4.8 — highest quality',
		);
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-aidesc-wrap">
			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:8px">
					<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'AI Description Generator', 'hbl' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Generate descriptions for Directorist listings that have no content. Uses Claude AI with each listing\'s business name and primary subcategory as inputs.', 'hbl' ); ?></p>

			<!-- Stats -->
			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h10M4 18h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number" id="hbl-aidesc-stat-empty">–</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Missing descriptions', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--info">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M2 7l10 5 10-5-10-5-10 5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( (string) $log_count ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Log entries', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card <?php echo $has_api_key ? 'hbl-stat-card--success' : 'hbl-stat-card--warning'; ?>">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number">
							<span class="hbl-aidesc-badge <?php echo $has_api_key ? 'hbl-aidesc-badge--on' : 'hbl-aidesc-badge--warn'; ?>">
								<?php echo $has_api_key ? esc_html__( 'Key Set', 'hbl' ) : esc_html__( 'No Key', 'hbl' ); ?>
							</span>
						</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Claude API', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card <?php echo $is_dry_run ? 'hbl-stat-card--warning' : 'hbl-stat-card--success'; ?>">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number">
							<span class="hbl-aidesc-badge <?php echo $is_dry_run ? 'hbl-aidesc-badge--warn' : 'hbl-aidesc-badge--on'; ?>">
								<?php echo $is_dry_run ? esc_html__( 'Dry Run', 'hbl' ) : esc_html__( 'Live', 'hbl' ); ?>
							</span>
						</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Write Mode', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<div class="hbl-reassign-container">

				<!-- ── Step 1: Settings ──────────────────────────────────────── -->
				<form method="post" action="options.php">
					<?php settings_fields( 'hbl_ai_desc' ); ?>
					<div class="hbl-reassign-step">
						<div class="hbl-step-header">
							<span class="hbl-step-number">1</span>
							<h2><?php esc_html_e( 'Settings', 'hbl' ); ?></h2>
						</div>

						<div class="hbl-aidesc-field">
							<label for="hbl-ai-api-key"><?php esc_html_e( 'Claude API Key', 'hbl' ); ?></label>
							<div class="hbl-aidesc-field-row">
								<input
									type="password"
									id="hbl-ai-api-key"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
									value="<?php echo esc_attr( $settings['api_key'] ); ?>"
									class="hbl-input hbl-aidesc-code"
									style="max-width:360px"
									autocomplete="off"
									placeholder="sk-ant-api03-..."
								>
								<button type="button" class="button button-secondary" onclick="var f=document.getElementById('hbl-ai-api-key');f.type=f.type==='password'?'text':'password';">
									<?php esc_html_e( 'Show / Hide', 'hbl' ); ?>
								</button>
							</div>
							<p class="description"><?php esc_html_e( 'Get your key from console.anthropic.com → API Keys.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-aidesc-field">
							<label for="hbl-ai-model"><?php esc_html_e( 'Model', 'hbl' ); ?></label>
							<select id="hbl-ai-model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]" class="hbl-select">
								<?php foreach ( $models as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['model'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Haiku is recommended for bulk generation: fast, cheap, and produces solid short descriptions.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-aidesc-field">
							<label for="hbl-ai-prompt"><?php esc_html_e( 'Prompt Template', 'hbl' ); ?></label>
							<textarea
								id="hbl-ai-prompt"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[prompt_template]"
								class="hbl-aidesc-textarea"
								rows="4"
							><?php echo esc_textarea( $settings['prompt_template'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Use', 'hbl' ); ?> <code>{business_name}</code> <?php esc_html_e( 'and', 'hbl' ); ?> <code>{subcategory}</code> <?php esc_html_e( 'as placeholders. Output is written directly to the listing description.', 'hbl' ); ?>
							</p>
						</div>

						<div class="hbl-aidesc-field">
							<label for="hbl-ai-batch-size"><?php esc_html_e( 'Batch size', 'hbl' ); ?></label>
							<input
								type="number"
								id="hbl-ai-batch-size"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]"
								value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>"
								class="hbl-input"
								style="max-width:80px"
								min="1"
								max="50"
							>
							<p class="description"><?php esc_html_e( 'Listings processed per AJAX call (1–50). Lower is safer on shared hosts.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-aidesc-toggle-row">
							<label class="hbl-aidesc-toggle">
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[dry_run]"
									value="1"
									<?php checked( $is_dry_run ); ?>
								>
								<span class="hbl-aidesc-toggle-slider"></span>
							</label>
							<div class="hbl-aidesc-toggle-text">
								<strong><?php esc_html_e( 'Dry Run Mode', 'hbl' ); ?></strong>
								<span><?php esc_html_e( 'Generate and log descriptions but do not write them back to listings. Uncheck to go live.', 'hbl' ); ?></span>
							</div>
						</div>

						<?php submit_button( __( 'Save Settings', 'hbl' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<!-- ── Step 2: Run Generator ─────────────────────────────────── -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">2</span>
						<h2><?php esc_html_e( 'Run Generator', 'hbl' ); ?></h2>
					</div>

					<?php if ( ! $has_api_key ) : ?>
						<p class="description hbl-aidesc-no-key-notice">
							<?php esc_html_e( 'A Claude API key is required. Add one in Step 1 above.', 'hbl' ); ?>
						</p>
					<?php else : ?>
						<div class="hbl-aidesc-run-controls">
							<button type="button" id="hbl-aidesc-run-btn" class="button button-primary button-hero">
								<?php esc_html_e( 'Start Generation', 'hbl' ); ?>
							</button>
							<button type="button" id="hbl-aidesc-stop-btn" class="button button-hero" style="display:none">
								<?php esc_html_e( 'Stop After This Batch', 'hbl' ); ?>
							</button>
							<span id="hbl-aidesc-run-status" class="hbl-aidesc-run-status"></span>
						</div>
						<div id="hbl-aidesc-progress" style="display:none;margin-top:16px">
							<div class="hbl-aidesc-progress-track">
								<div id="hbl-aidesc-progress-bar" class="hbl-aidesc-progress-fill"></div>
							</div>
							<p id="hbl-aidesc-progress-msg" class="hbl-aidesc-progress-msg"></p>
						</div>

						<?php if ( $is_dry_run ) : ?>
							<p class="description" style="margin-top:12px;color:#92400E">
								<?php esc_html_e( 'Dry Run mode is ON — descriptions will be logged but not saved to listings.', 'hbl' ); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<!-- ── Step 3: Spot-Check Log ────────────────────────────────── -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
						<div style="display:flex;align-items:center;gap:14px">
							<span class="hbl-step-number">3</span>
							<h2><?php esc_html_e( 'Spot-Check Log', 'hbl' ); ?></h2>
						</div>
						<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
							<button type="button" id="hbl-aidesc-apply-btn" class="button button-primary" style="display:none" disabled>
								<?php esc_html_e( 'Apply Selected', 'hbl' ); ?> (<span id="hbl-aidesc-apply-count">0</span>)
							</button>
							<?php if ( $log_count > 0 ) : ?>
								<button type="button" id="hbl-aidesc-clear-log-btn" class="button hbl-aidesc-clear-btn">
									<?php esc_html_e( 'Clear Log', 'hbl' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
					<p class="description" style="margin-bottom:16px">
						<?php esc_html_e( 'Every generation attempt is recorded here. Select Dry Run rows and click Apply Selected to write those descriptions to their listings.', 'hbl' ); ?>
					</p>

					<?php if ( empty( $log ) ) : ?>
						<p class="description" style="color:#6B7280"><?php esc_html_e( 'No entries yet. Run the generator above to populate this log.', 'hbl' ); ?></p>
					<?php else : ?>
						<label class="hbl-select-all-wrap" style="margin-bottom:12px">
							<input type="checkbox" id="hbl-aidesc-select-all" title="<?php esc_attr_e( 'Select all Dry Run rows', 'hbl' ); ?>">
							<span><?php esc_html_e( 'Select All', 'hbl' ); ?></span>
						</label>
						<div class="hbl-table-card">
							<table class="hbl-aidesc-log-table">
								<thead>
									<tr>
										<th style="width:36px;text-align:center"></th>
										</th>
										<th style="width:36px">#</th>
										<th><?php esc_html_e( 'Listing', 'hbl' ); ?></th>
										<th style="width:150px"><?php esc_html_e( 'Subcategory', 'hbl' ); ?></th>
										<th><?php esc_html_e( 'Generated Description', 'hbl' ); ?></th>
										<th style="width:90px"><?php esc_html_e( 'Status', 'hbl' ); ?></th>
										<th style="width:130px"><?php esc_html_e( 'Time', 'hbl' ); ?></th>
									</tr>
								</thead>
								<tbody id="hbl-aidesc-log-tbody">
									<?php
									$status_map = array(
										'written' => '<span class="hbl-aidesc-status hbl-aidesc-status--written">Written</span>',
										'dry_run' => '<span class="hbl-aidesc-status hbl-aidesc-status--dry">Dry Run</span>',
										'error'   => '<span class="hbl-aidesc-status hbl-aidesc-status--error">Error</span>',
									);
									foreach ( $log as $i => $entry ) :
										$edit_url    = get_edit_post_link( (int) $entry['listing_id'] );
										$selectable  = 'dry_run' === $entry['status'];
										?>
										<tr data-listing-id="<?php echo esc_attr( (string) $entry['listing_id'] ); ?>"
											data-description="<?php echo esc_attr( $entry['description'] ?? '' ); ?>"
											data-status="<?php echo esc_attr( $entry['status'] ); ?>">
											<td style="text-align:center" data-label="">
												<?php if ( $selectable ) : ?>
													<input type="checkbox" class="hbl-aidesc-row-cb" value="<?php echo esc_attr( (string) $entry['listing_id'] ); ?>">
												<?php else : ?>
													<input type="checkbox" disabled style="opacity:.3">
												<?php endif; ?>
											</td>
											<td data-label="<?php esc_attr_e( 'No.', 'hbl' ); ?>"><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
											<td data-label="<?php esc_attr_e( 'Business Name', 'hbl' ); ?>">
												<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank">
													<?php echo esc_html( $entry['title'] ); ?>
												</a>
												<span class="hbl-aidesc-listing-id">#<?php echo esc_html( (string) $entry['listing_id'] ); ?></span>
											</td>
											<td data-label="<?php esc_attr_e( 'Subcategory', 'hbl' ); ?>"><?php echo esc_html( $entry['subcategory'] ?: '—' ); ?></td>
											<td class="hbl-aidesc-desc-cell" data-label="<?php esc_attr_e( 'Description', 'hbl' ); ?>">
												<?php if ( 'error' === $entry['status'] ) : ?>
													<span class="hbl-aidesc-error-text"><?php echo esc_html( $entry['error'] ); ?></span>
												<?php elseif ( $selectable ) : ?>
													<textarea class="hbl-aidesc-edit-textarea" rows="2"><?php echo esc_textarea( $entry['description'] ?? '' ); ?></textarea>
												<?php else : ?>
													<?php echo esc_html( $entry['description'] ); ?>
												<?php endif; ?>
											</td>
											<td data-label="<?php esc_attr_e( 'Status', 'hbl' ); ?>"><?php echo $status_map[ $entry['status'] ] ?? esc_html( $entry['status'] ); // phpcs:ignore ?></td>
											<td class="hbl-aidesc-ts" data-label="<?php esc_attr_e( 'Time', 'hbl' ); ?>"><?php echo esc_html( $entry['timestamp'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

			</div><!-- .hbl-reassign-container -->
		</div>

		<script>
		(function () {
			'use strict';

			var NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
			var DRY_RUN   = <?php echo wp_json_encode( $is_dry_run ); ?>;
			var AJAX      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var EDIT_BASE = <?php echo wp_json_encode( admin_url( 'post.php?action=edit&post=' ) ); ?>;

			// ── Utility ────────────────────────────────────────────────────────
			function htmlEsc( str ) {
				var d = document.createElement( 'div' );
				d.textContent = str || '';
				return d.innerHTML;
			}

			// ── Auto-growing textarea (Prompt Template) ──────────────────────────
			// Grows to fit its content instead of showing an internal scrollbar.
			(function () {
				var ta = document.getElementById( 'hbl-ai-prompt' );
				if ( ! ta ) return;
				function grow() {
					ta.style.height = 'auto';
					ta.style.height = ta.scrollHeight + 2 + 'px';
				}
				ta.addEventListener( 'input', grow );
				window.addEventListener( 'resize', grow );
				grow();
			})();

			// ── Load empty count on page load ──────────────────────────────────
			(function () {
				var el = document.getElementById( 'hbl-aidesc-stat-empty' );
				if ( ! el ) return;
				var fd = new FormData();
				fd.append( 'action', 'hbl_ai_desc_count_empty' );
				fd.append( 'nonce', NONCE );
				fetch( AJAX, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) { if ( res.success ) el.textContent = res.data.count; } )
					.catch( function () { el.textContent = '?'; } );
			})();

			// ── Selection helpers ──────────────────────────────────────────────
			var applyBtn     = document.getElementById( 'hbl-aidesc-apply-btn' );
			var applyCount   = document.getElementById( 'hbl-aidesc-apply-count' );
			var selectAllCb  = document.getElementById( 'hbl-aidesc-select-all' );
			var tbodyEl      = document.getElementById( 'hbl-aidesc-log-tbody' );

			function getRowCheckboxes() {
				if ( ! tbodyEl ) return [];
				return Array.prototype.slice.call( tbodyEl.querySelectorAll( '.hbl-aidesc-row-cb' ) );
			}

			function updateApplyButton() {
				var checked = getRowCheckboxes().filter( function (cb) { return cb.checked; } );
				var n = checked.length;
				if ( applyBtn ) {
					applyBtn.style.display = n > 0 ? '' : 'none';
					applyBtn.disabled      = n === 0;
				}
				if ( applyCount ) applyCount.textContent = n;

				// Sync select-all indeterminate state
				if ( selectAllCb ) {
					var all = getRowCheckboxes();
					selectAllCb.disabled = all.length === 0;
					if ( n === 0 ) {
						selectAllCb.checked       = false;
						selectAllCb.indeterminate = false;
					} else if ( n === all.length ) {
						selectAllCb.checked       = true;
						selectAllCb.indeterminate = false;
					} else {
						selectAllCb.checked       = false;
						selectAllCb.indeterminate = true;
					}
				}
			}

			// Delegate checkbox clicks inside the tbody
			if ( tbodyEl ) {
				tbodyEl.addEventListener( 'change', function (e) {
					if ( e.target && e.target.classList.contains( 'hbl-aidesc-row-cb' ) ) {
						updateApplyButton();
					}
				} );
			}

			// Select / deselect all
			if ( selectAllCb ) {
				selectAllCb.addEventListener( 'change', function () {
					getRowCheckboxes().forEach( function (cb) {
						cb.checked = selectAllCb.checked;
					} );
					updateApplyButton();
				} );
			}

			// ── Apply Selected ─────────────────────────────────────────────────
			if ( applyBtn ) {
				applyBtn.addEventListener( 'click', function () {
					var checked = getRowCheckboxes().filter( function (cb) { return cb.checked; } );
					if ( ! checked.length ) return;

					if ( ! confirm( checked.length + ' description(s) will be written to their listings. Continue?' ) ) return;

					var items = checked.map( function (cb) {
						var row = cb.closest( 'tr' );
						var textarea = row.querySelector( '.hbl-aidesc-edit-textarea' );
						return {
							listing_id  : parseInt( row.dataset.listingId, 10 ),
							description : textarea ? textarea.value.trim() : row.dataset.description,
						};
					} );

					applyBtn.disabled    = true;
					applyBtn.textContent = 'Applying…';

					var fd = new FormData();
					fd.append( 'action', 'hbl_ai_desc_apply_selected' );
					fd.append( 'nonce',  NONCE );
					fd.append( 'items',  JSON.stringify( items ) );

					fetch( AJAX, { method: 'POST', body: fd } )
						.then( function (r) { return r.json(); } )
						.then( function (res) {
							if ( ! res.success ) {
								alert( 'Apply failed: ' + ( res.data || 'Unknown error.' ) );
								applyBtn.disabled    = false;
								applyBtn.innerHTML   = 'Apply Selected (<span id="hbl-aidesc-apply-count">' + checked.length + '</span>)';
								return;
							}

							var d = res.data;

							// Update each applied row in the table
							d.written_ids.forEach( function ( lid ) {
								var row = tbodyEl.querySelector( 'tr[data-listing-id="' + lid + '"]' );
								if ( ! row ) return;

								// Uncheck and disable the checkbox
								var cb = row.querySelector( '.hbl-aidesc-row-cb' );
								if ( cb ) {
									cb.checked  = false;
									cb.disabled = true;
									cb.classList.remove( 'hbl-aidesc-row-cb' );
									cb.style.opacity = '0.3';
								}

								// Flip status badge
								var statusCell = row.cells[ row.cells.length - 2 ]; // 2nd-to-last cell
								if ( statusCell ) {
									statusCell.innerHTML = '<span class="hbl-aidesc-status hbl-aidesc-status--written">Written</span>';
								}

								// Update data attribute so future refreshes reflect reality
								row.dataset.status = 'written';
							} );

							// Show result summary
							var msg = d.written + ' description(s) written to listings.';
							if ( d.failed ) msg += ' ' + d.failed + ' failed.';
							alert( msg );

							applyBtn.style.display = 'none';
							if ( selectAllCb ) { selectAllCb.checked = false; selectAllCb.indeterminate = false; }

							// Refresh empty-count stat
							var cfd = new FormData();
							cfd.append( 'action', 'hbl_ai_desc_count_empty' );
							cfd.append( 'nonce',  NONCE );
							fetch( AJAX, { method: 'POST', body: cfd } )
								.then( function (r) { return r.json(); } )
								.then( function (cr) {
									var el = document.getElementById( 'hbl-aidesc-stat-empty' );
									if ( el && cr.success ) el.textContent = cr.data.count;
								} );
						} )
						.catch( function (e) {
							alert( 'Network error: ' + e.message );
							applyBtn.disabled    = false;
							applyBtn.innerHTML   = 'Apply Selected (<span id="hbl-aidesc-apply-count">' + checked.length + '</span>)';
						} );
				} );
			}

			// ── Run generator ──────────────────────────────────────────────────
			var runBtn     = document.getElementById( 'hbl-aidesc-run-btn' );
			var stopBtn    = document.getElementById( 'hbl-aidesc-stop-btn' );
			var statusEl   = document.getElementById( 'hbl-aidesc-run-status' );
			var progressEl = document.getElementById( 'hbl-aidesc-progress' );
			var barEl      = document.getElementById( 'hbl-aidesc-progress-bar' );
			var msgEl      = document.getElementById( 'hbl-aidesc-progress-msg' );

			if ( ! runBtn ) return;

			var stopping = false;

			var STATUS_HTML = {
				written : '<span class="hbl-aidesc-status hbl-aidesc-status--written">Written</span>',
				dry_run : '<span class="hbl-aidesc-status hbl-aidesc-status--dry">Dry Run</span>',
				error   : '<span class="hbl-aidesc-status hbl-aidesc-status--error">Error</span>'
			};

			function resetUI() {
				runBtn.disabled       = false;
				runBtn.style.display  = '';
				stopBtn.style.display = 'none';
				stopBtn.disabled      = false;
			}

			function prependLogRows( entries ) {
				if ( ! tbodyEl || ! entries || ! entries.length ) return;

				// Renumber existing row index cells (column 2, index 1)
				var existingRows = tbodyEl.querySelectorAll( 'tr' );
				for ( var x = 0; x < existingRows.length; x++ ) {
					existingRows[ x ].cells[1].textContent = entries.length + x + 1;
				}

				for ( var i = entries.length - 1; i >= 0; i-- ) {
					var e   = entries[ i ];
					var tr  = document.createElement( 'tr' );
					tr.setAttribute( 'data-listing-id',  e.listing_id );
					tr.setAttribute( 'data-description', e.description || '' );
					tr.setAttribute( 'data-status',      e.status );

					var cbCell = e.status === 'dry_run'
						? '<input type="checkbox" class="hbl-aidesc-row-cb" value="' + htmlEsc( String( e.listing_id ) ) + '">'
						: '<input type="checkbox" disabled style="opacity:.3">';

					var descCell = e.status === 'error'
						? '<span class="hbl-aidesc-error-text">' + htmlEsc( e.error ) + '</span>'
						: ( e.status === 'dry_run'
							? '<textarea class="hbl-aidesc-edit-textarea" rows="2">' + htmlEsc( e.description ) + '</textarea>'
							: htmlEsc( e.description ) );

					tr.innerHTML =
						'<td style="text-align:center" data-label="">' + cbCell + '</td>' +
						'<td data-label="No.">' + ( i + 1 ) + '</td>' +
						'<td data-label="Business Name"><a href="' + htmlEsc( EDIT_BASE + e.listing_id ) + '" target="_blank">' + htmlEsc( e.title ) + '</a>' +
						'<span class="hbl-aidesc-listing-id">#' + htmlEsc( String( e.listing_id ) ) + '</span></td>' +
						'<td data-label="Subcategory">' + htmlEsc( e.subcategory || '—' ) + '</td>' +
						'<td class="hbl-aidesc-desc-cell" data-label="Description">' + descCell + '</td>' +
						'<td data-label="Status">' + ( STATUS_HTML[ e.status ] || htmlEsc( e.status ) ) + '</td>' +
						'<td class="hbl-aidesc-ts" data-label="Time">' + htmlEsc( e.timestamp ) + '</td>';

					tbodyEl.insertBefore( tr, tbodyEl.firstChild );
				}

				updateApplyButton();
			}

			function run( offset, totals ) {
				if ( stopping ) {
					statusEl.textContent = 'Stopped. ' + totals.generated + ' description(s) ' + ( DRY_RUN ? 'previewed' : 'written' ) + '.';
					resetUI();
					return;
				}

				statusEl.textContent = offset === 0 ? 'Starting…' : 'Processing… (' + offset + ' done)';

				var fd = new FormData();
				fd.append( 'action',  'hbl_ai_desc_batch_run' );
				fd.append( 'nonce',   NONCE );
				fd.append( 'offset',  offset );
				fd.append( 'dry_run', DRY_RUN ? 'true' : 'false' );

				fetch( AJAX, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) {
						if ( ! res.success ) {
							statusEl.textContent = 'Error: ' + ( res.data || 'Unknown error.' );
							resetUI();
							return;
						}
						var d = res.data;
						var newTotals = {
							generated : totals.generated + d.generated,
							failed    : totals.failed    + d.failed,
							total     : d.total || totals.total
						};

						if ( newTotals.total ) {
							barEl.style.width = Math.min( 100, Math.round( d.processed / newTotals.total * 100 ) ) + '%';
						}
						msgEl.textContent = newTotals.generated + ' generated' +
							( newTotals.failed ? ', ' + newTotals.failed + ' failed' : '' ) +
							' of ~' + newTotals.total;

						if ( d.log_batch && d.log_batch.length ) {
							prependLogRows( d.log_batch );
						}

						if ( d.done ) {
							barEl.style.width = '100%';
							statusEl.textContent = 'Done! ' + newTotals.generated + ' description(s) ' +
								( DRY_RUN ? 'previewed (Dry Run)' : 'written to listings' ) +
								( newTotals.failed ? ', ' + newTotals.failed + ' failed.' : '.' );
							resetUI();

							var cfd = new FormData();
							cfd.append( 'action', 'hbl_ai_desc_count_empty' );
							cfd.append( 'nonce',  NONCE );
							fetch( AJAX, { method: 'POST', body: cfd } )
								.then( function (r) { return r.json(); } )
								.then( function (cr) {
									var el = document.getElementById( 'hbl-aidesc-stat-empty' );
									if ( el && cr.success ) el.textContent = cr.data.count;
								} );
						} else {
							run( d.processed, newTotals );
						}
					} )
					.catch( function (e) {
						statusEl.textContent = 'Network error: ' + e.message;
						resetUI();
					} );
			}

			runBtn.addEventListener( 'click', function () {
				stopping              = false;
				runBtn.disabled       = true;
				runBtn.style.display  = 'none';
				stopBtn.style.display = '';
				progressEl.style.display = 'block';
				barEl.style.width     = '0%';
				statusEl.textContent  = '';
				run( 0, { generated: 0, failed: 0, total: 0 } );
			} );

			stopBtn.addEventListener( 'click', function () {
				stopping             = true;
				stopBtn.disabled     = true;
				statusEl.textContent = 'Stopping after this batch…';
			} );

			// ── Clear log ──────────────────────────────────────────────────────
			var clearBtn = document.getElementById( 'hbl-aidesc-clear-log-btn' );
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					if ( ! confirm( '<?php echo esc_js( __( 'Clear the entire log? This cannot be undone.', 'hbl' ) ); ?>' ) ) return;
					clearBtn.disabled    = true;
					clearBtn.textContent = '<?php echo esc_js( __( 'Clearing…', 'hbl' ) ); ?>';
					var fd = new FormData();
					fd.append( 'action', 'hbl_ai_desc_clear_log' );
					fd.append( 'nonce',  NONCE );
					fetch( AJAX, { method: 'POST', body: fd } )
						.then( function () { location.reload(); } )
						.catch( function () { clearBtn.disabled = false; clearBtn.textContent = '<?php echo esc_js( __( 'Clear Log', 'hbl' ) ); ?>'; } );
				} );
			}
		})();
		</script>
		<?php
	}
}

HBL_AI_Descriptions::get_instance();
