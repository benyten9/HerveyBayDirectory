<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap-rich-snippets-book">
	<?php foreach ( \SEOPressPro\Helpers\Schemas\Book::fields() as $key => $field ) :
		$input = ltrim( \SEOPressPro\Helpers\Schemas\Book::PREFIX . $key, '_' );
		?>
		<p>
			<label for="<?php echo esc_attr( $input . '_meta' ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<?php echo seopress_schemas_mapping_array( $input, 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Mapping renderer escapes its dynamic values. ?>
			<span class="description"><?php echo esc_html( $field['description'] ); ?></span>
		</p>
	<?php endforeach; ?>
</div>
