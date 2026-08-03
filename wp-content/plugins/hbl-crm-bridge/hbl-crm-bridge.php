<?php
/**
 * Plugin Name:  HBL – Directorist CRM Bridge
 * Description:  Bridges Directorist listing events to DoubleScale via outgoing webhooks, and exposes a REST API endpoint so CRMs canquery or update listing data in return.
 *
 *               Typical flow:
 *                 CRM sends email → user clicks → arrives on site → claims listing
 *                 → this plugin fires outgoing webhook to CRM → CRM applies tag /
 *                 moves contact to new workflow.
 *
 * Version:      1.0.38
 * Requires PHP: 7.4
 * Author:       HBL
 * License:      GPL-2.0+
 * Text Domain:  hbl-crm-bridge
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * OUTGOING WEBHOOKS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Configure one or more CRM webhook URLs in Settings → CRM Bridge.
 * Each URL receives a non-blocking POST with a JSON payload whenever one of
 * these Directorist events fires:
 *
 *   listing_claimed    – atbdp_claim_listing
 *   listing_published  – atbdp_listing_published  (new listing goes live)
 *   listing_inserted   – atbdp_listing_inserted   (draft/pending created)
 *   listing_updated    – atbdp_listing_updated
 *   listing_expired    – atbdp_listing_expired
 *
 * Payload structure (JSON):
 *   {
 *     "event":            "listing_claimed",
 *     "listing_id":       123,
 *     "listing_title":    "Acme Plumbing",
 *     "listing_url":      "https://example.com/listing/acme-plumbing/",
 *     "listing_status":   "publish",
 *     "owner_id":         45,
 *     "owner_email":      "owner@example.com",
 *     "owner_name":       "John Doe",
 *     "owner_first_name": "John",
 *     "owner_last_name":  "Doe",
 *     "timestamp":        "2026-05-12T14:00:00+00:00",
 *     "site_url":         "https://example.com"
 *   }
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * INCOMING REST ENDPOINT
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Endpoint:  POST  /wp-json/hbl-crm/v1/action
 * Auth:      Pass the Incoming Secret Key as the X-HBL-Secret request header
 *            (or in a JSON body field "secret").
 *
 * Supported actions (pass as JSON body field "action"):
 *
 *   ping
 *     → Returns 200 with {"ok":true}. Use to test connectivity.
 *
 *   get_listing
 *     Required: listing_id  OR  owner_email
 *     → Returns listing data (id, title, url, status, plan, owner).
 *
 *   set_listing_status
 *     Required: listing_id, status  (publish | pending | expired | draft)
 *     → Updates post_status; returns new status.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * DOUBLESCALE CRM SYNC (LOCAL)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * If the DoubleScale CRM plugin is active on this site, the same Directorist
 * events listed above can also sync directly into DoubleScale — no webhook
 * round-trip required:
 *
 *   - The listing owner is created/updated as a DoubleScale Contact
 *     (email, first name, last name, source = "directorist").
 *   - A per-event tag (configured in Settings → CRM Bridge) is applied to
 *     the contact, e.g. "Listing Published".
 *   - The contact can optionally be added to a configured DoubleScale List.
 *
 * Applying a tag fires DoubleScale's own `doublescale_contact_tag_apply`
 * hook, so any DoubleScale automation listening for that tag will run.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * MERGE TAGS PRODUCED
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * These custom fields are written on the listing owner's DoubleScale contact
 * and are therefore usable as email merge tags. Field definitions this plugin
 * owns (currently just expiration-date) are created in DoubleScale
 * automatically on the first admin page load; the rest are written on the
 * assumption that a matching field already exists (created by hand in
 * DoubleScale → Custom Fields) — writes no-op harmlessly until it does.
 *
 *   {{contact:contact_field:expiration-date}}     27 July 2027 / Never / ''
 *     When the listing's current plan expires (e.g. a Silver membership).
 *     Refreshed on every synced listing event and on plan-upgrade completion.
 *   {{contact:contact_field:business-name}}       Acme Plumbing
 *   {{contact:contact_field:listing-url}}         https://example.com/listing/acme-plumbing/
 *   {{contact:contact_field:edited-since-claim}}  Yes — 2026-08-03
 *   {{contact:contact_field:plan-upgrade-status}} Completed: Gold — 2026-08-03
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * DEVELOPER HOOKS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *   Filter: hbl_crm_bridge_payload   ($payload, $event, $listing_id)
 *     Modify the outgoing webhook payload before it is sent.
 *
 *   Filter: hbl_crm_bridge_should_send  ($bool, $event, $listing_id)
 *     Return false to suppress the webhook for a specific event/listing.
 *
 *   Action: hbl_crm_bridge_webhook_sent  ($event, $listing_id, $url, $response_code)
 *     Fires after each outgoing webhook attempt.
 *
 *   Filter: hbl_crm_bridge_doublescale_contact_data  ($contact_data, $event, $listing_id)
 *     Modify the contact fields passed to DoubleScale before the contact is
 *     created/updated.
 *
 *   Action: hbl_crm_bridge_doublescale_synced  ($event, $listing_id, $contact)
 *     Fires after a listing event has been synced to a DoubleScale contact.
 *
 * @package HBL_CRMBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────────────────

define( 'HBL_CRM_BRIDGE_VERSION',     '1.0.38' );
define( 'HBL_CRM_BRIDGE_OPTION_KEY',  'hbl_crm_bridge_settings' );
define( 'HBL_CRM_BRIDGE_REST_NS',     'hbl-crm/v1' );
define( 'HBL_CRM_BRIDGE_REST_ROUTE',  '/action' );
define( 'HBL_CRM_BRIDGE_URL',         plugin_dir_url( __FILE__ ) );

// ── Bootstrap ──────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'hbl_crm_bridge_on_activate' );
// Priority 20: after Directorist (default 10) and DoubleScale (priority 5) have both
// fully initialized — DoubleScale fires doublescale_ready at priority 5, so CRM Bridge
// must not run until after that sequence completes.
add_action( 'plugins_loaded', 'hbl_crm_bridge_boot', 20 );

/**
 * Wires up all hooks once all plugins are loaded.
 */
function hbl_crm_bridge_boot(): void {

	// ── Outgoing: Directorist listing events ──────────────────────────────────

	// Fires after dcl_new_claim() persists the claim record — passes $claim_id,
	// not $listing_id. The callback resolves both IDs from post meta.
	add_action( 'atbdp_new_claim_inserted', 'hbl_crm_bridge_on_claimed', 10, 1 );

	// Standard listing lifecycle.
	add_action( 'atbdp_listing_published', 'hbl_crm_bridge_on_published', 10, 1 );
	add_action( 'atbdp_listing_inserted',  'hbl_crm_bridge_on_inserted',  10, 1 );
	add_action( 'atbdp_listing_updated',   'hbl_crm_bridge_on_updated',   10, 1 );
	add_action( 'atbdp_listing_expired',   'hbl_crm_bridge_on_expired',   10, 1 );

	// ── Contact-field signals for email sequences ─────────────────────────────
	// Owner edited a listing they had claimed.
	add_action( 'hbl_owner_edited_listing', 'hbl_crm_bridge_on_owner_edited', 10, 2 );
	// Plan-upgrade checkout started (pending order created) …
	add_action( 'hbl_order_started', 'hbl_crm_bridge_on_order_started', 10, 3 );
	// … and completed (Stripe confirmed).
	add_action( 'hbl_listing_payment_verified', 'hbl_crm_bridge_on_payment_verified', 10, 4 );

	// ── Incoming: REST endpoint ───────────────────────────────────────────────

	add_action( 'rest_api_init', 'hbl_crm_bridge_register_rest_routes' );

	// ── Admin settings page ───────────────────────────────────────────────────

	add_action( 'admin_menu',           'hbl_crm_bridge_add_settings_page' );
	add_action( 'admin_init',           'hbl_crm_bridge_register_settings' );
	add_action( 'admin_enqueue_scripts', 'hbl_crm_bridge_enqueue_admin_assets' );

	// Create this plugin's DoubleScale custom field definitions (e.g.
	// "expiration-date") once Pro's models are available. Deferred to
	// admin_init, same as Listing Views, because DoubleScale Pro is not
	// guaranteed to be loaded at plugins_loaded time, and because creating
	// fields must never happen on a front-end request.
	add_action( 'admin_init', 'hbl_crm_bridge_maybe_ensure_custom_fields' );

	// ── Bulk sync AJAX ────────────────────────────────────────────────────────

	add_action( 'wp_ajax_hbl_crm_bridge_list_listings', 'hbl_crm_bridge_ajax_list_listings' );
	add_action( 'wp_ajax_hbl_crm_bridge_bulk_sync', 'hbl_crm_bridge_ajax_bulk_sync' );
	add_action( 'admin_post_hbl_crm_bridge_export_noemail', 'hbl_crm_bridge_export_noemail_csv' );
}

// ══════════════════════════════════════════════════════════════════════════════
// OUTGOING WEBHOOK CALLBACKS
// ══════════════════════════════════════════════════════════════════════════════

function hbl_crm_bridge_on_claimed( int $claim_id ): void {
	// The hook passes the claim record ID, not the listing ID.
	$listing_id = (int) get_post_meta( $claim_id, '_claimed_listing', true );
	$claimer_id = (int) get_post_meta( $claim_id, '_listing_claimer', true );

	if ( ! $listing_id ) {
		return;
	}

	hbl_crm_bridge_dispatch( 'listing_claimed', $listing_id );
	hbl_crm_bridge_doublescale_sync( 'listing_claimed', $listing_id, $claimer_id );
}

function hbl_crm_bridge_on_published( int $listing_id ): void {
	hbl_crm_bridge_dispatch( 'listing_published', $listing_id );
	hbl_crm_bridge_doublescale_sync( 'listing_published', $listing_id );
}

function hbl_crm_bridge_on_inserted( int $listing_id ): void {
	hbl_crm_bridge_dispatch( 'listing_inserted', $listing_id );
	hbl_crm_bridge_doublescale_sync( 'listing_inserted', $listing_id );
}

function hbl_crm_bridge_on_updated( int $listing_id ): void {
	hbl_crm_bridge_dispatch( 'listing_updated', $listing_id );
	hbl_crm_bridge_doublescale_sync( 'listing_updated', $listing_id );
}

function hbl_crm_bridge_on_expired( int $listing_id ): void {
	hbl_crm_bridge_dispatch( 'listing_expired', $listing_id );
	hbl_crm_bridge_doublescale_sync( 'listing_expired', $listing_id );
}

// ══════════════════════════════════════════════════════════════════════════════
// CONTACT-FIELD SIGNALS  (edited-since-claim / plan-upgrade-status)
//
// These write DoubleScale contact custom fields an email sequence can test
// before sending. They no-op cleanly until the matching custom field is
// created in DoubleScale, so the field slugs below can be created any time.
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Owner edited a listing they had already claimed.
 * Sets the "edited-since-claim" custom field to a dated "Yes" marker.
 */
function hbl_crm_bridge_on_owner_edited( int $listing_id, int $user_id = 0 ): void {
	$contact = hbl_crm_bridge_get_contact_for_listing( $listing_id, $user_id );
	if ( ! $contact ) {
		return;
	}
	hbl_crm_bridge_set_custom_field( $contact, 'edited-since-claim', 'Yes — ' . gmdate( 'Y-m-d' ) );
	hbl_crm_bridge_log_activity(
		$contact,
		'Listing edited after claim',
		sprintf( 'Owner edited their claimed listing "%s".', html_entity_decode( get_the_title( $listing_id ), ENT_QUOTES ) ),
		$user_id ?: (int) get_post_field( 'post_author', $listing_id )
	);
}

