<?php
/** Current anonymous native listing facts behind a separate disclosure policy.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Chat\Workflows\PublicListingFacts;
use DirectoristAIPro\Domain\DirectoristInspector;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed native read adapter.

/** Public endpoints always use a guest public projection, including with administrator cookies. */
final class NativeFacts {
	private DirectoristInspector $inspector;

	public function __construct( ?DirectoristInspector $inspector = null ) {
		$this->inspector = $inspector ?? new DirectoristInspector();
	}

	public static function anonymous( callable $read ) {
		$user     = get_current_user_id();
		$get      = $_GET;
		$post     = $_POST;
		$request  = $_REQUEST;
		$cookie   = $_COOKIE;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		try {
			wp_set_current_user( 0 );
			if ( 0 !== get_current_user_id() ) {
				throw new \RuntimeException( 'Anonymous publication identity is unavailable.' );
			}
			$result = $read();
			if ( 0 !== get_current_user_id() ) {
				throw new \RuntimeException( 'Publication execution identity changed.' );
			}
			return $result;
		} finally {
			$_GET     = $get;
			$_POST    = $post;
			$_REQUEST = $request;
			$_COOKIE  = $cookie;
			wp_set_current_user( $user );
		}
	}

	public function context(): Context {
		return new Context( Actor::verified_guest( hash( 'sha256', 'directorist-public-markdown' ), time() + 300 ), 'visitor', 'ui' );
	}

	public function directory( int $directory ): bool {
		wp_cache_delete( $directory, 'terms' );
		wp_cache_delete( $directory, 'term_meta' );
		return $this->inspector->available() && Settings::active( $directory ) && directorist_is_directory( $directory ) && DirectoryReview::fresh( $directory ) && ! get_directorist_option( 'disable_single_listing', false ) && ! self::custom_page( $directory );
	}

	public static function custom_page( int $directory ): bool {
		// Native templates select the custom branch even for a missing/blank page; no fallback is assumed.
		return (bool) get_directorist_type_option( $directory, 'enable_single_listing_page', false );
	}

	public function eligible( int $id ): bool {
		return 'eligible' === $this->eligibility_reason( $id );
	}

