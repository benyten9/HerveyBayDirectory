<?php
/**
 * Trusted mapping plus read-only native validation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Directory\DirectorySchema;
use DirectoristAIPro\Validation\ContractException;

/** Validation never inserts listings, terms, attachments or reviews. */
final class RecordValidator {
	public function validate( Context $context, array $schema, array $mapping, array $record, array $policy ): array {
		$posted       = array(
			'listing_id'     => 0,
			'directory_type' => $schema['directory_id'],
			'directory_id'   => $schema['directory_id'],
			'tax_input'      => array(),
			't_c_check'      => ! empty( $policy['accept_terms'] ),
			'privacy_policy' => ! empty( $policy['accept_privacy'] ),
		);
		$errors       = array();
		$warnings     = array();
		$values       = array();
		$field_values = array();
		foreach ( $mapping['fields'] as $entry ) {
			$id    = $entry['field_id'];
			$props = $schema['fields'][ $id ]['props'];
			try {
				$components = array();
				foreach ( $entry['components'] ?? array() as $name => $path ) { $components[ $name ] = SourceParser::pointer( $record['data'], $path ); }
				$field_values[ $id ] = Mapping::convert( SourceParser::pointer( $record['data'], $entry['source_path'] ), $entry['transform'], $props, $components, $schema['behavior'] ); } catch ( ContractException $error ) {
				$errors[] = $this->error( $id, $error->error_code(), $error->getMessage() ); }
		}
		foreach ( $mapping['default_fields'] as $id ) {
			$props = $schema['fields'][ $id ]['props'];
			if ( ! array_key_exists( 'default_value', $props ) && ! array_key_exists( 'default', $props ) ) {
				$errors[] = $this->error( $id, 'default_unavailable', 'No native default is configured.' );
				continue; }
			$field_values[ $id ] = $props['default_value'] ?? $props['default'];
		}
		foreach ( $schema['fields'] as $id => $descriptor ) {
			$props = $descriptor['props'];
			if ( ! $props || 'importable' !== $descriptor['support'] ) {
				continue; }
			$value = $field_values[ $id ] ?? null;
			try {
				if ( in_array( $descriptor['widget'], array( 'category', 'location', 'tag' ), true ) ) {
					$taxonomy = $this->taxonomy( $descriptor['widget'] );
					$value    = $this->terms( $value, $taxonomy, $schema['directory_id'] );
					if ( 'multiple' !== ( $props['type'] ?? '' ) && count( $value ) > 1 ) {
						throw new ContractException( 'value_taxonomy', 'This taxonomy field accepts only one selected term.' ); }
					$posted['tax_input'][ $taxonomy ] = $value;
				} elseif ( in_array( $descriptor['widget'], array( 'pricing', 'map' ), true ) ) {
					CompositeFields::hydrate( $descriptor['widget'], $value, $posted );
				} else {
					if ( 'image_upload' === $descriptor['widget'] && null !== $value ) {
						$this->media( $value, $props, $policy ); }
					$posted[ $props['field_key'] ] = $value;
				}
			} catch ( ContractException $error ) {
				$errors[] = $this->error( $id, $error->error_code(), $error->getMessage() ); }
		}
		foreach ( $schema['fields'] as $id => $descriptor ) {
			$props = $descriptor['props'] ?? $descriptor['saved_props'] ?? array();
			try {
				$this->conditions( $props, $schema );
				$visible = \Directorist\Fields\Conditional_Logic::should_show_field( $props, $posted );
				if ( ! $visible ) {
					$warnings[] = array(
						'field_id' => $id,
						'code'     => 'conditionally_hidden',
						'message'  => 'Native conditional logic omits this value.',
					);
					continue; }
				if ( 'importable' !== $descriptor['support'] ) {
					if ( ! empty( $props['required'] ) && ! in_array( $descriptor['support'], array( 'computed_display', 'policy_protected' ), true ) ) {
						throw new ContractException( 'unsupported_required', 'A required field has no verified import adapter.' ); }
					if ( 'unsupported' === $descriptor['support'] && ! in_array( $id, $mapping['omit_fields'], true ) ) {
						throw new ContractException( 'omission_required', 'Explicitly review omission of this unsupported optional field.' ); }
					$warnings[] = array(
						'field_id' => $id,
						'code'     => $descriptor['support'],
						'message'  => $descriptor['reason'],
					);
					continue;
				}
				$this->constraints( $props, $field_values[ $id ] ?? null );
				$native = directorist_get_field( $props );
				if ( in_array( $descriptor['widget'], array( 'pricing', 'map' ), true ) && null === ( $field_values[ $id ] ?? null ) ) {
					if ( $native->is_required() ) { throw new ContractException( 'required_value', 'A required source fact is missing.' ); }
					$values[ $id ] = null; continue;
				}
				if ( $native->is_required() && $native->is_value_empty( $posted ) ) {
					throw new ContractException( 'required_value', 'A required source fact is missing.' ); }
				if ( ! $native->is_value_empty( $posted ) ) {
					if ( apply_filters( 'atbdp_add_listing_form_validation_logic', true, $props, $posted ) ) {
						$native->validate( $posted ); }
					if ( $native->has_error() ) {
						throw new ContractException( 'native_validation', wp_strip_all_tags( $native->get_error() ) ); }
					if ( in_array( $descriptor['widget'], array( 'category', 'location', 'tag' ), true ) ) {
						$values[ $id ] = $posted['tax_input'][ $this->taxonomy( $descriptor['widget'] ) ]; } elseif ( 'image_upload' === $descriptor['widget'] ) {
						$values[ $id ] = $posted[ $props['field_key'] ]; } else {
							$values[ $id ] = $native->sanitize( $posted );
							if ( in_array( $descriptor['widget'], array( 'pricing', 'map' ), true ) ) { $values[ $id ] = CompositeFields::sanitized( $descriptor['widget'], $values[ $id ] ); }
							else { $posted[ $props['field_key'] ] = $values[ $id ]; } }
				} else {
					$values[ $id ] = null; }
			} catch ( ContractException $error ) {
				$errors[] = $this->error( $id, $error->error_code(), $error->getMessage() ); } catch ( \Throwable $error ) {
				$errors[] = $this->error( $id, 'native_evaluator_unavailable', 'The installed native field could not evaluate this value.' ); }
		}
		if ( $schema['behavior']['terms_required'] && ! $posted['t_c_check'] ) {
			$errors[] = $this->error( '', 'acceptance_required', 'Explicitly accept the selected directory terms in the review.' ); }
		if ( $schema['behavior']['privacy_required'] && ! $posted['privacy_policy'] ) {
			$errors[] = $this->error( '', 'acceptance_required', 'Explicitly accept the selected directory privacy policy in the review.' ); }
		$result             = array(
			'record_id'          => $record['id'],
			'ordinal'            => $record['ordinal'],
			'provenance'         => $record['provenance'],
			'source_record_hash' => DirectorySchema::hash( $record['data'] ),
			'valid'              => ! $errors,
			'posted'             => $posted,
			'values'             => $values,
			'errors'             => $errors,
			'warnings'           => $warnings,
		);
		$result['row_hash'] = DirectorySchema::hash( $result );
		return $result;
	}

