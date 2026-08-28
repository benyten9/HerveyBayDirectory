<?php
/**
 * AI Assistant loader.
 *
 * Handles loading AI Assistant scripts and styles for different contexts:
 * - Gutenberg Editor (React sidebar plugin)
 * - Classic Editor (Admin bar button + slide-out panel)
 * - SEOPress Settings Pages (Header button + slide-out panel)
 *
 * @package SEOPress PRO
 * @subpackage AI Assistant
 * @since 9.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check if AI Assistant is enabled and properly configured.
 *
 * @since 9.6.0
 *
 * @return bool True if AI Assistant should be loaded.
 */
function seopress_ai_assistant_is_enabled() {
	$option_pro = seopress_pro_get_service( 'OptionPro' );

	// The AI Assistant must be explicitly enabled by the user.
	if ( empty( $option_pro->getAIAssistantEnabled() ) ) {
		return false;
	}

	// An AI provider API key must also be configured.
	$provider = $option_pro->getAIProvider();
	$api_key  = seopress_pro_get_service( 'Usage' )->getLicenseKey( $provider ? $provider : 'openai' );

	return ! empty( $api_key );
}

/**
 * Get the white-label aware brand name used in the AI Assistant UI.
 *
 * Returns "SEOPress" by default. When white-label is enabled, returns the user's
 * custom name (admin menu title, then Pro plugin list title, then free plugin
 * list title), or an empty string when no custom name is set so the brand can be
 * dropped entirely from the interface.
 *
 * @since 10.0.0
 *
 * @return string Brand name, or an empty string when the brand must be hidden.
 */
function seopress_ai_assistant_get_brand_name() {
	if ( '1' !== seopress_get_toggle_option( 'white-label' ) ) {
		return 'SEOPress';
	}

	$option_pro = seopress_pro_get_service( 'OptionPro' );

	$custom = $option_pro->getWhiteLabelAdminTitle();
	if ( empty( $custom ) ) {
		$custom = $option_pro->getWhiteLabelListTitlePro();
	}
	if ( empty( $custom ) ) {
		$custom = $option_pro->getWhiteLabelListTitle();
	}

	return ! empty( $custom ) ? trim( wp_strip_all_tags( (string) $custom ) ) : '';
}

/**
 * Get the white-label aware welcome message for the AI Assistant chat.
 *
 * @since 10.0.0
 *
 * @return string
 */
function seopress_ai_assistant_get_welcome_message() {
	$brand_name = seopress_ai_assistant_get_brand_name();

	if ( empty( $brand_name ) ) {
		return __( 'Hi! Ask me anything about SEO.', 'wp-seopress-pro' );
	}

	/* translators: %s: plugin (brand) name */
	return sprintf( __( 'Hi! Ask me anything about SEO or %s.', 'wp-seopress-pro' ), $brand_name );
}

/**
 * Enqueue Gutenberg editor assets for AI Assistant.
 *
 * @since 9.6.0
 *
 * @return void
 */
