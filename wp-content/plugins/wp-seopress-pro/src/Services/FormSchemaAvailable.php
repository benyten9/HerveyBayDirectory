<?php

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;

class FormSchemaAvailable {
	public function getData() {
		$noticesArticle = array();
		if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogo() ) ) {
			$noticesArticle[] = array(
				'type' => 'info',
				'html' => '<p><span class="dashicons dashicons-yes"></span>' . __( 'You have set a publisher logo. Good!', 'wp-seopress-pro' ) . '</p>',
			);
		} else {
			$noticesArticle[] = array(
				'type' => 'error',
				/* translators: %s: link to plugin settings page */
				'html' => '<p><span class="dashicons dashicons-no-alt"></span>' . sprintf( __( 'You don\'t have set a <a href="%s">publisher logo</a>. It\'s required for Article content types.', 'wp-seopress-pro' ), admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ) ) . '</p>',
			);
		}

		$pre = '<code>' . htmlspecialchars( '<script type="application/ld+json">your custom schema</script>', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '</code>';

		return array(
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaArticle',
				'type'    => 'articles',
				'label'   => __( 'Article (WebPage)', 'wp-seopress-pro' ),
				'notices' => $noticesArticle,
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaLocalBusiness',
				'type'    => 'localbusiness',
				'label'   => __( 'Local Business', 'wp-seopress-pro' ),
				'notices' => array(),
			),
			array(
				'class'      => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaFaq',
				'type'       => 'faq',
				'label'      => __( 'FAQ', 'wp-seopress-pro' ),
				'deprecated' => true,
				'notices'    => array(
					array(
						'type' => 'warning',
						'html' => '<p>' .
						__( '<strong>Deprecated:</strong> Google has limited FAQ rich results to government and health authority websites only (August 2023). This schema still works for other search engines and LLMs.', 'wp-seopress-pro' ) . '</p>',
					),
				),
			),
			array(
				'class'      => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaHowTo',
				'type'       => 'howto',
				'label'      => __( 'How-to', 'wp-seopress-pro' ),
				'deprecated' => true,
				'notices'    => array(
					array(
						'type' => 'warning',
						'html' => '<p>' .
						__( '<strong>Deprecated:</strong> Google no longer supports HowTo rich results (August 2023). This schema still works for other search engines and LLMs.', 'wp-seopress-pro' ) . '</p>',
					),
				),
			),
			array(
				'class'      => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaCourse',
				'type'       => 'courses',
				'label'      => __( 'Course', 'wp-seopress-pro' ),
				'deprecated' => true,
				'notices'    => array(
					array(
						'type' => 'warning',
						'html' => '<p>' .
						__( '<strong>Deprecated:</strong> Google has removed Course Info rich results. Only Course List (ItemList) is still supported. This schema still works for other search engines and LLMs.', 'wp-seopress-pro' ) . '</p>',
					),
					array(
						'type' => 'warning',
						'html' => '<ul class="seopress-list advice">
                        <li>' . __( 'Only use course markup for educational content that fits the following definition of a course: A series or unit of curriculum that contains lectures, lessons, or modules in a particular subject and/or topic.', 'wp-seopress-pro' ) . '
                        </li>
                        <li>' . __( 'A course must have an explicit educational outcome of knowledge and/or skill in a particular subject and/or topic, and be led by one or more instructors with a roster of students.', 'wp-seopress-pro' ) . '
                        </li>
                        <li>' . __( 'A general public event such as "Astronomy Day" is not a course, and a single 2-minute "How to make a Sandwich Video" is not a course.', 'wp-seopress-pro' ) . '
                        </li>
                    </ul>',
					),
				),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaRecipe',
				'type'    => 'recipes',
				'label'   => __( 'Recipe', 'wp-seopress-pro' ),
				'notices' => array(
					array(
						'type' => 'warning',
						'html' => '<ul class="advice seopress-list">
                        <li>' . __( 'Use recipe markup for content about preparing a particular dish. For example, "facial scrub" or "party ideas" are not valid names for a dish.', 'wp-seopress-pro' ) . '</li>
                    </ul>',
					),
				),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaJob',
				'type'    => 'jobs',
				'label'   => __( 'Job', 'wp-seopress-pro' ),
				'notices' => array(),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaVideo',
				'type'    => 'videos',
				'label'   => __( 'Video', 'wp-seopress-pro' ),
				'notices' => array(),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaEvent',
				'type'    => 'events',
				'label'   => __( 'Event', 'wp-seopress-pro' ),
				'notices' => array(
					array(
						'type' => 'warning',
						'html' => '
                            <ul>
                                <li>
                                    ' . __( '<strong>Expired events.</strong> Events data for any feature will never be shown for expired events. However, you do not have to remove markup for expired events.', 'wp-seopress-pro' ) . '
                                </li>
                                <li>
                                    ' . __( '<strong>Indicate the performer.</strong> Each event item must specify a performer property corresponding to the event\'s performer; that is, a musician, musical group, presenter, actor, and so on.', 'wp-seopress-pro' ) . '
                                </li>
                                <li>
                                    <strong>' . __( 'Do not include promotional elements in the name.', 'wp-seopress-pro' ) . "</strong>
                                </li>
                                <ul>
                                    <li>
                                        <span class='dashicons dashicons-no'></span>" . __( 'Promoting non-event products or services: "Trip package: San Diego/LA, 7 nights"', 'wp-seopress-pro' ) . "
                                    </li>
                                    <li>
                                        <span class='dashicons dashicons-no'></span>" . __( 'Prices in event titles: "Music festival - only $10!" Instead, highlight ticket prices using the tickets property in your markup.', 'wp-seopress-pro' ) . "
                                    </li>
                                    <li>
                                        <span class='dashicons dashicons-no'></span>" . __( 'Using a non-event for a title, such as: "Sale on dresses!"', 'wp-seopress-pro' ) . "
                                    </li>
                                    <li>
                                        <span class='dashicons dashicons-no'></span>" . __( 'Discounts or purchase opportunties, such as: "Concert - buy your tickets now," or "Concert - 50 percent off until Saturday!"', 'wp-seopress-pro' ) . '
                                    </li>
                                </ul>
                                <li>
                                    ' . __( '<strong>Multi-day events.</strong> If your event/ticket info is for the festival itself, specify both the start and end date of the festival. If your event/ticket info is for a specific performance that is part of the festival, specify the specific date of the performance. If the specific date is unavailable, specify both the start and end date of the festival.', 'wp-seopress-pro' ) . '
                                </li>
                            </ul>
                        ',
					),
				),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaProduct',
				'type'    => 'products',
				'label'   => __( 'Product', 'wp-seopress-pro' ),
				'notices' => array(
					array(
						'type' => 'warning',
						'html' => '
                        <ul class="advice seopress-list">
                            <li>' . __( '<strong>Use markup for a specific product, not a category or list of products.</strong> For example, "shoes in our shop" is not a specific product.', 'wp-seopress-pro' ) . '</li>
                            <li>' . __( '<strong>Adult-related products are not supported.</strong>', 'wp-seopress-pro' ) . '</li>
                            <li>' . __( '<strong>Works best with WooCommerce: we automatically add aggregateRating properties from user reviews (you have to enable this option from WooCommerce settings).</strong>', 'wp-seopress-pro' ) . '</li>
                        </ul>
                        ',
					),
				),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaSotfware',
				'type'    => 'softwareapp',
				'label'   => __( 'Software Application', 'wp-seopress-pro' ),
				'notices' => array(),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaService',
				'type'    => 'services',
				'label'   => __( 'Service', 'wp-seopress-pro' ),
				'notices' => array(),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaReview',
				'type'    => 'review',
				'label'   => __( 'Review', 'wp-seopress-pro' ),
				'notices' => array(),
			),
			array(
				'class'   => '\\SEOPressPro\\Services\\Forms\\Schemas\\FormSchemaCustom',
				'type'    => 'custom',
				'label'   => __( 'Custom', 'wp-seopress-pro' ),
				'notices' => array(
					array(
						'type' => 'info',
						'html' => '<p>' . sprintf(
							/* translators: %s: script tag */
							__( 'Build your custom schema. Don\'t forget to include the script tag: %s', 'wp-seopress-pro' ),
							$pre
						) . '</p>',
					),
				),
			),
		);
	}
}
