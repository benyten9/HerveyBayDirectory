<?php

namespace SEOPressPro\Services\ListTable;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use WP_List_Table;

class BrokenLinksTable extends WP_List_Table {

	public function get_columns() {
		$columns = array(
			'cb'        => '<input type="checkbox" />',
			'name'      => __( 'Broken Link', 'wp-seopress-pro' ),
			'count'     => __( 'Count', 'wp-seopress-pro' ),
			'status'    => __( 'Status', 'wp-seopress-pro' ),
			'type'      => __( 'Type', 'wp-seopress-pro' ),
			'anchor'    => __( 'Anchor text', 'wp-seopress-pro' ),
			'source'    => __( 'Source', 'wp-seopress-pro' ),
			'post_type' => __( 'Post type', 'wp-seopress-pro' ),
		);
		return $columns;
	}

	protected function get_table_data() {
		$paged   = $this->get_pagenum();
		$perPage = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 20;
		$posts   = get_posts(
			array(
				'posts_per_page' => $perPage,
				'post_type'      => 'seopress_bot',
				'paged'          => $paged,
			)
		);

		return $posts;
	}
	protected function get_sortable_columns() {
		$sortable_columns = array(
			'status' => array( 'status', false ),
		);
		return $sortable_columns;
	}

	public function get_bulk_actions() {
		$actions = array(
			'delete_all' => __( 'Delete', 'wp-seopress-pro' ),
		);

		return $actions;
	}


	public function prepare_items() {
		$this->process_bulk_action();
		$total   = wp_count_posts( 'seopress_bot' )->publish;
		$perPage = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 1;

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $perPage,
				'total_pages' => 2,
			)
		);

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->items = $this->get_table_data();
	}

	public function column_cb( $item ) {
		return sprintf( '<input id="cb-select-%s" type="checkbox" name="post[]" value="%s">', $item->ID, $item->ID );
	}

	public function column_name( $item ) {
		$p_id = get_post_meta( $item->ID, 'seopress_bot_source_id', true );
		if ( ! $p_id || ! isset( $p_id ) ) {
			return;

		}

		$broken_link_edit = get_edit_post_link( $p_id );
		$str              = '<a href="' . $broken_link_edit . '">';
		$str             .= get_the_title( $item->ID );
		$str             .= ' - <span class="dashicons dashicons-edit"></span>';
		$str             .= '</a>';
		return $str;
	}

	public function extra_tablenav( $which ) {
		switch ( $which ) {
			case 'top':
				$status = array( '200', '301', '302', '307', '400', '401', '402', '403', '404', '410', '451', '500' );

				echo "<select name='bot-status' id='bot-status' class='postform'>";
				echo "<option value=''>" . __( 'Show All', 'wp-seopress-pro' ) . '</option>';
				foreach ( $status as $code ) {
					echo '<option value=' . $code, isset( $_GET[ $code ] ) == $code ? ' selected="selected"' : '', '>' . $code . '</option>';
				}
				echo '</select>';
				break;

		}
	}


	public function column_count( $item ) {
		return get_post_meta( $item->ID, 'seopress_bot_count', true );
	}

	public function column_status( $item ) {
		$seopress_bot_status = get_post_meta( $item->ID, 'seopress_bot_status', true );
		$str                 = '';
		switch ( $seopress_bot_status ) {
			case '500':
				$str = '<span class="seopress_bot_500">' . $seopress_bot_status . '</span>';
				break;

			case '404':
			case '403':
			case '402':
			case '401':
			case '400':
				$str = '<span class="seopress_bot_404">' . $seopress_bot_status . '</span>';
				break;

			case '307':
				$str = '<span class="seopress_bot_307">' . $seopress_bot_status . '</span>';
				break;

			case '302':
				$str = '<span class="seopress_bot_302">' . $seopress_bot_status . '</span>';
				break;

			case '301':
				$str = '<span class="seopress_bot_301">' . $seopress_bot_status . '</span>';
				break;

			case '200':
				$str = '<span class="seopress_bot_200">' . $seopress_bot_status . '</span>';
				break;

			default:
				$str = '<span class="seopress_bot_default">' . esc_html( $seopress_bot_status ) . '</span>';
				break;
		}

		return $str;
	}

	public function column_type( $item ) {
		return get_post_meta( $item->ID, 'seopress_bot_type', true );
	}

	public function column_anchor( $item ) {
		return get_post_meta( $item->ID, 'seopress_bot_a_title', true );
	}

	public function column_source( $item ) {
		return '<a href="' . get_post_meta( $item->ID, 'seopress_bot_source_url', true ) . '">' . get_post_meta( $item->ID, 'seopress_bot_source_title', true ) . '</a>';
	}

	public function column_post_type( $item ) {
		return get_post_meta( $item->ID, 'seopress_bot_cpt', true );
	}

	public function process_bulk_action() {

		if ( isset( $_POST['_wpnonce'] ) && ! empty( $_POST['_wpnonce'] ) ) {
			$nonce  = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
			$action = 'bulk-' . $this->_args['plural'];

			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( esc_html__( 'Nope! Security check failed!', 'wp-seopress-pro' ) );
			}
		} else {
			wp_die( esc_html__( 'No nonce provided!', 'wp-seopress-pro' ) );
		}

		$action = $this->current_action();

		switch ( $action ) {

			case 'delete_all':
				if ( ! isset( $_POST['post'] ) ) {
					return;
				}

				$posts = sanitize_text_field( wp_unslash( $_POST['post'] ) );

				foreach ( $posts as $post ) {
					wp_delete_post( $post, true );
				}
				break;
		}

		return;
	}
}
