<?php
/**
 * Conservative import bounds.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

/** Bounds are enforced before inference and writes. */
final class Limits {
	public const SOURCE_BYTES   = 10485760;
	public const EXPANDED_BYTES = 20971520;
	public const RECORDS        = 10000;
	public const COLUMNS        = 200;
	public const CELL_BYTES     = 65536;
	public const BATCH_RECORDS  = 20;
	public const BATCH_SECONDS  = 15;
	public const SAMPLE_RECORDS = 8;
	public const SAMPLE_BYTES   = 16384;
	public const RECENT_IMPORTS = 7;
	public const REVISION       = 'listing-import-v1';
}