	private function constraints( array $props, $value ): void {
		if ( null === $value || '' === $value ) {
			return; }
		foreach ( $props as $key => $setting ) {
			if ( preg_match( '/pattern|regex|validation|restriction|allowed_format/i', (string) $key ) && null !== $setting && '' !== $setting && false !== $setting && array() !== $setting ) {
				throw new ContractException( 'setting_unverified', 'A validation setting has no verified import evaluator: ' . $key . '.' ); }
		}
		if ( is_scalar( $value ) ) {
			$length = mb_strlen( (string) $value );
			if ( isset( $props['maxlength'] ) && is_numeric( $props['maxlength'] ) && (int) $props['maxlength'] > 0 && $length > (int) $props['maxlength'] || isset( $props['minlength'] ) && is_numeric( $props['minlength'] ) && $length < (int) $props['minlength'] ) {
				throw new ContractException( 'value_bounds', 'The source value exceeds configured text bounds.' ); }
		}
		if ( 'number' === ( $props['widget_name'] ?? '' ) && is_numeric( $value ) ) {
			if ( isset( $props['min'] ) && is_numeric( $props['min'] ) && (float) $value < (float) $props['min'] || isset( $props['max'] ) && is_numeric( $props['max'] ) && (float) $props['max'] > 0 && (float) $value > (float) $props['max'] ) {
				throw new ContractException( 'value_bounds', 'The source number exceeds configured bounds.' ); }
			if ( isset( $props['step'] ) && 'any' !== $props['step'] ) {
				if ( ! is_numeric( $props['step'] ) || (float) $props['step'] <= 0 ) {
					throw new ContractException( 'setting_unverified', 'The configured numeric step cannot be evaluated.' ); }
				$steps = ( (float) $value - (float) ( $props['min_value'] ?? $props['min'] ?? 0 ) ) / (float) $props['step'];
				if ( abs( $steps - round( $steps ) ) > 0.00000001 ) {
					throw new ContractException( 'value_bounds', 'The source number does not match the configured numeric step.' ); }
			}
		}
	}

