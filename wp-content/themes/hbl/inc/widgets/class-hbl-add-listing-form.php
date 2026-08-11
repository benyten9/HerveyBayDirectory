<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Add_Listing_Form extends Widget_Base {

	public function get_name() {
		return 'hbl-add-listing-form';
	}

	public function get_title() {
		return esc_html__( 'HBL Add Listing Form', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'add', 'listing', 'form', 'submit', 'directorist', 'create' );
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'form_title',
			array(
				'label'   => esc_html__( 'Form Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Add New Listing',
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => esc_html__( 'Show Title', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'form_description',
			array(
				'label'     => esc_html__( 'Form Description', 'hbl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'default'   => 'Share your business with the Hervey Bay community.',
				'condition' => array(
					'show_title' => 'yes',
				),
			)
		);

		$this->add_control(
			'require_login',
			array(
				'label'        => esc_html__( 'Require Login', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show login prompt if user is not logged in', 'hbl' ),
			)
		);

		$this->add_control(
			'enable_recaptcha',
			array(
				'label'        => esc_html__( 'Enable reCAPTCHA', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Requires reCAPTCHA v2 keys in Elementor → Settings → Integrations.', 'hbl' ),
			)
		);

		$this->add_control(
			'enable_pricing_plans',
			array(
				'label'        => esc_html__( 'Enable Pricing Plans', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'If disabled, shows a "Not Launched" overlay instead of the plans.', 'hbl' ),
			)
		);

		$this->add_control(
			'coming_soon_plans',
			array(
				'label'       => esc_html__( 'Coming Soon Plan IDs', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'description' => esc_html__( 'Enter Plan IDs separated by commas (e.g., 123, 456) to show "Coming Soon" overlay on specific plans.', 'hbl' ),
				'condition'   => array(
					'enable_pricing_plans' => 'yes',
				),
			)
		);

		$this->add_control(
			'enable_business_info',
			array(
				'label'        => esc_html__( 'Enable Business Info', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_pricing_info',
			array(
				'label'        => esc_html__( 'Enable Pricing Options', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_services',
			array(
				'label'        => esc_html__( 'Enable Services', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_contact_info',
			array(
				'label'        => esc_html__( 'Enable Contact Info', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_map',
			array(
				'label'        => esc_html__( 'Enable Map', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_social',
			array(
				'label'        => esc_html__( 'Enable Social Media', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_media',
			array(
				'label'        => esc_html__( 'Enable Media Upload', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_typography',
			array(
				'label' => esc_html__( 'Typography', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => esc_html__( 'Title Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-add-listing-form-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-add-listing-form-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => esc_html__( 'Description Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6C757D',
				'selectors' => array(
					'{{WRAPPER}} .hbl-add-listing-form-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_buttons',
			array(
				'label' => esc_html__( 'Buttons', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => esc_html__( 'Button Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-form-btn',
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => esc_html__( 'Button Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Button Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Button Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			array(),
			'1.9.4'
		);
		wp_enqueue_script(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			array(),
			'1.9.4',
			true
		);

		if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			wp_enqueue_script( 'google-recaptcha' );
		}
		$editing_listing_id = 0;
		$listing_data = array();
		
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( preg_match( '/\/edit\/(\d+)\/?/', $request_uri, $matches ) ) {
			$editing_listing_id = absint( $matches[1] );
		}
		if ( ! $editing_listing_id && isset( $_GET['atbdp_listing_id'] ) ) {
			$editing_listing_id = absint( $_GET['atbdp_listing_id'] );
		}
		
		if ( $editing_listing_id && defined( 'ATBDP_POST_TYPE' ) ) {
			$listing_post = get_post( $editing_listing_id );
			$current_user_id = get_current_user_id();
			
			if ( $listing_post && get_post_type( $listing_post ) === ATBDP_POST_TYPE && 
			     ( (int) $listing_post->post_author === $current_user_id || current_user_can( 'edit_others_posts' ) ) ) {
				
				$listing_categories_terms = get_the_terms( $editing_listing_id, ATBDP_CATEGORY );
				$listing_category_ids = array();
				if ( $listing_categories_terms && ! is_wp_error( $listing_categories_terms ) ) {
					$listing_category_ids = wp_list_pluck( $listing_categories_terms, 'term_id' );
				}
				$listing_category_id = ! empty( $listing_category_ids ) ? $listing_category_ids[0] : 0;
				
				$listing_locations_terms = defined( 'ATBDP_LOCATION' ) ? get_the_terms( $editing_listing_id, ATBDP_LOCATION ) : array();
				$listing_location_id = ( $listing_locations_terms && ! is_wp_error( $listing_locations_terms ) ) ? $listing_locations_terms[0]->term_id : 0;
				
				$services_text = get_post_meta( $editing_listing_id, '_services', true );
				if ( empty( $services_text ) ) {
					$services_text = get_post_meta( $editing_listing_id, 'services', true );
				}
				$services_list = array();
				if ( ! empty( $services_text ) ) {
					$services_list = array_filter( array_map( 'trim', explode( "\n", $services_text ) ) );
				}
				
				$listing_tags_terms = get_the_terms( $editing_listing_id, 'at_biz_dir-tags' );
				$listing_tags_names = array();
				if ( $listing_tags_terms && ! is_wp_error( $listing_tags_terms ) ) {
					$listing_tags_names = wp_list_pluck( $listing_tags_terms, 'name' );
				}
				$tags_string = ! empty( $listing_tags_names ) ? implode( ',', $listing_tags_names ) : '';
				
				$selected_plan_id = get_post_meta( $editing_listing_id, '_fm_plans', true );
				
				$gallery_ids = get_post_meta( $editing_listing_id, '_listing_img', true );
				
				$logo_url = '';
				$custom_file = get_post_meta( $editing_listing_id, 'custom-file', true );
				if ( ! empty( $custom_file ) ) {
					$parts = explode( '|||', $custom_file );
					$logo_url = trim( $parts[0] );
				}
				$thumbnail_id = get_post_thumbnail_id( $editing_listing_id );
				
				$listing_data = array(
					'id'             => $editing_listing_id,
					'title'          => $listing_post->post_title,
					'content'        => $listing_post->post_content,
					'tagline'        => ! empty( $tags_string ) ? $tags_string : get_post_meta( $editing_listing_id, '_tagline', true ),
					'category'       => implode( ',', $listing_category_ids ),
					'categories'     => $listing_category_ids,
					'location'       => $listing_location_id,
					'phone'          => get_post_meta( $editing_listing_id, '_phone', true ),
					'email'          => get_post_meta( $editing_listing_id, '_email', true ),
					'website'        => get_post_meta( $editing_listing_id, '_website', true ),
					'address'        => get_post_meta( $editing_listing_id, '_address', true ),
					'thumbnail_id'   => $thumbnail_id,
					'logo_url'       => $logo_url,
					'plan_id'        => $selected_plan_id,
					'services'       => $services_list,
					'lat'            => get_post_meta( $editing_listing_id, '_manual_lat', true ),
					'lng'            => get_post_meta( $editing_listing_id, '_manual_lng', true ),
					'hide_map'       => get_post_meta( $editing_listing_id, '_hide_map', true ),
					'facebook'       => get_post_meta( $editing_listing_id, '_facebook', true ),
					'instagram'      => get_post_meta( $editing_listing_id, '_instagram', true ),
					'twitter'        => get_post_meta( $editing_listing_id, '_twitter', true ),
					'linkedin'       => get_post_meta( $editing_listing_id, '_linkedin', true ),
					'youtube'        => get_post_meta( $editing_listing_id, '_youtube', true ),
					'tiktok'         => get_post_meta( $editing_listing_id, '_tiktok', true ),
					'video'          => get_post_meta( $editing_listing_id, '_videourl', true ),
					'gallery'        => $gallery_ids,
					'pricing_type'   => get_post_meta( $editing_listing_id, '_atbd_listing_pricing', true ),
					'price'          => get_post_meta( $editing_listing_id, '_price', true ),
					'price_range'    => get_post_meta( $editing_listing_id, '_price_range', true ),
					'pricing_list'   => get_post_meta( $editing_listing_id, '_pricing', true ),
				);
			} else {
				$editing_listing_id = 0;
			}
		}
		
		$is_editing = ! empty( $listing_data );

		if ( 'yes' === $settings['require_login'] && ! is_user_logged_in() ) {
			$login_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_login_page_link() : wp_login_url();
			$register_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_registration_page_link() : wp_registration_url();
			?>
			<div class="hbl-add-listing-form-widget">
				<?php if ( 'yes' === $settings['show_title'] ) : ?>
					<div class="hbl-listing-form-header">
						<h2 class="hbl-add-listing-form-title"><?php echo esc_html( $settings['form_title'] ); ?></h2>
						<?php if ( ! empty( $settings['form_description'] ) ) : ?>
							<p class="hbl-add-listing-form-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="hbl-form-login-required">
					<div class="hbl-form-login-icon">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
					<h3><?php esc_html_e( 'Login Required', 'hbl' ); ?></h3>
					<p><?php esc_html_e( 'Please login to add a listing.', 'hbl' ); ?></p>
					<div class="hbl-form-login-buttons">
						<a href="<?php echo esc_url( $login_url ); ?>" class="hbl-form-btn hbl-form-btn-primary"><?php esc_html_e( 'Login', 'hbl' ); ?></a>
						<a href="<?php echo esc_url( $register_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary"><?php esc_html_e( 'Register', 'hbl' ); ?></a>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		$listing_categories = array();
		if ( taxonomy_exists( ATBDP_CATEGORY ) ) {
			$listing_categories = get_terms( array(
				'taxonomy'   => ATBDP_CATEGORY,
				'hide_empty' => false,
			) );
		}

		$listing_locations = array();
		if ( taxonomy_exists( ATBDP_LOCATION ) ) {
			$listing_locations = get_terms( array(
				'taxonomy'   => ATBDP_LOCATION,
				'hide_empty' => false,
			) );
		}

		$listing_tags = array();
		if ( taxonomy_exists( 'at_biz_dir-tags' ) ) {
			$listing_tags = get_terms( array(
				'taxonomy'   => 'at_biz_dir-tags',
				'hide_empty' => false,
			) );
		}

		$user_id = get_current_user_id();

		$listing_packages = class_exists( 'HBL_Pricing_Plans' )
			? \HBL_Pricing_Plans::get_plans( array( 'with_restrictions' => true ) )
			: array();
		$all_listings_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/all-listings/' );
		?>
		<div class="hbl-add-listing-form-widget">
			<?php 
			if ( isset( $_GET['debug'] ) && $_GET['debug'] === 'plans' && current_user_can( 'manage_options' ) ) :
			?>
			<div class="hbl-debug-plans" style="background: #1F2937; color: #F3F4F6; padding: 24px; border-radius: 3px; margin-bottom: 24px; font-family: monospace; font-size: 13px;">
				<h3 style="color: #F9532A; margin: 0 0 16px 0; font-size: 18px;">🔧 Pricing Plans Debug Info</h3>
				<p style="color: #9CA3AF; margin: 0 0 16px 0;">This debug info is only visible to administrators. Remove <code>?debug=plans</code> from URL to hide.</p>
				
				<?php foreach ( $listing_packages as $index => $plan ) : ?>
				<div style="background: #374151; padding: 16px; border-radius: 3px; margin-bottom: 12px; <?php echo $index === 0 ? 'border-left: 4px solid #F9532A;' : ''; ?>">
					<h4 style="color: #10B981; margin: 0 0 12px 0;">
						<?php echo esc_html( $plan['title'] ); ?> 
						<?php if ( $plan['is_free'] ) : ?>
							<span style="background: #059669; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">FREE</span>
						<?php else : ?>
							<span style="background: #F59E0B; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">$<?php echo number_format( $plan['price'], 2 ); ?></span>
						<?php endif; ?>
						<?php if ( $index === 0 ) : ?>
							<span style="background: #F9532A; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">DEFAULT</span>
						<?php endif; ?>
					</h4>
					<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
						<?php 
						$restrictions = $plan['restrictions'];
						$fields = array(
							'phone' => 'Phone (_phone)',
							'email' => 'Email (_email)',
							'website' => 'Website (_website)',
							'social_networks' => 'Social Networks (_social)',
							'gallery' => 'Gallery (_listing_img)',
							'video' => 'Video (_videourl)',
							'tags' => 'Tags Field (_tag)',
							'tagline' => 'Tagline (_tagline)',
							'category' => 'Category (_category)',
							'price_field' => 'Price Field (_pricing)',
							'address' => 'Address (_address)',
							'map' => 'Map (_map)',
							'location' => 'Location (_location)',
							'reviews' => 'Reviews (fm_cs_review)',
							'faqs' => 'FAQs (_faqs)',
							'business_hours' => 'Business Hours (_bdbh)',
						);
						foreach ( $fields as $key => $label ) :
							$value = isset( $restrictions[ $key ] ) ? $restrictions[ $key ] : false;
							$color = $value ? '#10B981' : '#EF4444';
							$icon = $value ? '✅' : '❌';
						?>
						<div style="color: <?php echo $color; ?>;">
							<?php echo $icon; ?> <?php echo esc_html( $label ); ?>
						</div>
						<?php endforeach; ?>
					</div>
					
					<details style="margin-top: 12px;">
						<summary style="color: #9CA3AF; cursor: pointer;">View Raw Meta Values</summary>
						<div style="background: #1F2937; padding: 12px; margin-top: 8px; border-radius: 3px; overflow-x: auto;">
							<table style="width: 100%; border-collapse: collapse; font-size: 12px;">
								<tr style="border-bottom: 1px solid #4B5563;">
									<th style="text-align: left; padding: 4px 8px; color: #9CA3AF;">Meta Key</th>
									<th style="text-align: left; padding: 4px 8px; color: #9CA3AF;">Raw Value</th>
									<th style="text-align: left; padding: 4px 8px; color: #9CA3AF;">Boolean</th>
								</tr>
								<?php 
								$meta_keys = array(
									'_phone', '_email', '_website', '_social',
									'_listing_img', '_max_listing_img', '_unlimited_listing_img', '_videourl',
									'_category', '_max_category', '_tag', '_max_tag', '_pricing',
									'_tagline', '_listing_content', '_address', '_map', '_location',
									'fm_cs_review', '_faqs', '_bdbh', 'cf_owner', 'is_featured_listing',
									'fm_price', 'free_plan', 'fm_description', 'default_pln'
								);
								foreach ( $meta_keys as $meta_key ) :
									$raw_value = get_post_meta( $plan['id'], $meta_key, true );
									$bool_value = (bool) $raw_value;
								?>
								<tr style="border-bottom: 1px solid #374151;">
									<td style="padding: 4px 8px; color: #D1D5DB;"><?php echo esc_html( $meta_key ); ?></td>
									<td style="padding: 4px 8px; color: #F59E0B;"><?php echo esc_html( var_export( $raw_value, true ) ); ?></td>
									<td style="padding: 4px 8px; color: <?php echo $bool_value ? '#10B981' : '#EF4444'; ?>;"><?php echo $bool_value ? 'true' : 'false'; ?></td>
								</tr>
								<?php endforeach; ?>
							</table>
						</div>
					</details>
				</div>
				<?php endforeach; ?>
				
				<div style="margin-top: 16px; padding: 12px; background: #374151; border-radius: 3px;">
					<strong style="color: #F59E0B;">⚠️ To enable features:</strong>
					<ol style="margin: 8px 0 0 20px; color: #D1D5DB;">
						<li>Go to <strong>WordPress Admin → Directory Listings → Pricing Plans</strong></li>
						<li>Edit each plan and enable the features you want</li>
						<li>Look for checkboxes in the "Submission Form Fields" or similar sections</li>
						<li>Save each plan after making changes</li>
					</ol>
				</div>
			</div>
			<?php endif; ?>
			
			<?php if ( 'yes' === $settings['show_title'] ) : ?>
				<div class="hbl-listing-form-header">
					<div class="hbl-listing-form-header-top">
						<a href="<?php echo esc_url( $all_listings_url ); ?>" class="hbl-form-btn hbl-form-btn-back">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Back to Listings', 'hbl' ); ?>
						</a>
					</div>
					<h2 class="hbl-add-listing-form-title">
						<?php 
						if ( $is_editing ) {
							esc_html_e( 'Edit Listing', 'hbl' );
						} else {
							echo esc_html( $settings['form_title'] );
						}
						?>
					</h2>
					<?php if ( ! empty( $settings['form_description'] ) && ! $is_editing ) : ?>
						<p class="hbl-add-listing-form-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
					<?php elseif ( $is_editing ) : ?>
						<p class="hbl-add-listing-form-description"><?php esc_html_e( 'Update your listing information below.', 'hbl' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form id="hbl-listing-form" class="hbl-listing-form" method="post">
				<?php wp_nonce_field( 'hbl_listing_nonce', 'listing_nonce' ); ?>
				<input type="hidden" name="action" value="hbl_save_listing">
				<input type="hidden" name="listing_id" value="<?php echo esc_attr( $editing_listing_id ); ?>">

				<?php if ( ! empty( $listing_packages ) ) : 
					$show_packages = 'yes' === $settings['enable_pricing_plans'];
					$package_section_class = 'hbl-form-section hbl-form-section-highlight';
					if ( ! $show_packages ) {
						$package_section_class .= ' hbl-section-not-launched';
					}
				?>
				<div class="<?php echo esc_attr( $package_section_class ); ?>">
					<?php if ( ! $show_packages ) : ?>
						<div class="hbl-not-launched-overlay">
							<div class="hbl-not-launched-content">
								<div class="hbl-rocket-icon">🚀</div>
								<h3><?php esc_html_e( 'Coming Soon', 'hbl' ); ?></h3>
								<p><?php esc_html_e( 'Our pricing plans are launching shortly. Stay tuned!', 'hbl' ); ?></p>
							</div>
						</div>
					<?php endif; ?>
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M21 16V8C20.9996 7.6493 20.9071 7.3048 20.7315 7.00017C20.556 6.69555 20.3037 6.44158 20 6.26L13 2.26C12.696 2.08805 12.3511 1.99804 12 1.99804C11.6489 1.99804 11.304 2.08805 11 2.26L4 6.26C3.69626 6.44158 3.44398 6.69555 3.26846 7.00017C3.09294 7.3048 3.00036 7.6493 3 8V16C3.00036 16.3507 3.09294 16.6952 3.26846 16.9998C3.44398 17.3045 3.69626 17.5584 4 17.74L11 21.74C11.304 21.912 11.6489 22.002 12 22.002C12.3511 22.002 12.696 21.912 13 21.74L20 17.74C20.3037 17.5584 20.556 17.3045 20.7315 16.9998C20.9071 16.6952 20.9996 16.3507 21 16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M3.27 6.96L12 12.01L20.73 6.96" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M12 22.08V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Choose Your Listing Package', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Select a package that best suits your business needs', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="listing_package" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 16V8C20.9996 7.6493 20.9071 7.3048 20.7315 7.00017C20.556 6.69555 20.3037 6.44158 20 6.26L13 2.26C12.696 2.08805 12.3511 1.99804 12 1.99804C11.6489 1.99804 11.304 2.08805 11 2.26L4 6.26C3.69626 6.44158 3.44398 6.69555 3.26846 7.00017C3.09294 7.3048 3.00036 7.6493 3 8V16C3.00036 16.3507 3.09294 16.6952 3.26846 16.9998C3.44398 17.3045 3.69626 17.5584 4 17.74L11 21.74C11.304 21.912 11.6489 22.002 12 22.002C12.3511 22.002 12.696 21.912 13 21.74L20 17.74C20.3037 17.5584 20.556 17.3045 20.7315 16.9998C20.9071 16.6952 20.9996 16.3507 21 16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Listing Package', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-package-options">
								<?php 
								$coming_soon_ids = array();
								if ( ! empty( $settings['coming_soon_plans'] ) ) {
									$coming_soon_ids = array_map( 'trim', explode( ',', $settings['coming_soon_plans'] ) );
								}
								
								$first_available_index = -1;
								foreach ( $listing_packages as $idx => $pkg ) {
									if ( ! in_array( (string) $pkg['id'], $coming_soon_ids ) ) {
										$first_available_index = $idx;
										break;
									}
								}

								foreach ( $listing_packages as $index => $package ) : ?>
									<?php 
									$is_coming_soon = in_array( (string) $package['id'], $coming_soon_ids );
									
									$is_selected = false;
									if ( ! $is_coming_soon ) {
										if ( $is_editing && ! empty( $listing_data['plan_id'] ) ) {
											$is_selected = ( (int) $package['id'] === (int) $listing_data['plan_id'] );
										} else {
											$is_selected = ( $index === $first_available_index );
										}
									}
									
									$option_classes = $package['recommended'] ? 'hbl-form-package-recommended' : '';
									if ( $is_coming_soon ) {
										$option_classes .= ' hbl-plan-coming-soon';
									}
									?>
									<label class="hbl-form-package-option <?php echo esc_attr( $option_classes ); ?>">
										<input type="radio" name="listing_package" value="<?php echo esc_attr( $package['id'] ); ?>" 
											data-restrictions="<?php echo esc_attr( wp_json_encode( $package['restrictions'] ) ); ?>"
											<?php echo $is_selected ? 'checked' : ''; ?> 
											<?php echo $is_coming_soon ? 'disabled onclick="return false;"' : ''; ?> required>
										<div class="hbl-form-package-card">
											<?php if ( $is_coming_soon ) : ?>
												<div class="hbl-plan-coming-soon-overlay">
													<div class="hbl-rocket-icon-small">🚀</div>
													<span class="hbl-plan-coming-soon-text"><?php esc_html_e( 'Coming Soon', 'hbl' ); ?></span>
												</div>
											<?php endif; ?>
											<?php if ( $package['recommended'] ) : ?>
												<span class="hbl-form-package-badge"><?php esc_html_e( 'Recommended', 'hbl' ); ?></span>
											<?php endif; ?>
											<div class="hbl-form-package-header">
												<span class="hbl-form-package-name"><?php echo esc_html( $package['title'] ); ?></span>
												<span class="hbl-form-package-price">
													<?php if ( $package['is_free'] || $package['price'] <= 0 ) : ?>
														<span class="hbl-price-free"><?php esc_html_e( 'Free', 'hbl' ); ?></span>
													<?php else : ?>
														<span class="hbl-price-currency">$</span><?php echo esc_html( number_format( $package['price_with_tax'], 2 ) ); ?>
														<?php if ( ! empty( $package['tax_amount'] ) ) : ?>
															<span style="display:block;font-size:10px;font-weight:400;color:#9ca3af;line-height:1.4;"><?php esc_html_e( 'inc. tax', 'hbl' ); ?></span>
														<?php endif; ?>
													<?php endif; ?>
												</span>
											</div>
											<?php if ( ! empty( $package['description'] ) ) : ?>
												<p class="hbl-form-package-desc"><?php echo esc_html( $package['description'] ); ?></p>
											<?php endif; ?>
											<div class="hbl-form-package-check">
												<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
													<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												</svg>
											</div>
										</div>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_business_info'] ) : ?>
				<div class="hbl-form-section" id="hbl-section-business">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 21V5C19 4.46957 18.7893 3.96086 18.4142 3.58579C18.0391 3.21071 17.5304 3 17 3H7C6.46957 3 5.96086 3.21071 5.58579 3.58579C5.21071 3.96086 5 4.46957 5 5V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M3 21H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M9 7H10M9 11H10M9 15H10M14 7H15M14 11H15M14 15H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Business Information', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Tell us about your business', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="listing_title" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M4 7V4H20V7M9 20H15M12 4V20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Business Name', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="text" id="listing_title" name="listing_title" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Enter your business name...', 'hbl' ); ?>" value="<?php echo esc_attr( $listing_data['title'] ?? '' ); ?>" required>
							</div>
						</div>

						<?php if ( ! empty( $listing_tags ) && ! is_wp_error( $listing_tags ) ) : ?>
						<div class="hbl-form-group" id="hbl-field-tags">
							<label for="listing_tagline" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M20.59 13.41L13.42 20.58C13.2343 20.766 13.0137 20.9135 12.7709 21.0141C12.5281 21.1148 12.2678 21.1666 12.005 21.1666C11.7422 21.1666 11.4819 21.1148 11.2391 21.0141C10.9963 20.9135 10.7757 20.766 10.59 20.58L2 12V2H12L20.59 10.59C20.9625 10.9647 21.1716 11.4716 21.1716 12C21.1716 12.5284 20.9625 13.0353 20.59 13.41Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M7 7H7.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Tags', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<div class="hbl-tags-container">
									<div class="hbl-tags-selected" id="hbl-tags-selected"></div>
									<select id="listing_tagline" class="hbl-form-select hbl-tag-select">
										<option value=""><?php esc_html_e( '— Select Tag —', 'hbl' ); ?></option>
										<?php foreach ( $listing_tags as $tag ) : ?>
											<option value="<?php echo esc_attr( $tag->name ); ?>" data-name="<?php echo esc_attr( $tag->name ); ?>">
												<?php echo esc_html( $tag->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
								<input type="hidden" name="listing_tagline" id="listing_tagline_hidden" value="<?php echo esc_attr( $listing_data['tagline'] ?? '' ); ?>">
							</div>
							<p class="hbl-form-help-text hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Select up to 3 tags to describe key features of your business.', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Select up to 3 tags to describe key features of your business.', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Select up to 10 tags to be found even more easily in filtered searches.', 'hbl' ); ?>"><?php esc_html_e( 'Select up to 3 tags to describe key features of your business.', 'hbl' ); ?></p>
							<div class="hbl-field-limit-badge" id="hbl-limit-tags"></div>
						</div>
						<?php endif; ?>

						<div class="hbl-form-group">
							<label for="listing_content" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M14 2V8H20M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Description', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<textarea id="listing_content" name="listing_content" class="hbl-form-textarea" rows="5" placeholder="<?php esc_attr_e( 'Describe what makes your business special...', 'hbl' ); ?>" required><?php echo esc_textarea( $listing_data['content'] ?? '' ); ?></textarea>
							</div>
							<p class="hbl-form-help-text hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Add a short description here (up to 50 words).', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Add a short description here (up to 50 words).', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Add a comprehensive description of up to 500 words for maximum visibility and detail.', 'hbl' ); ?>"><?php esc_html_e( 'Add a short description here (up to 50 words).', 'hbl' ); ?></p>
						</div>

						<?php if ( ! empty( $listing_categories ) && ! is_wp_error( $listing_categories ) ) : ?>
						<div class="hbl-form-group" id="hbl-field-category">
							<label for="listing_category" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Category', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<div class="hbl-categories-container">
									<div class="hbl-categories-selected" id="hbl-categories-selected"></div>
									<select id="listing_category" class="hbl-form-select hbl-category-select">
										<option value=""><?php esc_html_e( '— Select Category —', 'hbl' ); ?></option>
										<?php foreach ( $listing_categories as $category ) : ?>
											<option value="<?php echo esc_attr( $category->term_id ); ?>" data-name="<?php echo esc_attr( $category->name ); ?>">
												<?php echo esc_html( $category->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
								<input type="hidden" name="listing_category" id="listing_category_hidden" value="<?php echo esc_attr( $listing_data['category'] ?? ( ! empty( $listing_data['categories'] ) ? implode( ',', $listing_data['categories'] ) : '' ) ); ?>" required>
							</div>
							<p class="hbl-form-help-text hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Choose the category that best describes your business.', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Choose the category that best describes your business.', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Select up to 3 categories to maximise your visibility across the site.', 'hbl' ); ?>"><?php esc_html_e( 'Choose the category that best describes your business.', 'hbl' ); ?></p>
							<div class="hbl-field-limit-badge" id="hbl-limit-category"></div>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_pricing_info'] ) : ?>
				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<line x1="12" y1="1" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M17 5H9.5C8.57174 5 7.6815 5.36875 7.02513 6.02513C6.36875 6.6815 6 7.57174 6 8.5C6 9.42826 6.36875 10.3185 7.02513 10.9749C7.6815 11.6313 8.57174 12 9.5 12H14.5C15.4283 12 16.3185 12.3687 16.9749 13.0251C17.6313 13.6815 18 14.5717 18 15.5C18 16.4283 17.6313 17.3185 16.9749 17.9749C16.3185 18.6313 15.4283 19 14.5 19H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Pricing Information', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Set your pricing details', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Add Pricing Options', 'hbl' ); ?></span>
							</label>
							<div class="hbl-services-list" id="hbl-pricing-list">
								<?php
								$pricing_items = isset( $listing_data['pricing_list'] ) && ! empty( $listing_data['pricing_list'] ) 
									? array_filter( array_map( 'trim', explode( "\n", $listing_data['pricing_list'] ) ) ) 
									: array();
								
								if ( empty( $pricing_items ) ) {
									$pricing_items = array( '' );
								}

								foreach ( $pricing_items as $item ) :
								?>
								<div class="hbl-service-item">
									<div class="hbl-form-input-wrapper">
										<input type="text" name="listing_pricing[]" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Enter pricing option (e.g., Basic Wash - $25)', 'hbl' ); ?>" value="<?php echo esc_attr( $item ); ?>">
									</div>
									<button type="button" class="hbl-remove-pricing-btn" title="<?php esc_attr_e( 'Remove', 'hbl' ); ?>">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</button>
								</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="hbl-form-btn hbl-form-btn-secondary hbl-add-pricing-btn" id="hbl-add-pricing-btn">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php esc_html_e( 'Add Another Option', 'hbl' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_services'] ) : ?>
				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M14.7 6.3C14.5168 6.1059 14.2944 5.95161 14.0477 5.84669C13.8011 5.74177 13.5354 5.68857 13.267 5.6905C12.9987 5.69244 12.7339 5.74947 12.489 5.85795C12.244 5.96643 12.024 6.12393 11.844 6.32052L4.3 14.5C4.10536 14.7061 3.95093 14.9489 3.84576 15.2146C3.74058 15.4802 3.68666 15.764 3.68715 16.0505L3.68 18.62C3.67999 18.8943 3.73472 19.1659 3.84124 19.4189C3.94775 19.6719 4.10388 19.9013 4.30015 20.0935C4.49642 20.2858 4.72888 20.4371 4.98399 20.5383C5.2391 20.6395 5.51185 20.6886 5.78615 20.6827L8.35 20.62C8.93 20.608 9.48 20.37 9.88 19.96L17.42 11.78C17.808 11.3824 18.0255 10.8502 18.027 10.2955C18.0284 9.74088 17.8137 9.20747 17.428 8.808L14.7 6.3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M12 8L16 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M19 15V21M16 18H22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Services Offered', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'List the services your business provides', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M9 11L12 14L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M21 12V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Add Services', 'hbl' ); ?></span>
							</label>
							<div class="hbl-services-list" id="hbl-services-list">
								<?php if ( $is_editing && ! empty( $listing_data['services'] ) ) : ?>
									<?php foreach ( $listing_data['services'] as $service ) : ?>
										<?php if ( ! empty( trim( $service ) ) ) : ?>
										<div class="hbl-service-item">
											<div class="hbl-form-input-wrapper">
												<input type="text" name="listing_services[]" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Enter a service (e.g., Home Delivery)', 'hbl' ); ?>" value="<?php echo esc_attr( $service ); ?>">
											</div>
											<button type="button" class="hbl-remove-service-btn" title="<?php esc_attr_e( 'Remove', 'hbl' ); ?>">
												<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												</svg>
											</button>
										</div>
										<?php endif; ?>
									<?php endforeach; ?>
								<?php else : ?>
								<div class="hbl-service-item">
									<div class="hbl-form-input-wrapper">
										<input type="text" name="listing_services[]" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Enter a service (e.g., Home Delivery)', 'hbl' ); ?>">
									</div>
									<button type="button" class="hbl-remove-service-btn" title="<?php esc_attr_e( 'Remove', 'hbl' ); ?>">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</button>
								</div>
								<?php endif; ?>
							</div>
							<button type="button" class="hbl-form-btn hbl-form-btn-secondary hbl-add-service-btn" id="hbl-add-service-btn">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php esc_html_e( 'Add Another Service', 'hbl' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_contact_info'] ) : ?>
				<div class="hbl-form-section" id="hbl-section-contact">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1468 21.5902 20.9046 21.7335 20.6407 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77382 17.3147 6.72533 15.2662 5.18999 12.85C3.49997 10.2412 2.44824 7.27099 2.11999 4.18001C2.095 3.90347 2.12787 3.62477 2.21649 3.36163C2.30512 3.09849 2.44756 2.85669 2.63476 2.65163C2.82196 2.44656 3.0498 2.28271 3.30379 2.17053C3.55777 2.05834 3.83233 2.00027 4.10999 2.00001H7.10999C7.5953 1.99523 8.06579 2.16708 8.43376 2.48354C8.80173 2.79999 9.04207 3.23945 9.10999 3.72001C9.23662 4.68007 9.47144 5.62273 9.80999 6.53001C9.94454 6.88793 9.97366 7.27692 9.8939 7.65089C9.81415 8.02485 9.62886 8.36812 9.35999 8.64001L8.08999 9.91001C9.51355 12.4136 11.5864 14.4865 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9751 14.1859 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Contact Information', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'How can customers reach you?', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-row">
							<div class="hbl-form-group hbl-form-group-half">
								<label for="listing_phone" class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1468 21.5902 20.9046 21.7335 20.6407 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77382 17.3147 6.72533 15.2662 5.18999 12.85C3.49997 10.2412 2.44824 7.27099 2.11999 4.18001C2.095 3.90347 2.12787 3.62477 2.21649 3.36163C2.30512 3.09849 2.44756 2.85669 2.63476 2.65163C2.82196 2.44656 3.0498 2.28271 3.30379 2.17053C3.55777 2.05834 3.83233 2.00027 4.10999 2.00001H7.10999C7.5953 1.99523 8.06579 2.16708 8.43376 2.48354C8.80173 2.79999 9.04207 3.23945 9.10999 3.72001C9.23662 4.68007 9.47144 5.62273 9.80999 6.53001C9.94454 6.88793 9.97366 7.27692 9.8939 7.65089C9.81415 8.02485 9.62886 8.36812 9.35999 8.64001L8.08999 9.91001C9.51355 12.4136 11.5864 14.4865 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9751 14.1859 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Phone Number', 'hbl' ); ?></span>
								</label>
								<div class="hbl-form-input-wrapper">
									<input type="tel" id="listing_phone" name="listing_phone" class="hbl-form-input" placeholder="<?php esc_attr_e( '04XX XXX XXX', 'hbl' ); ?>" value="<?php echo esc_attr( $listing_data['phone'] ?? '' ); ?>">
								</div>
								<p class="hbl-form-help-text hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Your phone number allows customers to call you directly once they view your full listing.', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Your phone number allows customers to call you directly once they view your full listing.', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Provide all available contact options to make it easy for customers to find and reach you.', 'hbl' ); ?>"><?php esc_html_e( 'Your phone number allows customers to call you directly once they view your full listing.', 'hbl' ); ?></p>
							</div>
							<div class="hbl-form-group hbl-form-group-half">
								<label for="listing_email" class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Email Address', 'hbl' ); ?></span>
								</label>
								<div class="hbl-form-input-wrapper">
									<input type="email" id="listing_email" name="listing_email" class="hbl-form-input" placeholder="<?php esc_attr_e( 'hello@yourbusiness.com', 'hbl' ); ?>" value="<?php echo esc_attr( $listing_data['email'] ?? '' ); ?>">
								</div>
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_website" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M2 12H22M12 2C14.5013 4.73835 15.9228 8.29203 16 12C15.9228 15.708 14.5013 19.2616 12 22C9.49872 19.2616 8.07725 15.708 8 12C8.07725 8.29203 9.49872 4.73835 12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Website', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_website" name="listing_website" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://www.yourbusiness.com', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['website'] ?? '' ); ?>">
							</div>
							<p class="hbl-form-help-text hbl-plan-helper hbl-plan-helper-gold-only" data-plan-bronze="" data-plan-silver="" data-plan-gold="<?php esc_attr_e( 'Add your website, email, socials and directions to make it easy for customers to find and visit you.', 'hbl' ); ?>"></p>
						</div>

						<div class="hbl-form-group">
							<label for="listing_address" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Business Address', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="text" id="listing_address" name="listing_address" class="hbl-form-input" placeholder="<?php esc_attr_e( '123 Main Street, Hervey Bay QLD 4655', 'hbl' ); ?>" value="<?php echo esc_attr( $listing_data['address'] ?? '' ); ?>">
							</div>
							<p class="hbl-form-help-text hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Add your business address so customers know where you\'re located.', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Add your business address so customers know where you\'re located.', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Add your business address so customers can find you and get directions.', 'hbl' ); ?>"><?php esc_html_e( 'Add your business address so customers know where you\'re located.', 'hbl' ); ?></p>
						</div>

						<?php if ( ! empty( $listing_locations ) && ! is_wp_error( $listing_locations ) ) : ?>
						<div class="hbl-form-group">
							<label for="listing_location" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Location/Suburb', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<select id="listing_location" name="listing_location" class="hbl-form-select">
									<option value=""><?php esc_html_e( '— Select Location —', 'hbl' ); ?></option>
									<?php foreach ( $listing_locations as $location ) : ?>
										<option value="<?php echo esc_attr( $location->term_id ); ?>" <?php selected( $listing_data['location'] ?? 0, $location->term_id ); ?>>
											<?php echo esc_html( $location->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_map'] ) : ?>
				<div class="hbl-form-section" id="hbl-section-map">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<line x1="8" y1="2" x2="8" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<line x1="16" y1="6" x2="16" y2="22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Map Location', 'hbl' ); ?></h3>
							<p class="hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Your location will appear on your business page.', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Your location will appear on your business page.', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Your listing will display a larger, more prominent map for stronger location emphasis.', 'hbl' ); ?>"><?php esc_html_e( 'Your location will appear on your business page.', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="listing_map_address" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Search Address', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper hbl-form-search-wrapper">
								<input type="text" id="listing_map_address" name="listing_map_address" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Enter address to locate on map...', 'hbl' ); ?>">
								<button type="button" id="hbl-search-address-btn" class="hbl-form-search-btn">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</button>
							</div>
							<p class="hbl-form-help-text"><?php esc_html_e( 'Type an address and press Enter or click search to locate it on the map', 'hbl' ); ?></p>
						</div>

						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Pin Your Location', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-map-wrapper">
								<div id="hbl-listing-map" class="hbl-form-map"></div>
								<p class="hbl-form-help-text"><?php esc_html_e( 'Drag the marker to set your exact business location, or enter coordinates below.', 'hbl' ); ?></p>
							</div>
						</div>

						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<input type="checkbox" id="manual_coordinate" name="manual_coordinate" value="1" class="hbl-form-checkbox-inline">
								<span><?php esc_html_e( 'Enter Coordinates Manually', 'hbl' ); ?></span>
							</label>
						</div>
						
						<div class="hbl-form-coordinates-wrapper" style="display: none;">
							<div class="hbl-form-row">
								<div class="hbl-form-group hbl-form-group-half">
									<label for="listing_lat" class="hbl-form-label">
										<span><?php esc_html_e( 'Latitude', 'hbl' ); ?></span>
									</label>
									<div class="hbl-form-input-wrapper">
										<input type="text" id="listing_lat" name="listing_lat" class="hbl-form-input" placeholder="<?php esc_attr_e( 'e.g., -25.2985784', 'hbl' ); ?>">
									</div>
								</div>
								<div class="hbl-form-group hbl-form-group-half">
									<label for="listing_lng" class="hbl-form-label">
										<span><?php esc_html_e( 'Longitude', 'hbl' ); ?></span>
									</label>
									<div class="hbl-form-input-wrapper">
										<input type="text" id="listing_lng" name="listing_lng" class="hbl-form-input" placeholder="<?php esc_attr_e( 'e.g., 152.8535216', 'hbl' ); ?>">
									</div>
								</div>
							</div>
							<button type="button" class="hbl-form-btn hbl-form-btn-secondary" id="hbl-generate-map">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php esc_html_e( 'Generate on Map', 'hbl' ); ?>
							</button>
						</div>

						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<input type="checkbox" id="hide_map" name="hide_map" value="1" class="hbl-form-checkbox-inline">
								<span><?php esc_html_e( 'Hide map on listing page', 'hbl' ); ?></span>
							</label>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_social'] ) : ?>
				<div class="hbl-form-section" id="hbl-section-social">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Social Media', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Connect your social media profiles', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="listing_facebook" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Facebook', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_facebook" name="listing_facebook" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://facebook.com/yourbusiness', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['facebook'] ?? '' ); ?>">
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_instagram" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M16 11.37C16.1234 12.2022 15.9813 13.0522 15.5938 13.799C15.2063 14.5458 14.5931 15.1514 13.8416 15.5297C13.0901 15.9079 12.2384 16.0396 11.4078 15.9059C10.5771 15.7723 9.80976 15.3801 9.21484 14.7852C8.61991 14.1902 8.22773 13.4229 8.09406 12.5922C7.9604 11.7615 8.09206 10.9099 8.47032 10.1584C8.84858 9.40685 9.45418 8.79374 10.201 8.40624C10.9478 8.01874 11.7978 7.87659 12.63 8C13.4789 8.12588 14.2649 8.52146 14.8717 9.12831C15.4785 9.73515 15.8741 10.5211 16 11.37Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Instagram', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_instagram" name="listing_instagram" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://instagram.com/yourbusiness', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['instagram'] ?? '' ); ?>">
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_twitter" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M4 4L10.5 12.5M20 20L13.5 11.5M10.5 12.5L4 20H8L13.5 11.5M10.5 12.5L16 4H20L13.5 11.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Twitter / X', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_twitter" name="listing_twitter" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://twitter.com/yourbusiness', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['twitter'] ?? '' ); ?>">
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_linkedin" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M16 8C17.5913 8 19.1174 8.63214 20.2426 9.75736C21.3679 10.8826 22 12.4087 22 14V21H18V14C18 13.4696 17.7893 12.9609 17.4142 12.5858C17.0391 12.2107 16.5304 12 16 12C15.4696 12 14.9609 12.2107 14.5858 12.5858C14.2107 12.9609 14 13.4696 14 14V21H10V14C10 12.4087 10.6321 10.8826 11.7574 9.75736C12.8826 8.63214 14.4087 8 16 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<rect x="2" y="9" width="4" height="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="4" cy="4" r="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'LinkedIn', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_linkedin" name="listing_linkedin" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://linkedin.com/company/yourbusiness', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['linkedin'] ?? '' ); ?>">
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_youtube" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22.54 6.42C22.4212 5.94541 22.1793 5.51057 21.8387 5.15941C21.498 4.80824 21.0708 4.55318 20.6 4.42C18.88 4 12 4 12 4C12 4 5.12 4 3.4 4.46C2.92925 4.59318 2.50198 4.84824 2.16135 5.19941C1.82072 5.55057 1.57879 5.98541 1.46 6.46C1.14521 8.20556 0.991235 9.97631 1 11.75C0.988687 13.537 1.14266 15.3213 1.46 17.08C1.59096 17.5398 1.83831 17.9581 2.17814 18.2945C2.51798 18.6308 2.93882 18.8738 3.4 19C5.12 19.46 12 19.46 12 19.46C12 19.46 18.88 19.46 20.6 19C21.0708 18.8668 21.498 18.6118 21.8387 18.2606C22.1793 17.9094 22.4212 17.4746 22.54 17C22.8524 15.2676 23.0063 13.5103 23 11.75C23.0113 9.96295 22.8573 8.1787 22.54 6.42Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'YouTube', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_youtube" name="listing_youtube" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://youtube.com/@yourbusiness', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['youtube'] ?? '' ); ?>">
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_tiktok" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M9 12C9 13.6569 7.65685 15 6 15C4.34315 15 3 13.6569 3 12C3 10.3431 4.34315 9 6 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M15 3V12C15 15.866 11.866 19 8 19C4.13401 19 1 15.866 1 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M15 3C15 5.20914 16.7909 7 19 7V3H15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'TikTok', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_tiktok" name="listing_tiktok" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://tiktok.com/@yourbusiness', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['tiktok'] ?? '' ); ?>">
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_media'] ) : ?>
				<div class="hbl-form-section" id="hbl-section-media">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3 class="hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Images / Logo', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Images / Logo', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Images, Logo & Media', 'hbl' ); ?>"><?php esc_html_e( 'Images / Logo', 'hbl' ); ?></h3>
							<p class="hbl-plan-helper" data-plan-bronze="<?php esc_attr_e( 'Upload one image or logo to represent your business.', 'hbl' ); ?>" data-plan-silver="<?php esc_attr_e( 'Upload one image or logo to represent your business.', 'hbl' ); ?>" data-plan-gold="<?php esc_attr_e( 'Upload up to 12 images including your logo. When Gold launches, your premium listing can also include a featured video.', 'hbl' ); ?>"><?php esc_html_e( 'Upload one image or logo to represent your business.', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Business Logo / Featured Image', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-image-upload">
								<input type="hidden" id="listing_image" name="listing_image" value="<?php echo $is_editing && ! empty( $listing_data['thumbnail_id'] ) ? esc_attr( $listing_data['thumbnail_id'] ) : ''; ?>">
								<div class="hbl-form-image-preview" id="hbl-listing-image-preview">
									<?php if ( $is_editing && ! empty( $listing_data['thumbnail_id'] ) ) : 
										$logo_url = wp_get_attachment_image_url( $listing_data['thumbnail_id'], 'medium' );
										if ( $logo_url ) :
									?>
										<img src="<?php echo esc_url( $logo_url ); ?>" alt="Business Image" style="max-width: 100%; height: auto; border-radius: 12px;">
									<?php else : ?>
									<div class="hbl-form-image-placeholder">
										<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<p><?php esc_html_e( 'Upload your business logo or a featured image', 'hbl' ); ?></p>
									</div>
									<?php endif; ?>
									<?php else : ?>
										<div class="hbl-form-image-placeholder">
											<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<p><?php esc_html_e( 'Upload your business logo or a featured image', 'hbl' ); ?></p>
										</div>
									<?php endif; ?>
								</div>
								<div class="hbl-form-image-buttons">
									<button type="button" class="hbl-form-btn hbl-form-btn-secondary hbl-upload-listing-image">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M17 8L12 3L7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M12 3V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<?php esc_html_e( 'Upload Image', 'hbl' ); ?>
									</button>
									<button type="button" class="hbl-form-btn hbl-form-btn-danger hbl-remove-listing-image" style="<?php echo ( $is_editing && ! empty( $listing_data['thumbnail_id'] ) ) ? '' : 'display: none;'; ?>">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<?php esc_html_e( 'Remove', 'hbl' ); ?>
									</button>
								</div>
							</div>
						</div>

						<div class="hbl-form-group" id="hbl-field-gallery">
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Gallery Images', 'hbl' ); ?></span>
							</label>
							<p class="hbl-form-help-text" id="hbl-gallery-help-text"><?php esc_html_e( 'Upload multiple images to showcase your business', 'hbl' ); ?></p>
							<div class="hbl-field-limit-badge" id="hbl-limit-gallery"></div>
							<div class="hbl-form-gallery-upload">
								<?php 
								$gallery_value = '';
								if ( $is_editing && ! empty( $listing_data['gallery'] ) ) {
									$gallery_ids_raw = $listing_data['gallery'];
									if ( is_array( $gallery_ids_raw ) ) {
										$gallery_value = implode( ',', array_filter( array_map( 'absint', $gallery_ids_raw ) ) );
									} else {
										$gallery_value = esc_attr( $gallery_ids_raw );
									}
								}
								?>
								<input type="hidden" id="listing_gallery" name="listing_gallery" value="<?php echo esc_attr( $gallery_value ); ?>">
								<div class="hbl-form-gallery-grid" id="hbl-listing-gallery-preview">
									<?php if ( $is_editing && ! empty( $listing_data['gallery'] ) ) : 
										$gallery_ids = $listing_data['gallery'];
										$gallery_array = is_array( $gallery_ids ) ? $gallery_ids : explode( ',', $gallery_ids );
										$gallery_array = array_filter( array_map( 'absint', $gallery_array ) );
										foreach ( $gallery_array as $img_id ) :
											$img_url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
											if ( $img_url ) :
									?>
										<div class="hbl-form-gallery-item" data-id="<?php echo esc_attr( $img_id ); ?>">
											<img src="<?php echo esc_url( $img_url ); ?>" alt="Gallery Image">
											<button type="button" class="hbl-form-gallery-item-remove" title="<?php esc_attr_e( 'Remove', 'hbl' ); ?>">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												</svg>
											</button>
										</div>
									<?php 
											endif;
										endforeach;
									endif; 
									?>
								</div>
								<div class="hbl-form-gallery-add" id="hbl-gallery-add-btn">
									<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Add Images', 'hbl' ); ?></span>
								</div>
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="listing_video" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<polygon points="23 7 16 12 23 17 23 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<rect x="1" y="5" width="15" height="14" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Video URL', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="listing_video" name="listing_video" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://youtube.com/watch?v=... or https://vimeo.com/...', 'hbl' ); ?>" value="<?php echo esc_url( $listing_data['video'] ?? '' ); ?>">
							</div>
							<p class="hbl-form-help-text"><?php esc_html_e( 'Add a YouTube or Vimeo video URL to showcase your business', 'hbl' ); ?></p>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="hbl-form-section hbl-form-section-info hbl-gold-only-section" id="hbl-section-promotions" style="display: none;">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Promotions & Invitations', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Priority inclusion in spotlight features, promotions and future premium opportunities.', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-info-box hbl-info-box-premium">
							<div class="hbl-info-icon hbl-info-icon-premium">
								<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M12 16V12M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</div>
							<p class="hbl-info-text"><?php esc_html_e( 'As a Gold member, you\'ll receive priority inclusion in spotlight features, promotions and future premium opportunities.', 'hbl' ); ?></p>
						</div>
					</div>
				</div>

				<?php if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) ) : ?>
				<div class="hbl-recaptcha-wrapper" style="margin:0 0 16px;">
					<?php if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) : ?>
						<div style="border:2px dashed #ccc;padding:12px 16px;text-align:center;color:#888;font-size:13px;border-radius:4px;background:#f9f9f9;">reCAPTCHA (renders on frontend)</div>
					<?php else : ?>
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( get_option( 'elementor_pro_recaptcha_site_key' ) ); ?>"></div>
					<?php endif; ?>
					<span class="hbl-recaptcha-error" style="display:none;color:#dc3545;font-size:13px;margin-top:4px;"><?php esc_html_e( 'Please complete the CAPTCHA.', 'hbl' ); ?></span>
				</div>
				<?php endif; ?>

				<div class="hbl-form-actions">
					<?php if ( $is_editing ) : ?>
						<a href="<?php echo esc_url( $all_listings_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-large">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<span><?php esc_html_e( 'Cancel', 'hbl' ); ?></span>
						</a>
					<?php endif; ?>
					<button type="submit" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large <?php echo $is_editing ? '' : 'hbl-form-btn-block'; ?>" id="hbl-listing-submit-btn">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo $is_editing ? esc_html__( 'Update Listing', 'hbl' ) : esc_html__( 'Submit Listing', 'hbl' ); ?></span>
					</button>
				</div>
			</form>
		</div>

		<?php if ( ! empty( $listing_packages ) ) : ?>
		<script>
		(function($) {
			'use strict';
			
			$(document).ready(function() {
				var $form = $('#hbl-listing-form');
				var $packageInputs = $form.find('input[name="listing_package"]');
				
				var currentRestrictions = {};
				
				var selectedTags = [];
				var selectedCategories = [];
				
				var fieldMap = {
					'phone': '#listing_phone',
					'email': '#listing_email',
					'website': '#listing_website',
					'gallery': '#hbl-gallery-add-btn',
					'video': '#listing_video',
					'address': '#listing_address',
					'location': '#listing_location',
				};
				
				var sectionMap = {
					'social_networks': '#hbl-section-social',
					'map': '#hbl-section-map',
				};
				
				function createLimitBadge(type, current, max, unlimited, allowed) {
					if (!allowed) {
						return '<span class="hbl-limit-badge hbl-limit-disabled">' +
							'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
							'<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> ' +
							'<?php echo esc_js( __( 'Upgrade plan to enable', 'hbl' ) ); ?></span>';
					}
					
					if (unlimited) {
						return '<span class="hbl-limit-badge hbl-limit-enabled hbl-limit-unlimited">' +
							'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
							'<polyline points="20,6 9,17 4,12"></polyline></svg> ' +
							'<?php echo esc_js( __( 'Unlimited', 'hbl' ) ); ?></span>';
					}
					
					var remaining = max - current;
					var statusClass = remaining <= 0 ? 'hbl-limit-exceeded' : (remaining <= 2 ? 'hbl-limit-warning' : 'hbl-limit-enabled');
					
					return '<span class="hbl-limit-badge ' + statusClass + '">' +
						'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
						'<polyline points="20,6 9,17 4,12"></polyline></svg> ' +
						current + '/' + max + ' ' + type + '</span>';
				}
				
				function updateFieldLimits(restrictions) {
					var tagsMax = restrictions.unlimited_tags ? 999 : (parseInt(restrictions.max_tags) || 1);
					var tagsBadge = createLimitBadge(
						'<?php echo esc_js( __( 'tags', 'hbl' ) ); ?>',
						selectedTags.length,
						tagsMax,
						restrictions.unlimited_tags,
						restrictions.tags
					);
					$('#hbl-limit-tags').html(tagsBadge);
					
					var catsMax = restrictions.unlimited_categories ? 999 : (parseInt(restrictions.max_categories) || 1);
					var catsBadge = createLimitBadge(
						'<?php echo esc_js( __( 'categories', 'hbl' ) ); ?>',
						selectedCategories.length,
						catsMax,
						restrictions.unlimited_categories,
						restrictions.category
					);
					$('#hbl-limit-category').html(catsBadge);
					
					var galleryCount = $('#hbl-listing-gallery-preview .hbl-form-gallery-item').length;
					var imagesMax = restrictions.unlimited_images ? 999 : (parseInt(restrictions.max_images) || 10);
					var galleryBadge = createLimitBadge(
						'<?php echo esc_js( __( 'images', 'hbl' ) ); ?>',
						galleryCount,
						imagesMax,
						restrictions.unlimited_images,
						restrictions.gallery
					);
					$('#hbl-limit-gallery').html(galleryBadge);
				}
				
				function applyPlanRestrictions(restrictions) {
					if (!restrictions) return;
					
					currentRestrictions = restrictions;
					console.log('Applying restrictions:', restrictions);
					
					updateFieldLimits(restrictions);
					
					$form.find('.hbl-form-section').removeClass('hbl-section-restricted').show();
					$form.find('.hbl-form-group').removeClass('hbl-field-restricted');
					$form.find('.hbl-upgrade-notice').remove();
					$form.find('input, textarea, select').prop('disabled', false);
					
					$.each(fieldMap, function(key, selector) {
						var isAllowed = restrictions[key];
						var $fields = $(selector);
						
						if (!isAllowed && $fields.length) {
							$fields.each(function() {
								var $field = $(this);
								var $group = $field.closest('.hbl-form-group');
								
								if ($field.is('input, textarea, select')) {
									$field.prop('disabled', true);
								}
								$group.addClass('hbl-field-restricted');
								
								if (!$group.find('.hbl-upgrade-notice').length) {
									$group.append('<p class="hbl-upgrade-notice"><?php echo esc_js( __( 'Upgrade your plan to enable this feature', 'hbl' ) ); ?></p>');
								}
							});
						}
					});
					
					$.each(sectionMap, function(key, selector) {
						var isAllowed = restrictions[key];
						var $section = $(selector);
						
						if (!isAllowed && $section.length) {
							$section.addClass('hbl-section-restricted');
							$section.find('input, textarea, select').prop('disabled', true);
						}
					});
					
					if (!restrictions.tags) {
						$('#hbl-field-tags').addClass('hbl-field-restricted');
						$('#listing_tagline').prop('disabled', true);
					}
					
					if (!restrictions.category) {
						$('#hbl-field-category').addClass('hbl-field-restricted');
						$('#listing_category').prop('disabled', true);
					}
					
					if (!restrictions.gallery) {
						$('#hbl-field-gallery').addClass('hbl-field-restricted');
						$('#hbl-gallery-add-btn').addClass('hbl-btn-disabled');
					} else {
						$('#hbl-gallery-add-btn').removeClass('hbl-btn-disabled');
					}
					
					$form.data('max-images', restrictions.unlimited_images ? 0 : (parseInt(restrictions.max_images) || 10));
					$form.data('max-tags', restrictions.unlimited_tags ? 0 : (parseInt(restrictions.max_tags) || 1));
					$form.data('max-categories', restrictions.unlimited_categories ? 0 : (parseInt(restrictions.max_categories) || 1));
					
					if (restrictions.map) {
						setTimeout(function() {
							$(window).trigger('hbl-reinit-map');
						}, 300);
					}
				}
				
				function updatePlanHelperText(planType) {
					var dataKey = 'data-plan-' + planType;
					
					$('.hbl-plan-helper').each(function() {
						var $elem = $(this);
						var newText = $elem.attr(dataKey);
						
						if (newText && newText.trim() !== '') {
							$elem.text(newText).show();
						} else {
							$elem.hide();
						}
					});
					
					$('#hbl-reviews-info-box .hbl-info-box-bronze').hide();
					$('#hbl-reviews-info-box .hbl-info-box-silver').hide();
					$('#hbl-reviews-info-box .hbl-info-box-gold').hide();
					$('#hbl-reviews-info-box .hbl-info-box-' + planType).show();
					
					if (planType === 'gold') {
						$('.hbl-gold-only-section').show();
					} else {
						$('.hbl-gold-only-section').hide();
					}
					
					$('#hbl-section-reviews').removeClass('hbl-section-bronze hbl-section-silver hbl-section-gold')
						.addClass('hbl-section-' + planType);
				}
				
				function getPlanType($checkedPackage) {
					var planTitle = $checkedPackage.closest('.hbl-form-package-option')
						.find('.hbl-form-package-name').text().toLowerCase().trim();
					
					if (planTitle.indexOf('gold') !== -1 || planTitle.indexOf('premium') !== -1 || planTitle.indexOf('listing') !== -1) {
						return 'gold';
					} else if (planTitle.indexOf('silver') !== -1) {
						return 'silver';
					}
					return 'bronze';
				}
				
				var $tagSelect = $('#listing_tagline');
				var $tagsHidden = $('#listing_tagline_hidden');
				var $tagsSelected = $('#hbl-tags-selected');
				
				function addTag(tagName) {
					tagName = $.trim(tagName);
					if (!tagName) return false;
					
					if (selectedTags.indexOf(tagName) !== -1) return false;
					
					var maxTags = $form.data('max-tags');
					if (maxTags > 0 && selectedTags.length >= maxTags) {
						alert('<?php echo esc_js( __( 'You have reached the maximum number of tags allowed for your plan.', 'hbl' ) ); ?>');
						return false;
					}
					
					selectedTags.push(tagName);
					
					var $tag = $('<span class="hbl-tag-item" data-tag="' + $('<div>').text(tagName).html() + '">' +
						'<span class="hbl-tag-text">' + $('<div>').text(tagName).html() + '</span>' +
						'<button type="button" class="hbl-tag-remove" data-tag="' + $('<div>').text(tagName).html() + '">&times;</button>' +
						'</span>');
					$tagsSelected.append($tag);
					
					$tagSelect.find('option[value="' + tagName + '"]').hide();
					
					updateTagsHidden();
					updateFieldLimits(currentRestrictions);
					return true;
				}
				
				function removeTag(tagName) {
					var index = selectedTags.indexOf(tagName);
					if (index > -1) {
						selectedTags.splice(index, 1);
					}
					$tagsSelected.find('.hbl-tag-item[data-tag="' + tagName + '"]').remove();
					$tagSelect.find('option[value="' + tagName + '"]').show();
					updateTagsHidden();
					updateFieldLimits(currentRestrictions);
				}
				
				function updateTagsHidden() {
					$tagsHidden.val(selectedTags.join(','));
				}
				
				$tagSelect.on('change', function() {
					var $selected = $(this).find('option:selected');
					if ($selected.val()) {
						addTag($selected.val());
						$(this).val('');
					}
				});
				
				$tagsSelected.on('click', '.hbl-tag-remove', function() {
					removeTag($(this).data('tag'));
				});
				
				var initialTags = $tagsHidden.val();
				if (initialTags) {
					initialTags.split(',').forEach(function(tag) {
						if (tag.trim()) {
							selectedTags.push(tag.trim());
							var $tag = $('<span class="hbl-tag-item" data-tag="' + $('<div>').text(tag.trim()).html() + '">' +
								'<span class="hbl-tag-text">' + $('<div>').text(tag.trim()).html() + '</span>' +
								'<button type="button" class="hbl-tag-remove" data-tag="' + $('<div>').text(tag.trim()).html() + '">&times;</button>' +
								'</span>');
							$tagsSelected.append($tag);
							$tagSelect.find('option[value="' + tag.trim() + '"]').hide();
						}
					});
				}
				
				var $categorySelect = $('#listing_category');
				var $categoryHidden = $('#listing_category_hidden');
				var $categoriesSelected = $('#hbl-categories-selected');
				
				function addCategory(catId, catName) {
					if (!catId) return false;
					
					for (var i = 0; i < selectedCategories.length; i++) {
						if (selectedCategories[i].id == catId) return false;
					}
					
					var maxCats = $form.data('max-categories');
					if (maxCats > 0 && selectedCategories.length >= maxCats) {
						alert('<?php echo esc_js( __( 'You have reached the maximum number of categories allowed for your plan.', 'hbl' ) ); ?>');
						return false;
					}
					
					selectedCategories.push({ id: catId, name: catName });
					
					var $cat = $('<span class="hbl-category-item" data-id="' + catId + '">' +
						'<span class="hbl-category-text">' + $('<div>').text(catName).html() + '</span>' +
						'<button type="button" class="hbl-category-remove" data-id="' + catId + '">&times;</button>' +
						'</span>');
					$categoriesSelected.append($cat);
					
					$categorySelect.find('option[value="' + catId + '"]').hide();
					
					updateCategoriesHidden();
					updateFieldLimits(currentRestrictions);
					return true;
				}
				
				function removeCategory(catId) {
					selectedCategories = selectedCategories.filter(function(cat) {
						return cat.id != catId;
					});
					$categoriesSelected.find('.hbl-category-item[data-id="' + catId + '"]').remove();
					$categorySelect.find('option[value="' + catId + '"]').show();
					updateCategoriesHidden();
					updateFieldLimits(currentRestrictions);
				}
				
				function updateCategoriesHidden() {
					var ids = selectedCategories.map(function(cat) { return cat.id; });
					$categoryHidden.val(ids.join(','));
				}
				
				$categorySelect.on('change', function() {
					var $selected = $(this).find('option:selected');
					if ($selected.val()) {
						addCategory($selected.val(), $selected.data('name') || $selected.text());
						$(this).val('');
					}
				});
				
				$categoriesSelected.on('click', '.hbl-category-remove', function() {
					removeCategory($(this).data('id'));
				});
				
				var initialCategory = $categoryHidden.val();
				if (initialCategory) {
					initialCategory.split(',').forEach(function(catId) {
						if (catId) {
							var $option = $categorySelect.find('option[value="' + catId + '"]');
							if ($option.length) {
								selectedCategories.push({ id: catId, name: $option.text() });
								var $cat = $('<span class="hbl-category-item" data-id="' + catId + '">' +
									'<span class="hbl-category-text">' + $('<div>').text($option.text()).html() + '</span>' +
									'<button type="button" class="hbl-category-remove" data-id="' + catId + '">&times;</button>' +
									'</span>');
								$categoriesSelected.append($cat);
								$option.hide();
							}
						}
					});
				}
				
				$(document).on('hbl-gallery-updated', function() {
					updateFieldLimits(currentRestrictions);
				});
				
				$packageInputs.on('change', function() {
					var $this = $(this);
					var restrictions = $this.data('restrictions');
					if (restrictions) {
						applyPlanRestrictions(restrictions);
					}
					
					var planType = getPlanType($this);
					updatePlanHelperText(planType);
				});

				$form.on('click', '.hbl-plan-coming-soon', function(e) {
					e.preventDefault();
					e.stopPropagation();
					return false;
				});
				
				setTimeout(function() {
					var $checkedPackage = $packageInputs.filter(':checked');
					if ($checkedPackage.length) {
						var restrictions = $checkedPackage.data('restrictions');
						if (restrictions) {
							applyPlanRestrictions(restrictions);
						}
						
						var planType = getPlanType($checkedPackage);
						updatePlanHelperText(planType);
					}
				}, 100);
				
				<?php if ( $is_editing && ! empty( $listing_data['thumbnail_id'] ) ) : ?>
				var thumbnailId = <?php echo absint( $listing_data['thumbnail_id'] ); ?>;
				var thumbnailUrl = '<?php echo esc_js( wp_get_attachment_image_url( $listing_data['thumbnail_id'], 'medium' ) ); ?>';
				if (thumbnailId && thumbnailUrl) {
					var $imagePreview = $('#hbl-listing-image-preview');
					var $hiddenInput = $('#listing_image');
					var $removeButton = $form.find('.hbl-remove-listing-image');
					
					$imagePreview.html('<img src="' + thumbnailUrl + '" alt="Business Image" style="max-width: 100%; height: auto; border-radius: 12px;">');
					$hiddenInput.val(thumbnailId);
					$removeButton.show();
				}
				<?php endif; ?>
				
				<?php if ( $is_editing && ! empty( $listing_data['gallery'] ) ) : 
					$gallery_for_js = $listing_data['gallery'];
					if ( is_array( $gallery_for_js ) ) {
						$gallery_for_js = implode( ',', array_filter( array_map( 'absint', $gallery_for_js ) ) );
					}
				?>
				var existingGalleryIds = '<?php echo esc_js( $gallery_for_js ); ?>';
				if (existingGalleryIds) {
					var galleryArray = existingGalleryIds.split(',').map(function(id) { 
						return parseInt(id.trim()); 
					}).filter(function(id) { 
						return id > 0; 
					});
					
					if (typeof galleryImages !== 'undefined') {
						galleryImages = galleryArray;
					} else {
						window.hblExistingGalleryIds = galleryArray;
					}
				}
				<?php endif; ?>
			});
		})(jQuery);
		</script>
		<?php endif; ?>
		<?php
	}
}

