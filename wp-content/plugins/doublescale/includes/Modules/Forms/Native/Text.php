<?php
/**
 * Merge tags in form messages, redirects and emails.
 *
 * Form tags: {{form:<field key>}}, {{form:all_answers}}, {{form_title}} ({{form_name}} still works), {{site_name}},
 * {{admin_email}}, {{submission_id}}. Contact tags ({{contact:first_name|there}}) go through
 * DoubleScale's merge-tag manager when the submission has a contact.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Merge tag rendering.
 */
final class Text {

	/**
	 * Replace merge tags.
	 *
	 * @param string    $text    Text with tags.
	 * @param FormModel $form    Form.
	 * @param array     $answers Answers keyed by field key.
	 * @param array     $fields  Input fields keyed by field id.
	 * @param mixed     $contact Contact model or null.
	 * @param array     $extra   More tags: name => value.
	 * @param string    $mode    html, text or url (how values are escaped).
	 * @return string
	 */
	public static function render( string $text, FormModel $form, array $answers, array $fields, $contact = null, array $extra = array(), string $mode = 'html' ): string {
		if ( false === strpos( $text, '{{' ) ) {
			return $text;
		}

		$by_key = array();
		foreach ( $fields as $field ) {
			$by_key[ $field['key'] ] = $field;
		}

		$escape = static function ( string $value ) use ( $mode ): string {
			return self::escape( $value, $mode );
		};

		$simple = array_merge(
			array(
				'form_title'  => (string) $form->name,
				'form_name'   => (string) $form->name, // Older name of {{form_title}}.
				'site_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'admin_email' => (string) get_option( 'admin_email' ),
			),
			$extra
		);

		// 1. Form tags become placeholders, so the visitor's answers are only put in at the
		// very end, after contact tags ran: a "{{contact:email}}" typed into an answer stays text.
		$values = array();
		$hold   = static function ( string $value ) use ( &$values ): string {
			$values[] = $value;
			return "\x1A" . ( count( $values ) - 1 ) . "\x1A";
		};

		$text = (string) preg_replace_callback(
			'/\{\{\s*form:([a-z0-9_]+)\s*\}\}/i',
			static function ( $match ) use ( $answers, $by_key, $escape, $mode, $hold ) {
				$key = strtolower( $match[1] );
				if ( 'all_answers' === $key ) {
					return $hold( self::all_answers( $answers, $by_key, $mode ) );
				}
				if ( ! isset( $by_key[ $key ] ) ) {
					return '';
				}
				return $hold( $escape( FieldTypes::readable( $by_key[ $key ], $answers[ $key ] ?? null ) ) );
			},
			$text
		);

		$text = (string) preg_replace_callback(
			'/\{\{\s*(form_title|form_name|site_name|admin_email|submission_id)\s*\}\}/',
			static function ( $match ) use ( $simple, $escape, $hold ) {
				return $hold( $escape( (string) ( $simple[ $match[1] ] ?? '' ) ) );
			},
			$text
		);

		// 2. Contact tags, on the manager's template only.
		if ( false !== strpos( $text, '{{' ) ) {
			if ( $contact && 'url' === $mode ) {
				// In an address each contact value is encoded on its own, like answers, so it
				// can't add query parameters or turn "/{{tag}}" into another site.
				$text = (string) preg_replace_callback(
					'/\{\{[^}]*\}\}/',
					static function ( $match ) use ( $contact ) {
						$value = (string) \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags( $match[0], $contact );
						return $value === $match[0] ? $match[0] : rawurlencode( $value );
					},
					$text
				);
			} elseif ( $contact ) {
				$text = (string) \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags( $text, $contact );
			}
			// Tags left over (no contact, unknown tag): their fallback, or nothing.
			$text = (string) preg_replace_callback(
				'/\{\{[^}|]*\|([^}]*)\}\}/',
				static function ( $match ) use ( $escape ) {
					return $escape( trim( $match[1] ) );
				},
				$text
			);
			$text = (string) preg_replace( '/\{\{[^}]*\}\}/', '', $text );
		}

		// 3. The answers.
		return (string) preg_replace_callback(
			"/\x1A(\d+)\x1A/",
			static function ( $match ) use ( $values ) {
				return $values[ (int) $match[1] ] ?? '';
			},
			$text
		);
	}

	/**
	 * Escape a value for where it goes. Braces in values are neutralised too, so no later
	 * step (an email template, a message channel) can read a visitor's text as a merge tag.
	 *
	 * @param string $value Value.
	 * @param string $mode  html, text or url.
	 * @return string
	 */
	public static function escape( string $value, string $mode ): string {
		if ( 'url' === $mode ) {
			return rawurlencode( $value );
		}
		$value = str_replace( "\x1A", '', $value );
		if ( 'html' === $mode ) {
			return str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), esc_html( $value ) );
		}
		return str_replace( array( '{{', '}}' ), array( '{ {', '} }' ), $value );
	}

	/**
	 * Every answer: an HTML table for emails, "Label: value" lines otherwise.
	 *
	 * @param array  $answers Answers keyed by field key.
	 * @param array  $by_key  Fields keyed by key.
	 * @param string $mode    html, text or url.
	 * @return string
	 */
	public static function all_answers( array $answers, array $by_key, string $mode = 'html' ): string {
		$rows = array();
		foreach ( $by_key as $key => $field ) {
			if ( ! array_key_exists( $key, $answers ) || ! FieldTypes::get( $field )->in_answers_list() ) {
				continue;
			}
			$value = FieldTypes::readable( $field, $answers[ $key ] );
			if ( '' === $value ) {
				continue;
			}
			$rows[] = array( '' !== $field['label'] ? $field['label'] : $key, $value );
		}

		if ( 'html' !== $mode ) {
			return self::escape(
				implode(
					"\n",
					array_map(
						static function ( $row ) {
							return $row[0] . ': ' . $row[1];
						},
						$rows
					)
				),
				$mode
			);
		}

		$html = '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:600px">';
		foreach ( $rows as $row ) {
			$html .= '<tr><td style="border-bottom:1px solid #e5e7eb;font-weight:600;vertical-align:top;width:35%">' . self::escape( $row[0], 'html' ) . '</td>'
				. '<td style="border-bottom:1px solid #e5e7eb;vertical-align:top">' . nl2br( self::escape( $row[1], 'html' ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}
}
