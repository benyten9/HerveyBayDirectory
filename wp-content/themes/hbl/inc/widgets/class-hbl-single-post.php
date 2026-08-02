<?php
/**
 * HBL Single Post Widget
 *
 * Single post layout: 700px reading column, sticky sidebar with categories,
 * and AJAX load-more related posts.
 *
 * @package HBL
 * @since 1.2.963
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Single_Post extends Widget_Base {

	public function get_name()       { return 'hbl-single-post'; }
	public function get_title()      { return esc_html__( 'HB Single Post', 'hbl' ); }
	public function get_icon()       { return 'eicon-single-post'; }
	public function get_categories() { return array( 'hbl' ); }

	// ── AJAX: related posts load-more ─────────────────────────────────────────

	public static function ajax_related() {
		check_ajax_referer( 'hbl_related_nonce', 'nonce' );

		$post_id    = isset( $_POST['post_id'] )    ? absint( $_POST['post_id'] )                                                      : 0;
		$page       = isset( $_POST['page'] )       ? max( 1, absint( $_POST['page'] ) )                                               : 1;
		$count      = isset( $_POST['count'] )      ? max( 1, absint( $_POST['count'] ) )                                              : 3;
		$related_by = isset( $_POST['related_by'] ) ? sanitize_text_field( wp_unslash( $_POST['related_by'] ) )                        : 'category';

		if ( ! $post_id ) {
			wp_send_json_error();
		}

		$args = array(
			'post_type'      => 'post',
			'posts_per_page' => $count,
			'paged'          => $page,
			'post__not_in'   => array( $post_id ),
			'post_status'    => 'publish',
		);

		switch ( $related_by ) {
			case 'category':
				$cats = wp_get_post_categories( $post_id );
				if ( $cats ) $args['category__in'] = $cats;
				break;
			case 'tag':
				$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
				if ( $tags ) $args['tag__in'] = $tags;
				break;
			case 'author':
				$args['author'] = get_post_field( 'post_author', $post_id );
				break;
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
		}

		$q = new \WP_Query( $args );

		ob_start();
		while ( $q->have_posts() ) {
			$q->the_post();
			self::render_related_card();
		}
		wp_reset_postdata();
		$html = ob_get_clean();

		wp_send_json_success( array(
			'html'      => $html,
			'max_pages' => (int) $q->max_num_pages,
			'page'      => $page,
		) );
	}

	// ── Related card renderer ─────────────────────────────────────────────────

	public static function render_related_card() {
		$cats    = get_the_category();
		$cat     = ! empty( $cats ) ? $cats[0]->name : '';
		$words   = str_word_count( strip_tags( get_the_content() ) );
		$mins    = max( 1, (int) ceil( $words / 200 ) );
		?>
		<article class="hbl-related-card">
			<a href="<?php the_permalink(); ?>" class="hbl-related-card-img-wrap">
				<?php if ( has_post_thumbnail() ) : ?>
					<?php the_post_thumbnail( 'medium_large', array( 'class' => 'hbl-related-card-img', 'loading' => 'lazy' ) ); ?>
				<?php else : ?>
					<div class="hbl-related-card-img-placeholder"></div>
				<?php endif; ?>
				<?php if ( $cat ) : ?>
					<span class="hbl-related-card-cat"><?php echo esc_html( $cat ); ?></span>
				<?php endif; ?>
			</a>
			<div class="hbl-related-card-body">
				<div class="hbl-related-card-meta">
					<time><?php echo get_the_date( 'M j, Y' ); ?></time>
					<span><?php printf( _n( '%d min read', '%d min read', $mins, 'hbl' ), $mins ); ?></span>
				</div>
				<h3 class="hbl-related-card-title">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</h3>
				<p class="hbl-related-card-excerpt"><?php echo wp_trim_words( get_the_excerpt(), 18 ); ?></p>
				<a href="<?php the_permalink(); ?>" class="hbl-related-card-link">
					<?php esc_html_e( 'Read Article', 'hbl' ); ?>
					<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M1 11L11 1M11 1H1M11 1V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
			</div>
		</article>
		<?php
	}

	// ── Controls ──────────────────────────────────────────────────────────────

	protected function register_controls() {

		// Post Settings
		$this->start_controls_section( 'section_post_settings', array( 'label' => esc_html__( 'Post Settings', 'hbl' ) ) );
		$this->add_control( 'post_id', array( 'label' => esc_html__( 'Post ID', 'hbl' ), 'type' => Controls_Manager::NUMBER, 'description' => esc_html__( 'Leave empty to use current post', 'hbl' ), 'default' => '' ) );
		$this->add_control( 'show_featured_image', array( 'label' => esc_html__( 'Show Featured Image', 'hbl' ), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_meta',           array( 'label' => esc_html__( 'Show Post Meta', 'hbl' ),      'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
$this->add_control( 'show_date',           array( 'label' => esc_html__( 'Show Date', 'hbl' ),           'type' => Controls_Manager::SWITCHER, 'default' => 'yes', 'condition' => array( 'show_meta' => 'yes' ) ) );
		$this->add_control( 'show_categories',     array( 'label' => esc_html__( 'Show Categories', 'hbl' ),     'type' => Controls_Manager::SWITCHER, 'default' => 'yes', 'condition' => array( 'show_meta' => 'yes' ) ) );
		$this->add_control( 'show_reading_time',   array( 'label' => esc_html__( 'Show Reading Time', 'hbl' ),   'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_tags',           array( 'label' => esc_html__( 'Show Tags', 'hbl' ),           'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_social_share',   array( 'label' => esc_html__( 'Show Social Share', 'hbl' ),   'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_sidebar',        array( 'label' => esc_html__( 'Show Sidebar', 'hbl' ),        'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->end_controls_section();

		// Related Posts
		$this->start_controls_section( 'section_related_posts', array( 'label' => esc_html__( 'Related Posts', 'hbl' ) ) );
		$this->add_control( 'show_related_posts',   array( 'label' => esc_html__( 'Show Related Posts', 'hbl' ),      'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'related_posts_title',  array( 'label' => esc_html__( 'Section Title', 'hbl' ),           'type' => Controls_Manager::TEXT, 'default' => 'Related Articles', 'label_block' => true, 'condition' => array( 'show_related_posts' => 'yes' ) ) );
		$this->add_control( 'related_posts_count',  array( 'label' => esc_html__( 'Posts per Load', 'hbl' ),          'type' => Controls_Manager::NUMBER, 'default' => 3, 'min' => 1, 'max' => 6, 'condition' => array( 'show_related_posts' => 'yes' ) ) );
		$this->add_control( 'related_posts_by',     array( 'label' => esc_html__( 'Related Posts By', 'hbl' ),        'type' => Controls_Manager::SELECT, 'default' => 'category', 'options' => array( 'category' => 'Category', 'tag' => 'Tag', 'recent' => 'Recent' ), 'condition' => array( 'show_related_posts' => 'yes' ) ) );
		$this->add_control( 'load_more_text',       array( 'label' => esc_html__( 'Load More Text', 'hbl' ),          'type' => Controls_Manager::TEXT, 'default' => 'Load More Articles', 'condition' => array( 'show_related_posts' => 'yes' ) ) );
		$this->end_controls_section();

		// Style: Colors
		$this->start_controls_section( 'section_style', array( 'label' => esc_html__( 'Colors', 'hbl' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'accent_color', array(
			'label'     => esc_html__( 'Accent Color', 'hbl' ),
			'type'      => Controls_Manager::COLOR,
			'default'   => '#008080',
			'selectors' => array(
				'{{WRAPPER}} .hbl-post-cat-badge'           => 'background-color: {{VALUE}};',
				'{{WRAPPER}} .hbl-related-card-cat'         => 'background-color: {{VALUE}};',
				'{{WRAPPER}} .hbl-post-sidebar-cat.active'  => 'color: {{VALUE}}; border-left-color: {{VALUE}};',
				'{{WRAPPER}} .hbl-related-card-link'        => 'color: {{VALUE}};',
				'{{WRAPPER}} .hbl-post-load-more-btn'       => 'border-color: {{VALUE}}; color: {{VALUE}};',
				'{{WRAPPER}} .hbl-post-load-more-btn:hover' => 'background-color: {{VALUE}};',
				'{{WRAPPER}} .hbl-share-btn:hover'          => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
			),
		) );
		$this->add_control( 'post_title_color', array( 'label' => esc_html__( 'Post Title Color', 'hbl' ), 'type' => Controls_Manager::COLOR, 'default' => '#111111', 'selectors' => array( '{{WRAPPER}} .hbl-post-title' => 'color: {{VALUE}};' ) ) );
		$this->add_control( 'post_content_color', array( 'label' => esc_html__( 'Content Color', 'hbl' ), 'type' => Controls_Manager::COLOR, 'default' => '#333333', 'selectors' => array( '{{WRAPPER}} .hbl-single-post-content' => 'color: {{VALUE}};' ) ) );
		$this->end_controls_section();

		// Style: Typography
		$this->start_controls_section( 'section_typography', array( 'label' => esc_html__( 'Typography', 'hbl' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_group_control( Group_Control_Typography::get_type(), array(
			'name'           => 'post_title_typography',
			'label'          => esc_html__( 'Post Title', 'hbl' ),
			'selector'       => '{{WRAPPER}} .hbl-post-title',
			'fields_options' => array( 'typography' => array( 'default' => 'custom' ), 'font_family' => array( 'default' => 'Poppins' ), 'font_weight' => array( 'default' => '700' ), 'font_size' => array( 'default' => array( 'unit' => 'px', 'size' => 42 ) ), 'line_height' => array( 'default' => array( 'unit' => 'em', 'size' => 1.25 ) ) ),
		) );
		$this->add_group_control( Group_Control_Typography::get_type(), array(
			'name'           => 'post_content_typography',
			'label'          => esc_html__( 'Post Content', 'hbl' ),
			'selector'       => '{{WRAPPER}} .hbl-single-post-content',
			'fields_options' => array( 'typography' => array( 'default' => 'custom' ), 'font_family' => array( 'default' => 'Poppins' ), 'font_size' => array( 'default' => array( 'unit' => 'px', 'size' => 17 ) ), 'line_height' => array( 'default' => array( 'unit' => 'em', 'size' => 1.8 ) ) ),
		) );
		$this->end_controls_section();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function reading_time( $content ) {
		return max( 1, (int) ceil( str_word_count( strip_tags( $content ) ) / 200 ) );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	protected function render() {
		static $styles_printed = false;

		$s       = $this->get_settings_for_display();
		$id      = $this->get_id();
		$post_id = ! empty( $s['post_id'] ) ? (int) $s['post_id'] : get_the_ID();

		if ( ! $post_id ) return;
		$post = get_post( $post_id );
		if ( ! $post ) return;

		setup_postdata( $post );

		$reading_time  = $this->reading_time( $post->post_content );
		$categories    = get_the_category( $post_id );
		$primary_cat   = ! empty( $categories ) ? $categories[0] : null;
		$all_cats      = get_categories( array( 'hide_empty' => true ) );
		$tags          = get_the_tags( $post_id );
		$post_url      = get_permalink( $post_id );
		$post_title_e  = rawurlencode( get_the_title( $post_id ) );

		$rel_count     = ! empty( $s['related_posts_count'] ) ? absint( $s['related_posts_count'] ) : 3;
		$rel_by        = ! empty( $s['related_posts_by'] )    ? $s['related_posts_by']              : 'category';
		$lm_text       = ! empty( $s['load_more_text'] )      ? $s['load_more_text']                : 'Load More Articles';
		$show_sidebar  = ! empty( $s['show_sidebar'] ) && 'yes' === $s['show_sidebar'];

		// Initial related posts query
		$rel_args = array(
			'post_type'      => 'post',
			'posts_per_page' => $rel_count,
			'paged'          => 1,
			'post__not_in'   => array( $post_id ),
			'post_status'    => 'publish',
		);
		if ( 'category' === $rel_by && $categories ) {
			$rel_args['category__in'] = wp_list_pluck( $categories, 'term_id' );
		} elseif ( 'tag' === $rel_by && $tags ) {
			$rel_args['tag__in'] = wp_list_pluck( $tags, 'term_id' );
		} else {
			$rel_args['orderby'] = 'date';
			$rel_args['order']   = 'DESC';
		}
		$rel_query = new \WP_Query( $rel_args );

		if ( ! $styles_printed ) {
			$styles_printed = true;
			$this->print_styles();
		}
		?>
		<div class="hbl-single-post-widget">

			<?php // ── Featured image ────────────────────────────────────────── ?>
			<?php if ( 'yes' === $s['show_featured_image'] && has_post_thumbnail( $post_id ) ) : ?>
				<div class="hbl-post-hero">
					<?php echo get_the_post_thumbnail( $post_id, 'full', array( 'class' => 'hbl-post-hero-img' ) ); ?>
				</div>
			<?php endif; ?>

			<?php // ── Article header ────────────────────────────────────────── ?>
			<header class="hbl-post-header">
				<?php if ( $primary_cat ) : ?>
					<a href="<?php echo esc_url( get_category_link( $primary_cat->term_id ) ); ?>"
					   class="hbl-post-cat-badge">
						<?php echo esc_html( $primary_cat->name ); ?>
					</a>
				<?php endif; ?>

				<h1 class="hbl-post-title"><?php echo wp_kses_post( get_the_title( $post_id ) ); ?></h1>

				<?php if ( 'yes' === $s['show_meta'] ) : ?>
					<div class="hbl-post-meta">
<?php if ( 'yes' === $s['show_date'] ) : ?>
							<time class="hbl-post-date"><?php echo esc_html( get_the_date( 'F j, Y', $post_id ) ); ?></time>
							<span class="hbl-post-meta-sep">·</span>
						<?php endif; ?>
						<?php if ( 'yes' === $s['show_reading_time'] ) : ?>
							<span class="hbl-post-reading-time">
								<?php printf( _n( '%d min read', '%d min read', $reading_time, 'hbl' ), $reading_time ); ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</header>

			<?php // ── Article + Sidebar layout ──────────────────────────────── ?>
			<div class="hbl-post-layout<?php echo $show_sidebar ? ' hbl-post-has-sidebar' : ''; ?>">

				<?php // Article column ?>
				<article class="hbl-post-main">
					<div class="hbl-single-post-content">
						<?php echo apply_filters( 'the_content', $post->post_content ); ?>
					</div>

					<?php // Tags + Share ?>
					<footer class="hbl-post-footer">
						<?php if ( 'yes' === $s['show_tags'] && ! empty( $tags ) ) : ?>
							<div class="hbl-post-tags">
								<span class="hbl-post-tags-label"><?php esc_html_e( 'Tags:', 'hbl' ); ?></span>
								<?php foreach ( $tags as $tag ) : ?>
									<a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>" class="hbl-post-tag">
										#<?php echo esc_html( $tag->name ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $s['show_social_share'] ) : ?>
							<div class="hbl-post-share">
								<span class="hbl-post-share-label"><?php esc_html_e( 'Share:', 'hbl' ); ?></span>
								<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $post_url ); ?>" target="_blank" rel="noopener" class="hbl-share-btn hbl-share-fb" title="Facebook">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
								</a>
								<a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $post_url ); ?>&text=<?php echo $post_title_e; ?>" target="_blank" rel="noopener" class="hbl-share-btn hbl-share-tw" title="Twitter / X">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
								</a>
								<a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo rawurlencode( $post_url ); ?>" target="_blank" rel="noopener" class="hbl-share-btn hbl-share-li" title="LinkedIn">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6zM2 9h4v12H2z"/><circle cx="4" cy="4" r="2"/></svg>
								</a>
								<button class="hbl-share-btn hbl-share-copy" title="<?php esc_attr_e( 'Copy link', 'hbl' ); ?>" data-url="<?php echo esc_attr( $post_url ); ?>">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
								</button>
							</div>
						<?php endif; ?>
					</footer>
				</article>

				<?php if ( $show_sidebar ) : ?>
				<aside class="hbl-post-sidebar">
					<div class="hbl-post-sidebar-sticky">

<?php // Categories ?>
						<?php if ( ! empty( $all_cats ) ) : ?>
							<div class="hbl-sidebar-section">
								<p class="hbl-sidebar-heading"><?php esc_html_e( 'Categories', 'hbl' ); ?></p>
								<ul class="hbl-sidebar-cat-list">
									<?php foreach ( $all_cats as $cat ) :
										$is_active = $primary_cat && $primary_cat->term_id === $cat->term_id;
									?>
										<li>
											<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>"
											   class="hbl-post-sidebar-cat<?php echo $is_active ? ' active' : ''; ?>">
												<?php echo esc_html( $cat->name ); ?>
												<span class="hbl-sidebar-cat-count"><?php echo esc_html( $cat->count ); ?></span>
											</a>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php // Tags ?>
						<?php if ( 'yes' === $s['show_tags'] && ! empty( $tags ) ) : ?>
							<div class="hbl-sidebar-section">
								<p class="hbl-sidebar-heading"><?php esc_html_e( 'Tags', 'hbl' ); ?></p>
								<div class="hbl-sidebar-tags">
									<?php foreach ( $tags as $tag ) : ?>
										<a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>" class="hbl-sidebar-tag">
											#<?php echo esc_html( $tag->name ); ?>
										</a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

					</div>
				</aside>
				<?php endif; ?>

			</div><!-- .hbl-post-layout -->

			<?php // ── Related posts ─────────────────────────────────────────── ?>
			<?php if ( 'yes' === $s['show_related_posts'] && $rel_query->have_posts() ) : ?>
				<section class="hbl-related-section">
					<?php if ( ! empty( $s['related_posts_title'] ) ) : ?>
						<h2 class="hbl-related-section-title"><?php echo esc_html( $s['related_posts_title'] ); ?></h2>
					<?php endif; ?>

					<div id="hbl-related-grid-<?php echo esc_attr( $id ); ?>" class="hbl-related-grid">
						<?php
						while ( $rel_query->have_posts() ) {
							$rel_query->the_post();
							self::render_related_card();
						}
						wp_reset_postdata();
						?>
					</div>

					<div class="hbl-post-load-more-wrap"<?php if ( $rel_query->max_num_pages <= 1 ) echo ' style="display:none"'; ?>>
						<button class="hbl-post-load-more-btn"
						        data-grid="hbl-related-grid-<?php echo esc_attr( $id ); ?>"
						        data-page="1"
						        data-max="<?php echo esc_attr( (int) $rel_query->max_num_pages ); ?>"
						        data-post-id="<?php echo esc_attr( $post_id ); ?>"
						        data-count="<?php echo esc_attr( $rel_count ); ?>"
						        data-related-by="<?php echo esc_attr( $rel_by ); ?>"
						        data-nonce="<?php echo esc_attr( wp_create_nonce( 'hbl_related_nonce' ) ); ?>">
							<?php echo esc_html( $lm_text ); ?>
						</button>
					</div>
				</section>
			<?php endif; ?>

		</div><!-- .hbl-single-post-widget -->

		<script>
		(function() {
			var widget  = document.querySelector('.hbl-single-post-widget');
			if (!widget) return;
			var btn     = widget.querySelector('.hbl-post-load-more-btn');
			var copyBtn = widget.querySelector('.hbl-share-copy');
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var lmText  = <?php echo wp_json_encode( $lm_text ); ?>;

			// Load more related posts
			if (btn) {
				btn.addEventListener('click', function() {
					var grid = document.getElementById(btn.dataset.grid);
					if (!grid) return;
					btn.disabled = true;
					btn.textContent = '<?php esc_html_e( 'Loading\u2026', 'hbl' ); ?>';
					var fd = new FormData();
					fd.append('action',     'hbl_related_posts_load');
					fd.append('nonce',      btn.dataset.nonce);
					fd.append('page',       parseInt(btn.dataset.page || 1) + 1);
					fd.append('post_id',    btn.dataset.postId);
					fd.append('count',      btn.dataset.count);
					fd.append('related_by', btn.dataset.relatedBy);
					fetch(ajaxUrl, { method: 'POST', body: fd })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							if (!res.success) { restore(); return; }
							grid.insertAdjacentHTML('beforeend', res.data.html);
							btn.dataset.page = res.data.page;
							if (res.data.page >= res.data.max_pages) {
								btn.parentElement.style.display = 'none';
							} else {
								btn.disabled = false;
								btn.textContent = lmText;
							}
						})
						.catch(restore);
				});
				function restore() { btn.disabled = false; btn.textContent = lmText; }
			}

			// Copy link button
			if (copyBtn) {
				copyBtn.addEventListener('click', function() {
					navigator.clipboard.writeText(copyBtn.dataset.url).then(function() {
						copyBtn.title = '<?php esc_html_e( 'Copied!', 'hbl' ); ?>';
						setTimeout(function() { copyBtn.title = '<?php esc_html_e( 'Copy link', 'hbl' ); ?>'; }, 2000);
					});
				});
			}
		})();
		</script>

		<?php
		wp_reset_postdata();
	}

	// ── Styles ────────────────────────────────────────────────────────────────

	private function print_styles() {
		// Styles moved to style.css
	}

}

// ── Register AJAX hooks ───────────────────────────────────────────────────────
if ( ! has_action( 'wp_ajax_hbl_related_posts_load' ) ) {
	add_action( 'wp_ajax_hbl_related_posts_load',        array( 'HBL\Widgets\HBL_Single_Post', 'ajax_related' ) );
	add_action( 'wp_ajax_nopriv_hbl_related_posts_load', array( 'HBL\Widgets\HBL_Single_Post', 'ajax_related' ) );
}