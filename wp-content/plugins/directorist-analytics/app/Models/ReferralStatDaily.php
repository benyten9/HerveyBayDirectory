<?php
/**
 * Referral Stat Daily Model
 *
 * Represents aggregated daily referral statistics.
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
 * Referral Stat Daily Model Class
 *
 * @since 1.0.0
 */
class ReferralStatDaily extends Model {

	/**
	 * Get table name
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_table_name(): string {
		return 'directorist_analytics_referral_stats_daily';
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