/**
 * A paid plan-upgrade checkout has started (pending order created). Records the
 * plan name and "Started" status so a sequence can target "started but not
 * completed". Completion later overwrites this with "Completed" (see below).
 */
function hbl_crm_bridge_on_order_started( int $order_id, int $listing_id, int $plan_id ): void {
	// Resolve via the listing owner (a claimed listing is authored by the
	// claimer) so we hit the same contact the claim sync created.
	$owner_id = (int) get_post_field( 'post_author', $listing_id );
	$contact  = hbl_crm_bridge_get_contact_for_listing( $listing_id, $owner_id );
	if ( ! $contact ) {
		return;
	}
	$plan = $plan_id ? get_the_title( $plan_id ) : '';
	$plan = $plan ? html_entity_decode( $plan, ENT_QUOTES ) : 'Plan';
	hbl_crm_bridge_set_custom_field( $contact, 'plan-upgrade-status', 'Started: ' . $plan . ' — ' . gmdate( 'Y-m-d' ) );
	hbl_crm_bridge_log_activity(
		$contact,
		'Plan upgrade started',
		sprintf( 'Started checkout for the "%s" plan (payment not yet completed).', $plan ),
		$owner_id
	);
}

/**
 * Plan-upgrade payment confirmed. Flips the status to "Completed".
 * Signature matches theme's hbl_listing_payment_verified action.
 */
function hbl_crm_bridge_on_payment_verified( int $listing_id, $plan_id = 0, $plan_tier = '', $new_status = '' ): void {
	$owner_id = (int) get_post_field( 'post_author', $listing_id );
	$contact  = hbl_crm_bridge_get_contact_for_listing( $listing_id, $owner_id );
	if ( ! $contact ) {
		return;
	}
	$plan = $plan_id ? get_the_title( (int) $plan_id ) : '';
	$plan = $plan ? html_entity_decode( $plan, ENT_QUOTES ) : ( $plan_tier ?: 'Plan' );
	hbl_crm_bridge_set_custom_field( $contact, 'plan-upgrade-status', 'Completed: ' . $plan . ' — ' . gmdate( 'Y-m-d' ) );
	// The plan is applied at this point, so the listing's expiry is now set —
	// refresh the expiration-date field (e.g. when the Silver membership ends).
	hbl_crm_bridge_set_custom_field( $contact, 'expiration-date', hbl_crm_bridge_get_listing_expiry( $listing_id ) );
	hbl_crm_bridge_log_activity(
		$contact,
		'Plan upgrade completed',
		sprintf( 'Completed payment for the "%s" plan.', $plan ),
		$owner_id
	);
}

// ══════════════════════════════════════════════════════════════════════════════
// OUTGOING WEBHOOK DISPATCHER
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Builds the payload and fires a non-blocking POST to every configured CRM URL.
 *
 * @param string $event      One of the event slugs (e.g. "listing_claimed").
 * @param int    $listing_id Directorist listing post ID.
 */
function hbl_crm_bridge_dispatch( string $event, int $listing_id ): void {

	// Same REST guard as hbl_crm_bridge_doublescale_sync — webhook dispatches
	// must not fire during REST API requests.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	$settings  = hbl_crm_bridge_get_settings();
	$urls      = hbl_crm_bridge_parse_webhook_urls( $settings['webhook_urls'] ?? '' );
	$enabled   = $settings['enabled_events'] ?? [];

	// Skip if no URLs configured.
	if ( empty( $urls ) ) {
		return;
	}

	// Skip if this event type is disabled in settings.
	if ( ! empty( $enabled ) && ! in_array( $event, $enabled, true ) ) {
		return;
	}

	// Allow external code to suppress a specific send.
	$should_send = (bool) apply_filters( 'hbl_crm_bridge_should_send', true, $event, $listing_id );
	if ( ! $should_send ) {
		return;
	}

	$payload = hbl_crm_bridge_build_payload( $event, $listing_id );

	/** @param array  $payload    Outgoing JSON data. */
	$payload = (array) apply_filters( 'hbl_crm_bridge_payload', $payload, $event, $listing_id );

	$body = wp_json_encode( $payload );

	foreach ( $urls as $url ) {
		$response = wp_remote_post( $url, [
			'headers'   => [
				'Content-Type' => 'application/json',
				'X-HBL-Event' => $event,
			],
			'body'      => $body,
			'timeout'   => 15,
			'blocking'  => false, // Fire-and-forget; avoids slowing page load.
			'sslverify' => true,
		] );

		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		do_action( 'hbl_crm_bridge_webhook_sent', $event, $listing_id, $url, $code );
	}
}

/**
 * Assembles the standard payload array for a listing event.
 *
 * @param  string $event
 * @param  int    $listing_id
 * @return array
 */
function hbl_crm_bridge_build_payload( string $event, int $listing_id ): array {

	$post   = get_post( $listing_id );
	$owner  = $post ? get_userdata( (int) $post->post_author ) : false;

	return [
		'event'            => $event,
		'listing_id'       => $listing_id,
		'listing_title'    => $post ? get_the_title( $post ) : '',
		'listing_url'      => get_permalink( $listing_id ) ?: '',
		'listing_status'   => $post ? $post->post_status : '',
		'owner_id'         => $owner ? (int) $owner->ID : 0,
		'owner_email'      => $owner ? $owner->user_email : '',
		'owner_name'       => $owner ? $owner->display_name : '',
		'owner_first_name' => $owner ? $owner->first_name : '',
		'owner_last_name'  => $owner ? $owner->last_name : '',
		'timestamp'        => ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( DateTimeInterface::ATOM ),
		'site_url'         => get_site_url(),
	];
}

/**
 * Splits the multi-line webhook URL textarea into a clean array of URLs.
 *
 * @param  string $raw
 * @return string[]
 */
function hbl_crm_bridge_parse_webhook_urls( string $raw ): array {
	$lines = preg_split( '/\r?\n/', $raw );
	$urls  = [];
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line && filter_var( $line, FILTER_VALIDATE_URL ) ) {
			$urls[] = $line;
		}
	}
	return $urls;
}

// ══════════════════════════════════════════════════════════════════════════════
// DOUBLESCALE CRM SYNC (LOCAL)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Whether the DoubleScale CRM plugin is active and its Contact model is available.
 *
 * Uses DOUBLESCALE_VERSION (defined at plugin file load, before plugins_loaded) as
 * the primary gate so we never trigger DoubleScale's autoloader before the plugin
 * has completed its own boot sequence.
 *
 * @return bool
 */
function hbl_crm_bridge_doublescale_available(): bool {
	if ( ! defined( 'DOUBLESCALE_VERSION' ) ) {
		return false;
	}
	// class_exists with autoload=true is safe here: by the time any Directorist
	// listing hook fires (the only callers of this function), DoubleScale has
	// finished booting and its Composer autoloader is fully registered.
	return class_exists( \DoubleScale\Modules\Contacts\Models\ContactModel::class );
}

/**
 * Writes a timestamped line to the PHP error log, prefixed with '[HBL CRM Bridge]'.
 * Only called when 'doublescale_debug_log' is enabled in settings.
 *
 * @param string $msg
 */
function hbl_crm_bridge_ds_log( string $msg ): void {
	error_log( '[HBL CRM Bridge] ' . $msg );
}

/**
 * Syncs a Directorist listing event straight into the local DoubleScale CRM:
 * creates/updates the listing owner as a contact, applies the tag configured
 * for this event, and (optionally) adds the contact to a configured list.
 *
 * @param string $event      One of the event slugs (e.g. "listing_claimed").
 * @param int    $listing_id Directorist listing post ID.
 * @param int    $user_id    Optional. Override the contact user (e.g. the claimer). Defaults to post_author.
 */
function hbl_crm_bridge_doublescale_sync( string $event, int $listing_id, int $user_id = 0 ): void {

	// Never run during a REST API request — the only REST route we own is the
	// incoming CRM action endpoint, and Directorist listing hooks must not
	// interact with DoubleScale's own REST handlers (e.g. create/delete lists,
	// tags, automations). Directorist form submissions and WP-Cron do not set
	// REST_REQUEST, so legitimate syncs are unaffected.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	if ( ! hbl_crm_bridge_doublescale_available() ) {
		return;
	}

	$settings = hbl_crm_bridge_get_settings();

	if ( empty( $settings['doublescale_enabled'] ) ) {
		return;
	}

	$debug = ! empty( $settings['doublescale_debug_log'] );

	$post  = get_post( $listing_id );
	// Use the explicitly supplied user ID (e.g. the claimer), falling back to the listing's post_author.
	$owner = get_userdata( $user_id ?: ( $post ? (int) $post->post_author : 0 ) );

	if ( ! $owner || ! is_email( $owner->user_email ) ) {
		if ( $debug ) {
			hbl_crm_bridge_ds_log( sprintf(
				'[%s #%d] Skipped — no valid owner email (post_author=%s, email="%s").',
				$event, $listing_id,
				$post ? (string) (int) $post->post_author : 'post not found',
				$owner ? $owner->user_email : 'no user'
			) );
		}
		return;
	}

	if ( $debug ) {
		hbl_crm_bridge_ds_log( sprintf( '[%s #%d] Starting sync for %s.', $event, $listing_id, $owner->user_email ) );
	}

	$contact_data = [
		'email'      => $owner->user_email,
		'first_name' => $owner->first_name,
		'last_name'  => $owner->last_name,
		'source'     => 'directorist',
	];

	/** @param array $contact_data Contact fields passed to ContactModel::createOrUpdate(). */
	$contact_data = (array) apply_filters( 'hbl_crm_bridge_doublescale_contact_data', $contact_data, $event, $listing_id );

	// DoubleScale's models can throw (e.g. on a DB error), and this runs inside
	// live Directorist hooks — never let a CRM sync failure break the listing
	// flow for the end user. Log and bail instead.
	try {
		$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::createOrUpdate( $contact_data );

		if ( ! $contact ) {
			if ( $debug ) {
				hbl_crm_bridge_ds_log( sprintf(
					'[%s #%d] ContactModel::createOrUpdate() returned null for %s — contact not saved.',
					$event, $listing_id, $owner->user_email
				) );
			}
			return;
		}

		if ( $debug ) {
			hbl_crm_bridge_ds_log( sprintf(
				'[%s #%d] Contact created/updated (ID=%s) for %s.',
				$event, $listing_id, (string) $contact->id, $owner->user_email
			) );
		}

		// Store the listing URL as contact meta so DoubleScale email templates
		// can reference it (e.g. as a merge tag via custom fields).
		if ( $listing_id && $post ) {
			hbl_crm_bridge_set_contact_meta( $contact, 'listing_url',   get_permalink( $listing_id ) );
			hbl_crm_bridge_set_contact_meta( $contact, 'listing_title', $post->post_title );
			// Also push into custom fields so they're usable as email merge tags.
			hbl_crm_bridge_set_custom_field( $contact, 'business-name',   $post->post_title );
			hbl_crm_bridge_set_custom_field( $contact, 'listing-url',     get_permalink( $listing_id ) ?: '' );
			hbl_crm_bridge_set_custom_field( $contact, 'expiration-date', hbl_crm_bridge_get_listing_expiry( $listing_id ) );
		}

		// Apply the per-event tag, if one is configured.
		$tag_name = trim( $settings['doublescale_tags'][ $event ] ?? '' );
		if ( $tag_name ) {
			$tag = \DoubleScale\Modules\Contacts\Models\TagModel::getOrCreate( $tag_name );
			if ( $tag ) {
				$contact->add_tags( [ $tag->id ] );
				if ( $debug ) {
					hbl_crm_bridge_ds_log( sprintf( '[%s #%d] Applied tag "%s" (ID=%s).', $event, $listing_id, $tag_name, (string) $tag->id ) );
				}
			}
		}

		// Add the contact to the configured list, if any.
		$list_name = trim( $settings['doublescale_list'] ?? '' );
		if ( $list_name ) {
			$list = \DoubleScale\Modules\Contacts\Models\ListModel::getOrCreate( $list_name );
			if ( $list ) {
				$contact->add_lists( [ $list->id ] );
				if ( $debug ) {
					hbl_crm_bridge_ds_log( sprintf( '[%s #%d] Added to list "%s" (ID=%s).', $event, $listing_id, $list_name, (string) $list->id ) );
				}
			}
		}

		// Switch the contact's workflow tags, if configured for this event.
		$remove_tag = trim( $settings['doublescale_workflow_remove_tag'][ $event ] ?? '' );
		$add_tag    = trim( $settings['doublescale_workflow_add_tag'][ $event ] ?? '' );
		if ( $remove_tag || $add_tag ) {
			if ( $debug ) {
				hbl_crm_bridge_ds_log( sprintf(
					'[%s #%d] Switching workflow tags: remove="%s" add="%s".',
					$event, $listing_id, $remove_tag, $add_tag
				) );
			}
			hbl_crm_bridge_doublescale_switch_workflow_tags( $contact, $remove_tag, $add_tag );
		}

		do_action( 'hbl_crm_bridge_doublescale_synced', $event, $listing_id, $contact );

		if ( $debug ) {
			hbl_crm_bridge_ds_log( sprintf( '[%s #%d] Sync complete for %s.', $event, $listing_id, $owner->user_email ) );
		}
	} catch ( \Throwable $e ) {
		error_log( sprintf(
			'[HBL CRM Bridge] DoubleScale sync failed for event "%s" (listing #%d): %s in %s:%d',
			$event, $listing_id, $e->getMessage(), $e->getFile(), $e->getLine()
		) );
	}
}

