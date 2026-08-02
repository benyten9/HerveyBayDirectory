<?php
/**
 * HBL Theme Customizer
 *
 * @package HBL
 * @since 1.0.0
 */

/**
 * Add postMessage support for site title and description for the Theme Customizer.
 *
 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
 */
function hbl_customize_register( $wp_customize ) {
	$wp_customize->get_setting( 'blogname' )->transport         = 'postMessage';
	$wp_customize->get_setting( 'blogdescription' )->transport  = 'postMessage';
	$wp_customize->get_setting( 'header_textcolor' )->transport = 'postMessage';

	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial(
			'blogname',
			array(
				'selector'        => '.site-title a',
				'render_callback' => 'hbl_customize_partial_blogname',
			)
		);
		$wp_customize->selective_refresh->add_partial(
			'blogdescription',
			array(
				'selector'        => '.site-description',
				'render_callback' => 'hbl_customize_partial_blogdescription',
			)
		);
	}

	// Theme Settings Section
	$wp_customize->add_section(
		'hbl_theme_settings',
		array(
			'title'    => __( 'Theme Settings', 'hbl' ),
			'priority' => 25,
		)
	);

	// Page Title Display
	$wp_customize->add_setting(
		'hbl_page_title',
		array(
			'default'           => true,
			'sanitize_callback' => function( $checked ) {
				return ( ( isset( $checked ) && true === $checked ) ? true : false );
			},
		)
	);

	$wp_customize->add_control(
		'hbl_page_title',
		array(
			'label'    => __( 'Page Title', 'hbl' ),
			'section'  => 'hbl_theme_settings',
			'settings' => 'hbl_page_title',
			'type'     => 'checkbox',
			'description' => __( 'Display the page title on singular pages', 'hbl' ),
		)
	);

	// Theme Colors Section
	$wp_customize->add_section(
		'hbl_colors',
		array(
			'title'    => __( 'Theme Colors', 'hbl' ),
			'priority' => 30,
		)
	);

	// Primary Color
	$wp_customize->add_setting(
		'hbl_primary_color',
		array(
			'default'           => '#008080',
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'postMessage',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'hbl_primary_color',
			array(
				'label'    => __( 'Primary Color', 'hbl' ),
				'section'  => 'hbl_colors',
				'settings' => 'hbl_primary_color',
			)
		)
	);

	// Secondary Color
	$wp_customize->add_setting(
		'hbl_secondary_color',
		array(
			'default'           => '#F9532A',
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'postMessage',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'hbl_secondary_color',
			array(
				'label'    => __( 'Secondary Color', 'hbl' ),
				'section'  => 'hbl_colors',
				'settings' => 'hbl_secondary_color',
			)
		)
	);
}
add_action( 'customize_register', 'hbl_customize_register' );

/**
 * Get HBL theme setting
 *
 * @param string $setting_name The setting name.
 * @return mixed The setting value.
 */
function hbl_get_setting( $setting_name ) {
	return get_theme_mod( $setting_name, hbl_get_default_setting_value( $setting_name ) );
}

/**
 * Get default setting value
 *
 * @param string $setting_name The setting name.
 * @return mixed The default value.
 */
function hbl_get_default_setting_value( $setting_name ) {
	$defaults = array(
		'hbl_page_title' => true,
	);

	return isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
}

/**
 * Render the site title for the selective refresh partial.
 *
 * @return void
 */
function hbl_customize_partial_blogname() {
	bloginfo( 'name' );
}

/**
 * Render the site tagline for the selective refresh partial.
 *
 * @return void
 */
function hbl_customize_partial_blogdescription() {
	bloginfo( 'description' );
}

/**
 * Binds JS handlers to make Theme Customizer preview reload changes asynchronously.
 */
function hbl_customize_preview_js() {
	wp_enqueue_script( 'hbl-customizer', get_template_directory_uri() . '/assets/js/customizer.js', array( 'customize-preview' ), HBL_VERSION, true );
}
add_action( 'customize_preview_init', 'hbl_customize_preview_js' );

