<?php
/**
 * Backward-compatible alias for the moved Link Trigger Clicked class.
 *
 * Canonical location: {@see \DoubleScale\Pro\Modules\Automations\Triggers\Link\LinkTriggerClicked}.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

class_alias(
	\DoubleScale\Pro\Modules\Automations\Triggers\Link\LinkTriggerClicked::class,
	__NAMESPACE__ . '\LinkTriggerClicked'
);
