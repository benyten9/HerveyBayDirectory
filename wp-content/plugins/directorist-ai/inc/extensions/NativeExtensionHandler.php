<?php
/** Discovery for extensions whose native facts/workflows remain checked per listing. */
namespace DirectoristAIPro\Extensions;

class NativeExtensionHandler implements ExtensionHandler {
	public function __construct( private string $identity, private string $basename, private array $capabilities, private string $state = 'native_checks_required' ) {}
	public function id(): string { return $this->identity; }
	public function plugin(): string { return $this->basename; }
	public function features(): array { return $this->capabilities; }
	public function support(): string { return $this->state; }
	public function hooks(): void {}
}
