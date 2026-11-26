<?php

/**
 * Class RestFormController
 * This class is responsible for handling the Form REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;
use DoubleScale\Modules\Forms\Native\Renderer;
use DoubleScale\Modules\Forms\Native\Schema;

/**
 * RestFormController class
 */
class RestFormController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'forms';

	/**
	 * Columns the forms list may be sorted by.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SORTABLE_COLUMNS = array( 'name', 'form_type', 'status', 'created_at', 'updated_at' );

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'IDs of the forms.', 'doublescale'),
							'type'        => 'array',
							'items'       => array(
								'type' => 'integer',
							),
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::READABLE ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get schema for the controller.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		 return array(
			 '$schema'    => 'http://json-schema.org/draft-04/schema#',
			 'title'      => 'form',
			 'type'       => 'object',
			 'properties' => array(
				 'id'         => array(
					 'description' => esc_html__( 'Unique identifier for the object.', 'doublescale'),
					 'type'        => 'integer',
				 ),
				 'name'       => array(
					 'description' => esc_html__( 'Name of the form.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
					 'required'    => true,
				 ),
				 'form_type'  => array(
					 'description' => esc_html__( 'Type of the form.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'form_id'    => array(
					 'description' => esc_html__( 'ID of the form.', 'doublescale'),
					 'type'        => array( 'integer', 'string' ),
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'data'       => array(
					 'description' => esc_html__( 'Data of the form.', 'doublescale'),
					 'type'        => 'object',
				 ),
				 'status'     => array(
					 'description' => esc_html__( 'Status of the form.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'layout'      => array(
					 'description' => esc_html__( 'Layout of a DoubleScale form.', 'doublescale' ),
					 'type'        => 'string',
				 ),
				 'slug'        => array(
					 'description' => esc_html__( 'Link slug of a DoubleScale form.', 'doublescale' ),
					 'type'        => 'string',
				 ),
				 'form_schema' => array(
					 'description' => esc_html__( 'Fields, steps and logic of a DoubleScale form.', 'doublescale' ),
					 'type'        => 'object',
				 ),
				 'style'       => array(
					 'description' => esc_html__( 'Look of a DoubleScale form.', 'doublescale' ),
					 'type'        => 'object',
				 ),
				 'settings'    => array(
					 'description' => esc_html__( 'Settings of a DoubleScale form.', 'doublescale' ),
					 'type'        => 'object',
				 ),
				 'created_at' => array(
					 'description' => esc_html__( 'Date the object was created.', 'doublescale'),
					 'type'        => 'string',
					 'format'      => 'date-time',
					 'readonly'    => true,
				 ),
				 'updated_at' => array(
					 'description' => esc_html__( 'Date the object was last modified.', 'doublescale'),
					 'type'        => 'string',
					 'format'      => 'date-time',
					 'readonly'    => true,
				 ),
			 ),
		 );
	}

	/**
	 * Get collection params
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Limit results to those matching a string.', 'doublescale'),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'form_type' => array(
				'description'       => __( 'Limit results to one form source (doublescale for forms built here).', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'per_page' => array(
				'description'       => __( 'Number of items to return in one page.', 'doublescale'),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'doublescale'),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'ids'      => array(
				'description' => __( 'IDs of the forms.', 'doublescale'),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'from'     => array(
				'description' => __( 'Start date for filtering forms.', 'doublescale'),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'       => array(
				'description' => __( 'End date for filtering forms.', 'doublescale'),
				'type'        => 'string',
				'format'      => 'date',
			),
		) + $this->get_sorting_collection_params( self::SORTABLE_COLUMNS );
	}

	/**
	 * Get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		try {
			$keyword  = $request->get_param( 'keyword' ) ? $request->get_param( 'keyword' ) : '';
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			$query       = FormModel::query();
			$total_count = $query->count();

			// Overview tiles: forms by status and complete submissions, whatever the filters.
			$counts = array(
				'all'         => (int) $total_count,
				'active'      => (int) FormModel::query()->where( 'status', 'active' )->count(),
				'inactive'    => (int) FormModel::query()->where( 'status', '!=', 'active' )->count(),
				'submissions' => doublescale_table_exists( 'form_submissions' ) ? (int) \DoubleScale\Modules\Forms\Models\FormSubmissionModel::query()->where( 'status', 'complete' )->count() : 0,
			);

			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( 'active' === $status ) {
				$query->where( 'status', 'active' );
			} elseif ( 'inactive' === $status ) {
				$query->where( 'status', '!=', 'active' );
			}

			if ( $keyword ) {
				$query->where( 'name', 'LIKE', '%' . $keyword . '%' );
			}
			$form_type = (string) $request->get_param( 'form_type' );
			if ( '' !== $form_type ) {
				$query->where( 'form_type', $form_type );
			}
			$query->withCount(
				array(
					'submissions' => static function ( $q ) {
						$q->where( 'status', 'complete' );
					},
				)
			);
			if ( $from ) {
				$query->where( 'created_at', '>=', $from );
			}
			if ( $to ) {
				$query->where( 'created_at', '<=', $to );
			}
			$this->apply_sorting( $query, $request, self::SORTABLE_COLUMNS );

			$forms = $query->paginate( $per_page, array( '*' ), 'page', $page );
			$forms->getCollection()->transform(
				function ( $form ) {
					return $this->decorate( $form, false );
				}
			);

			return new WP_REST_Response(
				$forms->toArray() + array(
					'total_count' => $total_count,
					'counts'      => $counts,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_items( $request ) {
		try {
			$form_ids = $request->get_param( 'ids' );
			FormModel::whereIn( 'id', $form_ids )->delete();
			self::forget_unlinked_entries( array_map( 'intval', (array) $form_ids ) );
			foreach ( (array) $form_ids as $deleted_id ) {
				\DoubleScale\Modules\Forms\Native\Scoring::forget( (int) $deleted_id );
				\DoubleScale\Modules\Forms\Native\Stats::forget( (int) $deleted_id );
			}
			\DoubleScale\Modules\Forms\Native\Popups::flush();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			if ( FormModel::NATIVE_TYPE === $request->get_param( 'form_type' ) ) {
				$form = FormModel::create( $this->prepare_native( $request, null ) );
				\DoubleScale\Modules\Forms\Native\Popups::flush();
				return new WP_REST_Response( $this->decorate( $form, true ), 201 );
			}

			$form_data = $this->prepare_form( $request );
			$form      = FormModel::create( $form_data );

			return new WP_REST_Response( $form, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$form    = FormModel::find( $form_id );

			if ( ! $form ) {
				return new WP_Error( 'error', __( 'Form not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $form->is_native() ? $this->decorate( $form, true ) : $form, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$form    = FormModel::find( $form_id );

			if ( ! $form ) {
				return new WP_Error( 'error', __( 'Form not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			if ( $form->is_native() ) {
				$form->update( $this->prepare_native( $request, $form ) );
				$form = $form->fresh();
				\DoubleScale\Modules\Forms\Native\Popups::flush();
				return new WP_REST_Response( $this->decorate( $form, true ), 200 );
			}

			$form_data = $this->prepare_form( $request );
			$form->update( $form_data );

			return new WP_REST_Response( $form, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_item( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$form    = FormModel::find( $form_id );

			if ( ! $form ) {
				return new WP_Error( 'error', __( 'Form not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$form->delete();
			self::forget_unlinked_entries( array( (int) $form_id ) );
			\DoubleScale\Modules\Forms\Native\Scoring::forget( (int) $form_id );
			\DoubleScale\Modules\Forms\Native\Popups::flush();
			\DoubleScale\Modules\Forms\Native\Stats::forget( (int) $form_id );

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare item for database
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	/**
	 * Copy a form. A DoubleScale form's copy starts as a draft with its own link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ) {
		$form = FormModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $form ) {
			return new WP_Error( 'error', __( 'Form not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$copy = $form->replicate( array( 'submissions_count' ) );
		/* translators: %s: form name */
		$copy->name = sprintf( __( '%s (copy)', 'doublescale' ), $form->name );
		if ( $form->is_native() ) {
			$copy->hash         = FormModel::new_hash();
			$copy->slug         = FormModel::unique_slug( (string) $form->slug );
			$copy->status       = 'inactive';
			$copy->published_at = null;
			$copy->version      = 0;
		} else {
			$copy->status = 'inactive';
		}
		$copy->save();

		return new WP_REST_Response( $copy->is_native() ? $this->decorate( $copy, true ) : $copy, 201 );
	}

	/**
	 * Fields of a DoubleScale form from the builder, cleaned.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param FormModel|null  $form    The form being updated, or null for a new one.
	 * @return array
	 */
	private function prepare_native( $request, $form ): array {
		$data = array();
		$name = $request->get_param( 'name' );

		if ( null === $form ) {
			$name                = '' !== trim( (string) $name ) ? sanitize_text_field( (string) $name ) : __( 'Untitled form', 'doublescale' );
			$data['name']        = $name;
			$data['form_type']   = FormModel::NATIVE_TYPE;
			$data['hash']        = FormModel::new_hash();
			$data['slug']        = FormModel::unique_slug( (string) ( $request->get_param( 'slug' ) ?: $name ) );
			$data['status']      = 'active' === $request->get_param( 'status' ) ? 'active' : 'inactive';
			$data['layout']      = in_array( $request->get_param( 'layout' ), Schema::LAYOUTS, true ) ? $request->get_param( 'layout' ) : 'classic';
			$data['form_schema'] = null !== $request->get_param( 'form_schema' ) ? Schema::normalize_schema( $request->get_param( 'form_schema' ) ) : Schema::default_schema();
			$data['style']       = Schema::normalize_style( $request->get_param( 'style' ) );
			$data['settings']    = Schema::normalize_settings( $request->get_param( 'settings' ) );
			$data['data']        = array();
			$data['version']     = 1;
			if ( 'active' === $data['status'] ) {
				$data['published_at'] = current_time( 'mysql', true );
			}
			return $data;
		}

		if ( null !== $name && '' !== trim( (string) $name ) ) {
			$data['name'] = sanitize_text_field( (string) $name );
		}
		if ( null !== $request->get_param( 'slug' ) ) {
			$data['slug'] = FormModel::unique_slug( (string) $request->get_param( 'slug' ), $form->id );
		}
		if ( in_array( $request->get_param( 'layout' ), Schema::LAYOUTS, true ) ) {
			$data['layout'] = $request->get_param( 'layout' );
		}
		if ( null !== $request->get_param( 'form_schema' ) ) {
			$data['form_schema'] = Schema::normalize_schema( $request->get_param( 'form_schema' ) );
		}
		// Style and settings may arrive in part (one tab, one toggle): merge into what is stored.
		if ( null !== $request->get_param( 'style' ) ) {
			$data['style'] = Schema::normalize_style( self::merge( (array) $form->style, (array) $request->get_param( 'style' ) ) );
		}
		if ( null !== $request->get_param( 'settings' ) ) {
			$data['settings'] = Schema::normalize_settings( self::merge( (array) $form->settings, (array) $request->get_param( 'settings' ) ) );
		}
		$status = $request->get_param( 'status' );
		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$data['status'] = $status;
			if ( 'active' === $status && 'active' !== $form->status ) {
				$data['published_at'] = current_time( 'mysql', true );
			}
		}
		if ( ! empty( $data ) ) {
			$data['version'] = (int) $form->version + 1;
		}

		return $data;
	}

	/**
	 * Merge a partial update into stored values: objects merge key by key, lists (lists,
	 * tags, options) are replaced as a whole.
	 *
	 * @param array $stored Stored values.
	 * @param array $input  New values.
	 * @return array
	 */
	private static function merge( array $stored, array $input ): array {
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) && ! wp_is_numeric_array( $value ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) && ! wp_is_numeric_array( $stored[ $key ] ) ) {
				$stored[ $key ] = self::merge( $stored[ $key ], $value );
			} else {
				$stored[ $key ] = $value;
			}
		}
		return $stored;
	}

	/**
	 * A form as the admin needs it: a DoubleScale form gets its links, shortcode and fields.
	 *
	 * @param FormModel $form Form.
	 * @param bool      $full Include the definition, style and settings.
	 * @return array
	 */
	private function decorate( FormModel $form, bool $full ): array {
		$row = $form->toArray();
		if ( ! $form->is_native() ) {
			return $row;
		}
		if ( $full ) {
			$row['form_schema'] = Schema::normalize_schema( $form->form_schema ?: Schema::default_schema() );
			$row['style']       = Schema::normalize_style( $form->style );
			$row['settings']    = Schema::normalize_settings( $form->settings );
			$row['layout']      = in_array( $form->layout, Schema::LAYOUTS, true ) ? $form->layout : 'classic';
		} else {
			unset( $row['form_schema'], $row['style'], $row['settings'] );
		}
		$row['link']         = Renderer::link( $form );
		$row['preview_link'] = Renderer::preview_link( $form );
		$row['shortcode']    = '[doublescale_form id="' . (int) $form->id . '"]';
		$row['is_native']    = true;
		if ( $full && ! isset( $row['submissions_count'] ) ) {
			$row['submissions_count'] = $form->submissions()->where( 'status', 'complete' )->count();
		}
		$row['scoring_available'] = \DoubleScale\Modules\Forms\Native\Scoring::available();
		if ( $full ) {
			// The Lead Scoring rules that mention this form (points live only there).
			$row['scoring_rules'] = \DoubleScale\Modules\Forms\Native\Scoring::rules_for( $form );
			// Whether submissions can find or create a contact (automations need one).
			$row['contact_readiness'] = \DoubleScale\Modules\Forms\Native\Contacts::readiness( $form );
		}
		$row['records_available'] = \DoubleScale\Modules\Forms\Native\Records::available();
		// Which CAPTCHA services have both keys saved (a form can only use those).
		$row['captcha_ready'] = array(
			'turnstile' => '' !== \DoubleScale\Modules\Forms\Native\GlobalSettings::site_key( 'turnstile' ),
			'recaptcha' => '' !== \DoubleScale\Modules\Forms\Native\GlobalSettings::site_key( 'recaptcha' ),
			'hcaptcha'  => '' !== \DoubleScale\Modules\Forms\Native\GlobalSettings::site_key( 'hcaptcha' ),
		);
		return $row;
	}

	public function prepare_form( $request ) {
		$form_data = array();

		$form_data['name']      = $request->get_param( 'name' );
		$form_data['form_type'] = $request->get_param( 'form_type' );
		$form_data['form_id']   = $request->get_param( 'form_id' );
		$form_data['data']      = $request->get_param( 'data' );
		$form_data['status']    = $request->get_param( 'status' );

		foreach ( $form_data as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $form_data[ $key ] );
			}
		}

		return $form_data;
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to create items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to get a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to update a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * When a form is deleted, its entries that belong to no contact (spam, unfinished,
	 * forms that don't make contacts) and their files go with it: nobody could reach them
	 * again. Entries linked to a contact stay in that contact's history.
	 *
	 * @param int[] $form_ids Deleted form ids.
	 * @return void
	 */
	private static function forget_unlinked_entries( array $form_ids ): void {
		$form_ids = array_filter( array_map( 'intval', $form_ids ) );
		if ( empty( $form_ids ) || ! doublescale_table_exists( 'form_submissions' ) ) {
			return;
		}
		$ids = FormSubmissionModel::whereIn( 'form_id', $form_ids )->whereNull( 'contact_id' )->pluck( 'id' )->map( 'intval' )->all();
		if ( $ids ) {
			\DoubleScale\Modules\Forms\Native\Uploads::delete_files( $ids );
			FormSubmissionModel::whereIn( 'id', $ids )->delete();
		}
	}
}
