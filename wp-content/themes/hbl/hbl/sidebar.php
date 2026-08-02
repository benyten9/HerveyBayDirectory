<?php
/**
 * The sidebar containing the main widget area
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>

<aside id="secondary" class="widget-area col-lg-4">
	<div class="sidebar-widgets">
		<?php dynamic_sidebar( 'sidebar-1' ); ?>
	</div>
</aside>

