<?php
/**
 * Migrate legacy {token} placeholders in sales-rep notification templates.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

/**
 * SalesRepNotificationLegacyTokens helper.
 */
final class SalesRepNotificationLegacyTokens {

	/**
	 * @return array<string, string> Legacy key => sales merge-tag slug.
	 */
	private static function token_map(): array {
		return array(
			'event_label'             => 'event_label',
			'company_name'            => 'company_name',
			'sales_link'              => 'admin_link',
			'decline_reason_suffix'   => 'decline_reason_suffix',
			'proposal_number'         => 'proposal_number',
			'proposal_subject'        => 'proposal_subject',
			'invoice_number'          => 'invoice_number',
			'contract_number'         => 'contract_number',
			'contract_subject'        => 'contract_subject',
		);
	}

	/**
	 * Convert `{legacy_key}` placeholders to `{{sales:slug}}`.
	 *
	 * @param string $text Template text.
	 * @return string
	 */
	public static function migrate( string $text ): string {
		if ( '' === $text || false === strpos( $text, '{' ) ) {
			return $text;
		}

		foreach ( self::token_map() as $legacy => $slug ) {
			$text = str_replace( '{' . $legacy . '}', '{{sales:' . $slug . '}}', $text );
		}

		return $text;
	}

	/**
	 * @param string $text Template text.
	 * @return bool
	 */
	public static function contains_legacy_tokens( string $text ): bool {
		return (bool) preg_match( '/\{[a-z_]+\}/', $text );
	}
}
