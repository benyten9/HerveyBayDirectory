<?php

namespace SEOPressPro\Services\InternalLinking;

class RenderMetaboxInternalLinking {

	public function render( $id ) {
		$post    = get_post( $id );
		$content = seopress_pro_get_service( 'SignificantKeywords' )->getFullContentByPost( $post );

		$keywords = seopress_pro_get_service( 'SignificantKeywords' )->retrieveSignificantKeywords( $content );
		$data     = seopress_pro_get_service( 'SignificantKeywords' )->computeKeywords( $keywords, $content, $id );

		?>

		<script>
			document.addEventListener('DOMContentLoaded', function(){
				const $ = jQuery;
				$(".seopress-copy-clipboard").on("click", function(){
					const value = $(this).data("copy-value");
					const $temp = $("<input>");
					$("body").append($temp);
					$temp.val(value).select();
					document.execCommand("copy");
					$temp.remove();

					$("#seopress-link-copied").fadeIn(200).delay(2000).fadeOut(200);
				});
			})

		</script>

		<p class="description-alt desc-fb">
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				role="img"
				aria-hidden="true"
				focusable="false"
			>
				<path d="M12 15.8c-3.7 0-6.8-3-6.8-6.8s3-6.8 6.8-6.8c3.7 0 6.8 3 6.8 6.8s-3.1 6.8-6.8 6.8zm0-12C9.1 3.8 6.8 6.1 6.8 9s2.4 5.2 5.2 5.2c2.9 0 5.2-2.4 5.2-5.2S14.9 3.8 12 3.8zM8 17.5h8V19H8zM10 20.5h4V22h-4z"></path>
			</svg>
			<?php esc_html_e( 'Internal links are important for SEO and user experience. Always try to link your content together, with quality link anchors.', 'wp-seopress-pro' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Here is a list of articles related to your content, sorted by relevance, that you should link to.', 'wp-seopress-pro' ); ?>
		</p>
		<div style="display:none;" id="seopress-link-copied">
			<div class="seopress-notice is-info">
				<?php esc_html_e( 'Link copied in the clipboard', 'wp-seopress-pro' ); ?>
			</div>
		</div>
		<?php if ( empty( $data ) ) : ?>
			<?php esc_html_e( 'No suggestion of internal links.', 'wp-seopress-pro' ); ?>
		<?php endif; ?>

		<?php foreach ( $data as $key => $item ) : ?>
			<div>
				<p style="margin-bottom:2px"><?php esc_html_e( 'Matching word:', 'wp-seopress-pro' ); ?> <strong><?php echo esc_html( $item['word'] ); ?></strong></p>
				<div style="display: flex; margin-bottom: 15px; align-items: center;">
					<span data-copy-value="<?php echo esc_url( $item['permalink'] ); ?>" class="dashicons dashicons-admin-page seopress-copy-clipboard" style="padding:5px; background: var(--borderColorLight40); border-radius: 4px; width:30px; height:30px; display:flex; align-items:center; line-height:30px; justify-content:center; cursor:pointer;"></span>
					<a
						href=<?php echo esc_url( $item['permalink'] ); ?>
						title="
						<?php
						esc_attr_e(
							'Open this link in a new window',
							'wp-seopress-pro'
						);
						?>
						"
						target="_blank"
						style="margin-right:10px; margin-left:10px; line-height: 30px;"
					>
						<?php echo esc_html( $item['title'] ); ?>
					</a>
					<span class="dashicons dashicons-external"></span>
					<a
						href="<?php echo esc_url( $item['edit_link'] ); ?>"
						target="_blank"
						style="text-decoration: none;"
						title="
						<?php
						esc_attr_e(
							'Edit this link in a new window',
							'wp-seopress-pro'
						);
						?>
						"
					>
						<span
							class="dashicons dashicons-edit"
						></span>
					</a>
				</div>
			</div>
			<?php
		endforeach;
	}
}
