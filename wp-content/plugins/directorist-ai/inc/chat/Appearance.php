<?php
/**
 * Display-only chat appearance and its bounded REST administration surface.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Chat;

/** Appearance values never enter the runtime capability or tool policy. */
final class Appearance {
	private const OPTION      = 'directorist_ai_pro_appearance';
	private const COLORS      = array( 'accent_color', 'accent_text_color', 'background_color', 'text_color', 'bubble_color', 'card_background_color', 'card_border_color', 'launcher_background_color', 'launcher_text_color', 'launcher_hover_color', 'button_background_color', 'button_text_color', 'button_hover_color', 'secondary_background_color', 'secondary_text_color', 'secondary_hover_color', 'header_background_color', 'header_text_color', 'assistant_background_color', 'assistant_text_color', 'user_text_color', 'input_background_color', 'input_text_color', 'input_border_color', 'focus_color' );
	private const BACKGROUNDS = array(
		'accent_color'          => 'accent_gradient',
		'background_color'      => 'background_gradient',
		'bubble_color'          => 'bubble_gradient',
		'card_background_color' => 'card_background_gradient'
	);
	private const NUMBERS     = array(
		'border_radius'            => array( 0, 32 ),
		'max_width'                => array( 280, 900 ),
		'font_size'                => array( 12, 22 ),
		'line_height'              => array( 120, 180 ),
		'spacing'                  => array( 4, 24 ),
		'bubble_radius'            => array( 0, 32 ),
		'card_radius'              => array( 0, 32 ),
		'card_padding'             => array( 4, 24 ),
		'launcher_size'            => array( 44, 80 ),
		'launcher_radius'          => array( 0, 40 ),
		'launcher_offset_x'        => array( 0, 96 ),
		'launcher_offset_y'        => array( 0, 96 ),
		'launcher_mobile_offset_x' => array( 8, 32 ),
		'launcher_mobile_offset_y' => array( 8, 48 ),
		'panel_height'             => array( 360, 900 ),
		'panel_padding'            => array( 8, 32 ),
		'panel_border_width'       => array( 0, 3 ),
		'message_max_width'        => array( 60, 100 ),
		'button_radius'            => array( 0, 32 ),
		'button_padding'           => array( 8, 24 ),
		'input_radius'             => array( 0, 32 ),
		'input_max_height'         => array( 80, 240 ),
		'motion_duration'          => array( 120, 500 ),
		'identity_icon_size'       => array( 20, 44 ),
		'message_spacing'          => array( 4, 32 ),
	);
	private const STYLES      = array( 'accent_color', 'accent_text_color', 'accent_gradient', 'background_color', 'background_gradient', 'text_color', 'bubble_color', 'bubble_gradient', 'card_background_color', 'card_background_gradient', 'card_border_color', 'border_radius', 'max_width', 'font_size', 'line_height', 'spacing', 'bubble_radius', 'card_radius', 'card_padding', 'font_family', 'color_mode', 'avatar_url', 'launcher_position' );
	private const ENUMS       = array(
		'layout_mode'            => array( 'adaptive', 'compact', 'wide' ),
		'launcher_display'       => array( 'icon_label', 'icon', 'text' ),
		'launcher_icon'          => array( 'directorist', 'chat', 'assistant', 'custom' ),
		'launcher_icon_position' => array( 'before', 'after' ),
		'header_icon'            => array( 'directorist', 'chat', 'assistant', 'none' ),
		'launcher_shadow'        => array( 'subtle', 'none' ),
		'panel_shadow'           => array( 'subtle', 'none' ),
		'send_button_style'      => array( 'icon', 'text' ),
		'motion_style'           => array( 'elastic', 'fade', 'slide', 'none' ),
	);
	private const FLAGS       = array( 'header_show_avatar' );

