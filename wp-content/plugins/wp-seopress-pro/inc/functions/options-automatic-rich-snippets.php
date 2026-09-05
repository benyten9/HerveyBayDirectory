<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Automatic Rich Snippets.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( '1' === seopress_pro_get_service( 'OptionPro' )->getRichSnippetEnable() ) { // Is Rich Snippets enabled.
	if ( is_single() || is_singular() ) {
		// If Disable all automatic schemas doesn't exist, then continue.
		if ( ! get_post_meta( get_the_ID(), '_seopress_pro_rich_snippets_disable_all', true ) ) {
			/**
			 * Automatic rich snippets manual option.
			 *
			 * @param int    $id The ID.
			 * @param string $schema_name The schema name.
			 * @param array  $post_meta_key The post meta key.
			 * @param array  $seopress_pro_schemas The seopress pro schemas.
			 * @param array  $sp_schemas_dyn_variables The sp schemas dyn variables.
			 * @param array  $sp_schemas_dyn_variables_replace The sp schemas dyn variables replace.
			 * @return array $schema_datas
			 */
			function seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace ) {
				if ( ! empty( $post_meta_key ) ) {
					foreach ( $post_meta_key as $key => $value ) {
						// Initialize the post meta value.
						$_post_meta_value = null;

						// Opening hours are not driven by a source token — the
						// meta holds the days array itself — so they resolve on
						// their own and skip the mapping chain below. Falling
						// through used to read `$post_meta_value` left over from
						// the previous key of the loop.
						if ( 'opening_hours' == $key ) {
							$schema_datas[ $key ] = seopress_pro_resolve_lb_opening_hours( $id, $value, $schema_name, $seopress_pro_schemas );

							continue;
						}

						// Single datas.
						$post_meta_value = get_post_meta( $id, $value, true );

						// Global datas.
						$manual_global = get_post_meta( $id, $value . '_manual_global', true );

						$manual_img_global         = get_post_meta( $id, $value . '_manual_img_global', true );
						$manual_img_library_global = get_post_meta( $id, $value . '_manual_img_library_global', true );

						$manual_date_global = get_post_meta( $id, $value . '_manual_date_global', true );

						$manual_time_global = get_post_meta( $id, $value . '_manual_time_global', true );

						$manual_rating_global = get_post_meta( $id, $value . '_manual_rating_global', true );

						$manual_custom_global = get_post_meta( $id, $value . '_manual_custom_global', true );

						$cf = get_post_meta( $id, $value . '_cf', true );

						$tax = get_post_meta( $id, $value . '_tax', true );

						$lb = get_post_meta( $id, $value . '_lb', true );

						// From current single post.
						if ( 'manual_single' == $post_meta_value || 'manual_img_single' == $post_meta_value || 'manual_date_single' == $post_meta_value || 'manual_time_single' == $post_meta_value || 'manual_rating_single' == $post_meta_value || 'manual_custom_single' == $post_meta_value ) {
							if ( isset( $seopress_pro_schemas[0][ $id ][ 'rich_snippets_' . $schema_name ][ $key ] ) ) {
								$_post_meta_value = $seopress_pro_schemas[0][ $id ][ 'rich_snippets_' . $schema_name ][ $key ];
							}
						} elseif ( 'manual_global' == $post_meta_value ) {
							if ( '' != $manual_global ) {
								$_post_meta_value = $manual_global;
							}
						} elseif ( 'manual_img_global' == $post_meta_value ) {
							if ( '' != $manual_img_global ) {
								$_post_meta_value = $manual_img_global;
							}
						} elseif ( 'manual_img_library_global' == $post_meta_value ) {
							if ( '' != $manual_img_library_global ) {
								$_post_meta_value = $manual_img_library_global;
							}
						} elseif ( 'manual_date_global' == $post_meta_value ) {
							if ( '' != $manual_date_global ) {
								$_post_meta_value = $manual_date_global;
							}
						} elseif ( 'manual_time_global' == $post_meta_value ) {
							if ( '' != $manual_time_global ) {
								$_post_meta_value = $manual_time_global;
							}
						} elseif ( 'manual_rating_global' == $post_meta_value ) {
							if ( '' != $manual_rating_global ) {
								$_post_meta_value = $manual_rating_global;
							}
						} elseif ( 'manual_custom_global' == $post_meta_value ) {
							if ( '' != $manual_custom_global ) {
								$_post_meta_value = $manual_custom_global;
							}
						} elseif ( 'manual_lb_global' == $post_meta_value ) {
							if ( '' != $lb ) {
								$_post_meta_value = $lb;
							}
						} elseif ( 'custom_fields' == $post_meta_value ) {
							if ( '' != $cf ) {
								$_post_meta_value = get_post_meta( get_the_ID(), $cf, true );
							}
						} elseif ( 'custom_taxonomy' == $post_meta_value ) {
							if ( '' != $tax ) {
								$_post_meta_value = '';
								if ( taxonomy_exists( $tax ) ) {
									$terms = wp_get_post_terms( get_the_ID(), $tax, array( 'fields' => 'names' ) );
									if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
										$_post_meta_value = $terms[0];
									}
								}
							}
						} elseif ( 'none' != $post_meta_value ) { // From schema single post.
							$_post_meta_value = str_replace( $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace, $post_meta_value );
						}

						// Push value to array.
						$schema_datas[ $key ] = $_post_meta_value;
					}

					return $schema_datas;
				}
			}

			require_once __DIR__ . '/schemas/Article.php';
			require_once __DIR__ . '/schemas/LocalBusiness.php';
			require_once __DIR__ . '/schemas/Faq.php';
			require_once __DIR__ . '/schemas/Course.php';
			require_once __DIR__ . '/schemas/Recipe.php';
			require_once __DIR__ . '/schemas/Job.php';
			require_once __DIR__ . '/schemas/Video.php';
			require_once __DIR__ . '/schemas/Event.php';
			require_once __DIR__ . '/schemas/Product.php';
			require_once __DIR__ . '/schemas/SoftwareApp.php';
			require_once __DIR__ . '/schemas/Service.php';
			require_once __DIR__ . '/schemas/Review.php';
			require_once __DIR__ . '/schemas/Custom.php';

			// Dynamic variables.
			global $post;
			global $product;

			// Excerpt length.
			$seopress_excerpt_length = 50;
			$seopress_excerpt_length = apply_filters( 'seopress_excerpt_length', $seopress_excerpt_length );

			// Excerpt.
			$seopress_excerpt = '';
			if ( ! is_404() && '' != $post ) {
				if ( has_excerpt( $post->ID ) ) {
					$seopress_excerpt = get_the_excerpt();
				}
			}
			if ( '' != $seopress_excerpt ) {
				$seopress_get_the_excerpt = wp_trim_words( esc_attr( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $seopress_excerpt ), true ) ) ) ), $seopress_excerpt_length );
			} elseif ( '' != $post ) {
				if ( '' != get_post_field( 'post_content', $post->ID ) ) {
					$seopress_get_the_excerpt = wp_trim_words( esc_attr( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $post->ID ), true ) ) ) ) ), $seopress_excerpt_length );
				} else {
					$seopress_get_the_excerpt = null;
				}
			} else {
				$seopress_get_the_excerpt = null;
			}

			if ( '' != $post ) {
				if ( '' != get_post_field( 'post_content', $post->ID ) ) {
					$seopress_get_the_content = wp_trim_words( esc_attr( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $post->ID ), true ) ) ) ) ), $seopress_excerpt_length );
				} else {
					$seopress_get_the_content = null;
				}
			} else {
				$seopress_get_the_content = null;
			}

			// Author name.
			$the_author_meta = '';
			$the_author_meta = get_the_author_meta( 'display_name', $post->post_author );

			// Date on sale from.
			$get_date_on_sale_from = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_date_on_sale_from' ) ) {
				$get_date_on_sale_from = $product->get_date_on_sale_from();
				if ( '' != $get_date_on_sale_from ) {
					$get_date_on_sale_from = $get_date_on_sale_from->date( 'm-d-Y' );
				}
			}

			// Date on sale to.
			$get_date_on_sale_to = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_date_on_sale_to' ) ) {
				$get_date_on_sale_to = $product->get_date_on_sale_to();
				if ( '' != $get_date_on_sale_to ) {
					$get_date_on_sale_to = $get_date_on_sale_to->date( 'm-d-Y' );
				}
			}

			// Product cat.
			$product_cat_term_list = '';
			if ( taxonomy_exists( 'product_cat' ) ) {
				$terms = wp_get_post_terms( get_the_ID(), 'product_cat', array( 'fields' => 'names' ) );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					$product_cat_term_list = $terms[0];
				}
			}

			// Regular price.
			$get_regular_price = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_regular_price' ) ) {
				$get_regular_price = $product->get_regular_price();
			}

			// Sale price.
			$get_sale_price = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_sale_price' ) ) {
				$get_sale_price = $product->get_sale_price();
			}

			// Sale price with tax (regular price as fallback if not available).
			$get_sale_price_with_tax = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_price' ) && function_exists( 'wc_get_price_including_tax' ) ) {
				$get_sale_price_with_tax = wc_get_price_including_tax( $product, array( 'price' => $get_sale_price ) );
			}

			// SKU.
			$get_sku = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_sku' ) ) {
				$get_sku = $product->get_sku();
			}

			// Barcode type.
			$get_barcode_type = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_id' ) && get_post_meta( $product->get_id(), 'sp_wc_barcode_type_field', true ) ) {
				$get_barcode_type = get_post_meta( $product->get_id(), 'sp_wc_barcode_type_field', true );
			}

			// Barcode.
			$get_barcode = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'get_id' ) && get_post_meta( $product->get_id(), 'sp_wc_barcode_field', true ) ) {
				$get_barcode = get_post_meta( $product->get_id(), 'sp_wc_barcode_field', true );
			}

			// Stock.
			$get_stock = '';
			if ( isset( $product ) && is_object( $product ) && method_exists( $product, 'managing_stock' ) && true === $product->managing_stock() ) { // If managing stock.
				if ( method_exists( $product, 'is_in_stock' ) && true === $product->is_in_stock() ) {
					$get_stock = seopress_check_ssl() . 'schema.org/InStock';
				} else { // If out of stock.
					$get_stock = seopress_check_ssl() . 'schema.org/OutOfStock';
				}
			} elseif ( isset( $product ) && is_object( $product ) && method_exists( $product, 'managing_stock' ) && false === $product->managing_stock() && method_exists( $product, 'get_stock_status' ) && $product->get_stock_status() ) {
				if ( 'instock' == $product->get_stock_status() ) {
					$get_stock = seopress_check_ssl() . 'schema.org/InStock';
				} else { // If out of stock.
					$get_stock = seopress_check_ssl() . 'schema.org/OutOfStock';
				}
			}

			// YouTube video data (lazy-resolved; empty array when no YouTube URL in content
			// or when the Data API call fails). Used by the Video schema auto-fill and by
			// the %%youtube_video_*%% dynamic variables.
			$seopress_youtube_video_data = function_exists( 'seopress_pro_get_post_youtube_video_data' )
				? seopress_pro_get_post_youtube_video_data( isset( $post->ID ) ? $post->ID : null )
				: array();

			$sp_schemas_dyn_variables = array(
				'site_title',
				'tagline',
				'site_url',
				'post_id',
				'post_title',
				'post_excerpt',
				'post_content',
				'post_permalink',
				'post_author_name',
				'post_date',
				'post_updated',
				'knowledge_graph_logo',
				'post_thumbnail',
				'post_author_picture',
				'product_regular_price',
				'product_sale_price',
				'product_price_with_tax',
				'product_date_from',
				'product_date_to',
				'product_sku',
				'product_barcode_type',
				'product_barcode',
				'product_category',
				'product_stock',
				'youtube_video_title',
				'youtube_video_description',
				'youtube_video_thumbnail',
				'youtube_video_duration',
				'youtube_video_upload_date',
				'youtube_video_view_count',
				'youtube_video_content_url',
				'youtube_video_embed_url',
			);

			$sp_schemas_dyn_variables = apply_filters( 'seopress_schemas_dyn_variables', $sp_schemas_dyn_variables );

			$sp_schemas_dyn_variables_replace = array(
				get_bloginfo( 'name' ),
				get_bloginfo( 'description' ),
				get_home_url(),
				get_the_ID(),
				the_title_attribute( 'echo=0' ),
				$seopress_get_the_excerpt,
				$seopress_get_the_content,
				get_permalink(),
				$the_author_meta,
				get_the_date( 'c' ),
				get_the_modified_date( 'c' ),
				seopress_get_service( 'SocialOption' )->getSocialKnowledgeImage(),
				get_the_post_thumbnail_url( $post, apply_filters( 'seopress_schemas_post_thumbnail_size', 'full' ) ),
				get_avatar_url( get_the_author_meta( 'ID' ) ),
				$get_regular_price,
				$get_sale_price,
				$get_sale_price_with_tax,
				$get_date_on_sale_from,
				$get_date_on_sale_to,
				$get_sku,
				$get_barcode_type,
				$get_barcode,
				$product_cat_term_list,
				$get_stock,
				isset( $seopress_youtube_video_data['name'] ) ? $seopress_youtube_video_data['name'] : '',
				isset( $seopress_youtube_video_data['description'] ) ? $seopress_youtube_video_data['description'] : '',
				isset( $seopress_youtube_video_data['img'] ) ? $seopress_youtube_video_data['img'] : '',
				isset( $seopress_youtube_video_data['duration'] ) ? $seopress_youtube_video_data['duration'] : '',
				isset( $seopress_youtube_video_data['upload_date'] ) ? $seopress_youtube_video_data['upload_date'] : '',
				isset( $seopress_youtube_video_data['view_count'] ) ? $seopress_youtube_video_data['view_count'] : '',
				isset( $seopress_youtube_video_data['content_url'] ) ? $seopress_youtube_video_data['content_url'] : '',
				isset( $seopress_youtube_video_data['embed_url'] ) ? $seopress_youtube_video_data['embed_url'] : '',
			);

			$sp_schemas_dyn_variables_replace = apply_filters( 'seopress_schemas_dyn_variables_replace', $sp_schemas_dyn_variables_replace );

			// Request schemas based on post type / rules.
			$args = array(
				'post_type'      => 'seopress_schemas',
				'posts_per_page' => -1,
			);

			$sp_schemas_query = new WP_Query( $args );
			$current_post     = $post;
			$sp_schemas_ids   = array();

			if ( $sp_schemas_query->have_posts() ) {
				while ( $sp_schemas_query->have_posts() ) {
					$sp_schemas_query->the_post();
					if ( get_post_meta( get_the_ID(), '_seopress_pro_rich_snippets_rules', true ) &&
						seopress_is_content_valid_for_schemas( $current_post->ID ) ) {
						$sp_schemas_ids[] = get_the_ID();
					}
				}
			}
			wp_reset_postdata();

			if ( ! empty( $sp_schemas_ids ) ) {
				foreach ( $sp_schemas_ids as $id ) {
					// Datas.
					$schema_datas = array();

					// Type.
					$seopress_pro_rich_snippets_type = get_post_meta( $id, '_seopress_pro_rich_snippets_type', true );

					// Datas.
					$seopress_pro_schemas = get_post_meta( $post->ID, '_seopress_pro_schemas' );

					$disable = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_disable', true );
					if ( is_array( $disable ) && array_key_exists( $id, $disable ) ) {
						continue;
					}

					// Article.
					if ( 'articles' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'article';

						$post_meta_key = array(
							'type'                => '_seopress_pro_rich_snippets_article_type',
							'title'               => '_seopress_pro_rich_snippets_article_title',
							'desc'                => '_seopress_pro_rich_snippets_article_desc',
							'author'              => '_seopress_pro_rich_snippets_article_author',
							'img'                 => '_seopress_pro_rich_snippets_article_img',
							'coverage_start_date' => '_seopress_pro_rich_snippets_article_coverage_start_date',
							'coverage_start_time' => '_seopress_pro_rich_snippets_article_coverage_start_time',
							'coverage_end_date'   => '_seopress_pro_rich_snippets_article_coverage_end_date',
							'coverage_end_time'   => '_seopress_pro_rich_snippets_article_coverage_end_time',
							'speakable'           => '_seopress_pro_rich_snippets_article_speakable',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_articles_option( $schema_datas );
					}

					// Local Business.
					if ( 'localbusiness' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'lb';

						$post_meta_key = array(
							'name'                 => '_seopress_pro_rich_snippets_lb_name',
							'type'                 => '_seopress_pro_rich_snippets_lb_type',
							'img'                  => '_seopress_pro_rich_snippets_lb_img',
							'street_addr'          => '_seopress_pro_rich_snippets_lb_street_addr',
							'city'                 => '_seopress_pro_rich_snippets_lb_city',
							'state'                => '_seopress_pro_rich_snippets_lb_state',
							'pc'                   => '_seopress_pro_rich_snippets_lb_pc',
							'country'              => '_seopress_pro_rich_snippets_lb_country',
							'lat'                  => '_seopress_pro_rich_snippets_lb_lat',
							'lon'                  => '_seopress_pro_rich_snippets_lb_lon',
							'website'              => '_seopress_pro_rich_snippets_lb_website',
							'tel'                  => '_seopress_pro_rich_snippets_lb_tel',
							'price'                => '_seopress_pro_rich_snippets_lb_price',
							'serves_cuisine'       => '_seopress_pro_rich_snippets_lb_serves_cuisine',
							'menu'                 => '_seopress_pro_rich_snippets_lb_menu',
							'accepts_reservations' => '_seopress_pro_rich_snippets_lb_accepts_reservations',
							'opening_hours'        => '_seopress_pro_rich_snippets_lb_opening_hours',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_lb_option( $schema_datas );
					}

					// FAQ.
					if ( 'faq' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'faq';

						$post_meta_key = array(
							'q' => '_seopress_pro_rich_snippets_faq_q',
							'a' => '_seopress_pro_rich_snippets_faq_a',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_faq_option( $schema_datas );
					}

					// Courses.
					if ( 'courses' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'courses';

						$post_meta_key = array(
							'title'     => '_seopress_pro_rich_snippets_courses_title',
							'desc'      => '_seopress_pro_rich_snippets_courses_desc',
							'school'    => '_seopress_pro_rich_snippets_courses_school',
							'website'   => '_seopress_pro_rich_snippets_courses_website',
							'offers'    => '_seopress_pro_rich_snippets_courses_offers',
							'instances' => '_seopress_pro_rich_snippets_courses_instances',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_courses_option( $schema_datas );
					}

					// Recipes.
					if ( 'recipes' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'recipes';

						$post_meta_key = array(
							'name'         => '_seopress_pro_rich_snippets_recipes_name',
							'desc'         => '_seopress_pro_rich_snippets_recipes_desc',
							'cat'          => '_seopress_pro_rich_snippets_recipes_cat',
							'img'          => '_seopress_pro_rich_snippets_recipes_img',
							'video'        => '_seopress_pro_rich_snippets_recipes_video',
							'prep_time'    => '_seopress_pro_rich_snippets_recipes_prep_time',
							'cook_time'    => '_seopress_pro_rich_snippets_recipes_cook_time',
							'calories'     => '_seopress_pro_rich_snippets_recipes_calories',
							'yield'        => '_seopress_pro_rich_snippets_recipes_yield',
							'keywords'     => '_seopress_pro_rich_snippets_recipes_keywords',
							'cuisine'      => '_seopress_pro_rich_snippets_recipes_cuisine',
							'ingredient'   => '_seopress_pro_rich_snippets_recipes_ingredient',
							'instructions' => '_seopress_pro_rich_snippets_recipes_instructions',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_recipes_option( $schema_datas );
					}

					// Jobs.
					if ( 'jobs' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'jobs';

						$post_meta_key = array(
							'name'                 => '_seopress_pro_rich_snippets_jobs_name',
							'desc'                 => '_seopress_pro_rich_snippets_jobs_desc',
							'date_posted'          => '_seopress_pro_rich_snippets_jobs_date_posted',
							'valid_through'        => '_seopress_pro_rich_snippets_jobs_valid_through',
							'employment_type'      => '_seopress_pro_rich_snippets_jobs_employment_type',
							'identifier_name'      => '_seopress_pro_rich_snippets_jobs_identifier_name',
							'identifier_value'     => '_seopress_pro_rich_snippets_jobs_identifier_value',
							'hiring_organization'  => '_seopress_pro_rich_snippets_jobs_hiring_organization',
							'hiring_same_as'       => '_seopress_pro_rich_snippets_jobs_hiring_same_as',
							'hiring_logo'          => '_seopress_pro_rich_snippets_jobs_hiring_logo',
							'hiring_logo_width'    => '_seopress_pro_rich_snippets_jobs_hiring_logo_width',
							'hiring_logo_height'   => '_seopress_pro_rich_snippets_jobs_hiring_logo_height',
							'address_street'       => '_seopress_pro_rich_snippets_jobs_address_street',
							'address_locality'     => '_seopress_pro_rich_snippets_jobs_address_locality',
							'address_region'       => '_seopress_pro_rich_snippets_jobs_address_region',
							'postal_code'          => '_seopress_pro_rich_snippets_jobs_postal_code',
							'country'              => '_seopress_pro_rich_snippets_jobs_country',
							'remote'               => '_seopress_pro_rich_snippets_jobs_remote',
							'location_requirement' => '_seopress_pro_rich_snippets_jobs_location_requirement',
							'direct_apply'         => '_seopress_pro_rich_snippets_jobs_direct_apply',
							'salary'               => '_seopress_pro_rich_snippets_jobs_salary',
							'salary_currency'      => '_seopress_pro_rich_snippets_jobs_salary_currency',
							'salary_unit'          => '_seopress_pro_rich_snippets_jobs_salary_unit',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_jobs_option( $schema_datas );
					}

					// Videos.
					if ( 'videos' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'videos';

						$post_meta_key = array(
							'name'        => '_seopress_pro_rich_snippets_videos_name',
							'description' => '_seopress_pro_rich_snippets_videos_description',
							'date_posted' => '_seopress_pro_rich_snippets_videos_date_posted',
							'img'         => '_seopress_pro_rich_snippets_videos_img',
							'duration'    => '_seopress_pro_rich_snippets_videos_duration',
							'url'         => '_seopress_pro_rich_snippets_videos_url',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// YouTube auto-fill: when the global toggle is on (or the per-schema
						// toggle is set on this specific Video schema entry), fill any empty
						// fields with metadata fetched from the first YouTube video found
						// in the current post's content.
						$videos_yt_autofill_global = '1' === seopress_pro_get_service( 'SitemapOptionPro' )->getVideoSchemaYouTubeEnable();
						$videos_yt_autofill_schema = '1' === get_post_meta( $id, '_seopress_pro_rich_snippets_videos_youtube_autofill', true );

						if ( ( $videos_yt_autofill_global || $videos_yt_autofill_schema ) && ! empty( $seopress_youtube_video_data ) ) {
							if ( ! is_array( $schema_datas ) ) {
								$schema_datas = array();
							}

							$yt_field_map = array(
								'name'        => 'name',
								'description' => 'description',
								'img'         => 'img',
								'duration'    => 'duration',
								'url'         => 'content_url',
								'date_posted' => 'upload_date',
							);

							foreach ( $yt_field_map as $schema_key => $yt_key ) {
								if ( empty( $schema_datas[ $schema_key ] ) && ! empty( $seopress_youtube_video_data[ $yt_key ] ) ) {
									$schema_datas[ $schema_key ] = $seopress_youtube_video_data[ $yt_key ];
								}
							}
						}

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_videos_option( $schema_datas );
					}

					// Events.
					if ( 'events' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'events';

						$post_meta_key = array(
							'type'                   => '_seopress_pro_rich_snippets_events_type',
							'name'                   => '_seopress_pro_rich_snippets_events_name',
							'desc'                   => '_seopress_pro_rich_snippets_events_desc',
							'img'                    => '_seopress_pro_rich_snippets_events_img',
							'start_date'             => '_seopress_pro_rich_snippets_events_start_date',
							'start_date_timezone'    => '_seopress_pro_rich_snippets_events_start_date_timezone',
							'start_time'             => '_seopress_pro_rich_snippets_events_start_time',
							'end_date'               => '_seopress_pro_rich_snippets_events_end_date',
							'end_time'               => '_seopress_pro_rich_snippets_events_end_time',
							'previous_start_date'    => '_seopress_pro_rich_snippets_events_previous_start_date',
							'previous_start_time'    => '_seopress_pro_rich_snippets_events_previous_start_time',
							'location_name'          => '_seopress_pro_rich_snippets_events_location_name',
							'location_url'           => '_seopress_pro_rich_snippets_events_location_url',
							'location_address'       => '_seopress_pro_rich_snippets_events_location_address',
							'offers_name'            => '_seopress_pro_rich_snippets_events_offers_name',
							'offers_cat'             => '_seopress_pro_rich_snippets_events_offers_cat',
							'offers_price'           => '_seopress_pro_rich_snippets_events_offers_price',
							'offers_price_currency'  => '_seopress_pro_rich_snippets_events_offers_price_currency',
							'offers_availability'    => '_seopress_pro_rich_snippets_events_offers_availability',
							'offers_valid_from_date' => '_seopress_pro_rich_snippets_events_offers_valid_from_date',
							'offers_valid_from_time' => '_seopress_pro_rich_snippets_events_offers_valid_from_time',
							'offers_url'             => '_seopress_pro_rich_snippets_events_offers_url',
							'performer'              => '_seopress_pro_rich_snippets_events_performer',
							'organizer_name'         => '_seopress_pro_rich_snippets_events_organizer_name',
							'organizer_url'          => '_seopress_pro_rich_snippets_events_organizer_url',
							'status'                 => '_seopress_pro_rich_snippets_events_status',
							'attendance_mode'        => '_seopress_pro_rich_snippets_events_attendance_mode',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_events_option( $schema_datas );
					}

					// Products.
					if ( 'products' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'product';

						$post_meta_key = array(
							'name'             => '_seopress_pro_rich_snippets_product_name',
							'description'      => '_seopress_pro_rich_snippets_product_description',
							'img'              => '_seopress_pro_rich_snippets_product_img',
							'price'            => '_seopress_pro_rich_snippets_product_price',
							'price_valid_date' => '_seopress_pro_rich_snippets_product_price_valid_date',
							'sku'              => '_seopress_pro_rich_snippets_product_sku',
							'brand'            => '_seopress_pro_rich_snippets_product_brand',
							'global_ids'       => '_seopress_pro_rich_snippets_product_global_ids',
							'global_ids_value' => '_seopress_pro_rich_snippets_product_global_ids_value',
							'currency'         => '_seopress_pro_rich_snippets_product_price_currency',
							'condition'        => '_seopress_pro_rich_snippets_product_condition',
							'availability'     => '_seopress_pro_rich_snippets_product_availability',
							'positive_notes'   => '_seopress_pro_rich_snippets_product_positive_notes',
							'negative_notes'   => '_seopress_pro_rich_snippets_product_negative_notes',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_products_option( $schema_datas );
					}

					// Software Application.
					if ( 'softwareapp' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'softwareapp';

						$post_meta_key = array(
							'name'       => '_seopress_pro_rich_snippets_softwareapp_name',
							'os'         => '_seopress_pro_rich_snippets_softwareapp_os',
							'cat'        => '_seopress_pro_rich_snippets_softwareapp_cat',
							'price'      => '_seopress_pro_rich_snippets_softwareapp_price',
							'currency'   => '_seopress_pro_rich_snippets_softwareapp_currency',
							'rating'     => '_seopress_pro_rich_snippets_softwareapp_rating',
							'max_rating' => '_seopress_pro_rich_snippets_softwareapp_max_rating',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_softwareapp_option( $schema_datas );
					}

					// Service.
					if ( 'services' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'service';

						$post_meta_key = array(
							'name'              => '_seopress_pro_rich_snippets_service_name',
							'type'              => '_seopress_pro_rich_snippets_service_type',
							'description'       => '_seopress_pro_rich_snippets_service_description',
							'img'               => '_seopress_pro_rich_snippets_service_img',
							'area'              => '_seopress_pro_rich_snippets_service_area',
							'provider_name'     => '_seopress_pro_rich_snippets_service_provider_name',
							'lb_img'            => '_seopress_pro_rich_snippets_service_lb_img',
							'provider_mobility' => '_seopress_pro_rich_snippets_service_provider_mobility',
							'slogan'            => '_seopress_pro_rich_snippets_service_slogan',
							'street_addr'       => '_seopress_pro_rich_snippets_service_street_addr',
							'city'              => '_seopress_pro_rich_snippets_service_city',
							'state'             => '_seopress_pro_rich_snippets_service_state',
							'pc'                => '_seopress_pro_rich_snippets_service_pc',
							'country'           => '_seopress_pro_rich_snippets_service_country',
							'lat'               => '_seopress_pro_rich_snippets_service_lat',
							'lon'               => '_seopress_pro_rich_snippets_service_lon',
							'tel'               => '_seopress_pro_rich_snippets_service_tel',
							'price'             => '_seopress_pro_rich_snippets_service_price',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_services_option( $schema_datas );
					}

					// Review.
					if ( 'review' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'review';

						$post_meta_key = array(
							'item'       => '_seopress_pro_rich_snippets_review_item',
							'item_type'  => '_seopress_pro_rich_snippets_review_item_type',
							'img'        => '_seopress_pro_rich_snippets_review_img',
							'rating'     => '_seopress_pro_rich_snippets_review_rating',
							'max_rating' => '_seopress_pro_rich_snippets_review_max_rating',
							'body'       => '_seopress_pro_rich_snippets_review_body',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_review_option( $schema_datas );
					}

					// Custom.
					if ( 'custom' == $seopress_pro_rich_snippets_type ) {
						// Schema type.
						$schema_name = 'custom';

						$post_meta_key = array(
							'custom' => '_seopress_pro_rich_snippets_custom',
						);

						// Get datas.
						$schema_datas = seopress_automatic_rich_snippets_manual_option( $id, $schema_name, $post_meta_key, $seopress_pro_schemas, $sp_schemas_dyn_variables, $sp_schemas_dyn_variables_replace );

						// Fallback for schemas saved by the React editor without the
						// `_seopress_pro_rich_snippets_custom` = 'manual_custom_global'
						// pointer meta — the body lives under
						// `_seopress_pro_rich_snippets_custom_manual_custom_global`.
						if ( empty( $schema_datas['custom'] ) ) {
							$manual_custom_global = get_post_meta( $id, '_seopress_pro_rich_snippets_custom_manual_custom_global', true );
							if ( '' !== $manual_custom_global && null !== $manual_custom_global ) {
								$schema_datas['custom'] = $manual_custom_global;
							}
						}

						// Output schema in JSON-LD.
						seopress_automatic_rich_snippets_custom_option( $schema_datas );
					}
				}
			}
		}
	}
}

/**
 * Check of the post is valid for any schema.
 *
 * @since 3.8.1
 *
 * @author Julio Potier
 *
 * @param int $post_id The post ID.
 *
 * @return bool
 **/
function seopress_is_content_valid_for_schemas( $post_id ) {
	$_post  = get_post( $post_id );
	$_cpt   = get_post_type( $_post );
	$_taxos = get_post_taxonomies( $_post );

	$service_wp_data = seopress_get_service( 'WordPressData' );
	$_terms        = array();
	if ( $service_wp_data && method_exists( $service_wp_data, 'getTaxonomies' ) ) {
		$_terms = array_flip( wp_list_pluck( wp_get_post_terms( $post_id, array_keys( $service_wp_data->getTaxonomies() ) ), 'term_id' ) );
	}

	$rules = get_post_meta( get_the_ID(), '_seopress_pro_rich_snippets_rules', true );
	if ( ! is_array( $rules ) ) {
		$rules = seopress_get_default_schemas_rules( $rules );
	}
	$conditions = seopress_get_schemas_conditions();
	$filters    = seopress_get_schemas_filters();
	$html       = '';
	foreach ( $rules as $or => $values ) {
		$flag = 0;
		foreach ( $values as $and => $value ) {
			$filter = $filters[ $value['filter'] ];
			$cond   = $conditions[ $value['cond'] ];
			if ( 'post_type' === $value['filter'] && post_type_exists( $value['cpt'] ) &&
				( ( $value['cpt'] === $_cpt && 'equal' === $value['cond'] ) || ( $value['cpt'] !== $_cpt && 'not_equal' === $value['cond'] ) )
			) {
				++$flag;
			}
			if ( 'taxonomy' === $value['filter'] && term_exists( (int) $value['taxo'] ) &&
				( ( isset( $_terms[ $value['taxo'] ] ) && 'equal' === $value['cond'] ) || ( ! isset( $_terms[ $value['taxo'] ] ) && 'not_equal' === $value['cond'] ) )
			) {
				++$flag;
			}
			if (
				'postId' === $value['filter'] &&
				( ( (int) $value['postId'] === (int) $post_id && 'equal' === $value['cond'] ) || ( (int) $value['postId'] !== (int) $post_id && 'not_equal' === $value['cond'] ) )
			) {
				++$flag;
			}

			if ( count( $values ) === $flag ) {
				return true;
			}
		}
	}

	return false;
}
