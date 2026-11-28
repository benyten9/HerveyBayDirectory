<?php
/**
 * Bounded readable native media revisions for a page publication review.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Trusted native dependency reader.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Builder adapters identify attachments; this helper never discovers layout relationships. */
final class BuilderPageMediaFingerprint {

	public static function read( array $ids ): string {
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		if ( count( $ids ) > 16 ) {
			throw new ContractException( 'needs_expansion', 'Page publication exceeds its media dependency bound.' );
		}
		global $wpdb;
		$assets  = array();
		$total   = 0;
		$uploads = wp_upload_dir();
		$base    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $id ) );
			if ( '' !== $wpdb->last_error || ! is_int( $id ) || $id < 1 || ! $post instanceof \stdClass || 'attachment' !== $post->post_type || ! str_starts_with( $post->post_mime_type, 'image/' ) || 'trash' === $post->post_status || ! current_user_can( 'read_post', $id ) ) {
				throw new ContractException( 'unauthorized', 'Page publication media is unavailable to this actor.' );
			}
			$file = realpath( (string) get_attached_file( $id ) );
			if ( ! is_string( $base ) || ! is_string( $file ) || ! str_starts_with( $file, $base . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || ! is_readable( $file ) ) {
				throw new ContractException( 'unsupported_dependency', 'Page publication requires readable local native media.' );
			}
			clearstatcache( true, $file );
			$size   = filesize( $file );
			$total += false === $size ? 0 : $size;
			if ( false === $size || $size > 12582912 || $total > 33554432 ) {
				throw new ContractException( 'needs_expansion', 'Page publication media exceeds its file-read budget.' );
			}
			$meta        = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key,meta_id", $id ) );
			$meta_failed = '' !== $wpdb->last_error;
			$url         = wp_get_attachment_url( $id );
			$bytes       = hash_file( 'sha256', $file );
			if ( $meta_failed || count( $meta ) > 200 || strlen( wp_json_encode( $meta ) ) > 1048576 || ! is_string( $url ) || '' === $url || ! is_string( $bytes ) ) {
				throw new ContractException( 'unsupported_dependency', 'Page publication media revision cannot be verified.' );
			}
			$assets[] = (object) array(
				'post'  => $post,
				'meta'  => $meta,
				'url'   => $url,
				'bytes' => $bytes,
			);
		}
		return hash( 'sha256', ( new JsonCanonicalizer() )->encode( $assets ) );
	}
}
