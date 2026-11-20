<?php
/**
 * Country Stat Daily Model
 *
 * Represents aggregated daily country statistics.
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
 * Country Stat Daily Model Class
 *
 * @since 1.0.0
 */
class CountryStatDaily extends Model {

	/**
	 * Get table name
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_table_name(): string {
		return 'directorist_analytics_country_stats_daily';
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