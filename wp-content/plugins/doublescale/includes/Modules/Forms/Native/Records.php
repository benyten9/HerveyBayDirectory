<?php
/**
 * "Also create": a submission can also make a company, a deal, a ticket and a project, all
 * linked to the contact, to each other and to the submission.
 *
 * - Company (Pro): found by name or website first (existing details only filled in where
 *   empty), else created; the contact joins it.
 * - Deal (Pro): in the chosen pipeline and stage, value fixed or from an answer. If the
 *   contact already has an open deal in that pipeline it is updated instead (per form).
 * - Ticket (Helpdesk): the answers become the first message; the customer gets the normal
 *   "we got your request" email unless the form turns it off.
 * - Project (Pro): with the chosen status, owner, budget and due date; linked to the deal
 *   made by the same submission.
 * - Each can run only when the answers match conditions (the builder's logic editor).
 *
 * Runs in the background step after the submission (never slows the visitor down), through
 * the same services the admin uses, so activity, notifications and automations follow.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;

defined( 'ABSPATH' ) || exit;

/**
 * Records made from a submission.
 */
final class Records {

	public const KINDS = array( 'company', 'deal', 'ticket', 'project' );

	/**
	 * Defaults of the "Also create" settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$when = array(
			'match'  => 'all',
			'groups' => array(),
		);
		return array(
			'company' => array(
				'enabled'       => false,
				'conditional'   => false,
				'when'          => $when,
				'name_field'    => '',
				'website_field' => '',
				'phone_field'   => '',
				'email_field'   => '',
			),
			'deal'    => array(
				'enabled'     => false,
				'conditional' => false,
				'when'        => $when,
				'pipeline_id' => 0,
				'stage_id'    => 0,
				'title'       => '{{form_title}}: {{contact:first_name}} {{contact:last_name}}',
				'value_from'  => 'fixed',
				'value'       => 0,
				'value_field' => '',
				'priority'    => 'medium',
				'owner_id'    => 0,
				'existing'    => 'update',
				'fields'      => array(),
			),
			'ticket'  => array(
				'enabled'     => false,
				'conditional' => false,
				'when'        => $when,
				'title'       => '{{form_title}}',
				'priority'    => 'normal',
				'mailbox_id'  => 0,
				'notify'      => true,
				'fields'      => array(),
			),
			'project' => array(
				'enabled'      => false,
				'conditional'  => false,
				'when'         => $when,
				'title'        => '{{form_title}}: {{contact:first_name}} {{contact:last_name}}',
				'status_id'    => 0,
				'owner_id'     => 0,
				'budget_from'  => 'none',
				'budget'       => 0,
				'budget_field' => '',
				'due_days'     => 0,
				'link_deal'    => false,
				'fields'       => array(),
			),
		);
	}

	/**
	 * Clean the "Also create" settings.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	public static function normalize( $raw ): array {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$out      = array();
		$key      = static function ( $value ): string {
			return sanitize_key( (string) $value );
		};
		$title    = static function ( $value, string $fallback ): string {
			$value = trim( sanitize_text_field( (string) $value ) );
			return '' !== $value ? substr( $value, 0, 250 ) : $fallback;
		};
		// Target => form field key, both keys (custom field ids are digits).
		$map = static function ( $fields ) use ( $key ): array {
			$clean = array();
			foreach ( (array) $fields as $target => $field ) {
				$target = sanitize_key( (string) $target );
				$field  = $key( $field );
				if ( '' !== $target && '' !== $field && count( $clean ) < 100 ) {
					$clean[ $target ] = $field;
				}
			}
			return $clean;
		};

		foreach ( self::KINDS as $kind ) {
			$item         = array_merge( $defaults[ $kind ], (array) ( $raw[ $kind ] ?? array() ) );
			$out[ $kind ] = array(
				'enabled'     => ! empty( $item['enabled'] ),
				'conditional' => ! empty( $item['conditional'] ),
				'when'        => Schema::normalize_when( $item['when'] ),
			);
		}

		$company        = array_merge( $defaults['company'], (array) ( $raw['company'] ?? array() ) );
		$out['company'] += array(
			'name_field'    => $key( $company['name_field'] ),
			'website_field' => $key( $company['website_field'] ),
			'phone_field'   => $key( $company['phone_field'] ),
			'email_field'   => $key( $company['email_field'] ),
		);

		$deal        = array_merge( $defaults['deal'], (array) ( $raw['deal'] ?? array() ) );
		$out['deal'] += array(
			'pipeline_id' => absint( $deal['pipeline_id'] ),
			'stage_id'    => absint( $deal['stage_id'] ),
			'title'       => $title( $deal['title'], $defaults['deal']['title'] ),
			'value_from'  => 'field' === $deal['value_from'] ? 'field' : 'fixed',
			'value'       => max( 0, min( 1000000000, round( (float) $deal['value'], 2 ) ) ),
			'value_field' => $key( $deal['value_field'] ),
			'priority'    => in_array( $deal['priority'], array( 'low', 'medium', 'high' ), true ) ? $deal['priority'] : 'medium',
			'owner_id'    => absint( $deal['owner_id'] ),
			'existing'    => 'create' === $deal['existing'] ? 'create' : 'update',
			'fields'      => $map( $deal['fields'] ),
		);

		$ticket        = array_merge( $defaults['ticket'], (array) ( $raw['ticket'] ?? array() ) );
		$out['ticket'] += array(
			'title'      => $title( $ticket['title'], $defaults['ticket']['title'] ),
			'priority'   => in_array( $ticket['priority'], array( 'low', 'normal', 'high', 'urgent' ), true ) ? $ticket['priority'] : 'normal',
			'mailbox_id' => absint( $ticket['mailbox_id'] ),
			'notify'     => ! empty( $ticket['notify'] ),
			'fields'     => $map( $ticket['fields'] ),
		);

		$project        = array_merge( $defaults['project'], (array) ( $raw['project'] ?? array() ) );
		$out['project'] += array(
			'title'        => $title( $project['title'], $defaults['project']['title'] ),
			'status_id'    => absint( $project['status_id'] ),
			'owner_id'     => absint( $project['owner_id'] ),
			'budget_from'  => in_array( $project['budget_from'], array( 'none', 'fixed', 'field' ), true ) ? $project['budget_from'] : 'none',
			'budget'       => max( 0, min( 1000000000, round( (float) $project['budget'], 2 ) ) ),
			'budget_field' => $key( $project['budget_field'] ),
			'due_days'     => max( 0, min( 3650, (int) $project['due_days'] ) ),
			'link_deal'    => ! empty( $project['link_deal'] ),
			'fields'       => $map( $project['fields'] ),
		);

		return $out;
	}

	/**
	 * Which record types this site can make now (module on, Pro where needed).
	 *
	 * @return array<string, bool>
	 */
	public static function available(): array {
		$pro = Schema::pro_active();
		return array(
			'company' => $pro && doublescale_module_data_ready( 'companies' ) && doublescale_table_exists( 'companies' ) && doublescale_table_exists( 'company_contact' ),
			'deal'    => $pro && doublescale_module_data_ready( 'deals' ),
			'ticket'  => doublescale_module_data_ready( 'support' ),
			'project' => $pro && doublescale_module_data_ready( 'projects' ),
		);
	}

