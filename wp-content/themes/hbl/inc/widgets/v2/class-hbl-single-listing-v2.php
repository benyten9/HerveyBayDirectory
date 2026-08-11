<?php

namespace HBL\Widgets\V2;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use HBL\Widgets\V2\Traits\Query_Handler;
use HBL\Widgets\V2\Traits\Card_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Single_Listing_V2 extends Widget_Base {

	use Query_Handler;
	use Card_Renderer;

	public function get_name() {
		return 'hbl-single-listing-v2';
	}

	public function get_title() {
		return esc_html__( 'HBL Single Listing V2', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-single-post';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'listing', 'single', 'business', 'directory', 'v2' );
	}

	private function get_current_listing_id() {
		if ( is_singular( 'at_biz_dir' ) ) {
			return get_the_ID();
		}

		$atbdp_listing = get_query_var( 'atbdp_listing' );
		if ( $atbdp_listing ) {
			$post = get_page_by_path( $atbdp_listing, OBJECT, 'at_biz_dir' );
			if ( $post ) {
				return $post->ID;
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url_path    = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$path_parts  = explode( '/', $url_path );

		foreach ( $path_parts as $index => $part ) {
			if ( in_array( $part, array( 'listing', 'single-listing', 'directory' ), true ) && isset( $path_parts[ $index + 1 ] ) ) {
				$post = get_page_by_path( $path_parts[ $index + 1 ], OBJECT, 'at_biz_dir' );
				if ( $post ) {
					return $post->ID;
				}
			}
		}

		global $post;
		if ( $post && $post->post_type === 'at_biz_dir' ) {
			return $post->ID;
		}

		return 0;
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_display',
			array( 'label' => esc_html__( 'Display Options', 'hbl' ) )
		);

		$this->add_control( 'show_hero_image', array(
			'label' => esc_html__( 'Show Hero/Featured Image', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_gallery', array(
			'label' => esc_html__( 'Show Gallery', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_description', array(
			'label' => esc_html__( 'Show Description', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_contact_info', array(
			'label' => esc_html__( 'Show Contact Info', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_map', array(
			'label' => esc_html__( 'Show Map', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_social_links', array(
			'label' => esc_html__( 'Show Social Links', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_categories_tags', array(
			'label' => esc_html__( 'Show Categories & Tags', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_reviews', array(
			'label' => esc_html__( 'Show Reviews', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'show_related', array(
			'label' => esc_html__( 'Show Related Listings', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'related_count', array(
			'label'     => esc_html__( 'Related Listings Count', 'hbl' ),
			'type'      => Controls_Manager::NUMBER,
			'default'   => 4, 'min' => 1, 'max' => 12,
			'condition' => array( 'show_related' => 'yes' ),
		) );

		$this->add_control( 'show_claim_listing', array(
			'label' => esc_html__( 'Show Claim Listing Button', 'hbl' ),
			'type'  => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes',
		) );

		$this->add_control( 'claim_button_text', array(
			'label'     => esc_html__( 'Claim Button Text', 'hbl' ),
			'type'      => Controls_Manager::TEXT, 'default' => esc_html__( 'Claim Now', 'hbl' ),
			'condition' => array( 'show_claim_listing' => 'yes' ),
		) );

		$this->add_control( 'claim_listing_link', array(
			'label'     => esc_html__( 'Claim Button Link', 'hbl' ),
			'type'      => Controls_Manager::URL,
			'default'   => array( 'url' => '', 'is_external' => false, 'nofollow' => false ),
			'condition' => array( 'show_claim_listing' => 'yes' ),
		) );

		$this->end_controls_section();

		$this->start_controls_section(
			'section_plan_tiers',
			array( 'label' => esc_html__( 'Plan Tier Mapping', 'hbl' ) )
		);

		$pricing_plans = $this->get_pricing_plans();
		if ( ! empty( $pricing_plans ) ) {
			$this->add_control( 'gold_plan_ids', array(
				'label' => esc_html__( 'Gold Tier Plans', 'hbl' ), 'type' => Controls_Manager::SELECT2,
				'multiple' => true, 'options' => $pricing_plans, 'label_block' => true,
			) );
			$this->add_control( 'silver_plan_ids', array(
				'label' => esc_html__( 'Silver Tier Plans', 'hbl' ), 'type' => Controls_Manager::SELECT2,
				'multiple' => true, 'options' => $pricing_plans, 'label_block' => true,
			) );
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'section_plan_badges',
			array( 'label' => esc_html__( 'Plan Badges', 'hbl' ) )
		);

		$this->add_control(
			'show_plan_badges',
			array(
				'label'        => esc_html__( 'Show Plan Badge', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show a badge on the listing based on its pricing plan tier.', 'hbl' ),
			)
		);

		$repeater = new \Elementor\Repeater();
		$repeater->add_control( 'plan_tier', array(
			'label'   => esc_html__( 'Plan Tier', 'hbl' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'gold',
			'options' => array(
				'gold'   => esc_html__( 'Gold', 'hbl' ),
				'silver' => esc_html__( 'Silver', 'hbl' ),
				'bronze' => esc_html__( 'Bronze', 'hbl' ),
			),
		) );
		$repeater->add_control( 'badge_text', array(
			'label'   => esc_html__( 'Badge Text', 'hbl' ),
			'type'    => Controls_Manager::TEXT,
			'default' => esc_html__( 'PREMIUM', 'hbl' ),
		) );
		$repeater->add_control( 'badge_bg_color', array(
			'label'   => esc_html__( 'Background Color', 'hbl' ),
			'type'    => Controls_Manager::COLOR,
			'default' => '#F9532A',
		) );
		$repeater->add_control( 'badge_text_color', array(
			'label'   => esc_html__( 'Text Color', 'hbl' ),
			'type'    => Controls_Manager::COLOR,
			'default' => '#FFFFFF',
		) );

		$this->add_control(
			'plan_badges',
			array(
				'label'       => esc_html__( 'Plan Badges', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array( 'plan_tier' => 'gold',   'badge_text' => 'PREMIUM',  'badge_bg_color' => '#F9532A', 'badge_text_color' => '#FFFFFF' ),
					array( 'plan_tier' => 'silver',  'badge_text' => 'FEATURED', 'badge_bg_color' => '#008080', 'badge_text_color' => '#FFFFFF' ),
					array( 'plan_tier' => 'bronze',  'badge_text' => 'BASIC',    'badge_bg_color' => '#6c757d', 'badge_text_color' => '#FFFFFF' ),
				),
				'title_field' => '{{{ plan_tier.toUpperCase() }}} - {{{ badge_text }}}',
				'condition'   => array( 'show_plan_badges' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section( 'section_fallback_logo', array( 'label' => esc_html__( 'Fallback Logo', 'hbl' ) ) );
		$this->add_control( 'fallback_logo', array( 'label' => esc_html__( 'Default Logo', 'hbl' ), 'type' => Controls_Manager::MEDIA ) );
		$this->end_controls_section();
	}

	protected function render() {
		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		$settings   = $this->get_settings_for_display();
		$widget_id  = $this->get_id();
		$listing_id = $this->get_current_listing_id();

		if ( ! $listing_id ) {
			echo '<div class="hbl-v2-notice"><p>' . esc_html__( 'No listing found. Please navigate to a listing page.', 'hbl' ) . '</p></div>';
			return;
		}

		$listing = get_post( $listing_id );
		if ( ! $listing || $listing->post_status !== 'publish' ) {
			echo '<div class="hbl-v2-notice"><p>' . esc_html__( 'Listing not found or not published.', 'hbl' ) . '</p></div>';
			return;
		}

		$all_meta = get_post_meta( $listing_id );

		$address  = isset( $all_meta['_address'][0] ) ? $all_meta['_address'][0] : '';
		$phone    = isset( $all_meta['_phone'][0] ) ? $all_meta['_phone'][0] : '';
		$website  = isset( $all_meta['_website'][0] ) ? $all_meta['_website'][0] : '';
		$email    = isset( $all_meta['_email'][0] ) ? $all_meta['_email'][0] : '';
		$lat      = isset( $all_meta['_manual_lat'][0] ) ? $all_meta['_manual_lat'][0] : ( isset( $all_meta['_lat'][0] ) ? $all_meta['_lat'][0] : '' );
		$lng      = isset( $all_meta['_manual_lng'][0] ) ? $all_meta['_manual_lng'][0] : ( isset( $all_meta['_lng'][0] ) ? $all_meta['_lng'][0] : '' );

		$plan_id  = isset( $all_meta['_fm_plans'][0] ) ? absint( $all_meta['_fm_plans'][0] ) : 0;
		if ( $plan_id > 0 ) {
			$plan_post = get_post( $plan_id );
			if ( ! $plan_post || $plan_post->post_status !== 'publish' || $plan_post->post_type !== 'atbdp_pricing_plans' ) {
				$plan_id = 0;
			}
		}
		$plan_tier  = $this->get_plan_tier( $plan_id, $settings );
		$is_claimed = isset( $all_meta['_claimed_by_admin'][0] ) && ! empty( $all_meta['_claimed_by_admin'][0] );

		$logo_url = $this->get_listing_logo( $listing_id, $all_meta );
		if ( empty( $logo_url ) && ! empty( $settings['fallback_logo']['url'] ) ) {
			$logo_url = $settings['fallback_logo']['url'];
		}

		$gallery_ids = isset( $all_meta['_images'][0] ) ? maybe_unserialize( $all_meta['_images'][0] ) : array();
		if ( ! is_array( $gallery_ids ) ) {
			$gallery_ids = array();
		}

		$featured_img = get_the_post_thumbnail_url( $listing_id, 'full' );

		$average_rating = 0;
		$rating_count   = 0;
		if ( function_exists( 'directorist_get_listing_rating' ) ) {
			$average_rating = floatval( directorist_get_listing_rating( $listing_id ) );
		}
		if ( function_exists( 'directorist_get_listing_review_count' ) ) {
			$rating_count = intval( directorist_get_listing_review_count( $listing_id ) );
		}

		$categories     = wp_get_post_terms( $listing_id, 'at_biz_dir-category' );
		$tags           = wp_get_post_terms( $listing_id, 'at_biz_dir-tags' );
		$location_terms = wp_get_post_terms( $listing_id, 'at_biz_dir-location' );

		if ( is_wp_error( $categories ) ) { $categories = array(); }
		if ( is_wp_error( $tags ) ) { $tags = array(); }
		if ( is_wp_error( $location_terms ) ) { $location_terms = array(); }

		$social = isset( $all_meta['social'][0] ) ? maybe_unserialize( $all_meta['social'][0] ) : '';
		if ( empty( $social ) && isset( $all_meta['_social'][0] ) ) {
			$social = maybe_unserialize( $all_meta['_social'][0] );
		}
		if ( empty( $social ) || ! is_array( $social ) ) {
			$social = array();
			foreach ( array( 'facebook', 'instagram', 'twitter', 'linkedin', 'youtube' ) as $sn ) {
				$val = '';
				foreach ( array( '_' . $sn, $sn ) as $key ) {
					if ( isset( $all_meta[ $key ][0] ) && ! empty( $all_meta[ $key ][0] ) ) {
						$val = $all_meta[ $key ][0];
						break;
					}
				}
				if ( $val ) { $social[ $sn ] = $val; }
			}
		}

		$plan_badge = null;
		if ( isset( $settings['show_plan_badges'] ) && 'yes' === $settings['show_plan_badges'] && ! empty( $settings['plan_badges'] ) ) {
			foreach ( $settings['plan_badges'] as $badge ) {
				if ( isset( $badge['plan_tier'] ) && $badge['plan_tier'] === $plan_tier ) {
					$plan_badge = $badge;
					break;
				}
			}
		}

		$reviews = array();
		if ( function_exists( 'atbdp_get_listing_reviews' ) ) {
			$reviews = atbdp_get_listing_reviews( $listing_id );
		}

		$related_listings = array();
		if ( isset( $settings['show_related'] ) && 'yes' === $settings['show_related'] && ! empty( $categories ) ) {
			$related_count = isset( $settings['related_count'] ) ? absint( $settings['related_count'] ) : 4;
			$related_query = new \WP_Query( array(
				'post_type'      => 'at_biz_dir',
				'post_status'    => 'publish',
				'posts_per_page' => $related_count,
				'post__not_in'   => array( $listing_id ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'at_biz_dir-category',
						'field'    => 'term_id',
						'terms'    => wp_list_pluck( $categories, 'term_id' ),
					),
				),
			) );
			if ( $related_query->have_posts() ) {
				while ( $related_query->have_posts() ) {
					$related_query->the_post();
					$related_listings[] = get_the_ID();
				}
				wp_reset_postdata();
			}
		}

		$show_hero     = isset( $settings['show_hero_image'] ) && 'yes' === $settings['show_hero_image'];
		$show_gallery  = isset( $settings['show_gallery'] ) && 'yes' === $settings['show_gallery'];
		$show_desc     = isset( $settings['show_description'] ) && 'yes' === $settings['show_description'];
		$show_contact  = isset( $settings['show_contact_info'] ) && 'yes' === $settings['show_contact_info'];
		$show_map      = isset( $settings['show_map'] ) && 'yes' === $settings['show_map'];
		$show_social   = isset( $settings['show_social_links'] ) && 'yes' === $settings['show_social_links'];
		$show_cat_tags = isset( $settings['show_categories_tags'] ) && 'yes' === $settings['show_categories_tags'];
		$show_reviews  = isset( $settings['show_reviews'] ) && 'yes' === $settings['show_reviews'];
		$show_related  = isset( $settings['show_related'] ) && 'yes' === $settings['show_related'];
		$show_claim    = isset( $settings['show_claim_listing'] ) && 'yes' === $settings['show_claim_listing'];
		?>
		<div class="hbl-v2-widget hbl-v2-single-listing" data-widget-id="<?php echo esc_attr( $widget_id ); ?>" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">

			<?php  ?>
			<?php if ( $show_hero && ( $featured_img || ! empty( $gallery_ids[0] ) ) ) :
				$hero_url = $featured_img ?: wp_get_attachment_image_url( $gallery_ids[0], 'full' );
			?>
				<div class="hbl-v2-sl-hero" style="background-image: url('<?php echo esc_url( $hero_url ); ?>');">
					<?php if ( $plan_badge ) : ?>
						<span class="hbl-v2-sl-badge" style="background-color: <?php echo esc_attr( $plan_badge['badge_bg_color'] ); ?>; color: <?php echo esc_attr( $plan_badge['badge_text_color'] ); ?>;">
							<?php echo esc_html( $plan_badge['badge_text'] ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php  ?>
			<div class="hbl-v2-sl-layout">

				<?php  ?>
				<div class="hbl-v2-sl-main">

					<?php  ?>
					<div class="hbl-v2-sl-header">
						<?php if ( $logo_url ) : ?>
							<div class="hbl-v2-sl-logo">
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_the_title( $listing_id ) ); ?>">
							</div>
						<?php endif; ?>
						<div class="hbl-v2-sl-title-group">
							<h1 class="hbl-v2-sl-title"><?php echo esc_html( get_the_title( $listing_id ) ); ?></h1>
							<?php if ( $is_claimed ) : ?>
								<span class="hbl-v2-sl-claimed"><i class="bi bi-patch-check-fill"></i> <?php esc_html_e( 'Claimed', 'hbl' ); ?></span>
							<?php endif; ?>
							<?php if ( $average_rating > 0 ) : ?>
								<div class="hbl-v2-sl-rating">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<i class="bi <?php echo $i <= round( $average_rating ) ? 'bi-star-fill' : 'bi-star'; ?>"></i>
									<?php endfor; ?>
									<span class="hbl-v2-sl-rating-value"><?php echo esc_html( number_format( $average_rating, 1 ) ); ?></span>
									<?php if ( $rating_count > 0 ) : ?>
										<span class="hbl-v2-sl-rating-count">(<?php printf( esc_html( _n( '%d review', '%d reviews', $rating_count, 'hbl' ) ), $rating_count ); ?>)</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $location_terms ) ) : ?>
								<div class="hbl-v2-sl-location">
									<i class="bi bi-geo-alt"></i>
									<?php echo esc_html( implode( ', ', wp_list_pluck( $location_terms, 'name' ) ) ); ?>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<?php  ?>
					<?php if ( $show_cat_tags && ( ! empty( $categories ) || ! empty( $tags ) ) ) : ?>
						<div class="hbl-v2-sl-terms">
							<?php foreach ( $categories as $cat ) : ?>
								<a href="<?php echo esc_url( get_term_link( $cat ) ); ?>" class="hbl-v2-sl-category-badge">
									<?php echo esc_html( $cat->name ); ?>
								</a>
							<?php endforeach; ?>
							<?php foreach ( $tags as $tag ) : ?>
								<a href="<?php echo esc_url( get_term_link( $tag ) ); ?>" class="hbl-v2-sl-tag-badge">
									#<?php echo esc_html( $tag->name ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_desc && ! empty( $listing->post_content ) ) : ?>
						<div class="hbl-v2-sl-section">
							<h3 class="hbl-v2-sl-section-title"><?php esc_html_e( 'About', 'hbl' ); ?></h3>
							<div class="hbl-v2-sl-description">
								<?php echo wp_kses_post( wpautop( $listing->post_content ) ); ?>
							</div>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_gallery && ! empty( $gallery_ids ) ) : ?>
						<div class="hbl-v2-sl-section">
							<h3 class="hbl-v2-sl-section-title"><?php esc_html_e( 'Gallery', 'hbl' ); ?></h3>
							<div class="hbl-v2-sl-gallery">
								<?php foreach ( $gallery_ids as $img_id ) :
									$img_url = wp_get_attachment_image_url( $img_id, 'medium_large' );
									if ( ! $img_url ) { continue; }
								?>
									<a href="<?php echo esc_url( wp_get_attachment_image_url( $img_id, 'full' ) ); ?>" class="hbl-v2-sl-gallery-item" target="_blank" rel="noopener">
										<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( get_the_title( $listing_id ) ); ?>" loading="lazy">
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_reviews && ! empty( $reviews ) ) : ?>
						<div class="hbl-v2-sl-section">
							<h3 class="hbl-v2-sl-section-title"><?php esc_html_e( 'Reviews', 'hbl' ); ?></h3>
							<div class="hbl-v2-sl-reviews">
								<?php foreach ( (array) $reviews as $review ) :
									if ( ! is_object( $review ) ) { continue; }
									$reviewer_rating = isset( $review->rating ) ? intval( $review->rating ) : 0;
								?>
									<div class="hbl-v2-sl-review">
										<div class="hbl-v2-sl-review-header">
											<span class="hbl-v2-sl-reviewer"><?php echo esc_html( isset( $review->display_name ) ? $review->display_name : esc_html__( 'Anonymous', 'hbl' ) ); ?></span>
											<div class="hbl-v2-sl-review-stars">
												<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
													<i class="bi <?php echo $i <= $reviewer_rating ? 'bi-star-fill' : 'bi-star'; ?>"></i>
												<?php endfor; ?>
											</div>
										</div>
										<?php if ( ! empty( $review->comment ) ) : ?>
											<p class="hbl-v2-sl-review-text"><?php echo esc_html( $review->comment ); ?></p>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_related && ! empty( $related_listings ) ) : ?>
						<div class="hbl-v2-sl-section">
							<h3 class="hbl-v2-sl-section-title"><?php esc_html_e( 'Related Listings', 'hbl' ); ?></h3>
							<div class="hbl-v2-sl-related-grid">
								<?php foreach ( $related_listings as $related_id ) :
									$r_logo    = $this->get_listing_logo( $related_id, get_post_meta( $related_id ) );
									$r_cats    = wp_get_post_terms( $related_id, 'at_biz_dir-category' );
									$r_cat_name = ( ! is_wp_error( $r_cats ) && ! empty( $r_cats ) ) ? $r_cats[0]->name : '';
								?>
									<a href="<?php echo esc_url( get_permalink( $related_id ) ); ?>" class="hbl-v2-sl-related-card">
										<?php if ( $r_logo ) : ?>
											<div class="hbl-v2-sl-related-logo">
												<img src="<?php echo esc_url( $r_logo ); ?>" alt="<?php echo esc_attr( get_the_title( $related_id ) ); ?>">
											</div>
										<?php else : ?>
											<div class="hbl-v2-sl-related-logo hbl-v2-sl-related-logo-placeholder">
												<i class="bi bi-building"></i>
											</div>
										<?php endif; ?>
										<div class="hbl-v2-sl-related-info">
											<span class="hbl-v2-sl-related-title"><?php echo esc_html( get_the_title( $related_id ) ); ?></span>
											<?php if ( $r_cat_name ) : ?>
												<span class="hbl-v2-sl-related-cat"><?php echo esc_html( $r_cat_name ); ?></span>
											<?php endif; ?>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

				</div>

				<?php  ?>
				<div class="hbl-v2-sl-sidebar">

					<?php  ?>
					<?php if ( $plan_badge && ! ( $show_hero && $featured_img ) ) : ?>
						<div class="hbl-v2-sl-sidebar-badge" style="background-color: <?php echo esc_attr( $plan_badge['badge_bg_color'] ); ?>; color: <?php echo esc_attr( $plan_badge['badge_text_color'] ); ?>;">
							<?php echo esc_html( $plan_badge['badge_text'] ); ?>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_contact && ( $phone || $email || $website || $address ) ) : ?>
						<div class="hbl-v2-sl-sidebar-card">
							<h4 class="hbl-v2-sl-sidebar-card-title"><?php esc_html_e( 'Contact Info', 'hbl' ); ?></h4>
							<ul class="hbl-v2-sl-contact-list">
								<?php if ( $phone ) : ?>
									<li>
										<i class="bi bi-telephone"></i>
										<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
									</li>
								<?php endif; ?>
								<?php if ( $email ) : ?>
									<li>
										<i class="bi bi-envelope"></i>
										<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
									</li>
								<?php endif; ?>
								<?php if ( $website ) : ?>
									<li>
										<i class="bi bi-globe"></i>
										<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '/^https?:\/\//', '', rtrim( $website, '/' ) ) ); ?></a>
									</li>
								<?php endif; ?>
								<?php if ( $address ) : ?>
									<li>
										<i class="bi bi-geo-alt"></i>
										<span><?php echo esc_html( $address ); ?></span>
									</li>
								<?php endif; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_social && ! empty( $social ) ) :
						$social_icons = array(
							'facebook'  => array( 'icon' => 'bi-facebook',  'label' => 'Facebook'  ),
							'instagram' => array( 'icon' => 'bi-instagram', 'label' => 'Instagram' ),
							'twitter'   => array( 'icon' => 'bi-twitter-x', 'label' => 'Twitter'  ),
							'linkedin'  => array( 'icon' => 'bi-linkedin',  'label' => 'LinkedIn'  ),
							'youtube'   => array( 'icon' => 'bi-youtube',   'label' => 'YouTube'   ),
						);
						$has_social = false;
						foreach ( $social_icons as $key => $info ) {
							if ( ! empty( $social[ $key ] ) ) { $has_social = true; break; }
						}
					?>
					<?php if ( $has_social ) : ?>
						<div class="hbl-v2-sl-sidebar-card">
							<h4 class="hbl-v2-sl-sidebar-card-title"><?php esc_html_e( 'Follow Us', 'hbl' ); ?></h4>
							<div class="hbl-v2-sl-social-links">
								<?php foreach ( $social_icons as $key => $info ) :
									if ( empty( $social[ $key ] ) ) { continue; }
									$url = $social[ $key ];
									if ( ! preg_match( '/^https?:\/\//i', $url ) ) { $url = 'https://' . $url; }
								?>
									<a href="<?php echo esc_url( $url ); ?>" class="hbl-v2-sl-social-link" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $info['label'] ); ?>">
										<i class="bi <?php echo esc_attr( $info['icon'] ); ?>"></i>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_map && $lat && $lng ) : ?>
						<div class="hbl-v2-sl-sidebar-card hbl-v2-sl-map-card">
							<h4 class="hbl-v2-sl-sidebar-card-title"><?php esc_html_e( 'Location', 'hbl' ); ?></h4>
							<div class="hbl-v2-sl-map"
							     id="hbl-sl-map-<?php echo esc_attr( $widget_id ); ?>"
							     data-lat="<?php echo esc_attr( $lat ); ?>"
							     data-lng="<?php echo esc_attr( $lng ); ?>"
							     data-title="<?php echo esc_attr( get_the_title( $listing_id ) ); ?>">
							</div>
						</div>
					<?php endif; ?>

					<?php  ?>
					<?php if ( $show_claim && ! $is_claimed ) :
						$claim_url  = ! empty( $settings['claim_listing_link']['url'] ) ? $settings['claim_listing_link']['url'] : '';
						$claim_text = ! empty( $settings['claim_button_text'] ) ? $settings['claim_button_text'] : esc_html__( 'Claim Now', 'hbl' );
					?>
						<div class="hbl-v2-sl-sidebar-card hbl-v2-sl-claim-card">
							<i class="bi bi-building-add"></i>
							<p><?php esc_html_e( 'Is this your business? Claim it now to manage your listing.', 'hbl' ); ?></p>
							<?php if ( $claim_url ) : ?>
								<a href="<?php echo esc_url( $claim_url ); ?>" class="hbl-v2-sl-claim-btn"
								   <?php echo ! empty( $settings['claim_listing_link']['is_external'] ) ? 'target="_blank"' : ''; ?>
								   <?php echo ! empty( $settings['claim_listing_link']['nofollow'] ) ? 'rel="nofollow"' : ''; ?>>
									<?php echo esc_html( $claim_text ); ?>
								</a>
							<?php else : ?>
								<button type="button" class="hbl-v2-sl-claim-btn atbdp-modal-btn" data-listing="<?php echo esc_attr( $listing_id ); ?>">
									<?php echo esc_html( $claim_text ); ?>
								</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>

				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var $mapEl = document.getElementById('hbl-sl-map-<?php echo esc_js( $widget_id ); ?>');
			if ($mapEl && typeof L !== 'undefined') {
				var lat = parseFloat($mapEl.dataset.lat);
				var lng = parseFloat($mapEl.dataset.lng);
				var title = $mapEl.dataset.title;
				if (lat && lng) {
					var map = L.map($mapEl, { zoomControl: true, scrollWheelZoom: false }).setView([lat, lng], 15);
					L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
						attribution: '© OpenStreetMap contributors'
					}).addTo(map);
					L.marker([lat, lng]).addTo(map).bindPopup(title).openPopup();
				}
			}
		});
		</script>
		<?php
	}
}
