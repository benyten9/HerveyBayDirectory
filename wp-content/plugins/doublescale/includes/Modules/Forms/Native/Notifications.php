<?php
/**
 * The two emails a form can send: the team notification and the autoresponder.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Form emails.
 */
final class Notifications {

	/**
	 * Send the form's emails for a submission.
	 *
	 * @param FormModel $form       Form.
	 * @param array     $settings   Settings.
	 * @param mixed     $submission Submission model.
	 * @param array     $fields     Input fields keyed by id.
	 * @param array     $answers    Answers keyed by key.
	 * @param mixed     $contact    Contact model or null.
	 * @return void
	 */
	public static function send( FormModel $form, array $settings, $submission, array $fields, array $answers, $contact ): void {
		$extra = array( 'submission_id' => (string) $submission->id );
		$admin = $settings['notifications']['admin'];

		if ( $admin['enabled'] ) {
			// Each address (or tag) in "To" gives at most one recipient, so an answer holding a
			// list of addresses can't turn the team email into a mailer for strangers.
			$recipients = array();
			foreach ( preg_split( '/[,;\s]+/', (string) $admin['to'] ) as $piece ) {
				if ( '' === trim( $piece ) ) {
					continue;
				}
				$rendered = Text::render( $piece, $form, $answers, $fields, $contact, $extra, 'text' );
				foreach ( preg_split( '/[,;\s]+/', $rendered ) as $candidate ) {
					if ( is_email( trim( $candidate ) ) ) {
						$recipients[] = trim( $candidate );
						break;
					}
				}
			}
			$subject    = Text::render( (string) $admin['subject'], $form, $answers, $fields, $contact, $extra, 'text' );
			$body       = Text::render( (string) $admin['body'], $form, $answers, $fields, $contact, $extra );
			$body      .= self::footer( $form, $submission, $contact );
			foreach ( array_unique( $recipients ) as $recipient ) {
				( new Emails() )->send( $recipient, $subject, $body );
			}
		}

		$reply = $settings['notifications']['autoresponder'];
		if ( $reply['enabled'] ) {
			$email = $contact && is_email( (string) $contact->email ) ? (string) $contact->email : '';
			if ( '' === $email ) {
				foreach ( $fields as $field ) {
					// Not a question set to "Don't save to the contact" (a friend's email, say).
					$not_theirs = ! empty( $field['map'] ) && 'none' === ( $field['map']['object'] ?? '' );
					if ( 'email' === $field['type'] && ! $not_theirs && ! empty( $answers[ $field['key'] ] ) && is_email( $answers[ $field['key'] ] ) ) {
						$email = (string) $answers[ $field['key'] ];
						break;
					}
				}
			}
			if ( '' !== $email ) {
				$subject = Text::render( (string) $reply['subject'], $form, $answers, $fields, $contact, $extra, 'text' );
				$body    = Text::render( (string) $reply['body'], $form, $answers, $fields, $contact, $extra );
				( new Emails() )->send( $email, $subject, $body );
			}
		}
	}

	/**
	 * Links under the team notification: the submission and the contact in DoubleScale.
	 *
	 * @param FormModel $form       Form.
	 * @param mixed     $submission Submission.
	 * @param mixed     $contact    Contact or null.
	 * @return string
	 */
	private static function footer( FormModel $form, $submission, $contact ): string {
		$base  = admin_url( 'admin.php?page=doublescale' );
		$links = array(
			'<a href="' . esc_url( $base . '&path=forms/' . (int) $form->id . '/submissions' ) . '">' . esc_html__( 'View submission', 'doublescale' ) . '</a>',
		);
		if ( $contact ) {
			$links[] = '<a href="' . esc_url( $base . '&path=contacts/' . (int) $contact->id ) . '">' . esc_html__( 'Open contact', 'doublescale' ) . '</a>';
		}
		unset( $submission );
		return '<p style="margin-top:24px;color:#6b7280;font-size:13px">' . implode( ' · ', $links ) . '</p>';
	}
}
