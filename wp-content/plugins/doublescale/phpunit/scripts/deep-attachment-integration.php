<?php
/**
 * Deep integration checks for the unified attachment store (run via wp eval-file).
 *
 * Usage: wp eval-file wp-content/plugins/doublescale/phpunit/scripts/deep-attachment-integration.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;

$results = array();
$failed  = 0;

$pass = static function ( string $label, bool $ok, string $detail = '' ) use ( &$results, &$failed ): void {
	$results[] = array(
		'label'  => $label,
		'ok'     => $ok,
		'detail' => $detail,
	);
	if ( ! $ok ) {
		++$failed;
	}
};

$new      = $wpdb->prefix . 'doublescale_attachments';
$support  = $wpdb->prefix . 'doublescale_support_attachments';
$contract = $wpdb->prefix . 'doublescale_sales_contract_attachments';

// 1) Schema exists and is dbDelta-safe.
$migration = new \DoubleScale\Core\Database\Migrations\AttachmentsTable();
$query     = $migration->get_query();
$pass(
	'AttachmentsTable query has no COMMENT/semicolons',
	false === strpos( $query, 'COMMENT' ) && false === strpos( $query, ';' )
);

$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) === $new;
$pass( 'Unified table exists', $table_exists, $new );

if ( $table_exists ) {
	$columns = $wpdb->get_col( "DESCRIBE {$new}", 0 );
	$pass(
		'Unified table has polymorphic columns',
		in_array( 'attachable_type', $columns, true ) && in_array( 'attachable_id', $columns, true ),
		implode( ',', $columns )
	);
}

// 2) Signature + signed URL.
$core_service = new \DoubleScale\Core\Services\AttachmentService();
$sign         = $core_service->generate_signature( 1001 );
$pass( 'HMAC signature verifies', $core_service->verify_signature( 1001, $sign ) );
$pass( 'HMAC rejects tampered signature', ! $core_service->verify_signature( 1001, 'bad' ) );

$dummy            = new \DoubleScale\Core\Models\AttachmentModel();
$dummy->id        = 1001;
$dummy->file_hash = 'deep_test_hash_001';
$core_url         = $core_service->signed_url( $dummy );
$pass( 'Core signed URL uses ds_file', false !== strpos( $core_url, 'ds_file=deep_test_hash_001' ) );

$support_dummy              = new \DoubleScale\Modules\Support\Models\AttachmentModel();
$support_dummy->id          = 1001;
$support_dummy->file_hash   = 'deep_test_hash_002';
$support_url                = ( new \DoubleScale\Modules\Support\Services\AttachmentService() )->signed_url( $support_dummy );
$pass( 'Support signed URL uses legacy args', false !== strpos( $support_url, 'ds_support_file=deep_test_hash_002' ) );

// 3) Legacy migration round-trip with synthetic legacy tables.
$wpdb->query( "DELETE FROM {$new}" );
delete_option( 'doublescale_attachments_unified' );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$support}" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$contract}" );

$wpdb->query(
	"CREATE TABLE {$support} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		ticket_id BIGINT(20) UNSIGNED NULL,
		activity_id BIGINT(20) UNSIGNED NULL,
		user_id BIGINT(20) UNSIGNED NULL,
		contact_id BIGINT(20) UNSIGNED NULL,
		file_name VARCHAR(255) NOT NULL,
		file_path VARCHAR(500) NOT NULL,
		file_type VARCHAR(100) NOT NULL,
		file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		file_hash VARCHAR(64) NOT NULL,
		content_id VARCHAR(255) NULL,
		driver VARCHAR(50) NOT NULL DEFAULT 'local',
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY idx_file_hash (file_hash)
	) DEFAULT CHARSET=utf8mb4"
);

$wpdb->query(
	"CREATE TABLE {$contract} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		contract_id BIGINT(20) UNSIGNED NOT NULL,
		user_id BIGINT(20) UNSIGNED NULL,
		contact_id BIGINT(20) UNSIGNED NULL,
		file_name VARCHAR(255) NOT NULL,
		file_path VARCHAR(500) NOT NULL,
		file_type VARCHAR(100) NOT NULL DEFAULT '',
		file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		file_hash VARCHAR(64) NOT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY file_hash (file_hash)
	) DEFAULT CHARSET=utf8mb4"
);

$wpdb->insert(
	$support,
	array(
		'ticket_id'   => 1,
		'activity_id' => 10,
		'user_id'     => 1,
		'file_name'   => 'support-test.txt',
		'file_path'   => 'doublescale-support/2026/06/support-test.txt',
		'file_type'   => 'text/plain',
		'file_size'   => 12,
		'file_hash'   => 'deep_support_hash_a',
		'status'      => 'active',
	)
);

$wpdb->insert(
	$contract,
	array(
		'contract_id' => 5,
		'user_id'     => 1,
		'file_name'   => 'contract-test.pdf',
		'file_path'   => 'doublescale-sales/contracts/2026/06/contract-test.pdf',
		'file_type'   => 'application/pdf',
		'file_size'   => 2048,
		'file_hash'   => 'deep_contract_hash_b',
	)
);

try {
	( new \DoubleScale\Core\Database\Migrations\MigrateLegacyAttachments() )->run();
	$migration_ok = true;
	$migration_err = '';
} catch ( \Throwable $e ) {
	$migration_ok  = false;
	$migration_err = $e->getMessage();
}

$pass( 'Legacy migration completes', $migration_ok, $migration_err );

$support_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new} WHERE attachable_type='support_ticket'" );
$contract_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new} WHERE attachable_type='sales_contract'" );
$pass( 'Support rows copied', 1 === $support_count, "got {$support_count}" );
$pass( 'Contract rows copied', 1 === $contract_count, "got {$contract_count}" );

$pass(
	'Legacy support table dropped',
	$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $support ) ) !== $support
);
$pass(
	'Legacy contract table dropped',
	$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $contract ) ) !== $contract
);
$pass( 'Unified flag set', (bool) get_option( 'doublescale_attachments_unified' ) );

// 4) Eloquent read via module models.
$support_row = \DoubleScale\Modules\Support\Models\AttachmentModel::query()
	->forType( \DoubleScale\Modules\Support\Models\AttachmentModel::ATTACHABLE_TYPE )
	->where( 'file_hash', 'deep_support_hash_a' )
	->first();
$pass( 'Support model reads migrated row', null !== $support_row );
if ( $support_row ) {
	$pass( 'Support ticket_id accessor', 1 === (int) $support_row->ticket_id );
}

$contract_row = \DoubleScale\Pro\Modules\Contracts\Models\ContractAttachmentModel::query()
	->forType( \DoubleScale\Pro\Modules\Contracts\Models\ContractAttachmentModel::ATTACHABLE_TYPE )
	->where( 'file_hash', 'deep_contract_hash_b' )
	->first();
$pass( 'Contract model reads migrated row', null !== $contract_row );
if ( $contract_row ) {
	$pass( 'Contract contract_id accessor', 5 === (int) $contract_row->contract_id );
}

// 5) Real file upload via core service.
$upload_dir = wp_upload_dir( null, false, false );
$tmpdir     = trailingslashit( (string) $upload_dir['basedir'] ) . 'doublescale-support/2026/06';
wp_mkdir_p( $tmpdir );
$tmpfile = tempnam( sys_get_temp_dir(), 'dsatt' );
file_put_contents( $tmpfile, 'hello attach' );

$_file = array(
	'name'     => 'live-upload.txt',
	'type'     => 'text/plain',
	'tmp_name' => $tmpfile,
	'error'    => 0,
	'size'     => 12,
);

// Simulate is_uploaded_file for CLI.
add_filter(
	'pre_move_uploaded_file',
	static function ( $null, $file, $new_file ) {
		unset( $null );
		return copy( $file, $new_file ) ? $new_file : false;
	},
	10,
	3
);

// Bypass is_uploaded_file — core checks it; use store_raw instead for CLI.
$stored = $core_service->store_raw(
	array(
		'filename' => 'live-upload.txt',
		'mime'     => 'text/plain',
		'content'  => 'hello attach',
	),
	'support_ticket',
	99,
	array( 'user_id' => 1 ),
	array(
		'activity_id'        => 50,
		'storage_subdir'     => 'doublescale-support/2026/06',
		'protected_base_dir' => 'doublescale-support',
	)
);

$pass( 'Core store_raw succeeds', ! is_wp_error( $stored ) );
if ( ! is_wp_error( $stored ) ) {
	$abs = \DoubleScale\Core\Models\AttachmentModel::resolve_absolute_path( (string) $stored->file_path );
	$pass( 'Stored file exists on disk', is_file( $abs ), $abs );
	$pass( 'Stored row is active support_ticket', 'support_ticket' === $stored->attachable_type && 'active' === $stored->status );

	$serve_sign = $core_service->generate_signature( (int) $stored->id );
	$pass( 'Stored attachment signature verifies', $core_service->verify_signature( (int) $stored->id, $serve_sign ) );

	$stored->delete();
}

@unlink( $tmpfile );

// 6) Idempotent re-run.
try {
	( new \DoubleScale\Core\Database\Migrations\MigrateLegacyAttachments() )->run();
	$pass( 'Migration idempotent re-run', true );
} catch ( \Throwable $e ) {
	$pass( 'Migration idempotent re-run', false, $e->getMessage() );
}

// Output report.
echo "=== DoubleScale Unified Attachments Deep Test ===\n";
foreach ( $results as $row ) {
	$status = $row['ok'] ? 'PASS' : 'FAIL';
	$line   = "[{$status}] {$row['label']}";
	if ( '' !== $row['detail'] ) {
		$line .= " — {$row['detail']}";
	}
	echo $line . "\n";
}
echo '---' . "\n";
echo ( 0 === $failed ? 'ALL PASSED' : "{$failed} FAILED" ) . ' (' . count( $results ) . " checks)\n";

exit( 0 === $failed ? 0 : 1 );
