<?php
/**
 * Email blocks module bootstrap.
 *
 * Saved reusable email sections for the email builder (Pro).
 *
 * @package DoubleScale\Pro\Modules\EmailBlocks
 */

namespace DoubleScale\Pro\Modules\EmailBlocks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'email_blocks';
	}

	public function label(): string {
		return __( 'Email Blocks', 'doublescale' );
	}

	public function description(): string {
		return __( 'Reusable saved email sections for the email builder.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function dependencies(): array {
		return array( 'core', 'campaigns' );
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestSavedBlockController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );
	}
}
