<?php
/**
 * AI module bootstrap.
 *
 * Owns: AI provider infrastructure, REST controllers for AI generation
 * (email builder, text, sequences, test-connection, models).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Ai;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'ai';
	}

	public function label(): string {
		return __( 'AI', 'doublescale' );
	}

	public function description(): string {
		return __( 'AI-powered email generation, text writing, and sequence building.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function register( Container $container ): void {}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestAiController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );
	}
}
