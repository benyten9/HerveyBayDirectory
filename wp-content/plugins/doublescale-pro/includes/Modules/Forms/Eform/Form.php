<?php

/**
 * Class eForm
 * This class is responsible for handling the integration of eForm (wp-fsqm-pro)
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Eform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * Eform class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'eform';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'eForm';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'This will trigger when a form is submitted';

	/**
	 * Load Hooks
	 */
	public function load_hooks() {
		add_action( 'ipt_fsqm_hook_save_insert', array( $this, 'process' ), 10, 1 );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_form_select_options", array( $this, 'ajax_get_form_select_options' ) );
	}

	/**
	 * Is Enabled
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return doublescale_is_plugin_active( 'wp-fsqm-pro/ipt_fsqm.php' );
	}

	/**
	 * Get Fields
	 *
	 * Retrieves all mappable fields for a given eForm form.
	 * eForm stores element definitions in three serialized columns: mcq, freetype, pinfo.
	 * Built-in contact fields (f_name, l_name, email, phone) are top-level data columns.
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return array
	 */
	public function get_fields( $form_id ) {
		global $wpdb, $ipt_fsqm_info;

		$fields = array();

		if ( ! isset( $ipt_fsqm_info['form_table'] ) ) {
			return $fields;
		}

		$form = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT mcq, freetype, pinfo FROM {$ipt_fsqm_info['form_table']} WHERE id = %d",
				$form_id
			)
		);

		if ( empty( $form ) ) {
			return $fields;
		}

		$fields['f_name'] = array(
			'label' => __( 'First Name', 'doublescale'),
			'type'  => 'text',
		);
		$fields['l_name'] = array(
			'label' => __( 'Last Name', 'doublescale'),
			'type'  => 'text',
		);
		$fields['email'] = array(
			'label' => __( 'Email', 'doublescale'),
			'type'  => 'email',
		);
		$fields['phone'] = array(
			'label' => __( 'Phone', 'doublescale'),
			'type'  => 'tel',
		);

		$categories = array(
			'freetype' => maybe_unserialize( $form->freetype ),
			'mcq'      => maybe_unserialize( $form->mcq ),
			'pinfo'    => maybe_unserialize( $form->pinfo ),
		);

		$builtin_pinfo_types = array( 'f_name', 'l_name', 'email', 'phone', 'p_name', 'p_email', 'p_phone' );

		foreach ( $categories as $m_type => $elements ) {
			if ( ! is_array( $elements ) ) {
				continue;
			}

			foreach ( $elements as $idx => $el ) {
				if ( ! is_array( $el ) || empty( $el['type'] ) || empty( $el['title'] ) ) {
					continue;
				}

				if ( in_array( $el['type'], $builtin_pinfo_types, true ) ) {
					continue;
				}

				$field_key            = $m_type . '_' . $idx;
				$fields[ $field_key ] = array(
					'label' => $el['title'],
					'type'  => $el['type'],
				);
			}
		}

		return $fields;
	}

	/**
	 * Ajax Get Fields
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_fields() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		if ( ! $this->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'eForm is not active', 'doublescale') ) );
			return;
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( empty( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form ID', 'doublescale') ) );
		}

		$fields = $this->get_fields( $form_id );

		wp_send_json_success( $fields );
	}

	/**
	 * Ajax Get Form Select Options
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_form_select_options() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		if ( ! $this->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'eForm is not active', 'doublescale') ) );
			return;
		}

		global $wpdb, $ipt_fsqm_info;

		if ( ! isset( $ipt_fsqm_info['form_table'] ) ) {
			wp_send_json_error( array( 'message' => __( 'eForm tables not found', 'doublescale') ) );
			return;
		}

		$forms = $wpdb->get_results(
			"SELECT id, name FROM {$ipt_fsqm_info['form_table']} ORDER BY name ASC"
		);

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'No forms found', 'doublescale') ) );
			return;
		}

		$options = array();
		foreach ( $forms as $form ) {
			$options[ $form->id ] = $form->name;
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * Triggered on ipt_fsqm_hook_save_insert. The $data parameter is a reference
	 * to IPT_FSQM_Form_Elements_Data which must NOT be passed to process_form()
	 * directly -- Action Scheduler would fail to serialize it.
	 *
	 * @since 1.0.0
	 *
	 * @param object $data IPT_FSQM_Form_Elements_Data object reference.
	 *
	 * @return void
	 */
	public function process( $data ) {
		try {
			if ( ! is_object( $data ) || ! isset( $data->form_id ) ) {
				return;
			}

			$form_id      = $data->form_id;
			$entry_fields = array();

			$entry_fields['f_name'] = $data->data->f_name ?? '';
			$entry_fields['l_name'] = $data->data->l_name ?? '';
			$entry_fields['email']  = $data->data->email ?? '';
			$entry_fields['phone']  = $data->data->phone ?? '';

			foreach ( (array) ( $data->data->freetype ?? array() ) as $idx => $el ) {
				$entry_fields[ 'freetype_' . $idx ] = is_array( $el ) ? ( $el['value'] ?? '' ) : (string) $el;
			}

			foreach ( (array) ( $data->data->mcq ?? array() ) as $idx => $el ) {
				$entry_fields[ 'mcq_' . $idx ] = $this->extract_mcq_value( $el );
			}

			foreach ( (array) ( $data->data->pinfo ?? array() ) as $idx => $el ) {
				$entry_fields[ 'pinfo_' . $idx ] = is_array( $el ) ? ( $el['value'] ?? '' ) : (string) $el;
			}

			$std_data               = $this->get_default_data();
			$std_data['form_id']    = $form_id;
			$std_data['entry_id']   = $data->data_id ?? null;
			$std_data['form_title'] = ! empty( $data->name ) ? $data->name : $this->get_form_title_fallback( $form_id );
			$std_data['fields']     = $this->get_fields( $form_id );
			$std_data['entry']      = array(
				'fields' => $entry_fields,
			);

			if ( $this->is_form_active( $form_id ) ) {
				$this->process_form( $std_data );
			}

			$this->process_automations( $std_data );
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error processing eForm submission', 'doublescale'),
				array(
					'code'     => 'eform_process_error',
					'form_id'  => $data->form_id ?? null,
					'entry_id' => $data->data_id ?? null,
					'error'    => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
		}
	}

	/**
	 * Extract a display value from an MCQ submission element.
	 *
	 * MCQ elements store data in different keys depending on subtype:
	 * - options (array): radio, select, checkbox, starrating, scalerating, spinners, grading, thumbselect, pricing_table
	 * - value (scalar): slider, likedislike, toggle
	 * - option (string): smileyrating
	 * - values (assoc {min,max}): range
	 * - rows (array of arrays): matrix, matrix_dropdown
	 * - order (array): sorting
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $el MCQ submission element.
	 *
	 * @return string
	 */
	protected function extract_mcq_value( $el ) {
		if ( ! is_array( $el ) ) {
			return (string) $el;
		}

		if ( isset( $el['options'] ) && is_array( $el['options'] ) ) {
			$values = array_filter( array_map( 'strval', $el['options'] ) );
			$result = implode( ', ', $values );
			if ( ! empty( $el['others'] ) ) {
				$result .= ( $result ? ', ' : '' ) . $el['others'];
			}
			return $result;
		}

		if ( isset( $el['value'] ) ) {
			return is_array( $el['value'] )
				? implode( ', ', array_filter( array_map( 'strval', $el['value'] ) ) )
				: (string) $el['value'];
		}

		if ( isset( $el['option'] ) ) {
			return (string) $el['option'];
		}

		if ( isset( $el['values'] ) && is_array( $el['values'] ) ) {
			return ( $el['values']['min'] ?? '' ) . ' - ' . ( $el['values']['max'] ?? '' );
		}

		if ( isset( $el['rows'] ) && is_array( $el['rows'] ) ) {
			return wp_json_encode( $el['rows'] );
		}

		if ( isset( $el['order'] ) && is_array( $el['order'] ) ) {
			return implode( ', ', $el['order'] );
		}

		return '';
	}

	/**
	 * Fallback form title from the database when $data->name is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id eForm form ID.
	 *
	 * @return string
	 */
	protected function get_form_title_fallback( $form_id ) {
		global $wpdb, $ipt_fsqm_info;

		if ( ! isset( $ipt_fsqm_info['form_table'] ) ) {
			return '';
		}

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$ipt_fsqm_info['form_table']} WHERE id = %d",
				$form_id
			)
		);
	}
}
