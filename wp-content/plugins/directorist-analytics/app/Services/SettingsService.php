<?php
/** One merging writer for native and MCP privacy settings. Never invokes cleanup. */
namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

final class SettingsService {
    public static function defaults(): array {
        return [ 'enable_data_protection' => false, 'respect_do_not_track' => false, 'include_admin_visits' => true, 'anonymize_ips' => false, 'auto_delete_days' => 30 ];
    }

    public static function configured(): array {
        return array_merge( self::defaults(), (array) get_option( PrivacyService::SETTINGS_KEY, [] ) );
    }

    public static function policy( ?array $settings = null ): array {
        $settings  = $settings ?? self::configured();
        $protected = ! empty( $settings['enable_data_protection'] );
        return [
            'configured_retention_days'      => (int) $settings['auto_delete_days'],
            'effective_retention_days'       => $protected ? 30 : absint( $settings['auto_delete_days'] ?: 30 ),
            'data_protection_forces_30_days' => $protected,
            'effective_respect_do_not_track' => $protected || ! empty( $settings['respect_do_not_track'] ),
            'effective_include_admin_visits' => ! $protected && ! empty( $settings['include_admin_visits'] ),
            'effective_anonymize_ips'        => $protected || ! empty( $settings['anonymize_ips'] ),
            'zero_retention_note'            => 'Native zero falls back to 30 days; MCP V1 rejects zero and does not advertise unlimited retention.',
            'cleanup_effect'                 => 'Retention changes affect the next native scheduled raw-log cleanup only. No immediate purge; daily aggregates remain stored.',
        ];
    }

    public static function save( array $changes ): array {
        if ( class_exists( '\Directorist\MCP\Safety\Write_Lock' ) ) {
            \Directorist\MCP\Safety\Write_Lock::native_lock(); }
        if ( array_diff( array_keys( $changes ), array_keys( self::defaults() ) ) ) {
            throw new \InvalidArgumentException( 'Unsupported analytics setting.' ); }
        // Preserve omitted flags and unknown native settings as part of the complete option unit.
        $settings = array_merge( self::configured(), $changes );
        $updated  = update_option( PrivacyService::SETTINGS_KEY, $settings );
        if ( ! $updated && self::configured() !== $settings ) {
            throw new \RuntimeException( 'Analytics settings could not be saved.' ); }
        return $settings;
    }
}
