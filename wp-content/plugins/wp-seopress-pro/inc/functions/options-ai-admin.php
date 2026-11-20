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

/**
 * Register bulk actions on a list table, honouring the seopress_bulk_actions filter.
 *
 * @since 10.3
 *
 * @param string $hook    Bulk actions hook to register on, e.g. bulk_actions-upload.
 * @param string $key     Post type or taxonomy name, 'attachment' for the media library.
 * @param array  $actions Bulk actions to register, as $callback => $handler. $handler is null when
 *                        the action has no PHP handler, which is the case for the AI ones: they are
 *                        handled in JavaScript.
 *
 * @return void
 */
function seopress_pro_register_bulk_actions( $hook, $key, $actions ) {
	/** This filter is documented in wp-seopress/inc/functions/options-advanced-admin.php */
	$actions = apply_filters( 'seopress_bulk_actions', $actions, $key );

	if ( ! is_array( $actions ) ) {
		return;
	}

	foreach ( $actions as $action => $handler ) {
		add_filter( $hook, $action );

		if ( null !== $handler ) {
			add_filter( 'handle_' . $hook, $handler, 10, 3 );
		}
	}
}

$post_types = seopress_get_service( 'WordPressData' )->getPostTypes();


foreach ( (array) $post_types as $key => $value ) {
	if ( method_exists( seopress_get_service( 'TitleOption' ), 'getSingleCptEnable' ) && null === seopress_get_service( 'TitleOption' )->getSingleCptEnable( $key ) && '' != $key ) {
		seopress_pro_register_bulk_actions(
			'bulk_actions-edit-' . $key,
			$key,
			array(
				'seopress_bulk_actions_ai_title' => null,
				'seopress_bulk_actions_ai_desc'  => null,
			)
		);
	}
}

// Media library bulk actions.
seopress_pro_register_bulk_actions(
	'bulk_actions-upload',
	'attachment',
	array(
		'seopress_bulk_actions_ai_alt_text'         => null,
		'seopress_bulk_actions_ai_alt_text_missing' => null,
		'seopress_bulk_actions_ai_alt_only'         => null,
		'seopress_bulk_actions_ai_caption_only'     => null,
		'seopress_bulk_actions_ai_description_only' => null,
	)
);

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

add_action( 'admin_notices', 'seopress_bulk_action_ai_reviewed_admin_notice' );

/**
 * Admin notice once the AI suggestions approved in the bulk review are saved.
 *
 * @since 10.4.0
 *
 * @return void
 */
function seopress_bulk_action_ai_reviewed_admin_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a count.
	$saved = isset( $_GET['bulk_ai_reviewed'] ) ? absint( wp_unslash( $_GET['bulk_ai_reviewed'] ) ) : 0;

	if ( $saved > 0 ) {
		/* translators: %s: number of AI suggestions saved after review */
		$message = _n( '%s reviewed AI suggestion saved.', '%s reviewed AI suggestions saved.', $saved, 'wp-seopress-pro' );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( $message, number_format_i18n( $saved ) ) ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a count.
	$failed = isset( $_GET['bulk_ai_review_failed'] ) ? absint( wp_unslash( $_GET['bulk_ai_review_failed'] ) ) : 0;

	if ( $failed > 0 ) {
		/* translators: %s: number of approved AI suggestions that could not be saved */
		$message = _n( '%s approved AI suggestion could not be saved.', '%s approved AI suggestions could not be saved.', $failed, 'wp-seopress-pro' );
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( sprintf( $message, number_format_i18n( $failed ) ) ) );
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
