<?php
/**
 * Private listing import routes and durable background wake handlers.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

use DirectoristAIPro\Storage\Database;

/** All native writes remain behind the exact import review. */
final class ImportModule {

	/**
	 * Site runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind the site's runtime storage.
	 *
	 * @param Database $db Site runtime connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/** Register private routes and ID-only queue callbacks. */
	public function hooks(): void {
		$service = new ImportService( $this->db );
		$bridge  = new ImportModelBridge( $this->db );
		$bridge->hooks();
		add_action(
			'rest_api_init',
			function () use ( $bridge ): void {
				( new ImportApi( $this->db, array( $bridge, 'suggest_rest' ) ) )->register();
			}
		);
		add_action( 'directorist_ai_pro_import', array( $service, 'process' ), 10, 1 );
		add_action( 'directorist_ai_pro_recover', array( $service, 'recover' ) );
		add_action( 'directorist_ai_pro_lifecycle', array( $service, 'cleanup' ) );
	}
}
