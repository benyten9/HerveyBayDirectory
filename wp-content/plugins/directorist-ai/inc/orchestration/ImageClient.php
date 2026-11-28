<?php
/**
 * Budgeted worker image provider seam.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

interface ImageClient {
	/** Capability probe must not invoke the provider. */
	public function available(): bool;
	/**
	 * Generate an image through the configured native provider.
	 *
	 * @param string $brief Reviewed input.
	 */
	public function generate( string $brief ): ImageResponse;
}