function seopress_ai_assistant_editor_assets() {
	if ( ! seopress_ai_assistant_is_enabled() ) {
		return;
	}

	// Hide the AI Assistant for roles not allowed to use AI (Advanced > Security).
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'edit_posts', 'chat' ) ) {
		return;
	}

	// Don't load in the Site Editor (FSE) — only in the post editor.
	$screen = get_current_screen();
	if ( $screen && 'site-editor' === $screen->id ) {
		return;
	}

	$asset_file = SEOPRESS_PRO_PLUGIN_DIR_PATH . 'public/editor/ai-assistant/ai-assistant.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = include $asset_file;

	wp_enqueue_script(
		'seopress-ai-assistant-editor',
		SEOPRESS_PRO_PLUGIN_DIR_URL . 'public/editor/ai-assistant/ai-assistant.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_enqueue_style(
		'seopress-ai-assistant-editor',
		SEOPRESS_PRO_PLUGIN_DIR_URL . 'public/editor/ai-assistant/ai-assistant.css',
		array(),
		$asset['version']
	);

	wp_set_script_translations( 'seopress-ai-assistant-editor', 'wp-seopress-pro' );

	// Pass AI provider/model config to the editor.
	$option_pro  = seopress_pro_get_service( 'OptionPro' );
	$completions = seopress_pro_get_service( 'Completions' );
	$provider    = $option_pro->getAIProvider();
	$provider    = ! empty( $provider ) ? $provider : 'openai';

	wp_localize_script(
		'seopress-ai-assistant-editor',
		'seopressAIConfig',
		array(
			'provider'    => $provider,
			'model'       => $completions->getAIModel( $provider ),
			'models'      => seopress_ai_get_models_list(),
			'isAdmin'     => current_user_can( 'manage_options' ),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'streamNonce' => wp_create_nonce( 'seopress_ai_stream' ),
			'brandName'   => seopress_ai_assistant_get_brand_name(),
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'seopress_ai_assistant_editor_assets' );

/**
 * Get the list of available models per provider.
 *
 * @since 9.8.0
 *
 * @return array Models grouped by provider.
 */
function seopress_ai_get_models_list() {
	return array(
		'openai'   => array(
			array(
				'label' => 'GPT-5.6 Terra',
				'value' => 'gpt-5.6-terra',
			),
			array(
				'label' => 'GPT-5.6 Sol',
				'value' => 'gpt-5.6-sol',
			),
			array(
				'label' => 'GPT-5.6 Luna',
				'value' => 'gpt-5.6-luna',
			),
		),
		'deepseek' => array(
			array(
				'label' => 'DeepSeek V4 Flash',
				'value' => 'deepseek-v4-flash',
			),
			array(
				'label' => 'DeepSeek V4 Pro',
				'value' => 'deepseek-v4-pro',
			),
		),
		'gemini'   => array(
			array(
				'label' => 'Gemini 2.5 Flash',
				'value' => 'gemini-2.5-flash',
			),
			array(
				'label' => 'Gemini 2.5 Flash Lite',
				'value' => 'gemini-2.5-flash-lite',
			),
			array(
				'label' => 'Gemini 2.5 Pro',
				'value' => 'gemini-2.5-pro',
			),
		),
		'mistral'  => array(
			array(
				'label' => 'Mistral Small',
				'value' => 'mistral-small-latest',
			),
			array(
				'label' => 'Mistral Large',
				'value' => 'mistral-large-latest',
			),
			array(
				'label' => 'Pixtral Large',
				'value' => 'pixtral-large-latest',
			),
		),
		'claude'   => array(
			array(
				'label' => 'Claude Sonnet 4',
				'value' => 'claude-sonnet-4-20250514',
			),
			array(
				'label' => 'Claude 3.5 Haiku',
				'value' => 'claude-3-5-haiku-20241022',
			),
		),
	);
}

/**
 * Enqueue Classic Editor assets for AI Assistant.
 *
 * @since 9.6.0
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function seopress_ai_assistant_classic_editor_assets( $hook = '' ) {
	if ( ! seopress_ai_assistant_is_enabled() ) {
		return;
	}

	// Hide the AI Assistant for roles not allowed to use AI (Advanced > Security).
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'edit_posts', 'chat' ) ) {
		return;
	}

	// Only load on post editing screens.
	global $pagenow;
	if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		return; // Gutenberg handles its own assets.
	}

	// Don't enqueue twice.
	if ( wp_script_is( 'seopress-ai-assistant', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_script(
		'seopress-ai-assistant',
		SEOPRESS_PRO_PLUGIN_DIR_URL . 'assets/js/seopress-ai-assistant.js',
		array( 'jquery', 'wp-api-fetch' ),
		SEOPRESS_PRO_VERSION,
		true
	);

	wp_enqueue_style(
		'seopress-ai-assistant',
		SEOPRESS_PRO_PLUGIN_DIR_URL . 'assets/css/seopress-ai-assistant.css',
		array(),
		SEOPRESS_PRO_VERSION
	);

	wp_localize_script(
		'seopress-ai-assistant',
		'seopressAIAssistant',
		array(
			'restUrl' => rest_url( 'seopress/v1/ai/chat' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'context' => 'classic-editor',
			'postId'  => get_the_ID(),
			'i18n'    => array(
				'title'          => __( 'AI Assistant', 'wp-seopress-pro' ),
				'placeholder'    => __( 'Ask about SEO...', 'wp-seopress-pro' ),
				'send'           => __( 'Send', 'wp-seopress-pro' ),
				'clear'          => __( 'Clear', 'wp-seopress-pro' ),
				'close'          => __( 'Close', 'wp-seopress-pro' ),
				'welcome'        => seopress_ai_assistant_get_welcome_message(),
				'error'          => __( 'Sorry, an error occurred. Please try again.', 'wp-seopress-pro' ),
				'includeContext' => __( 'Include post context', 'wp-seopress-pro' ),
				'thinking'       => __( 'Thinking...', 'wp-seopress-pro' ),
				'you'            => __( 'You', 'wp-seopress-pro' ),
				'assistant'      => __( 'AI Assistant', 'wp-seopress-pro' ),
				'copy'           => __( 'Copy', 'wp-seopress-pro' ),
				'quickActions'   => __( 'Quick actions:', 'wp-seopress-pro' ),
				'quickOutline'   => __( 'Write an article outline', 'wp-seopress-pro' ),
				'quickTitles'    => __( 'Suggest titles', 'wp-seopress-pro' ),
				'quickAnalyze'   => __( 'Analyze my post', 'wp-seopress-pro' ),
			),
		)
	);
}
// Load on both hooks to ensure it works for all post types including WooCommerce.
add_action( 'seopress_seo_metabox_init', 'seopress_ai_assistant_classic_editor_assets' );
add_action( 'admin_enqueue_scripts', 'seopress_ai_assistant_classic_editor_assets' );

/**
 * Enqueue Settings page assets for AI Assistant.
 *
 * @since 9.6.0
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function seopress_ai_assistant_settings_assets( $hook ) {
	if ( ! seopress_ai_assistant_is_enabled() ) {
		return;
	}

	if ( ! function_exists( 'is_seopress_page' ) || ! is_seopress_page() ) {
		return;
	}

	wp_enqueue_script(
		'seopress-ai-assistant',
		SEOPRESS_PRO_PLUGIN_DIR_URL . 'assets/js/seopress-ai-assistant.js',
		array( 'jquery', 'wp-api-fetch' ),
		SEOPRESS_PRO_VERSION,
		true
	);

	wp_enqueue_style(
		'seopress-ai-assistant',
		SEOPRESS_PRO_PLUGIN_DIR_URL . 'assets/css/seopress-ai-assistant.css',
		array(),
		SEOPRESS_PRO_VERSION
	);

	wp_localize_script(
		'seopress-ai-assistant',
		'seopressAIAssistant',
		array(
			'restUrl' => rest_url( 'seopress/v1/ai/chat' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'context' => 'settings',
			'i18n'    => array(
				'title'       => __( 'AI Assistant', 'wp-seopress-pro' ),
				'placeholder' => __( 'Ask about SEO...', 'wp-seopress-pro' ),
				'send'        => __( 'Send', 'wp-seopress-pro' ),
				'clear'       => __( 'Clear', 'wp-seopress-pro' ),
				'close'       => __( 'Close', 'wp-seopress-pro' ),
				'welcome'     => seopress_ai_assistant_get_welcome_message(),
				'error'       => __( 'Sorry, an error occurred. Please try again.', 'wp-seopress-pro' ),
				'thinking'    => __( 'Thinking...', 'wp-seopress-pro' ),
				'you'         => __( 'You', 'wp-seopress-pro' ),
				'assistant'   => __( 'AI Assistant', 'wp-seopress-pro' ),
				'copy'        => __( 'Copy', 'wp-seopress-pro' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'seopress_ai_assistant_settings_assets' );

/**
 * Add AI Assistant button to admin bar for Classic Editor.
 *
 * @since 9.6.0
 *
 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
 * @return void
 */
function seopress_ai_assistant_admin_bar( $admin_bar ) {
	if ( ! seopress_ai_assistant_is_enabled() ) {
		return;
	}

	global $pagenow;
	if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		return;
	}

	$admin_bar->add_node(
		array(
			'id'    => 'seopress-ai-assistant',
			'title' => '<span class="ab-icon dashicons dashicons-format-chat" style="margin-top: 2px;"></span><span class="ab-label">' . esc_html__( 'AI Assistant', 'wp-seopress-pro' ) . '</span>',
			'href'  => '#',
			'meta'  => array(
				'class' => 'seopress-ai-assistant-trigger',
				'title' => __( 'Open AI Assistant', 'wp-seopress-pro' ),
			),
		)
	);
}
add_action( 'admin_bar_menu', 'seopress_ai_assistant_admin_bar', 100 );

