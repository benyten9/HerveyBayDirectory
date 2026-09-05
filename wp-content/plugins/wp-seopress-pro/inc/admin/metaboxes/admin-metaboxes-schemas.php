<?php
/**
 * SEOPress PRO Metaboxes Schemas.
 *
 * @package SEOPress PRO
 * @subpackage Metaboxes
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Requests schemas matching the current post.
 *
 * Matching is delegated to `AutomaticSchemasMatcher` so the classic metabox
 * and the per-post REST endpoint share the same source of truth.
 *
 * @see \SEOPressPro\Services\Schemas\AutomaticSchemasMatcher
 */
global $post;

$sp_schemas_ids = array();

if ( $post instanceof WP_Post ) {
	$automatic_schemas_matcher = seopress_pro_get_service( 'AutomaticSchemasMatcher' );
	if ( $automatic_schemas_matcher ) {
		$sp_schemas_ids = $automatic_schemas_matcher->forPost( $post->ID );
	}
}

/**
 * Display schemas inside Automatic tab.
 */
if ( ! empty( $sp_schemas_ids ) ) {
	$count = count( $sp_schemas_ids ); ?>

<div class="seopress-notice">
	<p class="sp-schema-count" data-count="<?php echo absint( $count ); ?>">
		<?php
			/* translators: %s number of automatic schemas */
			printf( _n( 'You have <strong>%s</strong> automatic schema:', 'You have <strong>%s</strong> automatic schemas:', $count, 'wp-seopress-pro' ), number_format_i18n( absint( $count ) ) );
		?>
	</p>
</div>

	<?php
	// Disable all schemas for this post.
	$seopress_pro_rich_snippets_disable_all = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_disable_all', true );

	echo '<p><label for="seopress_pro_rich_snippets_disable_all"><input id="seopress_pro_rich_snippets_disable_all" name="seopress_pro_rich_snippets_disable_all" type="checkbox"';
	if ( '1' == $seopress_pro_rich_snippets_disable_all ) {
		echo 'checked="yes"';
	}
	echo ' value="1"/>';
	esc_html_e( 'Disable all automatic schemas for this post?', 'wp-seopress-pro' ) . '</label></p>';

	// Disable automatic schemas individually.
	$seopress_pro_rich_snippets_disable = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_disable', true );

	foreach ( $sp_schemas_ids as $id ) {
		// All datas.
		$seopress_pro_schemas = get_post_meta( $post->ID, '_seopress_pro_schemas' );

		// Type.
		$seopress_pro_rich_snippets_type = get_post_meta( $id, '_seopress_pro_rich_snippets_type', true );

		// Article.
		if ( 'articles' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_article_title = get_post_meta( $id, '_seopress_pro_rich_snippets_article_title', true );
			$check_article_title                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['title'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['title'] : null;

			$seopress_pro_rich_snippets_article_desc = get_post_meta( $id, '_seopress_pro_rich_snippets_article_desc', true );
			$check_article_desc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['desc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['desc'] : null;

			$seopress_pro_rich_snippets_article_author = get_post_meta( $id, '_seopress_pro_rich_snippets_article_author', true );
			$check_article_author                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['author'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['author'] : null;

			$seopress_pro_rich_snippets_article_img = get_post_meta( $id, '_seopress_pro_rich_snippets_article_img', true );
			$check_article_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['img'] : null;

			$seopress_pro_rich_snippets_article_coverage_start_date = get_post_meta( $id, '_seopress_pro_rich_snippets_article_coverage_start_date', true );
			$check_article_coverage_start_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_start_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_start_date'] : null;

			$seopress_pro_rich_snippets_article_coverage_start_time = get_post_meta( $id, '_seopress_pro_rich_snippets_article_coverage_start_time', true );
			$check_article_coverage_start_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_start_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_start_time'] : null;

			$seopress_pro_rich_snippets_article_coverage_end_date = get_post_meta( $id, '_seopress_pro_rich_snippets_article_coverage_end_date', true );
			$check_article_coverage_end_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_end_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_end_date'] : null;

			$seopress_pro_rich_snippets_article_coverage_end_time = get_post_meta( $id, '_seopress_pro_rich_snippets_article_coverage_end_time', true );
			$check_article_coverage_end_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_end_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['coverage_end_time'] : null;

			$seopress_pro_rich_snippets_article_speakable = get_post_meta( $id, '_seopress_pro_rich_snippets_article_speakable', true );
			$check_article_speakable                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['speakable'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_article']['speakable'] : null;
		}
		// Business.
		if ( 'localbusiness' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_lb_name = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_name', true );
			$check_lb_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['name'] : null;

			$seopress_pro_rich_snippets_lb_type = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_type', true );
			$check_lb_type                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['type'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['type'] : null;

			$seopress_pro_rich_snippets_lb_img = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_img', true );
			$check_lb_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['img'] : null;

			$seopress_pro_rich_snippets_lb_street_addr = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_street_addr', true );
			$check_lb_street_addr                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['street_addr'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['street_addr'] : null;

			$seopress_pro_rich_snippets_lb_city = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_city', true );
			$check_lb_city                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['city'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['city'] : null;

			$seopress_pro_rich_snippets_lb_state = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_state', true );
			$check_lb_state                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['state'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['state'] : null;

			$seopress_pro_rich_snippets_lb_pc = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_pc', true );
			$check_lb_pc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['pc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['pc'] : null;

			$seopress_pro_rich_snippets_lb_country = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_country', true );
			$check_lb_country                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['country'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['country'] : null;

			$seopress_pro_rich_snippets_lb_lat = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_lat', true );
			$check_lb_lat                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['lat'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['lat'] : null;

			$seopress_pro_rich_snippets_lb_lon = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_lon', true );
			$check_lb_lon                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['lon'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['lon'] : null;

			$seopress_pro_rich_snippets_lb_website = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_website', true );
			$check_lb_website                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['website'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['website'] : null;

			$seopress_pro_rich_snippets_lb_tel = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_tel', true );
			$check_lb_tel                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['tel'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['tel'] : null;

			$seopress_pro_rich_snippets_lb_price = get_post_meta( $id, '_seopress_pro_rich_snippets_lb_price', true );
			$check_lb_price                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['price'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['price'] : null;

			$check_lb_opening_hours = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['opening_hours'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_lb']['opening_hours'] : null;
		}

		// FAQ.
		if ( 'faq' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_faq_q = get_post_meta( $id, '_seopress_pro_rich_snippets_faq_q', true );
			$check_faq_q                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_faq']['q'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_faq']['q'] : null;

			$seopress_pro_rich_snippets_faq_a = get_post_meta( $id, '_seopress_pro_rich_snippets_faq_a', true );
			$check_faq_a                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_faq']['a'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_faq']['a'] : null;
		}

		// Course.
		if ( 'courses' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_courses_title = get_post_meta( $id, '_seopress_pro_rich_snippets_courses_title', true );
			$check_courses_title                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['title'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['title'] : null;

			$seopress_pro_rich_snippets_courses_desc = get_post_meta( $id, '_seopress_pro_rich_snippets_courses_desc', true );
			$check_courses_desc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['desc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['desc'] : null;

			$seopress_pro_rich_snippets_courses_school = get_post_meta( $id, '_seopress_pro_rich_snippets_courses_school', true );
			$check_courses_school                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['school'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['school'] : null;

			$seopress_pro_rich_snippets_courses_website = get_post_meta( $id, '_seopress_pro_rich_snippets_courses_website', true );
			$check_courses_website                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['website'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['website'] : null;

			$seopress_pro_rich_snippets_courses_offers = get_post_meta( $id, '_seopress_pro_rich_snippets_courses_offers', true );
			$check_courses_offers                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['offers'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['offers'] : null;

			$seopress_pro_rich_snippets_courses_instances = get_post_meta( $id, '_seopress_pro_rich_snippets_courses_instances', true );
			$check_courses_instances                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['instances'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_courses']['instances'] : null;
		}

		// Recipe.
		if ( 'recipes' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_recipes_name = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_name', true );
			$check_recipes_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['name'] : null;

			$seopress_pro_rich_snippets_recipes_desc = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_desc', true );
			$check_recipes_desc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['desc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['desc'] : null;

			$seopress_pro_rich_snippets_recipes_cat = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_cat', true );
			$check_recipes_cat                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['cat'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['cat'] : null;

			$seopress_pro_rich_snippets_recipes_img = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_img', true );
			$check_recipes_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['img'] : null;

			$seopress_pro_rich_snippets_recipes_video = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_video', true );
			$check_recipes_video                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['video'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['video'] : null;

			$seopress_pro_rich_snippets_recipes_prep_time = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_prep_time', true );
			$check_recipes_prep_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['prep_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['prep_time'] : null;

			$seopress_pro_rich_snippets_recipes_cook_time = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_cook_time', true );
			$check_recipes_cook_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['cook_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['cook_time'] : null;

			$seopress_pro_rich_snippets_recipes_calories = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_calories', true );
			$check_recipes_calories                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['calories'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['calories'] : null;

			$seopress_pro_rich_snippets_recipes_yield = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_yield', true );
			$check_recipes_yield                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['yield'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['yield'] : null;

			$seopress_pro_rich_snippets_recipes_keywords = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_keywords', true );
			$check_recipes_keywords                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['keywords'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['keywords'] : null;

			$seopress_pro_rich_snippets_recipes_cuisine = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_cuisine', true );
			$check_recipes_cuisine                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['cuisine'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['cuisine'] : null;

			$seopress_pro_rich_snippets_recipes_ingredient = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_ingredient', true );
			$check_recipes_ingredient                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['ingredient'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['ingredient'] : null;

			$seopress_pro_rich_snippets_recipes_instructions = get_post_meta( $id, '_seopress_pro_rich_snippets_recipes_instructions', true );
			$check_recipes_instructions                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['instructions'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_recipes']['instructions'] : null;
		}

		// Job
		if ( 'jobs' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_jobs_name = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_name', true );
			$check_jobs_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['name'] : null;

			$seopress_pro_rich_snippets_jobs_desc = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_desc', true );
			$check_jobs_desc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['desc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['desc'] : null;

			$seopress_pro_rich_snippets_jobs_date_posted = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_date_posted', true );
			$check_jobs_date_posted                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['date_posted'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['date_posted'] : null;

			$seopress_pro_rich_snippets_jobs_valid_through = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_valid_through', true );
			$check_jobs_valid_through                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['valid_through'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['valid_through'] : null;

			$seopress_pro_rich_snippets_jobs_employment_type = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_employment_type', true );
			$check_jobs_employment_type                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['employment_type'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['employment_type'] : null;

			$seopress_pro_rich_snippets_jobs_identifier_name = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_identifier_name', true );
			$check_jobs_identifier_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['identifier_name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['identifier_name'] : null;

			$seopress_pro_rich_snippets_jobs_identifier_value = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_identifier_value', true );
			$check_jobs_identifier_value                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['identifier_value'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['identifier_value'] : null;

			$seopress_pro_rich_snippets_jobs_hiring_organization = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_hiring_organization', true );
			$check_jobs_hiring_organization                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['hiring_organization'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['hiring_organization'] : null;

			$seopress_pro_rich_snippets_jobs_hiring_same_as = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_hiring_same_as', true );
			$check_jobs_hiring_same_as                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['hiring_same_as'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['hiring_same_as'] : null;

			$seopress_pro_rich_snippets_jobs_hiring_logo = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_hiring_logo', true );
			$check_jobs_hiring_logo                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['hiring_logo'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['hiring_logo'] : null;

			$seopress_pro_rich_snippets_jobs_address_street = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_address_street', true );
			$check_jobs_address_street                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['address_street'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['address_street'] : null;

			$seopress_pro_rich_snippets_jobs_address_locality = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_address_locality', true );
			$check_jobs_address_locality                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['address_locality'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['address_locality'] : null;

			$seopress_pro_rich_snippets_jobs_address_region = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_address_region', true );
			$check_jobs_address_region                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['address_region'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['address_region'] : null;

			$seopress_pro_rich_snippets_jobs_postal_code = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_postal_code', true );
			$check_jobs_postal_code                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['postal_code'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['postal_code'] : null;

			$seopress_pro_rich_snippets_jobs_country = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_country', true );
			$check_jobs_country                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['country'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['country'] : null;

			$seopress_pro_rich_snippets_jobs_remote = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_remote', true );
			$check_jobs_remote                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['remote'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['remote'] : null;

			$seopress_pro_rich_snippets_jobs_location_requirement = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_location_requirement', true );
			$check_jobs_location_requirement                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['location_requirement'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['location_requirement'] : null;

			$seopress_pro_rich_snippets_jobs_direct_apply = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_direct_apply', true );
			$check_jobs_direct_apply                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['direct_apply'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['direct_apply'] : null;

			$seopress_pro_rich_snippets_jobs_salary = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_salary', true );
			$check_jobs_salary                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['salary'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['salary'] : null;

			$seopress_pro_rich_snippets_jobs_salary_currency = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_salary_currency', true );
			$check_jobs_salary_currency                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['salary_currency'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['salary_currency'] : null;

			$seopress_pro_rich_snippets_jobs_salary_unit = get_post_meta( $id, '_seopress_pro_rich_snippets_jobs_salary_unit', true );
			$check_jobs_salary_unit                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['salary_unit'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_jobs']['salary_unit'] : null;
		}

		// Video
		if ( 'videos' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_videos_name = get_post_meta( $id, '_seopress_pro_rich_snippets_videos_name', true );
			$check_videos_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['name'] : null;

			$seopress_pro_rich_snippets_videos_description = get_post_meta( $id, '_seopress_pro_rich_snippets_videos_description', true );
			$check_videos_description                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['description'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['description'] : null;

			$seopress_pro_rich_snippets_videos_date_posted = get_post_meta( $id, '_seopress_pro_rich_snippets_videos_date_posted', true );
			$check_videos_date_posted                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['date_posted'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['date_posted'] : null;

			$seopress_pro_rich_snippets_videos_img = get_post_meta( $id, '_seopress_pro_rich_snippets_videos_img', true );
			$check_videos_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['img'] : null;

			$seopress_pro_rich_snippets_videos_duration = get_post_meta( $id, '_seopress_pro_rich_snippets_videos_duration', true );
			$check_videos_duration                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['duration'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['duration'] : null;

			$seopress_pro_rich_snippets_videos_url = get_post_meta( $id, '_seopress_pro_rich_snippets_videos_url', true );
			$check_videos_url                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['url'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_videos']['url'] : null;
		}

		// Events
		if ( 'events' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_events_type = get_post_meta( $id, '_seopress_pro_rich_snippets_events_type', true );
			$check_events_type                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['type'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['type'] : null;

			$seopress_pro_rich_snippets_events_name = get_post_meta( $id, '_seopress_pro_rich_snippets_events_name', true );
			$check_events_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['name'] : null;

			$seopress_pro_rich_snippets_events_desc = get_post_meta( $id, '_seopress_pro_rich_snippets_events_desc', true );
			$check_events_desc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['desc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['desc'] : null;

			$seopress_pro_rich_snippets_events_img = get_post_meta( $id, '_seopress_pro_rich_snippets_events_img', true );
			$check_events_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['img'] : null;

			$seopress_pro_rich_snippets_events_start_date = get_post_meta( $id, '_seopress_pro_rich_snippets_events_start_date', true );
			$check_events_start_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['start_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['start_date'] : null;

			$seopress_pro_rich_snippets_events_start_date_timezone = get_post_meta( $id, '_seopress_pro_rich_snippets_events_start_date_timezone', true );
			$check_events_start_date_timezone                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['start_date_timezone'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['start_date_timezone'] : null;

			$seopress_pro_rich_snippets_events_start_time = get_post_meta( $id, '_seopress_pro_rich_snippets_events_start_time', true );
			$check_events_start_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['start_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['start_time'] : null;

			$seopress_pro_rich_snippets_events_end_date = get_post_meta( $id, '_seopress_pro_rich_snippets_events_end_date', true );
			$check_events_end_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['end_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['end_date'] : null;

			$seopress_pro_rich_snippets_events_end_time = get_post_meta( $id, '_seopress_pro_rich_snippets_events_end_time', true );
			$check_events_end_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['end_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['end_time'] : null;

			$seopress_pro_rich_snippets_events_previous_start_date = get_post_meta( $id, '_seopress_pro_rich_snippets_events_previous_start_date', true );
			$check_events_previous_start_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['previous_start_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['previous_start_date'] : null;

			$seopress_pro_rich_snippets_events_previous_start_time = get_post_meta( $id, '_seopress_pro_rich_snippets_events_previous_start_time', true );
			$check_events_previous_start_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['previous_start_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['previous_start_time'] : null;

			$seopress_pro_rich_snippets_events_location_name = get_post_meta( $id, '_seopress_pro_rich_snippets_events_location_name', true );
			$check_events_location_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['location_name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['location_name'] : null;

			$seopress_pro_rich_snippets_events_location_url = get_post_meta( $id, '_seopress_pro_rich_snippets_events_location_url', true );
			$check_events_location_url                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['location_url'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['location_url'] : null;

			$seopress_pro_rich_snippets_events_location_address = get_post_meta( $id, '_seopress_pro_rich_snippets_events_location_address', true );
			$check_events_location_address                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['location_address'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['location_address'] : null;

			$seopress_pro_rich_snippets_events_offers_name = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_name', true );
			$check_events_offers_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_name'] : null;

			$seopress_pro_rich_snippets_events_offers_cat = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_cat', true );
			$check_events_offers_cat                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_cat'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_cat'] : null;

			$seopress_pro_rich_snippets_events_offers_price = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_price', true );
			$check_events_offers_price                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_price'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_price'] : null;

			$seopress_pro_rich_snippets_events_offers_price_currency = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_price_currency', true );
			$check_events_offers_price_currency                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_price_currency'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_price_currency'] : null;

			$seopress_pro_rich_snippets_events_offers_availability = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_availability', true );
			$check_events_offers_availability                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_availability'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_availability'] : null;

			$seopress_pro_rich_snippets_events_offers_valid_from_date = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_valid_from_date', true );
			$check_events_offers_valid_from_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_valid_from_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_valid_from_date'] : null;

			$seopress_pro_rich_snippets_events_offers_valid_from_time = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_valid_from_time', true );
			$check_events_offers_valid_from_time                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_valid_from_time'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_valid_from_time'] : null;

			$seopress_pro_rich_snippets_events_offers_url = get_post_meta( $id, '_seopress_pro_rich_snippets_events_offers_url', true );
			$check_events_offers_url                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_url'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['offers_url'] : null;

			$seopress_pro_rich_snippets_events_performer = get_post_meta( $id, '_seopress_pro_rich_snippets_events_performer', true );
			$check_events_performer                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['performer'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['performer'] : null;

			$seopress_pro_rich_snippets_events_organizer_name = get_post_meta( $id, '_seopress_pro_rich_snippets_events_organizer_name', true );
			$check_events_organizer_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['organizer_name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['organizer_name'] : null;

			$seopress_pro_rich_snippets_events_organizer_url = get_post_meta( $id, '_seopress_pro_rich_snippets_events_organizer_url', true );
			$check_events_organizer_url                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['organizer_url'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['organizer_url'] : null;

			$seopress_pro_rich_snippets_events_status = get_post_meta( $id, '_seopress_pro_rich_snippets_events_status', true );
			$check_events_status                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['status'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['status'] : null;

			$seopress_pro_rich_snippets_events_attendance_mode = get_post_meta( $id, '_seopress_pro_rich_snippets_events_attendance_mode', true );
			$check_events_attendance_mode                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['attendance_mode'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_events']['attendance_mode'] : null;
		}

		// Products
		if ( 'products' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_product_name = get_post_meta( $id, '_seopress_pro_rich_snippets_product_name', true );
			$check_product_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['name'] : null;

			$seopress_pro_rich_snippets_product_description = get_post_meta( $id, '_seopress_pro_rich_snippets_product_description', true );
			$check_product_description                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['description'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['description'] : null;

			$seopress_pro_rich_snippets_product_img = get_post_meta( $id, '_seopress_pro_rich_snippets_product_img', true );
			$check_product_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['img'] : null;

			$seopress_pro_rich_snippets_product_price = get_post_meta( $id, '_seopress_pro_rich_snippets_product_price', true );
			$check_product_price                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['price'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['price'] : null;

			$seopress_pro_rich_snippets_product_price_valid_date = get_post_meta( $id, '_seopress_pro_rich_snippets_product_price_valid_date', true );
			$check_product_price_valid_date                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['price_valid_date'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['price_valid_date'] : null;

			$seopress_pro_rich_snippets_product_sku = get_post_meta( $id, '_seopress_pro_rich_snippets_product_sku', true );
			$check_product_sku                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['sku'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['sku'] : null;

			$seopress_pro_rich_snippets_product_brand = get_post_meta( $id, '_seopress_pro_rich_snippets_product_brand', true );
			$check_product_brand                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['brand'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['brand'] : null;

			$seopress_pro_rich_snippets_product_global_ids = get_post_meta( $id, '_seopress_pro_rich_snippets_product_global_ids', true );
			$check_product_global_ids                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['global_ids'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['global_ids'] : null;

			$seopress_pro_rich_snippets_product_global_ids_value = get_post_meta( $id, '_seopress_pro_rich_snippets_product_global_ids_value', true );
			$check_product_global_ids_value                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['global_ids_value'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['global_ids_value'] : null;

			$seopress_pro_rich_snippets_product_price_currency = get_post_meta( $id, '_seopress_pro_rich_snippets_product_price_currency', true );
			$check_product_currency                            = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['currency'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['currency'] : null;

			$seopress_pro_rich_snippets_product_condition = get_post_meta( $id, '_seopress_pro_rich_snippets_product_condition', true );
			$check_product_condition                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['condition'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['condition'] : null;

			$seopress_pro_rich_snippets_product_availability = get_post_meta( $id, '_seopress_pro_rich_snippets_product_availability', true );
			$check_product_availability                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['availability'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['availability'] : null;

			$seopress_pro_rich_snippets_product_positive_notes = get_post_meta( $id, '_seopress_pro_rich_snippets_product_positive_notes', true );
			$check_product_pros                                = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['positive_notes'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['positive_notes'] : null;

			$seopress_pro_rich_snippets_product_negative_notes = get_post_meta( $id, '_seopress_pro_rich_snippets_product_negative_notes', true );
			$check_product_cons                                = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['negative_notes'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['negative_notes'] : null;

			$seopress_pro_rich_snippets_product_energy_consumption = get_post_meta( $id, '_seopress_pro_rich_snippets_product_energy_consumption', true );
			$check_product_ec                                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['energy_consumption'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_product']['energy_consumption'] : null;
		}

		// Service
		if ( 'services' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_service_name = get_post_meta( $id, '_seopress_pro_rich_snippets_service_name', true );
			$check_service_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['name'] : null;

			$seopress_pro_rich_snippets_service_type = get_post_meta( $id, '_seopress_pro_rich_snippets_service_type', true );
			$check_service_type                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['type'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['type'] : null;

			$seopress_pro_rich_snippets_service_description = get_post_meta( $id, '_seopress_pro_rich_snippets_service_description', true );
			$check_service_description                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['description'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['description'] : null;

			$seopress_pro_rich_snippets_service_img = get_post_meta( $id, '_seopress_pro_rich_snippets_service_img', true );
			$check_service_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['img'] : null;

			$seopress_pro_rich_snippets_service_area = get_post_meta( $id, '_seopress_pro_rich_snippets_service_area', true );
			$check_service_area                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['area'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['area'] : null;

			$seopress_pro_rich_snippets_service_provider_name = get_post_meta( $id, '_seopress_pro_rich_snippets_service_provider_name', true );
			$check_service_provider_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['provider_name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['provider_name'] : null;

			$seopress_pro_rich_snippets_service_lb_img = get_post_meta( $id, '_seopress_pro_rich_snippets_service_lb_img', true );
			$check_service_lb_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['lb_img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['lb_img'] : null;

			$seopress_pro_rich_snippets_service_provider_mobility = get_post_meta( $id, '_seopress_pro_rich_snippets_service_provider_mobility', true );
			$check_service_provider_mobility                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['provider_mobility'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['provider_mobility'] : null;

			$seopress_pro_rich_snippets_service_slogan = get_post_meta( $id, '_seopress_pro_rich_snippets_service_slogan', true );
			$check_service_slogan                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['slogan'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['slogan'] : null;

			$seopress_pro_rich_snippets_service_street_addr = get_post_meta( $id, '_seopress_pro_rich_snippets_service_street_addr', true );
			$check_service_street_addr                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['street_addr'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['street_addr'] : null;

			$seopress_pro_rich_snippets_service_city = get_post_meta( $id, '_seopress_pro_rich_snippets_service_city', true );
			$check_service_city                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['city'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['city'] : null;

			$seopress_pro_rich_snippets_service_state = get_post_meta( $id, '_seopress_pro_rich_snippets_service_state', true );
			$check_service_state                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['state'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['state'] : null;

			$seopress_pro_rich_snippets_service_pc = get_post_meta( $id, '_seopress_pro_rich_snippets_service_pc', true );
			$check_service_pc                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['pc'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['pc'] : null;

			$seopress_pro_rich_snippets_service_country = get_post_meta( $id, '_seopress_pro_rich_snippets_service_country', true );
			$check_service_country                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['country'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['country'] : null;

			$seopress_pro_rich_snippets_service_lat = get_post_meta( $id, '_seopress_pro_rich_snippets_service_lat', true );
			$check_service_lat                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['lat'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['lat'] : null;

			$seopress_pro_rich_snippets_service_lon = get_post_meta( $id, '_seopress_pro_rich_snippets_service_lon', true );
			$check_service_lon                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['lon'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['lon'] : null;

			$seopress_pro_rich_snippets_service_tel = get_post_meta( $id, '_seopress_pro_rich_snippets_service_tel', true );
			$check_service_tel                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['tel'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['tel'] : null;

			$seopress_pro_rich_snippets_service_price = get_post_meta( $id, '_seopress_pro_rich_snippets_service_price', true );
			$check_service_price                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['price'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_service']['price'] : null;
		}

		// Software App
		if ( 'softwareapp' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_softwareapp_name = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_name', true );
			$check_softwareapp_name                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['name'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['name'] : null;

			$seopress_pro_rich_snippets_softwareapp_os = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_os', true );
			$check_softwareapp_os                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['os'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['os'] : null;

			$seopress_pro_rich_snippets_softwareapp_cat = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_cat', true );
			$check_softwareapp_cat                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['cat'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['cat'] : null;

			$seopress_pro_rich_snippets_softwareapp_price = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_price', true );
			$check_softwareapp_price                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['price'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['price'] : null;

			$seopress_pro_rich_snippets_softwareapp_currency = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_currency', true );
			$check_softwareapp_currency                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['currency'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['currency'] : null;

			$seopress_pro_rich_snippets_softwareapp_rating = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_rating', true );
			$check_softwareapp_rating                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['rating'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['rating'] : null;

			$seopress_pro_rich_snippets_softwareapp_max_rating = get_post_meta( $id, '_seopress_pro_rich_snippets_softwareapp_max_rating', true );
			$check_softwareapp_max_rating                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['max_rating'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_softwareapp']['max_rating'] : null;
		}

		// Review
		if ( 'review' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_review_item = get_post_meta( $id, '_seopress_pro_rich_snippets_review_item', true );
			$check_review_item                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['item'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['item'] : null;

			$seopress_pro_rich_snippets_review_item_type = get_post_meta( $id, '_seopress_pro_rich_snippets_review_item_type', true );
			$check_review_item_type                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['item_type'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['item_type'] : null;

			$seopress_pro_rich_snippets_review_img = get_post_meta( $id, '_seopress_pro_rich_snippets_review_img', true );
			$check_review_img                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['img'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['img'] : null;

			$seopress_pro_rich_snippets_review_rating = get_post_meta( $id, '_seopress_pro_rich_snippets_review_rating', true );
			$check_review_rating                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['rating'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['rating'] : null;

			$seopress_pro_rich_snippets_review_max_rating = get_post_meta( $id, '_seopress_pro_rich_snippets_review_max_rating', true );
			$check_review_max_rating                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['max_rating'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['max_rating'] : null;

			$seopress_pro_rich_snippets_review_body = get_post_meta( $id, '_seopress_pro_rich_snippets_review_body', true );
			$check_review_body                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['body'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_review']['body'] : null;
		}

		// Custom.
		if ( 'custom' == $seopress_pro_rich_snippets_type ) {
			$seopress_pro_rich_snippets_custom = get_post_meta( $id, '_seopress_pro_rich_snippets_custom', true );
			$check_custom                      = isset( $seopress_pro_schemas[0][ $id ]['rich_snippets_custom']['custom'] ) ? $seopress_pro_schemas[0][ $id ]['rich_snippets_custom']['custom'] : null;
		}

		if ( 'none' != $seopress_pro_rich_snippets_type || '' != $seopress_pro_rich_snippets_type ) {
			echo '<p class="schema_type"><span class="label">' . esc_html__( 'Name:', 'wp-seopress-pro' ) . ' </span>' . esc_html( get_the_title( $id ) ) . ' - <span class="label">' . esc_html__( 'Type:', 'wp-seopress-pro' ) . ' </span>' . esc_html( $seopress_pro_rich_snippets_type );

			if ( current_user_can( seopress_capability( 'manage_options', 'schemas' ) ) && is_admin() ) {
				// Route to the React Schemas editor when DataViews is supported,
				// falling back to the classic CPT edit screen on older WordPress.
				$schema_edit_link = \SEOPressPro\Helpers\DataViewsAssets::is_supported()
					? admin_url( 'admin.php?page=seopress-schemas#/edit/' . absint( $id ) )
					: admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' );
				echo '<span class="edit"><a class="components-button is-link" href="' . esc_url( $schema_edit_link ) . '">' . esc_html__( 'Edit', 'wp-seopress-pro' ) . '</a></span>';
			}

			echo '</p>';

			// Disable schema.
			echo '<p><label for="seopress_pro_rich_snippets_disable[' . absint( $id ) . ']"><input id="seopress_pro_rich_snippets_disable[' . absint( $id ) . ']" name="seopress_pro_rich_snippets_disable[' . absint( $id ) . ']" type="checkbox" class="toggle"';
			if ( is_array( $seopress_pro_rich_snippets_disable ) && array_key_exists( $id, $seopress_pro_rich_snippets_disable ) ) {
				echo 'checked="yes"';
			}
			echo ' value="1"/>';
			esc_html_e( 'Disable this automatic schema for this post?', 'wp-seopress-pro' ) . '</label></p>';
		}

		// Article.
		if ( 'articles' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_article_title ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][title]_meta">
							' . wp_kses_post( __( 'Headline <em>(max limit: 110)</em>', 'wp-seopress-pro' ) ) . '</label>
						' . esc_html__( 'Default value if empty: Post title', 'wp-seopress-pro' ) . '
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][title]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][title]" placeholder="' . esc_html__( 'The headline of the article', 'wp-seopress-pro' ) . '" aria-label="' . wp_kses_post( __( 'Headline <em>(max limit: 110)</em>', 'wp-seopress-pro' ) ) . '" value="' . esc_html( $check_article_title ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_article_desc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][desc]_meta">
							' . esc_html__( 'Description', 'wp-seopress-pro' ) . '</label>
						' . esc_html__( 'Default value if empty: Post excerpt', 'wp-seopress-pro' ) . '
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][desc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][desc]" placeholder="' . esc_html__( 'The description of the article', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_desc ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_article_author ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][author]_meta">
							' . esc_html__( 'Post author', 'wp-seopress-pro' ) . '</label>
						' . esc_html__( 'Default value if empty: Post author', 'wp-seopress-pro' ) . '
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][author]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][author]" placeholder="' . esc_html__( 'The author of the article', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Post author', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_author ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_article_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][img]_meta">
							' . esc_html__( 'Image', 'wp-seopress-pro' ) . '</label>
							' . esc_html__( 'Default value if empty: Post thumbnail (featured image)', 'wp-seopress-pro' ) . '
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Image', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_img ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_article_coverage_start_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_start_date]_meta">
							' . esc_html__( 'Coverage Start Date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_start_date]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_start_date]" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Coverage Start Date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_coverage_start_date ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_article_coverage_start_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_start_time]_meta">
							' . esc_html__( 'Coverage Start Time', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_start_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_start_time]" placeholder="' . esc_html__( 'e.g. HH:MM', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Coverage Start Time', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_coverage_start_time ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_article_coverage_end_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_end_date]_meta">
							' . esc_html__( 'Coverage End Date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_end_date]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_end_date]" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Coverage End Date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_coverage_end_date ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_article_coverage_end_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_end_time]_meta">
							' . esc_html__( 'Coverage End Time', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_end_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][coverage_end_time]" placeholder="' . esc_html__( 'e.g. HH:MM', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Coverage End Time', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_coverage_end_time ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_article_speakable ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][speakable]_meta">
							' . esc_html__( 'Speakable CSS Selector', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][speakable]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_article][speakable]" placeholder="' . esc_html__( 'e.g. post', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Speakable CSS Selector', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_article_speakable ) . '" />
					</p>';
			}
		}

		// Local Business.
		if ( 'localbusiness' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][name]_meta">
							' . esc_html__( 'Name of your business', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][name]" placeholder="' . esc_html__( 'e.g. My Local Business', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Name of your business', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_type ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][type]_meta">
							' . esc_html__( 'Select a business type', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][type]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][type]" placeholder="' . esc_html__( 'e.g. TravelAgency', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Select a business type', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_type ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_lb_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][img]_meta">
							' . esc_html__( 'Image', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_img ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_street_addr ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][street_addr]_meta">
							' . esc_html__( 'Street Address', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][street_addr]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][street_addr]" placeholder="' . esc_html__( 'e.g. Place Bellevue', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Street Address', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_street_addr ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_city ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][city]_meta">
							' . esc_html__( 'City', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][city]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][city]" placeholder="' . esc_html__( 'e.g. Biarritz', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'City', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_city ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_state ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][state]_meta">
							' . esc_html__( 'State', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][state]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][state]" placeholder="' . esc_html__( 'e.g. Nouvelle Aquitaine', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'State', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_state ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_pc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][pc]_meta">
							' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][pc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][pc]" placeholder="' . esc_html__( 'e.g. 64200', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_pc ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_country ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][country]_meta">
							' . esc_html__( 'Country', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][country]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][country]" placeholder="' . esc_html__( 'e.g. FR for France', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Country', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_country ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_lat ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][lat]_meta">
							' . esc_html__( 'Latitude', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][lat]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][lat]" placeholder="' . esc_html__( 'e.g. 43.4831389', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Latitude', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_lat ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_lon ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][lon]_meta">
							' . esc_html__( 'Longitude', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][lon]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][lon]" placeholder="' . esc_html__( 'e.g. -1.5630987', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Longitude', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_lon ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_website ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][website]_meta">
							' . esc_html__( 'URL', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][website]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][website]" placeholder="' . esc_url( get_home_url() ) . '" aria-label="' . esc_html__( 'URL', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_website ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_tel ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][tel]_meta">
							' . esc_html__( 'Telephone', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][tel]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][tel]" placeholder="' . esc_html__( 'e.g. +33501020304', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Telephone', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_tel ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_lb_price ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][price]_meta">
							' . esc_html__( 'Price range', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][price]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][price]" placeholder="' . esc_html__( 'e.g. $$, €€€, or ££££...', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Price range', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_lb_price ) . '" />
					</p>';
			}
			echo '<p>
				<label for="seopress_pro_rich_snippets_lb_opening_hours_meta">
					' . esc_html__( 'Opening hours', 'wp-seopress-pro' ) . '</label>
			</p>';

			$options = $check_lb_opening_hours;

			$days = array( esc_html__( 'Monday', 'wp-seopress-pro' ), esc_html__( 'Tuesday', 'wp-seopress-pro' ), esc_html__( 'Wednesday', 'wp-seopress-pro' ), esc_html__( 'Thursday', 'wp-seopress-pro' ), esc_html__( 'Friday', 'wp-seopress-pro' ), esc_html__( 'Saturday', 'wp-seopress-pro' ), esc_html__( 'Sunday', 'wp-seopress-pro' ) );

			$hours = array( '00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23' );

			$mins = array( '00', '15', '30', '45', '59' );

			echo '<ul class="wrap-opening-hours">';

			foreach ( $days as $key => $day ) {
				$check_day = isset( $options[ $key ]['open'] );

				$check_day_am = isset( $options[ $key ]['am']['open'] );

				$check_day_pm = isset( $options[ $key ]['pm']['open'] );

				$selected_start_hours = isset( $options[ $key ]['am']['start']['hours'] ) ? $options[ $key ]['am']['start']['hours'] : null;

				$selected_start_mins = isset( $options[ $key ]['am']['start']['mins'] ) ? $options[ $key ]['am']['start']['mins'] : null;

				echo '<li>';

				echo '<span class="day"><strong>' . esc_html( $day ) . '</strong></span>';

				echo '<ul>';
				// Closed?
				echo '<li>';

				echo '<input id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][open]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][open]" type="checkbox"';
				if ( '1' == $check_day ) {
					echo 'checked="yes"';
				}
				echo ' value="1"/>';

				echo '<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][open]">' . esc_html__( 'Closed all the day?', 'wp-seopress-pro' ) . '</label> ';

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['open'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['open'] );
				}
				echo '</li>';

				// AM.
				echo '<li>';
				echo '<input id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][open]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][open]" type="checkbox"';
				if ( '1' == $check_day_am ) {
					echo 'checked="yes"';
				}
				echo ' value="1"/>';

				echo '<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][open]">' . esc_html__( 'Open in the morning?', 'wp-seopress-pro' ) . '</label> ';

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['am']['open'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['am']['open'] );
				}

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][start][hours]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][start][hours]">';

				foreach ( $hours as $hour ) {
					echo '<option ';
					if ( $hour == $selected_start_hours ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $hour ) . '">' . esc_html( $hour ) . '</option>';
				}

				echo '</select>';

				echo ' : ';

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][start][mins]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][start][mins]">';

				foreach ( $mins as $min ) {
					echo '<option ';
					if ( $min == $selected_start_mins ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $min ) . '">' . esc_html( $min ) . '</option>';
				}

				echo '</select>';

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['am']['start']['hours'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['am']['start']['hours'] );
				}

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['am']['start']['mins'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['am']['start']['mins'] );
				}

				echo ' - ';

				$selected_end_hours = isset( $options[ $key ]['am']['end']['hours'] ) ? $options[ $key ]['am']['end']['hours'] : null;

				$selected_end_mins = isset( $options[ $key ]['am']['end']['mins'] ) ? $options[ $key ]['am']['end']['mins'] : null;

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][end][hours]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][end][hours]">';

				foreach ( $hours as $hour ) {
					echo '<option ';
					if ( $hour == $selected_end_hours ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $hour ) . '">' . esc_html( $hour ) . '</option>';
				}

				echo '</select>';

				echo ' : ';

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][end][mins]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][am][end][mins]">';

				foreach ( $mins as $min ) {
					echo '<option ';
					if ( $min == $selected_end_mins ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $min ) . '">' . esc_html( $min ) . '</option>';
				}

				echo '</select>';
				echo '</li>';

				// PM.
				echo '<li>';
				$selected_start_hours2 = isset( $options[ $key ]['pm']['start']['hours'] ) ? $options[ $key ]['pm']['start']['hours'] : null;

				$selected_start_mins2 = isset( $options[ $key ]['pm']['start']['mins'] ) ? $options[ $key ]['pm']['start']['mins'] : null;

				echo '<input id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][open]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][open]" type="checkbox"';
				if ( '1' == $check_day_pm ) {
					echo 'checked="yes"';
				}
				echo ' value="1"/>';

				echo '<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][open]">' . esc_html__( 'Open in the afternoon?', 'wp-seopress-pro' ) . '</label> ';

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['open'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['open'] );
				}

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][start][hours]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][start][hours]">';

				foreach ( $hours as $hour ) {
					echo '<option ';
					if ( $hour == $selected_start_hours2 ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $hour ) . '">' . esc_html( $hour ) . '</option>';
				}

				echo '</select>';

				echo ' : ';

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][start][mins]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][start][mins]">';

				foreach ( $mins as $min ) {
					echo '<option ';
					if ( $min == $selected_start_mins2 ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $min ) . '">' . esc_html( $min ) . '</option>';
				}

				echo '</select>';

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['start']['hours'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['start']['hours'] );
				}

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['start']['mins'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['start']['mins'] );
				}

				echo ' - ';

				$selected_end_hours2 = isset( $options[ $key ]['pm']['end']['hours'] ) ? $options[ $key ]['pm']['end']['hours'] : null;

				$selected_end_mins2 = isset( $options[ $key ]['pm']['end']['mins'] ) ? $options[ $key ]['pm']['end']['mins'] : null;

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][end][hours]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][end][hours]">';

				foreach ( $hours as $hour ) {
					echo '<option ';
					if ( $hour == $selected_end_hours2 ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $hour ) . '">' . esc_html( $hour ) . '</option>';
				}

				echo '</select>';

				echo ' : ';

				echo '<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][end][mins]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_lb][opening_hours][' . absint( $key ) . '][pm][end][mins]">';

				foreach ( $mins as $min ) {
					echo '<option ';
					if ( $min == $selected_end_mins2 ) {
						echo 'selected="selected"';
					}
					echo ' value="' . absint( $min ) . '">' . esc_html( $min ) . '</option>';
				}

				echo '</select>';

				echo '</li>';
				echo '</ul>';

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['end']['hours'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['end']['hours'] );
				}

				if ( isset( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['end']['mins'] ) ) {
					esc_attr( $options['seopress_pro_schemas'][ $id ]['rich_snippets_lb']['opening_hours'][ $key ]['pm']['end']['mins'] );
				}

				$check_lb_opening_hours = $options;
				echo '</li>';
			}
			echo '</ul>';
		}

		// FAQ.
		if ( 'faq' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_faq_q ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_faq][q]_meta">
							' . esc_html__( 'Question', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_faq][q]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_faq][q]" placeholder="' . esc_html__( 'Your question', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Question', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_faq_q ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_faq_a ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_faq][a]_meta">
							' . esc_html__( 'Answer', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_faq][a]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_faq][a]" placeholder="' . esc_html__( 'Your answer', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Answer', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_faq_a ) . '" />
					</p>';
			}
		}

		// Courses.
		if ( 'courses' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_courses_title ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][title]_meta">
							' . esc_html__( 'Title', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][title]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][title]" placeholder="' . esc_html__( 'The title of your lesson, course...', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Title', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_courses_title ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_courses_desc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][desc]_meta">
							' . esc_html__( 'Course description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][desc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][desc]" placeholder="' . esc_html__( 'Enter your course/lesson description', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Course description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_courses_desc ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_courses_school ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][school]_meta">
							' . esc_html__( 'School/Organization', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][school]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][school]" placeholder="' . esc_html__( 'Name of university, organization...', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'School/Organization', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_courses_school ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_courses_website ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][website]_meta">
							' . esc_html__( 'School/Organization Website', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][website]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][website]" placeholder="' . esc_html__( 'Enter the URL like https://example.com/', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'School/Organization Website', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_courses_website ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_courses_offers ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][offers]_meta">
							' . esc_html__( 'Course offers', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][offers]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][offers]" placeholder="' . esc_html__( 'Course offers', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Course offers', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_courses_offers ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_courses_instances ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][instances]_meta">
							' . esc_html__( 'Course instances', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][instances]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_courses][instances]" placeholder="' . esc_html__( 'Course instances', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Course instances', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_courses_instances ) . '" />
					</p>';
			}
		}

		// Recipes.
		if ( 'recipes' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][name]_meta">
							' . esc_html__( 'Recipe name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][name]" placeholder="' . esc_html__( 'The name of your dish', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Recipe name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_desc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][desc]_meta">
							' . esc_html__( 'Short recipe description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][desc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][desc]" placeholder="' . esc_html__( 'A short summary describing the dish.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Short recipe description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_desc ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_cat ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cat]_meta">
							' . esc_html__( 'Recipe category', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cat]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cat]" placeholder="' . esc_html__( 'e.g. appetizer, entree, or dessert', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Recipe category', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_cat ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_recipes_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][img]_meta">
							' . esc_html__( 'Image', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Image', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_img ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_video ) {
				echo '<p>
                        <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][video]_meta">
                            ' . esc_html__( 'Video URL of the recipe', 'wp-seopress-pro' ) . '</label>
                        <input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][video]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][video]" placeholder="' . esc_html__( 'e.g. https://www.youtube.com/watch?v=p6v9Jd5lRIU', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Video URL of the recipe', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_video ) . '" />
                    </p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_prep_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][prep_time]_meta">
							' . esc_html__( 'Preparation time (in minutes)', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][prep_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][prep_time]" placeholder="' . esc_html__( 'e.g. 30', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Preparation time (in minutes)', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_prep_time ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_cook_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cook_time]_meta">
							' . esc_html__( 'Cooking time (in minutes)', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cook_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cook_time]" placeholder="' . esc_html__( 'e.g. 45', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Cooking time (in minutes)', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_cook_time ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_calories ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][calories]_meta">
							' . esc_html__( 'Calories', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][calories]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][calories]" placeholder="' . esc_html__( 'Number of calories', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Calories', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_calories ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_yield ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][yield]_meta">
							' . esc_html__( 'Recipe yield', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][yield]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][yield]" placeholder="' . esc_html__( 'e.g. number of people served, or number of servings', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Recipe yield', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_yield ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_keywords ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][keywords]_meta">
							' . esc_html__( 'Keywords (separated by commas)', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][keywords]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][keywords]" placeholder="' . esc_html__( 'e.g. winter apple pie, nutmeg crust (NOT recommended: dessert, American)', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Keywords', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_keywords ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_cuisine ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cuisine]_meta">
							' . esc_html__( 'Recipe cuisine', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cuisine]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][cuisine]" placeholder="' . esc_html__( 'The region associated with your recipe. For example, "French", Mediterranean", or "American".', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Recipe cuisine', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_cuisine ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_ingredient ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][ingredient]_meta">
							' . esc_html__( 'Recipe ingredients (one per line)', 'wp-seopress-pro' ) . '</label>
						<textarea rows="12" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][ingredient]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][ingredient]" placeholder="' . esc_html__( 'Ingredients used in the recipe. One ingredient per line. Include only the ingredient text that is necessary for making the recipe. Don\'t include unnecessary information, such as a definition of the ingredient.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Recipe ingredients', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_ingredient ) . '">' . esc_html( $check_recipes_ingredient ) . '</textarea>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_recipes_instructions ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][instructions]_meta">
							' . esc_html__( 'Recipe instructions (one per line)', 'wp-seopress-pro' ) . '</label>
						<textarea rows="12" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][instructions]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_recipes][instructions]" placeholder="' . esc_html__( 'e.g. Heat oven to 425°F. Include only text on how to make the recipe and don\'t include other text such as "Directions", "Watch the video", "Step 1".', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Recipe instructions', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_recipes_instructions ) . '">' . esc_html( $check_recipes_instructions ) . '</textarea>
					</p>';
			}
		}

		// Jobs
		if ( 'jobs' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][name]_meta">
							' . esc_html__( 'Job title', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][name]" placeholder="' . esc_html__( 'The title of the job (not the title of the posting). For example, "Software Engineer" or "Barista".', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Job title', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_desc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][desc]_meta">
							' . esc_html__( 'Job description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][desc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][desc]" placeholder="' . esc_html__( 'The full description of the job in HTML format.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Job description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_desc ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_jobs_date_posted ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][date_posted]_meta">
							' . esc_html__( 'Published date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" autocomplete="false" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][date_posted]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][date_posted]" placeholder="' . esc_html__( 'The original date that employer posted the job in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Published date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_date_posted ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_jobs_valid_through ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][valid_through]_meta">
							' . esc_html__( 'Expiration date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" autocomplete="false" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][valid_through]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][valid_through]" placeholder="' . esc_html__( 'The date when the job posting will expire in ISO 8601 format. For example, "2017-02-24" or "2017-02-24T19:33:17+00:00".', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Expiration date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_valid_through ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_employment_type ) {
				echo '<p class="seopress_pro_rich_snippets_jobs_employment_type_p">
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][employment_type]_meta">
							' . esc_html__( 'Type of employment', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress_pro_rich_snippets_jobs_employment_type" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][employment_type]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][employment_type]" placeholder="' . esc_html__( 'Type of employment, You can include more than one employmentType property.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Type of employment', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_employment_type ) . '" />
						<span class="wrap-tags">';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-1" data-tag="FULL_TIME"><span class="dashicons dashicons-plus-alt2"></span>FULL TIME</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-2" data-tag="PART_TIME"><span class="dashicons dashicons-plus-alt2"></span>PART TIME</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-3" data-tag="CONTRACTOR"><span class="dashicons dashicons-plus-alt2"></span>CONTRACTOR</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-4" data-tag="TEMPORARY"><span class="dashicons dashicons-plus-alt2"></span>TEMPORARY</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-5" data-tag="INTERN"><span class="dashicons dashicons-plus-alt2"></span>INTERN</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-6" data-tag="VOLUNTEER"><span class="dashicons dashicons-plus-alt2"></span>VOLUNTEER</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-7" data-tag="PER_DIEM"><span class="dashicons dashicons-plus-alt2"></span>PER_DIEM</button>';
				echo '<button type="button" class="btn btnSecondary tag-title" id="seopress-tag-employment-8" data-tag="OTHER"><span class="dashicons dashicons-plus-alt2"></span>OTHER</button>';
				echo '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_identifier_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][identifier_name]_meta">
							' . esc_html__( 'Identifier name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][identifier_name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][identifier_name]" placeholder="' . esc_html__( 'The hiring organization\'s unique identifier name for the job', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Identifier name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_identifier_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_identifier_value ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][identifier_value]_meta">
							' . esc_html__( 'Identifier value', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][identifier_value]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][identifier_value]" placeholder="' . esc_html__( 'The hiring organization\'s unique identifier value for the job', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Identifier value', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_identifier_value ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_hiring_organization ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_organization]_meta">
							' . esc_html__( 'Organization that hires', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_organization]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_organization]" placeholder="' . esc_html__( 'The organization offering the job position. This should be the name of the company.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Organization that hires', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_hiring_organization ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_hiring_same_as ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_same_as]_meta">
							' . esc_html__( 'Organization URL', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_same_as]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_same_as]" placeholder="' . esc_html__( 'The organization website URL offering the job position.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Organization URL', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_hiring_same_as ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_jobs_hiring_logo ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_logo]_meta">
							' . esc_html__( 'Organization logo', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_logo]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][hiring_logo]" placeholder="' . esc_html__( 'The organization logo offering the job position.', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Organization logo', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_hiring_logo ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_address_street ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_street]_meta">
							' . esc_html__( 'Street address', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_street]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_street]" placeholder="' . esc_html__( 'Street address', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Street address', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_address_street ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_address_locality ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_locality]_meta">
							' . esc_html__( 'Locality address', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_locality]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_locality]" placeholder="' . esc_html__( 'Locality address', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Locality address', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_address_locality ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_address_region ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_region]_meta">
							' . esc_html__( 'Region', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_region]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][address_region]" placeholder="' . esc_html__( 'Region', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Region', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_address_region ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_postal_code ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][postal_code]_meta">
							' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][postal_code]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][postal_code]" placeholder="' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_postal_code ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_country ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][country]_meta">
							' . esc_html__( 'Country', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][country]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][country]" placeholder="' . esc_html__( 'Country', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Country', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_country ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_remote ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][remote]_meta">
							' . esc_html__( 'Remote job?', 'wp-seopress-pro' ) . '</label>
						<input type="checkbox" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][remote]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][remote]" placeholder="' . esc_html__( 'Remote job?', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Remote job?', 'wp-seopress-pro' ) . '"';
				if ( '1' == esc_html( $check_jobs_remote ) ) {
					echo 'checked="yes"';
				}
				echo ' value="1"/>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_location_requirement ) {
				echo '<p>
                    <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][location_requirement]_meta">
                        ' . esc_html__( 'Location requirement for remote job', 'wp-seopress-pro' ) . '</label>
                    <input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][location_requirement]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][location_requirement]" placeholder="' . esc_html__( 'Location requirement for remote job', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Location requirement for remote job', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_location_requirement ) . '" />
                </p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_direct_apply ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][direct_apply]_meta">
							' . esc_html__( 'Direct apply?', 'wp-seopress-pro' ) . '</label>
						<input type="checkbox" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][direct_apply]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][direct_apply]" placeholder="' . esc_html__( 'Direct apply?', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Direct apply?', 'wp-seopress-pro' ) . '"';
				if ( '1' == esc_html( $check_jobs_direct_apply ) ) {
					echo 'checked="yes"';
				}
				echo ' value="1"/>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_salary ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary]_meta">
							' . esc_html__( 'Salary', 'wp-seopress-pro' ) . '</label>
						<input type="number" step="0.01" min="0" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary]" placeholder="' . esc_html__( '50', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Salary', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_salary ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_salary_currency ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary_currency]_meta">
							' . esc_html__( 'Currency', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary_currency]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary_currency]" placeholder="' . esc_html__( 'Currency', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Currency', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_jobs_salary_currency ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_jobs_salary_unit ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary_unit]_meta">
							' . esc_html__( 'Select your unit text', 'wp-seopress-pro' ) . '</label>
						<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary_unit]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_jobs][salary_unit]">
							<option ' . selected( 'HOUR', esc_html( $check_jobs_salary_unit ), false ) . ' value="HOUR">' . esc_html__( 'HOUR', 'wp-seopress-pro' ) . '</option>
							<option ' . selected( 'DAY', esc_html( $check_jobs_salary_unit ), false ) . ' value="DAY">' . esc_html__( 'DAY', 'wp-seopress-pro' ) . '</option>
							<option ' . selected( 'WEEK', esc_html( $check_jobs_salary_unit ), false ) . ' value="WEEK">' . esc_html__( 'WEEK', 'wp-seopress-pro' ) . '</option>
							<option ' . selected( 'MONTH', esc_html( $check_jobs_salary_unit ), false ) . ' value="MONTH">' . esc_html__( 'MONTH', 'wp-seopress-pro' ) . '</option>
							<option ' . selected( 'YEAR', esc_html( $check_jobs_salary_unit ), false ) . ' value="YEAR">' . esc_html__( 'YEAR', 'wp-seopress-pro' ) . '</option>
						</select>
					</p>';
			}
		}

		// Videos
		if ( 'videos' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_videos_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][name]_meta">
							' . esc_html__( 'Video name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][name]" placeholder="' . esc_html__( 'The title of your video', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Video name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_videos_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_videos_description ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][description]_meta">
							' . esc_html__( 'Video description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][description]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][description]" placeholder="' . esc_html__( 'The description of the video', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Video description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_videos_description ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_videos_date_posted ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][date_posted]_meta">
							' . esc_html__( 'Uploaded date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][date_posted]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][date_posted]" placeholder="' . esc_html__( 'The uploaded date of your video in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_videos_date_posted ) . '" />
					</p>';
			}

			if ( 'manual_img_single' == $seopress_pro_rich_snippets_videos_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][img]_meta">
							' . esc_html__( 'Video thumbnail', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Video thumbnail', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_videos_img ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_videos_duration ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][duration]_meta">
							' . esc_html__( 'Duration of your video (format: hh:mm:ss)', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][duration]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][duration]" placeholder="' . esc_html__( 'e.g. 00:12:00 for 12 mins', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Duration of your video (format: hh:mm:ss)', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_videos_duration ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_videos_url ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][url]_meta">
							' . esc_html__( 'Video URL', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][url]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_videos][url]" placeholder="' . esc_html__( 'e.g. https://example.com/video.mp4', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Video URL', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_videos_url ) . '" />
					</p>';
			}
		}

		// Events
		if ( 'events' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_type ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][type]_meta">
							' . esc_html__( 'Event type', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][type]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][type]" placeholder="' . esc_html__( 'Select your event type', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Event type', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_type ) . '" />
                        <span class="description">' . wp_kses_post( __( '<strong>Authorized values:</strong> "BusinessEvent", "ChildrensEvent", "ComedyEvent", "CourseInstance", "DanceEvent", "DeliveryEvent", "EducationEvent", "ExhibitionEvent", "Festival", "FoodEvent", "LiteraryEvent", "MusicEvent", "PublicationEvent", "SaleEvent", "ScreeningEvent", "SocialEvent", "SportsEvent", "TheaterEvent", "VisualArtsEvent"', 'wp-seopress-pro' ) ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][name]_meta">
							' . esc_html__( 'Event name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][name]" placeholder="' . esc_html__( 'The name of your event', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Event name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_desc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][desc]_meta">
							' . esc_html__( 'Event description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][desc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][desc]" placeholder="' . esc_html__( 'Enter your event description', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Event description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_desc ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_events_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][img]_meta">
							' . esc_html__( 'Image thumbnail', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Image thumbnail', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_img ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_events_start_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_date]_meta">
							' . esc_html__( 'Start date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_date]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_date]" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Start date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_start_date ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_start_date_timezone ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_date_timezone]_meta">
							' . esc_html__( 'Timezone', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_date_timezone]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_date_timezone]" placeholder="' . esc_html__( 'e.g. -4:00', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Timezone start date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_start_date_timezone ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_events_start_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_time]_meta">
							' . esc_html__( 'Start time', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][start_time]" placeholder="' . esc_html__( 'e.g. HH:MM', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Start time', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_start_time ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_events_end_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][end_date]_meta">
							' . esc_html__( 'End date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][end_date]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][end_date]" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'End date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_end_date ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_events_end_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][end_time]_meta">
							' . esc_html__( 'End time', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][end_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][end_time]" placeholder="' . esc_html__( 'e.g. HH:MM', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'End time', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_end_time ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_events_previous_start_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][previous_start_date]_meta">
							' . esc_html__( 'Previous start date', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][previous_start_date]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][previous_start_date]" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Previous start date', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_previous_start_date ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_events_previous_start_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][previous_start_time]_meta">
							' . esc_html__( 'Previous Start time', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][previous_start_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][previous_start_time]" placeholder="' . esc_html__( 'e.g. HH:MM', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Previous start time', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_previous_start_time ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_location_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_name]_meta">
							' . esc_html__( 'Location name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_name]" placeholder="' . esc_html__( 'e.g. My Local Business name', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Location name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_location_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_location_url ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_url]_meta">
							' . esc_html__( 'Event website', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_url]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_url]" placeholder="' . esc_html__( 'e.g. https://www.example.com', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Event website', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_location_url ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_location_address ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_address]_meta">
							' . esc_html__( 'Location Address', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_address]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][location_address]" placeholder="' . esc_html__( 'e.g. 1 Avenue de l\'Imperatrice, 64200 Biarritz', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Location Address', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_location_address ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_offers_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_name]_meta">
							' . esc_html__( 'Offer name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_name]" placeholder="' . esc_html__( 'e.g. General admission', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Offer name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_offers_cat ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_cat]_meta">
							' . esc_html__( 'Offer category', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_cat]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_cat]" placeholder="' . esc_html__( 'Select your offer category', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Offer category', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_cat ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_offers_price ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_price]_meta">
							' . esc_html__( 'Offer price', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_price]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_price]" placeholder="' . esc_html__( 'e.g. 10', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Offer price', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_price ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_offers_price_currency ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_price_currency]_meta">
							' . esc_html__( 'Offer price currency', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_price_currency]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_price_currency]" placeholder="' . esc_html__( 'e.g. USD, EUR...', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Offer price currency', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_price_currency ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_offers_availability ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_availability]_meta">
							' . esc_html__( 'Availability', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_availability]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_availability]" placeholder="' . esc_html__( 'e.g. InStock, SoldOut, PreOrder', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Availability', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_availability ) . '" />
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_events_offers_valid_from_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_valid_from_date]_meta">
							' . esc_html__( 'Valid From', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_valid_from_date]" class="seopress-date-picker" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_valid_from_date]" placeholder="' . esc_html__( 'The date when tickets go on sale', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Valid From', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_valid_from_date ) . '" />
					</p>';
			}
			if ( 'manual_time_single' == $seopress_pro_rich_snippets_events_offers_valid_from_time ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_valid_from_time]_meta">
							' . esc_html__( 'Time', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_valid_from_time]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_valid_from_time]" placeholder="' . esc_html__( 'The time when tickets go on sale', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Time', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_valid_from_time ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_offers_url ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_url]_meta">
							' . esc_html__( 'Website to buy tickets', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_url]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][offers_url]" placeholder="' . esc_html__( 'e.g. https://www.example.com', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Website to buy tickets', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_offers_url ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_performer ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][performer]_meta">
							' . esc_html__( 'Performer name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][performer]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][performer]" placeholder="' . esc_html__( 'e.g. Lana Del Rey', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Performer name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_performer ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_organizer_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][organizer_name]_meta">
							' . esc_html__( 'Organizer name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][organizer_name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][organizer_name]" placeholder="' . esc_html__( 'e.g. Apple', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Organizer name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_organizer_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_organizer_url ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][organizer_url]_meta">
							' . esc_html__( 'Organizer URL', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][organizer_url]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][organizer_url]" placeholder="' . esc_html__( 'e.g. https://www.example.com', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Organizer URL', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_organizer_url ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_status ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][status]_meta">
							' . esc_html__( 'Event status', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][status]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][status]" placeholder="' . esc_html__( 'e.g. EventCancelled', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Event status', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_status ) . '" />
						<span class="description">' . esc_html__( 'Possible values: "EventCancelled", "EventMovedOnline", "EventPostponed", "EventRescheduled", "EventScheduled"', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_events_attendance_mode ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][attendance_mode]_meta">
							' . esc_html__( 'Event attendance mode', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][attendance_mode]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_events][attendance_mode]" placeholder="' . esc_html__( 'e.g. OfflineEventAttendanceMode', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Event attendance mode', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_events_attendance_mode ) . '" />
						<span class="description">' . esc_html__( 'Possible values: "OfflineEventAttendanceMode", "OnlineEventAttendanceMode", "MixedEventAttendanceMode"', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
		}

		// Products
		if ( 'products' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][name]_meta">
							' . esc_html__( 'Product name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][name]" placeholder="' . esc_html__( 'The name of your product', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_name ) . '" />
						<span class="description">' . esc_html__( 'Default: product title', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_description ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][description]_meta">
							' . esc_html__( 'Product description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][description]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][description]" placeholder="' . esc_html__( 'The description of the product', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_description ) . '" />
						<span class="description">' . esc_html__( 'Default: product excerpt', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_product_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][img]_meta">
							' . esc_html__( 'Thumbnail', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Thumbnail', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_img ) . '" />
						<span class="description">' . esc_html__( 'Default: product image', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_price ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][price]_meta">
							' . esc_html__( 'Product price', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][price]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][price]" placeholder="' . esc_html__( 'e.g. 30', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product price', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_price ) . '" />
						<span class="description">' . esc_html__( 'Default: active product price', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_date_single' == $seopress_pro_rich_snippets_product_price_valid_date ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][price_valid_date]_meta">
							' . esc_html__( 'Product price valid until', 'wp-seopress-pro' ) . '</label>
						<input type="text" class="seopress-date-picker" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][price_valid_date]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][price_valid_date]" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product price valid until', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_price_valid_date ) . '" />
						<span class="description">' . esc_html__( 'Default: sale price dates To field', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_sku ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][sku]_meta">
							' . esc_html__( 'Product SKU', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][sku]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][sku]" placeholder="' . esc_html__( 'e.g. 0446310786', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product SKU', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_sku ) . '" />
						<span class="description">' . esc_html__( 'Default: product SKU', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_global_ids ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][global_ids]_meta">
							' . esc_html__( 'Product Global Identifiers type', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][global_ids]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][global_ids]" placeholder="' . esc_html__( 'e.g. gtin8', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product Global Identifiers type', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_global_ids ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_global_ids_value ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][global_ids_value]_meta">
							' . esc_html__( 'Product Global Identifiers', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][global_ids_value]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][global_ids_value]" placeholder="' . esc_html__( 'e.g. 925872', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product Global Identifiers', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_global_ids_value ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_brand ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][brand]_meta">
							' . esc_html__( 'Select a brand', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][brand]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][brand]" placeholder="' . esc_html__( 'e.g. category', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Select a brand', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_brand ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_price_currency ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][currency]_meta">
							' . esc_html__( 'Product currency', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][currency]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][currency]" placeholder="' . esc_html__( 'e.g. USD, EUR', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product currency', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_currency ) . '" />
						<span class="description">' . esc_html__( 'Default: USD', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_condition ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][condition]_meta">
							' . esc_html__( 'Product Condition', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][condition]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][condition]" placeholder="' . esc_html__( 'e.g. NewCondition, UsedCondition...', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product Condition', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_condition ) . '" />
						<span class="description">' . esc_html__( 'Default: new', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_availability ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][availability]_meta">
							' . esc_html__( 'Product Availability', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][availability]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][availability]" placeholder="' . esc_html__( 'e.g. InStock, InStoreOnly...', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Product Availability', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_availability ) . '" />
						<span class="description">' . esc_html__( 'Default: In Stock', 'wp-seopress-pro' ) . '</span>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_positive_notes ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][positive_notes]_meta">
							' . esc_html__( 'Positive Notes', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][positive_notes]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][positive_notes]" placeholder="' . esc_html__( 'e.g. Great design', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Positive Notes', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_pros ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_product_negative_notes ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][negative_notes]_meta">
							' . esc_html__( 'Negative Notes', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][negative_notes]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_product][negative_notes]" placeholder="' . esc_html__( 'e.g. Too expensive', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Negative Notes', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_product_cons ) . '" />
					</p>';
			}
		}

		// Service.
		if ( 'services' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][name]_meta">
							' . esc_html__( 'Service name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][name]" placeholder="' . esc_html__( 'The name of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Service name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_type ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][type]_meta">
							' . esc_html__( 'Service type', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][type]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][type]" placeholder="' . esc_html__( 'The type of service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Service type', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_type ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_description ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][description]_meta">
							' . esc_html__( 'Service description', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][description]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][description]" placeholder="' . esc_html__( 'The description of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Service description', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_description ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_service_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][img]_meta">
							' . esc_html__( 'Thumbnail', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Image', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_img ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_area ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][area]_meta">
							' . esc_html__( 'Area served', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][area]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][area]" placeholder="' . esc_html__( 'The area served by your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Area served', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_area ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_provider_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][provider_name]_meta">
							' . esc_html__( 'Provider name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][provider_name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][provider_name]" placeholder="' . esc_html__( 'The provider name of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Provider name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_provider_name ) . '" />
					</p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_service_lb_img ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lb_img]_meta">
							' . esc_html__( 'Location image', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lb_img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lb_img]" placeholder="' . esc_html__( 'Select your location image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Location image', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_lb_img ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_provider_mobility ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][provider_mobility]_meta">
							' . esc_html__( 'Provider mobility (static or dynamic)', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][provider_mobility]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][provider_mobility]" placeholder="' . esc_html__( 'The provider mobility of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Provider mobility (static or dynamic)', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_provider_mobility ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_slogan ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][slogan]_meta">
							' . esc_html__( 'Slogan', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][slogan]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][slogan]" placeholder="' . esc_html__( 'The slogan of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Slogan', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_slogan ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_street_addr ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][street_addr]_meta">
							' . esc_html__( 'Street Address', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][street_addr]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][street_addr]" placeholder="' . esc_html__( 'The street address of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Street Address', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_street_addr ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_city ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][city]_meta">
							' . esc_html__( 'City', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][city]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][city]" placeholder="' . esc_html__( 'The city of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'City', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_city ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_state ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][state]_meta">
							' . esc_html__( 'State', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][state]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][state]" placeholder="' . esc_html__( 'The state of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'State', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_state ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_pc ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][pc]_meta">
							' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][pc]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][pc]" placeholder="' . esc_html__( 'The postal code of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Postal code', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_pc ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_country ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][country]_meta">
							' . esc_html__( 'Country', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][country]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][country]" placeholder="' . esc_html__( 'The country of your service (ISO format)', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Country', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_country ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_lat ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lat]_meta">
							' . esc_html__( 'Latitude', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lat]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lat]" placeholder="' . esc_html__( 'The latitude of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Latitude', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_lat ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_lon ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lon]_meta">
							' . esc_html__( 'Longitude', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lon]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][lon]" placeholder="' . esc_html__( 'The longitude of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Longitude', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_lon ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_tel ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][tel]_meta">
							' . esc_html__( 'Telephone', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][tel]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][tel]" placeholder="' . esc_html__( 'The telephone of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Telephone', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_tel ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_service_price ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][price]_meta">
							' . esc_html__( 'Price range', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][price]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_service][price]" placeholder="' . esc_html__( 'The price range of your service', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Price range', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_service_price ) . '" />
					</p>';
			}
		}

		// Software App.
		if ( 'softwareapp' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_softwareapp_name ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][name]_meta">
							' . esc_html__( 'Software name', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][name]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][name]" placeholder="' . esc_html__( 'The name of your app', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'App name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_softwareapp_name ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_softwareapp_os ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][os]_meta">
							' . esc_html__( 'Operating system', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][os]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][os]" placeholder="' . esc_html__( 'The operating system(s) required to use the app', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Operating system', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_softwareapp_os ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_softwareapp_cat ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][cat]_meta">
							' . esc_html__( 'Application category', 'wp-seopress-pro' ) . '</label>
							<select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][cat]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][cat]">
								<option ' . selected( 'GameApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="GameApplication">' . esc_html__( 'GameApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'SocialNetworkingApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="SocialNetworkingApplication">' . esc_html__( 'SocialNetworkingApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'TravelApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="TravelApplication">' . esc_html__( 'TravelApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'ShoppingApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="ShoppingApplication">' . esc_html__( 'ShoppingApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'SportsApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="SportsApplication">' . esc_html__( 'SportsApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'LifestyleApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="LifestyleApplication">' . esc_html__( 'LifestyleApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'BusinessApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="BusinessApplication">' . esc_html__( 'BusinessApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'DesignApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="DesignApplication">' . esc_html__( 'DesignApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'DeveloperApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="DeveloperApplication">' . esc_html__( 'DeveloperApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'DriverApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="DriverApplication">' . esc_html__( 'DriverApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'EducationalApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="EducationalApplication">' . esc_html__( 'EducationalApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'HealthApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="HealthApplication">' . esc_html__( 'HealthApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'FinanceApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="FinanceApplication">' . esc_html__( 'FinanceApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'SecurityApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="SecurityApplication">' . esc_html__( 'SecurityApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'BrowserApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="BrowserApplication">' . esc_html__( 'BrowserApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'CommunicationApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="CommunicationApplication">' . esc_html__( 'CommunicationApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'DesktopEnhancementApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="DesktopEnhancementApplication">' . esc_html__( 'DesktopEnhancementApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'EntertainmentApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="EntertainmentApplication">' . esc_html__( 'EntertainmentApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'MultimediaApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="MultimediaApplication">' . esc_html__( 'MultimediaApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'HomeApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="HomeApplication">' . esc_html__( 'HomeApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'UtilitiesApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="UtilitiesApplication">' . esc_html__( 'UtilitiesApplication', 'wp-seopress-pro' ) . '</option>
								<option ' . selected( 'ReferenceApplication', esc_html( $check_softwareapp_cat ), false ) . ' value="ReferenceApplication">' . esc_html__( 'ReferenceApplication', 'wp-seopress-pro' ) . '</option>
							</select>
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_softwareapp_price ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][price]_meta">
							' . esc_html__( 'Price of your app', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][price]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][price]" placeholder="' . esc_html__( 'The price of your app (set "0" if the app is free of charge)', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Price', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_softwareapp_price ) . '" />
					</p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_softwareapp_currency ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][currency]_meta">
							' . esc_html__( 'Currency', 'wp-seopress-pro' ) . '</label>
						<input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][currency]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][currency]" placeholder="' . esc_html__( 'Currency', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Currency', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_softwareapp_currency ) . '" />
					</p>';
			}
			if ( 'manual_rating_single' == $seopress_pro_rich_snippets_softwareapp_rating ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][rating]_meta">
                ' . esc_html__( 'Your rating', 'wp-seopress-pro' ) . '</label>
                <input type="number" step="0.1" min="1" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][rating]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][rating]" placeholder="' . esc_html__( 'The item rating', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Your rating', 'wp-seopress-pro' ) . '" value="' . esc_attr( $check_softwareapp_rating ) . '" />
                </p>';
			}
			if ( 'manual_rating_single' == $seopress_pro_rich_snippets_softwareapp_max_rating ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][max_rating]_meta">
                ' . esc_html__( 'Max best rating', 'wp-seopress-pro' ) . '</label>
                <input type="number" step="0.1" min="1" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][max_rating]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_softwareapp][max_rating]" placeholder="' . esc_html__( 'Max best rating', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Max best rating', 'wp-seopress-pro' ) . '" value="' . esc_attr( $check_softwareapp_max_rating ) . '" />
                </p>';
			}
		}

		// Review.
		if ( 'review' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_single' == $seopress_pro_rich_snippets_review_item ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][item]_meta">
                ' . esc_html__( 'Review item name', 'wp-seopress-pro' ) . '</label>
                <input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][item]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][item]" placeholder="' . esc_html__( 'The item name reviewed', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Review item name', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_review_item ) . '" />
                </p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_review_item_type ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][item_type]_meta">
                ' . esc_html__( 'Review item type', 'wp-seopress-pro' ) . '</label>
                <select id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][item_type]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][item_type]">
                <option ' . selected( 'CreativeWorkSeason', esc_html( $check_review_item_type ), false ) . ' value="CreativeWorkSeason">' . esc_html__( 'CreativeWorkSeason', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'CreativeWorkSeries', esc_html( $check_review_item_type ), false ) . ' value="CreativeWorkSeries">' . esc_html__( 'CreativeWorkSeries', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'Episode', esc_html( $check_review_item_type ), false ) . ' value="Episode">' . esc_html__( 'Episode', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'Game', esc_html( $check_review_item_type ), false ) . ' value="Game">' . esc_html__( 'Game', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'MediaObject', esc_html( $check_review_item_type ), false ) . ' value="MediaObject">' . esc_html__( 'MediaObject', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'MusicPlaylist', esc_html( $check_review_item_type ), false ) . ' value="MusicPlaylist">' . esc_html__( 'MusicPlaylist', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'MusicRecording', esc_html( $check_review_item_type ), false ) . ' value="MusicRecording">' . esc_html__( 'MusicRecording', 'wp-seopress-pro' ) . '</option>
                <option ' . selected( 'Organization', esc_html( $check_review_item_type ), false ) . ' value="Organization">' . esc_html__( 'Organization', 'wp-seopress-pro' ) . '</option>
                </select>
                </p>';
			}
			if ( 'manual_img_single' == $seopress_pro_rich_snippets_review_img ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][img]_meta">
                ' . esc_html__( 'Review item image', 'wp-seopress-pro' ) . '</label>
                <input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][img]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][img]" placeholder="' . esc_html__( 'Select your image', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Review item image', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_review_img ) . '" />
                </p>';
			}
			if ( 'manual_rating_single' == $seopress_pro_rich_snippets_review_rating ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][rating]_meta">
                ' . esc_html__( 'Your rating', 'wp-seopress-pro' ) . '</label>
                <input type="number" step="0.1" min="1" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][rating]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][rating]" placeholder="' . esc_html__( 'The item rating', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Your rating', 'wp-seopress-pro' ) . '" value="' . esc_attr( $check_review_rating ) . '" />
                </p>';
			}
			if ( 'manual_rating_single' == $seopress_pro_rich_snippets_review_max_rating ) {
				echo '<p>
                <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][max_rating]_meta">
                ' . esc_html__( 'Max best rating', 'wp-seopress-pro' ) . '</label>
                <input type="number" step="0.1" min="1" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][max_rating]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][max_rating]" placeholder="' . esc_html__( 'Max best rating', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Max best rating', 'wp-seopress-pro' ) . '" value="' . esc_attr( $check_review_max_rating ) . '" />
                </p>';
			}
			if ( 'manual_single' == $seopress_pro_rich_snippets_review_body ) {
				echo '<p>
                        <label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][body]_meta">
                            ' . esc_html__( 'Review body', 'wp-seopress-pro' ) . '</label>
                        <input type="text" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][body]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_review][body]" placeholder="' . esc_html__( 'Review body', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Your review body', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_review_body ) . '" />
                    </p>';
			}
		}

		// Custom.
		if ( 'custom' == $seopress_pro_rich_snippets_type ) {
			if ( 'manual_custom_single' == $seopress_pro_rich_snippets_custom ) {
				echo '<p>
						<label for="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_custom][custom]_meta">
							' . esc_html__( 'Custom schema', 'wp-seopress-pro' ) . '</label>
						<textarea rows="25" id="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_custom][custom]" name="seopress_pro_schemas[' . absint( $id ) . '][rich_snippets_custom][custom]" placeholder="' . esc_html__(
								'e.g. <script type="application/ld+json">{
							"@context": "https://schema.org/",
							"@type": "Review",
							"itemReviewed": {
							  "@type": "Restaurant",
							  "image": "http://www.example.com/seafood-restaurant.jpg",
							  "name": "Legal Seafood",
							  "servesCuisine": "Seafood",
							  "telephone": "1234567",
							  "address" :{
								"@type": "PostalAddress",
								"streetAddress": "123 William St",
								"addressLocality": "New York",
								"addressRegion": "NY",
								"postalCode": "10038",
								"addressCountry": "US"
							  }
							},
							"reviewRating": {
							  "@type": "Rating",
							  "ratingValue": "4"
							},
							"name": "A good seafood place.",
							"author": {
							  "@type": "Person",
							  "name": "Bob Smith"
							},
							"reviewBody": "The seafood is great.",
							"publisher": {
							  "@type": "Organization",
							  "name": "Washington Times"
							}
						  }</script>',
								'wp-seopress-pro'
							) . '" aria-label="' . esc_html__( 'Custom schema', 'wp-seopress-pro' ) . '" value="' . esc_html( $check_custom ) . '">' . htmlspecialchars( $check_custom, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '</textarea>
					</p>';
			}
		}
	}
} else {
	?>
<div class="seopress-notice">
	<p>
		<?php esc_html_e( 'No automatic schema created for this content.', 'wp-seopress-pro' ); ?>
	</p>

	<p>
		<a class="<?php echo esc_attr( seopress_btn_secondary_classes() ); ?>"
			href="<?php echo esc_url( admin_url( 'post-new.php?post_type=seopress_schemas' ) ); ?>">
			<?php esc_html_e( 'Add a schema', 'wp-seopress-pro' ); ?>
		</a>
	</p>
</div>
	<?php
}
