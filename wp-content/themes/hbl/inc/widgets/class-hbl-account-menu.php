<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Account_Menu extends Widget_Base {

	public function get_name() {
		return 'hbl-account-menu';
	}

	public function get_title() {
		return esc_html__( 'HBL Account Menu', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-person';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'account', 'dashboard', 'login', 'user', 'header', 'menu' );
	}

	private function icon( $name ) {
		$icons = array(
			'dashboard' => '<path d="M19 3H5C3.89543 3 3 3.89543 3 5V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V5C21 3.89543 20.1046 3 19 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 9H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 21V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'listings'  => '<path d="M8 6H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 12H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 6H3.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12H3.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 18H3.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'plus'      => '<path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'event'     => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'profile'   => '<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'favorites' => '<path d="M20.84 4.61C20.3292 4.09924 19.7228 3.69397 19.0554 3.41708C18.3879 3.14019 17.6725 2.99756 16.95 2.99756C16.2275 2.99756 15.5121 3.14019 14.8446 3.41708C14.1772 3.69397 13.5708 4.09924 13.06 4.61L12 5.67L10.94 4.61C9.9083 3.57831 8.50903 2.99806 7.05 2.99806C5.59096 2.99806 4.19169 3.57831 3.16 4.61C2.1283 5.6417 1.54806 7.04097 1.54806 8.5C1.54806 9.95903 2.1283 11.3583 3.16 12.39L4.22 13.45L12 21.23L19.78 13.45L20.84 12.39C21.3508 11.8792 21.756 11.2728 22.0329 10.6054C22.3098 9.93789 22.4524 9.2225 22.4524 8.5C22.4524 7.7775 22.3098 7.06211 22.0329 6.39464C21.756 5.72718 21.3508 5.12075 20.84 4.61Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'claims'    => '<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'logout'    => '<path d="M9 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H9M16 17L21 12M21 12L16 7M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'chevron'   => '<path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
		);

		if ( empty( $icons[ $name ] ) ) {
			return '';
		}

		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' . $icons[ $name ] . '</svg>';
	}

	private function control_panel_icon() {
		return '<svg width="20" height="20" viewBox="0 0 50 50" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M 2 7 A 1.0001 1.0001 0 0 0 1 8 L 1 42 A 1.0001 1.0001 0 0 0 2 43 L 48 43 A 1.0001 1.0001 0 0 0 49 42 L 49 8 A 1.0001 1.0001 0 0 0 48 7 L 2 7 z M 3 9 L 47 9 L 47 41 L 3 41 L 3 9 z M 16 11 A 1.0001 1.0001 0 0 0 15 12 L 15 13.058594 C 10.509992 13.558947 7 17.379829 7 22 C 7 26.958516 11.041484 31 16 31 C 20.620171 31 24.441053 27.490008 24.941406 23 L 26 23 A 1.0001 1.0001 0 0 0 27 22 C 26.9989 19.083284 25.838874 16.284187 23.777344 14.222656 C 21.715813 12.161126 18.916716 11.0011 16 11 z M 17 13.199219 C 19.007669 13.431398 20.921571 14.195009 22.363281 15.636719 C 23.804991 17.078429 24.568602 18.992331 24.800781 21 L 17 21 L 17 13.199219 z M 33 15 A 1.0001 1.0001 0 1 0 33 17 L 41 17 A 1.0001 1.0001 0 1 0 41 15 L 33 15 z M 15 15.078125 L 15 22 A 1.0001 1.0001 0 0 0 16 23 L 22.921875 23 C 22.437987 26.399042 19.536745 29 16 29 C 12.122516 29 9 25.877484 9 22 C 9 18.463255 11.600958 15.562013 15 15.078125 z M 33 21 A 1.0001 1.0001 0 1 0 33 23 L 41 23 A 1.0001 1.0001 0 1 0 41 21 L 33 21 z M 33 27 A 1.0001 1.0001 0 1 0 33 29 L 41 29 A 1.0001 1.0001 0 1 0 41 27 L 33 27 z M 23.984375 33.486328 A 1.0001 1.0001 0 0 0 23 34.5 L 23 35 L 9 35 A 1.0001 1.0001 0 1 0 9 37 L 23 37 L 23 37.5 A 1.0001 1.0001 0 1 0 25 37.5 L 25 36.167969 A 1.0001 1.0001 0 0 0 25 35.841797 L 25 34.5 A 1.0001 1.0001 0 0 0 23.984375 33.486328 z M 27 35 L 27 37 L 41 37 C 41.553 37 42 36.552 42 36 C 42 35.448 41.553 35 41 35 L 27 35 z"/></svg>';
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General', 'hbl' ),
			)
		);

		$this->add_control(
			'dashboard_url',
			array(
				'label'       => esc_html__( 'Dashboard Page URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'Leave empty to auto-detect', 'hbl' ),
				'default'     => array(
					'url' => '',
				),
				'description' => esc_html__( 'The page using the "HBL Account Dashboard" template. Leave empty to auto-detect it.', 'hbl' ),
			)
		);

		$this->add_control(
			'trigger_text',
			array(
				'label'   => esc_html__( 'Trigger Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Dashboard', 'hbl' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_links',
			array(
				'label' => esc_html__( 'Menu Links', 'hbl' ),
			)
		);

		$links = array(
			'dashboard_link' => esc_html__( 'Dashboard', 'hbl' ),
			'my_listings'    => esc_html__( 'My Listings', 'hbl' ),
			'add_listing'    => esc_html__( 'Add New Listing', 'hbl' ),
			'add_event'      => esc_html__( 'Add New Event', 'hbl' ),
			'profile'        => esc_html__( 'My Profile', 'hbl' ),
			'favorites'      => esc_html__( 'Favorites', 'hbl' ),
			'events'         => esc_html__( 'My Events', 'hbl' ),
			'claims'         => esc_html__( 'My Claims', 'hbl' ),
		);

		foreach ( $links as $key => $default_label ) {
			$this->add_control(
				'show_' . $key,
				array(
					'label'        => esc_html__( 'Show', 'hbl' ) . ' "' . $default_label . '"',
					'type'         => Controls_Manager::SWITCHER,
					'label_on'     => esc_html__( 'Yes', 'hbl' ),
					'label_off'    => esc_html__( 'No', 'hbl' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);

			$this->add_control(
				$key . '_label',
				array(
					'label'     => esc_html__( 'Label', 'hbl' ),
					'type'      => Controls_Manager::TEXT,
					'default'   => $default_label,
					'condition' => array(
						'show_' . $key => 'yes',
					),
				)
			);
		}

		$this->add_control(
			'show_logout',
			array(
				'label'        => esc_html__( 'Show Logout Link', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_guest',
			array(
				'label' => esc_html__( 'Guest Buttons (Logged Out)', 'hbl' ),
			)
		);

		$this->add_control(
			'login_text',
			array(
				'label'   => esc_html__( 'Login Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Login', 'hbl' ),
			)
		);

		$this->add_control(
			'show_register',
			array(
				'label'        => esc_html__( 'Show Register Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'register_text',
			array(
				'label'     => esc_html__( 'Register Button Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Register', 'hbl' ),
				'condition' => array(
					'show_register' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_trigger',
			array(
				'label' => esc_html__( 'Trigger', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'trigger_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1A1A1A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-trigger' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'trigger_hover_color',
			array(
				'label'       => esc_html__( 'Hover Color', 'hbl' ),
				'type'        => Controls_Manager::COLOR,
				'default'     => '',
				'description' => esc_html__( 'Leave empty to keep the text colour and just underline on hover.', 'hbl' ),
				'selectors'   => array(
					'{{WRAPPER}} .hbl-account-menu-trigger:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'trigger_icon_size',
			array(
				'label'     => esc_html__( 'Icon Size', 'hbl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 14,
						'max' => 32,
					),
				),
				'default'   => array(
					'size' => 20,
					'unit' => 'px',
				),
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'name_typography',
				'label'    => esc_html__( 'Name Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-account-menu-name',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_dropdown',
			array(
				'label' => esc_html__( 'Dropdown', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'dropdown_bg',
			array(
				'label'     => esc_html__( 'Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-dropdown' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dropdown_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#E9ECEF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-dropdown' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'link_color',
			array(
				'label'     => esc_html__( 'Link Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#333333',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-list a' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'link_hover_color',
			array(
				'label'     => esc_html__( 'Link Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-list a:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'link_hover_bg',
			array(
				'label'     => esc_html__( 'Link Hover Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 128, 128, 0.08)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-list a:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'logout_color',
			array(
				'label'     => esc_html__( 'Logout Link Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#DC3545',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-logout' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'link_typography',
				'label'    => esc_html__( 'Link Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-account-menu-list a',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_guest',
			array(
				'label' => esc_html__( 'Guest Buttons', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'guest_primary_bg',
			array(
				'label'     => esc_html__( 'Login Button Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-btn-primary' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'guest_primary_hover_bg',
			array(
				'label'     => esc_html__( 'Login Button Hover Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#E04520',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-btn-primary:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'guest_outline_border',
			array(
				'label'     => esc_html__( 'Register Button Border', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#DEE2E6',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-btn-outline' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'guest_outline_color',
			array(
				'label'     => esc_html__( 'Register Button Text', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1A1A1A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-account-menu-btn-outline' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings      = $this->get_settings_for_display();
		$dashboard_url = ! empty( $settings['dashboard_url']['url'] ) ? $settings['dashboard_url']['url'] : hbl_get_dashboard_page_url();

		if ( ! is_user_logged_in() ) {
			$register_base = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_registration_page_link() : wp_registration_url();
			$register_url  = add_query_arg( 'tab', 'signup', $register_base );
			?>
			<div class="hbl-account-menu hbl-account-menu-guest">
				<a href="<?php echo esc_url( $register_url ); ?>" class="hbl-account-menu-btn hbl-account-menu-btn-primary"><?php esc_html_e( 'Login / Register', 'hbl' ); ?></a>
			</div>
			<?php
			return;
		}

		$current_user      = wp_get_current_user();
		$user_id           = $current_user->ID;
		$profile_image_id  = get_user_meta( $user_id, 'hbl_profile_image', true );
		$avatar_url        = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' ) : '';
		$add_listing_url   = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_add_listing_page_link() : '#';
		$add_event_url     = home_url( '/add-event/' );
		$logout_url        = wp_logout_url( home_url() );

		$menu_items = array(
			'dashboard_link' => array( 'icon' => 'dashboard', 'url' => $dashboard_url ),
			'my_listings'    => array( 'icon' => 'listings', 'url' => add_query_arg( 'view', 'listings', $dashboard_url ) ),
			'add_listing'    => array( 'icon' => 'plus', 'url' => $add_listing_url ),
			'add_event'      => array( 'icon' => 'event', 'url' => $add_event_url ),
			'profile'        => array( 'icon' => 'profile', 'url' => add_query_arg( 'view', 'profile', $dashboard_url ) ),
			'favorites'      => array( 'icon' => 'favorites', 'url' => add_query_arg( 'view', 'favorites', $dashboard_url ) ),
			'events'         => array( 'icon' => 'event', 'url' => add_query_arg( 'view', 'events', $dashboard_url ) ),
			'claims'         => array( 'icon' => 'claims', 'url' => add_query_arg( 'view', 'claims', $dashboard_url ) ),
		);
		?>
		<div class="hbl-account-menu" data-hbl-account-menu>
			<button type="button" class="hbl-account-menu-trigger" aria-haspopup="true" aria-expanded="false">
				<span class="hbl-account-menu-icon"><?php echo $this->control_panel_icon(); ?></span>
				<span class="hbl-account-menu-name"><?php echo esc_html( $settings['trigger_text'] ); ?></span>
				<span class="hbl-account-menu-chevron"><?php echo $this->icon( 'chevron' ); ?></span>
			</button>

			<div class="hbl-account-menu-overlay" data-hbl-account-menu-close></div>

			<div class="hbl-account-menu-dropdown" role="menu">
				<div class="hbl-account-menu-header">
					<?php if ( $avatar_url ) : ?>
						<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" class="hbl-account-menu-dropdown-avatar">
					<?php else : ?>
						<?php echo get_avatar( $user_id, 48, '', '', array( 'class' => 'hbl-account-menu-dropdown-avatar' ) ); ?>
					<?php endif; ?>
					<div class="hbl-account-menu-header-text">
						<span class="hbl-account-menu-dropdown-name"><?php echo esc_html( $current_user->display_name ); ?></span>
						<span class="hbl-account-menu-dropdown-email"><?php echo esc_html( $current_user->user_email ); ?></span>
					</div>
					<button type="button" class="hbl-account-menu-close" data-hbl-account-menu-close aria-label="<?php esc_attr_e( 'Close menu', 'hbl' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>
				</div>
				<ul class="hbl-account-menu-list">
					<?php foreach ( $menu_items as $key => $item ) : ?>
						<?php if ( 'yes' === $settings[ 'show_' . $key ] ) : ?>
							<li>
								<a href="<?php echo esc_url( $item['url'] ); ?>">
									<?php echo $this->icon( $item['icon'] ); ?>
									<?php echo esc_html( $settings[ $key . '_label' ] ); ?>
								</a>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( 'yes' === $settings['show_logout'] ) : ?>
						<li class="hbl-account-menu-divider"></li>
						<li>
							<a href="<?php echo esc_url( $logout_url ); ?>" class="hbl-account-menu-logout">
								<?php echo $this->icon( 'logout' ); ?>
								<?php esc_html_e( 'Logout', 'hbl' ); ?>
							</a>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		</div>
		<?php
	}
}
