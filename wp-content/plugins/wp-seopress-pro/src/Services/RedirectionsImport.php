<?php
/**
 * SEOPress PRO bulk redirections importer.
 *
 * @package SEOPress PRO
 * @subpackage Services
 */

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Import redirections from a file, in batches, whatever the site.
 *
 * The eight importers used to read the whole upload into memory, loop over it
 * and call wp_insert_post() once per row, all inside the admin_init request
 * that received the form. A file of 18,000 redirections therefore had to fit
 * in memory twice - the raw lines and the parsed rows - then survive the
 * ~200,000 queries and the hook chain wp_insert_post() runs for each post,
 * before PHP's max_execution_time ran out. It did not: the request died, and
 * whatever had already been written stayed behind with no way to tell what was
 * missing.
 *
 * Two things change here.
 *
 * The work is split in two passes. The prepare pass reads the upload once,
 * streaming, and writes one normalized redirection per line into a private job
 * file. It touches no database at all, so it is bounded by disk rather than by
 * anything a host throttles, and it knows the exact number of rows before the
 * first insert - which is what makes both a real progress bar and a resumable
 * import possible. The run pass then reads that file from a byte offset and
 * imports a slice of it, one HTTP request per slice.
 *
 * The insert itself no longer goes through wp_insert_post(). Redirections are
 * rows in a post table, not editorial content: nothing subscribes to their
 * save_post, they have no revisions, no slug anybody reads, and no term count
 * worth recomputing 18,000 times. So a slice is written as a handful of
 * multi-row INSERTs - posts, then meta, then term relationships - which turns
 * ~11 queries per redirection into ~4 per hundred.
 *
 * @since 10.3.0
 */
class RedirectionsImport {

	/**
	 * Post type holding redirections.
	 */
	const POST_TYPE = 'seopress_404';

	/**
	 * Taxonomy holding redirection categories.
	 */
	const TAXONOMY = 'seopress_404_cat';

	/**
	 * Option prefix holding an import job, one per user.
	 *
	 * An option rather than a transient: an import spans many requests and
	 * possibly several minutes, and a transient on a site with a memory-only
	 * object cache can be evicted between two of them, which would orphan the
	 * job file and leave the progress bar stuck. Not a *user* option either, so
	 * that WP-CLI - which runs with no current user - can drive the same code.
	 */
	const JOB_OPTION = 'seopress_redirections_import_job';

	/**
	 * How long a run may hold the job before another one may take it over.
	 *
	 * Long enough that a slow host finishing a slice is never mistaken for a
	 * dead one, short enough that a run killed mid-slice does not block the
	 * resume for long.
	 */
	const LOCK_TTL = 120;

	/**
	 * Layout of the job file and of the job option.
	 *
	 * Bumped whenever a resume would read either of them wrongly. A job left
	 * behind by an older build is reported as unavailable rather than resumed
	 * against rules it was not written under.
	 */
	const JOB_FORMAT = 2;

	/**
	 * Redirection types SEOPress can store.
	 *
	 * @var string[]
	 */
	const TYPES = array( '301', '302', '307', '410', '451' );

	/**
	 * Query parameter handling modes.
	 *
	 * @var string[]
	 */
	const PARAMS = array( 'exact_match', 'without_param', 'with_ignored_param' );

	/**
	 * Connection status modes.
	 *
	 * @var string[]
	 */
	const LOGGED_STATUSES = array( 'both', 'only_logged_in', 'only_not_logged_in' );

	/**
	 * Open job file handle, during a prepare pass.
	 *
	 * @var resource|null
	 */
	protected $handle = null;

	/**
	 * Origins already written during the current prepare pass.
	 *
	 * @var array<string,true>
	 */
	protected $seen = array();

	/**
	 * Counters for the current prepare pass.
	 *
	 * @var array<string,int>
	 */
	protected $counters = array();

	/**
	 * Separator detected during the current prepare pass, for CSV sources.
	 *
	 * @var string
	 */
	protected $separator = '';

	/**
	 * Term taxonomy ids resolved during a run, keyed by the raw file value.
	 *
	 * @var array<string,int>
	 */
	protected $term_cache = array();

	/**
	 * Term taxonomy ids touched by the current run, recounted once per slice.
	 *
	 * @var array<int,int>
	 */
	protected $touched_terms = array();

	/**
	 * Whether a chunk has to nest inside a transaction somebody else opened.
	 *
	 * @var bool|null Resolved once per request.
	 */
	protected $nested_transaction = null;

	/**
	 * Sources this importer understands.
	 *
	 * @return array<string,array{label:string,extensions:string[]}>
	 */
	public function get_sources() {
		return array(
			'csv'                 => array(
				'label'      => __( 'CSV file (must match the template)', 'wp-seopress-pro' ),
				'extensions' => array( 'csv', 'txt' ),
			),
			'redirections_plugin' => array(
				'label'      => __( 'Redirection plugin (JSON)', 'wp-seopress-pro' ),
				'extensions' => array( 'json' ),
			),
			'yoast'               => array(
				'label'      => __( 'Yoast Premium (CSV)', 'wp-seopress-pro' ),
				'extensions' => array( 'csv', 'txt' ),
			),
			'rankmath'            => array(
				'label'      => __( 'Rank Math (JSON)', 'wp-seopress-pro' ),
				'extensions' => array( 'json' ),
			),
			'rankmath_csv'        => array(
				'label'      => __( 'Rank Math (CSV)', 'wp-seopress-pro' ),
				'extensions' => array( 'csv', 'txt' ),
			),
			'slimseo'             => array(
				'label'      => __( 'Slim SEO (CSV)', 'wp-seopress-pro' ),
				'extensions' => array( 'csv', 'txt' ),
			),
			'aioseo'              => array(
				'label'      => __( 'All in One SEO (JSON)', 'wp-seopress-pro' ),
				'extensions' => array( 'json' ),
			),
			'smartcrawl'          => array(
				'label'      => __( 'SmartCrawl (JSON)', 'wp-seopress-pro' ),
				'extensions' => array( 'json' ),
			),
		);
	}