	private function conditions( array $props, array $schema ): void {
		$logic = $props['conditional_logic_data'] ?? $props['conditional_logic'] ?? $props['options']['conditional_logic']['value'] ?? $props['options']['conditional_logic'] ?? null;
		if ( is_string( $logic ) ) {
			$decoded = json_decode( $logic, true );
			if ( ! is_array( $decoded ) ) {
				throw new ContractException( 'conditional_unresolved', 'Malformed native conditional configuration.' );
			} $logic = $decoded; }
		if ( ! is_array( $logic ) || ! in_array( $logic['enabled'] ?? false, array( true, 1, '1', 'true' ), true ) ) {
			return; }
		if ( ! is_array( $logic['groups'] ?? null ) || ! $logic['groups'] ) {
			throw new ContractException( 'conditional_unresolved', 'Conditional groups are unresolved.' ); }
		$references = array();
		foreach ( $schema['fields'] as $id => $field ) {
			foreach ( array( $id, $field['props']['field_key'] ?? '', $field['props']['widget_name'] ?? '' ) as $key ) {
				if ( '' !== $key ) {
					$references[ $key ] = $field['support']; }
			}
		}
		foreach ( $logic['groups'] as $group ) {
			if ( ! is_array( $group['conditions'] ?? null ) || ! $group['conditions'] ) {
				throw new ContractException( 'conditional_unresolved', 'Conditional dependencies are unresolved.' ); }
			foreach ( $group['conditions'] as $condition ) {
				if ( ! isset( $references[ $condition['field'] ?? '' ] ) || 'importable' !== $references[ $condition['field'] ] || ! in_array( strtolower( trim( (string) ( $condition['operator'] ?? '' ) ) ), array( 'is', '==', '=', 'is not', '!=', 'not', 'contains', 'does not contain', 'empty', 'is empty', 'not empty', 'is not empty', 'greater than', '>', 'less than', '<', 'greater than or equal', '>=', 'less than or equal', '<=', 'starts with', 'ends with' ), true ) ) {
					throw new ContractException( 'conditional_unresolved', 'A conditional dependency or operator cannot be verified.' ); }
			}
		}
	}

	private function taxonomy( string $widget ): string {
		return array(
			'category' => ATBDP_CATEGORY,
			'location' => ATBDP_LOCATION,
			'tag'      => ATBDP_TAGS,
		)[ $widget ]; }

	private function terms( $values, string $taxonomy, int $directory_id ): array {
		$ids = array();
		foreach ( (array) $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				throw new ContractException( 'value_taxonomy', 'Use an existing term ID or exact hierarchical name path.' ); }
			$term = null;
			if ( preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) {
				$term = get_term( (int) $value, $taxonomy ); } else {
				$parent = 0;
				foreach ( explode( ' > ', (string) $value ) as $name ) {
					$matches = get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'name'       => $name,
							'parent'     => $parent,
							'number'     => 3,
							'meta_query' => array(
								array(
									'key'     => '_directory_type_' . $directory_id,
									'compare' => 'EXISTS',
								),
							),
						)
					);
					if ( is_wp_error( $matches ) || 1 !== count( $matches ) ) {
						throw new ContractException( 'value_taxonomy', 'The term path is unknown or ambiguous in this directory. Choose an existing term ID.' ); }
					$term   = $matches[0];
					$parent = $term->term_id;
				}
				}
				if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy || ! in_array( $directory_id, directorist_get_term_directory( $term->term_id ), true ) ) {
					throw new ContractException( 'value_taxonomy', 'The term does not belong to the selected directory.' ); }
				$ids[] = (int) $term->term_id;
		}
		return array_values( array_unique( $ids ) );
	}

	private function media( $ids, array $props, array $policy ): void {
		if ( ! is_array( $ids ) || empty( $policy['existing_media_only'] ) ) {
			throw new ContractException( 'value_media', 'Explicitly review use of existing owned image attachments.' ); }
		$total = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$file = get_attached_file( $id );
			if ( ! is_int( $id ) || ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || (int) $post->post_author !== (int) $policy['author_id'] || ! wp_attachment_is_image( $id ) || ! $file || ! is_file( $file ) || ! wp_get_image_mime( $file ) ) {
				throw new ContractException( 'value_media', 'An attachment is unavailable, not an image, or owned by another author.' ); }
			$bytes  = filesize( $file );
			$total += $bytes;
			$native = directorist_get_field( $props );
			if ( $bytes > $native->get_per_image_upload_size() || $total > $native->get_total_upload_size() ) {
				throw new ContractException( 'value_media', 'Existing images exceed native upload size constraints.' ); }
		}
	}

	private function error( string $id, string $code, string $message ): array {
		return array(
			'field_id' => $id,
			'code'     => $code,
			'message'  => $message,
		); }
}
