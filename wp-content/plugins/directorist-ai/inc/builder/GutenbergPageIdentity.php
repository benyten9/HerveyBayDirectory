<?php
/**
 * Gutenberg-only native page classification retained for reviewed editor saves.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Narrow native identity classification.

/** Classifies native Gutenberg pages without enabling publication. */
final class GutenbergPageIdentity {
	/** Converted native builder state is outside this Gutenberg publication contract. */
	public static function supports_page( int $id ): bool {
		return 'page' === get_post_type( $id )
			&& ! get_post_meta( $id, '_directorist_ai_elementor_draft_operation', true )
			&& ! get_post_meta( $id, '_directorist_ai_divi_draft_operation', true )
			&& 'builder' !== get_post_meta( $id, '_elementor_edit_mode', true )
			&& ! get_post_meta( $id, '_elementor_data', true )
			&& 'on' !== get_post_meta( $id, '_et_pb_use_builder', true );
	}
}
