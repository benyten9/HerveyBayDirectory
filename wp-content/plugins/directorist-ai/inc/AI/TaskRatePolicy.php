<?php
/**
 * Versioned bounded policy; production managed prices deliberately remain unset.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use DirectoristAIPro\Validation\ContractException;

// phpcs:disable WordPress.WP.CapitalPDangit,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact explicit pricing boundary.

/** An isolated fixture may propose rates; it never represents a real wallet. */
final class TaskRatePolicy {
	public const VERSION = 'unpriced-proposal-v1';
	private array $rates;
	private string $version;

	public function __construct() {
		$this->rates   = array();
		$this->version = self::VERSION;
	}

	public static function fixture( string $version, array $rates ): self {
		if ( ! defined( 'DAI_TASK_ACCOUNTING_FIXTURE' ) || true !== DAI_TASK_ACCOUNTING_FIXTURE || ! preg_match( '/^fixture-[a-z0-9._-]{1,64}$/D', $version ) || array_diff( array_keys( $rates ), TaskPolicy::KINDS ) ) {
			throw new ContractException( 'invalid_input', 'Mock rates are restricted to an explicit isolated fixture.' );
		}
		foreach ( $rates as $value ) {
			if ( ! is_int( $value ) || 0 > $value || 1000000 < $value ) {
				throw new ContractException( 'invalid_input', 'Mock rate is outside the bounded proposal contract.' );
			}
		}
		$policy          = new self();
		$policy->rates   = $rates;
		$policy->version = $version;
		return $policy;
	}

	public function quote( array $task, string $source ): array {
		if ( ! in_array( $source, array( 'wordpress', 'managed' ), true ) || ! in_array( $task['kind'] ?? '', TaskPolicy::KINDS, true ) || ! is_bool( $task['uses_ai'] ?? null ) ) {
			throw new ContractException( 'invalid_input', 'Task pricing requires a validated task and source.' );
		}
		$native    = ! $task['uses_ai'];
		$byo       = 'wordpress' === $source;
		$mock      = ! $native && ! $byo && isset( $this->rates[ $task['kind'] ] );
		$limits    = function_exists( 'get_option' ) ? \DirectoristAIPro\Chat\Operations::settings() : \DirectoristAIPro\Chat\Operations::defaults();
		$connected = ! $native && ! $byo && ! $mock && '' !== ManagedService::fingerprint();
		$units     = $native || $byo ? 0 : ( $mock ? $this->rates[ $task['kind'] ] * ( $task['images']['quantity'] ?? 1 ) : null );
		return array(
			'version'          => $this->version,
			'mode'             => $native ? 'native' : ( $byo ? 'byo' : ( $mock ? 'fixture_mock' : ( $connected ? 'managed_usage' : 'unavailable' ) ) ),
			'authoritative'    => false,
			'currency'         => $connected ? 'USD' : 'credits',
			'tier'             => in_array( $task['kind'], array( 'listing_full', 'layout_full' ), true ) ? 'extended' : 'standard',
			'quoted_units'     => $units,
			'max_calls'        => $native ? 0 : ( $mock ? 8 + ( $task['images']['quantity'] ?? 0 ) : $limits['run_model_rounds'] ),
			'max_repairs'      => $native ? 0 : ( $mock ? 3 : $limits['run_repair_rounds'] ),
			'quote_expires_at' => $mock ? gmdate( 'Y-m-d H:i:s', time() + 600 ) : null,
			'provider_cost'    => null,
			'message'          => $native ? 'Deterministic native work uses no AI task credits.' : ( $byo ? 'WordPress/BYO never deducts managed service credits. Provider costs remain separate.' : ( $mock ? 'Isolated non-authoritative mock units. No wallet, balance or payment exists.' : ( $connected ? 'Managed provider usage plus markup is charged by the service in USD. Local task counts are capacity controls, not prices.' : ManagedService::status()['message'] ) ) ),
		);
	}
}
