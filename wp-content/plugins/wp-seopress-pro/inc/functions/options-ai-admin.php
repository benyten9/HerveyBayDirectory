<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options AI Admin.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

// Bulk actions.
// Generate automatically SEO metadata.
$post_types = seopress_get_service( 'WordPressData' )->getPostTypes();


foreach ( (array) $post_types as $key => $value ) {
	if ( method_exists( seopress_get_service( 'TitleOption' ), 'getSingleCptEnable' ) && null === seopress_get_service( 'TitleOption' )->getSingleCptEnable( $key ) && '' != $key ) {
		add_filter( 'bulk_actions-edit-' . $key, 'seopress_bulk_actions_ai_title' );
		add_filter( 'bulk_actions-edit-' . $key, 'seopress_bulk_actions_ai_desc' );
	}
}

/**
 * Bulk action to generate meta title with AI.
 *
 * @param array $bulk_actions The bulk actions.
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_title( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'edit_posts', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_title'] = __( 'Generate meta title with AI', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action to generate meta description with AI.
 *
 * @param array $bulk_actions The bulk actions.
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_desc( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'edit_posts', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_desc'] = __( 'Generate meta description with AI', 'wp-seopress-pro' );

	return $bulk_actions;
}

add_action( 'admin_notices', 'seopress_bulk_action_ai_title_admin_notice' );

/**
 * Admin notice for bulk action to generate meta title with AI.
 *
 * @return void
 */
function seopress_bulk_action_ai_title_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_posts'] ) ) {
		$index_count = intval( $_REQUEST['bulk_ai_posts'] );

		printf(
			'<div id="message" class="updated fade"><p>' .
				esc_html(
				/* translators: %s number of posts */
					_n(
						'%s generation by AI.',
						'%s generations by AI.',
						$index_count,
						'wp-seopress-pro'
					)
				) . '</p></div>',
			absint( $index_count )
		);
	}

	if ( ! empty( $_REQUEST['bulk_ai_posts_failed'] ) ) {
		$index_count = intval( $_REQUEST['bulk_ai_posts_failed'] );

		$log = '<a href="' . esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ) ) . '" class="button button-secondary">' . esc_html__( 'Check out logs', 'wp-seopress-pro' ) . '</a>';

		if ( $index_count > 0 ) {
			$message = sprintf(
				/* translators: %1$s number of posts, %2$s link to logs */
				_n(
					'%1$s generation failed by AI.',
					'%1$s generations failed by AI.',
					absint( $index_count ),
					'wp-seopress-pro'
				),
				absint( $index_count )
			);

			printf(
				'<div id="message" class="error fade"><p>%s %s</p></div>',
				esc_html( $message ),
				wp_kses_post( $log )
			);
		}
	}

	if ( ! empty( $_REQUEST['bulk_ai_title_posts'] ) ) {
		$ai_title_count = intval( $_REQUEST['bulk_ai_title_posts'] );
		/* translators: %s number of posts */
		$message = _n( '%s meta title generated with AI.', '%s meta titles generated with AI.', $ai_title_count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $ai_title_count ) ) ) );
	}
}

add_action( 'admin_notices', 'seopress_bulk_action_ai_desc_admin_notice' );

/**
 * Admin notice for bulk action to generate meta description with AI.
 *
 * @return void
 */
function seopress_bulk_action_ai_desc_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_desc_posts'] ) ) {
		$ai_desc_count = intval( $_REQUEST['bulk_ai_desc_posts'] );
		/* translators: %s number of posts */
		$message = _n( '%s meta description generated with AI.', '%s meta descriptions generated with AI.', $ai_desc_count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $ai_desc_count ) ) ) );
	}
}

/**
 * Bulk action to generate image metadata (alt text, caption, description) for images
 *
 * @param array $bulk_actions
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_alt_text( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'upload_files', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_alt_text'] = esc_html__( 'Generate alt, caption, description with AI', 'wp-seopress-pro' );

	return $bulk_actions;
}
add_filter( 'bulk_actions-upload', 'seopress_bulk_actions_ai_alt_text' );


/**
 * Admin notice for bulk action to generate image metadata with AI.
 *
 * @return void
 */
function seopress_bulk_actions_ai_alt_text_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_alt_text'] ) ) {
		$ai_alt_text_count = intval( $_REQUEST['bulk_ai_alt_text'] );
		/* translators: %s number of media */
		$message = _n( '%s image metadata (alt, caption, description) generated with AI.', '%s image metadata (alt, caption, description) generated with AI.', $ai_alt_text_count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $ai_alt_text_count ) ) ) );
	}
}
add_action( 'admin_notices', 'seopress_bulk_actions_ai_alt_text_notice' );

