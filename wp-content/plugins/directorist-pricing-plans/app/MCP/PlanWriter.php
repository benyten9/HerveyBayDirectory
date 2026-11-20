<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\DTO\Plan\DTO;
use DirectoristPricingPlan\App\Models\UserPackage;

/** Pure configuration preparation followed by the same repositories as native admin. */
final class PlanWriter {
    public static function defaults(): array {
        return [
            'title' => '', 'description' => '', 'type' => 'package', 'fee_type' => 'free', 'price' => 0.0,
            'allowed_listings' => 0, 'is_allowed_unlimited_listings' => false,
            'allowed_featured_listings' => 0, 'is_allowed_unlimited_featured_listings' => false,
            'is_taxable' => false, 'tax_type' => 'flat', 'tax_rate' => 0.0,
            'interval_type' => 'month', 'interval_count' => 1, 'is_subscription_enabled' => false,
            'is_trial_enabled' => false, 'trial_interval_type' => 'month', 'trial_interval_count' => 1,
            'sort_order' => 0, 'is_published' => false, 'is_hidden_from_plans_list' => false,
            'is_marked_as_recommended' => false, 'is_fallback' => false, 'fallback_plan_id' => 0,
            'listing_display_priority' => 1, 'is_featured' => false, 'features' => [],
        ];
    }

    public static function validate( array $values ): array {
        $schema = Schema::configuration();
        $error = rest_validate_value_from_schema( $values, $schema );
        Access::check( ! is_wp_error( $error ), 'Invalid native plan configuration.' );
        foreach ( $schema['properties'] as $key => $definition ) {
            if ( 'number' === $definition['type'] ) { $values[ $key ] = (float) $values[ $key ]; }
            if ( 'integer' === $definition['type'] ) { $values[ $key ] = (int) $values[ $key ]; }
        }
        Access::check( '' !== $values['title'], 'Plan title is required.' );
        Access::check( (bool) get_term_by( 'id', (int) $values['directory_type_id'], ATBDP_DIRECTORY_TYPE ), 'Directory is unavailable.', 'not_found' );
        Access::check( ! $values['is_subscription_enabled'] || ( 'paid' === $values['fee_type'] && $values['price'] > 0 && 'lifetime' !== $values['interval_type'] ), 'A subscription requires a paid periodic plan.' );
        Access::check( 'lifetime' === $values['interval_type'] || $values['interval_count'] > 0, 'Periodic plans require a positive interval count.' );
        Access::check( ! $values['is_trial_enabled'] || $values['trial_interval_count'] > 0, 'Enabled trials require a positive interval count.' );
        Access::check( abs( round( $values['tax_rate'] * 100 ) - $values['tax_rate'] * 100 ) < 0.00001, 'Native tax rates support two decimal places.' );
        Access::check( abs( round( $values['price'] * 100 ) - $values['price'] * 100 ) < 0.00001, 'Plan prices support two decimal places.' );
        Access::check( 'paid' !== $values['fee_type'] || $values['price'] > 0, 'Paid plans require a positive price.' );
        Access::check( 'free' !== $values['fee_type'] || 0.0 === (float) $values['price'], 'Free plans must have a zero price.' );
        Access::check( $values['is_allowed_unlimited_listings'] || ( ! $values['is_allowed_unlimited_featured_listings'] && $values['allowed_featured_listings'] <= $values['allowed_listings'] ), 'Featured allowance cannot exceed total allowance.' );
        if ( 'pay_per_listing' === $values['type'] ) {
            Access::check( 'paid' === $values['fee_type'] && ! $values['is_trial_enabled'] && ! $values['is_allowed_unlimited_listings'] && ! $values['is_allowed_unlimited_featured_listings'] && 0 === $values['allowed_listings'] && 0 === $values['allowed_featured_listings'], 'Pay-per-listing plans require paid pricing and no package quotas or trial.' );
        } else {
            Access::check( ! $values['is_featured'], 'Package featured allowance uses explicit quota fields.' );
        }
        if ( $values['fallback_plan_id'] ) {
            $fallback = directorist_get_pricing_plan_by_id( $values['fallback_plan_id'] );
            Access::check( $fallback && (int) $fallback->directory_type_id === (int) $values['directory_type_id'] && 'free' === $fallback->fee_type, 'Fallback must be a free plan in the same directory.' );
        }
        $registered = directorist_plan_registered_features( (int) $values['directory_type_id'] );
        $seen = [];
        foreach ( $values['features'] as $feature ) {
            Access::check( isset( $registered[ $feature['key'] ] ) && ! isset( $seen[ $feature['key'] ] ), 'Unsupported or duplicated native feature.' );
            $seen[ $feature['key'] ] = true;
            if ( isset( $feature['data'] ) ) {
                Access::check( isset( $registered[ $feature['key'] ]['data']['is_unlimited'] ), 'This feature does not support limits.' );
            }
        }
        return $values;
    }