	/**
	 * Make the records of a submission (background step). Never throws.
	 *
	 * @param FormModel           $form       Form.
	 * @param array               $settings   Normalized settings.
	 * @param FormSubmissionModel $submission Complete submission.
	 * @param array               $fields     Input fields by id.
	 * @param array               $answers    Answers by key.
	 * @param ContactModel|null   $contact    The contact (records need one).
	 * @return array<string, int> Ids made or updated, by kind.
	 */
	public static function run( FormModel $form, array $settings, $submission, array $fields, array $answers, $contact ): array {
		$records = $settings['records'] ?? array();
		$made    = array();
		if ( ! $contact instanceof ContactModel || empty( $records ) ) {
			return $made;
		}
		$meta = (array) $submission->meta;
		if ( ! empty( $meta['records'] ) ) {
			// Already made (the background step ran twice): never make doubles.
			return (array) $meta['records'];
		}

		$available = self::available();
		$values    = self::values_by_id( $fields, $answers );
		$context   = array(
			'form'       => $form,
			'settings'   => $settings,
			'submission' => $submission,
			'fields'     => $fields,
			'answers'    => $answers,
			'contact'    => $contact,
			// Matched by an email or phone someone typed: it may not be them.
			'unverified' => empty( $meta['contact_created'] ) && 'logged_in' !== ( $meta['contact_reason'] ?? '' ),
			// Signed in as this contact: the only submitter we know is really them.
			'own'        => 'logged_in' === ( $meta['contact_reason'] ?? '' ),
		);
		self::$review = array();

		foreach ( self::KINDS as $kind ) {
			$config = $records[ $kind ] ?? array();
			if ( empty( $config['enabled'] ) || empty( $available[ $kind ] ) ) {
				continue;
			}
			if ( ! empty( $config['conditional'] ) && ! empty( $config['when']['groups'] ) && ! Logic::matches( $config['when'], $values ) ) {
				continue;
			}
			try {
				$id = (int) call_user_func( array( self::class, 'make_' . $kind ), $config, $context, $made );
				if ( $id > 0 ) {
					$made[ $kind ] = $id;
				}
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->error(
					'Form could not create a linked record',
					array(
						'code'          => 'native_form_record_failed',
						'kind'          => $kind,
						'form_id'       => $form->id,
						'submission_id' => $submission->id,
						'error'         => $e->getMessage(),
					)
				);
			}
		}

		if ( $made || self::$review ) {
			if ( $made ) {
				$meta['records'] = $made;
			}
			if ( self::$review ) {
				// Left for the team: matched records the visitor was not trusted to change.
				$meta['records_review'] = self::$review;
			}
			$submission->meta = $meta;
			$submission->save();
			if ( $made ) {
				self::link_note( $form, $submission, $contact, $made );
			}
		}
		return $made;
	}

