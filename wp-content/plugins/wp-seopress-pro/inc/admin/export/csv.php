<?php
/**
 * Export SEOPress metadata to CSV
 *
 * @package SEOPress
 * @subpackage Export
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * WPML compatibility
 *
 * @return void
 */
add_filter(
	'seopress_metadata_query_args',
	function ( $args, $seopress_get_post_types, $increment, $offset ) {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $sitepress, $sitepress_settings;

			$sitepress_settings['auto_adjust_ids'] = 0;
			remove_filter( 'terms_clauses', array( $sitepress, 'terms_clauses' ) );
			remove_filter( 'category_link', array( $sitepress, 'category_link_adjust_id' ), 1 );
		}
		return $args;
	},
	10,
	4
);

if ( '{VERSION}' === SEOPRESS_VERSION || version_compare( SEOPRESS_VERSION, '7.9', '>=' ) ) {
	/**
	 * Export SEOPress metadata to CSV
	 *
	 * @return void
	 */
	function seopress_metadata_export() {
		check_ajax_referer( 'seopress_export_csv_metadata_nonce', '_ajax_nonce', true );

		if ( ! is_admin() ) {
			wp_send_json_error();

			return;
		}

		if ( ! current_user_can( seopress_capability( 'manage_options', 'migration' ) ) ) {
			wp_send_json_error();

			return;
		}

		$offset = 0;
		if ( isset( $_POST['offset'] ) ) {
			$offset = absint( $_POST['offset'] );
		}

		$post_export = '';
		if ( isset( $_POST['post_export'] ) ) {
			$post_export = esc_attr( $_POST['post_export'] );
		}

		$term_export = '';
		if ( isset( $_POST['term_export'] ) ) {
			$term_export = esc_attr( $_POST['term_export'] );
		}

		$cpt_export = array();
		if ( isset( $_POST['cptData'] ) && is_array( $_POST['cptData'] ) ) {
			foreach ( wp_unslash( $_POST['cptData'] ) as $formData ) {
				if ( isset( $formData['name'], $formData['value'] ) && 'post_types[]' === $formData['name'] ) {
					$post_type = sanitize_text_field( $formData['value'] );
					// Only keep registered post types so the value can never reach the count query below with injectable characters.
					if ( post_type_exists( $post_type ) ) {
						$cpt_export[] = $post_type;
					}
				}
			}
		}

		$tax_export = array();
		if ( isset( $_POST['taxData'] ) && is_array( $_POST['taxData'] ) ) {
			foreach ( wp_unslash( $_POST['taxData'] ) as $formData ) {
				if ( isset( $formData['name'], $formData['value'] ) && 'taxonomies[]' === $formData['name'] ) {
					$taxonomy = sanitize_text_field( $formData['value'] );
					// Only keep registered taxonomies.
					if ( taxonomy_exists( $taxonomy ) ) {
						$tax_export[] = $taxonomy;
					}
				}
			}
		}

		// Get post types.
		$seopress_get_post_types = isset( $cpt_export ) ? $cpt_export : array();

		// Get taxonomies.
		$seopress_get_taxonomies = isset( $tax_export ) ? $tax_export : array();

		if ( empty( $seopress_get_post_types ) && empty( $seopress_get_taxonomies ) ) {
			wp_send_json_error();

			return;
		}

		// Fresh export cycle: clear any stale rows left over from a
		// previous run that crashed before the final cleanup step
		// (otherwise each subsequent export reloads the bloated array
		// from wp_options and trips the MySQL max_allowed_packet limit).
		if ( 0 === $offset && '' === $post_export && '' === $term_export ) {
			delete_option( 'seopress_metadata_csv' );
		}

		global $wpdb;
		global $post;

		// Count posts.
		$count_items = 0;

		$post_status_in = "post_status IN ( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash' )";

		if ( empty( $seopress_get_post_types ) ) {
			$total_count_posts = (int) $wpdb->get_var(
				"SELECT count(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND $post_status_in" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		} else {
			// Build a placeholder list so the (already whitelisted) post types are bound, never interpolated.
			$placeholders = implode( ', ', array_fill( 0, count( $seopress_get_post_types ), '%s' ) );

			$total_count_posts = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT count(*) FROM {$wpdb->posts} WHERE post_type IN ( $placeholders ) AND $post_status_in", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$seopress_get_post_types
				)
			);
		}

		// Count terms.
		$total_count_terms = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->terms}" );

		$increment = 200;

		$csv          = get_option( 'seopress_metadata_csv', array() );
		$download_url = '';

		$settings['id']              = array();
		$settings['post_title']      = array();
		$settings['url']             = array();
		$settings['slug']            = array();
		$settings['taxonomy']        = array();
		$settings['post_type']       = array();
		$settings['meta_title']      = array();
		$settings['meta_desc']       = array();
		$settings['fb_title']        = array();
		$settings['fb_desc']         = array();
		$settings['fb_img']          = array();
		$settings['tw_title']        = array();
		$settings['tw_desc']         = array();
		$settings['tw_img']          = array();
		$settings['noindex']         = array();
		$settings['nofollow']        = array();
		$settings['noimageindex']    = array();
		$settings['nosnippet']       = array();
		$settings['canonical_url']   = array();
		$settings['primary_cat']     = array();
		$settings['redirect_active'] = array();
		$settings['redirect_status'] = array();
		$settings['redirect_type']   = array();
		$settings['redirect_url']    = array();
		$settings['target_kw']       = array();
		$settings['breadcrumbs']     = array();

		$metas_key = array(
			'meta_title'         => '_seopress_titles_title',
			'meta_desc'          => '_seopress_titles_desc',
			'fb_title'           => '_seopress_social_fb_title',
			'fb_desc'            => '_seopress_social_fb_desc',
			'fb_img'             => '_seopress_social_fb_img',
			'tw_title'           => '_seopress_social_twitter_title',
			'tw_desc'            => '_seopress_social_twitter_desc',
			'tw_img'             => '_seopress_social_twitter_img',
			'noindex'            => '_seopress_robots_index',
			'nofollow'           => '_seopress_robots_follow',
			'noimageindex'       => '_seopress_robots_imageindex',
			'nosnippet'          => '_seopress_robots_snippet',
			'canonical_url'      => '_seopress_robots_canonical',
			'primary_cat'        => '_seopress_robots_primary_cat',
			'redirect_active'    => '_seopress_redirections_enabled',
			'redirect_status'    => '_seopress_redirections_logged_status',
			'redirect_type'      => '_seopress_redirections_type',
			'redirect_url'       => '_seopress_redirections_value',
			'target_kw'          => '_seopress_analysis_target_kw',
			'breadcrumbs'        => '_seopress_robots_breadcrumbs',
		);

		// Posts.
		if ( 'done' != $post_export ) {
			if ( $offset > $total_count_posts || empty( $seopress_get_post_types ) ) {
				wp_reset_postdata();
				$count_items = $total_count_posts;
				// Reset offset once Posts export is done.
				$offset = 0;
				update_option( 'seopress_metadata_csv', $csv, false );
				$post_export = 'done';
			} else {
				$args       = array(
					'post_type'      => $seopress_get_post_types,
					'posts_per_page' => $increment,
					'offset'         => $offset,
					'post_status'    => 'any',
					'order'          => 'DESC',
					'orderby'        => 'date',
				);
				$args       = apply_filters( 'seopress_metadata_query_args', $args, $seopress_get_post_types, $increment, $offset );
				$meta_query = get_posts( $args );

				if ( $meta_query ) {
					// The Loop.
					foreach ( $meta_query as $post ) {
						array_push( $settings['id'], $post->ID );

						array_push( $settings['post_title'], $post->post_title );

						array_push( $settings['url'], get_permalink( $post ) );

						array_push( $settings['slug'], $post->post_name );

						array_push( $settings['taxonomy'], '' );

						array_push( $settings['post_type'], $post->post_type );

						foreach ( $metas_key as $key => $meta_key ) {
							$meta = get_post_meta( $post->ID, $meta_key, true );
							if ( isset( $meta ) && false !== $meta ) {
								array_push( $settings[ $key ], get_post_meta( $post->ID, $meta_key, true ) );
							} else {
								array_push( $settings[ $key ], '' );
							}
						}

						$csv[] = array_merge(
							$settings['id'],
							$settings['post_title'],
							$settings['url'],
							$settings['slug'],
							$settings['taxonomy'],
							$settings['post_type'],
							$settings['meta_title'],
							$settings['meta_desc'],
							$settings['fb_title'],
							$settings['fb_desc'],
							$settings['fb_img'],
							$settings['tw_title'],
							$settings['tw_desc'],
							$settings['tw_img'],
							$settings['noindex'],
							$settings['nofollow'],
							$settings['noimageindex'],
							$settings['nosnippet'],
							$settings['canonical_url'],
							$settings['primary_cat'],
							$settings['redirect_active'],
							$settings['redirect_status'],
							$settings['redirect_type'],
							$settings['redirect_url'],
							$settings['target_kw'],
							$settings['breadcrumbs']
						);

						// Clean arrays.
						$settings['id']              = array();
						$settings['post_title']      = array();
						$settings['url']             = array();
						$settings['slug']            = array();
						$settings['taxonomy']        = array();
						$settings['post_type']       = array();
						$settings['meta_title']      = array();
						$settings['meta_desc']       = array();
						$settings['fb_title']        = array();
						$settings['fb_desc']         = array();
						$settings['fb_img']          = array();
						$settings['tw_title']        = array();
						$settings['tw_desc']         = array();
						$settings['tw_img']          = array();
						$settings['noindex']         = array();
						$settings['nofollow']        = array();
						$settings['noimageindex']    = array();
						$settings['nosnippet']       = array();
						$settings['canonical_url']   = array();
						$settings['primary_cat']     = array();
						$settings['redirect_active'] = array();
						$settings['redirect_status'] = array();
						$settings['redirect_type']   = array();
						$settings['redirect_url']    = array();
						$settings['target_kw']       = array();
						$settings['breadcrumbs']     = array();
					}
				}
				$offset += $increment;

				if ( $offset >= $total_count_posts ) {
					$count_items = $total_count_posts;
				} else {
					$count_items = $offset;
				}

				update_option( 'seopress_metadata_csv', $csv, false );
			}
		} elseif ( 'done' != $term_export ) {
			// Terms.
			if ( $offset > $total_count_terms || empty( $seopress_get_taxonomies ) ) {
				$count_items = $total_count_terms + $total_count_posts;
				update_option( 'seopress_metadata_csv', $csv, false );
				$post_export = 'done';
				$term_export = 'done';
			} else {
				$args = array(
					'taxonomy'   => $seopress_get_taxonomies,
					'number'     => $increment,
					'offset'     => $offset,
					'order'      => 'DESC',
					'orderby'    => 'date',
					'hide_empty' => false,
				);

				$args = apply_filters( 'seopress_metadata_query_terms_args', $args, $seopress_get_taxonomies, $increment, $offset );

				$meta_query = get_terms( $args );

				if ( $meta_query ) {
					// The Loop.
					foreach ( $meta_query as $term ) {
						if ( is_wp_error( $term ) ) {
							continue;
						}
						if ( ! is_object( $term ) ) {
							continue;
						}
						array_push( $settings['id'], $term->term_id );

						array_push( $settings['post_title'], $term->name );

						array_push( $settings['url'], get_term_link( $term ) );

						array_push( $settings['slug'], $term->slug );

						array_push( $settings['taxonomy'], $term->taxonomy );

						array_push( $settings['post_type'], '' );

						foreach ( $metas_key as $key => $meta_key ) {
							if ( get_term_meta( $term->term_id, $meta_key, true ) ) {
								array_push( $settings[ $key ], get_term_meta( $term->term_id, $meta_key, true ) );
							} else {
								array_push( $settings[ $key ], '' );
							}
						}

						$csv[] = array_merge(
							$settings['id'],
							$settings['post_title'],
							$settings['url'],
							$settings['slug'],
							$settings['taxonomy'],
							$settings['post_type'],
							$settings['meta_title'],
							$settings['meta_desc'],
							$settings['fb_title'],
							$settings['fb_desc'],
							$settings['fb_img'],
							$settings['tw_title'],
							$settings['tw_desc'],
							$settings['tw_img'],
							$settings['noindex'],
							$settings['nofollow'],
							$settings['noimageindex'],
							$settings['nosnippet'],
							$settings['canonical_url'],
							$settings['primary_cat'],
							$settings['redirect_active'],
							$settings['redirect_status'],
							$settings['redirect_type'],
							$settings['redirect_url'],
							$settings['target_kw'],
							$settings['breadcrumbs']
						);

						// Clean arrays.
						$settings['id']              = array();
						$settings['post_title']      = array();
						$settings['url']             = array();
						$settings['slug']            = array();
						$settings['taxonomy']        = array();
						$settings['post_type']       = array();
						$settings['meta_title']      = array();
						$settings['meta_desc']       = array();
						$settings['fb_title']        = array();
						$settings['fb_desc']         = array();
						$settings['fb_img']          = array();
						$settings['tw_title']        = array();
						$settings['tw_desc']         = array();
						$settings['tw_img']          = array();
						$settings['noindex']         = array();
						$settings['nofollow']        = array();
						$settings['noimageindex']    = array();
						$settings['nosnippet']       = array();
						$settings['canonical_url']   = array();
						$settings['primary_cat']     = array();
						$settings['redirect_active'] = array();
						$settings['redirect_status'] = array();
						$settings['redirect_type']   = array();
						$settings['redirect_url']    = array();
						$settings['target_kw']       = array();
						$settings['breadcrumbs']     = array();
					}
				}

				$offset += $increment;

				if ( $offset >= $total_count_terms ) {
					$count_items = $total_count_terms + $total_count_posts;
				} elseif ( $offset === 200 ) {
					$count_items = $total_count_posts + 200;
				} else {
					$count_items += $offset;
				}
				$post_export = 'done';
				update_option( 'seopress_metadata_csv', $csv, false );
			}
		} else {
			$post_export = 'done';
			$term_export = 'done';
		}

		// Create download URL.
		if ( 'done' == $post_export && 'done' == $term_export ) {
			$args = array_merge(
				$_POST,
				array(
					'nonce'           => wp_create_nonce( 'seopress_csv_batch_export_nonce' ),
					'page'            => 'seopress-import-export',
					'seopress_action' => 'seopress_download_batch_export',
				)
			);

			$download_url = add_query_arg( $args, admin_url( 'admin.php' ) );

			$offset = 'done';
		}

		// Return data to JSON.
		$data = array();

		$data['count'] = $count_items;
		$data['total'] = $total_count_posts + $total_count_terms;

		$data['offset']      = $offset;
		$data['url']         = $download_url;
		$data['post_export'] = $post_export;
		$data['term_export'] = $term_export;
		wp_send_json_success( $data );
	}

	add_action( 'wp_ajax_seopress_metadata_export', 'seopress_metadata_export' );
}
