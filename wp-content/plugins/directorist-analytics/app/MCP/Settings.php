<?php

/** Explicit core settings adapter with complete configured/effective policy previews. */
namespace DirectoristAnalytics\App\MCP;

use DirectoristAnalytics\App\Services\SettingsService;
use DirectoristAnalytics\App\Services\PrivacyService;
use Directorist\MCP\Support\Validation;
defined( 'ABSPATH' ) || exit;
final class Settings
{
    public static function policy() : array {
        return SettingsService::policy();
    }

    public static function policy_schema() : array {
        return Schema::object(
            [
                'configured_retention_days'      => Schema::integer(),
                'effective_retention_days'       => Schema::integer(),
                'data_protection_forces_30_days' => ['type' => 'boolean'],
                'effective_respect_do_not_track' => ['type' => 'boolean'],
                'effective_include_admin_visits' => ['type' => 'boolean'],
                'effective_anonymize_ips'        => ['type' => 'boolean'],
                'zero_retention_note'            => Schema::text( 200 ),
                'cleanup_effect'                 => Schema::text( 300 ),
            ]
        );
    }

    public static function configured_schema() : array {
        return Schema::object(
            [
                'enable_data_protection' => ['type' => 'boolean'],
                'respect_do_not_track'   => ['type' => 'boolean'],
                'include_admin_visits'   => ['type' => 'boolean'],
                'anonymize_ips'          => ['type' => 'boolean'],
                'auto_delete_days'       => Schema::integer( 0, 365 ),
            ]
        );
    }

    public static function effects_schema() : array {
        return Schema::object(
            [
                'configured_before' => self::configured_schema(),
                'configured_after'  => self::configured_schema(),
                'effective_before'  => self::policy_schema(),
                'effective_after'   => self::policy_schema(),
                'retention_reduced' => ['type' => 'boolean'],
                'next_cleanup_at'   => Schema::integer(),
            ]
        );
    }

    public static function preview( array $changes ) : array {
        Validation::check( current_user_can( 'manage_options' ), 'Configuration access is required.', 'forbidden' );
        $before           = array_intersect_key( SettingsService::configured(), SettingsService::defaults() );
        $after            = array_merge( $before, $changes );
        $effective_before = SettingsService::policy( $before );
        $effective_after  = SettingsService::policy( $after );
        return [
            'configured_before' => $before,
            'configured_after'  => $after,
            'effective_before'  => $effective_before,
            'effective_after'   => $effective_after,
            'retention_reduced' => $effective_after['effective_retention_days'] < $effective_before['effective_retention_days'],
            'next_cleanup_at'   => (int) wp_next_scheduled( 'directorist_analytics_delete_old_logs' ),
        ];
    }

    public static function write( array $changes ) : void {
        Validation::check( current_user_can( 'manage_options' ), 'Configuration access is required.', 'forbidden' );
        foreach ( $changes as $key => $value ) {
            self::validate( $key, $value );
        }
        SettingsService::save( $changes );
    }

    public static function validate( string $key, $value ) {
        Validation::check( array_key_exists( $key, SettingsService::defaults() ), 'Unsupported analytics setting.' );
        if ( 'auto_delete_days' === $key ) {
            Validation::check( is_int( $value ) && $value >= 1 && $value <= 365, 'Use 1–365 retention days. Native zero is ambiguous and falls back to 30; unlimited retention is unsupported.' );
        } else {
            Validation::check( is_bool( $value ), 'Use a boolean privacy setting.' );
        }
        return $value;
    }

    public static function invalidate() : void {
        foreach ( [
            PrivacyService::SETTINGS_KEY,
            'alloptions',
            'notoptions',
        ] as $key ) {
            wp_cache_delete( $key, 'options' );
        }
    }

    public static function register( $registry ) : void {
        global $wpdb;
        foreach ( SettingsService::defaults() as $key => $default ) {
            $registry->register_field_adapter(
                [
                    'owner'            => 'analytics',
                    'domain'           => 'settings',
                    'key'              => $key,
                    'label'            => ucwords( str_replace( '_', ' ', $key ) ),
                    'group'            => 'analytics/privacy',
                    'contract_version' => Integration::CONTRACT,
                    'schema'           => 'auto_delete_days' === $key ? Schema::integer( 1, 365 ) : ['type' => 'boolean'],
                    'default'          => $default,
                    'capability'       => 'manage_options',
                    'sensitive'        => false,
                    'writable'         => true,
                    'resettable'       => true,
                    'storage_adapter'  => PrivacyService::SETTINGS_KEY,
                    'tables'           => [$wpdb->options],
                    'dependencies'     => [
                        'where'            => 'enable_data_protection',
                        'effective_policy' => 'analytics_privacy_policy',
                        'note'             => 'Data protection forces 30-day retention, DNT, IP anonymization and exclusion of admin visits. See configured and effective preview.',
                    ],
                    'permission'       => static function () {
                        return current_user_can( 'manage_options' );
                    },
                    'read'             => static function () use( $key ) {
                        return SettingsService::configured()[$key];
                    },
                    'validate'         => static function ( $value ) use( $key ) {
                        return self::validate( $key, $value );
                    },
                    'write'            => [self::class, 'write'],
                    'invalidate'       => [self::class, 'invalidate'],
                    'preview_changes'  => [self::class, 'preview'],
                    'effects_schema'   => self::effects_schema(),
                ]
            );
        }
        $registry->register_field_adapter(
            [
                'owner'            => 'analytics',
                'domain'           => 'settings',
                'key'              => 'analytics_privacy_policy',
                'label'            => 'Configured and effective analytics privacy policy',
                'group'            => 'analytics/privacy',
                'contract_version' => Integration::CONTRACT,
                'schema'           => self::policy_schema(),
                'default'          => SettingsService::policy( SettingsService::defaults() ),
                'sensitive'        => false,
                'writable'         => false,
                'capability'       => 'manage_options',
                'permission'       => static function () {
                    return current_user_can( 'manage_options' );
                },
                'read'             => [self::class, 'policy'],
                'validate'         => static function ( $value ) {
                    return $value;
                },
            ]
        );
    }
}
