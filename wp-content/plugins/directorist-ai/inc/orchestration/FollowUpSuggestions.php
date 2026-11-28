<?php
/**
 * Advisory next questions returned with the existing answer, never execution authority.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Malformed optional suggestions are dropped without rejecting a valid answer. */
final class FollowUpSuggestions {
	/** Return the optional same-response question contract.
	 *
	 * @return array JSON Schema.
	 */
	public static function schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 3,
			'items'    => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'label'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 80,
					),
					'text'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 700,
					),
					'intent'      => array( 'enum' => array( 'search', 'compare', 'question', 'action', 'knowledge' ) ),
					'listing_ids' => array(
						'type'        => 'array',
						'maxItems'    => 5,
						'uniqueItems' => true,
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'required'             => array( 'label', 'text', 'intent' ),
			),
		);
	}

	/**
	 * Keep only bounded, plain questions tied to the released result identities.
	 *
	 * @param mixed $items Optional model advice.
	 * @param array $listing_ids Released native listing identities.
	 * @param bool  $actions_available Whether actual native actions were released.
	 * @return array Valid advisory questions.
	 */
	public static function visitor( $items, array $listing_ids, bool $actions_available = false ): array {
		if ( ! is_array( $items ) || array_values( $items ) !== $items ) {
			return array(); }
		$valid = array();
		$seen  = array();
		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			if ( ! $item instanceof \stdClass || array_diff( array_keys( get_object_vars( $item ) ), array( 'label', 'text', 'intent', 'listing_ids' ) ) || ! is_string( $item->label ?? null ) || ! is_string( $item->text ?? null ) || ! in_array( $item->intent ?? null, array( 'search', 'compare', 'question', 'action', 'knowledge' ), true ) ) {
				continue; }
			$label = trim( $item->label );
			$text  = trim( $item->text );
			$ids   = $item->listing_ids ?? array();
			if ( '' === $label || '' === $text || mb_strlen( $label ) > 80 || mb_strlen( $text ) > 700 || preg_match( '/[<>\x00-\x1f]|https?:\/\/|www\./iu', $label . $text ) || ! is_array( $ids ) || count( $ids ) > 5 || count( array_filter( $ids, static fn( $id ): bool => is_int( $id ) && $id > 0 ) ) !== count( $ids ) || array_diff( $ids, $listing_ids ) || ( 'action' === $item->intent && ! $actions_available ) ) {
				continue; }
			if ( ! self::visitor_request( $label ) || ! self::visitor_request( $text ) ) {
				continue;
			}
			$key = mb_strtolower( preg_replace( '/[^\pL\pN]+/u', ' ', $text ) );
			if ( isset( $seen[ $key ] ) ) {
				continue; }
			$seen[ $key ] = true;
			$valid[]      = (object) array(
				'label'       => $label,
				'text'        => $text,
				'intent'      => $item->intent,
				'listing_ids' => array_values( array_unique( $ids ) ),
			);
			if ( count( $valid ) === 3 ) {
				break; }
		}
		return $valid;
	}

	/**
	 * Keep optional assistant-voice advice out of a visitor's next utterance.
	 *
	 * @param string $text Candidate visitor suggestion.
	 * @return bool Whether the text may be a visitor request.
	 */
	private static function visitor_request( string $text ): bool {
		return ! preg_match( '/^(?:would you (?:like|prefer)|do you (?:want|need)|shall (?:I|we)|vill du|skulle du vilja|ska jag|(?:te |le )?gustar[íi]a|quieres|prefieres|souhaitez-vous|voulez-vous|m[öo]chten sie|m[öo]chtest du|soll ich|هل (?:تريد|ترغب))(?![\p{L}\p{N}])/iu', $text )
			&& ! preg_match( '/^আপনি কি[\s\S]*চান(?:[?।.!\s]*)$/u', $text );
	}
}
