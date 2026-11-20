<?php
/**
 * A value of the automation editor's catalogues built only when it is read.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Triggers, actions, goals and rules register on every request, but their
 * fields and options (lists of campaigns, lists, tags, pipelines, posts…)
 * are only needed when the editor asks for the catalogue. Registration stores
 * a DeferredValue in their place; the managers' getters resolve the tree.
 */
final class DeferredValue {

	/**
	 * @var callable
	 */
	private $build;

	/**
	 * @param callable $build Returns the value.
	 */
	public function __construct( callable $build ) {
		$this->build = $build;
	}

	/**
	 * Replace every DeferredValue in a tree by its value.
	 *
	 * @param array $tree Catalogue tree.
	 *
	 * @return array
	 */
	public static function resolve( array $tree ): array {
		array_walk_recursive(
			$tree,
			static function ( &$value ) {
				if ( $value instanceof self ) {
					$value = call_user_func( $value->build );
				}
			}
		);

		return $tree;
	}
}