	/**
	 * How many redirections one run() call imports.
	 *
	 * Deliberately conservative: a slice of 500 is a few hundred milliseconds
	 * of database work on a healthy host and still well inside the request
	 * budget of a slow one. A larger file simply means more requests.
	 *
	 * @return int
	 */
	public function get_batch_size() {
		$size = (int) apply_filters( 'seopress_redirections_import_batch_size', 500 );

		return max( 1, min( 5000, $size ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Job lifecycle
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read the uploaded file and write the job file the run pass consumes.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param string $source    Source key, see get_sources().
	 * @param array  $options   Optional. 'separator' => 'auto'|'comma'|'semicolon'|'tab'.
	 *
	 * @return array|\WP_Error Job descriptor, or an error.
	 */
	public function prepare( $file_path, $source, $options = array() ) {
		return $this->with_job_lock( function () use ( $file_path, $source, $options ) {
			return $this->prepare_job( $file_path, $source, $options );
		} );
	}

	protected function prepare_job( $file_path, $source, $options = array() ) {
		$sources = $this->get_sources();

		if ( ! isset( $sources[ $source ] ) ) {
			return new \WP_Error(
				'seopress_import_unknown_source',
				__( 'Unknown import source.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error(
				'seopress_import_unreadable_file',
				__( 'The uploaded file could not be read.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$job_dir = $this->get_job_dir();

		if ( is_wp_error( $job_dir ) ) {
			return $job_dir;
		}

		// Any job still around for this user is now abandoned: one import at a
		// time, and starting a new one is also how a stuck job gets cleaned up.
		$this->cancel_job();

		$job_id   = wp_generate_password( 32, false, false );
		$job_file = $job_dir . 'job-' . $job_id . '.ndjson';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write of our own temporary file; WP_Filesystem has no streaming API.
		$this->handle = fopen( $job_file, 'wb' );

		if ( false === $this->handle ) {
			return new \WP_Error(
				'seopress_import_job_file',
				__( 'The temporary import file could not be written. Check the permissions of your uploads folder.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		$this->seen      = array();
		$this->separator = '';
		$this->counters  = array(
			'written'   => 0,
			'skipped'   => 0,
			'degraded'  => 0,
			'duplicate' => 0,
			'disabled'  => 0,
		);

		$method = 'parse_' . $source;
		$parsed = $this->{$method}( $file_path, $options );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $this->handle );
		$this->handle = null;
		$this->seen   = array();

		if ( is_wp_error( $parsed ) ) {
			wp_delete_file( $job_file );

			return $parsed;
		}

		if ( 0 === $this->counters['written'] ) {
			wp_delete_file( $job_file );

			return new \WP_Error(
				'seopress_import_empty',
				__( 'No valid redirection was found in this file. Check the source you selected and, for a CSV, the separator and the column order.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$job = array(
			'id'        => $job_id,
			'format'    => self::JOB_FORMAT,
			'file'      => $job_file,
			'source'    => $source,
			'separator' => $this->separator,
			'total'     => $this->counters['written'],
			'offset'    => 0,
			'processed' => 0,
			'imported'  => 0,
			'skipped'   => $this->counters['skipped'],
			'degraded'  => $this->counters['degraded'],
			'duplicate' => $this->counters['duplicate'],
			'disabled'  => $this->counters['disabled'],
			'disabled_imported' => 0,
			'resumed'   => 0,
			'lock'      => 0,
			'created'   => time(),
			'updated'   => time(),
		);

		$this->save_job( $job );

		return $job;
	}

	/**
	 * Import the next slice of a prepared job.
	 *
	 * @param string $job_id Job identifier returned by prepare().
	 * @param int    $limit  Optional. Rows to import, defaults to get_batch_size().
	 *
	 * @return array|\WP_Error Job progress, or an error.
	 */
	public function run( $job_id, $limit = 0 ) {
		return $this->with_job_lock( function () use ( $job_id, $limit ) {
			return $this->run_job( $job_id, $limit );
		} );
	}

	protected function run_job( $job_id, $limit = 0 ) {
		$job = $this->get_job();

		if ( ! $job || ! hash_equals( (string) $job['id'], (string) $job_id ) ) {
			return new \WP_Error(
				'seopress_import_no_job',
				__( 'This import is no longer available. Please upload your file again.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! is_readable( $job['file'] ) ) {
			$this->cancel_job();

			return new \WP_Error(
				'seopress_import_lost_job_file',
				__( 'The temporary import file was removed before the import finished. Please upload your file again.', 'wp-seopress-pro' ),
				array( 'status' => 410 )
			);
		}

		// Two tabs resuming the same job would both read the same offset and
		// both write it: the slug check below only sees what is already
		// committed, so two runs racing inside the same slice defeat it.
		if ( ! empty( $job['lock'] ) && ( time() - (int) $job['lock'] ) < self::LOCK_TTL ) {
			return new \WP_Error(
				'seopress_import_locked',
				__( 'This import is already running, in another tab or window.', 'wp-seopress-pro' ),
				array( 'status' => 409 )
			);
		}

		$job['lock'] = time();
		$this->save_job( $job );

		// Resume jobs created before private storage without resetting their offset.
		$private_dir = $this->get_job_dir();
		if ( is_wp_error( $private_dir ) ) {
			return $this->unlock( $job, $private_dir );
		}
		if ( wp_normalize_path( dirname( $job['file'] ) ) !== untrailingslashit( wp_normalize_path( $private_dir ) ) ) {
			$upload = wp_upload_dir();
			$legacy = trailingslashit( $upload['basedir'] ) . 'seopress-redirections-import';
			$real   = realpath( $job['file'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local move preserves a resumable job; a failed move must leave the source intact.
			if ( ! $real || dirname( $real ) !== realpath( $legacy ) || ! preg_match( '/^job-[A-Za-z0-9]+\.ndjson$/', basename( $real ) ) || ! rename( $real, $private_dir . basename( $real ) ) ) {
				return $this->unlock( $job, new \WP_Error( 'seopress_import_private_storage', __( 'The import file could not be moved to private storage. Please upload it again.', 'wp-seopress-pro' ) ) );
			}
			$job['file'] = $private_dir . basename( $real );
			$this->save_job( $job );
		}

		// The sweep goes by modification time, and reading a file does not
		// change it: without this an import running for longer than the sweep
		// window would have its own job file collected underneath it.
		touch( $job['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Refreshing the mtime of our own job file; WP_Filesystem has no equivalent.

		$limit = $limit > 0 ? (int) $limit : $this->get_batch_size();
		$slice = $this->read_slice( $job['file'], $job['offset'], $limit );

		if ( is_wp_error( $slice ) ) {
			return $this->unlock( $job, $slice );
		}

		$written = array(
			'imported' => 0,
			'existing' => 0,
		);

		if ( ! empty( $slice['rows'] ) ) {
			$written = $this->insert_rows( $slice['rows'], $this->job_marker( $job ) );

			// A slice that could not be written leaves the offset where it was,
			// so the next call replays exactly these rows rather than stepping
			// over them. Reporting them as "skipped" and moving on, which is
			// what this used to do, loses them without saying so.
			if ( is_wp_error( $written ) ) {
				return $this->unlock( $job, $written );
			}
		}

		// Count only completed slices. Replayed rows are included once, just
		// like imported/existing above; a failed slice never advances this count.
		// Older jobs lack the counter: keep it unknown until completion.
		if ( isset( $job['disabled_imported'] ) ) {
			foreach ( $slice['rows'] as $row ) {
				if ( 'yes' !== $row['e'] ) {
					++$job['disabled_imported'];
				}
			}
		}

		$job['offset']     = $slice['offset'];
		$job['processed'] += count( $slice['rows'] );
		// Rows a previous attempt had already committed count as imported: this
		// job wrote them, it just died before it could record having done so.
		$job['imported'] += $written['imported'] + $written['existing'];
		$job['resumed']  += $written['existing'];
		$job['updated']   = time();
		$job['lock']      = 0;

		$done = $slice['eof'] || $job['processed'] >= $job['total'];

		if ( $done ) {
			$this->finish();
			$this->cancel_job();
		} else {
			$this->save_job( $job );
		}

		return $this->progress( $job, $done );
	}

	/**
	 * Release the lock and hand back the error that caused it.
	 *
	 * @param array     $job   Job descriptor.
	 * @param \WP_Error $error Error to return.
	 *
	 * @return \WP_Error
	 */
	protected function unlock( $job, $error ) {
		$job['lock'] = 0;
		$this->save_job( $job );

		return $error;
	}

	/**
	 * The token that ties a written row back to the job that wrote it.
	 *
	 * Derived from the job id rather than being the job id: the marker ends up
	 * in post_name, where it outlives the import and is readable by anyone who
	 * can list redirections, while the id names the job file. Hashing keeps the
	 * one from giving away the other.
	 *
	 * @param array $job Job descriptor.
	 *
	 * @return string
	 */
	protected function job_marker( $job ) {
		return substr( md5( (string) $job['id'] ), 0, 16 );
	}

	/**
	 * Import a whole file in one go, without the batching round trips.
	 *
	 * For WP-CLI and for the no-JavaScript form fallback, where there is no
	 * client to drive the slices. Still slice by slice internally, so memory
	 * stays flat however large the file is.
	 *
	 * @param string        $file_path Path to the uploaded file.
	 * @param string        $source    Source key.
	 * @param array         $options   Optional. Same as prepare().
	 * @param callable|null $on_slice  Optional. Called with the progress array after each slice.
	 *
	 * @return array|\WP_Error Final progress, or an error.
	 */
	public function import_all( $file_path, $source, $options = array(), $on_slice = null ) {
		$job = $this->prepare( $file_path, $source, $options );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		do {
			$state = $this->run( $job['id'] );

			// Deliberately not cancelled: the job and everything it has already
			// written stay put, so the Tools screen offers to resume it and the
			// rows written so far are not thrown away with it.
			if ( is_wp_error( $state ) ) {
				return $state;
			}

			if ( is_callable( $on_slice ) ) {
				call_user_func( $on_slice, $state );
			}
		} while ( empty( $state['done'] ) );

		return $state;
	}

	/**
	 * Drop the current user's job and its file.
	 *
	 * @return void|\WP_Error
	 */
	public function cancel() {
		return $this->with_job_lock( function () {
			return $this->cancel_job();
		} );
	}

	protected function cancel_job() {
		$job = $this->get_job();

		if ( $job && ! empty( $job['file'] ) ) {
			$this->delete_job_file( $job['file'] );
		}

		delete_option( $this->job_option_name() );
	}

	/** Locks belong to the database connection and cannot expire during a live slice. */
	private static $held_locks = array();

	/**
	 * Serialize preparing, resuming and cancelling a user's import.
	 *
	 * A separate option read/write is not a mutex. Acquire before reading the
	 * offset, including when it was cached earlier in this request.
	 *
	 * @param callable $callback Operation to execute while holding the lock.
	 * @return mixed|\WP_Error
	 */
	protected function with_job_lock( $callback ) {
		global $wpdb;
		$name = 'seopress_import_' . md5( DB_NAME . ':' . $wpdb->options . ':' . get_current_user_id() );
		$locked = isset( self::$held_locks[ $name ] ) ? '0' : $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( null === $locked || false === $locked ) {
			return new \WP_Error(
				'seopress_import_lock_unavailable',
				__( 'The import could not acquire a database lock. Please try again.', 'wp-seopress-pro' ),
				array( 'status' => 503 )
			);
		}
		if ( '1' !== (string) $locked ) {
			return new \WP_Error(
				'seopress_import_locked',
				__( 'This import is already running, in another tab or window.', 'wp-seopress-pro' ),
				array( 'status' => 409 )
			);
		}

		self::$held_locks[ $name ] = true;
		try {
			wp_cache_delete( $this->job_option_name(), 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			unset( self::$held_locks[ $name ] );
		}
	}

	/**
	 * The current user's job, if any.
	 *
	 * @return array|null
	 */
	public function get_job() {
		$job = get_option( $this->job_option_name() );

		if ( ! is_array( $job ) || empty( $job['id'] ) || empty( $job['file'] ) ) {
			return null;
		}

		if ( ! isset( $job['format'] ) || self::JOB_FORMAT !== (int) $job['format'] ) {
			return null;
		}

		return $job;
	}

	/**
	 * Progress payload shared by run() and the REST layer.
	 *
	 * @param array $job  Job descriptor.
	 * @param bool  $done Whether the job is complete.
	 *
	 * @return array
	 */
	public function progress( $job, $done ) {
		$total   = max( 1, (int) $job['total'] );
		$percent = $done ? 100 : (int) floor( ( (int) $job['processed'] / $total ) * 100 );

		return array(
			'jobId'     => $job['id'],
			'source'    => $job['source'],
			'total'     => (int) $job['total'],
			'processed' => (int) $job['processed'],
			'imported'  => (int) $job['imported'],
			'skipped'   => (int) $job['skipped'],
			'degraded'  => (int) $job['degraded'],
			'duplicate' => (int) $job['duplicate'],
			// Guarded rather than read directly: a job prepared by an earlier
			// version and resumed after the upgrade has no such key, and the
			// row format is unchanged, so it is worth finishing rather than
			// discarding over a counter.
			'disabled'  => (int) ( isset( $job['disabled'] ) ? $job['disabled'] : 0 ),
			'disabled_imported' => isset( $job['disabled_imported'] )
				? (int) $job['disabled_imported']
				: ( $done && isset( $job['disabled'] ) ? (int) $job['disabled'] : null ),
			'resumed'   => (int) ( isset( $job['resumed'] ) ? $job['resumed'] : 0 ),
			'percent'   => min( 100, max( 0, $percent ) ),
			'done'      => (bool) $done,
			// What the resume banner needs to describe the job it found.
			'startedAt' => (int) ( isset( $job['created'] ) ? $job['created'] : 0 ),
			'updatedAt' => (int) ( isset( $job['updated'] ) ? $job['updated'] : 0 ),
			'running'   => ! empty( $job['lock'] ) && ( time() - (int) $job['lock'] ) < self::LOCK_TTL,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Job storage
	|--------------------------------------------------------------------------
	*/

	/**
	 * Persist the job for the current user.
	 *
	 * @param array $job Job descriptor.
	 *
	 * @return void
	 */
	protected function save_job( $job ) {
		update_option( $this->job_option_name(), $job, false );
	}

	/**
	 * Name of the option holding the current user's job.
	 *
	 * @return string
	 */
	protected function job_option_name() {
		return self::JOB_OPTION . '_' . get_current_user_id();
	}

	/**
	 * Directory holding the job files, created and locked down on first use.
	 *
	 * @return string|\WP_Error Trailing-slashed path, or an error.
	 */
	public function get_job_dir() {
		$dir = seopress_private_import_dir( 'redirections' );
		return is_wp_error( $dir ) ? $dir : trailingslashit( $dir );
	}

	/**
	 * Delete a job file, refusing anything outside the job folder.
	 *
	 * @param string $file Absolute path.
	 *
	 * @return void
	 */
	protected function delete_job_file( $file ) {
		$dir = $this->get_job_dir();

		if ( is_wp_error( $dir ) ) {
			return;
		}

		$real_dir  = realpath( $dir );
		$real_file = realpath( $file );

		if ( ! $real_dir || ! $real_file ) {
			return;
		}

		$upload = wp_upload_dir();
		$legacy = realpath( trailingslashit( $upload['basedir'] ) . 'seopress-redirections-import' );
		if ( ( dirname( $real_file ) !== $real_dir && ( ! $legacy || dirname( $real_file ) !== $legacy ) ) || ! preg_match( '/^job-[A-Za-z0-9]+\.ndjson$/', basename( $real_file ) ) ) {
			return;
		}

		wp_delete_file( $real_file );
	}

	/**
	 * Delete job files nobody came back for.
	 *
	 * An abandoned import - the tab is closed, the browser crashes - leaves its
	 * job file behind, and the user option pointing at it is only replaced on
	 * that user's next import. Swept daily.
	 *
	 * @param int $max_age Optional. Age in seconds past which a file is stale.
	 *
	 * @return int Number of files deleted.
	 */
	public function garbage_collect( $max_age = DAY_IN_SECONDS ) {
		$dir = $this->get_job_dir();

		if ( is_wp_error( $dir ) ) {
			return 0;
		}

		$files  = (array) glob( $dir . 'job-*.ndjson' );
		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
			$files = array_merge( $files, (array) glob( trailingslashit( $upload['basedir'] ) . 'seopress-redirections-import/job-*.ndjson' ) );
		}

		if ( empty( $files ) ) {
			return 0;
		}

		$deleted = 0;
		$cutoff  = time() - (int) $max_age;

		foreach ( $files as $file ) {
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file deleted between the glob and this call is exactly what the sweep is for.

			if ( $mtime && $mtime > $cutoff ) {
				continue;
			}

			wp_delete_file( $file );
			++$deleted;
		}

		return $deleted;
	}

	/*
	|--------------------------------------------------------------------------
	| Reading and inserting
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read up to $limit normalized rows starting at a byte offset.
	 *
	 * Seeking by byte offset rather than by row number is what keeps a slice
	 * O(slice) instead of O(file): resuming at row 17,500 of an 18,000 row file
	 * must not mean re-reading the 17,499 before it, once per slice.
	 *
	 * @param string $file   Job file path.
	 * @param int    $offset Byte offset to resume from.
	 * @param int    $limit  Maximum rows to read.
	 *
	 * @return array{rows:array,offset:int,eof:bool}|\WP_Error
	 */
	protected function read_slice( $file, $offset, $limit ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read of our own job file.
		$handle = fopen( $file, 'rb' );

		if ( false === $handle ) {
			return new \WP_Error(
				'seopress_import_unreadable_job',
				__( 'The temporary import file could not be read.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		if ( $offset > 0 ) {
			fseek( $handle, (int) $offset );
		}

		$rows = array();

		// Counting lines rather than rows caps the work per request whatever
		// the file holds: a run of lines this pass cannot use must not turn one
		// slice into a walk through the whole file.
		$read = 0;

		while ( $read < $limit ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			++$read;

			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$row = json_decode( $line, true );

			if ( is_array( $row ) && ! empty( $row['o'] ) && isset( $row['n'] ) ) {
				$rows[] = $row;
			}
		}

		$position = ftell( $handle );
		$eof      = feof( $handle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return array(
			'rows'   => $rows,
			'offset' => false === $position ? (int) $offset : (int) $position,
			'eof'    => $eof,
		);
	}

	/**
	 * Write a slice of normalized rows to the database.
	 *
	 * @param array  $rows   Normalized rows.
	 * @param string $marker Job marker, see job_marker().
	 *
	 * @return array{imported:int,existing:int}|\WP_Error
	 */
	protected function insert_rows( $rows, $marker ) {
		$written = array(
			'imported' => 0,
			'existing' => 0,
		);

		// Term counts are recomputed once per slice instead of once per
		// relationship, which is what wp_set_object_terms() would do.
		$was_deferring = wp_defer_term_counting();
		wp_defer_term_counting( true );

		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$result = $this->insert_chunk( $chunk, $marker );

			if ( is_wp_error( $result ) ) {
				wp_defer_term_counting( $was_deferring );

				return $result;
			}

			$written['imported'] += $result['imported'];
			$written['existing'] += $result['existing'];
		}

		wp_defer_term_counting( $was_deferring );

		if ( ! empty( $this->touched_terms ) ) {
			wp_update_term_count_now( array_values( $this->touched_terms ), self::TAXONOMY );
			$this->touched_terms = array();
		}

		// Nothing has to call clean_post_cache() for rows that never existed
		// before, but WP_Query caches its result sets against this key: without
		// it the redirections list keeps answering with the pre-import page.
		wp_cache_set_last_changed( 'posts' );

		return $written;
	}

	/**
	 * Insert one chunk of rows: posts, then meta, then term relationships.
	 *
	 * Two properties make a replayed slice safe, and both matter because a
	 * slice is replayed whenever a run dies before it could record its progress
	 * - a closed tab, a dropped connection, a request the host cut short.
	 *
	 * Each row carries a slug derived from the job and from the row's own
	 * ordinal in the job file, so the same row always lands on the same key -
	 * whatever the file has been cut into. The rows already in the table under
	 * those keys are read back first and left alone, which is what turns "run
	 * this slice again" into a no-op rather than into a second copy of every
	 * redirection in it.
	 *
	 * The ordinal has to come from the row and not from where the slice starts:
	 * keyed on the slice, resuming with a different batch size moves every
	 * boundary, no key matches, and the whole tail of the file is written a
	 * second time.
	 *
	 * The three writes then happen in one transaction, so a chunk is either
	 * fully there or not there at all. Without it a run dying between the posts
	 * and their meta would leave redirections with no destination and no type,
	 * and the slug check above would then skip over them for good. This assumes
	 * a transactional storage engine, which wp_posts has had by default since
	 * MySQL 5.5.
	 *
	 * @param array  $chunk  Up to 100 normalized rows.
	 * @param string $marker Job marker.
	 *
	 * @return array{imported:int,existing:int}|\WP_Error
	 */
	protected function insert_chunk( $chunk, $marker ) {
		global $wpdb;

		$slugs = array();

		foreach ( $chunk as $index => $row ) {
			$slugs[ $index ] = sprintf( 'seopress-r-%s-%d', $marker, (int) $row['n'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$slug_placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		$already = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_name IN ($slug_placeholders)",
				array_merge( array( self::POST_TYPE ), array_values( $slugs ) )
			)
		);

		$already = array_flip( (array) $already );
		$pending = array();

		foreach ( $chunk as $index => $row ) {
			if ( ! isset( $already[ $slugs[ $index ] ] ) ) {
				$pending[ $index ] = $row;
			}
		}

		$existing = count( $chunk ) - count( $pending );

		if ( empty( $pending ) ) {
			return array(
				'imported' => 0,
				'existing' => $existing,
			);
		}

		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', 1 );
		$author  = get_current_user_id();

		$values       = array();
		$placeholders = array();

		foreach ( $pending as $index => $row ) {
			$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, %s, %d)';

			array_push(
				$values,
				$author,
				$now,
				$now_gmt,
				'',               // post_content.
				$this->prepare_title( $row['o'] ),
				'',               // post_excerpt.
				'publish',
				'closed',         // comment_status.
				'closed',         // ping_status.
				'',               // post_password.
				$slugs[ $index ],
				'',               // to_ping.
				'',               // pinged.
				$now,
				$now_gmt,
				'',               // post_content_filtered.
				0,                // post_parent.
				'',               // guid: a non-public post type has no permalink, and nothing reads it.
				0,                // menu_order.
				self::POST_TYPE,
				'',               // post_mime_type.
				0                 // comment_count.
			);
		}

		$this->begin_chunk();

		// The VALUES lists are built above out of %d and %s only - one group per
		// row - so the statement handed to prepare() is still entirely made of
		// placeholders, and every value still travels through prepare().
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->posts}
					(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
					VALUES " . implode( ', ', $placeholders ),
				$values
			)
		);

		if ( false === $inserted ) {
			$this->rollback_chunk();

			return $this->write_failed();
		}

		// Read the ids back through the slugs rather than assuming they follow
		// on from $wpdb->insert_id. MySQL 8 defaults innodb_autoinc_lock_mode
		// to 2, where a concurrent insert into wp_posts can take an id from the
		// middle of the range this statement was allocated. Attaching the meta
		// of one redirection to the post of another is not the kind of failure
		// that announces itself.
		$pending_slugs = array();

		foreach ( array_keys( $pending ) as $index ) {
			$pending_slugs[] = $slugs[ $index ];
		}

		$slug_placeholders = implode( ', ', array_fill( 0, count( $pending_slugs ), '%s' ) );

		$mapped = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_name IN ($slug_placeholders)",
				array_merge( array( self::POST_TYPE ), $pending_slugs )
			),
			ARRAY_A
		);

		$ids = array();

		foreach ( (array) $mapped as $post ) {
			$ids[ $post['post_name'] ] = (int) $post['ID'];
		}

		$meta_rows     = array();
		$relationships = array();
		$imported      = 0;

		foreach ( $pending as $index => $row ) {
			$slug = $slugs[ $index ];

			if ( empty( $ids[ $slug ] ) ) {
				$this->rollback_chunk();

				return $this->write_failed();
			}

			$post_id = $ids[ $slug ];
			++$imported;

			foreach ( $this->build_meta( $row ) as $key => $value ) {
				$meta_rows[] = array( $post_id, $key, $value );
			}

			if ( ! empty( $row['g'] ) ) {
				foreach ( $this->resolve_terms( $row['g'] ) as $term_taxonomy_id ) {
					$relationships[] = array( $post_id, $term_taxonomy_id );
				}
			}
		}

		// A hundred redirections carry seven hundred meta rows, and a
		// destination is a URL of arbitrary length: sent as one statement that
		// is a megabyte of SQL on a file with long URLs, and a host running the
		// default max_allowed_packet would drop the connection rather than the
		// statement. Two hundred rows keeps every statement small enough to be
		// beyond question and still costs only four queries per chunk.
		foreach ( array_chunk( $meta_rows, 200 ) as $meta_chunk ) {
			$meta_values       = array();
			$meta_placeholders = array();

			foreach ( $meta_chunk as $meta ) {
				$meta_placeholders[] = '(%d, %s, %s)';
					// Match the metadata API: serialized-looking CSV text must stay a string.
				array_push( $meta_values, $meta[0], $meta[1], maybe_serialize( $meta[2] ) );
			}

			$meta_written = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $meta_placeholders ),
					$meta_values
				)
			);

			if ( false === $meta_written ) {
				$this->rollback_chunk();

				return $this->write_failed();
			}
		}

		if ( ! empty( $relationships ) ) {
			$rel_placeholders = array();
			$rel_values       = array();

			foreach ( $relationships as $relationship ) {
				$rel_placeholders[] = '(%d, %d, 0)';
				array_push( $rel_values, $relationship[0], $relationship[1] );

				$this->touched_terms[ $relationship[1] ] = $relationship[1];
			}

			// IGNORE because one row may list the same category twice, and
			// (object_id, term_taxonomy_id) is the primary key.
			$rel_written = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . implode( ', ', $rel_placeholders ),
					$rel_values
				)
			);

			if ( false === $rel_written ) {
				$this->rollback_chunk();

				return $this->write_failed();
			}
		}

		$this->commit_chunk();
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array(
			'imported' => $imported,
			'existing' => $existing,
		);
	}

	/**
	 * Open the boundary a chunk is written inside.
	 *
	 * A plain START TRANSACTION is only correct when nothing else has one open.
	 * The WordPress test suite wraps every test in a transaction it rolls back
	 * afterwards, and committing there would commit the whole test's writes as
	 * well - so a savepoint is used instead, which gives the same all-or-nothing
	 * for these three writes without touching the enclosing transaction.
	 *
	 * autocommit is what tells the two apart: WordPress itself never opens a
	 * transaction, and the code that does - the test bootstrap - turns
	 * autocommit off to do it.
	 *
	 * @return void
	 */
	protected function begin_chunk() {
		global $wpdb;

		if ( null === $this->nested_transaction ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->nested_transaction = ! (int) $wpdb->get_var( 'SELECT @@autocommit' );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $this->nested_transaction ) {
			$wpdb->query( 'SAVEPOINT seopress_import_chunk' );
		} else {
			$wpdb->query( 'START TRANSACTION' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Undo everything the current chunk wrote.
	 *
	 * @return void
	 */
	protected function rollback_chunk() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $this->nested_transaction ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT seopress_import_chunk' );
			$wpdb->query( 'RELEASE SAVEPOINT seopress_import_chunk' );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Make the current chunk permanent.
	 *
	 * @return void
	 */
	protected function commit_chunk() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $this->nested_transaction ) {
			$wpdb->query( 'RELEASE SAVEPOINT seopress_import_chunk' );
		} else {
			$wpdb->query( 'COMMIT' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The error a failed chunk reports, carrying whatever the database said.
	 *
	 * @return \WP_Error
	 */
	protected function write_failed() {
		global $wpdb;

		$detail = ! empty( $wpdb->last_error ) ? $wpdb->last_error : __( 'the database refused the write', 'wp-seopress-pro' );

		return new \WP_Error(
			'seopress_import_write_failed',
			sprintf(
				/* translators: %s: database error message. */
				__( 'A batch could not be written (%s). Nothing was left half-written, and the import can be resumed from where it stopped.', 'wp-seopress-pro' ),
				$detail
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Prepare an origin for the post_title column.
	 *
	 * WordPress would run the title through sanitize_post(), and of what
	 * that does only the invalid-UTF-8 check matters here: the column rejects
	 * invalid UTF-8, and one bad row would fail the whole multi-row statement.
	 * The KSES pass core adds for a user without unfiltered_html has nothing
	 * left to strip - normalize_origin() already ran wp_strip_all_tags() - and
	 * running it anyway would corrupt regex patterns containing `<`.
	 *
	 * @param string $origin Origin as normalized by the parse pass.
	 *
	 * @return string
	 */
	protected function prepare_title( $origin ) {
		return wp_check_invalid_utf8( (string) $origin );
	}

	/**
	 * Post meta for one normalized row.
	 *
	 * Every key is written, the empty counter included, so an imported
	 * redirection is indistinguishable from one created in the editor: the list
	 * orders on those meta keys, and a row missing one drops out of the sort.
	 *
	 * @param array $row Normalized row.
	 *
	 * @return array<string,string>
	 */
	protected function build_meta( $row ) {
		return array(
			'_seopress_redirections_value'         => (string) ( isset( $row['t'] ) ? $row['t'] : '' ),
			'_seopress_redirections_type'          => (string) ( isset( $row['ty'] ) ? $row['ty'] : '301' ),
			'_seopress_redirections_enabled'       => (string) ( isset( $row['e'] ) ? $row['e'] : '' ),
			'_seopress_redirections_enabled_regex' => (string) ( isset( $row['r'] ) ? $row['r'] : '' ),
			'_seopress_redirections_logged_status' => (string) ( isset( $row['l'] ) ? $row['l'] : 'both' ),
			'_seopress_redirections_param'         => (string) ( isset( $row['p'] ) ? $row['p'] : 'exact_match' ),
			'seopress_404_count'                   => (string) ( isset( $row['c'] ) ? $row['c'] : '' ),
		);
	}

	/**
	 * Resolve the categories of a row to term taxonomy ids.
	 *
	 * Accepts what the exports actually carry: term ids (SEOPress CSV) and
	 * slugs or names (Rank Math). A slug matching nothing is created, the way
	 * wp_set_object_terms() would.
	 *
	 * @param array $categories Raw category values.
	 *
	 * @return int[] Term taxonomy ids.
	 */
	protected function resolve_terms( $categories ) {
		$resolved = array();

		foreach ( (array) $categories as $category ) {
			$key = (string) $category;

			if ( '' === $key ) {
				continue;
			}

			if ( ! array_key_exists( $key, $this->term_cache ) ) {
				$this->term_cache[ $key ] = $this->resolve_term( $key );
			}

			if ( $this->term_cache[ $key ] ) {
				$resolved[ $this->term_cache[ $key ] ] = $this->term_cache[ $key ];
			}
		}

		return array_values( $resolved );
	}

	/**
	 * Resolve a single category value to a term taxonomy id.
	 *
	 * @param string $category Term id, slug or name.
	 *
	 * @return int Term taxonomy id, 0 when it could not be resolved.
	 */
	protected function resolve_term( $category ) {
		if ( ctype_digit( $category ) ) {
			$term = get_term( (int) $category, self::TAXONOMY, ARRAY_A );

			// A numeric id matching nothing is a leftover from the site the
			// file was exported from. Creating a category named "738" would be
			// worse than dropping it.
			if ( ! $term || is_wp_error( $term ) ) {
				return 0;
			}

			return (int) $term['term_taxonomy_id'];
		}

		$term = get_term_by( 'slug', $category, self::TAXONOMY, ARRAY_A );

		if ( ! $term ) {
			$term = get_term_by( 'name', $category, self::TAXONOMY, ARRAY_A );
		}

		if ( ! $term ) {
			$created = wp_insert_term( $category, self::TAXONOMY );

			if ( is_wp_error( $created ) ) {
				return 0;
			}

			$term = get_term( (int) $created['term_id'], self::TAXONOMY, ARRAY_A );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}

		return (int) $term['term_taxonomy_id'];
	}

	/**
	 * Wrap up a finished job.
	 *
	 * @return void
	 */
	protected function finish() {
		wp_cache_set_last_changed( 'posts' );

		/**
		 * Fires once a bulk redirections import has written its last row.
		 *
		 * @since 10.3.0
		 */
		do_action( 'seopress_redirections_imported' );
	}

	/*
	|--------------------------------------------------------------------------
	| Row normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize one redirection and write it to the job file.
	 *
	 * @param array $args Row pieces, see the defaults below.
	 *
	 * @return bool Whether a row was written.
	 */
	protected function write_row( $args ) {
		$defaults = array(
			'origin'     => '',
			'target'     => '',
			'type'       => '301',
			'enabled'    => '',
			'regex'      => '',
			'param'      => 'exact_match',
			'logged'     => 'both',
			'count'      => '',
			'categories' => array(),
		);

		$args     = array_merge( $defaults, $args );
		$is_regex = 'yes' === $args['regex'];
		$origin   = $this->normalize_origin( $args['origin'], $is_regex );

		if ( '' === $origin ) {
			++$this->counters['skipped'];

			return false;
		}

		$type = (string) $args['type'];

		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = '301';
		}

		$param = (string) $args['param'];

		if ( ! in_array( $param, self::PARAMS, true ) ) {
			$param = 'exact_match';
		}

		$logged = (string) $args['logged'];

		if ( ! in_array( $logged, self::LOGGED_STATUSES, true ) ) {
			$logged = 'both';
		}

		$target = $this->normalize_target( $args['target'] );

		// A 3xx pointing nowhere is not a redirection, it is a row that will
		// silently do nothing. 410 and 451 legitimately have no destination.
		if ( '' === $target && in_array( $type, array( '301', '302', '307' ), true ) ) {
			++$this->counters['skipped'];

			return false;
		}

		if ( $this->is_duplicate( $origin, $is_regex ) ) {
			++$this->counters['duplicate'];

			return false;
		}

		$enabled = 'yes' === $args['enabled'] ? 'yes' : '';

		// Counted, because an import that reports nothing but "52 redirections
		// imported successfully" over a file whose `Enabled` column was empty
		// leaves the user with 52 rules that do nothing and no reason to go
		// looking. The column is honoured either way - this only says so. See
		// #1696.
		if ( '' === $enabled ) {
			++$this->counters['disabled'];
		}

		$row = array(
			// The row's ordinal in the job file. It is what a replayed slice is
			// recognised by, so it must depend on the row alone - never on how
			// the file happens to be cut into slices, which changes with the
			// batch size and would shift every key with it.
			'n'  => $this->counters['written'],
			'o'  => $origin,
			't'  => $target,
			'ty' => $type,
			'e'  => $enabled,
			'r'  => $is_regex ? 'yes' : '',
			'p'  => $param,
			'l'  => $logged,
			'c'  => '' === (string) $args['count'] ? '' : (string) (int) $args['count'],
			'g'  => array_values( array_filter( array_map( 'strval', (array) $args['categories'] ), 'strlen' ) ),
		);

		$encoded = wp_json_encode( $row );

		if ( false === $encoded ) {
			++$this->counters['skipped'];

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $this->handle, $encoded . "\n" );
		++$this->counters['written'];

		return true;
	}

	/**
	 * Whether this origin was already written during this prepare pass.
	 *
	 * The old importer deduplicated with array_unique( $csv, SORT_REGULAR ),
	 * which compares every row against every other one and holds the whole file
	 * while doing it. A hash set costs one lookup per row instead, and is
	 * capped so that an unusually large file degrades to "imported with
	 * duplicates" rather than to an out-of-memory error.
	 *
	 * A pattern and a path that happen to read the same are different
	 * redirections, so the mode is part of the key.
	 *
	 * @param string $origin   Normalized origin.
	 * @param bool   $is_regex Whether the origin is a pattern.
	 *
	 * @return bool
	 */
	protected function is_duplicate( $origin, $is_regex ) {
		$limit = (int) apply_filters( 'seopress_redirections_import_dedupe_limit', 250000 );

		if ( $limit > 0 && count( $this->seen ) >= $limit ) {
			return false;
		}

		$key = md5( ( $is_regex ? 'r:' : 'p:' ) . $origin );

		if ( isset( $this->seen[ $key ] ) ) {
			return true;
		}

		$this->seen[ $key ] = true;

		return false;
	}

	/**
	 * Normalize an origin the way the redirection matcher will read it back.
	 *
	 * Mirrors the REST editor's normalize_origin(): a plain path loses its
	 * scheme, host, fragment and leading slash; a regex is a pattern, not a
	 * path, and keeps every one of those - the leading `/` anchors the match,
	 * `#` is not a fragment and `%2F` is not an escape waiting to be resolved.
	 *
	 * @param string $origin   Raw origin from the file.
	 * @param bool   $is_regex Whether the origin is a pattern.
	 *
	 * @return string
	 */
	protected function normalize_origin( $origin, $is_regex ) {
		$origin = trim( (string) $origin );

		if ( ! $is_regex ) {
			$origin = rawurldecode( $origin );
			$origin = seopress_pro_redirection_origin_path( $origin );
		}

		// Not sanitize_text_field(): it strips every %XX sequence, so a
		// percent-encoded UTF-8 path comes out matching nothing. The rest of
		// what it does is worth keeping.
		$origin = wp_check_invalid_utf8( $origin );
		$origin = wp_strip_all_tags( $origin );
		$origin = preg_replace( '/[\r\n\t ]+/', ' ', $origin );

		return trim( (string) $origin );
	}

	/**
	 * Normalize a redirection destination.
	 *
	 * @param string $target Raw destination from the file.
	 *
	 * @return string
	 */
	protected function normalize_target( $target ) {
		$target = trim( (string) $target );

		if ( '' === $target ) {
			return '';
		}

		$target = rawurldecode( $target );
		$target = wp_check_invalid_utf8( $target );
		$target = wp_strip_all_tags( $target );

		return trim( (string) preg_replace( '/[\r\n\t]+/', '', $target ) );
	}

	/**
	 * Make a relative destination absolute, leaving absolute ones alone.
	 *
	 * @param string $target Destination.
	 *
	 * @return string
	 */
	protected function absolutize_target( $target ) {
		$target = trim( (string) $target );

		if ( '' === $target || 0 === strpos( $target, '#' ) ) {
			return $target;
		}

		if ( preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $target ) || preg_match( '#^mailto:#i', $target ) ) {
			return $target;
		}

		return home_url( '/' . ltrim( $target, '/' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Source parsers
	|
	| One per supported export. Each reads the file, hands every redirection it
	| finds to write_row() and returns true, or a WP_Error when the file itself
	| is unusable. None of them touches the database or holds more than one row
	| at a time, which is what lets the same code path handle a 20 line file and
	| a 200,000 line one.
	|--------------------------------------------------------------------------
	*/

	/**
	 * SEOPress's own CSV export.
	 *
	 * Columns, in order: origin, destination, type, enabled, query parameter
	 * handling, hit counter, category ids, regex, connection status.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_csv( $file_path, $options ) {
		return $this->read_csv(
			$file_path,
			$options,
			function ( $cells, $index ) {
				// The export writes a header row, which the old importer read as
				// a redirection: "Title" pointing at "Redirection URL".
				if ( 0 === $index && $this->looks_like_header( $cells, 2, array( 'title', 'url', 'origin', 'source', 'source url', 'from' ) ) ) {
					return;
				}

				if ( count( $cells ) < 2 ) {
					++$this->counters['skipped'];

					return;
				}

				$categories = array();

				if ( ! empty( $cells[6] ) ) {
					$categories = array_map( 'trim', explode( ',', (string) $cells[6] ) );
				}

				$this->write_row(
					array(
						'origin'     => isset( $cells[0] ) ? $cells[0] : '',
						'target'     => isset( $cells[1] ) ? $cells[1] : '',
						'type'       => isset( $cells[2] ) ? trim( (string) $cells[2] ) : '301',
						'enabled'    => $this->truthy( isset( $cells[3] ) ? $cells[3] : '' ) ? 'yes' : '',
						'regex'      => $this->truthy( isset( $cells[7] ) ? $cells[7] : '' ) ? 'yes' : '',
						'param'      => isset( $cells[4] ) ? strtolower( trim( (string) $cells[4] ) ) : 'exact_match',
						'logged'     => isset( $cells[8] ) ? strtolower( trim( (string) $cells[8] ) ) : 'both',
						'count'      => isset( $cells[5] ) && is_numeric( trim( (string) $cells[5] ) ) ? trim( (string) $cells[5] ) : '',
						'categories' => $categories,
					)
				);
			}
		);
	}

	/**
	 * Yoast Premium's CSV export: origin, destination, type.
	 *
	 * Destinations are stored relative to the site, so they are made absolute
	 * the way the rest of SEOPress stores them.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_yoast( $file_path, $options ) {
		return $this->read_csv(
			$file_path,
			$options,
			function ( $cells, $index ) {
				if ( 0 === $index && $this->looks_like_header( $cells, 2, array( 'origin', 'source', 'old url', 'url', 'from' ) ) ) {
					return;
				}

				$target = isset( $cells[1] ) ? trim( (string) $cells[1] ) : '';

				// Yoast writes "//" for the home page.
				$target = '//' === $target ? '/' : $target;

				$this->write_row(
					array(
						'origin'  => isset( $cells[0] ) ? $cells[0] : '',
						'target'  => $this->absolutize_target( $target ),
						'type'    => isset( $cells[2] ) ? trim( (string) $cells[2] ) : '301',
						'enabled' => 'yes',
					)
				);
			}
		);
	}

	/**
	 * Rank Math's CSV export.
	 *
	 * Columns are matched by name - id, source, matching, destination, type,
	 * category, status, ignore, and sometimes hits - so their order in the file
	 * does not matter. One row may carry several sources as a JSON array, each
	 * with its own comparison mode; every one of them becomes a redirection.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_rankmath_csv( $file_path, $options ) {
		$map           = null;
		$default_order = array( 'id', 'source', 'matching', 'destination', 'type', 'category', 'status', 'ignore' );

		return $this->read_csv(
			$file_path,
			$options,
			function ( $cells, $index ) use ( &$map, $default_order ) {
				if ( 0 === $index ) {
					$header = array_map(
						function ( $cell ) {
							return strtolower( trim( (string) $cell ) );
						},
						$cells
					);

					if ( in_array( 'source', $header, true ) && in_array( 'destination', $header, true ) ) {
						$map = array_flip( $header );

						return;
					}

					$map = array_flip( $default_order );
				}

				$get = function ( $key ) use ( $cells, $map ) {
					if ( ! isset( $map[ $key ] ) || ! isset( $cells[ $map[ $key ] ] ) ) {
						return '';
					}

					return trim( (string) $cells[ $map[ $key ] ] );
				};

				$source      = $get( 'source' );
				$destination = $get( 'destination' );

				// "DELETE" destinations only mean something inside Rank Math.
				if ( '' === $source || 'DELETE' === $destination ) {
					++$this->counters['skipped'];

					return;
				}

				$hits       = $get( 'hits' );
				$status     = strtolower( $get( 'status' ) );
				$categories = array_filter( array_map( 'trim', explode( ',', $get( 'category' ) ) ), 'strlen' );

				foreach ( $this->rankmath_patterns( $source, $get( 'matching' ) ) as $pattern ) {
					$this->write_row(
						array(
							'origin'     => $pattern['pattern'],
							'target'     => $destination,
							'type'       => $get( 'type' ),
							'enabled'    => ( '' === $status || 'active' === $status ) ? 'yes' : '',
							'regex'      => $pattern['regex'],
							'count'      => is_numeric( $hits ) ? $hits : '',
							'categories' => $categories,
						)
					);
				}
			}
		);
	}

	/**
	 * Slim SEO's CSV export.
	 *
	 * Columns are matched by name: type, condition, from, to, note, enable,
	 * ignore parameters.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_slimseo( $file_path, $options ) {
		$map           = null;
		$default_order = array( 'type', 'condition', 'from', 'to', 'note', 'enable', 'ignore parameters' );

		return $this->read_csv(
			$file_path,
			$options,
			function ( $cells, $index ) use ( &$map, $default_order ) {
				if ( 0 === $index ) {
					$header = array_map(
						function ( $cell ) {
							return strtolower( trim( (string) $cell ) );
						},
						$cells
					);

					if ( in_array( 'from', $header, true ) && in_array( 'to', $header, true ) ) {
						$map = array_flip( $header );

						return;
					}

					$map = array_flip( $default_order );
				}

				$get = function ( $key ) use ( $cells, $map ) {
					if ( ! isset( $map[ $key ] ) || ! isset( $cells[ $map[ $key ] ] ) ) {
						return '';
					}

					return trim( (string) $cells[ $map[ $key ] ] );
				};

				$condition = strtolower( $get( 'condition' ) );
				$regex     = 'regex' === $condition ? 'yes' : '';
				$param     = 'exact_match';

				// Slim SEO also offers "contains", "starts with" and "ends
				// with", which SEOPress has no equivalent for. Those come in as
				// exact matches and are counted, so the summary can say so.
				if ( '' === $regex && '' !== $condition && ! in_array( $condition, array( 'exact-match', 'exact_match', 'exact' ), true ) ) {
					++$this->counters['degraded'];
				}

				if ( '' === $regex && $this->truthy( $get( 'ignore parameters' ) ) ) {
					$param = 'with_ignored_param';
				}

				$this->write_row(
					array(
						'origin'  => $get( 'from' ),
						'target'  => $this->absolutize_target( $get( 'to' ) ),
						'type'    => $get( 'type' ),
						'enabled' => $this->truthy( $get( 'enable' ) ) ? 'yes' : '',
						'regex'   => $regex,
						'param'   => $param,
					)
				);
			}
		);
	}

	/**
	 * The Redirection plugin's "WordPress redirects" JSON export.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_redirections_plugin( $file_path, $options ) {
		unset( $options );

		$data = $this->read_json( $file_path );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$redirects = isset( $data['redirects'] ) && is_array( $data['redirects'] ) ? $data['redirects'] : $data;

		foreach ( $redirects as $redirect ) {
			if ( ! is_array( $redirect ) ) {
				continue;
			}

			$flag_query = isset( $redirect['match_data']['source']['flag_query'] )
				? (string) $redirect['match_data']['source']['flag_query']
				: '';

			$param = 'exact_match';

			if ( 'pass' === $flag_query ) {
				$param = 'with_ignored_param';
			} elseif ( 'ignore' === $flag_query ) {
				$param = 'without_param';
			}

			$this->write_row(
				array(
					'origin'  => isset( $redirect['url'] ) ? $redirect['url'] : '',
					'target'  => isset( $redirect['action_data']['url'] ) ? $redirect['action_data']['url'] : '',
					'type'    => isset( $redirect['action_code'] ) ? (string) $redirect['action_code'] : '301',
					'enabled' => $this->truthy( isset( $redirect['enabled'] ) ? $redirect['enabled'] : false ) ? 'yes' : '',
					'regex'   => $this->truthy( isset( $redirect['regex'] ) ? $redirect['regex'] : false ) ? 'yes' : '',
					'param'   => $param,
					'count'   => isset( $redirect['last_count'] ) && is_numeric( $redirect['last_count'] ) ? $redirect['last_count'] : '',
				)
			);
		}

		return true;
	}

	/**
	 * Rank Math's JSON export.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_rankmath( $file_path, $options ) {
		unset( $options );

		$data = $this->read_json( $file_path );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$redirects = isset( $data['redirections'] ) && is_array( $data['redirections'] ) ? $data['redirections'] : $data;

		foreach ( $redirects as $redirect ) {
			if ( ! is_array( $redirect ) ) {
				continue;
			}

			$status = isset( $redirect['status'] ) ? strtolower( (string) $redirect['status'] ) : 'active';

			foreach ( $this->rankmath_sources( isset( $redirect['sources'] ) ? $redirect['sources'] : null ) as $pattern ) {
				$this->write_row(
					array(
						'origin'  => $pattern['pattern'],
						'target'  => isset( $redirect['url_to'] ) ? $redirect['url_to'] : '',
						'type'    => isset( $redirect['header_code'] ) ? (string) $redirect['header_code'] : '301',
						'enabled' => ( '' === $status || 'active' === $status ) ? 'yes' : '',
						'regex'   => $pattern['regex'],
						'count'   => isset( $redirect['hits'] ) && is_numeric( $redirect['hits'] ) ? $redirect['hits'] : '',
					)
				);
			}
		}

		return true;
	}

	/**
	 * All in One SEO's JSON export.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_aioseo( $file_path, $options ) {
		unset( $options );

		$data = $this->read_json( $file_path );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$redirects = isset( $data['redirects'] ) && is_array( $data['redirects'] ) ? $data['redirects'] : $data;

		foreach ( $redirects as $redirect ) {
			if ( ! is_array( $redirect ) ) {
				continue;
			}

			$param = 'exact_match';

			switch ( isset( $redirect['query_param'] ) ? $redirect['query_param'] : '' ) {
				case 'ignore':
					$param = 'without_param';
					break;
				case 'pass':
				case 'utm':
					$param = 'with_ignored_param';
					break;
			}

			$logged = 'both';

			if ( ! empty( $redirect['custom_rules'] ) ) {
				$logged = $this->aioseo_logged_status( $redirect['custom_rules'] );
			}

			$this->write_row(
				array(
					'origin'  => isset( $redirect['source_url'] ) ? $redirect['source_url'] : '',
					'target'  => isset( $redirect['target_url'] ) ? $redirect['target_url'] : '',
					'type'    => isset( $redirect['type'] ) ? (string) $redirect['type'] : '301',
					'enabled' => $this->truthy( isset( $redirect['enabled'] ) ? $redirect['enabled'] : false ) ? 'yes' : '',
					'regex'   => $this->truthy( isset( $redirect['regex'] ) ? $redirect['regex'] : false ) ? 'yes' : '',
					'param'   => $param,
					'logged'  => $logged,
					'count'   => isset( $redirect['hits'] ) && is_numeric( $redirect['hits'] ) ? $redirect['hits'] : '',
				)
			);
		}

		return true;
	}

	/**
	 * SmartCrawl's JSON export.
	 *
	 * @param string $file_path Path to the uploaded file.
	 * @param array  $options   Prepare options.
	 *
	 * @return true|\WP_Error
	 */
	protected function parse_smartcrawl( $file_path, $options ) {
		unset( $options );

		$data = $this->read_json( $file_path );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( $data as $redirect ) {
			if ( ! is_array( $redirect ) ) {
				continue;
			}

			$destination = '';

			if ( isset( $redirect['destination'] ) ) {
				if ( is_string( $redirect['destination'] ) ) {
					$destination = $redirect['destination'];
				} elseif ( is_array( $redirect['destination'] ) && ! empty( $redirect['destination']['id'] ) ) {
					$destination = (string) get_permalink( (int) $redirect['destination']['id'] );
				}
			}

			$regex = isset( $redirect['options'][0] ) && 'regex' === $redirect['options'][0] ? 'yes' : '';

			$this->write_row(
				array(
					'origin'  => isset( $redirect['source'] ) ? $redirect['source'] : '',
					'target'  => $destination,
					'type'    => isset( $redirect['type'] ) ? (string) $redirect['type'] : '301',
					'enabled' => 'yes',
					'regex'   => $regex,
				)
			);
		}

		return true;
	}

	/*
	|--------------------------------------------------------------------------
	| Parsing helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Stream a CSV file row by row.
	 *
	 * Read with fgetcsv() rather than file() + str_getcsv(): the whole point is not to
	 * hold the file in memory, and it is also the only one of the two that
	 * reads a quoted field spanning several lines - a destination carrying a
	 * line break used to split one redirection into two broken ones.
	 *
	 * @param string   $file_path Path to the CSV.
	 * @param array    $options   Prepare options.
	 * @param callable $on_row    Called with ( array $cells, int $index ).
	 *
	 * @return true|\WP_Error
	 */
	protected function read_csv( $file_path, $options, $on_row ) {
		$separator = $this->resolve_separator( $file_path, $options );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read of the uploaded file.
		$handle = fopen( $file_path, 'r' );

		if ( false === $handle ) {
			return new \WP_Error(
				'seopress_import_unreadable_file',
				__( 'The uploaded file could not be read.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$this->separator = $separator;
		$index           = 0;

		while ( true ) {
			$cells = fgetcsv( $handle, 0, $separator, '"', '\\' );

			if ( false === $cells || null === $cells ) {
				break;
			}

			// A blank line comes back as array( null ).
			if ( array( null ) === $cells ) {
				continue;
			}

			if ( 0 === $index && isset( $cells[0] ) ) {
				$cells[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cells[0] );
			}

			// The export prefixes a cell a spreadsheet would evaluate with a
			// quote; dropping it back keeps a round trip lossless.
			$cells = array_map( 'seopress_pro_unescape_csv_value', $cells );

			if ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) {
				continue;
			}

			call_user_func( $on_row, $cells, $index );
			++$index;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return true;
	}

	/**
	 * Decode a JSON export.
	 *
	 * @param string $file_path Path to the JSON file.
	 *
	 * @return array|\WP_Error
	 */
	protected function read_json( $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload, read once.
		$raw = file_get_contents( $file_path );

		if ( false === $raw || '' === trim( (string) $raw ) ) {
			return new \WP_Error(
				'seopress_import_unreadable_file',
				__( 'The uploaded file could not be read.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$data = json_decode( $raw, true );

		unset( $raw );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'seopress_import_invalid_json',
				__( 'This file is not valid JSON. Export it again from the plugin you are migrating from.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		return $data;
	}

	/**
	 * Which separator a CSV should be read with.
	 *
	 * @param string $file_path Path to the CSV.
	 * @param array  $options   Prepare options.
	 *
	 * @return string
	 */
	protected function resolve_separator( $file_path, $options ) {
		$requested = isset( $options['separator'] ) ? strtolower( (string) $options['separator'] ) : 'auto';

		$known = array(
			'comma'     => ',',
			','         => ',',
			'semicolon' => ';',
			';'         => ';',
			'tab'       => "\t",
			"\t"        => "\t",
			'pipe'      => '|',
			'|'         => '|',
		);

		if ( isset( $known[ $requested ] ) ) {
			return $known[ $requested ];
		}

		// seopress_detect_csv_separator() lives with the metadata importer,
		// which is only loaded on an admin page request - this code also runs
		// over REST and in WP-CLI.
		if ( ! function_exists( 'seopress_detect_csv_separator' ) ) {
			require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/helpers-csv.php';
		}

		return seopress_detect_csv_separator( $file_path );
	}

	/**
	 * Whether the first row of a CSV is a header rather than a redirection.
	 *
	 * Both conditions have to hold: the first cell reads like a column name,
	 * and the cell that should carry the redirection type does not. A file
	 * whose very first redirection happens to be called "url" is then still
	 * imported, and a header is still recognised whatever the export named its
	 * columns.
	 *
	 * @param array $cells      Row cells.
	 * @param int   $type_index Index of the redirection type column.
	 * @param array $names      Column names the first cell may carry.
	 *
	 * @return bool
	 */
	protected function looks_like_header( $cells, $type_index, $names ) {
		$first = isset( $cells[0] ) ? strtolower( trim( (string) $cells[0] ) ) : '';

		if ( ! in_array( $first, $names, true ) ) {
			return false;
		}

		$type = isset( $cells[ $type_index ] ) ? trim( (string) $cells[ $type_index ] ) : '';

		return ! in_array( $type, self::TYPES, true );
	}

	/**
	 * Expand a Rank Math CSV source cell into the patterns it stands for.
	 *
	 * @param string $source   Source cell, a plain pattern or a JSON array.
	 * @param string $matching Row-level comparison mode.
	 *
	 * @return array<int,array{pattern:string,regex:string}>
	 */
	protected function rankmath_patterns( $source, $matching ) {
		$patterns = array();

		if ( '[' === substr( $source, 0, 1 ) && ']' === substr( $source, -1 ) ) {
			$decoded = json_decode( $source, true );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $entry ) {
					if ( empty( $entry['pattern'] ) ) {
						continue;
					}

					$patterns[] = array(
						'pattern' => (string) $entry['pattern'],
						'regex'   => $this->rankmath_comparison( isset( $entry['comparison'] ) ? $entry['comparison'] : $matching ),
					);
				}
			}
		}

		if ( empty( $patterns ) ) {
			$patterns[] = array(
				'pattern' => $source,
				'regex'   => $this->rankmath_comparison( $matching ),
			);
		}

		return $patterns;
	}

	/**
	 * Expand the serialized `sources` column of a Rank Math JSON export.
	 *
	 * @param mixed $sources Serialized array, plain array, or a bare pattern.
	 *
	 * @return array<int,array{pattern:string,regex:string}>
	 */
	protected function rankmath_sources( $sources ) {
		if ( empty( $sources ) ) {
			return array();
		}

		if ( is_string( $sources ) && is_serialized( $sources ) ) {
			// Not through sanitize_text_field() first: that rewrites the string
			// without fixing the byte counts serialize() wrote, so every source
			// holding a percent sequence or an accent failed to unserialize and
			// the redirection was dropped without a word.
			$sources = @unserialize( $sources, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- This is the shape Rank Math exports; object instantiation is disabled.
		}

		if ( is_string( $sources ) ) {
			return array(
				array(
					'pattern' => $sources,
					'regex'   => '',
				),
			);
		}

		if ( ! is_array( $sources ) ) {
			return array();
		}

		$patterns = array();

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || empty( $source['pattern'] ) ) {
				continue;
			}

			$patterns[] = array(
				'pattern' => (string) $source['pattern'],
				'regex'   => $this->rankmath_comparison( isset( $source['comparison'] ) ? $source['comparison'] : '' ),
			);
		}

		return $patterns;
	}

	/**
	 * Translate a Rank Math comparison mode.
	 *
	 * "contains", "start" and "end" have no equivalent in SEOPress. They are
	 * imported as exact matches and counted, so the summary can say how many
	 * rules lost precision instead of leaving the user to find out from their
	 * traffic.
	 *
	 * @param string $comparison Rank Math comparison mode.
	 *
	 * @return string 'yes' when the pattern is a regex, '' otherwise.
	 */
	protected function rankmath_comparison( $comparison ) {
		$comparison = strtolower( trim( (string) $comparison ) );

		if ( 'regex' === $comparison ) {
			return 'yes';
		}

		if ( in_array( $comparison, array( 'contains', 'start', 'end' ), true ) ) {
			++$this->counters['degraded'];
		}

		return '';
	}

	/**
	 * Read the connection status out of an AIOSEO custom rule set.
	 *
	 * @param mixed $custom_rules Rules, as JSON or as an array.
	 *
	 * @return string
	 */
	protected function aioseo_logged_status( $custom_rules ) {
		if ( is_string( $custom_rules ) ) {
			$custom_rules = json_decode( $custom_rules, true );
		}

		if ( ! is_array( $custom_rules ) ) {
			return 'both';
		}

		foreach ( $custom_rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['type'], $rule['value'] ) || 'login' !== $rule['type'] ) {
				continue;
			}

			if ( 'loggedin' === $rule['value'] ) {
				return 'only_logged_in';
			}

			if ( 'loggedout' === $rule['value'] ) {
				return 'only_not_logged_in';
			}
		}

		return 'both';
	}

	/**
	 * Whether a value from an export means "on".
	 *
	 * Every plugin spells it differently, and the same plugin spells it
	 * differently between its CSV and its JSON: true, 1, "1", "yes", "active".
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	protected function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( 'yes', 'true', 'on', 'active', 'enable', 'enabled', '1' ), true );
	}
}
