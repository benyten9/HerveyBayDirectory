<?php

namespace SEOPressPro\Core;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Container\ContainerSeopress;
use SEOPress\Core\Hooks\ActivationHook;
use SEOPress\Core\Hooks\DeactivationHook;
use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPress\Core\Hooks\ExecuteHooksBackend;
use SEOPress\Core\Hooks\ExecuteHooksFrontend;

abstract class Kernel {

	protected static $container = null;

	protected static $data = array(
		'slug'      => null,
		'main_file' => null,
		'file'      => null,
		'root'      => null,
	);

	public static function setContainer( ManageContainer $container ) {
		self::$container = self::getDefaultContainer();
	}

	protected static function getDefaultContainer() {
		return new ContainerSeopress();
	}

	public static function getContainer() {
		if ( null === self::$container ) {
			self::$container = self::getDefaultContainer();
		}

		return self::$container;
	}

	public static function handleHooksPlugin() {
		switch ( current_filter() ) {
			case 'plugins_loaded':
				// Registering an action whose callback reaches into the free
				// plugin is what turns a deactivated or mid-update free plugin
				// into a fatal error: the callback fires later in this very
				// request, long after Pro decided to deactivate itself. Arming
				// nothing at all is the only thing that holds for every
				// callback, rather than guarding them one by one.
				if ( function_exists( 'seopress_pro_is_free_runtime_available' ) && ! seopress_pro_is_free_runtime_available() ) {
					break;
				}

				foreach ( self::getContainer()->getActions() as $key => $class ) {
					try {
						if ( ! class_exists( $class ) ) {
							continue;
						}

						$class = new $class();
						switch ( true ) {
							case $class instanceof ExecuteHooksBackend:
								if ( is_admin() ) {
									$class->hooks();
								}
								break;

							case $class instanceof ExecuteHooksFrontend:
								if ( ! is_admin() ) {
									$class->hooks();
								}
								break;

							case $class instanceof ExecuteHooks:
								$class->hooks();
								break;
						}
					} catch ( \Throwable $e ) {
						// Skip any class that cannot be loaded or instantiated.
						// class_exists() autoloads the file, so a class whose
						// parent interface/class is momentarily unavailable (an
						// in-progress plugin update swapping files, a stale
						// opcache, a partial deploy) throws \Error, not
						// \Exception. Catching \Throwable keeps one broken class
						// from white-screening the whole site during that window.
					}
				}
				break;
			case 'activate_' . self::$data['slug'] . '/' . self::$data['main_file'] . '.php':
				foreach ( self::getContainer()->getActions() as $key => $class ) {
					try {
						if ( ! class_exists( $class ) ) {
							continue;
						}
						$class = new $class();
						if ( $class instanceof ActivationHook ) {
							$class->activate();
						}
					} catch ( \Throwable $e ) {
						// Skip any class that cannot be loaded or instantiated.
						// class_exists() autoloads the file, so a class whose
						// parent interface/class is momentarily unavailable (an
						// in-progress plugin update swapping files, a stale
						// opcache, a partial deploy) throws \Error, not
						// \Exception. Catching \Throwable keeps one broken class
						// from white-screening the whole site during that window.
					}
				}
				break;
			case 'deactivate_' . self::$data['slug'] . '/' . self::$data['main_file'] . '.php':
				foreach ( self::getContainer()->getActions() as $key => $class ) {
					try {
						if ( ! class_exists( $class ) ) {
							continue;
						}
						if ( $class instanceof DeactivationHook ) {
							$class->deactivate();
						}
					} catch ( \Throwable $e ) {
						// Skip any class that cannot be loaded or instantiated.
						// class_exists() autoloads the file, so a class whose
						// parent interface/class is momentarily unavailable (an
						// in-progress plugin update swapping files, a stale
						// opcache, a partial deploy) throws \Error, not
						// \Exception. Catching \Throwable keeps one broken class
						// from white-screening the whole site during that window.
					}
				}
				break;
		}
	}

	/**
	 * @static
	 *
	 * @return void
	 */
	public static function buildContainer() {
		self::buildClasses( self::$data['root'] . '/src/Services', 'services', 'Services\\' );
		self::buildClasses( self::$data['root'] . '/src/Thirds', 'services', 'Thirds\\' );
		self::buildClasses( self::$data['root'] . '/src/Actions', 'actions', 'Actions\\' );
	}

	/**
	 * @static
	 *
	 * @param string $path
	 * @param string $type
	 * @param string $namespace
	 *
	 * @return void
	 */
	public static function buildClasses( $path, $type, $namespace = '' ) {
		try {
			$files = array_diff( scandir( $path ), array( '..', '.' ) );
			foreach ( $files as $filename ) {
				$pathCheck = $path . '/' . $filename;
				if ( is_dir( $pathCheck ) ) {
					self::buildClasses( $pathCheck, $type, $namespace . $filename . '\\' );
					continue;
				}

				$pathinfo = pathinfo( $filename );
				if ( isset( $pathinfo['extension'] ) && 'php' !== $pathinfo['extension'] ) {
					continue;
				}

				$data = '\\SEOPressPro\\' . $namespace . str_replace( '.php', '', $filename );

				switch ( $type ) {
					case 'services':
						self::getContainer()->setService( $data );
						break;
					case 'actions':
						self::getContainer()->setAction( $data );
						break;
				}
			}
		} catch ( \Throwable $e ) {
			// Keep building the container even if one file cannot be scanned
			// or read (mid-update file swap, permissions): a single bad entry
			// must not abort registration of every other class.
		}
	}

	public static function execute( $data ) {
		self::$data = array_merge( self::$data, $data );

		self::buildContainer();

		add_action( 'plugins_loaded', array( __CLASS__, 'handleHooksPlugin' ), 20 );
		register_activation_hook( $data['file'], array( __CLASS__, 'handleHooksPlugin' ), 20 );
		register_deactivation_hook( $data['file'], array( __CLASS__, 'handleHooksPlugin' ), 20 );
	}
}
