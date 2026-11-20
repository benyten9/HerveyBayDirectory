<?php
/** Native period columns and meanings shared by admin reports and scoped MCP reads. */
namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

final class MetricDefinitions {
    public static function period(): array {
        return [
            'views'              => [ 'native_key' => 'total_views', 'definition' => 'Sum of native daily view events; a period measure, independent of lifetime listing counters.' ],
            'unique_views'       => [ 'native_key' => 'unique_views', 'definition' => 'Sum of native daily unique-view increments, per listing. Not distinct people across dates or listings.' ],
            'clicks_total'       => [ 'native_key' => 'total_clicks', 'definition' => 'Sum of native daily contact-form, website, phone and email click events.' ],
            'search_appearances' => [ 'native_key' => 'search_appearances', 'definition' => 'Sum of native daily listing appearances in search results; excludes site search phrases and author statistics.' ],
        ];
    }

    public static function native_select(): array {
        $select = [];
        foreach ( self::period() as $column => $definition ) {
            $select[] = 'SUM(' . $column . ') as ' . $definition['native_key'];
        }
        foreach ( [ 'clicks_contact_form', 'clicks_website', 'clicks_phone', 'clicks_email' ] as $column ) {
            $select[] = 'SUM(' . $column . ') as ' . $column;
        }
        return $select;
    }
}
