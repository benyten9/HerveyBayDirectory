<?php
namespace ElementorPro\Modules\CollectionLoop\Traits;

use Elementor\Modules\AtomicWidgets\Elements\Base\Has_Element_Template;
use Elementor\Modules\AtomicWidgets\Elements\Base\Render_Context;
use Elementor\Plugin;
use ElementorPro\Modules\CollectionLoop\Elements\Collection_Loop\Collection_Loop;
use ElementorPro\Modules\CollectionLoop\Elements\Collection_Loop_Item\Collection_Loop_Item;
use ElementorPro\Modules\CollectionLoop\Query\ItemProviders\Loop_Item_Provider;
use ElementorPro\Modules\CollectionLoop\Query\ItemProviders\Post_Loop_Item_Provider;
use ElementorPro\Modules\CollectionLoop\Utils\Alternate_Selector;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

trait Has_Loop_Iteration {
	use Has_Element_Template {
		render_children_to_html as protected render_default_children_to_html;
	}

	abstract protected function get_loop_context_key(): string;

	protected function render_children_to_html(): string {
		$loop_context = Render_Context::get( $this->get_loop_context_key() );

		if ( empty( $loop_context ) ) {
			return $this->render_default_children_to_html();
		}

		return $this->render_children_for_loop();
	}

	protected function render_children_for_loop(): string {
		$loop_context = Render_Context::get( $this->get_loop_context_key() );

		if ( empty( $loop_context ) || empty( $loop_context['has_items'] ) ) {
			return '';
		}

		$children = $this->get_children();
		$template = $children[ Collection_Loop::TEMPLATE_CHILD_INDEX ] ?? null;

		if ( ! $template ) {
			return '';
		}

		$item_provider = $this->resolve_item_provider( $loop_context );

		if ( null === $item_provider ) {
			return '';
		}

		$selector = new Alternate_Selector( $template, array_slice( $children, 1, Collection_Loop_Item::MAX_ALTERNATES ) );

		/**
		 * TODO: @deprecate this check on version 4.4
		 */
		$format_element_ids_class = 'Elementor\\Modules\\AtomicWidgets\\Utils\\Format_Element_Ids';

		if ( ! class_exists( $format_element_ids_class ) ) {
			return $this->render_children_for_loop_legacy( $selector, $item_provider );
		}

		return $this->render_children_for_loop_with_unique_ids( $selector, $loop_context, $item_provider, $format_element_ids_class );
	}

	private function render_children_for_loop_with_unique_ids( Alternate_Selector $selector, array $loop_context, Loop_Item_Provider $item_provider, string $format_element_ids_class ): string {
		$loop_id        = (string) ( $loop_context['loop_id'] ?? '' );
		$html           = '';
		$raw_data_by_id = [];
		$iteration      = 0;
		$budget         = $item_provider->count();

		$render_slots = function ( string $item_id ) use ( $selector, $loop_id, &$iteration, $budget, $format_element_ids_class, &$raw_data_by_id ): string {
			return $this->render_alternate_slots_for_item(
				$selector,
				$loop_id,
				$item_id,
				$iteration,
				$budget,
				$format_element_ids_class,
				$raw_data_by_id
			);
		};

		$item_provider->iterate( function ( string $item_id ) use ( &$iteration, $budget, $render_slots, &$html ): bool {
			if ( $iteration >= $budget ) {
				return false;
			}

			$html .= $render_slots( $item_id );

			return $iteration < $budget;
		} );

		return $html;
	}

	private function render_children_for_loop_legacy( Alternate_Selector $selector, Loop_Item_Provider $item_provider ): string {
		$html      = '';
		$iteration = 0;
		$budget    = $item_provider->count();

		$render_slots = function () use ( $selector, &$iteration, $budget ): string {
			return $this->render_alternate_slots_for_item_legacy( $selector, $iteration, $budget );
		};

		$item_provider->iterate( function () use ( &$iteration, $budget, $render_slots, &$html ): bool {
			if ( $iteration >= $budget ) {
				return false;
			}

			$html .= $render_slots();

			return $iteration < $budget;
		} );

		return $html;
	}