/**
 * Bulk action to generate image metadata for images with missing alt text
 *
 * @param array $bulk_actions The bulk actions.
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_alt_text_missing( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'upload_files', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_alt_text_missing'] = esc_html__( 'Generate alt, caption, description with AI (only for missing)', 'wp-seopress-pro' );

	return $bulk_actions;
}
add_filter( 'bulk_actions-upload', 'seopress_bulk_actions_ai_alt_text_missing' );

/**
 * Bulk action to generate only alt text with AI.
 *
 * @param array $bulk_actions The bulk actions.
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_alt_only( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'upload_files', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_alt_only'] = esc_html__( 'Generate alt text with AI', 'wp-seopress-pro' );

	return $bulk_actions;
}
add_filter( 'bulk_actions-upload', 'seopress_bulk_actions_ai_alt_only' );

/**
 * Bulk action to generate only caption with AI.
 *
 * @param array $bulk_actions The bulk actions.
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_caption_only( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'upload_files', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_caption_only'] = esc_html__( 'Generate caption with AI', 'wp-seopress-pro' );

	return $bulk_actions;
}
add_filter( 'bulk_actions-upload', 'seopress_bulk_actions_ai_caption_only' );

/**
 * Bulk action to generate only description with AI.
 *
 * @param array $bulk_actions The bulk actions.
 *
 * @return array $bulk_actions
 */
function seopress_bulk_actions_ai_description_only( $bulk_actions ) {
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'upload_files', 'bulk' ) ) {
		return $bulk_actions;
	}
	$bulk_actions['seopress_ai_description_only'] = esc_html__( 'Generate description with AI', 'wp-seopress-pro' );

	return $bulk_actions;
}
add_filter( 'bulk_actions-upload', 'seopress_bulk_actions_ai_description_only' );

/**
 * Admin notice for bulk action to generate image metadata for images with missing alt text with AI.
 *
 * @return void
 */
function seopress_bulk_actions_ai_alt_text_missing_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_alt_text_missing'] ) ) {
		$ai_alt_text_count = intval( $_REQUEST['bulk_ai_alt_text_missing'] );
		/* translators: %s number of media */
		$message = _n( '%s image metadata (alt, caption, description) generated with AI.', '%s image metadata (alt, caption, description) generated with AI.', $ai_alt_text_count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $ai_alt_text_count ) ) ) );
	}
}
add_action( 'admin_notices', 'seopress_bulk_actions_ai_alt_text_missing_notice' );

/**
 * Admin notice for bulk action to generate only alt text with AI.
 *
 * @return void
 */
function seopress_bulk_actions_ai_alt_only_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_alt_only'] ) ) {
		$count = intval( $_REQUEST['bulk_ai_alt_only'] );
		/* translators: %s number of media */
		$message = _n( '%s alt text generated with AI.', '%s alt texts generated with AI.', $count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $count ) ) ) );
	}
}
add_action( 'admin_notices', 'seopress_bulk_actions_ai_alt_only_notice' );

/**
 * Admin notice for bulk action to generate only caption with AI.
 *
 * @return void
 */
function seopress_bulk_actions_ai_caption_only_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_caption_only'] ) ) {
		$count = intval( $_REQUEST['bulk_ai_caption_only'] );
		/* translators: %s number of media */
		$message = _n( '%s caption generated with AI.', '%s captions generated with AI.', $count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $count ) ) ) );
	}
}
add_action( 'admin_notices', 'seopress_bulk_actions_ai_caption_only_notice' );

/**
 * Admin notice for bulk action to generate only description with AI.
 *
 * @return void
 */
function seopress_bulk_actions_ai_description_only_notice() {
	if ( ! empty( $_REQUEST['bulk_ai_description_only'] ) ) {
		$count = intval( $_REQUEST['bulk_ai_description_only'] );
		/* translators: %s number of media */
		$message = _n( '%s description generated with AI.', '%s descriptions generated with AI.', $count, 'wp-seopress-pro' );
		printf( '<div id="message" class="updated fade"><p>%s</p></div>', esc_html( sprintf( $message, absint( $count ) ) ) );
	}
}
add_action( 'admin_notices', 'seopress_bulk_actions_ai_description_only_notice' );
