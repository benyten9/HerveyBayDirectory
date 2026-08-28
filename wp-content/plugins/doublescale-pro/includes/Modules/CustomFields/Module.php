<?php
/**
 * Custom Fields module — definitions, groups, REST API (Pro).
 *
 * @package DoubleScale\Pro\Modules\CustomFields
 */

namespace DoubleScale\Pro\Modules\CustomFields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Pro\Modules\CustomFields\Abilities\CustomFieldAbilities;

final class Module extends AbstractModule {

	/**
	 * Custom field abilities for the WordPress Abilities API.
	 *
	 * Duck-typed {@see \DoubleScale\Core\Abilities\ProvidesAbilities}: the
	 * method is what the registrar looks for, not the interface, so Pro does
	 * not hard-depend on a free class that older free installs may lack.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return CustomFieldAbilities::definitions();
	}

	public function slug(): string {
		return 'custom-fields';
	}

	public function label(): string {
		return __( 'Custom Fields', 'doublescale' );
	}

	public function description(): string {
		return __( 'Custom field definitions and groups for contacts, deals, and other CRM records.', 'doublescale' );
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function migrations(): array {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return array();
		}
		$base = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/CustomFields/Migrations/';
		return array(
			$base . 'CustomFieldsGroupsTable.php',
			$base . 'CustomFieldsTable.php',
			$base . 'CustomFieldRelationshipTable.php',
		);
	}

	public function boot( Container $container ): void {
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			foreach ( glob( DOUBLESCALE_PLUGIN_DIR . 'includes/Core/Fields/Types/*.php' ) ?: array() as $file ) {
				require_once $file;
			}
		}
		parent::boot( $container );
	}

	public function restControllers(): array {
		return array(
			Rest\RestCustomFieldController::class,
			Rest\RestCustomFieldsGroupController::class,
		);
	}
}
