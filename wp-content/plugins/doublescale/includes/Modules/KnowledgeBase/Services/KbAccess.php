<?php
/**
 * Who may read and write help-center articles in the admin.
 *
 * Three tiers, each granted by the Help Center roles' own capabilities and,
 * as before those roles existed, by the helpdesk tiers:
 *   - read (every article, drafts and internal ones included):
 *     `doublescale_view_kb` (Help Center Manager and Agent, CRM Manager and
 *     Agent), or helpdesk access (admins, CRM Managers, Support Managers and
 *     Agents)
 *   - write (create articles; edit, publish and delete the ones they wrote):
 *     `doublescale_write_kb` (Help Center Agent, CRM Agent), and every manager
 *   - manage (every article, categories, order, BetterDocs import, the help
 *     center page address): `doublescale_manage_kb` (Help Center Manager, CRM
 *     Manager), or the helpdesk settings tier (admins, CRM Managers, Support
 *     Managers)
 *
 * The Help Center roles never grant helpdesk access: their holders read and
 * write articles without seeing a ticket.
 *
 * Who wrote an article is `kb_articles.author_id`, set on create and never
 * taken from a request ({@see can_edit()}). Public and portal readers never
 * come through here: they get published articles filtered by visibility.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;

/**
 * KbAccess class.
 */
final class KbAccess {

	/**
	 * Staff who may list and open every article in the admin.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read( $user_id = null ): bool {
		$user_id = (int) Permissions::set_current_user_id( $user_id );

		return user_can( $user_id, 'doublescale_view_kb' )
			|| (bool) Permissions::has_support_access( $user_id );
	}

	/**
	 * Staff who may write articles: create new ones, and edit, publish and
	 * delete the ones they wrote ({@see can_edit()} decides per article).
	 * Every manager writes too.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_write( $user_id = null ): bool {
		$user_id = (int) Permissions::set_current_user_id( $user_id );

		return user_can( $user_id, 'doublescale_write_kb' ) || self::can_manage( $user_id );
	}

	/**
	 * Staff who may change every article and the categories, the order, the
	 * import and the help center page address.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_manage( $user_id = null ): bool {
		$user_id = (int) Permissions::set_current_user_id( $user_id );

		return user_can( $user_id, 'doublescale_manage_kb' )
			|| (bool) Permissions::can_access_support_settings( $user_id );
	}

	/**
	 * Whether the user may change this one article (edit, publish, delete):
	 * managers any article, writers the ones they wrote.
	 *
	 * @param KbArticleModel|null $article Article.
	 * @param int|null            $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_edit( $article, $user_id = null ): bool {
		$user_id = (int) Permissions::set_current_user_id( $user_id );
		if ( $user_id <= 0 || ! $article instanceof KbArticleModel ) {
			return false;
		}

		if ( self::can_manage( $user_id ) ) {
			return true;
		}

		return self::can_write( $user_id ) && (int) $article->author_id === $user_id;
	}
}
