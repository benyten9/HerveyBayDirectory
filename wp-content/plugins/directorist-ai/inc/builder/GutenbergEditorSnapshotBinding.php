<?php
/**
 * Immutable server-issued Gutenberg editor registry evidence.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.PHP.YodaConditions.NotYoda -- Compact immutable evidence boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Binds allowlisted client registry facts to a consumed server challenge. */
final class GutenbergEditorSnapshotBinding implements CatalogEditorSnapshot {
	private const CHALLENGE_PREFIX = 'dai_gb_editor_challenge_';
	private const BINDING_PREFIX   = 'dai_gb_editor_binding_';
	private const EPOCH_PREFIX     = 'dai_gb_editor_epoch_';

	private string $binding_id;
	private int $site_id;
	private int $user_id;
	private string $document_kind;
	private ?int $directory_id;
	private string $document_ref;
	private string $origin;
	private string $base_native_revision;
	private int $expires_at;
	private object $snapshot;
	private string $fingerprint;

	/** Persist a short-lived server challenge before accepting editor facts. */
	public static function challenge( Context $context, string $document_kind, ?int $directory_id, string $document_ref, string $origin, ?string $saved_content = null ): object {
		$context->assert_execution_identity();
		if ( ! ProviderRegistration::valid_token( $document_kind ) || ( null !== $directory_id && $directory_id < 1 ) || ! ProviderRegistration::valid_token( $document_ref ) || ! self::same_origin( $origin ) || ! self::can_edit_document( $document_ref ) || ! self::document_matches_kind( $document_ref, $document_kind, $directory_id ) ) {
			throw new ContractException( 'unauthorized', 'Editor registry challenge is unavailable.' );
		}
		if ( null !== $saved_content && preg_match( '/^post:(\d+)$/D', $document_ref, $match ) ) {
			$post = get_post( (int) $match[1] );
			if ( ! $post instanceof \WP_Post || ! hash_equals( hash( 'sha256', $post->post_content ), hash( 'sha256', $saved_content ) ) ) {
				throw new ContractException( 'evidence_stale', 'Editor saved source differs from the current document.' );
			}
		}
		$id        = 'gb-' . bin2hex( random_bytes( 16 ) );
		$expires   = time() + 300;
		$challenge = array(
			'binding_id'           => $id,
			'site_id'              => get_current_blog_id(),
			'user_id'              => (int) $context->actor()->user_id(),
			'document_kind'        => $document_kind,
			'directory_id'         => $directory_id,
			'document_ref'         => $document_ref,
			'origin'               => $origin,
			'epoch'                => hash( 'sha256', random_bytes( 32 ) ),
			'base_native_revision' => self::document_revision( $document_ref ),
			'expires_at'           => $expires,
		);
		set_transient( self::CHALLENGE_PREFIX . hash( 'sha256', $id ), $challenge, 300 );
		set_transient( self::epoch_key( $challenge ), $challenge['epoch'], 900 );
		return (object) array(
			'binding_id' => $id,
			'nonce'      => wp_create_nonce( 'directorist_ai_gutenberg_editor_snapshot:' . $id ),
			'expires_at' => $expires,
		);
	}

