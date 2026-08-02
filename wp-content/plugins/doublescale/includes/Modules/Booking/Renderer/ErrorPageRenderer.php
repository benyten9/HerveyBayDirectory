<?php
/**
 * Error Page Renderer
 *
 * Renders a styled error page when a public booking link cannot be resolved
 * (missing hash, deleted booking, unexpected failure). Shares the head/footer
 * markup of the other booking renderers so the page does not fall back to
 * WordPress' bare `wp_die()` styling.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

class ErrorPageRenderer extends BaseTemplateRenderer {

	public function render( string $heading, string $message, string $detail = '' ) {
		$template_path = __DIR__ . '/templates/error.php';

		return $this->render_template_page(
			$template_path,
			array(
				'title'   => $heading,
				'heading' => $heading,
				'message' => $message,
				'detail'  => $detail,
			)
		);
	}
}