	/**
	 * Matched records a submission was not allowed to change, by kind (for the team to review).
	 *
	 * @var array<string, mixed>
	 */
	private static $review = array();

	/**
	 * Company: found by name (or website), else created; the contact joins it.
	 *
	 * Joining an existing company gives access to what it shares (portal projects, tickets,
	 * invoices), so a public form only links an existing company for a visitor signed in as
	 * that contact. Anyone else's match is left for the team (submission meta
	 * records_review), and nothing about the existing company changes.
	 *
	 * @param array $config  Company settings.
	 * @param array $context Submission context.
	 * @param array $made    Records made so far.
	 * @return int
	 */
	private static function make_company( array $config, array $context, array $made ): int {
		unset( $made );
		$service = '\DoubleScale\Pro\Modules\Companies\Services\CompanyService';
		$model   = '\DoubleScale\Pro\Modules\Companies\Models\CompanyModel';
		$contact = $context['contact'];

		$name    = self::answer_text( $context['answers'], $config['name_field'] );
		if ( '' === $name ) {
			$name = trim( (string) $contact->company_name );
		}
		$website = esc_url_raw( self::answer_text( $context['answers'], $config['website_field'] ) );
		$phone   = self::answer_text( $context['answers'], $config['phone_field'] );
		$email   = sanitize_email( self::answer_text( $context['answers'], $config['email_field'] ) );
		$domain  = '' !== $website ? (string) $service::normalize_domain( $website ) : '';
		if ( '' !== $domain && $service::is_public_domain( $domain ) ) {
			$domain = '';
		}
		if ( '' === $name && '' === $domain ) {
			return 0;
		}

		if ( $context['unverified'] ) {
			// Someone else's contact (matched by a typed email): don't tie it to any company.
			self::$review['company'] = array( 'reason' => 'unverified_contact', 'name' => substr( $name, 0, 190 ), 'domain' => $domain );
			return 0;
		}

		$company = null;
		if ( '' !== $name ) {
			$company = $model::whereRaw( 'LOWER(TRIM(name)) = ?', array( strtolower( $name ) ) )->first();
		}
		if ( ! $company && '' !== $domain ) {
			$matches = $model::where( 'domain', $domain )->limit( 2 )->get();
			$company = 1 === count( $matches ) ? $matches[0] : null;
		}

		$details = array_filter(
			array(
				'website' => $website,
				'domain'  => $domain,
				'phone'   => sanitize_text_field( $phone ),
				'email'   => is_email( $email ) ? $email : '',
			)
		);

		$created = false;
		if ( $company && empty( $context['own'] ) && ! $service::is_member( (int) $company->id, (int) $contact->id ) ) {
			self::$review['company'] = array( 'reason' => 'existing_company', 'id' => (int) $company->id );
			return 0;
		}

		if ( $company ) {
			// Someone typed these, so they only fill what the company does not have yet.
			$fill = array();
			foreach ( $details as $field => $value ) {
				if ( '' === trim( (string) $company->getAttribute( $field ) ) ) {
					$fill[ $field ] = $value;
				}
			}
			if ( $fill ) {
				$company->fill( $fill );
				$company->save();
			}
		} else {
			$created = true;
			if ( '' === $name ) {
				$name = $domain;
			}
			$owner   = (int) ( $contact->owner_id ?? 0 );
			$company = $model::create(
				array_merge(
					$details,
					array(
						'name'          => substr( sanitize_text_field( $name ), 0, 190 ),
						'owner_user_id' => $owner > 0 ? $owner : null,
					)
				)
			);
			do_action( 'doublescale_company_created', (int) $company->id );
		}

		// A company this submission created: the contact may lead it. An existing one: only
		// ever a plain member, never its primary contact.
		$service::add_contact_company( (int) $contact->id, (int) $company->id, $created ? null : $service::ROLE_MEMBER );
		return (int) $company->id;
	}

