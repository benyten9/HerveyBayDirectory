<?php
/**
 * SendGrid personalization block (SDK drop-in for \SendGrid\Mail\Personalization).
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendGrid;

defined( 'ABSPATH' ) || exit;

/**
 * Personalization.
 *
 * @since 1.0.0
 */
class Personalization {

	/**
	 * To recipients.
	 *
	 * @var To[]
	 */
	protected $to = array();

	/**
	 * Cc recipients.
	 *
	 * @var To[]
	 */
	protected $cc = array();

	/**
	 * Bcc recipients.
	 *
	 * @var To[]
	 */
	protected $bcc = array();

	/**
	 * Substitutions keyed by token.
	 *
	 * @var array
	 */
	protected $substitutions = array();

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-compatible surface.

	/**
	 * @param To $to Recipient.
	 */
	public function addTo( To $to ) {
		$this->to[] = $to;
	}

	/**
	 * @param To $cc Recipient.
	 */
	public function addCc( To $cc ) {
		$this->cc[] = $cc;
	}

	/**
	 * @param To $bcc Recipient.
	 */
	public function addBcc( To $bcc ) {
		$this->bcc[] = $bcc;
	}

	/**
	 * @param string $key   Substitution token, e.g. "{{name}}".
	 * @param string $value Replacement value.
	 */
	public function addSubstitution( $key, $value ) {
		$this->substitutions[ (string) $key ] = (string) $value;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Serialise to a personalizations entry.
	 *
	 * @return array
	 */
	public function to_array() {
		$entry = array(
			'to' => array_map(
				static function ( To $t ) {
					return $t->to_array();
				},
				$this->to
			),
		);

		if ( ! empty( $this->cc ) ) {
			$entry['cc'] = array_map(
				static function ( To $t ) {
					return $t->to_array();
				},
				$this->cc
			);
		}
		if ( ! empty( $this->bcc ) ) {
			$entry['bcc'] = array_map(
				static function ( To $t ) {
					return $t->to_array();
				},
				$this->bcc
			);
		}
		if ( ! empty( $this->substitutions ) ) {
			$entry['substitutions'] = $this->substitutions;
		}

		return $entry;
	}
}
