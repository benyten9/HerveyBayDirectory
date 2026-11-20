<?php
/**
 * Search Engine Stat Daily Model
 *
 * Represents aggregated daily search engine statistics.
 *
 * @package DirectoristAnalytics
 * @subpackage Models
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Models;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\WpMVC\App;
use DirectoristAnalytics\WpMVC\Database\Eloquent\Model;
use DirectoristAnalytics\WpMVC\Database\Resolver;

/**
 * Search Engine Stat Daily Model Class
 *
 * @since 1.0.0
 */
class SearchEngineStatDaily extends Model {

	/**
	 * Get table name
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_table_name(): string {
		return 'directorist_analytics_search_engine_stats_daily';
	}

	/**
	 * Get database resolver
	 *
	 * @since 1.0.0
	 * @return Resolver
	 */
	public function resolver(): Resolver {
		return App::$container->get( Resolver::class );
	}
}