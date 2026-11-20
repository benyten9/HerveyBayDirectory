<?php
/**
 * Which article visibilities the current reader may see outside the admin.
 *
 *   - a signed-in client-portal contact → public + portal
 *   - helpdesk staff                    → public + portal (to preview what
 *                                         clients see; never internal)
 *   - anyone else                       → public
 *
 * Internal articles never leave the admin (and, later, a staff-only assistant):
 * no public route, page, portal view or sitemap returns them, whoever asks.
 * Drafts never leave the admin either; {@see KbPublicArticles} adds that.
 *
 * Decided on the server for every request — never taken from the client.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalIdentity;

/**
 * KbAudience class.
 */
final class KbAudience {

	/**
	 * Visibilities the current request may read.
	 *
	 * @return string[]
	 */
	public static function current(): array {
		if ( self::is_portal_reader() ) {
			return array( KbArticleModel::VISIBILITY_PUBLIC, KbArticleModel::VISIBILITY_PORTAL );
		}

		return array( KbArticleModel::VISIBILITY_PUBLIC );
	}

	/**
	 * A confirmed client-portal contact whose Help Center is on, or helpdesk
	 * staff previewing.
	 *
	 * @return bool
	 */
	private static function is_portal_reader(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( KbAccess::can_read() ) {
			return true;
		}

		$contact = class_exists( PortalIdentity::class ) ? PortalIdentity::current_contact() : null;
		if ( null === $contact ) {
			return false;
		}

		// The owner switched the Help Center off for this client (or for every
		// client): hiding the section is not enough, the articles go too.
		return ! class_exists( PortalAccess::class ) || PortalAccess::allows( 'help_center', $contact );
	}
}