	/** Consume one server challenge and issue bounded inspect-only evidence. */
	public static function issue( Context $context, array $data ): self {
		$context->assert_execution_identity();
		$required = array( 'allowed_block_types', 'binding_id', 'blocks', 'group_variations', 'nonce', 'origin' );
		$keys     = array_keys( $data );
		sort( $keys, SORT_STRING );
		$extended = array_merge( $required, array( 'document_blocks', 'editor_revision' ) );
		sort( $extended, SORT_STRING );
		if ( ( $keys !== $required && $keys !== $extended ) || ! is_string( $data['binding_id'] ) || ! ProviderRegistration::valid_token( $data['binding_id'] ) || ! is_string( $data['origin'] ) || ! is_string( $data['nonce'] ) ) {
			throw new ContractException( 'evidence_stale', 'Editor registry evidence is invalid.' );
		}
		$key       = self::CHALLENGE_PREFIX . hash( 'sha256', $data['binding_id'] );
		$challenge = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $challenge ) || $challenge['binding_id'] !== $data['binding_id'] || (int) $challenge['site_id'] !== get_current_blog_id() || (int) $challenge['user_id'] !== (int) $context->actor()->user_id() || (int) $challenge['expires_at'] <= time() || ! hash_equals( $challenge['origin'], $data['origin'] ) || ! self::same_origin( $data['origin'] ) || ! wp_verify_nonce( $data['nonce'], 'directorist_ai_gutenberg_editor_snapshot:' . $data['binding_id'] ) || ! self::can_edit_document( $challenge['document_ref'] ) || ! hash_equals( $challenge['base_native_revision'], self::document_revision( $challenge['document_ref'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Editor registry challenge is stale or already consumed.' );
		}
		$blocks           = self::blocks( $data['blocks'] );
		$allowed          = self::tokens( $data['allowed_block_types'] );
		$variations       = self::variations( $data['group_variations'] );
		$document_blocks  = self::document_blocks( $data['document_blocks'] ?? null );
		$claimed_revision = $data['editor_revision'] ?? null;
		if ( null === $document_blocks xor null === $claimed_revision ) {
			throw new ContractException( 'evidence_stale', 'Dirty editor content evidence is incomplete.' );
		}
		$editor_revision = null;
		if ( null !== $claimed_revision ) {
			$editor_revision = GraphVersion::identifier( $claimed_revision );
		}
		$revision = hash( 'sha256', json_encode( array( $blocks, $allowed, $variations, $document_blocks, $editor_revision, $challenge['document_ref'], $challenge['base_native_revision'], $challenge['epoch'] ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		$payload  = (object) array(
			'allowed_block_types'  => $allowed,
			'base_native_revision' => $challenge['base_native_revision'],
			'blocks'               => $blocks,
			'document_ref'         => $challenge['document_ref'],
			'epoch'                => $challenge['epoch'],
			'group_variations'     => $variations,
			'document_blocks'      => $document_blocks,
			'editor_revision'      => $editor_revision,
			'revision'             => $revision,
		);
		( new JsonDecoder( 1048576, 32 ) )->check( $payload );
		$binding = new self( $context, $challenge, $payload );
		set_transient(
			self::BINDING_PREFIX . hash( 'sha256', $binding->binding_id ),
			array(
				'challenge' => $challenge,
				'snapshot'  => $payload,
			),
			max( 1, (int) $challenge['expires_at'] - time() )
		);
		return $binding;
	}

	/** Resolve previously issued evidence for the same current actor/site. */
	public static function load( Context $context, string $binding_id ): self {
		if ( ! ProviderRegistration::valid_token( $binding_id ) ) {
			throw new ContractException( 'evidence_stale', 'Editor binding identity is invalid.' );
		}
		$stored = get_transient( self::BINDING_PREFIX . hash( 'sha256', $binding_id ) );
		if ( ! is_array( $stored ) || ! isset( $stored['challenge'], $stored['snapshot'] ) || ! is_array( $stored['challenge'] ) || ! $stored['snapshot'] instanceof \stdClass || $stored['challenge']['binding_id'] !== $binding_id || (int) $stored['challenge']['site_id'] !== get_current_blog_id() || (int) $stored['challenge']['user_id'] !== (int) $context->actor()->user_id() ) {
			throw new ContractException( 'evidence_stale', 'Editor binding is unavailable.' );
		}
		$binding = new self( $context, $stored['challenge'], $stored['snapshot'] );
		$binding->assert_current( $context, 'gutenberg' );
		return $binding;
	}

	private function __construct( Context $context, array $challenge, object $snapshot ) {
		$this->binding_id           = $challenge['binding_id'];
		$this->site_id              = (int) $challenge['site_id'];
		$this->user_id              = (int) $context->actor()->user_id();
		$this->document_kind        = $challenge['document_kind'];
		$this->directory_id         = $challenge['directory_id'];
		$this->document_ref         = $challenge['document_ref'];
		$this->origin               = $challenge['origin'];
		$this->base_native_revision = $challenge['base_native_revision'];
		$this->expires_at           = (int) $challenge['expires_at'];
		$this->snapshot             = $snapshot;
		$this->fingerprint          = hash( 'sha256', json_encode( array( $this->binding_id, $this->site_id, $this->user_id, $this->document_kind, $this->directory_id, $this->origin, $this->expires_at, $snapshot ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function assert_current( Context $context, string $builder ): void {
		$context->assert_execution_identity();
		$current_epoch = get_transient( self::EPOCH_PREFIX . hash( 'sha256', $this->site_id . ':' . $this->user_id . ':' . $this->document_ref ) );
		if ( 'gutenberg' !== $builder || get_current_blog_id() !== $this->site_id || (int) $context->actor()->user_id() !== $this->user_id || time() >= $this->expires_at || ! is_string( $current_epoch ) || ! hash_equals( $this->snapshot->epoch, $current_epoch ) || ! self::same_origin( $this->origin ) || ! self::can_edit_document( $this->document_ref ) || ! hash_equals( $this->base_native_revision, self::document_revision( $this->document_ref ) ) ) {
			throw new ContractException( 'evidence_stale', 'Editor registry evidence is stale.' );
		}
	}

	public function fingerprint(): string {
		return $this->fingerprint; }
	public function binding_id(): string {
		return $this->binding_id; }
	public function document_kind(): string {
		return $this->document_kind; }
	public function directory_id(): ?int {
		return $this->directory_id; }
	public function snapshot(): object {
		return json_decode( json_encode( $this->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR ); }

	private static function same_origin( string $origin ): bool {
		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $origin );
		return is_array( $expected ) && is_array( $actual ) && strtolower( (string) ( $expected['scheme'] ?? '' ) ) === strtolower( (string) ( $actual['scheme'] ?? '' ) ) && strtolower( (string) ( $expected['host'] ?? '' ) ) === strtolower( (string) ( $actual['host'] ?? '' ) ) && (int) ( $expected['port'] ?? 0 ) === (int) ( $actual['port'] ?? 0 );
	}

	private static function epoch_key( array $challenge ): string {
		return self::EPOCH_PREFIX . hash( 'sha256', $challenge['site_id'] . ':' . $challenge['user_id'] . ':' . $challenge['document_ref'] );
	}

	private static function can_edit_document( string $document_ref ): bool {
		if ( preg_match( '/^post:(\d+)$/D', $document_ref, $matches ) ) {
			return current_user_can( 'edit_post', (int) $matches[1] );
		}
		return 1 === preg_match( '/^template:(wp_template|wp_template_part):[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+$/D', $document_ref ) && current_user_can( 'edit_theme_options' );
	}

	/** Apply native kind and current directory identity at draft stage and effect time. */
	public static function matches_saved_document( string $document_ref, string $document_kind, ?int $directory_id, ?string $directory_slug ): bool {
		$private = self::private_template_context( $document_ref );
		if ( null !== $private ) {
			if ( $private['native_kind'] !== $document_kind || ( 'template:single_listing' === $document_kind && $private['directory_id'] !== $directory_id ) ) {
				return false;
			}
			$design_id = 'template:single_listing' === $document_kind ? $private['directory_id'] : $directory_id;
			$term      = null !== $design_id ? get_term( $design_id, ATBDP_DIRECTORY_TYPE ) : null;
			return null === $directory_slug || ( $term instanceof \WP_Term && hash_equals( $directory_slug, $term->slug ) );
		}
		if ( 'template:single_listing' === $document_kind ) {
			if ( ! preg_match( '/:single-at_biz_dir-([A-Za-z0-9_.-]+)$/D', $document_ref, $match ) ) {
				return false;
			}
			$term = get_term_by( 'slug', $match[1], ATBDP_DIRECTORY_TYPE );
			if ( ! $term instanceof \WP_Term || ( null !== $directory_id && $directory_id !== (int) $term->term_id ) || ( null !== $directory_slug && ! hash_equals( $directory_slug, $term->slug ) ) ) {
				return false;
			}
			$directory_id = (int) $term->term_id;
		}
		return self::document_matches_kind( $document_ref, $document_kind, $directory_id );
	}

	private static function document_matches_kind( string $document_ref, string $document_kind, ?int $directory_id ): bool {
		$private = self::private_template_context( $document_ref );
		if ( null !== $private ) {
			return $private['native_kind'] === $document_kind && ( 'template:single_listing' !== $document_kind || $private['directory_id'] === $directory_id );
		}
		if ( preg_match( '/^post:(\d+)$/D', $document_ref, $matches ) ) {
			$post = get_post( (int) $matches[1] );
			return 'page' === $document_kind && $post instanceof \WP_Post && 'page' === $post->post_type;
		}
		if ( ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $document_ref, $matches ) ) {
			return false;
		}
		$template = GutenbergTemplateSource::read( $document_ref );
		if ( ! $template instanceof \WP_Block_Template ) {
			return false;
		}
		if ( 'template_part' === $document_kind ) {
			return 'wp_template_part' === $matches[1];
		}
		if ( 'wp_template' !== $matches[1] ) {
			return false;
		}
		$slug  = substr( $document_ref, strrpos( $document_ref, ':' ) + 1 );
		$exact = array(
			'template:archive_listing'   => array( 'archive', 'archive-at_biz_dir' ),
			'template:taxonomy_category' => array( 'taxonomy-at_biz_dir-category' ),
			'template:taxonomy_location' => array( 'taxonomy-at_biz_dir-location' ),
			'template:taxonomy_tag'      => array( 'taxonomy-at_biz_dir-tag', 'taxonomy-at_biz_dir-tags' ),
			'template:author_listing'    => array( 'author-at_biz_dir' ),
			'template:home_search'       => array( 'directorist-home-search-result' ),
		);
		if ( 'template:single_listing' === $document_kind ) {
			if ( null === $directory_id || ! preg_match( '/^single-at_biz_dir-([A-Za-z0-9_.-]+)$/D', $slug, $match ) ) {
				return false;
			}
			$term = get_term_by( 'slug', $match[1], ATBDP_DIRECTORY_TYPE );
			return $term instanceof \WP_Term && $directory_id === (int) $term->term_id;
		}
		return isset( $exact[ $document_kind ] ) && in_array( $slug, $exact[ $document_kind ], true );
	}

	/** Integration-owned lineage proves a private native template's source family. */
	private static function private_template_context( string $document_ref ): ?array {
		static $reading = array();
		if ( isset( $reading[ $document_ref ] ) || count( $reading ) >= 8 || ! current_user_can( 'edit_theme_options' ) || ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):directorist-ai-(?:draft|working)-[a-f0-9]{24}$/D', $document_ref, $match ) || get_stylesheet() !== $match[2] ) {
			return null;
		}
		$reading[ $document_ref ] = true;
		try {
			$context = apply_filters( 'directorist_ai_pro_gutenberg_native_template_context', null, $document_ref );
			if ( ! is_array( $context ) || ! is_string( $context['native_kind'] ?? null ) || ! in_array( $context['native_kind'], array( 'template_part', 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) || ! array_key_exists( 'directory_id', $context ) || ( null !== $context['directory_id'] && ( ! is_int( $context['directory_id'] ) || $context['directory_id'] < 1 ) ) ) {
				return null;
			}
			return $context;
		} finally {
			unset( $reading[ $document_ref ] );
		}
	}

	private static function document_revision( string $document_ref ): string {
		if ( preg_match( '/^post:(\d+)$/D', $document_ref, $matches ) ) {
			$post = get_post( (int) $matches[1] );
			if ( ! $post instanceof \WP_Post ) {
				throw new ContractException( 'evidence_stale', 'Editor document is unavailable.' );
			}
			return hash( 'sha256', json_encode( array( $post->ID, $post->post_type, $post->post_modified_gmt, hash( 'sha256', $post->post_content ) ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
		}
		if ( ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $document_ref, $matches ) ) {
			throw new ContractException( 'evidence_stale', 'Editor template identity is invalid.' );
		}
		$template = GutenbergTemplateSource::read( $document_ref );
		if ( ! $template instanceof \WP_Block_Template ) {
			throw new ContractException( 'evidence_stale', 'Editor template is unavailable.' );
		}
		$assignment = apply_filters( 'directorist_ai_gutenberg_template_assignment_revision', null, $document_ref );
		return hash( 'sha256', json_encode( array( $document_ref, $template->id, $template->source, $template->content, $template->status, $template->theme, $template->area, $template->modified ?? null, $assignment ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	private static function tokens( $values ): array {
		if ( true === $values ) {
			return array( '*' );
		}
		if ( false === $values ) {
			return array();
		}
		if ( ! is_array( $values ) ) {
			throw new ContractException( 'evidence_stale', 'Editor allowed-block evidence is invalid.' );
		}
		if ( count( $values ) > 500 ) {
			throw new ContractException( 'evidence_stale', 'Editor allowed-block evidence is invalid.' );
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! ProviderRegistration::valid_token( $value ) ) {
				throw new ContractException( 'evidence_stale', 'Editor allowed-block evidence is invalid.' );
			}
		}
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	private static function blocks( $blocks ): array {
		if ( ! is_array( $blocks ) || count( $blocks ) > 100 ) {
			throw new ContractException( 'evidence_stale', 'Editor block evidence is invalid.' );
		}
		$allowed = array( 'allowedBlocks', 'ancestor', 'attributes', 'name', 'parent', 'providesContext', 'selectors', 'styles', 'supports', 'usesContext' );
		$result  = array();
		$names   = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['name'] ) || ! is_string( $block['name'] ) || ! ProviderRegistration::valid_token( $block['name'] ) || isset( $names[ $block['name'] ] ) || array_diff( array_keys( $block ), $allowed ) || ! self::valid_block_maps( $block ) ) {
				throw new ContractException( 'evidence_stale', 'Editor block evidence is invalid.' );
			}
			$names[ $block['name'] ] = true;
			$result[]                = json_decode( json_encode( $block, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 24, JSON_THROW_ON_ERROR );
		}
		usort( $result, static fn( object $left, object $right ): int => $left->name <=> $right->name );
		return $result;
	}

	private static function valid_block_maps( array $block ): bool {
		foreach ( array( 'attributes', 'providesContext', 'selectors', 'supports' ) as $field ) {
			if ( isset( $block[ $field ] ) && ! is_array( $block[ $field ] ) ) {
				return false;
			}
		}
		foreach ( array( 'allowedBlocks', 'ancestor', 'parent', 'styles', 'usesContext' ) as $field ) {
			if ( isset( $block[ $field ] ) && ! is_array( $block[ $field ] ) ) {
				return false;
			}
		}
		foreach ( array( 'allowedBlocks', 'ancestor', 'parent', 'usesContext' ) as $field ) {
			foreach ( (array) ( $block[ $field ] ?? array() ) as $item ) {
				if ( ! is_string( $item ) || ! ProviderRegistration::valid_token( $item ) ) {
					return false;
				}
			}
		}
		foreach ( (array) ( $block['providesContext'] ?? array() ) as $key => $attribute ) {
			if ( ! is_string( $key ) || ! ProviderRegistration::valid_token( $key ) || ! is_string( $attribute ) || ! ProviderRegistration::valid_token( $attribute ) ) {
				return false;
			}
		}
		foreach ( (array) ( $block['attributes'] ?? array() ) as $attribute ) {
			if ( ! is_array( $attribute ) || array_diff( array_keys( $attribute ), array( '__experimentalRole', 'attribute', 'default', 'description', 'enabled', 'enum', 'items', 'query', 'role', 'selector', 'source', 'type' ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function variations( $variations ): array {
		if ( ! is_array( $variations ) || count( $variations ) > 20 ) {
			throw new ContractException( 'evidence_stale', 'Editor variation evidence is invalid.' );
		}
		$result = array();
		$names  = array();
		foreach ( $variations as $variation ) {
			if ( ! is_array( $variation ) || array_diff( array_keys( $variation ), array( 'attributes', 'isActive', 'name', 'scope' ) ) || ! isset( $variation['name'] ) || ! is_string( $variation['name'] ) || isset( $names[ $variation['name'] ] ) || ! in_array( $variation['name'], array( 'group', 'group-row', 'group-stack', 'group-grid' ), true ) ) {
				throw new ContractException( 'evidence_stale', 'Editor variation evidence is invalid.' );
			}
			$names[ $variation['name'] ] = true;
			unset( $variation['isActive'] );
			$result[] = json_decode( json_encode( $variation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 16, JSON_THROW_ON_ERROR );
		}
		usort( $result, static fn( object $left, object $right ): int => $left->name <=> $right->name );
		return $result;
	}

	/** Preserve a bounded dirty block tree with epoch-bound client identities. */
	private static function document_blocks( $blocks ): ?array {
		if ( null === $blocks ) {
			return null;
		}
		if ( ! is_array( $blocks ) ) {
			throw new ContractException( 'evidence_stale', 'Dirty editor block evidence is invalid.' );
		}
		$count  = 0;
		$walk   = static function ( array $items, int $depth ) use ( &$walk, &$count ): array {
			if ( $depth > 24 || count( $items ) > 500 ) {
				throw new ContractException( 'needs_expansion', 'Dirty editor block evidence exceeds its bound.' );
			}
			$result = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'name', 'clientId', 'attributes', 'innerBlocks' ) ) || ! isset( $item['name'], $item['clientId'], $item['attributes'], $item['innerBlocks'] ) || ! is_string( $item['name'] ) || ! ProviderRegistration::valid_token( $item['name'] ) || ! is_string( $item['clientId'] ) || ! ProviderRegistration::valid_token( $item['clientId'] ) || ! is_array( $item['attributes'] ) || ! is_array( $item['innerBlocks'] ) || ++$count > 2000 ) {
					throw new ContractException( 'evidence_stale', 'Dirty editor block evidence is invalid.' );
				}
				$copy     = (object) array(
					'name'        => $item['name'],
					'clientId'    => $item['clientId'],
					'attributes'  => json_decode( json_encode( $item['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 24, JSON_THROW_ON_ERROR ),
					'innerBlocks' => $walk( $item['innerBlocks'], $depth + 1 ),
				);
				$result[] = $copy;
			}
			return $result;
		};
		$result = $walk( $blocks, 0 );
		( new JsonDecoder( 5242880, 32 ) )->check( $result );
		return $result;
	}
}
