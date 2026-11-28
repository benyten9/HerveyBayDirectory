<?php
/**
 * Native category/tag suggestions and explicitly reviewed term creation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Validation\ContractException;

/** Listing relationships remain unsaved until native Save. */
final class ListingTerms {
	/**
	 * Resolve one installed taxonomy field under native assignment authority.
	 *
	 * @param Context $context Current actor.
	 * @param object  $form Bound form scope.
	 * @return array Native field schema.
	 * @throws ContractException When the taxonomy scope is unavailable.
	 */
	public static function field( Context $context, object $form ): array {
		$widget   = array(
			'categories' => 'category',
			'tags'       => 'tag',
		)[ $form->term_field ?? '' ] ?? null;
		$props    = $widget ? directorist_get_listing_form_field( $form->directory_id, $widget ) : null;
		$taxonomy = 'categories' === ( $form->term_field ?? '' ) ? ATBDP_CATEGORY : ATBDP_TAGS;
		$object   = get_taxonomy( $taxonomy );
		$posted   = get_object_vars( $form->facts ?? new \stdClass() );
		$fields   = ListingFormService::fields( $form->directory_id, $context->actor()->can( 'manage_options' ) );
		foreach ( get_object_vars( $form->values ?? new \stdClass() ) as $id => $value ) {
			if ( isset( $fields[ $id ] ) ) {
				$posted[ $fields[ $id ]['field_key'] ] = $value;
			}
		}
		$label = 'tags' === ( $form->term_field ?? '' ) ? __( 'Tags', 'directorist-ai' ) : __( 'Categories', 'directorist-ai' );
		if ( ! $props ) {
			// translators: %s is Categories or Tags.
			throw new ContractException( 'unauthorized', sprintf( __( '%s are not enabled for this directory. Add the field in Directory Builder before using AI.', 'directorist-ai' ), $label ) );
		}
		if ( ! $object || ! $context->actor()->can( 'manage_options' ) || ! $context->actor()->can( $object->cap->assign_terms ) ) {
			throw new ContractException( 'unauthorized', __( 'Your account cannot change this listing’s categories or tags.', 'directorist-ai' ) );
		}
		if ( ! \Directorist\Fields\Conditional_Logic::should_show_field( $props, $posted ) ) {
			// translators: %s is Categories or Tags.
			throw new ContractException( 'unauthorized', sprintf( __( '%s are hidden by this listing’s field settings. Complete the required fields, then try again.', 'directorist-ai' ), $label ) );
		}
		return $props;
	}

