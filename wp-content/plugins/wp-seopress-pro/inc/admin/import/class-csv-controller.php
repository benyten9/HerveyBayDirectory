<?php //phpcs:ignore
/**
 * SEOPress PRO CSV Setup Wizard Controller.
 *
 * @package SEOPress PRO
 * @subpackage Import
 */
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! class_exists( 'WP_Importer' ) ) {
	return;
}

/**
 * SEOPRESS_CSV_Setup_Wizard_Controller class.
 */
class SEOPRESS_CSV_Setup_Wizard_Controller {

	/**
	 * The path to the current file.
	 *
	 * @var string
	 */
	protected $file = '';

	/**
	 * Current step.
	 *
	 * @var string
	 */
	private $step = '';

	/**
	 * Steps for the setup wizard.
	 *
	 * @var array
	 */
	private $steps = array();

	/**
	 * Errors.
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * The current delimiter for the file being read.
	 *
	 * @var string
	 */
	protected $delimiter = ';';

	/**
	 * Ignore existing metadata.
	 *
	 * @var boolean
	 */
	protected $import_ignore_metadata = false;

	/**
	 * Get importer instance.
	 *
	 * @param string $file file to import.
	 * @param array  $args importer arguments.
	 *
	 * @return SEOPRESS_CSV_Importer
	 */
	public static function get_importer( $file, $args = array() ) {
		$importer_class = apply_filters( 'seopress_csv_importer_class', 'SEOPRESS_CSV_Importer' );
		$args           = apply_filters( 'seopress_csv_importer_args', $args, $importer_class );

		return new $importer_class( $file, $args );
	}

