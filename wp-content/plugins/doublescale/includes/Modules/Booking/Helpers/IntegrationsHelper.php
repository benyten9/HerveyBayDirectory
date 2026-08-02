<?php

/**
 * Integrations Helper
 *
 * @package DoubleScale
 * @subpackage Helpers
 */

namespace DoubleScale\Modules\Booking\Helpers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Managers\IntegrationsManager;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use Illuminate\Support\Arr;

/**
 * Integrations Helper class
 */
class IntegrationsHelper {










	/**
	 * Check if integrations are available
	 *
	 * @return bool
	 */
	public static function has_integrations() {
		return true;
	}

	/**
	 * Whether the given host calendar has at least one calendar integration
	 * connected (Google / Outlook / Apple / etc.).
	 *
	 * Used by collective events to gate booking creation: a collective meeting
	 * is created on the team owner's integrated calendar — without one, the
	 * meeting link can't be issued, so we block the booking up-front rather
	 * than leaving an orphan record with no remote event.
	 *
	 * @param CalendarModel|null $host_calendar Host (`type=host`) calendar whose meta is checked.
	 * @return bool
	 */
	public static function host_has_any_calendar_integration( $host_calendar ): bool {
		if ( ! $host_calendar ) {
			return false;
		}
		$integrations = IntegrationsManager::instance()->get_items();
		if ( ! is_array( $integrations ) || empty( $integrations ) ) {
			return false;
		}
		foreach ( $integrations as $integration ) {
			if ( ! is_object( $integration ) || empty( $integration->meta_key ) ) {
				continue;
			}
			if ( method_exists( $integration, 'is_calendar' ) && ! $integration->is_calendar() ) {
				continue;
			}
			$accounts = $host_calendar->get_meta( $integration->meta_key, array() );
			if ( self::calendar_meta_has_integration_accounts( $accounts ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether calendar meta for an integration contains at least one usable account row.
	 *
	 * Prefer this over empty( $meta ) alone: meta may decode as object, or list rows with
	 * tokens / app_credentials while the outer value is still truthy in edge DB states.
	 *
	 * @param mixed $accounts Value from {@see \DoubleScale\Modules\Booking\Integration\Accounts::get_accounts()}.
	 */
	public static function calendar_meta_has_integration_accounts( $accounts ): bool {
		if ( $accounts instanceof \stdClass ) {
			$accounts = (array) $accounts;
		}
		if ( ! is_array( $accounts ) ) {
			return false;
		}
		if ( array() === $accounts ) {
			return false;
		}
		foreach ( $accounts as $row ) {
			if ( $row instanceof \stdClass ) {
				$row = (array) $row;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['tokens'] ) ) {
				return true;
			}
			$ac = $row['app_credentials'] ?? null;
			if ( is_array( $ac ) ) {
				if ( ! empty( $ac['account_id'] ) || ! empty( $ac['client_id'] ) || ! empty( $ac['client_secret'] ) ) {
					return true;
				}
			} elseif ( $ac instanceof \stdClass ) {
				$ac = (array) $ac;
				if ( ! empty( $ac['account_id'] ) || ! empty( $ac['client_id'] ) || ! empty( $ac['client_secret'] ) ) {
					return true;
				}
			}
			if ( ! empty( $row['config'] ) && is_array( $row['config'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a Zoom account row can create meetings (OAuth tokens or Server-to-Server app credentials).
	 *
	 * UI readiness previously required app_credentials only; OAuth-connected hosts often store tokens only.
	 *
	 * @param mixed $account Account row from meta (array or object).
	 */
	public static function zoom_account_ready_for_conferencing( $account ): bool {
		if ( $account instanceof \stdClass ) {
			$account = (array) $account;
		}
		if ( ! is_array( $account ) ) {
			return false;
		}
		if ( ! empty( Arr::get( $account, 'tokens.access_token' ) ) ) {
			return true;
		}
		$ac = Arr::get( $account, 'app_credentials', array() );
		if ( $ac instanceof \stdClass ) {
			$ac = (array) $ac;
		}
		return is_array( $ac )
			&& ! empty( $ac['account_id'] )
			&& ! empty( $ac['client_id'] )
			&& ! empty( $ac['client_secret'] );
	}

	/**
	 * Get default integrations data
	 *
	 * @param string $context The context for which we need the defaults ('manager' or 'event').
	 * @return array
	 */
	public static function get_default_integrations( $context = 'event' ) {
		// Apple/Google/Outlook/Zoom integrations are Pro-tier — their icons live in Pro.
		// On Free-only installs the constant is undefined and the icon URL silently empties.
		$pro_icons_base = defined( 'DOUBLESCALE_PRO_PLUGIN_URL' )
			? DOUBLESCALE_PRO_PLUGIN_URL . 'assets/booking-icons/'
			: '';

		if ( $context === 'manager' ) {
			return array(

				'google'  => array(
					'name'         => 'Google Calendar/Meet',
					'description'  => 'Google Calendar Integration',
					'icon'         => $pro_icons_base ? $pro_icons_base . 'google/icon.svg' : '',
					'is_calendar'  => true,
					'auth_type'    => 'oauth2',
					'has_accounts' => true,
					'is_global'    => false,
				),

				'outlook' => array(
					'name'         => 'Outlook Calendar/MS Teams Conferencing',
					'description'  => 'Outlook Calendar Integration',
					'icon'         => $pro_icons_base ? $pro_icons_base . 'outlook/icon.svg' : '',
					'is_calendar'  => true,
					'auth_type'    => 'oauth2',
					'has_accounts' => true,
					'is_global'    => false,
				),
				'zoom'    => array(
					'name'         => 'Zoom Integration',
					'description'  => 'Zoom Meeting Integration',
					'icon'         => $pro_icons_base ? $pro_icons_base . 'zoom/icon.svg' : '',
					'is_calendar'  => false,
					'auth_type'    => 'basic',
					'has_accounts' => false,
					'is_global'    => false,
				),
				'apple'   => array(
					'name'         => 'Apple Calendar',
					'description'  => 'Apple Calendar Integration',
					'icon'         => $pro_icons_base ? $pro_icons_base . 'apple/icon.svg' : '',
					'is_calendar'  => true,
					'auth_type'    => 'basic',
					'has_accounts' => true,
					'is_global'    => false,
				),
			);
		} else {
			// For event context or default
			return array(
				'google'  => array(
					'name'            => 'Google',
					'connected'       => false,
					'has_accounts'    => false,
					'has_settings'    => false,
					'has_get_started' => false,
					'has_pro_version' => false,
				),
				'outlook' => array(
					'name'            => 'Outlook',
					'connected'       => false,
					'has_accounts'    => false,
					'has_settings'    => false,
					'teams_enabled'   => false,
					'has_get_started' => false,
					'has_pro_version' => false,
				),
				'apple'   => array(
					'name'            => 'Apple',
					'connected'       => false,
					'has_accounts'    => false,
					'has_settings'    => false,
					'has_get_started' => false,
					'has_pro_version' => false,
				),
				'zoom'    => array(
					'name'            => 'Zoom',
					'connected'       => false,
					'has_accounts'    => false,
					'has_settings'    => false,
					'has_get_started' => false,
					'has_pro_version' => false,
				),
			);
		}
	}
}
