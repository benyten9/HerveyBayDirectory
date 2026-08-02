<?php
/**
 * Application kernel (free DoubleScale).
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;

/**
 * @property-read ValidatorFactory $validator
 * @property-read Tasks            $campaigns_tasks
 * @property-read Tasks            $automations_tasks
 * @property-read Tasks            $daily_tasks
 * @property-read Tasks            $forms_tasks
 */
final class PluginKernel {

	private Container $container;

	private ModuleRegistry $module_registry;

	/** @var self|null */
	private static $instance;

	/**
	 * @var array<string, string>
	 */
	private static $property_bindings = array(
		'campaigns_tasks'   => 'tasks.campaigns',
		'automations_tasks' => 'tasks.automations',
		'daily_tasks'       => 'tasks.daily',
		'forms_tasks'       => 'tasks.forms',
		'validator'         => ValidatorFactory::class,
	);

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		self::$instance = $this;

		$container = new Container();
		$container->set_as_global();
		$this->container = $container;

		$this->init_illuminate();

		require_once DOUBLESCALE_PLUGIN_DIR . 'includes/Core/functions.php';

		$this->register_core_services();

		$this->module_registry = new ModuleRegistry( $container );
		$this->module_registry->register( new CoreModule() );
		$this->module_registry->discover( DOUBLESCALE_PLUGIN_DIR . 'includes/Modules' );

		/**
		 * Allow extensions (e.g. DoubleScale Pro) to register additional modules before boot.
		 *
		 * @param ModuleRegistry $module_registry
		 */
		do_action( 'doublescale_register_modules', $this->module_registry );

		$this->module_registry->boot();
	}

	private function register_core_services(): void {
		$this->container->singleton(
			'tasks.campaigns',
			static function () {
				return new Tasks( 'doublescale_campaigns' );
			}
		);
		$this->container->singleton(
			'tasks.automations',
			static function () {
				return new Tasks( 'doublescale_automations' );
			}
		);
		$this->container->singleton(
			'tasks.daily',
			static function () {
				return new Tasks( 'doublescale_daily' );
			}
		);
		$this->container->singleton(
			'tasks.forms',
			static function () {
				return new Tasks( 'doublescale_forms' );
			}
		);
	}

	private function init_illuminate(): void {
		global $wpdb;

		$capsule = new Capsule();

		$capsule->addConnection(
			array(
				'driver'    => 'mysql',
				'host'      => DB_HOST,
				'database'  => DB_NAME,
				'username'  => DB_USER,
				'password'  => DB_PASSWORD,
				'charset'   => DB_CHARSET,
				'collation' => DB_COLLATE,
				'prefix'    => $wpdb->prefix,
			)
		);

		$capsule->setEventDispatcher( new Dispatcher( new IlluminateContainer() ) );
		$capsule->setAsGlobal();
		$capsule->bootEloquent();

		// Defer the validation stack (Translator + Factory) to first use. It is
		// only needed on requests that validate input (mostly REST writes), so
		// building it eagerly here taxed every frontend/cron request. A lazy
		// singleton resolves — and memoizes — it the first time `->validator`
		// (ValidatorFactory) is read from the container.
		$this->container->singleton(
			ValidatorFactory::class,
			static function () {
				return new ValidatorFactory( new Translator( new ArrayLoader(), 'en' ) );
			}
		);
	}

	public function get_container(): Container {
		return $this->container;
	}

	public function get_module_registry(): ModuleRegistry {
		return $this->module_registry;
	}

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		if ( isset( self::$property_bindings[ $name ] ) ) {
			return $this->container->get( self::$property_bindings[ $name ] );
		}
		return null;
	}

	/**
	 * @param string $name Property name.
	 */
	public function __isset( $name ): bool {
		return isset( self::$property_bindings[ $name ] );
	}
}
