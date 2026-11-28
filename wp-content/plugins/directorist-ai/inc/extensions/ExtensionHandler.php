<?php
/** Installed extension discovery does not grant native action authority. */
namespace DirectoristAIPro\Extensions;

interface ExtensionHandler {
	public function id(): string;
	public function plugin(): string;
	public function features(): array;
	public function support(): string;
	public function hooks(): void;
}
