<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use Directorist\Directorist_Listing_Form;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;

class FormFields implements Provider {
    /**
     * Plan cache keyed by "{user_id}:{directory_type_id}".
     *
     * @var array<string, stdClass|null>
     */
    protected array $plan_cache = [];

    /**
     * Plan features cache keyed by "{user_id}:{directory_type_id}".
     *
     * @var array<string, array>
     */
    protected array $feature_cache = [];

    protected bool $is_resolved_current_plan = false;

    protected ?stdClass $current_plan = null;

    protected bool $is_current_plan_active = false;

    protected bool $is_current_plan_legacy = false;

    /**
     * Excluded category IDs to be applied to the next category get_terms call.
     *
     * @var int[]
     */
    protected array $pending_category_exclude_ids = [];

    /**
     * True only while the submission form is rendering the admin_category_select[] field.
     * Scopes the get_terms_args exclusion filter to the submission form only.
     */
    protected bool $is_rendering_submission_category = false;

    public function boot() {
        add_filter( 'directorist_submission_form_fields', [ $this, 'submission_form_fields' ], 10, 2 );
        add_filter( 'directorist_form_field_data', [ $this, 'directorist_form_field_data' ], 10, 1 );
        add_filter( 'get_terms_args', [ $this, 'filter_excluded_category_terms' ], 10, 2 );
        add_filter( "directorist_rest_" . ATBDP_CATEGORY . "_query", [ $this, 'filter_rest_category_query' ], 10, 1 );
        
        add_filter( 'directorist_single_listings_contents', [ $this, 'single_listings_contents' ], 10, 2 );
        add_filter( 'directorist_single_listing_header', [ $this, 'single_listing_header' ], 10, 2 );
        add_filter( 'directorist_single_listing_thumbnails', [ $this, 'listing_thumbnails' ], 10, 2 );
        
        add_filter( 'directorist_listing_archive_thumbnails', [ $this, 'listing_thumbnails' ], 10, 2 );
        add_filter( 'directorist_image_upload_config', [ $this, 'image_upload_config' ], 10, 2 );
        add_filter( 'directorist_listing_archive_fields', [ $this, 'listing_archive_fields' ], 10, 2 );
        add_filter( 'directorist_field_template', [ $this, 'directorist_field_template' ], 10, 3 );
        add_filter( 'directorist_template_file_path', [ $this, 'directorist_template_file_path' ], 10, 3 );
        add_filter( 'atbdp_add_listing_form_validation_logic', [ $this, 'skip_listing_type_validation_when_featured_unavailable' ], 10, 3 );
    }

    public function skip_listing_type_validation_when_featured_unavailable( bool $should_validate, array $field_props, array $posted_data ): bool {
        if ( ! $should_validate ) {
            return $should_validate;
        }

        $field_key = ! empty( $field_props['field_key'] ) ? $field_props['field_key'] : '';

        if ( 'listing_type' !== $field_key || ! empty( $posted_data['listing_type'] ) ) {
            return $should_validate;
        }

        $plan = $this->get_plan_from_submission_data( $posted_data );

        if ( ! $plan || $this->plan_allows_featured_listing( $plan ) ) {
            return $should_validate;
        }

        return false;
    }

