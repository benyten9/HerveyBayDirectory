<?php
/**
 * Partial entries (Pro): answers saved while someone is still filling in a form, so a
 * visitor who leaves half way is not lost.
 *
 * - The browser saves the answers so far (after each step, or a pause in a one-page form).
 *   One row per visit, found again by a random resume token the browser keeps.
 * - When the visitor sends the form, the partial row is removed: the complete one replaces it.
 * - A row that stops changing for the form's "abandoned after" time is abandoned once: it is
 *   linked to the person (an existing contact, or a new one when the form allows it) and the
 *   "Form abandoned" automation trigger runs.
 * - {{forms:resume ID}} links back to the form with the answers filled in (30 days).
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

use DoubleScale\Core\PluginKernel;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;

defined( 'ABSPATH' ) || exit;

/**
 * Partial entries and abandonment.
 */
final class Partial {

	/** Background check of one partial row. */
	public const TASK = 'doublescale_forms_abandon_check';

	/** Query arg of resume links. */
	public const RESUME_ARG = 'dsf_resume';

	/** How long a resume link works. */
	public const RESUME_TTL = 30 * DAY_IN_SECONDS;

	/** Saves per visitor and form every hour (a long form saves once per step). */
	/** New unfinished entries one network may start per form, per hour. */
	private const NEW_PER_HOUR = 10;

	private const SAVES_PER_HOUR = 120;

