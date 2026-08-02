<?php
/**
 * Entity report descriptor.
 *
 * Describes the *shape* of a reportable entity — which column holds the owner,
 * the amount, the reporting date, and where status comes from. Anything
 * expressible as "which column" belongs here; anything expressible as "which
 * query shape" belongs in an EntityReportService subclass.
 *
 * The owner column is the highest-risk field in the reporting stack: the six
 * entities use four different names (owner_id / assigned_user_id /
 * sale_agent_user_id / assigned_to) and getting it wrong leaks another user's
 * records. It is exposed read-only and applied through exactly one code path
 * (EntityReportService::apply_owner_scope), never by hand.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Support
 */

namespace DoubleScale\Pro\Modules\Analytics\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable per-entity reporting metadata.
 */
final class EntityReportDescriptor {

	/**
	 * Status lives in a varchar column validated by a constants class.
	 */
	const STATUS_ENUM = 'enum';

	/**
	 * Status lives in a related table (projects -> doublescale_project_statuses).
	 */
	const STATUS_RELATION = 'relation';

	const PROJECTS     = 'projects';
	const PROPOSALS    = 'proposals';
	const CONTRACTS    = 'contracts';
	const INVOICES     = 'invoices';
	const CREDIT_NOTES = 'credit-notes';
	const TASKS        = 'tasks';

	/**
	 * @var array<string, mixed>
	 */
	private $config;

