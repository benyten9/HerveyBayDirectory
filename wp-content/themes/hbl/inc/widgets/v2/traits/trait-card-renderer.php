<?php
/**
 * Card Renderer Trait for HBL Directorist V2
 * 
 * Handles rendering of listing cards with expandable content (like V1)
 *
 * @package HBL
 * @since 2.0.0
 */

namespace HBL\Widgets\V2\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Card_Renderer {

	/**
	 * Render individual listing card (expandable like V1)
	 *
	 * @param int $listing_id Listing post ID
	 * @param array|null $external_settings Optional settings for AJAX rendering
	 */
	protected function render_listing_card( $listing_id, $external_settings = null ) {
		// Batch get all meta data in one call for performance
		$all_meta = get_post_meta( $listing_id );
		
		// Get pricing plan info
		$plan_id = isset( $all_meta['_fm_plans'][0] ) ? absint( $all_meta['_fm_plans'][0] ) : 0;
		
		// Verify plan exists and is published
		if ( $plan_id > 0 ) {
			$plan_post = get_post( $plan_id );
			if ( ! $plan_post || $plan_post->post_status !== 'publish' || $plan_post->post_type !== 'atbdp_pricing_plans' ) {
				$plan_id = 0;
			}
		}
		
		// Get settings
		$settings = $external_settings !== null ? $external_settings : $this->get_settings_for_display();
		
		// Determine plan tier
		$plan_tier = $this->get_plan_tier( $plan_id, $settings );
		
		// Get listing data
		$address = isset( $all_meta['_address'][0] ) ? $all_meta['_address'][0] : '';
		$phone = isset( $all_meta['_phone'][0] ) ? $all_meta['_phone'][0] : '';
		$website = isset( $all_meta['_website'][0] ) ? $all_meta['_website'][0] : '';
		$email = isset( $all_meta['_email'][0] ) ? $all_meta['_email'][0] : '';
		$is_claimed = isset( $all_meta['_claimed_by_admin'][0] ) && ! empty( $all_meta['_claimed_by_admin'][0] );
		
		// Get logo
		$logo_url = $this->get_listing_logo( $listing_id, $all_meta );

		// If no logo, try the first gallery image
		if ( empty( $logo_url ) ) {
			$gallery_ids = array();
			if ( function_exists( 'atbdp_get_listing_attachment_ids' ) ) {
				$gallery_ids = atbdp_get_listing_attachment_ids( $listing_id );
			}
			if ( empty( $gallery_ids ) ) {
				$raw = get_post_meta( $listing_id, '_listing_img', true );
				$gallery_ids = is_array( $raw ) ? $raw : array();
			}
			if ( ! empty( $gallery_ids ) ) {
				$first = $gallery_ids[0];
				if ( is_numeric( $first ) ) {
					$src      = wp_get_attachment_image_src( (int) $first, 'medium' );
					$logo_url = $src ? $src[0] : '';
				} elseif ( filter_var( $first, FILTER_VALIDATE_URL ) ) {
					$logo_url = $first;
				}
			}
		}

		// If still nothing, use the widget-configured fallback logo
		if ( empty( $logo_url ) && ! empty( $settings['fallback_logo']['url'] ) ) {
			$logo_url = $settings['fallback_logo']['url'];
		}
		
		// Get rating
		$average_rating = 0;
		$rating_count = 0;
		if ( function_exists( 'directorist_get_listing_rating' ) ) {
			$average_rating = floatval( directorist_get_listing_rating( $listing_id ) );
		}
		if ( function_exists( 'directorist_get_listing_review_count' ) ) {
			$rating_count = intval( directorist_get_listing_review_count( $listing_id ) );
		}
		
		// Get categories and tags
		$all_categories = wp_get_post_terms( $listing_id, 'at_biz_dir-category' );
		$all_tags = wp_get_post_terms( $listing_id, 'at_biz_dir-tags' );
		$location_terms = wp_get_post_terms( $listing_id, 'at_biz_dir-location' );
		
		// Limit based on tier
		$max_categories = $plan_tier === 'gold' ? 3 : ( $plan_tier === 'silver' ? 2 : 1 );
		$max_tags = $plan_tier === 'gold' ? 5 : ( $plan_tier === 'silver' ? 5 : 3 );
		
		$categories = is_array( $all_categories ) && ! is_wp_error( $all_categories ) 
			? array_slice( $all_categories, 0, $max_categories ) 
			: array();
		
		$tags = is_array( $all_tags ) && ! is_wp_error( $all_tags ) 
			? array_slice( $all_tags, 0, $max_tags ) 
			: array();
		
		// Get social media
		$social = isset( $all_meta['social'][0] ) ? maybe_unserialize( $all_meta['social'][0] ) : '';
		if ( empty( $social ) && isset( $all_meta['_social'][0] ) ) {
			$social = maybe_unserialize( $all_meta['_social'][0] );
		}
		
		// Build social array from individual fields if needed
		if ( empty( $social ) || ! is_array( $social ) ) {
			$social = array();
			$social_fields = array(
				'facebook' => array( '_facebook', 'facebook' ),
				'instagram' => array( '_instagram', 'instagram' ),
				'twitter' => array( '_twitter', 'twitter' ),
				'linkedin' => array( '_linkedin', 'linkedin' ),
				'youtube' => array( '_youtube', 'youtube' ),
				'tiktok' => array( '_tiktok', 'tiktok' ),
			);
			
			foreach ( $social_fields as $network_id => $field_names ) {
				foreach ( $field_names as $field_name ) {
					if ( isset( $all_meta[ $field_name ][0] ) && ! empty( $all_meta[ $field_name ][0] ) ) {
						$social[] = array(
							'id' => $network_id,
							'url' => $all_meta[ $field_name ][0],
						);
						break;
					}
				}
			}
		}
		
		// Get listing URL
		$listing_url = get_permalink( $listing_id );
		
		// Clean title (remove "Preview:" prefix)
		$title = $this->get_clean_title( get_the_title( $listing_id ) );
		
		// Get description
		$content = get_the_content( null, false, $listing_id );
		$description_words = 30;
		$description_preview = wp_trim_words( strip_shortcodes( wp_strip_all_tags( $content ) ), $description_words, '...' );
		
		// Format review text
		if ( $rating_count > 0 ) {
			$review_text = sprintf( _n( '%s Review', '%s Reviews', $rating_count, 'hbl' ), number_format_i18n( $rating_count ) );
		} else {
			$review_text = __( '(0 Reviews)', 'hbl' );
		}
		
		// Store category and location IDs for filtering
		$category_id = ! empty( $categories ) ? $categories[0]->term_id : '';
		$location_id = ! empty( $location_terms ) ? $location_terms[0]->term_id : '';
		
		// Determine if card should be expandable
		$is_expandable = ( 'bronze' !== $plan_tier );
		
		// Map for click handling
		$card_link_attr = '';
		if ( ! $is_expandable ) {
			$card_link_attr = 'onclick="window.open(\'' . esc_url( $listing_url ) . '\', \'_blank\'); return false;"'; // Bronze card click action
		}
		
		?>
		<div class="hbl-v2-listing-card hbl-plan-<?php echo esc_attr( $plan_tier ); ?> <?php echo $is_expandable ? 'hbl-expandable' : 'hbl-bronze-card'; ?>" 
		     data-listing-id="<?php echo esc_attr( $listing_id ); ?>"
		     data-category="<?php echo esc_attr( $category_id ); ?>"
		     data-location="<?php echo esc_attr( $location_id ); ?>"
		     data-rating="<?php echo esc_attr( $average_rating ); ?>"
		     data-plan-tier="<?php echo esc_attr( $plan_tier ); ?>"
		     style="cursor: pointer; position: relative;"
		     <?php echo $card_link_attr; ?>>


			<?php
			// Show plan badge if enabled
			if ( isset( $settings['show_plan_badges'] ) && 'yes' === $settings['show_plan_badges'] && ! empty( $settings['plan_badges'] ) ) :
				foreach ( $settings['plan_badges'] as $badge ) :
					if ( isset( $badge['plan_tier'] ) && $badge['plan_tier'] === $plan_tier ) :
						$badge_text = isset( $badge['badge_text'] ) ? $badge['badge_text'] : 'PREMIUM';
						$badge_bg = isset( $badge['badge_bg_color'] ) ? $badge['badge_bg_color'] : '#F9532A';
						$badge_color = isset( $badge['badge_text_color'] ) ? $badge['badge_text_color'] : '#FFFFFF';
						?>
						<div class="hbl-v2-plan-badge" style="background: <?php echo esc_attr( $badge_bg ); ?>; color: <?php echo esc_attr( $badge_color ); ?>;">
							<?php echo esc_html( $badge_text ); ?>
						</div>
						<?php
						break;
					endif;
				endforeach;
			endif;
			?>
			
			
			<!-- Card Header (Always Visible) -->
			<div class="hbl-v2-card-header">
				
				<!-- Left Section: Logo only -->
				<div class="hbl-v2-card-header-left">
					<!-- Logo -->
					<div class="hbl-v2-listing-logo">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $title ); ?>">
						<?php else : ?>
							<div class="hbl-v2-logo-placeholder">
								<i class="bi bi-building"></i>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Center Section: Business Details -->
				<div class="hbl-v2-card-header-center">
					<!-- Business Name -->
					<div class="hbl-v2-listing-title-row">
						<h3 class="hbl-v2-listing-title"><?php echo esc_html( $title ); ?></h3>
						<?php if ( $is_claimed ) : ?>
							<span class="hbl-v2-claimed-badge" title="<?php esc_attr_e( 'Verified Owner', 'hbl' ); ?>">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none">
									<path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
								</svg>
								<span><?php esc_html_e( 'Claimed', 'hbl' ); ?></span>
							</span>
						<?php endif; ?>
						
