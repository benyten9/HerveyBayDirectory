<?php
/**
 * A contact's new WhatsApp number starts without the old number's window.
 *
 * Meta's 24-hour window belongs to the number that wrote in, so when
 * `whatsapp_phone` changes the stored window goes, and the time of the change
 * is kept so inbound messages from the old number open nothing.
 *
 * This used to be answered by the WhatsApp campaign engine
 * (WhatsappConversationWindow::on_number_changed). The engine is moving to Pro,
 * while every contact save that changes the number — admin, portal, import,
 * WooCommerce, Zapier — runs in free; the two meta writes belong with the
 * contact, so Contacts does them itself and works with any engine, or none.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsappNumberChange.
 */
final class WhatsappNumberChange {

	/** Contact meta holding Meta's conversation window for the current number. */
	const WINDOW_META_KEY = 'whatsapp_conversation_window';

	/** Contact meta holding when the WhatsApp number last changed (UTC). */
	const CHANGED_META_KEY = 'whatsapp_number_changed_at';

	/**
	 * Listen for number changes on contact saves.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'doublescale_contact_number_changed', array( self::class, 'on_number_changed' ), 10, 2 );
	}

	/**
	 * Forget the old number's window when the WhatsApp number changes.
	 *
	 * @param object $contact Saved contact.
	 * @param string $field   'phone' or 'whatsapp_phone'.
	 * @return void
	 */
	public static function on_number_changed( $contact, $field ) {
		if ( 'whatsapp_phone' !== $field || empty( $contact->id ) ) {
			return;
		}

		doublescale_delete_contact_meta( (int) $contact->id, self::WINDOW_META_KEY );
		doublescale_update_contact_meta( (int) $contact->id, self::CHANGED_META_KEY, gmdate( 'Y-m-d H:i:s' ) );
	}
}
