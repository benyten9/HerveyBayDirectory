<?php
/**
 * Mailboxes table - support channels / departments.
 *
 * Each row is a routing destination (e.g. "Sales Support", "Tech Support").
 * `box_type='web'` accepts tickets via portal/forms only; `box_type='email'`
 * is a *superset* ("web + email") that also accepts inbound email (Pro's
 * `InboundTicketRouter` matches the recipient against the mailbox `email`).
 *
 * Single-address model: a mailbox stores ONE sending identity,
 * `data.identity.from_email` (the same shape as the CRM Inbox identity). That
 * address is the From/Reply-To used to send and, for `email` boxes, the
 * address inbound mail is matched against. From Name and Reply-To are not
 * stored: From Name is derived at send time from the SMTP connection that
 * sends from the address (falling back to the site name) and Reply-To is the
 * From address itself.
 *
 * The `email` column mirrors `data.identity.from_email`, derived on save by
 * the `MailboxModel` saving event for EVERY box type, so it is never empty.
 * It is NOT unique: duplicate mailboxes (same address and/or `box_type`) are
 * allowed.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SupportMailboxesTable migration.
 */
class SupportMailboxesTable extends Migration {

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'support_mailboxes';

	/**
	 * `data` stays LONGTEXT rather than JSON for broader MySQL compatibility
	 * through dbDelta — same call-out as Booking's calendar meta. Application
	 * code (MailboxModel data accessor) handles encode/decode.
	 *
	 * NOTE: keep every inline COMMENT comma- and semicolon-free. dbDelta()
	 * tokenizes the column list on commas, so a comma or semicolon inside a
	 * COMMENT corrupts the parse AND still reports "Created table" — the
	 * migration ledger then records success while the table never exists.
	 * (See SupportTicketsTable for the same warning and the original bug.)
	 *
	 * `email` is NOT unique: duplicate mailboxes are allowed. `slug` stays
	 * UNIQUE (the model auto-suffixes collisions so duplicate names still work).
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(191) NOT NULL COMMENT 'URL-friendly identifier',
			email VARCHAR(191) NOT NULL COMMENT 'Inbound match address - mirrors data.identity.from_email',
			box_type VARCHAR(50) NOT NULL DEFAULT 'web' COMMENT 'web is portal only - email is web plus inbound email',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			data LONGTEXT NULL COMMENT 'JSON blob - name avatar email_footer and identity.from_email (the SMTP sending identity)',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY idx_email (email),
			KEY idx_box_type (box_type),
			KEY idx_is_default (is_default)";
	}
}
