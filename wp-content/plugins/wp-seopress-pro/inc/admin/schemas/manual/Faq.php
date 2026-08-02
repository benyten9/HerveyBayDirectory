<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO FAQ Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_faq( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$seopress_pro_rich_snippets_faq = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_faq'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_faq'] : array();

	// SEOPress < 3.9
	// Double dimension required as a result of migration 3.9
	$seopress_pro_rich_snippets_faq = array( '0' => $seopress_pro_rich_snippets_faq );
	?>
<div class="wrap-rich-snippets-item wrap-rich-snippets-faq">
	<div class="seopress-notice is-warning">
		<p>
			<strong><?php esc_html_e( 'Deprecated:', 'wp-seopress-pro' ); ?></strong>
			<?php esc_html_e( 'Google has limited FAQ rich results to government and health authority websites only (August 2023). This schema still works for other search engines and LLMs.', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'Mark up your Frequently Asked Questions page with JSON-LD to try to get the position 0 in search results. ', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<?php
	// Init $seopress_faq array if empty
	if ( empty( $seopress_pro_rich_snippets_faq ) ) {
		$seopress_pro_rich_snippets_faq = array( '0' => array( '' ) );
	}
	$total = count( $seopress_pro_rich_snippets_faq[0] );

	if ( $total > 0 ) {
		?>
	<div id="wrap-faq" data-count="<?php echo absint( $total ); ?>">
		<?php
		foreach ( $seopress_pro_rich_snippets_faq[0] as $key => $value ) {
			$num            = $key + 1;
			$check_question = isset( $seopress_pro_rich_snippets_faq[0][ $key ]['question'] ) ? $seopress_pro_rich_snippets_faq[0][ $key ]['question'] : null;
			$check_answer   = isset( $seopress_pro_rich_snippets_faq[0][ $key ]['answer'] ) ? $seopress_pro_rich_snippets_faq[0][ $key ]['answer'] : null;
			?>
		<div class="faq">
			<h3 class="accordion-section-title" tabindex="0">
				<?php if ( empty( $check_question ) ) { ?>
					<span style="color:red">
					<?php esc_html_e( 'Empty Question', 'wp-seopress-pro' ); ?>
					</span>
					<?php
				} else {
					echo esc_attr( $check_question );
				}

				if ( empty( $check_answer ) ) {
					echo ' - ';
					?>
					<span style="color:red">
						<?php esc_html_e( 'Empty Answer', 'wp-seopress-pro' ); ?>
					</span>
					<?php
				}
				?>
			</h3>
			<div class="accordion-section-content">
				<div class="inside">
					<p>
						<label
							for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][<?php echo esc_attr( $key ); ?>][question]">
							<?php esc_html_e( 'Question (required)', 'wp-seopress-pro' ); ?>
						</label>
						<input
							id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][<?php echo esc_attr( $key ); ?>][question]"
							type="text"
							name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][<?php echo esc_attr( $key ); ?>][question]"
							placeholder="<?php esc_html_e( 'Enter your question', 'wp-seopress-pro' ); ?>"
							aria-label="<?php esc_html_e( 'Question', 'wp-seopress-pro' ); ?>"
							value="<?php echo esc_attr( $check_question ); ?>" />
					</p>
					<p>
						<label
							for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][<?php echo esc_attr( $key ); ?>][answer]">
							<?php esc_html_e( 'Answer (required)', 'wp-seopress-pro' ); ?>
						</label>
						<textarea
							id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][<?php echo esc_attr( $key ); ?>][answer]"
							name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][<?php echo esc_attr( $key ); ?>][answer]"
							placeholder="<?php esc_html_e( 'Enter your answer', 'wp-seopress-pro' ); ?>"
							aria-label="<?php esc_html_e( 'Answer', 'wp-seopress-pro' ); ?>"
							rows="8"><?php echo esc_textarea( $check_answer ); ?></textarea>
					</p>

					<p>
						<a href="#" class="remove-faq button">
							<?php esc_html_e( 'Remove question', 'wp-seopress-pro' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
			<?php
		}
		?>
	</div>
		<?php
	} else {
		?>
	<div id="wrap-faq" data-count="1">
		<div class="faq">
			<h3 class="accordion-section-title" tabindex="0">
				<?php esc_html_e( 'Question', 'wp-seopress-pro' ); ?>
			</h3>
			<div class="accordion-section-content">
				<div class="inside">
					<p>
						<label
							for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][0][question]">
							<?php esc_html_e( 'Question (required)', 'wp-seopress-pro' ); ?>
						</label>
						<input
							id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][0][question]"
							type="text"
							name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][0][question]"
							placeholder="<?php esc_html_e( 'Enter your question', 'wp-seopress-pro' ); ?>"
							aria-label="<?php esc_html_e( 'Question', 'wp-seopress-pro' ); ?>"
							value="" />
					</p>
					<p>
						<label
							for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][0][answer]">
							<?php esc_html_e( 'Answer (required)', 'wp-seopress-pro' ); ?>
						</label>
						<textarea
							id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][0][answer]"
							name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_faq][0][answer]"
							placeholder="<?php esc_html_e( 'Enter your answer', 'wp-seopress-pro' ); ?>"
							aria-label="<?php esc_html_e( 'Answer', 'wp-seopress-pro' ); ?>"
							rows="8"></textarea>
					</p>

					<p>
						<a href="#" class="remove-faq button">
							<?php esc_html_e( 'Remove question', 'wp-seopress-pro' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
	</div>
	<?php } ?>
	<p>
		<a href="#" id="add-faq" class="add-faq <?php echo esc_attr( seopress_btn_secondary_classes() ); ?>">
			<?php esc_html_e( 'Add question', 'wp-seopress-pro' ); ?>
		</a>
	</p>
</div>
	<?php
}
