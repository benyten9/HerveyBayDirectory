<?php
/**
 * HBL User Profile Widget
 *
 * Display user profile information with modern design
 *
 * @package HBL
 * @since 1.2.697
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_User_Profile extends Widget_Base {

	public function get_name() {
		return 'hbl-user-profile';
	}

	public function get_title() {
		return esc_html__( 'HBL User Profile', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-user-circle-o';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'user', 'profile', 'author', 'member', 'account', 'hbl' );
	}

	protected function register_controls() {
		// ========== CONTENT: GENERAL ==========
		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'profile_source',
			array(
				'label'   => esc_html__( 'Profile Source', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current' => esc_html__( 'Current Logged-in User', 'hbl' ),
					'author'  => esc_html__( 'Author (from URL)', 'hbl' ),
					'custom'  => esc_html__( 'Custom User ID', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'custom_user_id',
			array(
				'label'     => esc_html__( 'User ID', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'condition' => array(
					'profile_source' => 'custom',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: DISPLAY ==========
		$this->start_controls_section(
			'section_display',
			array(
				'label' => esc_html__( 'Display Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'show_avatar',
			array(
				'label'        => esc_html__( 'Show Avatar', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'avatar_size',
			array(
				'label'     => esc_html__( 'Avatar Size', 'hbl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 50,
						'max' => 200,
					),
				),
				'default'   => array(
					'size' => 120,
					'unit' => 'px',
				),
				'condition' => array(
					'show_avatar' => 'yes',
				),
				'selectors' => array(
					'{{WRAPPER}} .hbl-profile-avatar' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'show_name',
			array(
				'label'        => esc_html__( 'Show Name', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_email',
			array(
				'label'        => esc_html__( 'Show Email', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_bio',
			array(
				'label'        => esc_html__( 'Show Bio', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_website',
			array(
				'label'        => esc_html__( 'Show Website', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_member_since',
			array(
				'label'        => esc_html__( 'Show Member Since', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_listings_count',
			array(
				'label'        => esc_html__( 'Show Listings Count', 'hbl' ),
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

		$this->end_controls_section();

		// ========== CONTENT: USER LISTINGS ==========
		$this->start_controls_section(
			'section_listings',
			array(
				'label' => esc_html__( 'User Listings', 'hbl' ),
			)
		);

		$this->add_control(
			'show_listings',
			array(
				'label'        => esc_html__( 'Show User Listings', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'listings_per_page',
			array(
				'label'     => esc_html__( 'Listings Per Page', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 6,
				'min'       => 1,
				'max'       => 24,
				'condition' => array(
					'show_listings' => 'yes',
				),
			)
		);

		$this->add_control(
			'listings_columns',
			array(
				'label'     => esc_html__( 'Columns', 'hbl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '3',
				'options'   => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'condition' => array(
					'show_listings' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: COLORS ==========
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
					'{{WRAPPER}} .hbl-profile-name' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-profile-stat-value' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-profile-social-link:hover' => 'background: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-profile-listing-link' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#4a5568',
				'selectors' => array(
					'{{WRAPPER}} .hbl-profile-bio' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-profile-meta' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-profile-stat-label' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-profile-card' => 'background-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-profile-listing-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Get user to display
	 */
	private function get_profile_user( $settings ) {
		$user = null;

		switch ( $settings['profile_source'] ) {
			case 'current':
				if ( is_user_logged_in() ) {
					$user = wp_get_current_user();
				}
				break;

			case 'author':
				// Get author from URL
				$author_slug = get_query_var( 'author_name' );
				if ( $author_slug ) {
					$user = get_user_by( 'slug', $author_slug );
				} else {
					$author_id = get_query_var( 'author' );
					if ( $author_id ) {
						$user = get_user_by( 'id', $author_id );
					}
				}
				// Fallback to URL parameter
				if ( ! $user && isset( $_GET['author_id'] ) ) {
					$user = get_user_by( 'id', absint( $_GET['author_id'] ) );
				}
				break;

			case 'custom':
				if ( ! empty( $settings['custom_user_id'] ) ) {
					$user = get_user_by( 'id', absint( $settings['custom_user_id'] ) );
				}
				break;
		}

		return $user;
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$user = $this->get_profile_user( $settings );

		if ( ! $user ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="hbl-profile-no-user"><p>' . esc_html__( 'No user found. Please configure the widget settings.', 'hbl' ) . '</p></div>';
			}
			return;
		}

		$user_id = $user->ID;
		$display_name = $user->display_name;
		$email = $user->user_email;
		$bio = get_user_meta( $user_id, 'description', true );
		$website = $user->user_url;
		$registered = $user->user_registered;

		// Social links (common user meta)
		$facebook = get_user_meta( $user_id, 'facebook', true );
		$twitter = get_user_meta( $user_id, 'twitter', true );
		$instagram = get_user_meta( $user_id, 'instagram', true );
		$linkedin = get_user_meta( $user_id, 'linkedin', true );

		// Count user listings
		$listings_count = 0;
		if ( defined( 'ATBDP_POST_TYPE' ) ) {
			$listings_count = count_user_posts( $user_id, ATBDP_POST_TYPE );
		}
		?>
		<div class="hbl-profile-widget">
			<div class="hbl-profile-card">
				<div class="hbl-profile-header">
					<?php if ( 'yes' === $settings['show_avatar'] ) : ?>
						<div class="hbl-profile-avatar">
							<?php echo get_avatar( $user_id, 200 ); ?>
						</div>
					<?php endif; ?>

					<div class="hbl-profile-info">
						<?php if ( 'yes' === $settings['show_name'] ) : ?>
							<h1 class="hbl-profile-name"><?php echo esc_html( $display_name ); ?></h1>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_email'] ) : ?>
							<div class="hbl-profile-meta hbl-profile-email">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_website'] && $website ) : ?>
							<div class="hbl-profile-meta hbl-profile-website">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
									<path d="M2 12H22M12 2C14.5 4.5 16 8 16 12C16 16 14.5 19.5 12 22C9.5 19.5 8 16 8 12C8 8 9.5 4.5 12 2Z" stroke="currentColor" stroke-width="2"/>
								</svg>
								<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $website, PHP_URL_HOST ) ); ?></a>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_member_since'] ) : ?>
							<div class="hbl-profile-meta hbl-profile-member-since">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
									<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<?php echo esc_html__( 'Member since', 'hbl' ) . ' ' . esc_html( date_i18n( 'F Y', strtotime( $registered ) ) ); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( 'yes' === $settings['show_bio'] && $bio ) : ?>
					<div class="hbl-profile-bio">
						<?php echo wp_kses_post( wpautop( $bio ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_listings_count'] ) : ?>
					<div class="hbl-profile-stats">
						<div class="hbl-profile-stat">
							<span class="hbl-profile-stat-value"><?php echo esc_html( $listings_count ); ?></span>
							<span class="hbl-profile-stat-label"><?php echo esc_html( _n( 'Listing', 'Listings', $listings_count, 'hbl' ) ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_social_links'] && ( $facebook || $twitter || $instagram || $linkedin ) ) : ?>
					<div class="hbl-profile-social">
						<?php if ( $facebook ) : ?>
							<a href="<?php echo esc_url( $facebook ); ?>" target="_blank" rel="noopener" class="hbl-profile-social-link" title="Facebook">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
									<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z"/>
								</svg>
							</a>
						<?php endif; ?>
						<?php if ( $twitter ) : ?>
							<a href="<?php echo esc_url( $twitter ); ?>" target="_blank" rel="noopener" class="hbl-profile-social-link" title="Twitter/X">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
									<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
								</svg>
							</a>
						<?php endif; ?>
						<?php if ( $instagram ) : ?>
							<a href="<?php echo esc_url( $instagram ); ?>" target="_blank" rel="noopener" class="hbl-profile-social-link" title="Instagram">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
									<path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37zM17.5 6.5h.01"/>
								</svg>
							</a>
						<?php endif; ?>
						<?php if ( $linkedin ) : ?>
							<a href="<?php echo esc_url( $linkedin ); ?>" target="_blank" rel="noopener" class="hbl-profile-social-link" title="LinkedIn">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
									<path d="M16 8C17.5913 8 19.1174 8.63214 20.2426 9.75736C21.3679 10.8826 22 12.4087 22 14V21H18V14C18 13.4696 17.7893 12.9609 17.4142 12.5858C17.0391 12.2107 16.5304 12 16 12C15.4696 12 14.9609 12.2107 14.5858 12.5858C14.2107 12.9609 14 13.4696 14 14V21H10V14C10 12.4087 10.6321 10.8826 11.7574 9.75736C12.8826 8.63214 14.4087 8 16 8Z"/>
									<rect x="2" y="9" width="4" height="12"/>
									<circle cx="4" cy="4" r="2"/>
								</svg>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( 'yes' === $settings['show_listings'] && defined( 'ATBDP_POST_TYPE' ) ) : ?>
				<?php
				$paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
				$listings_query = new \WP_Query( array(
					'post_type'      => ATBDP_POST_TYPE,
					'posts_per_page' => absint( $settings['listings_per_page'] ),
					'author'         => $user_id,
					'paged'          => $paged,
					'post_status'    => 'publish',
				) );

				if ( $listings_query->have_posts() ) :
				?>
					<div class="hbl-profile-listings">
						<h2 class="hbl-profile-listings-title">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
								<rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
								<rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
								<rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
							</svg>
							<?php esc_html_e( 'Listings', 'hbl' ); ?>
						</h2>

						<div class="hbl-profile-listings-grid hbl-profile-listings-cols-<?php echo esc_attr( $settings['listings_columns'] ); ?>">
							<?php
							while ( $listings_query->have_posts() ) :
								$listings_query->the_post();
								$listing_id = get_the_ID();
								$image = get_the_post_thumbnail_url( $listing_id, 'medium' );
								$categories = get_the_terms( $listing_id, ATBDP_CATEGORY );
							?>
								<div class="hbl-profile-listing-card">
									<a href="<?php the_permalink(); ?>" class="hbl-profile-listing-image">
										<?php if ( $image ) : ?>
											<img src="<?php echo esc_url( $image ); ?>" alt="<?php the_title_attribute(); ?>">
										<?php else : ?>
											<div class="hbl-profile-listing-placeholder">
												<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
													<circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
													<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2"/>
												</svg>
											</div>
										<?php endif; ?>
									</a>
									<div class="hbl-profile-listing-content">
										<h3 class="hbl-profile-listing-title">
											<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
										</h3>
										<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
											<span class="hbl-profile-listing-category"><?php echo esc_html( $categories[0]->name ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							<?php endwhile; ?>
						</div>

						<?php if ( $listings_query->max_num_pages > 1 ) : ?>
							<div class="hbl-profile-pagination">
								<?php
								echo paginate_links( array(
									'total'     => $listings_query->max_num_pages,
									'current'   => $paged,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								) );
								?>
							</div>
						<?php endif; ?>
					</div>
				<?php
				wp_reset_postdata();
				endif;
				?>
			<?php endif; ?>
		</div>
		<?php
	}
}