    public function submission_form_fields( $form_fields, array $context = [] ) {
        if ( empty( $context['type'] ) ) {
            return $form_fields;
        }
        
        if ( 'listing_submission_form' !== $context['type'] ) {
            return $form_fields;
        }

        if ( empty( $form_fields ) || ! is_array( $form_fields ) ) {
            return $form_fields;
        }

        if ( empty( $form_fields['fields'] ) || ! is_array( $form_fields['fields'] ) ) {
            return $form_fields;
        }

        $directory_type_id = ! empty( $context['directory_type_id'] ) ? (int) $context['directory_type_id'] : 0;
        $listing_owner_id  = ! empty( $context['listing_owner_id'] ) ? (int) $context['listing_owner_id'] : get_current_user_id();
        $listing_id        = ! empty( $context['listing_id'] ) ? (int) $context['listing_id'] : 0;

        $plan_features = [];

        if ( $this->is_edit_mode() ) {
            $plan_features = $this->get_plan_features_for_listing( $listing_id, $listing_owner_id, $directory_type_id );   
        }

        if ( empty( $plan_features ) ) {
            $current_plan = $this->get_current_plan( $directory_type_id );

            if ( ! $current_plan ) {
                return [];
            }

            $plan_features = $this->get_plan_features( $current_plan );
        }

        if ( empty( $plan_features ) ) {
            return [];
        }

        $plan_feature_keys    = apply_filters( 'directorist_pricing_plans_features_keys',  array_column( $plan_features, 'key' ), 'submission_form_fields' );
        $excluded_form_fields = [];

        foreach ( $form_fields['fields'] as $key => $field ) {
            $field_key = ! empty( $field['field_key'] ) ? $field['field_key'] : '';

            // listing_type is never validated by the plan.
            if ( 'listing_type' === $field_key ) {
                continue;
            }

            if ( 'pricing' === $field_key ) {
                if ( ! $this->apply_pricing_feature_to_field( $form_fields['fields'][ $key ], $plan_features ) ) {
                    unset( $form_fields['fields'][ $key ] );
                    $excluded_form_fields[] = $key;
                }

                continue;
            }

            $feature_index = array_search( $field_key, $plan_feature_keys );

            if ( $feature_index === false ) {
                continue;
            }

            $current_feature = $plan_features[ $feature_index ];

            if ( ! $current_feature->is_enabled ) {
                unset( $form_fields['fields'][ $key ] );
                $excluded_form_fields[] = $key;
                continue;
            }

            if ( 'admin_category_select[]' === $field_key ) {
                $this->apply_category_feature_to_field( $form_fields['fields'][ $key ], $current_feature );
                continue;
            }

            if ( $this->supports_submission_limit( $field_key ) ) {
                $this->apply_submission_limit_to_field( $form_fields['fields'][ $key ], $current_feature );
            }
        }

        if ( ! empty( $excluded_form_fields ) ) {
            // If any fields were excluded, also exclude them from the groups to prevent JS errors.
            if ( ! empty( $form_fields['groups'] ) && is_array( $form_fields['groups'] ) ) {
                foreach ( $form_fields['groups'] as $group_key => $group ) {
                    if ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) {
                        $form_fields['groups'][ $group_key ]['fields'] = array_diff( $group['fields'], $excluded_form_fields );
                    }
                }
            }
        }