/**
 * Switches a DoubleScale contact's workflow tags: removes one tag and/or
 * adds another.
 *
 * This is the recommended way to drive automation entry/exit from outside
 * DoubleScale — tags are a stable, first-class part of the Contacts API
 * (the same `TagModel`/`add_tags()` calls already used for the per-event
 * tag above). Configure the matching DoubleScale Automation to start (or
 * exit) when these tags are added/removed under DoubleScale → Automations.
 *
 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact     The synced contact.
 * @param string                                            $remove_name Tag name to remove, or ''.
 * @param string                                            $add_name    Tag name to add, or ''.
 */
function hbl_crm_bridge_doublescale_switch_workflow_tags( $contact, string $remove_name, string $add_name ): void {

	if ( $remove_name ) {
		$remove_tag = \DoubleScale\Modules\Contacts\Models\TagModel::getOrCreate( $remove_name );

		if ( $remove_tag ) {
			$contact->tags()->detach( $remove_tag->id );
			do_action( 'doublescale_contact_tag_remove', $contact, [ $remove_tag->id ] );
		}
	}

	if ( $add_name ) {
		$add_tag = \DoubleScale\Modules\Contacts\Models\TagModel::getOrCreate( $add_name );

		if ( $add_tag ) {
			$contact->add_tags( [ $add_tag->id ] );
		}
	}
}

/**
 * Upserts a single key/value pair in DoubleScale's contact meta table.
 *
 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact
 * @param string $key
 * @param string $value
 */
function hbl_crm_bridge_set_contact_meta( $contact, string $key, string $value ): void {
	try {
		\DoubleScale\Modules\Contacts\Models\ContactMetaModel::updateOrCreate(
			[ 'contact_id' => $contact->id, 'meta_key' => $key ],
			[ 'meta_value' => $value ]
		);
	} catch ( \Throwable $e ) {
		// Non-fatal — meta is supplementary data.
	}
}

/**
 * Writes a value into a DoubleScale contact CUSTOM FIELD, addressed by slug.
 *
 * Unlike contact meta, custom fields are what DoubleScale email sequences and
 * segments can filter on. Mirrors DoubleScale's own UpdateContactFields action
 * (contact.custom_fields()->syncWithoutDetaching([id => ['value' => …]])).
 * No-ops safely if the custom field hasn't been created in DoubleScale yet
 * (get_id() returns 0), so hooks can be wired ahead of the field existing.
 *
 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact
 * @param string $slug  Custom field slug, e.g. "business-name".
 * @param string $value
 */
function hbl_crm_bridge_set_custom_field( $contact, string $slug, string $value ): void {
	if ( ! $contact || ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
		return;
	}
	try {
		$field_id = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::get_id( $slug );
		if ( ! $field_id ) {
			return; // Field not defined in DoubleScale yet — nothing to write to.
		}
		if ( null !== $contact->custom_fields() ) {
			// entity_type MUST be set — the pivot column is NOT NULL with no
			// default, and get_custom_field() only reads rows where it is
			// 'contact' (matches DoubleScale's own RestContactController::sync).
			$contact->custom_fields()->syncWithoutDetaching( [
				(int) $field_id => [ 'value' => $value, 'entity_type' => 'contact' ],
			] );
		}
	} catch ( \Throwable $e ) {
		// Non-fatal — never let a CRM write break the listing/checkout flow.
	}
}

/**
 * Logs a system note on the contact's Activities timeline in DoubleScale, so
 * these signals are visible there (not just as filterable custom-field values).
 * Mirrors DoubleScale's own system-note activities (ActivityModel 'note' +
 * data.type = 'system').
 *
 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact
 * @param string $title Short timeline heading.
 * @param string $note  Body text.
 */
function hbl_crm_bridge_log_activity( $contact, string $title, string $note, int $actor_id = 0 ): void {
	if ( ! $contact || ! class_exists( \DoubleScale\Modules\Activities\Models\ActivityModel::class ) ) {
		return;
	}
	// Attribute to the acting user (the listing owner) so the timeline shows a
	// real name instead of "Unknown User". Falls back to the current user, then
	// null (which reads as Unknown) only when neither is available.
	$user_id = $actor_id ?: ( get_current_user_id() ?: null );
	try {
		\DoubleScale\Modules\Activities\Models\ActivityModel::create( [
			'contact_id'    => $contact->id,
			'activity_type' => 'note',
			'data'          => [
				'title'   => $title,
				'type'    => 'system',
				// The DoubleScale timeline reads the body from content/note and
				// the description line from message — set all so the event text
				// shows on the card, not just "added a note".
				'content' => $note,
				'note'    => $note,
				'message' => $note,
			],
			'user_id'       => $user_id,
		] );
	} catch ( \Throwable $e ) {
		// Non-fatal — timeline logging must never break the listing/checkout flow.
	}
}

/**
 * Human-readable plan-expiry date for a listing, for the "expiration-date"
 * custom field (e.g. when a Silver membership ends). Reads the pricing-plans
 * meta: _never_expire (lifetime) or _expiry_date (Y-m-d H:i:s). Returns '' when
 * the listing has no plan expiry set.
 *
 * @param int $listing_id
 * @return string e.g. "27 July 2027", "Never", or ''.
 */
function hbl_crm_bridge_get_listing_expiry( int $listing_id ): string {
	if ( get_post_meta( $listing_id, '_never_expire', true ) ) {
		return 'Never';
	}
	$raw = (string) get_post_meta( $listing_id, '_expiry_date', true );
	if ( '' === $raw ) {
		return '';
	}
	$ts = strtotime( $raw );
	return $ts ? gmdate( 'j F Y', $ts ) : $raw;
}

// ══════════════════════════════════════════════════════════════════════════════
// DOUBLESCALE CUSTOM FIELD DEFINITIONS
//
// hbl_crm_bridge_set_custom_field() no-ops silently when a slug has no matching
// field in DoubleScale, so without this, "expiration-date" would never actually
// appear as a merge tag unless someone created it by hand in DoubleScale's
// Custom Fields admin screen. This creates it automatically instead, mirroring
// the same self-healing pattern used by the Listing Views plugin.
// ══════════════════════════════════════════════════════════════════════════════

/**
 * The field set this plugin publishes.
 *
 * @return array<string,array{name:string,type:string}>
 */
function hbl_crm_bridge_field_definitions(): array {
	return [
		'expiration-date' => [ 'name' => 'Expiration Date', 'type' => 'text' ],
	];
}

/**
 * Creates any missing custom field definitions in DoubleScale.
 *
 * @return array{created:int,existing:int,error:string}
 */
function hbl_crm_bridge_ensure_custom_fields(): array {
	$result = [ 'created' => 0, 'existing' => 0, 'error' => '' ];

	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
		$result['error'] = 'DoubleScale Pro custom fields are not available.';
		return $result;
	}

	try {
		$group_id = hbl_crm_bridge_ensure_field_group();

		foreach ( hbl_crm_bridge_field_definitions() as $slug => $definition ) {
			if ( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::get_id( $slug ) ) {
				++$result['existing'];
				continue;
			}

			\DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::create(
				[
					'name'     => $definition['name'],
					'slug'     => $slug,
					'type'     => $definition['type'],
					'group_id' => $group_id,
					'scope'    => 'contact',
				]
			);

			++$result['created'];
		}
	} catch ( \Throwable $e ) {
		$result['error'] = $e->getMessage();
	}

	return $result;
}

/**
 * Creates the field definitions once, then stops trying.
 *
 * Retries on each admin load until it actually succeeds, so an install where
 * DoubleScale Pro was activated after this plugin still self-heals rather than
 * silently never publishing anything.
 */
function hbl_crm_bridge_maybe_ensure_custom_fields(): void {
	if ( get_option( 'hbl_crm_bridge_fields_ready' ) ) {
		return;
	}

	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
		return;
	}

	$result = hbl_crm_bridge_ensure_custom_fields();

	if ( ! $result['error'] ) {
		update_option( 'hbl_crm_bridge_fields_ready', 1, false );
	}
}

/**
 * Finds or creates the contact-scoped group this plugin's fields live in.
 *
 * @return int Group ID (0 is accepted by DoubleScale as "ungrouped").
 */
function hbl_crm_bridge_ensure_field_group(): int {
	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::class ) ) {
		return 0;
	}

	try {
		$group = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::where( 'scope', 'contact' )
			->where( 'name', 'CRM Bridge' )
			->first();

		if ( $group ) {
			return (int) $group->id;
		}

		$group = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::create(
			[
				'name'  => 'CRM Bridge',
				'scope' => 'contact',
			]
		);

		return $group ? (int) $group->id : 0;
	} catch ( \Throwable $e ) {
		return 0;
	}
}

/**
 * Finds or creates the DoubleScale contact for a Directorist listing, reusing
 * the same resolver (and therefore the same email/identity) used when the
 * contact was first synced. Returns null when the listing has no usable email.
 *
 * @param int $listing_id
 * @param int $user_id  Optional explicit owner (e.g. the claimer).
 * @return \DoubleScale\Modules\Contacts\Models\ContactModel|null
 */
