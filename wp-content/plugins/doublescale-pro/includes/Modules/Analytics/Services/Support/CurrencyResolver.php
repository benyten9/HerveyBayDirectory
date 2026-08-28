<?php
/**
 * Shim: CurrencyResolver moved to free core.
 *
 * Call sites in Pro can keep importing this class; the body lives in
 * \DoubleScale\Core\Services\CurrencyResolver (real free API, or Pro stub).
 *
 * @package DoubleScale\Pro\Modules\Analytics\Services\Support
 */

namespace DoubleScale\Pro\Modules\Analytics\Services\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and aggregates report currencies.
 */
class CurrencyResolver extends \DoubleScale\Core\Services\CurrencyResolver {}
