<?php
/**
 * SEOPress PRO UI Components.
 *
 * Reusable UI components for the admin interface.
 *
 * @package SEOPress PRO
 * @subpackage Admin
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Render an accordion/collapsible section.
 *
 * The title should be passed already translated using __() or esc_html__().
 *
 * Example usage:
 * ```php
 * seopress_accordion( array(
 *     'title'   => __( 'My accordion title', 'wp-seopress-pro' ),
 *     'icon'    => 'dashicons-info-outline',
 *     'content' => '<p>' . esc_html__( 'My content', 'wp-seopress-pro' ) . '</p>',
 * ) );
 * ```
 *
 * @param array $args {
 *     Arguments for the accordion.
 *
 *     @type string $id      Optional. Unique ID for the accordion element.
 *     @type string $title   Required. The title shown in the summary/header. Should be pre-translated.
 *     @type string $icon    Optional. Dashicons class name (e.g., 'dashicons-info-outline'). Default empty.
 *     @type string $content Required. The content to display when expanded. Can contain HTML.
 *     @type bool   $open    Optional. Whether the accordion should be open by default. Default false.
 *     @type string $class   Optional. Additional CSS classes for the details element.
 * }
 * @return void
 */
function seopress_accordion( $args = array() ) {
	$defaults = array(
		'id'      => '',
		'title'   => '',
		'icon'    => '',
		'content' => '',
		'open'    => false,
		'class'   => '',
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['title'] ) || empty( $args['content'] ) ) {
		return;
	}

	$classes  = 'seopress-accordion';
	$classes .= ! empty( $args['class'] ) ? ' ' . esc_attr( $args['class'] ) : '';

	// Allow basic HTML in title (strong, em, span) for flexibility.
	$allowed_title_html = array(
		'strong' => array(),
		'em'     => array(),
		'span'   => array( 'class' => array() ),
		'code'   => array(),
	);
	?>
	<details class="<?php echo esc_attr( $classes ); ?>"<?php echo ! empty( $args['id'] ) ? ' id="' . esc_attr( $args['id'] ) . '"' : ''; ?><?php echo $args['open'] ? ' open' : ''; ?>>
		<summary>
			<?php if ( ! empty( $args['icon'] ) ) : ?>
				<span class="dashicons <?php echo esc_attr( $args['icon'] ); ?>"></span>
			<?php endif; ?>
			<?php echo wp_kses( $args['title'], $allowed_title_html ); ?>
		</summary>
		<div class="seopress-accordion-content">
			<?php echo wp_kses_post( $args['content'] ); ?>
		</div>
	</details>
	<?php
}

/**
 * Render an external link with proper styling.
 *
 * @param array $args {
 *     Arguments for the external link.
 *
 *     @type string $url   Required. The URL to link to.
 *     @type string $text  Required. The link text.
 *     @type string $class Optional. Additional CSS classes.
 * }
 * @return string The HTML for the external link.
 */
function seopress_external_link( $args = array() ) {
	$defaults = array(
		'url'   => '',
		'text'  => '',
		'class' => '',
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['url'] ) || empty( $args['text'] ) ) {
		return '';
	}

	$classes = 'seopress-external-link';
	$classes .= ! empty( $args['class'] ) ? ' ' . esc_attr( $args['class'] ) : '';

	return sprintf(
		'<a href="%s" target="_blank" class="%s"><span>%s</span><span class="dashicons dashicons-external"></span></a>',
		esc_url( $args['url'] ),
		esc_attr( $classes ),
		esc_html( $args['text'] )
	);
}
