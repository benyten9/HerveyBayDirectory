<?php
/**
 * Compile bounded directory proposals from the installed native definitions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Directory;

use Directorist\Multi_Directory\Multi_Directory_Manager;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed native schema adapter.
/** Native Directory Builder AI integration. */
final class DirectorySchema {
	public const REVISION = 'directory-blueprint-v3';
	public const SECTIONS = array( 'submission_form_fields', 'search_form_fields', 'single_listings_contents', 'single_listing_header', 'listings_card_grid_view', 'listings_card_list_view' );
	public const SETTINGS = array( 'enable_sidebar', 'preview_mode', 'default_expiration', 'new_listing_status', 'edit_listing_status' );
	private array $definitions;

	public function __construct() {
		Multi_Directory_Manager::load_builder_data();
		$this->definitions = Multi_Directory_Manager::$fields;
	}

	public function environment_hash(): string {
		$definitions = array_intersect_key( $this->definitions, array_flip( array_merge( self::SECTIONS, self::SETTINGS ) ) );
		// Extensions can register nested review/author widgets only in admin requests.
		// These preserved areas are not editable by this contract; their presentation
		// metadata must not invalidate REST captures when the admin worker executes.
		foreach ( array( 'search_form_fields', 'single_listings_contents' ) as $section ) {
			unset( $definitions[ $section ]['widgets']['other_widgets'] );
		}
		return self::hash( array( self::REVISION, ATBDP_VERSION, $definitions, $this->creation_defaults() ) );
	}

	/** Seed only installed native controls from the trusted bundled defaults. */
	public function creation_defaults(): array {
		$defaults = json_decode( file_get_contents( DIRECTORIST_ASSETS_DIR . 'sample-data/directory/directory.json' ), true );
		$keys     = array_merge( self::SECTIONS, self::SETTINGS, array( 'icon', 'preview_image', 'global_listing_type', 'submit_button_label', 'enable_single_listing_page', 'single_listing_page', 'single_listing_page_title', 'enable_schema_markup', 'apply_schema_markup', 'directory_schema_type_global', 'guest_email_label', 'guest_email_placeholder' ) );
		return array_intersect_key( is_array( $defaults ) ? $defaults : array(), array_flip( $keys ) );
	}