	/** Built-in fallback usable with every theme. */
	public static function defaults(): array {
		return array(
			'display_name'             => 'Directorist AI',
			'welcome'                  => 'How can I help you find a listing?',
			'placeholder'              => 'Ask about listings…',
			'starter_questions'        => array( 'Find listings near me', 'Show popular listings' ),
			'accent_color'             => '#0b72dc',
			'background_color'         => '#ffffff',
			'text_color'               => '#151826',
			'bubble_color'             => '#f5f7fa',
			'card_background_color'    => '#ffffff',
			'card_border_color'        => '#e6e9f4',
			'border_radius'            => 16,
			'max_width'                => 720,
			'layout_mode'              => 'adaptive',
			'font_family'              => 'geist',
			'font_size'                => 14,
			'line_height'              => 150,
			'spacing'                  => 12,
			'bubble_radius'            => 14,
			'card_radius'              => 14,
			'card_padding'             => 9,
			'color_mode'               => 'light',
			'avatar_url'               => '',
			'launcher_size'            => 56,
			'launcher_radius'          => 28,
			'launcher_offset_x'        => 20,
			'launcher_offset_y'        => 20,
			'launcher_mobile_offset_x' => 12,
			'launcher_mobile_offset_y' => 12,
			'panel_height'             => 640,
			'panel_padding'            => 16,
			'panel_border_width'       => 0,
			'message_max_width'        => 88,
			'button_radius'            => 12,
			'button_padding'           => 12,
			'input_radius'             => 14,
			'input_max_height'         => 144,
			'motion_duration'          => 300,
			'identity_icon_size'       => 32,
			'message_spacing'          => 16,
			'launcher_display'         => 'icon_label',
			'launcher_icon'            => 'directorist',
			'launcher_icon_position'   => 'before',
			'header_icon'              => 'directorist',
			'launcher_shadow'          => 'subtle',
			'panel_shadow'             => 'subtle',
			'send_button_style'        => 'icon',
			'motion_style'             => 'elastic',
			'header_show_avatar'       => true,
			'launcher_label'           => 'Ask AI',
			'launcher_image_url'       => '',
			'launcher_position'        => 'right',
		);
	}

	/** Return the bounded fallback palette for dark mode. */
	public static function dark_defaults(): array {
		return array(
			'accent_color'          => '#8bb9ff',
			'background_color'      => '#17202a',
			'text_color'            => '#f5f7fa',
			'bubble_color'          => '#26374a',
			'card_background_color' => '#223047',
			'card_border_color'     => '#526274',
		);
	}

	/** Return only visual theme tokens permitted by the same frontend resolver. */
	public static function theme(): array {
		$theme = apply_filters( 'directorist_ai_pro_theme_appearance', array() );
		return self::clean_tokens( $theme );
	}

