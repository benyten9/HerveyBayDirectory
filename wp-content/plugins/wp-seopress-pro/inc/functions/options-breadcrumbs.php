<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Breadcrumbs.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/*
 * The breadcrumb list identifies itself with the canonical of the page it
 * describes, which this resolves.
 *
 * Required here rather than relied upon: `seopress_schema_current_canonical()`
 * is otherwise only defined when options-automatic-rich-snippets.php loads one
 * of the automatic schema files, which needs the rich snippets toggle on,
 * "Enable Structured Data Types" on, a singular page and no per-post
 * `_disable_all`. Everywhere else, and on every site with structured data off,
 * the function did not exist and the list went out with no identity at all:
 * category and taxonomy archives, author pages, the blog index, the
 * WooCommerce shop. It only ever worked because both callbacks sit on wp_head
 * priority 2 and happened to run in the right order.
 */
require_once __DIR__ . '/schemas/Canonical.php';

/**
 * Display post parent items.
 *
 * @param object $post The post object.
 * @param array  $crumbs The breadcrumbs array.
 *
 * @return array $crumbs
 */
function seopress_breadcrumbs_post_parent( $post, $crumbs ) {
	if ( $post->post_parent ) { // If post has parent pages.
		$parent_id = $post->post_parent;
		while ( $parent_id ) {
			$page      = get_post( $parent_id );
			$parent_id = $page->post_parent;
			if ( '' != get_post_meta( $page->ID, '_seopress_robots_breadcrumbs', true ) ) {
				$parent_crumbs[] = array( wp_strip_all_tags( get_post_meta( $page->ID, '_seopress_robots_breadcrumbs', true ) ), get_permalink( $page->ID ), $page->ID );
			} else {
				$parent_crumbs[] = array( get_the_title( $page->ID ), get_permalink( $page->ID ), $page->ID );
			}
		}

		$parent_crumbs = array_reverse( $parent_crumbs );

		foreach ( $parent_crumbs as $crumb ) {
			$crumbs[] = array(
				0 => $crumb[0],
				1 => $crumb[1],
				2 => $crumb[2],
			);
		}
	}

	return $crumbs;
}

/**
 * The primary term steering the breadcrumb trail for a taxonomy.
 *
 * The trail read `_seopress_robots_primary_cat` whatever the taxonomy was,
 * which is the only key the primary term selector wrote before Free 10.4.0.
 * A site whose breadcrumb taxonomy is its own `doc_cat` or `genre` therefore
 * had a selector that changed nothing: the value went to
 * `_seopress_robots_primary_term_doc_cat` and nothing read it.
 *
 * `PrimaryTerm::get_primary_term()` answers the right key for the taxonomy,
 * legacy one included, and already refuses a term that no longer exists, no
 * longer belongs to the taxonomy or is no longer assigned to the post, which
 * is what the inline checks here were doing.
 *
 * Guarded: PRO can run against a Free version that predates the helper, and
 * the legacy read is what that version wrote.
 *
 * @since 10.4.0
 *
 * @param \WP_Post|int $post     The post.
 * @param string       $taxonomy The taxonomy the trail is built from.
 *
 * @return \WP_Term|null
 */
function seopress_breadcrumbs_primary_term( $post, $taxonomy ) {
	$post = get_post( $post );

	if ( ! $post instanceof WP_Post || empty( $taxonomy ) ) {
		return null;
	}

	$helper = '\SEOPress\Helpers\Metas\PrimaryTerm';

	// is_callable() rather than class_exists(): it autoloads too, and answers
	// false if the method is gone, where a call would be a fatal on every
	// page showing the trail.
	if ( ! is_callable( array( $helper, 'get_primary_term' ) ) || ! is_callable( array( $helper, 'get_meta_key' ) ) ) {
		return seopress_breadcrumbs_legacy_primary_term( $post, $taxonomy );
	}

	$term = $helper::get_primary_term( $post->ID, $taxonomy );

	if ( $term instanceof WP_Term ) {
		return $term;
	}

	// Before 10.4.0 the legacy key steered whatever the breadcrumb taxonomy
	// was, and it is still written for any post type by the CSV import, the
	// robots REST route and sites using `seopress_primary_category_list` to
	// offer their own taxonomy. Keep reading it while this taxonomy has no
	// key of its own.
	$key = $helper::get_meta_key( $taxonomy, $post->post_type );

	if ( $helper::LEGACY_META_KEY !== $key && ! metadata_exists( 'post', $post->ID, $key ) ) {
		return seopress_breadcrumbs_legacy_primary_term( $post, $taxonomy );
	}

	return null;
}

/**
 * The primary term as a Free older than 10.4.0 stored it.
 *
 * One key for every taxonomy, read whatever the breadcrumb taxonomy is and
 * validated against it. Used with a Free that predates the helper, and with
 * the helper for a taxonomy that has no key of its own yet.
 *
 * @since 10.4.0
 *
 * @param \WP_Post|int $post     The post.
 * @param string       $taxonomy The taxonomy the trail is built from.
 *
 * @return \WP_Term|null
 */