function hbl_crm_bridge_get_contact_for_listing( int $listing_id, int $user_id = 0 ) {
	if ( ! hbl_crm_bridge_doublescale_available() ) {
		return null;
	}
	$resolved = hbl_crm_bridge_resolve_listing_contact( $listing_id, $user_id );
	if ( ! $resolved ) {
		return null;
	}
	try {
		return \DoubleScale\Modules\Contacts\Models\ContactModel::createOrUpdate( [
			'email'      => $resolved['email'],
			'first_name' => $resolved['first_name'],
			'last_name'  => $resolved['last_name'],
			'source'     => 'directorist',
		] );
	} catch ( \Throwable $e ) {
		return null;
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// BULK SYNC — pushes all unclaimed listing owners into DoubleScale
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Resolve the contact fields for a listing.
 *
 * Claimed listings are re-authored to the claiming WP user on approval (the
 * claim plugin sets post_author => claimer), so their owner is a real account
 * with an email. Unclaimed / imported (e.g. Outscraper) listings stay authored
 * by the importer (admin); their real business details live in the Directorist
 * listing meta (_email + post title), not a WP user — so we fall back to that.
 * Personal name fields are left blank for meta-derived contacts because there
 * is no personal owner, only a business.
 *
 * @param int $listing_id Directorist listing post ID.
 * @param int $user_id    Optional explicit contact user (e.g. the claimer).
 * @return array{email:string, first_name:string, last_name:string, business_name:string}|null
 *         Null when no usable email exists on either the user or the listing.
 */
function hbl_crm_bridge_resolve_listing_contact( int $listing_id, int $user_id = 0 ): ?array {
	$post = get_post( $listing_id );
	if ( ! $post ) {
		return null;
	}

	$business_name = get_the_title( $listing_id );

	// 1. An explicitly supplied owner (e.g. the claimer passed by the claim event)
	//    is the real person and takes priority.
	if ( $user_id ) {
		$u = get_userdata( $user_id );
		if ( $u && is_email( $u->user_email ) ) {
			return [
				'email'         => $u->user_email,
				'first_name'    => (string) $u->first_name,
				'last_name'     => (string) $u->last_name,
				'business_name' => $business_name,
			];
		}
	}

	// 2. The listing's own contact email (Directorist `_email`) is the canonical,
	//    per-listing business email. Prefer it — imported/unclaimed listings are all
	//    authored by the same importer account, so using the author's email would
	//    collapse every listing onto one contact.
	$meta_email = get_post_meta( $listing_id, '_email', true );
	if ( is_email( $meta_email ) ) {
		return [
			'email'         => $meta_email,
			'first_name'    => '', // business-only record — no personal name
			'last_name'     => '',
			'business_name' => $business_name,
		];
	}

	// 3. Last resort: a *real* (non-admin) author account — e.g. an owner who
	//    submitted their listing through the site. Never the admin/importer, or
	//    every seeded listing would resolve to the same account.
	$author = get_userdata( (int) $post->post_author );
	if ( $author && is_email( $author->user_email ) && ! user_can( $author, 'manage_options' ) ) {
		return [
			'email'         => $author->user_email,
			'first_name'    => (string) $author->first_name,
			'last_name'     => (string) $author->last_name,
			'business_name' => $business_name,
		];
	}

	return null;
}

/**
 * Syncs one listing owner to DoubleScale with a given tag.
 * Used by the Bulk Sync feature and can be called externally.
 *
 * @param int    $listing_id  Directorist listing post ID.
 * @param string $tag_name    Tag to apply (e.g. "Unclaimed Listing").
 * @param int    $user_id     Optional override for the contact user (defaults to post_author).
 * @return array{ok: bool, email: string, error: string}
 */
function hbl_crm_bridge_sync_one( int $listing_id, string $tag_name, int $user_id = 0 ): array {
	if ( ! hbl_crm_bridge_doublescale_available() ) {
		return [ 'ok' => false, 'email' => '', 'error' => 'DoubleScale not available.' ];
	}

	$post = get_post( $listing_id );
	if ( ! $post ) {
		return [ 'ok' => false, 'email' => '', 'error' => 'Listing not found.' ];
	}

	$resolved = hbl_crm_bridge_resolve_listing_contact( $listing_id, $user_id );

	if ( ! $resolved ) {
		return [ 'ok' => false, 'email' => '', 'error' => 'No listing email (_email) and no eligible (non-admin) owner account — cannot create a contact.' ];
	}

	try {
		$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::createOrUpdate( [
			'email'      => $resolved['email'],
			'first_name' => $resolved['first_name'],
			'last_name'  => $resolved['last_name'],
			'source'     => 'directorist',
		] );

		if ( ! $contact ) {
			return [ 'ok' => false, 'email' => $resolved['email'], 'error' => 'ContactModel::createOrUpdate returned null.' ];
		}

		hbl_crm_bridge_set_contact_meta( $contact, 'listing_url',   get_permalink( $listing_id ) );
		hbl_crm_bridge_set_contact_meta( $contact, 'listing_title', $post->post_title );
		// Business name (the listing title) — stored as contact meta AND pushed
		// into the "business-name" custom field so email sequences can use it.
		hbl_crm_bridge_set_contact_meta( $contact, 'business_name', $resolved['business_name'] );
		hbl_crm_bridge_set_custom_field( $contact, 'business-name',   $resolved['business_name'] );
		hbl_crm_bridge_set_custom_field( $contact, 'listing-url',     get_permalink( $listing_id ) ?: '' );
		hbl_crm_bridge_set_custom_field( $contact, 'expiration-date', hbl_crm_bridge_get_listing_expiry( $listing_id ) );

		if ( $tag_name ) {
			$tag = \DoubleScale\Modules\Contacts\Models\TagModel::getOrCreate( $tag_name );
			if ( $tag ) {
				$contact->add_tags( [ $tag->id ] );
			}
		}

		return [ 'ok' => true, 'email' => $resolved['email'], 'error' => '' ];

	} catch ( \Throwable $e ) {
		return [ 'ok' => false, 'email' => $resolved['email'], 'error' => $e->getMessage() ];
	}
}

/**
 * Build the WP_Query meta_query for a Bulk Sync scope.
 *
 * @param string $scope unclaimed | claimed | all
 * @return array Empty array for "all" (no meta filter).
 */
function hbl_crm_bridge_scope_meta_query( string $scope ): array {
	if ( 'unclaimed' === $scope ) {
		return [
			'relation' => 'OR',
			[ 'key' => '_claimed_by_admin', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_claimed_by_admin', 'value' => '', 'compare' => '=' ],
		];
	}
	if ( 'claimed' === $scope ) {
		return [
			[ 'key' => '_claimed_by_admin', 'value' => '', 'compare' => '!=' ],
		];
	}
	return [];
}

/**
 * AJAX: list the listings for a scope so the admin can pick which to sync.
 *
 * Each row reports the email the sync would actually use (via the resolver) and
 * whether it is syncable, so the table can flag / disable un-syncable rows up
 * front rather than only discovering them after a sync attempt.
 */
function hbl_crm_bridge_ajax_list_listings(): void {
	check_ajax_referer( 'hbl_crm_bridge_bulk_sync', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized.' );
	}

	$scope = sanitize_key( $_POST['scope'] ?? 'unclaimed' );

	$args = [
		'post_type'      => 'at_biz_dir',
		'post_status'    => 'publish',
		'posts_per_page' => 2000, // safety cap for very large directories
		'orderby'        => 'title',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	];
	$meta_query = hbl_crm_bridge_scope_meta_query( $scope );
	if ( ! empty( $meta_query ) ) {
		$args['meta_query'] = $meta_query;
	}

	$rows = [];
	foreach ( ( new WP_Query( $args ) )->posts as $id ) {
		$resolved = hbl_crm_bridge_resolve_listing_contact( (int) $id );
		$rows[]   = [
			'id'       => (int) $id,
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
			'email'    => $resolved ? $resolved['email'] : '',
			'phone'    => (string) get_post_meta( $id, '_phone', true ),
			'syncable' => (bool) $resolved,
		];
	}

	wp_send_json_success( [ 'rows' => $rows, 'count' => count( $rows ) ] );
}

/**
 * Streams a CSV of the un-syncable (no usable email) listings for a scope, so
 * the businesses that can't become contacts can be enriched and re-imported.
 * Served via admin-post.php (a real file download, not AJAX).
 */
function hbl_crm_bridge_export_noemail_csv(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'hbl' ), '', [ 'response' => 403 ] );
	}
	check_admin_referer( 'hbl_crm_bridge_export_noemail' );

	$scope = sanitize_key( $_GET['scope'] ?? 'unclaimed' );

	$args = [
		'post_type'      => 'at_biz_dir',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	];
	$meta_query = hbl_crm_bridge_scope_meta_query( $scope );
	if ( ! empty( $meta_query ) ) {
		$args['meta_query'] = $meta_query;
	}

	$rows = [];
	foreach ( ( new WP_Query( $args ) )->posts as $id ) {
		// Only export listings that are NOT syncable (no usable email).
		if ( hbl_crm_bridge_resolve_listing_contact( (int) $id ) ) {
			continue;
		}
		$rows[] = [
			(int) $id,
			html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
			(string) get_post_meta( $id, '_phone', true ),
			(string) get_post_meta( $id, '_website', true ),
			(string) get_post_meta( $id, '_address', true ),
			get_permalink( $id ) ?: '',
		];
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="no-email-listings-' . $scope . '-' . gmdate( 'Ymd-His' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel renders accents correctly.
	fputcsv( $out, [ 'Listing ID', 'Business Name', 'Phone', 'Website', 'Address', 'Edit URL' ] );
	foreach ( $rows as $row ) {
		fputcsv( $out, $row );
	}
	fclose( $out );
	exit;
}

/**
 * AJAX handler for the Bulk Sync button.
 *
 * Two modes:
 *  - `ids` present  → sync exactly those listing IDs (the selectable-table flow;
 *    the client sends them in chunks). Returns that chunk's results.
 *  - otherwise      → legacy scope/offset paging over a WP_Query.
 */
function hbl_crm_bridge_ajax_bulk_sync(): void {
	check_ajax_referer( 'hbl_crm_bridge_bulk_sync', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized.' );
	}

	// ── Selected-IDs mode ────────────────────────────────────────────────────
	$ids_raw = (string) ( $_POST['ids'] ?? '' );
	if ( '' !== $ids_raw ) {
		$ids      = array_values( array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) ) );
		$tag_name = sanitize_text_field( $_POST['tag'] ?? '' );

		$synced = 0; $failed = 0; $emails = []; $failures = []; $reason_counts = [];

		foreach ( $ids as $listing_id ) {
			$result = hbl_crm_bridge_sync_one( (int) $listing_id, $tag_name );
			if ( $result['ok'] ) {
				$synced++;
				if ( ! empty( $result['email'] ) ) {
					$emails[] = strtolower( $result['email'] );
				}
			} else {
				$failed++;
				$reason = $result['error'] ?: 'Unknown error.';
				$reason_counts[ $reason ] = ( $reason_counts[ $reason ] ?? 0 ) + 1;
				if ( count( $failures ) < 25 ) {
					$failures[] = [
						'id'     => (int) $listing_id,
						'title'  => html_entity_decode( get_the_title( $listing_id ), ENT_QUOTES ),
						'reason' => $reason,
					];
				}
			}
		}

		wp_send_json_success( [
			'synced'        => $synced,
			'failed'        => $failed,
			'emails'        => array_values( array_unique( $emails ) ),
			'failures'      => $failures,
			'reason_counts' => (object) $reason_counts,
		] );
	}

	$settings = hbl_crm_bridge_get_settings();
	$tag_name = sanitize_text_field( $_POST['tag'] ?? '' );
	$offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$scope    = sanitize_key( $_POST['scope'] ?? 'unclaimed' ); // unclaimed | claimed | all
	$limit    = max( 0, (int) ( $_POST['limit'] ?? 0 ) );        // 0 = no limit (all)
	$per_page = 50;

	// Scope → meta query. "Claimed" means the admin-claim marker
	// (_claimed_by_admin) is set; "unclaimed" means it is absent or empty.
	$meta_query = [];
	if ( 'unclaimed' === $scope ) {
		$meta_query = [
			'relation' => 'OR',
			[ 'key' => '_claimed_by_admin', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_claimed_by_admin', 'value' => '', 'compare' => '=' ],
		];
	} elseif ( 'claimed' === $scope ) {
		$meta_query = [
			[ 'key' => '_claimed_by_admin', 'value' => '', 'compare' => '!=' ],
		];
	}
	// 'all' → no meta filter.

	// Respect the optional max-listings limit while paging.
	$this_batch = $per_page;
	if ( $limit > 0 ) {
		$remaining = $limit - $offset;
		if ( $remaining <= 0 ) {
			wp_send_json_success( [
				'synced' => 0, 'failed' => 0, 'emails' => [], 'failures' => [],
				'reason_counts' => (object) [], 'total' => $limit, 'processed' => $offset, 'done' => true,
			] );
		}
		$this_batch = min( $per_page, $remaining );
	}

	$args = [
		'post_type'      => 'at_biz_dir',
		'post_status'    => 'publish',
		'posts_per_page' => $this_batch,
		'offset'         => $offset,
		'fields'         => 'ids',
	];
	if ( ! empty( $meta_query ) ) {
		$args['meta_query'] = $meta_query;
	}
	$query = new WP_Query( $args );

	$synced        = 0;
	$failed        = 0;
	$emails        = [];
	$failures      = []; // capped list of { id, title, reason } for spot-checking
	$reason_counts = []; // reason => count, so the UI can group identical failures

	foreach ( $query->posts as $listing_id ) {
		$result = hbl_crm_bridge_sync_one( (int) $listing_id, $tag_name );
		if ( $result['ok'] ) {
			$synced++;
			// Track the distinct owner email so the UI can report how many
			// unique contacts actually landed in DoubleScale (contacts are
			// deduped by email, so N listings can map to far fewer contacts).
			if ( ! empty( $result['email'] ) ) {
				$emails[] = strtolower( $result['email'] );
			}
		} else {
			$failed++;
			$reason = $result['error'] ?: 'Unknown error.';
			$reason_counts[ $reason ] = ( $reason_counts[ $reason ] ?? 0 ) + 1;
			if ( count( $failures ) < 25 ) {
				$failures[] = [
					'id'     => (int) $listing_id,
					'title'  => get_the_title( $listing_id ),
					'reason' => $reason,
				];
			}
		}
	}

	$found     = (int) $query->found_posts;
	$total     = ( $limit > 0 ) ? min( $limit, $found ) : $found;
	$processed = $offset + count( $query->posts );
	$done      = ( count( $query->posts ) < $this_batch ) || ( $limit > 0 && $processed >= $limit );

	wp_send_json_success( [
		'synced'        => $synced,
		'failed'        => $failed,
		'emails'        => array_values( array_unique( $emails ) ),
		'failures'      => $failures,
		'reason_counts' => (object) $reason_counts,
		'total'         => $total,
		'processed'     => $processed,
		'done'          => $done,
	] );
}

