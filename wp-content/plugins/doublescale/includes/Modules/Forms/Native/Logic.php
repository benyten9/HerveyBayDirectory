<?php
/**
 * Conditional logic, run on the server exactly as the renderer runs it in the browser
 * (src/shared/forms/logic.ts). The server decides what counts: a hidden field's answer is
 * dropped and a hidden field is never required, whatever the browser sent.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

/**
 * Logic evaluator.
 */
final class Logic {

	/**
	 * Which steps and fields are hidden for these answers.
	 *
	 * A target with a "show" rule stays hidden until one of its show rules matches; a "hide"
	 * rule that matches hides it. A field in a hidden step is hidden too.
	 *
	 * @param array $schema Normalized definition.
	 * @param array $values Answers keyed by field id.
	 * @return array{steps: array<string, bool>, fields: array<string, bool>}
	 */
	public static function hidden( array $schema, array $values ): array {
		$hidden = array(
			'steps'  => array(),
			'fields' => array(),
		);
		// Only show/hide rules with a target take part (and set the number of passes: the
		// browser counts the same rules, so rules that hide each other end the same way).
		$rules = array_values(
			array_filter(
				(array) ( $schema['rules'] ?? array() ),
				static function ( $rule ) {
					return is_array( $rule ) && in_array( $rule['action'] ?? '', array( 'show', 'hide' ), true ) && ! empty( $rule['target']['id'] );
				}
			)
		);
		if ( empty( $rules ) ) {
			return $hidden;
		}

		// Repeat until nothing changes: a rule may read a field another rule hides, in chains
		// of any depth. Capped (one pass per show/hide rule) so rules that hide each other
		// can't loop forever. The browser (logic.ts) runs the same loop, so both always agree.
		$limit = count( $rules );
		for ( $pass = 0; $pass <= $limit; $pass++ ) {
			$visible_values = $values;
			foreach ( $hidden['fields'] as $field_id => $is_hidden ) {
				if ( $is_hidden ) {
					$visible_values[ $field_id ] = null;
				}
			}

			$shows = array();
			$hides = array();
			foreach ( $rules as $rule ) {
				$key = ( 'step' === ( $rule['target']['type'] ?? 'field' ) ? 'step' : 'field' ) . ':' . $rule['target']['id'];
				$met = self::matches( $rule['when'], $visible_values );
				if ( 'show' === $rule['action'] ) {
					$shows[ $key ] = ( $shows[ $key ] ?? false ) || $met;
				} elseif ( $met ) {
					$hides[ $key ] = true;
				}
			}

			$next = array(
				'steps'  => array(),
				'fields' => array(),
			);
			foreach ( $schema['steps'] as $step ) {
				$step_key    = 'step:' . $step['id'];
				$step_hidden = ( isset( $shows[ $step_key ] ) && ! $shows[ $step_key ] ) || isset( $hides[ $step_key ] );
				if ( $step_hidden ) {
					$next['steps'][ $step['id'] ] = true;
				}
				foreach ( $step['fields'] as $field ) {
					$field_key = 'field:' . $field['id'];
					if ( $step_hidden || ( isset( $shows[ $field_key ] ) && ! $shows[ $field_key ] ) || isset( $hides[ $field_key ] ) ) {
						$next['fields'][ $field['id'] ] = true;
					}
				}
			}
			$settled = $next == $hidden; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Same keys, order may differ.
			$hidden  = $next;
			if ( $settled ) {
				break;
			}
		}

		return $hidden;
	}

