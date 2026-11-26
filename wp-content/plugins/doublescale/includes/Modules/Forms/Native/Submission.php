<?php
/**
 * What happens when a visitor sends a DoubleScale form.
 *
 * In the request: spam gate → validate (respecting logic) → read the visitor's context
 * (UTMs, page, IP) → save the submission → create or update the contact → consent log,
 * timeline, attribution, stats. The contact exists before the thank-you message shows.
 *
 * In the background (Action Scheduler): notification emails, double opt-in email, lead
 * scoring and automations, so a slow mail server never slows the visitor down.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\PluginKernel;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;

/**
 * Submission pipeline.
 */
final class Submission {

	public const TASK = 'native_after_submit';

	/**
	 * Handle a submission.
	 *
	 * @param FormModel $form  Form.
	 * @param array     $input Request body: answers (by field key), ctx, spam fields.
	 * @return array{status: int, body: array}
	 */
	public static function handle( FormModel $form, array $input ): array {
		$effective = Schema::effective( $form );
		$schema    = $effective['schema'];
		$settings  = Schema::normalize_settings( $form->settings );
		$fields    = Schema::input_fields( $schema );
		$raw       = is_array( $input['answers'] ?? null ) ? $input['answers'] : array();

		// Closed, full, not open yet, members only or locked: the page may be stale, so check again.
		$access = Access::state( $form, $settings );
		if ( ! $access['open'] ) {
			$closed = Access::reply( $access );
			return self::reply( $closed['status'], $closed['body'] );
		}

		// Answers by field id, cleaned.
		$values = array();
		foreach ( $fields as $id => $field ) {
			$given = array_key_exists( $field['key'], $raw ) ? $raw[ $field['key'] ] : null;
			if ( null === $given && '' !== $field['default'] && FieldTypes::get( $field )->default_when_empty() ) {
				$given = $field['default'];
			}
			$values[ $id ] = FieldTypes::sanitize( $field, $given );
		}

		$hidden = Logic::hidden( $schema, $values );
		foreach ( $hidden['fields'] as $id => $is_hidden ) {
			if ( $is_hidden && FieldTypes::get( $fields[ $id ] ?? array() )->hidden_by_logic() ) {
				$values[ $id ] = null;
			}
		}

		$errors  = array();
		$answers = array();
		$visible = array();
		foreach ( $fields as $id => $field ) {
			if ( ! empty( $hidden['fields'][ $id ] ) && FieldTypes::get( $field )->hidden_by_logic() ) {
				continue;
			}
			$visible[ $id ] = $field;
			$value          = $values[ $id ];
			if ( null === $value ) {
				if ( $field['required'] ) {
					$errors[ $field['key'] ] = $schema['labels']['required'];
				}
				continue;
			}
			$error = FieldTypes::validate( $field, $value, (array) ( $schema['labels']['messages'] ?? array() ) );
			if ( '' !== $error ) {
				$errors[ $field['key'] ] = $error;
				continue;
			}
			$answers[ $field['key'] ] = $value;
		}

		if ( isset( $answers ) && ! empty( $errors ) ) {
			return self::reply(
				422,
				array(
					'code'    => 'invalid',
					'message' => __( 'Please check the highlighted fields.', 'doublescale' ),
					'errors'  => $errors,
				)
			);
		}

		$email = '';
		foreach ( $visible as $field ) {
			if ( 'email' === $field['type'] && ! empty( $answers[ $field['key'] ] ) ) {
				$email = (string) $answers[ $field['key'] ];
				break;
			}
		}

		$spam = Spam::check( (int) $form->id, $settings['spam'], $input, $email );
		$ctx  = self::context( $input['ctx'] ?? array() );

		if ( Spam::LIMITED === $spam['verdict'] ) {
			return self::reply(
				429,
				array(
					'code'    => 'rate_limited',
					'message' => __( 'Too many submissions from your network. Please try again later.', 'doublescale' ),
				)
			);
		}
		if ( Spam::EXPIRED === $spam['verdict'] ) {
			// They loaded the page long ago (it was cached): a fresh token, dated as if they had
			// spent the minimum time on it, and the browser sends once more.
			return self::reply(
				409,
				array(
					'code'    => 'expired',
					'message' => __( 'Please reload the page and try again.', 'doublescale' ),
					'issued'  => Tokens::issued( (int) $form->id, time() - (int) $settings['spam']['min_seconds'] - 1 ),
				)
			);
		}
		if ( Spam::CAPTCHA_FAILED === $spam['verdict'] ) {
			return self::reply(
				400,
				array(
					'code'    => 'captcha',
					'message' => __( 'Please complete the anti-spam check and try again.', 'doublescale' ),
				)
			);
		}
		if ( Spam::BLOCKED === $spam['verdict'] ) {
			$key = '';
			foreach ( $visible as $field ) {
				if ( 'email' === $field['type'] ) {
					$key = $field['key'];
					break;
				}
			}
			return self::reply(
				422,
				array(
					'code'    => 'invalid',
					'message' => __( 'Please use a different email address.', 'doublescale' ),
					'errors'  => '' !== $key ? array( $key => __( 'Please use a different email address.', 'doublescale' ) ) : array(),
				)
			);
		}
		if ( Spam::SPAM === $spam['verdict'] ) {
			if ( Spam::keep_spam( (int) $form->id ) ) {
				self::store( $form, null, FormSubmissionModel::STATUS_SPAM, $answers, $fields, $ctx, array( 'spam_reason' => $spam['reason'] ) );
			}
			// Bots get the normal reply, so they cannot tell they were caught.
			return self::reply( 200, self::outcome_body( $form, $schema, $settings, $answers, $fields, $values, null, 0 ) );
		}

		if ( Schema::pro_active() && $settings['access']['one_per_person'] ) {
			$known = Contacts::find( $visible, $answers );
			if ( $known && Access::already_sent( $form, (int) $known->id ) ) {
				// Only someone signed in as that contact is told; anyone else gets the usual
				// thank-you (nothing saved, nothing changed), so the form can't reveal who sent it.
				if ( self::is_current_users_contact( $known ) ) {
					return self::reply(
						409,
						array(
							'code'    => 'already_sent',
							'message' => __( 'You have already filled in this form. Thank you!', 'doublescale' ),
						)
					);
				}
				return self::reply( 200, self::outcome_body( $form, $schema, $settings, $answers, $fields, $values, null, 0 ) );
			}
		}

		$resolved = Contacts::resolve( $form, $settings, $visible, $answers );
		$contact  = $resolved['contact'];

		$submission = self::store(
			$form,
			$contact,
			FormSubmissionModel::STATUS_COMPLETE,
			$answers,
			$fields,
			$ctx,
			array(
				'contact_reason'  => $resolved['reason'],
				'contact_created' => $resolved['created'],
			)
		);

		if ( ! $submission ) {
			return self::reply(
				500,
				array(
					'code'    => 'save_failed',
					'message' => __( 'Sorry, your answers could not be saved. Please try again.', 'doublescale' ),
				)
			);
		}

		self::claim_files( $form, $submission, $contact, $visible, $answers );

		if ( ! $contact && 'no_identifier' === $resolved['reason'] ) {
			/**
			 * A submission with no email, phone or known visitor. Website tracking (Pro) keeps
			 * its id in the browser and hands it to the contact once the visitor is identified.
			 *
			 * @param int       $submission_id Submission id.
			 * @param FormModel $form          Form.
			 */
			do_action( 'doublescale_native_form_unlinked', (int) $submission->id, $form );
		}

		if ( $contact ) {
			self::log_consent( $contact, $form, $submission, $visible, $answers, $ctx );
			self::attribution( $contact, $form, $ctx );
			self::timeline( $contact, $form, $submission, $visible, $answers );
			// Only a person this browser just created (or a logged-in user's own contact) is
			// followed by website tracking; typing someone's email doesn't make you them.
			if ( $resolved['created'] || 'logged_in' === $resolved['reason'] ) {
				self::set_tracking_cookie( $contact );
			}
		}
		Stats::bump( (int) $form->id, 'completions', (string) ( $input['variant'] ?? '' ) );
		// The visit's partial entry is replaced by this complete one.
		Partial::completed( $form, (string) ( $input['_dsf_resume'] ?? '' ) );

		do_action( 'doublescale_native_form_saved', $submission, $form, $contact );

		PluginKernel::instance()->forms_tasks->enqueue_async( self::TASK, (int) $submission->id );

		// Contact tags on the visitor's screen only for a contact they just created or their own:
		// otherwise anyone could type a stranger's email and read that person's details back.
		$own = $contact && ( $resolved['created'] || 'logged_in' === $resolved['reason'] || self::is_current_users_contact( $contact ) );

		return self::reply( 200, self::outcome_body( $form, $schema, $settings, $answers, $fields, $values, $own ? $contact : null, (int) $submission->id ) );
	}