function seopress_breadcrumbs_legacy_primary_term( $post, $taxonomy ) {
	$post = get_post( $post );

	if ( ! $post instanceof WP_Post || empty( $taxonomy ) ) {
		return null;
	}

	$stored = get_post_meta( $post->ID, '_seopress_robots_primary_cat', true );

	// A real guard, not only an early exit: has_term() answers true for an
	// empty term list as soon as the post has any term, so a 0 would get
	// past it. Only the get_term() check below would then catch it.
	if ( ! is_scalar( $stored ) || ! is_numeric( $stored ) || 0 >= (int) $stored ) {
		return null;
	}

	if ( ! has_term( (int) $stored, $taxonomy, $post->ID ) ) {
		return null;
	}

	$term = get_term( (int) $stored, $taxonomy );

	return $term instanceof WP_Term ? $term : null;
}

/**
 * Display Term archive link.
 *
 * @param object $post The post object.
 * @param array  $crumbs The breadcrumbs array.
 * @param array  $options The options array.
 *
 * @return array $crumbs
 */
function seopress_breadcrumbs_term_link( $post, $crumbs, $options ) {
	$cpt      = get_post_type( $post );
	$taxonomy = isset( $options['seopress_breadcrumbs_tax'][ $cpt ]['tax'] ) ? $options['seopress_breadcrumbs_tax'][ $cpt ]['tax'] : null;

	$terms_crumbs = array();

	if ( 'none' != $taxonomy && null != $taxonomy ) { // If taxonomy set from breadcrumbs option.
		$primary_term = seopress_breadcrumbs_primary_term( $post, $taxonomy );

		if ( null !== $primary_term ) {
			// The query for its children that used to run here could not change
			// the result: the primary term was unshifted in front of them and
			// current() always returned it.
			$tax = $primary_term;
		} else {
			$terms = wp_get_post_terms(
				$post->ID,
				$taxonomy,
				array(
					'orderby' => 'parent',
					'order'   => 'DESC',
				)
			);
			$terms = function_exists( 'seopress_maybe_mangled_object_vars' ) ? seopress_maybe_mangled_object_vars( $terms ) : $terms; // Prevent PHP 8.1 warning.
			$tax   = current( $terms );
		}

		if ( isset( $tax->term_id ) ) {
			$ancestors_cat = get_ancestors( $tax->term_id, $taxonomy );

			$ancestors_crumb = array_reverse( $ancestors_cat );

			if ( ! empty( $ancestors_crumb ) ) {
				foreach ( $ancestors_crumb as $key => $value ) {
					$term    = get_term( $value, $taxonomy );
					$term_id = $term->term_id;
					$term    = $term->name;

					if ( '' != get_term_meta( $value, '_seopress_robots_breadcrumbs', true ) ) {
						$term = get_term_meta( $value, '_seopress_robots_breadcrumbs', true );
					}

					$terms_crumbs[] = array(
						0 => wp_strip_all_tags( $term ),
						1 => get_term_link( $value ),
						2 => $term_id,
					);
				}
			}

			if ( $tax ) {
				$tax_name = $tax->name;

				if ( '' != get_term_meta( $tax->term_id, '_seopress_robots_breadcrumbs', true ) ) {
					$tax_name = get_term_meta( $tax->term_id, '_seopress_robots_breadcrumbs', true );
				}

				$terms_crumbs[] = array(
					0 => wp_strip_all_tags( $tax_name ),
					1 => get_term_link( $tax ),
					2 => $tax->term_id,
				);
			}
		}
	}

	$terms_crumbs = apply_filters( 'seopress_pro_breadcrumbs_terms', $terms_crumbs );

	$crumbs = array_merge( $crumbs, $terms_crumbs );

	return $crumbs;
}

/**
 * The Breadcrumbs
 */