	/**
	 * Deal: updates the contact's open deal in the pipeline (when the form says so), else creates one.
	 *
	 * @param array $config  Deal settings.
	 * @param array $context Submission context.
	 * @param array $made    Records made so far.
	 * @return int
	 */
	private static function make_deal( array $config, array $context, array $made ): int {
		$deal_model = '\DoubleScale\Pro\Modules\Deals\Models\DealModel';
		$stage_model = '\DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel';
		$manager    = '\DoubleScale\Pro\Modules\Deals\Services\DealManager';
		$contact    = $context['contact'];

		$stage = $config['stage_id'] ? $stage_model::find( $config['stage_id'] ) : null;
		if ( ! $stage || (int) $stage->pipeline_id !== (int) $config['pipeline_id'] ) {
			return 0;
		}

		$value = 'field' === $config['value_from']
			? self::answer_number( $context['answers'], $config['value_field'] )
			: (float) $config['value'];
		$value = null === $value ? null : max( 0, $value );

		// Only the contact's own submission may change their open deal; a typed email adds a new one.
		if ( 'update' === $config['existing'] && empty( $context['unverified'] ) ) {
			$open = $deal_model::where( 'contact_id', (int) $contact->id )
				->where( 'pipeline_id', (int) $config['pipeline_id'] )
				->where( 'status', 'open' )
				->orderBy( 'created_at', 'desc' )
				->first();
			if ( $open ) {
				if ( null !== $value && $value > 0 && (float) $open->value !== $value ) {
					$manager::instance()->update_deal( (int) $open->id, array( 'value' => $value ) );
				}
				self::save_custom_fields( $open, 'deal', $config['fields'], $context );
				self::attach_company( $open, $made );
				return (int) $open->id;
			}
		}

		$deal = $manager::instance()->create_deal(
			array(
				'title'       => self::title( $config['title'], $context, __( 'New deal', 'doublescale' ) ),
				'contact_id'  => (int) $contact->id,
				'pipeline_id' => (int) $config['pipeline_id'],
				'stage_id'    => (int) $config['stage_id'],
				'value'       => null === $value ? 0 : $value,
				'priority'    => $config['priority'],
				'owner_id'    => self::deal_owner( $config, $context ),
				'source'      => 'website',
			)
		);
		if ( ! $deal ) {
			return 0;
		}
		self::save_custom_fields( $deal, 'deal', $config['fields'], $context );
		self::attach_company( $deal, $made );
		return (int) $deal->id;
	}

	/**
	 * Ticket: the answers become the first message, written by the customer.
	 *
	 * @param array $config  Ticket settings.
	 * @param array $context Submission context.
	 * @param array $made    Records made so far.
	 * @return int
	 */
	private static function make_ticket( array $config, array $context, array $made ): int {
		$service = function_exists( 'doublescale_resolve' ) ? doublescale_resolve( \DoubleScale\Modules\Support\Services\TicketService::class ) : null;
		$service = $service ? $service : apply_filters( 'doublescale_support_ticket_service_instance', null );
		$service = $service ? $service : new \DoubleScale\Modules\Support\Services\TicketService( new \DoubleScale\Modules\Support\Services\ContactResolver() );

		$by_key = array();
		foreach ( $context['fields'] as $field ) {
			$by_key[ $field['key'] ] = $field;
		}
		$content = Text::all_answers( $context['answers'], $by_key, 'html' );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			$content = '<p>' . esc_html( sprintf( /* translators: %s: form name */ __( 'Sent from the form %s.', 'doublescale' ), $context['form']->name ) ) . '</p>';
		}