						<?php if ( 'bronze' !== $plan_tier ) : ?>
							<div class="hbl-v2-rating">
								<div class="hbl-v2-stars">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<i class="bi bi-star<?php echo $i <= $average_rating ? '-fill' : ''; ?>"></i>
									<?php endfor; ?>
								</div>
								<span class="hbl-v2-review-count">(<?php echo esc_html( $rating_count ); ?>)</span>
							</div>
						<?php endif; ?>
					</div>

					<!-- Categories and Tags -->
					<?php if ( ! empty( $categories ) || ! empty( $tags ) ) : ?>
						<div class="hbl-v2-listing-taxonomies">
							<?php if ( ! empty( $categories ) ) : ?>
								<div class="hbl-v2-listing-categories">
									<?php 
									$category_links = array();
									foreach ( $categories as $category ) {
										$cat_url = remove_query_arg( 'directory_type', get_term_link( $category ) );
										$category_links[] = '<a href="' . esc_url( $cat_url ) . '" onclick="event.stopPropagation();">' . esc_html( $category->name ) . '</a>';
									}
									echo '<span class="hbl-v2-taxonomy-label">' . esc_html__( 'Category', 'hbl' ) . ': </span>';
									echo '<span class="hbl-v2-taxonomy-items">' . implode( ', ', $category_links ) . '</span>';
									?>
								</div>
							<?php endif; ?>
							
