<?php

defined( 'ABSPATH' ) || exit;

function seopress_get_schema_metaboxe_book( $data, $key_schema = 0 ) {
	?>
	<div class="wrap-rich-snippets-item wrap-rich-snippets-book">
	<?php foreach ( \SEOPressPro\Helpers\Schemas\Book::fields() as $key => $field ) :
		$meta_key = \SEOPressPro\Helpers\Schemas\Book::PREFIX . $key;
		$id = 'seopress-book-' . $key_schema . '-' . $key;
		?>
		<p>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][<?php echo esc_attr( ltrim( $meta_key, '_' ) ); ?>]" value="<?php echo esc_attr( isset( $data[ $meta_key ] ) ? $data[ $meta_key ] : '' ); ?>" />
			<span class="description"><?php echo esc_html( $field['description'] ); ?></span>
		</p>
	<?php endforeach; ?>
	</div>
	<?php
}
