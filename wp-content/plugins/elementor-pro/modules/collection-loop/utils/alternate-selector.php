<?php

namespace ElementorPro\Modules\CollectionLoop\Utils;

use ElementorPro\Modules\CollectionLoop\Elements\Collection_Loop_Item\Collection_Loop_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v3-parity alternate template selection per iteration index.
 * Call `select_for_index` in ascending order from 0.
 */
class Alternate_Selector {
	/** @var object */
	private $template;

	/** @var array<int, array{element: object, apply_once: bool, repeat_every: int, static_position: bool}> */
	private $alternates;

	/** @var array<string, true> */
	private $used_apply_once_ids = [];

	/**
	 * @param object   $template   The main (index 0) loop item template.
	 * @param object[] $alternates The alternate loop items (siblings after the template).
	 */
	public function __construct( $template, array $alternates ) {
		$this->template   = $template;
		$this->alternates = array_map( [ $this, 'to_config' ], $alternates );
	}

	/**
	 * @return array{element: object, is_static: bool}
	 */
	public function select_for_index( int $index ): array {
		foreach ( $this->alternates as $config ) {
			if ( ! $this->is_available( $config ) ) {
				continue;
			}

			if ( ! $this->matches_at_index( $config, $index ) ) {
				continue;
			}

			if ( $config['apply_once'] ) {
				$this->used_apply_once_ids[ $config['element']->get_id() ] = true;
			}

			return [
				'element'   => $config['element'],
				'is_static' => $config['static_position'],
			];
		}

		return [
			'element'   => $this->template,
			'is_static' => false,
		];
	}

	private function to_config( $element ): array {
		return [
			'element'         => $element,
			'apply_once'      => (bool) $element->get_atomic_setting( Collection_Loop_Item::ALTERNATE_APPLY_ONCE_PROP ),
			// Negative values are treated as their absolute value (-2 behaves like 2).
			// This is a defensive guard for data saved outside the control's own min restriction.
			'repeat_every'    => abs( (int) $element->get_atomic_setting( Collection_Loop_Item::ALTERNATE_REPEAT_EVERY_PROP ) ),
			'static_position' => (bool) $element->get_atomic_setting( Collection_Loop_Item::ALTERNATE_STATIC_POSITION_PROP ),
		];
	}

	private function is_available( array $config ): bool {
		if ( ! $config['apply_once'] ) {
			return true;
		}

		return ! isset( $this->used_apply_once_ids[ $config['element']->get_id() ] );
	}

	private function matches_at_index( array $config, int $index ): bool {
		// Repeat_every of 0 means the alternate is turned off — it never matches.
		if ( Collection_Loop_Item::REPEAT_EVERY_DISABLED === $config['repeat_every'] ) {
			return false;
		}

		$one_based = $index + 1;

		if ( $config['apply_once'] ) {
			return $one_based === $config['repeat_every'];
		}

		return 0 === $one_based % $config['repeat_every'];
	}
}