							<?php if ( ! empty( $tags ) ) : ?>
								<div class="hbl-v2-listing-tags">
									<?php 
									$tag_links = array();
									foreach ( $tags as $tag ) {
										$tag_url = remove_query_arg( 'directory_type', get_term_link( $tag ) );
										$tag_links[] = '<a href="' . esc_url( $tag_url ) . '" onclick="event.stopPropagation();">' . esc_html( $tag->name ) . '</a>';
									}
									echo '<span class="hbl-v2-taxonomy-label">' . esc_html__( 'Tags', 'hbl' ) . ': </span>';
									echo '<span class="hbl-v2-taxonomy-items">' . implode( ', ', $tag_links ) . '</span>';
									?>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<!-- Description Preview -->
					<?php if ( ! empty( $description_preview ) ) : ?>
						<div class="hbl-v2-listing-description-preview">
							<p><?php echo esc_html( $description_preview ); ?></p>
						</div>
					<?php endif; ?>

					<!-- Meta Info -->
					<div class="hbl-v2-listing-meta">
						<div class="hbl-v2-meta-row">
							<?php 
							$display_address = $address;
							if ( ! empty( $location_terms ) ) {
								$display_address = $location_terms[0]->name;
							}
							
							if ( $display_address ) : ?>
								<div class="hbl-v2-meta-item">
									<i class="bi bi-geo-alt"></i>
									<span><?php echo esc_html( $display_address ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- Action Buttons -->
					<div class="hbl-v2-card-actions">
						<?php if ( $phone ) : 
							$call_href = 'tel:' . esc_attr( $phone );
							// For bronze tiers, the call button should redirect to the single listing page
							if ( 'bronze' === $plan_tier ) {
								$call_href = esc_url( $listing_url );
							}
						?>
							<a href="<?php echo $call_href; ?>" class="hbl-v2-btn hbl-v2-btn-call" onclick="event.stopPropagation();">
								<?php esc_html_e( 'Call Now', 'hbl' ); ?>
							</a>
						<?php endif; ?>
						
						<a href="<?php echo esc_url( $listing_url ); ?>" class="hbl-v2-btn hbl-v2-btn-view-profile" onclick="event.stopPropagation();" target="_blank">
							<?php esc_html_e( 'View Full Profile', 'hbl' ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- Expandable Content (Silver/Gold only) -->
			<?php if ( 'bronze' !== $plan_tier ) : ?>
				<div class="hbl-v2-card-expandable" onclick="event.stopPropagation();" style="cursor: auto;">
					<div class="hbl-v2-card-expandable-content">
						
						<?php
						// Get gallery images
						$gallery = array();
						if ( function_exists( 'atbdp_get_listing_attachment_ids' ) ) {
							$gallery = atbdp_get_listing_attachment_ids( $listing_id );
						}
						
						// Tier limits
						$max_images_gallery = $plan_tier === 'gold' ? 10 : 5;
						$max_reviews = $plan_tier === 'gold' ? 10 : 5;
						
						// Limit gallery
						if ( ! empty( $gallery ) && is_array( $gallery ) ) {
							$gallery = array_slice( $gallery, 0, $max_images_gallery );
						}
						?>
						
						<!-- Photo Gallery -->
						<?php if ( ! empty( $gallery ) && is_array( $gallery ) ) : ?>
							<div class="hbl-v2-expandable-section hbl-v2-gallery-section">
								<h4><?php esc_html_e( 'Photo Gallery', 'hbl' ); ?></h4>
								<div class="hbl-v2-gallery-grid">
									<?php 
									foreach ( $gallery as $image_id ) : 
										if ( empty( $image_id ) ) continue;
										
										if ( is_numeric( $image_id ) ) {
											$image_url = wp_get_attachment_image_url( $image_id, 'large' );
										} elseif ( filter_var( $image_id, FILTER_VALIDATE_URL ) ) {
											$image_url = $image_id;
										} else {
											$image_url = wp_get_attachment_url( $image_id );
										}
										
										if ( $image_url ) :
									?>
										<div class="hbl-v2-gallery-item">
											<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
										</div>
									<?php 
										endif;
									endforeach; 
									?>
								</div>
			</div>
			<div class="hbl-v2-expandable-divider"></div>
						<?php endif; ?>
						
						<!-- Services -->
						<div class="hbl-v2-expandable-section hbl-v2-services-section">
							<h4><?php esc_html_e( 'Services', 'hbl' ); ?></h4>
							<?php
							$services_text = '';
							$service_fields = array( 'custom-textarea', 'custom_textarea', '_custom-textarea', '_custom_textarea', '_features', 'features', 'services', '_services' );
							
							foreach ( $service_fields as $field ) {
								$services_text = get_post_meta( $listing_id, $field, true );
								if ( ! empty( $services_text ) ) break;
							}
							
							if ( ! empty( $services_text ) ) :
								$services_lines = array_filter( array_map( 'trim', explode( "\n", $services_text ) ) );
								if ( ! empty( $services_lines ) ) :
							?>
								<ul class="hbl-v2-services-list">
									<?php foreach ( $services_lines as $service ) : ?>
										<li><i class="bi bi-check-circle-fill"></i><?php echo esc_html( $service ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php 
								endif;
							else :
							?>
								<p class="hbl-v2-no-content"><?php esc_html_e( 'No services listed', 'hbl' ); ?></p>
							<?php endif; ?>

						</div>
						
						<div class="hbl-v2-expandable-divider"></div>
						
						<!-- Contact & Location (Silver & Gold) -->
						<div class="hbl-v2-expandable-section hbl-v2-contact-section">
							<h4><?php esc_html_e( 'Contact & Location', 'hbl' ); ?></h4>
							
							<div class="hbl-v2-contact-grid">
								<?php if ( $address ) : ?>
									<div class="hbl-v2-contact-item">
										<div class="hbl-v2-contact-icon"><i class="bi bi-geo-alt"></i></div>
										<div class="hbl-v2-contact-text">
											<span class="hbl-v2-contact-label"><?php esc_html_e( 'Address', 'hbl' ); ?></span>
											<span class="hbl-v2-contact-value"><?php echo esc_html( $address ); ?></span>
										</div>
									</div>
								<?php endif; ?>
								
								<?php if ( $phone ) : ?>
									<div class="hbl-v2-contact-item">
										<div class="hbl-v2-contact-icon"><i class="bi bi-telephone"></i></div>
										<div class="hbl-v2-contact-text">
											<span class="hbl-v2-contact-label"><?php esc_html_e( 'Phone', 'hbl' ); ?></span>
											<span class="hbl-v2-contact-value">
                                                <a href="tel:<?php echo esc_attr( $phone ); ?>" style="color: inherit; text-decoration: none;"><?php echo esc_html( $phone ); ?></a>
                                            </span>
										</div>
									</div>
								<?php endif; ?>
								
								<?php if ( $website ) : ?>
									<div class="hbl-v2-contact-item">
										<div class="hbl-v2-contact-icon"><i class="bi bi-globe"></i></div>
										<div class="hbl-v2-contact-text">
											<span class="hbl-v2-contact-label"><?php esc_html_e( 'Website', 'hbl' ); ?></span>
											<span class="hbl-v2-contact-value">
                                                <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener" style="color: inherit; text-decoration: none;"><?php echo esc_html( parse_url( $website, PHP_URL_HOST ) ); ?></a>
                                            </span>
										</div>
									</div>
								<?php endif; ?>
							</div>
							
							<?php 
							// Map for quick view
							$lat = get_post_meta( $listing_id, '_manual_lat', true );
							$lng = get_post_meta( $listing_id, '_manual_lng', true );
							
							if ( $lat && $lng ) :
							?>
								<div id="hbl-v2-quick-map-<?php echo esc_attr( $listing_id ); ?>" class="hbl-v2-quick-map hbl-single-listing-map" data-lat="<?php echo esc_attr( $lat ); ?>" data-lng="<?php echo esc_attr( $lng ); ?>" data-title="<?php echo esc_attr( $title ); ?>"></div>
								

							<?php endif; ?>
							
							<?php if ( 'gold' === $plan_tier && $lat && $lng ) : ?>
								<div class="hbl-v2-directions-btn-wrapper" style="margin-top: 15px;">
									<a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo esc_attr( $lat . ',' . $lng ); ?>" target="_blank" rel="noopener" class="hbl-v2-btn hbl-v2-btn-outline" style="width: 100%; justify-content: center;">
										<i class="bi bi-cursor-fill" style="margin-right: 8px;"></i>
										<?php esc_html_e( 'Get Directions', 'hbl' ); ?>
									</a>
								</div>
							<?php endif; ?>
						</div>
						
						<?php if ( 'gold' === $plan_tier ) : ?>
						<div class="hbl-v2-expandable-divider"></div>
						
						<!-- Reviews Section -->
						<div class="hbl-v2-expandable-section hbl-v2-reviews-section-full">
							<h4><?php esc_html_e( 'Reviews', 'hbl' ); ?></h4>
							
							<?php
							// Get reviews for this listing
							$reviews = array();
							if ( function_exists( 'get_comments' ) ) {
								$reviews = get_comments( array(
									'post_id' => $listing_id,
									'status' => 'approve',
									'type' => 'review',
									'number' => $max_reviews,
								) );
							}
							?>
							
							<?php if ( $rating_count > 0 && ! empty( $reviews ) ) : ?>
								<!-- Overall Rating -->
								<div class="hbl-v2-reviews-rating">
									<div class="hbl-v2-rating-point">
										<span class="hbl-v2-rating-number"><?php echo number_format( $average_rating, 1 ); ?></span>
										<div class="hbl-v2-rating-info">
											<div class="hbl-v2-stars">
												<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
													<i class="bi bi-star<?php echo $i <= $average_rating ? '-fill' : ''; ?>"></i>
												<?php endfor; ?>
											</div>
											<span class="hbl-v2-rating-text"><?php echo esc_html( $review_text ); ?></span>
										</div>
									</div>
								</div>
								
								<!-- Latest Review -->
								<?php 
								$latest_review = $reviews[0];
								$reviewer_name = ! empty( $latest_review->comment_author ) ? $latest_review->comment_author : __( 'Anonymous', 'hbl' );
								$review_date = date_i18n( get_option( 'date_format' ), strtotime( $latest_review->comment_date ) );
								$review_rating_value = get_comment_meta( $latest_review->comment_ID, 'rating', true );
								?>
								<div class="hbl-v2-review-content">
									<div class="hbl-v2-review-header">
										<div class="hbl-v2-reviewer-info">
											<span class="hbl-v2-reviewer-name"><?php echo esc_html( $reviewer_name ); ?></span>
											<span class="hbl-v2-review-date"><?php echo esc_html( $review_date ); ?></span>
										</div>
										<?php if ( $review_rating_value ) : ?>
											<div class="hbl-v2-review-individual-rating">
												<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
													<i class="bi bi-star<?php echo $i <= $review_rating_value ? '-fill' : ''; ?>"></i>
												<?php endfor; ?>
											</div>
										<?php endif; ?>
									</div>
									<p><?php echo esc_html( wp_trim_words( $latest_review->comment_content, 25, '...' ) ); ?></p>
									<?php if ( count( $reviews ) > 1 ) : ?>
										<div class="hbl-v2-review-meta">
											<small><?php echo sprintf( esc_html__( 'and %d more reviews', 'hbl' ), count( $reviews ) - 1 ); ?></small>
										</div>
									<?php endif; ?>
								</div>
							<?php else : ?>
								<p class="hbl-v2-no-reviews"><?php esc_html_e( 'No reviews yet', 'hbl' ); ?></p>
							<?php endif; ?>
						</div>
						
						<div class="hbl-v2-expandable-divider"></div>
						
						<!-- Review Form - Using Directorist Native Review System -->
						<div class="hbl-v2-expandable-section hbl-v2-review-form-section">
							<?php
							// Check if reviews are enabled
							$reviews_enabled = function_exists( 'directorist_is_review_enabled' ) && directorist_is_review_enabled();
							$guest_review_enabled = function_exists( 'directorist_is_guest_review_enabled' ) && directorist_is_guest_review_enabled();
							$can_user_review = is_user_logged_in() || $guest_review_enabled;
							
							// Check if current user has already reviewed
							$user_already_reviewed = false;
							if ( is_user_logged_in() && function_exists( 'directorist_user_review_exists' ) ) {
								$current_user = wp_get_current_user();
								$user_already_reviewed = directorist_user_review_exists( $current_user->user_email, $listing_id );
							}
							
							if ( $reviews_enabled ) :
								if ( $can_user_review && ! $user_already_reviewed ) :
									// Get current user data if logged in
									$current_user = wp_get_current_user();
									$user_name = is_user_logged_in() ? $current_user->display_name : '';
									$user_email = is_user_logged_in() ? $current_user->user_email : '';
							?>
								<h4><?php echo sprintf( esc_html__( 'Leave a Review for "%s"', 'hbl' ), esc_html( $this->get_clean_title( get_the_title( $listing_id ) ) ) ); ?></h4>
								
								<form class="hbl-v2-review-form" data-listing-id="<?php echo esc_attr( $listing_id ); ?>">
									<?php wp_nonce_field( 'hbl_submit_review', 'hbl_review_nonce' ); ?>
									<input type="hidden" name="listing_id" value="<?php echo esc_attr( $listing_id ); ?>">
									
									<!-- Rating -->
									<div class="hbl-v2-form-group hbl-v2-rating-group">
										<label><?php esc_html_e( 'Rating', 'hbl' ); ?> <span class="required">*</span></label>
										<div class="hbl-v2-star-rating" data-rating="0">
											<input type="hidden" name="review_rating" value="0" required>
											<i class="bi bi-star" data-value="1"></i>
											<i class="bi bi-star" data-value="2"></i>
											<i class="bi bi-star" data-value="3"></i>
											<i class="bi bi-star" data-value="4"></i>
											<i class="bi bi-star" data-value="5"></i>
										</div>
									</div>
									
									<?php if ( ! is_user_logged_in() ) : ?>
									<!-- Name (for guests) -->
									<div class="hbl-v2-form-group">
										<label><?php esc_html_e( 'Name', 'hbl' ); ?> <span class="required">*</span></label>
										<input type="text" name="reviewer_name" class="form-control" placeholder="<?php esc_attr_e( 'Your name', 'hbl' ); ?>" required>
									</div>
									
									<!-- Email (for guests) -->
									<div class="hbl-v2-form-group">
										<label><?php esc_html_e( 'Email', 'hbl' ); ?> <span class="required">*</span></label>
										<input type="email" name="reviewer_email" class="form-control" placeholder="<?php esc_attr_e( 'Your email', 'hbl' ); ?>" required>
									</div>
									<?php else : ?>
									<input type="hidden" name="reviewer_name" value="<?php echo esc_attr( $user_name ); ?>">
									<input type="hidden" name="reviewer_email" value="<?php echo esc_attr( $user_email ); ?>">
									<?php endif; ?>
									
									<!-- Review Content -->
									<div class="hbl-v2-form-group">
										<label><?php esc_html_e( 'Your Review', 'hbl' ); ?> <span class="required">*</span></label>
										<textarea name="review_content" class="form-control" rows="4" placeholder="<?php esc_attr_e( 'Share your experience and help others make better choices...', 'hbl' ); ?>" required></textarea>
									</div>
									
									<div class="hbl-v2-form-submit">
										<button type="submit" class="hbl-v2-btn hbl-v2-btn-primary">
											<span class="btn-text"><?php esc_html_e( 'Submit Your Review', 'hbl' ); ?></span>
											<span class="btn-loading" style="display:none;">
												<i class="bi bi-arrow-repeat spin"></i> <?php esc_html_e( 'Submitting...', 'hbl' ); ?>
											</span>
										</button>
									</div>
									
									<div class="hbl-v2-review-message" style="display:none;"></div>
								</form>
							<?php 
								elseif ( $user_already_reviewed ) :
							?>
								<div class="hbl-v2-already-reviewed">
									<i class="bi bi-check-circle-fill"></i>
									<p><?php esc_html_e( 'Thank you! You have already submitted a review for this listing.', 'hbl' ); ?></p>
								</div>
							<?php 
								else :
									// Show login message
							?>
								<h4><?php echo sprintf( esc_html__( 'Would you like to leave "%s" a review?', 'hbl' ), esc_html( $this->get_clean_title( get_the_title( $listing_id ) ) ) ); ?></h4>
								<div class="hbl-v2-login-message">
									<p><?php esc_html_e( 'Please log in to leave a review.', 'hbl' ); ?></p>
									<?php if ( class_exists( '\\ATBDP_Permalink' ) ) : ?>
										<a href="<?php echo esc_url( \ATBDP_Permalink::get_login_page_url( array( 'redirect' => get_the_permalink( $listing_id ), 'scope' => 'review' ) ) ); ?>" class="hbl-v2-btn hbl-v2-btn-primary">
											<?php esc_html_e( 'Login to Write Your Review', 'hbl' ); ?>
										</a>
									<?php else : ?>
										<a href="<?php echo esc_url( wp_login_url( get_the_permalink( $listing_id ) ) ); ?>" class="hbl-v2-btn hbl-v2-btn-primary">
											<?php esc_html_e( 'Login to Write Your Review', 'hbl' ); ?>
										</a>
									<?php endif; ?>
								</div>
			<?php 
								endif;
							else :
							?>
								<h4><?php esc_html_e( 'Reviews', 'hbl' ); ?></h4>
								<p><?php esc_html_e( 'Reviews are currently disabled for this listing.', 'hbl' ); ?></p>
							<?php endif; ?>
						</div>
						<?php endif; ?>

						
					</div>
				</div>
				
				<!-- Expand/Collapse Button at Bottom -->
				<div class="hbl-v2-expand-trigger" onclick="event.stopPropagation(); this.closest('.hbl-v2-listing-card').classList.toggle('expanded');">
					<span class="hbl-v2-expand-text"><?php esc_html_e( 'Quick View', 'hbl' ); ?></span>
					<span class="hbl-v2-expand-icon"></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get listing logo URL
	 *
	 * @param int $listing_id Listing ID
	 * @param array $all_meta All meta data
	 * @return string Logo URL or empty string
	 */
	protected function get_listing_logo( $listing_id, $all_meta ) {
		$logo_url = '';
		
		// Try custom-file field (Directorist plupload format)
		// Users upload "Preview Image" or "Logo" here usually
		$custom_file_variations = array( 'custom-file', '_custom-file' );
		
		foreach ( $custom_file_variations as $field_name ) {
			if ( isset( $all_meta[ $field_name ][0] ) ) {
				$custom_file = $all_meta[ $field_name ][0];
				
				if ( ! empty( $custom_file ) ) {
					if ( strpos( $custom_file, '|' ) !== false ) {
						$parts = explode( '|', $custom_file );
						$logo_url = trim( $parts[0] );
					} else {
						$logo_url = trim( $custom_file );
					}
					
					if ( is_numeric( $logo_url ) ) {
						$media_src = wp_get_attachment_image_src( intval( $logo_url ), 'medium' );
						$logo_url = $media_src ? $media_src[0] : '';
					}
					
					if ( ! empty( $logo_url ) && filter_var( $logo_url, FILTER_VALIDATE_URL ) ) {
						break;
					} else {
						$logo_url = '';
					}
				}
			}
		}

		// REMOVED: Fallback to featured image (get_the_post_thumbnail_url)
		// This ensures we don't show random listing images as the logo if no logo is uploaded.
		
		return $logo_url;
	}

	/**
	 * Clean listing title by removing prefixes like "Preview:", "Draft:", etc.
	 * 
	 * @param string $title The original title
	 * @return string The cleaned title
	 */
	protected function get_clean_title( $title ) {
		// Remove common Directorist prefixes
		$prefixes = array(
			'Preview: ',
			'Preview:',
			'Draft: ',
			'Draft:',
			'Pending: ',
			'Pending:',
		);
		
		foreach ( $prefixes as $prefix ) {
			if ( strpos( $title, $prefix ) === 0 ) {
				$title = substr( $title, strlen( $prefix ) );
				break;
			}
		}
		
		return trim( $title );
	}
}