	/**
	 * Background work after a submission: emails, double opt-in, lead scoring, automations.
	 *
	 * @param int $submission_id Submission id.
	 * @return void
	 */
	public static function after_submit( $submission_id ): void {
		$submission = FormSubmissionModel::find( (int) $submission_id );
		if ( ! $submission ) {
			return;
		}
		$form = FormModel::find( (int) $submission->form_id );
		if ( ! $form ) {
			return;
		}
		$contact  = $submission->contact_id ? ContactModel::find( (int) $submission->contact_id ) : null;
		$schema   = Schema::effective( $form )['schema'];
		$settings = Schema::normalize_settings( $form->settings );
		$fields   = Schema::input_fields( $schema );
		$answers  = is_array( $submission->answers ) ? $submission->answers : array();

		try {
			Notifications::send( $form, $settings, $submission, $fields, $answers, $contact );

			// Not for a visitor known only by their browser: they gave no email here (shared devices).
			$by_browser = 'known_visitor' === ( ( is_array( $submission->meta ) ? $submission->meta : array() )['contact_reason'] ?? '' );
			if ( $contact && ! $by_browser && 'unverified' === $contact->email_status && is_email( $contact->email ) && Contacts::wants_double_optin( $settings, $fields, $answers ) ) {
				\DoubleScale\Modules\Emails\Emails::send_double_optin_email( $contact );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error( 'Form notifications failed', array( 'submission_id' => $submission->id, 'error' => $e->getMessage() ) );
		}

		// Company, deal, ticket and project, before automations run (so they can use them).
		Records::run( $form, $settings, $submission, $fields, $answers, $contact );

		if ( $contact ) {
			do_action( 'doublescale_form_submitted', (int) $contact->id );
		}

		$adapter = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_form( Form::SLUG );
		if ( $adapter instanceof Form ) {
			$adapter->run_automations( $form, $submission, $contact, $fields, $answers );
		}

		do_action( 'doublescale_native_form_submitted', $submission, $form, $contact );
	}

	/**
	 * Save a submission row.
	 *
	 * @param FormModel         $form    Form.
	 * @param ContactModel|null $contact Contact.
	 * @param string            $status  Status.
	 * @param array             $answers Answers keyed by key.
	 * @param array             $fields  Input fields keyed by id.
	 * @param array             $ctx     Visitor context.
	 * @param array             $extra   More meta.
	 * @return FormSubmissionModel|null
	 */
	private static function store( FormModel $form, $contact, string $status, array $answers, array $fields, array $ctx, array $extra = array() ) {
		$labels = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field['key'], $answers ) ) {
				$labels[ $field['key'] ] = array(
					'label' => $field['label'],
					'type'  => $field['type'],
				);
				if ( ! empty( $field['options'] ) ) {
					// Choice labels, so the answer still reads "Over $1k" after the form changes.
					$labels[ $field['key'] ]['options'] = $field['options'];
				}
			}
		}

		try {
			return FormSubmissionModel::create(
				array(
					'form_id'    => $form->id,
					'contact_id' => $contact ? (int) $contact->id : null,
					'status'     => $status,
					'answers'    => $answers,
					'meta'       => array_merge(
						$ctx,
						array(
							'fields' => $labels,
						),
						$extra
					),
					'variant'    => null,
				)
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Could not save a form submission',
				array(
					'code'    => 'native_form_save_failed',
					'form_id' => $form->id,
					'error'   => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Tie uploaded files to the submission; the answer keeps the file names.
	 *
	 * @param FormModel           $form       Form.
	 * @param FormSubmissionModel $submission Submission.
	 * @param ContactModel|null   $contact    Contact.
	 * @param array               $fields     Visible input fields.
	 * @param array               $answers    Answers keyed by key (by reference: file names replace tokens).
	 * @return void
	 */
	private static function claim_files( FormModel $form, $submission, $contact, array $fields, array &$answers ): void {
		$files   = array();
		$changed = false;
		foreach ( $fields as $field ) {
			if ( empty( $answers[ $field['key'] ] ) || ! FieldTypes::get( $field )->is_upload() ) {
				continue;
			}
			$claimed                  = Uploads::claim( $form, (int) $submission->id, $contact ? (int) $contact->id : null, $field['key'], (array) $answers[ $field['key'] ] );
			$answers[ $field['key'] ] = $claimed['names'];
			$files[ $field['key'] ]   = $claimed['ids'];
			$changed                  = true;
		}
		if ( $changed ) {
			$meta              = is_array( $submission->meta ) ? $submission->meta : array();
			$meta['files']     = $files;
			$submission->meta    = $meta;
			$submission->answers = $answers;
			$submission->save();
		}
	}

	/**
	 * What the visitor sees next: a message or a redirect, after logic rules and merge tags.
	 *
	 * @param FormModel         $form          Form.
	 * @param array             $schema        Definition.
	 * @param array             $settings      Settings.
	 * @param array             $answers       Answers keyed by key.
	 * @param array             $fields        Input fields keyed by id.
	 * @param array             $values        Answers keyed by id (hidden ones null).
	 * @param ContactModel|null $contact       Contact.
	 * @param int               $submission_id Submission id.
	 * @return array
	 */
	private static function outcome_body( FormModel $form, array $schema, array $settings, array $answers, array $fields, array $values, $contact, int $submission_id ): array {
		$extra = array( 'submission_id' => (string) $submission_id );
		$rule  = Logic::outcome( $schema, $values );

		if ( $rule && 'redirect' === $rule['action'] && '' !== $rule['url'] ) {
			return array(
				'type' => 'redirect',
				'url'  => esc_url_raw( Text::render( $rule['url'], $form, $answers, $fields, $contact, $extra, 'url' ) ),
			);
		}
		$endings = (array) ( $schema['screens']['endings'] ?? array() );
		if ( $rule && 'ending' === $rule['action'] ) {
			foreach ( $endings as $ending ) {
				if ( $ending['id'] === ( $rule['target']['id'] ?? '' ) ) {
					return self::ending_body( $ending, $form, $answers, $fields, $contact, $extra );
				}
			}
		}
		if ( $rule && 'message' === $rule['action'] ) {
			return array(
				'type'    => 'message',
				'message' => wp_kses_post( Text::render( $rule['message'], $form, $answers, $fields, $contact, $extra ) ),
			);
		}

		$after = $settings['after_submit'];
		if ( 'redirect' === $after['type'] && '' !== $after['redirect_url'] ) {
			return array(
				'type'    => 'redirect',
				'url'     => esc_url_raw( Text::render( $after['redirect_url'], $form, $answers, $fields, $contact, $extra, 'url' ) ),
				'new_tab' => $after['new_tab'],
			);
		}

		// The default thank-you screen (the first), or the plain message of older forms.
		if ( ! empty( $endings[0] ) ) {
			return self::ending_body( $endings[0], $form, $answers, $fields, $contact, $extra );
		}

		return array(
			'type'    => 'message',
			'message' => wp_kses_post( Text::render( $after['message'], $form, $answers, $fields, $contact, $extra ) ),
		);
	}

	/**
	 * A thank-you screen as the submit reply: title, message and an optional button (type
	 * stays "message", so older renderers still show the text).
	 *
	 * @param array     $ending  Thank-you screen.
	 * @param FormModel $form    Form.
	 * @param array     $answers Answers by key.
	 * @param array     $fields  Fields by id.
	 * @param mixed     $contact Contact allowed in tags, or null.
	 * @param array     $extra   Extra tags.
	 * @return array
	 */
	private static function ending_body( array $ending, FormModel $form, array $answers, array $fields, $contact, array $extra ): array {
		// A redirect ending sends the visitor to its page (the address can use the answers).
		if ( 'redirect' === ( $ending['type'] ?? 'screen' ) && '' !== trim( (string) ( $ending['url'] ?? '' ) ) ) {
			return array(
				'type'    => 'redirect',
				'ending'  => (string) $ending['id'],
				'url'     => esc_url_raw( Text::render( (string) $ending['url'], $form, $answers, $fields, $contact, $extra, 'url' ) ),
				'new_tab' => ! empty( $ending['new_tab'] ),
			);
		}
		$url = '' !== $ending['button_url'] ? esc_url_raw( Text::render( $ending['button_url'], $form, $answers, $fields, $contact, $extra, 'url' ) ) : '';
		return array(
			'type'    => 'message',
			'ending'  => (string) $ending['id'],
			'title'   => sanitize_text_field( Text::render( $ending['title'], $form, $answers, $fields, $contact, $extra, 'text' ) ),
			'message' => wp_kses_post( Text::render( $ending['description'], $form, $answers, $fields, $contact, $extra ) ),
			'button'  => '' !== $ending['button_label'] && '' !== $url
				? array(
					'label' => sanitize_text_field( $ending['button_label'] ),
					'url'   => $url,
				)
				: null,
		);
	}

	/**
	 * The visitor's context, cleaned: page, referrer, UTMs, click ids, first touch, device.
	 *
	 * @param mixed $raw Context the renderer sent.
	 * @return array
	 */
	public static function context( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$text = static function ( $value, int $length = 190 ): string {
			$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
		};
		// Addresses are kept without their query string or fragment (which can hold tokens,
		// emails or order keys); the referrer keeps only its site.
		$url    = static function ( $value ): string {
			$value = is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			$value = preg_replace( '/[?#].*$/', '', $value );
			return substr( (string) $value, 0, 500 );
		};
		$origin = static function ( $value ): string {
			$value  = is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
			$scheme = (string) wp_parse_url( $value, PHP_URL_SCHEME );
			$host   = (string) wp_parse_url( $value, PHP_URL_HOST );
			return ( '' !== $host && in_array( $scheme, array( 'http', 'https' ), true ) ) ? $scheme . '://' . $host : '';
		};

		$utm = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $part ) {
			$value = $text( $raw['utm'][ $part ] ?? '' );
			if ( '' !== $value ) {
				$utm[ $part ] = $value;
			}
		}
		$click = array();
		foreach ( array( 'gclid', 'fbclid', 'msclkid' ) as $part ) {
			$value = $text( $raw['click'][ $part ] ?? '', 255 );
			if ( '' !== $value ) {
				$click[ $part ] = $value;
			}
		}
		$first = array();
		if ( is_array( $raw['first'] ?? null ) ) {
			foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $part ) {
				$value = $text( $raw['first'][ $part ] ?? '' );
				if ( '' !== $value ) {
					$first[ $part ] = $value;
				}
			}
			$first['landing']  = $url( $raw['first']['landing'] ?? '' );
			$first['referrer'] = $origin( $raw['first']['referrer'] ?? '' );
			$first['at']       = $text( $raw['first']['at'] ?? '', 40 );
			$first             = array_filter( $first );
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$embed = $text( $raw['embed'] ?? '', 20 );

		return array_filter(
			array(
				'page_url'   => $url( $raw['page_url'] ?? '' ),
				'page_title' => $text( $raw['page_title'] ?? '', 255 ),
				'referrer'   => $origin( $raw['referrer'] ?? '' ),
				'utm'        => $utm,
				'click'      => $click,
				'first'      => $first,
				'embed'      => in_array( $embed, array( 'shortcode', 'block', 'page', 'popup', 'preview' ), true ) ? $embed : '',
				'device'     => self::device( $agent ),
				'user_agent' => substr( $agent, 0, 300 ),
				'ip_hash'    => wp_hash( ClientIp::get() ),
				'user_id'    => get_current_user_id() ?: null,
			)
		);
	}

	/**
	 * Desktop, tablet or mobile, from the user agent.
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	private static function device( string $agent ): string {
		if ( '' === $agent ) {
			return '';
		}
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $agent ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android|BlackBerry|Opera Mini|IEMobile/i', $agent ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Keep a consent-log row for every ticked consent box.
	 *
	 * @param ContactModel        $contact    Contact.
	 * @param FormModel           $form       Form.
	 * @param FormSubmissionModel $submission Submission.
	 * @param array               $fields     Visible input fields.
	 * @param array               $answers    Answers keyed by key.
	 * @param array               $ctx        Visitor context.
	 * @return void
	 */
	private static function log_consent( $contact, FormModel $form, $submission, array $fields, array $answers, array $ctx ): void {
		if ( ! doublescale_table_exists( 'consent_log' ) ) {
			return;
		}
		global $wpdb;
		foreach ( $fields as $field ) {
			if ( 'consent' !== $field['type'] || empty( $answers[ $field['key'] ] ) ) {
				continue;
			}
			$text = wp_strip_all_tags( (string) ( $field['props']['text'] ?? '' ) );
			if ( '' === trim( $text ) ) {
				$text = $field['label'];
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only log.
			$wpdb->insert(
				$wpdb->prefix . 'doublescale_consent_log',
				array(
					'contact_id'    => (int) $contact->id,
					'channel'       => (string) ( $field['props']['channel'] ?? 'terms' ),
					'granted'       => 1,
					'consent_text'  => $text,
					'source_type'   => 'form',
					'source_id'     => (int) $form->id,
					'submission_id' => (int) $submission->id,
					'ip_hash'       => (string) ( $ctx['ip_hash'] ?? '' ),
					'page_url'      => (string) ( $ctx['page_url'] ?? '' ),
					'created_at'    => current_time( 'mysql', true ),
				)
			);
		}
	}

	/**
	 * First and latest touch on the contact.
	 *
	 * @param ContactModel $contact Contact.
	 * @param FormModel    $form    Form.
	 * @param array        $ctx     Visitor context.
	 * @return void
	 */
	private static function attribution( $contact, FormModel $form, array $ctx ): void {
		$touch = self::touch( $ctx['utm'] ?? array(), (string) ( $ctx['referrer'] ?? '' ) );
		$touch = array_merge(
			$touch,
			array(
				'page'    => (string) ( $ctx['page_url'] ?? '' ),
				'form_id' => (int) $form->id,
				'at'      => gmdate( 'c' ),
			)
		);

		$first = doublescale_get_contact_meta( (int) $contact->id, 'first_touch', true );
		if ( empty( $first ) ) {
			$landing = $ctx['first'] ?? array();
			$first   = ! empty( $landing )
				? array_merge(
					self::touch( $landing, (string) ( $landing['referrer'] ?? '' ) ),
					array(
						'page'    => (string) ( $landing['landing'] ?? '' ),
						'form_id' => (int) $form->id,
						'at'      => (string) ( $landing['at'] ?? gmdate( 'c' ) ),
					)
				)
				: $touch;
			doublescale_update_contact_meta( (int) $contact->id, 'first_touch', $first );
			self::touch_keys( (int) $contact->id, 'first_touch', $first );
		}
		doublescale_update_contact_meta( (int) $contact->id, 'latest_touch', $touch );
		self::touch_keys( (int) $contact->id, 'latest_touch', $touch );
	}

	/**
	 * One plain meta row per part of a touch (first_touch_source = linkedin, …), so contact
	 * filters, segments and lead scoring can match on them in SQL.
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $prefix     first_touch or latest_touch.
	 * @param array  $touch      Touch.
	 * @return void
	 */
	private static function touch_keys( int $contact_id, string $prefix, array $touch ): void {
		foreach ( array( 'source', 'medium', 'campaign' ) as $part ) {
			$value = strtolower( (string) ( $touch[ $part ] ?? '' ) );
			if ( '' === $value ) {
				doublescale_delete_contact_meta( $contact_id, $prefix . '_' . $part );
			} else {
				doublescale_update_contact_meta( $contact_id, $prefix . '_' . $part, substr( $value, 0, 190 ) );
			}
		}
	}

	/**
	 * Source and medium from UTMs, or from the referrer when there are none.
	 *
	 * @param array  $utm      UTM values.
	 * @param string $referrer Referrer address.
	 * @return array
	 */
	private static function touch( array $utm, string $referrer ): array {
		$source = (string) ( $utm['source'] ?? '' );
		$medium = (string) ( $utm['medium'] ?? '' );
		$host   = $referrer ? strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) ) : '';
		$own    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $own ) {
			$host = '';
		}

		if ( '' === $source ) {
			if ( '' === $host ) {
				$source = 'direct';
				$medium = $medium ?: 'none';
			} else {
				$source = preg_replace( '/^www\./', '', $host );
				$medium = $medium ?: ( preg_match( '/(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia)\./', $host ) ? 'organic' : 'referral' );
			}
		}

		return array_filter(
			array(
				'source'   => $source,
				'medium'   => $medium,
				'campaign' => (string) ( $utm['campaign'] ?? '' ),
				'term'     => (string) ( $utm['term'] ?? '' ),
				'content'  => (string) ( $utm['content'] ?? '' ),
				'referrer' => $referrer,
			)
		);
	}

	/**
	 * A timeline note: "Submitted <form>" with the first answers.
	 *
	 * @param ContactModel        $contact    Contact.
	 * @param FormModel           $form       Form.
	 * @param FormSubmissionModel $submission Submission.
	 * @param array               $fields     Visible input fields.
	 * @param array               $answers    Answers keyed by key.
	 * @return void
	 */
	private static function timeline( $contact, FormModel $form, $submission, array $fields, array $answers ): void {
		if ( ! class_exists( '\DoubleScale\Modules\Activities\Models\ActivityModel' ) || ! doublescale_table_exists( 'activities' ) ) {
			return;
		}
		$lines = array();
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field['key'], $answers ) || ! FieldTypes::get( $field )->in_timeline() ) {
				continue;
			}
			$value = FieldTypes::readable( $field, $answers[ $field['key'] ] );
			if ( '' === $value ) {
				continue;
			}
			$value   = function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $value, 0, 140, '…' ) : substr( $value, 0, 140 );
			$lines[] = ( '' !== $field['label'] ? $field['label'] : $field['key'] ) . ': ' . $value;
			if ( count( $lines ) >= 3 ) {
				break;
			}
		}

		try {
			\DoubleScale\Modules\Activities\Models\ActivityModel::create(
				array(
					'contact_id'    => (int) $contact->id,
					'activity_type' => 'note',
					'data'          => array(
						/* translators: %s: form name */
						'title'         => sprintf( __( 'Submitted %s', 'doublescale' ), $form->name ),
						'type'          => 'system',
						'note'          => implode( "\n", $lines ),
						'form_id'       => (int) $form->id,
						'submission_id' => (int) $submission->id,
					),
					'user_id'       => null,
				)
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning( 'Could not add the form submission to the timeline', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Entries sent from a browser before it was known, handed to the contact it now belongs to
	 * (website tracking, Pro, after the visitor created a contact with a form or logged in).
	 * Only complete entries still without a contact, sent in the last 30 days, are taken; the
	 * contact's own details are not changed by them.
	 *
	 * @param int   $contact_id     Contact id.
	 * @param int[] $submission_ids Submission ids from the browser (signed cookie).
	 * @return int Entries linked.
	 */
	public static function claim_entries( $contact_id, $submission_ids ): int {
		$contact = ContactModel::find( (int) $contact_id );
		if ( ! $contact || ! is_array( $submission_ids ) ) {
			return 0;
		}
		$linked = 0;
		foreach ( array_slice( array_unique( array_map( 'intval', $submission_ids ) ), 0, 20 ) as $id ) {
			$submission = $id > 0 ? FormSubmissionModel::find( $id ) : null;
			if ( ! $submission || $submission->contact_id || FormSubmissionModel::STATUS_COMPLETE !== $submission->status ) {
				continue;
			}
			$meta = is_array( $submission->meta ) ? $submission->meta : array();
			if ( 'no_identifier' !== ( $meta['contact_reason'] ?? '' ) || strtotime( (string) $submission->created_at ) < time() - 30 * DAY_IN_SECONDS ) {
				continue;
			}
			$form = FormModel::find( (int) $submission->form_id );
			if ( ! $form || ! $form->is_native() ) {
				continue;
			}
			$submission->contact_id = (int) $contact->id;
			$submission->meta       = array_merge( $meta, array( 'contact_reason' => 'identified_later', 'linked_at' => gmdate( 'c' ) ) );
			$submission->save();
			$fields = Schema::input_fields( Schema::effective( $form )['schema'] );
			self::timeline( $contact, $form, $submission, $fields, is_array( $submission->answers ) ? $submission->answers : array() );
			/**
			 * An earlier entry now belongs to a contact.
			 *
			 * @param FormSubmissionModel $submission Submission.
			 * @param FormModel           $form       Form.
			 * @param ContactModel        $contact    Contact.
			 */
			do_action( 'doublescale_native_form_entry_linked', $submission, $form, $contact );
			++$linked;
		}
		return $linked;
	}

	/**
	 * Whether the logged-in user is this contact.
	 *
	 * @param ContactModel $contact Contact.
	 * @return bool
	 */
	private static function is_current_users_contact( $contact ): bool {
		$user = wp_get_current_user();
		return $user && $user->exists() && '' !== (string) $contact->email && strtolower( (string) $user->user_email ) === strtolower( (string) $contact->email );
	}

	/**
	 * Tie this browser to the contact, so website tracking (Pro) keeps following them and
	 * the pages they read before the form land on their timeline.
	 *
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	private static function set_tracking_cookie( $contact ): void {
		if ( headers_sent() || ! apply_filters( 'doublescale_native_form_set_tracking_cookie', true, $contact ) ) {
			return;
		}
		if ( function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'websitetracking' )
			&& class_exists( '\DoubleScale\Pro\Modules\WebsiteTracking\Website' ) ) {
			try {
				\DoubleScale\Pro\Modules\WebsiteTracking\Website::instance()->track_anonymous_visits_after_signup( $contact );
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->warning( 'Form could not start website tracking', array( 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * A reply.
	 *
	 * @param int   $status HTTP status.
	 * @param array $body   Body.
	 * @return array{status: int, body: array}
	 */
	private static function reply( int $status, array $body ): array {
		return array(
			'status' => $status,
			'body'   => $body,
		);
	}
}
