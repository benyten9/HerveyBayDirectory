<?php

namespace SEOPressPro\Actions;

use SEOPress\Core\Hooks\ExecuteHooks;
use WP_CLI;

class Commands implements ExecuteHooks {
	public function hooks() {
		add_action( 'cli_init', array( $this, 'init' ) );
	}

	public function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'seopress settings', '\SEOPressPro\CommandLine\Settings' );
			WP_CLI::add_command( 'seopress ai', '\SEOPressPro\CommandLine\AI' );
			WP_CLI::add_command( 'seopress-pro license', '\SEOPressPro\CommandLine\License' );
		}
	}
}
