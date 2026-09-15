<?php
/**
 * Builds the filtered contacts query shared by the list endpoint and the
 * filter-targeted bulk actions.
 *
 * Both paths must resolve to the *same* set of contacts: when a user picks
 * "select all N matching this filter", the rows the bulk action mutates have
 * to be exactly the rows the list showed them. Keeping the pipeline in one
 * place is what makes that guarantee testable instead of aspirational.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Contacts\Filters\Process as Contact_Filters_Process;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_REST_Request;

/**
 * Translates list/bulk request criteria into an Eloquent query.
 */
final class ContactQueryBuilder {

	/**
	 * Criteria keys that narrow the contact set.
	 *
	 * Used to decide whether a filter target addresses the whole database.
	 *
	 * @var string[]
	 */
	const NARROWING_KEYS = array(
		'keywords',
		'filters',
		'from',
		'to',
		'subscribed',
		'campaign_type',
		'has_whatsapp_phone',
	);

	/**
	 * Build a query from a REST request's list parameters.
	 *
	 * @param WP_REST_Request      $request Request carrying the list criteria.
	 * @param array<string, mixed> $args    Optional. 'with' => relations to eager-load.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function from_request( $request, array $args = array() ) {
		return self::from_criteria( self::criteria_from_request( $request ), $args );
	}

	/**
	 * Extract the list criteria from a REST request.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array<string, mixed>
	 */
	public static function criteria_from_request( $request ) {
		return array(
			'keywords'           => $request->get_param( 'keywords' ) ?? '',
			'filters'            => self::normalize_filters( $request->get_param( 'filters' ) ),
			'subscribed'         => $request->get_param( 'subscribed' ) ?? false,
			'campaign_type'      => $request->get_param( 'campaign_type' ) ?? null,
			'has_whatsapp_phone' => $request->get_param( 'has_whatsapp_phone' ) ?? null,
			'from'               => $request->get_param( 'from' ) ?? null,
			'to'                 => $request->get_param( 'to' ) ?? null,
		);
	}

	/**
	 * Whether the criteria narrow the set at all.
	 *
	 * An empty set of criteria addresses every contact in the database, which
	 * callers must confirm explicitly rather than stumble into.
	 *
	 * @param array<string, mixed> $criteria Criteria array.
	 *
	 * @return bool
	 */
	public static function is_empty_criteria( array $criteria ) {
		foreach ( self::NARROWING_KEYS as $key ) {
			$value = $criteria[ $key ] ?? null;

			if ( 'keywords' === $key ) {
				if ( '' !== trim( (string) $value ) ) {
					return false;
				}
				continue;
			}

			if ( 'subscribed' === $key ) {
				if ( $value ) {
					return false;
				}
				continue;
			}

			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					return false;
				}
				continue;
			}