    public static function prepare( string $action, array $args ): array {
        Access::check( Access::admin(), 'Administrator access is required.', 'forbidden' );
        Access::check( in_array( $action, [ 'create-plan', 'update-plan', 'delete-plan' ], true ), 'Unsupported plan operation.', 'unsupported_operation' );
        $id = (int) ( $args['plan_id'] ?? 0 );
        $native = $id ? Access::plan( $id ) : null;
        if ( 'delete-plan' === $action ) {
            return self::prepare_delete( $native );
        }
        $before = $native ? Reader::configuration( $native ) : [];
        if ( 'update-plan' === $action ) {
            $selected = (array) $args['changes'];
            Access::check( (bool) $selected, 'Select at least one explicit change.' );
        } else {
            $selected = array_intersect_key( $args, Schema::configuration()['properties'] );
        }
        if ( isset( $selected['title'] ) ) { $selected['title'] = sanitize_text_field( $selected['title'] ); }
        if ( isset( $selected['description'] ) ) { $selected['description'] = sanitize_textarea_field( $selected['description'] ); }
        if ( isset( $selected['features'] ) ) {
            $keys = array_column( $selected['features'], 'key' );
            Access::check( count( $keys ) === count( array_unique( $keys ) ), 'Duplicated native feature.' );
        }
        $after = array_replace( $native ? $before : self::defaults(), $selected );
        if ( isset( $selected['features'] ) && $native ) {
            $features = array_column( $before['features'], null, 'key' );
            foreach ( $selected['features'] as $feature ) {
                $features[ $feature['key'] ] = array_replace_recursive( $features[ $feature['key'] ] ?? [], $feature );
            }
            $after['features'] = array_values( $features );
        } elseif ( ! $native && ! isset( $selected['features'] ) ) {
            $after['features'] = Reader::features( (object) [ 'id' => 0, 'directory_type_id' => (int) $after['directory_type_id'] ] );
        }
        if ( $native ) {
            // Match the disabled controls in the admin editor, even if the REST API accepts them.
            Access::check( $after['directory_type_id'] === $before['directory_type_id'], 'Plan directory is locked in the admin editor. Create a new plan to use another directory.', 'unsupported_operation' );
            Access::check( $after['type'] === $before['type'], 'Plan type is locked in the admin editor. Create a new plan to use another type.', 'unsupported_operation' );
        }
        $after = self::validate( $after );
        if ( $native ) {
            Access::check( $after['fallback_plan_id'] !== $id, 'A plan cannot fall back to itself.' );
        }
        $references = $id ? (int) UserPackage::query()->where( 'plan_id', $id )->count() : 0;
        $warnings = [];
        if ( $references ) {
            // The native admin writer permits edits with package history. Disclose live effects.
            $presentation = [ 'title', 'description', 'is_published', 'is_hidden_from_plans_list', 'is_marked_as_recommended', 'sort_order' ];
            foreach ( $after as $key => $value ) {
                if ( ! in_array( $key, $presentation, true ) && $value !== $before[ $key ] ) {
                    $warnings[] = 'Existing packages use live plan settings. Saving these changes updates settings used by existing package owners, as in the native admin plan editor. Existing package periods and recorded order amounts are not recalculated.';
                    break;
                }
            }
            if ( $after['listing_display_priority'] !== $before['listing_display_priority'] ) {
                $warnings[] = 'The native plan update also applies the new listing display priority to existing packages.';
            }
        }
        $state = [ 'plan' => $native ? (array) $native : [], 'features' => $native ? directorist_pricing_plan_repository()->plan_feature_repository->get_query_builder()->where( 'plan_id', $id )->get() : [], 'references' => $references, 'registered_features' => directorist_plan_registered_features( $after['directory_type_id'] ), 'directory' => get_term_meta( $after['directory_type_id'] ), 'currency' => atbdp_get_payment_currency() ];
        return [
            'state' => $state,
            'preview' => [ 'before' => $before ?: (object) [], 'after' => $after, 'affected_packages' => $references, 'entitlements_use_live_plan' => true ],
            'warnings' => $warnings,
            'execute' => static function () use ( $id, $after, $selected, $native ): array {
                $dto = new DTO();
                // Preserve omitted storage values; native update hooks also require the existing priority.
                foreach ( $after as $key => $value ) {
                    if ( 'features' !== $key && ( ! $id || array_key_exists( $key, $selected ) || 'listing_display_priority' === $key ) ) {
                        $dto->{'set_' . $key}( $value );
                    }
                }
                $repository = directorist_pricing_plan_repository();
                if ( $id ) {
                    $dto->set_id( $id );
                    $repository->update_plan( $dto );
                    $saved_id = $id;
                } else {
                    $saved_id = (int) $repository->create( $dto );
                    Access::check( $saved_id > 0, 'Plan creation failed.', 'operation_failed' );
                }
                if ( ! $native || isset( $selected['features'] ) ) {
                    // Preserve unselected native feature data (including empty unlimited limits).
                    $features = $native ? $repository->plan_feature_repository->get( $native ) : [];
                    $map = [];
                    foreach ( $features as $feature ) {
                        $map[ $feature->key ] = (array) $feature;
                    }
                    foreach ( ( $selected['features'] ?? $after['features'] ) as $feature ) {
                        $map[ $feature['key'] ] = array_replace_recursive( $map[ $feature['key'] ] ?? [], $feature );
                    }
                    $chosen = $native ? array_intersect_key( $map, array_column( $selected['features'], 'key', 'key' ) ) : $map;
                    $repository->plan_feature_repository->update_features( array_values( $chosen ), $saved_id, true );
                }
                $saved = directorist_get_pricing_plan_by_id( $saved_id );
                Access::check( (bool) $saved, 'Plan could not be read after saving.', 'operation_failed' );
                Access::check( Reader::hash( Reader::configuration( $saved ) ) === Reader::hash( $after ), 'Native plan save did not match the prepared configuration.', 'operation_failed' );
                return [ 'plan' => Reader::plan( $saved ) ];
            },
        ];
    }

