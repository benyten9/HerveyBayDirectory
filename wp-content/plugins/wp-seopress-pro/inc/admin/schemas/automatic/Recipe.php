<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Recipe Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-recipes">
	<div class="seopress-notice">
		<p>
			<?php
				/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Recipe schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/recipe' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_name_meta">
			<?php esc_html_e( 'Recipe name', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_name', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The name of your dish', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_desc_meta"><?php esc_html_e( 'Short recipe description', 'wp-seopress-pro' ); ?>
			<code>description</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_desc', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'A short summary describing the dish.', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_cat_meta">
			<?php esc_html_e( 'Recipe category', 'wp-seopress-pro' ); ?>
			<code>recipeCategory</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_cat', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. appetizer, entree, or dessert', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_img_meta"><?php esc_html_e( 'Image', 'wp-seopress-pro' ); ?>
			<code>image</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_img', 'image' ); ?>
		<span class="description"><?php esc_html_e( 'Minimum size: 185px by 185px, aspect ratio 1:1', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_video_meta"><?php esc_html_e( 'Video URL of the recipe', 'wp-seopress-pro' ); ?>
			<code>video</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_video', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'A video URL describing the recipe preparation', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_prep_time_meta">
			<?php esc_html_e( 'Preparation time (in minutes)', 'wp-seopress-pro' ); ?>
			<code>prepTime</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_prep_time', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. 30 min', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_cook_time_meta">
			<?php esc_html_e( 'Cooking time (in minutes)', 'wp-seopress-pro' ); ?>
			<code>totalTime</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_cook_time', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. 45 min', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_calories_meta">
			<?php esc_html_e( 'Calories', 'wp-seopress-pro' ); ?>
			<code>nutrition.calories</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_calories', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Number of calories', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_yield_meta">
			<?php esc_html_e( 'Recipe yield', 'wp-seopress-pro' ); ?>
			<code>recipeYield</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_yield', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. number of people served, or number of servings', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_keywords_meta">
			<?php esc_html_e( 'Keywords', 'wp-seopress-pro' ); ?>
			<code>keywords</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_keywords', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. winter apple pie, nutmeg crust (NOT recommended: dessert, American)', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_cuisine_meta">
			<?php esc_html_e( 'Recipe cuisine', 'wp-seopress-pro' ); ?>
			<code>recipeCuisine</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_cuisine', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The region associated with your recipe. For example, "French", Mediterranean", or "American".', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_ingredient_meta">
			<?php esc_html_e( 'Recipe ingredients', 'wp-seopress-pro' ); ?>
			<code>recipeIngredient</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_ingredient', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Ingredients used in the recipe. One ingredient per line. Include only the ingredient text that is necessary for making the recipe. Don\'t include unnecessary information, such as a definition of the ingredient.', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_recipes_instructions_meta">
			<?php esc_html_e( 'Recipe instructions', 'wp-seopress-pro' ); ?>
			<code>recipeInstructions</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_recipes_instructions', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. Heat oven to 425°F. Include only text on how to make the recipe and don\'t include other text such as "Directions", "Watch the video", "Step 1".', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
