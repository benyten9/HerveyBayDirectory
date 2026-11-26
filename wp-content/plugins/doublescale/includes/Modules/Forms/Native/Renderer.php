<?php
/**
 * Where DoubleScale forms show up on the site: the [doublescale_form] shortcode, the
 * "DoubleScale Form" block, and each form's own page (example.com/forms/<slug>/).
 *
 * The form renders in the page itself (no iframe): the server prints a container with the
 * form's definition as JSON, and build/renderer/forms mounts on it.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Front-end rendering.
 */
final class Renderer {

	public const HANDLE    = 'doublescale-forms';
	public const QUERY_VAR = 'doublescale_form';
	public const PREVIEW   = 'dsf_preview';

	/** Signed token in a form link sent to a contact ({{forms:link}}): the browser asks for their details with it. */
	public const CONTACT_PARAM = 'dsf_c';

	/**
	 * Containers printed on this page (keeps ids unique when a form shows twice).
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'doublescale_form', array( self::class, 'shortcode' ) );
		// The builder shows the same default texts as the form (preview, "Other texts" placeholders).
		add_filter(
			'doublescale_admin_config',
			static function ( $config ) {
				// The builder's preview: counted texts for the numbers people usually pick.
				$config['formTexts'] = Texts::for_browser( range( 0, 30 ) );
				// Features the builder offers (unfinished entries are off for now).
				$config['formFeatures'] = array( 'partial' => Partial::available() );
				return $config;
			}
		);
		Popups::init();
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( Access::class, 'maybe_unlock' ), 0 );
		add_action( 'template_redirect', array( self::class, 'maybe_render_page' ), 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ), 5 );
		add_action( 'wp_footer', array( self::class, 'print_touch_script' ), 99 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'register_assets' ), 5 );
		// The block's server-rendered preview needs the renderer inside the editor canvas.
		add_action(
			'enqueue_block_assets',
			static function () {
				if ( is_admin() ) {
					self::register_assets();
					wp_enqueue_script( self::HANDLE );
					if ( wp_style_is( self::HANDLE, 'registered' ) ) {
						wp_enqueue_style( self::HANDLE );
					}
				}
			}
		);
	}

	/**
	 * Register the renderer's script and style (enqueued only where a form is shown).
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$dir   = DOUBLESCALE_PLUGIN_DIR . 'build/renderer/forms/';
		$url   = DOUBLESCALE_PLUGIN_URL . 'build/renderer/forms/';
		$asset = file_exists( $dir . 'index.asset.php' ) ? require $dir . 'index.asset.php' : array();
		$ver   = $asset['version'] ?? DOUBLESCALE_VERSION;

		wp_register_script( self::HANDLE, $url . 'index.js', $asset['dependencies'] ?? array( 'wp-element' ), $ver, true );
		if ( file_exists( $dir . 'style.css' ) ) {
			wp_register_style( self::HANDLE, $url . 'style.css', array(), $ver );
		}
	}

	/**
	 * A few hundred bytes on every public page: remember where the visitor first came from
	 * (landing page without its query string, UTMs, click ids, the referring site) and the
	 * latest campaign, so a form filled pages later still knows the source. Stored in the
	 * visitor's browser only (localStorage), for 90 days; skipped when the WP Consent API says
	 * the visitor has not agreed to statistics.
	 *
	 * @return void
	 */
	public static function print_touch_script(): void {
		if ( is_admin() || ! apply_filters( 'doublescale_forms_track_first_touch', true ) ) {
			return;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
<script id="doublescale-forms-touch">(function(){try{if(typeof window.wp_has_consent==="function"&&!window.wp_has_consent("statistics"))return;var s=window.localStorage,q=new URLSearchParams(location.search),r=document.referrer,h=<?php echo wp_json_encode( (string) $host ); ?>,o={},u=0,n=Date.now(),T=7776e6,k=["source","medium","campaign","term","content"],c=["gclid","fbclid","msclkid"],g;k.forEach(function(p){var v=q.get("utm_"+p);if(v){o[p]=v.slice(0,190);u=1}});c.forEach(function(p){var v=q.get(p);if(v){o[p]=v.slice(0,255);u=1}});if(r&&r.indexOf("//"+h)<0){try{o.referrer=new URL(r).origin}catch(e){}}if(u||o.referrer){o.at=new Date(n).toISOString();s.setItem("dsf_last",JSON.stringify(o))}try{g=JSON.parse(s.getItem("dsf_touch")||"null")}catch(e){g=null}if(!g||!g.at||n-Date.parse(g.at)>T){o.landing=location.origin+location.pathname;o.at=o.at||new Date(n).toISOString();s.setItem("dsf_touch",JSON.stringify(o))}}catch(e){}})();</script>
		<?php
	}

	/**
	 * [doublescale_form id="12"] or [doublescale_form hash="k2j…"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'hash' => '',
			),
			(array) $atts,
			'doublescale_form'
		);
		$form = self::find( (int) $atts['id'], (string) $atts['hash'] );
		return $form ? self::markup( $form, 'shortcode' ) : '';
	}

	/**
	 * The "DoubleScale Form" block (rendered on the server, previewed in the editor).
	 *
	 * @return void
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'doublescale-form-block',
			DOUBLESCALE_PLUGIN_URL . 'assets/js/form-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ),
			DOUBLESCALE_VERSION,
			true
		);
		wp_localize_script(
			'doublescale-form-block',
			'doublescaleFormBlock',
			array(
				'formsUrl' => admin_url( 'admin.php?page=doublescale&path=forms' ),
			)
		);
		register_block_type(
			'doublescale/form',
			array(
				'api_version'     => 3,
				'title'           => __( 'DoubleScale Form', 'doublescale' ),
				'category'        => 'widgets',
				'icon'            => 'feedback',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'editor_script'   => 'doublescale-form-block',
				'render_callback' => static function ( $attributes ) {
					$form = self::find( (int) ( $attributes['formId'] ?? 0 ), '' );
					if ( ! $form ) {
						return '';
					}
					// The block editor's preview (rendered over REST): not counted, and clicks
					// select the block instead of filling the form.
					if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
						return '<div class="dsf-editor-preview" style="pointer-events:none" inert>' . self::markup( $form, 'preview' ) . '</div>';
					}
					return self::markup( $form, 'block' );
				},
			)
		);
	}

	/**
	 * Rewrite rule for form pages.
	 *
	 * @return void
	 */
	public static function add_rewrite_rule(): void {
		$base  = GlobalSettings::base();
		$regex = '^' . preg_quote( $base, '#' ) . '/([^/]+)/?$';
		add_rewrite_rule( $regex, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );

		// Flush once when the base changed or the rule is missing from the stored rules.
		$rules = get_option( 'rewrite_rules' );
		$stale = get_option( 'doublescale_forms_flush_rewrites' ) || ( is_array( $rules ) && ! isset( $rules[ $regex ] ) );
		if ( $stale && ! get_transient( 'doublescale_forms_rewrites_flushed' ) ) {
			set_transient( 'doublescale_forms_rewrites_flushed', 1, MINUTE_IN_SECONDS );
			delete_option( 'doublescale_forms_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Public query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render a form page (example.com/forms/<slug>/), or a manager's preview (?dsf_preview=<hash>).
	 *
	 * @return void
	 */
	public static function maybe_render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview link, manager-only.
		$preview = isset( $_GET[ self::PREVIEW ] ) ? sanitize_key( wp_unslash( $_GET[ self::PREVIEW ] ) ) : '';
		$slug    = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $preview && '' === $slug ) {
			return;
		}
		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return;
		}

		if ( '' !== $preview ) {
			if ( ! Permissions::has_crm_manager_access() ) {
				return;
			}
			$form = FormModel::find_native_by_hash( $preview );
		} else {
			$slug = sanitize_title( $slug );
			$form = FormModel::find_native_by_slug( $slug );
			if ( ! $form ) {
				// Old links keep working after a slug change.
				$form = FormModel::find_native_by_hash( sanitize_key( $slug ) );
			}
			if ( $form && 'active' !== $form->status && ! Permissions::has_crm_manager_access() ) {
				$form = null;
			}
		}

		if ( ! $form ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		self::render_page( $form, '' !== $preview );
		exit;
	}

	/**
	 * The link of a form's own page.
	 *
	 * @param FormModel $form Form.
	 * @return string
	 */
	public static function link( FormModel $form ): string {
		$slug = '' !== (string) $form->slug ? (string) $form->slug : (string) $form->hash;
		if ( get_option( 'permalink_structure' ) ) {
			$prefix = 0 === strpos( (string) get_option( 'permalink_structure' ), '/index.php' ) ? 'index.php/' : '';
			return home_url( user_trailingslashit( $prefix . GlobalSettings::base() . '/' . $slug ) );
		}
		return add_query_arg( self::QUERY_VAR, $slug, home_url( '/' ) );
	}

	/**
	 * The preview link (managers only).
	 *
	 * @param FormModel $form Form.
	 * @return string
	 */
	public static function preview_link( FormModel $form ): string {
		return add_query_arg( self::PREVIEW, $form->hash, home_url( '/' ) );
	}

	/**
	 * What the renderer needs to draw and send a form.
	 *
	 * @param FormModel $form  Form.
	 * @param string    $embed shortcode, block, page, preview or api.
	 * @return array
	 */
	public static function payload( FormModel $form, string $embed ): array {
		$effective = Schema::effective( $form );
		$settings  = Schema::normalize_settings( $form->settings );
		$captcha   = $settings['spam']['captcha'];
		$site_key  = 'none' !== $captcha ? GlobalSettings::site_key( $captcha ) : '';

		$prefill = array();
		$user    = wp_get_current_user();
		if ( $user && $user->exists() && ! in_array( $embed, array( 'preview', 'api' ), true ) ) {
			$prefill = array(
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
			);
		}
		return array(
			'id'      => (int) $form->id,
			'hash'    => (string) $form->hash,
			'name'    => (string) $form->name,
			'status'  => (string) $form->status,
			'layout'  => $effective['layout'],
			'schema'  => self::public_schema( $effective['schema'] ),
			'style'   => Schema::normalize_style( $form->style ),
			'embed'   => $embed,
			'rest'    => esc_url_raw( rest_url( 'doublescale/v1/forms/public/' . $form->hash ) ),
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'captcha' => array(
				'provider' => '' !== $site_key ? $captcha : 'none',
				'site_key' => $site_key,
			),
			'spam'    => array(
				'honeypot'    => $settings['spam']['honeypot'],
				'min_seconds' => $settings['spam']['min_seconds'],
			),
			'prefill' => $prefill,
			// Pro partial entries: the browser saves answers as it goes and can resume them.
			'partial' => Partial::enabled( $settings ),
			// When the form was handed out, signed (the spam check's time trap).
			'issued'  => Tokens::issued( (int) $form->id ),
			'pro'     => Schema::pro_active(),
			// Default texts, with the counted ones ("3 options") worded for this form's numbers; the
			// form's own wording (schema.labels.messages) goes on top in the browser.
			'i18n'    => Texts::for_browser( Texts::numbers_in( $effective['schema'] ) ),
		);
	}

	/**
	 * The container and data for one form, with the renderer enqueued.
	 *
	 * @param FormModel $form  Form.
	 * @param string    $embed shortcode, block, page or preview.
	 * @return string
	 */
	public static function markup( FormModel $form, string $embed ): string {
		$access = Access::state( $form );
		if ( ! $access['open'] ) {
			self::register_assets();
			if ( wp_style_is( self::HANDLE, 'registered' ) ) {
				wp_enqueue_style( self::HANDLE );
			}
			return Access::markup( $form, $access );
		}

		self::register_assets();
		wp_enqueue_script( self::HANDLE );
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}

		++self::$count;
		$id      = 'dsf-' . $form->hash . '-' . self::$count;
		$payload = self::payload( $form, $embed );
		$style   = $payload['style'];
		$css     = '' !== $style['custom_css'] ? '<style>' . self::scope_css( $style['custom_css'], '#' . $id ) . '</style>' : '';

		return $css
			. '<div class="dsf-root" id="' . esc_attr( $id ) . '" data-dsf="' . esc_attr( $form->hash ) . '">'
			. '<script type="application/json" class="dsf-data">' . wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>'
			. '<noscript><p>' . esc_html__( 'Please turn on JavaScript to fill in this form.', 'doublescale' ) . '</p></noscript>'
			. '</div>';
	}

	/**
	 * A form's own page.
	 *
	 * @param FormModel $form    Form.
	 * @param bool      $preview Manager preview.
	 * @return void
	 */
	private static function render_page( FormModel $form, bool $preview ): void {
		$settings = Schema::normalize_settings( $form->settings );
		$page     = $settings['page'];
		$title    = '' !== $page['title'] ? $page['title'] : (string) $form->name;
		$markup   = self::markup( $form, $preview ? 'preview' : 'page' );
		$noindex  = $page['noindex'] || $preview || 'active' !== $form->status;
		// Conversational forms take the whole screen, in the form's own background colour.
		$conversational = 'conversational' === Schema::effective( $form )['layout'];
		$page_class     = $conversational ? 'dsf-page dsf-page--conversational' : 'dsf-page';
		$background     = $conversational ? Schema::normalize_style( $form->style )['background'] : $page['background'];

		status_header( 200 );
		nocache_headers();
		if ( ! headers_sent() ) {
			/**
			 * Whether a form page may only be framed by this site (stops clickjacking).
			 * Return false to allow other sites to show form pages in an iframe.
			 *
			 * @param bool      $same_origin Default true.
			 * @param FormModel $form        The form.
			 */
			if ( apply_filters( 'doublescale_forms_page_same_origin_frames', true, $form ) ) {
				header( 'X-Frame-Options: SAMEORIGIN' );
				header( "Content-Security-Policy: frame-ancestors 'self'" );
			}
			// Prefill tokens sit in the address; never pass them on to logo, font or CAPTCHA hosts.
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		}

		$head = static function () use ( $title, $page, $form, $noindex ) {
			if ( $noindex ) {
				echo '<meta name="robots" content="noindex,nofollow">' . "\n";
			}
			if ( '' !== $page['description'] ) {
				echo '<meta name="description" content="' . esc_attr( $page['description'] ) . '">' . "\n";
			}
			echo '<meta property="og:type" content="website">' . "\n";
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
			echo '<meta property="og:url" content="' . esc_url( self::link( $form ) ) . '">' . "\n";
			if ( '' !== $page['description'] ) {
				echo '<meta property="og:description" content="' . esc_attr( $page['description'] ) . '">' . "\n";
			}
			if ( '' !== $page['image'] ) {
				echo '<meta property="og:image" content="' . esc_url( $page['image'] ) . '">' . "\n";
				echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
			}
		};

		$body = '<main class="dsf-page__main" style="max-width:' . (int) $page['width'] . 'px">'
			. ( '' !== $page['logo'] ? '<img class="dsf-page__logo" src="' . esc_url( $page['logo'] ) . '" alt="">' : '' )
			. $markup
			. '</main>';

		if ( 'theme' === $page['chrome'] ) {
			add_filter(
				'pre_get_document_title',
				static function () use ( $title ) {
					return esc_html( $title );
				}
			);
			add_action( 'wp_head', $head, 1 );
			if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
				?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class( $page_class . ' dsf-page--theme' ); ?>><?php wp_body_open(); ?>
<div class="wp-site-blocks"><?php block_template_part( 'header' ); ?>
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above. ?>
<?php block_template_part( 'footer' ); ?></div>
<?php wp_footer(); ?></body></html>
				<?php
				return;
			}
			get_header();
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			get_footer();
			return;
		}

		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<?php $head(); ?>
<?php wp_site_icon(); ?>
<style>
html,body{margin:0;padding:0}
body.dsf-page{background:<?php echo esc_html( $background ); ?>;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.dsf-page__main{margin:0 auto;padding:48px 16px 64px;box-sizing:border-box}
.dsf-page__logo{display:block;max-height:56px;max-width:220px;margin:0 auto 24px}
</style>
<?php wp_print_styles( array( self::HANDLE ) ); ?>
</head>
<body class="<?php echo esc_attr( $page_class ); ?>">
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above. ?>
<?php wp_print_scripts( array( self::HANDLE ) ); ?>
</body>
</html>
		<?php
	}

	/**
	 * A form by id or hash, if visitors may see it (managers also see drafts).
	 *
	 * @param int    $id   Form id.
	 * @param string $hash Form hash.
	 * @return FormModel|null
	 */
	private static function find( int $id, string $hash ) {
		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return null;
		}
		$form = $id > 0 ? FormModel::find( $id ) : FormModel::find_native_by_hash( sanitize_key( $hash ) );
		if ( ! $form || ! $form->is_native() ) {
			return null;
		}
		if ( 'active' !== $form->status && ! Permissions::has_crm_manager_access() ) {
			return null;
		}
		return $form;
	}