// ══════════════════════════════════════════════════════════════════════════════
// INCOMING REST ENDPOINT
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Registers the REST route.
 */
function hbl_crm_bridge_register_rest_routes(): void {
	register_rest_route( HBL_CRM_BRIDGE_REST_NS, HBL_CRM_BRIDGE_REST_ROUTE, [
		'methods'             => WP_REST_Server::CREATABLE, // POST only
		'callback'            => 'hbl_crm_bridge_rest_handler',
		'permission_callback' => 'hbl_crm_bridge_rest_auth',
	] );
}

/**
 * Authentication check for incoming requests.
 *
 * Accepts the secret from either:
 *   – HTTP header:  X-HBL-Secret: <key>
 *   – JSON body:    { "secret": "<key>" }
 *
 * @param  WP_REST_Request $request
 * @return bool|WP_Error
 */
function hbl_crm_bridge_rest_auth( WP_REST_Request $request ) {
	$settings       = hbl_crm_bridge_get_settings();
	$expected_secret = sanitize_text_field( $settings['incoming_secret'] ?? '' );

	if ( empty( $expected_secret ) ) {
		return new WP_Error(
			'hbl_crm_no_secret',
			'Incoming secret not configured. Set it in Settings → CRM Bridge.',
			[ 'status' => 500 ]
		);
	}

	$provided = $request->get_header( 'X-HBL-Secret' )
		?: ( $request->get_param( 'secret' ) ?? '' );

	if ( ! hash_equals( $expected_secret, (string) $provided ) ) {
		return new WP_Error(
			'hbl_crm_unauthorized',
			'Invalid or missing secret.',
			[ 'status' => 401 ]
		);
	}

	return true;
}

/**
 * Handles incoming REST requests from the CRM.
 *
 * @param  WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function hbl_crm_bridge_rest_handler( WP_REST_Request $request ) {
	$action = sanitize_key( $request->get_param( 'action' ) ?? '' );

	switch ( $action ) {

		// ── ping ─────────────────────────────────────────────────────────────
		case 'ping':
			return rest_ensure_response( [ 'ok' => true, 'site' => get_site_url() ] );

		// ── get_listing ───────────────────────────────────────────────────────
		case 'get_listing':
			return hbl_crm_bridge_action_get_listing( $request );

		// ── set_listing_status ────────────────────────────────────────────────
		case 'set_listing_status':
			return hbl_crm_bridge_action_set_status( $request );

		default:
			return new WP_Error(
				'hbl_crm_unknown_action',
				sprintf( 'Unknown action "%s". Supported: ping, get_listing, set_listing_status.', $action ),
				[ 'status' => 400 ]
			);
	}
}

/**
 * REST action: return listing data by listing_id or owner_email.
 */
function hbl_crm_bridge_action_get_listing( WP_REST_Request $request ) {

	if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
		return new WP_Error( 'hbl_crm_no_directorist', 'Directorist not active.', [ 'status' => 500 ] );
	}

	$listing_id  = (int) ( $request->get_param( 'listing_id' ) ?? 0 );
	$owner_email = sanitize_email( $request->get_param( 'owner_email' ) ?? '' );

	// Resolve by owner email when no direct ID given.
	if ( ! $listing_id && $owner_email ) {
		$owner = get_user_by( 'email', $owner_email );
		if ( ! $owner ) {
			return new WP_Error( 'hbl_crm_user_not_found', 'No user with that email.', [ 'status' => 404 ] );
		}

		$posts = get_posts( [
			'post_type'      => ATBDP_POST_TYPE,
			'author'         => $owner->ID,
			'posts_per_page' => 10,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		if ( empty( $posts ) ) {
			return new WP_Error( 'hbl_crm_no_listings', 'No listings found for that user.', [ 'status' => 404 ] );
		}

		// Return array of all listings for this owner.
		$data = array_map( fn( $id ) => hbl_crm_bridge_listing_data( (int) $id ), $posts );
		return rest_ensure_response( [ 'listings' => $data ] );
	}

	if ( ! $listing_id ) {
		return new WP_Error( 'hbl_crm_missing_param', 'Provide listing_id or owner_email.', [ 'status' => 400 ] );
	}

	$post = get_post( $listing_id );
	if ( ! $post || $post->post_type !== ATBDP_POST_TYPE ) {
		return new WP_Error( 'hbl_crm_not_found', 'Listing not found.', [ 'status' => 404 ] );
	}

	return rest_ensure_response( [ 'listing' => hbl_crm_bridge_listing_data( $listing_id ) ] );
}

/**
 * REST action: update a listing's post_status.
 */
function hbl_crm_bridge_action_set_status( WP_REST_Request $request ) {

	if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
		return new WP_Error( 'hbl_crm_no_directorist', 'Directorist not active.', [ 'status' => 500 ] );
	}

	$listing_id = (int) ( $request->get_param( 'listing_id' ) ?? 0 );
	$new_status = sanitize_key( $request->get_param( 'status' ) ?? '' );

	$allowed = [ 'publish', 'pending', 'draft', 'expired' ];
	if ( ! in_array( $new_status, $allowed, true ) ) {
		return new WP_Error(
			'hbl_crm_invalid_status',
			sprintf( 'Invalid status. Allowed: %s.', implode( ', ', $allowed ) ),
			[ 'status' => 400 ]
		);
	}

	$post = get_post( $listing_id );
	if ( ! $post || $post->post_type !== ATBDP_POST_TYPE ) {
		return new WP_Error( 'hbl_crm_not_found', 'Listing not found.', [ 'status' => 404 ] );
	}

	$result = wp_update_post( [ 'ID' => $listing_id, 'post_status' => $new_status ], true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( [
		'ok'         => true,
		'listing_id' => $listing_id,
		'status'     => get_post_status( $listing_id ),
	] );
}

/**
 * Returns a consistent data array for a single listing.
 *
 * @param  int $listing_id
 * @return array
 */
function hbl_crm_bridge_listing_data( int $listing_id ): array {
	$post  = get_post( $listing_id );
	$owner = $post ? get_userdata( (int) $post->post_author ) : false;

	return [
		'listing_id'     => $listing_id,
		'listing_title'  => $post ? get_the_title( $post ) : '',
		'listing_url'    => get_permalink( $listing_id ) ?: '',
		'listing_status' => $post ? $post->post_status : '',
		'plan_id'        => (int) get_post_meta( $listing_id, '_fm_plans', true ),
		'expiry_date'    => get_post_meta( $listing_id, '_expiry_date', true ) ?: null,
		'owner_id'       => $owner ? (int) $owner->ID : 0,
		'owner_email'    => $owner ? $owner->user_email : '',
		'owner_name'     => $owner ? $owner->display_name : '',
	];
}

// ══════════════════════════════════════════════════════════════════════════════
// SETTINGS: HELPERS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Returns the plugin settings array, merged with defaults.
 *
 * @return array
 */
function hbl_crm_bridge_get_settings(): array {
	$defaults = [
		'webhook_urls'        => '',
		'incoming_secret'     => '',
		'enabled_events'      => [
			'listing_claimed',
			'listing_published',
			'listing_inserted',
			'listing_updated',
			'listing_expired',
		],
		'doublescale_enabled' => false,
		'doublescale_list'    => '',
		'doublescale_tags'    => [
			'listing_claimed'   => '',
			'listing_published' => '',
			'listing_inserted'  => '',
			'listing_updated'   => '',
			'listing_expired'   => '',
		],
		'doublescale_workflow_remove_tag' => [
			'listing_claimed'   => '',
			'listing_published' => '',
			'listing_inserted'  => '',
			'listing_updated'   => '',
			'listing_expired'   => '',
		],
		'doublescale_workflow_add_tag'    => [
			'listing_claimed'   => '',
			'listing_published' => '',
			'listing_inserted'  => '',
			'listing_updated'   => '',
			'listing_expired'   => '',
		],
		'doublescale_debug_log'           => false,
	];
	$saved = get_option( HBL_CRM_BRIDGE_OPTION_KEY, [] );
	return array_merge( $defaults, (array) $saved );
}

// ══════════════════════════════════════════════════════════════════════════════
// SETTINGS: ADMIN PAGE
// ══════════════════════════════════════════════════════════════════════════════

function hbl_crm_bridge_add_settings_page(): void {
	global $hbl_crm_bridge_page_hook;

	// Position 31 places this directly below the DoubleScale top-level menu (position 30).
	$hbl_crm_bridge_page_hook = add_menu_page(
		'CRM Bridge',
		'CRM Bridge',
		'manage_options',
		'hbl-crm-bridge',
		'hbl_crm_bridge_settings_page_html',
		'dashicons-randomize',
		31
	);
}

/**
 * Enqueues the admin stylesheet only on the CRM Bridge settings page.
 *
 * @param string $hook The current admin page hook suffix.
 */
function hbl_crm_bridge_enqueue_admin_assets( string $hook ): void {
	global $hbl_crm_bridge_page_hook;

	if ( $hook !== $hbl_crm_bridge_page_hook ) {
		return;
	}

	wp_enqueue_style(
		'hbl-crm-bridge-admin',
		HBL_CRM_BRIDGE_URL . 'assets/css/admin.css',
		[],
		HBL_CRM_BRIDGE_VERSION
	);
}

function hbl_crm_bridge_register_settings(): void {
	// The Settings API (register_setting / options.php) is only needed for
	// genuine admin page loads and options.php form submissions — not for
	// admin-ajax.php or any other context that admin_init also fires in.
	// Skipping it in those contexts prevents side-effects during DoubleScale's
	// own admin AJAX calls.
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}

	register_setting(
		'hbl_crm_bridge',
		HBL_CRM_BRIDGE_OPTION_KEY,
		[ 'sanitize_callback' => 'hbl_crm_bridge_sanitize_settings' ]
	);
}

