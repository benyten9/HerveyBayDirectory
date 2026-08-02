<?php
/**
 * Migrate legacy {token} placeholders to {{sales:slug}} merge tags.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

/**
 * SalesEmailLegacyTokens helper.
 */
final class SalesEmailLegacyTokens {

	/**
	 * @param string $document_type proposal|invoice|credit_note|contract|subscription.
	 * @return array<string, string> Legacy key => sales merge-tag slug.
	 */
	private static function map_for_document( string $document_type ): array {
		$shared = array(
			'contact_name' => 'customer_name',
			'company_name' => 'company_name',
		);

		switch ( $document_type ) {
			case 'proposal':
				return array_merge(
					$shared,
					array(
						'subject'          => 'proposal_subject',
						'proposal_number'  => 'proposal_number',
						'total'            => 'proposal_total',
						'open_till'        => 'proposal_open_till',
						'proposal_link'    => 'proposal_url',
						'public_url'       => 'proposal_url',
					)
				);
			case 'invoice':
				return array_merge(
					$shared,
					array(
						'invoice_number' => 'invoice_number',
						'total'          => 'invoice_balance',
						'balance'        => 'invoice_balance',
						'due_date'       => 'invoice_due_date',
						'invoice_link'   => 'invoice_url',
						'public_url'     => 'invoice_url',
					)
				);
			case 'credit_note':
				return array_merge(
					$shared,
					array(
						'credit_note_number' => 'credit_note_number',
						'total'              => 'credit_note_total',
						'remaining'          => 'credit_note_remaining',
						'credit_note_date'   => 'credit_note_date',
						'credit_note_link'   => 'credit_note_url',
						'public_url'         => 'credit_note_url',
					)
				);
			case 'contract':
				return array_merge(
					$shared,
					array(
						'subject'          => 'contract_subject',
						'contract_number'  => 'contract_number',
						'contract_value'   => 'contract_value',
						'end_date'         => 'contract_end_date',
						'contract_link'    => 'contract_url',
						'public_url'       => 'contract_url',
					)
				);
			case 'subscription':
				return array_merge(
					$shared,
					array(
						'subscription_name' => 'subscription_name',
						'amount'            => 'subscription_amount',
						'subscription_link' => 'subscription_url',
						'public_url'        => 'subscription_url',
					)
				);
			default:
				return $shared;
		}
	}

	/**
	 * Convert `{legacy_key}` placeholders to `{{sales:slug}}` for backward compatibility.
	 *
	 * @param string $text          Template text.
	 * @param string $document_type Document context.
	 * @return string
	 */
	public static function migrate( string $text, string $document_type ): string {
		if ( '' === $text || false === strpos( $text, '{' ) ) {
			return $text;
		}

		foreach ( self::map_for_document( $document_type ) as $legacy => $slug ) {
			$text = str_replace( '{' . $legacy . '}', '{{sales:' . $slug . '}}', $text );
		}

		return $text;
	}
}
