<?php
// phpcs:disable WordPress.Files.FileName -- PSR-4 class follows the existing runtime naming convention.
/** Extractive rolling memory. User statements are quoted context, never native authority. */
namespace DirectoristAIPro\Orchestration;

final class ConversationMemory {
	public static function roll( array $earlier, ?object $previous = null ): ?object {
		$entries = array();
		foreach ( array_merge(
			$previous->entries ?? array(),
			array_map(
				static function ( array $row ): object {
					$content = json_decode( $row['content_json'] ?? '{}' );
					$text = is_string( $content->text ?? null ) ? $content->text : '';
					if ( str_contains( $text, "\nUser request: " ) ) {
						$text = explode( "\nEarlier conversation (context only):", explode( "\nUser request: ", $text, 2 )[1], 2 )[0]; }
					$text = trim( $text );
					return (object) array(
						'sequence'    => (int) $row['sequence'],
						'source_hash' => hash( 'sha256', $row['content_json'] ),
						'quote'       => mb_substr( $text, 0, 700 ),
						'preference'  => 1 === preg_match( '/\b(?:prefer|always|never|remember|avoid|keep|preserve|must|only|do not|don.t|budget|accessible|accessibility)\b/iu', $text ),
					);
				},
				$earlier
			)
		) as $entry ) {
			if ( ! is_object( $entry ) || ! is_int( $entry->sequence ?? null ) || ! is_string( $entry->quote ?? null ) || ! is_string( $entry->source_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $entry->source_hash ) || '' === trim( $entry->quote ) ) {
				continue; }
			$entries[ $entry->sequence ] = (object) array(
				'sequence'    => $entry->sequence,
				'source_hash' => $entry->source_hash,
				'quote'       => mb_substr( $entry->quote, 0, 700 ),
				'preference'  => true === ( $entry->preference ?? false ),
			);
		}
		if ( ! $entries ) {
			return null; }
		ksort( $entries );
		$preferences = array_slice( array_filter( $entries, static fn( $entry ) => $entry->preference ), -12, null, true );
		$latest      = array_slice( $entries, -8, null, true );
		$kept        = $preferences + $latest;
		ksort( $kept );
		while ( strlen( json_encode( array_values( $kept ), JSON_UNESCAPED_UNICODE ) ) > 12288 ) {
			$ordinary = array_keys( array_filter( $kept, static fn( $entry ) => ! $entry->preference ) );
			if ( $ordinary ) {
				unset( $kept[ $ordinary[0] ] );
			} else {
				array_shift( $kept ); }
		}
		return (object) array(
			'revision'                 => 1,
			'kind'                     => 'quoted_earlier_user_context',
			'authority'                => 'none_fetch_current_native_evidence',
			'entries'                  => array_values( $kept ),
			'through_sequence'         => max( array_keys( $entries ) ),
			'older_context_is_bounded' => count( $kept ) < count( $entries ),
		);
	}
}