/**
 * Sanitizes the settings before saving.
 *
 * @param  mixed $input
 * @return array
 */
function hbl_crm_bridge_sanitize_settings( $input ): array {
	$all_events = [
		'listing_claimed',
		'listing_published',
		'listing_inserted',
		'listing_updated',
		'listing_expired',
	];

	$clean = [];

	$clean['webhook_urls']    = sanitize_textarea_field( $input['webhook_urls'] ?? '' );
	$clean['incoming_secret'] = sanitize_text_field( $input['incoming_secret'] ?? '' );

	$enabled = [];
	foreach ( $all_events as $ev ) {
		if ( ! empty( $input['enabled_events'][ $ev ] ) ) {
			$enabled[] = $ev;
		}
	}
	$clean['enabled_events'] = $enabled;

	$clean['doublescale_enabled'] = ! empty( $input['doublescale_enabled'] );
	$clean['doublescale_list']    = sanitize_text_field( $input['doublescale_list'] ?? '' );

	$tags = [];
	foreach ( $all_events as $ev ) {
		$tags[ $ev ] = sanitize_text_field( $input['doublescale_tags'][ $ev ] ?? '' );
	}
	$clean['doublescale_tags'] = $tags;

	$workflow_remove_tag = [];
	$workflow_add_tag    = [];
	foreach ( $all_events as $ev ) {
		$workflow_remove_tag[ $ev ] = sanitize_text_field( $input['doublescale_workflow_remove_tag'][ $ev ] ?? '' );
		$workflow_add_tag[ $ev ]    = sanitize_text_field( $input['doublescale_workflow_add_tag'][ $ev ] ?? '' );
	}
	$clean['doublescale_workflow_remove_tag'] = $workflow_remove_tag;
	$clean['doublescale_workflow_add_tag']    = $workflow_add_tag;
	$clean['doublescale_debug_log']           = ! empty( $input['doublescale_debug_log'] );

	return $clean;
}

/**
 * Renders the settings page HTML.
 */
