<?php
/**
 * View Model
 *
 * Represents listing view records with visitor metadata.
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
 * View Model Class
 *
 * @since 1.0.0
 */
class View extends Model {

	/**
	 * Get table name
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_table_name(): string {
		return 'directorist_analytics_views';
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