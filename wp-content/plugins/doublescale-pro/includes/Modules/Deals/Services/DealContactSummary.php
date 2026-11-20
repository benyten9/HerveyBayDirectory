<?php
/**
 * Open deals in the contact profile summary.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

use DoubleScale\Core\UserRoles\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `deals` to the free plugin's contact summary: how many open deals the
 * viewer can see for the contact, their total value and the latest stage.
 */
class DealContactSummary {

	/**
	 * Hook into the summary.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'doublescale_contact_summary', array( $this, 'add' ), 10, 2 );
	}

	/**
	 * Fill the `deals` part.
	 *
	 * @param array $summary    Summary parts.
	 * @param int   $contact_id Contact ID.
	 * @return array
	 */
	public function add( $summary, $contact_id ) {
		if ( ! Permissions::has_agent_access() ) {
			return $summary;
		}

		try {
			// Same list the Deals tab shows, so a rep counts only their own.
			$deals = DealManager::instance()->get_deals_with_filters(
				array(
					'contact_id' => (int) $contact_id,
					'status'     => 'open',
				)
			);
		} catch ( \Throwable $e ) {
			return $summary;
		}

		$value    = 0.0;
		$currency = '';
		$stage    = '';
		foreach ( $deals as $deal ) {
			$value += (float) $deal->value;
			if ( '' === $currency ) {
				$currency = (string) $deal->currency;
			}
			if ( '' === $stage && $deal->stage ) {
				$stage = (string) $deal->stage->name;
			}
		}

		$summary['deals'] = array(
			'count'    => count( $deals ),
			'value'    => round( $value, 2 ),
			'currency' => $currency,
			'stage'    => $stage,
		);

		return $summary;
	}
}
