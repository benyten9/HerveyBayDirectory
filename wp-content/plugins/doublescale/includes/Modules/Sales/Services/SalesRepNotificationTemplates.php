<?php
/**
 * Editable templates for sales-rep in-app / email notifications.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * SalesRepNotificationTemplates service.
 */
final class SalesRepNotificationTemplates {

	/**
	 * @return array<string, array{title: string, message: string}>
	 */
	public static function defaults(): array {
		return array(
			NotificationCategories::SALES_PROPOSAL_SENT     => array(
				'title'   => '{{sales:event_label}}: {{sales:proposal_number}}',
				'message' => '{{sales:proposal_number}} — {{sales:proposal_subject}}',
			),
			NotificationCategories::SALES_PROPOSAL_ACCEPTED => array(
				'title'   => '{{sales:event_label}}: {{sales:proposal_number}}',
				'message' => '{{sales:proposal_number}} — {{sales:proposal_subject}}',
			),
			NotificationCategories::SALES_PROPOSAL_DECLINED => array(
				'title'   => '{{sales:event_label}}: {{sales:proposal_number}}',
				'message' => '{{sales:proposal_number}} — {{sales:proposal_subject}}{{sales:decline_reason_suffix}}',
			),
			NotificationCategories::SALES_INVOICE_PAID      => array(
				'title'   => __( 'Invoice paid: {{sales:invoice_number}}', 'doublescale' ),
				'message' => __( 'Invoice {{sales:invoice_number}} has been paid in full.', 'doublescale' ),
			),
			NotificationCategories::SALES_CONTRACT_SENT     => array(
				'title'   => '{{sales:event_label}}: {{sales:contract_number}}',
				'message' => '{{sales:contract_number}} — {{sales:contract_subject}}',
			),
			NotificationCategories::SALES_CONTRACT_SIGNED   => array(
				'title'   => '{{sales:event_label}}: {{sales:contract_number}}',
				'message' => '{{sales:contract_number}} — {{sales:contract_subject}}',
			),
		);
	}

	/**
	 * @param string $subcategory Notification subcategory key.
	 * @return array{title: string, message: string}
	 */
	public static function get_template( string $subcategory ): array {
		$defaults = self::defaults();
		$stored   = SalesSettings::get( 'rep_notification_templates', array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$base     = $defaults[ $subcategory ] ?? array(
			'title'   => '',
			'message' => '',
		);
		$custom   = isset( $stored[ $subcategory ] ) && is_array( $stored[ $subcategory ] )
			? $stored[ $subcategory ]
			: array();

		return array(
			'title'   => self::normalize_template_part(
				'' !== trim( (string) ( $custom['title'] ?? '' ) )
					? (string) $custom['title']
					: (string) $base['title']
			),
			'message' => self::normalize_template_part(
				'' !== trim( (string) ( $custom['message'] ?? '' ) )
					? (string) $custom['message']
					: (string) $base['message']
			),
		);
	}

	/**
	 * @param string               $subcategory Subcategory key.
	 * @param array<string, mixed> $context Proposal / invoice / contract context.
	 * @return array{title: string, message: string}
	 */
	public static function render( string $subcategory, array $context ): array {
		$template      = self::get_template( $subcategory );
		$merge_context = SalesEmailMergeTags::for_rep_notification( $context, $subcategory );

		return array(
			'title'   => self::resolve_part( $template['title'], $merge_context ),
			'message' => self::resolve_part( $template['message'], $merge_context ),
		);
	}

	/**
	 * @param string $text Template part.
	 * @return string
	 */
	public static function normalize_template_part( string $text ): string {
		if ( SalesRepNotificationLegacyTokens::contains_legacy_tokens( $text ) ) {
			return SalesRepNotificationLegacyTokens::migrate( $text );
		}

		return $text;
	}

	/**
	 * @param string $text          Template part with merge tags.
	 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $merge_context Context.
	 * @return string
	 */
	private static function resolve_part( string $text, $merge_context ): string {
		$text = self::normalize_template_part( $text );

		return SalesEmailMergeTags::resolve( $text, $merge_context );
	}

	/**
	 * @param mixed $templates Raw templates payload.
	 * @return array<string, array{title: string, message: string}>
	 */
	public static function sanitize_templates( $templates ): array {
		if ( ! is_array( $templates ) ) {
			return array();
		}

		$allowed = array_keys( self::defaults() );
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( ! isset( $templates[ $key ] ) || ! is_array( $templates[ $key ] ) ) {
				continue;
			}
			$row = $templates[ $key ];
			$clean[ $key ] = array(
				'title'   => isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '',
				'message' => isset( $row['message'] ) ? sanitize_textarea_field( (string) $row['message'] ) : '',
			);
		}

		return $clean;
	}
}
