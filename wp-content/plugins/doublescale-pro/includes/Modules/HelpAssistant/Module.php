<?php
/**
 * Help assistant and live chat (Pro).
 *
 * The chat customers open (portal, help center page, floating widget, each
 * off until the owner turns it on; CustomerChannels puts it on those pages),
 * answered by the team (live chat, every plan with a licence, see
 * {@see Services\LiveChatPlan}) and/or the AI assistant.
 *
 * The AI answers from the articles of free's knowledge base: the passage
 * index (Indexer, kept in step with free's article hooks), the search over it
 * (Retriever) and the answer (ChatEngine). That part runs only while the
 * knowledge base is on ({@see self::kb_ready()}); live chat needs only the
 * Helpdesk.
 *
 * The class check matters: on an older free (no knowledge base classes) the
 * module stays off.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Pro\Modules\HelpAssistant\Services\AnswerCache;
use DoubleScale\Pro\Modules\HelpAssistant\Services\CustomerChannels;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Embeddings;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Indexer;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Privacy;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Retention;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatPush;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatWords;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ConversationStore;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveChat;
use DoubleScale\Pro\Modules\HelpAssistant\Services\VisitorCapture;
use DoubleScale\Pro\Modules\HelpAssistant\Services\SitePages;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;

final class Module extends AbstractModule {

	/**
	 * Free's knowledge base module, which the assistant answers from.
	 */
	private const FREE_MODULE = '\\DoubleScale\\Modules\\KnowledgeBase\\Module';

	public function slug(): string {
		return 'help_assistant';
	}

	public function label(): string {
		return __( 'Help Assistant', 'doublescale' );
	}

	public function description(): string {
		return __( 'An AI assistant that answers your customers from your help articles.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Follows the Helpdesk: no toggle of its own (the AI switch and the live
	 * chat switch are in its settings).
	 *
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return false;
	}

	public function dependencies(): array {
		return array( 'core', 'support' );
	}

	/**
	 * Its tables only while it is on. It has no switch of its own (it follows
	 * the Helpdesk), and the migration runner creates the tables of every
	 * module that has no switch: with the Helpdesk off they must not exist.
	 * Schema::ensure() still creates them the first time the module runs.
	 *
	 * @return string[]
	 */
	public function migrations(): array {
		return $this->is_enabled() ? parent::migrations() : array();
	}

	/**
	 * On while the Helpdesk is on (with a free that ships the knowledge base).
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		if ( ! class_exists( self::FREE_MODULE ) ) {
			return false;
		}

		return ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'support' );
	}

	/**
	 * The knowledge base is on and its tables are there: the AI can answer.
	 *
	 * @return bool
	 */
	public static function kb_ready(): bool {
		return class_exists( self::FREE_MODULE )
			&& function_exists( 'doublescale_module_data_ready' )
			&& doublescale_module_data_ready( 'knowledge_base' );
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestHelpChatController::class,
			Rest\Controllers\RestHelpPublicChatController::class,
			Rest\Controllers\RestHelpSettingsController::class,
			Rest\Controllers\RestHelpHandoffController::class,
			Rest\Controllers\RestHelpFeedbackController::class,
			Rest\Controllers\RestHelpInsightsController::class,
			Rest\Controllers\RestHelpLiveInboxController::class,
		);
	}

	public function boot( Container $container ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		parent::boot( $container );

		add_action( 'admin_init', array( Schema::class, 'ensure' ), 5 );
		// The AI's index of the articles: only with the knowledge base on.
		if ( self::kb_ready() ) {
			Indexer::register();
			AnswerCache::register();
			Embeddings::register();
		}
		CustomerChannels::register();
		Privacy::register();
		Retention::register();
		LiveChat::register();
		ChatPush::register();
		VisitorCapture::register();
		SitePages::register();
		ChatWords::register();

		// The inbox's "Live chats" view: tickets with an open live chat.
		add_filter( 'doublescale_support_ticket_view_ids', array( self::class, 'live_chats_view' ), 10, 2 );

		// The admin header's chat icon: only while live chat is on, for the helpdesk team.
		add_filter( 'doublescale_admin_config', array( self::class, 'admin_config' ) );

		// Pro's "About the visitor" panel beside a ticket reads the chat it came from.
		add_filter( 'doublescale_support_ticket_chat', array( self::class, 'ticket_chat' ), 10, 2 );
		add_filter( 'doublescale_support_chat_context', array( self::class, 'chat_context' ), 10, 2 );
	}

	/**
	 * @param int[]|null $ids  Earlier answer.
	 * @param string     $view View name.
	 * @return int[]|null
	 */
	public static function live_chats_view( $ids, $view ) {
		if ( 'live_chats' !== $view ) {
			return $ids;
		}
		global $wpdb;
		$conversations = ConversationStore::conversations();
		$tickets       = $wpdb->prefix . 'doublescale_support_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_col( "SELECT DISTINCT c.ticket_id FROM {$conversations} c INNER JOIN {$tickets} t ON t.id = c.ticket_id WHERE t.status <> 'closed'" );
		return array_map( 'intval', (array) $found );
	}

	/**
	 * @param array<string, mixed> $config Admin app config.
	 * @return array<string, mixed>
	 */
	public static function admin_config( $config ) {
		$config               = is_array( $config ) ? $config : array();
		$config['helpCenter'] = array(
			'liveChat'  => Services\LiveInbox::enabled()
				&& class_exists( '\\DoubleScale\\Core\\UserRoles\\Permissions' )
				&& \DoubleScale\Core\UserRoles\Permissions::has_support_access(),
			// The AI Assistant page: its main switch and what it still needs.
			'assistant' => array(
				'enabled'      => Services\HelpSettings::enabled(),
				'aiConfigured' => Rest\Controllers\RestHelpPublicChatController::ai_ready(),
				// A provider is connected (it may still be refusing: aiConfigured false).
				'aiConnected'  => class_exists( '\\DoubleScale\\Admin\\AdminConfig' ) && \DoubleScale\Admin\AdminConfig::is_ai_configured(),
				// Connected but refused by the provider: its reason (AiHealth).
				// Only for whoever may change the AI settings: the text can
				// name the account; the others see "not set up".
				'aiProblem'    => self::can_change_ai() ? \DoubleScale\Pro\Modules\Ai\AiHealth::problem() : '',
				'limitsReady'  => Services\ChatGuard::available(),
				// Where customers may ask it (portal, widget; the help center
				// follows the floating button and has no switch of its own).
				'places'       => array_values( array_diff( array_keys( array_filter( Services\HelpSettings::get()['channels'] ) ), array( 'help_page' ) ) ),
				// Live chat keeps the chat open (to the team) with the AI off.
				'liveChat'     => Services\LiveChat::enabled(),
				// The AI answers from articles: the help center must be on.
				'helpCenterOn' => self::kb_ready(),
				// What it can answer from (the "publish an article" hint).
				'articles'     => self::answerable_articles(),
			),
			// Live chat comes with every plan; it needs a licence.
			'liveChatPlan' => array(
				'allowed'    => Services\LiveChatPlan::allowed(),
				'upgradeUrl' => defined( 'DOUBLESCALE_PRO_PRICE_URL' ) ? DOUBLESCALE_PRO_PRICE_URL : 'https://doublescale.io/pricing',
			),
		);
		// Help Center settings turn on spending on the site's AI key: site
		// administrators only (the same rule as the settings route).
		$caps = isset( $config['userCapabilities'] ) && is_array( $config['userCapabilities'] ) ? $config['userCapabilities'] : array();
		$caps['doublescale_manage_help_center'] = current_user_can( 'manage_options' );
		// The AI Assistant's Conversations tab: what customers wrote (the same
		// rule as its route; a Help Center Agent does not see it).
		$caps['doublescale_view_help_conversations'] = Rest\Controllers\RestHelpInsightsController::can_view_conversations();
		$config['userCapabilities']             = $caps;
		return $config;
	}

	/**
	 * Whether the current user may change the AI settings (Settings → AI):
	 * administrators and CRM Managers.
	 *
	 * @return bool
	 */
	/**
	 * Published articles the assistant may answer from (public and client
	 * portal; never team-only ones). 0 while the knowledge base is off.
	 *
	 * @return int
	 */
	public static function answerable_articles(): int {
		if ( ! self::kb_ready() || ! class_exists( '\\DoubleScale\\Modules\\KnowledgeBase\\Models\\KbArticleModel' ) ) {
			return 0;
		}
		try {
			return (int) \DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel::query()
				->where( 'status', \DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel::STATUS_PUBLISHED )
				->where( 'visibility', '!=', \DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel::VISIBILITY_INTERNAL )
				->count();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	public static function can_change_ai(): bool {
		return current_user_can( 'manage_options' )
			|| ( class_exists( '\\DoubleScale\\Core\\UserRoles\\Permissions' ) && \DoubleScale\Core\UserRoles\Permissions::has_crm_manager_access() );
	}

	/**
	 * @param array<string, mixed>|null $conversation Earlier answer.
	 * @param int                       $ticket_id    Ticket.
	 * @return array<string, mixed>|null
	 */
	public static function ticket_chat( $conversation, $ticket_id ) {
		if ( is_array( $conversation ) ) {
			return $conversation;
		}
		global $wpdb;
		$table = ConversationStore::conversations();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		}
		return ConversationStore::find_by_ticket( (int) $ticket_id );
	}

	/**
	 * @param array<string, mixed> $context      Earlier answer.
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return array<string, mixed>
	 */
	public static function chat_context( $context, $conversation ) {
		return is_array( $conversation ) ? ConversationStore::context( $conversation ) : (array) $context;
	}
}
