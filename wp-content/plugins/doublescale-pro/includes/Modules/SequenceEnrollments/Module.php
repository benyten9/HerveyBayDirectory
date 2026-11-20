<?php
/**
 * Email sequence enrolments: one row per contact per sequence.
 *
 * Owns the `doublescale_sequence_contacts` table and the one-time copy of
 * enrolments that older releases kept inside the sequence's settings JSON.
 *
 * @package DoubleScale\Pro\Modules\SequenceEnrollments
 */

namespace DoubleScale\Pro\Modules\SequenceEnrollments;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

/**
 * Sequence enrolments module.
 */
final class Module extends AbstractModule {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'sequence-enrollments';
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return __( 'Email Sequence Enrolments', 'doublescale' );
	}

	/**
	 * @return string
	 */
	public function description(): string {
		return __( 'Who is enrolled in each email sequence, when they joined and why they left.', 'doublescale' );
	}

	/**
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return false;
	}

	/**
	 * @return array<int, string>
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	/**
	 * @param Container $container Service container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		parent::boot( $container );
		add_action( 'doublescale_contact_deleting', array( SequenceEnrollments::class, 'forget_contact' ) );
	}

	/**
	 * The table first, then the copy out of the old settings JSON.
	 *
	 * @return array<int, string>
	 */
	public function migrations(): array {
		$dir = $this->module_dir() . '/Migrations';

		return array(
			$dir . '/SequenceContactsTable.php',
			$dir . '/SequenceContactsBackfill.php',
		);
	}
}
