=== Directorist AI ===
Tags: directorist, ai, directory, listings, chatbot
Requires at least: 7.0
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI listing creation, directory imports, visitor chat and native layout assistance for Directorist.

== Description ==

Directorist AI works with your site's saved directory fields, listings and available capabilities. It provides listing assistance, reviewed imports, visitor answers, public listing readiness and native builder workflows.

Directorist is required. Layout generation also requires the matching Directorist Gutenberg, Elementor or Divi integration and a supported native editor version. See the compatibility matrix supplied with each release for the combinations actually verified.

Managed AI requires a Directorist AI license and available credits. The production store and service identity is supplied with the package. Site owners enter their license key, not service credentials. Existing explicitly configured WordPress provider routes remain available.

== Installation ==

1. Install and activate the compatible Directorist core release.
2. Install the directorist-ai ZIP and activate Directorist AI on the intended site.
3. Connect the site's license in the Directorist AI settings.
4. Install the relevant native builder and Directorist integration if layout assistance is required.

An earlier directorist-ai-pro beta installation can coexist during installation. Activating the new canonical plugin retires the old per-site activation. Saved settings, conversations, usage and license records retain their existing storage identities. No uninstall operation is required. Network activation is unsupported.

== External services ==

Managed requests send the relevant prompt and permitted site context to the configured Directorist AI service. Service requests use the linked credit account. A site administrator may explicitly configure an alternative WordPress AI provider. Static interface translations do not require a visitor model request.

== Changelog ==

= 1.0.1 =
* Layout generation: Apply a combined heading, search button and listing card redesign as one native editor change while preserving the page's directory, search fields and pagination.
* Contextual editing: Change the card surface and listing title colors together. Preserve unrequested controls, saved variants and dynamic bindings.
* Gutenberg: Resolve natural-language requests for a uniquely identified block and report when a block locator must be refreshed. Recover preparation after a native autosave.
* Elementor: Preserve the selected widget when supported scalar settings still use defaults. Use the native editor's verified defaults and bindings to decide which settings can change.
* Visitor chat: Keep broad search introductions concise while rendering the matching cards. Retain the evidence needed for ranking, requested details, comparisons and follow-up questions.
* Visitor chat: Improve pricing and currency accuracy, use the listing's verified contact destination and ground suggested follow-ups in the current conversation.
* Listing generation: Improve term and field guidance, preserve the native form during failures and show relevant recovery actions in the conversation.
* Directory generation and import: Improve proposal validation and preserve the destination directory's saved schema and native settings when applying supported changes.
* Visitor chat: Show the welcome state immediately for new visitors. Refresh initial suggestions and validate saved history after the chat opens.
* Visitor chat: Restore recent verified conversations from a browser snapshot scoped to the site, account, session, directory and language. Retain typed drafts during synchronization and keep history clearing in sync across tabs.
* Visitor chat: Open listings and other destinations in a new tab while keeping the conversation visible. Close the selected-listings menu when starting a comparison.
* Credits: Select a credit pack in Directorist AI and open its checkout in a new tab.
* Installation and updates: Detect the canonical plugin when uploading the ZIP and receive licensed updates through the Directorist store.

= 1.0.0 =
* Adopt the Directorist AI product identity while preserving beta storage compatibility.
* Add managed AI, multilingual visitor chat, reviewed imports, readiness and contextual native layout assistance.
* Preserve saved conversations and native permissions across compatible catalog changes.