	public static function hash( $value ): string {
		$sort = static function ( $value ) use ( &$sort ) {
			if ( is_object( $value ) ) {
				$value = get_object_vars( $value ); }
			if ( is_array( $value ) ) {
				if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					ksort( $value ); }
				foreach ( $value as &$item ) {
					$item = $sort( $item ); }
			}
			return $value;
		};
		return hash( 'sha256', wp_json_encode( $sort( $value ), JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Read directory-owned values for the model and editor adapter.
	 *
	 * @throws ContractException When native defaults are unavailable.
	 */
	public function native( int $id ): array {
		$defaults = json_decode( file_get_contents( DIRECTORIST_ASSETS_DIR . 'sample-data/directory/directory.json' ), true );
		if ( ! is_array( $defaults ) ) {
			throw new ContractException( 'directory_unavailable', 'Native directory defaults are unavailable.' ); }
		$config = array();
		foreach ( array_merge( self::SECTIONS, self::SETTINGS ) as $key ) {
			if ( ! isset( $this->definitions[ $key ] ) ) {
				continue; }
			$config[ $key ] = $id && metadata_exists( 'term', $id, $key ) ? get_term_meta( $id, $key, true ) : ( $defaults[ $key ] ?? $this->definitions[ $key ]['value'] ?? null );
		}
		$term           = $id ? get_term( $id, ATBDP_DIRECTORY_TYPE ) : null;
		$config['name'] = $term instanceof \WP_Term ? $term->name : '';
		if ( ! $id ) {
			foreach ( $config['submission_form_fields']['fields'] as &$field ) {
				if ( 'custom' === ( $field['widget_group'] ?? '' ) && empty( $field['label'] ) ) {
					$field['label'] = 'textarea' === $field['widget_name'] ? 'Additional details' : ucfirst( str_replace( '_', ' ', $field['widget_name'] ) ); }
			}
			unset( $field );
		}
		return $config;
	}

	public function source_hash( int $id ): string {
		return self::hash( $id ? $this->native( $id ) : array( 'new_directory' => true ) );
	}

	private function prototype( string $section, string $widget ): array {
		foreach ( $this->definitions[ $section ]['widgets'] ?? array() as $group => $definition ) {
			if ( isset( $definition['widgets'][ $widget ] ) ) {
				return array( $group, $definition['widgets'][ $widget ] );
			}
		}
		throw new ContractException( 'unsupported_directory_field', 'This field is not supported in the selected directory area: ' . $widget . '.' );
	}

	private function defaults( array $definition ): array {
		$result = array();
		foreach ( $definition as $key => $option ) {
			if ( is_array( $option ) && array_key_exists( 'value', $option ) ) {
				$result[ $key ] = $option['value']; }
		}
		return $result;
	}

	public function catalog( array $config ): array {
		$widgets = array();
		foreach ( array( 'preset', 'custom' ) as $group ) {
			foreach ( $this->definitions['submission_form_fields']['widgets'][ $group ]['widgets'] ?? array() as $id => $definition ) {
				// Executable HTML, buttons and uploads require their own specialized contracts.
				if ( in_array( $id, array( 'html', 'button', 'file', 'terms_privacy', 'booking' ), true ) ) {
					continue; }
				$widgets[] = array(
					'widget'   => $id,
					'label'    => wp_strip_all_tags( $definition['label'] ?? $id ),
					'custom'   => 'custom' === $group,
					'controls' => array_values( array_intersect( array_keys( $definition['options'] ?? array() ), array( 'label', 'placeholder', 'required', 'options' ) ) ),
					'search'   => $this->supports( 'search_form_fields', $id ),
					'single'   => $this->supports( 'single_listings_contents', $id ) || isset( $this->definitions['single_listing_header']['widgets'][ $this->header_widget( $id ) ] ),
					'card'     => $this->card_supported( $id, $config ),
					'options'  => isset( $definition['options']['options'] ),
				);
			}
		}
		return array(
			'revision'     => self::REVISION,
			'widgets'      => $widgets,
			'current'      => $this->summary( $config ),
			'settings'     => $this->setting_catalog(),
			'instructions' => 'Create mode also contains the listed seeded fields: their keys and widget types remain reserved. Fields are additive updates. Existing field keys and types cannot change. Null properties preserve current values. Use existing keys for preset fields. Search/single/card capabilities describe supported native additions. Unsupported optional placements are preserved unchanged and disclosed in the review; they do not discard supported form changes. Explicit card:false may remove an existing matching placement even when new card additions are unavailable. Existing choice values cannot be removed. Global settings, PHP, SQL, markup, field deletion and type conversion are outside this contract.',
		);
	}

	private function supports( string $section, string $widget ): bool {
		try {
			$this->prototype( $section, $widget );
			return true;
		} catch ( ContractException $error ) {
			return false; }
	}

	/** Optional display placement never authorizes a new native widget/control. */
	private function placement_supported( string $widget, string $area, array $config ): bool {
		if ( 'card' === $area ) {
			return $this->card_supported( $widget, $config ); }
		if ( 'search' === $area ) {
			return $this->supports( 'search_form_fields', $widget ); }
		return $this->supports( 'single_listings_contents', $widget ) || isset( $this->definitions['single_listing_header']['widgets'][ $this->header_widget( $widget ) ] );
	}

	/** Explain skipped optional placements using the same native availability check. */
	public function placement_omissions( array $base, object $proposal ): array {
		$omissions = array();
		foreach ( $proposal->fields as $field ) {
			list( , $definition ) = $this->prototype( 'submission_form_fields', $field->widget );
			foreach ( array( 'label', 'placeholder' ) as $property ) {
				if ( null !== ( $field->$property ?? null ) && ! isset( $definition['options'][ $property ] ) && ( $base['submission_form_fields']['fields'][ $field->key ][ $property ] ?? '' ) !== $field->$property ) {
					$omissions[] = (object) array(
						'key'   => $field->key,
						'label' => $field->label ?? $base['submission_form_fields']['fields'][ $field->key ]['label'] ?? $field->widget,
						'area'  => $property,
					);
				}
			}
			foreach ( array( 'search', 'single', 'card' ) as $area ) {
				if ( true === ( $field->$area ?? null ) && ! $this->placement_supported( $field->widget, $area, $base ) ) {
					$omissions[] = (object) array(
						'key'   => $field->key,
						'label' => $field->label ?? $base['submission_form_fields']['fields'][ $field->key ]['label'] ?? $field->widget,
						'area'  => $area,
					);
				}
			}
		}
		return $omissions;
	}

	private function setting_catalog(): array {
		$result = array();
		foreach ( self::SETTINGS as $key ) {
			$d = $this->definitions[ $key ] ?? null;
			if ( ! $d ) {
				continue; }
			$result[] = array(
				'key'     => $key,
				'type'    => $d['type'] ?? '',
				'choices' => array_values( array_filter( array_map( static fn( $option ) => $option['value'] ?? null, $d['options'] ?? array() ), static fn( $value ) => null !== $value ) ),
				'min'     => 'number' === ( $d['type'] ?? '' ) ? ( $d['min'] ?? 0 ) : null,
				'max'     => 'number' === ( $d['type'] ?? '' ) ? ( $d['max'] ?? 3650 ) : null,
			);
		}
		return $result;
	}

	public function summary( array $config ): array {
		$groups  = array();
		$ordered = array();
		foreach ( $config['submission_form_fields']['groups'] ?? array() as $group ) {
			foreach ( $group['fields'] ?? array() as $position => $id ) {
				$groups[ $id ]  = $group['label'] ?? '';
				$ordered[ $id ] = $position;
			}
		}
		$fields = array();
		foreach ( $ordered as $id => $position ) {
			$field    = $config['submission_form_fields']['fields'][ $id ];
			$fields[] = array(
				'key'         => (string) $id,
				'widget'      => $field['widget_name'] ?? '',
				'label'       => $field['label'] ?? $id,
				'group'       => $groups[ $id ] ?? '',
				'position'    => $position + 1,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => $field['placeholder'] ?? '',
				'options'     => array_map(
					static fn( $option ) => array(
						'value' => $option['option_value'] ?? '',
						'label' => $option['option_label'] ?? '',
					),
					$field['options'] ?? array()
				),
				'search'      => isset( $config['search_form_fields']['fields'][ $id ] ),
				'single'      => isset( $config['single_listings_contents']['fields'][ $id ] ) || $this->contains_widget( $config['single_listing_header'] ?? array(), $this->header_widget( $field['widget_name'] ?? '' ) ),
				'card'        => $this->contains_reference( $config['listings_card_grid_view'] ?? array(), (string) $id ),
			);
		}
		return array(
			'name'     => $config['name'] ?? '',
			'fields'   => $fields,
			'settings' => array_intersect_key( $config, array_flip( self::SETTINGS ) ),
		);
	}

	private function text( $value, int $max = 200 ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max || wp_strip_all_tags( $value ) !== $value || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value ) ) {
			throw new ContractException( 'invalid_directory_proposal', 'Directory labels must be bounded plain text.' ); }
		return sanitize_text_field( $value );
	}

