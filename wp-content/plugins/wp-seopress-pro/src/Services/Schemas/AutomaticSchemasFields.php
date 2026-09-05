<?php

namespace SEOPressPro\Services\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the per-post overridable fields of the 13
 * automatic schema types.
 *
 * An automatic schema stored as a `seopress_schemas` CPT post can mark any of
 * its fields as "ask the target post to fill this value" by setting the
 * field's global meta to one of the `manual_*` sentinels:
 *
 * - `manual_single`        — plain text / select / number / textarea override
 * - `manual_img_single`    — image URL override
 * - `manual_date_single`   — date override (YYYY-MM-DD)
 * - `manual_time_single`   — time override (HH:MM or HH:MM:SS)
 * - `manual_rating_single` — rating number override
 * - `manual_custom_single` — custom JSON-LD blob override
 * - `manual_opening_hours_single` — Local Business opening hours override
 *
 * This service:
 * 1. Encodes the complete map of overridable fields (meta key + sentinel +
 *    section + UI metadata) for every type.
 * 2. Given a schema CPT post ID, returns the subset of fields whose global
 *    meta value currently matches their sentinel — i.e. the fields that the
 *    post-level metabox should expose as per-post overrides.
 *
 * The data is consumed by:
 * - `SchemaAutomaticPerPost` REST controller (React universal metabox).
 * - Eventually, a refactored version of the classic PHP template.
 *
 * The override storage format is preserved byte-for-byte from the classic
 * save path so that `options-automatic-rich-snippets.php` and
 * `PrintRichSnippets.php` remain untouched:
 *
 *     _seopress_pro_schemas (post meta)
 *       => 0
 *          => <schemaId>
 *             => <section>     // e.g. rich_snippets_article
 *                => <key>      // e.g. title
 *                   => value
 *
 * @since 9.8
 */
class AutomaticSchemasFields {

	const SENTINEL_SINGLE = 'manual_single';
	const SENTINEL_IMG    = 'manual_img_single';
	const SENTINEL_DATE   = 'manual_date_single';
	const SENTINEL_TIME   = 'manual_time_single';
	const SENTINEL_RATING = 'manual_rating_single';
	const SENTINEL_CUSTOM = 'manual_custom_single';

	/*
	 * Opening hours are not mapped through a source token like the other
	 * properties: `_seopress_pro_rich_snippets_lb_opening_hours` holds the
	 * days array itself. The per-post opt-in therefore lives in its own
	 * `_source` meta key, absent by default so existing schemas keep
	 * resolving against the global hours.
	 */
	const SENTINEL_OPENING_HOURS = 'manual_opening_hours_single';

	/**
	 * Return the overridable fields for a schema post.
	 *
	 * Reads each field's global meta on the schema and keeps only the ones
	 * whose value equals the corresponding `manual_*` sentinel — matching the
	 * visibility logic of the classic metabox template.
	 *
	 * @param int $schema_id The `seopress_schemas` post ID.
	 *
	 * @return array{
	 *     type: string,
	 *     section: string,
	 *     fields: array<int,array{
	 *         key: string,
	 *         section: string,
	 *         meta_key: string,
	 *         sentinel: string,
	 *         type: string,
	 *         label: string,
	 *         placeholder: string,
	 *         default_hint: string,
	 *         description: string,
	 *         options: array,
	 *     }>
	 * }
	 */
	public function getOverridableFieldsForSchema( $schema_id ) {
		$schema_id = (int) $schema_id;
		if ( $schema_id <= 0 ) {
			return array(
				'type'    => '',
				'section' => '',
				'fields'  => array(),
			);
		}

		$type = get_post_meta( $schema_id, '_seopress_pro_rich_snippets_type', true );
		$map  = $this->getMap();

		if ( ! is_string( $type ) || ! isset( $map[ $type ] ) ) {
			return array(
				'type'    => is_string( $type ) ? $type : '',
				'section' => '',
				'fields'  => array(),
			);
		}

		$definition  = $map[ $type ];
		$overridable = array();

		foreach ( $definition['fields'] as $field ) {
			$global_value = get_post_meta( $schema_id, $field['meta_key'], true );
			if ( $global_value !== $field['sentinel'] ) {
				continue;
			}

			$overridable[] = array(
				'key'          => $field['key'],
				'section'      => $definition['section'],
				'meta_key'     => $field['meta_key'],
				'sentinel'     => $field['sentinel'],
				'type'         => $field['type'],
				'label'        => $field['label'],
				'placeholder'  => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
				'default_hint' => isset( $field['default_hint'] ) ? $field['default_hint'] : '',
				'description'  => isset( $field['description'] ) ? $field['description'] : '',
				'options'      => isset( $field['options'] ) ? $field['options'] : array(),
			);
		}

		return array(
			'type'    => $type,
			'section' => $definition['section'],
			'fields'  => $overridable,
		);
	}