		$data = array(
			'title'          => self::title( $config['title'], $context, (string) $context['form']->name ),
			'content'        => $content,
			'contact_id'     => (int) $context['contact']->id,
			'priority'       => $config['priority'],
			'status'         => 'open',
			'source'         => 'web',
			'author_user_id' => null,
		);
		if ( $config['mailbox_id'] ) {
			$data['mailbox_id'] = (int) $config['mailbox_id'];
		}
		if ( ! empty( $made['company'] ) ) {
			$data['company_id'] = (int) $made['company'];
		}
		// Someone else's email typed into a form: the acknowledgement stays neutral.
		if ( $context['unverified'] ) {
			$data[ \DoubleScale\Modules\Support\Services\TicketService::UNVERIFIED_KEY ] = true;
		}
		$custom = array();
		foreach ( $config['fields'] as $target => $field_key ) {
			$value = self::answer_text( $context['answers'], $field_key );
			if ( '' !== $value ) {
				$custom[ $target ] = $value;
			}
		}
		if ( $custom ) {
			$data['custom_data'] = $custom;
		}

		$mailer = null;
		if ( ! $config['notify'] && function_exists( 'doublescale_resolve' ) ) {
			$mailer = doublescale_resolve( \DoubleScale\Modules\Support\Services\EmailNotifications::class );
			if ( $mailer ) {
				remove_action( 'doublescale_support_ticket_created', array( $mailer, 'on_ticket_created' ), 10 );
			}
		}
		try {
			$ticket = $service->create_ticket( $data );
			// A required ticket field the form doesn't fill: still open the ticket, without them.
			if ( is_wp_error( $ticket ) && isset( $data['custom_data'] ) ) {
				unset( $data['custom_data'] );
				$ticket = $service->create_ticket( $data );
			}
		} finally {
			if ( $mailer ) {
				add_action( 'doublescale_support_ticket_created', array( $mailer, 'on_ticket_created' ), 10, 1 );
			}
		}

