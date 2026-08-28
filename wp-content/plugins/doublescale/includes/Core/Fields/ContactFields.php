<?php

/**
 * Class ContactFields
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Core\Fields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Fields\Types\RadioField;
use DoubleScale\Core\Fields\Types\TextField;
use DoubleScale\Core\Fields\Types\Boolean_Field;
use DoubleScale\Core\Fields\Types\EmailField;
use DoubleScale\Core\Fields\Types\NumberField;
use DoubleScale\Core\Fields\Types\UrlField;
use DoubleScale\Core\Fields\Types\PhoneField;
use DoubleScale\Core\Fields\Types\DateField;
use DoubleScale\Core\Fields\Types\TextareaField;
use DoubleScale\Core\Fields\Types\SelectField;
use DoubleScale\Core\Fields\Types\MultiselectField;
use DoubleScale\Core\Fields\Types\CheckboxField;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

/**
 * ContactFields class
 */
class ContactFields {

	/**
	 * Fields
	 *
	 * @var array
	 */
	protected $fields = array();

	/**
	 * Types
	 *
	 * @var array
	 */
	protected $types = array(
		'text'        => TextField::class,
		'boolean'     => Boolean_Field::class,
		'email'       => EmailField::class,
		'number'      => NumberField::class,
		'url'         => UrlField::class,
		'phone'       => PhoneField::class,
		'date'        => DateField::class,
		'textarea'    => TextareaField::class,
		'select'      => SelectField::class,
		'multiselect' => MultiselectField::class,
		'checkbox'    => CheckboxField::class,
		'radio'       => RadioField::class,
	);

	/**
	 * Instance
	 *
	 * @var ContactFields
	 */
	protected static $instance = null;

	/**
	 * Get Instance
	 *
	 * @since 1.0.0
	 *
	 * @return ContactFields
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->setup_fields();
	}



	/**
	 * Setup Fields
	 *
	 * @return void
	 */
	private function setup_fields() {
		$this->fields = array(
			'first_name' => array(
				'name'     => __( 'First Name', 'doublescale' ),
				'type'     => $this->types['text'],
				'required' => true,
			),
			'last_name'  => array(
				'name'     => __( 'Last Name', 'doublescale' ),
				'type'     => $this->types['text'],
				'required' => true,
			),
			'company_name' => array(
				'name' => __( 'Company Name', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'company_registration_number' => array(
				'name' => __( 'Company Registration Number', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'tax_vat_number' => array(
				'name' => __( 'Tax / VAT Number', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'email'      => array(
				'name'     => __( 'Email', 'doublescale' ),
				'type'     => $this->types['email'],
				'required' => true,
			),
			'address_1'  => array(
				'name' => __( 'Address 1', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'address_2'  => array(
				'name' => __( 'Address 2', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'city'       => array(
				'name' => __( 'City', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'state'      => array(
				'name' => __( 'State', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'country'    => array(
				'name' => __( 'Country', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'zip'        => array(
				'name' => __( 'Zip', 'doublescale' ),
				'type' => $this->types['number'],
			),
			'phone'      => array(
				'name' => __( 'Phone', 'doublescale' ),
				'type' => $this->types['text'],
			),
			'whatsapp_phone' => array(
				'name' => __( 'WhatsApp Phone', 'doublescale' ),
				'type' => $this->types['phone'],
			),
		);

		if ( class_exists( CustomFieldModel::class ) ) {
			// Only contact-scoped fields belong on a contact; deal/task/project
			// fields have their own storage and would never resolve here.
			$custom_fields = CustomFieldModel::where( 'scope', 'contact' )->get();

			foreach ( $custom_fields as $custom_field ) {
				$this->fields[ $custom_field->id ] = array(
					'name'      => $custom_field->name,
					'type'      => $this->types[ $custom_field->type ],
					'is_custom' => true,
				);
			}
		}
	}

	/**
	 * Get Fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->fields;
	}

	/**
	 * Get field value
	 *
	 * @param string       $field Field.
	 * @param ContactModel $contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_field_value( $field, $contact ) {
		if ( ! isset( $this->fields[ $field ] ) ) {
			return null;
		}

		if ( ! isset( $this->fields[ $field ]['is_custom'] ) ) {
			return $contact->{$field};
		}

		return $contact->get_custom_field( $field );
	}
}