	/**
	 * Renders one or more output slots for the current loop item. Static alternates
	 * may emit multiple slots without advancing to the next item.
	 */
	private function render_alternate_slots_for_item(
		Alternate_Selector $selector,
		string $loop_id,
		string $item_id,
		int &$iteration,
		int $budget,
		string $format_element_ids_class,
		array &$raw_data_by_id
	): string {
		$html = '';

		do {
			if ( $iteration >= $budget ) {
				break;
			}

			$pick   = $selector->select_for_index( $iteration );
			$picked = $pick['element'];
			$picked_id = $picked->get_id();

			if ( ! isset( $raw_data_by_id[ $picked_id ] ) ) {
				$raw_data_by_id[ $picked_id ] = $picked->get_raw_data();
			}

			$template_data = $raw_data_by_id[ $picked_id ];
			$children_data = $template_data['elements'] ?? [];

			// Iteration is in the seed so static slots (same $item_id) still get unique ids.
			$rewritten_children = $format_element_ids_class::format(
				$children_data,
				[ $loop_id, $item_id, (string) $iteration ]
			);

			$iteration_data = array_merge( $template_data, [ 'elements' => $rewritten_children ] );
			$element        = Plugin::$instance->elements_manager->create_element_instance( $iteration_data );

			if ( $element ) {
				ob_start();
				$element->print_element();
				$html .= (string) ob_get_clean();
			}

			$iteration++;
		} while ( $pick['is_static'] );

		return $html;
	}

	private function render_alternate_slots_for_item_legacy(
		Alternate_Selector $selector,
		int &$iteration,
		int $budget
	): string {
		$html = '';

		do {
			if ( $iteration >= $budget ) {
				break;
			}

			$pick   = $selector->select_for_index( $iteration );
			$picked = $pick['element'];
			$picked->reset_descendant_render_state();

			ob_start();
			$picked->print_element();
			$html .= (string) ob_get_clean();

			$iteration++;
		} while ( $pick['is_static'] );

		return $html;
	}

	/**
	 * Resolve the item provider out of the render context, tolerating pre-refactor
	 * `query` shape (raw WP_Query) so v3-shaped fake contexts in tests still work.
	 */
	private function resolve_item_provider( array $loop_context ): ?Loop_Item_Provider {
		$item_provider = $loop_context['item_provider'] ?? null;

		if ( $item_provider instanceof Loop_Item_Provider ) {
			return $item_provider;
		}

		$query = $loop_context['query'] ?? null;

		if ( $query instanceof \WP_Query ) {
			return new Post_Loop_Item_Provider( $query );
		}

		if ( is_object( $query ) && method_exists( $query, 'have_posts' ) && method_exists( $query, 'the_post' ) ) {
			return new class( $query ) implements Loop_Item_Provider {
				private $query;

				public function __construct( $query ) {
					$this->query = $query;
				}

				public function has_items(): bool {
					return (bool) $this->query->have_posts();
				}

				public function count(): int {
					return property_exists( $this->query, 'post_count' ) ? (int) $this->query->post_count : 0;
				}

				public function items(): array {
					return [];
				}

				public function iterate( callable $on_iteration ): void {
					try {
						while ( $this->query->have_posts() ) {
							$this->query->the_post();

							if ( false === $on_iteration( '' ) ) {
								break;
							}
						}
					} finally {
						wp_reset_postdata();
					}
				}

				public function query(): ?\WP_Query {
					return $this->query instanceof \WP_Query ? $this->query : null;
				}

				public function max_num_pages(): int {
					return property_exists( $this->query, 'max_num_pages' ) ? (int) $this->query->max_num_pages : 1;
				}
			};
		}

		return null;
	}
}
