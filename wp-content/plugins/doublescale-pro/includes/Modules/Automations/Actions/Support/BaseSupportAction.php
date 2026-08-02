<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Support;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Core\UserRoles\UserRoles;

/**
 * Base utilities for Support ticket automation actions.
 *
 * Mirrors the Deal action family: a shared "affects" selector decides which
 * ticket(s) belonging to the automation contact a step operates on, and every
 * mutation is routed through {@see TicketService} so the canonical
 * `doublescale_support_ticket_*` events fire exactly once.
 *
 * @since 1.3.0
 */
abstract class BaseSupportAction extends Action {

	/**
	 * Translate helper that is linter-safe in namespaced context.
	 *
	 * @param string $text Text to translate.
	 * @return string
	 */
	protected function t( $text ) {
		if ( function_exists( '\\__' ) ) {
			return call_user_func( '\\__', $text, 'doublescale' );
		}
		return $text;
	}

	/**
	 * Resolve the shared TicketService instance.
	 *
	 * Prefers the DI-container singleton (so a filtered/overridden service is
	 * honoured) and falls back to a plain instance, matching how
	 * RestTicketController builds the service.
	 *
	 * @return TicketService
	 */
	protected function ticket_service(): TicketService {
		if ( class_exists( '\\DoubleScale\\Core\\PluginKernel' ) ) {
			try {
				$container = \DoubleScale\Core\PluginKernel::instance()->get_container();
				$service   = $container->get( TicketService::class );
				if ( $service instanceof TicketService ) {
					return $service;
				}
			} catch ( \Throwable $e ) {
				// Fall through to a direct instance below.
			}
		}
		return new TicketService( new ContactResolver() );
	}

	/**
	 * Resolve the tickets a step should act on for the automation contact,
	 * based on the step's `affects` setting.
	 *
	 * @param array                  $settings           Step settings (expects 'affects').
	 * @param AutomationContactModel $automation_contact Automation contact model.
	 *
	 * @return \Illuminate\Database\Eloquent\Collection<int, TicketModel>
	 */
	protected function get_target_tickets( $settings, AutomationContactModel $automation_contact ) {
		if ( ! $this->support_storage_ready() ) {
			return TicketModel::query()->whereRaw( '1 = 0' )->get();
		}

		$contact_id = $automation_contact->contact->id;
		$affects    = is_array( $settings ) ? ( $settings['affects'] ?? 'most-recent-open-ticket-contact' ) : 'most-recent-open-ticket-contact';

		$query = TicketModel::query()->where( 'contact_id', $contact_id );

		switch ( $affects ) {
			case 'all-tickets-contact':
				return $query->orderBy( 'created_at', 'desc' )->get();

			case 'all-open-tickets-contact':
				return $query->whereIn( 'status', TicketStatus::get_active_statuses() )
					->orderBy( 'created_at', 'desc' )
					->get();

			case 'most-recent-ticket-contact':
				return $query->orderBy( 'created_at', 'desc' )->limit( 1 )->get();

			case 'most-recent-open-ticket-contact':
			default:
				return $query->whereIn( 'status', TicketStatus::get_active_statuses() )
					->orderBy( 'created_at', 'desc' )
					->limit( 1 )
					->get();
		}
	}

	/**
	 * Shared "affects" select options.
	 *
	 * @return array
	 */
	public function get_effects_options() {
		return array(
			'most-recent-open-ticket-contact' => $this->t( 'Most recent open ticket for this contact' ),
			'most-recent-ticket-contact'      => $this->t( 'Most recent ticket for this contact' ),
			'all-open-tickets-contact'        => $this->t( 'All open tickets for this contact' ),
			'all-tickets-contact'             => $this->t( 'All tickets for this contact' ),
		);
	}

	/**
	 * Whether support storage is safe to query (module on and mailboxes table exists).
	 *
	 * @return bool
	 */
	protected function support_storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'support', MailboxModel::class );
	}

	/**
	 * Shared mailbox select options.
	 *
	 * @return array
	 */
	public function get_mailboxes_options() {
		if ( ! $this->support_storage_ready() ) {
			return array();
		}

		$options = array();
		foreach ( MailboxModel::all() as $mailbox ) {
			$options[ $mailbox->id ] = $mailbox->name ?? $mailbox->email;
		}
		return $options;
	}

	/**
	 * Shared agent select options (support staff + administrators).
	 *
	 * @return array
	 */
	public function get_agents_options() {
		$users = get_users(
			array(
				'role__in' => array(
					UserRoles::SUPPORT_MANAGER,
					UserRoles::SUPPORT_AGENT,
					UserRoles::CRM_MANAGER,
					UserRoles::ADMINISTRATOR,
				),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}
		return $options;
	}

	/**
	 * Resolve merge tags within a free-text string (title, note body, etc.).
	 *
	 * @param string                 $text               Raw string, possibly containing {{group:slug}} tags.
	 * @param AutomationContactModel $automation_contact Contact for tag resolution.
	 * @return string
	 */
	protected function parse_text( $text, AutomationContactModel $automation_contact ) {
		if ( empty( $text ) ) {
			return '';
		}
		if ( preg_match( '/{{.*?:.*?}}/', $text ) ) {
			return \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags( $text, $automation_contact );
		}
		return $text;
	}
}