if ( '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsEnable() || '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsJsonEnable() ) {
	/**
	 * Inline CSS in head.
	 *
	 * @param string $empty The empty string.
	 * @param bool   $echo The echo.
	 *
	 * @return string $inline_css
	 */
	function seopress_breadcrumbs_inline_css( $empty = '', $echo = true ) {
		$seopress_display_breadcrumbs_separator = seopress_get_breadcrumbs_separator();
		$inline_css                             = "<style>.breadcrumb {list-style:none;margin:0;padding-inline-start:0;}.breadcrumb li {margin:0;display:inline-block;position:relative;}.breadcrumb li::after{content:'" . $seopress_display_breadcrumbs_separator . "';margin-left:5px;margin-right:5px;}.breadcrumb li:last-child::after{display:none}</style>";
		$inline_css                             = apply_filters( 'seopress_pro_breadcrumbs_css', $inline_css );
		if ( $echo ) {
			echo $inline_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline stylesheet assembled above.
		}
		return $inline_css;
	}
	add_action( 'wp_head', 'seopress_breadcrumbs_inline_css', 30 );

	/**
	 * Returns the seperator
	 *
	 * @return  string  $seperator
	 */
	function seopress_get_breadcrumbs_separator() {
		$seperator = ' - ';
		if ( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsSeparator() ) {
			$seperator = ' ' . seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsSeparator() . ' ';
		}
		return apply_filters( 'seopress_pro_breadcrumbs_sep', $seperator );
	}

	/**
	 * Display breadcrumbs.
	 *
	 * @param bool $echo The echo.
	 *
	 * @return string $breadcrumbs
	 */
	function seopress_display_breadcrumbs( $echo = true ) {
		$page_id = get_option( 'page_for_posts' );
		/**
		 * Internationalization.
		 */
		// Home.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nHome() ) ) {
			$i18n_home = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nHome();
		} else {
			$i18n_home = __( 'Home', 'wp-seopress-pro' );
		}
		// Author.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nAuthor() ) ) {
			$i18n_author = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nAuthor();
		} else {
			$i18n_author = __( 'Author: ', 'wp-seopress-pro' );
		}
		// 404 error.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18n404() ) ) {
			$i18n_404 = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18n404();
		} else {
			$i18n_404 = __( '404 error', 'wp-seopress-pro' );
		}
		// Search results for.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nSearch() ) ) {
			$i18n_search_results = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nSearch() . ' ';
		} else {
			$i18n_search_results = __( 'Search results for: ', 'wp-seopress-pro' );
		}
		// No results.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nNoResults() ) ) {
			$i18n_no_results = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nNoResults();
		} else {
			$i18n_no_results = __( 'No results', 'wp-seopress-pro' );
		}

		// Attachments.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nAttachments() ) ) {
			$i18n_attachments = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nAttachments();
		} else {
			$i18n_attachments = __( 'Attachments', 'wp-seopress-pro' );
		}
		// Paged.
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nPaged() ) ) {
			$i18n_paged = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nPaged();
		} else {
			$i18n_paged = __( 'Page ', 'wp-seopress-pro' );
		}

		// Globals.
		global $post, $wp_query;

		// Init.
		$crumbs  = array();
		$options = get_option( 'seopress_pro_option_name' );

		// Home prefix.
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( function_exists( 'pll_home_url' ) ) {
			$real_home = pll_home_url();
		} else {
			$real_home = get_home_url();
		}

		$crumbs[] = array(
			0 => $i18n_home,
			1 => $real_home,
		);

		// 404.
		if ( is_404() ) {
			$crumbs[] = array(
				0 => $i18n_404,
			);
		}

		// Attachment.
		if ( is_attachment() ) {
			$crumbs[] = array(
				0 => $i18n_attachments,
			);
		}

		// Single.
		if ( is_single() && ( ! is_home() && ! is_front_page() ) ) {
			if ( is_singular( 'tribe_events' ) ) { // Events calendar.
				$queried_object = get_queried_object();
				$post_type      = get_post_type_object( 'tribe_events' );

				$crumbs[] = array(
					0 => $post_type->labels->name,
					1 => esc_url( tribe_get_events_link() ),
				);

				$crumbs = seopress_breadcrumbs_term_link( $post, $crumbs, $options );

				if ( '' != get_post_meta( $queried_object->ID, '_seopress_robots_breadcrumbs', true ) ) {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_post_meta( $queried_object->ID, '_seopress_robots_breadcrumbs', true ) ),
						1 => esc_url( tribe_get_event_link( $queried_object->ID ) ),
						2 => $queried_object->ID,
					);
				} else {
					$crumbs[] = array(
						0 => wp_strip_all_tags( $queried_object->post_title ),
						1 => esc_url( tribe_get_event_link( $queried_object->ID ) ),
						2 => $queried_object->ID,
					);
				}
			} elseif ( is_plugin_active( 'wp-job-manager/wp-job-manager.php' ) && is_singular( 'job_listing' ) && get_theme_support( 'job-manager-templates' ) === false ) { // WP Job Manager.
				$queried_object = get_queried_object();
				$post_type      = get_post_type_object( 'job_listing' );

				if ( ! empty( get_option( 'job_manager_jobs_page_id' ) ) ) {
					if ( '' != get_post_meta( get_option( 'job_manager_jobs_page_id' ), '_seopress_robots_breadcrumbs', true ) ) {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_post_meta( get_option( 'job_manager_jobs_page_id' ), '_seopress_robots_breadcrumbs', true ) ),
							1 => get_the_permalink( get_option( 'job_manager_jobs_page_id' ) ),
							2 => get_option( 'job_manager_jobs_page_id' ),
						);
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_the_title( get_option( 'job_manager_jobs_page_id' ) ) ),
							1 => get_page_link( get_option( 'job_manager_jobs_page_id' ) ),
							2 => get_option( 'job_manager_jobs_page_id' ),
						);
					}
				}

				if ( '' != get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ) {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
				} else {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_the_title() ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
				}
			} elseif ( is_plugin_active( 'wp-job-manager/wp-job-manager.php' ) && is_singular( 'resume' ) && get_theme_support( 'resume-manager-templates' ) === false ) { // WP Job Manager - Resume add-on.
				$queried_object = get_queried_object();
				$post_type      = get_post_type_object( 'resume' );

				if ( ! empty( get_option( 'resume_manager_resumes_page_id' ) ) ) {
					if ( '' != get_post_meta( get_option( 'resume_manager_resumes_page_id' ), '_seopress_robots_breadcrumbs', true ) ) {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_post_meta( get_option( 'resume_manager_resumes_page_id' ), '_seopress_robots_breadcrumbs', true ) ),
							1 => get_the_permalink( get_option( 'resume_manager_resumes_page_id' ) ),
							2 => get_option( 'resume_manager_resumes_page_id' ),
						);
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_the_title( get_option( 'resume_manager_resumes_page_id' ) ) ),
							1 => get_page_link( get_option( 'resume_manager_resumes_page_id' ) ),
							2 => get_option( 'resume_manager_resumes_page_id' ),
						);
					}
				}

				if ( '' != get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ) {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
				} else {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_the_title() ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
				}
			} elseif ( 'post' != get_post_type( $post ) ) {
				$post_type = get_post_type_object( get_post_type( $post ) );
				if ( '1' == $post_type->has_archive || true == $post_type->has_archive ) { // CPT
					// Product CPT.
					if ( function_exists( 'is_shop' ) && 'product' == get_post_type( $post ) && get_option( 'woocommerce_shop_page_id' ) ) {
						// Shop base.
						if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsRemoveShopPage() ) {
							if ( '' != get_post_meta( get_option( 'woocommerce_shop_page_id' ), '_seopress_robots_breadcrumbs', true ) ) {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_post_meta( get_option( 'woocommerce_shop_page_id' ), '_seopress_robots_breadcrumbs', true ) ),
									1 => get_the_permalink( get_option( 'woocommerce_shop_page_id' ) ),
									2 => get_option( 'woocommerce_shop_page_id' ),
								);
							} else {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_the_title( get_option( 'woocommerce_shop_page_id' ) ) ),
									1 => get_page_link( get_option( 'woocommerce_shop_page_id' ) ),
									2 => get_option( 'woocommerce_shop_page_id' ),
								);
							}
						}
					} else {
						// Display CPT archive link.
						$crumbs_cpt = array(
							0 => $post_type->labels->name,
							1 => get_post_type_archive_link( get_post_type( $post ) ),
						);

						$crumbs_cpt = apply_filters( 'seopress_pro_breadcrumbs_remove_cpt', $crumbs_cpt, $post_type );

						if ( false !== $crumbs_cpt ) {
							$crumbs[] = $crumbs_cpt;
						}
					}

					$crumbs = seopress_breadcrumbs_term_link( $post, $crumbs, $options );

					$crumbs = seopress_breadcrumbs_post_parent( $post, $crumbs );

					if ( '' != get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ) {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ),
							1 => get_the_permalink(),
							2 => get_the_id(),
						);
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_the_title() ),
							1 => get_the_permalink(),
							2 => get_the_id(),
						);
					}
				} else {
					if ( true === apply_filters( 'seopress_breadcrumbs_force_archive_name', false ) ) {
						$crumbs[] = array(
							0 => $post_type->labels->name,
						);
					}

					$crumbs = seopress_breadcrumbs_term_link( $post, $crumbs, $options );

					$crumbs = seopress_breadcrumbs_post_parent( $post, $crumbs );

					if ( '' != get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ) {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ),
							1 => get_the_permalink(),
							2 => get_the_id(),
						);
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_the_title() ),
							1 => get_the_permalink(),
							2 => get_the_id(),
						);
					}
				}
			} else {
				// Blog parent page.
				if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsRemoveBlogPage() ) {
					if ( 'page' == get_option( 'show_on_front' ) && '0' != $page_id ) {
						if ( '' != get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ) {
							$crumbs[] = array(
								0 => wp_strip_all_tags( get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ),
								1 => get_the_permalink( $page_id ),
								2 => $page_id,
							);
						} else {
							$crumbs[] = array(
								0 => wp_strip_all_tags( get_the_title( $page_id ) ),
								1 => get_the_permalink( $page_id ),
								2 => $page_id,
							);
						}
					}
				}

				// Display Term archive link.
				$crumbs = seopress_breadcrumbs_term_link( $post, $crumbs, $options );

				// Default single post (custom + default).
				if ( '' != get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ) {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
				} else {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_the_title() ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
				}
			}
		}

		// Page.
		if ( is_page() && ( ! is_home() && ! is_front_page() ) ) {
			if ( $post->post_parent ) {
				$crumbs = seopress_breadcrumbs_post_parent( $post, $crumbs );
			} elseif ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) { // WooCommerce Endpoint.
				$crumbs[] = array(
					0 => get_the_title(),
					1 => get_permalink(),
					2 => get_the_id(),
				);
			}

			// Display Term archive link.
			$crumbs = seopress_breadcrumbs_term_link( $post, $crumbs, $options );

			// Current page.
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			} elseif ( '' != get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ) {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_post_meta( get_the_id(), '_seopress_robots_breadcrumbs', true ) ),
						1 => get_the_permalink(),
						2 => get_the_id(),
					);
			} else {
				$crumbs[] = array(
					0 => wp_strip_all_tags( get_the_title() ),
					1 => get_the_permalink(),
					2 => get_the_id(),
				);
			}
		}

		// Blog.
		if ( is_home() ) {
			if ( 'page' == get_option( 'show_on_front' ) && '0' != $page_id ) {
				if ( '' != get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ) {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ),
						1 => get_the_permalink( $page_id ),
						2 => $page_id,
					);
				} else {
					$crumbs[] = array(
						0 => wp_strip_all_tags( get_the_title( $page_id ) ),
						1 => get_the_permalink( $page_id ),
						2 => $page_id,
					);
				}
			}
		}

		// Post Type Archives.
		if ( is_post_type_archive( 'tribe_events' ) ) { // Events calendar.
			$post_type = get_post_type_object( 'tribe_events' );

			$crumbs[] = array(
				0 => wp_strip_all_tags( $post_type->labels->name ),
				1 => esc_url( tribe_get_events_link() ),
			);
		} elseif ( is_post_type_archive() ) {
			$post_type = get_post_type_object( get_post_type() );

			if ( isset( $post_type ) && 'product' == $post_type->name ) {
				// Product CPT.
				if ( function_exists( 'is_shop' ) && get_option( 'woocommerce_shop_page_id' ) ) {
					// Shop base.
					if ( '' != get_post_meta( get_option( 'woocommerce_shop_page_id' ), '_seopress_robots_breadcrumbs', true ) ) {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_post_meta( get_option( 'woocommerce_shop_page_id' ), '_seopress_robots_breadcrumbs', true ) ),
							1 => get_the_permalink( get_option( 'woocommerce_shop_page_id' ) ),
							2 => get_option( 'woocommerce_shop_page_id' ),
						);
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_the_title( get_option( 'woocommerce_shop_page_id' ) ) ),
							1 => get_page_link( get_option( 'woocommerce_shop_page_id' ) ),
							2 => get_option( 'woocommerce_shop_page_id' ),
						);
					}
				}
			} elseif ( isset( $post_type ) ) {
				$crumbs[] = array(
					0 => wp_strip_all_tags( $post_type->labels->name ),
					1 => get_post_type_archive_link( $post_type->name ),
				);
			} else {
				$post_type = get_query_var( 'post_type' );

				if ( isset( $post_type ) ) {
					$crumbs[] = array(
						0 => $i18n_no_results,
						1 => get_post_type_archive_link( $post_type ),
					);
				} else {
					$crumbs[] = array(
						0 => $i18n_no_results,
					);
				}
			}
		}

		// Date Archives.
		if ( is_date() ) {
			// Blog parent page.
			if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsRemoveBlogPage() ) {
				if ( 'page' == get_option( 'show_on_front' ) && '0' != $page_id ) {
					if ( '' != get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ) {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ),
							1 => get_the_permalink( $page_id ),
							2 => $page_id,
						);
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( get_the_title( $page_id ) ),
							1 => get_the_permalink( $page_id ),
							2 => $page_id,
						);
					}
				}
			}
			if ( is_year() || is_month() ) {
				$crumbs[] = array(
					0 => get_the_time( 'Y' ),
					1 => get_year_link( get_the_time( 'Y' ) ),
				);
			}
			if ( is_month() ) {
				$crumbs[] = array(
					0 => get_the_time( 'F' ),
					1 => get_month_link( get_the_time( 'Y' ), get_the_time( 'm' ) ),
				);
			}
		}

		// Author Archives.
		if ( is_author() ) {
			global $author;

			$author_name = get_userdata( $author );
			if ( $author_name instanceof WP_User ) {
				$crumbs[] = array(
					0 => $i18n_author . $author_name->display_name,
					1 => get_author_posts_url( $author_name->ID ),
					2 => $author_name->ID,
				);
			}
		}

		// Taxonomies (including Post Tag and Post Category).
		if ( ( is_tax() || is_tag() || is_category() ) && ! is_search() ) {
			$current_term = $GLOBALS['wp_query']->get_queried_object();

			if ( isset( $current_term ) ) {
				$taxonomy = get_taxonomy( $current_term->taxonomy );

				$cpt = isset( $options['seopress_breadcrumbs_cpt'][ $taxonomy->name ]['cpt'] ) ? $options['seopress_breadcrumbs_cpt'][ $taxonomy->name ]['cpt'] : null;
				$cpt = get_post_type_object( $cpt );

				if ( 'none' != $cpt && null != $cpt ) {
					// job_listing_tag.
					if ( 'job_listing' == $cpt->name && get_theme_support( 'job-manager-templates' ) === false ) {
						$queried_object = get_queried_object();
						$post_type      = get_post_type_object( 'job_listing' );

						if ( ! empty( get_option( 'job_manager_jobs_page_id' ) ) ) {
							if ( '' != get_post_meta( get_option( 'job_manager_jobs_page_id' ), '_seopress_robots_breadcrumbs', true ) ) {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_post_meta( get_option( 'job_manager_jobs_page_id' ), '_seopress_robots_breadcrumbs', true ) ),
									1 => get_the_permalink( get_option( 'job_manager_jobs_page_id' ) ),
									2 => get_option( 'job_manager_jobs_page_id' ),
								);
							} else {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_the_title( get_option( 'job_manager_jobs_page_id' ) ) ),
									1 => get_page_link( get_option( 'job_manager_jobs_page_id' ) ),
									2 => get_option( 'job_manager_jobs_page_id' ),
								);
							}
						}
					} elseif ( 'post' == $cpt->name && '1' !== seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsRemoveBlogPage() ) { // Blog page.
						if ( 'page' == get_option( 'show_on_front' ) && '0' != $page_id ) {
							if ( '' != get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ) {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_post_meta( $page_id, '_seopress_robots_breadcrumbs', true ) ),
									1 => get_the_permalink( $page_id ),
									2 => $page_id,
								);
							} else {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_the_title( $page_id ) ),
									1 => get_the_permalink( $page_id ),
									2 => $page_id,
								);
							}
						}
					} elseif ( function_exists( 'is_shop' ) && get_option( 'woocommerce_shop_page_id' ) && 'product' == $cpt->name ) { // Shop page.
						// Shop base.
						if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsRemoveShopPage() ) {
							if ( '' != get_post_meta( get_option( 'woocommerce_shop_page_id' ), '_seopress_robots_breadcrumbs', true ) ) {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_post_meta( get_option( 'woocommerce_shop_page_id' ), '_seopress_robots_breadcrumbs', true ) ),
									1 => get_the_permalink( get_option( 'woocommerce_shop_page_id' ) ),
									2 => get_option( 'woocommerce_shop_page_id' ),
								);
							} else {
								$crumbs[] = array(
									0 => wp_strip_all_tags( get_the_title( get_option( 'woocommerce_shop_page_id' ) ) ),
									1 => get_page_link( get_option( 'woocommerce_shop_page_id' ) ),
									2 => get_option( 'woocommerce_shop_page_id' ),
								);
							}
						}
					} else {
						$crumbs[] = array(
							0 => wp_strip_all_tags( $cpt->labels->name ),
							1 => get_post_type_archive_link( $cpt->name ),
						);
					}
				}

				// Ancestors.
				if ( 0 != $current_term->parent ) {
					$ancestors_term = get_ancestors( $current_term->term_id, $current_term->taxonomy );

					$ancestors_crumb = array_reverse( $ancestors_term );

					foreach ( $ancestors_crumb as $key => $value ) {
						$current_term_name = get_term( $value, $current_term->taxonomy );
						$current_term_name = $current_term_name->name;

						if ( '' != get_term_meta( $value, '_seopress_robots_breadcrumbs', true ) ) {
							$current_term_name = get_term_meta( $value, '_seopress_robots_breadcrumbs', true );
						}

						$crumbs[] = array(
							0 => wp_strip_all_tags( $current_term_name ),
							1 => get_term_link( $value ),
							2 => $current_term->term_id,
						);
					}
				}

				// Current term.
				$current_term_name = single_term_title( '', false );

				if ( '' != get_term_meta( $current_term->term_id, '_seopress_robots_breadcrumbs', true ) ) {
					$current_term_name = get_term_meta( $current_term->term_id, '_seopress_robots_breadcrumbs', true );
				}

				$crumbs[] = array(
					0 => wp_strip_all_tags( $current_term_name ),
					1 => get_term_link( $current_term ),
					2 => $current_term->term_id,
				);
			}
		}

		// Search results.
		if ( is_search() ) {
			$s_query = '';
			if ( '' != get_search_query() ) {
				$s_query = urlencode( get_query_var( 's' ) );
			}

			$crumbs[] = array(
				0 => $i18n_search_results . get_search_query(),
				1 => get_search_link( $s_query ),
			);
		}

		// Pagination.
		if ( is_paged() ) {
			global $wp;
			$current_url = home_url( add_query_arg( array(), $wp->request ) );

			$current_page = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

			$crumbs[] = array(
				0 => $i18n_paged . $current_page,
				1 => $current_url,
			);
		}

		// WooCommerce Endpoint.
		if ( function_exists( 'is_wc_endpoint_url' ) && function_exists( 'wc_get_account_endpoint_url' ) ) {
			if ( is_wc_endpoint_url() ) {
				$crumbs[] = array(
					0 => wp_strip_all_tags( WC()->query->get_endpoint_title( WC()->query->get_current_endpoint() ) ),
					1 => wc_get_account_endpoint_url( WC()->query->get_current_endpoint() ),
				);
			}
		}

		// Render.
		if ( ( is_front_page() && is_paged() ) || ! is_front_page() ) {
			$sp_breadcrumbs_html = '';

			if ( empty( $crumbs ) || ! is_array( $crumbs ) ) {
				return;
			}

			// Schema.org itemListElement.
			$crumbs = apply_filters( 'seopress_pro_breadcrumbs_crumbs', $crumbs );

			$json_enabled   = '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsJsonEnable();
			$display_markup = true;
			if ( $json_enabled ) {
				$display_markup = false;
			}
			$display_markup = apply_filters( 'seopress_pro_breadcrumbs_html_markup', $display_markup );

			$linkable = apply_filters( 'seopress_pro_breadcrumbs_last_item_linkable', false );
			$last_key = array_keys( $crumbs );
			$last_key = array_pop( $last_key );

			$li_class = 'class="breadcrumb-item"';
			$li_class = apply_filters( 'seopress_pro_breadcrumbs_html_class_li', $li_class );

			$li_active_class = 'class="breadcrumb-item active"';
			$li_active_class = apply_filters( 'seopress_pro_breadcrumbs_html_class_li_active', $li_active_class );

			foreach ( $crumbs as $key => $crumb ) {
				$sep = $key;
				if ( $last_key != $sep ) {
					if ( true === $display_markup ) {
						$sp_breadcrumbs_html .= '<li ' . $li_class . ' itemprop="itemListElement" itemscope itemtype="' . seopress_check_ssl() . 'schema.org/ListItem">';
					} else {
						$sp_breadcrumbs_html .= '<li ' . $li_class . '>';
					}
				} elseif ( true === $display_markup ) {
						$sp_breadcrumbs_html .= '<li ' . $li_active_class . ' aria-current="page" itemprop="itemListElement" itemscope itemtype="' . seopress_check_ssl() . 'schema.org/ListItem">';
				} else {
					$sp_breadcrumbs_html .= '<li ' . $li_active_class . ' aria-current="page">';
				}

				if ( $last_key != $sep || true === $linkable ) {
					if ( ! empty( $crumb[1] ) ) {
						if ( true === $display_markup ) {
							$sp_breadcrumbs_html .= '<a itemscope itemtype="' . seopress_check_ssl() . 'schema.org/WebPage" itemprop="item" itemid="' . esc_url( $crumb[1] ) . '" href="' . esc_url( $crumb[1] ) . '">';
						} else {
							$sp_breadcrumbs_html .= '<a href="' . esc_url( $crumb[1] ) . '">';
						}
					}
				}

				// The home label (first crumb) comes from the "Translation for homepage"
				// option, which is admin-only (manage_options) and intentionally left
				// unsanitized to allow custom HTML such as SVG icons or <span> wrappers
				// (see seopress_pro_sanitize_options_fields()). It is rendered as-is.
				// Every other label is dynamic (post titles, term names, author name,
				// custom breadcrumb meta) and stays escaped to prevent stored XSS.
				$crumb_label = ( 0 === $sep ) ? $crumb[0] : esc_html( $crumb[0] );

				if ( true === $display_markup ) {
					$sp_breadcrumbs_html .= '<span itemprop="name">' . $crumb_label . '</span>';
				} else {
					$sp_breadcrumbs_html .= '<span>' . $crumb_label . '</span>';
				}

				if ( $last_key != $sep || true === $linkable ) {
					if ( ! empty( $crumb[1] ) ) {
						$sp_breadcrumbs_html .= '</a>';
					}
				}

				$key = $key + 1;
				if ( true === $display_markup ) {
					$sp_breadcrumbs_html .= '<meta itemprop="position" content="' . $key . '">';
				}
				$sp_breadcrumbs_html .= '</li>';
			}

			$here = '';
			if ( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nHere() ) {
				$here = seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nHere();
			}

			$nav_class = '';
			$nav_class = apply_filters( 'seopress_pro_breadcrumbs_html_class', $nav_class );

			$ol_class = 'class="breadcrumb"';
			$ol_class = apply_filters( 'seopress_pro_breadcrumbs_html_class_ol', $ol_class );

			if ( true === $display_markup ) {
				$sp_breadcrumbs = '<nav ' . $nav_class . ' aria-label="' . esc_html__( 'breadcrumb', 'wp-seopress-pro' ) . '">' . $here . '<ol ' . $ol_class . ' itemscope itemtype="' . seopress_check_ssl() . 'schema.org/BreadcrumbList">' . $sp_breadcrumbs_html . '</ol></nav>';
			} else {
				$sp_breadcrumbs = '<nav ' . $nav_class . ' aria-label="' . esc_html__( 'breadcrumb', 'wp-seopress-pro' ) . '">' . $here . '<ol ' . $ol_class . '>' . $sp_breadcrumbs_html . '</ol></nav>';
			}

			$sp_breadcrumbs = apply_filters( 'seopress_pro_breadcrumbs_html', $sp_breadcrumbs );

			// JSON-LD.
			if ( $json_enabled ) {
				if ( empty( $crumbs ) || ! is_array( $crumbs ) ) {
					return;
				}

				$sp_breadcrumbs_json = array();

				$old_markup = apply_filters( 'seopress_breadcrumbs_old_markup', false );

				if ( false === $old_markup ) {
					$sp_breadcrumbs_json = array(
						'@context' => seopress_check_ssl() . 'schema.org',
						'name'     => 'Breadcrumb',
						'@type'    => 'BreadcrumbList',
					);

					// The list had no identity of its own, although its items
					// already followed a `<url>#fragment` convention. Without one,
					// nothing on the page can reference the trail, and a second
					// BreadcrumbList from a theme or another plugin is
					// indistinguishable from this one.
					$breadcrumb_canonical = seopress_schema_current_canonical();
					if ( is_string( $breadcrumb_canonical ) && '' !== $breadcrumb_canonical ) {
						$sp_breadcrumbs_json['@id'] = $breadcrumb_canonical . '#breadcrumb';
					}
				} else {
					$sp_breadcrumbs_json = array(
						'@context' => seopress_check_ssl() . 'schema.org',
						'@type'    => 'BreadcrumbList',
					);
				}

				$sp_breadcrumbs_json['itemListElement'] = array();

				// Remove the Home item from the JSON-LD BreadcrumbList. SEOPress
				// drops the first crumb (the Home crumb it adds before the
				// seopress_pro_breadcrumbs_crumbs filter runs) by position. Make
				// it filterable so setups that reorder or prepend their own crumbs
				// via that filter can keep the Home item in the structured data.
				//
				// Google does not require the top level path, so dropping it
				// costs nothing on a trail with depth: "News > Article" says what
				// "Home > News > Article" said. It costs everything on a page with
				// no ancestors, where Home is all that stands between the current
				// page and an empty trail: removing it leaves a single item naming
				// the page the visitor is already on, and Google asks for at least
				// two. So keep Home whenever it is the only other item.
				//
				// @param bool $remove_home Whether to strip the first (Home) crumb. Default true.
				if ( count( $crumbs ) > 2 && apply_filters( 'seopress_pro_breadcrumbs_json_remove_home', true ) ) {
					array_shift( $crumbs );
				}

				// array_shift() also reindexed the keys. The loop below derives
				// "position" from the array key, so keep that invariant when the
				// shift is skipped: a seopress_pro_breadcrumbs_crumbs callback that
				// drops a crumb with unset() leaves gaps (0, 2, 3) and would emit a
				// BreadcrumbList with non-sequential positions.
				$crumbs = array_values( $crumbs );

				$last_crumb_key = array_key_last( $crumbs );

				foreach ( $crumbs as $key => $crumb ) {

					// A schema "name" is text, not markup. The Home label is the one
					// crumb deliberately left unsanitized, so a site can put an SVG icon
					// or a <span> in it for the HTML trail (see
					// seopress_pro_sanitize_options_fields()). An icon has nothing to say
					// to a parser, and Google reads the tags as part of the crumb's name.
					// Titles arrive carrying whatever whitespace an editor left in them,
					// and the same call settles that: wp_strip_all_tags() collapses the
					// runs and trims what is left, so a name is one line of text.
					$crumb_name = isset( $crumb[0] ) ? wp_strip_all_tags( $crumb[0], true ) : '';

					$sp_breadcrumbs_json['itemListElement'][ $key ] = array(
						'@type'    => 'ListItem',
						'position' => $key + 1,
					);

					if ( true === $old_markup ) {
						$sp_breadcrumbs_json['itemListElement'][ $key ]['name'] = $crumb_name;
					}

					// Check if URL is available.
					if ( ! empty( $crumb[1] ) ) {
						if ( false === $old_markup ) {
							// "@id", not "id": a plain "id" key carries no meaning in
							// JSON-LD, so the node had no identifier at all. Google
							// reported "Missing field 'id' (in 'itemListElement.item')"
							// and dropped the breadcrumb from rich results.
							//
							// The identifier is the crumb's own URL, with no
							// fragment. Google documents "item" as the URL of the
							// page the crumb stands for, and a "#webpage" fragment
							// names a node about that page rather than the page.
							// Our own output disagreed with itself over it: the
							// Article schema identifies the current page as
							// `<canonical>` through mainEntityOfPage, while its
							// crumb called the same page `<canonical>#webpage`, so
							// one page had two identities in one document.
							$sp_breadcrumbs_json['itemListElement'][ $key ]['item'] = array(
								'@type' => 'WebPage',
								'@id'   => $crumb[1],
								'url'   => $crumb[1],
								'name'  => $crumb_name,
							);
						} else {
							$sp_breadcrumbs_json['itemListElement'][ $key ]['item'] = $crumb[1];
						}
					} elseif ( false === $old_markup && '' !== $crumb_name ) {
						// No URL for this crumb (e.g. CPT archive without an exposed
						// permalink). Google requires an "item" field on every
						// intermediate ListItem; only the terminal one may omit it.
						if ( $key === $last_crumb_key ) {
							$sp_breadcrumbs_json['itemListElement'][ $key ]['name'] = $crumb_name;
						} else {
							$sp_breadcrumbs_json['itemListElement'][ $key ]['item'] = array(
								'@type' => 'Thing',
								'name'  => $crumb_name,
							);
						}
					}
				}
			}

			if ( $json_enabled ) {
				$sp_breadcrumbs_json = apply_filters( 'seopress_pro_breadcrumbs_json', $sp_breadcrumbs_json );

				// Crumb labels come straight from get_the_title() and friends, so
				// they carry entities. seopress_pro_json_ld_encode() resolves them
				// and re-escapes with JSON's own escapes rather than leaving the
				// output to depend on an HTML unescaping pass.
				$jsonld  = '<script type="application/ld+json">';
				$jsonld .= seopress_pro_json_ld_encode( $sp_breadcrumbs_json );
				$jsonld .= '</script>';
				$jsonld .= "\n";
			}

			if ( '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsEnable() ) {
				if ( true === $echo ) {
					do_action( 'seopress_breadcrumbs_before_html' );
					echo $sp_breadcrumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumb markup assembled by the plugin, filterable by third parties.
					do_action( 'seopress_breadcrumbs_after_html' );
				} elseif ( false === $echo ) {
					return do_action( 'seopress_breadcrumbs_before_html' ) . $sp_breadcrumbs . do_action( 'seopress_breadcrumbs_after_html' );
				}
			}

			if ( $json_enabled && 'json' === $echo ) {
				return $jsonld;
			}
		}
	}
	// Shortcode.
	function seopress_shortcode_breadcrumbs() {
		return seopress_display_breadcrumbs( false );
	}
	if ( '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsEnable() ) {
		add_shortcode( 'seopress_breadcrumbs', 'seopress_shortcode_breadcrumbs' );
	}

	// JSON-LD.
	if ( '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsJsonEnable() ) {
		add_action( 'wp_head', 'seopress_jsonld_breadcrumbs', 2 );
		/**
		 * Display JSON-LD breadcrumbs.
		 *
		 * @return void
		 */
		function seopress_jsonld_breadcrumbs() {
			echo seopress_display_breadcrumbs( 'json' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumb markup assembled by the plugin, filterable by third parties.
		}
	}

	/**
	 * Display Storefront breadcrumbs.
	 *
	 * @return void
	 */
	function seopress_storefront_display_breadcrumbs() {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumb markup assembled by the plugin, filterable by third parties.
		echo '
		<div class="storefront-breadcrumb">
			<div class="col-full">
				' . seopress_display_breadcrumbs( false ) . '
			</div>
		</div>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Remove WooCommerce breadcrumbs and add Storefront breadcrumbs.
	 *
	 * @return void
	 */
	function seopress_remove_wc_breadcrumbs() {
		remove_action( 'storefront_before_content', 'woocommerce_breadcrumb', 10, 0 );
		add_action( 'storefront_before_content', 'seopress_storefront_display_breadcrumbs', 20, 0 );
	}
	add_action( 'init', 'seopress_remove_wc_breadcrumbs' );
}
