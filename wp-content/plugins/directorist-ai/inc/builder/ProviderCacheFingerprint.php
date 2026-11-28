<?php
/**
 * Bounded cheap source fingerprints from one integration reader.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable value object.

use DirectoristAIPro\Validation\ContractException;

/** Dynamic rules without a complete fingerprint explicitly bypass storage. */
final class ProviderCacheFingerprint {
	private string $native;
	private string $integration;
	private string $rules;
	private string $design;
	private bool $cacheable;
	/** @var string[] */
	private array $dynamic_reasons;

	public function __construct( string $native, string $integration, string $rules, string $design, bool $cacheable = true, array $dynamic_reasons = array() ) {
		foreach ( array( $native, $integration, $rules, $design ) as $hash ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new ContractException( 'unsupported_contract', 'Catalog source fingerprint is invalid.' );
			}
		}
		$dynamic_reasons = ProviderRegistration::tokens( $dynamic_reasons );
		if ( $cacheable && $dynamic_reasons ) {
			throw new ContractException( 'unsupported_contract', 'Cacheable source cannot declare dynamic reasons.' );
		}
		$this->native          = $native;
		$this->integration     = $integration;
		$this->rules           = $rules;
		$this->design          = $design;
		$this->cacheable       = $cacheable;
		$this->dynamic_reasons = $dynamic_reasons;
	}

	public function native(): string {
		return $this->native; }
	public function integration(): string {
		return $this->integration; }
	public function rules(): string {
		return $this->rules; }
	public function design(): string {
		return $this->design; }
	public function cacheable(): bool {
		return $this->cacheable; }
	public function dynamic_reasons(): array {
		return $this->dynamic_reasons; }
}