	/** Structural diagnostics only; withheld stored values are never returned. */
	public function eligibility_reason( int $id ): string {
		wp_cache_delete( $id, 'posts' );
		wp_cache_delete( $id, 'post_meta' );
		if ( ! $this->inspector->available() ) {
			return 'directorist_unavailable'; }
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type ) {
			return 'listing_unavailable'; }
		if ( 'publish' !== $post->post_status ) {
			return 'listing_not_published'; }
		if ( '' !== $post->post_password ) {
			return 'password_protected'; }
		$directory = (int) directorist_get_listing_directory( $id );
		if ( ! Settings::get()['enabled'] ) {
			return 'export_disabled'; }
		if ( '1' !== (string) get_option( 'blog_public', '1' ) ) {
			return 'site_indexing_disabled'; }
		if ( ! DirectoryReview::settings( $directory )['enabled'] ) {
			return 'directory_not_selected'; }
		if ( ! directorist_is_directory( $directory ) ) {
			return 'directory_unavailable'; }
		if ( get_directorist_option( 'disable_single_listing', false ) ) {
			return 'single_listing_disabled'; }
		if ( self::custom_page( $directory ) ) {
			return 'custom_single_page_visibility_unverified'; }
		if ( ! DirectoryReview::fresh( $directory ) ) {
			return 'directory_review_required'; }
		if ( ! $this->directory( $directory ) ) {
			return 'current_public_visibility_required'; }
		if ( in_array( get_post_meta( $id, '_listing_status', true ), array( 'expired', 'deleted' ), true ) ) {
			return 'listing_expired_or_deleted'; }
		$memberships = wp_get_object_terms(
			$id,
			ATBDP_DIRECTORY_TYPE,
			array(
				'fields'        => 'ids',
				'cache_results' => false,
			)
		);
		if ( is_wp_error( $memberships ) || 1 !== count( $memberships ) || $directory !== (int) $memberships[0] ) {
			return 'directory_identity_unavailable'; }
		$expiry = get_post_meta( $id, '_expiry_date', true );
		if ( ! metadata_exists( 'post', $id, '_never_expire' ) && '' !== $expiry && ( ! is_string( $expiry ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expiry ) || $expiry <= current_time( 'mysql' ) ) ) {
			return 'listing_expired_or_deleted'; }
		return 'eligible';
	}

	public function capture( int $id, ?array &$diagnostics = null ): ?array {
		return self::anonymous(
			function () use ( $id, &$diagnostics ): ?array {
				if ( ! $this->eligible( $id ) ) {
						return null;
				}
				$post      = get_post( $id );
				$directory = (int) directorist_get_listing_directory( $id );
				$context   = $this->context();
				$snapshot  = $this->inspector->description_snapshot( $context, $directory, 'public_search' );
				$facts     = $this->inspector->query_facts( $context, $directory, 'public', array( $id ) );
				if ( ! hash_equals( $snapshot['directory']->schema_hash, $facts->schema_hash ) ) {
					return null;
				}
				$native     = directorist_get_listing_form_fields(
					$directory,
					array(
						'source'           => 'directorist_ai',
						'listing_id'       => $id,
						'listing_owner_id' => (int) $post->post_author,
					)
				);
				$data       = array(
					'listing_id'        => $id,
					'listing_owner_id'  => (int) $post->post_author,
					'directory_type_id' => $directory,
				);
				$layout     = directorist_single_listings_contents( $directory, $data );
				$header     = directorist_single_listing_header( $directory, $data );
				$placements = DisclosurePolicy::placements( $native, $layout, $header );
				$values     = array();
				foreach ( $facts->listings[0]->fields as $fact ) {
					$values[ $fact->field_id ] = $fact;
				}
				$descriptors = array();
				foreach ( $snapshot['directory']->fields as $descriptor ) {
						$descriptors[ $descriptor->field_id ] = $descriptor;
				}
				$allowed = array();
				$posted  = array();
				foreach ( $snapshot['sources'] as $logical => $source ) {
					$key    = $source['source_key'];
					$field  = $native[ $key ] ?? null;
					$reason = ! is_array( $field ) || ! self::source_matches( $field, $source ) ? 'current_public_visibility_required' : ( ! in_array( $field['widget_name'] ?? '', DisclosurePolicy::WIDGETS, true ) ? 'unsupported_public_adapter' : ( in_array( $logical, Settings::excluded( $directory ), true ) ? 'excluded_by_policy' : ( ! isset( $placements[ $key ] ) ? 'absent_from_public_layout' : ( ! isset( $values[ $logical ] ) || ! $values[ $logical ]->known ? 'current_public_value_unavailable' : ( ! $this->plan( $id, $field['widget_name'] ) ? 'plan_permission_unverified' : '' ) ) ) ) );
					if ( '' !== $reason ) {
						if ( null !== $diagnostics ) {
							$diagnostics[ $logical ] = $reason; }
						continue;
					}
					$value = $values[ $logical ]->value;
					// The public generic reader treats checkbox widgets as booleans; native choices are selections.
					if ( 'checkbox' === $field['widget_name'] ) {
						$value = get_post_meta( $id, '_' . sanitize_key( (string) $field['field_key'] ), true );
					}
					$allowed[ $logical ] = array(
						'field'      => $field,
						'placement'  => $placements[ $key ],
						'descriptor' => $descriptors[ $logical ],
						'value'      => $value,
						'source_key' => $key,
					);
					$this->posted( $posted, (string) $key, $field, $value );
				}
				// Remove hidden dependencies as well as their dependent facts; cycles cannot add visibility.
				$condition_rounds = count( $allowed );
				for ( $round = 0; $round <= $condition_rounds; ++$round ) {
					$changed = false;
					foreach ( $allowed as $logical => $item ) {
						$visible = DisclosurePolicy::visible( $item['field'], $posted );
						foreach ( $item['placement'] as $placement ) {
								$visible = $visible && DisclosurePolicy::visible( $placement, $posted );
						}
						if ( ! $visible ) {
							if ( null !== $diagnostics ) {
								$diagnostics[ $logical ] = 'native_condition_not_public'; }
							unset( $allowed[ $logical ] );
							$changed = true;
						}
					}
					if ( ! $changed ) {
						break;
					}
					$posted = array();
					foreach ( $allowed as $item ) {
						$this->posted( $posted, (string) $item['source_key'], $item['field'], $item['value'] );
					}
				}
				if ( ! isset( $allowed['title'] ) || '' === Markdown::plain( (string) $allowed['title']['value'] ) ) {
					return null;
				}
				$export = array();
				foreach ( $allowed as $logical => $item ) {
					$value = $this->display_value( $id, $item );
					if ( null !== $value && '' !== $value && array() !== $value ) {
						if ( null !== $diagnostics ) {
							$diagnostics[ $logical ] = 'published'; }
						$export[] = array(
							'id'    => $logical,
							'label' => $item['descriptor']->label ? $item['descriptor']->label : ucfirst( $logical ),
							'value' => $value,
						);
					} elseif ( null !== $diagnostics ) {
						$diagnostics[ $logical ] = 'public_value_not_representable';
					}
				}
				$this->extensions( $id, $directory, $native, $layout, $posted, $export );
				$canonical = get_permalink( $id );
				if ( ! Markdown::url( $canonical ) || ! $this->eligible( $id ) || (int) directorist_get_listing_directory( $id ) !== $directory ) {
					return null;
				}
				$result = array(
					'id'             => $id,
					'directory_id'   => $directory,
					'directory_name' => $snapshot['directory']->directory->name,
					'title'          => $allowed['title']['value'],
					'canonical'      => $canonical,
					'modified_gmt'   => $facts->listings[0]->version->modified_gmt,
					'fields'         => $export,
				);
				// Revisions are internal; only public facts and native source modification times enter Markdown.
				$result['revision'] = hash( 'sha256', serialize( array( Settings::REVISION, DirectoryReview::policy_hash( $directory ), $snapshot['directory']->schema_hash, $native, $layout, $header, $result ) ) );
				return $result;
			}
		);
	}

	private static function source_matches( array $field, array $source ): bool {
		// A per-listing filter may hide a field, but cannot relabel values read from another native storage identity.
		foreach ( array( 'widget_name', 'field_key', 'type', 'only_for_admin' ) as $key ) {
			$current = array(
				'present' => array_key_exists( $key, $field ),
				'type'    => gettype( $field[ $key ] ?? null ),
				'value'   => $field[ $key ] ?? null,
			);
			if ( ! isset( $source[ $key ] ) || $current !== $source[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	private function plan( int $id, string $widget ): bool {
		if ( ! function_exists( 'is_fee_manager_active' ) ) {
			return false;
		}
		if ( ! is_fee_manager_active() || in_array( $widget, array( 'title', 'description', 'category', 'location', 'tag' ), true ) ) {
			return true;
		}
		// Only this installed native permission contract is verified. Other fee-gated widgets are withheld.
		$flags = array( 'pricing' => 'is_plan_allowed_price' );
		$flag  = $flags[ $widget ] ?? '';
		$plan  = (int) get_post_meta( $id, '_fm_plans', true );
		return $plan > 0 && $flag && function_exists( $flag ) && (bool) $flag( $plan );
	}

	private function posted( array &$posted, string $key, array $field, $value ): void {
		$widget             = $field['widget_name'];
		$storage            = (string) ( $field['field_key'] ?? $key );
		$posted[ $key ]     = $value;
		$posted[ $storage ] = $value;
		if ( in_array( $widget, array( 'title', 'description', 'excerpt', 'category', 'location', 'tag', 'pricing', 'map', 'phone', 'phone2', 'email', 'website', 'address', 'zip', 'fax' ), true ) ) {
			$posted[ $widget ] = $value;
		}
		$posted['custom_field'][ $storage ] = $value;
		$taxonomies                         = array(
			'category' => ATBDP_CATEGORY,
			'location' => ATBDP_LOCATION,
			'tag'      => ATBDP_TAGS,
		);
		if ( isset( $taxonomies[ $widget ] ) ) {
			$ids = array_map( static fn( $term ): int => (int) $term->id, is_array( $value ) ? $value : array() );
			$posted['tax_input'][ $taxonomies[ $widget ] ] = $ids;
			$posted[ $key ]                                = $ids;
			$posted[ $storage ]                            = $ids;
			$posted[ $widget ]                             = $ids;
			$posted[ array(
				'category' => 'categories',
				'location' => 'locations',
				'tag'      => 'tags',
			)[ $widget ] ]                                 = $ids;
		}
	}

	private function display_value( int $id, array $item ) {
		$widget = $item['field']['widget_name'];
		$value  = $item['value'];
		if ( in_array( $widget, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$choices = $item['field']['options'] ?? $item['field']['choices'] ?? $item['field']['select_options'] ?? null;
			if ( ! is_array( $choices ) || ! $choices || count( $choices ) > 1000 ) {
				return null;
			}
			$selected = is_array( $value ) ? $value : ( 'checkbox' === $widget ? explode( ',', (string) $value ) : array( $value ) );
			$labels   = array();
			foreach ( $choices as $choice_key => $choice ) {
				$stored_value = is_array( $choice ) ? ( $choice['value'] ?? $choice['option_value'] ?? $choice_key ) : ( is_int( $choice_key ) ? $choice : $choice_key );
				$label        = is_array( $choice ) ? ( $choice['label'] ?? $choice['option_label'] ?? null ) : $choice;
				if ( ! is_scalar( $stored_value ) || ! is_scalar( $label ) ) {
					return null;
				}
				foreach ( $selected as $stored ) {
					if ( is_scalar( $stored ) && (string) $stored === (string) $stored_value ) {
						$labels[] = $label;
					}
				}
			}
			return $labels ? $labels : null;
		}
		if ( in_array( $widget, array( 'category', 'location', 'tag' ), true ) ) {
			return array_map( static fn( $term ): string => $term->name, is_array( $value ) ? $value : array() );
		}
		if ( 'pricing' === $widget ) {
			if ( ! is_object( $value ) || 'price' !== $value->type || ! is_numeric( $value->price ) || ! is_finite( (float) $value->price ) || (float) $value->price < 0 ) {
				return null;
			}
			$currency = function_exists( 'directorist_get_currency' ) ? directorist_get_currency() : '';
			return preg_match( '/^[A-Z]{3}$/D', (string) $currency ) ? (string) $value->price . ' ' . $currency : null;
		}
		if ( 'map' === $widget ) {
			if ( ! is_object( $value ) || $value->hide_map || ! is_numeric( $value->latitude ) || ! is_numeric( $value->longitude ) || abs( (float) $value->latitude ) > 90 || abs( (float) $value->longitude ) > 180 ) {
				return null;
			}
			return 'Latitude: ' . $value->latitude . '; Longitude: ' . $value->longitude;
		}
		return is_scalar( $value ) && ( ! is_float( $value ) || is_finite( $value ) ) ? $value : null;
	}

	private function extensions( int $id, int $directory, array $native, array $layout, array $posted, array &$export ): void {
		$definitions = PublicListingFacts::definitions( $directory, $native );
		foreach ( array(
			'hours' => 'business_hours',
			'faqs'  => 'faqs',
		) as $logical => $widget ) {
			if ( ! isset( $definitions[ 'directorist_ai_' . $logical ] ) || in_array( $logical, Settings::excluded( $directory ), true ) ) {
				continue;
			}
			$visible = false;
			foreach ( (array) ( $layout['groups'] ?? array() ) as $group ) {
				if ( 'general_group' !== ( $group['type'] ?? '' ) || ! DisclosurePolicy::visible( $group, $posted ) ) {
					continue;
				}
				foreach ( (array) ( $group['fields'] ?? array() ) as $key ) {
					$field = $layout['fields'][ $key ] ?? array();
					if ( ( $field['widget_name'] ?? '' ) === $widget && DisclosurePolicy::visible( $field, $posted ) && ( 'other_widgets' === ( $field['widget_group'] ?? '' ) || ( isset( $native[ $field['original_widget_key'] ?? '' ] ) && DisclosurePolicy::visible( $native[ $field['original_widget_key'] ], $posted ) ) ) ) {
						$visible = true;
					}
				}
			}
			if ( $visible ) {
				$fact = PublicListingFacts::fact( $id, 'ai_' . $logical );
				if ( $fact['known'] ) {
					$value = $fact['value'];
					if ( 'hours' === $logical ) {
						// Dynamic open-now predictions are excluded; retain the native schedule and zone.
						$value = (object) array(
							'timezone'    => $value->timezone,
							'always_open' => $value->always_open,
							'schedule'    => $value->schedule,
						);
					}
					$export[] = array(
						'id'    => $logical,
						'label' => 'hours' === $logical ? 'Business hours' : 'FAQs',
						'value' => $value,
					);
				}
			}
		}
	}
}
