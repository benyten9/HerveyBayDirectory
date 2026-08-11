<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Single_Listing extends Widget_Base {

	public function get_name() {
		return 'hbl-single-listing';
	}

	public function get_title() {
		return esc_html__( 'HBL Single Listing', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-single-post';
	}

	public function get_categories() {
		return array( 'hbl-widgets' );
	}

	public function get_keywords() {
		return array( 'listing', 'single', 'business', 'directory', 'hbl' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Content Settings', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_featured_image',
			array(
				'label'        => esc_html__( 'Show Featured Image', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_gallery',
			array(
				'label'        => esc_html__( 'Show Gallery', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_services',
			array(
				'label'        => esc_html__( 'Show Services', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		
		$this->add_control(
			'show_pricing',
			array(
				'label'        => esc_html__( 'Show Pricing', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);



		$this->add_control(
			'show_contact_info',
			array(
				'label'        => esc_html__( 'Show Contact Info', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_social_links',
			array(
				'label'        => esc_html__( 'Show Social Links', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_map',
			array(
				'label'        => esc_html__( 'Show Map', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_reviews',
			array(
				'label'        => esc_html__( 'Show Reviews', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_related',
			array(
				'label'        => esc_html__( 'Show Related Listings', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'related_count',
			array(
				'label'     => esc_html__( 'Related Listings Count', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 3,
				'min'       => 1,
				'max'       => 6,
				'condition' => array(
					'show_related' => 'yes',
				),
			)
		);

		$this->add_control(
			'claim_listing_heading',
			array(
				'label'     => esc_html__( 'Claim Listing', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_claim_listing',
			array(
				'label'        => esc_html__( 'Show Claim Listing', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'claim_listing_link',
			array(
				'label'       => esc_html__( 'Claim Button Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
				'condition'   => array(
					'show_claim_listing' => 'yes',
				),
				'description' => esc_html__( 'Leave empty to use default Directorist claim modal. Add a URL to link to a custom popup or page.', 'hbl' ),
			)
		);

		$this->add_control(
			'claim_button_text',
			array(
				'label'     => esc_html__( 'Claim Button Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Claim Now', 'hbl' ),
				'condition' => array(
					'show_claim_listing' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_plan_tiers',
			array(
				'label' => esc_html__( 'Plan Tier Mapping', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$pricing_plans = $this->get_pricing_plans();

		if ( ! empty( $pricing_plans ) ) {
			$this->add_control(
				'gold_plan_ids',
				array(
					'label'       => esc_html__( 'Gold Tier Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
				)
			);

			$this->add_control(
				'silver_plan_ids',
				array(
					'label'       => esc_html__( 'Silver Tier Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
				)
			);
		} else {
			$this->add_control(
				'no_plans_notice',
				array(
					'type' => Controls_Manager::RAW_HTML,
					'raw' => esc_html__( 'No pricing plans found. Please create plans in Directory Listings > Pricing Plans.', 'hbl' ),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				)
			);
		}

		$this->end_controls_section();
		$this->start_controls_section(
			'section_style_colors',
			array(
				'label' => esc_html__( 'Colors', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'primary_color',
			array(
				'label'     => esc_html__( 'Primary Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-listing-hero-overlay' => 'background: linear-gradient(180deg, rgba(0,128,128,0.4) 0%, {{VALUE}} 100%);',
					'{{WRAPPER}} .hbl-single-listing-cta-btn' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-listing-section-icon' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-listing-contact-icon' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-listing-social-link:hover' => 'background: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'     => esc_html__( 'Accent Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-listing-rating-stars' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-listing-action-btn:hover' => 'background: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-listing-section-title::after' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'card_bg_color',
			array(
				'label'     => esc_html__( 'Card Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-listing-card' => 'background-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-listing-section' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		global $post;
		
		if ( ! $post || ! defined( 'ATBDP_POST_TYPE' ) || $post->post_type !== ATBDP_POST_TYPE ) {
			echo '<div class="hbl-single-listing-notice">';
			echo '<p>' . esc_html__( 'This widget should be used on a single listing page.', 'hbl' ) . '</p>';
			echo '</div>';
			return;
		}

		$listing_id = $post->ID;
		
		$title = get_the_title( $listing_id );
		$content = get_the_content( null, false, $listing_id );

		$plan_id = get_post_meta( $listing_id, '_fm_plans', true );
		$plan_tier = $this->get_plan_tier( intval( $plan_id ), $settings );
		
		$word_limit = 50;
		if ( 'gold' === $plan_tier ) {
			$word_limit = 500;
		} elseif ( 'silver' === $plan_tier ) {
			$word_limit = 200;
		}

		if ( ! empty( $content ) ) {
			$content = wp_trim_words( $content, $word_limit, '...' );
		}

		$tagline = get_post_meta( $listing_id, '_tagline', true );
		$featured_image = get_the_post_thumbnail_url( $listing_id, 'large' );
		
		$logo_url = '';
		$custom_file_variations = array(
			'custom-file',
			'_custom-file',
			'custom_file',
			'_custom_file',
		);
		
		foreach ( $custom_file_variations as $field_name ) {
			$custom_file = get_post_meta( $listing_id, $field_name, true );
			
			if ( ! empty( $custom_file ) ) {
				if ( strpos( $custom_file, '|' ) !== false ) {
					$parts = explode( '|', $custom_file );
					$logo_url = trim( $parts[0] );
				} else {
					$logo_url = trim( $custom_file );
				}
				
				if ( is_numeric( $logo_url ) ) {
					$logo_url = wp_get_attachment_image_url( intval( $logo_url ), 'medium' );
				}
				
				if ( ! empty( $logo_url ) && filter_var( $logo_url, FILTER_VALIDATE_URL ) ) {
					break;
				} else {
					$logo_url = '';
				}
			}
		}
		
		if ( empty( $logo_url ) ) {
			$logo_fields = array(
				'_manual_logo_image',
				'_logo',
				'_business_logo',
			);
			
			foreach ( $logo_fields as $field ) {
				$logo_data = get_post_meta( $listing_id, $field, true );
				
				if ( ! empty( $logo_data ) ) {
					if ( is_array( $logo_data ) ) {
						$logo_id = isset( $logo_data[0] ) ? $logo_data[0] : false;
					} else {
						$logo_id = $logo_data;
					}
					
					if ( $logo_id && is_numeric( $logo_id ) ) {
						$logo_url = wp_get_attachment_image_url( $logo_id, 'medium' );
						if ( $logo_url ) {
							break;
						}
					}
					elseif ( $logo_id && is_string( $logo_id ) && filter_var( $logo_id, FILTER_VALIDATE_URL ) ) {
						$logo_url = $logo_id;
						break;
					}
				}
			}
		}
		
		if ( empty( $logo_url ) && $featured_image ) {
			$logo_url = $featured_image;
		}
		
		$phone = get_post_meta( $listing_id, '_phone', true );
		$phone2 = get_post_meta( $listing_id, '_phone2', true );
		$email = get_post_meta( $listing_id, '_email', true );
		$website = get_post_meta( $listing_id, '_website', true );
		$address = get_post_meta( $listing_id, '_address', true );
		
		$facebook = get_post_meta( $listing_id, '_facebook', true );
		$twitter = get_post_meta( $listing_id, '_twitter', true );
		$instagram = get_post_meta( $listing_id, '_instagram', true );
		$linkedin = get_post_meta( $listing_id, '_linkedin', true );
		$youtube = get_post_meta( $listing_id, '_youtube', true );
		$tiktok = get_post_meta( $listing_id, '_tiktok', true );
		
		$lat = get_post_meta( $listing_id, '_manual_lat', true );
		$lng = get_post_meta( $listing_id, '_manual_lng', true );
		
		$categories = get_the_terms( $listing_id, ATBDP_CATEGORY );
		$locations = get_the_terms( $listing_id, ATBDP_LOCATION );
		
		$gallery_images = get_post_meta( $listing_id, '_listing_prv_img', true );
		$gallery_ids = get_post_meta( $listing_id, '_listing_img', true );
		
		$video_url = get_post_meta( $listing_id, '_videourl', true );
		
		$business_hours = get_post_meta( $listing_id, '_bdbh', true );
		
		$average_rating = 0;
		$review_count = 0;
		if ( function_exists( 'JERC_DEVELOPER_Review' ) || function_exists( 'directorist_get_listing_rating' ) ) {
			if ( function_exists( 'directorist_get_listing_rating' ) ) {
				$average_rating = directorist_get_listing_rating( $listing_id );
			}
			$review_count = get_comments_number( $listing_id );
		}

		$is_claimed = get_post_meta( $listing_id, '_claimed', true );

		if ( 'yes' === $settings['show_map'] && $lat && $lng ) {
			wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
		}

		$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' );
		$all_listings_url = home_url( '/all-listings/' );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		
		$referrer = wp_get_referer();
		$back_url = $all_listings_url;
		
		if ( $referrer ) {
			$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
			if ( $referrer_host === $site_host ) {
				$back_url = $referrer;
			}
		}
		?>
		<div class="hbl-single-listing-widget">
			<div class="hbl-single-listing-hero no-image">
				<div class="hbl-single-listing-hero-overlay"></div>
				
				<div class="hbl-single-listing-hero-content">
					<div class="hbl-single-listing-nav">
						<a href="<?php echo esc_url( $back_url ); ?>" class="hbl-single-listing-back-btn">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<span><?php esc_html_e( 'Back to Directory', 'hbl' ); ?></span>
						</a>
						
						<div class="hbl-single-listing-actions">
							<button class="hbl-single-listing-action-btn hbl-share-btn" title="<?php esc_attr_e( 'Share', 'hbl' ); ?>">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="18" cy="5" r="3" stroke="currentColor" stroke-width="2"/>
									<circle cx="6" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
									<circle cx="18" cy="19" r="3" stroke="currentColor" stroke-width="2"/>
									<path d="M8.59 13.51L15.42 17.49M15.41 6.51L8.59 10.49" stroke="currentColor" stroke-width="2"/>
								</svg>
							</button>
							<?php 
							$is_favorited = false;
							if ( is_user_logged_in() ) {
								$user_favorites = get_user_meta( get_current_user_id(), 'atbdp_favourites', true );
								$user_favorites = is_array( $user_favorites ) ? $user_favorites : array();
								$is_favorited = in_array( $listing_id, $user_favorites );
							}
							?>
							<button class="hbl-single-listing-action-btn hbl-favorite-btn <?php echo $is_favorited ? 'is-favorited' : ''; ?>" data-listing-id="<?php echo esc_attr( $listing_id ); ?>" data-type="listing" title="<?php echo $is_favorited ? esc_attr__( 'Remove from Favorites', 'hbl' ) : esc_attr__( 'Add to Favorites', 'hbl' ); ?>">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="<?php echo $is_favorited ? 'currentColor' : 'none'; ?>" xmlns="http://www.w3.org/2000/svg">
									<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</button>
						</div>
					</div>
					
					<div class="hbl-single-listing-hero-info">
						<?php if ( $logo_url ) : ?>
							<div class="hbl-single-listing-logo">
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $title ); ?>">
							</div>
						<?php endif; ?>
						
						<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
							<div class="hbl-single-listing-categories">
								<?php foreach ( $categories as $category ) : ?>
									<a href="<?php echo esc_url( get_term_link( $category ) ); ?>" class="hbl-single-listing-category-badge">
										<?php echo esc_html( $category->name ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						
						<div class="hbl-listing-title-row">
							<h1 class="hbl-single-listing-title"><?php echo esc_html( $title ); ?></h1>
							<?php if ( $is_claimed ) : ?>
								<span class="hbl-claimed-badge hbl-claimed-badge-large">
									<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Claimed', 'hbl' ); ?>
								</span>
							<?php endif; ?>
						</div>
						
						<?php if ( $average_rating > 0 && 'bronze' !== $plan_tier ) : ?>
							<div class="hbl-v2-rating" style="display: flex; gap: 8px; align-items: center; margin-bottom: 20px;">
								<div class="hbl-v2-stars" style="color: #F9532A;">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<svg width="16" height="16" viewBox="0 0 24 24" fill="<?php echo $i <= $average_rating ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg">
											<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
										</svg>
									<?php endfor; ?>
								</div>
								<span class="hbl-v2-review-count" style="color: #ffffff;">(<?php echo esc_html( $review_count ); ?>)</span>
							</div>
						<?php endif; ?>
						
						<?php if ( $tagline ) : ?>
							<p class="hbl-single-listing-tagline"><?php echo esc_html( $tagline ); ?></p>
						<?php endif; ?>
						
						<div class="hbl-single-listing-quick-info">

							
							<?php if ( $locations && ! is_wp_error( $locations ) ) : ?>
								<div class="hbl-single-listing-quick-item">
									<div class="hbl-single-listing-quick-info-icon">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
										</svg>
									</div>
									<span class="hbl-single-listing-quick-text"><?php echo esc_html( $locations[0]->name ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			
			<div class="hbl-single-listing-content">
				<div class="hbl-single-listing-grid">
					<div class="hbl-single-listing-main">
						<div class="hbl-single-listing-section">
							<div class="hbl-single-listing-section-header">
								<div class="hbl-single-listing-section-icon">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M14 2V8H20M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<h2 class="hbl-single-listing-section-title"><?php esc_html_e( 'About This Business', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-single-listing-section-content">
								<div class="hbl-single-listing-description">
									<?php echo wp_kses_post( wpautop( $content ) ); ?>
								</div>
							</div>
						</div>
						
						<?php if ( 'bronze' === $plan_tier ) : ?>
						<div class="hbl-bronze-upgrade-notice">
							<?php esc_html_e( 'Silver and Gold listings include website links, maps, directions and reviews.', 'hbl' ); ?>
						</div>
						<?php endif; ?>
						
						<?php 
						$services_text = '';
						$service_fields = array(
							'custom-textarea',
							'custom_textarea',
							'_custom-textarea',
							'_custom_textarea',
							'_features',
							'features',
							'services',
							'_services'
						);
						
						foreach ( $service_fields as $field ) {
							$services_text = get_post_meta( $listing_id, $field, true );
							if ( ! empty( $services_text ) ) {
								break;
							}
						}
						
						$pricing_text = '';
						$pricing_fields = array(
							'custom-textarea-2',
							'custom_textarea_2',
							'_custom-textarea-2',
							'_custom_textarea_2',
							'_pricing',
							'pricing'
						);
						
						foreach ( $pricing_fields as $field ) {
							$pricing_text = get_post_meta( $listing_id, $field, true );
							if ( ! empty( $pricing_text ) ) {
                                if ( is_array( $pricing_text ) ) {
                                    $pricing_text = implode( "\n", $pricing_text );
                                }
								break;
							}
						}

                        $pricing_type = get_post_meta( $listing_id, '_atbd_listing_pricing', true );
                        $price = get_post_meta( $listing_id, '_price', true );
                        $price_range = get_post_meta( $listing_id, '_price_range', true );

                        $has_structured_price = ( 'price' === $pricing_type && ! empty( $price ) ) || ( 'range' === $pricing_type && ! empty( $price_range ) );
                        if ( empty( $pricing_type ) && ! empty( $price ) ) {
                            $has_structured_price = true;
                            $pricing_type = 'price';
                        }
						
						$show_services = 'yes' === $settings['show_services'] && ! empty( $services_text );
						$show_pricing = 'yes' === $settings['show_pricing'] && ( ! empty( $pricing_text ) || $has_structured_price );
						
						if ( $show_services || $show_pricing ) : 
						?>
						<div class="hbl-single-listing-section">
							<div class="hbl-single-listing-section-header">
								<div class="hbl-single-listing-section-icon">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M9 5H7C6.46957 5 5.96086 5.21071 5.58579 5.58579C5.21071 5.96086 5 6.46957 5 7V19C5 19.5304 5.21071 20.0391 5.58579 20.4142C5.96086 20.7893 6.46957 21 7 21H17C17.5304 21 18.0391 20.7893 18.4142 20.4142C18.7893 20.0391 19 19.5304 19 19V7C19 6.46957 18.7893 5.96086 18.4142 5.58579C18.0391 5.21071 17.5304 5 17 5H15M9 5C9 5.53043 9.21071 6.03914 9.58579 6.41421C9.96086 6.78929 10.4696 7 11 7H13C13.5304 7 14.0391 6.78929 14.4142 6.41421C14.7893 6.03914 15 5.53043 15 5M9 5C9 4.46957 9.21071 3.96086 9.58579 3.58579C9.96086 3.21071 10.4696 3 11 3H13C13.5304 3 14.0391 3.21071 14.4142 3.58579C14.7893 3.96086 15 4.46957 15 5M12 12H15M12 16H15M9 12H9.01M9 16H9.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<h2 class="hbl-single-listing-section-title"><?php esc_html_e( 'Services', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-single-listing-section-content">
								<div class="hbl-two-column-section">
									<?php if ( $show_services ) : ?>
									<div class="hbl-services-section">
										<h4><?php esc_html_e( 'Services', 'hbl' ); ?></h4>
										<?php
										$services_lines = array_filter( array_map( 'trim', explode( "\n", $services_text ) ) );
										if ( ! empty( $services_lines ) ) :
										?>
											<ul class="hbl-services-list">
												<?php foreach ( $services_lines as $service ) : ?>
													<li><i class="bi bi-check-circle-fill"></i><?php echo esc_html( $service ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</div>
									<?php endif; ?>
									

								</div>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ( 'yes' === $settings['show_gallery'] && ! empty( $gallery_ids ) ) : ?>
						<div class="hbl-single-listing-section">
							<div class="hbl-single-listing-section-header">
								<div class="hbl-single-listing-section-icon">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
										<circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
										<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<h2 class="hbl-single-listing-section-title"><?php esc_html_e( 'Photo Gallery', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-single-listing-section-content">
								<div class="hbl-single-listing-gallery">
									<?php 
									if ( is_array( $gallery_ids ) ) {
										$gallery_array = array_filter( array_map( 'absint', $gallery_ids ) );
									} else {
										$gallery_string = trim( $gallery_ids );
										if ( ! empty( $gallery_string ) ) {
											$gallery_array = array_filter( array_map( 'absint', explode( ',', $gallery_string ) ) );
										} else {
											$gallery_array = array();
										}
									}
									
									foreach ( $gallery_array as $image_id ) : 
										$image_id = absint( $image_id );
										if ( ! $image_id ) continue;
										
										$image_url = wp_get_attachment_image_url( $image_id, 'medium_large' );
										$image_full = wp_get_attachment_image_url( $image_id, 'full' );
										if ( $image_url ) :
									?>
										<a href="<?php echo esc_url( $image_full ); ?>" class="hbl-single-listing-gallery-item" data-lightbox="gallery">
											<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $title ); ?>">
											<div class="hbl-single-listing-gallery-overlay">
												<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
													<path d="M21 21L16.65 16.65M11 8V14M8 11H14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
												</svg>
											</div>
										</a>
									<?php 
										endif;
									endforeach; 
									?>
								</div>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ( $video_url ) : ?>
						<div class="hbl-single-listing-section">
							<div class="hbl-single-listing-section-header">
								<div class="hbl-single-listing-section-icon">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<polygon points="5 3 19 12 5 21 5 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<h2 class="hbl-single-listing-section-title"><?php esc_html_e( 'Video', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-single-listing-section-content">
								<div class="hbl-single-listing-video">
									<?php echo wp_oembed_get( $video_url ); ?>
								</div>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ( 'yes' === $settings['show_map'] && $lat && $lng && 'bronze' !== $plan_tier ) : ?>
						<div class="hbl-single-listing-section">
							<div class="hbl-single-listing-section-header">
								<div class="hbl-single-listing-section-icon">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
									</svg>
								</div>
								<h2 class="hbl-single-listing-section-title"><?php esc_html_e( 'Location', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-single-listing-section-content">
								<div id="hbl-single-listing-map" class="hbl-single-listing-map" data-lat="<?php echo esc_attr( $lat ); ?>" data-lng="<?php echo esc_attr( $lng ); ?>" data-title="<?php echo esc_attr( $title ); ?>"></div>
								<?php if ( $address ) : ?>
									<div class="hbl-single-listing-address-box">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
										</svg>
										<span><?php echo esc_html( $address ); ?></span>
									</div>
								<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ( 'yes' === $settings['show_reviews'] && 'gold' === $plan_tier ) : ?>
						<div class="hbl-single-listing-section">
							<div class="hbl-single-listing-section-header">
								<div class="hbl-single-listing-section-icon">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M21 15C21 15.5304 20.7893 16.0391 20.4142 16.4142C20.0391 16.7893 19.5304 17 19 17H7L3 21V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H19C19.5304 3 20.0391 3.21071 20.4142 3.58579C20.7893 3.96086 21 4.46957 21 5V15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<h2 class="hbl-single-listing-section-title"><?php esc_html_e( 'Reviews', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-single-listing-section-content">
								<div class="hbl-single-listing-reviews">
									<?php
									$reviews = get_comments( array(
										'post_id' => $listing_id,
										'status'  => 'approve',
										'number'  => 10,
									) );
									
									if ( ! empty( $reviews ) ) :
										foreach ( $reviews as $review ) :
											$rating = get_comment_meta( $review->comment_ID, 'rating', true );
									?>
										<div class="hbl-single-listing-review">
											<div class="hbl-single-listing-review-header">
												<div class="hbl-single-listing-review-avatar">
													<?php echo get_avatar( $review->comment_author_email, 50 ); ?>
												</div>
												<div class="hbl-single-listing-review-info">
													<h4 class="hbl-single-listing-review-author"><?php echo esc_html( $review->comment_author ); ?></h4>
													<span class="hbl-single-listing-review-date"><?php echo esc_html( human_time_diff( strtotime( $review->comment_date ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'hbl' ); ?></span>
												</div>
												<?php if ( $rating ) : ?>
													<div class="hbl-single-listing-review-rating">
														<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
															<svg width="14" height="14" viewBox="0 0 24 24" fill="<?php echo $i <= $rating ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg">
																<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
															</svg>
														<?php endfor; ?>
													</div>
												<?php endif; ?>
											</div>
											<div class="hbl-single-listing-review-content">
												<?php echo wp_kses_post( $review->comment_content ); ?>
											</div>
										</div>
									<?php 
										endforeach;
									else :
									?>
										<div class="hbl-single-listing-no-reviews">
											<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M21 15C21 15.5304 20.7893 16.0391 20.4142 16.4142C20.0391 16.7893 19.5304 17 19 17H7L3 21V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H19C19.5304 3 20.0391 3.21071 20.4142 3.58579C20.7893 3.96086 21 4.46957 21 5V15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<h4><?php esc_html_e( 'No Reviews Yet', 'hbl' ); ?></h4>
											<p><?php esc_html_e( 'Be the first to leave a review!', 'hbl' ); ?></p>
										</div>
									<?php endif; ?>
									
									<?php if ( is_user_logged_in() ) : ?>
										<div class="hbl-single-listing-review-form">
											<h3><?php esc_html_e( 'Write a Review', 'hbl' ); ?></h3>
											<form id="hbl-review-form" method="post">
												<input type="hidden" name="listing_id" value="<?php echo esc_attr( $listing_id ); ?>">
												<div class="hbl-review-rating-select">
													<label><?php esc_html_e( 'Your Rating', 'hbl' ); ?></label>
													<div class="hbl-review-stars">
														<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
															<input type="radio" name="rating" id="star-<?php echo $i; ?>" value="<?php echo $i; ?>">
															<label for="star-<?php echo $i; ?>">
																<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
																	<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
																</svg>
															</label>
														<?php endfor; ?>
													</div>
												</div>
												<div class="hbl-review-content-input">
													<label for="review-content"><?php esc_html_e( 'Your Review', 'hbl' ); ?></label>
													<textarea id="review-content" name="review_content" rows="4" placeholder="<?php esc_attr_e( 'Share your experience...', 'hbl' ); ?>" required></textarea>
												</div>
												<button type="submit" class="hbl-single-listing-cta-btn">
													<?php esc_html_e( 'Submit Review', 'hbl' ); ?>
												</button>
											</form>
										</div>
									<?php else : ?>
										<div class="hbl-single-listing-login-prompt">
											<p><?php esc_html_e( 'Please', 'hbl' ); ?> <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'login', 'hbl' ); ?></a> <?php esc_html_e( 'to write a review.', 'hbl' ); ?></p>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endif; ?>
					</div>
					
					<div class="hbl-single-listing-sidebar">
						<?php if ( 'yes' === $settings['show_contact_info'] ) : ?>
						<div class="hbl-single-listing-card">
							<div class="hbl-single-listing-card-header">
								<h3 class="hbl-single-listing-card-title">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1469 21.5901 20.9046 21.7335 20.6408 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77383 17.3147 6.72534 15.2662 5.19 12.85C3.49998 10.2412 2.44824 7.27097 2.12 4.18C2.09501 3.90347 2.12788 3.62476 2.2165 3.36162C2.30513 3.09849 2.44757 2.85669 2.63477 2.65163C2.82196 2.44656 3.04981 2.28271 3.30379 2.17053C3.55778 2.05834 3.83234 2.00026 4.11 2H7.11C7.59531 1.99522 8.06579 2.16708 8.43376 2.48353C8.80173 2.79999 9.04208 3.23945 9.11 3.72C9.23662 4.68007 9.47145 5.62273 9.81 6.53C9.94454 6.88792 9.97366 7.27691 9.89391 7.65088C9.81415 8.02485 9.62886 8.36811 9.36 8.64L8.09 9.91C9.51356 12.4136 11.5864 14.4864 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9752 14.1858 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Contact Info', 'hbl' ); ?>
								</h3>
							</div>
							
							<div class="hbl-single-listing-contact-list">
								<?php if ( $phone ) : ?>
									<a href="tel:<?php echo esc_attr( $phone ); ?>" class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1469 21.5901 20.9046 21.7335 20.6408 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77383 17.3147 6.72534 15.2662 5.19 12.85C3.49998 10.2412 2.44824 7.27097 2.12 4.18C2.09501 3.90347 2.12788 3.62476 2.2165 3.36162C2.30513 3.09849 2.44757 2.85669 2.63477 2.65163C2.82196 2.44656 3.04981 2.28271 3.30379 2.17053C3.55778 2.05834 3.83234 2.00026 4.11 2H7.11C7.59531 1.99522 8.06579 2.16708 8.43376 2.48353C8.80173 2.79999 9.04208 3.23945 9.11 3.72C9.23662 4.68007 9.47145 5.62273 9.81 6.53C9.94454 6.88792 9.97366 7.27691 9.89391 7.65088C9.81415 8.02485 9.62886 8.36811 9.36 8.64L8.09 9.91C9.51356 12.4136 11.5864 14.4864 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9752 14.1858 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php esc_html_e( 'Phone', 'hbl' ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( $phone ); ?></span>
										</div>
									</a>
								<?php endif; ?>

								<?php if ( $phone2 ) : ?>
									<a href="tel:<?php echo esc_attr( $phone2 ); ?>" class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1469 21.5901 20.9046 21.7335 20.6408 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77383 17.3147 6.72534 15.2662 5.19 12.85C3.49998 10.2412 2.44824 7.27097 2.12 4.18C2.09501 3.90347 2.12788 3.62476 2.2165 3.36162C2.30513 3.09849 2.44757 2.85669 2.63477 2.65163C2.82196 2.44656 3.04981 2.28271 3.30379 2.17053C3.55778 2.05834 3.83234 2.00026 4.11 2H7.11C7.59531 1.99522 8.06579 2.16708 8.43376 2.48353C8.80173 2.79999 9.04208 3.23945 9.11 3.72C9.23662 4.68007 9.47145 5.62273 9.81 6.53C9.94454 6.88792 9.97366 7.27691 9.89391 7.65088C9.81415 8.02485 9.62886 8.36811 9.36 8.64L8.09 9.91C9.51356 12.4136 11.5864 14.4864 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9752 14.1858 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php esc_html_e( 'Phone', 'hbl' ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( $phone2 ); ?></span>
										</div>
									</a>
								<?php endif; ?>
								
								<?php if ( $email ) : ?>
									<?php if ( 'bronze' === $plan_tier ) : ?>
									<div class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php esc_html_e( 'Email', 'hbl' ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( $email ); ?></span>
										</div>
									</div>
									<?php else : ?>
									<a href="mailto:<?php echo esc_attr( $email ); ?>" class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php esc_html_e( 'Email', 'hbl' ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( $email ); ?></span>
										</div>
									</a>
									<?php endif; ?>
								<?php endif; ?>
								
								<?php if ( $website ) : ?>
									<?php if ( 'bronze' === $plan_tier ) : ?>
									<div class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
												<path d="M2 12H22M12 2C14.5013 4.73835 15.9228 8.29203 16 12C15.9228 15.708 14.5013 19.2616 12 22C9.49872 19.2616 8.07725 15.708 8 12C8.07725 8.29203 9.49872 4.73835 12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php esc_html_e( 'Website', 'hbl' ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( parse_url( $website, PHP_URL_HOST ) ); ?></span>
										</div>
									</div>
									<?php else : ?>
									<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
												<path d="M2 12H22M12 2C14.5013 4.73835 15.9228 8.29203 16 12C15.9228 15.708 14.5013 19.2616 12 22C9.49872 19.2616 8.07725 15.708 8 12C8.07725 8.29203 9.49872 4.73835 12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php esc_html_e( 'Website', 'hbl' ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( parse_url( $website, PHP_URL_HOST ) ); ?></span>
										</div>
									</a>
									<?php endif; ?>
								<?php endif; ?>
								
								<?php 
								$display_address = $address;
								$address_label = __( 'Address', 'hbl' );
								
								if ( 'bronze' === $plan_tier ) {
									if ( $locations && ! is_wp_error( $locations ) && ! empty( $locations ) ) {
										$display_address = $locations[0]->name;
										$address_label = __( 'Location', 'hbl' );
									} else {
										$display_address = '';
									}
								}
								
								if ( $display_address ) : ?>
									<div class="hbl-single-listing-contact-item">
										<div class="hbl-single-listing-contact-icon">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
											</svg>
										</div>
										<div class="hbl-single-listing-contact-text">
											<span class="hbl-single-listing-contact-label"><?php echo esc_html( $address_label ); ?></span>
											<span class="hbl-single-listing-contact-value"><?php echo esc_html( $display_address ); ?></span>
										</div>
									</div>
								<?php endif; ?>
							</div>
							
							<?php if ( $phone || $email ) : ?>
								<div class="hbl-single-listing-cta-buttons">
									<?php if ( $phone ) : ?>
										<a href="tel:<?php echo esc_attr( $phone ); ?>" class="hbl-single-listing-cta-btn hbl-single-listing-cta-primary">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1469 21.5901 20.9046 21.7335 20.6408 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77383 17.3147 6.72534 15.2662 5.19 12.85C3.49998 10.2412 2.44824 7.27097 2.12 4.18C2.09501 3.90347 2.12788 3.62476 2.2165 3.36162C2.30513 3.09849 2.44757 2.85669 2.63477 2.65163C2.82196 2.44656 3.04981 2.28271 3.30379 2.17053C3.55778 2.05834 3.83234 2.00026 4.11 2H7.11C7.59531 1.99522 8.06579 2.16708 8.43376 2.48353C8.80173 2.79999 9.04208 3.23945 9.11 3.72C9.23662 4.68007 9.47145 5.62273 9.81 6.53C9.94454 6.88792 9.97366 7.27691 9.89391 7.65088C9.81415 8.02485 9.62886 8.36811 9.36 8.64L8.09 9.91C9.51356 12.4136 11.5864 14.4864 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9752 14.1858 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php esc_html_e( 'Call Now', 'hbl' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( $email && 'bronze' !== $plan_tier ) : ?>
										<a href="mailto:<?php echo esc_attr( $email ); ?>" class="hbl-single-listing-cta-btn hbl-single-listing-cta-secondary">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php esc_html_e( 'Send Email', 'hbl' ); ?>
										</a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
						<?php endif; ?>
						
						<?php if ( 'yes' === $settings['show_social_links'] && ( $facebook || $twitter || $instagram || $linkedin || $youtube || $tiktok ) ) : ?>
						<div class="hbl-single-listing-card">
							<div class="hbl-single-listing-card-header">
								<h3 class="hbl-single-listing-card-title">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Follow Us', 'hbl' ); ?>
								</h3>
							</div>
							
							<div class="hbl-single-listing-social-links">
								<?php if ( $facebook ) : ?>
									<a href="<?php echo esc_url( $facebook ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-social-link hbl-social-facebook" title="Facebook">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
											<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z"/>
										</svg>
									</a>
								<?php endif; ?>
								
								<?php if ( $twitter ) : ?>
									<a href="<?php echo esc_url( $twitter ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-social-link hbl-social-twitter" title="Twitter/X">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
											<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
										</svg>
									</a>
								<?php endif; ?>
								
								<?php if ( $instagram ) : ?>
									<a href="<?php echo esc_url( $instagram ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-social-link hbl-social-instagram" title="Instagram">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg">
											<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
											<path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37zM17.5 6.5h.01"/>
										</svg>
									</a>
								<?php endif; ?>
								
								<?php if ( $linkedin ) : ?>
									<a href="<?php echo esc_url( $linkedin ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-social-link hbl-social-linkedin" title="LinkedIn">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
											<path d="M16 8C17.5913 8 19.1174 8.63214 20.2426 9.75736C21.3679 10.8826 22 12.4087 22 14V21H18V14C18 13.4696 17.7893 12.9609 17.4142 12.5858C17.0391 12.2107 16.5304 12 16 12C15.4696 12 14.9609 12.2107 14.5858 12.5858C14.2107 12.9609 14 13.4696 14 14V21H10V14C10 12.4087 10.6321 10.8826 11.7574 9.75736C12.8826 8.63214 14.4087 8 16 8Z"/>
											<rect x="2" y="9" width="4" height="12"/>
											<circle cx="4" cy="4" r="2"/>
										</svg>
									</a>
								<?php endif; ?>
								
								<?php if ( $youtube ) : ?>
									<a href="<?php echo esc_url( $youtube ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-social-link hbl-social-youtube" title="YouTube">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
											<path d="M22.54 6.42C22.4212 5.94541 22.1793 5.51057 21.8387 5.15941C21.498 4.80824 21.0708 4.55318 20.6 4.42C18.88 4 12 4 12 4C12 4 5.12 4 3.4 4.46C2.92925 4.59318 2.50198 4.84824 2.16135 5.19941C1.82072 5.55057 1.57879 5.98541 1.46 6.46C1.14521 8.20556 0.991235 9.97631 1 11.75C0.988687 13.537 1.14266 15.3213 1.46 17.08C1.59096 17.5398 1.83831 17.9581 2.17814 18.2945C2.51798 18.6308 2.93882 18.8738 3.4 19C5.12 19.46 12 19.46 12 19.46C12 19.46 18.88 19.46 20.6 19C21.0708 18.8668 21.498 18.6118 21.8387 18.2606C22.1793 17.9094 22.4212 17.4746 22.54 17C22.8524 15.2676 23.0064 13.5103 23 11.75C23.0113 9.96295 22.8573 8.1787 22.54 6.42Z"/>
											<path d="M9.75 15.02L15.5 11.75L9.75 8.48V15.02Z" fill="white"/>
										</svg>
									</a>
								<?php endif; ?>
								
								<?php if ( $tiktok ) : ?>
									<a href="<?php echo esc_url( $tiktok ); ?>" target="_blank" rel="noopener" class="hbl-single-listing-social-link hbl-social-tiktok" title="TikTok">
										<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
											<path d="M19.59 6.69C18.5506 6.49321 17.6041 5.98178 16.8803 5.22706C16.1565 4.47234 15.6908 3.51208 15.55 2.47V2H12.45V15.9C12.4262 16.6979 12.093 17.4535 11.5241 18.0106C10.9551 18.5677 10.1929 18.8844 9.395 18.89C7.71 18.89 6.31 17.51 6.31 15.78C6.31 13.71 8.32 12.14 10.37 12.75V9.58C6.6 9.05 3.21 11.96 3.21 15.78C3.21 19.48 6.22 22 9.38 22C12.77 22 15.55 19.22 15.55 15.78V8.72C16.9629 9.75536 18.6636 10.3136 20.41 10.32V7.2C20.41 7.2 19.95 7.23 19.59 6.69Z"/>
										</svg>
									</a>
								<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if ( $business_hours && is_array( $business_hours ) ) : ?>
						<div class="hbl-single-listing-card">
							<div class="hbl-single-listing-card-header">
								<h3 class="hbl-single-listing-card-title">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
										<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Business Hours', 'hbl' ); ?>
								</h3>
							</div>
							
							<div class="hbl-single-listing-hours-list">
								<?php 
								$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
								foreach ( $days as $day ) :
									if ( isset( $business_hours[$day] ) ) :
										$hours = $business_hours[$day];
								?>
									<div class="hbl-single-listing-hours-item">
										<span class="hbl-single-listing-hours-day"><?php echo esc_html( ucfirst( $day ) ); ?></span>
										<span class="hbl-single-listing-hours-time">
											<?php 
											if ( isset( $hours['closed'] ) && $hours['closed'] ) {
												esc_html_e( 'Closed', 'hbl' );
											} else {
												echo esc_html( $hours['start'] . ' - ' . $hours['end'] );
											}
											?>
										</span>
									</div>
								<?php 
									endif;
								endforeach; 
								?>
							</div>
						</div>
						<?php endif; ?>
						
						<?php 
						$claimed_by_admin = get_post_meta( $listing_id, '_claimed_by_admin', true );
						$claim_fee = get_post_meta( $listing_id, '_claim_fee', true );
						$is_claimed = $claimed_by_admin || ( 'claim_approved' === $claim_fee );
						$show_claim = 'yes' === $settings['show_claim_listing'];
						
						if ( $show_claim && ! $is_claimed ) :
							$claim_title = function_exists( 'get_directorist_option' ) ? get_directorist_option( 'claim_widget_title', esc_html__( 'Is this your business?', 'hbl' ) ) : esc_html__( 'Is this your business?', 'hbl' );
							$claim_description = function_exists( 'get_directorist_option' ) ? get_directorist_option( 'claim_widget_description', esc_html__( 'Claim listing is the best way to manage and protect your business.', 'hbl' ) ) : esc_html__( 'Claim listing is the best way to manage and protect your business.', 'hbl' );
							$claim_button_text = ! empty( $settings['claim_button_text'] ) ? $settings['claim_button_text'] : esc_html__( 'Claim Now', 'hbl' );
							$claim_link = $settings['claim_listing_link'];
							$has_custom_link = ! empty( $claim_link['url'] );
							
							$link_attrs = '';
							if ( $has_custom_link ) {
								$claim_url = $claim_link['url'];
								$claim_url = add_query_arg( array(
									'listing_id' => $listing_id,
									'listing_title' => urlencode( get_the_title( $listing_id ) ),
								), $claim_url );
								$link_attrs .= ' href="' . esc_url( $claim_url ) . '"';
								if ( ! empty( $claim_link['is_external'] ) ) {
									$link_attrs .= ' target="_blank"';
								}
								if ( ! empty( $claim_link['nofollow'] ) ) {
									$link_attrs .= ' rel="nofollow"';
								}
							}
						?>
						<div class="hbl-single-listing-card hbl-single-listing-claim-card">
							<div class="hbl-single-listing-card-header">
								<h3 class="hbl-single-listing-card-title">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Claim Listing', 'hbl' ); ?>
								</h3>
							</div>
							
							<div class="hbl-single-listing-claim-content">
								<p class="hbl-single-listing-claim-title"><?php echo esc_html( $claim_title ); ?></p>
								<p class="hbl-single-listing-claim-description"><?php echo esc_html( $claim_description ); ?></p>
								
										<?php if ( is_user_logged_in() ) : ?>
									<?php if ( $has_custom_link ) : ?>
										<a<?php echo $link_attrs; ?> class="hbl-single-listing-claim-btn hbl-claim-trigger" data-listing-id="<?php echo esc_attr( $listing_id ); ?>" data-listing-title="<?php echo esc_attr( get_the_title( $listing_id ) ); ?>">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php echo esc_html( $claim_button_text ); ?>
										</a>
									<?php else : ?>
										<a href="#" class="hbl-single-listing-claim-btn directorist-btn-modal directorist-btn-modal-js" data-directorist_target="directorist-claim-listing-modal" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php echo esc_html( $claim_button_text ); ?>
										</a>
									<?php endif; ?>
								<?php else : ?>
									<?php if ( $has_custom_link ) : ?>
										<a<?php echo $link_attrs; ?> class="hbl-single-listing-claim-btn hbl-claim-trigger" data-listing-id="<?php echo esc_attr( $listing_id ); ?>" data-listing-title="<?php echo esc_attr( get_the_title( $listing_id ) ); ?>">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php echo esc_html( $claim_button_text ); ?>
										</a>
									<?php else : ?>
										<a href="<?php echo esc_url( home_url( '/sign-in/' ) ); ?>" class="hbl-single-listing-claim-btn">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php esc_html_e( 'Sign In to Claim', 'hbl' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( ! $has_custom_link ) : ?>
									<p class="hbl-single-listing-claim-login-notice">
										<?php esc_html_e( 'You need to sign in to claim this listing.', 'hbl' ); ?>
									</p>
									<?php endif; ?>
								<?php endif; ?>
								
								<script>
								jQuery(document).ready(function($) {
									$('.hbl-claim-trigger').on('click', function() {
										var listingId = $(this).data('listing-id');
										var listingTitle = $(this).data('listing-title');
										if (listingId) {
											localStorage.setItem('hbl_claim_listing_id', listingId);
											localStorage.setItem('hbl_claim_listing_title', listingTitle);
										}
									});
								});
								</script>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>
				
				<?php if ( 'yes' === $settings['show_related'] ) : ?>
				<div class="hbl-single-listing-related">
					<div class="hbl-single-listing-related-header">
						<h2 class="hbl-single-listing-related-title">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Related Listings', 'hbl' ); ?>
						</h2>
					</div>
					
					<div class="hbl-single-listing-related-grid">
						<?php
						$related_args = array(
							'post_type'      => ATBDP_POST_TYPE,
							'posts_per_page' => absint( $settings['related_count'] ),
							'post__not_in'   => array( $listing_id ),
							'orderby'        => 'rand',
						);
						
						if ( $categories && ! is_wp_error( $categories ) ) {
							$related_args['tax_query'] = array(
								array(
									'taxonomy' => ATBDP_CATEGORY,
									'field'    => 'term_id',
									'terms'    => wp_list_pluck( $categories, 'term_id' ),
								),
							);
						}
						
						$related_query = new \WP_Query( $related_args );
						
						if ( $related_query->have_posts() ) :
							while ( $related_query->have_posts() ) : $related_query->the_post();
								$related_id = get_the_ID();
								
								$related_image = get_the_post_thumbnail_url( $related_id, 'medium' );
								if ( ! $related_image ) {
									$preview_img_id = get_post_meta( $related_id, '_listing_prv_img', true );
									if ( $preview_img_id ) {
										$related_image = wp_get_attachment_image_url( $preview_img_id, 'medium' );
									}
								}
								if ( ! $related_image ) {
									$gallery_ids = get_post_meta( $related_id, '_listing_img', true );
									if ( ! empty( $gallery_ids ) ) {
										$first_id = is_array( $gallery_ids ) ? reset( $gallery_ids ) : explode( ',', $gallery_ids )[0];
										if ( $first_id ) {
											$related_image = wp_get_attachment_image_url( absint( $first_id ), 'medium' );
										}
									}
								}
								
								$related_categories = get_the_terms( $related_id, ATBDP_CATEGORY );
								$related_locations = get_the_terms( $related_id, ATBDP_LOCATION );
								$related_rating = function_exists( 'directorist_get_listing_rating' ) ? directorist_get_listing_rating( $related_id ) : 0;
						?>
							<a href="<?php the_permalink(); ?>" class="hbl-single-listing-related-card">
								<div class="hbl-single-listing-related-image">
									<?php if ( $related_image ) : ?>
										<img src="<?php echo esc_url( $related_image ); ?>" alt="<?php the_title_attribute(); ?>" class="hbl-single-listing-related-img">
									<?php else : ?>
										<div class="hbl-single-listing-related-placeholder">
											<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
												<circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
												<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
									<?php endif; ?>
									<?php if ( $related_rating > 0 ) : ?>
										<div class="hbl-single-listing-related-rating">
											<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
												<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
											</svg>
											<?php echo esc_html( number_format( $related_rating, 1 ) ); ?>
										</div>
									<?php endif; ?>
								</div>
								<div class="hbl-single-listing-related-content">
									<h4 class="hbl-single-listing-related-card-title"><?php the_title(); ?></h4>
									<?php if ( $related_categories && ! is_wp_error( $related_categories ) ) : ?>
										<span class="hbl-single-listing-related-category"><?php echo esc_html( $related_categories[0]->name ); ?></span>
									<?php endif; ?>
									<?php if ( $related_locations && ! is_wp_error( $related_locations ) ) : ?>
										<span class="hbl-single-listing-related-location">
											<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2"/>
												<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
											</svg>
											<?php echo esc_html( $related_locations[0]->name ); ?>
										</span>
									<?php endif; ?>
								</div>
							</a>
						<?php 
							endwhile;
							wp_reset_postdata();
						else :
						?>
							<p class="hbl-single-listing-no-related"><?php esc_html_e( 'No related listings found.', 'hbl' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		
		<?php if ( 'yes' === $settings['show_map'] && $lat && $lng ) : ?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof L !== 'undefined') {
				var mapEl = document.getElementById('hbl-single-listing-map');
				if (mapEl) {
					var lat = parseFloat(mapEl.dataset.lat);
					var lng = parseFloat(mapEl.dataset.lng);
					var title = mapEl.dataset.title;
					
					var map = L.map('hbl-single-listing-map').setView([lat, lng], 15);
					
					L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
						attribution: '© OpenStreetMap contributors'
					}).addTo(map);
					
					L.marker([lat, lng]).addTo(map)
						.bindPopup('<strong>' + title + '</strong>')
						.openPopup();
				}
			}
		});
		</script>
		<?php endif; ?>
		
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var shareBtn = document.querySelector('.hbl-share-btn');
			if (shareBtn) {
				shareBtn.addEventListener('click', function() {
					var shareData = {
						title: '<?php echo esc_js( $title ); ?>',
						text: '<?php echo esc_js( wp_trim_words( $tagline ? $tagline : $content, 20 ) ); ?>',
						url: '<?php echo esc_url( get_permalink( $listing_id ) ); ?>'
					};
					
					if (navigator.share) {
						navigator.share(shareData).catch(function(err) {
						});
					} else {
						var tempInput = document.createElement('input');
						tempInput.value = shareData.url;
						document.body.appendChild(tempInput);
						tempInput.select();
						document.execCommand('copy');
						document.body.removeChild(tempInput);
						
						var originalTitle = shareBtn.getAttribute('title');
						shareBtn.setAttribute('title', '<?php echo esc_attr__( 'Link copied!', 'hbl' ); ?>');
						shareBtn.style.background = '#008080';
						shareBtn.style.color = '#ffffff';
						
						setTimeout(function() {
							shareBtn.setAttribute('title', originalTitle);
							shareBtn.style.background = '';
							shareBtn.style.color = '';
						}, 2000);
					}
				});
			}
		});
		</script>
		<?php
	}

	protected function get_pricing_plans() {
		$plans = get_posts( array(
			'post_type'      => 'atbdp_pricing_plans',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$options = array();
		if ( ! empty( $plans ) ) {
			foreach ( $plans as $plan ) {
				$options[ $plan->ID ] = $plan->post_title;
			}
		}

		return $options;
	}

	protected function get_plan_tier( $plan_id, $settings ) {
		$gold_plan_ids = isset( $settings['gold_plan_ids'] ) ? (array) $settings['gold_plan_ids'] : array();
		$silver_plan_ids = isset( $settings['silver_plan_ids'] ) ? (array) $settings['silver_plan_ids'] : array();

		$gold_plan_ids = array_map( 'absint', $gold_plan_ids );
		$silver_plan_ids = array_map( 'absint', $silver_plan_ids );

		if ( in_array( $plan_id, $gold_plan_ids, true ) ) {
			return 'gold';
		} elseif ( in_array( $plan_id, $silver_plan_ids, true ) ) {
			return 'silver';
		}

		if ( $plan_id > 0 ) {
			$plan = get_post( $plan_id );
			if ( $plan ) {
				$title = strtolower( $plan->post_title );
				if ( strpos( $title, 'gold' ) !== false ) {
					return 'gold';
				} elseif ( strpos( $title, 'silver' ) !== false ) {
					return 'silver';
				}
			}
		}

		return 'bronze';
	}
}