	/** Return only known saved structures. Older or malformed options fail to defaults. */
	public static function saved(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Resolve theme/default, global, preset, directory and instance layers in order.
	 *
	 * @param array $settings Reviewed input.
	 */
	public static function resolve( array $settings = array() ): array {
		$saved  = self::saved();
		$theme  = self::theme();
		$layers = array( $theme, self::clean_tokens( $saved['global'] ?? array() ) );
		$preset = $settings['preset_id'] ?? '';
		if ( is_string( $preset ) && isset( $saved['presets'][ $preset ] ) ) {
			$layers[] = self::clean_tokens( $saved['presets'][ $preset ] );
		}
		$directory = $settings['directory_id'] ?? '';
		if ( is_string( $directory ) && isset( $saved['directories'][ $directory ] ) ) {
			$layers[] = self::clean_tokens( $saved['directories'][ $directory ] );
		}
		$layers[] = self::clean_tokens( $settings['style'] ?? array(), true );
		$value    = self::defaults();
		$light    = array_intersect_key( $value, self::dark_defaults() );
		foreach ( $layers as $layer ) {
			$palette = array();
			if ( isset( $layer['color_mode'] ) && $layer['color_mode'] !== $value['color_mode'] ) {
				$palette = 'dark' === $layer['color_mode'] ? self::dark_defaults() : $light;
			}
			$value = array_merge( $value, $palette, $layer );
			foreach ( self::BACKGROUNDS as $color => $gradient ) {
				if ( ( array_key_exists( $color, $palette ) || array_key_exists( $color, $layer ) ) && ! array_key_exists( $gradient, $layer ) ) {
					$value[ $gradient ] = '';
				}
			}
		}
		return $value;
	}

	/**
	 * Whitelist display tokens; invalid values disappear without influencing other policy.
	 *
	 * @param mixed $tokens Reviewed input.
	 * @param bool  $style_only Reviewed input.
	 */
	public static function clean_tokens( $tokens, bool $style_only = false ): array {
		if ( ! is_array( $tokens ) ) {
			return array();
		}
		$result = array();
		foreach ( self::COLORS as $key ) {
			if ( isset( $tokens[ $key ] ) && is_string( $tokens[ $key ] ) ) {
				$color = self::safe_color( $tokens[ $key ] );
				if ( null !== $color ) {
					$result[ $key ] = $color;
				}
			}
		}
		foreach ( self::BACKGROUNDS as $key ) {
			if ( isset( $tokens[ $key ] ) && is_string( $tokens[ $key ] ) ) {
				$gradient = '' === $tokens[ $key ] ? '' : self::safe_gradient( $tokens[ $key ] );
				if ( null !== $gradient ) {
					$result[ $key ] = $gradient;
				}
			}
		}
		foreach ( self::NUMBERS as $key => $limits ) {
			if ( isset( $tokens[ $key ] ) && is_int( $tokens[ $key ] ) && $tokens[ $key ] >= $limits[0] && $tokens[ $key ] <= $limits[1] ) {
				$result[ $key ] = $tokens[ $key ];
			}
		}
		if ( isset( $tokens['font_family'] ) && in_array( $tokens['font_family'], array( 'geist', 'system', 'sans', 'serif', 'mono' ), true ) ) {
			$result['font_family'] = $tokens['font_family'];
		}
		if ( isset( $tokens['color_mode'] ) && in_array( $tokens['color_mode'], array( 'light', 'dark' ), true ) ) {
			$result['color_mode'] = $tokens['color_mode'];
		}
		foreach ( array( 'avatar_url', 'launcher_image_url' ) as $image_key ) {
			if ( isset( $tokens[ $image_key ] ) && is_string( $tokens[ $image_key ] ) ) {
				$url = $tokens[ $image_key ];
				if ( '' === $url ) {
					$result[ $image_key ] = '';
				} elseif ( strlen( $url ) <= 512 && ! preg_match( '/[\\x00-\\x20\\x7f\\\\<>]/', $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$parts = wp_parse_url( $url );
					if ( is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) ) {
						$result[ $image_key ] = esc_url_raw( $url, array( 'http', 'https' ) );
					}
				}
			}
		}
		if ( isset( $tokens['launcher_position'] ) && in_array( $tokens['launcher_position'], array( 'left', 'right' ), true ) ) {
			$result['launcher_position'] = $tokens['launcher_position'];
		}
		foreach ( self::ENUMS as $key => $values ) {
			if ( isset( $tokens[ $key ] ) && in_array( $tokens[ $key ], $values, true ) ) {
				$result[ $key ] = $tokens[ $key ];
			}
		}
		foreach ( self::FLAGS as $key ) {
			if ( isset( $tokens[ $key ] ) && is_bool( $tokens[ $key ] ) ) {
				$result[ $key ] = $tokens[ $key ];
			}
		}
		if ( $style_only ) {
			return $result;
		}
		foreach ( array(
			'display_name'   => 80,
			'launcher_label' => 80,
			'welcome'        => 300,
			'placeholder'    => 120
		) as $key => $limit ) {
			if ( isset( $tokens[ $key ] ) && is_string( $tokens[ $key ] ) && strlen( $tokens[ $key ] ) <= $limit ) {
				$result[ $key ] = sanitize_text_field( $tokens[ $key ] );
			}
		}
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Retain reviewed PHP tokens while validation remains in the surrounding code.
		if ( isset( $tokens['starter_questions'] ) && is_array( $tokens['starter_questions'] ) && count( $tokens['starter_questions'] ) <= 4 && $tokens['starter_questions'] === array_values( $tokens['starter_questions'] ) ) {
			$starters = array();
			foreach ( $tokens['starter_questions'] as $starter ) {
				if ( ! is_string( $starter ) || strlen( $starter ) > 120 ) {
					return $result;
				}
				$starters[] = sanitize_text_field( $starter );
			}
			$result['starter_questions'] = $starters;
		}
		return $result;
	}

	/** Literal picker colors cannot contain CSS references or declarations.
	 *
	 * @param string $value Candidate color.
	 */
	private static function safe_color( string $value ): ?string {
		if ( strlen( $value ) > 100 ) {
			return null;
		}
		$color = strtolower( trim( $value ) );
		if ( preg_match( '/^#[0-9a-f]{3}$/D', $color ) ) {
			return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		if ( preg_match( '/^#(?:[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/D', $color ) || 'transparent' === $color ) {
			return $color;
		}
		if ( ! preg_match( '/^(rgb|rgba|hsl|hsla)\(([^()]+)\)$/D', $color, $match ) ) {
			return null;
		}
		$values = array_map( 'trim', explode( ',', $match[2] ) );
		if ( count( $values ) !== ( str_ends_with( $match[1], 'a' ) ? 4 : 3 ) ) {
			return null;
		}
		foreach ( $values as $index => $part ) {
			if ( ! preg_match( '/^\d+(?:\.\d+)?%?$/D', $part ) ) {
				return null;
			}
			$percent = str_ends_with( $part, '%' );
			$number  = (float) $part;
			if ( 3 === $index ? $percent || $number > 1 : ( str_starts_with( $match[1], 'hsl' ) ? ( 0 === $index ? $percent || $number > 360 : ! $percent || $number > 100 ) : $number > ( $percent ? 100 : 255 ) ) ) {
				return null;
			}
		}
		return $color;
	}

	/** Closed native gradient grammar, mirrored in the local preview.
	 *
	 * @param string $value Candidate linear or radial gradient.
	 */
	private static function safe_gradient( string $value ): ?string {
		if ( strlen( $value ) > 2000 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			return null;
		}
		$gradient = strtolower( trim( $value ) );
		if ( ! preg_match( '/^(linear|radial)-gradient\(([a-z0-9#.,%+\-() ]+)\)$/D', $gradient, $match ) ) {
			return null;
		}
		$parts = array();
		$depth = 0;
		$start = 0;
		for ( $index = 0; $index < strlen( $match[2] ); ++$index ) {
			$character = $match[2][ $index ];
			if ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
			}
			if ( $depth < 0 || $depth > 1 ) {
				return null;
			}
			if ( ',' === $character && 0 === $depth ) {
				$parts[] = trim( substr( $match[2], $start, $index - $start ) );
				$start   = $index + 1;
			}
		}
		if ( 0 !== $depth ) {
			return null;
		}
		$parts[]     = trim( substr( $match[2], $start ) );
		$orientation = $parts[0];
		if ( 'linear' === $match[1] && ( ( preg_match( '/^[+-]?\d+(?:\.\d+)?deg$/D', $orientation ) && abs( (float) $orientation ) <= 360 ) || preg_match( '/^to (?:top|bottom|left|right)(?: (?:top|bottom|left|right))?$/D', $orientation ) ) ) {
			array_shift( $parts );
		}
		if ( 'radial' === $match[1] && preg_match( '/^(?:(?:circle|ellipse)(?: (?:closest-side|closest-corner|farthest-side|farthest-corner))?|(?:closest-side|closest-corner|farthest-side|farthest-corner))(?: at (?:center|top|bottom|left|right)(?: (?:center|top|bottom|left|right))?)?$/D', $orientation ) ) {
			array_shift( $parts );
		}
		if ( count( $parts ) < 2 || count( $parts ) > 16 ) {
			return null;
		}
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^(#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\([^()]+\)|transparent)(?: +(\d+(?:\.\d+)?%))?$/D', $part, $stop ) || null === self::safe_color( $stop[1] ) || ( isset( $stop[2] ) && (float) $stop[2] > 100 ) ) {
				return null;
			}
		}
		return $gradient;
	}

