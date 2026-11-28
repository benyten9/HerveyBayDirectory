<?php
/**
 * Small deterministic version-range matcher for integration negotiation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment -- Compact internal value object.

use DirectoristAIPro\Validation\ContractException;

/** Supports an AND-list of explicit version_compare operators. */
final class VersionConstraint {
	/** @var array<int,array{operator:string,version:string}> */
	private array $clauses = array();

	/**
	 * Parse a reviewed local constraint such as `>=4.0 <5.0`.
	 *
	 * @param string $constraint Constraint expression.
	 * @throws ContractException Invalid expression.
	 */
	public function __construct( string $constraint ) {
		$constraint = trim( $constraint );
		if ( '*' === $constraint ) {
			return;
		}
		if ( '' === $constraint || strlen( $constraint ) > 128 ) {
			throw new ContractException( 'unsupported_contract', 'Invalid provider version constraint.' );
		}
		$clauses = preg_split( '/\s+/', $constraint );
		foreach ( false === $clauses ? array() : $clauses as $clause ) {
			if ( ! preg_match( '/^(>=|<=|>|<|=|!=)?([0-9][0-9A-Za-z.+-]{0,63})$/D', $clause, $matches ) ) {
				throw new ContractException( 'unsupported_contract', 'Invalid provider version constraint.' );
			}
			$this->clauses[] = array(
				'operator' => '' === $matches[1] ? '=' : $matches[1],
				'version'  => $matches[2],
			);
		}
	}

	/** Return whether one installed version satisfies every clause. */
	public function matches( string $version ): bool {
		if ( ! preg_match( '/^[0-9][0-9A-Za-z.+-]{0,63}$/D', $version ) ) {
			return false;
		}
		foreach ( $this->clauses as $clause ) {
			if ( ! version_compare( $version, $clause['version'], $clause['operator'] ) ) {
				return false;
			}
		}
		return true;
	}
}
