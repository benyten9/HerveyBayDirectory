<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Services\Options\AdvancedOption;

class AdvancedOptionPro extends AdvancedOption {
	/**
	 * @since 6.2.0
	 *
	 * @return string
	 */
	public function getSecurityMetaboxRoleStructuredData() {
		return $this->searchOptionByKey( 'seopress_advanced_security_metaboxe_sdt_role' );
	}

	/**
	 * @since 8.1.0
	 *
	 * @return string
	 */
	public function getRewriteSearch() {
		return $this->searchOptionByKey( 'seopress_rewrite_search' );
	}

	/**
	 * @since 9.0.0
	 */
	public function getAppearanceDashboardLiveChat() {
		return $this->searchOptionByKey( 'seopress_advanced_appearance_dashboard_livechat' );
	}
}