	public function compile( array $base, object $proposal, bool $editing ): array {
		if ( array_diff( array_keys( get_object_vars( $proposal ) ), array( 'name', 'fields', 'settings', 'order' ) ) || ! is_array( $proposal->fields ?? null ) || count( $proposal->fields ) > 30 || ! is_array( $proposal->settings ?? null ) || count( $proposal->settings ) > count( self::SETTINGS ) ) {
			throw new ContractException( 'invalid_directory_proposal', 'Directory proposal is outside the supported schema.' ); }
		$config = $base;
		if ( null !== ( $proposal->name ?? null ) ) {
			$config['name'] = $this->text( $proposal->name, 100 ); }
		if ( '' === trim( $config['name'] ?? '' ) ) {
			throw new ContractException( 'invalid_directory_proposal', 'Give this directory a name.' ); }
		$seen = array();
		foreach ( $proposal->fields as $change ) {
			if ( ! $change instanceof \stdClass || array_diff( array_keys( get_object_vars( $change ) ), array( 'key', 'widget', 'label', 'placeholder', 'group', 'required', 'options', 'search', 'single', 'card' ) ) || ! is_string( $change->key ?? null ) || ! is_string( $change->widget ?? null ) || isset( $seen[ $change->key ] ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'Directory fields need unique native references.' ); }
			$key                               = $change->key;
			$seen[ $key ]                      = true;
			list( $widget_group, $definition ) = $this->prototype( 'submission_form_fields', $change->widget );
			if ( in_array( $change->widget, array( 'html', 'button', 'file', 'terms_privacy', 'booking' ), true ) ) {
				throw new ContractException( 'unsupported_directory_field', 'This field needs a specialized contract. Configure it in the native builder.' ); }
			$old = $config['submission_form_fields']['fields'][ $key ] ?? null;
			if ( $old && ( $old['widget_name'] ?? null ) !== $change->widget ) {
				throw new ContractException( 'directory_data_protected', 'Existing field types cannot be changed by AI.' ); }
			if ( ! $old && ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $key ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'New field references must use a short lowercase identifier.' ); }
			if ( ! $old && 'preset' === $widget_group ) {
				foreach ( $config['submission_form_fields']['fields'] as $existing_key => $field ) {
					if ( $field['widget_name'] === $change->widget ) {
						throw new ContractException( 'invalid_directory_proposal', 'Use the existing preset field key: ' . $existing_key . '.' ); }
				}
			}
			$field = $old ?? $this->defaults( $definition['options'] ?? array() );
			if ( ! $old ) {
				$field['widget_group'] = $widget_group;
				$field['widget_name']  = $change->widget;
				$field['widget_key']   = $key;
				if ( 'custom' === $widget_group ) {
					$field['field_key'] = 'dai_' . str_replace( '-', '_', $key ); }
			}
			foreach ( array( 'label', 'placeholder' ) as $property ) {
				if ( null !== ( $change->$property ?? null ) ) {
					$current_value = $field[ $property ] ?? '';
					if ( $change->$property === $current_value ) {
						continue; }
					if ( ! isset( $definition['options'][ $property ] ) ) {
						$this->text( $change->$property );
						continue;
					}
					$field[ $property ] = $this->text( $change->$property );
				}
			}
			$current_required = ! empty( $field['required'] );
			if ( null !== ( $change->required ?? null ) && $change->required !== $current_required ) {
				if ( ! is_bool( $change->required ) || ! isset( $definition['options']['required'] ) || ( 'title' === $change->widget && ! $change->required ) ) {
					throw new ContractException( 'invalid_directory_proposal', 'This required-field change is unsupported.' ); }
				$field['required'] = $change->required;
			}
			if ( null !== ( $change->options ?? null ) && ! ( array() === $change->options && ! in_array( $change->widget, array( 'select', 'checkbox', 'radio' ), true ) ) ) {
				if ( ! in_array( $change->widget, array( 'select', 'checkbox', 'radio' ), true ) || ! is_array( $change->options ) || ! $change->options || count( $change->options ) > 30 ) {
					throw new ContractException( 'invalid_directory_proposal', 'Choice fields require one to thirty supported options.' ); }
				$options     = array();
				$old_options = $field['options'] ?? array();
				foreach ( $change->options as $label ) {
					$label = $this->text( $label, 100 );
					$value = sanitize_title( $label );
					foreach ( $old_options as $old_option ) {
						if ( $old_option['option_label'] === $label ) {
							$value = $old_option['option_value']; }
					}
					if ( '' === $value || isset( $options[ $value ] ) ) {
						throw new ContractException( 'invalid_directory_proposal', 'Choice values must be nonempty and unique.' ); }
					$options[ $value ] = array(
						'option_value' => $value,
						'option_label' => $label,
					);
				}
				foreach ( $old_options as $old_option ) {
					if ( $editing && ! isset( $options[ $old_option['option_value'] ] ) ) {
						throw new ContractException( 'directory_data_protected', 'Existing choice values cannot be removed. Add options or use the native builder.' ); }
				}
				$field['options'] = array_values( $options );
			}
			if ( in_array( $change->widget, array( 'select', 'checkbox', 'radio' ), true ) && empty( $field['options'] ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'This choice field needs options.' ); }
			$config['submission_form_fields']['fields'][ $key ] = $field;
			$this->place_form_field( $config, $key, $change->group ?? null );
			foreach ( array( 'search', 'single', 'card' ) as $area ) {
				if ( null !== ( $change->$area ?? null ) ) {
					if ( ! is_bool( $change->$area ) ) {
						throw new ContractException( 'invalid_directory_proposal', 'Display and search controls require boolean values.' ); }
					if ( $change->$area && ! $this->placement_supported( $change->widget, $area, $config ) ) {
						// Retain the exact existing placement; disclose the omission in
						// the reviewed artifact instead of inventing a native control.
						continue;
					}
					$this->place_field( $config, $key, $field, $area, $change->$area );
				}
			}
			// Labels/options follow their stable submission-field reference.
			if ( isset( $config['search_form_fields']['fields'][ $key ] ) ) {
				$config['search_form_fields']['fields'][ $key ]['label'] = $field['label'] ?? $key;
			}
		}
		$setting_keys = array();
		foreach ( $proposal->settings as $setting ) {
			if ( ! $setting instanceof \stdClass || array_diff( array_keys( get_object_vars( $setting ) ), array( 'key', 'value' ) ) || ! in_array( $setting->key ?? null, self::SETTINGS, true ) || ! is_string( $setting->value ?? null ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'Only supported directory settings can change.' ); }
			if ( isset( $setting_keys[ $setting->key ] ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'Each setting can change only once in a proposal.' ); }
			$setting_keys[ $setting->key ] = true;
			$value                         = json_decode( $setting->value, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$value = $setting->value; }
			$d = $this->definitions[ $setting->key ] ?? array();
			if ( 'toggle' === ( $d['type'] ?? '' ) ) {
				if ( in_array( $value, array( 0, 1 ), true ) ) {
					$value = (bool) $value; }
				if ( ! is_bool( $value ) ) {
					throw new ContractException( 'invalid_directory_proposal', 'Toggle settings require true or false.' ); }
			} elseif ( isset( $d['options'] ) ) {
				$allowed = array_column( $d['options'], 'value' );
				if ( ! in_array( $value, $allowed, true ) ) {
					throw new ContractException( 'invalid_directory_proposal', 'The setting value is not available in Directorist.' ); }
			} elseif ( ! is_int( $value ) || $value < ( $d['min'] ?? 0 ) || $value > ( $d['max'] ?? 3650 ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'The numeric setting is outside its native range.' ); }
			$config[ $setting->key ] = $value;
		}
		if ( null !== ( $proposal->order ?? null ) ) {
			if ( ! is_array( $proposal->order ) || count( array_filter( $proposal->order, 'is_string' ) ) !== count( $proposal->order ) || count( $proposal->order ) !== count( array_unique( $proposal->order ) ) || array_diff( $proposal->order, array_keys( $config['submission_form_fields']['fields'] ) ) ) {
				throw new ContractException( 'invalid_directory_proposal', 'Field order references unknown fields.' ); }
			foreach ( $config['submission_form_fields']['groups'] as &$group ) {
				$group['fields'] = array_values( array_merge( array_intersect( $proposal->order, $group['fields'] ), array_diff( $group['fields'], $proposal->order ) ) );
			}
			unset( $group );
		}
		// Remove sections emptied by these moves; retain pre-existing empty drafts.
		$config['submission_form_fields']['groups'] = array_values( array_filter( $config['submission_form_fields']['groups'], static fn( $row, $index ): bool => ! empty( $row['fields'] ) || empty( $base['submission_form_fields']['groups'][ $index ]['fields'] ), ARRAY_FILTER_USE_BOTH ) );
		$this->validate( $config );
		return $this->normalize( $config );
	}

	public function normalize( array $config ): array {
		foreach ( $config as $key => &$value ) {
			if ( 'name' === $key ) {
				continue; }
			if ( 'toggle' === ( $this->definitions[ $key ]['type'] ?? '' ) ) {
				$value = in_array( $value, array( true, 1, '1', 'true' ), true ) ? true : 0;
			}
			$value = directorist_maybe_json( $value, false, 'directorist_clean_post' );
		}
		return $config;
	}

	private function place_form_field( array &$config, string $key, $label ): void {
		$groups  = &$config['submission_form_fields']['groups'];
		$current = null;
		foreach ( $groups as $index => $group ) {
			if ( in_array( $key, $group['fields'], true ) ) {
				$current = $index;
				break; }
		}
		if ( null === $label && null !== $current ) {
			return; }
		if ( null !== $current && $label === $groups[ $current ]['label'] ) {
			return; }
		$label = null === $label ? 'Business details' : $this->text( $label, 100 );
		if ( '' === $label ) {
			throw new ContractException( 'invalid_directory_proposal', 'Form sections need a label.' ); }
		$destination = null;
		foreach ( $groups as $index => &$group ) {
			$group['fields'] = array_values( array_diff( $group['fields'], array( $key ) ) );
			if ( $label === $group['label'] ) {
				$destination = $index; }
		}
		unset( $group );
		if ( null === $destination ) {
			if ( count( $groups ) >= 20 ) {
				throw new ContractException( 'invalid_directory_proposal', 'This directory already has the supported number of sections.' ); }
			$groups[]    = array(
				'type'   => 'general_group',
				'label'  => $label,
				'icon'   => 'las la-list',
				'fields' => array(),
				'plans'  => array(),
			);
			$destination = count( $groups ) - 1;
		}
		$groups[ $destination ]['fields'][] = $key;
	}

	private function header_widget( string $widget ): string {
		return array(
			'pricing'      => 'price',
			'image_upload' => 'slider',
		)[ $widget ] ?? $widget;
	}

	private function place_field( array &$config, string $key, array $field, string $area, bool $enabled ): void {
		$widget = $field['widget_name'];
		// An explicit false on an unavailable placement is an unchanged absence.
		if ( ! $enabled && ( ( 'search' === $area && ! $this->supports( 'search_form_fields', $widget ) ) || ( 'single' === $area && ! $this->supports( 'single_listings_contents', $widget ) && ! isset( $this->definitions['single_listing_header']['widgets'][ $this->header_widget( $widget ) ] ) ) ) ) {
			return; }
		if ( 'card' === $area ) {
			$this->place_card( $config, $key, $widget, $enabled );
			return; }
		if ( 'single' === $area && ! $this->supports( 'single_listings_contents', $widget ) ) {
			$header = $this->header_widget( $widget );
			if ( ! isset( $this->definitions['single_listing_header']['widgets'][ $header ] ) ) {
				throw new ContractException( 'unsupported_directory_field', 'This field has no native single-listing display.' ); }
			$this->header_visibility( $config['single_listing_header'], $header, $enabled );
			return;
		}
		$section                    = 'search' === $area ? 'search_form_fields' : 'single_listings_contents';
		list( $group, $definition ) = $this->prototype( $section, $widget );
		if ( ! is_array( $config[ $section ] ) ) {
			$config[ $section ] = array(
				'fields' => array(),
				'groups' => array(),
			); }
		if ( ! $enabled ) {
			unset( $config[ $section ]['fields'][ $key ] );
			foreach ( $config[ $section ]['groups'] as &$row ) {
				$row['fields'] = array_values( array_diff( $row['fields'] ?? array(), array( $key ) ) ); }
			return;
		}
		$config[ $section ]['fields'][ $key ] = ( $config[ $section ]['fields'][ $key ] ?? $this->defaults( $definition['options'] ?? array() ) ) + array(
			'widget_group'        => $group,
			'widget_name'         => $widget,
			'widget_key'          => $key,
			'original_widget_key' => $key,
		);
		if ( 'search' === $area ) {
			$config[ $section ]['fields'][ $key ]['label'] = $field['label'] ?? $key; }
		foreach ( $config[ $section ]['groups'] as &$row ) {
			if ( in_array( $key, $row['fields'] ?? array(), true ) ) {
				if ( 'search' === $area || 'general_group' === ( $row['type'] ?? '' ) ) {
					return; }
				$row['fields'] = array_values( array_diff( $row['fields'], array( $key ) ) );
			}
		}
		unset( $row );
		if ( 'single' === $area ) {
			$label = 'Business details';
			foreach ( $config['submission_form_fields']['groups'] as $form_group ) {
				if ( in_array( $key, $form_group['fields'], true ) ) {
					$label = $form_group['label'];
					break; }
			}
			foreach ( $config[ $section ]['groups'] as $index => $row ) {
				$row_label = $row['label'] ?? '';
				if ( 'general_group' === ( $row['type'] ?? '' ) && $label === $row_label ) {
					$config[ $section ]['groups'][ $index ]['fields'][] = $key;
					return; }
			}
			$ids         = array_map( 'intval', array_column( $config[ $section ]['groups'], 'section_id' ) );
			$new_group   = array(
				'type'       => 'general_group',
				'label'      => $label,
				'icon'       => 'las la-list',
				'section_id' => (string) ( $ids ? max( $ids ) + 1 : 1 ),
				'fields'     => array( $key ),
			);
			$destination = count( $config[ $section ]['groups'] );
			foreach ( $config[ $section ]['groups'] as $index => $row ) {
				if ( 'section' === ( $row['type'] ?? '' ) ) {
					$destination = $index;
					break; }
			}
			array_splice( $config[ $section ]['groups'], $destination, 0, array( $new_group ) );
			return;
		}
		if ( ! $config[ $section ]['groups'] ) {
			$config[ $section ]['groups'][] = array(
				'type'       => 'general_group',
				'label'      => 'search' === $area ? 'Search Bar' : 'Business details',
				'icon'       => 'las la-list',
				'section_id' => '9001',
				'fields'     => array(),
			); }
		$index = 'search' === $area && 'title' !== $widget && count( $config[ $section ]['groups'] ) > 1 ? 1 : count( $config[ $section ]['groups'] ) - 1;
		$config[ $section ]['groups'][ $index ]['fields'][] = $key;
	}

	private function header_visibility( array &$nodes, string $widget, bool $enabled ): void {
		foreach ( $nodes as &$node ) {
			if ( isset( $node['placeholders'] ) ) {
				$this->header_visibility( $node['placeholders'], $widget, $enabled ); }
			if ( isset( $node['selectedWidgets'] ) ) {
				$found = array_filter( $node['selectedWidgets'], static fn( $item ) => $widget === $item['widget_name'] );
				if ( ! $enabled ) {
					$node['selectedWidgets'] = array_values( array_filter( $node['selectedWidgets'], static fn( $item ) => $widget !== $item['widget_name'] ) ); } elseif ( ! $found ) {
					foreach ( $this->definitions['single_listing_header']['layout'] ?? array() as $slot ) {
						if ( ( $slot['placeholderKey'] ?? '' ) === ( $node['placeholderKey'] ?? '' ) && in_array( $widget, $slot['acceptedWidgets'] ?? array(), true ) ) {
							$d                         = $this->definitions['single_listing_header']['widgets'][ $widget ];
							$node['selectedWidgets'][] = $this->defaults( $d['options']['fields'] ?? $d['options'] ?? array() ) + array(
								'type'        => $d['type'] ?? 'badge',
								'label'       => $d['label'] ?? $widget,
								'widget_name' => $widget,
								'widget_key'  => $widget,
							);
						}
					}
					}
			}
		}
	}

	private function card_widget( string $widget ): string {
		return array(
			'title'    => 'listing_title',
			'location' => 'listings_location',
		)[ $widget ] ?? $widget;
	}

	private function card_supported( string $widget, array $config ): bool {
		if ( 'image_upload' === $widget ) {
			return true; }
		$id = $this->card_widget( $widget );
		foreach ( array( 'listings_card_grid_view', 'listings_card_list_view' ) as $section ) {
			$templates = array_keys( $config[ $section ]['template_data'] ?? array() );
			if ( ! in_array( $config[ $section ]['active_template'] ?? '', $templates, true ) ) {
				return false; }
			foreach ( $templates as $template_id ) {
				$template = $this->definitions[ $section ]['card_templates'][ $template_id ] ?? null;
				$accepted = false;
				if ( isset( $template['widgets'][ $id ] ) ) {
					foreach ( $template['layout'] ?? array() as $slots ) {
						foreach ( $slots as $slot ) {
							$accepted = $accepted || in_array( $id, $slot['acceptedWidgets'] ?? array(), true ); }
					}
				}
				if ( ! $accepted ) {
					return false; }
			}
		}
		return true;
	}

	private function contains_reference( array $nodes, string $key ): bool {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue; }
			if ( ( $node['original_widget_key'] ?? $node['widget_key'] ?? null ) === $key || $this->contains_reference( $node, $key ) ) {
				return true; }
		}
		return false;
	}

	private function contains_widget( array $nodes, string $widget ): bool {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue; }
			if ( ( $node['widget_name'] ?? null ) === $widget || $this->contains_widget( $node, $widget ) ) {
				return true; }
		}
		return false;
	}

	private function place_card( array &$config, string $key, string $widget, bool $enabled ): void {
		if ( $enabled && ! $this->card_supported( $widget, $config ) ) {
			throw new ContractException( 'unsupported_directory_field', 'This field has no native listing-card placement.' ); }
		foreach ( array( 'listings_card_grid_view', 'listings_card_list_view' ) as $section ) {
			$card = &$config[ $section ];
			if ( 'image_upload' === $widget ) {
				$prefix                  = 'listings_card_grid_view' === $section ? 'grid_view_' : 'list_view_';
				$card['active_template'] = $prefix . ( $enabled ? 'with_thumbnail' : 'without_thumbnail' );
			} else {
				if ( ! isset( $card['template_data'] ) || ! is_array( $card['template_data'] ) ) {
					if ( $enabled ) {
						throw new ContractException( 'unsupported_directory_field', 'Save the native card template before requesting AI changes.' ); }
					continue;
				}
				foreach ( $card['template_data'] as $template_id => &$layout ) {
					$template = $this->definitions[ $section ]['card_templates'][ $template_id ] ?? null;
					if ( ! $template && $enabled ) {
						continue; }
					$id    = $this->card_widget( $widget );
					$found = false;
					foreach ( $layout as $area => &$slots ) {
						foreach ( $slots as $slot => &$items ) {
							if ( ! is_array( $items ) ) {
								continue; }
							foreach ( $items as $index => $item ) {
								$reference   = $item['original_widget_key'] ?? $item['widget_key'] ?? null;
								$widget_name = $item['widget_name'] ?? null;
								if ( $reference === $key || ( $widget_name === $id && 'custom' !== ( $config['submission_form_fields']['fields'][ $key ]['widget_group'] ?? '' ) ) ) {
									$found = true;
									if ( ! $enabled ) {
										unset( $items[ $index ] ); }
								}
							}
							$items = array_values( $items );
						}
						unset( $items );
					}
					unset( $slots );
					if ( $enabled && ! $found && isset( $template['widgets'][ $id ] ) ) {
						foreach ( $template['layout'] ?? array() as $area => $slots ) {
							foreach ( $slots as $slot => $definition ) {
								if ( ! in_array( $id, $definition['acceptedWidgets'] ?? array(), true ) ) {
									continue; }
								$d                          = $template['widgets'][ $id ];
								$layout[ $area ][ $slot ][] = $this->defaults( $d['options']['fields'] ?? array() ) + array(
									'type'                => $d['type'],
									'label'               => $config['submission_form_fields']['fields'][ $key ]['label'] ?? $d['label'],
									'hook'                => $d['hook'] ?? '',
									'widget_name'         => $id,
									'widget_key'          => 'dai-' . $key,
									'original_widget_key' => $key,
								);
								break 2;
							}
						}
					}
				}
				unset( $layout );
			}
			if ( isset( $card['template_data'][ $card['active_template'] ] ) ) {
				foreach ( $card['template_data'][ $card['active_template'] ] as $area => $slots ) {
					$card[ $area ] = $slots; }
			}
		}
	}

	/**
	 * Validate native relationships independently of the model proposal.
	 *
	 * @throws ContractException When a native relationship is invalid.
	 */
	public function validate( array $config ): void {
		$form = $config['submission_form_fields']['fields'] ?? array();
		if ( ! $form || count( $form ) > 100 ) {
			throw new ContractException( 'invalid_directory_schema', 'This directory needs a bounded native submission form.' ); }
		$meta_keys = array();
		$has_title = false;
		foreach ( $form as $id => $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['widget_name'] ?? null ) || ! is_string( $field['field_key'] ?? null ) ) {
				throw new ContractException( 'invalid_directory_schema', 'A submission field is incomplete.' ); }
			$this->prototype( 'submission_form_fields', $field['widget_name'] );
			if ( '' === $field['field_key'] || strlen( $field['field_key'] ) > 200 ) {
				throw new ContractException( 'invalid_directory_schema', 'A submission storage key is invalid.' ); }
			if ( in_array( $field['widget_name'], array( 'select', 'checkbox', 'radio' ), true ) ) {
				$choices = array();
				foreach ( $field['options'] ?? array() as $option ) {
					if ( ! is_array( $option ) || ! is_string( $option['option_value'] ?? null ) || '' === $option['option_value'] || ! is_string( $option['option_label'] ?? null ) || isset( $choices[ $option['option_value'] ] ) ) {
						throw new ContractException( 'invalid_directory_schema', 'Choice fields require distinct native values and labels.' ); }
					$choices[ $option['option_value'] ] = true;
				}
				if ( ! $choices ) {
					throw new ContractException( 'invalid_directory_schema', 'A choice field needs native options.' ); }
			}
			if ( isset( $meta_keys[ $field['field_key'] ] ) ) {
				throw new ContractException( 'invalid_directory_schema', 'Submission fields have conflicting storage keys.' ); }
			$meta_keys[ $field['field_key'] ] = true;
			$has_title                        = $has_title || ( 'title' === $field['widget_name'] && ! empty( $field['required'] ) );
		}
		if ( ! $has_title ) {
			throw new ContractException( 'invalid_directory_schema', 'The required native listing-title field must remain available.' ); }
		foreach ( array( 'submission_form_fields', 'search_form_fields', 'single_listings_contents' ) as $section ) {
			$fields = $config[ $section ]['fields'] ?? array();
			$placed = array();
			foreach ( $config[ $section ]['groups'] ?? array() as $group ) {
				foreach ( $group['fields'] ?? array() as $id ) {
					if ( 'single_listings_contents' === $section && ! empty( $fields[ $id ]['original_widget_key'] ) && 'general_group' !== ( $group['type'] ?? '' ) ) {
						throw new ContractException( 'invalid_directory_schema', 'Submission fields need a normal single-listing content section, outside review and related-listing modules.' ); }
					if ( ! isset( $fields[ $id ] ) || isset( $placed[ $id ] ) ) {
						throw new ContractException( 'invalid_directory_schema', 'A directory section contains a missing or repeated field.' ); }
					$placed[ $id ] = true;
				}
			}
			if ( array_diff( array_keys( $fields ), array_keys( $placed ) ) ) {
				throw new ContractException( 'invalid_directory_schema', 'Every directory field must belong to a native section.' ); }
			if ( 'submission_form_fields' === $section ) {
				continue; }
			foreach ( $fields as $field ) {
				$this->prototype( $section, $field['widget_name'] ?? '' );
				$reference = $field['original_widget_key'] ?? null;
				if ( $reference && ( ! isset( $form[ $reference ] ) || $form[ $reference ]['widget_name'] !== $field['widget_name'] ) ) {
					throw new ContractException( 'invalid_directory_schema', 'Search and display fields must reference compatible submission fields.' ); }
			}
		}
		foreach ( array( 'listings_card_grid_view', 'listings_card_list_view' ) as $section ) {
			foreach ( $config[ $section ]['template_data'] ?? array() as $template_id => $layout ) {
				$template = $this->definitions[ $section ]['card_templates'][ $template_id ] ?? null;
				if ( ! $template ) {
					throw new ContractException( 'invalid_directory_schema', 'A listing-card template is unavailable.' ); }
				foreach ( $layout as $area => $slots ) {
					foreach ( $slots as $slot => $items ) {
						foreach ( $items as $item ) {
							$widget = $item['widget_name'] ?? '';
							if ( ! isset( $template['widgets'][ $widget ] ) || ( ! empty( $item['original_widget_key'] ) && ! in_array( $widget, $template['layout'][ $area ][ $slot ]['acceptedWidgets'] ?? array(), true ) ) ) {
								throw new ContractException( 'invalid_directory_schema', 'A listing-card field has an unsupported placement.' ); }
							$reference = $item['original_widget_key'] ?? null;
							if ( $reference && ( ! isset( $form[ $reference ] ) || $this->card_widget( $form[ $reference ]['widget_name'] ) !== $widget ) ) {
								throw new ContractException( 'invalid_directory_schema', 'A listing-card field has an incompatible submission reference.' ); }
						}
					}
				}
			}
		}
	}
}