	/**
	 * Whether unfinished entries exist at all on this site. Off for now (the feature is being
	 * rethought): no form saves them, and their settings, the "Form abandoned" trigger and the
	 * {{forms:resume}} merge tag are hidden. Developers can turn it on with this filter.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		/**
		 * Whether native forms offer unfinished entries (saving answers as people go).
		 *
		 * @param bool $available Default false.
		 */
		return (bool) apply_filters( 'doublescale_native_forms_partial_entries', false );
	}

	/**
	 * Whether a form keeps partial entries.
	 *
	 * @param array $settings Normalized settings.
	 * @return bool
	 */
	public static function enabled( array $settings ): bool {
		return self::available() && Schema::pro_active() && ! empty( $settings['partial']['enabled'] );
	}

	/**
	 * Save the answers so far. Returns the resume token, or a WP_Error-like array.
	 *
	 * @param FormModel $form  Form.
	 * @param array     $input Request body: answers, step (screen index), token, ctx, _dsf_hp, _dsf_issued.
	 * @return array{status: int, body: array}
	 */
	public static function save( FormModel $form, array $input ): array {
		$settings = Schema::normalize_settings( $form->settings );
		if ( ! self::enabled( $settings ) ) {
			return self::reply( 404, 'partial_off' );
		}
		if ( ! Access::state( $form, $settings )['open'] ) {
			return self::reply( 403, 'form_closed' );
		}
		// Bots that fill the trap, or requests that never loaded the form, are ignored quietly.
		$age = Tokens::issued_age( (string) ( $input['_dsf_issued'] ?? '' ), (int) $form->id );
		if ( '' !== trim( (string) ( $input['_dsf_hp'] ?? '' ) ) || null === $age || $age > Tokens::ISSUED_MAX ) {
			return self::reply( 200, '', array( 'token' => '' ) );
		}
		if ( RateLimiter::ready() && RateLimiter::hit( 'forms_partial_' . $form->id . '_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > self::SAVES_PER_HOUR ) {
			return self::reply( 429, 'rate_limited' );
		}

		$effective = Schema::effective( $form );
		$schema    = $effective['schema'];
		$fields    = Schema::input_fields( $schema );
		$answers   = self::clean_answers( $schema, $fields, is_array( $input['answers'] ?? null ) ? $input['answers'] : array() );
		if ( empty( $answers ) ) {
			return self::reply( 200, '', array( 'token' => '' ) );
		}

		// The screen they were on (0 = first), to bring them back to it.
		$step  = max( 0, min( 500, (int) ( $input['step'] ?? 0 ) ) );
		$token = self::token_format( (string) ( $input['token'] ?? '' ) );
		$row   = '' !== $token ? self::find( $form, $token ) : null;

		if ( $row ) {
			$meta                = (array) $row->meta;
			$meta['fields']      = self::labels( $fields, $answers );
			$row->answers        = $answers;
			$row->step           = $step;
			$row->meta           = $meta;
			try {
				$row->save();
			} catch ( \Throwable $e ) {
				return self::reply( 500, 'save_failed' );
			}
			return self::reply( 200, '', array( 'token' => $token ) );
		}

		// Blocked or throwaway addresses are not kept (they would become contacts and messages later).
		foreach ( $fields as $field ) {
			if ( 'email' === $field['type'] && ! empty( $answers[ $field['key'] ] ) && '' !== Spam::blocked_email( $settings['spam'], (string) $answers[ $field['key'] ] ) ) {
				return self::reply( 200, '', array( 'token' => '' ) );
			}
		}
		// New entries from one network are capped well below the save limit: each one can
		// become a contact and an "abandoned" message later.
		if ( RateLimiter::ready() && RateLimiter::hit( 'forms_partial_new_' . $form->id . '_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > self::NEW_PER_HOUR ) {
			return self::reply( 429, 'rate_limited' );
		}

		$token = wp_generate_password( 32, false, false );
		try {
			$row = FormSubmissionModel::create(
				array(
					'form_id'      => $form->id,
					'contact_id'   => null,
					'status'       => FormSubmissionModel::STATUS_PARTIAL,
					'answers'      => $answers,
					'meta'         => array_merge(
						Submission::context( $input['ctx'] ?? array() ),
						array( 'fields' => self::labels( $fields, $answers ) )
					),
					'step'         => $step,
					'resume_token' => $token,
				)
			);
		} catch ( \Throwable $e ) {
			return self::reply( 500, 'save_failed' );
		}

		PluginKernel::instance()->forms_tasks->schedule_single( time() + self::window( $settings ), self::TASK, (int) $row->id );

		return self::reply( 200, '', array( 'token' => $token ) );
	}

	/**
	 * The answers of a partial entry, for a resume link (only this form's, within 30 days).
	 *
	 * @param FormModel $form  Form.
	 * @param string    $token Resume token.
	 * @return array{status: int, body: array}
	 */
	public static function resume( FormModel $form, string $token ): array {
		$settings = Schema::normalize_settings( $form->settings );
		if ( ! self::enabled( $settings ) ) {
			return self::reply( 404, 'partial_off' );
		}
		if ( RateLimiter::ready() && RateLimiter::hit( 'forms_resume_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > 60 ) {
			return self::reply( 429, 'rate_limited' );
		}
		$token = self::token_format( $token );
		$row   = '' !== $token ? self::find( $form, $token ) : null;
		if ( ! $row || strtotime( (string) $row->updated_at . ' UTC' ) < time() - self::RESUME_TTL ) {
			return self::reply( 404, 'resume_expired' );
		}

		$fields  = Schema::input_fields( Schema::effective( $form )['schema'] );
		$answers = array();
		foreach ( $fields as $field ) {
			// Files are never handed back: they belong to the visit that uploaded them.
			if ( FieldTypes::get( $field )->saved_in_partial() && array_key_exists( $field['key'], (array) $row->answers ) ) {
				$answers[ $field['key'] ] = $row->answers[ $field['key'] ];
			}
		}
		return self::reply(
			200,
			'',
			array(
				'answers' => (object) $answers,
				'step'    => (int) $row->step,
				'token'   => $token,
			)
		);
	}

	/**
	 * Remove the partial row of a visit that has now sent the form.
	 *
	 * @param FormModel $form  Form.
	 * @param string    $token Resume token sent with the answers.
	 * @return void
	 */
	public static function completed( FormModel $form, string $token ): void {
		$token = self::token_format( $token );
		if ( '' === $token ) {
			return;
		}
		FormSubmissionModel::query()
			->where( 'form_id', (int) $form->id )
			->where( 'status', FormSubmissionModel::STATUS_PARTIAL )
			->where( 'resume_token', $token )
			->delete();
	}

	/**
	 * Background check of a partial row: abandon it once it stopped changing, or look again later.
	 *
	 * @param int $submission_id Row id.
	 * @return void
	 */
	public static function check( $submission_id ): void {
		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return;
		}
		$row = FormSubmissionModel::find( (int) $submission_id );
		if ( ! $row || FormSubmissionModel::STATUS_PARTIAL !== $row->status || ! empty( $row->meta['abandoned_at'] ) ) {
			return;
		}
		$form = FormModel::find( (int) $row->form_id );
		if ( ! $form ) {
			return;
		}
		$settings = Schema::normalize_settings( $form->settings );
		if ( ! self::enabled( $settings ) ) {
			return;
		}

		$quiet_since = strtotime( (string) $row->updated_at . ' UTC' );
		$due         = $quiet_since + self::window( $settings );
		if ( $due > time() + 30 ) {
			// Still being filled in: look again when it would be due.
			PluginKernel::instance()->forms_tasks->schedule_single( $due, self::TASK, (int) $row->id );
			return;
		}

		self::abandon( $form, $settings, $row );
	}

	/**
	 * Mark a row abandoned, link the person and run "Form abandoned".
	 *
	 * @param FormModel           $form     Form.
	 * @param array               $settings Normalized settings.
	 * @param FormSubmissionModel $row      Partial row.
	 * @return void
	 */
	public static function abandon( FormModel $form, array $settings, FormSubmissionModel $row ): void {
		$schema  = Schema::effective( $form )['schema'];
		$fields  = Schema::input_fields( $schema );
		$answers = (array) $row->answers;
		$visible = array();
		foreach ( $fields as $id => $field ) {
			if ( array_key_exists( $field['key'], $answers ) ) {
				$visible[ $id ] = $field;
			}
		}

		// An address the form would refuse (blocked or throwaway) is not followed up.
		foreach ( $visible as $field ) {
			if ( 'email' === $field['type'] && ! empty( $answers[ $field['key'] ] ) && '' !== Spam::blocked_email( $settings['spam'], (string) $answers[ $field['key'] ] ) ) {
				$meta                 = (array) $row->meta;
				$meta['abandoned_at'] = gmdate( 'Y-m-d H:i:s' );
				$meta['skipped']      = 'blocked_email';
				$row->meta            = $meta;
				$row->save();
				return;
			}
		}

		$contact = Contacts::find( $visible, $answers );
		if ( ! $contact && ! empty( $settings['partial']['create_contact'] ) ) {
			// Only the person is saved: lists, tags and subscriptions wait for a finished form,
			// and an unfinished one never subscribes anyone (no consent, so "unverified").
			$quiet                            = $settings;
			$quiet['contact']['create']       = true;
			$quiet['contact']['lists']        = array();
			$quiet['contact']['tags']         = array();
			$quiet['contact']['update']       = 'fill_blank';
			$quiet['contact']['email_status'] = 'consent';
			$resolved                         = Contacts::resolve( $form, $quiet, $visible, array_diff_key( $answers, self::consent_keys( $visible ) ) );
			$contact                          = $resolved['contact'];
		}

		$meta                 = (array) $row->meta;
		$meta['abandoned_at'] = gmdate( 'Y-m-d H:i:s' );
		$row->meta            = $meta;
		if ( $contact ) {
			$row->contact_id = (int) $contact->id;
		}
		// One "abandoned" follow-up per person and form a day, however many entries were left:
		// typing someone's email over and over must not flood them.
		if ( $contact ) {
			$recent = FormSubmissionModel::query()
				->where( 'form_id', (int) $form->id )
				->where( 'contact_id', (int) $contact->id )
				->where( 'status', FormSubmissionModel::STATUS_PARTIAL )
				->where( 'id', '!=', (int) $row->id )
				->where( 'updated_at', '>=', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) )
				->get()
				->filter(
					static function ( $other ) {
						$other_meta = (array) $other->meta;
						return ! empty( $other_meta['abandoned_at'] ) && empty( $other_meta['skipped'] );
					}
				)
				->count();
			if ( $recent > 0 ) {
				$meta            = (array) $row->meta;
				$meta['skipped'] = 'recent_followup';
				$row->meta       = $meta;
				$row->save();
				return;
			}
		}
		$row->save();

		/**
		 * A partial entry was abandoned (Pro "Form abandoned" trigger).
		 *
		 * @param FormSubmissionModel $row     The partial entry.
		 * @param FormModel           $form    The form.
		 * @param ContactModel|null   $contact The person, when known.
		 */
		do_action( 'doublescale_native_form_abandoned', $row, $form, $contact instanceof ContactModel ? $contact : null );
	}

	/**
	 * A resume link for the contact's latest unfinished entry of a form, or '' when there is none.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $form_id    Form id.
	 * @return string
	 */
	public static function resume_link( int $contact_id, int $form_id ): string {
		if ( $contact_id <= 0 || ! doublescale_module_data_ready( 'forms' ) ) {
			return '';
		}
		$form = FormModel::find( $form_id );
		if ( ! $form || ! $form->is_native() ) {
			return '';
		}
		$row = FormSubmissionModel::query()
			->where( 'form_id', $form_id )
			->where( 'contact_id', $contact_id )
			->where( 'status', FormSubmissionModel::STATUS_PARTIAL )
			->whereNotNull( 'resume_token' )
			->orderBy( 'id', 'desc' )
			->first();
		if ( ! $row ) {
			return '';
		}
		return add_query_arg( self::RESUME_ARG, rawurlencode( (string) $row->resume_token ), Renderer::link( $form ) );
	}

	/**
	 * Minutes of quiet before a partial entry counts as abandoned.
	 *
	 * @param array $settings Normalized settings.
	 * @return int Seconds.
	 */
	private static function window( array $settings ): int {
		return max( 10, (int) ( $settings['partial']['abandon_minutes'] ?? 60 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Clean the answers given so far: hidden-by-logic, invalid and file answers are left out.
	 *
	 * @param array $schema  Effective schema.
	 * @param array $fields  Input fields by id.
	 * @param array $raw     Raw answers by key.
	 * @return array Answers by key.
	 */
	private static function clean_answers( array $schema, array $fields, array $raw ): array {
		$values = array();
		foreach ( $fields as $id => $field ) {
			$given         = array_key_exists( $field['key'], $raw ) ? $raw[ $field['key'] ] : null;
			// Uploads never come with an unfinished entry; hidden values do (logic may read them).
			$values[ $id ] = FieldTypes::get( $field )->is_upload() ? null : FieldTypes::sanitize( $field, $given );
		}
		$hidden  = Logic::hidden( $schema, $values );
		$answers = array();
		foreach ( $fields as $id => $field ) {
			$value = $values[ $id ];
			if ( null === $value || ! empty( $hidden['fields'][ $id ] ) || ! FieldTypes::get( $field )->saved_in_partial() ) {
				continue;
			}
			if ( '' !== FieldTypes::validate( $field, $value ) ) {
				continue;
			}
			$answers[ $field['key'] ] = $value;
		}
		return $answers;
	}

	/**
	 * Field labels for the inbox (the same shape a complete submission keeps).
	 *
	 * @param array $fields  Input fields by id.
	 * @param array $answers Answers by key.
	 * @return array
	 */
	private static function labels( array $fields, array $answers ): array {
		$labels = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field['key'], $answers ) ) {
				$labels[ $field['key'] ] = array(
					'label' => $field['label'],
					'type'  => $field['type'],
				);
				if ( ! empty( $field['options'] ) ) {
					$labels[ $field['key'] ]['options'] = $field['options'];
				}
			}
		}
		return $labels;
	}

	/**
	 * Keys of consent fields: an unfinished form never subscribes anyone.
	 *
	 * @param array $fields Fields by id.
	 * @return array<string, true>
	 */
	private static function consent_keys( array $fields ): array {
		$keys = array();
		foreach ( $fields as $field ) {
			if ( 'consent' === $field['type'] ) {
				$keys[ $field['key'] ] = true;
			}
		}
		return $keys;
	}

	/**
	 * The partial row of this form with this token.
	 *
	 * @param FormModel $form  Form.
	 * @param string    $token Token.
	 * @return FormSubmissionModel|null
	 */
	private static function find( FormModel $form, string $token ) {
		return FormSubmissionModel::query()
			->where( 'form_id', (int) $form->id )
			->where( 'status', FormSubmissionModel::STATUS_PARTIAL )
			->where( 'resume_token', $token )
			->first();
	}

	/**
	 * A token as we make them (32 letters and digits), or ''.
	 *
	 * @param string $token Raw.
	 * @return string
	 */
	private static function token_format( string $token ): string {
		return preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * Reply shape shared with Submission.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code ('' for success).
	 * @param array  $body   Body.
	 * @return array{status: int, body: array}
	 */
	private static function reply( int $status, string $code, array $body = array() ): array {
		if ( '' !== $code ) {
			$body['code'] = $code;
		}
		return array(
			'status' => $status,
			'body'   => $body,
		);
	}
}
