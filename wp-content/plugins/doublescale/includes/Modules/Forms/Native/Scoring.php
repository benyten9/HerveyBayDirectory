<?php
/**
 * Lead scoring for native forms. Points live only in Lead Scoring rules (Pro); a form never
 * stores or rewrites them. The builder shows which rules mention the form (submitted it,
 * submitted any form, or answered one of its questions) and links to them.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Lead Scoring rules that concern a form.
 */
final class Scoring {

	/** Old: forms used to own one rule each (form id → rule id). Kept only to tidy up. */
	public const OPTION = 'doublescale_form_score_rules';

	private const MODEL = '\DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleModel';

	/**
	 * Whether Lead Scoring is on (Pro module with its table).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( self::MODEL ) && function_exists( 'doublescale_is_module_active' )
			&& doublescale_is_module_active( 'leadscoring' ) && doublescale_table_exists( 'lead_scoring_rules' );
	}

	/**
	 * The Lead Scoring rules that mention a form.
	 *
	 * @param FormModel $form Form.
	 * @return array<int, array{id: int, title: string, points: int, adding: bool, active: bool, match: string, detail: array|null}>
	 */
	public static function rules_for( FormModel $form ): array {
		if ( ! self::available() ) {
			return array();
		}
		$model  = self::MODEL;
		$fields = Schema::input_fields( Schema::effective( $form )['schema'] );
		$labels = array();
		foreach ( $fields as $field ) {
			$labels[ $field['key'] ] = (string) ( $field['label'] ?: $field['key'] );
		}
		$out = array();
		foreach ( $model::query()->orderBy( 'id' )->get() as $rule ) {
			$settings = is_array( $rule->settings ) ? $rule->settings : (array) json_decode( (string) $rule->settings, true );
			$match    = '';
			$detail   = null;
			foreach ( (array) ( $settings['conditions'] ?? array() ) as $group ) {
				foreach ( (array) $group as $condition ) {
					$slug  = (string) ( $condition['rule'] ?? '' );
					$value = (array) ( $condition['value'] ?? array() );
					if ( 'form_submission' === $slug ) {
						$ids = array_map( 'intval', (array) ( $value['form_ids'] ?? array() ) );
						if ( in_array( (int) $form->id, $ids, true ) ) {
							$match = $match ?: 'submitted';
						} elseif ( empty( $ids ) ) {
							$match = $match ?: 'any_form';
						}
					} elseif ( 'form_answer' === $slug && (int) ( $value['form_id'] ?? 0 ) === (int) $form->id ) {
						$match  = 'answer';
						$key    = (string) ( $value['field'] ?? '' );
						$detail = array(
							'field' => $labels[ $key ] ?? $key,
							'op'    => sanitize_key( (string) ( $value['op'] ?? 'is' ) ),
							'value' => (string) ( $value['value'] ?? '' ),
						);
					}
				}
			}
			if ( '' === $match ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $rule->id,
				'title'  => (string) $rule->title,
				'points' => (int) $rule->points,
				'adding' => (bool) $rule->is_adding,
				'active' => 'active' === (string) $rule->status,
				'match'  => $match,
				'detail' => $detail,
			);
		}
		return $out;
	}

	/**
	 * A form was deleted: its rules stay (they are Lead Scoring's), only the old link goes.
	 *
	 * @param int $form_id Form id.
	 * @return void
	 */
	public static function forget( int $form_id ): void {
		$map = (array) get_option( self::OPTION, array() );
		if ( isset( $map[ $form_id ] ) ) {
			unset( $map[ $form_id ] );
			update_option( self::OPTION, $map, false );
		}
	}
}