	/**
	 * Check whether a file is a valid CSV file.
	 *
	 * @param string $file       file path.
	 * @param bool   $check_path whether to also check the file is located in a valid location (Default: true).
	 *
	 * @return bool
	 */
	public static function is_file_valid_csv( $file, $check_path = true ) {
		if ( $check_path && apply_filters( 'seopress_csv_importer_check_import_file_path', true ) && false !== stripos( $file, '://' ) ) {
			return false;
		}

		$valid_filetypes = self::get_valid_csv_filetypes();
		$filetype        = wp_check_filetype( $file, $valid_filetypes );
		if ( in_array( $filetype['type'], $valid_filetypes, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get all the valid filetypes for a CSV file.
	 *
	 * @return array
	 */
	protected static function get_valid_csv_filetypes() {
		return apply_filters(
			'seopress_csv_import_valid_filetypes',
			array(
				'csv' => 'text/csv',
			)
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$default_steps = array(
			'upload'  => array(
				'name'    => esc_html__( 'Upload CSV file', 'wp-seopress-pro' ),
				'view'    => array( $this, 'upload_form' ),
				'handler' => array( $this, 'upload_form_handler' ),
			),
			'mapping' => array(
				'name'    => esc_html__( 'Column mapping', 'wp-seopress-pro' ),
				'view'    => array( $this, 'mapping_form' ),
				'handler' => '',
			),
			'import'  => array(
				'name'    => esc_html__( 'Import', 'wp-seopress-pro' ),
				'view'    => array( $this, 'import' ),
				'handler' => '',
			),
			'done'    => array(
				'name'    => esc_html__( 'Done!', 'wp-seopress-pro' ),
				'view'    => array( $this, 'done' ),
				'handler' => '',
			),
		);

		$this->steps = apply_filters( 'seopress_setup_csv_wizard_steps', $default_steps );

		$this->step                   = isset( $_REQUEST['step'] ) ? sanitize_key( $_REQUEST['step'] ) : current( array_keys( $this->steps ) );
		$this->file                   = isset( $_REQUEST['file'] ) ? seopress_clean( wp_unslash( $_REQUEST['file'] ) ) : '';
		$this->delimiter              = ! empty( $_REQUEST['delimiter'] ) ? seopress_clean( wp_unslash( $_REQUEST['delimiter'] ) ) : ';';
		$this->import_ignore_metadata = ! empty( $_REQUEST['import_ignore_metadata'] ) ? esc_attr( $_REQUEST['import_ignore_metadata'] ) : false;

		// Import mappings for CSV data.
		include_once __DIR__ . '/mapping.php';
	}

	/**
	 * Get the URL for the next step's screen.
	 *
	 * @param string $step slug (default: current step).
	 *
	 * @return string URL for next step if a next step exists.
	 *                Admin URL if it's the last step.
	 *                Empty string on failure.
	 *
	 * @since 3.7
	 */
	public function get_next_step_link( $step = '' ) {
		if ( ! $step ) {
			$step = $this->step;
		}

		$keys = array_keys( $this->steps );

		if ( end( $keys ) === $step ) {
			return admin_url();
		}

		$step_index = array_search( $step, $keys, true );

		if ( false === $step_index ) {
			return '';
		}

		$params = array(
			'step'                   => $keys[ $step_index + 1 ],
			'file'                   => $this->file,
			'delimiter'              => $this->delimiter,
			'import_ignore_metadata' => $this->import_ignore_metadata,
			'_wpnonce'               => wp_create_nonce( 'seopress-csv-importer' ),
		);

		return add_query_arg( $params );
	}

	/**
	 * Output header view.
	 */
	protected function output_header() {
		?>
<div class="seopress-option seopress-wizard">
		<?php
	}

	/**
	 * Output steps view.
	 */
	protected function output_steps() {
		$output_steps = $this->steps;
		?>
	<ol class="seopress-setup-steps">
		<?php
				$i = 1;
		foreach ( $output_steps as $step_key => $step ) {
			$is_completed = array_search( $this->step, array_keys( $this->steps ), true ) > array_search( $step_key, array_keys( $this->steps ), true );

			if ( $step_key === $this->step ) {
				?>
		<li class="active">
			<div class="icon" data-step="<?php echo $i; ?>"></div>
			<span><?php echo esc_html( $step['name'] ); ?></span>
			<div class="divider"></div>
		</li>
				<?php
			} elseif ( $is_completed ) {
				?>
		<li class="done">
			<div class="icon" data-step="<?php echo $i; ?>"></div>
			<a
				href="<?php echo esc_url( add_query_arg( 'step', $step_key, remove_query_arg( 'activate_error' ) ) ); ?>">
				<?php echo esc_html( $step['name'] ); ?>
			</a>
			<div class="divider"></div>
		</li>
				<?php
			} else {
				?>
		<li>
			<div class="icon" data-step="<?php echo $i; ?>"></div>
			<span><?php echo esc_html( $step['name'] ); ?></span>
			<div class="divider"></div>
		</li>
				<?php
			}
			++$i;
		}
		?>
	</ol>
		<?php
	}

	/**
	 * Output the content for the current step.
	 */
	public function output_content() {

		?>

		<?php
		if ( ! empty( $this->steps[ $this->step ]['view'] ) ) {
			call_user_func( $this->steps[ $this->step ]['view'], $this );
		}
		?>


		<?php
	}

	/**
	 * Output footer view.
	 */
	protected function output_footer() {

		?>
</div>
		<?php
		do_action( 'seopress_setup_footer' );
	}

	/**
	 * Add error message.
	 *
	 * @param string $message error message.
	 * @param array  $actions list of actions with 'url' and 'label'.
	 */
	protected function add_error( $message, $actions = array() ) {
		$this->errors[] = array(
			'message' => $message,
			'actions' => $actions,
		);
	}

	/**
	 * Add error message.
	 */
	protected function output_errors() {
		if ( ! $this->errors ) {
			return;
		}

		foreach ( $this->errors as $error ) {
			?>
			<div class="seopress-notice is-error">
				<p><?php echo esc_html( $error['message'] ); ?></p>

				<?php if ( ! empty( $error['actions'] ) ) { ?>
					<p>
						<?php foreach ( $error['actions'] as $action ) { ?>
							<a class="btn btnPrimary"
								href="<?php echo esc_url( $action['url'] ); ?>">
								<?php echo esc_html( $action['label'] ); ?>
							</a>
						<?php } ?>
					</p>
					<?php
				}
				?>
			</div>
			<?php
		}
	}

	/**
	 * Dispatch current step and show correct view.
	 */
	public function dispatch() {
		if ( ! empty( $_POST['save_step'] ) && ! empty( $this->steps[ $this->step ]['handler'] ) ) {
			call_user_func( $this->steps[ $this->step ]['handler'], $this );
		}
		$this->output_steps();
		$this->output_header();
		$this->output_errors();
		$this->output_content();
		$this->output_footer();
	}

	/**
	 * Output information about the uploading process.
	 */
	protected function upload_form() {
		$bytes      = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size       = size_format( $bytes );
		$upload_dir = wp_upload_dir();
		?>

<h1>
		<?php esc_html_e( 'Import metadata from a CSV file', 'wp-seopress-pro' ); ?>
</h1>
<form method="post" enctype="multipart/form-data">
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'This tool allows you to import SEO metadata to your site from a CSV file.', 'wp-seopress-pro' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Existing posts / terms that match by ID will be updated.', 'wp-seopress-pro' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Posts, pages, custom post types or term taxonomies that do not exist will be skipped.', 'wp-seopress-pro' ); ?>
		</p>
	</div>

		<?php
		if ( ! empty( $upload_dir['error'] ) ) {
			?>
	<div class="inline error">
		<p>
			<?php esc_html_e( 'Before you can upload your import file, you will need to fix the following error:', 'wp-seopress-pro' ); ?>
		</p>
		<p>
			<strong><?php echo esc_html( $upload_dir['error'] ); ?></strong>
		</p>
	</div>
			<?php
		} else {
			?>
	<p>
		<strong>
			<?php esc_html_e( 'Select your separator:', 'wp-seopress-pro' ); ?>
		</strong>
	</p>
	<p>
		<label for="import_sep_comma">
			<input id="import_sep_comma" name="delimiter" type="radio" value="comma" />
			<?php echo wp_kses_post( __( 'Comma separator: <code>,</code>', 'wp-seopress-pro' ) ); ?>
		</label>
	</p>
	<p>
		<label for="import_sep_semicolon">
			<input id="import_sep_semicolon" name="delimiter" type="radio" value="semicolon" />
			<?php echo wp_kses_post( __( 'Semicolon separator: <code>;</code>', 'wp-seopress-pro' ) ); ?>
		</label>
	</p>
	<p>
		<strong>
			<label
				for="import_file"><?php esc_html_e( 'Choose a CSV file from your computer:', 'wp-seopress-pro' ); ?></label>
		</strong>
	</p>
	<p>
		<input type="file" id="upload" name="import" size="25" />
		<input type="hidden" name="action" value="save" />
		<input type="hidden" name="max_file_size"
			value="<?php echo esc_attr( $bytes ); ?>" />

		<small>
			<?php
				$bytes  = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
			$size       = size_format( $bytes );
			$upload_dir = wp_upload_dir();
			printf(
				/* translators: %s: maximum upload size */
				esc_html__( 'Maximum size: %s', 'wp-seopress-pro' ),
				esc_html( $size )
			);
			?>
		</small>
	</p>
	<p>
		<strong>
			<?php esc_html_e( 'Ignore existing values?', 'wp-seopress-pro' ); ?>
		</strong>
	</p>
	<p>
		<label for="import_ignore_metadata">
			<input id="import_ignore_metadata" name="import_ignore_metadata" type="checkbox" value="1" />
			<?php esc_html_e( 'Existing post and term metas will not be updated. Only empty values will be filled.', 'wp-seopress-pro' ); ?>
		</label>
	</p>
			<?php
		}
		?>
	<p class="seopress-setup-actions step">
		<button type="submit" class="btn btnPrimary"
			value="<?php esc_attr_e( 'Next step', 'wp-seopress-pro' ); ?>"
			name="save_step">
			<?php esc_html_e( 'Next step', 'wp-seopress-pro' ); ?>
		</button>
		<?php wp_nonce_field( 'seopress-csv-importer' ); ?>
	</p>
</form>

		<?php
	}

	/**
	 * Handle the upload form and store options.
	 */
	public function upload_form_handler() {
		check_admin_referer( 'seopress-csv-importer' );

		$file = $this->handle_upload();

		if ( is_wp_error( $file ) ) {
			$this->add_error( $file->get_error_message() );

			return;
		} else {
			$this->file = $file;
		}

		wp_redirect( esc_url_raw( $this->get_next_step_link() ) );
		exit;
	}

	/**
	 * Handles the CSV upload and initial parsing of the file.
	 *
	 * @return string|WP_Error
	 */
	public function handle_upload() {
		$file_url = isset( $_POST['file_url'] ) ? sanitize_text_field( wp_unslash( $_POST['file_url'] ) ) : '';

		if ( empty( $file_url ) ) {
			if ( ! isset( $_FILES['import'] ) ) {
				return new WP_Error( 'seopress_metadata_csv_importer_upload_file_empty', esc_html__( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.', 'wp-seopress-pro' ) );
			}

			if ( ! self::is_file_valid_csv( sanitize_text_field( wp_unslash( $_FILES['import']['name'] ) ), false ) ) {
				return new WP_Error( 'seopress_metadata_csv_importer_upload_file_invalid', esc_html__( 'Invalid file type. The importer supports CSV and TXT file formats.', 'wp-seopress-pro' ) );
			}

			$overrides = array(
				'test_form' => false,
				'mimes'     => self::get_valid_csv_filetypes(),
			);
			$import    = $_FILES['import'];
			$upload    = wp_handle_upload( $import, $overrides );

			if ( isset( $upload['error'] ) ) {
				return new WP_Error( 'seopress_metadata_csv_importer_upload_error', $upload['error'] );
			}

			// Construct the object array.
			$object = array(
				'post_title'     => basename( $upload['file'] ),
				'post_content'   => $upload['url'],
				'post_mime_type' => $upload['type'],
				'guid'           => $upload['url'],
				'context'        => 'import',
				'post_status'    => 'private',
			);

			// Save the data.
			$id = wp_insert_attachment( $object, $upload['file'] );

			/*
			 * Schedule a cleanup for one day from now in case of failed
			 * import or missing wp_import_cleanup() call.
			 */
			wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

			return $upload['file'];
		} elseif ( file_exists( ABSPATH . $file_url ) ) {
			if ( ! self::is_file_valid_csv( ABSPATH . $file_url ) ) {
				return new WP_Error( 'seopress_metadata_csv_importer_upload_file_invalid', esc_html__( 'Invalid file type. The importer supports CSV and TXT file formats.', 'wp-seopress-pro' ) );
			}

			return ABSPATH . $file_url;
		}

		return new WP_Error( 'seopress_metadata_csv_importer_upload_invalid_file', esc_html__( 'Please upload or provide the link to a valid CSV file.', 'wp-seopress-pro' ) );
	}

	/**
	 * Column mapping.
	 */
	public function mapping_form() {
		check_admin_referer( 'seopress-csv-importer' );
		$args = array(
			'lines'     => 1,
			'delimiter' => $this->delimiter,
		);

		$importer     = self::get_importer( $this->file, $args );
		$headers      = $importer->get_raw_keys();
		$mapped_items = $this->auto_map_columns( $headers );
		$sample       = current( $importer->get_raw_data() );

		if ( empty( $sample ) ) {
			$this->add_error(
				esc_html__( 'The file is empty or using a different encoding than UTF-8, please try again with a new file.', 'wp-seopress-pro' ),
				array(
					array(
						'url'   => admin_url( 'admin.php?page=seopress_csv_importer' ),
						'label' => esc_html__( 'Upload a new file', 'wp-seopress-pro' ),
					),
				)
			);

			// Force output the errors in the same page.
			$this->output_errors();

			return;
		}
		?>
<h1>
		<?php esc_html_e( 'Map CSV fields to post metas', 'wp-seopress-pro' ); ?>
</h1>
<form method="post"
	action="<?php echo esc_url( $this->get_next_step_link() ); ?>">
	<p>
		<?php esc_html_e( 'Select fields from your CSV file to map against posts / terms fields, or to ignore during import.', 'wp-seopress-pro' ); ?>
	</p>

	<section class="seopress-importer-mapping-table-wrapper">
		<table class="widefat seopress-importer-mapping-table">
			<thead>
				<tr>
					<th>
						<?php esc_html_e( 'Column name', 'wp-seopress-pro' ); ?>
					</th>
					<th>
						<?php esc_html_e( 'Map to field', 'wp-seopress-pro' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $headers as $index => $name ) { ?>
					<?php
					$mapped_value = $mapped_items[ $index ];
					$hidden       = in_array( $name, array( 'post_type', 'taxonomy' ) ) ? ' style="display:none"' : '';
					?>
				<tr<?php echo $hidden; ?>>
					<td class="seopress-importer-mapping-table-name">
						<?php echo esc_html( $name ); ?>
						<?php if ( ! empty( $sample[ $index ] ) ) { ?>
						<span
							class="description"><?php esc_html_e( 'Sample:', 'wp-seopress-pro' ); ?>
							<code><?php echo esc_html( $sample[ $index ] ); ?></code></span>
						<?php } ?>
					</td>
					<td class="seopress-importer-mapping-table-field">
						<input type="hidden"
							name="map_from[<?php echo esc_attr( $index ); ?>]"
							value="<?php echo esc_attr( $name ); ?>" />
						<select
							name="map_to[<?php echo esc_attr( $index ); ?>]">
							<option value="">
								<?php esc_html_e( 'Do not import', 'wp-seopress-pro' ); ?>
							</option>
							<option value="">--------------</option>
							<?php foreach ( $this->get_mapping_options( $mapped_value ) as $key => $value ) { ?>
							<option
								value="<?php echo esc_attr( $key ); ?>"
								<?php selected( $mapped_value, $key ); ?>><?php echo esc_html( $value ); ?>
							</option>
							<?php } ?>
						</select>
					</td>
				</tr>
				<?php } ?>
			</tbody>
		</table>
	</section>

	<p class="seopress-setup-actions step">
		<button type="submit" class="btn btnPrimary"
			value="<?php esc_attr_e( 'Run the importer', 'wp-seopress-pro' ); ?>"
			name="save_step">
			<?php esc_html_e( 'Run the importer', 'wp-seopress-pro' ); ?>
		</button>
		<input type="hidden" name="file"
			value="<?php echo esc_attr( $this->file ); ?>" />
		<input type="hidden" name="delimiter"
			value="<?php echo esc_attr( $this->delimiter ); ?>" />
		<input type="hidden" name="import_ignore_metadata"
			value="<?php echo esc_attr( $this->import_ignore_metadata ); ?>" />
		<?php wp_nonce_field( 'seopress-csv-importer' ); ?>
	</p>
</form>
		<?php
	}

	/**
	 * Import the file if it exists and is valid.
	 */
	public function import() {
		// Displaying this page triggers Ajax action to run the import with a valid nonce,
		// therefore this page needs to be nonce protected as well.
		check_admin_referer( 'seopress-csv-importer' );

		if ( ! self::is_file_valid_csv( $this->file ) ) {
			$this->add_error( esc_html__( 'Invalid file type. The importer supports CSV and TXT file formats.', 'wp-seopress-pro' ) );
			$this->output_errors();

			return;
		}

		if ( ! is_file( $this->file ) ) {
			$this->add_error( esc_html__( 'The file does not exist, please try again.', 'wp-seopress-pro' ) );
			$this->output_errors();

			return;
		}

		if ( ! empty( $_POST['map_from'] ) && ! empty( $_POST['map_to'] ) ) {
			$mapping_from = seopress_clean( wp_unslash( $_POST['map_from'] ) );
			$mapping_to   = seopress_clean( wp_unslash( $_POST['map_to'] ) );
		} else {
			wp_redirect( esc_url_raw( $this->get_next_step_link( 'upload' ) ) );
			exit;
		}
		wp_localize_script(
			'seopress-csv-import',
			'seopress_csv_import_params',
			array(
				'import_nonce'           => wp_create_nonce( 'seopress-csv-importer' ),
				'mapping'                => array(
					'from' => $mapping_from,
					'to'   => $mapping_to,
				),
				'file'                   => $this->file,
				'delimiter'              => $this->delimiter,
				'import_ignore_metadata' => $this->import_ignore_metadata,
			)
		);
		// wp_print_scripts( 'seopress-csv-import' );
		wp_enqueue_script( 'seopress-csv-import' );
		?>
<h1>
		<?php esc_html_e( 'Importing', 'wp-seopress-pro' ); ?>
</h1>
<p>
		<?php esc_html_e( 'Your metadata are now being imported...', 'wp-seopress-pro' ); ?>
</p>
<div class="seopress-progress-form-content seopress-importer seopress-importer__importing">
	<section>
		<span class="spinner is-active"></span>
		<progress class="seopress-importer-progress" max="100" value="0"></progress>
	</section>
</div>
		<?php
	}

	/**
	 * Final step.
	 */
	public function done() {
		check_admin_referer( 'seopress-csv-importer' );
		$imported = isset( $_GET['metadatas-imported'] ) ? absint( $_GET['metadatas-imported'] ) : 0;
		$updated  = isset( $_GET['metadatas-updated'] ) ? absint( $_GET['metadatas-updated'] ) : 0;
		$failed   = isset( $_GET['metadatas-failed'] ) ? absint( $_GET['metadatas-failed'] ) : 0;
		$skipped  = isset( $_GET['metadatas-skipped'] ) ? absint( $_GET['metadatas-skipped'] ) : 0;
		$errors   = array_filter( (array) get_user_option( 'seopress_import_error_log' ) );
		?>
<h2>
		<?php esc_html_e( 'Import complete!', 'wp-seopress-pro' ); ?>
</h2>

<div class="seopress-progress-form-content seopress-importer">
	<section class="seopress-importer-done">
		<?php
					$results = array();

		if ( 0 < $imported ) {
			$results[] = sprintf(
				/* translators: %s: posts count */
				_n( '%s post or term imported.', '%s posts / terms imported.', $imported, 'wp-seopress-pro' ),
				'<strong>' . number_format_i18n( $imported ) . '</strong>'
			);
		}

		if ( 0 < $updated ) {
			$results[] = sprintf(
				/* translators: %s: posts count */
				_n( '%s post or term updated.', '%s posts / terms updated.', $updated, 'wp-seopress-pro' ),
				'<strong>' . number_format_i18n( $updated ) . '</strong>'
			);
		}

		if ( 0 < $skipped ) {
			$results[] = sprintf(
				/* translators: %s: posts count */
				_n( '%s post or term was skipped.', '%s posts / terms were skipped.', $skipped, 'wp-seopress-pro' ),
				'<strong>' . number_format_i18n( $skipped ) . '</strong>'
			);
		}

		if ( 0 < $failed ) {
			$results[] = sprintf(
				/* translators: %s: posts count */
				_n( 'Failed to import %s post or term.', 'Failed to import %s posts / terms.', $failed, 'wp-seopress-pro' ),
				'<strong>' . number_format_i18n( $failed ) . '</strong>'
			);
		}

		if ( 0 < $failed || 0 < $skipped ) {
			$results['log'] = '<a href="#" class="seopress-importer-done-view-errors">' . esc_html__( 'View import log', 'wp-seopress-pro' ) . '</a>';
		}

		if ( ! empty( $results ) ) {
			echo '<ul>';
			foreach ( $results as $key => $result ) {
				if ( $key === 'log' ) {
					echo '<li><br>' . $result . '</li>';
				} else {
					echo '<li><span class="dashicons dashicons-minus"></span>' . $result . '</li>';
				}
			}
			echo '<ul>';
		}
		?>
	</section>
	<section class="seopress-importer-error-log" style="display:none">
		<table class="widefat seopress-importer-error-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post', 'wp-seopress-pro' ); ?>
					</th>
					<th><?php esc_html_e( 'Reason for failure', 'wp-seopress-pro' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( count( $errors ) ) {
					foreach ( $errors as $error ) {
						if ( ! is_wp_error( $error ) ) {
							continue;
						}
						$error_data = $error->get_error_data();
						?>
				<tr>
					<th><code><?php echo esc_html( $error_data['row'] ); ?></code>
					</th>
					<td><?php echo esc_html( $error->get_error_message() ); ?>
					</td>
				</tr>
						<?php
					}
				}
				?>
			</tbody>
		</table>
	</section>
	<script type="text/javascript">
		jQuery(function() {
			jQuery('.seopress-importer-done-view-errors').on('click', function() {
				jQuery('.seopress-importer-error-log').slideToggle();
				return false;
			});
		});
	</script>
	<div class="seopress-actions">
		<p>
			<a class="btn btnPrimary"
				href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'View posts', 'wp-seopress-pro' ); ?></a>
			<a class="btn btnSecondary"
				href="<?php echo esc_url( admin_url( 'admin.php?page=seopress_csv_importer&step=upload' ) ); ?>">
				<?php esc_html_e( 'Make another import', 'wp-seopress-pro' ); ?>
			</a>
		</p>
	</div>
</div>
		<?php
	}

	/**
	 * Columns to normalize.
	 *
	 * @param array $columns list of columns names and keys.
	 *
	 * @return array
	 */
	protected function normalize_columns_names( $columns ) {
		$normalized = array();

		foreach ( $columns as $key => $value ) {
			$normalized[ strtolower( $key ) ] = $value;
		}

		return $normalized;
	}

	/**
	 * Auto map column names.
	 *
	 * @param array $raw_headers raw header columns.
	 * @param bool  $num_indexes whether to use numbers or raw header columns as indexes.
	 *
	 * @return array
	 */
	protected function auto_map_columns( $raw_headers, $num_indexes = true ) {
		$default_columns = $this->normalize_columns_names(
			apply_filters(
				'seopress_csv_metadata_import_mapping_default_columns',
				array(
					esc_html__( 'ID', 'wp-seopress-pro' ) => 'id',
					esc_html__( 'Post / term title', 'wp-seopress-pro' ) => 'post_title',
					esc_html__( 'Slug', 'wp-seopress-pro' ) => 'slug',
					esc_html__( 'Taxonomy', 'wp-seopress-pro' ) => 'taxonomy',
					esc_html__( 'Post Type', 'wp-seopress-pro' ) => 'post_type',
					esc_html__( 'Meta Title', 'wp-seopress-pro' ) => 'meta_title',
					esc_html__( 'Meta description', 'wp-seopress-pro' ) => 'meta_desc',
					esc_html__( 'Facebook title', 'wp-seopress-pro' ) => 'fb_title',
					esc_html__( 'Facebook description', 'wp-seopress-pro' ) => 'fb_desc',
					esc_html__( 'Facebook thumbnail', 'wp-seopress-pro' ) => 'fb_img',
					esc_html__( 'X title', 'wp-seopress-pro' ) => 'tw_title',
					esc_html__( 'X description', 'wp-seopress-pro' ) => 'tw_desc',
					esc_html__( 'X thumbnail', 'wp-seopress-pro' ) => 'tw_img',
					esc_html__( 'noindex', 'wp-seopress-pro' ) => 'noindex',
					esc_html__( 'nofollow', 'wp-seopress-pro' ) => 'nofollow',
					esc_html__( 'noimageindex', 'wp-seopress-pro' ) => 'noimageindex',
					esc_html__( 'nosnippet', 'wp-seopress-pro' ) => 'nosnippet',
					esc_html__( 'Canonical URL', 'wp-seopress-pro' ) => 'canonical_url',
					esc_html__( 'Primary category', 'wp-seopress-pro' ) => 'primary_cat',
					esc_html__( 'Active redirect', 'wp-seopress-pro' ) => 'redirect_active',
					esc_html__( 'Redirect status', 'wp-seopress-pro' ) => 'redirect_status',
					esc_html__( 'Redirection type', 'wp-seopress-pro' ) => 'redirect_type',
					esc_html__( 'URL redirect', 'wp-seopress-pro' ) => 'redirect_url',
					esc_html__( 'Target Keyword', 'wp-seopress-pro' ) => 'target_kw',
					esc_html__( 'Custom breadcrumbs', 'wp-seopress-pro' ) => 'breadcrumbs',
				),
				$raw_headers
			)
		);

		$headers = array();
		foreach ( $raw_headers as $key => $field ) {
			$field             = strtolower( $field );
			$index             = $num_indexes ? $key : $field;
			$headers[ $index ] = $field;

			if ( isset( $default_columns[ $field ] ) ) {
				$headers[ $index ] = $default_columns[ $field ];
			}
		}

		return apply_filters( 'seopress_csv_metadata_import_mapping_default_columns', $headers, $raw_headers );
	}

	/**
	 * Get mapping options.
	 *
	 * @param string $item item name.
	 *
	 * @return array
	 */
	protected function get_mapping_options( $item = '' ) {
		// Available options.
		$options = array(
			'id'              => esc_html__( 'ID', 'wp-seopress-pro' ),
			'post_title'      => esc_html__( 'Post / term title', 'wp-seopress-pro' ),
			'slug'            => esc_html__( 'Slug', 'wp-seopress-pro' ),
			'taxonomy'        => esc_html__( 'Taxonomy', 'wp-seopress-pro' ),
			'post_type'       => esc_html__( 'Post Type', 'wp-seopress-pro' ),
			'meta_title'      => esc_html__( 'Meta Title', 'wp-seopress-pro' ),
			'meta_desc'       => esc_html__( 'Meta description', 'wp-seopress-pro' ),
			'fb_title'        => esc_html__( 'Facebook title', 'wp-seopress-pro' ),
			'fb_desc'         => esc_html__( 'Facebook description', 'wp-seopress-pro' ),
			'fb_img'          => esc_html__( 'Facebook thumbnail', 'wp-seopress-pro' ),
			'tw_title'        => esc_html__( 'X title', 'wp-seopress-pro' ),
			'tw_desc'         => esc_html__( 'X description', 'wp-seopress-pro' ),
			'tw_img'          => esc_html__( 'X thumbnail', 'wp-seopress-pro' ),
			'noindex'         => esc_html__( 'noindex? (yes)', 'wp-seopress-pro' ),
			'nofollow'        => esc_html__( 'nofollow? (yes)', 'wp-seopress-pro' ),
			'noimageindex'    => esc_html__( 'noimageindex? (yes)', 'wp-seopress-pro' ),
			'nosnippet'       => esc_html__( 'nosnippet? (yes)', 'wp-seopress-pro' ),
			'canonical_url'   => esc_html__( 'Canonical URL', 'wp-seopress-pro' ),
			'primary_cat'     => esc_html__( 'Primary category', 'wp-seopress-pro' ),
			'redirect_active' => esc_html__( 'Active redirect', 'wp-seopress-pro' ),
			'redirect_status' => esc_html__( 'Redirect status', 'wp-seopress-pro' ),
			'redirect_type'   => esc_html__( 'Redirection type', 'wp-seopress-pro' ),
			'redirect_url'    => esc_html__( 'URL redirect', 'wp-seopress-pro' ),
			'target_kw'       => esc_html__( 'Target Keyword', 'wp-seopress-pro' ),
			'breadcrumbs'     => esc_html__( 'Custom breadcrumbs', 'wp-seopress-pro' ),
		);

		return apply_filters( 'seopress_csv_metadata_import_mapping_options', $options, $item );
	}
}
