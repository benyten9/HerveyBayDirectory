<?php
/**
 * Notify a configured address after an automatic contact merge.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Contacts\Services;

use DoubleScale\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the after-the-fact notice for merges that nobody reviewed.
 *
 * An automatic merge is not reversible and happens with no one watching, so the
 * people who own data quality have to learn it happened. The recipient is one
 * address configured in settings rather than every administrator: the person
 * responsible may not be a WordPress user, and broadcasting a routine
 * housekeeping event to every admin trains them to ignore it.
 *
 * @since 1.0.0
 */
final class ContactMergeNotifier {

	/**
	 * Settings key holding the contact-merge options.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'contact_merge';

	/**
	 * Register the listener.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'doublescale_contact_auto_merged', array( __CLASS__, 'on_auto_merged' ), 10, 3 );
	}

	/**
	 * Send the notice for one automatic merge.
	 *
	 * @param array  $merged  Public merged contact row.
	 * @param array  $preview The preview the merge decision was made on.
	 * @param string $reason  Where the merge came from (import, purchase, ...).
	 * @return void
	 */
	public static function on_auto_merged( $merged, $preview = array(), $reason = '' ) {
		$settings  = Settings::get( self::SETTINGS_KEY, array() );
		$settings  = is_array( $settings ) ? $settings : array();
		$recipient = self::resolve_recipient( $settings );

		// No address configured means the site has not asked to be told.
		if ( '' === $recipient ) {
			return;
		}

		$contact_id = isset( $merged['id'] ) ? (int) $merged['id'] : 0;
		$name       = self::describe_contact( $merged );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Duplicate contacts were merged automatically', 'doublescale' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines = array(
			sprintf(
				/* translators: %s: contact name or email. */
				__( 'A duplicate was merged into %s.', 'doublescale' ),
				$name
			),
			'',
			sprintf(
				/* translators: %s: what triggered the merge. */
				__( 'Triggered by: %s', 'doublescale' ),
				'' !== (string) $reason ? (string) $reason : __( 'an automatic update', 'doublescale' )
			),
		);

		$copied = self::describe_copied_identifiers( $preview );
		if ( '' !== $copied ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated list of identifiers. */
				__( 'Details added to the surviving contact: %s', 'doublescale' ),
				$copied
			);
		}

		$lines[] = '';
		$lines[] = __( 'This merge ran without review because the two records had nothing in conflict. Open the contact to check the result:', 'doublescale' );
		$lines[] = self::contact_url( $contact_id );

		/**
		 * Filters the automatic-merge notification before it is sent.
		 *
		 * @param array  $email  Subject, body and recipient.
		 * @param array  $merged Merged contact row.
		 * @param string $reason Merge trigger.
		 */
		$email = apply_filters(
			'doublescale_contact_auto_merge_email',
			array(
				'to'      => $recipient,
				'subject' => $subject,
				'body'    => implode( "\n", $lines ),
			),
			$merged,
			(string) $reason
		);

		if ( empty( $email['to'] ) ) {
			return;
		}

		$sent = wp_mail( $email['to'], $email['subject'], $email['body'] );

		if ( ! $sent ) {
			doublescale_get_logger()->warning(
				'Automatic contact merge notification could not be sent',
				array(
					'contact_id' => $contact_id,
					'reason'     => (string) $reason,
					'code'       => 'contact_auto_merge_notice_failed',
				)
			);
		}
	}

	/**
	 * Read the notification address out of the contact-merge settings.
	 *
	 * Returns an empty string when nothing usable is configured — the caller
	 * then sends nothing, rather than falling back to an inbox that never
	 * asked for these notices.
	 *
	 * @param array $settings Contact-merge settings.
	 * @return string A valid address, or '' when there is none.
	 */
	private static function resolve_recipient( array $settings ) {
		$configured = $settings['notify_email'] ?? '';

		if ( ! is_string( $configured ) ) {
			return '';
		}

		$configured = trim( $configured );

		if ( '' === $configured || ! is_email( $configured ) ) {
			return '';
		}

		return $configured;
	}

	/**
	 * Name a contact for the notice, falling back to whatever identifies it.
	 *
	 * @param array $contact Public contact row.
	 * @return string
	 */
	private static function describe_contact( $contact ) {
		$contact = is_array( $contact ) ? $contact : array();

		$name = trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}

		foreach ( array( 'email', 'phone', 'whatsapp_phone' ) as $field ) {
			if ( ! empty( $contact[ $field ] ) ) {
				return (string) $contact[ $field ];
			}
		}

		return sprintf(
			/* translators: %d: contact ID. */
			__( 'contact #%d', 'doublescale' ),
			isset( $contact['id'] ) ? (int) $contact['id'] : 0
		);
	}

	/**
	 * List the identifiers the merge copied over, for the body of the notice.
	 *
	 * @param array $preview Merge preview.
	 * @return string Comma-separated list, or '' when nothing was copied.
	 */
	private static function describe_copied_identifiers( $preview ) {
		$preview = is_array( $preview ) ? $preview : array();
		$copied  = $preview['identifiers_to_copy'] ?? array();

		if ( ! is_array( $copied ) || empty( $copied ) ) {
			return '';
		}

		$labels = array(
			'email'          => __( 'email address', 'doublescale' ),
			'phone'          => __( 'phone number', 'doublescale' ),
			'whatsapp_phone' => __( 'WhatsApp number', 'doublescale' ),
		);

		$parts = array();
		foreach ( $copied as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['field'] ) ) {
				continue;
			}

			$field   = (string) $entry['field'];
			$parts[] = $labels[ $field ] ?? $field;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Admin URL for the merged contact.
	 *
	 * @param int $contact_id Contact ID.
	 * @return string
	 */
	private static function contact_url( $contact_id ) {
		if ( $contact_id <= 0 ) {
			return admin_url( 'admin.php?page=doublescale&path=contacts' );
		}

		return admin_url( 'admin.php?page=doublescale&path=contacts/' . $contact_id );
	}
}
