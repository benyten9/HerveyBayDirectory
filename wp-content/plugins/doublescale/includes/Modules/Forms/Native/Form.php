<?php
/**
 * DoubleScale's own forms as one more form source next to Contact Form 7, WPForms and the
 * rest: the "Form submitted" automation trigger, its form picker, field merge tags and
 * field rules all come from here.
 *
 * Unlike the connected plugins, the contact is made by the submit pipeline (Submission),
 * so automations get that contact instead of mapping their own.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Native form source.
 */
class Form extends Abstracts_Form {

	public const SLUG = FormModel::NATIVE_TYPE;

	/**
	 * Slug.
	 *
	 * @var string
	 */
	public $slug = self::SLUG;

	/**
	 * Name.
	 *
	 * @var string
	 */
	public $name = 'DoubleScale Forms';

	/**
	 * Description.
	 *
	 * @var string
	 */
	public $description = 'Runs when someone submits a form built with DoubleScale.';

	/**
	 * Contact of the submission being run through automations.
	 *
	 * @var mixed
	 */
	private $current_contact = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function load_hooks() {
		$this->name        = __( 'DoubleScale Forms', 'doublescale' );
		$this->description = __( 'Runs when someone submits a form built with DoubleScale.', 'doublescale' );

		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_form_select_options", array( $this, 'ajax_get_form_select_options' ) );
	}

	/**
	 * The trigger's settings: which DoubleScale form.
	 *
	 * @return array
	 */
	public function get_form_options() {
		$options                     = parent::get_form_options();
		$options['form_id']['label'] = __( 'Form', 'doublescale' );
		return $options;
	}

	/**
	 * Fields of a form: key => { label, type }.
	 *
	 * @param mixed $form_id Form row id.
	 * @return array
	 */
	public function get_fields( $form_id ) {
		$form = FormModel::find( (int) $this->get_form_id( (string) $form_id ) );
		if ( ! $form || ! $form->is_native() ) {
			return array();
		}
		return Schema::field_list( Schema::effective( $form )['schema'] );
	}

	/**
	 * AJAX: fields of a form (automation builder).
	 *
	 * @return void
	 */
	public function ajax_get_fields() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );
		if ( ! \DoubleScale\Core\UserRoles\Permissions::has_crm_manager_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'doublescale' ) ), 403 );
		}
		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		wp_send_json_success( $this->get_fields( $form_id ) );
	}

	/**
	 * AJAX: DoubleScale forms to pick from (automation builder).
	 *
	 * @return void
	 */
	public function ajax_get_form_select_options() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );
		if ( ! \DoubleScale\Core\UserRoles\Permissions::has_crm_manager_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'doublescale' ) ), 403 );
		}
		$options = array();
		foreach ( FormModel::where( 'form_type', self::SLUG )->orderBy( 'name' )->get() as $form ) {
			// Automations need a contact: say which forms can't make one.
			$options[ $form->id ] = 'ok' === Contacts::readiness( $form )['status']
				? $form->name
				/* translators: %s: form name */
				: sprintf( __( '%s (doesn’t create contacts)', 'doublescale' ), $form->name );
		}
		wp_send_json_success( $options );
	}

	/**
	 * Run the "DoubleScale form submitted" automations for a submission.
	 *
	 * @param FormModel $form       Form.
	 * @param mixed     $submission Submission model.
	 * @param mixed     $contact    Contact model or null (automations need a contact).
	 * @param array     $fields     Input fields keyed by id.
	 * @param array     $answers    Answers keyed by key.
	 * @return void
	 */
	public function run_automations( FormModel $form, $submission, $contact, array $fields, array $answers ): void {
		if ( ! $contact || ! doublescale_module_data_ready( 'automations' ) ) {
			return;
		}

		$field_list = array();
		foreach ( $fields as $field ) {
			$field_list[ $field['key'] ] = array(
				'label' => '' !== $field['label'] ? $field['label'] : $field['key'],
				'type'  => $field['type'],
			);
		}

		$this->form_data       = $form;
		$this->current_contact = $contact;

		$this->process_automations(
			array(
				'form_id'       => (int) $form->id,
				'form_title'    => (string) $form->name,
				'entry_id'      => (int) $submission->id,
				'submission_id' => (int) $submission->id,
				'contact_id'    => (int) $contact->id,
				'fields'        => $field_list,
				'entry'         => array(
					'fields' => $answers,
				),
			)
		);

		$this->current_contact = null;
	}

	/**
	 * The submission already made the contact; automations use it as it is.
	 *
	 * @param AutomationModel $automation Automation.
	 * @return mixed
	 */
	public function maybe_create_contact( AutomationModel $automation ) {
		unset( $automation );
		return $this->current_contact;
	}

	/**
	 * Native forms never go through the connected-plugin path.
	 *
	 * @param array $data Submission data.
	 * @return void
	 */
	public function process_form( $data ) {
		unset( $data );
	}

	/**
	 * Register the background step of the submit pipeline.
	 *
	 * @return void
	 */
	public static function register_tasks(): void {
		PluginKernel::instance()->forms_tasks->register_callback( Submission::TASK, array( Submission::class, 'after_submit' ), true );
		PluginKernel::instance()->forms_tasks->register_callback( Partial::TASK, array( Partial::class, 'check' ), true );
	}
}
