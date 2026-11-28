<?php
/**
 * Shared runtime services and the initial read-only diagnostic capability.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Core;

use DirectoristAIPro\Approvals\ApprovedEffectExecutor;
use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\EffectHandlerRegistry;
use DirectoristAIPro\Capabilities\AbilityBridge;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Chat\Appearance;
use DirectoristAIPro\Chat\Operations;
use DirectoristAIPro\Runtime\ActionSchedulerQueue;
use DirectoristAIPro\Runtime\ConversationApi;
use DirectoristAIPro\Runtime\Dispatcher;
use DirectoristAIPro\Runtime\SourceFreeMessageProvenance;
use DirectoristAIPro\Effects\EffectLedger;
use DirectoristAIPro\Orchestration\ContextAssembler;
use DirectoristAIPro\Orchestration\ModelOrchestrator;
use DirectoristAIPro\Orchestration\ToolPolicy;
use DirectoristAIPro\Orchestration\UsageBudget;
use DirectoristAIPro\Orchestration\WordPressModelClient;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Retrieval\GroundedRunResult;
use DirectoristAIPro\Retrieval\GroundedMessageProvenance;
use DirectoristAIPro\Retrieval\GroundedConversationHistoryVerifier;
use DirectoristAIPro\Traits\Singleton;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\SchemaRegistry;

/** UI, workers and integrations receive the same gateway instance. */
final class Runtime {
	use Singleton;

	/**
	 * Shared registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;
	/**
	 * Shared gateway; effects remain unavailable until the ledger is installed.
	 *
	 * @var Gateway
	 */
	private Gateway $gateway;
	/**
	 * Shared durable dispatch coordinator.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;
	/**
	 * Shared review lifecycle.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;

	/** Install guarded runtime services without starting work or calling a provider. */
	protected function __construct() {
		global $wpdb;
		$db        = new Database( $wpdb );
		$readiness = $db->readiness();
		$schemas   = new SchemaRegistry();
		$schemas->register( 'runtime.status.input', '{"type":"object","properties":{},"additionalProperties":false}' );
		$schemas->register( 'runtime.status.output', '{"type":"object","properties":{"contract":{"const":"directorist-ai/v1"},"storage_version":{"type":"integer"},"expected_version":{"type":"integer"},"option_version":{"type":"integer"},"option_drift":{"type":"boolean"},"storage_status":{"type":"string","enum":["ready","future_schema","migration_required","uninstalled","incomplete_schema","invalid_metadata","unavailable"]},"effects_enabled":{"type":"boolean"}},"required":["contract","storage_version","expected_version","option_version","option_drift","storage_status","effects_enabled"],"additionalProperties":false}' );
		$schemas->register( 'conversation.clarify.input', '{"type":"object","properties":{"question":{"type":"string","minLength":1,"maxLength":2000}},"required":["question"],"additionalProperties":false}' );
		$schemas->register( 'conversation.clarify.output', '{"type":"object","properties":{"question":{"type":"string","minLength":1,"maxLength":2000}},"required":["question"],"additionalProperties":false}' );
		$this->registry = new Registry( $schemas );
		$this->registry->register(
			new Operation(
				array(
					'id'            => 'runtime.status',
					'revision'      => '1',
					'description'   => 'Inspect Directorist AI runtime readiness without reading conversations or credentials.',
					'input_schema'  => 'runtime.status.input',
					'output_schema' => 'runtime.status.output',
					'effect'        => 'read',
					'profiles'      => array( 'admin', 'external' ),
					'allow_guest'   => false,
				),
				static function ( $input, Context $context ): bool {
					return $context->actor()->can( 'manage_options' ); },
				static function ( Context $context ): bool {
					return $context->actor()->can( 'manage_options' ); },
				static function (): bool {
					return Plugin::get_instance()->dependencies_available(); },
				static function () use ( $readiness ): object {
					return (object) array_merge( array( 'contract' => 'directorist-ai/v1' ), $readiness->status() );
				}
			)
		);
		$this->registry->register(
			new Operation(
				array(
					'id'            => 'conversation.clarify',
					'revision'      => '1',
					'description'   => 'Request one missing piece of information without performing a domain effect.',
					'input_schema'  => 'conversation.clarify.input',
					'output_schema' => 'conversation.clarify.output',
					'effect'        => 'read',
					'profiles'      => array( 'admin', 'owner', 'visitor', 'builder', 'external' ),
					'allow_guest'   => true,
				),
				static fn(): bool => true,
				static fn(): bool => true,
				static fn(): bool => true,
				static fn( object $input ): object => (object) array( 'question' => $input->question )
			)
		);
		/**
		 * Register trusted operation implementations; never bind model callbacks.
		 *
		 * @param Registry       $registry Operation registry.
		 * @param SchemaRegistry $schemas Strict input/output schemas.
		 */
		do_action( 'directorist_ai_pro_register_operations', $this->registry, $schemas );
		$this->approvals = new ApprovalService( $db );
		$handlers        = new EffectHandlerRegistry();
		/** Register trusted native effect handlers; no transport callbacks. */
		do_action( 'directorist_ai_pro_register_effect_handlers', $handlers );
		$effects       = new ApprovedEffectExecutor( $this->approvals, $handlers, new EffectLedger( $db ) );
		$this->gateway = new Gateway( $this->registry, new ContractValidator( $schemas ), $effects, $readiness );
		do_action( 'directorist_ai_pro_gateway_ready', $this->gateway );
		$this->dispatcher = new Dispatcher( $db, new ActionSchedulerQueue(), new GroundedMessageProvenance( $this->gateway, $db ), new SourceFreeMessageProvenance( $db ) );
		$this->dispatcher->hooks();
		( new \DirectoristAIPro\AI\ManagedReceiptReconciler( $db ) )->hooks();
		( new ModelOrchestrator( $db, $this->gateway, new WordPressModelClient(), new UsageBudget( $db ), new ContextAssembler( $db ), new ToolPolicy() ) )->hooks();
		( new AbilityBridge( $this->registry, $this->gateway ) )->hooks();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Return the shared execution service to trusted domain/runtime consumers.
	 *
	 * @return Gateway
	 */
	public function gateway(): Gateway {
		return $this->gateway;
	}

	/**
	 * Expose only read-only administration/discovery surfaces in this milestone.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		global $wpdb;
		$db      = new Database( $wpdb );
		$results = new GroundedRunResult( $db, $this->gateway );
		$history = new GroundedConversationHistoryVerifier( $db, new GroundedMessageProvenance( $this->gateway, $db ), $results );
		( new ConversationApi( $db, null, $this->dispatcher, $this->approvals, $results, $history ) )->register();
		Appearance::register_routes();
		\DirectoristAIPro\Chat\MediaPreviewApi::register_routes( $db );
		Operations::register_routes( $db );
		\DirectoristAIPro\Chat\ListingActions::register_routes();
		( new \DirectoristAIPro\Chat\VisitorWorkflowApi( $db ) )->register();
		\DirectoristAIPro\AI\Api::register( $db );
		register_rest_route(
			'directorist-ai/v1',
			'/runtime',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' ); },
				'callback'            => function () {
					try {
						return $this->gateway->execute( 'runtime.status', '{}', Context::current( 'admin', 'ui' ) );
					} catch ( ContractException $error ) {
						return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 403 ) );
					}
				},
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/capabilities',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return is_user_logged_in(); },
				'callback'            => function () {
					try {
						return $this->gateway->describe( Context::current( 'external', 'ui' ) );
					} catch ( ContractException $error ) {
						return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 403 ) );
					}
				},
			)
		);
	}
}
