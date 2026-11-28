<?php
/** Native, revisioned website context shared by visitor planning and answers.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed public context adapter.
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;

/** Build a fresh native site profile and load the packaged behavior rules. */
final class VisitorSiteContext {
	/** Public identities for browser synchronization, without schemas or administrator settings. */
	public static function catalog(): array {
		$directories = Operations::public_directories();
		$labels      = array();
		$inspector   = new \DirectoristAIPro\Domain\DirectoristInspector();
		foreach ( $directories as $directory ) {
			$fields                        = $inspector->public_field_labels( (int) $directory['id'] );
			$candidate                     = $labels;
			$candidate[ $directory['id'] ] = (object) $fields;
			if ( strlen( wp_json_encode( (object) $candidate ) ) > 32768 ) {
				continue;
			}
			$labels = $candidate;
		}
		return array(
			'contract'              => 'directorist-ai/visitor-catalog-v1',
			'revision'              => hash( 'sha256', wp_json_encode( $directories ) ),
			'directories'           => $directories,
			'starters'              => StarterSuggestions::cached(),
			'starter_language'      => StarterSuggestions::cached_language(),
			'starters_by_language'  => StarterSuggestions::cached_by_language(),
			'field_labels'          => (object) $labels,
			'field_labels_revision' => hash( 'sha256', wp_json_encode( (object) $labels ) ),
		);
	}

	public static function profile( object $scope ): object {
		$settings    = Operations::settings();
		$directories = Operations::public_directories();
		if ( ! VisitorScope::global( $scope ) && isset( $scope->directory_id ) ) {
			$directories = array_values( array_filter( $directories, static fn( $dir )=>$dir['id'] === (string) $scope->directory_id ) );
		}
		$capabilities = \DirectoristAIPro\Extensions\ExtensionRegistry::profile();
		$currency = function_exists( 'directorist_get_currency' ) ? directorist_get_currency() : '';
		$profile  = (object) array(
			'site_name'        => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'site_description' => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'purpose'          => ( '' !== $settings['chat_purpose'] ) ? $settings['chat_purpose'] : 'Help visitors find relevant public listings across the available directories and use the actions shown on each listing.',
			'tone'             => $settings['chat_tone'],
			'locale'           => \DirectoristAIPro\AI\LanguagePolicy::site_locale(),
			'timezone'         => wp_timezone_string(),
			'local_time'       => wp_date( DATE_ATOM ),
			'currency'         => is_scalar( $currency ) ? (string) $currency : '',
			'directories'      => $directories,
			'capabilities'     => (object) $capabilities,
		);
		$stable   = clone $profile;
		unset( $stable->local_time );
		$profile->revision = hash( 'sha256', wp_json_encode( $stable ) );
		return json_decode( wp_json_encode( $profile ) );
	}
	public static function rules( string $mode = 'plan', ?string $task = null ): array {
		$names = 'write' === $mode ? array( 'core', 'search', 'actions', 'recovery' ) : array( 'core', 'actions', 'recovery' );
		if ( 'write' === $mode && null !== $task ) {
			$names = array( 'core', 'recovery' );
			if ( in_array( $task, array( 'search', 'compare', 'knowledge' ), true ) ) { $names[] = 'search'; }
			if ( 'action' === $task ) { $names[] = 'actions'; }
		}
		$rules = array();
		foreach ( $names as $name ) {
			$path = __DIR__ . '/playbooks/' . $name . '.json';
			$raw  = is_readable( $path ) ? file_get_contents( $path ) : false;
			$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Visitor behavior rules are unavailable.' );
			}$rules[ $name ] = $data;}
		$rules['core']['rules'][] = 'For directory inventory or counts per directory, use the site directories topic without searches. Its native public facts include each available directory count and the total.';
		$rules['core']['rules'][] = 'When the visitor requests counts for each directory, preserve every supplied directory count in the final reply. An unavailable count is unknown, never zero or a complete total.';
		return $rules;
	}
	/** Enrich only public advertised fields with native human labels and bounded taxonomy choices. */
	public static function enrich( array $entries ): array {
		$descriptions = array();
		foreach ( $entries as $entry ) {
			if ( 'directory.describe' === ( $entry->response->operation ?? null ) && true === ( $entry->response->ok ?? false ) ) {
				$descriptions[ $entry->response->data->directory->id ] = $entry->response->data;
			}
		}
		foreach ( $entries as $entry ) {
			if ( 'listing.catalog' !== ( $entry->response->operation ?? null ) || true !== ( $entry->response->ok ?? false ) ) {
				continue;
			}$catalog = $entry->response->data;
			$desc     = $descriptions[ $catalog->directory_id ] ?? null;
			foreach ( $catalog->fields as $field ) {
				foreach ( $desc->fields ?? array() as $native ) {
					if ( $native->field_id === $field->field_id ) {
						$field->label             = $native->label;
						$field->conditional_logic = $native->conditional_logic;
						break;}
				}
				$taxonomy = array(
					'categories' => defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : null,
					'locations'  => defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : null,
					'tags'       => defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : null,
				)[ $field->field_id ] ?? null;
				if ( $taxonomy ) {
					$terms = get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'number'     => 100,
							'meta_query' => array(
								array(
									'key'     => '_directory_type_' . $catalog->directory_id,
									'compare' => 'EXISTS',
								),
							),
						)
					);
					if ( ! is_wp_error( $terms ) ) {
						$field->term_choices = array_map(
							static fn( $term )=>array(
								'id'    => (int) $term->term_id,
								'label' => wp_strip_all_tags( $term->name ),
							),
							$terms
						);
					}
				}
			}
		}return $entries;
	}
}