	/** Theme, WordPress and user presets feed the actual Gutenberg picker. */
	public static function editor_presets(): array {
		$result = array(
			'colors'    => array(),
			'gradients' => array()
		);
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $result;
		}
		foreach ( array(
			'colors'    => 'palette',
			'gradients' => 'gradients'
		) as $kind => $setting ) {
			$origins = wp_get_global_settings( array( 'color', $setting ) );
			foreach ( array(
				'theme'   => __( 'Theme', 'directorist-ai' ),
				'default' => __( 'WordPress', 'directorist-ai' ),
				'custom'  => __( 'Custom', 'directorist-ai' )
			) as $origin => $label ) {
				$items = array();
				foreach ( array_slice( is_array( $origins[ $origin ] ?? null ) ? $origins[ $origin ] : array(), 0, 50 ) as $preset ) {
					if ( ! is_array( $preset ) || ! is_string( $preset['name'] ?? null ) || ! is_string( $preset['slug'] ?? null ) ) {
						continue;
					}
					$key   = 'colors' === $kind ? 'color' : 'gradient';
					$value = is_string( $preset[ $key ] ?? null ) ? ( 'colors' === $kind ? self::safe_color( $preset[ $key ] ) : self::safe_gradient( $preset[ $key ] ) ) : null;
					if ( null !== $value ) {
						$items[] = array(
							'name' => sanitize_text_field( substr( $preset['name'], 0, 120 ) ),
							'slug' => sanitize_key( substr( $preset['slug'], 0, 80 ) ),
							$key   => $value
						);
					}
				}
				if ( $items ) {
					$result[ $kind ][] = array(
						'name' => $label,
						$kind  => $items
					);
				}
			}
		}
		return $result;
	}

	/**
	 * Strict save: unknown fields are rejected, so access settings cannot hide here.
	 *
	 * @param array $input Reviewed input.
	 */
	public static function save( array $input ): array|\WP_Error {
		if ( array_diff( array_keys( $input ), array( 'global', 'presets', 'directories' ) ) ) {
			return new \WP_Error( 'invalid_input', 'Appearance contains unsupported fields.', array( 'status' => 400 ) );
		}
		$next = array();
		foreach ( $input as $layer => $tokens ) {
			if ( 'global' === $layer ) {
				$valid = self::validate_tokens( $tokens );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$next['global'] = $valid;
				continue;
			}
			if ( ! is_array( $tokens ) || count( $tokens ) > 50 ) {
				return new \WP_Error( 'invalid_input', 'Appearance layer is invalid.', array( 'status' => 400 ) );
			}
			$next[ $layer ] = array();
			foreach ( $tokens as $id => $values ) {
				if ( ! is_string( $id ) && ! is_int( $id ) ) {
					return new \WP_Error( 'invalid_input', 'Appearance key is invalid.', array( 'status' => 400 ) );
				}
				$id = (string) $id; // PHP converts decimal JSON object keys to integer array keys.
				if ( 'presets' === $layer ? ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $id ) : ! preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) {
					return new \WP_Error( 'invalid_input', 'Appearance key is invalid.', array( 'status' => 400 ) );
				}
				$valid = self::validate_tokens( $values );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$next[ $layer ][ $id ] = $valid;
			}
		}
		update_option( self::OPTION, $next, false );
		return $next;
	}

	/**
	 * Validate the appearance tokens.
	 *
	 * @param mixed $tokens Reviewed input.
	 */
	private static function validate_tokens( $tokens ): array|\WP_Error {
		if ( ! is_array( $tokens ) || array_diff( array_keys( $tokens ), array_merge( self::STYLES, self::COLORS, array_keys( self::NUMBERS ), array_keys( self::ENUMS ), self::FLAGS, array( 'launcher_image_url', 'launcher_label', 'display_name', 'welcome', 'placeholder', 'starter_questions' ) ) ) ) {
			return new \WP_Error( 'invalid_input', 'Appearance token is unsupported.', array( 'status' => 400 ) );
		}
		$clean = self::clean_tokens( $tokens );
		if ( count( $clean ) !== count( $tokens ) ) {
			return new \WP_Error( 'invalid_input', 'Appearance token value is invalid.', array( 'status' => 400 ) );
		}
		return $clean;
	}

	/** Admin endpoints only; sample preview is entirely local. */
	public static function register_routes(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/appearance',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static fn(): array => array(
						'saved'         => self::saved(),
						'defaults'      => self::defaults(),
						'dark_defaults' => self::dark_defaults(),
						'theme'         => self::theme(),
						'palettes'      => self::editor_presets()
					)
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static function ( \WP_REST_Request $request ) {
						$document = json_decode( $request->get_body() );
						if ( ! $document instanceof \stdClass ) {
							return new \WP_Error( 'invalid_input', 'Appearance document must be an object.', array( 'status' => 400 ) );
						}
						return self::save( json_decode( $request->get_body(), true ) );
					}
				),
			)
		);
	}
}
