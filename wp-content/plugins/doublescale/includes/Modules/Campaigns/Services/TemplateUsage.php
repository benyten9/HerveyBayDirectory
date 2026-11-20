<?php
/**
 * What still depends on a template.
 *
 * A campaign and an automation step send from the template their settings'
 * `template_ids` names; a sent message shows the template it was rendered from
 * (communication_tracking.template_id). Deleting a template any of them uses
 * would leave it with nothing to send or show.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

/**
 * TemplateUsage class
 */
final class TemplateUsage {

	const CAMPAIGN   = 'campaign';
	const AUTOMATION = 'automation';
	const MESSAGES   = 'messages';

	/**
	 * The first thing found that uses the template, or null when nothing does.
	 *
	 * @param int $template_id Template.
	 * @return string|null One of the class constants.
	 */
	public static function find( $template_id ) {
		global $wpdb;

		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return null;
		}

		$sources = array(
			self::CAMPAIGN   => 'campaigns',
			self::AUTOMATION => 'automation_steps',
		);

		foreach ( $sources as $kind => $suffix ) {
			// Templates are shared; a module that was never on has no table to look in.
			if ( ! doublescale_table_exists( $suffix ) ) {
				continue;
			}
			$table = $wpdb->prefix . 'doublescale_' . $suffix;
			// LIKE only narrows the rows; the id is compared after decoding,
			// so template 5 is not "used" by a row naming template 55.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT settings FROM {$table} WHERE settings LIKE %s AND settings LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'%' . $wpdb->esc_like( '"template_ids"' ) . '%',
					'%' . $wpdb->esc_like( (string) $template_id ) . '%'
				)
			);

			foreach ( (array) $rows as $json ) {
				$settings = json_decode( (string) $json, true );
				$ids      = is_array( $settings['template_ids'] ?? null ) ? $settings['template_ids'] : array();

				if ( in_array( $template_id, array_map( 'intval', $ids ), true ) ) {
					return $kind;
				}
			}
		}

		$sent = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}doublescale_communication_tracking WHERE template_id = %d LIMIT 1",
				$template_id
			)
		);

		return $sent ? self::MESSAGES : null;
	}
}
