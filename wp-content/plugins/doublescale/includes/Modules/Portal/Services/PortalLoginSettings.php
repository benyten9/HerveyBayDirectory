<?php
/**
 * Client Portal → the login page's branding.
 *
 * The login page carries the business's own brand, never the product's: the logo
 * from Settings → Business (at the width the admin picks) and/or a heading that
 * defaults to the business name, then the site title.
 *
 * Persisted under `doublescale_settings['client_portal']['login']`, next to the keys
 * the other portal settings own; only this class's key is written here.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * PortalLoginSettings.
 */
final class PortalLoginSettings {

	/** Settings key shared with the other client portal settings. */
	public const SETTINGS_KEY = 'client_portal';

	/** Logo width bounds and default, in CSS pixels. */
	public const MIN_WIDTH     = 40;
	public const MAX_WIDTH     = 360;
	public const DEFAULT_WIDTH = 140;

	/** Longest heading the login page shows. */
	public const MAX_HEADING = 80;

	/** Longest text in a login text field. */
	public const MAX_TEXT = 300;

	/** Points in the brand panel, and the longest one. */
	public const MAX_POINTS = 4;
	public const MAX_POINT  = 60;

	/** The texts an admin can write over; empty keeps the default. */
	public const TEXT_FIELDS = array( 'title', 'subtitle', 'panel_title', 'panel_text' );

	/**
	 * The business's name and logo, as Settings → Business holds them.
	 *
	 * @return array{name: string, logo: string}
	 */
	public static function business(): array {
		$name = '';
		$logo = '';
		if ( class_exists( \DoubleScale\Modules\Documents\Services\DocumentPdf::class ) ) {
			$b    = \DoubleScale\Modules\Documents\Services\DocumentPdf::branding_config();
			$name = trim( (string) ( $b['business_name'] ?? '' ) );
			$logo = trim( (string) ( $b['business_logo'] ?? '' ) );
		}
		return array(
			'name' => $name,
			'logo' => $logo,
		);
	}

	/**
	 * Stored values with defaults.
	 *
	 * @return array<string, mixed> show_logo, logo_width, heading, the texts ('' = default) and points ([] = default).
	 */
	public static function stored(): array {
		$raw   = Settings::get( self::SETTINGS_KEY, array() );
		$login = is_array( $raw ) && isset( $raw['login'] ) && is_array( $raw['login'] ) ? $raw['login'] : array();
		$out   = array(
			'show_logo'  => ! array_key_exists( 'show_logo', $login ) || (bool) $login['show_logo'],
			'logo_width' => self::clamp_width( $login['logo_width'] ?? self::DEFAULT_WIDTH ),
			'heading'    => (string) ( $login['heading'] ?? '' ),
			'points'     => array_values( array_filter( array_map( 'strval', (array) ( $login['points'] ?? array() ) ) ) ),
		);
		foreach ( self::TEXT_FIELDS as $field ) {
			$out[ $field ] = (string) ( $login[ $field ] ?? '' );
		}
		return $out;
	}

	/**
	 * The login page's texts before the admin changes them. The panel's
	 * points name only what the portal shows a client by default, so a site
	 * without bookings never promises bookings.
	 *
	 * @return array{title: string, subtitle: string, panel_title: string, panel_text: string, points: string[]}
	 */
	public static function default_texts(): array {
		$access = PortalAccess::for_contact( null );
		$on     = static function ( string $key ) use ( $access ): bool {
			return ! empty( $access[ $key ] );
		};

		$points = array();
		if ( $on( 'documents' ) || $on( 'contracts' ) ) {
			$points[] = __( 'Pay and sign online', 'doublescale' );
		}
		if ( $on( 'projects' ) ) {
			$points[] = __( 'Follow your projects', 'doublescale' );
		}
		if ( $on( 'appointments' ) ) {
			$points[] = __( 'Book meetings', 'doublescale' );
		}
		if ( $on( 'requests' ) ) {
			$points[] = __( 'Get support', 'doublescale' );
		}
		if ( $on( 'files' ) ) {
			$points[] = __( 'Share files with us', 'doublescale' );
		}

		return array(
			'title'       => __( 'Welcome back', 'doublescale' ),
			'subtitle'    => __( 'Sign in to your client portal.', 'doublescale' ),
			'panel_title' => __( 'Everything about your work with us, in one place.', 'doublescale' ),
			'panel_text'  => __( 'Sign in to see what is new.', 'doublescale' ),
			'points'      => array_slice( $points, 0, self::MAX_POINTS ),
		);
	}