	/**
	 * The first redirect, message or thank-you-screen rule these answers meet.
	 *
	 * Rules that can't do anything are skipped, so the next one (or the form's default) is
	 * used: a redirect without an address, a message without text, a thank-you screen that
	 * was deleted.
	 *
	 * @param array $schema Normalized definition.
	 * @param array $values Answers keyed by field id (hidden fields already null).
	 * @return array|null The rule.
	 */
	public static function outcome( array $schema, array $values ): ?array {
		$endings = array();
		foreach ( (array) ( $schema['screens']['endings'] ?? array() ) as $ending ) {
			$endings[ (string) ( $ending['id'] ?? '' ) ] = true;
		}
		foreach ( (array) ( $schema['rules'] ?? array() ) as $rule ) {
			$action = (string) ( $rule['action'] ?? '' );
			if ( ! in_array( $action, array( 'redirect', 'message', 'ending' ), true ) ) {
				continue;
			}
			if ( 'redirect' === $action && '' === trim( (string) ( $rule['url'] ?? '' ) ) ) {
				continue;
			}
			if ( 'message' === $action && '' === trim( (string) ( $rule['message'] ?? '' ) ) ) {
				continue;
			}
			if ( 'ending' === $action && ! isset( $endings[ (string) ( $rule['target']['id'] ?? '' ) ] ) ) {
				continue;
			}
			if ( self::matches( (array) ( $rule['when'] ?? array() ), $values ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Whether a rule's condition groups are met.
	 *
	 * @param array $when   { match, groups: [ { match, conditions } ] }.
	 * @param array $values Answers keyed by field id.
	 * @return bool
	 */
	public static function matches( array $when, array $values ): bool {
		$groups = (array) ( $when['groups'] ?? array() );
		if ( empty( $groups ) ) {
			return false;
		}
		$any = 'any' === ( $when['match'] ?? 'all' );

		foreach ( $groups as $group ) {
			$met = self::group_matches( $group, $values );
			if ( $any && $met ) {
				return true;
			}
			if ( ! $any && ! $met ) {
				return false;
			}
		}

		return ! $any;
	}

	/**
	 * Whether one group of conditions is met.
	 *
	 * @param array $group  { match, conditions }.
	 * @param array $values Answers keyed by field id.
	 * @return bool
	 */
	private static function group_matches( array $group, array $values ): bool {
		$conditions = (array) ( $group['conditions'] ?? array() );
		if ( empty( $conditions ) ) {
			return false;
		}
		$any = 'any' === ( $group['match'] ?? 'all' );

		foreach ( $conditions as $condition ) {
			$met = self::condition_matches( $condition, $values[ $condition['field'] ] ?? null );
			if ( $any && $met ) {
				return true;
			}
			if ( ! $any && ! $met ) {
				return false;
			}
		}

		return ! $any;
	}

	/**
	 * Whether one condition is met by an answer.
	 *
	 * @param array $condition { field, op, value }.
	 * @param mixed $answer    The answer.
	 * @return bool
	 */
	public static function condition_matches( array $condition, $answer ): bool {
		$op       = (string) ( $condition['op'] ?? 'is' );
		$expected = self::lower( trim( (string) ( $condition['value'] ?? '' ) ) );
		$items    = self::items( $answer );
		$is_empty = empty( $items );

		switch ( $op ) {
			case 'empty':
				return $is_empty;
			case 'not_empty':
				return ! $is_empty;
			case 'is':
				return in_array( $expected, $items, true );
			case 'is_not':
				return ! in_array( $expected, $items, true );
			case 'contains':
				foreach ( $items as $item ) {
					if ( '' !== $expected && false !== strpos( $item, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'not_contains':
				foreach ( $items as $item ) {
					if ( '' !== $expected && false !== strpos( $item, $expected ) ) {
						return false;
					}
				}
				return true;
			case 'starts_with':
				foreach ( $items as $item ) {
					if ( '' !== $expected && 0 === strpos( $item, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'ends_with':
				foreach ( $items as $item ) {
					if ( '' !== $expected && substr( $item, -strlen( $expected ) ) === $expected ) {
						return true;
					}
				}
				return false;
			case 'gt':
			case 'lt':
				if ( $is_empty ) {
					return false;
				}
				// Dates ("is after" / "is before"): two real YYYY-MM-DD days compare as text.
				if ( self::is_day( $items[0] ) && self::is_day( $expected ) ) {
					return 'gt' === $op ? strcmp( $items[0], $expected ) > 0 : strcmp( $items[0], $expected ) < 0;
				}
				if ( ! is_numeric( $items[0] ) || ! is_numeric( $expected ) ) {
					return false;
				}
				return 'gt' === $op ? (float) $items[0] > (float) $expected : (float) $items[0] < (float) $expected;
		}

		return false;
	}

	/**
	 * An answer as lowercase strings: one per choice, one per part of a name or address.
	 *
	 * @param mixed $answer Answer.
	 * @return string[]
	 */
	private static function items( $answer ): array {
		if ( null === $answer || '' === $answer || false === $answer ) {
			return array();
		}
		if ( true === $answer ) {
			return array( 'true', 'yes', '1' );
		}
		$values = is_array( $answer ) ? array_values( $answer ) : array( $answer );
		$out    = array();
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$out[] = self::lower( trim( (string) $value ) );
			}
		}
		return $out;
	}

	/**
	 * Whether the text is a real calendar day written YYYY-MM-DD.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function is_day( string $text ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m ) && (int) $m[1] >= 1 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Lowercase, letters with accents too ("É" is "é"), as the browser's toLowerCase() does.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