	/**
	 * @param array<string, mixed> $config Descriptor fields.
	 */
	private function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * All supported entity keys.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array( self::PROJECTS, self::PROPOSALS, self::CONTRACTS, self::INVOICES, self::CREDIT_NOTES, self::TASKS );
	}

	/**
	 * Resolve the descriptor for an entity key.
	 *
	 * Single source of truth: controller and service both resolve from here, so
	 * they cannot disagree about which column means what.
	 *
	 * @param string $key Entity key.
	 * @return self
	 * @throws \InvalidArgumentException When the key is unknown.
	 */
	public static function for_key( $key ) {
		$map = self::definitions();

		if ( ! isset( $map[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Unknown report entity: ' . (string) $key );
		}

		return new self( $map[ $key ] );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions() {
		return array(
			self::PROJECTS     => array(
				'key'              => self::PROJECTS,
				'label'            => __( 'Projects', 'doublescale' ),
				'model_class'      => 'DoubleScale\\Pro\\Modules\\Projects\\Models\\ProjectModel',
				'owner_column'     => 'owner_id',
				'amount_column'    => 'budget',
				'date_column'      => 'created_at',
				'status_source'    => self::STATUS_RELATION,
				'status_column'    => 'status_id',
				'status_relation'  => 'status',
				'status_constants' => null,
				'has_trash_flag'   => false,
				'has_currency'     => false,
				'module_slug'      => 'projects',
			),
			self::PROPOSALS    => array(
				'key'              => self::PROPOSALS,
				'label'            => __( 'Proposals', 'doublescale' ),
				'model_class'      => 'DoubleScale\\Modules\\Documents\\Models\\ProposalModel',
				'owner_column'     => 'assigned_user_id',
				'amount_column'    => 'total',
				'date_column'      => 'date',
				'status_source'    => self::STATUS_ENUM,
				'status_column'    => 'status',
				'status_relation'  => null,
				'status_constants' => 'DoubleScale\\Modules\\Documents\\Constants\\ProposalStatus',
				'has_trash_flag'   => false,
				'has_currency'     => true,
				'module_slug'      => 'documents',
			),
			self::CONTRACTS    => array(
				'key'              => self::CONTRACTS,
				'label'            => __( 'Contracts', 'doublescale' ),
				'model_class'      => 'DoubleScale\\Pro\\Modules\\Contracts\\Models\\ContractModel',
				'owner_column'     => 'assigned_user_id',
				'amount_column'    => 'contract_value',
				'date_column'      => 'created_at',
				'status_source'    => self::STATUS_ENUM,
				'status_column'    => 'status',
				'status_relation'  => null,
				'status_constants' => 'DoubleScale\\Pro\\Modules\\Contracts\\Constants\\ContractStatus',
				'has_trash_flag'   => true,
				'has_currency'     => true,
				'module_slug'      => 'contracts',
			),
			self::INVOICES     => array(
				'key'              => self::INVOICES,
				'label'            => __( 'Invoices', 'doublescale' ),
				'model_class'      => 'DoubleScale\\Modules\\Documents\\Models\\InvoiceModel',
				'owner_column'     => 'sale_agent_user_id',
				'amount_column'    => 'total',
				'date_column'      => 'invoice_date',
				'status_source'    => self::STATUS_ENUM,
				'status_column'    => 'status',
				'status_relation'  => null,
				'status_constants' => 'DoubleScale\\Modules\\Documents\\Constants\\InvoiceStatus',
				'has_trash_flag'   => false,
				'has_currency'     => true,
				'module_slug'      => 'documents',
			),
			self::CREDIT_NOTES => array(
				'key'              => self::CREDIT_NOTES,
				'label'            => __( 'Credit Notes', 'doublescale' ),
				'model_class'      => 'DoubleScale\\Pro\\Modules\\CreditNotes\\Models\\CreditNoteModel',
				'owner_column'     => 'sale_agent_user_id',
				'amount_column'    => 'total',
				'date_column'      => 'credit_note_date',
				'status_source'    => self::STATUS_ENUM,
				'status_column'    => 'status',
				'status_relation'  => null,
				'status_constants' => 'DoubleScale\\Pro\\Modules\\CreditNotes\\Constants\\CreditNoteStatus',
				'has_trash_flag'   => false,
				'has_currency'     => true,
				'module_slug'      => 'credit_notes',
			),
			self::TASKS        => array(
				'key'              => self::TASKS,
				'label'            => __( 'Tasks', 'doublescale' ),
				'model_class'      => 'DoubleScale\\Pro\\Modules\\Tasks\\Models\\TaskModel',
				'owner_column'     => 'assigned_to',
				'amount_column'    => null,
				'date_column'      => 'created_at',
				'status_source'    => self::STATUS_ENUM,
				'status_column'    => 'status',
				'status_relation'  => null,
				'status_constants' => 'DoubleScale\\Core\\Constants\\TaskStatus',
				'has_trash_flag'   => false,
				'has_currency'     => false,
				'module_slug'      => 'tasks',
			),
		);
	}

	/**
	 * @return string
	 */
	public function key() {
		return $this->config['key'];
	}

	/**
	 * @return string
	 */
	public function label() {
		return $this->config['label'];
	}

	/**
	 * @return string
	 */
	public function model_class() {
		return $this->config['model_class'];
	}

	/**
	 * Column holding the record owner. Read-only by design.
	 *
	 * @return string
	 */
	public function owner_column() {
		return $this->config['owner_column'];
	}

	/**
	 * @return string|null
	 */
	public function amount_column() {
		return $this->config['amount_column'];
	}

	/**
	 * @return string
	 */
	public function date_column() {
		return $this->config['date_column'];
	}

	/**
	 * @return string
	 */
	public function status_source() {
		return $this->config['status_source'];
	}

	/**
	 * @return string|null
	 */
	public function status_column() {
		return $this->config['status_column'];
	}

	/**
	 * @return string|null
	 */
	public function status_relation() {
		return $this->config['status_relation'];
	}

	/**
	 * @return string|null
	 */
	public function status_constants() {
		return $this->config['status_constants'];
	}

	/**
	 * @return bool
	 */
	public function has_trash_flag() {
		return (bool) $this->config['has_trash_flag'];
	}

	/**
	 * @return bool
	 */
	public function has_currency() {
		return (bool) $this->config['has_currency'];
	}

	/**
	 * @return string|null
	 */
	public function module_slug() {
		return $this->config['module_slug'];
	}

	/**
	 * @return bool
	 */
	public function has_enum_status() {
		return self::STATUS_ENUM === $this->status_source();
	}

	/**
	 * Valid status values for an enum-backed entity.
	 *
	 * @return string[] Empty for relation-backed statuses (projects).
	 */
	public function status_values() {
		$constants = $this->status_constants();
		if ( ! $constants || ! class_exists( $constants ) || ! method_exists( $constants, 'all' ) ) {
			return array();
		}

		return (array) $constants::all();
	}

	/**
	 * Human label for an enum status value.
	 *
	 * @param string $status Status value.
	 * @return string
	 */
	public function status_label( $status ) {
		$constants = $this->status_constants();
		if ( $constants && class_exists( $constants ) && method_exists( $constants, 'get_label' ) ) {
			return (string) $constants::get_label( $status );
		}

		return ucfirst( (string) $status );
	}

	/**
	 * Whether a status value is valid for this entity.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public function is_valid_status( $status ) {
		$constants = $this->status_constants();
		if ( $constants && class_exists( $constants ) && method_exists( $constants, 'is_valid' ) ) {
			return (bool) $constants::is_valid( $status );
		}

		return false;
	}

	/**
	 * Whether the underlying model is loadable in this installation.
	 *
	 * Guards against a pro module being absent or disabled at the class level.
	 *
	 * @return bool
	 */
	public function model_exists() {
		return class_exists( $this->model_class() );
	}
}
