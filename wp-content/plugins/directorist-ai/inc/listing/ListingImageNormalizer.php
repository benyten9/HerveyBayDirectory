<?php
/**
 * Fit trusted generated image bytes to the native slot budget before review.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Domain\MediaSource;
use DirectoristAIPro\Validation\ContractException;
use WordPress\AiClient\Files\DTO\File;

/** WordPress image editors preserve the reviewed result and keep temporary files private. */
final class ListingImageNormalizer {
	/**
	 * Preserve fitting sources or compress and resize within the native byte limit.
	 *
	 * @param File $file Trusted inline provider image.
	 * @param int  $budget Native remaining byte limit.
	 * @return File Exact source displayed in the human review.
	 * @throws ContractException When the image cannot fit the native slot.
	 */
	public static function fit( File $file, int $budget ): File {
		$source = MediaSource::from_inline( (string) $file->getBase64Data(), $file->getMimeType() );
		if ( $budget >= $source['size'] ) {
			return $file;
		}
		if ( $budget < 1 ) {
			throw new ContractException( 'invalid_input', 'The gallery has no remaining image capacity.', array( 'image_gallery_capacity' ) );
		}
		$input  = wp_tempnam( 'directorist-ai-private-source' );
		$output = wp_tempnam( 'directorist-ai-private-review' );
		$paths  = array_filter( array( $input, $output ) );
		try {
			if ( ! $input || ! $output || ! chmod( $input, 0600 ) || ! chmod( $output, 0600 ) || false === file_put_contents( $input, $source['bytes'] ) ) {
				throw new ContractException( 'invalid_input', 'Private image processing is unavailable.', array( 'image_processing_unavailable' ) );
			}
			$editor = wp_get_image_editor( $input );
			if ( is_wp_error( $editor ) ) {
				throw new ContractException( 'invalid_input', 'The image exceeds native limits and cannot be resized.', array( 'image_size_limit' ) );
			}
			$mime = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : $source['mime'];
			foreach ( array(
				1024 => 85,
				768  => 75,
				512  => 65,
			) as $edge => $quality ) {
				$editor->set_quality( $quality );
				$editor->resize( $edge, $edge, false );
				$saved = $editor->save( $output, $mime );
				if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
					continue;
				}
				$path    = $saved['path'];
				$paths[] = $path;
				chmod( $path, 0600 );
				$bytes = file_get_contents( $path );
				if ( is_string( $bytes ) && strlen( $bytes ) <= $budget ) {
					MediaSource::from_inline( base64_encode( $bytes ), $mime );
					return new File( base64_encode( $bytes ), $mime );
				}
			}
			throw new ContractException( 'invalid_input', 'The image cannot fit this directory’s native image limits.', array( 'image_size_limit' ) );
		} finally {
			foreach ( array_unique( $paths ) as $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}
}
