<?php
/**
 * Section-provider registry for the Client Portal.
 *
 * PHP owns *whether a section exists and what data it serves*; the renderer owns
 * *how it looks* (a static slug → React component registry). Each domain module
 * contributes a section descriptor via the `doublescale_portal_sections` filter:
 *
 *     add_filter( 'doublescale_portal_sections', function ( array $sections ) {
 *         $sections[] = array(
 *             'slug'         => 'bookings',
 *             'label'        => __( 'Bookings', 'doublescale' ),
 *             'icon'         => 'calendar',
 *             'order'        => 20,
 *             'is_available' => static fn() => doublescale_is_module_active( 'booking' ),
 *             'badge'        => static fn( $contact ) => 0, // upcoming count
 *         );
 *         return $sections;
 *     } );
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * PortalSections helper.
 */
final class PortalSections {

	/**
	 * Raw, unfiltered section descriptors contributed by every module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Filter the registered Client Portal sections.
		 *
		 * @param array<int, array<string, mixed>> $sections Section descriptors.
		 */
		$sections = (array) apply_filters( 'doublescale_portal_sections', array() );

		return array_values( array_filter( $sections, 'is_array' ) );
	}

	/**
	 * Resolve the visible sections for a contact: keep only available ones,
	 * compute badge counts, sort by `order`, and strip the PHP-only callables.
	 *
	 * @param ContactModel|null $contact Resolved contact (may be null).
	 * @return array<int, array<string, mixed>> Renderer-ready section list.
	 */
	public static function for_contact( ?ContactModel $contact ): array {
		$resolved = array();

		foreach ( self::all() as $section ) {
			$slug = isset( $section['slug'] ) ? sanitize_key( (string) $section['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			if ( ! self::is_available( $section ) ) {
				continue;
			}

			$resolved[] = array(
				'slug'  => $slug,
				'label' => isset( $section['label'] ) ? (string) $section['label'] : ucfirst( $slug ),
				'icon'  => isset( $section['icon'] ) ? sanitize_key( (string) $section['icon'] ) : $slug,
				'order' => isset( $section['order'] ) ? (int) $section['order'] : 50,
				'badge' => self::resolve_badge( $section, $contact ),
			);
		}

		usort(
			$resolved,
			static function ( $a, $b ) {
				if ( $a['order'] === $b['order'] ) {
					return strcmp( (string) $a['slug'], (string) $b['slug'] );
				}
				return $a['order'] <=> $b['order'];
			}
		);

		return $resolved;
	}

	/**
	 * Whether a section descriptor reports itself available right now.
	 *
	 * @param array<string, mixed> $section Section descriptor.
	 * @return bool
	 */
	private static function is_available( array $section ): bool {
		if ( isset( $section['is_available'] ) && is_callable( $section['is_available'] ) ) {
			return (bool) call_user_func( $section['is_available'] );
		}

		return true;
	}

	/**
	 * Resolve a section's badge count for the contact (0 on any failure).
	 *
	 * @param array<string, mixed> $section Section descriptor.
	 * @param ContactModel|null    $contact Resolved contact.
	 * @return int
	 */
	private static function resolve_badge( array $section, ?ContactModel $contact ): int {
		if ( ! isset( $section['badge'] ) || ! is_callable( $section['badge'] ) ) {
			return 0;
		}

		try {
			return max( 0, (int) call_user_func( $section['badge'], $contact ) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
