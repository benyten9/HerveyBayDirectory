<?php
/**
 * Submissions inbox (DoubleScale forms and connected form plugins), form stats and the
 * forms-wide settings.
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;
use DoubleScale\Modules\Forms\Native\FieldTypes;
use DoubleScale\Modules\Forms\Native\GlobalSettings;
use DoubleScale\Modules\Forms\Native\Stats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Submissions routes.
 */
class RestFormSubmissionsController extends RestController {

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/forms/submissions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms/submissions/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'status'     => array(
							'type' => 'string',
							'enum' => array( FormSubmissionModel::STATUS_COMPLETE, FormSubmissionModel::STATUS_SPAM ),
						),
						'contact_id' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms/(?P<id>[\d]+)/submissions/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms/(?P<id>[\d]+)/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'stats' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'from' => array(
							'type'   => 'string',
							'format' => 'date',
						),
						'to'   => array(
							'type'   => 'string',
							'format' => 'date',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * List parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'form_id'    => array( 'type' => 'integer' ),
			'contact_id' => array( 'type' => 'integer' ),
			'status'     => array(
				'type'    => 'string',
				'default' => FormSubmissionModel::STATUS_COMPLETE,
				'enum'    => array( 'any', FormSubmissionModel::STATUS_COMPLETE, FormSubmissionModel::STATUS_SPAM, FormSubmissionModel::STATUS_PARTIAL, FormSubmissionModel::STATUS_PENDING, 'unlinked' ),
			),
			'search'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'from'       => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'to'         => array(
				'type'   => 'string',
				'format' => 'date',
			),
			'page'       => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page'   => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 200,
			),
		);
	}

	/**
	 * Submissions, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$ready = $this->require_module_data( 'forms' );
		if ( $ready ) {
			return $ready;
		}

		$query = $this->filtered_query( $request );
		$total = ( clone $query )->count();
		$rows  = $query->orderBy( 'id', 'desc' )
			->forPage( (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) )
			->get();

		return new WP_REST_Response(
			array(
				'data'     => $this->present_many( $rows ),
				'total'    => $total,
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'counts'   => $this->status_counts( $request ),
			),
			200
		);
	}

	/**
	 * One submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$submission = FormSubmissionModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $submission ) {
			return new WP_Error( 'not_found', __( 'Submission not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $this->present_many( array( $submission ), true )[0], 200 );
	}

	/**
	 * Mark as spam / not spam, or link to a contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$submission = FormSubmissionModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $submission ) {
			return new WP_Error( 'not_found', __( 'Submission not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$status = $request->get_param( 'status' );
		if ( $status ) {
			$submission->status = $status;
		}
		$contact_id = $request->get_param( 'contact_id' );
		if ( null !== $contact_id ) {
			$contact_id = (int) $contact_id;
			if ( $contact_id > 0 && ! ContactModel::find( $contact_id ) ) {
				return new WP_Error( 'not_found', __( 'Contact not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			$submission->contact_id = $contact_id > 0 ? $contact_id : null;
		}
		$submission->save();

		return new WP_REST_Response( $this->present_many( array( $submission ), true )[0], 200 );
	}

	/**
	 * Delete submissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_items( $request ) {
		$ids = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );
		if ( $ids ) {
			\DoubleScale\Modules\Forms\Native\Uploads::delete_files( $ids );
			FormSubmissionModel::whereIn( 'id', $ids )->delete();
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * A form's submissions as CSV.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function export( $request ) {
		$form = FormModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$rows    = FormSubmissionModel::where( 'form_id', $form->id )->where( 'status', FormSubmissionModel::STATUS_COMPLETE )->orderBy( 'id' )->get();
		$columns = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row->meta['fields'] ?? array() ) as $key => $field ) {
				if ( ! isset( $columns[ $key ] ) ) {
					$columns[ $key ] = array_merge( array( 'key' => $key ), (array) $field );
				}
			}
			foreach ( array_keys( (array) $row->answers ) as $key ) {
				if ( ! isset( $columns[ $key ] ) ) {
					$columns[ $key ] = array(
						'key'   => $key,
						'label' => $key,
						'type'  => 'text',
					);
				}
			}
		}

		$filename = sanitize_file_name( $form->name . '-submissions-' . wp_date( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out    = fopen( 'php://output', 'w' );
		$header = array( __( 'Submitted', 'doublescale' ), __( 'Contact ID', 'doublescale' ) );
		foreach ( $columns as $column ) {
			$header[] = '' !== ( $column['label'] ?? '' ) ? $column['label'] : $column['key'];
		}
		$header = array_merge( $header, array( __( 'Page', 'doublescale' ), 'utm_source', 'utm_medium', 'utm_campaign' ) );
		fputcsv( $out, array_map( array( self::class, 'csv_safe' ), array_map( 'strval', $header ) ) );

		foreach ( $rows as $row ) {
			$answers = (array) $row->answers;
			$meta    = (array) $row->meta;
			$line    = array( (string) $row->created_at, (string) $row->contact_id );
			foreach ( $columns as $key => $column ) {
				$line[] = self::csv_safe( FieldTypes::readable( array( 'type' => $column['type'] ?? 'text', 'options' => (array) ( $column['options'] ?? array() ) ), $answers[ $key ] ?? null ) );
			}
			$line[] = self::csv_safe( (string) ( $meta['page_url'] ?? '' ) );
			$line[] = self::csv_safe( (string) ( $meta['utm']['source'] ?? '' ) );
			$line[] = self::csv_safe( (string) ( $meta['utm']['medium'] ?? '' ) );
			$line[] = self::csv_safe( (string) ( $meta['utm']['campaign'] ?? '' ) );
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
		exit;
	}

	/**
	 * Views, starts, submissions and conversion of a form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function stats( $request ) {
		$to   = self::day( $request->get_param( 'to' ) ) ?: current_time( 'Y-m-d' );
		$from = self::day( $request->get_param( 'from' ) ) ?: gmdate( 'Y-m-d', strtotime( $to . ' -29 days' ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		// At most about 13 months, so the day-by-day series stays small.
		$earliest = gmdate( 'Y-m-d', strtotime( $to . ' -400 days' ) );
		if ( $from < $earliest ) {
			$from = $earliest;
		}
		$form_id = (int) $request->get_param( 'id' );
		$summary = Stats::summary( $form_id, $from, $to );

		// Every day of the period, so charts show the quiet days too.
		$by_day = array();
		foreach ( $summary['days'] as $day ) {
			$by_day[ $day['day'] ] = $day;
		}
		$days = array();
		for ( $t = strtotime( $from ); $t <= strtotime( $to ); $t += DAY_IN_SECONDS ) {
			$key    = gmdate( 'Y-m-d', $t );
			$days[] = $by_day[ $key ] ?? array(
				'day'         => $key,
				'views'       => 0,
				'starts'      => 0,
				'completions' => 0,
			);
		}
		$summary['days'] = $days;

		// Sources, devices and answers, from the period's finished submissions.
		$rows = FormSubmissionModel::where( 'form_id', $form_id )
			->where( 'status', FormSubmissionModel::STATUS_COMPLETE )
			->where( 'created_at', '>=', $from . ' 00:00:00' )
			->where( 'created_at', '<=', $to . ' 23:59:59' )
			->orderBy( 'id', 'desc' )
			->limit( 5000 )
			->get( array( 'id', 'form_id', 'status', 'answers', 'meta', 'contact_id', 'created_at' ) );

		$sources = array();
		$devices = array();
		foreach ( $rows as $row ) {
			$meta   = is_array( $row->meta ) ? $row->meta : array();
			$source = (string) ( $meta['utm']['source'] ?? '' );
			if ( '' === $source && ! empty( $meta['referrer'] ) ) {
				$host   = (string) wp_parse_url( (string) $meta['referrer'], PHP_URL_HOST );
				$own    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$source = ( '' !== $host && $host !== $own ) ? preg_replace( '/^www\./', '', $host ) : '';
			}
			$source             = '' !== $source ? strtolower( $source ) : 'direct';
			$sources[ $source ] = ( $sources[ $source ] ?? 0 ) + 1;
			$device             = (string) ( $meta['device'] ?? '' );
			$device             = in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ? $device : 'unknown';
			$devices[ $device ] = ( $devices[ $device ] ?? 0 ) + 1;
		}
		arsort( $sources );
		$summary['sources'] = array();
		foreach ( array_slice( $sources, 0, 8, true ) as $name => $count ) {
			$summary['sources'][] = array(
				'name'  => (string) $name,
				'count' => $count,
			);
		}
		$summary['devices'] = $devices;

		// How each choice question was answered (Pro).
		$summary['answers'] = array();
		$form               = FormModel::find( $form_id );
		if ( $form && $form->is_native() && \DoubleScale\Modules\Forms\Native\Schema::pro_active() ) {
			$schema = \DoubleScale\Modules\Forms\Native\Schema::normalize_schema( $form->form_schema ?: array() );
			foreach ( \DoubleScale\Modules\Forms\Native\Schema::input_fields( $schema ) as $field ) {
				if ( ! in_array( $field['type'], array( 'radio', 'select', 'checkbox', 'rating', 'consent' ), true ) ) {
					continue;
				}
				$counts   = array();
				$answered = 0;
				foreach ( $rows as $row ) {
					$value = ( (array) $row->answers )[ $field['key'] ] ?? null;
					if ( null === $value || '' === $value || array() === $value ) {
						continue;
					}
					++$answered;
					foreach ( (array) $value as $item ) {
						$item            = is_bool( $item ) ? ( $item ? 'yes' : 'no' ) : (string) $item;
						$counts[ $item ] = ( $counts[ $item ] ?? 0 ) + 1;
					}
				}
				$options = array();
				if ( 'rating' === $field['type'] ) {
					$max = max( 2, min( 10, (int) ( $field['props']['max'] ?? 5 ) ) );
					for ( $star = $max; $star >= 1; $star-- ) {
						/* translators: %d: number of stars */
						$options[] = array( 'label' => sprintf( _n( '%d star', '%d stars', $star, 'doublescale' ), $star ), 'value' => (string) $star );
					}
				} elseif ( 'consent' === $field['type'] ) {
					$options[] = array( 'label' => __( 'Ticked', 'doublescale' ), 'value' => 'yes' );
				} else {
					$options = $field['options'];
				}
				$items = array();
				foreach ( $options as $option ) {
					$items[] = array(
						'label' => (string) $option['label'],
						'count' => (int) ( $counts[ (string) $option['value'] ] ?? 0 ),
					);
					unset( $counts[ (string) $option['value'] ] );
				}
				foreach ( $counts as $other => $count ) {
					$items[] = array(
						'label' => (string) $other,
						'count' => (int) $count,
					);
				}
				$summary['answers'][] = array(
					'key'      => $field['key'],
					'label'    => '' !== $field['label'] ? $field['label'] : $field['key'],
					'type'     => $field['type'],
					'answered' => $answered,
					'items'    => $items,
				);
			}
		}

		$summary['latest'] = $this->present_many( $rows->take( 5 ) );
		$summary['total']  = $rows->count();
		$summary['from']   = $from;
		$summary['to']     = $to;

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * Forms-wide settings (secret keys masked).
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response( GlobalSettings::for_admin(), 200 );
	}

	/**
	 * Save forms-wide settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function update_settings( $request ) {
		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : array();
		if ( isset( $input['base'] ) ) {
			$base = sanitize_title( (string) $input['base'] );
			if ( $base !== GlobalSettings::base() && GlobalSettings::is_reserved_base( $base ) ) {
				return new \WP_Error(
					'doublescale_forms_reserved_base',
					/* translators: %s: the link base, e.g. "shop". */
					sprintf( __( '"%s" is already used by WordPress, your shop or one of your pages. Choose another link base.', 'doublescale' ), $base ),
					array( 'status' => 400 )
				);
			}
		}
		GlobalSettings::save( $input );
		return new WP_REST_Response( GlobalSettings::for_admin(), 200 );
	}

	/**
	 * A Y-m-d date, or '' when the value isn't one.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function day( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || (int) $parts[1] < 2000 ) {
			return '';
		}
		return $value;
	}

	/**
	 * Managers only.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * The list query with the request's filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private function filtered_query( $request, bool $with_status = true ) {
		$query = FormSubmissionModel::query()->whereNotNull( 'form_id' );

		if ( $request->get_param( 'form_id' ) ) {
			$query->where( 'form_id', (int) $request->get_param( 'form_id' ) );
		}
		if ( $request->get_param( 'contact_id' ) ) {
			$query->where( 'contact_id', (int) $request->get_param( 'contact_id' ) );
		}
		if ( $with_status ) {
			$status = (string) $request->get_param( 'status' );
			if ( 'unlinked' === $status ) {
				$query->where( 'status', FormSubmissionModel::STATUS_COMPLETE )->whereNull( 'contact_id' );
			} elseif ( 'any' !== $status && '' !== $status ) {
				$query->where( 'status', $status );
			}
		}
		if ( self::day( $request->get_param( 'from' ) ) ) {
			$query->where( 'created_at', '>=', self::day( $request->get_param( 'from' ) ) . ' 00:00:00' );
		}
		if ( self::day( $request->get_param( 'to' ) ) ) {
			$query->where( 'created_at', '<=', self::day( $request->get_param( 'to' ) ) . ' 23:59:59' );
		}
		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$like = '%' . $search . '%';
			$query->where(
				static function ( $q ) use ( $like ) {
					$q->where( 'answers', 'LIKE', $like );
					$contact_ids = ContactModel::where( 'email', 'LIKE', $like )
						->orWhere( 'first_name', 'LIKE', $like )
						->orWhere( 'last_name', 'LIKE', $like )
						->limit( 500 )
						->pluck( 'id' )
						->all();
					if ( $contact_ids ) {
						$q->orWhereIn( 'contact_id', $contact_ids );
					}
				}
			);
		}
		return $query;
	}

	/**
	 * How many submissions each status tab holds (for the tab badges).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function status_counts( $request ): array {
		$base   = $this->filtered_query( $request, false );
		$counts = array();
		foreach ( array( FormSubmissionModel::STATUS_COMPLETE, FormSubmissionModel::STATUS_SPAM, FormSubmissionModel::STATUS_PARTIAL ) as $status ) {
			$counts[ $status ] = ( clone $base )->where( 'status', $status )->count();
		}
		$counts['unlinked'] = ( clone $base )->where( 'status', FormSubmissionModel::STATUS_COMPLETE )->whereNull( 'contact_id' )->count();
		return $counts;
	}

	/**
	 * Submissions as the admin shows them: answers with labels, the form and the contact.
	 *
	 * @param iterable $rows Submission models.
	 * @param bool     $full Include the whole context.
	 * @return array
	 */
	private function present_many( $rows, bool $full = false ): array {
		$form_ids    = array();
		$contact_ids = array();
		foreach ( $rows as $row ) {
			$form_ids[ (int) $row->form_id ] = true;
			if ( $row->contact_id ) {
				$contact_ids[ (int) $row->contact_id ] = true;
			}
		}
		$forms    = $form_ids ? FormModel::whereIn( 'id', array_keys( $form_ids ) )->get( array( 'id', 'name', 'form_type', 'data', 'form_schema' ) )->keyBy( 'id' ) : array();
		$contacts = $contact_ids ? ContactModel::whereIn( 'id', array_keys( $contact_ids ) )->get( array( 'id', 'first_name', 'last_name', 'email', 'phone' ) )->keyBy( 'id' ) : array();

		$out = array();
		foreach ( $rows as $row ) {
			$form    = $forms[ (int) $row->form_id ] ?? null;
			$contact = $row->contact_id ? ( $contacts[ (int) $row->contact_id ] ?? null ) : null;
			$meta    = is_array( $row->meta ) ? $row->meta : array();
			$labels  = is_array( $meta['fields'] ?? null ) ? $meta['fields'] : array();
			$answers = array();
			foreach ( (array) $row->answers as $key => $value ) {
				$field     = array(
					'type'    => (string) ( $labels[ $key ]['type'] ?? 'text' ),
					'label'   => (string) ( $labels[ $key ]['label'] ?? $key ),
					'options' => (array) ( $labels[ $key ]['options'] ?? array() ),
				);
				$answers[] = array(
					'key'   => (string) $key,
					'label' => '' !== $field['label'] ? $field['label'] : (string) $key,
					'type'  => $field['type'],
					'value' => $value,
					'text'  => FieldTypes::readable( $field, $value ),
				);
			}

			$item = array(
				'id'         => (int) $row->id,
				'form_id'    => (int) $row->form_id,
				'form'       => $form ? array(
					'id'        => (int) $form->id,
					'name'      => (string) $form->name,
					'form_type' => (string) $form->form_type,
				) : null,
				'status'     => (string) ( $row->status ?: FormSubmissionModel::STATUS_COMPLETE ),
				'contact'    => $contact ? array(
					'id'    => (int) $contact->id,
					'name'  => trim( $contact->first_name . ' ' . $contact->last_name ),
					'email' => (string) $contact->email,
					'phone' => (string) $contact->phone,
				) : null,
				'answers'    => $answers,
				'page_url'   => (string) ( $meta['page_url'] ?? '' ),
				'referrer'   => (string) ( $meta['referrer'] ?? '' ),
				'utm'        => (array) ( $meta['utm'] ?? array() ),
				'device'     => (string) ( $meta['device'] ?? '' ),
				'created_at' => (string) $row->created_at,
			);
			if ( $full ) {
				$item['meta']  = array_diff_key( $meta, array_flip( array( 'fields', 'ip_hash', 'user_agent', 'files' ) ) );
				$item['files'] = ! empty( $meta['files'] ) ? \DoubleScale\Modules\Forms\Native\Uploads::for_submission( (int) $row->id ) : array();
				$item['records'] = self::record_names( (array) ( $meta['records'] ?? array() ) );
				$item['records_review'] = self::review_notes( (array) ( $meta['records_review'] ?? array() ) );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * What "Also create" left for the team to check (see Records::$review), with names.
	 *
	 * @param array $review Review entries by kind.
	 * @return array<int, array{kind: string, reason: string, id: int, name: string}>
	 */
	private static function review_notes( array $review ): array {
		$out = array();
		foreach ( $review as $kind => $entry ) {
			$entry = (array) $entry;
			$id    = (int) ( $entry['id'] ?? 0 );
			$name  = (string) ( $entry['name'] ?? '' );
			if ( 'company' === $kind && $id > 0 ) {
				$found = self::record_names( array( 'company' => $id ) );
				$name  = $found ? (string) $found[0]['title'] : $name;
			}
			$out[] = array(
				'kind'   => sanitize_key( (string) $kind ),
				'reason' => sanitize_key( (string) ( $entry['reason'] ?? '' ) ),
				'id'     => $id,
				'name'   => $name,
			);
		}
		return $out;
	}

	/**
	 * Names of the records a submission made ("Also create"), for the detail view. Records
	 * deleted since, or in a module that is now off, are left out.
	 *
	 * @param array $made Ids by kind.
	 * @return array<int, array{kind: string, id: int, title: string}>
	 */
	private static function record_names( array $made ): array {
		global $wpdb;
		$tables = array(
			'company' => array( 'companies', 'name' ),
			'deal'    => array( 'deals', 'title' ),
			'ticket'  => array( 'support_tickets', 'title' ),
			'project' => array( 'projects', 'title' ),
		);
		$out = array();
		foreach ( $tables as $kind => list( $table, $column ) ) {
			$id = (int) ( $made[ $kind ] ?? 0 );
			if ( $id <= 0 || ! doublescale_table_exists( $table ) ) {
				continue;
			}
			$name  = $wpdb->prefix . 'doublescale_' . $table;
			$title = $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM `{$name}` WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table/column names from the list above.
			if ( null === $title ) {
				continue;
			}
			$out[] = array(
				'kind'  => $kind,
				'id'    => $id,
				'title' => (string) $title,
			);
		}
		return $out;
	}

	/**
	 * Stop spreadsheet apps running a cell as a formula.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	private static function csv_safe( string $value ): string {
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}
}
