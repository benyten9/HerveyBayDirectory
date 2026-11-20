<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

// $back_to_site comes from BaseTemplateRenderer::back_to_site_link(); null inside an embed.
if ( empty( $back_to_site ) ) {
	return;
}
?>
<div class="booking-back-to-site">
	<a href="<?php echo esc_url( $back_to_site['url'] ); ?>">
		<span aria-hidden="true"><?php echo is_rtl() ? '&rarr;' : '&larr;'; ?></span>
		<?php echo esc_html( $back_to_site['label'] ); ?>
	</a>
</div>
