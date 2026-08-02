<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options AI.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Generate image metadata (alt text, caption, description) when sending an image to WP
 *
 * @param string $post_ID The post ID.
 *
 * @return void
 */
function seopress_ai_alt_text_upload( $post_ID ) {
	// Restrict automatic generation to allowed roles (Advanced > Security).
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( null, 'upload_files', 'upload' ) ) {
		return;
	}

	if ( seopress_pro_get_service( 'OptionPro' )->getAIOpenaiAltText() !== '1' ) {
		return;
	}

	if ( ! isset( $post_ID ) ) {
		return;
	}

	if ( false === wp_attachment_is_image( $post_ID ) ) {
		return;
	}

	// Check if the upload is from a Gravity Forms request.
	if ( isset( $_POST['gform_submit'] ) || isset( $_POST['gform_unique_id'] ) ) {
		return;
	}

	$language = function_exists( 'seopress_get_current_lang' ) ? seopress_get_current_lang() : get_locale();

	// Build the list of fields to generate from the per-field options. A null
	// value means the option was never saved (legacy install): default to
	// enabled so existing sites keep generating all three fields. An empty
	// string means the user explicitly disabled that field.
	$option_pro  = seopress_pro_get_service( 'OptionPro' );
	$field_flags = array(
		'alt_text'    => $option_pro->getAIGenFieldAltText(),
		'caption'     => $option_pro->getAIGenFieldCaption(),
		'description' => $option_pro->getAIGenFieldDescription(),
	);

	$fields = array();
	foreach ( $field_flags as $field => $flag ) {
		if ( null === $flag || '1' === $flag ) {
			$fields[] = $field;
		}
	}

	// Every field disabled: nothing to generate on upload.
	if ( empty( $fields ) ) {
		return;
	}

	// generateImgAltText generates and saves only the requested fields.
	seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_ID, 'image_meta', $language, true, null, $fields );
}
add_action( 'add_attachment', 'seopress_ai_alt_text_upload', 20 );

/**
 * Add AI button to media modal
 *
 * @param array  $form_fields The form fields.
 * @param object $post The post.
 *
 * @return array $form_fields
 */
function seopress_ai_alt_text_media_modal( $form_fields, $post ) {
	// Hide the media-modal AI button for roles not allowed to use AI.
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( isset( $post->ID ) ? (int) $post->ID : null, 'edit_post', 'image_meta' ) ) {
		return $form_fields;
	}

	$language = function_exists( 'seopress_get_current_lang' ) ? seopress_get_current_lang() : get_locale();

	$form_fields['seopress_ai_generate_image_meta'] = array(
		'label' => esc_html__( 'AI', 'wp-seopress-pro' ),
		'input' => 'html',
		'html'  => sprintf(
			'<div id="seopress-ai-generate-image-meta" style="display:flex;align-items:center;gap:8px;">
				<span class="spinner" style="float:none;margin:0;"></span>
				<button
					class="seopress-ai-generate-image-meta seopress-ai-icon-button"
					type="button"
					title="%s"
					style="border:none;background:transparent;padding:6px 12px;min-width:auto;cursor:pointer;transition:background-color 0.2s ease-in-out, border-radius 0.2s ease-in-out;"
					onmouseover="this.style.backgroundColor=\'#f0f0f1\';this.style.borderRadius=\'4px\';this.querySelector(\'svg\').style.transform=\'rotate(-15deg) scale(1.1)\';"
					onmouseout="this.style.backgroundColor=\'transparent\';this.style.borderRadius=\'0\';this.querySelector(\'svg\').style.transform=\'rotate(0deg) scale(1)\';"
					onclick="SEOPRESSMedia.generateImageMeta(%d, \'%s\')"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;transition:transform 0.2s ease-in-out;">
						<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/>
					</svg>
				</button>
				<span style="font-size:12px;color:#646970;">%s</span>
				<a href="%s" style="font-size:12px;color:#646970;">%s</a>
				<div class="seopress-error" style="display:none;color:#d63638;font-size:12px;"></div>
			</div>',
			esc_attr__( 'Generate alt text, caption, and description with AI', 'wp-seopress-pro' ),
			(int) $post->ID,
			(string) $language,
			esc_html__( 'Alt, Caption, Description', 'wp-seopress-pro' ),
			esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ) ),
			esc_html__( 'Configure AI', 'wp-seopress-pro' )
		),
	);
	return $form_fields;
}
add_filter( 'attachment_fields_to_edit', 'seopress_ai_alt_text_media_modal', 10, 2 );

/**
 * Gate the metabox AI generation buttons by user role (Advanced > Security).
 *
 * Hooks the free plugin's OPTIONS.AI localization flag so the icons are hidden
 * for roles not allowed to use AI. The server-side endpoints enforce the same
 * gate, so this only removes the UI affordance.
 *
 * @param bool     $enabled Whether AI buttons are enabled.
 * @param int|null $post_id The current post ID, if any.
 *
 * @return bool
 */
function seopress_ai_metabox_options_ai( $enabled, $post_id = null ) {
	if ( ! $enabled ) {
		return $enabled;
	}

	if ( ! function_exists( 'seopress_ai_generation_check' ) ) {
		return $enabled;
	}

	return seopress_ai_generation_check( $post_id ? (int) $post_id : null, $post_id ? 'edit_post' : 'edit_posts', 'metabox' );
}
add_filter( 'seopress_metabox_options_ai', 'seopress_ai_metabox_options_ai', 10, 2 );