	/**
	 * Return bounded native choices, retaining category parents.
	 *
	 * @param object $form Bound form scope.
	 * @return array Directory-appropriate native terms.
	 */
	public static function catalog( object $form ): array {
		$taxonomy = 'categories' === $form->term_field ? ATBDP_CATEGORY : ATBDP_TAGS;
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 501,
			)
		);
		$result   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$directories = directorist_get_term_directory( $term->term_id );
			if ( ! in_array( $form->directory_id, $directories, true ) && ( 'categories' === $form->term_field || $directories ) ) {
				continue;
			}
			$result[] = (object) array(
				'id'        => (int) $term->term_id,
				'name'      => $term->name,
				'parent_id' => (int) $term->parent,
			);
		}
		return $result;
	}

	/**
	 * Validate exact existing IDs or bounded explicitly new names.
	 *
	 * @param Context $context Current actor.
	 * @param object  $form Bound form scope.
	 * @param string  $value Untrusted JSON term suggestion.
	 * @return array Exact human-review candidates.
	 * @throws ContractException When a candidate violates native scope or limits.
	 */
	public static function candidates( Context $context, object $form, string $value ): array {
		$props   = self::field( $context, $form );
		$terms   = json_decode( $value );
		$maximum = (int) ( $props['max'] ?? 0 );
		$bound   = 'choose_or_create' === ( $form->term_action ?? '' ) ? ( 'categories' === $form->term_field ? 3 : 5 ) : 10;
		if ( ! is_array( $terms ) || count( $terms ) > $bound || ( $maximum > 0 && count( $terms ) > $maximum ) || ( 'single' === ( $props['type'] ?? '' ) && count( $terms ) > 1 ) ) {
			throw new ContractException( 'invalid_input', 'Use a JSON term array within the allowed native selection limit.' );
		}
		if ( 'choose_or_create' === ( $form->term_action ?? '' ) && ! $terms ) {
			throw new ContractException( 'invalid_input', 'Choose at least one relevant term or ask for more business details.' );
		}
		$catalog  = array_column( self::catalog( $form ), null, 'id' );
		$taxonomy = 'categories' === $form->term_field ? ATBDP_CATEGORY : ATBDP_TAGS;
		$object   = get_taxonomy( $taxonomy );
		$result   = array();
		foreach ( $terms as $term ) {
			$keys = $term instanceof \stdClass ? array_keys( get_object_vars( $term ) ) : array();
			sort( $keys );
			if ( ! in_array( $keys, array( array( 'id', 'name' ), array( 'id', 'name', 'parent_id' ) ), true ) || ! ( null === $term->id || is_int( $term->id ) && $term->id > 0 ) || ! is_string( $term->name ) || '' === trim( $term->name ) || mb_strlen( $term->name ) > 80 || sanitize_text_field( $term->name ) !== $term->name || str_contains( $term->name, ',' ) ) {
				throw new ContractException( 'invalid_input', 'A suggested term is invalid.' );
			}
			// Native IDs own hierarchy; a model need not repeat an existing parent.
			if ( ! property_exists( $term, 'parent_id' ) ) {
				$term->parent_id = $term->id && isset( $catalog[ $term->id ] ) ? $catalog[ $term->id ]->parent_id : 0;
			}
			if ( ! is_int( $term->parent_id ) || $term->parent_id < 0 ) {
				throw new ContractException( 'invalid_input', 'A suggested term parent is invalid.' );
			}
			if ( $term->id ) {
				if ( ! isset( $catalog[ $term->id ] ) || ( $catalog[ $term->id ]->name !== $term->name || $catalog[ $term->id ]->parent_id !== $term->parent_id ) ) {
					throw new ContractException( 'scope_mismatch', 'Choose an exact existing term from the current directory catalog.' );
				}
			} elseif ( ! $context->actor()->can( $object->cap->manage_terms ) || ( $term->parent_id && ( 'tags' === $form->term_field || ! isset( $catalog[ $term->parent_id ] ) ) ) || term_exists( $term->name, $taxonomy, $term->parent_id ) ) {
				throw new ContractException( 'scope_mismatch', 'This new term is unavailable. Prefer an existing appropriate term.' );
			}
			$key = $term->id ? 'id:' . $term->id : mb_strtolower( $term->name ) . ':' . $term->parent_id;
			if ( isset( $result[ $key ] ) ) {
				throw new ContractException( 'invalid_input', 'Term suggestions must be unique.' );
			}
			$result[ $key ] = $term;
		}
		$result = array_values( $result );
		return 'choose_or_create' === ( $form->term_action ?? '' ) ? self::ground_automatic_terms( $context, $form, $catalog, $result ) : $result;
	}


	/**
	 * Normalize supplied display prose without concatenating HTML block boundaries.
	 *
	 * @param string $text Native listing field text.
	 * @return string Normalized text used for literal matching.
	 */
	private static function automatic_term_text( string $text ): string {
		$text = preg_replace( '#</(?:p|div|li|h[1-6]|section|article)>|<br\s*/?>#i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\p{Z}\s]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * A conservative literal support check; uncertainty declines this extra safeguard.
	 *
	 * @param string $text Supplied business description.
	 * @param string $name Registered native term name.
	 * @return bool Whether the description contains the term.
	 */
	private static function automatic_term_mentioned( string $text, string $name ): bool {
		$text = self::automatic_term_text( $text );
		$name = self::automatic_term_text( $name );
		// A name/description question or recognized negated/uncertain assertion
		// cannot establish this direct anchor. This is not a language classifier.
		$text = preg_replace( '/\bnot (?:only|just)\b/iu', '', $text );
		if ( '' === $name || preg_match( '/[?؟？]|(?<![\p{L}\p{M}\p{N}])(?:not|no|never|without|neither|nor|don.t|doesn.t|isn.t|aren.t|maybe|perhaps|possibly|might|নয়|নই|নেই|না)(?![\p{L}\p{M}\p{N}])/iu', $text ) ) {
			return false; }
		$length = preg_match_all( '/[\p{L}\p{N}]/u', $name );
		if ( $length < 2 ) {
			return false; }
		return (bool) preg_match( '/(?<![\p{L}\p{M}\p{N}])' . str_replace( ' ', '\\s+', preg_quote( $name, '/' ) ) . '(?![\p{L}\p{M}\p{N}])/u' . ( $length >= 4 ? 'i' : '' ), $text );
	}

	/**
	 * Keep native selected facts; filter unsupported new peers only beside a direct anchor.
	 *
	 * @param Context $context   Current listing actor and permissions.
	 * @param object  $form      Trusted current field values.
	 * @param array   $catalog   Available terms keyed by native ID.
	 * @param array   $suggested Proposed term selections.
	 * @return array Grounded native selections.
	 * @throws ContractException A directly supported selection would be removed.
	 */
	private static function ground_automatic_terms( Context $context, object $form, array $catalog, array $suggested ): array {
		$descriptions = array();
		foreach ( ListingFormService::fields( $form->directory_id, $context->actor()->can( 'manage_options' ) ) as $id => $props ) {
			if ( 'description' !== ( $props['widget_name'] ?? '' ) || ! is_string( $form->values->$id ?? null ) ) {
				continue; }
			$text = self::automatic_term_text( $form->values->$id );
			// A repeated exact business identity is not its activity/classification.
			$title = self::automatic_term_text( (string) ( $form->values->title ?? '' ) );
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				$text = preg_replace( '/(?<![\p{L}\p{M}\p{N}])' . preg_quote( trim( $title ), '/' ) . '(?![\p{L}\p{M}\p{N}])/iu', ' ', $text ) ?? ''; }
			$descriptions[] = $text;
		}
		if ( ! $descriptions ) {
			return $suggested; }
		$current = array();
		foreach ( json_decode( $form->values->{$form->term_field} ?? '[]' ) ?? array() as $term ) {
			if ( is_int( $term->id ?? null ) && isset( $catalog[ $term->id ] ) ) {
				$current[ $term->id ] = true; }
		}
		$labels = array();
		foreach ( $catalog as $id => $term ) {
			$labels[ mb_strtolower( self::automatic_term_text( $term->name ) ) ][] = $id; }
		$anchors = array();
		foreach ( $current as $id => $_ ) {
			$term = $catalog[ $id ];
			if ( 1 === count( $labels[ mb_strtolower( self::automatic_term_text( $term->name ) ) ] ) && array_filter( $descriptions, static fn( string $text ): bool => self::automatic_term_mentioned( $text, $term->name ) ) ) {
				$anchors[ $id ] = true; }
		}
		// No positively supported current selection: retain semantic classification.
		if ( ! $anchors ) {
			return $suggested; }
		$provided = array();
		foreach ( $suggested as $term ) {
			if ( $term->id ) {
				$provided[ $term->id ] = true; }
		}
		if ( array_diff_key( $anchors, $provided ) ) {
			throw new ContractException( 'invalid_input', 'Keep the current selections directly supported by the supplied business description. Return a corrected selection or ask for the changed business detail.', array( 'listing_term_preserve_anchor' ) ); }
		$allowed = $current;
		foreach ( array_keys( $current ) as $id ) {
			$seen = array();
			while ( isset( $catalog[ $id ] ) && $catalog[ $id ]->parent_id && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$id          = $catalog[ $id ]->parent_id;
				if ( isset( $catalog[ $id ] ) ) {
					$allowed[ $id ] = true; }
			}
		}
		$peer_parents = array();
		foreach ( $anchors as $id => $_ ) {
			$peer_parents[ $catalog[ $id ]->parent_id ] = true; }
		$filtered = array_values(
			array_filter(
				$suggested,
				static function ( object $term ) use ( $allowed, $peer_parents, $descriptions ): bool {
					if ( $term->id && isset( $allowed[ $term->id ] ) || ! isset( $peer_parents[ $term->parent_id ] ) ) {
						return true; }
					return (bool) array_filter( $descriptions, static fn( string $text ): bool => self::automatic_term_mentioned( $text, $term->name ) );
				}
			)
		);
		if ( ! $filtered ) {
			throw new ContractException( 'invalid_input', 'No supported automatic selection remains. Keep current choices or ask for the missing business detail.', array( 'listing_term_grounding' ) ); }
		return $filtered;
	}

	/**
	 * Import reviewed new terms or reconcile their exact native operation markers.
	 *
	 * @param Context    $context Current actor.
	 * @param object     $payload Reviewed form payload.
	 * @param EffectPlan $plan Exact approved effect.
	 * @param bool       $create Whether to create a missing reviewed term.
	 * @return array Native term diff ready for unsaved form application.
	 * @throws ContractException When exact native term readback is uncertain.
	 */
	public static function resolve( Context $context, object $payload, EffectPlan $plan, bool $create ): array {
		self::field( $context, $payload->form );
		$taxonomy = 'categories' === $payload->form->term_field ? ATBDP_CATEGORY : ATBDP_TAGS;
		$diff     = json_decode( wp_json_encode( $payload->diff ) );
		$catalog  = array_column( self::catalog( $payload->form ), null, 'id' );
		foreach ( $diff[0]->after_terms as $candidate ) {
			if ( $candidate->id ) {
				if ( ! isset( $catalog[ $candidate->id ] ) || ( $catalog[ $candidate->id ]->name !== $candidate->name || $catalog[ $candidate->id ]->parent_id !== $candidate->parent_id ) ) {
					throw new ContractException( 'evidence_stale', 'A reviewed existing term changed.' );
				}
				continue;
			}
			$object = get_taxonomy( $taxonomy );
			if ( ! $context->actor()->can( $object->cap->manage_terms ) || ( $candidate->parent_id && ! isset( $catalog[ $candidate->parent_id ] ) ) ) {
				throw new ContractException( 'unauthorized', 'New term creation is unavailable.' );
			}
			$existing = term_exists( $candidate->name, $taxonomy, $candidate->parent_id );
			$id       = is_array( $existing ) ? (int) $existing['term_id'] : 0;
			if ( ! $id && $create ) {
				$insert = wp_insert_term( $candidate->name, $taxonomy, array( 'parent' => $candidate->parent_id ) );
				if ( is_wp_error( $insert ) ) {
					throw new ContractException( 'evidence_stale', 'The reviewed term could not be created.' );
				}
				$id = (int) $insert['term_id'];
				update_term_meta( $id, '_directorist_ai_native_term_operation', $plan->operation_id );
				directorist_update_term_directory( $id, array( $payload->form->directory_id ) );
			}
			$term = $id ? get_term( $id, $taxonomy ) : null;
			if ( ! $term || get_term_meta( $id, '_directorist_ai_native_term_operation', true ) !== $plan->operation_id || $term->name !== $candidate->name || (int) $term->parent !== $candidate->parent_id || ! in_array( $payload->form->directory_id, directorist_get_term_directory( $id ), true ) ) {
				throw new ContractException( 'evidence_stale', 'The reviewed new term requires native readback.' );
			}
			$candidate->id      = $id;
			$candidate->created = true;
		}
		return $diff;
	}
}
