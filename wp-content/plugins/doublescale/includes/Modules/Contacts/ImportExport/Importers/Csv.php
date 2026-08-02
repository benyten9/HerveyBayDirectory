<?php
/**
 * Csv Importer
 *
 * This class is responsible for handling the Csv importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use League\Csv\Reader;
use League\Csv\Statement;
use DoubleScale\Modules\Contacts\ImportExport\Security;

/**
 * Csv Importer class
 */
class Csv extends Importer {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Csv';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'csv';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = false;

	/**
	 * Mapping
	 *
	 * @var array
	 */
	protected $mapping;

	/**
	 * File name
	 *
	 * @var string
	 */
	protected $file_name;

	/**
	 * Constructor
	 *
	 * @param array $args args
	 */
	public function __construct( $args = array() ) {
		parent::__construct( $args );

		$this->mapping   = $args['mapping'] ?? array();
		$this->file_name = $args['file_name'] ?? '';
	}

	/**
	 * Run importer
	 */
	public function run() {
		if ( ! Security::prepare_upload_dir() ) {
			throw new \Exception( esc_html__( 'Could not create the import working directory.', 'doublescale' ) );
		}

		$mapping = $this->build_mapping( $this->mapping );
		if ( ! isset( $mapping['email'] ) || empty( $mapping['email'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( esc_html__( 'Email field is required.', 'doublescale' ) );
		}

		$file_path = Security::get_upload_file_path( $this->file_name );
		$csv       = Reader::createFromPath( $file_path, 'r' );
		$csv->setHeaderOffset( 0 );
		$total = count( $csv );

		$result = $this->import_with_offset(
			$total,
			$this->offset,
			function ( $offset ) use ( $csv ) {
				$stmt        = ( new Statement() )->offset( $offset )->limit( 20 );
				$subscribers = $stmt->process( $csv );
				return $subscribers;
			},
			$mapping
		);

		if ( 'completed' === $result['status'] ) {
			wp_delete_file( $file_path );
		}

		return $result;
	}

	/**
	 * Build the contact-field => csv-column mapping.
	 *
	 * The UI submits the mapping the other way round, as csv-column =>
	 * contact-field, with unmapped columns carrying an empty string (the
	 * "None" option). array_flip() cannot be used here: every unmapped
	 * column shares the same empty value, so flipping collapses them into a
	 * single '' key and each collision silently overwrites the entry before
	 * it — dropping real mappings (typically first_name, the earliest row)
	 * without any error.
	 *
	 * Unmapped columns are skipped, and two columns targeting the same
	 * contact field is reported rather than silently resolved.
	 *
	 * @param array $mapping Raw mapping as csv-column => contact-field.
	 *
	 * @throws \Exception If two columns are mapped to the same contact field.
	 *
	 * @return array Mapping as contact-field => csv-column.
	 */
	protected function build_mapping( $mapping ) {
		$built = array();

		foreach ( (array) $mapping as $column => $field ) {
			// The "None" option submits an empty string; skip unmapped columns.
			if ( null === $field || '' === $field ) {
				continue;
			}

			if ( isset( $built[ $field ] ) ) {
				throw new \Exception(
					sprintf(
						/* translators: 1: first CSV column name, 2: second CSV column name, 3: contact field name. */
						esc_html__( 'The columns "%1$s" and "%2$s" are both mapped to "%3$s". Each contact field can only be mapped once.', 'doublescale' ),
						esc_html( $built[ $field ] ),
						esc_html( $column ),
						esc_html( $field )
					)
				);
			}

			$built[ $field ] = $column;
		}

		return $built;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'file_name' => array(
				'label'      => __( 'File Name', 'doublescale' ),
				'type'       => 'file',
				'conditions' => array(
					'relation' => 'and',
					'rules'    => array(
						array(
							'field'    => 'file_name',
							'operator' => 'empty',
						),
					),
				),
			),
			'mapping'   => array(
				'label'      => __( 'Mapping', 'doublescale' ),
				'type'       => 'contact_mapped_fields',
				'conditions' => array(
					'relation' => 'and',
					'rules'    => array(
						array(
							'field'    => 'file_name',
							'operator' => 'not_empty',
						),
					),
				),
			),
		);
	}
}
