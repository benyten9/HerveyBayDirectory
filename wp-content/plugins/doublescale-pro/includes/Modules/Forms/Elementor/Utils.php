<?php

/**
 * Plugin Elementor Utils
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Elementor;

use ElementorPro\Plugin;
use WP_Query;

/**
 * Utils class.
 */
class Utils
{

	/**
	 * Get all elementor pages.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_pages()
	{
		$pages = array();

		$query = new WP_Query(
			array(
				'post_type'   => array('page', 'elementor_library', 'post'), // Query for pages
				'post_status' => 'publish', // Query only published pages
				'meta_query'  => array(
					'relation' => 'AND', // Relation between the meta queries
					array(
						'key'     => '_elementor_edit_mode',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_elementor_data',
						'compare' => 'LIKE',
						'value'   => '"widgetType":"form"',
					),
				),
			)
		);

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$pages[get_the_ID()] = get_the_title();
			}

			wp_reset_postdata();
		}

		return $pages;
	}

	/**
	 * Get forms by page id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Page ID.
	 *
	 * @return array
	 */
	public static function get_forms_by_page_id($post_id)
	{
		$forms = array();

		$document = Plugin::elementor()->documents->get($post_id);
		if (empty($document)) {
			return array();
		}

		$data  = $document->get_elements_data();
		$forms = array();

		Plugin::elementor()->db->iterate_data(
			$data,
			function ($element) use (&$forms) {
				$widget_type = $element['widgetType'] ?? '';
				if ('form' === $widget_type) {
					$forms[$element['id']] = $element['settings']['form_name'];
				}
			}
		);

		return $forms;
	}

	/**
	 * Get form by form id and page id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form ID.
	 * @param int $page_id Page ID.
	 *
	 * @return array
	 */
	public static function get_form($form_id, $page_id)
	{

		$document = Plugin::elementor()->documents->get($page_id);
		if (empty($document)) {
			return array();
		}

		$data      = $document->get_elements_data();
		$form_data = array();

		Plugin::elementor()->db->iterate_data(
			$data,
			function ($element) use (&$form_data, $form_id) {
				$widget_type = $element['widgetType'] ?? '';
				if ('form' === $widget_type && $form_id === $element['id']) {
					$form_data = $element;
					return;
				}
			}
		);

		return $form_data;
	}
}