function hbl_crm_bridge_settings_page_html(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings           = hbl_crm_bridge_get_settings();
	$enabled            = $settings['enabled_events'];
	$doublescale_tags   = $settings['doublescale_tags'];
	$doublescale_active = hbl_crm_bridge_doublescale_available();
	$rest_url           = rest_url( HBL_CRM_BRIDGE_REST_NS . HBL_CRM_BRIDGE_REST_ROUTE );

	$all_events = [
		'listing_claimed'   => [
			'label' => 'Listing Claimed',
			'desc'  => 'via the Claim Listing extension',
		],
		'listing_published' => [
			'label' => 'Listing Published',
			'desc'  => 'a new listing goes live',
		],
		'listing_inserted'  => [
			'label' => 'Listing Inserted',
			'desc'  => 'a draft / pending listing is created',
		],
		'listing_updated'   => [
			'label' => 'Listing Updated',
			'desc'  => 'an existing listing is edited',
		],
		'listing_expired'   => [
			'label' => 'Listing Expired',
			'desc'  => 'a listing reaches its expiry date',
		],
	];

	$workflow_tag_switch_count = 0;
	foreach ( $all_events as $slug => $info ) {
		$has_remove = '' !== trim( $settings['doublescale_workflow_remove_tag'][ $slug ] ?? '' );
		$has_add    = '' !== trim( $settings['doublescale_workflow_add_tag'][ $slug ] ?? '' );
		if ( $has_remove || $has_add ) {
			$workflow_tag_switch_count++;
		}
	}

	if ( ! $doublescale_active ) {
		$ds_badge_class = 'hbl-badge-warn';
		$ds_badge_label = 'Not Installed';
	} elseif ( ! empty( $settings['doublescale_enabled'] ) ) {
		$ds_badge_class = 'hbl-badge-on';
		$ds_badge_label = 'Active';
	} else {
		$ds_badge_class = 'hbl-badge-off';
		$ds_badge_label = 'Inactive';
	}

	if ( ! empty( $settings['incoming_secret'] ) ) {
		$api_badge_class = 'hbl-badge-on';
		$api_badge_label = 'Configured';
	} else {
		$api_badge_class = 'hbl-badge-warn';
		$api_badge_label = 'No Secret Set';
	}
	?>
	<div class="wrap hbl-crmb-wrap">
		<h1>
			<svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			CRM Bridge
		</h1>
		<p class="description">Connect Directorist listing events to your local DoubleScale CRM — sync contacts, apply tags, switch contacts between automation workflows via tags, and let your CRM query or update listings through the incoming REST API.</p>

		<div class="hbl-crmb-stats">
			<div class="hbl-crmb-stat">
				<div class="hbl-crmb-stat-value"><?php echo esc_html( count( $enabled ) . ' / ' . count( $all_events ) ); ?></div>
				<div class="hbl-crmb-stat-label">Listing Events Enabled</div>
			</div>
			<div class="hbl-crmb-stat">
				<div class="hbl-crmb-stat-value">
					<span class="hbl-badge <?php echo esc_attr( $ds_badge_class ); ?>"><span class="hbl-badge-dot"></span> <?php echo esc_html( $ds_badge_label ); ?></span>
				</div>
				<div class="hbl-crmb-stat-label">DoubleScale Sync</div>
			</div>
			<div class="hbl-crmb-stat">
				<div class="hbl-crmb-stat-value"><?php echo esc_html( (string) $workflow_tag_switch_count . ' / ' . count( $all_events ) ); ?></div>
				<div class="hbl-crmb-stat-label">Workflow Tag Switches Configured</div>
			</div>
			<div class="hbl-crmb-stat">
				<div class="hbl-crmb-stat-value">
					<span class="hbl-badge <?php echo esc_attr( $api_badge_class ); ?>"><span class="hbl-badge-dot"></span> <?php echo esc_html( $api_badge_label ); ?></span>
				</div>
				<div class="hbl-crmb-stat-label">Incoming REST API</div>
			</div>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'hbl_crm_bridge' ); ?>

			<div class="hbl-crmb-step">
				<div class="hbl-crmb-step-header">
					<div class="hbl-crmb-step-number">1</div>
					<div>
						<h2>Listing Events</h2>
						<p class="hbl-crmb-step-sub">Choose which Directorist events trigger a sync, and the DoubleScale tag (if any) each one applies to the contact.</p>
					</div>
				</div>

				<div class="hbl-crmb-events">
					<?php foreach ( $all_events as $slug => $info ) : ?>
						<div class="hbl-crmb-event">
							<div class="hbl-crmb-event-head">
								<div>
									<span class="hbl-crmb-event-name"><?php echo esc_html( $info['label'] ); ?></span>
									<span class="hbl-crmb-event-slug"><?php echo esc_html( $info['desc'] ); ?></span>
								</div>
								<label class="hbl-toggle">
									<input
										type="checkbox"
										name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[enabled_events][<?php echo esc_attr( $slug ); ?>]"
										value="1"
										<?php checked( in_array( $slug, $enabled, true ) ); ?>
									>
									<span class="hbl-toggle-slider"></span>
								</label>
							</div>
							<?php if ( $doublescale_active ) : ?>
								<div class="hbl-crmb-event-tag">
									<label for="hbl_ds_tag_<?php echo esc_attr( $slug ); ?>">DoubleScale tag</label>
									<input
										type="text"
										id="hbl_ds_tag_<?php echo esc_attr( $slug ); ?>"
										class="hbl-crmb-input"
										name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[doublescale_tags][<?php echo esc_attr( $slug ); ?>]"
										value="<?php echo esc_attr( $doublescale_tags[ $slug ] ?? '' ); ?>"
										placeholder="e.g. <?php echo esc_attr( $info['label'] ); ?>"
									>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="hbl-crmb-step">
				<div class="hbl-crmb-step-header">
					<div class="hbl-crmb-step-number">2</div>
					<div>
						<h2>DoubleScale CRM Sync</h2>
						<p class="hbl-crmb-step-sub">Sync listing owners straight into your local DoubleScale CRM as contacts.</p>
					</div>
					<span class="hbl-badge <?php echo esc_attr( $ds_badge_class ); ?>"><span class="hbl-badge-dot"></span> <?php echo esc_html( $ds_badge_label ); ?></span>
				</div>

				<?php if ( ! $doublescale_active ) : ?>
					<p class="description">The DoubleScale plugin was not detected on this site. Activate it to enable local CRM sync.</p>
				<?php endif; ?>

				<div class="hbl-crmb-toggle-row">
					<label class="hbl-toggle">
						<input
							type="checkbox"
							name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[doublescale_enabled]"
							value="1"
							<?php checked( ! empty( $settings['doublescale_enabled'] ) ); ?>
							<?php disabled( ! $doublescale_active ); ?>
						>
						<span class="hbl-toggle-slider"></span>
					</label>
					<div class="hbl-crmb-toggle-row-text">
						<strong>Sync listing events to DoubleScale contacts</strong>
						<span>The listing owner is created or updated as a Contact, tagged per the events above, and optionally added to the list below.</span>
					</div>
				</div>

				<div class="hbl-crmb-field">
					<label for="hbl_doublescale_list">Add to List</label>
					<input
						type="text"
						id="hbl_doublescale_list"
						class="hbl-crmb-input"
						name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[doublescale_list]"
						value="<?php echo esc_attr( $settings['doublescale_list'] ); ?>"
						placeholder="e.g. Directory Owners"
					>
					<p class="description">Optional. Synced contacts are added to this DoubleScale list (created automatically if it doesn't exist).</p>
				</div>

				<div class="hbl-crmb-toggle-row" style="margin-top:0;background:rgba(245,158,11,0.05);border-color:rgba(245,158,11,0.4)">
					<label class="hbl-toggle">
						<input
							type="checkbox"
							name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[doublescale_debug_log]"
							value="1"
							<?php checked( ! empty( $settings['doublescale_debug_log'] ) ); ?>
						>
						<span class="hbl-toggle-slider"></span>
					</label>
					<div class="hbl-crmb-toggle-row-text">
						<strong>Debug Logging</strong>
						<span>Writes a <code>[HBL CRM Bridge]</code> line to the PHP error log at every step of the sync — useful for diagnosing why contacts or tags are not appearing. Disable once confirmed working.</span>
					</div>
				</div>
			</div>

			<div class="hbl-crmb-step">
				<div class="hbl-crmb-step-header">
					<div class="hbl-crmb-step-number">3</div>
					<div>
						<h2>Workflow Tags</h2>
						<p class="hbl-crmb-step-sub">When an event fires, remove one tag from the contact and/or add another — e.g. remove "Cold Outreach" and add "Onboarding" when a listing is claimed.</p>
					</div>
				</div>

				<?php if ( ! $doublescale_active ) : ?>
					<p class="description">The DoubleScale plugin was not detected on this site. Activate it to configure workflow tags.</p>
				<?php endif; ?>

				<div class="hbl-crmb-events">
					<?php foreach ( $all_events as $slug => $info ) : ?>
						<div class="hbl-crmb-event">
							<div class="hbl-crmb-event-head">
								<div>
									<span class="hbl-crmb-event-name"><?php echo esc_html( $info['label'] ); ?></span>
									<span class="hbl-crmb-event-slug"><?php echo esc_html( $info['desc'] ); ?></span>
								</div>
							</div>
							<div class="hbl-crmb-event-tag">
								<label for="hbl_workflow_remove_tag_<?php echo esc_attr( $slug ); ?>">Remove tag</label>
								<input
									type="text"
									id="hbl_workflow_remove_tag_<?php echo esc_attr( $slug ); ?>"
									class="hbl-crmb-input"
									name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[doublescale_workflow_remove_tag][<?php echo esc_attr( $slug ); ?>]"
									value="<?php echo esc_attr( $settings['doublescale_workflow_remove_tag'][ $slug ] ?? '' ); ?>"
									placeholder="e.g. Cold Outreach"
									<?php disabled( ! $doublescale_active ); ?>
								>
							</div>
							<div class="hbl-crmb-event-tag" style="margin-top:12px">
								<label for="hbl_workflow_add_tag_<?php echo esc_attr( $slug ); ?>">Add tag</label>
								<input
									type="text"
									id="hbl_workflow_add_tag_<?php echo esc_attr( $slug ); ?>"
									class="hbl-crmb-input"
									name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[doublescale_workflow_add_tag][<?php echo esc_attr( $slug ); ?>]"
									value="<?php echo esc_attr( $settings['doublescale_workflow_add_tag'][ $slug ] ?? '' ); ?>"
									placeholder="e.g. Onboarding"
									<?php disabled( ! $doublescale_active ); ?>
								>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="description" style="margin-top:16px">Tags are created automatically if they don't exist. Leave blank to skip. To act on these tags, set up an Automation in DoubleScale → Automations with a "Tag Added" trigger/goal for the "Add tag" value, and an exit/goal condition on the "Remove tag" value — this keeps the actual workflow logic inside DoubleScale, where it's guaranteed to stay compatible with your installed version.</p>
			</div>

			<div class="hbl-crmb-step">
				<div class="hbl-crmb-step-header">
					<div class="hbl-crmb-step-number">4</div>
					<div>
						<h2>Bulk Sync — Push Listing Owners to DoubleScale</h2>
						<p class="hbl-crmb-step-sub">Load listings by scope, tick the ones you want, and sync them into DoubleScale as contacts. Rows without a usable email are flagged as <em>No email</em> and can't be selected. Each synced contact also gets its listing URL and business name stored as contact meta (<code>listing_url</code>, <code>listing_title</code>, <code>business_name</code>).</p>
					</div>
				</div>

				<?php if ( ! $doublescale_active ) : ?>
					<p class="description">DoubleScale must be active to use Bulk Sync.</p>
				<?php else : ?>
					<div class="hbl-crmb-sync-toolbar">
						<div class="hbl-crmb-sync-field">
							<label for="hbl_bulk_sync_scope">Listings to sync</label>
							<select id="hbl_bulk_sync_scope" class="hbl-crmb-input">
								<option value="unclaimed">Unclaimed only</option>
								<option value="claimed">Claimed only</option>
								<option value="all">All listings</option>
							</select>
						</div>
						<div class="hbl-crmb-sync-field hbl-crmb-sync-search">
							<label for="hbl_bulk_sync_search">Search</label>
							<input type="search" id="hbl_bulk_sync_search" class="hbl-crmb-input" placeholder="Filter by business name or email…">
						</div>
						<label class="hbl-crmb-check-inline">
							<input type="checkbox" id="hbl_bulk_only_syncable"> Only syncable
						</label>
						<button type="button" id="hbl-bulk-load-btn" class="button">Load listings</button>
						<button type="button" id="hbl-bulk-export-btn" class="button" title="Download the listings in this scope that have no usable email (can't become contacts)">Export no-email (CSV)</button>
					</div>

					<p id="hbl-bulk-load-msg" class="hbl-crmb-sync-hint">Pick a scope and choose <strong>Load listings</strong> to select which businesses to push into DoubleScale.</p>

					<div id="hbl-bulk-table-wrap" style="display:none">
						<div class="hbl-crmb-selbar">
							<span id="hbl-bulk-selcount" class="hbl-crmb-selcount">0 selected</span>
							<button type="button" id="hbl-bulk-select-syncable" class="button-link">Select all syncable</button>
							<div class="hbl-crmb-selbar-spacer"></div>
							<input type="text" id="hbl_bulk_sync_tag" class="hbl-crmb-input" placeholder="Tag to apply (e.g. Unclaimed Listing)">
							<button type="button" id="hbl-bulk-sync-btn" class="button button-primary">Sync selected</button>
						</div>

						<div class="hbl-crmb-table-scroll">
							<table class="hbl-crmb-table">
								<thead>
									<tr>
										<th class="hbl-crmb-col-chk"><input type="checkbox" id="hbl-bulk-selectall" title="Select all syncable"></th>
										<th>Business</th>
										<th>Email</th>
										<th>Phone</th>
										<th class="hbl-crmb-col-status">Status</th>
									</tr>
								</thead>
								<tbody id="hbl-bulk-tbody"></tbody>
							</table>
						</div>
					</div>
					<div id="hbl-bulk-sync-status" style="margin-top:14px;display:none">
						<div id="hbl-bulk-sync-bar-wrap" style="background:#e2e8f0;border-radius:4px;height:8px;margin-bottom:12px">
							<div id="hbl-bulk-sync-bar" style="background:#3b82f6;height:8px;border-radius:4px;width:0%;transition:width 0.3s"></div>
						</div>
						<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
							<div style="flex:1;min-width:120px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px">
								<div id="hbl-bss-total" style="font-size:22px;font-weight:700;color:#374151;line-height:1.2">0</div>
								<div style="font-size:12px;color:#6b7280">Selected to sync</div>
							</div>
							<div style="flex:1;min-width:120px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px">
								<div id="hbl-bss-synced" style="font-size:22px;font-weight:700;color:#059669;line-height:1.2">0</div>
								<div style="font-size:12px;color:#6b7280">Synced</div>
							</div>
							<div style="flex:1;min-width:120px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px">
								<div id="hbl-bss-failed" style="font-size:22px;font-weight:700;color:#dc2626;line-height:1.2">0</div>
								<div style="font-size:12px;color:#6b7280">Unsynced (failed)</div>
							</div>
							<div style="flex:1;min-width:120px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px">
								<div id="hbl-bss-contacts" style="font-size:22px;font-weight:700;color:#2563eb;line-height:1.2">0</div>
								<div style="font-size:12px;color:#6b7280">Unique contacts in DoubleScale</div>
							</div>
						</div>
						<p id="hbl-bulk-sync-msg" style="margin:0;font-size:13px;color:#374151"></p>
						<div id="hbl-bulk-sync-errors" style="margin:8px 0 0;font-size:12px"></div>
						<p id="hbl-bulk-sync-link" style="margin:10px 0 0;display:none">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=doublescale' ) ); ?>" target="_blank" rel="noopener">Open DoubleScale Contacts →</a>
						</p>
					</div>
					<script>
					(function(){
						var scopeEl    = document.getElementById('hbl_bulk_sync_scope');
						var searchEl   = document.getElementById('hbl_bulk_sync_search');
						var onlySyncEl = document.getElementById('hbl_bulk_only_syncable');
						var loadBtn    = document.getElementById('hbl-bulk-load-btn');
						var loadMsg    = document.getElementById('hbl-bulk-load-msg');
						var tableWrap  = document.getElementById('hbl-bulk-table-wrap');
						var tbody      = document.getElementById('hbl-bulk-tbody');
						var selAll     = document.getElementById('hbl-bulk-selectall');
						var selSyncBtn = document.getElementById('hbl-bulk-select-syncable');
						var selCount   = document.getElementById('hbl-bulk-selcount');
						var tagEl      = document.getElementById('hbl_bulk_sync_tag');
						var syncBtn    = document.getElementById('hbl-bulk-sync-btn');
						var status     = document.getElementById('hbl-bulk-sync-status');
						var bar        = document.getElementById('hbl-bulk-sync-bar');
						var msg        = document.getElementById('hbl-bulk-sync-msg');
						var elTotal    = document.getElementById('hbl-bss-total');
						var elSynced   = document.getElementById('hbl-bss-synced');
						var elFailed   = document.getElementById('hbl-bss-failed');
						var elUnique   = document.getElementById('hbl-bss-contacts');
						var errBox     = document.getElementById('hbl-bulk-sync-errors');
						var linkBox    = document.getElementById('hbl-bulk-sync-link');
						var NONCE      = '<?php echo esc_js( wp_create_nonce( 'hbl_crm_bridge_bulk_sync' ) ); ?>';
						var CHUNK      = 50;

						// Loaded rows + current selection (id -> true).
						var rows = [];
						var selected = {};

						// Sync-run accumulators.
						var seen = {};
						var uniqueCount = 0;
						var reasonCounts = {};
						var samples = [];

						function escapeHtml(s) {
							return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
								return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c];
							});
						}

						function renderErrors() {
							var reasons = Object.keys(reasonCounts);
							if (!reasons.length) { errBox.innerHTML = ''; return; }

							var html = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:10px 12px">';
							html += '<div style="font-weight:600;color:#b91c1c;margin-bottom:6px">Unsynced by reason</div>';
							html += '<ul style="margin:0 0 6px;padding-left:18px;color:#b91c1c">';
							reasons.forEach(function(r){
								html += '<li><strong>' + reasonCounts[r] + '×</strong> ' + escapeHtml(r) + '</li>';
							});
							html += '</ul>';

							if (samples.length) {
								html += '<details style="margin-top:4px"><summary style="cursor:pointer;color:#7f1d1d">Show ' + samples.length + ' example listing(s)</summary>';
								html += '<ul style="margin:6px 0 0;padding-left:18px;color:#7f1d1d">';
								samples.forEach(function(f){
									html += '<li>#' + f.id + ' — ' + escapeHtml(f.title || '(no title)') + '</li>';
								});
								html += '</ul></details>';
							}
							html += '</div>';
							errBox.innerHTML = html;
						}

						function chunk(arr, n) {
							var out = [];
							for (var i = 0; i < arr.length; i += n) { out.push(arr.slice(i, i + n)); }
							return out;
						}

						function visibleRows() {
							var q = (searchEl.value || '').trim().toLowerCase();
							var onlySync = onlySyncEl.checked;
							return rows.filter(function(row){
								if (onlySync && !row.syncable) { return false; }
								if (q && ((row.title + ' ' + row.email).toLowerCase().indexOf(q) === -1)) { return false; }
								return true;
							});
						}

						function updateCount() {
							var n = Object.keys(selected).length;
							selCount.textContent = n + ' selected';
							syncBtn.disabled = (n === 0);
						}

						function updateHeadState() {
							var vis = visibleRows().filter(function(r){ return r.syncable; });
							if (!vis.length) { selAll.checked = false; selAll.indeterminate = false; return; }
							var sel = vis.filter(function(r){ return selected[r.id]; }).length;
							selAll.checked = (sel === vis.length);
							selAll.indeterminate = (sel > 0 && sel < vis.length);
						}

						function renderTable() {
							var list = visibleRows();
							if (!list.length) {
								tbody.innerHTML = '<tr><td colspan="5" class="hbl-crmb-empty">No listings match your filter.</td></tr>';
								updateHeadState();
								return;
							}
							var html = '';
							list.forEach(function(row){
								var checked = selected[row.id] ? ' checked' : '';
								var dis     = row.syncable ? '' : ' disabled';
								var email   = row.email ? escapeHtml(row.email) : '<span class="hbl-crmb-muted">—</span>';
								var phone   = row.phone ? escapeHtml(row.phone) : '<span class="hbl-crmb-muted">—</span>';
								var badge   = row.syncable
									? '<span class="hbl-crmb-badge hbl-crmb-badge-ok">Ready</span>'
									: '<span class="hbl-crmb-badge hbl-crmb-badge-no">No email</span>';
								html += '<tr class="' + (row.syncable ? '' : 'is-disabled') + '">'
									+ '<td class="hbl-crmb-col-chk"><input type="checkbox" class="hbl-bulk-row" data-id="' + row.id + '"' + checked + dis + '></td>'
									+ '<td class="hbl-crmb-cell-biz"><span class="hbl-crmb-biz">' + escapeHtml(row.title || '(no title)') + '</span> <span class="hbl-crmb-muted">#' + row.id + '</span></td>'
									+ '<td data-label="Email">' + email + '</td>'
									+ '<td data-label="Phone">' + phone + '</td>'
									+ '<td class="hbl-crmb-col-status" data-label="Status">' + badge + '</td>'
									+ '</tr>';
							});
							tbody.innerHTML = html;
							updateHeadState();
						}

						function setSelection(on) {
							visibleRows().forEach(function(r){
								if (!r.syncable) { return; }
								if (on) { selected[r.id] = true; } else { delete selected[r.id]; }
							});
							renderTable();
							updateCount();
						}

						// ---- Load listings ----
						loadBtn.addEventListener('click', function(){
							loadBtn.disabled = true;
							loadMsg.style.display = 'block';
							loadMsg.textContent = 'Loading listings…';

							var data = new FormData();
							data.append('action', 'hbl_crm_bridge_list_listings');
							data.append('nonce', NONCE);
							data.append('scope', scopeEl.value);

							fetch(ajaxurl, { method: 'POST', body: data })
								.then(function(r){ return r.json(); })
								.then(function(res){
									loadBtn.disabled = false;
									if (!res.success) { loadMsg.textContent = 'Error: ' + (res.data || 'Could not load listings.'); return; }
									rows = res.data.rows || [];
									selected = {};
									if (!rows.length) { loadMsg.textContent = 'No listings found for this scope.'; tableWrap.style.display = 'none'; return; }
									loadMsg.style.display = 'none';
									tableWrap.style.display = 'block';
									renderTable();
									updateCount();
									status.style.display = 'none';
								})
								.catch(function(e){ loadBtn.disabled = false; loadMsg.textContent = 'Network error: ' + e.message; });
						});

						// ---- Export no-email listings (CSV) ----
						var exportBtn = document.getElementById('hbl-bulk-export-btn');
						if (exportBtn) {
							exportBtn.addEventListener('click', function(){
								window.location.href = '<?php echo esc_js( admin_url( 'admin-post.php' ) ); ?>'
									+ '?action=hbl_crm_bridge_export_noemail'
									+ '&_wpnonce=<?php echo esc_js( wp_create_nonce( 'hbl_crm_bridge_export_noemail' ) ); ?>'
									+ '&scope=' + encodeURIComponent(scopeEl.value);
							});
						}

						// ---- Selection events ----
						tbody.addEventListener('change', function(e){
							if (!e.target.classList.contains('hbl-bulk-row')) { return; }
							var id = e.target.getAttribute('data-id');
							if (e.target.checked) { selected[id] = true; } else { delete selected[id]; }
							updateCount();
							updateHeadState();
						});
						selAll.addEventListener('change', function(){ setSelection(selAll.checked); });
						selSyncBtn.addEventListener('click', function(e){ e.preventDefault(); setSelection(true); });
						searchEl.addEventListener('input', renderTable);
						onlySyncEl.addEventListener('change', renderTable);

						// ---- Sync selected (chunked) ----
						syncBtn.addEventListener('click', function(){
							var ids = Object.keys(selected);
							if (!ids.length) { alert('Select at least one listing to sync.'); return; }
							var tag = tagEl.value.trim();
							if (!tag) { alert('Please enter a tag name first.'); return; }

							var chunks   = chunk(ids, CHUNK);
							var totalSel = ids.length;
							var done = 0, synced = 0, failed = 0;
							seen = {}; uniqueCount = 0; reasonCounts = {}; samples = [];

							syncBtn.disabled = true; loadBtn.disabled = true;
							status.style.display = 'block';
							errBox.innerHTML = ''; linkBox.style.display = 'none';
							elTotal.textContent = totalSel;
							elSynced.textContent = 0; elFailed.textContent = 0; elUnique.textContent = 0;

							function processChunk(i) {
								if (i >= chunks.length) {
									msg.textContent = 'Done! ' + synced + ' listing(s) synced → ' + uniqueCount + ' unique contact(s) in DoubleScale, ' + failed + ' unsynced.';
									bar.style.width = '100%';
									linkBox.style.display = 'block';
									syncBtn.disabled = false; loadBtn.disabled = false;
									return;
								}
								msg.textContent = 'Syncing ' + done + '/' + totalSel + '…';

								var data = new FormData();
								data.append('action', 'hbl_crm_bridge_bulk_sync');
								data.append('nonce', NONCE);
								data.append('tag', tag);
								data.append('ids', chunks[i].join(','));

								fetch(ajaxurl, { method: 'POST', body: data })
									.then(function(r){ return r.json(); })
									.then(function(res){
										if (!res.success) { msg.textContent = 'Error: ' + (res.data || 'Unknown error.'); syncBtn.disabled = false; loadBtn.disabled = false; return; }
										var d = res.data;
										synced += d.synced; failed += d.failed; done += chunks[i].length;
										if (d.emails) { for (var e = 0; e < d.emails.length; e++) { if (!seen[d.emails[e]]) { seen[d.emails[e]] = 1; uniqueCount++; } } }
										if (d.reason_counts) { for (var k in d.reason_counts) { if (Object.prototype.hasOwnProperty.call(d.reason_counts, k)) { reasonCounts[k] = (reasonCounts[k] || 0) + d.reason_counts[k]; } } }
										if (d.failures) { for (var j = 0; j < d.failures.length && samples.length < 50; j++) { samples.push(d.failures[j]); } }
										renderErrors();
										elSynced.textContent = synced; elFailed.textContent = failed; elUnique.textContent = uniqueCount;
										bar.style.width = Math.round((done / totalSel) * 100) + '%';
										processChunk(i + 1);
									})
									.catch(function(err){ msg.textContent = 'Network error: ' + err.message; syncBtn.disabled = false; loadBtn.disabled = false; });
							}
							processChunk(0);
						});
					})();
					</script>
				<?php endif; ?>
			</div>

			<div class="hbl-crmb-step">
				<div class="hbl-crmb-step-header">
					<div class="hbl-crmb-step-number">5</div>
					<div>
						<h2>Incoming REST API</h2>
						<p class="hbl-crmb-step-sub">Let your CRM query or update listing data by sending a request to this site.</p>
					</div>
					<span class="hbl-badge <?php echo esc_attr( $api_badge_class ); ?>"><span class="hbl-badge-dot"></span> <?php echo esc_html( $api_badge_label ); ?></span>
				</div>

				<div class="hbl-crmb-field">
					<label>Endpoint URL</label>
					<div class="hbl-crmb-endpoint">
						<code><?php echo esc_url( $rest_url ); ?></code>
					</div>
					<p class="description">
						Send as <code>POST</code>, <code>Content-Type: application/json</code>, with header
						<code>X-HBL-Secret: &lt;your-secret&gt;</code> and a JSON body containing <code>"action"</code>.
					</p>
				</div>

				<div class="hbl-crmb-field">
					<label for="hbl_incoming_secret">Incoming Secret Key</label>
					<input
						type="text"
						id="hbl_incoming_secret"
						name="<?php echo esc_attr( HBL_CRM_BRIDGE_OPTION_KEY ); ?>[incoming_secret]"
						value="<?php echo esc_attr( $settings['incoming_secret'] ); ?>"
						class="hbl-crmb-input code"
						style="max-width:340px"
						autocomplete="off"
					>
					<button
						type="button"
						class="button button-secondary hbl-crmb-generate-btn"
						onclick="
							var chars='ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
							var key='';
							for(var i=0;i<32;i++) key+=chars.charAt(Math.floor(Math.random()*chars.length));
							document.getElementById('hbl_incoming_secret').value=key;
						"
					>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M21 2v6h-6M3 22v-6h6M3.51 9a9 9 0 0114.85-3.36L21 8M3 16l2.64 2.36A9 9 0 0020.49 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						Generate
					</button>
					<p class="description">Used to authenticate incoming requests from your CRM. Keep this private.</p>
				</div>

				<div class="hbl-crmb-field">
					<label>Supported Actions</label>
					<table class="hbl-crmb-actions-table">
						<tr>
							<th>Action</th>
							<th>Required params</th>
							<th>Returns</th>
						</tr>
						<tr>
							<td><code>ping</code></td>
							<td>—</td>
							<td><code>{"ok":true,"site":"..."}</code></td>
						</tr>
						<tr>
							<td><code>get_listing</code></td>
							<td><code>listing_id</code> or <code>owner_email</code></td>
							<td>Listing data incl. owner, plan, expiry</td>
						</tr>
						<tr>
							<td><code>set_listing_status</code></td>
							<td><code>listing_id</code>, <code>status</code></td>
							<td>New status confirmed</td>
						</tr>
					</table>

					<p class="description" style="margin-top:8px">Example cURL test:</p>
					<pre class="hbl-crmb-curl">curl -X POST '<?php echo esc_url( $rest_url ); ?>' \
  -H 'Content-Type: application/json' \
  -H 'X-HBL-Secret: YOUR_SECRET' \
  -d '{"action":"ping"}'</pre>
				</div>
			</div>

			<?php submit_button( 'Save Settings' ); ?>
		</form>
	</div>
	<?php
}

// ══════════════════════════════════════════════════════════════════════════════
// LIFECYCLE
// ══════════════════════════════════════════════════════════════════════════════

function hbl_crm_bridge_on_activate(): void {
	// Generate a random secret on first activation if none is set.
	$settings = get_option( HBL_CRM_BRIDGE_OPTION_KEY, [] );
	if ( empty( $settings['incoming_secret'] ) ) {
		$settings['incoming_secret'] = wp_generate_password( 32, false );
		update_option( HBL_CRM_BRIDGE_OPTION_KEY, $settings );
	}
}