	/**
	 * What the login page says: the admin's text where written, else the default.
	 *
	 * @return array{title: string, subtitle: string, panel_title: string, panel_text: string, points: string[]}
	 */
	public static function texts(): array {
		$stored   = self::stored();
		$defaults = self::default_texts();
		$out      = array();
		foreach ( self::TEXT_FIELDS as $field ) {
			$out[ $field ] = '' !== trim( $stored[ $field ] ) ? trim( $stored[ $field ] ) : $defaults[ $field ];
		}
		$out['points'] = $stored['points'] ? $stored['points'] : $defaults['points'];
		return $out;
	}

	/**
	 * The name the login page falls back to: the business name, then the site title.
	 *
	 * @return string
	 */
	public static function default_heading(): string {
		$business = self::business();
		return '' !== $business['name'] ? $business['name'] : (string) get_bloginfo( 'name' );
	}

	/**
	 * What the login page shows: a logo (URL + width) when there is one to show, and the
	 * heading. With a logo, the heading only shows when the admin wrote one.
	 *
	 * @param string $override Shortcode `title` attribute, which wins over everything.
	 * @return array{logo: string, logo_width: int, heading: string, alt: string}
	 */
	public static function brand( string $override = '' ): array {
		$s        = self::stored();
		$business = self::business();
		$logo     = $s['show_logo'] ? $business['logo'] : '';
		$override = trim( $override );

		if ( '' !== $override ) {
			$heading = $override;
		} elseif ( '' !== trim( $s['heading'] ) ) {
			$heading = trim( $s['heading'] );
		} else {
			$heading = '' !== $logo ? '' : self::default_heading();
		}

		/**
		 * Filter the brand heading shown above the Client Portal login gate.
		 *
		 * @param string $heading Brand heading text ('' shows the logo alone).
		 */
		$heading = (string) apply_filters( 'doublescale_client_portal_brand_title', $heading );

		return array(
			'logo'       => $logo,
			'logo_width' => $s['logo_width'],
			'heading'    => $heading,
			'alt'        => self::default_heading(),
		);
	}

	/**
	 * Settings payload for the admin screen (with what the preview needs).
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$business = self::business();
		return array_merge(
			self::stored(),
			array(
				'business_logo'   => $business['logo'],
				'default_heading' => self::default_heading(),
				'min_width'       => self::MIN_WIDTH,
				'max_width'       => self::MAX_WIDTH,
				'max_heading'     => self::MAX_HEADING,
				'max_text'        => self::MAX_TEXT,
				'max_points'      => self::MAX_POINTS,
				'max_point'       => self::MAX_POINT,
				'defaults'        => self::default_texts(),
			)
		);
	}

	/**
	 * Save the login branding.
	 *
	 * @param array<string, mixed> $input `show_logo`, `logo_width`, `heading`, the texts and `points`.
	 *                                   A text left out of the input keeps its saved value.
	 * @return array<string, mixed>
	 */
	public static function update( array $input ): array {
		$raw = Settings::get( self::SETTINGS_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$old = self::stored();

		$login = array(
			'show_logo'  => ! empty( $input['show_logo'] ),
			'logo_width' => self::clamp_width( $input['logo_width'] ?? self::DEFAULT_WIDTH ),
			'heading'    => self::cut( sanitize_text_field( (string) ( $input['heading'] ?? '' ) ), self::MAX_HEADING ),
		);
		foreach ( self::TEXT_FIELDS as $field ) {
			$login[ $field ] = array_key_exists( $field, $input )
				? self::cut( sanitize_textarea_field( (string) $input[ $field ] ), self::MAX_TEXT )
				: $old[ $field ];
		}
		$points = array_key_exists( 'points', $input ) ? (array) $input['points'] : $old['points'];
		$points = array_map(
			static function ( $point ) {
				return self::cut( sanitize_text_field( (string) $point ), self::MAX_POINT );
			},
			$points
		);
		$login['points'] = array_slice( array_values( array_filter( $points, 'strlen' ) ), 0, self::MAX_POINTS );

		$raw['login'] = $login;
		Settings::update( self::SETTINGS_KEY, $raw );

		return self::settings();
	}

	/**
	 * Trim a text and keep it within a length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function cut( string $text, int $max ): string {
		$text = trim( $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Keep a width inside the allowed range.
	 *
	 * @param mixed $width Raw width.
	 * @return int
	 */
	private static function clamp_width( $width ): int {
		return max( self::MIN_WIDTH, min( self::MAX_WIDTH, (int) $width ) );
	}
}
