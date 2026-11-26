<?php
/**
 * Popups, slide-ins and bars (Pro): a published form shown over the site's pages.
 *
 * The server decides where (page rules, logged-in visitors, access rules) and prints the
 * form hidden at the end of the page; the browser decides when (delay, scroll, leaving the
 * page, a click) and how often (after closing, after sending). Everything that depends on the
 * visitor happens in the browser, so cached pages stay correct.
 *
 * The list of popup forms is kept in one autoloaded option, rebuilt when a form is saved,
 * so pages without popups never query the forms table.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

use DoubleScale\Modules\Forms\Models\FormModel;

defined( 'ABSPATH' ) || exit;

/**
 * Site popups.
 */
final class Popups {

	/** Cached list: form id => page rules. */
	private const OPTION = 'doublescale_forms_popups';

	/** Popup types. */
	public const TYPES = array( 'modal', 'slide_in', 'bar' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_footer', array( self::class, 'print_popups' ), 5 );
	}

	/**
	 * Forget the cached list (a form was saved or deleted).
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Defaults of the popup settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => false,
			'type'           => 'modal',
			'position'       => 'bottom_right',
			'trigger'        => 'delay',
			'delay'          => 5,
			'scroll'         => 50,
			'pages'          => 'all',
			'paths'          => '',
			'devices'        => 'all',
			'again_days'     => 7,
			'hide_sent'      => true,
			'hide_logged_in' => false,
			'bar_text'       => __( 'Have a question? We reply within a day.', 'doublescale' ),
			'bar_button'     => __( 'Get in touch', 'doublescale' ),
		);
	}

	/**
	 * Clean popup settings.
	 *
	 * @param mixed $raw Raw settings.
	 * @return array
	 */
	public static function normalize( $raw ): array {
		$popup = array_merge( self::defaults(), is_array( $raw ) ? $raw : array() );
		$type  = in_array( $popup['type'], self::TYPES, true ) ? $popup['type'] : 'modal';

		$positions = 'bar' === $type ? array( 'bottom', 'top' ) : array( 'bottom_right', 'bottom_left' );
		$position  = in_array( $popup['position'], $positions, true ) ? $popup['position'] : $positions[0];

		$paths = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $popup['paths'] ) as $line ) {
			$line = self::clean_path( $line );
			if ( '' !== $line && count( $paths ) < 50 ) {
				$paths[] = $line;
			}
		}

		return array(
			'enabled'        => ! empty( $popup['enabled'] ),
			'type'           => $type,
			'position'       => $position,
			'trigger'        => in_array( $popup['trigger'], array( 'delay', 'scroll', 'exit', 'click' ), true ) ? $popup['trigger'] : 'delay',
			'delay'          => max( 0, min( 600, (int) $popup['delay'] ) ),
			'scroll'         => max( 5, min( 100, (int) $popup['scroll'] ) ),
			'pages'          => in_array( $popup['pages'], array( 'all', 'include', 'exclude' ), true ) ? $popup['pages'] : 'all',
			'paths'          => implode( "\n", $paths ),
			'devices'        => in_array( $popup['devices'], array( 'all', 'desktop', 'mobile' ), true ) ? $popup['devices'] : 'all',
			// 0 = on every page view, -1 = never again once closed.
			'again_days'     => max( -1, min( 365, (int) $popup['again_days'] ) ),
			'hide_sent'      => ! empty( $popup['hide_sent'] ),
			'hide_logged_in' => ! empty( $popup['hide_logged_in'] ),
			'bar_text'       => sanitize_text_field( (string) $popup['bar_text'] ),
			'bar_button'     => sanitize_text_field( (string) $popup['bar_button'] ),
		);
	}

	/**
	 * Print the popups that belong on this page.
	 *
	 * @return void
	 */
	public static function print_popups(): void {
		if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || ! Schema::pro_active() || ! doublescale_module_data_ready( 'forms' ) ) {
			return;
		}
		// The form's own page shows the form already; managers' previews stay clean.
		if ( '' !== (string) get_query_var( Renderer::QUERY_VAR ) ) {
			return;
		}

		$path = self::request_path();
		foreach ( self::forms() as $id => $rules ) {
			if ( ! self::page_matches( $rules, $path ) ) {
				continue;
			}
			if ( ! empty( $rules['hide_logged_in'] ) && is_user_logged_in() ) {
				continue;
			}
			$form = FormModel::find( (int) $id );
			if ( ! $form || ! $form->is_native() || 'active' !== $form->status ) {
				continue;
			}
			// Closed, full or members-only forms don't pop up at all.
			if ( ! Access::state( $form )['open'] ) {
				continue;
			}
			echo self::markup( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
	}

	/**
	 * The hidden popup around a form.
	 *
	 * @param FormModel $form Form.
	 * @return string
	 */
	public static function markup( FormModel $form ): string {
		$settings = Schema::normalize_settings( $form->settings );
		$popup    = $settings['popup'];
		$style    = Schema::normalize_style( $form->style );
		$config   = array(
			'hash'      => (string) $form->hash,
			'slug'      => (string) $form->slug,
			'type'      => $popup['type'],
			'trigger'   => $popup['trigger'],
			'delay'     => $popup['delay'],
			'scroll'    => $popup['scroll'],
			'devices'   => $popup['devices'],
			'againDays' => $popup['again_days'],
			'hideSent'  => $popup['hide_sent'],
		);
		$width    = 'slide_in' === $popup['type'] ? 420 : min( 720, (int) $settings['page']['width'] );
		$label    = '' !== $settings['page']['title'] ? $settings['page']['title'] : (string) $form->name;
		$close    = '<button type="button" class="dsf-popup__close" data-dsf-close aria-label="' . esc_attr__( 'Close', 'doublescale' ) . '">'
			. '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
			. '</button>';

		$dialog = '<div class="dsf-popup__dialog" role="dialog" aria-modal="true" aria-label="' . esc_attr( $label ) . '" tabindex="-1" style="' . esc_attr( '--dsf-popup-width:' . $width . 'px;--dsf-popup-bg:' . $style['background'] ) . '">'
			. $close
			. '<div class="dsf-popup__body">' . Renderer::markup( $form, 'popup' ) . '</div>'
			. '</div>';

		$classes = 'dsf-popup dsf-popup--' . ( 'bar' === $popup['type'] ? 'modal' : $popup['type'] ) . ( 'slide_in' === $popup['type'] ? ' dsf-popup--' . $popup['position'] : '' );
		$out     = '<div class="' . esc_attr( $classes ) . '" data-dsf-popup="' . esc_attr( (string) wp_json_encode( $config ) ) . '" hidden>'
			. ( 'slide_in' === $popup['type'] ? '' : '<div class="dsf-popup__backdrop" data-dsf-close></div>' )
			. $dialog
			. '</div>';

		if ( 'bar' === $popup['type'] ) {
			$out .= '<div class="dsf-bar dsf-bar--' . esc_attr( $popup['position'] ) . '" data-dsf-bar="' . esc_attr( (string) $form->hash ) . '" style="' . esc_attr( '--dsf-primary:' . $style['primary'] . ';--dsf-btn-text:' . $style['button_text_color'] ) . '" role="region" aria-label="' . esc_attr( $label ) . '" hidden>'
				. '<p class="dsf-bar__text">' . esc_html( $popup['bar_text'] ) . '</p>'
				. '<button type="button" class="dsf-bar__button" data-dsf-open="' . esc_attr( (string) $form->hash ) . '">' . esc_html( '' !== $popup['bar_button'] ? $popup['bar_button'] : __( 'Open', 'doublescale' ) ) . '</button>'
				. '<button type="button" class="dsf-bar__close" data-dsf-bar-close aria-label="' . esc_attr__( 'Close', 'doublescale' ) . '">'
				. '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
				. '</button>'
				. '</div>';
		}

		return $out;
	}

	/**
	 * Popup forms and their page rules (cached).
	 *
	 * @return array<int, array>
	 */
	public static function forms(): array {
		$cached = get_option( self::OPTION, null );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$list = array();
		foreach ( FormModel::where( 'form_type', FormModel::NATIVE_TYPE )->where( 'status', 'active' )->get( array( 'id', 'settings' ) ) as $form ) {
			$popup = self::normalize( ( (array) $form->settings )['popup'] ?? array() );
			if ( $popup['enabled'] ) {
				$list[ (int) $form->id ] = array(
					'pages'          => $popup['pages'],
					'paths'          => '' !== $popup['paths'] ? explode( "\n", $popup['paths'] ) : array(),
					'hide_logged_in' => $popup['hide_logged_in'],
				);
			}
		}
		update_option( self::OPTION, $list, true );
		return $list;
	}

	/**
	 * Whether a page path passes a popup's page rules.
	 *
	 * @param array  $rules Rules from forms().
	 * @param string $path  Request path, like /pricing/.
	 * @return bool
	 */
	public static function page_matches( array $rules, string $path ): bool {
		$mode = (string) ( $rules['pages'] ?? 'all' );
		if ( 'all' === $mode ) {
			return true;
		}
		if ( empty( $rules['paths'] ) ) {
			// "Only these pages" with none listed shows nowhere; "all except" none shows everywhere.
			return 'exclude' === $mode;
		}
		$hit = false;
		foreach ( (array) $rules['paths'] as $pattern ) {
			if ( self::path_matches( (string) $pattern, $path ) ) {
				$hit = true;
				break;
			}
		}
		return 'include' === $mode ? $hit : ! $hit;
	}

	/**
	 * Whether a path matches a pattern: "/pricing/" exactly, "/blog/*" for a section, "/" the home page.
	 *
	 * @param string $pattern Pattern.
	 * @param string $path    Path.
	 * @return bool
	 */
	public static function path_matches( string $pattern, string $path ): bool {
		$pattern = self::clean_path( $pattern );
		$path    = self::clean_path( $path );
		if ( '' === $pattern ) {
			return false;
		}
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
		return (bool) preg_match( $regex, $path );
	}

	/**
	 * The path of this request, relative to the site (sub-folder installs and /index.php/ permalinks included).
	 *
	 * @return string
	 */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		if ( 0 === strpos( $path, '/index.php' ) ) {
			$path = substr( $path, strlen( '/index.php' ) );
		}
		return self::clean_path( $path );
	}

	/**
	 * A path with a leading and trailing slash (unless it ends in a wildcard), no host or query.
	 *
	 * @param string $path Raw.
	 * @return string
	 */
	private static function clean_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $path ) ) {
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		}
		$path = (string) preg_replace( '#[?\#].*$#', '', $path );
		$path = (string) preg_replace( '#[^A-Za-z0-9/_\-.*%~]#', '', $path );
		$path = '/' . ltrim( $path, '/' );
		if ( '*' !== substr( $path, -1 ) ) {
			$path = rtrim( $path, '/' ) . '/';
		}
		return (string) preg_replace( '#/+#', '/', $path );
	}
}