	/**
	 * The definition as the page gets it: redirect and message rules stay on the server (the
	 * submit reply carries the outcome), so their links and texts are not readable in advance.
	 *
	 * @param array $schema Effective definition.
	 * @return array
	 */
	private static function public_schema( array $schema ): array {
		$schema['rules'] = array_values(
			array_filter(
				(array) ( $schema['rules'] ?? array() ),
				static function ( $rule ) {
					return in_array( $rule['action'] ?? '', array( 'show', 'hide' ), true );
				}
			)
		);
		// Thank-you screens come with the submit reply (only the one that applies), like messages.
		if ( isset( $schema['screens'] ) ) {
			$schema['screens']['endings'] = array();
		}
		return $schema;
	}

	/**
	 * Prefix every selector of custom CSS with the form's container.
	 *
	 * @param string $css   CSS.
	 * @param string $scope Container selector.
	 * @return string
	 */
	private static function scope_css( string $css, string $scope ): string {
		return preg_replace_callback(
			'/([^{}]+)\{/',
			static function ( $match ) use ( $scope ) {
				$selectors = trim( $match[1] );
				if ( '' === $selectors || '@' === $selectors[0] ) {
					return $match[0];
				}
				$parts = array();
				foreach ( explode( ',', $selectors ) as $selector ) {
					$selector = trim( $selector );
					$parts[]  = ( ':root' === $selector || 'form' === $selector ) ? $scope : $scope . ' ' . $selector;
				}
				return implode( ', ', $parts ) . '{';
			},
			$css
		);
	}
}