			if ( null !== $value && '' !== $value && false !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build a query from an already-extracted criteria array.
	 *
	 * The order of the clauses below is deliberate and mirrors the list
	 * endpoint exactly: date range, advanced filters, subscription, campaign
	 * channel, WhatsApp availability, then the keyword search last so it
	 * searches *within* the filtered results.
	 *
	 * @param array<string, mixed> $criteria Criteria array.
	 * @param array<string, mixed> $args     Optional. 'with' => relations to eager-load.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function from_criteria( array $criteria, array $args = array() ) {
		$keywords           = $criteria['keywords'] ?? '';
		$filters            = $criteria['filters'] ?? null;
		$subscribed         = $criteria['subscribed'] ?? false;
		$campaign_type      = $criteria['campaign_type'] ?? null;
		$has_whatsapp_phone = $criteria['has_whatsapp_phone'] ?? null;
		$from               = $criteria['from'] ?? null;
		$to                 = $criteria['to'] ?? null;

		$contacts = ContactModel::query();

		$with = $args['with'] ?? array();
		if ( ! empty( $with ) ) {
			$contacts = $contacts->with( $with );
		}

		// Apply date range filters.
		if ( $from ) {
			$contacts->where( 'created_at', '>=', $from );
		}
		if ( $to ) {
			$contacts->where( 'created_at', '<=', $to );
		}

		// Apply filters FIRST to narrow down the results.
		if ( $filters ) {
			$filters_process = new Contact_Filters_Process( $contacts, $filters );
			$contacts        = $filters_process->filter();
		}

		// Apply subscription filter.
		if ( $subscribed ) {
			$contacts = $contacts->where( 'email_status', 'subscribed' );
		}

		// Apply campaign type filter (email/phone availability + channel status).
		if ( $campaign_type ) {
			// Convert campaign_type to integer format for processing.
			// Frontend may send: "sms" (string), "2" (numeric string), or 2 (integer).
			if ( is_numeric( $campaign_type ) ) {
				$campaign_type_int = (int) $campaign_type;
			} else {
				$campaign_type_int = CampaignChannel::to_integer( $campaign_type );
			}

			// Convert back to string for channel status field lookup.
			$campaign_type_string = CampaignChannel::to_string( $campaign_type_int );

			if ( $campaign_type_string ) {
				// Apply channel-specific status filter (e.g., sms_status = 'subscribed').
				$channel_status_field = $campaign_type_string . '_status';
				$contacts             = $contacts->where( $channel_status_field, 'subscribed' );

				if ( class_exists( '\DoubleScale\Modules\Campaigns\Services\CampaignContactFilter' ) ) {
					$campaign_contact_filter = \DoubleScale\Modules\Campaigns\Services\CampaignContactFilter::instance();
					$contacts                = $campaign_contact_filter->apply_campaign_type_filter( $contacts, $campaign_type_int );
				}
			}
		}

		// Apply WhatsApp phone filter.
		if ( ! is_null( $has_whatsapp_phone ) ) {
			if ( $has_whatsapp_phone ) {
				$contacts = $contacts->whereNotNull( 'whatsapp_phone' )
					->where( 'whatsapp_phone', '!=', '' );
			} else {
				$contacts = $contacts->where(
					function ( $query ) {
						$query->whereNull( 'whatsapp_phone' )
							->orWhere( 'whatsapp_phone', '=', '' );
					}
				);
			}
		}

		// Apply keyword search AFTER filters (search within filtered results).
		if ( '' !== $keywords ) {
			$has_custom_fields = class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' );
			$contacts          = $contacts->where(
				function ( $query ) use ( $keywords, $has_custom_fields ) {
					$query->where( 'first_name', 'like', '%' . $keywords . '%' )
						->orWhere( 'last_name', 'like', '%' . $keywords . '%' )
						->orWhere( 'email', 'like', '%' . $keywords . '%' )
						->orWhere( 'phone', 'like', '%' . $keywords . '%' )
						->orWhere( 'whatsapp_phone', 'like', '%' . $keywords . '%' );

					if ( $has_custom_fields ) {
						$query->orWhereHas(
							'custom_fields',
							function ( $custom_field_query ) use ( $keywords ) {
								$custom_field_query->where( 'value', 'like', '%' . $keywords . '%' );
							}
						);
					}
				}
			);
		}

		return $contacts;
	}

	/**
	 * Coerce the raw `filters` param into an array.
	 *
	 * The param arrives as a nested array, a JSON string, or a stdClass
	 * depending on how the request was encoded.
	 *
	 * @param mixed $filters Raw filters param.
	 *
	 * @return array|null
	 */
	public static function normalize_filters( $filters ) {
		if ( null === $filters || false === $filters || '' === $filters ) {
			return null;
		}
		if ( is_string( $filters ) ) {
			$decoded = json_decode( $filters, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return null;
			}
			$filters = $decoded;
		} elseif ( is_object( $filters ) ) {
			$decoded = json_decode( wp_json_encode( $filters ), true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			$filters = $decoded;
		}
		if ( ! is_array( $filters ) ) {
			return null;
		}
		if ( empty( $filters ) ) {
			return $filters;
		}
		return map_deep(
			$filters,
			static function ( $value ) {
				return $value;
			}
		);
	}
}