    /** Delete only an unused offer; purchased and historical references must survive. */
    private static function prepare_delete( \stdClass $plan ): array {
        global $wpdb;
        $id = (int) $plan->id;
        $repository = directorist_pricing_plan_repository();
        try {
            $repository->assert_can_delete_plan( $id );
        } catch ( \DirectoristPricingPlan\WpMVC\Exceptions\Exception $error ) {
            Access::check( false, $error->getMessage(), 403 === $error->getCode() ? 'forbidden' : 'dependency_blocked' );
        }
        $queries = [
            'orders' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}directorist_orders WHERE ref_type = %s AND ref = %d", 'pricing_plan', $id ),
            'fallbacks' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}directorist_plans WHERE fallback_plan_id = %d AND id <> %d", $id, $id ),
            'listings' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %s", ATBDP_POST_TYPE, directorist_plan_key(), (string) $id ),
        ];
        foreach ( $queries as $kind => $query ) {
            $count = $wpdb->get_var( $query );
            Access::check( null !== $count && '' === $wpdb->last_error, 'Plan dependencies could not be checked.', 'operation_failed' );
            Access::check( 0 === (int) $count, 'Plan has ' . $kind . ' references. Enable "Hide from all plans" in Plan Status instead of deleting it.', 'dependency_blocked' );
        }
        $features = $repository->plan_feature_repository->get_query_builder()->where( 'plan_id', $id )->order_by( 'id', 'asc' )->get();
        $apps = $repository->plan_app_configuration_repository->get_query_builder()->where( 'plan_id', $id )->order_by( 'id', 'asc' )->get();
        return [
            'state' => [ 'plan' => (array) $plan, 'features' => $features, 'app_configurations' => $apps ],
            'preview' => [
                'plan_id' => $id, 'before' => Reader::configuration( $plan ), 'permanent' => true,
                'deleted_features' => count( $features ), 'deleted_app_configurations' => count( $apps ),
            ],
            'warnings' => [
                'Permanently deletes the plan and its feature/application configuration rows. This cannot be undone.',
                'Show this deletion preview to the user and stop. Wait for explicit confirmation in a later user reply before deleting this plan. The preview token is not user approval. If the preview expires or changes, show the new preview and ask again.',
            ],
            'execute' => static function () use ( $id, $repository ): array {
                // Native policy is rechecked here. Database constraints remain authoritative.
                Access::check( 1 === $repository->delete_plan( $id ), 'Plan could not be deleted.', 'operation_failed' );
                Access::check( ! directorist_get_pricing_plan_by_id( $id ), 'Plan still exists after deletion.', 'operation_failed' );
                Access::check( ! $repository->plan_feature_repository->get_query_builder()->where( 'plan_id', $id )->count() && ! $repository->plan_app_configuration_repository->get_query_builder()->where( 'plan_id', $id )->count(), 'Native deletion did not clean up plan configuration. No forced cleanup is available.', 'operation_failed' );
                return [ 'plan_id' => $id, 'deleted' => true ];
            },
        ];
    }
}
