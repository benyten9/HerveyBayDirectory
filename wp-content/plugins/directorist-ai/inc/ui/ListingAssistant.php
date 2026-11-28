<?php
/**
 * Native listing header and field entry points; Save remains authoritative.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Listing\ListingFormService;

/** Native listing integration using the shared runtime. */
final class ListingAssistant {
	/**
	 * Register native listing integration hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'admin_footer', array( self::class, 'admin' ) );
		add_action( 'directorist_before_submit_listing_frontend', array( self::class, 'frontend' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'directorist_dashboard_listing_action_items_end', array( self::class, 'owner_action' ), 10, 2 );
	}
	/**
	 * Check native listing edit authority and ownership.
	 *
	 * @param mixed $post Native listing post.
	 * @return bool
	 */
	private static function can_assist( $post ): bool {
		return $post && ATBDP_POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID ) && ( current_user_can( 'manage_options' ) || get_current_user_id() === (int) $post->post_author );
	}
	/**
	 * Mount native listing assistance on the listing table or editor.
	 *
	 * @return void
	 */
	public static function admin(): void {
		$screen = get_current_screen();
		if ( ! $screen || ATBDP_POST_TYPE !== $screen->post_type || ! in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
			return;
		}
		$post = 'post' === $screen->base ? get_post() : null;
		if ( $post && ! self::can_assist( $post ) ) {
			return;
		}
		self::render( 'admin', $post && 'auto-draft' !== $post->post_status ? (int) $post->ID : 0, 'edit' === $screen->base );
	}
	/**
	 * Mount supported text assistance on the native owner form.
	 *
	 * @return void
	 */
	public static function frontend(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$id = absint( get_query_var( 'atbdp_listing_id', 0 ) );
		if ( isset( $_GET['atbdp_listing_id'] ) ) {
			$id = absint( $_GET['atbdp_listing_id'] );
		}
		if ( $id && ! self::can_assist( get_post( $id ) ) ) {
			return;
		}
		self::render( 'owner', $id, false );
	}
	/**
	 * Enqueue the listing composer with installed directory context.
	 *
	 * @param string $surface Native admin or owner form surface.
	 * @param int    $id Native listing or operation identity.
	 * @param bool   $list_screen Whether this is the listing table.
	 * @return void
	 */
	private static function render( string $surface, int $id, bool $list_screen ): void {
		$paused = (bool) get_option( 'directorist_ai_pro_emergency_stop', false );
		if ( $paused && ( ! $list_screen || 'admin' !== $surface || ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$type = get_post_type_object( ATBDP_POST_TYPE );
		if ( ! $id && ( ! $type || ! current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) ) ) {
			return;
		}
		$profile          = 'admin' === $surface && current_user_can( 'manage_options' ) ? 'admin' : 'owner';
		$terms            = get_terms(
			array(
				'taxonomy'   => ATBDP_DIRECTORY_TYPE,
				'hide_empty' => false,
			)
		);
		$directories      = array();
		$field_map        = array();
		$fact_map         = array();
		$schema_map       = array();
		$native_only      = array();
		$term_eligibility = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$unavailable_native = array();
			$directories[]      = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
			$fields             = array();
			foreach ( ListingFormService::fields( $term->term_id, 'admin' === $profile ) as $logical => $props ) {
				$typed = \DirectoristAIPro\Listing\ListingFieldValues::contract( $props, (int) $term->term_id );
				if ( 'pricing' === $props['widget_name'] && $id && (int) directorist_get_listing_directory( $id ) === (int) $term->term_id && 'both' !== $typed['pricing']->mode ) {
					$pricing = \DirectoristAIPro\Listing\ListingPricingValues::read( $id );
					if ( '' !== ( 'price_unit' === $typed['pricing']->mode ? $pricing->range : $pricing->price ) ) {
						$unavailable_native[] = array( 'label' => __( 'Pricing', 'directorist-ai' ) );
						continue;
					}
				}
				if ( $typed['taxonomy'] && ! current_user_can( get_taxonomy( $typed['taxonomy'] )->cap->assign_terms ) ) {
					continue; }
				$fields[] = array_merge(
					array(
						'id'           => $logical,
						'name'         => $props['field_key'],
						'label'        => wp_strip_all_tags( ( $props['label'] ?? '' ) ? $props['label'] : $logical ),
						'widget'       => $props['widget_name'],
						'required'     => ! empty( $props['required'] ),
						'max'          => \DirectoristAIPro\Listing\ListingFieldValues::is_array( $props ) || in_array( $props['widget_name'], array( 'number', 'switch' ), true ) ? 0 : (int) ( $props['max'] ?? 0 ),
						'visibleOnNew' => \Directorist\Fields\Conditional_Logic::should_show_field( $props, array() ),
					),
					$typed
				);
			}
			$topics = array();
			foreach ( $fields as $field ) {
				if ( 'category' === $field['widget'] ) {
					$topics = array_column( $field['options'], 'label' ); }
			}
			$directories[ count( $directories ) - 1 ]['topics'] = $topics;
			$field_map[ $term->term_id ]                        = $fields;
			$fact_map[ $term->term_id ]                         = array_keys( ListingFormService::fact_fields( $term->term_id, 'admin' === $profile ) );
			$schema_map[ $term->term_id ]                       = ListingFormService::schema_hash( (int) $term->term_id );
			$native_only[ $term->term_id ]                      = array_merge( ListingFormService::native_only_fields( (int) $term->term_id, 'admin' === $profile ), $unavailable_native );
			$term_eligibility[ $term->term_id ]                 = array();
			foreach ( array(
				'categories' => array( 'category', ATBDP_CATEGORY ),
				'tags'       => array( 'tag', ATBDP_TAGS ),
			) as $logical => $native ) {
				$taxonomy = get_taxonomy( $native[1] );
				if ( 'admin' === $profile && $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) && directorist_get_listing_form_field( $term->term_id, $native[0] ) ) {
					$term_eligibility[ $term->term_id ][] = $logical;
				}
			}
		}
		$directory = $id ? (int) directorist_get_listing_directory( $id ) : 0;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only native listing-table filter context; no mutation or approval.
		if ( $list_screen && isset( $_GET['directory_type'] ) && is_string( $_GET['directory_type'] ) ) {
			$selected = filter_var( wp_unslash( $_GET['directory_type'] ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false !== $selected && in_array( $selected, array_column( $directories, 'id' ), true ) ) {
				$directory = (int) $selected;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$config   = array(
			'apiPath'                  => '/directorist-ai/v1',
			'apiUrl'                   => rest_url( 'directorist-ai/v1' ),
			'nonce'                    => wp_create_nonce( 'wp_rest' ),
			'profile'                  => $profile,
			'surface'                  => $surface,
			'userId'                   => get_current_user_id(),
			'currentAuthor'            => array(
				'id'   => get_current_user_id(),
				'name' => wp_strip_all_tags( wp_get_current_user()->display_name ),
			),
			'siteId'                   => get_current_blog_id(),
			'canImport'                => $list_screen && 'admin' === $surface && current_user_can( 'manage_options' ) && $type && current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ),
			'aiPaused'                 => $paused,
			'generationEnabled'        => ! $paused,
			'listingId'                => $id,
			'title'                    => $id ? get_the_title( $id ) : __( 'New listing', 'directorist-ai' ),
			'directoryId'              => $directory,
			'directories'              => $directories,
			'fields'                   => $field_map,
			'factFields'               => $fact_map,
			'schemaHashes'             => $schema_map,
			'nativeOnlyFields'         => $native_only,
			'sourceHash'               => $id ? ListingFormService::source_hash( $id ) : null,
			'listScreen'               => $list_screen,
			'editUrl'                  => admin_url( 'post.php' ),
			'connectionsUrl'           => add_query_arg(
				array(
					'post_type' => ATBDP_POST_TYPE,
					'page'      => 'directorist-ai',
					'tab'       => 'connections',
				),
				admin_url( 'edit.php' )
			),
			'usageUrl'                 => add_query_arg(
				array(
					'post_type' => ATBDP_POST_TYPE,
					'page'      => 'directorist-ai',
					'tab'       => 'usage',
				),
				admin_url( 'edit.php' )
			),
			'termFields'               => current_user_can( 'manage_options' ) && 'admin' === $surface ? array(
				'categories' => ATBDP_CATEGORY,
				'tags'       => ATBDP_TAGS,
			) : array(),
			'termEligibility'          => $term_eligibility,
			'imageGenerationAvailable' => \DirectoristAIPro\Domain\MediaSuggestionService::image_provider_available(),
		);
		$task_dir = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		if ( ! file_exists( $task_dir . 'listing-assistant.asset.php' ) ) {
			return;
		}
		$asset = require $task_dir . 'listing-assistant.asset.php';
		$url   = plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		wp_enqueue_script( 'directorist-ai-listing-assistant', $url . 'listing-assistant.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'directorist-ai-listing-assistant', $url . 'listing-assistant.css', array( 'wp-components' ), $asset['version'] );
		wp_set_script_translations( 'directorist-ai-listing-assistant', 'directorist-ai' );
		wp_print_styles( array( 'wp-components', 'directorist-ai-listing-assistant' ) );
		echo '<div id="directorist-ai-listing" data-directorist-ai-listing="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
	}
	/**
	 * Link the listing row to its native improvement composer.
	 *
	 * @param array $actions Existing native row actions.
	 * @param mixed $post Native listing post.
	 * @return array
	 */
	public static function row_actions( array $actions, $post ): array {
		if ( self::can_assist( $post ) ) {
			$actions['directorist_ai'] = '<a href="' . esc_url( add_query_arg( 'directorist_ai', '1', get_edit_post_link( $post->ID, 'raw' ) ) ) . '">' . esc_html__( 'Improve with AI', 'directorist-ai' ) . '</a>';
		}
		return $actions;
	}
	/**
	 * Link the owner dashboard to its native listing form.
	 *
	 * @param array $items Existing native dashboard actions.
	 * @param int   $id Native listing or operation identity.
	 * @return array
	 */
	public static function owner_action( array $items, int $id ): array {
		if ( self::can_assist( get_post( $id ) ) ) {
			$items['directorist_ai'] = array(
				'class'     => '',
				'data_attr' => '',
				'link'      => add_query_arg( 'directorist_ai', '1', \ATBDP_Permalink::get_edit_listing_page_link( $id ) ),
				'icon'      => '',
				'label'     => __( 'Improve with AI', 'directorist-ai' ),
			);
		}
		return $items;
	}
}
