<?php
/**
 * Server-derived logical work, separate from model-call modality or token limits.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact closed task policy.

/** Only validated native context and trusted execution mode classify work. */
final class TaskPolicy {
	public const VERSION = 'logical-task-v1';
	public const KINDS   = array( 'chat_turn', 'directory_create', 'directory_edit', 'listing_import_mapping', 'listing_full', 'listing_improve', 'listing_field', 'layout_full', 'layout_refine', 'image_generation', 'inspection', 'native_action' );

	public static function classify( string $profile, ?object $context = null, bool $uses_ai = true ): array {
		if ( ! in_array( $profile, array( 'visitor', 'owner', 'admin', 'builder', 'external' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Task profile is unsupported.' );
		}
		$kind   = 'chat_turn';
		$images = null;
		if ( ! $uses_ai ) {
			$kind = isset( $context->inspection ) && true === $context->inspection ? 'inspection' : 'native_action';
		} elseif ( isset( $context->listing_import ) ) {
			$import = $context->listing_import;
			if ( 'admin' !== $profile || ! is_object( $import ) || 'mapping' !== ( $import->mode ?? '' ) || ! is_string( $import->job_id ?? null ) || ! preg_match( '/^[a-zA-Z0-9._:-]{1,128}$/D', $import->job_id ) || ! is_int( $import->directory_id ?? null ) || $import->directory_id < 1 || ! is_string( $import->schema_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $import->schema_hash ) || ! is_string( $import->source_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $import->source_hash ) ) {
				throw new ContractException( 'invalid_input', 'Listing import mapping requires a validated administrator source and directory schema.' );
			}
			$kind = 'listing_import_mapping';
		} elseif ( isset( $context->directory_builder ) && is_object( $context->directory_builder ) ) {
			$kind = 'create' === ( $context->directory_builder->mode ?? '' ) ? 'directory_create' : 'directory_edit';
		} elseif ( isset( $context->listing_form ) && is_object( $context->listing_form ) ) {
			$form = $context->listing_form;
			if ( isset( $form->media ) || 'media_form' === ( $form->mode ?? '' ) ) {
				$kind   = 'image_generation';
				$images = self::image_spec( 1, null, null );
			} elseif ( ! is_array( $form->field_ids ?? null ) || ! $form->field_ids || count( $form->field_ids ) > 50 || ! in_array( $form->mode ?? '', array( 'create_draft', 'edit_form', 'edit_terms' ), true ) ) {
				throw new ContractException( 'invalid_input', 'Native listing task scope is unavailable.' );
			} else {
				$kind = 1 === count( $form->field_ids ) ? 'listing_field' : ( 'create_draft' === $form->mode ? 'listing_full' : 'listing_improve' );
			}
		} elseif ( 'builder' === $profile || isset( $context->builder_document ) ) {
			$kind = isset( $context->builder_selection ) && null !== $context->builder_selection ? 'layout_refine' : 'layout_full';
		} elseif ( 'owner' === $profile || ( isset( $context->target ) && is_string( $context->target ) && str_starts_with( $context->target, 'listing:' ) ) ) {
			// Legacy saved-listing work lacks a validated per-field selection.
			$kind = 'listing:new' === ( $context->target ?? '' ) ? 'listing_full' : 'listing_improve';
		} elseif ( isset( $context->inspection ) && true === $context->inspection ) {
			$kind = 'inspection';
		}
		return array( 'version' => self::VERSION, 'kind' => $kind, 'feature' => Settings::feature( $profile, $context ), 'uses_ai' => $uses_ai, 'images' => $images );
	}

	public static function image_spec( int $quantity, ?string $size, ?string $quality ): array {
		if ( 1 > $quantity || 4 < $quantity || ( null !== $size && ( ! preg_match( '/^([1-9][0-9]{1,3})x([1-9][0-9]{1,3})$/D', $size, $match ) || 4096 < (int) $match[1] || 4096 < (int) $match[2] ) ) || ( null !== $quality && ! in_array( $quality, array( 'standard', 'hd', 'low', 'medium', 'high', 'auto' ), true ) ) ) {
			throw new ContractException( 'invalid_input', 'Image task quantity, size or quality is unsupported.' );
		}
		return array( 'quantity' => $quantity, 'size' => $size, 'quality' => $quality );
	}

	public static function image( string $feature, int $quantity, ?string $size, ?string $quality ): array {
		if ( ! in_array( $feature, array( 'listing', 'builder', 'visitor', 'admin' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Image task feature is unsupported.' );
		}
		return array( 'version' => self::VERSION, 'kind' => 'image_generation', 'feature' => $feature, 'uses_ai' => true, 'images' => self::image_spec( $quantity, $size, $quality ) );
	}
}