		if ( is_wp_error( $ticket ) || ! $ticket ) {
			doublescale_get_logger()->warning(
				'Form could not open a ticket',
				array(
					'code'    => 'native_form_ticket_failed',
					'form_id' => $context['form']->id,
					'error'   => is_wp_error( $ticket ) ? $ticket->get_error_message() : 'unknown',
				)
			);
			return 0;
		}
		return (int) $ticket->id;
	}

	/**
	 * Project: for the contact (and their company), linked to the deal made by this submission.
	 *
	 * @param array $config  Project settings.
	 * @param array $context Submission context.
	 * @param array $made    Records made so far.
	 * @return int
	 */
	private static function make_project( array $config, array $context, array $made ): int {
		$manager      = '\DoubleScale\Pro\Modules\Projects\Services\ProjectManager';
		$status_model = '\DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel';
		$contact      = $context['contact'];

		if ( ! empty( $context['unverified'] ) ) {
			// Projects show in the contact's portal: never on someone else's contact from a typed email.
			self::$review['project'] = array( 'reason' => 'unverified_contact' );
			return 0;
		}

		$status_id = (int) $config['status_id'];
		if ( ! $status_id || ! $status_model::find( $status_id ) ) {
			$default   = $manager::instance()->get_default_open_status();
			$status_id = $default ? (int) $default->id : 0;
		}
		if ( ! $status_id ) {
			return 0;
		}

		$budget = null;
		if ( 'fixed' === $config['budget_from'] ) {
			$budget = (float) $config['budget'];
		} elseif ( 'field' === $config['budget_from'] ) {
			$budget = self::answer_number( $context['answers'], $config['budget_field'] );
		}

		$by_key = array();
		foreach ( $context['fields'] as $field ) {
			$by_key[ $field['key'] ] = $field;
		}

		$data = array(
			'title'       => self::title( $config['title'], $context, __( 'New project', 'doublescale' ) ),
			'description' => wp_kses_post( Text::all_answers( $context['answers'], $by_key, 'html' ) ),
			'status_id'   => $status_id,
			'contact_id'  => (int) $contact->id,
			'start_date'  => current_datetime()->format( 'Y-m-d' ),
			'owner_id'    => self::project_owner( $config, $context ),
		);
		if ( $config['due_days'] > 0 ) {
			$data['due_date'] = current_datetime()->modify( '+' . (int) $config['due_days'] . ' days' )->format( 'Y-m-d' );
		}
		if ( null !== $budget && $budget > 0 ) {
			$data['budget'] = $budget;
		}
		if ( $config['link_deal'] && ! empty( $made['deal'] ) ) {
			$data['deal_id'] = (int) $made['deal'];
		}
		$company_id = ! empty( $made['company'] ) ? (int) $made['company'] : 0;
		if ( $company_id && is_callable( array( '\DoubleScale\Pro\Modules\Companies\Services\CompanyService', 'record_company_id' ) ) ) {
			$data['company_id'] = \DoubleScale\Pro\Modules\Companies\Services\CompanyService::record_company_id( (int) $contact->id, $company_id );
		}

		$project = $manager::instance()->create_project( array_filter( $data, static function ( $value ) {
			return null !== $value && '' !== $value;
		} ) );
		if ( ! $project ) {
			return 0;
		}
		self::save_custom_fields( $project, 'project', $config['fields'], $context );
		return (int) $project->id;
	}

	/**
	 * One note on the contact's timeline, linked to every record made, saying where they came from.
	 *
	 * @param FormModel           $form       Form.
	 * @param FormSubmissionModel $submission Submission.
	 * @param ContactModel        $contact    Contact.
	 * @param array               $made       Ids by kind.
	 * @return void
	 */
	private static function link_note( FormModel $form, $submission, $contact, array $made ): void {
		if ( ! class_exists( ActivityModel::class ) || ! doublescale_table_exists( 'activities' ) ) {
			return;
		}
		$labels = array(
			'company' => __( 'Company', 'doublescale' ),
			'deal'    => __( 'Deal', 'doublescale' ),
			'ticket'  => __( 'Ticket', 'doublescale' ),
			'project' => __( 'Project', 'doublescale' ),
		);
		$lines = array();
		foreach ( $made as $kind => $id ) {
			$lines[] = $labels[ $kind ] . ' #' . (int) $id;
		}
		$activity = ActivityModel::create(
			array(
				'contact_id'    => (int) $contact->id,
				'activity_type' => 'note',
				'data'          => array(
					/* translators: %s: form name */
					'title'         => sprintf( __( 'Created from the form %s', 'doublescale' ), $form->name ),
					'type'          => 'system',
					'note'          => implode( "\n", $lines ),
					'form_id'       => (int) $form->id,
					'submission_id' => (int) $submission->id,
				),
				'user_id'       => null,
			)
		);
		if ( ! $activity || ! class_exists( ActivityAssociationModel::class ) ) {
			return;
		}
		$types = array(
			'deal'    => ActivityAssociationModel::ENTITY_TYPE_DEAL,
			'ticket'  => ActivityAssociationModel::ENTITY_TYPE_TICKET,
			'project' => ActivityAssociationModel::ENTITY_TYPE_PROJECT,
		);
		foreach ( $types as $kind => $type ) {
			if ( ! empty( $made[ $kind ] ) ) {
				ActivityAssociationModel::create(
					array(
						'activity_id' => (int) $activity->id,
						'entity_type' => $type,
						'entity_id'   => (int) $made[ $kind ],
					)
				);
			}
		}
	}

	/**
	 * The deal's company: the one this submission found or made, if the contact belongs to it.
	 * (A new deal takes the contact's main company; this keeps the form's choice.)
	 *
	 * @param mixed $deal Deal.
	 * @param array $made Records made so far.
	 * @return void
	 */
	private static function attach_company( $deal, array $made ): void {
		if ( empty( $made['company'] ) || (int) $deal->company_id === (int) $made['company'] ) {
			return;
		}
		$deal->company_id = (int) $made['company'];
		$deal->save();
	}

	/**
	 * Custom field answers for a deal or project (written like the contact's, no extra rules).
	 *
	 * @param mixed  $record  Deal or project.
	 * @param string $type    deal or project.
	 * @param array  $map     Custom field id => form field key.
	 * @param array  $context Submission context.
	 * @return void
	 */
	private static function save_custom_fields( $record, string $type, array $map, array $context ): void {
		if ( empty( $map ) || ! method_exists( $record, 'custom_fields' ) ) {
			return;
		}
		$values = array();
		foreach ( $map as $custom_field_id => $field_key ) {
			if ( ! ctype_digit( (string) $custom_field_id ) ) {
				continue;
			}
			$answer = $context['answers'][ $field_key ] ?? null;
			if ( null === $answer || '' === $answer || array() === $answer ) {
				continue;
			}
			$values[ (int) $custom_field_id ] = array(
				'value'       => is_array( $answer ) ? implode( ', ', array_map( 'strval', self::flatten( $answer ) ) ) : (string) $answer,
				'entity_type' => $type,
			);
		}
		if ( $values ) {
			$record->custom_fields()->syncWithoutDetaching( $values );
		}
	}

	/**
	 * Owner of a new deal: the form's choice, else the contact's owner, else the form's contact owner.
	 * Only someone who may work deals.
	 *
	 * @param array $config  Deal settings.
	 * @param array $context Submission context.
	 * @return int|null
	 */
	private static function deal_owner( array $config, array $context ) {
		$rule = '\DoubleScale\Pro\Modules\Deals\Services\DealOwnerRule';
		foreach ( array( (int) $config['owner_id'], (int) ( $context['contact']->owner_id ?? 0 ), (int) ( $context['settings']['contact']['owner_id'] ?? 0 ) ) as $user_id ) {
			if ( $user_id > 0 && get_userdata( $user_id ) && ( ! is_callable( array( $rule, 'error' ) ) || null === $rule::error( $user_id ) ) ) {
				return $user_id;
			}
		}
		return null;
	}

	/**
	 * Owner of a new project, picked like the deal's, among people who may run projects.
	 *
	 * @param array $config  Project settings.
	 * @param array $context Submission context.
	 * @return int|null
	 */
	private static function project_owner( array $config, array $context ) {
		$team = '\DoubleScale\Pro\Modules\Projects\Services\ProjectTeam';
		foreach ( array( (int) $config['owner_id'], (int) ( $context['contact']->owner_id ?? 0 ), (int) ( $context['settings']['contact']['owner_id'] ?? 0 ) ) as $user_id ) {
			if ( $user_id > 0 && get_userdata( $user_id ) && ( ! is_callable( array( $team, 'is_eligible' ) ) || $team::is_eligible( $user_id ) ) ) {
				return $user_id;
			}
		}
		return null;
	}

	/**
	 * A record title from a template with merge tags (plain text).
	 *
	 * @param string $template Template.
	 * @param array  $context  Submission context.
	 * @param string $fallback When the result is empty.
	 * @return string
	 */
	private static function title( string $template, array $context, string $fallback ): string {
		$text = Text::render( $template, $context['form'], $context['answers'], $context['fields'], $context['contact'], array(), 'text' );
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		$text = trim( $text, " \t:-–" );
		return '' !== $text ? substr( $text, 0, 190 ) : $fallback;
	}

	/**
	 * An answer as text ('' when none).
	 *
	 * @param array  $answers Answers by key.
	 * @param string $key     Field key.
	 * @return string
	 */
	private static function answer_text( array $answers, string $key ): string {
		if ( '' === $key || ! array_key_exists( $key, $answers ) ) {
			return '';
		}
		$value = $answers[ $key ];
		if ( is_array( $value ) ) {
			return trim( implode( ', ', array_filter( array_map( 'strval', self::flatten( $value ) ) ) ) );
		}
		return trim( sanitize_text_field( (string) $value ) );
	}

	/**
	 * An answer as a number (null when none): "1,500", "$2000" and "1500.50" all read.
	 *
	 * @param array  $answers Answers by key.
	 * @param string $key     Field key.
	 * @return float|null
	 */
	private static function answer_number( array $answers, string $key ) {
		$text = self::answer_text( $answers, $key );
		if ( '' === $text ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9.\-]/', '', str_replace( ',', '', $text ) );
		return is_numeric( $clean ) ? round( (float) $clean, 2 ) : null;
	}

	/**
	 * Answers by field id (what Logic reads).
	 *
	 * @param array $fields  Fields by id.
	 * @param array $answers Answers by key.
	 * @return array
	 */
	private static function values_by_id( array $fields, array $answers ): array {
		$values = array();
		foreach ( $fields as $id => $field ) {
			$values[ $id ] = $answers[ $field['key'] ] ?? null;
		}
		return $values;
	}

	/**
	 * Flatten a nested answer (name, address) into its parts.
	 *
	 * @param array $value Value.
	 * @return array
	 */
	private static function flatten( array $value ): array {
		$out = array();
		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$out ) {
				if ( is_scalar( $item ) && '' !== (string) $item ) {
					$out[] = (string) $item;
				}
			}
		);
		return $out;
	}
}