        return $form_fields;
    }

    /**
     * Apply plan pricing module configuration to the pricing field.
     *
     * The pricing field is composed of the price_unit and price_range features.
     * When neither feature is enabled, the whole pricing field should be removed.
     *
     * @param array $field
     * @param array $plan_features
     * @return bool True when the pricing field should remain visible.
     */
    protected function apply_pricing_feature_to_field( array &$field, array $plan_features ): bool {
        $plan_feature_keys = array_column( $plan_features, 'key' );
        $price_unit_index  = array_search( 'price_unit', $plan_feature_keys, true );
        $price_range_index = array_search( 'price_range', $plan_feature_keys, true );
        $has_price_unit    = $price_unit_index !== false && ! empty( $plan_features[ $price_unit_index ]->is_enabled );
        $has_price_range   = $price_range_index !== false && ! empty( $plan_features[ $price_range_index ]->is_enabled );

        if ( $has_price_unit && $has_price_range ) {
            $field['pricing_type'] = 'both';
            return true;
        }

        if ( $has_price_unit ) {
            $field['pricing_type'] = 'price_unit';
            return true;
        }

        if ( $has_price_range ) {
            $field['pricing_type'] = 'price_range';
            return true;
        }

        return false;
    }

    /**
     * Apply category feature limit and exclude data directly to the field definition.
     *
     * @param array    $field           Reference to the field definition array.
     * @param stdClass $feature        The category plan feature object.
     */
    protected function apply_category_feature_to_field( array &$field, stdClass $feature ): void {
        $data = is_array( $feature->data ) ? $feature->data : [];

        // Apply max limit when not unlimited
        if ( empty( $data['is_unlimited'] ) && ! empty( $data['limit'] ) && (int) $data['limit'] > 0 ) {
            $field['max'] = (int) $data['limit'];
        }

        // Store excluded IDs for later application via get_terms_args filter
        if ( ! empty( $data['exclude'] ) && is_array( $data['exclude'] ) ) {
            $this->pending_category_exclude_ids = array_map( 'intval', $data['exclude'] );
        }
    }

    protected function supports_submission_limit( string $field_key ): bool {
        $supported_field_keys = [
            'tax_input[at_biz_dir-location][]',
            'tax_input[at_biz_dir-tags][]',
            'listing_content',
            'excerpt',
            'fax',
        ];

        if ( in_array( $field_key, $supported_field_keys, true ) ) {
            return true;
        }

        return strpos( $field_key, 'custom-textarea' ) === 0;
    }

    protected function apply_submission_limit_to_field( array &$field, stdClass $feature ): void {
        $data = is_array( $feature->data ) ? $feature->data : [];

        if ( ! empty( $data['is_unlimited'] ) || empty( $data['limit'] ) || (int) $data['limit'] < 1 ) {
            return;
        }

        $limit = (int) $data['limit'];

        if ( 'tax_input[at_biz_dir-location][]' === ( $field['field_key'] ?? '' ) ) {
            $field['max_location_creation'] = $limit;
            return;
        }

        $field['max'] = $limit;
    }

    public function directorist_form_field_data( array $field_data ): array {
        if ( is_admin() ) {
            return $field_data;
        }

        $field_key = ! empty( $field_data['field_key'] ) ? (string) $field_data['field_key'] : '';
        $limit     = ! empty( $field_data['max'] ) ? (int) $field_data['max'] : 0;

        if ( ! $field_key || $limit < 1 || ! $this->supports_submission_limit( $field_key ) ) {
            return $field_data;
        }

        printf(
            '<input type="hidden" class="directorist-pricing-plan-limit" id="directorist_plan_limit_%1$s" data-field-key="%2$s" value="%3$d">',
            esc_attr( sanitize_title_with_dashes( $field_key ) ),
            esc_attr( $field_key ),
            esc_attr( $limit )
        );

        return $field_data;
    }

    /**
     * Persistent get_terms_args filter that injects excluded category IDs into every
     * get_terms call for ATBDP_CATEGORY
     * Registered in boot() so it covers all recursive calls in add_listing_cat_fields().
     *
     * @param array    $args
     * @param string[] $taxonomies
     * @return array
     */
    public function filter_excluded_category_terms( array $args, array $taxonomies ): array {
        if ( empty( $this->pending_category_exclude_ids ) ) {
            return $args;
        }

        if ( ! in_array( ATBDP_CATEGORY, $taxonomies, true ) ) {
            return $args;
        }

        $existing_exclude = ! empty( $args['exclude'] ) ? (array) $args['exclude'] : [];
        $args['exclude']  = array_merge( $existing_exclude, $this->pending_category_exclude_ids );

        return $args;
    }

    /**
     * Filter the REST API categories query args to exclude the pending category IDs.
     * Covers the lazy_load_taxonomy_fields AJAX path used by select2 in the submission form.
     * The pending IDs are set per-user-session when the submission form is prepared.
     *
     * @param array $prepared_args
     * @return array
     */
    public function filter_rest_category_query( array $prepared_args ): array {
        if ( empty( $this->pending_category_exclude_ids ) ) {
            return $prepared_args;
        }

        $existing_exclude         = ! empty( $prepared_args['exclude'] ) ? (array) $prepared_args['exclude'] : [];
        $prepared_args['exclude'] = array_merge( $existing_exclude, $this->pending_category_exclude_ids );

        return $prepared_args;
    }

    public function single_listings_contents( $single_listings_contents, array $data ) {
        if ( $this->is_single_listing_preview() ) {
            return $single_listings_contents;
        }

        if ( empty( $single_listings_contents ) || ! is_array( $single_listings_contents ) ) {
            return $single_listings_contents;
        }

        if ( empty( $single_listings_contents['fields'] ) || ! is_array( $single_listings_contents['fields'] ) ) {
            return $single_listings_contents;
        }

        $directory_type_id = ! empty( $data['directory_type_id'] ) ? (int) $data['directory_type_id'] : 0;
        $listing_owner_id  = ! empty( $data['listing_owner_id'] ) ? (int) $data['listing_owner_id'] : get_current_user_id();
        $listing_id        = ! empty( $data['listing_id'] ) ? (int) $data['listing_id'] : 0;

        $plan_features = $this->get_plan_features_for_listing( $listing_id, $listing_owner_id, $directory_type_id );

        if ( null === $plan_features ) {
            $plan_features = $this->get_plan_features_for_user( $listing_owner_id, $directory_type_id );
        }

        if ( empty( $plan_features ) ) {
            // No active plan — remove all fields except listing_type.
            $single_listings_contents['fields'] = [];
            $single_listings_contents['groups'] = [];
            return $single_listings_contents;
        }

        $plan_feature_keys = apply_filters( 'directorist_pricing_plans_features_keys',  array_column( $plan_features, 'key' ), 'single_listings_contents' );

        // Field Key -> Feature Key
        $alias = [
            'description'       => 'listing_content',
            'category'          => 'admin_category_select[]',
            'listings_location' => 'tax_input[at_biz_dir-location][]',
            'tag'               => 'tax_input[at_biz_dir-tags][]',
            'video'             => 'videourl',
            'social_info'       => 'social',
        ];

        // Filter fields by plan features.
        $excluded_form_fields = [];

        foreach ( $single_listings_contents['fields'] as $key => $field ) {
            $widget_key = ! empty( $field['widget_key'] ) ? $field['widget_key'] : '';
            $widget_key = ! empty( $alias[ $widget_key ] ) ? $alias[ $widget_key ] : $widget_key;

            $feature_index = array_search( $widget_key, $plan_feature_keys );

            if ( $feature_index === false ) {
                $feature_index = array_search( str_replace( '_', '-', "custom_$widget_key" ), $plan_feature_keys );
            }

            if ( $feature_index === false ) {
                continue;
            }

            $current_feature = $plan_features[ $feature_index ];

            if ( ! $current_feature->is_enabled ) {
                unset( $single_listings_contents['fields'][ $key ] );
                $excluded_form_fields[] = $key;
            }
        }

        if ( ! empty( $single_listings_contents['groups'] ) && is_array( $single_listings_contents['groups'] ) ) {
            foreach ( $single_listings_contents['groups'] as $group_key => $group ) {
                if ( isset( $group['widget_name'] ) ) {
                    $group_feature_index = array_search( $group['widget_name'], $plan_feature_keys );

                    if ( $group_feature_index !== false ) {
                        $group_feature = $plan_features[ $group_feature_index ];

                        if ( (int) $group_feature->is_enabled !== 1 ) {
                            unset( $single_listings_contents['groups'][ $group_key ] );
                            continue;
                        }
                    }
                }

                if ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) {
                    if ( ! empty( $excluded_form_fields ) ) {
                        $single_listings_contents['groups'][ $group_key ]['fields'] = array_diff( $group['fields'], $excluded_form_fields );
                    }
                }
            }
        }
        
        return $single_listings_contents;
    }

    public function single_listing_header( array $header_data, array $data ): array {
        if ( $this->is_single_listing_preview() ) {
            return $header_data;
        }

        $author_id         = ! empty( $data['listing_owner_id'] ) ? (int) $data['listing_owner_id'] : 0;
        $directory_type_id = ! empty( $data['directory_type_id'] ) ? (int) $data['directory_type_id'] : 0;
        $listing_id        = ! empty( $data['listing_id'] ) ? (int) $data['listing_id'] : 0;

        $plan_features = $this->get_plan_features_for_listing( $listing_id, $author_id, $directory_type_id );

        if ( null === $plan_features ) {
            $plan_features = $this->get_plan_features_for_user( $author_id, $directory_type_id );
        }

        if ( empty( $plan_features ) ) {
            return $header_data;
        }

        $plan_feature_keys = apply_filters( 'directorist_pricing_plans_features_keys',  array_column( $plan_features, 'key' ), 'single_listing_header' );

        // Field Key -> Feature Key
        $alias = [
            'title'         => 'listing_title',
            'ratings_count' => 'review',
            'price'         => 'pricing',
            'category'      => 'admin_category_select[]',
            'tag'           => 'tax_input[at_biz_dir-tags][]',
            'slider'        => 'listing_img',
        ];

        foreach ( $header_data as $placeholder_group_key => $placeholder_group ) {
            if ( isset( $placeholder_group['selectedWidgets'] ) ) {
                $this->filter_single_listing_widgets( $header_data[ $placeholder_group_key ]['selectedWidgets'], $plan_feature_keys, $plan_features, $alias );
                continue;
            }

            if ( isset( $placeholder_group['placeholders'] ) ) {
                foreach ( $placeholder_group['placeholders'] as $placeholder_key => $placeholder ) {
                    $this->filter_single_listing_widgets( $header_data[ $placeholder_group_key ]['placeholders'][ $placeholder_key ]['selectedWidgets'], $plan_feature_keys, $plan_features, $alias );
                }
            }
        }
        
        return $header_data;
    }

    public function filter_single_listing_widgets( array &$selected_widgets, array $plan_feature_keys, array $plan_features, array $alias ): array {
        foreach ( $selected_widgets as $widget_index => $widget ) {
            $widget_key    = ! empty( $widget['widget_key'] ) ? $widget['widget_key'] : '';
            $widget_key    = ! empty( $alias[ $widget_key ] ) ? $alias[ $widget_key ] : $widget_key;
            $feature_index = array_search( $widget_key, $plan_feature_keys );

            if ( $feature_index === false ) {
                continue;
            }

            $current_feature = $plan_features[ $feature_index ];
            
            if ( (int) $current_feature->is_enabled !== 1 ) {
                unset( $selected_widgets[ $widget_index ] );
                continue;
            }

            if ( $widget_key === 'listing_title' ) {
                $tagline_feature_index = array_search( 'tagline', $plan_feature_keys );

                if ( $tagline_feature_index !== false ) {
                    $tagline_feature = $plan_features[ $tagline_feature_index ];

                    $selected_widgets[ $widget_index ]['enable_tagline'] = (int) $tagline_feature->is_enabled === 1;
                }
            }
        }

        return $selected_widgets;
    }

    public function listing_thumbnails( array $thumbnail_images, int $listing_id ): array {
        if ( $this->is_single_listing_preview() ) {
            return $thumbnail_images;
        }

        $author_id         = (int) get_post_field( 'post_author', $listing_id );
        $directory_type_id = (int) get_post_meta( $listing_id, '_directory_type', true );
        $plan_features     = $this->get_plan_features_for_listing( $listing_id, $author_id, $directory_type_id );
        
        if ( null === $plan_features ) {
            $plan_features = $this->get_plan_features_for_user( $author_id, $directory_type_id );
        }
        
        if ( null === $plan_features ) {
            return $thumbnail_images;
        }

        if ( empty( $plan_features ) ) {
            return [];
        }

        $plan_feature_keys = array_column( $plan_features, 'key' );
        $feature_index     = array_search( 'listing_img', $plan_feature_keys );

        if ( $feature_index === false ) {
            return [];
        }

        $current_feature = $plan_features[ $feature_index ];

        if ( (int) $current_feature->is_enabled !== 1 ) {
            return [];
        }

        if ( isset( $current_feature->data['is_unlimited'] ) && (int) $current_feature->data['is_unlimited'] === 1 ) {
            return $thumbnail_images;
        }

        $limit = isset( $current_feature->data['limit'] ) ? (int) $current_feature->data['limit'] : (int) $current_feature->data['limit'];

        return array_slice( $thumbnail_images, 0, max( 0, $limit ) );
    }

    public function image_upload_config( $config, Directorist_Listing_Form $listing_form ) {
        $directory_type_id = (int) $listing_form->get_current_listing_type();
        $listing_owner_id  = (int) $listing_form->get_listing_owner_id();
        
        $plan_features = $this->get_plan_features_for_user( $listing_owner_id, $directory_type_id );

        if ( empty( $plan_features ) ) {
            return $config;
        }

        $plan_feature_keys = array_column( $plan_features, 'key' );
        $feature_index     = array_search( 'listing_img', $plan_feature_keys );

        if ( $feature_index === false ) {
            return $config;
        }

        $current_feature = $plan_features[ $feature_index ];

        if ( ! isset( $current_feature->data['limit'] ) ) {
            return $config;
        }

        $config['max_num_of_img'] = isset( $current_feature->data['limit'] ) && (int) $current_feature->data['is_unlimited'] === 1 ? 0 : (int) $current_feature->data['limit'];

        return $config;
    }

    public function is_single_listing_preview() {
        return isset( $_GET['preview'] ) && 1 === (int) $_GET['preview'];
    }

    public function listing_archive_fields( array $archive_fields, array $data ): array {
        $directory_type_id = ! empty( $data['directory_type_id'] ) ? (int) $data['directory_type_id'] : 0;
        $author_id         = ! empty( $data['author_id'] ) ? (int) $data['author_id'] : 0;

        if ( ! $author_id || ! $directory_type_id ) {
            return $archive_fields;
        }

        // In archive context we may be inside The Loop — use get_the_ID() as fallback
        $listing_id    = ! empty( $data['listing_id'] ) ? (int) $data['listing_id'] : (int) get_the_ID();
        $plan_features = $this->get_plan_features_for_listing( $listing_id, $author_id, $directory_type_id );

        if ( null === $plan_features ) {
            $plan_features = $this->get_plan_features_for_user( $author_id, $directory_type_id );
        }

        if ( null === $plan_features ) {
            // No active plan
            return $archive_fields;
        }

        if ( empty( $plan_features ) ) {
            $archive_fields['card_fields'] = [];
            $archive_fields['list_fields'] = [];
            return $archive_fields;
        }

        $plan_feature_keys = apply_filters( 'directorist_pricing_plans_features_keys',  array_column( $plan_features, 'key' ), 'listing_archive_fields' );

        if ( ! empty( $archive_fields['card_fields'] ) && is_array( $archive_fields['card_fields'] ) ) {
            $archive_fields['card_fields'] = $this->filter_card_fields( $archive_fields['card_fields'], $plan_features, $plan_feature_keys );
        }

        if ( ! empty( $archive_fields['list_fields'] ) && is_array( $archive_fields['list_fields'] ) ) {
            $archive_fields['list_fields'] = $this->filter_card_fields( $archive_fields['list_fields'], $plan_features, $plan_feature_keys );
        }

        return $archive_fields;
    }

    /**
     * Recursively filter card/list fields by plan features.
     *
     * The card fields data is multi-dimensional with variable depth. Leaf nodes
     * are indexed (numeric-keyed) arrays of item objects. Intermediate nodes are
     * associative arrays acting as sections. Non-array values (e.g. active_template
     * string) are left untouched.
     *
     * Recursion stops at each indexed array: its items are inspected for widget_key
     * and filtered against plan features. Items with no matching plan feature are
     * always kept. Items with a matching plan feature are only kept when is_enabled.
     *
     * @param array $fields            The card/list fields array (any depth).
     * @param array $plan_features     Array of plan feature objects.
     * @param array $plan_feature_keys Indexed array of plan feature key strings.
     * @return array
     */
    protected function filter_card_fields( array $fields, array $plan_features, array $plan_feature_keys ): array {
        foreach ( $fields as $key => $value ) {
            // Leave non-array values (e.g. the "active_template" string) untouched.
            if ( ! is_array( $value ) ) {
                continue;
            }

            if ( $this->is_card_items_array( $value ) ) {
                // Leaf node — filter individual items.
                $fields[ $key ] = $this->filter_card_items( $value, $plan_features, $plan_feature_keys );
            } else {
                // Intermediate section node — recurse one level deeper.
                $fields[ $key ] = $this->filter_card_fields( $value, $plan_features, $plan_feature_keys );
            }
        }

        return $fields;
    }

    /**
     * Determine whether an array is an items array (indexed/numeric keys)
     * rather than a section array (string keys).
     *
     * Empty arrays are treated as items arrays — nothing to filter there.
     *
     * @param array $arr
     * @return bool
     */
    protected function is_card_items_array( array $arr ): bool {
        if ( empty( $arr ) ) {
            return true;
        }

        return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
    }

    /**
     * Filter an items array, removing items whose widget_key maps to a
     * disabled plan feature. Items with no matching plan feature are kept.
     *
     * @param array $items
     * @param array $plan_features
     * @param array $plan_feature_keys
     * @return array
     */
    protected function filter_card_items( array $items, array $plan_features, array $plan_feature_keys ): array {
        $filtered = [];

        // Field Key -> Feature Key
        $alias = [
            'category'          => 'admin_category_select[]',
            'listings_location' => 'tax_input[at_biz_dir-location][]',
            'rating'            => 'review',
        ];

        // echo '<pre>';
        // print_r( [
        //     'items' => $items,
        //     // 'plan_feature_keys' => $plan_feature_keys
        // ] );
        // echo '</pre>';

        // open_close_badge

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                $filtered[] = $item;
                continue;
            }

            $widget_key    = ! empty( $item['widget_key'] ) ? $item['widget_key'] : '';
            $widget_key    = ! empty( $alias[ $widget_key ] ) ? $alias[ $widget_key ] : $widget_key;
            $feature_index = array_search( $widget_key, $plan_feature_keys );

            if ( $feature_index === false ) {
                $feature_index = array_search( str_replace( '_', '-', "custom_$widget_key" ), $plan_feature_keys );
            }

            // No matching plan feature — always keep.
            if ( $feature_index === false ) {
                $filtered[] = $item;
                continue;
            }

            // Matching plan feature found — keep only if enabled.
            if ( (int) $plan_features[ $feature_index ]->is_enabled === 1 ) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    public function directorist_field_template( $template, $field_data, $directory_type_id ) {
        $field_key = ! empty( $field_data['field_key'] ) ? $field_data['field_key'] : '';

        if ( 'listing_type' === $field_key && ! $this->is_edit_mode() ) {
            $GLOBALS['directorist_pricing_plans_listing_type_template_context'] = [
                'field_data'             => $field_data,
                'current_plan'           => $this->get_current_plan( $directory_type_id ),
                'is_current_plan_active' => $this->is_current_plan_active,
                'is_legacy'              => $this->is_current_plan_legacy,
            ];

            return 'listing-form/fields/listing-type-pricing-plan';
        }

        return $template;
    }

    public function directorist_template_file_path( $file, $template, $args ) {
        if ( 'listing-form/fields/listing-type-pricing-plan' !== $template ) {
            return $file;
        }

        return directorist_pricing_plans_dir( 'resources/views/templates/listing_type.php' );
    }

    public function is_edit_mode(): bool {
        $id = get_query_var( 'atbdp_listing_id', 0 );
        $id = empty( $id ) && ! empty( $_REQUEST['edit'] ) ? absint( $_REQUEST['edit'] ) : $id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return $id > 0;
    }

    protected function get_current_plan( int $directory_type_id ): ?stdClass {
        if ( $this->is_resolved_current_plan ) {
            return $this->current_plan;
        }

        $this->is_resolved_current_plan = true;

        // Check if plan_id is provided in URL
        if ( isset( $_GET['plan_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $plan_id = absint( $_GET['plan_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            $current_package = directorist_user_package_repository()->get_package_by_plan( get_current_user_id(), $plan_id );

            if ( $current_package ) {
                $current_plan = directorist_get_pricing_plan_by_id( $current_package->plan_id );

                if ( ! $current_plan ) {
                    return null;
                }

                $this->current_plan           = $current_plan;
                $this->is_current_plan_active = true;
                $this->is_current_plan_legacy = ! empty( $current_package->is_legacy );
            } else {
                $current_plan = directorist_get_pricing_plan_by_id( $plan_id );

                if ( ! $current_plan ) {
                    return null;
                }

                $this->current_plan = $current_plan;
            }

            return $this->current_plan;
        }

        // Check if user has an active plan from directory
        $current_package = directorist_get_current_package( $directory_type_id );

        if ( $current_package ) {
            $this->is_current_plan_legacy = ! empty( $current_package->is_legacy );

            $current_plan = directorist_get_pricing_plan_by_id( $current_package->plan_id );

            if ( ! $current_plan ) {
                return null;
            }

            $this->current_plan           = $current_plan;
            $this->is_current_plan_active = true;
        }

        return $this->current_plan;
    }

    /**
     * Get the plan resolved from a listing's active package.
     *
     * @param int $listing_id
     * @param int $user_id
     * @param int $directory_type_id
     * @return stdClass|null
     */
    protected function get_plan_for_listing( int $listing_id, int $user_id, int $directory_type_id ): ?stdClass {
        $cache_key = "{$user_id}:{$directory_type_id}:{$listing_id}";

        if ( array_key_exists( $cache_key, $this->plan_cache ) ) {
            return $this->plan_cache[ $cache_key ];
        }

        $this->plan_cache[ $cache_key ] = null;

        $package = directorist_get_listing_package( $listing_id );

        if ( $package ) {
            $plan = directorist_get_pricing_plan_by_id( $package->plan_id );

            if ( ! $plan ) {
                return null;
            }

            $this->plan_cache[ $cache_key ] = $plan;
        }

        return $this->plan_cache[ $cache_key ];
    }

    /**
     * Get plan features for a specific listing, using `_plan_id` meta
     * when available.  Falls back to the directory-level cache bucket.
     *
     * @param int $listing_id
     * @param int $user_id
     * @param int $directory_type_id
     * @return array|null
     */
    protected function get_plan_features_for_listing( int $listing_id, int $user_id, int $directory_type_id ): ?array {
        $cache_key = "{$user_id}:{$directory_type_id}:{$listing_id}";

        if ( array_key_exists( $cache_key, $this->feature_cache ) ) {
            return $this->feature_cache[ $cache_key ];
        }

        $this->feature_cache[ $cache_key ] = null;

        $plan = $this->get_plan_for_listing( $listing_id, $user_id, $directory_type_id );

        if ( ! $plan ) {
            return $this->feature_cache[ $cache_key ];
        }

        $this->feature_cache[ $cache_key ] = $this->get_plan_features( $plan );

        return $this->feature_cache[ $cache_key ];
    }

    protected function get_plan_for_user( int $user_id, int $directory_type_id ): ?stdClass {
        $cache_key = "{$user_id}:{$directory_type_id}";

        if ( array_key_exists( $cache_key, $this->plan_cache ) ) {
            return $this->plan_cache[ $cache_key ];
        }

        $this->plan_cache[ $cache_key ] = null;

        $current_package = directorist_get_current_package( $directory_type_id, $user_id );

        if ( $current_package ) {
            $plan = directorist_get_pricing_plan_by_id( $current_package->plan_id );

            if ( ! $plan ) {
                return null;
            }

            $this->plan_cache[ $cache_key ] = $plan;
        }

        return $this->plan_cache[ $cache_key ];
    }

    protected function get_plan_from_submission_data( array $posted_data ): ?stdClass {
        if ( ! empty( $posted_data['plan_id'] ) ) {
            $plan = directorist_get_pricing_plan_by_id( (int) $posted_data['plan_id'] );

            if ( $plan ) {
                return $plan;
            }
        }

        $directory_type_id = ! empty( $posted_data['directory_id'] ) ? (int) $posted_data['directory_id'] : 0;

        if ( ! $directory_type_id ) {
            return null;
        }

        return $this->get_plan_for_user( get_current_user_id(), $directory_type_id );
    }

    protected function plan_allows_featured_listing( stdClass $plan ): bool {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
            return (int) ( $plan->is_featured ?? 0 ) === 1;
        }

        return (int) ( $plan->is_allowed_unlimited_featured_listings ?? 0 ) === 1 || (int) ( $plan->allowed_featured_listings ?? 0 ) > 0;
    }

    protected function get_plan_features_for_user( int $user_id, int $directory_type_id ): ?array {
        $cache_key = "{$user_id}:{$directory_type_id}";

        if ( array_key_exists( $cache_key, $this->feature_cache ) ) {
            return $this->feature_cache[ $cache_key ];
        }

        $this->feature_cache[ $cache_key ] = null;

        $plan = $this->get_plan_for_user( $user_id, $directory_type_id );
        
        if ( ! $plan ) {
            return $this->feature_cache[ $cache_key ];
        }

        $this->feature_cache[ $cache_key ] = $this->get_plan_features( $plan );

        return $this->feature_cache[ $cache_key ];
    }

    protected function get_plan_features( stdClass $plan ): array {
        /**
         * @var PlanFeatureRepository $plan_features_repository
         */
        $plan_features_repository = directorist_pricing_plans_singleton( PlanFeatureRepository::class );
        return $plan_features_repository->get( $plan );
    }
}