/**
 * Automatically generate the meta title and/or meta description with AI when a
 * post is published, only for the fields the user left empty.
 *
 * Hooked on `wp_after_insert_post`, which fires once the post meta from the
 * request has been persisted (Block editor / REST and Classic editor alike),
 * so the "is this field empty?" check reflects what the user submitted.
 *
 * Generation is restricted to the transition INTO the published status (a new
 * post published, or draft/pending -> publish). Saving an already-published
 * post never re-triggers a generation, so a single AI call happens at most once
 * per publish, and a failed generation is not retried on every subsequent edit.
 *
 * The heavy lifting (provider selection, language resolution, prompt building,
 * saving) is delegated to the shared Completions service with autosave = true,
 * so this behaves exactly like the metabox / bulk generation, minus the click.
 *
 * @param int           $post_id           The post ID.
 * @param \WP_Post      $post              The post object.
 * @param bool          $update            Whether this is an update to an existing post.
 * @param \WP_Post|null $post_before       The post before the save, or null for a new post.
 * @param bool          $bypass_role_check Skip the interactive AI role gate. Used for the
 *                                         WP-Cron scheduled-publish path, where there is no
 *                                         current user and the action is authorized by the
 *                                         site-wide setting rather than a logged-in editor.
 *
 * @return void
 */
function seopress_ai_auto_generate_metas( $post_id, $post = null, $update = false, $post_before = null, $bypass_role_check = false ) {
	if ( ! $post instanceof WP_Post ) {
		$post = get_post( $post_id );
	}
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$post_id = (int) $post->ID;

	// Only generate for published content.
	if ( 'publish' !== $post->post_status ) {
		return;
	}

	// Only on the transition into publish: if the post was already published
	// before this save, do not regenerate on later edits.
	if ( $post_before instanceof WP_Post && 'publish' === $post_before->post_status ) {
		return;
	}

	// Never act on revisions or autosaves.
	if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	// Read the feature settings; bail early when nothing is enabled.
	$option_pro = seopress_pro_get_service( 'OptionPro' );
	$gen_title  = '1' === $option_pro->getAIAutoGenTitle();
	$gen_desc   = '1' === $option_pro->getAIAutoGenDesc();
	if ( ! $gen_title && ! $gen_desc ) {
		return;
	}

	// Only for public, SEO-relevant post types (excludes attachments and
	// internal CPTs). We deliberately do not run the `seopress_metaboxe_seo`
	// filter here: its callbacks assume an editor page context with a set
	// global `$post`, which is not the case during init / save / REST.
	$post_types = seopress_get_service( 'WordPressData' )->getPostTypes();
	if ( empty( $post_types ) || ! array_key_exists( $post->post_type, (array) $post_types ) ) {
		return;
	}

	// Respect the AI role restrictions (Advanced > Security). Skipped for the
	// WP-Cron scheduled-publish path, where there is no current user to check
	// and the action is authorized by the site-wide setting.
	if ( ! $bypass_role_check && function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'auto_publish' ) ) {
		return;
	}

	// Only generate the fields the user actually left empty.
	$need_title = $gen_title && '' === trim( (string) get_post_meta( $post_id, '_seopress_titles_title', true ) );
	$need_desc  = $gen_desc && '' === trim( (string) get_post_meta( $post_id, '_seopress_titles_desc', true ) );
	if ( ! $need_title && ! $need_desc ) {
		return;
	}

	// Map the empty fields to the Completions $meta argument.
	if ( $need_title && $need_desc ) {
		$meta = '';
	} elseif ( $need_title ) {
		$meta = 'title';
	} else {
		$meta = 'desc';
	}

	$language = function_exists( 'seopress_get_current_lang' ) ? seopress_get_current_lang() : get_locale();

	// autosave = true writes _seopress_titles_title / _seopress_titles_desc directly.
	seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, $meta, $language, true );
}
add_action( 'wp_after_insert_post', 'seopress_ai_auto_generate_metas', 20, 4 );

/**
 * Cover scheduled posts going live via WP-Cron.
 *
 * A scheduled post is published by `wp_publish_post()`, which fires the
 * `future_to_publish` transition but NOT `wp_after_insert_post`, so the main
 * handler would miss it. We only act under WP-Cron: an editor-driven
 * `future -> publish` also fires this transition, but is already handled by
 * `wp_after_insert_post` once the request meta is saved, so restricting to cron
 * avoids generating twice (and avoids the block-editor meta-timing issue).
 *
 * @param \WP_Post $post The post being published.
 *
 * @return void
 */
function seopress_ai_auto_generate_metas_scheduled( $post ) {
	if ( ! wp_doing_cron() ) {
		return;
	}

	if ( $post instanceof WP_Post ) {
		// No current user under cron: bypass the interactive role gate.
		seopress_ai_auto_generate_metas( $post->ID, $post, true, null, true );
	}
}
add_action( 'future_to_publish', 'seopress_ai_auto_generate_metas_scheduled', 20, 1 );
