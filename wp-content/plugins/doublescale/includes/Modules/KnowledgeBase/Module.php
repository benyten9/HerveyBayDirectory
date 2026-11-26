<?php
/**
 * Help center (knowledge base) module bootstrap.
 *
 * The site owner writes help articles for their own customers, filed in
 * categories. Articles are read on the public help-center page, in the client
 * portal, and by Pro's help assistant, which answers questions from them.
 *
 * Owns: `kb_categories`, `kb_articles`.
 *
 * A child of the Helpdesk (`support`), registered in
 * {@see doublescale_child_module_parent_map()}: its toggle sits inside the
 * Helpdesk card, starts off (unlike the other child modules), and the module
 * only runs while the Helpdesk is on. Admin access: the Help Center Manager
 * and Agent roles, and the helpdesk tiers as before ({@see Services\KbAccess}).
 *
 * Pro must check `class_exists( Module::class )` before relying on this module:
 * on an older free, `doublescale_is_module_active( 'knowledge_base' )` reports
 * an unknown slug as active.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\KnowledgeBase\Abilities\KnowledgeBaseAbilities;
use DoubleScale\Modules\KnowledgeBase\Renderer\ArticleEditorAssets;
use DoubleScale\Modules\KnowledgeBase\Renderer\ArticlePageHandler;
use DoubleScale\Modules\KnowledgeBase\Renderer\HelpCenterHandler;
use DoubleScale\Modules\KnowledgeBase\Renderer\KbSitemapProvider;
use DoubleScale\Modules\KnowledgeBase\Services\ArticlePages;
use DoubleScale\Modules\KnowledgeBase\Services\KbAddresses;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;

final class Module extends AbstractModule implements ProvidesAbilities {

	/**
	 * Parent module slug.
	 */
	public const PARENT_SLUG = 'support';

	public function slug(): string {
		return 'knowledge_base';
	}

	public function label(): string {
		return __( 'Knowledge Base', 'doublescale' );
	}

	public function description(): string {
		return __( 'Write help articles for your customers and publish them on your site and in the client portal.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	/**
	 * Switched on: the Help Center roles exist again (members keep them while
	 * it is off, without their capabilities).
	 *
	 * @return void
	 */
	public function onActivate(): void {
		// Its toggle can be switched on while the Helpdesk is off; the roles
		// follow the module, not the toggle.
		if ( \DoubleScale\Core\UserRoles\UserRoles::is_role_module_enabled( \DoubleScale\Core\UserRoles\UserRoles::HELP_CENTER_MANAGER ) ) {
			\DoubleScale\Core\UserRoles\UserRoles::provision_kb_roles();
		}
	}

	/**
	 * @return void
	 */
	public function onDeactivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::deprovision_kb_roles();
	}

	public function dependencies(): array {
		// Listing `support` also makes ModuleManager::activateModule('support')
		// run this module's migrations when the Helpdesk is switched on.
		return array( 'core', self::PARENT_SLUG );
	}

	/**
	 * On while its own toggle is on (default: off) and the Helpdesk is on.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$stored = get_option( 'doublescale_enabled_modules', array() );
		$stored = is_array( $stored ) ? $stored : array();

		// Off until someone switches it on (Control Modules, or the Get Started
		// "Customer support" goal): updating free never adds a help center.
		$intent = array_key_exists( $this->slug(), $stored ) && (bool) $stored[ $this->slug() ];
		$intent = (bool) apply_filters( 'doublescale_module_enabled_' . $this->slug(), $intent );
		if ( ! $intent ) {
			return false;
		}

		if ( ! function_exists( 'doublescale_is_module_active' ) ) {
			return true;
		}

		return doublescale_is_module_active( self::PARENT_SLUG );
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestKbCategoryController::class,
			Rest\Controllers\RestKbArticleController::class,
			Rest\Controllers\RestKbOrderController::class,
			Rest\Controllers\RestKbPageController::class,
			Rest\Controllers\RestKbImportController::class,
			Rest\Controllers\RestKbPublicController::class,
		);
	}

	/**
	 * Staff-only search / read abilities.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return KnowledgeBaseAbilities::definitions();
	}

	public function boot( Container $container ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		parent::boot( $container );

		// The public help center page: shortcode, its own title / canonical /
		// description, and the page created on its own like the other
		// customer-facing shortcodes.
		new HelpCenterHandler();

		// Each article's own page: written in the block editor, at
		// /help-center/<slug>/.
		ArticlePages::register();
		new ArticlePageHandler();
		// Old addresses (a renamed page, another category word, BetterDocs)
		// lead to the articles they became.
		KbAddresses::register();
		if ( is_admin() ) {
			// The block editor, on the Help Center screens only.
			ArticleEditorAssets::register();
		}
		ShortcodePageProvisioner::register(
			KbPublicArticles::SHORTCODE,
			array(
				'title' => __( 'Knowledge Base', 'doublescale' ),
				// New pages only: a site that already has its page keeps its
				// address (the page is found by its stored id, not its slug).
				'slug'  => 'knowledge-base',
			)
		);

		add_action( 'init', array( self::class, 'register_sitemap' ) );
		add_filter( 'doublescale_portal_sections', array( self::class, 'register_portal_section' ) );
	}

	/**
	 * List public articles in the core sitemap.
	 *
	 * @return void
	 */
	public static function register_sitemap(): void {
		if ( function_exists( 'wp_register_sitemap_provider' ) && class_exists( '\WP_Sitemaps_Provider' ) ) {
			wp_register_sitemap_provider( KbSitemapProvider::NAME, new KbSitemapProvider() );
		}
	}

	/**
	 * The Help Center section of the client portal. Shown or hidden per
	 * client like any other area ({@see \DoubleScale\Modules\Portal\Services\PortalFeatures}).
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_portal_section( array $sections ): array {
		$sections[] = array(
			'slug'         => 'help',
			'label'        => __( 'Knowledge Base', 'doublescale' ),
			'icon'         => 'book',
			'order'        => 80,
			'is_available' => static fn() => doublescale_is_module_active( 'knowledge_base' ),
		);

		return $sections;
	}
}
