<?php
/**
 * Shared shortcode, block and builder placement for the public chat.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Capabilities\GuestSessions;

/** All placement surfaces use one renderer and the visitor runtime. */
final class Placement {
	private const COOKIE = 'directorist_ai_guest';
	/**
	 * Count rendered mount instances.
	 *
	 * @var int
	 */
	private static int $mount_number = 0;
	/**
	 * A valid page launcher replaces the automatic footer fallback for this request.
	 *
	 * @var bool
	 */
	private static bool $launcher_rendered = false;

	/** Register native entry points; no model request occurs during render. */
	public static function hooks(): void {
		BrowserSnapshot::hooks();
		add_shortcode( 'directorist_ai_chat', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_assets' ), 0 );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'wp_footer', array( self::class, 'launcher' ) );
		add_action( 'wp_logout', array( self::class, 'forget_guest_session' ) );
	}

	/** Reset the plugin's guest browser identity on native logout. */
	public static function forget_guest_session(): void {
		setcookie(
			self::COOKIE,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => '' !== COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict'
			)
		);
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/** Make the shared bundle available before native editors resolve widget dependencies. */
	public static function register_assets(): void {
		$path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		if ( ! file_exists( $path . 'chat.js' ) || ! file_exists( $path . 'chat.asset.php' ) ) {
			return;
		}
		$asset = require $path . 'chat.asset.php';
		if ( ! is_array( $asset ) || ! is_array( $asset['dependencies'] ?? null ) || ! is_string( $asset['version'] ?? null ) ) {
			return;
		}
		$url = plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		if ( ! wp_script_is( 'directorist-ai-pro-chat', 'registered' ) ) {
			wp_register_script( 'directorist-ai-pro-chat', $url . 'chat.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'directorist-ai-pro-chat', 'directorist-ai', plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'languages' );
		}
		if ( file_exists( $path . 'chat.css' ) && ! wp_style_is( 'directorist-ai-pro-chat', 'registered' ) ) {
			wp_register_style( 'directorist-ai-pro-chat', $url . 'chat.css', array(), $asset['version'] );
		}
	}

	/**
	 * Handle shortcode attributes using the same strict placement boundary.
	 *
	 * @param mixed $attributes Reviewed input.
	 */
	public static function shortcode( $attributes = array() ): string {
		if ( ! is_array( $attributes ) ) {
			return self::unavailable( 'Invalid chat placement.' );
		}
		return self::render( $attributes );
	}

	/** Server-rendered Gutenberg block with stable serialized visual settings. */
	public static function register_block(): void {
		$path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'inc/chat/block.js';
		wp_register_script( 'directorist-ai-pro-chat-block', plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'inc/chat/block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor' ), (string) filemtime( $path ), true );
		register_block_type(
			'directorist-ai/chat',
			array(
				'editor_script'   => 'directorist-ai-pro-chat-block',
				'render_callback' => static function ( array $attributes ): string {
					return self::render( $attributes ); },
				'attributes'      => array(
					'preset_id'    => array(
						'type'    => 'string',
						'default' => ''
					),
					'directory_id' => array(
						'type'    => 'string',
						'default' => ''
					),
					'mode'         => array(
						'type'    => 'string',
						'default' => 'inline'
					),
				),
			)
		);
	}

	/**
	 * Mount a fresh, isolated visitor interface. `instance_id` is only an editor hint;
	 * repeated native blocks still receive distinct DOM identities.
	 *
	 * @param array $settings Reviewed input.
	 */
	public static function render( array $settings = array() ): string {
		if ( array_diff( array_keys( $settings ), array( 'preset_id', 'directory_id', 'mode', 'instance_id', 'style' ) ) ) {
			return self::unavailable( 'Invalid chat placement settings.' );
		}
		$preset    = $settings['preset_id'] ?? '';
		$directory = $settings['directory_id'] ?? '';
		$mode      = $settings['mode'] ?? 'inline';
		$instance  = $settings['instance_id'] ?? '';
		if ( ! is_string( $preset ) || ( '' !== $preset && ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $preset ) )
			|| ! is_string( $directory ) || ( '' !== $directory && ! preg_match( '/^[1-9][0-9]{0,18}$/D', $directory ) )
			|| ! in_array( $mode, array( 'inline', 'launcher' ), true )
			|| ! is_string( $instance ) || ( '' !== $instance && ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $instance ) )
			|| ( isset( $settings['style'] ) && ( ! is_array( $settings['style'] ) || count( Appearance::clean_tokens( $settings['style'], true ) ) !== count( $settings['style'] ) ) ) ) {
			return self::unavailable( 'Invalid chat placement settings.' );
		}
		if ( ! self::available() ) {
			return self::unavailable( 'AI chat is currently unavailable.' );
		}
		$directories = Operations::public_directories();
		if ( ! $directories || ( '' !== $directory && ! in_array( $directory, array_column( $directories, 'id' ), true ) ) ) {
			return self::unavailable( 'This chat directory is unavailable.' );
		}
		$page_context = self::page_context( $directories );
		self::register_assets();
		if ( ! wp_script_is( 'directorist-ai-pro-chat', 'registered' ) ) {
			return self::unavailable( 'AI chat assets are unavailable.' );
		}
		wp_enqueue_script( 'directorist-ai-pro-chat' );
		if ( wp_style_is( 'directorist-ai-pro-chat', 'registered' ) ) {
			wp_enqueue_style( 'directorist-ai-pro-chat' );
		}
		$appearance            = Appearance::resolve( array_merge( $settings, array( 'directory_id' => $directory ) ) );
		$id                    = 'directorist-ai-chat-' . ++self::$mount_number . '-' . wp_unique_id();
		$directory_appearances = array();
		foreach ( $directories as $choice ) {
			$directory_appearances[ $choice['id'] ] = Appearance::resolve( array_merge( $settings, array( 'directory_id' => $choice['id'] ) ) );
		}
		$config    = array(
			'language'             => \DirectoristAIPro\AI\LanguagePolicy::configuration(),
			'apiPath'              => esc_url_raw( rest_url( 'directorist-ai/v1' ) ),
			'directory'            => $directory,
			'directories'          => $directories,
			'catalogRevision'      => hash( 'sha256', wp_json_encode( $directories ) ),
			'globalSearch'         => '' === ( $settings['directory_id'] ?? '' ),
			'directoryLocked'      => '' !== ( $settings['directory_id'] ?? '' ),
			'mode'                 => $mode,
			'instance'             => $instance,
			'appearance'           => $appearance,
			'starters'             => StarterSuggestions::cached(),
			'starterLanguage'      => StarterSuggestions::cached_language(),
			'startersByLanguage'   => StarterSuggestions::cached_by_language(),
			'directoryAppearances' => $directory_appearances,
			'nonce'                => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'pageContext'          => $page_context,
			'authenticated'        => is_user_logged_in(),
			'hasSession'           => self::has_session(),
			'userId'               => get_current_user_id(),
			'browserCache'         => BrowserSnapshot::current(),
			'updates'              => true,
			'loginUrl'             => wp_login_url( get_permalink() ? get_permalink() : home_url( '/' ) ),
		);
		$fonts     = array(
			'geist'  => '"Directorist Geist",ui-sans-serif,system-ui,sans-serif',
			'system' => 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
			'sans'   => 'Arial,Helvetica,sans-serif',
			'serif'  => 'Georgia,"Times New Roman",serif',
			'mono'   => 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
		);
		$variables = array(
			'--dai-accent'            => $appearance['accent_color'],
			'--dai-bg'                => $appearance['background_color'],
			'--dai-text'              => $appearance['text_color'],
			'--dai-bubble'            => $appearance['bubble_color'],
			'--dai-card-bg'           => $appearance['card_background_color'],
			'--dai-card-border'       => $appearance['card_border_color'],
			'--dai-accent-background' => ! empty( $appearance['accent_gradient'] ) ? $appearance['accent_gradient'] : $appearance['accent_color'],
			'--dai-bg-background'     => ! empty( $appearance['background_gradient'] ) ? $appearance['background_gradient'] : $appearance['background_color'],
			'--dai-bubble-background' => ! empty( $appearance['bubble_gradient'] ) ? $appearance['bubble_gradient'] : $appearance['bubble_color'],
			'--dai-card-background'   => ! empty( $appearance['card_background_gradient'] ) ? $appearance['card_background_gradient'] : $appearance['card_background_color'],
			'--dai-radius'            => $appearance['border_radius'] . 'px',
			'--dai-width'             => $appearance['max_width'] . 'px',
			'--dai-font'              => $fonts[ $appearance['font_family'] ],
			'--dai-font-size'         => $appearance['font_size'] . 'px',
			'--dai-line-height'       => number_format( $appearance['line_height'] / 100, 2, '.', '' ),
			'--dai-spacing'           => $appearance['spacing'] . 'px',
			'--dai-bubble-radius'     => $appearance['bubble_radius'] . 'px',
			'--dai-card-radius'       => $appearance['card_radius'] . 'px',
			'--dai-card-padding'      => $appearance['card_padding'] . 'px',
			'--dai-on-accent'         => $appearance['accent_text_color'] ?? self::accent_foreground( $appearance['accent_color'] ),
		);
		// All added appearance values have passed the closed visual-token validator.
		foreach ( array( 'launcher_size', 'launcher_radius', 'launcher_offset_x', 'launcher_offset_y', 'launcher_mobile_offset_x', 'launcher_mobile_offset_y', 'panel_height', 'panel_padding', 'panel_border_width', 'button_radius', 'button_padding', 'input_radius', 'input_max_height', 'identity_icon_size', 'message_spacing' ) as $token ) {
			$variables[ '--dai-' . str_replace( '_', '-', $token ) ] = $appearance[ $token ] . 'px';
		}
		$variables['--dai-motion-duration']   = $appearance['motion_duration'] . 'ms';
		$variables['--dai-message-max-width'] = $appearance['message_max_width'] . '%';
		foreach ( array(
			'launcher_background_color'  => 'launcher-bg',
			'launcher_text_color'        => 'launcher-text',
			'launcher_hover_color'       => 'launcher-hover',
			'button_background_color'    => 'button-bg',
			'button_text_color'          => 'button-text',
			'button_hover_color'         => 'button-hover',
			'secondary_background_color' => 'secondary-bg',
			'secondary_text_color'       => 'secondary-text',
			'secondary_hover_color'      => 'secondary-hover',
			'header_background_color'    => 'header-bg',
			'header_text_color'          => 'header-text',
			'assistant_background_color' => 'assistant-bg',
			'assistant_text_color'       => 'assistant-text',
			'user_text_color'            => 'user-text',
			'input_background_color'     => 'input-bg',
			'input_text_color'           => 'input-text',
			'input_border_color'         => 'input-border',
			'focus_color'                => 'focus'
		) as $token => $variable ) {
			if ( isset( $appearance[ $token ] ) ) {
				$variables[ '--dai-' . $variable ] = $appearance[ $token ];
			}
		}

