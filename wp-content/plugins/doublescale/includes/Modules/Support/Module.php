<?php
/**
 * Support module bootstrap.
 *
 * Owns: ticket workflow state (`support_tickets`), email channels
 * (`support_mailboxes`), and private attachments (`support_attachments`).
 * Conversations are folded into `doublescale_activities` and linked through
 * `activity_associations` with `entity_type=3`; tags are reused from
 * `doublescale_tags` via a JSON `tag_ids` column on the ticket; saved
 * replies, products, custom-field definitions, and notification toggles
 * live in `wp_options` under `doublescale_settings['support']`.
 *
 * Free baseline only. Pro extensions (AI service, advanced reports,
 * round-robin, CSAT, Slack/Teams notifications) attach via the standard
 * `doublescale_register_modules` filter and the slug-to-class map.
 *
 * Design references:
 *   - {@see Modules/Booking/Module.php} — module shape for a non-trivial domain
 *   - {@see docs/support-module-integration-plan.md} — full integration plan
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Container;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Abilities\SupportAbilities;
use DoubleScale\Modules\Support\Renderer\PortalFrontendHandler;
use DoubleScale\Modules\Support\Services\ActivityLogger;
use DoubleScale\Modules\Support\Services\AttachmentService;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\EmailNotifications;
use DoubleScale\Modules\Support\Services\TicketService;
use DoubleScale\Modules\Support\Services\AttachmentSettings;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Contacts\Models\ContactModel;

final class Module extends AbstractModule implements ProvidesAbilities {

	public function slug(): string {
		return 'support';
	}

	/**
	 * Read-only support abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return SupportAbilities::definitions();
	}

	public function label(): string {
		return __( 'Helpdesk', 'doublescale' );
	}

	public function description(): string {
		return __( 'Ticket-based customer helpdesk with mailbox channels, email piping, and a customer portal.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function onActivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::provision_support_roles();
	}

	public function onDeactivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::deprovision_support_roles();
	}

	public function dependencies(): array {
		// `activities` carries ticket replies/notes; `contacts` holds the
		// customer identity; `emails` provides the outbound rendering /
		// `Emails::send()` helper that bypasses QS's old SMTP bridge.
		return array( 'core', 'contacts', 'emails', 'activities' );
	}

	/**
	 * Action Scheduler hook pairs that this module owns, so toggling the
	 * module off via the Settings → Modules option correctly unschedules
	 * IMAP polling (Phase 3 wires the scheduler; the contract is declared
	 * up front so {@see \DoubleScale\Core\ModuleManager::clearScheduledTasksForModule()}
	 * Just Works the moment we add the polling task).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function scheduledHooks(): array {
		return array(
			array( 'doublescale_support', 'doublescale_support_email_inbound' ),
			array( 'doublescale_support', 'doublescale_support_attachment_cleanup' ),
			// Owned by Pro's auto-close runner ({@see \DoubleScale\Pro\Modules\Support\Services\AutoCloseRunner}),
			// declared here so disabling Support unschedules it via clearScheduledTasksForModule().
			array( 'doublescale_support', 'doublescale_support_auto_close' ),
		);
	}

	public function register( Container $container ): void {
		$container->singleton(
			ContactResolver::class,
			static fn() => new ContactResolver()
		);

		// TicketService is the single orchestration point for every ticket
		// state change (REST, IMAP, WP-CLI). Binding as a singleton means all
		// callers see the same instance and listeners registered against it
		// (e.g. ActivityLogger) only fire once per change. The factory closure
		// receives the underlying IlluminateContainer; use ->make() to resolve
		// previously-bound dependencies.
		$container->singleton(
			TicketService::class,
			static fn( $app ) => new TicketService( $app->make( ContactResolver::class ) )
		);

		// ActivityLogger needs TicketService to write SUPPORT_EVENT rows when
		// ticket-lifecycle hooks fire. The listener registration happens in
		// boot() (not here) — register() is for binding, boot() for hooks.
		$container->singleton(
			ActivityLogger::class,
			static fn( $app ) => new ActivityLogger( $app->make( TicketService::class ) )
		);

		// Public-facing portal renderer. Resolving this binding in boot()
		// is what wires `add_shortcode()` + `wp_enqueue_scripts`, so it
		// MUST stay a singleton — second-resolves would double-register
		// the shortcode handler.
		$container->singleton(
			PortalFrontendHandler::class,
			static fn() => new PortalFrontendHandler()
		);

		// Outbound email notifications. Resolving in boot() wires the
		// ticket-lifecycle hook listeners, so it MUST stay a singleton —
		// a second resolve would double-subscribe and send duplicate mail.
		$container->singleton(
			EmailNotifications::class,
			static fn() => new EmailNotifications()
		);

		$container->singleton(
			AttachmentService::class,
			static fn() => new AttachmentService()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestTicketController::class,
			Rest\Controllers\RestReplyController::class,
			Rest\Controllers\RestMailboxController::class,
			Rest\Controllers\RestPortalController::class,
			Rest\Controllers\RestAttachmentController::class,
			Rest\Controllers\RestGuestController::class,
			Rest\Controllers\RestReportController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		// Resolve the resolver + service eagerly so any other module that
		// listens for support-side hooks can use them from `doublescale_loaded`.
		$container->get( ContactResolver::class );
		$container->get( TicketService::class );

		// Register the ticket-lifecycle listeners. Done here (not in register())
		// so the WP hook system is guaranteed to be available — register()
		// runs before WP is fully initialised in some bootstrap orderings.
		$container->get( ActivityLogger::class )->register();

		// Resolve the portal frontend handler. Its constructor wires the
		// `[doublescale_support_portal]` shortcode and the `wp_enqueue_scripts`
		// listener that conditionally ships the renderer bundle to pages
		// containing the shortcode (and only for logged-in visitors).
		$container->get( PortalFrontendHandler::class );

		// Resolve the outbound email notifier so its constructor subscribes to
		// the ticket-lifecycle hooks (reply / created / status-change → customer).
		$container->get( EmailNotifications::class );

		add_action( 'init', array( $this, 'register_attachment_cleanup_schedule' ) );

		// Client Portal integration: contribute the Tickets section, inject the
		// support REST bases + attachment limits the reused ticket views need,
		// and add an "open tickets" dashboard summary card. Registered here (only
		// when the module is enabled) so a disabled Support module drops the
		// Tickets tab from the unified portal automatically.
		add_filter( 'doublescale_portal_sections', array( $this, 'register_portal_tickets_section' ) );
		add_filter( 'doublescale_client_portal_config', array( $this, 'inject_portal_config' ), 10, 2 );
		add_filter( 'doublescale_portal_summary_cards', array( $this, 'add_portal_summary_card' ), 10, 2 );

		// Opt `support_reply` into the portal timeline whitelist. Registered here
		// (enabled-only) so a disabled Support module drops its conversation rows
		// from the dashboard timeline too — the whitelist itself is deny-by-default.
		add_filter( 'doublescale_portal_timeline_activity_types', array( $this, 'allow_portal_timeline_types' ) );

		// Sidebar entry inside the DoubleScale top-level menu. Position 46
		// places Support immediately after Booking (45) so agent-facing tools
		// cluster visually. `group: 'sales'` matches the existing agent-tool
		// bucket (Pipelines / Booking) — Support is also an agent workflow,
		// not a setting. `requires_module: 'support'` makes the row disappear
		// the moment the module is toggled off via Settings → Modules.
		MenuRegistry::add(
			array(
				'page_title'      => __( 'Helpdesk', 'doublescale' ),
				'menu_title'      => __( 'Helpdesk', 'doublescale' ),
				'capability'      => 'doublescale_view_support',
				'slug'            => 'doublescale&path=support',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 46,
				'group'           => 'sales',
				'requires_module' => 'support',
			)
		);

		// For users whose only DoubleScale role is Support Agent / Support
		// Manager, strip every non-Support submenu from the DoubleScale
		// top-level menu. Priority 9999 ensures we run after every other
		// module has finished registering its own submenu entries.
		add_action( 'admin_menu', array( self::class, 'scope_menu_for_support_only_users' ), 9999 );
	}

	/**
	 * Remove every DoubleScale submenu except Support for users whose only
	 * DoubleScale roles are the support ones. Administrators and CRM roles
	 * are untouched.
	 *
	 * @return void
	 */
	public static function scope_menu_for_support_only_users(): void {
		if ( ! Permissions::is_support_only() ) {
			return;
		}

		$menu_slug = apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming
		global $submenu;
		if ( empty( $submenu[ $menu_slug ] ) || ! is_array( $submenu[ $menu_slug ] ) ) {
			return;
		}

		foreach ( $submenu[ $menu_slug ] as $key => $item ) {
			// Each submenu item is [ title, capability, slug, page_title? ].
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( false === strpos( $slug, 'path=support' ) ) {
				unset( $submenu[ $menu_slug ][ $key ] );
			}
		}

		// Re-index so WP's menu renderer doesn't choke on gaps.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming (re-indexing the same global filtered above).
		$submenu[ $menu_slug ] = array_values( $submenu[ $menu_slug ] );
	}

	/**
	 * Register and schedule the daily temp-attachment cleanup task.
	 *
	 * Runs on `init` (Action Scheduler's data store is ready by then). The callback
	 * is attached on every request — `add_action()` only lives for the current
	 * request, and the queue runner needs to find it on whichever request runs the
	 * queue. Only the scheduling half is behind a short transient lock, which keeps
	 * concurrent `init` fires from racing on the DB write; the recurring schedule is
	 * created only when none is pending, so repeated boots are idempotent.
	 *
	 * @return void
	 */
	public function register_attachment_cleanup_schedule(): void {
		$tasks = new \DoubleScale\Core\Tasks( 'doublescale_support' );
		$tasks->register_callback(
			'doublescale_support_attachment_cleanup',
			static function () {
				( new AttachmentService() )->cleanup_stale_temp();
			}
		);

		if ( get_transient( 'doublescale_register_tasks_lock_support_attachments' ) ) {
			return;
		}
		set_transient( 'doublescale_register_tasks_lock_support_attachments', 1, MINUTE_IN_SECONDS );

		if ( false === $tasks->get_next_timestamp( 'doublescale_support_attachment_cleanup' ) ) {
			$tasks->schedule_recurring( time(), DAY_IN_SECONDS, 'doublescale_support_attachment_cleanup' );
		}
	}

	/**
	 * Contribute the Tickets section to the Client Portal.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_portal_tickets_section( array $sections ): array {
		$sections[] = array(
			'slug'         => 'tickets',
			'label'        => __( 'Support', 'doublescale' ),
			'icon'         => 'ticket',
			'order'        => 10,
			'is_available' => static fn() => doublescale_is_module_active( 'support' ),
			'badge'        => static fn( $contact ) => self::count_open_tickets( $contact ),
		);

		return $sections;
	}

	/**
	 * Inject the support REST bases + uploader settings the reused portal ticket
	 * views require into the Client Portal renderer config.
	 *
	 * @param array<string, mixed> $config Renderer config.
	 * @param \WP_User             $user   Current user (unused).
	 * @return array<string, mixed>
	 */
	public function inject_portal_config( array $config, $user ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$config['rest_url']              = esc_url_raw( rest_url( 'doublescale/v1/support/portal' ) );
		$config['public_rest_url']       = esc_url_raw( rest_url( 'doublescale/v1/support/public' ) );
		$config['custom_fields_enabled'] = class_exists( '\\DoubleScale\\Pro\\Modules\\Support\\Services\\CustomFieldsService' );
		$config['attachment_limits']     = AttachmentSettings::to_payload();

		return $config;
	}

	/**
	 * Add the "open tickets" dashboard summary card.
	 *
	 * @param array<int, array<string, mixed>> $cards   Summary cards.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_portal_summary_card( array $cards, $contact ): array {
		$cards[] = array(
			'key'   => 'open_tickets',
			'label' => __( 'Open tickets', 'doublescale' ),
			'value' => self::count_open_tickets( $contact ),
			'route' => 'tickets',
		);

		return $cards;
	}

	/**
	 * Opt the `support_reply` activity type into the portal timeline whitelist.
	 *
	 * The whitelist is deny-by-default and lives in the Portal module; Support
	 * is the owner of the only customer-safe activity row written to
	 * `doublescale_activities`, so it contributes the type here. Because this
	 * runs from {@see boot()} (enabled-only), disabling Support removes its
	 * timeline rows in lock-step with the Tickets section + summary card.
	 *
	 * @param array<int, string> $types Allowed activity_type slugs.
	 * @return array<int, string>
	 */
	public function allow_portal_timeline_types( array $types ): array {
		$types[] = ActivityTypes::SUPPORT_REPLY;

		return $types;
	}

	/**
	 * Count a contact's not-yet-closed tickets (open + pending).
	 *
	 * @param ContactModel|null $contact Resolved contact.
	 * @return int
	 */
	private static function count_open_tickets( $contact ): int {
		if ( ! $contact instanceof ContactModel ) {
			return 0;
		}

		if (
			function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'support', TicketModel::class )
		) {
			return 0;
		}

		try {
			return (int) TicketModel::where( 'contact_id', $contact->id )
				->whereIn( 'status', array( TicketStatus::OPEN, TicketStatus::PENDING ) )
				->count();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