	/**
	 * Return the override value currently saved on a post for a given
	 * schema+section+key, or an empty string when not set.
	 *
	 * @param int    $post_id   The target post ID.
	 * @param int    $schema_id The schema CPT post ID.
	 * @param string $section   The override section (e.g. `rich_snippets_article`).
	 * @param string $key       The override sub-key (e.g. `title`).
	 *
	 * @return string
	 */
	public function getOverrideValue( $post_id, $schema_id, $section, $key ) {
		$all = get_post_meta( (int) $post_id, '_seopress_pro_schemas', false );
		if ( empty( $all ) || ! isset( $all[0][ (int) $schema_id ][ $section ][ $key ] ) ) {
			return '';
		}
		$value = $all[0][ (int) $schema_id ][ $section ][ $key ];
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Check whether a schema type is known to this service.
	 *
	 * @param string $type The schema type (e.g. `articles`).
	 *
	 * @return bool
	 */
	public function hasType( $type ) {
		return is_string( $type ) && isset( $this->getMap()[ $type ] );
	}

	/**
	 * Full map of type definitions.
	 *
	 * Each entry encodes:
	 * - `section` — the override array sub-key used in `_seopress_pro_schemas`.
	 * - `fields`  — the list of overridable fields with their meta key, sentinel,
	 *               UI type, label, placeholder, and optional default hint,
	 *               description, and select options.
	 *
	 * @return array<string,array{section:string,fields:array}>
	 */
	private function getMap() {
		return array(
			'articles'     => array(
				'section' => 'rich_snippets_article',
				'fields'  => $this->getArticleFields(),
			),
			'localbusiness' => array(
				'section' => 'rich_snippets_lb',
				'fields'  => $this->getLocalBusinessFields(),
			),
			'faq'          => array(
				'section' => 'rich_snippets_faq',
				'fields'  => $this->getFaqFields(),
			),
			'courses'      => array(
				'section' => 'rich_snippets_courses',
				'fields'  => $this->getCoursesFields(),
			),
			'recipes'      => array(
				'section' => 'rich_snippets_recipes',
				'fields'  => $this->getRecipesFields(),
			),
			'jobs'         => array(
				'section' => 'rich_snippets_jobs',
				'fields'  => $this->getJobsFields(),
			),
			'videos'       => array(
				'section' => 'rich_snippets_videos',
				'fields'  => $this->getVideosFields(),
			),
			'events'       => array(
				'section' => 'rich_snippets_events',
				'fields'  => $this->getEventsFields(),
			),
			'products'     => array(
				'section' => 'rich_snippets_product',
				'fields'  => $this->getProductsFields(),
			),
			'services'     => array(
				'section' => 'rich_snippets_service',
				'fields'  => $this->getServicesFields(),
			),
			'softwareapp'  => array(
				'section' => 'rich_snippets_softwareapp',
				'fields'  => $this->getSoftwareAppFields(),
			),
			'review'       => array(
				'section' => 'rich_snippets_review',
				'fields'  => $this->getReviewFields(),
			),
			'custom'       => array(
				'section' => 'rich_snippets_custom',
				'fields'  => $this->getCustomFields(),
			),
		);
	}

	/**
	 * Shortcut to build a field definition array.
	 *
	 * @param string $key          The override sub-key.
	 * @param string $meta_key     The global schema meta key.
	 * @param string $sentinel     The sentinel that flags this field as manual.
	 * @param string $type         UI type (text, textarea, date, time, upload, select, number, checkbox, rating, custom, opening_hours).
	 * @param string $label        Field label.
	 * @param string $placeholder  Input placeholder.
	 * @param string $default_hint Optional "Default value if empty: ..." hint.
	 * @param string $description  Optional helper description.
	 * @param array  $options      Optional options for select fields.
	 *
	 * @return array
	 */
	private function field( $key, $meta_key, $sentinel, $type, $label, $placeholder = '', $default_hint = '', $description = '', array $options = array() ) {
		return array(
			'key'          => $key,
			'meta_key'     => $meta_key,
			'sentinel'     => $sentinel,
			'type'         => $type,
			'label'        => $label,
			'placeholder'  => $placeholder,
			'default_hint' => $default_hint,
			'description'  => $description,
			'options'      => $options,
		);
	}

	/**
	 * Article fields (9).
	 *
	 * @return array
	 */
	private function getArticleFields() {
		return array(
			$this->field(
				'title',
				'_seopress_pro_rich_snippets_article_title',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Headline (max limit: 110)', 'wp-seopress-pro' ),
				__( 'The headline of the article', 'wp-seopress-pro' ),
				__( 'Default value if empty: Post title', 'wp-seopress-pro' )
			),
			$this->field(
				'desc',
				'_seopress_pro_rich_snippets_article_desc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Description', 'wp-seopress-pro' ),
				__( 'The description of the article', 'wp-seopress-pro' ),
				__( 'Default value if empty: Post excerpt', 'wp-seopress-pro' )
			),
			$this->field(
				'author',
				'_seopress_pro_rich_snippets_article_author',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Post author', 'wp-seopress-pro' ),
				__( 'The author of the article', 'wp-seopress-pro' ),
				__( 'Default value if empty: Post author', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_article_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Image', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' ),
				__( 'Default value if empty: Post thumbnail (featured image)', 'wp-seopress-pro' )
			),
			$this->field(
				'coverage_start_date',
				'_seopress_pro_rich_snippets_article_coverage_start_date',
				self::SENTINEL_DATE,
				'date',
				__( 'Coverage Start Date', 'wp-seopress-pro' ),
				__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' )
			),
			$this->field(
				'coverage_start_time',
				'_seopress_pro_rich_snippets_article_coverage_start_time',
				self::SENTINEL_TIME,
				'time',
				__( 'Coverage Start Time', 'wp-seopress-pro' ),
				__( 'e.g. HH:MM', 'wp-seopress-pro' )
			),
			$this->field(
				'coverage_end_date',
				'_seopress_pro_rich_snippets_article_coverage_end_date',
				self::SENTINEL_DATE,
				'date',
				__( 'Coverage End Date', 'wp-seopress-pro' ),
				__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' )
			),
			$this->field(
				'coverage_end_time',
				'_seopress_pro_rich_snippets_article_coverage_end_time',
				self::SENTINEL_TIME,
				'time',
				__( 'Coverage End Time', 'wp-seopress-pro' ),
				__( 'e.g. HH:MM', 'wp-seopress-pro' )
			),
			$this->field(
				'speakable',
				'_seopress_pro_rich_snippets_article_speakable',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Speakable CSS Selector', 'wp-seopress-pro' ),
				__( 'e.g. post', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Local Business fields (14).
	 *
	 * Note: `opening_hours` is gated by its own `_source` meta key rather than
	 * by a sentinel written on the value meta, because that meta already holds
	 * the days array. The classic per-post metabox still renders its own
	 * opening hours block unconditionally, which keeps the pre-existing
	 * behaviour of sites that never opted in.
	 *
	 * @return array
	 */
	private function getLocalBusinessFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_lb_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Name of your business', 'wp-seopress-pro' ),
				__( 'e.g. My Local Business', 'wp-seopress-pro' )
			),
			$this->field(
				'type',
				'_seopress_pro_rich_snippets_lb_type',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Select a business type', 'wp-seopress-pro' ),
				__( 'e.g. TravelAgency', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_lb_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Image', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' )
			),
			$this->field(
				'street_addr',
				'_seopress_pro_rich_snippets_lb_street_addr',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Street Address', 'wp-seopress-pro' ),
				__( 'e.g. Place Bellevue', 'wp-seopress-pro' )
			),
			$this->field(
				'city',
				'_seopress_pro_rich_snippets_lb_city',
				self::SENTINEL_SINGLE,
				'text',
				__( 'City', 'wp-seopress-pro' ),
				__( 'e.g. Biarritz', 'wp-seopress-pro' )
			),
			$this->field(
				'state',
				'_seopress_pro_rich_snippets_lb_state',
				self::SENTINEL_SINGLE,
				'text',
				__( 'State', 'wp-seopress-pro' ),
				__( 'e.g. Nouvelle Aquitaine', 'wp-seopress-pro' )
			),
			$this->field(
				'pc',
				'_seopress_pro_rich_snippets_lb_pc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Postal code', 'wp-seopress-pro' ),
				__( 'e.g. 64200', 'wp-seopress-pro' )
			),
			$this->field(
				'country',
				'_seopress_pro_rich_snippets_lb_country',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Country', 'wp-seopress-pro' ),
				__( 'e.g. FR for France', 'wp-seopress-pro' )
			),
			$this->field(
				'lat',
				'_seopress_pro_rich_snippets_lb_lat',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Latitude', 'wp-seopress-pro' ),
				__( 'e.g. 43.4831389', 'wp-seopress-pro' )
			),
			$this->field(
				'lon',
				'_seopress_pro_rich_snippets_lb_lon',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Longitude', 'wp-seopress-pro' ),
				__( 'e.g. -1.5630987', 'wp-seopress-pro' )
			),
			$this->field(
				'website',
				'_seopress_pro_rich_snippets_lb_website',
				self::SENTINEL_SINGLE,
				'url',
				__( 'URL', 'wp-seopress-pro' ),
				get_home_url()
			),
			$this->field(
				'tel',
				'_seopress_pro_rich_snippets_lb_tel',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Telephone', 'wp-seopress-pro' ),
				__( 'e.g. +33501020304', 'wp-seopress-pro' )
			),
			$this->field(
				'price',
				'_seopress_pro_rich_snippets_lb_price',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Price range', 'wp-seopress-pro' ),
				/* translators: example price range symbols */
				__( 'e.g. $$, €€€, or ££££...', 'wp-seopress-pro' )
			),
			$this->field(
				'opening_hours',
				'_seopress_pro_rich_snippets_lb_opening_hours_source',
				self::SENTINEL_OPENING_HOURS,
				'opening_hours',
				__( 'Opening hours', 'wp-seopress-pro' ),
				'',
				'',
				__( '<strong>Morning and Afternoon are just time slots</strong>. e.g. if you\'re opened from 10:00 AM to 9:00 PM, check Morning and enter 10:00 / 21:00. If you are open non-stop, check Morning and enter 0:00 / 23:59.', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * FAQ fields (2).
	 *
	 * @return array
	 */
	private function getFaqFields() {
		return array(
			$this->field(
				'q',
				'_seopress_pro_rich_snippets_faq_q',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Question', 'wp-seopress-pro' ),
				__( 'Your question', 'wp-seopress-pro' )
			),
			$this->field(
				'a',
				'_seopress_pro_rich_snippets_faq_a',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Answer', 'wp-seopress-pro' ),
				__( 'Your answer', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Course fields (6).
	 *
	 * @return array
	 */
	private function getCoursesFields() {
		return array(
			$this->field(
				'title',
				'_seopress_pro_rich_snippets_courses_title',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Title', 'wp-seopress-pro' ),
				__( 'The title of your lesson, course...', 'wp-seopress-pro' )
			),
			$this->field(
				'desc',
				'_seopress_pro_rich_snippets_courses_desc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Course description', 'wp-seopress-pro' ),
				__( 'Enter your course/lesson description', 'wp-seopress-pro' )
			),
			$this->field(
				'school',
				'_seopress_pro_rich_snippets_courses_school',
				self::SENTINEL_SINGLE,
				'text',
				__( 'School/Organization', 'wp-seopress-pro' ),
				__( 'Name of university, organization...', 'wp-seopress-pro' )
			),
			$this->field(
				'website',
				'_seopress_pro_rich_snippets_courses_website',
				self::SENTINEL_SINGLE,
				'url',
				__( 'School/Organization Website', 'wp-seopress-pro' ),
				__( 'Enter the URL like https://example.com/', 'wp-seopress-pro' )
			),
			$this->field(
				'offers',
				'_seopress_pro_rich_snippets_courses_offers',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Course offers', 'wp-seopress-pro' ),
				__( 'Course offers', 'wp-seopress-pro' )
			),
			$this->field(
				'instances',
				'_seopress_pro_rich_snippets_courses_instances',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Course instances', 'wp-seopress-pro' ),
				__( 'Course instances', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Recipe fields (13).
	 *
	 * @return array
	 */
	private function getRecipesFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_recipes_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Recipe name', 'wp-seopress-pro' ),
				__( 'The name of your dish', 'wp-seopress-pro' )
			),
			$this->field(
				'desc',
				'_seopress_pro_rich_snippets_recipes_desc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Short recipe description', 'wp-seopress-pro' ),
				__( 'A short summary describing the dish.', 'wp-seopress-pro' )
			),
			$this->field(
				'cat',
				'_seopress_pro_rich_snippets_recipes_cat',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Recipe category', 'wp-seopress-pro' ),
				__( 'e.g. appetizer, entree, or dessert', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_recipes_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Image', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' )
			),
			$this->field(
				'video',
				'_seopress_pro_rich_snippets_recipes_video',
				self::SENTINEL_SINGLE,
				'url',
				__( 'Video URL of the recipe', 'wp-seopress-pro' ),
				__( 'e.g. https://www.youtube.com/watch?v=p6v9Jd5lRIU', 'wp-seopress-pro' )
			),
			$this->field(
				'prep_time',
				'_seopress_pro_rich_snippets_recipes_prep_time',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Preparation time (in minutes)', 'wp-seopress-pro' ),
				__( 'e.g. 30', 'wp-seopress-pro' )
			),
			$this->field(
				'cook_time',
				'_seopress_pro_rich_snippets_recipes_cook_time',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Cooking time (in minutes)', 'wp-seopress-pro' ),
				__( 'e.g. 45', 'wp-seopress-pro' )
			),
			$this->field(
				'calories',
				'_seopress_pro_rich_snippets_recipes_calories',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Calories', 'wp-seopress-pro' ),
				__( 'Number of calories', 'wp-seopress-pro' )
			),
			$this->field(
				'yield',
				'_seopress_pro_rich_snippets_recipes_yield',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Recipe yield', 'wp-seopress-pro' ),
				__( 'e.g. number of people served, or number of servings', 'wp-seopress-pro' )
			),
			$this->field(
				'keywords',
				'_seopress_pro_rich_snippets_recipes_keywords',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Keywords (separated by commas)', 'wp-seopress-pro' ),
				__( 'e.g. winter apple pie, nutmeg crust (NOT recommended: dessert, American)', 'wp-seopress-pro' )
			),
			$this->field(
				'cuisine',
				'_seopress_pro_rich_snippets_recipes_cuisine',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Recipe cuisine', 'wp-seopress-pro' ),
				__( 'The region associated with your recipe. For example, "French", Mediterranean", or "American".', 'wp-seopress-pro' )
			),
			$this->field(
				'ingredient',
				'_seopress_pro_rich_snippets_recipes_ingredient',
				self::SENTINEL_SINGLE,
				'textarea',
				__( 'Recipe ingredients (one per line)', 'wp-seopress-pro' ),
				__( 'Ingredients used in the recipe. One ingredient per line. Include only the ingredient text that is necessary for making the recipe. Don\'t include unnecessary information, such as a definition of the ingredient.', 'wp-seopress-pro' )
			),
			$this->field(
				'instructions',
				'_seopress_pro_rich_snippets_recipes_instructions',
				self::SENTINEL_SINGLE,
				'textarea',
				__( 'Recipe instructions (one per line)', 'wp-seopress-pro' ),
				__( 'e.g. Heat oven to 425°F. Include only text on how to make the recipe and don\'t include other text such as "Directions", "Watch the video", "Step 1".', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Job fields (21).
	 *
	 * @return array
	 */
	private function getJobsFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_jobs_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Job title', 'wp-seopress-pro' ),
				__( 'The title of the job (not the title of the posting). For example, "Software Engineer" or "Barista".', 'wp-seopress-pro' )
			),
			$this->field(
				'desc',
				'_seopress_pro_rich_snippets_jobs_desc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Job description', 'wp-seopress-pro' ),
				__( 'The full description of the job in HTML format.', 'wp-seopress-pro' )
			),
			$this->field(
				'date_posted',
				'_seopress_pro_rich_snippets_jobs_date_posted',
				self::SENTINEL_DATE,
				'date',
				__( 'Published date', 'wp-seopress-pro' ),
				__( 'The original date that employer posted the job in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' )
			),
			$this->field(
				'valid_through',
				'_seopress_pro_rich_snippets_jobs_valid_through',
				self::SENTINEL_DATE,
				'date',
				__( 'Expiration date', 'wp-seopress-pro' ),
				__( 'The date when the job posting will expire in ISO 8601 format. For example, "2017-02-24" or "2017-02-24T19:33:17+00:00".', 'wp-seopress-pro' )
			),
			$this->field(
				'employment_type',
				'_seopress_pro_rich_snippets_jobs_employment_type',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Type of employment', 'wp-seopress-pro' ),
				__( 'Type of employment, You can include more than one employmentType property.', 'wp-seopress-pro' ),
				'',
				'',
				array(
					array( 'value' => 'FULL_TIME', 'label' => 'FULL TIME' ),
					array( 'value' => 'PART_TIME', 'label' => 'PART TIME' ),
					array( 'value' => 'CONTRACTOR', 'label' => 'CONTRACTOR' ),
					array( 'value' => 'TEMPORARY', 'label' => 'TEMPORARY' ),
					array( 'value' => 'INTERN', 'label' => 'INTERN' ),
					array( 'value' => 'VOLUNTEER', 'label' => 'VOLUNTEER' ),
					array( 'value' => 'PER_DIEM', 'label' => 'PER_DIEM' ),
					array( 'value' => 'OTHER', 'label' => 'OTHER' ),
				)
			),
			$this->field(
				'identifier_name',
				'_seopress_pro_rich_snippets_jobs_identifier_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Identifier name', 'wp-seopress-pro' ),
				__( 'The hiring organization\'s unique identifier name for the job', 'wp-seopress-pro' )
			),
			$this->field(
				'identifier_value',
				'_seopress_pro_rich_snippets_jobs_identifier_value',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Identifier value', 'wp-seopress-pro' ),
				__( 'The hiring organization\'s unique identifier value for the job', 'wp-seopress-pro' )
			),
			$this->field(
				'hiring_organization',
				'_seopress_pro_rich_snippets_jobs_hiring_organization',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Organization that hires', 'wp-seopress-pro' ),
				__( 'The organization offering the job position. This should be the name of the company.', 'wp-seopress-pro' )
			),
			$this->field(
				'hiring_same_as',
				'_seopress_pro_rich_snippets_jobs_hiring_same_as',
				self::SENTINEL_SINGLE,
				'url',
				__( 'Organization URL', 'wp-seopress-pro' ),
				__( 'The organization website URL offering the job position.', 'wp-seopress-pro' )
			),
			$this->field(
				'hiring_logo',
				'_seopress_pro_rich_snippets_jobs_hiring_logo',
				self::SENTINEL_IMG,
				'upload',
				__( 'Organization logo', 'wp-seopress-pro' ),
				__( 'The organization logo offering the job position.', 'wp-seopress-pro' )
			),
			$this->field(
				'address_street',
				'_seopress_pro_rich_snippets_jobs_address_street',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Street address', 'wp-seopress-pro' ),
				__( 'Street address', 'wp-seopress-pro' )
			),
			$this->field(
				'address_locality',
				'_seopress_pro_rich_snippets_jobs_address_locality',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Locality address', 'wp-seopress-pro' ),
				__( 'Locality address', 'wp-seopress-pro' )
			),
			$this->field(
				'address_region',
				'_seopress_pro_rich_snippets_jobs_address_region',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Region', 'wp-seopress-pro' ),
				__( 'Region', 'wp-seopress-pro' )
			),
			$this->field(
				'postal_code',
				'_seopress_pro_rich_snippets_jobs_postal_code',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Postal code', 'wp-seopress-pro' ),
				__( 'Postal code', 'wp-seopress-pro' )
			),
			$this->field(
				'country',
				'_seopress_pro_rich_snippets_jobs_country',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Country', 'wp-seopress-pro' ),
				__( 'Country', 'wp-seopress-pro' )
			),
			$this->field(
				'remote',
				'_seopress_pro_rich_snippets_jobs_remote',
				self::SENTINEL_SINGLE,
				'checkbox',
				__( 'Remote job?', 'wp-seopress-pro' )
			),
			$this->field(
				'location_requirement',
				'_seopress_pro_rich_snippets_jobs_location_requirement',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Location requirement for remote job', 'wp-seopress-pro' ),
				__( 'Location requirement for remote job', 'wp-seopress-pro' )
			),
			$this->field(
				'direct_apply',
				'_seopress_pro_rich_snippets_jobs_direct_apply',
				self::SENTINEL_SINGLE,
				'checkbox',
				__( 'Direct apply?', 'wp-seopress-pro' )
			),
			$this->field(
				'salary',
				'_seopress_pro_rich_snippets_jobs_salary',
				self::SENTINEL_SINGLE,
				'number',
				__( 'Salary', 'wp-seopress-pro' ),
				'50'
			),
			$this->field(
				'salary_currency',
				'_seopress_pro_rich_snippets_jobs_salary_currency',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Currency', 'wp-seopress-pro' ),
				__( 'Currency', 'wp-seopress-pro' )
			),
			$this->field(
				'salary_unit',
				'_seopress_pro_rich_snippets_jobs_salary_unit',
				self::SENTINEL_SINGLE,
				'select',
				__( 'Select your unit text', 'wp-seopress-pro' ),
				'',
				'',
				'',
				array(
					array( 'value' => 'HOUR', 'label' => __( 'HOUR', 'wp-seopress-pro' ) ),
					array( 'value' => 'DAY', 'label' => __( 'DAY', 'wp-seopress-pro' ) ),
					array( 'value' => 'WEEK', 'label' => __( 'WEEK', 'wp-seopress-pro' ) ),
					array( 'value' => 'MONTH', 'label' => __( 'MONTH', 'wp-seopress-pro' ) ),
					array( 'value' => 'YEAR', 'label' => __( 'YEAR', 'wp-seopress-pro' ) ),
				)
			),
		);
	}

	/**
	 * Video fields (6).
	 *
	 * @return array
	 */
	private function getVideosFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_videos_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Video name', 'wp-seopress-pro' ),
				__( 'The title of your video', 'wp-seopress-pro' )
			),
			$this->field(
				'description',
				'_seopress_pro_rich_snippets_videos_description',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Video description', 'wp-seopress-pro' ),
				__( 'The description of the video', 'wp-seopress-pro' )
			),
			$this->field(
				'date_posted',
				'_seopress_pro_rich_snippets_videos_date_posted',
				self::SENTINEL_DATE,
				'date',
				__( 'Uploaded date', 'wp-seopress-pro' ),
				__( 'The uploaded date of your video in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_videos_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Video thumbnail', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' )
			),
			$this->field(
				'duration',
				'_seopress_pro_rich_snippets_videos_duration',
				self::SENTINEL_TIME,
				'text',
				__( 'Duration of your video (format: hh:mm:ss)', 'wp-seopress-pro' ),
				__( 'e.g. 00:12:00 for 12 mins', 'wp-seopress-pro' )
			),
			$this->field(
				'url',
				'_seopress_pro_rich_snippets_videos_url',
				self::SENTINEL_SINGLE,
				'url',
				__( 'Video URL', 'wp-seopress-pro' ),
				__( 'e.g. https://example.com/video.mp4', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Event fields (27).
	 *
	 * @return array
	 */
	private function getEventsFields() {
		return array(
			$this->field(
				'type',
				'_seopress_pro_rich_snippets_events_type',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Event type', 'wp-seopress-pro' ),
				__( 'Select your event type', 'wp-seopress-pro' ),
				'',
				__( 'Authorized values: "Event", "BusinessEvent", "ChildrensEvent", "ComedyEvent", "CourseInstance", "DanceEvent", "DeliveryEvent", "EducationEvent", "ExhibitionEvent", "Festival", "FoodEvent", "LiteraryEvent", "MusicEvent", "PublicationEvent", "SaleEvent", "ScreeningEvent", "SocialEvent", "SportsEvent", "TheaterEvent", "VisualArtsEvent"', 'wp-seopress-pro' )
			),
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_events_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Event name', 'wp-seopress-pro' ),
				__( 'The name of your event', 'wp-seopress-pro' )
			),
			$this->field(
				'desc',
				'_seopress_pro_rich_snippets_events_desc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Event description', 'wp-seopress-pro' ),
				__( 'Enter your event description', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_events_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Image thumbnail', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' )
			),
			$this->field(
				'start_date',
				'_seopress_pro_rich_snippets_events_start_date',
				self::SENTINEL_DATE,
				'date',
				__( 'Start date', 'wp-seopress-pro' ),
				__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' )
			),
			$this->field(
				'start_date_timezone',
				'_seopress_pro_rich_snippets_events_start_date_timezone',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Timezone', 'wp-seopress-pro' ),
				__( 'e.g. -4:00', 'wp-seopress-pro' )
			),
			$this->field(
				'start_time',
				'_seopress_pro_rich_snippets_events_start_time',
				self::SENTINEL_TIME,
				'time',
				__( 'Start time', 'wp-seopress-pro' ),
				__( 'e.g. HH:MM', 'wp-seopress-pro' )
			),
			$this->field(
				'end_date',
				'_seopress_pro_rich_snippets_events_end_date',
				self::SENTINEL_DATE,
				'date',
				__( 'End date', 'wp-seopress-pro' ),
				__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' )
			),
			$this->field(
				'end_time',
				'_seopress_pro_rich_snippets_events_end_time',
				self::SENTINEL_TIME,
				'time',
				__( 'End time', 'wp-seopress-pro' ),
				__( 'e.g. HH:MM', 'wp-seopress-pro' )
			),
			$this->field(
				'previous_start_date',
				'_seopress_pro_rich_snippets_events_previous_start_date',
				self::SENTINEL_DATE,
				'date',
				__( 'Previous start date', 'wp-seopress-pro' ),
				__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' )
			),
			$this->field(
				'previous_start_time',
				'_seopress_pro_rich_snippets_events_previous_start_time',
				self::SENTINEL_TIME,
				'time',
				__( 'Previous Start time', 'wp-seopress-pro' ),
				__( 'e.g. HH:MM', 'wp-seopress-pro' )
			),
			$this->field(
				'location_name',
				'_seopress_pro_rich_snippets_events_location_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Location name', 'wp-seopress-pro' ),
				__( 'e.g. My Local Business name', 'wp-seopress-pro' )
			),
			$this->field(
				'location_url',
				'_seopress_pro_rich_snippets_events_location_url',
				self::SENTINEL_SINGLE,
				'url',
				__( 'Event website', 'wp-seopress-pro' ),
				__( 'e.g. https://www.example.com', 'wp-seopress-pro' )
			),
			$this->field(
				'location_address',
				'_seopress_pro_rich_snippets_events_location_address',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Location Address', 'wp-seopress-pro' ),
				__( 'e.g. 1 Avenue de l\'Imperatrice, 64200 Biarritz', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_name',
				'_seopress_pro_rich_snippets_events_offers_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Offer name', 'wp-seopress-pro' ),
				__( 'e.g. General admission', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_cat',
				'_seopress_pro_rich_snippets_events_offers_cat',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Offer category', 'wp-seopress-pro' ),
				__( 'Select your offer category', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_price',
				'_seopress_pro_rich_snippets_events_offers_price',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Offer price', 'wp-seopress-pro' ),
				__( 'e.g. 10', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_price_currency',
				'_seopress_pro_rich_snippets_events_offers_price_currency',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Offer price currency', 'wp-seopress-pro' ),
				__( 'e.g. USD, EUR...', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_availability',
				'_seopress_pro_rich_snippets_events_offers_availability',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Availability', 'wp-seopress-pro' ),
				__( 'e.g. InStock, SoldOut, PreOrder', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_valid_from_date',
				'_seopress_pro_rich_snippets_events_offers_valid_from_date',
				self::SENTINEL_DATE,
				'date',
				__( 'Valid From', 'wp-seopress-pro' ),
				__( 'The date when tickets go on sale', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_valid_from_time',
				'_seopress_pro_rich_snippets_events_offers_valid_from_time',
				self::SENTINEL_TIME,
				'time',
				__( 'Time', 'wp-seopress-pro' ),
				__( 'The time when tickets go on sale', 'wp-seopress-pro' )
			),
			$this->field(
				'offers_url',
				'_seopress_pro_rich_snippets_events_offers_url',
				self::SENTINEL_SINGLE,
				'url',
				__( 'Website to buy tickets', 'wp-seopress-pro' ),
				__( 'e.g. https://www.example.com', 'wp-seopress-pro' )
			),
			$this->field(
				'performer',
				'_seopress_pro_rich_snippets_events_performer',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Performer name', 'wp-seopress-pro' ),
				__( 'e.g. Lana Del Rey', 'wp-seopress-pro' )
			),
			$this->field(
				'organizer_name',
				'_seopress_pro_rich_snippets_events_organizer_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Organizer name', 'wp-seopress-pro' ),
				__( 'e.g. Apple', 'wp-seopress-pro' )
			),
			$this->field(
				'organizer_url',
				'_seopress_pro_rich_snippets_events_organizer_url',
				self::SENTINEL_SINGLE,
				'url',
				__( 'Organizer URL', 'wp-seopress-pro' ),
				__( 'e.g. https://www.example.com', 'wp-seopress-pro' )
			),
			$this->field(
				'status',
				'_seopress_pro_rich_snippets_events_status',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Event status', 'wp-seopress-pro' ),
				__( 'e.g. EventCancelled', 'wp-seopress-pro' ),
				'',
				__( 'Possible values: "EventCancelled", "EventMovedOnline", "EventPostponed", "EventRescheduled", "EventScheduled"', 'wp-seopress-pro' )
			),
			$this->field(
				'attendance_mode',
				'_seopress_pro_rich_snippets_events_attendance_mode',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Event attendance mode', 'wp-seopress-pro' ),
				__( 'e.g. OfflineEventAttendanceMode', 'wp-seopress-pro' ),
				'',
				__( 'Possible values: "OfflineEventAttendanceMode", "OnlineEventAttendanceMode", "MixedEventAttendanceMode"', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Product fields (14).
	 *
	 * @return array
	 */
	private function getProductsFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_product_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product name', 'wp-seopress-pro' ),
				__( 'The name of your product', 'wp-seopress-pro' ),
				__( 'Default: product title', 'wp-seopress-pro' )
			),
			$this->field(
				'description',
				'_seopress_pro_rich_snippets_product_description',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product description', 'wp-seopress-pro' ),
				__( 'The description of the product', 'wp-seopress-pro' ),
				__( 'Default: product excerpt', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_product_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Thumbnail', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' ),
				__( 'Default: product image', 'wp-seopress-pro' )
			),
			$this->field(
				'price',
				'_seopress_pro_rich_snippets_product_price',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product price', 'wp-seopress-pro' ),
				__( 'e.g. 30', 'wp-seopress-pro' ),
				__( 'Default: active product price', 'wp-seopress-pro' )
			),
			$this->field(
				'price_valid_date',
				'_seopress_pro_rich_snippets_product_price_valid_date',
				self::SENTINEL_DATE,
				'date',
				__( 'Product price valid until', 'wp-seopress-pro' ),
				__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ),
				__( 'Default: sale price dates To field', 'wp-seopress-pro' )
			),
			$this->field(
				'sku',
				'_seopress_pro_rich_snippets_product_sku',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product SKU', 'wp-seopress-pro' ),
				__( 'e.g. 0446310786', 'wp-seopress-pro' ),
				__( 'Default: product SKU', 'wp-seopress-pro' )
			),
			$this->field(
				'global_ids',
				'_seopress_pro_rich_snippets_product_global_ids',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product Global Identifiers type', 'wp-seopress-pro' ),
				__( 'e.g. gtin8', 'wp-seopress-pro' )
			),
			$this->field(
				'global_ids_value',
				'_seopress_pro_rich_snippets_product_global_ids_value',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product Global Identifiers', 'wp-seopress-pro' ),
				__( 'e.g. 925872', 'wp-seopress-pro' )
			),
			$this->field(
				'brand',
				'_seopress_pro_rich_snippets_product_brand',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Select a brand', 'wp-seopress-pro' ),
				__( 'e.g. category', 'wp-seopress-pro' )
			),
			$this->field(
				'currency',
				'_seopress_pro_rich_snippets_product_price_currency',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product currency', 'wp-seopress-pro' ),
				__( 'e.g. USD, EUR', 'wp-seopress-pro' ),
				__( 'Default: USD', 'wp-seopress-pro' )
			),
			$this->field(
				'condition',
				'_seopress_pro_rich_snippets_product_condition',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product Condition', 'wp-seopress-pro' ),
				__( 'e.g. NewCondition, UsedCondition...', 'wp-seopress-pro' ),
				__( 'Default: new', 'wp-seopress-pro' )
			),
			$this->field(
				'availability',
				'_seopress_pro_rich_snippets_product_availability',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Product Availability', 'wp-seopress-pro' ),
				__( 'e.g. InStock, InStoreOnly...', 'wp-seopress-pro' ),
				__( 'Default: In Stock', 'wp-seopress-pro' )
			),
			$this->field(
				'positive_notes',
				'_seopress_pro_rich_snippets_product_positive_notes',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Positive Notes', 'wp-seopress-pro' ),
				__( 'e.g. Great design', 'wp-seopress-pro' )
			),
			$this->field(
				'negative_notes',
				'_seopress_pro_rich_snippets_product_negative_notes',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Negative Notes', 'wp-seopress-pro' ),
				__( 'e.g. Too expensive', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Service fields (18).
	 *
	 * @return array
	 */
	private function getServicesFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_service_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Service name', 'wp-seopress-pro' ),
				__( 'The name of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'type',
				'_seopress_pro_rich_snippets_service_type',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Service type', 'wp-seopress-pro' ),
				__( 'The type of service', 'wp-seopress-pro' )
			),
			$this->field(
				'description',
				'_seopress_pro_rich_snippets_service_description',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Service description', 'wp-seopress-pro' ),
				__( 'The description of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_service_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Thumbnail', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' )
			),
			$this->field(
				'area',
				'_seopress_pro_rich_snippets_service_area',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Area served', 'wp-seopress-pro' ),
				__( 'The area served by your service', 'wp-seopress-pro' )
			),
			$this->field(
				'provider_name',
				'_seopress_pro_rich_snippets_service_provider_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Provider name', 'wp-seopress-pro' ),
				__( 'The provider name of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'lb_img',
				'_seopress_pro_rich_snippets_service_lb_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Location image', 'wp-seopress-pro' ),
				__( 'Select your location image', 'wp-seopress-pro' )
			),
			$this->field(
				'provider_mobility',
				'_seopress_pro_rich_snippets_service_provider_mobility',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Provider mobility (static or dynamic)', 'wp-seopress-pro' ),
				__( 'The provider mobility of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'slogan',
				'_seopress_pro_rich_snippets_service_slogan',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Slogan', 'wp-seopress-pro' ),
				__( 'The slogan of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'street_addr',
				'_seopress_pro_rich_snippets_service_street_addr',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Street Address', 'wp-seopress-pro' ),
				__( 'The street address of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'city',
				'_seopress_pro_rich_snippets_service_city',
				self::SENTINEL_SINGLE,
				'text',
				__( 'City', 'wp-seopress-pro' ),
				__( 'The city of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'state',
				'_seopress_pro_rich_snippets_service_state',
				self::SENTINEL_SINGLE,
				'text',
				__( 'State', 'wp-seopress-pro' ),
				__( 'The state of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'pc',
				'_seopress_pro_rich_snippets_service_pc',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Postal code', 'wp-seopress-pro' ),
				__( 'The postal code of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'country',
				'_seopress_pro_rich_snippets_service_country',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Country', 'wp-seopress-pro' ),
				__( 'The country of your service (ISO format)', 'wp-seopress-pro' )
			),
			$this->field(
				'lat',
				'_seopress_pro_rich_snippets_service_lat',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Latitude', 'wp-seopress-pro' ),
				__( 'The latitude of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'lon',
				'_seopress_pro_rich_snippets_service_lon',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Longitude', 'wp-seopress-pro' ),
				__( 'The longitude of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'tel',
				'_seopress_pro_rich_snippets_service_tel',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Telephone', 'wp-seopress-pro' ),
				__( 'The telephone of your service', 'wp-seopress-pro' )
			),
			$this->field(
				'price',
				'_seopress_pro_rich_snippets_service_price',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Price range', 'wp-seopress-pro' ),
				__( 'The price range of your service', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Software App fields (7).
	 *
	 * @return array
	 */
	private function getSoftwareAppFields() {
		return array(
			$this->field(
				'name',
				'_seopress_pro_rich_snippets_softwareapp_name',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Software name', 'wp-seopress-pro' ),
				__( 'The name of your app', 'wp-seopress-pro' )
			),
			$this->field(
				'os',
				'_seopress_pro_rich_snippets_softwareapp_os',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Operating system', 'wp-seopress-pro' ),
				__( 'The operating system(s) required to use the app', 'wp-seopress-pro' )
			),
			$this->field(
				'cat',
				'_seopress_pro_rich_snippets_softwareapp_cat',
				self::SENTINEL_SINGLE,
				'select',
				__( 'Application category', 'wp-seopress-pro' ),
				'',
				'',
				'',
				array(
					array( 'value' => 'GameApplication', 'label' => 'GameApplication' ),
					array( 'value' => 'SocialNetworkingApplication', 'label' => 'SocialNetworkingApplication' ),
					array( 'value' => 'TravelApplication', 'label' => 'TravelApplication' ),
					array( 'value' => 'ShoppingApplication', 'label' => 'ShoppingApplication' ),
					array( 'value' => 'SportsApplication', 'label' => 'SportsApplication' ),
					array( 'value' => 'LifestyleApplication', 'label' => 'LifestyleApplication' ),
					array( 'value' => 'BusinessApplication', 'label' => 'BusinessApplication' ),
					array( 'value' => 'DesignApplication', 'label' => 'DesignApplication' ),
					array( 'value' => 'DeveloperApplication', 'label' => 'DeveloperApplication' ),
					array( 'value' => 'DriverApplication', 'label' => 'DriverApplication' ),
					array( 'value' => 'EducationalApplication', 'label' => 'EducationalApplication' ),
					array( 'value' => 'HealthApplication', 'label' => 'HealthApplication' ),
					array( 'value' => 'FinanceApplication', 'label' => 'FinanceApplication' ),
					array( 'value' => 'SecurityApplication', 'label' => 'SecurityApplication' ),
					array( 'value' => 'BrowserApplication', 'label' => 'BrowserApplication' ),
					array( 'value' => 'CommunicationApplication', 'label' => 'CommunicationApplication' ),
					array( 'value' => 'DesktopEnhancementApplication', 'label' => 'DesktopEnhancementApplication' ),
					array( 'value' => 'EntertainmentApplication', 'label' => 'EntertainmentApplication' ),
					array( 'value' => 'MultimediaApplication', 'label' => 'MultimediaApplication' ),
					array( 'value' => 'HomeApplication', 'label' => 'HomeApplication' ),
					array( 'value' => 'UtilitiesApplication', 'label' => 'UtilitiesApplication' ),
					array( 'value' => 'ReferenceApplication', 'label' => 'ReferenceApplication' ),
				)
			),
			$this->field(
				'price',
				'_seopress_pro_rich_snippets_softwareapp_price',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Price of your app', 'wp-seopress-pro' ),
				__( 'The price of your app (set "0" if the app is free of charge)', 'wp-seopress-pro' )
			),
			$this->field(
				'currency',
				'_seopress_pro_rich_snippets_softwareapp_currency',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Currency', 'wp-seopress-pro' ),
				__( 'Currency', 'wp-seopress-pro' )
			),
			$this->field(
				'rating',
				'_seopress_pro_rich_snippets_softwareapp_rating',
				self::SENTINEL_RATING,
				'rating',
				__( 'Your rating', 'wp-seopress-pro' ),
				__( 'The item rating', 'wp-seopress-pro' )
			),
			$this->field(
				'max_rating',
				'_seopress_pro_rich_snippets_softwareapp_max_rating',
				self::SENTINEL_RATING,
				'rating',
				__( 'Max best rating', 'wp-seopress-pro' ),
				__( 'Max best rating', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Review fields (6).
	 *
	 * @return array
	 */
	private function getReviewFields() {
		return array(
			$this->field(
				'item',
				'_seopress_pro_rich_snippets_review_item',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Review item name', 'wp-seopress-pro' ),
				__( 'The item name reviewed', 'wp-seopress-pro' )
			),
			$this->field(
				'item_type',
				'_seopress_pro_rich_snippets_review_item_type',
				self::SENTINEL_SINGLE,
				'select',
				__( 'Review item type', 'wp-seopress-pro' ),
				'',
				'',
				'',
				array(
					array( 'value' => 'CreativeWorkSeason', 'label' => 'CreativeWorkSeason' ),
					array( 'value' => 'CreativeWorkSeries', 'label' => 'CreativeWorkSeries' ),
					array( 'value' => 'Episode', 'label' => 'Episode' ),
					array( 'value' => 'Game', 'label' => 'Game' ),
					array( 'value' => 'MediaObject', 'label' => 'MediaObject' ),
					array( 'value' => 'MusicPlaylist', 'label' => 'MusicPlaylist' ),
					array( 'value' => 'MusicRecording', 'label' => 'MusicRecording' ),
					array( 'value' => 'Organization', 'label' => 'Organization' ),
				)
			),
			$this->field(
				'img',
				'_seopress_pro_rich_snippets_review_img',
				self::SENTINEL_IMG,
				'upload',
				__( 'Review item image', 'wp-seopress-pro' ),
				__( 'Select your image', 'wp-seopress-pro' )
			),
			$this->field(
				'rating',
				'_seopress_pro_rich_snippets_review_rating',
				self::SENTINEL_RATING,
				'rating',
				__( 'Your rating', 'wp-seopress-pro' ),
				__( 'The item rating', 'wp-seopress-pro' )
			),
			$this->field(
				'max_rating',
				'_seopress_pro_rich_snippets_review_max_rating',
				self::SENTINEL_RATING,
				'rating',
				__( 'Max best rating', 'wp-seopress-pro' ),
				__( 'Max best rating', 'wp-seopress-pro' )
			),
			$this->field(
				'body',
				'_seopress_pro_rich_snippets_review_body',
				self::SENTINEL_SINGLE,
				'text',
				__( 'Review body', 'wp-seopress-pro' ),
				__( 'Review body', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Custom fields (1).
	 *
	 * @return array
	 */
	private function getCustomFields() {
		return array(
			$this->field(
				'custom',
				'_seopress_pro_rich_snippets_custom',
				self::SENTINEL_CUSTOM,
				'custom',
				__( 'Custom schema', 'wp-seopress-pro' )
			),
		);
	}

	/**
	 * Merge the freshly sanitized overrides into the tree already stored.
	 *
	 * Values submitted by the client win, key by key; everything else in the
	 * stored tree is preserved. Sanitization keeps only the fields declared for
	 * the schemas currently matching the post, so a plain overwrite would drop
	 * the rest: the overrides of a schema that stopped matching, and the
	 * opening hours the classic metabox saved on a schema that never opted into
	 * the per-post source.
	 *
	 * @param array $existing  The tree currently stored on the post.
	 * @param array $sanitized The sanitized tree submitted by the client.
	 *
	 * @return array
	 */
	public function mergeOverrides( array $existing, array $sanitized ) {
		$merged = $existing;

		foreach ( $sanitized as $schema_id => $sections ) {
			if ( ! isset( $merged[ $schema_id ] ) || ! is_array( $merged[ $schema_id ] ) ) {
				$merged[ $schema_id ] = array();
			}

			foreach ( $sections as $section_name => $fields ) {
				if ( ! isset( $merged[ $schema_id ][ $section_name ] ) || ! is_array( $merged[ $schema_id ][ $section_name ] ) ) {
					$merged[ $schema_id ][ $section_name ] = array();
				}

				foreach ( $fields as $key => $value ) {
					$merged[ $schema_id ][ $section_name ][ $key ] = $value;
				}
			}
		}

		return $merged;
	}

	/**
	 * Normalize the opening hours submitted by the React field.
	 *
	 * The shared `<FieldOpeningHours/>` component wraps the seven days under
	 * `seopress_local_business_opening_hours`. We unwrap it and store the bare
	 * days array, byte-compatible with what the classic per-post metabox posts,
	 * so `seopress_automatic_rich_snippets_lb_option()` reads it unchanged.
	 *
	 * Like the classic form, the `open` flags are only written when checked:
	 * `open` on a day means "closed all day", `open` on a half-day means the
	 * business is open during that slot.
	 *
	 * @param mixed $value The raw value submitted by the client.
	 *
	 * @return array
	 */
	public function sanitizeOpeningHours( $value ) {
		if ( is_array( $value ) && isset( $value['seopress_local_business_opening_hours'] ) && is_array( $value['seopress_local_business_opening_hours'] ) ) {
			$value = $value['seopress_local_business_opening_hours'];
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$days = array();

		foreach ( $value as $index => $day ) {
			$index = (int) $index;
			if ( $index < 0 || $index > 6 || ! is_array( $day ) ) {
				continue;
			}

			$clean = array();

			if ( ! empty( $day['open'] ) ) {
				$clean['open'] = '1';
			}

			foreach ( array( 'am', 'pm' ) as $half ) {
				if ( ! isset( $day[ $half ] ) || ! is_array( $day[ $half ] ) ) {
					continue;
				}

				$clean_half = array();

				if ( ! empty( $day[ $half ]['open'] ) ) {
					$clean_half['open'] = '1';
				}

				foreach ( array( 'start', 'end' ) as $bound ) {
					if ( ! isset( $day[ $half ][ $bound ] ) || ! is_array( $day[ $half ][ $bound ] ) ) {
						continue;
					}

					// The order matters: the frontend concatenates the values
					// of this array to build "HH:MM".
					$clean_half[ $bound ] = array(
						'hours' => $this->sanitizeHours( isset( $day[ $half ][ $bound ]['hours'] ) ? $day[ $half ][ $bound ]['hours'] : '' ),
						'mins'  => $this->sanitizeMinutes( isset( $day[ $half ][ $bound ]['mins'] ) ? $day[ $half ][ $bound ]['mins'] : '' ),
					);
				}

				if ( ! empty( $clean_half ) ) {
					$clean[ $half ] = $clean_half;
				}
			}

			$days[ $index ] = $clean;
		}

		ksort( $days );

		return $days;
	}

	/**
	 * Clamp an hour to the "00".."23" list offered by the UI.
	 *
	 * @param mixed $value The raw hour.
	 *
	 * @return string
	 */
	public function sanitizeHours( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '00';
		}

		$hours = (int) $value;
		if ( $hours < 0 || $hours > 23 ) {
			$hours = 0;
		}

		return sprintf( '%02d', $hours );
	}

	/**
	 * Keep a minute value within the list offered by the UI.
	 *
	 * @param mixed $value The raw minutes.
	 *
	 * @return string
	 */
	public function sanitizeMinutes( $value ) {
		$allowed = array( '00', '15', '30', '45', '59' );

		if ( ! is_scalar( $value ) ) {
			return '00';
		}

		$mins = sprintf( '%02d', (int) $value );

		return in_array( $mins, $allowed, true ) ? $mins : '00';
	}

	/**
	 * Shape the stored days for the React field, which expects the seven days
	 * wrapped under `seopress_local_business_opening_hours` and always present
	 * so every row renders as a controlled input.
	 *
	 * @param mixed $stored The value read from `_seopress_pro_schemas`.
	 *
	 * @return array
	 */
	public function formatOpeningHoursForClient( $stored ) {
		$stored = is_array( $stored ) ? $stored : array();
		$days   = array();

		for ( $index = 0; $index < 7; $index++ ) {
			$day = isset( $stored[ $index ] ) && is_array( $stored[ $index ] ) ? $stored[ $index ] : array();

			$formatted = array(
				'open' => isset( $day['open'] ) ? '1' : '',
			);

			foreach ( array( 'am', 'pm' ) as $half ) {
				$half_day = isset( $day[ $half ] ) && is_array( $day[ $half ] ) ? $day[ $half ] : array();

				$formatted[ $half ] = array(
					'open'  => isset( $half_day['open'] ) ? '1' : '',
					'start' => array(
						'hours' => $this->sanitizeHours( isset( $half_day['start']['hours'] ) ? $half_day['start']['hours'] : '00' ),
						'mins'  => $this->sanitizeMinutes( isset( $half_day['start']['mins'] ) ? $half_day['start']['mins'] : '00' ),
					),
					'end'   => array(
						'hours' => $this->sanitizeHours( isset( $half_day['end']['hours'] ) ? $half_day['end']['hours'] : '00' ),
						'mins'  => $this->sanitizeMinutes( isset( $half_day['end']['mins'] ) ? $half_day['end']['mins'] : '00' ),
					),
				);
			}

			$days[] = $formatted;
		}

		return array( 'seopress_local_business_opening_hours' => $days );
	}
}