		$style = '';
		foreach ( $variables as $name => $value ) {
			$style .= $name . ':' . $value . ';';
		}
		if ( 'launcher' === $mode ) {
			self::$launcher_rendered = true;
		}
		return '<div id="' . esc_attr( $id ) . '" class="directorist-ai-pro-chat" data-mode="' . esc_attr( $mode ) . '" data-color-mode="' . esc_attr( $appearance['color_mode'] ) . '" data-launcher-position="' . esc_attr( $appearance['launcher_position'] ) . '" data-directorist-ai-chat="' . esc_attr( wp_json_encode( $config ) ) . '" style="' . esc_attr( $style ) . '"></div>';
	}

	/** Report session availability without exposing a guest credential or issuing one. */
	private static function has_session(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		try {
			$token = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? $_COOKIE[ self::COOKIE ] : '';
			( new GuestSessions( wp_salt( 'auth' ), get_current_blog_id() ) )->resolve( $token );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Infer only a public native listing's directory; ambiguous pages ask the visitor.
	 *
	 * @param array $directories Currently enabled public directories.
	 */
	private static function page_context( array $directories ): array {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password || ! function_exists( 'directorist_get_listing_directory' ) ) {
			return array(
				'directory_id' => null,
				'listing_id'   => null
			);
		}
		$directory = (string) directorist_get_listing_directory( $post->ID );
		return in_array( $directory, array_column( $directories, 'id' ), true ) ? array(
			'directory_id' => $directory,
			'listing_id'   => $post->ID
		) : array(
			'directory_id' => null,
			'listing_id'   => null
		);
	}

	/**
	 * Match preview button text to the actual, validated accent background.
	 *
	 * @param string $color Validated hex accent.
	 */
	private static function accent_foreground( string $color ): string {
		if ( ! preg_match( '/^#[0-9a-f]{6}$/iD', $color ) ) {
			return '#ffffff';
		}
		$channels = array();
		foreach ( array( 1, 3, 5 ) as $offset ) {
			$channel    = hexdec( substr( $color, $offset, 2 ) ) / 255;
			$channels[] = $channel <= 0.04045 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
		}
		$luminance = $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
		return $luminance > 0.179 ? '#000000' : '#ffffff';
	}

	/** Opt-in site launcher. */
	public static function launcher(): void {
		if ( ! self::$launcher_rendered && ! is_admin() && ! is_feed() && ! self::native_editor_preview() && get_option( 'directorist_ai_pro_global_launcher', false ) && self::available() ) {
			echo self::render(
				array(
					'mode'        => 'launcher',
					'instance_id' => 'site-launcher'
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render escapes every attribute.
		}
	}

	/** Keep the automatic visitor button out of authenticated builder authoring canvases. */
	private static function native_editor_preview(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation gate, verified against native edit capability.
		$elementor = $_GET['elementor-preview'] ?? null;
		if ( did_action( 'elementor/loaded' ) && is_string( $elementor ) && preg_match( '/^[1-9][0-9]*$/D', $elementor ) && get_post( (int) $elementor ) && current_user_can( 'edit_post', (int) $elementor ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The query alone never suppresses guest chat.
		$divi    = '1' === ( $_GET['et_fb'] ?? null ) || '1' === ( $_GET['app_window'] ?? null );
		$post_id = get_queried_object_id();
		return $divi && function_exists( 'et_fb_is_enabled' ) && $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id );
	}

	/** Expose only current installation availability to a frontend placement. */
	private static function available(): bool {
		return function_exists( 'wp_supports_ai' ) && wp_supports_ai() && ! get_option( 'directorist_ai_pro_emergency_stop', false );
	}

	/**
	 * Render an unavailable status.
	 *
	 * @param string $message Reviewed input.
	 */
	private static function unavailable( string $message ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Retain reviewed PHP tokens while validation remains in the surrounding code.
		return '<p class="directorist-ai-pro-unavailable" role="status">' . esc_html__( $message, 'directorist-ai' ) . '</p>';
	}

	/** A verified anonymous identity is issued once per browser cookie. */
	public static function register_routes(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/guest-session',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $request ) {
					if ( ! self::available() ) {
						return new \WP_Error( 'provider_unavailable', 'AI chat is unavailable.', array( 'status' => 503 ) );
					}
					if ( is_user_logged_in() ) {
						return array( 'authenticated' => true );
					}
					$input = json_decode( '' !== $request->get_body() ? $request->get_body() : '{}', true );
					if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'remember' ) ) || ( array_key_exists( 'remember', $input ) && ! is_bool( $input['remember'] ) ) ) {
						return new \WP_Error( 'invalid_input', 'Choose whether to remember this chat.', array( 'status' => 400 ) );
					}
					$remember = $input['remember'] ?? true;
					$sessions = new GuestSessions( wp_salt( 'auth' ), get_current_blog_id() );
					$token = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? $_COOKIE[ self::COOKIE ] : '';
					try {
						$sessions->resolve( $token );
					} catch ( \Throwable $error ) {
						$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
						$key = 'dai_guest_issue_' . substr( hash( 'sha256', $remote . wp_salt( 'auth' ) ), 0, 32 );
						$count = (int) get_transient( $key );
						if ( $count >= 20 ) {
							return new \WP_Error( 'busy', 'Guest session limit reached. Please try later.', array( 'status' => 429 ) );
						}
						set_transient( $key, $count + 1, HOUR_IN_SECONDS );
						$token = $sessions->issue();
					}
					$actor = $sessions->resolve( $token );
					BrowserSnapshot::remember_guest( $actor, $token, $remember );
					setcookie(
						self::COOKIE,
						$token,
						array(
							'expires'  => $remember ? $actor->authorization_expires_at() : 0,
							'path'     => '' !== COOKIEPATH ? COOKIEPATH : '/',
							'secure'   => is_ssl(),
							'httponly' => true,
							'samesite' => 'Strict'
						)
					);
					return array( 'authenticated' => true );
				},
			)
		);
	}
}