/**
 * Add AI Assistant button to SEOPress header.
 *
 * @since 9.6.0
 *
 * @return void
 */
function seopress_ai_assistant_header_button() {
	if ( ! seopress_ai_assistant_is_enabled() ) {
		return;
	}

	if ( ! function_exists( 'is_seopress_page' ) || ! is_seopress_page() ) {
		return;
	}
	?>
	<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function() {
			var tablist = document.querySelector('.seopress-activity-panel-tabs');
			if (tablist && !document.querySelector('.seopress-ai-assistant-header-btn')) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'seopress-ai-assistant-trigger seopress-ai-assistant-header-btn btn';
				btn.title = '<?php echo esc_js( __( 'AI Assistant', 'wp-seopress-pro' ) ); ?>';
				btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#1e1e1e" stroke-width="1.5"><path d="M12 3C7.03 3 3 6.58 3 11c0 2.07.87 3.96 2.31 5.37L4 21l4.63-1.31C9.67 19.89 10.81 20 12 20c4.97 0 9-3.58 9-8s-4.03-8-9-8z"/><circle cx="8" cy="11" r="1" fill="#1e1e1e" stroke="none"/><circle cx="12" cy="11" r="1" fill="#1e1e1e" stroke="none"/><circle cx="16" cy="11" r="1" fill="#1e1e1e" stroke="none"/></svg>';
				tablist.appendChild(btn);
			}
		});
	</script>
	<?php
}
add_action( 'admin_footer', 'seopress_ai_assistant_header_button' );
