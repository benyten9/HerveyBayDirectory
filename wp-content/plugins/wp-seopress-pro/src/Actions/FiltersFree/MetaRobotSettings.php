<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class MetaRobotSettings implements ExecuteHooks {
	public function hooks() {
		add_filter( 'seopress_api_meta_robot_settings', array( $this, 'addSetting' ), 10, 2 );
	}

	/**
	 * @since 5.0.0
	 *
	 * @param array $data
	 * @param mixed $id
	 *
	 * @return array
	 */
	public function addSetting( $data, $id ) {

		$data[] = array(
			'key'         => '_seopress_robots_breadcrumbs',
			'type'        => 'input',
			'use_default' => '',
			'default'     => true,
			'label'       => __( 'Custom breadcrumbs', 'wp-seopress-pro' ),
			'description' => __( 'Enter a custom value, useful if your title is too long', 'wp-seopress-pro' ),
			'placeholder' => sprintf(
				/* translators: %s: post title */
				__( 'Current breadcrumbs: %s', 'wp-seopress-pro' ),
				get_the_title( $id )
			),
			'visible'     => true,
		);

		return $data;
	}
}
