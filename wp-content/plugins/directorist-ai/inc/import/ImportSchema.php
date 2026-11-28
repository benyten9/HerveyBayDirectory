<?php
/**
 * Complete selected-directory authority and import dispositions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Directory\DirectorySchema;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Validation\ContractException;

/** Saved/effective native properties remain private and authoritative. */
final class ImportSchema {
	public function __construct( private DirectoristInspector $inspector ) {}

	public function capture( Context $context, int $directory_id ): array {
		$context->assert_current();
		if ( $context->actor()->is_guest() || ! $context->actor()->can( 'manage_options' ) || $directory_id < 1 ) {
			throw new ContractException( 'unauthorized', 'Administrators must select an existing directory.' );
		}
		$term = get_term( $directory_id, ATBDP_DIRECTORY_TYPE );
		if ( ! $term instanceof \WP_Term ) {
			throw new ContractException( 'directory_unavailable', 'The selected directory no longer exists.' ); }
		$saved    = ( new DirectorySchema() )->native( $directory_id );
		$metadata = get_term_meta( $directory_id );
		unset( $metadata['_edit_lock'], $metadata['_edit_last'] );
		$effective   = $this->isolated( static fn() => directorist_get_listing_form_fields( $directory_id ) );
		$eligibility = $this->inspector->creation_eligibility( $context, $directory_id );
		$groups      = array();
		$order       = 0;
		foreach ( $saved['submission_form_fields']['groups'] ?? array() as $group ) {
			foreach ( $group['fields'] ?? array() as $id ) {
				$groups[ (string) $id ] = array(
					'group' => $group['label'] ?? '',
					'order' => $order++,
				); }
		}
		$fields = array();
		$used   = array();
		foreach ( $saved['submission_form_fields']['fields'] ?? array() as $id => $props ) {
			$id     = (string) $id;
			$native = null;
			foreach ( $effective as $key => $candidate ) {
				if ( (string) $key === $id || (string) ( $candidate['widget_key'] ?? '' ) === $id ) {
					if ( null !== $native || isset( $used[ $key ] ) ) {
						throw new ContractException( 'schema_ambiguous', 'Native field identity is ambiguous.' ); }
					$native       = $candidate;
					$used[ $key ] = true;
				}
			}
			$fields[ $id ] = $this->field(
				$id,
				$props,
				$native,
				$groups[ $id ] ?? array(
					'group' => '',
					'order' => $order++,
				)
			);
		}
		foreach ( $effective as $key => $props ) {
			if ( isset( $used[ $key ] ) ) {
				continue; }
			$id = (string) ( $props['widget_key'] ?? $key );
			if ( isset( $fields[ $id ] ) ) {
				throw new ContractException( 'schema_ambiguous', 'An extension field overlaps a saved field identity.' ); }
			$fields[ $id ] = $this->field(
				$id,
				null,
				$props,
				array(
					'group' => '',
					'order' => $order++,
				)
			);
		}
		$settings = array();
		foreach ( $metadata as $key => $values ) {
			$settings[ $key ] = array(
				'provenance'  => 'get_term_meta',
				'disposition' => in_array( $key, DirectorySchema::SETTINGS, true ) ? 'native_directory_behavior' : 'preserved',
				'reason'      => 'Exact saved value retained privately. Import never writes directory settings.',
			);
		}
		foreach ( $saved as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) ) {
				$settings[ $key ] = array(
					'provenance'  => 'DirectorySchema::native effective default',
					'disposition' => 'preserved',
					'reason'      => 'Exact native saved/default value retained.',
				); }
		}
		$behavior = array(
			'new_listing_status' => directorist_get_listing_create_status( $directory_id ),
			'default_expiration' => directorist_get_default_expiration( $directory_id ),
			'terms_required'     => directorist_should_check_terms_and_condition( $directory_id ),
			'privacy_required'   => directorist_should_check_privacy_policy( $directory_id ),
			'eligibility'        => (array) $eligibility,
			'allow_decimal'      => (bool) get_directorist_option( 'allow_decimal', 1 ),
			'currency'           => directorist_get_currency(),
			'pricing_range_choices' => CompositeFields::range_choices( $effective['pricing'] ?? array() ),
		);
		global $wp_version;
		$dependencies = $this->dependencies( $effective );
		$environment  = DirectorySchema::hash( array( Limits::REVISION, $wp_version, PHP_VERSION, ATBDP_VERSION, ( new DirectorySchema() )->environment_hash(), $effective, $behavior, $dependencies ) );
		$revision     = DirectorySchema::hash( array( get_current_blog_id(), $directory_id, $term->name, $metadata, $saved, $effective, $behavior, $environment ) );
		return array(
			'revision'         => $revision,
			'environment_hash' => $environment,
			'dependencies'     => $dependencies,
			'captured_at'      => gmdate( DATE_RFC3339 ),
			'site_id'          => get_current_blog_id(),
			'directory_id'     => $directory_id,
			'name'             => $term->name,
			'saved'            => $saved,
			'saved_metadata'   => $metadata,
			'effective'        => $effective,
			'fields'           => $fields,
			'settings'         => $settings,
			'behavior'         => $behavior,
		);
	}

	private function dependencies( array $effective ): array {
		$files = array();
		foreach ( $effective as $props ) {
			$class = new \ReflectionClass( directorist_get_field( $props ) );
			do {
				$file = $class->getFileName();
				if ( $file && is_file( $file ) ) {
					$files[ $file ] = hash_file( 'sha256', $file );
				} $class = $class->getParentClass();
			} while ( $class );
		}
		foreach ( array( DirectorySchema::class, DirectoristInspector::class, \Directorist\Fields\Conditional_Logic::class, \Directorist\AddListingForm\SubmissionController::class ) as $native_class ) {
			$reflection = new \ReflectionClass( $native_class );
			$file       = $reflection->getFileName();
			if ( $file && is_file( $file ) ) {
				$files[ $file ] = hash_file( 'sha256', $file ); }
		}
		foreach ( array( 'Mapping.php', 'CompositeFields.php', 'ImportSchema.php', 'RecordValidator.php', 'RowEffect.php', 'ImportService.php', 'ImportStore.php' ) as $name ) {
			$files[ 'import:' . $name ] = hash_file( 'sha256', __DIR__ . '/' . $name ); }
		$pricing_template = dirname( rtrim( DIRECTORIST_ASSETS_DIR, '/' ) ) . '/templates/listing-form/fields/pricing.php';
		if ( is_file( $pricing_template ) ) { $files['native:pricing-form'] = hash_file( 'sha256', $pricing_template ); }
		$plugins = array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && ! str_contains( $plugin, '..' ) && is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				$files[ 'plugin:' . $plugin ] = hash_file( 'sha256', WP_PLUGIN_DIR . '/' . $plugin ); }
		}
		ksort( $files );
		return $files;
	}

	private function field( string $id, ?array $saved, ?array $effective, array $position ): array {
		$props   = $effective ?? $saved ?? array();
		$widget  = $props['widget_name'] ?? '';
		$support = 'unsupported';
		$reason  = 'No verified import adapter for this installed field.';
		$adapter = null;
		if ( in_array( $widget, array( 'html', 'button', 'view_count', 'viewcount', 'ai_review_count', 'ai_rating' ), true ) ) {
			$support = 'computed_display';
			$reason  = 'Display or computed data never becomes source facts.'; } elseif ( in_array( $widget, array( 'terms_privacy', 'hide_contact_owner' ), true ) || ! empty( $props['only_for_admin'] ) ) {
			$support = 'policy_protected';
			$reason  = 'Native submission excludes admin fields. Acceptance is an explicit review policy.'; } elseif ( null !== $effective && in_array( $widget, array( 'title', 'description', 'excerpt', 'tagline', 'text', 'textarea', 'email', 'phone', 'phone2', 'fax', 'zip', 'address', 'website', 'url', 'number', 'date', 'time', 'color', 'color_picker', 'select', 'radio', 'checkbox', 'switch', 'category', 'location', 'tag', 'image_upload', 'video' ), true ) ) {
						$type = \Directorist\Fields\Fields::translate_key_to_field( $widget );
				if ( \Directorist\Fields\Fields::exists( $type ) ) {
					$support = 'importable';
					$adapter = 'native:' . $type;
					$reason  = 'Exact effective properties validated and sanitized by the installed field object.'; }
			}
			if ( 'unsupported' === $support && null !== $effective && in_array( $widget, array( 'pricing', 'map' ), true ) && \Directorist\Fields\Fields::exists( $widget ) && empty( $props['only_for_admin'] ) ) {
				$support = 'importable'; $adapter = 'native:' . $widget;
				$reason = 'pricing' === $widget ? 'Saved pricing settings apply. A zero fixed price needs attention because Directorist cannot retain it.' : 'Only supplied coordinates are imported. Map Address separately. Coordinates are never guessed.';
			}
			$provenance = array();
			foreach ( array_unique( array_merge( array_keys( $saved ?? array() ), array_keys( $effective ?? array() ) ) ) as $key ) {
				$provenance[ $key ] = array(
					'saved'       => null !== $saved && array_key_exists( $key, $saved ),
					'effective'   => null !== $effective && array_key_exists( $key, $effective ),
					'evaluator'   => 'importable' === $support && in_array( $key, array( 'required', 'only_for_admin', 'pricing_type', 'options', 'min_value', 'max_value', 'max', 'conditional_logic', 'conditional_logic_data', 'max_image_limit', 'max_per_image_limit', 'max_total_image_limit' ), true ) ? $adapter : null,
					'disposition' => 'preserved_exact',
				); }
			return array_merge(
				array(
					'id'                  => $id,
					'widget'              => $widget,
					'label'               => (string) ( $props['label'] ?? $id ),
					'required'            => ! empty( $props['required'] ),
					'support'             => $support,
					'reason'              => $reason,
					'adapter'             => $adapter,
					'saved_props'         => $saved,
					'props'               => $effective,
					'property_provenance' => $provenance,
				),
				$position
			);
	}

	/** The browser/model receives descriptors, never raw storage identity/settings. */
	public static function descriptors( array $schema ): array {
		$result = array();
		foreach ( $schema['fields'] as $field ) {
			$props                  = $field['props'] ?? $field['saved_props'] ?? array();
			$descriptor             = array_intersect_key( $field, array_flip( array( 'id', 'widget', 'label', 'required', 'support', 'reason', 'adapter', 'group', 'order' ) ) );
			$descriptor['settings'] = array_intersect_key( $props, array_flip( array( 'description', 'help', 'placeholder', 'default_value', 'default', 'min', 'max', 'min_value', 'max_value', 'maxlength', 'rows', 'type', 'multiple', 'options', 'max_image_limit', 'max_per_image_limit', 'max_total_image_limit' ) ) );
			if ( 'pricing' === ( $field['widget'] ?? null ) ) {
				$descriptor['settings'] += array_intersect_key( $props, array_flip( array( 'pricing_type', 'price_unit_field_label', 'price_unit_field_type', 'price_range_label' ) ) );
				$descriptor['settings']['allow_decimal'] = $schema['behavior']['allow_decimal'] ?? true;
				$descriptor['settings']['currency'] = $schema['behavior']['currency'] ?? null;
				$descriptor['components'] = array( array( 'name' => 'type', 'label' => 'Pricing method' ), array( 'name' => 'range', 'label' => 'Price range' ) );
				$descriptor['primary_label'] = 'Price or price range';
				$descriptor['accepted_ranges'] = array( 'skimming', 'moderate', 'economy', 'bellow_economy' );
				$descriptor['range_choices'] = $schema['behavior']['pricing_range_choices'] ?? array();
			} elseif ( 'map' === ( $field['widget'] ?? null ) ) {
				$descriptor['components'] = array( array( 'name' => 'longitude', 'label' => 'Longitude' ), array( 'name' => 'hide_map', 'label' => 'Hide map' ) );
				$descriptor['primary_label'] = 'Latitude';
			}
			// Conditional values may contain internal names; the full graph stays private.
			$descriptor['conditional'] = self::conditional_enabled( $props );
			if ( is_array( $descriptor['settings']['options'] ?? null ) ) {
				unset( $descriptor['settings']['options']['conditional_logic'] );
			}
			$result[] = $descriptor;
		}
		return $result;
	}

	/** Match the installed native evaluator's supported envelopes and enable values. */
	private static function conditional_enabled( array $props ): bool {
		$logic = null;
		if ( ! empty( $props['conditional_logic_data'] ) ) {
			$data  = $props['conditional_logic_data'];
			$logic = is_string( $data ) ? json_decode( $data, true ) : $data;
			$logic = is_array( $logic ) ? $logic : null;
		}
		if ( null === $logic ) {
			$option = $props['options']['conditional_logic'] ?? null;
			if ( ! empty( $option['value'] ) && is_array( $option['value'] ) ) {
				$logic = $option['value'];
			} elseif ( ! empty( $option ) && is_array( $option ) && ! isset( $option['value'] ) ) {
				$logic = $option;
			}
		}
		if ( null === $logic && ! empty( $props['conditional_logic'] ) && is_array( $props['conditional_logic'] ) ) {
			$logic = $props['conditional_logic'];
		}
		return is_array( $logic ) && in_array( $logic['enabled'] ?? false, array( true, 1, '1', 'true' ), true );
	}

	private function isolated( callable $reader ) {
		$get      = $_GET;
		$post     = $_POST;
		$request  = $_REQUEST;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		try {
			return $reader();
		} finally {
			$_GET     = $get;
			$_POST    = $post;
			$_REQUEST = $request; }
	}
}
