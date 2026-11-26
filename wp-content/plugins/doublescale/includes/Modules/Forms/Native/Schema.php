<?php
/**
 * The definition of a DoubleScale form: steps, fields, logic rules, settings and style.
 *
 * Everything the builder saves passes through normalize_*() here, so the renderer and the
 * submit pipeline can trust the shape. The TypeScript mirror is src/shared/forms/types.ts.
 *
 * Shape:
 *   schema   = { version, steps: [ { id, title, fields: [ Field ] } ], rules: [ Rule ], labels: {...} }
 *   Field    = { id, key, type, label, description, placeholder, required, width, default,
 *                options: [ {label, value} ], validation: {...}, props: {...}, map: {object, field}|null }
 *   Rule     = { id, action: show|hide|redirect|message, target: {type: field|step, id},
 *                url, message, when: { match: all|any, groups: [ { match, conditions: [ {field, op, value} ] } ] } }
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Form definition helpers.
 */
final class Schema {

	public const VERSION = 1;

	public const LAYOUTS = array( 'classic', 'multistep', 'conversational' );

	public const WIDTHS = array( 25, 33, 50, 66, 75, 100 );

	public const OPERATORS = array( 'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'empty', 'not_empty', 'gt', 'lt' );

	/**
	 * A new form's definition: name, email and message.
	 *
	 * @return array
	 */
	public static function default_schema(): array {
		return self::normalize_schema(
			array(
				'steps' => array(
					array(
						'id'     => 'step_1',
						'title'  => '',
						'fields' => array(
							array(
								'type'     => 'name',
								'key'      => 'name',
								'label'    => __( 'Name', 'doublescale' ),
								'required' => true,
								'map'      => array(
									'object' => 'contact',
									'field'  => 'name',
								),
							),
							array(
								'type'     => 'email',
								'key'      => 'email',
								'label'    => __( 'Email', 'doublescale' ),
								'required' => true,
								'map'      => array(
									'object' => 'contact',
									'field'  => 'email',
								),
							),
							array(
								'type'  => 'textarea',
								'key'   => 'message',
								'label' => __( 'Message', 'doublescale' ),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Default settings of a form.
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'after_submit'  => array(
				'type'         => 'message',
				'message'      => '<p>' . __( 'Thanks! We have received your answers.', 'doublescale' ) . '</p>',
				'redirect_url' => '',
				'new_tab'      => false,
			),
			'contact'       => array(
				'create'       => true,
				'update'       => 'fill_blank',
				'email_status' => 'subscribed',
				'lists'        => array(),
				'tags'         => array(),
				'owner_id'     => 0,
			),
			'notifications' => array(
				'admin'         => array(
					'enabled' => true,
					'to'      => '{{admin_email}}',
					'subject' => __( 'New submission: {{form_title}}', 'doublescale' ),
					'body'    => '{{form:all_answers}}',
				),
				'autoresponder' => array(
					'enabled' => false,
					'subject' => __( 'Thanks for getting in touch', 'doublescale' ),
					'body'    => '<p>' . __( 'Hi {{contact:first_name|there}}, we got your message and will reply soon.', 'doublescale' ) . '</p>',
				),
			),
			'spam'          => array(
				'honeypot'         => true,
				'min_seconds'      => 2,
				'captcha'          => 'none',
				'block_disposable' => true,
				'blocked_domains'  => '',
				'max_per_hour'     => 20,
			),
			'page'          => array(
				'title'       => '',
				'description' => '',
				'image'       => '',
				'noindex'     => true,
				'chrome'      => 'blank',
				'logo'        => '',
				'background'  => '#F5F3FF',
				'width'       => 640,
			),
			'scoring'       => array(
				'points' => 0,
			),
			'records'       => Records::defaults(),
			'popup'         => Popups::defaults(),
			'partial'       => array(
				'enabled'         => false,
				'abandon_minutes' => 60,
				'create_contact'  => false,
			),
			'access'        => array(
				'opens_at'       => '',
				'closes_at'      => '',
				'max_entries'    => 0,
				'one_per_person' => false,
				'logged_in'      => false,
				'password'       => '',
				'early_message'  => '<p>' . __( 'This form opens soon. Please come back later.', 'doublescale' ) . '</p>',
				'closed_message' => '<p>' . __( 'This form is closed. Thank you for your interest.', 'doublescale' ) . '</p>',
				'login_message'  => '<p>' . __( 'Please log in to fill in this form.', 'doublescale' ) . '</p>',
			),
		);
	}

	/**
	 * Default look of a form.
	 *
	 * @return array
	 */
	public static function default_style(): array {
		return array(
			'theme'             => 'default',
			'primary'           => '#6D28D9',
			'text'              => '#111827',
			'background'        => '#FFFFFF',
			'field_background'  => '#FFFFFF',
			'border'            => '#D1D5DB',
			'radius'            => 8,
			'font_size'         => 16,
			'button_align'      => 'full',
			'button_text_color' => '#FFFFFF',
			// Embedded forms line up with the page's text; the form's own page adds room itself.
			'padding'           => 0,
			'custom_css'        => '',
		);
	}

	/**
	 * Clean a definition coming from the builder.
	 *
	 * @param mixed $schema Raw definition.
	 * @return array
	 */
	public static function normalize_schema( $schema ): array {
		$schema = is_array( $schema ) ? $schema : array();
		$steps  = is_array( $schema['steps'] ?? null ) ? $schema['steps'] : array();
		$keys   = array();
		$ids    = array();
		$clean  = array();

		foreach ( array_values( $steps ) as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$step_id = self::id( $step['id'] ?? '', 'step', $ids );
			$fields  = array();
			foreach ( (array) ( $step['fields'] ?? array() ) as $field ) {
				$field = self::normalize_field( $field, $keys, $ids );
				if ( $field ) {
					$fields[] = $field;
				}
			}
			$clean[] = array(
				'id'          => $step_id,
				'title'       => self::text( $step['title'] ?? '' ),
				'next_label'  => self::text( $step['next_label'] ?? '' ),
				'description' => self::text( $step['description'] ?? '' ),
				'fields'      => $fields,
			);
			unset( $index );
		}

		if ( empty( $clean ) ) {
			$clean[] = array(
				'id'          => 'step_1',
				'title'       => '',
				'next_label'  => '',
				'description' => '',
				'fields'      => array(),
			);
		}

		$labels = is_array( $schema['labels'] ?? null ) ? $schema['labels'] : array();

		return array(
			'version' => self::VERSION,
			'steps'   => $clean,
			'rules'   => self::normalize_rules( $schema['rules'] ?? array() ),
			'screens' => self::normalize_screens( $schema['screens'] ?? array() ),
			'labels'  => array(
				'submit'   => self::text( $labels['submit'] ?? '' ) ?: __( 'Submit', 'doublescale' ),
				'next'     => self::text( $labels['next'] ?? '' ) ?: __( 'Next', 'doublescale' ),
				'back'     => self::text( $labels['back'] ?? '' ) ?: __( 'Back', 'doublescale' ),
				'ok'       => self::text( $labels['ok'] ?? '' ) ?: __( 'OK', 'doublescale' ),
				'required' => self::text( $labels['required'] ?? '' ) ?: __( 'This field is required.', 'doublescale' ),
				// Reworded validation messages and visitor texts (empty = the default text).
				'messages' => Texts::clean( $labels['messages'] ?? array() ),
			),
		);
	}

	/**
	 * Clean one field.
	 *
	 * @param mixed $field Raw field.
	 * @param array $keys  Keys used so far (by reference, keeps keys unique).
	 * @param array $ids   Ids used so far (by reference).
	 * @return array|null
	 */
	private static function normalize_field( $field, array &$keys, array &$ids ): ?array {
		if ( ! is_array( $field ) ) {
			return null;
		}
		$type = sanitize_key( (string) ( $field['type'] ?? '' ) );
		if ( ! FieldTypes::exists( $type ) ) {
			return null;
		}

		$id    = self::id( $field['id'] ?? '', 'f', $ids );
		$label = self::text( $field['label'] ?? '' );

		$key = sanitize_key( str_replace( '-', '_', (string) ( $field['key'] ?? '' ) ) );
		if ( '' === $key ) {
			$key = sanitize_key( str_replace( array( ' ', '-' ), '_', remove_accents( strtolower( $label ) ) ) );
		}
		if ( '' === $key || is_numeric( $key[0] ) ) {
			$key = 'field_' . $key;
		}
		$key  = substr( $key, 0, 60 );
		$base = $key;
		$n    = 2;
		while ( isset( $keys[ $key ] ) ) {
			$key = $base . '_' . $n++;
		}
		$keys[ $key ] = true;

		$width = (int) ( $field['width'] ?? 100 );
		if ( ! in_array( $width, self::WIDTHS, true ) ) {
			$width = 100;
		}

		$kind    = FieldTypes::get( $type );
		$options = array();
		if ( $kind->has_options() ) {
			foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
				$option_label = is_array( $option ) ? self::text( $option['label'] ?? '' ) : self::text( $option );
				$option_value = is_array( $option ) && isset( $option['value'] ) && '' !== (string) $option['value'] ? self::text( $option['value'] ) : $option_label;
				if ( '' === $option_label && '' === $option_value ) {
					continue;
				}
				$options[] = array(
					'label' => '' !== $option_label ? $option_label : $option_value,
					'value' => $option_value,
				);
			}
		}

		$validation = array();
		foreach ( array( 'min', 'max', 'min_length', 'max_length' ) as $rule ) {
			if ( isset( $field['validation'][ $rule ] ) && '' !== (string) $field['validation'][ $rule ] && is_numeric( $field['validation'][ $rule ] ) ) {
				$validation[ $rule ] = $field['validation'][ $rule ] + 0;
			}
		}

		$map = null;
		if ( is_array( $field['map'] ?? null ) && ! $kind->is_display() ) {
			$object = sanitize_key( (string) ( $field['map']['object'] ?? '' ) );
			$target = sanitize_text_field( (string) ( $field['map']['field'] ?? '' ) );
			if ( '' !== $object && '' !== $target ) {
				$map = array(
					'object' => $object,
					'field'  => $target,
				);
			} elseif ( 'none' === $object ) {
				// "Don't save to the contact", chosen on purpose: the answer is never used to
				// find or fill the contact (e.g. a friend's email on a referral form).
				$map = array(
					'object' => 'none',
					'field'  => '',
				);
			}
		}

		return array(
			'id'          => $id,
			'key'         => $key,
			'type'        => $type,
			'label'       => $label,
			'description' => self::text( $field['description'] ?? '' ),
			'placeholder' => self::text( $field['placeholder'] ?? '' ),
			'required'    => ! $kind->is_display() && ! empty( $field['required'] ),
			'width'       => $width,
			// Some types have no default (a consent box is never ticked in advance, GDPR).
			'default'     => ( $kind->takes_default() && is_scalar( $field['default'] ?? null ) ) ? self::text( $field['default'] ) : '',
			'options'     => $options,
			'validation'  => $validation,
			'props'       => self::normalize_props( $type, is_array( $field['props'] ?? null ) ? $field['props'] : array() ),
			'map'         => $map,
		);
	}

	/**
	 * Clean the type-specific settings of a field.
	 *
	 * @param string $type  Field type.
	 * @param array  $props Raw props.
	 * @return array
	 */
	private static function normalize_props( string $type, array $props ): array {
		$out = array();
		foreach ( $props as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			if ( in_array( $name, array( 'content', 'text' ), true ) ) {
				$out[ $name ] = wp_kses_post( (string) $value );
			} elseif ( in_array( $name, array( 'url', 'src' ), true ) ) {
				$out[ $name ] = esc_url_raw( (string) $value );
			} elseif ( is_bool( $value ) ) {
				$out[ $name ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$out[ $name ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$out[ $name ] = self::text( $value );
			} elseif ( is_array( $value ) ) {
				$out[ $name ] = array_map(
					static function ( $item ) {
						return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
					},
					array_slice( $value, 0, 50 )
				);
			}
		}

		// Each type's own rules (consent channel, heading level, HTML content…).
		return FieldTypes::get( $type )->props( $out, $props );
	}

	/**
	 * Clean logic rules.
	 *
	 * @param mixed $rules Raw rules.
	 * @return array
	 */
	public static function normalize_rules( $rules ): array {
		$out = array();
		$ids = array();
		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$action = sanitize_key( (string) ( $rule['action'] ?? '' ) );
			if ( ! in_array( $action, array( 'show', 'hide', 'redirect', 'message', 'ending' ), true ) ) {
				continue;
			}

			$when   = self::normalize_when( $rule['when'] ?? array() );
			$groups = $when['groups'];
			if ( ! $groups ) {
				continue;
			}

			$target = array();
			if ( in_array( $action, array( 'show', 'hide' ), true ) ) {
				$target_type = 'step' === ( $rule['target']['type'] ?? 'field' ) ? 'step' : 'field';
				$target_id   = sanitize_key( (string) ( $rule['target']['id'] ?? '' ) );
				if ( '' === $target_id ) {
					continue;
				}
				$target = array(
					'type' => $target_type,
					'id'   => $target_id,
				);
			}
			if ( 'ending' === $action ) {
				$target_id = sanitize_key( (string) ( $rule['target']['id'] ?? '' ) );
				if ( '' === $target_id ) {
					continue;
				}
				$target = array(
					'type' => 'ending',
					'id'   => $target_id,
				);
			}

			$out[] = array(
				'id'      => self::id( $rule['id'] ?? '', 'r', $ids ),
				'action'  => $action,
				'target'  => $target,
				'url'     => 'redirect' === $action ? self::url_with_tags( (string) ( $rule['url'] ?? '' ) ) : '',
				'message' => 'message' === $action ? wp_kses_post( (string) ( $rule['message'] ?? '' ) ) : '',
				'when'    => $when,
			);
		}

		return array_slice( $out, 0, 200 );
	}

	/**
	 * Clean the screens around the questions: one welcome screen (before the first question)
	 * and the thank-you screens (the first is the default; logic can pick another).
	 *
	 * @param mixed $raw Raw.
	 * @return array{welcome: array, endings: array}
	 */
	public static function normalize_screens( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$welcome = is_array( $raw['welcome'] ?? null ) ? $raw['welcome'] : array();
		$endings = array();
		$ids     = array();
		foreach ( (array) ( $raw['endings'] ?? array() ) as $ending ) {
			if ( ! is_array( $ending ) || count( $endings ) >= 20 ) {
				continue;
			}
			$endings[] = array(
				'id'           => self::id( $ending['id'] ?? '', 'end', $ids ),
				'title'        => self::text( $ending['title'] ?? '' ),
				'description'  => wp_kses_post( (string) ( $ending['description'] ?? '' ) ),
				'button_label' => self::text( $ending['button_label'] ?? '' ),
				'button_url'   => self::url_with_tags( (string) ( $ending['button_url'] ?? '' ) ),
				// A thank-you screen, or a redirect to a page (merge tags allowed in the address).
				'type'         => 'redirect' === ( $ending['type'] ?? '' ) ? 'redirect' : 'screen',
				'url'          => self::url_with_tags( (string) ( $ending['url'] ?? '' ) ),
				'new_tab'      => ! empty( $ending['new_tab'] ),
			);
		}
		return array(
			'welcome' => array(
				'enabled'      => ! empty( $welcome['enabled'] ),
				'title'        => self::text( $welcome['title'] ?? '' ),
				'description'  => wp_kses_post( (string) ( $welcome['description'] ?? '' ) ),
				'button_label' => self::text( $welcome['button_label'] ?? '' ) ?: __( 'Start', 'doublescale' ),
			),
			'endings' => $endings,
		);
	}

	/**
	 * Clean a condition set: groups of conditions joined by all/any. Empty groups are dropped.
	 *
	 * @param mixed $when Raw.
	 * @return array{match: string, groups: array}
	 */
	public static function normalize_when( $when ): array {
		$when   = is_array( $when ) ? $when : array();
		$groups = array();
		foreach ( (array) ( $when['groups'] ?? array() ) as $group ) {
			$conditions = array();
			foreach ( (array) ( $group['conditions'] ?? array() ) as $condition ) {
				$op = sanitize_key( (string) ( $condition['op'] ?? 'is' ) );
				if ( ! in_array( $op, self::OPERATORS, true ) || empty( $condition['field'] ) ) {
					continue;
				}
				$conditions[] = array(
					'field' => sanitize_key( (string) $condition['field'] ),
					'op'    => $op,
					'value' => is_scalar( $condition['value'] ?? '' ) ? self::text( $condition['value'] ?? '' ) : '',
				);
			}
			if ( $conditions ) {
				$groups[] = array(
					'match'      => 'any' === ( $group['match'] ?? 'all' ) ? 'any' : 'all',
					'conditions' => array_slice( $conditions, 0, 50 ),
				);
			}
		}
		return array(
			'match'  => 'any' === ( $when['match'] ?? 'all' ) ? 'any' : 'all',
			'groups' => array_slice( $groups, 0, 20 ),
		);
	}

	/**
	 * Clean settings, filling in defaults.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array
	 */
	public static function normalize_settings( $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = self::default_settings();
		$out      = array();

		$after                 = array_merge( $defaults['after_submit'], (array) ( $settings['after_submit'] ?? array() ) );
		$out['after_submit'] = array(
			'type'         => 'redirect' === $after['type'] ? 'redirect' : 'message',
			'message'      => wp_kses_post( (string) $after['message'] ),
			'redirect_url' => self::url_with_tags( (string) $after['redirect_url'] ),
			'new_tab'      => ! empty( $after['new_tab'] ),
		);

		$contact        = array_merge( $defaults['contact'], (array) ( $settings['contact'] ?? array() ) );
		$out['contact'] = array(
			'create'       => ! empty( $contact['create'] ),
			'update'       => 'overwrite' === $contact['update'] ? 'overwrite' : 'fill_blank',
			'email_status' => in_array( $contact['email_status'], array( 'subscribed', 'double_optin', 'consent' ), true ) ? $contact['email_status'] : 'subscribed',
			'lists'        => array_values( array_filter( array_map( 'absint', (array) $contact['lists'] ) ) ),
			'tags'         => array_values( array_filter( array_map( 'absint', (array) $contact['tags'] ) ) ),
			'owner_id'     => absint( $contact['owner_id'] ),
		);

		$notifications = (array) ( $settings['notifications'] ?? array() );
		foreach ( array( 'admin', 'autoresponder' ) as $kind ) {
			$item                          = array_merge( $defaults['notifications'][ $kind ], (array) ( $notifications[ $kind ] ?? array() ) );
			$out['notifications'][ $kind ] = array(
				'enabled' => ! empty( $item['enabled'] ),
				'subject' => self::text( $item['subject'] ),
				'body'    => wp_kses_post( (string) $item['body'] ),
			);
			if ( 'admin' === $kind ) {
				$out['notifications'][ $kind ]['to'] = sanitize_text_field( (string) ( $item['to'] ?? '' ) );
			}
		}

		$spam        = array_merge( $defaults['spam'], (array) ( $settings['spam'] ?? array() ) );
		$out['spam'] = array(
			'honeypot'         => ! empty( $spam['honeypot'] ),
			'min_seconds'      => max( 0, min( 30, (int) $spam['min_seconds'] ) ),
			'captcha'          => in_array( $spam['captcha'], array( 'none', 'turnstile', 'recaptcha', 'hcaptcha' ), true ) ? $spam['captcha'] : 'none',
			'block_disposable' => ! empty( $spam['block_disposable'] ),
			'blocked_domains'  => sanitize_textarea_field( (string) $spam['blocked_domains'] ),
			'max_per_hour'     => max( 1, min( 1000, (int) $spam['max_per_hour'] ) ),
		);

		$page        = array_merge( $defaults['page'], (array) ( $settings['page'] ?? array() ) );
		$out['page'] = array(
			'title'       => self::text( $page['title'] ),
			'description' => sanitize_textarea_field( (string) $page['description'] ),
			'image'       => esc_url_raw( (string) $page['image'] ),
			'noindex'     => ! empty( $page['noindex'] ),
			'chrome'      => 'theme' === $page['chrome'] ? 'theme' : 'blank',
			'logo'        => esc_url_raw( (string) $page['logo'] ),
			'background'  => self::color( $page['background'], $defaults['page']['background'] ),
			'width'       => max( 320, min( 1200, (int) $page['width'] ) ),
		);

		$scoring        = array_merge( $defaults['scoring'], (array) ( $settings['scoring'] ?? array() ) );
		$out['scoring'] = array(
			'points' => max( -1000, min( 1000, (int) $scoring['points'] ) ),
		);

		$out['records'] = Records::normalize( $settings['records'] ?? array() );
		$out['popup'] = Popups::normalize( $settings['popup'] ?? array() );

		$partial        = array_merge( $defaults['partial'], (array) ( $settings['partial'] ?? array() ) );
		$out['partial'] = array(
			'enabled'         => ! empty( $partial['enabled'] ),
			'abandon_minutes' => max( 10, min( 10080, (int) $partial['abandon_minutes'] ) ),
			'create_contact'  => ! empty( $partial['create_contact'] ),
		);

		$access        = array_merge( $defaults['access'], (array) ( $settings['access'] ?? array() ) );
		$out['access'] = array(
			'opens_at'       => self::local_time( $access['opens_at'] ),
			'closes_at'      => self::local_time( $access['closes_at'] ),
			'max_entries'    => max( 0, min( 1000000, (int) $access['max_entries'] ) ),
			'one_per_person' => ! empty( $access['one_per_person'] ),
			'logged_in'      => ! empty( $access['logged_in'] ),
			'password'       => substr( trim( (string) $access['password'] ), 0, 100 ),
			'early_message'  => wp_kses_post( (string) $access['early_message'] ),
			'closed_message' => wp_kses_post( (string) $access['closed_message'] ),
			'login_message'  => wp_kses_post( (string) $access['login_message'] ),
		);

		return $out;
	}

	/**
	 * Clean style, filling in defaults.
	 *
	 * @param mixed $style Raw style.
	 * @return array
	 */
	public static function normalize_style( $style ): array {
		$defaults = self::default_style();
		$style    = array_merge( $defaults, is_array( $style ) ? $style : array() );

		return array(
			'theme'             => 'inherit' === $style['theme'] ? 'inherit' : 'default',
			'primary'           => self::color( $style['primary'], $defaults['primary'] ),
			'text'              => self::color( $style['text'], $defaults['text'] ),
			'background'        => self::color( $style['background'], $defaults['background'] ),
			'field_background'  => self::color( $style['field_background'], $defaults['field_background'] ),
			'border'            => self::color( $style['border'], $defaults['border'] ),
			'radius'            => max( 0, min( 32, (int) $style['radius'] ) ),
			'font_size'         => max( 12, min( 22, (int) $style['font_size'] ) ),
			'button_align'      => in_array( $style['button_align'], array( 'full', 'left', 'center', 'right' ), true ) ? $style['button_align'] : 'full',
			'button_text_color' => self::color( $style['button_text_color'], $defaults['button_text_color'] ),
			'padding'           => max( 0, min( 64, (int) $style['padding'] ) ),
			'custom_css'        => self::css( (string) $style['custom_css'] ),
		);
	}

	/**
	 * The definition the visitor gets: without Pro, one step, no rules and no Pro fields.
	 *
	 * Nothing is deleted from the stored form, so turning Pro back on restores it.
	 *
	 * @param FormModel $form Form.
	 * @return array{schema: array, layout: string}
	 */
	public static function effective( FormModel $form ): array {
		$schema = self::normalize_schema( $form->form_schema ?: self::default_schema() );
		$layout = in_array( $form->layout, self::LAYOUTS, true ) ? $form->layout : 'classic';

		if ( ! self::pro_active() ) {
			$fields = array();
			foreach ( $schema['steps'] as $step ) {
				foreach ( $step['fields'] as $field ) {
					if ( ! FieldTypes::get( $field )->is_pro() ) {
						$fields[] = $field;
					}
				}
			}
			$schema['steps'] = array(
				array_merge(
					$schema['steps'][0],
					array( 'fields' => $fields )
				),
			);
			$schema['rules'] = array();
			$layout          = 'classic';
		} elseif ( 'classic' === $layout && count( $schema['steps'] ) > 1 ) {
			$layout = 'multistep';
		}

		return array(
			'schema' => $schema,
			'layout' => $layout,
		);
	}

	/**
	 * Fields that take an answer, keyed by field id.
	 *
	 * @param array $schema Normalized definition.
	 * @return array<string, array>
	 */
	public static function input_fields( array $schema ): array {
		$out = array();
		foreach ( $schema['steps'] as $step ) {
			foreach ( $step['fields'] as $field ) {
				if ( ! FieldTypes::get( $field )->is_display() ) {
					$field['step'] = $step['id'];
					$out[ $field['id'] ] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * Fields for automations and merge tags: key => { label, type }.
	 *
	 * @param array $schema Normalized definition.
	 * @return array<string, array{label: string, type: string}>
	 */
	public static function field_list( array $schema ): array {
		$out = array();
		foreach ( self::input_fields( $schema ) as $field ) {
			$out[ $field['key'] ] = array(
				'label' => '' !== $field['label'] ? $field['label'] : $field['key'],
				'type'  => $field['type'],
			);
		}
		return $out;
	}

	/**
	 * Whether Pro is active (logic, steps, Pro field types and custom-field mapping need it).
	 *
	 * @return bool
	 */
	public static function pro_active(): bool {
		return function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active();
	}

	/**
	 * A date and time in the site's timezone as "Y-m-d\TH:i" (what a datetime-local input sends), or ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function local_time( $value ): string {
		$value = substr( str_replace( ' ', 'T', trim( (string) $value ) ), 0, 16 );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * A unique id, keeping the given one when it is valid and unused.
	 *
	 * @param mixed  $id     Given id.
	 * @param string $prefix Prefix for new ids.
	 * @param array  $used   Ids used so far (by reference).
	 * @return string
	 */
	private static function id( $id, string $prefix, array &$used ): string {
		$id = is_scalar( $id ) ? sanitize_key( (string) $id ) : '';
		if ( '' === $id || isset( $used[ $id ] ) ) {
			do {
				$id = $prefix . '_' . strtolower( wp_generate_password( 8, false, false ) );
			} while ( isset( $used[ $id ] ) );
		}
		$used[ $id ] = true;
		return substr( $id, 0, 40 );
	}

	/**
	 * Plain text, one line.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * A hex colour or the fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function color( $value, string $fallback ): string {
		$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
		return $color ? $color : $fallback;
	}

	/**
	 * A redirect address that may hold {{merge tags}}.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function url_with_tags( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		// esc_url_raw() would mangle the braces, so tags are set aside while it runs.
		$tags = array();
		$url  = preg_replace_callback(
			'/\{\{[^}]{1,80}\}\}/',
			static function ( $match ) use ( &$tags ) {
				$tags[] = $match[0];
				return 'DSFTAG' . ( count( $tags ) - 1 ) . 'X';
			},
			$url
		);
		// A tag may fill the path or query, never the scheme or domain (no open redirect).
		$lead = strpos( $url, 'DSFTAG' );
		if ( false !== $lead ) {
			$host_end = preg_match( '#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', $url, $authority ) ? strlen( $authority[0] ) : 0;
			if ( $lead < $host_end || ( 0 === $host_end && ! preg_match( '#^/(?!/)#', $url ) ) ) {
				return '';
			}
		}
		$url = esc_url_raw( $url );
		foreach ( $tags as $index => $tag ) {
			$url = str_replace( 'DSFTAG' . $index . 'X', $tag, $url );
		}
		return $url;
	}

	/**
	 * Custom CSS made safe to print in a style tag.
	 *
	 * - Nothing can close the style tag.
	 * - Only @media, @supports and @container rules survive. @import, @font-face, @charset,
	 *   @namespace and any other at-rule are dropped with their body.
	 * - url() may only point at this site, a relative path or an inline image, so custom CSS
	 *   can't make visitors' browsers call other servers.
	 * - Backslash escapes go, so none of the above can be spelled in disguise (@\69mport).
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function css( string $css ): string {
		$css = substr( $css, 0, 20000 );
		$css = wp_strip_all_tags( $css );
		$css = (string) preg_replace( '#/\*.*?(\*/|$)#s', '', $css );
		$css = str_replace( array( '\\', '<' ), '', $css );
		$css = str_ireplace( array( '</style', 'expression(', 'javascript:', 'vbscript:', '-moz-binding', 'behavior:' ), '', $css );

		// At-rules: keep the allowed ones, cut the rest out together with their block or statement.
		$allowed = array( 'media', 'supports', 'container' );
		$out     = '';
		$length  = strlen( $css );
		$i       = 0;
		while ( $i < $length ) {
			$at = strpos( $css, '@', $i );
			if ( false === $at ) {
				$out .= substr( $css, $i );
				break;
			}
			$out .= substr( $css, $i, $at - $i );
			preg_match( '/\G@([a-zA-Z-]*)/', $css, $name, 0, $at );
			$keyword = strtolower( $name[1] ?? '' );
			if ( in_array( $keyword, $allowed, true ) ) {
				$out .= '@' . $keyword;
				$i    = $at + 1 + strlen( $name[1] );
				continue;
			}
			// Skip to the end of the statement (;) or the end of its balanced block.
			$semi  = strpos( $css, ';', $at );
			$brace = strpos( $css, '{', $at );
			if ( false !== $brace && ( false === $semi || $brace < $semi ) ) {
				$depth = 0;
				for ( $j = $brace; $j < $length; $j++ ) {
					if ( '{' === $css[ $j ] ) {
						++$depth;
					} elseif ( '}' === $css[ $j ] && 0 === --$depth ) {
						break;
					}
				}
				$i = $j + 1;
			} elseif ( false !== $semi ) {
				$i = $semi + 1;
			} else {
				break;
			}
		}

		// url(): only this site, relative paths and inline images.
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = (string) preg_replace_callback(
			'/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
			static function ( $match ) use ( $host ) {
				$target = trim( $match[2] );
				$lower  = strtolower( $target );
				if ( 0 === strpos( $lower, 'data:' ) ) {
					return preg_match( '#^data:image/(png|gif|jpe?g|webp);base64,[a-z0-9+/=\s]+$#i', $target ) ? $match[0] : 'none';
				}
				if ( preg_match( '#^([a-z][a-z0-9+.-]*:)?//#i', $target ) ) {
					$target_host = (string) wp_parse_url( ( 0 === strpos( $target, '//' ) ? 'https:' : '' ) . $target, PHP_URL_HOST );
					return ( '' !== $host && strtolower( $target_host ) === strtolower( $host ) && preg_match( '#^(https?:)?//#i', $target ) ) ? $match[0] : 'none';
				}
				return preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) ? 'none' : $match[0];
			},
			$out
		);
		// image-set()/src() can fetch too; only url() is allowed to.
		$out = (string) preg_replace( '/\b(image-set|-webkit-image-set|src)\s*\(/i', 'none(', $out );

		return trim( $out );
	}
}
