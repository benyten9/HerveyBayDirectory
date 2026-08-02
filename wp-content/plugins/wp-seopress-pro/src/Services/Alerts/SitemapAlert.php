<?php

namespace SEOPressPro\Services\Alerts;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

/**
 * SEO Alerts — XML sitemap evaluation.
 *
 * Bridges the SEO Alerts module to the comprehensive sitemap diagnostic shipped
 * by the free plugin (Services\Sitemap\Diagnostic). It returns a normalized
 * result the cron dispatcher (email/Slack) and the REST endpoint both consume.
 *
 * Only error-severity checks trigger an alert. If the XML sitemap feature is
 * disabled, the sitemap alert is skipped entirely (nothing to test).
 *
 * Falls back to the legacy "HTTP 200" probe when the free diagnostic is not
 * available (older free plugin), so the module keeps working either way.
 *
 * @since 10.0.0
 */
class SitemapAlert {

	const NAME_SERVICE = 'SitemapAlert';

	/**
	 * Evaluate the XML sitemap health for alerting.
	 *
	 * @return array {
	 *     @type bool   $has_error       Whether an alert should fire.
	 *     @type array  $errors          List of failing checks ( label, message, doc ).
	 *     @type string $url             The tested sitemap URL.
	 *     @type bool   $disabled        Whether the sitemap feature is off (alert skipped).
	 *     @type bool   $used_diagnostic Whether the full diagnostic ran (vs. the fallback).
	 * }
	 */
	public function evaluate() {
		$diagnostic = function_exists( 'seopress_get_service' ) ? seopress_get_service( 'SitemapDiagnostic' ) : null;

		if ( ! is_object( $diagnostic ) || ! method_exists( $diagnostic, 'run' ) ) {
			return $this->fallback();
		}

		$report = $diagnostic->run();
		$checks = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();

		$errors   = array();
		$disabled = false;

		foreach ( $checks as $check ) {
			$status = isset( $check['status'] ) ? $check['status'] : '';

			// The sitemap feature is off: skip the alert rather than report a
			// "disabled" error (the user may rely on the native WP sitemap).
			if ( 'feature_enabled' === $check['id'] && 'error' === $status ) {
				$disabled = true;
				break;
			}

			if ( 'error' === $status ) {
				$errors[] = array(
					'label'   => isset( $check['label'] ) ? $check['label'] : '',
					'message' => isset( $check['message'] ) ? $check['message'] : '',
					'doc'     => isset( $check['doc'] ) ? $check['doc'] : '',
				);
			}
		}

		if ( $disabled ) {
			return array(
				'has_error'       => false,
				'errors'          => array(),
				'url'             => isset( $report['url'] ) ? $report['url'] : '',
				'disabled'        => true,
				'used_diagnostic' => true,
			);
		}

		return array(
			'has_error'       => ! empty( $errors ),
			'errors'          => $errors,
			'url'             => isset( $report['url'] ) ? $report['url'] : '',
			'disabled'        => false,
			'used_diagnostic' => true,
		);
	}

	/**
	 * Legacy probe used when the free diagnostic is unavailable: HTTP status only.
	 *
	 * @return array Same shape as evaluate().
	 */
	private function fallback() {
		$url = get_home_url() . '/sitemaps.xml';

		$args = apply_filters(
			'seopress_seo_alerts_sitemap_args',
			array(
				'blocking'    => true,
				'timeout'     => 10,
				'redirection' => 1,
			)
		);

		$code      = (int) wp_remote_retrieve_response_code( wp_remote_get( $url, $args ) );
		$has_error = ( 200 !== $code );
		$errors    = array();

		if ( $has_error ) {
			$errors[] = array(
				'label'   => __( 'XML sitemap', 'wp-seopress-pro' ),
				'message' => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Returns HTTP %d.', 'wp-seopress-pro' ),
					$code
				),
				'doc'     => '',
			);
		}

		return array(
			'has_error'       => $has_error,
			'errors'          => $errors,
			'url'             => $url,
			'disabled'        => false,
			'used_diagnostic' => false,
		);
	}
}
