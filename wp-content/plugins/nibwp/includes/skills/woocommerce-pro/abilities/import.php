<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-import.
 *
 * Bulk in and bulk out. A catalogue of any size arrives as a CSV, and a request
 * that tries to import it in one go times out somewhere in the middle, leaving a
 * half-applied catalogue nobody can tell apart from a finished one. So:
 *
 *   • validate_csv writes nothing and names every bad row. Always first.
 *   • import_csv takes offset + limit and returns next_offset, so a large file
 *     is walked in batches and each batch is a request that finishes.
 *   • a job row records how far it got, so a resumed import knows where it was.
 *
 * WooCommerce's own WC_Product_CSV_Importer does the parsing and the product
 * building whenever it is available, because it handles the column aliases,
 * the attribute columns and the variation parent lookups that a reimplementation
 * gets subtly wrong. It lives in wp-admin and is not autoloaded, so it is
 * included on demand; when it genuinely is not there, the fallback below builds
 * products through the WC_Product_* classes from a documented column set.
 *
 * Every path in this file is relative to the uploads directory — see
 * lib/files.php. An absolute path, a URL and a `../` escape are all refused.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';
require_once __DIR__ . '/../lib/files.php';

/**
 * Where the resumable job state lives.
 *
 * A function rather than a top-level const: a const declared twice in the global
 * scope is a fatal, and a file two distributions both ship is a file that can be
 * loaded by two paths.
 */
function nibwp_wcpro_jobs_option(): string
{
    return 'nibwp_wcpro_import_jobs';
}

/**
 * The action enum. Single source of truth — the registration and
 * tests/skills/woocommerce-pro-check.php both read this, and the switch in
 * nibwp_wcpro_import_execute() must match it exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_import_actions(): array
{
    return [
        'list_files',
        'validate_csv',
        'import_csv',
        'import_rows',
        'import_status',
        'export_products',
        'export_orders',
        'export_customers',
    ];
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-import',
    __('WooCommerce Pro — bulk import and export', 'nibwp'),
    'Import products from a CSV already in the uploads directory or from an array of rows you supply, validate a CSV first with a dry run that reports every bad row and writes nothing, resume a large import in batches with offset and limit, and export products, orders or customers to CSV in the uploads directory. Paths are always relative to the uploads directory — an absolute path or a URL is refused.',
    nibwp_wcpro_import_actions(),
    [
        'path' => ['type' => 'string', 'description' => 'CSV path RELATIVE to the uploads directory, e.g. "imports/products.csv". Absolute paths and URLs are refused.'],
        'rows' => [
            'type' => 'array',
            'description' => 'For import_rows: an array of objects keyed by column name — sku, name, type, regular_price, sale_price, stock_quantity, categories, tags, images, description, … Same column names as the CSV.',
            'items' => ['type' => 'object'],
        ],
        'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'Data rows already handled. Pass back the next_offset the previous call returned.'],
        'limit' => ['type' => 'integer', 'default' => 0, 'description' => 'Rows to handle in this call. 0 means all of them, which is only safe for a small file.'],
        'update_existing' => ['type' => 'boolean', 'default' => false, 'description' => 'Update a product whose id or SKU already exists instead of skipping the row.'],
        'delimiter' => ['type' => 'string', 'default' => ',', 'description' => 'CSV delimiter. Use ";" for a European export.'],
        'mapping' => ['type' => 'object', 'description' => 'Optional column mapping, {csv_heading: schema_heading}. Only used by the WooCommerce importer.'],
        'job_id' => ['type' => 'string', 'description' => 'Continue or inspect a batched import. import_csv returns one.'],
        'filter' => [
            'type' => 'object',
            'description' => 'For the exports: {status:"publish", category:"slug", type:"simple", customer_id:0, date_from:"2026-01-01", date_to:"2026-12-31", limit:1000, offset:0}.',
        ],
        'filename' => ['type' => 'string', 'description' => 'Basename for an export. Sanitised, and always written as .csv inside uploads/nibwp-wcpro/.'],
        'columns' => [
            'type' => 'array',
            'description' => 'Optional column subset for an export. Defaults to the full set for that record type.',
            'items' => ['type' => 'string'],
        ],
    ],
    'nibwp_wcpro_import_execute',
    implode("\n", [
        'Dry run first, always: action:"validate_csv", path:"imports/products.csv". It writes nothing and returns a problem per bad row, with the spreadsheet line number.',
        'Then commit: action:"import_csv" with the same path, a _preflight_token, and a limit of 200-500 for a file of any size. The response carries next_offset and job_id — call again with both until next_offset is null.',
        'A row whose SKU already exists is SKIPPED unless update_existing:true. That is usually what you want on a first import and never what you want on a re-run.',
        'action:"import_rows" takes the rows inline instead of a file, for a handful of products or for data you assembled yourself. Same column names.',
        'Paths are relative to the uploads directory. "/var/www/x.csv", "C:/x.csv", "https://…/x.csv" and "../../wp-config.php" are all refused. action:"list_files" shows what is there to import.',
        'Exports land in uploads/nibwp-wcpro/ and the response carries the URL. Customer and order exports only include records the connected account could open in wp-admin; anything else is counted as skipped rather than silently included.',
    ]),
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_import_execute(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');

    // A commit that can create hundreds of products is the one thing in this
    // skill worth a preflight token: it is the write nobody can undo with one
    // call, and the token proves the run went through the pipeline.
    $needs_token = in_array($action, ['import_csv', 'import_rows'], true);
    $guard = nibwp_wcpro_guard($input, $needs_token);
    if (is_wp_error($guard)) {
        return $guard;
    }

    switch ($action) {
        case 'list_files':
            return nibwp_wcpro_list_import_files();

        case 'validate_csv':
            return nibwp_wcpro_validate_csv($input);

        case 'import_csv':
            return nibwp_wcpro_import_csv($input, (string) $guard['token']);

        case 'import_rows':
            return nibwp_wcpro_import_rows($input, (string) $guard['token']);

        case 'import_status':
            return nibwp_wcpro_import_status($input);

        case 'export_products':
            return nibwp_wcpro_export_products($input);

        case 'export_orders':
            return nibwp_wcpro_export_orders($input);

        case 'export_customers':
            return nibwp_wcpro_export_customers($input);
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_import_actions());
}

/* ------------------------------------------------------------------- files */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_import_files(): array|WP_Error
{
    $denied = nibwp_wcpro_require_manage('Listing the files available to import');
    if ($denied !== null) {
        return $denied;
    }
    $base = nibwp_wcpro_uploads_basedir();
    if (is_wp_error($base)) {
        return $base;
    }

    $items = [];
    foreach (['', 'imports', 'nibwp-wcpro'] as $folder) {
        $dir = $folder === '' ? $base : $base . '/' . $folder;
        foreach (glob($dir . '/*.{csv,txt,CSV,TXT}', GLOB_BRACE) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $items[] = [
                'path'  => ltrim(substr(nibwp_wcpro_normalize_path($file), strlen($base)), '/'),
                'bytes' => (int) filesize($file),
                'modified' => gmdate('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }
    }

    return nibwp_wcpro_ok(
        'list_files',
        sprintf('%d CSV/TXT file(s) in the uploads directory.', count($items)),
        [
            'items' => $items,
            'count' => count($items),
            'result' => ['searched' => ['uploads/', 'uploads/imports/', 'uploads/nibwp-wcpro/']],
        ],
    );
}

/* --------------------------------------------------------------- validation */

/**
 * The columns this skill understands, and what each one does.
 *
 * Deliberately a documented subset rather than everything WooCommerce's own
 * importer accepts: a column nobody can explain is a column that silently does
 * nothing. Unknown columns in a file are reported as a warning, not an error.
 *
 * @return array<string, string>
 */
function nibwp_wcpro_known_columns(): array
{
    return [
        'id'                => 'Existing product id. With update_existing:true the row updates that product.',
        'sku'               => 'Stock keeping unit. The key an import matches on when there is no id.',
        'name'              => 'Product name. Required on a new product.',
        'type'              => 'simple | variable | grouped | external. Defaults to simple.',
        'status'            => 'publish | draft | pending | private. Defaults to publish.',
        'featured'          => '1/0, yes/no.',
        'catalog_visibility' => 'visible | catalog | search | hidden.',
        'description'       => 'Long description. HTML allowed.',
        'short_description' => 'Short description. HTML allowed.',
        'regular_price'     => 'The normal price.',
        'sale_price'        => 'The discounted price. Must be below regular_price.',
        'tax_status'        => 'taxable | shipping | none.',
        'tax_class'         => 'A tax class slug, or empty for the standard rate.',
        'manage_stock'      => '1/0, yes/no.',
        'stock_quantity'    => 'A whole number. Only used when manage_stock is on.',
        'stock_status'      => 'instock | outofstock | onbackorder.',
        'backorders'        => 'no | notify | yes.',
        'weight'            => 'A non-negative number.',
        'length'            => 'A non-negative number.',
        'width'             => 'A non-negative number.',
        'height'            => 'A non-negative number.',
        'categories'        => 'Category names or slugs, comma separated. Created if missing.',
        'tags'              => 'Tag names, comma separated. Created if missing.',
        'images'            => 'Image URLs, comma separated. The first becomes the featured image.',
        'virtual'           => '1/0, yes/no.',
        'downloadable'      => '1/0, yes/no.',
        'menu_order'        => 'A whole number, for manual ordering.',
    ];
}

/**
 * Check a CSV row by row and report every problem, without writing anything.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_validate_csv(array $input): array|WP_Error
{
    // Validation reads a file and reports on the catalogue. It is gated on the
    // same authority as the import it precedes, so a dry run cannot be used to
    // probe for existing SKUs by an account that could not import.
    $denied = nibwp_wcpro_require_create_product();
    if ($denied !== null) {
        return $denied;
    }

    $path = nibwp_wcpro_uploads_path($input['path'] ?? '', true);
    if (is_wp_error($path)) {
        return $path;
    }

    $offset = max(0, (int) ($input['offset'] ?? 0));
    $limit = max(0, (int) ($input['limit'] ?? 0));
    $delimiter = nibwp_wcpro_csv_delimiter($input);
    $read = nibwp_wcpro_read_csv($path, $offset, $limit, $delimiter);
    if (is_wp_error($read)) {
        return $read;
    }

    $known = nibwp_wcpro_known_columns();
    $unknown_columns = array_values(array_diff($read['header'], array_keys($known)));
    $update_existing = nibwp_wcpro_bool($input['update_existing'] ?? false);

    $problems = [];
    $seen_skus = [];
    $ok_rows = 0;

    foreach ($read['rows'] as $row) {
        $line = (int) ($row['__line'] ?? 0);
        $row_problems = nibwp_wcpro_row_problems($row, $update_existing);

        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku !== '') {
            $key = strtolower($sku);
            if (isset($seen_skus[$key])) {
                $row_problems[] = [
                    'severity' => 'error',
                    'field'    => 'sku',
                    'message'  => sprintf('SKU "%s" also appears on line %d. Two rows cannot own one SKU.', $sku, $seen_skus[$key]),
                ];
            } else {
                $seen_skus[$key] = $line;
            }
        }

        if ($row_problems === []) {
            $ok_rows++;
            continue;
        }
        $problems[] = [
            'line'     => $line,
            'sku'      => $sku,
            'name'     => trim((string) ($row['name'] ?? '')),
            'problems' => $row_problems,
        ];
    }

    $errors = 0;
    foreach ($problems as $entry) {
        foreach ($entry['problems'] as $problem) {
            if (($problem['severity'] ?? '') === 'error') {
                $errors++;
            }
        }
    }

    return nibwp_wcpro_ok(
        'validate_csv',
        $errors === 0
            ? sprintf('%d row(s) checked, no errors. %d row(s) carry warnings.', count($read['rows']), count($problems))
            : sprintf('%d row(s) checked, %d error(s) across %d row(s). Nothing was written.', count($read['rows']), $errors, count($problems)),
        [
            'dry_run'  => true,
            'problems' => $problems,
            'count'    => count($read['rows']),
            'result'   => [
                'path'            => (string) ($input['path'] ?? ''),
                'columns'         => $read['header'],
                'unknown_columns' => $unknown_columns,
                'total_rows'      => $read['total'],
                'rows_checked'    => count($read['rows']),
                'rows_clean'      => $ok_rows,
                'error_count'     => $errors,
                'next_offset'     => $read['next_offset'],
                'importer'        => nibwp_wcpro_native_importer_available() ? 'WC_Product_CSV_Importer' : 'fallback (WC_Product_* classes)',
            ],
            'next_steps' => array_values(array_filter([
                $unknown_columns === [] ? '' : sprintf('These columns are not understood and will be ignored: %s.', implode(', ', $unknown_columns)),
                $errors > 0
                    ? 'Fix the rows listed in problems[] and validate again. Do not commit an import with errors — the bad rows fail individually and the catalogue ends up half-applied.'
                    : 'Clean. Commit with action:"import_csv", the same path, a _preflight_token, and limit:200 for a file of any size.',
                $read['next_offset'] === null ? '' : sprintf('Only the first %d row(s) were checked. Call again with offset:%d to continue.', count($read['rows']), (int) $read['next_offset']),
            ])),
        ],
    );
}

/**
 * Everything wrong with one row.
 *
 * @param array<string, string> $row
 * @return array<int, array<string, string>>
 */
function nibwp_wcpro_row_problems(array $row, bool $update_existing): array
{
    $problems = [];
    $value = static fn (string $key): string => trim((string) ($row[$key] ?? ''));

    $id = (int) $value('id');
    $sku = $value('sku');
    $name = $value('name');

    if ($id <= 0 && $sku === '' && $name === '') {
        $problems[] = ['severity' => 'error', 'field' => 'sku', 'message' => 'The row has no id, no sku and no name, so there is no way to say which product it is.'];
    }
    if ($id > 0 && get_post_type($id) !== 'product') {
        $problems[] = ['severity' => 'error', 'field' => 'id', 'message' => sprintf('There is no product %d on this site.', $id)];
    }
    if ($id <= 0 && $name === '') {
        $problems[] = ['severity' => 'error', 'field' => 'name', 'message' => 'A new product needs a name.'];
    }

    if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) {
        $existing = (int) wc_get_product_id_by_sku($sku);
        if ($existing > 0 && !$update_existing) {
            $problems[] = [
                'severity' => 'warning',
                'field'    => 'sku',
                'message'  => sprintf('SKU "%s" already belongs to product %d, so this row will be skipped. Pass update_existing:true to update it instead.', $sku, $existing),
            ];
        }
        if ($existing > 0 && $update_existing && !current_user_can('edit_post', $existing)) {
            $problems[] = [
                'severity' => 'error',
                'field'    => 'sku',
                'message'  => sprintf('SKU "%s" belongs to product %d, which this account may not edit.', $sku, $existing),
            ];
        }
    }

    $type = strtolower($value('type'));
    if ($type !== '' && !in_array($type, ['simple', 'variable', 'grouped', 'external', 'variation'], true)) {
        $problems[] = ['severity' => 'error', 'field' => 'type', 'message' => sprintf('"%s" is not a product type. Use simple, variable, grouped or external.', $type)];
    }

    $status = strtolower($value('status'));
    if ($status !== '' && !in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
        $problems[] = ['severity' => 'error', 'field' => 'status', 'message' => sprintf('"%s" is not a post status. Use publish, draft, pending or private.', $status)];
    }

    foreach (['regular_price', 'sale_price'] as $field) {
        $raw = $value($field);
        if ($raw !== '' && is_wp_error(nibwp_wcpro_price($raw, $field))) {
            $problems[] = ['severity' => 'error', 'field' => $field, 'message' => sprintf('%s must be a number, got "%s".', $field, $raw)];
        }
    }
    $regular = $value('regular_price');
    $sale = $value('sale_price');
    if ($sale !== '' && $regular === '') {
        $problems[] = ['severity' => 'error', 'field' => 'sale_price', 'message' => 'A sale price with no regular price is never shown as a discount, and the product has no price at all.'];
    }
    if ($sale !== '' && $regular !== '' && is_numeric($sale) && is_numeric($regular) && (float) $sale >= (float) $regular) {
        $problems[] = ['severity' => 'error', 'field' => 'sale_price', 'message' => sprintf('sale_price (%s) is not below regular_price (%s), so WooCommerce ignores it.', $sale, $regular)];
    }

    foreach (['weight', 'length', 'width', 'height'] as $field) {
        $raw = $value($field);
        if ($raw !== '' && is_wp_error(nibwp_wcpro_measure($raw, $field))) {
            $problems[] = ['severity' => 'error', 'field' => $field, 'message' => sprintf('%s must be a non-negative number, got "%s".', $field, $raw)];
        }
    }

    $stock = $value('stock_quantity');
    if ($stock !== '' && !preg_match('/^-?\d+(\.\d+)?$/', $stock)) {
        $problems[] = ['severity' => 'error', 'field' => 'stock_quantity', 'message' => sprintf('stock_quantity must be a number, got "%s".', $stock)];
    }
    if ($stock !== '' && !nibwp_wcpro_bool($value('manage_stock'))) {
        $problems[] = ['severity' => 'warning', 'field' => 'stock_quantity', 'message' => 'A stock quantity with manage_stock off is stored and then ignored. Set manage_stock to 1.'];
    }

    $stock_status = strtolower($value('stock_status'));
    if ($stock_status !== '' && !in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
        $problems[] = ['severity' => 'error', 'field' => 'stock_status', 'message' => sprintf('"%s" is not a stock status. Use instock, outofstock or onbackorder.', $stock_status)];
    }

    $tax_status = strtolower($value('tax_status'));
    if ($tax_status !== '' && !in_array($tax_status, ['taxable', 'shipping', 'none'], true)) {
        $problems[] = ['severity' => 'error', 'field' => 'tax_status', 'message' => sprintf('"%s" is not a tax status. Use taxable, shipping or none.', $tax_status)];
    }
    $tax_class = $value('tax_class');
    if ($tax_class !== '' && strtolower($tax_class) !== 'standard' && class_exists('WC_Tax') && method_exists('WC_Tax', 'get_tax_class_slugs')) {
        if (!in_array(sanitize_title($tax_class), array_map('strval', (array) WC_Tax::get_tax_class_slugs()), true)) {
            $problems[] = [
                'severity' => 'error',
                'field'    => 'tax_class',
                'message'  => sprintf('There is no tax class "%s". Create it first with nibwp/wcpro-store action:"create_tax_class".', $tax_class),
            ];
        }
    }

    foreach (nibwp_wcpro_split_list($value('images')) as $image) {
        if (is_wp_error(nibwp_wcpro_url($image, 'images'))) {
            $problems[] = ['severity' => 'error', 'field' => 'images', 'message' => sprintf('"%s" is not an absolute http(s) image URL.', $image)];
        }
    }

    return $problems;
}

/**
 * Split a comma-separated cell.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_split_list(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
}

/** The delimiter, validated: a multi-character one silently reads the whole line as one cell. */
function nibwp_wcpro_csv_delimiter(array $input): string
{
    $raw = (string) ($input['delimiter'] ?? ',');

    return strlen($raw) === 1 ? $raw : ',';
}

/* ------------------------------------------------------------------ import */

/** Is WooCommerce's own importer usable here? */
function nibwp_wcpro_native_importer_available(): bool
{
    if (class_exists('WC_Product_CSV_Importer')) {
        return true;
    }
    if (!defined('WC_ABSPATH')) {
        return false;
    }
    foreach ([
        'includes/import/abstract-wc-product-importer.php',
        'includes/import/class-wc-product-csv-importer.php',
        'includes/admin/importers/class-wc-product-csv-importer-controller.php',
    ] as $relative) {
        $path = WC_ABSPATH . $relative;
        if (is_readable($path)) {
            require_once $path;
        }
    }

    return class_exists('WC_Product_CSV_Importer');
}

/**
 * The byte position in the file at which data row $rows + 1 begins.
 *
 * WooCommerce's importer resumes from a byte offset; this ability's callers think
 * in rows, because that is what a spreadsheet shows them. This converts.
 */
function nibwp_wcpro_csv_byte_offset(string $path, int $rows, string $delimiter): int
{
    if ($rows <= 0) {
        return 0;
    }
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }
    fgetcsv($handle, 0, $delimiter); // header
    $seen = 0;
    while ($seen < $rows && ($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($cells === [null]) {
            continue;
        }
        $seen++;
    }
    $position = (int) ftell($handle);
    fclose($handle);

    return $position;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_import_csv(array $input, string $token): array|WP_Error
{
    $denied = nibwp_wcpro_require_create_product();
    if ($denied !== null) {
        return $denied;
    }

    $relative = (string) ($input['path'] ?? '');
    $path = nibwp_wcpro_uploads_path($relative, true);
    if (is_wp_error($path)) {
        return $path;
    }

    $delimiter = nibwp_wcpro_csv_delimiter($input);
    $update_existing = nibwp_wcpro_bool($input['update_existing'] ?? false);
    $limit = max(0, (int) ($input['limit'] ?? 0));
    $offset = max(0, (int) ($input['offset'] ?? 0));

    $job_id = nibwp_wcpro_text($input['job_id'] ?? '', 40);
    $job = $job_id !== '' ? nibwp_wcpro_job_get($job_id) : null;
    if ($job !== null && (string) $job['path'] !== $relative) {
        return nibwp_wcpro_err('job_mismatch', sprintf('Job %s is importing "%s", not "%s".', $job_id, (string) $job['path'], $relative), 409);
    }
    if ($job !== null && $offset === 0) {
        $offset = (int) $job['rows_done'];
    }

    $total = nibwp_wcpro_read_csv($path, 0, 1, $delimiter);
    if (is_wp_error($total)) {
        return $total;
    }

    if ($job === null) {
        $job_id = nibwp_wcpro_job_start($relative, (int) $total['total']);
        $job = nibwp_wcpro_job_get($job_id);
    }

    $outcome = nibwp_wcpro_native_importer_available()
        ? nibwp_wcpro_import_with_woocommerce($path, $offset, $limit, $delimiter, $update_existing, (array) ($input['mapping'] ?? []))
        : nibwp_wcpro_import_with_fallback($path, $offset, $limit, $delimiter, $update_existing);

    if (is_wp_error($outcome)) {
        return $outcome;
    }

    $rows_done = $offset + (int) $outcome['rows_read'];
    $next_offset = $rows_done < (int) $total['total'] ? $rows_done : null;
    nibwp_wcpro_job_update((string) $job_id, [
        'rows_done' => $rows_done,
        'total'     => (int) $total['total'],
        'imported'  => (int) ($job['imported'] ?? 0) + count($outcome['imported']),
        'updated'   => (int) ($job['updated'] ?? 0) + count($outcome['updated']),
        'skipped'   => (int) ($job['skipped'] ?? 0) + count($outcome['skipped']),
        'failed'    => (int) ($job['failed'] ?? 0) + count($outcome['failed']),
        'finished'  => $next_offset === null,
    ]);

    // The token is one-shot per commit, and a batched import is many commits, so
    // it is only released once the file is finished.
    if ($next_offset === null) {
        nibwp_wcpro_clear_token($token);
    }

    $final = nibwp_wcpro_job_get((string) $job_id);

    return nibwp_wcpro_ok(
        'import_csv',
        sprintf(
            '%d imported, %d updated, %d skipped, %d failed in this batch. %s',
            count($outcome['imported']),
            count($outcome['updated']),
            count($outcome['skipped']),
            count($outcome['failed']),
            $next_offset === null ? 'The file is finished.' : sprintf('Rows %d of %d done.', $rows_done, (int) $total['total']),
        ),
        [
            'result' => [
                'job_id'      => (string) $job_id,
                'path'        => $relative,
                'importer'    => $outcome['importer'],
                'rows_read'   => (int) $outcome['rows_read'],
                'next_offset' => $next_offset,
                'total_rows'  => (int) $total['total'],
                'progress'    => $final === null ? [] : $final,
                'imported'    => $outcome['imported'],
                'updated'     => $outcome['updated'],
                'skipped'     => $outcome['skipped'],
                'failed'      => $outcome['failed'],
            ],
            'count' => count($outcome['imported']) + count($outcome['updated']),
            'next_steps' => array_values(array_filter([
                $next_offset === null
                    ? 'Done. Check a handful of products in the catalogue before telling the user it worked.'
                    : sprintf('Call again with job_id:"%s" and offset:%d — or just job_id, which remembers where it was.', (string) $job_id, (int) $next_offset),
                $outcome['failed'] === [] ? '' : 'Some rows failed. Read failed[] — each entry names the row and the reason.',
            ])),
        ],
    );
}

/**
 * Import a slice of the file through WooCommerce's own importer.
 *
 * @return array{importer: string, rows_read: int, imported: array, updated: array, skipped: array, failed: array}|WP_Error
 */
function nibwp_wcpro_import_with_woocommerce(
    string $path,
    int $offset,
    int $limit,
    string $delimiter,
    bool $update_existing,
    array $mapping,
): array|WP_Error {
    // WC_Product_CSV_Importer::read_file() calls wp_die() on a file it does not
    // accept, which would take the whole request down with an HTML page. Ask the
    // same question first and answer with a WP_Error instead.
    if (function_exists('wc_is_file_valid_csv') && !wc_is_file_valid_csv($path, false)) {
        return nibwp_wcpro_err('invalid_csv', 'WooCommerce does not accept this file as a CSV. Its importer handles .csv and .txt only.', 422);
    }

    $params = [
        'start_pos'       => nibwp_wcpro_csv_byte_offset($path, $offset, $delimiter),
        'lines'           => $limit > 0 ? $limit : -1,
        'parse'           => true,
        'update_existing' => $update_existing,
        'delimiter'       => $delimiter,
    ];
    if ($mapping !== []) {
        $params['mapping'] = $mapping;
    }

    $importer = new WC_Product_CSV_Importer($path, $params);
    $data = (array) $importer->import();

    $rows_read = count((array) $importer->get_parsed_data());

    return [
        'importer'  => 'WC_Product_CSV_Importer',
        'rows_read' => $rows_read,
        'imported'  => array_map('intval', (array) ($data['imported'] ?? [])),
        'updated'   => array_map('intval', (array) ($data['updated'] ?? [])),
        'skipped'   => nibwp_wcpro_flatten_errors((array) ($data['skipped'] ?? [])),
        'failed'    => nibwp_wcpro_flatten_errors((array) ($data['failed'] ?? [])),
    ];
}

/**
 * WP_Error objects the importer returns, in a shape a client can read.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_wcpro_flatten_errors(array $errors): array
{
    $out = [];
    foreach ($errors as $error) {
        if ($error instanceof WP_Error) {
            $data = (array) $error->get_error_data();
            $out[] = [
                'row'     => (int) ($data['row'] ?? 0),
                'sku'     => (string) ($data['sku'] ?? ''),
                'id'      => (int) ($data['id'] ?? 0),
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
            ];
            continue;
        }
        $out[] = ['message' => is_scalar($error) ? (string) $error : (string) wp_json_encode($error)];
    }

    return $out;
}

/**
 * The documented fallback: build products through the WC_Product_* classes.
 *
 * Used only when WC_Product_CSV_Importer is genuinely absent. It handles the
 * column set nibwp_wcpro_known_columns() documents and nothing else — notably
 * not variations, whose parent lookup and attribute columns are the part of
 * WooCommerce's importer worth deferring to. A variation row is reported as
 * skipped with that reason rather than half-built.
 *
 * @return array{importer: string, rows_read: int, imported: array, updated: array, skipped: array, failed: array}|WP_Error
 */
function nibwp_wcpro_import_with_fallback(
    string $path,
    int $offset,
    int $limit,
    string $delimiter,
    bool $update_existing,
): array|WP_Error {
    $read = nibwp_wcpro_read_csv($path, $offset, $limit, $delimiter);
    if (is_wp_error($read)) {
        return $read;
    }

    $outcome = nibwp_wcpro_build_rows($read['rows'], $update_existing);
    $outcome['importer'] = 'fallback (WC_Product_* classes)';
    $outcome['rows_read'] = count($read['rows']);

    return $outcome;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_import_rows(array $input, string $token): array|WP_Error
{
    $denied = nibwp_wcpro_require_create_product();
    if ($denied !== null) {
        return $denied;
    }

    $rows = (array) ($input['rows'] ?? []);
    if ($rows === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "rows" array of objects keyed by column name. action:"validate_csv" documents the columns.', 422);
    }
    if (count($rows) > 500) {
        return nibwp_wcpro_err('too_many_rows', sprintf('%d rows in one request is too many. Send 200 at a time, or put them in a CSV and use action:"import_csv".', count($rows)), 422);
    }

    $offset = max(0, (int) ($input['offset'] ?? 0));
    $limit = max(0, (int) ($input['limit'] ?? 0));
    $slice = $limit > 0 ? array_slice($rows, $offset, $limit) : array_slice($rows, $offset);

    $normalized = [];
    $line = $offset + 2; // As if the array were a spreadsheet with a header.
    foreach ($slice as $row) {
        $row = (array) $row;
        $row['__line'] = (string) $line;
        $normalized[] = array_map(static fn ($cell) => is_scalar($cell) || $cell === null ? (string) $cell : (string) wp_json_encode($cell), $row);
        $line++;
    }

    $update_existing = nibwp_wcpro_bool($input['update_existing'] ?? false);
    $outcome = nibwp_wcpro_build_rows($normalized, $update_existing);

    $rows_done = $offset + count($normalized);
    $next_offset = $rows_done < count($rows) ? $rows_done : null;
    if ($next_offset === null) {
        nibwp_wcpro_clear_token($token);
    }

    return nibwp_wcpro_ok(
        'import_rows',
        sprintf(
            '%d imported, %d updated, %d skipped, %d failed out of %d row(s).',
            count($outcome['imported']),
            count($outcome['updated']),
            count($outcome['skipped']),
            count($outcome['failed']),
            count($normalized),
        ),
        [
            'result' => [
                'rows_read'   => count($normalized),
                'next_offset' => $next_offset,
                'imported'    => $outcome['imported'],
                'updated'     => $outcome['updated'],
                'skipped'     => $outcome['skipped'],
                'failed'      => $outcome['failed'],
            ],
            'count' => count($outcome['imported']) + count($outcome['updated']),
            'next_steps' => $next_offset === null ? [] : [sprintf('Call again with offset:%d for the rest.', (int) $next_offset)],
        ],
    );
}

/**
 * Turn validated rows into products.
 *
 * Each row is validated first and refused individually: one bad row must not
 * stop the other 199, and it must not be half-written either.
 *
 * @param array<int, array<string, string>> $rows
 * @return array{imported: array, updated: array, skipped: array, failed: array}
 */
function nibwp_wcpro_build_rows(array $rows, bool $update_existing): array
{
    $imported = [];
    $updated = [];
    $skipped = [];
    $failed = [];

    foreach ($rows as $row) {
        $line = (int) ($row['__line'] ?? 0);
        $problems = nibwp_wcpro_row_problems($row, $update_existing);
        $errors = array_values(array_filter($problems, static fn (array $problem): bool => ($problem['severity'] ?? '') === 'error'));
        if ($errors !== []) {
            $failed[] = ['row' => $line, 'sku' => trim((string) ($row['sku'] ?? '')), 'code' => 'row_invalid', 'message' => (string) ($errors[0]['message'] ?? 'Invalid row.'), 'problems' => $errors];
            continue;
        }

        $id = (int) ($row['id'] ?? 0);
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($id <= 0 && $sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $id = (int) wc_get_product_id_by_sku($sku);
        }

        if ($id > 0 && !$update_existing) {
            $skipped[] = ['row' => $line, 'sku' => $sku, 'id' => $id, 'code' => 'already_exists', 'message' => 'A product with this id or SKU already exists. Pass update_existing:true to update it.'];
            continue;
        }
        if ($id > 0 && !current_user_can('edit_post', $id)) {
            $skipped[] = ['row' => $line, 'sku' => $sku, 'id' => $id, 'code' => 'nibwp_forbidden', 'message' => 'This account may not edit that product.'];
            continue;
        }

        $product = nibwp_wcpro_row_to_product($row, $id);
        if (is_wp_error($product)) {
            $failed[] = ['row' => $line, 'sku' => $sku, 'code' => $product->get_error_code(), 'message' => $product->get_error_message()];
            continue;
        }

        if ($id > 0) {
            $updated[] = $product->get_id();
        } else {
            $imported[] = $product->get_id();
        }
    }

    return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
}

/**
 * One row to one product.
 *
 * @param array<string, string> $row
 * @return WC_Product|WP_Error
 */
function nibwp_wcpro_row_to_product(array $row, int $existing_id): WC_Product|WP_Error
{
    $value = static fn (string $key): string => trim((string) ($row[$key] ?? ''));
    $has = static fn (string $key): bool => array_key_exists($key, $row) && trim((string) $row[$key]) !== '';

    $type = strtolower($value('type')) ?: 'simple';
    if ($type === 'variation') {
        return nibwp_wcpro_err('variation_unsupported', 'The fallback importer does not build variations — their parent lookup belongs to WooCommerce\'s own importer. Create the parent, then use nibwp/wcpro-catalog generate_variations.', 422);
    }

    if ($existing_id > 0) {
        $product = wc_get_product($existing_id);
        if (!$product instanceof WC_Product) {
            return nibwp_wcpro_err('product_not_found', sprintf('Product %d disappeared between validation and writing.', $existing_id), 404);
        }
    } else {
        $class = match ($type) {
            'variable' => 'WC_Product_Variable',
            'grouped'  => 'WC_Product_Grouped',
            'external' => 'WC_Product_External',
            default    => 'WC_Product_Simple',
        };
        if (!class_exists($class)) {
            return nibwp_wcpro_err('wc_class_missing', sprintf('The WooCommerce class %s is not available on this site.', $class), 409);
        }
        $product = new $class();
        if (!$product instanceof WC_Product) {
            return nibwp_wcpro_err('wc_class_missing', sprintf('%s is not a WC_Product.', $class), 409);
        }
    }

    if ($has('name')) {
        $product->set_name(nibwp_wcpro_text($value('name'), 200));
    }
    if ($has('sku')) {
        $product->set_sku(nibwp_wcpro_text($value('sku'), 100));
    }
    if ($has('status')) {
        $product->set_status(strtolower($value('status')));
    }
    if ($has('description')) {
        $product->set_description(wp_kses_post((string) $row['description']));
    }
    if ($has('short_description')) {
        $product->set_short_description(wp_kses_post((string) $row['short_description']));
    }
    if (array_key_exists('featured', $row)) {
        $product->set_featured(nibwp_wcpro_bool($row['featured']));
    }
    if ($has('catalog_visibility')) {
        $visibility = nibwp_wcpro_enum($value('catalog_visibility'), ['visible', 'catalog', 'search', 'hidden'], 'catalog_visibility');
        if (is_wp_error($visibility)) {
            return $visibility;
        }
        $product->set_catalog_visibility($visibility);
    }

    foreach (['regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price'] as $field => $setter) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $price = nibwp_wcpro_price($row[$field], $field);
        if (is_wp_error($price)) {
            return $price;
        }
        if (method_exists($product, $setter)) {
            $product->$setter($price);
        }
    }

    if ($has('tax_status')) {
        $product->set_tax_status(strtolower($value('tax_status')));
    }
    if (array_key_exists('tax_class', $row)) {
        $class = $value('tax_class');
        $product->set_tax_class(strtolower($class) === 'standard' ? '' : sanitize_title($class));
    }

    if (array_key_exists('manage_stock', $row)) {
        $product->set_manage_stock(nibwp_wcpro_bool($row['manage_stock']));
    }
    if ($has('stock_quantity')) {
        $product->set_stock_quantity((float) $value('stock_quantity'));
    }
    if ($has('stock_status')) {
        $product->set_stock_status(strtolower($value('stock_status')));
    }
    if ($has('backorders')) {
        $backorders = nibwp_wcpro_enum(strtolower($value('backorders')), ['no', 'notify', 'yes'], 'backorders');
        if (is_wp_error($backorders)) {
            return $backorders;
        }
        $product->set_backorders($backorders);
    }

    foreach (['weight' => 'set_weight', 'length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height'] as $field => $setter) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $measure = nibwp_wcpro_measure($row[$field], $field);
        if (is_wp_error($measure)) {
            return $measure;
        }
        $product->$setter($measure);
    }

    if (array_key_exists('virtual', $row)) {
        $product->set_virtual(nibwp_wcpro_bool($row['virtual']));
    }
    if (array_key_exists('downloadable', $row)) {
        $product->set_downloadable(nibwp_wcpro_bool($row['downloadable']));
    }
    if ($has('menu_order')) {
        $product->set_menu_order((int) $value('menu_order'));
    }

    foreach (['categories' => ['product_cat', 'set_category_ids'], 'tags' => ['product_tag', 'set_tag_ids']] as $field => [$taxonomy, $setter]) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $ids = nibwp_wcpro_resolve_row_terms(nibwp_wcpro_split_list($value($field)), $taxonomy);
        if (is_wp_error($ids)) {
            return $ids;
        }
        $product->$setter($ids);
    }

    $product_id = (int) $product->save();
    if ($product_id <= 0) {
        return nibwp_wcpro_err('save_failed', 'WooCommerce did not return a product id, so nothing was stored for this row.', 500);
    }

    // Images after the save, because sideloading attaches them to a post that has
    // to exist first.
    $images = nibwp_wcpro_split_list($value('images'));
    if ($images !== [] && current_user_can('upload_files')) {
        nibwp_wcpro_attach_row_images($product, $images);
        $product->save();
    }

    return $product;
}

/**
 * Resolve category/tag names to term ids, creating what is missing.
 *
 * @param array<int, string> $names
 * @return array<int, int>|WP_Error
 */
function nibwp_wcpro_resolve_row_terms(array $names, string $taxonomy): array|WP_Error
{
    if ($names === [] || !taxonomy_exists($taxonomy)) {
        return [];
    }

    $ids = [];
    foreach ($names as $name) {
        $term = get_term_by('name', $name, $taxonomy);
        if (!$term instanceof WP_Term) {
            $term = get_term_by('slug', sanitize_title($name), $taxonomy);
        }
        if ($term instanceof WP_Term) {
            $ids[] = (int) $term->term_id;
            continue;
        }
        $denied = nibwp_wcpro_require_term_cap('edit_terms', $taxonomy, 0, sprintf('a new %s', $taxonomy));
        if ($denied !== null) {
            return $denied;
        }
        $created = wp_insert_term($name, $taxonomy);
        if (is_wp_error($created)) {
            return $created;
        }
        $ids[] = (int) ($created['term_id'] ?? 0);
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Sideload a row's images. Failures are tolerated: a missing picture is not a
 * reason to refuse the product it belongs to.
 *
 * @param array<int, string> $urls
 */
function nibwp_wcpro_attach_row_images(WC_Product $product, array $urls): void
{
    if (!function_exists('media_sideload_image')) {
        foreach (['/wp-admin/includes/media.php', '/wp-admin/includes/file.php', '/wp-admin/includes/image.php'] as $include) {
            $path = ABSPATH . ltrim($include, '/');
            if (is_readable($path)) {
                require_once $path;
            }
        }
    }
    if (!function_exists('media_sideload_image')) {
        return;
    }

    $gallery = [];
    foreach ($urls as $index => $url) {
        if (is_wp_error(nibwp_wcpro_url($url, 'images'))) {
            continue;
        }
        $attachment_id = media_sideload_image($url, $product->get_id(), '', 'id');
        if (is_wp_error($attachment_id)) {
            continue;
        }
        if ($index === 0) {
            $product->set_image_id((int) $attachment_id);
            continue;
        }
        $gallery[] = (int) $attachment_id;
    }
    if ($gallery !== []) {
        $product->set_gallery_image_ids($gallery);
    }
}

/* -------------------------------------------------------------------- jobs */

/**
 * @return array<string, array<string, mixed>>
 */
function nibwp_wcpro_jobs_all(): array
{
    $jobs = get_option(nibwp_wcpro_jobs_option(), []);

    return is_array($jobs) ? $jobs : [];
}

/** @return array<string, mixed>|null */
function nibwp_wcpro_job_get(string $job_id): ?array
{
    $jobs = nibwp_wcpro_jobs_all();
    $job = $jobs[$job_id] ?? null;

    return is_array($job) ? $job : null;
}

function nibwp_wcpro_job_start(string $path, int $total): string
{
    $job_id = 'wcpro_' . substr(md5($path . microtime(true) . (string) get_current_user_id()), 0, 16);
    $jobs = nibwp_wcpro_jobs_all();

    // Keep the last twenty. A job row is a progress note, not an audit log, and
    // an unbounded option row is how a site ends up with a megabyte of autoloaded
    // JSON nobody reads.
    if (count($jobs) >= 20) {
        $jobs = array_slice($jobs, -19, null, true);
    }

    $jobs[$job_id] = [
        'job_id'    => $job_id,
        'path'      => $path,
        'user_id'   => get_current_user_id(),
        'started'   => gmdate('Y-m-d H:i:s'),
        'total'     => $total,
        'rows_done' => 0,
        'imported'  => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'failed'    => 0,
        'finished'  => false,
    ];
    update_option(nibwp_wcpro_jobs_option(), $jobs, false);

    return $job_id;
}

function nibwp_wcpro_job_update(string $job_id, array $fields): void
{
    $jobs = nibwp_wcpro_jobs_all();
    if (!isset($jobs[$job_id]) || !is_array($jobs[$job_id])) {
        return;
    }
    $jobs[$job_id] = array_merge($jobs[$job_id], $fields, ['updated_at' => gmdate('Y-m-d H:i:s')]);
    update_option(nibwp_wcpro_jobs_option(), $jobs, false);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_import_status(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_manage('Reading import progress');
    if ($denied !== null) {
        return $denied;
    }

    $job_id = nibwp_wcpro_text($input['job_id'] ?? '', 40);
    if ($job_id !== '') {
        $job = nibwp_wcpro_job_get($job_id);
        if ($job === null) {
            return nibwp_wcpro_err('not_found', sprintf('No import job "%s". Jobs are kept for the last twenty imports.', $job_id), 404);
        }

        return nibwp_wcpro_ok(
            'import_status',
            sprintf('Job %s: %d of %d row(s) done%s.', $job_id, (int) $job['rows_done'], (int) $job['total'], !empty($job['finished']) ? ', finished' : ''),
            [
                'result' => $job,
                'next_steps' => empty($job['finished']) && (int) $job['rows_done'] < (int) $job['total']
                    ? [sprintf('Continue with action:"import_csv", job_id:"%s".', $job_id)]
                    : [],
            ],
        );
    }

    $jobs = array_values(nibwp_wcpro_jobs_all());

    return nibwp_wcpro_ok('import_status', sprintf('%d import job(s) on record.', count($jobs)), [
        'items' => $jobs,
        'count' => count($jobs),
    ]);
}

/* ------------------------------------------------------------------ exports */

/** @return array<int, string> */
function nibwp_wcpro_product_export_columns(): array
{
    return [
        'id', 'sku', 'name', 'type', 'status', 'featured', 'catalog_visibility',
        'regular_price', 'sale_price', 'price', 'tax_status', 'tax_class',
        'manage_stock', 'stock_quantity', 'stock_status', 'backorders',
        'weight', 'length', 'width', 'height',
        'categories', 'tags', 'images', 'short_description', 'description',
        'total_sales', 'permalink',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_export_products(array $input): array|WP_Error
{
    // The capability that opens Products in wp-admin. Each product is then asked
    // about individually below.
    $type = get_post_type_object('product');
    $cap = is_object($type) && isset($type->cap->edit_posts) ? (string) $type->cap->edit_posts : 'edit_products';
    $denied = nibwp_wcpro_require_cap($cap, 'Exporting products');
    if ($denied !== null) {
        return $denied;
    }

    $filter = (array) ($input['filter'] ?? []);
    $limit = (int) ($filter['limit'] ?? 1000);
    $ids = get_posts([
        'post_type'      => 'product',
        'post_status'    => nibwp_wcpro_text($filter['status'] ?? 'any', 20) ?: 'any',
        'posts_per_page' => $limit > 0 ? min($limit, 5000) : 1000,
        'offset'         => max(0, (int) ($filter['offset'] ?? 0)),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $columns = nibwp_wcpro_export_columns($input, nibwp_wcpro_product_export_columns());
    $rows = [];
    $skipped = 0;
    foreach ((array) $ids as $id) {
        $id = (int) $id;
        if (!current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product) {
            $skipped++;
            continue;
        }
        $record = nibwp_wcpro_product_export_row($product);
        $rows[] = array_map(static fn (string $column) => $record[$column] ?? '', $columns);
    }

    $written = nibwp_wcpro_write_csv(nibwp_wcpro_export_filename($input, 'products'), $columns, $rows);
    if (is_wp_error($written)) {
        return $written;
    }

    return nibwp_wcpro_ok(
        'export_products',
        sprintf('Exported %d product(s)%s.', count($rows), $skipped > 0 ? sprintf(', %d skipped as not editable by this account', $skipped) : ''),
        ['result' => $written + ['columns' => $columns, 'skipped' => $skipped], 'count' => count($rows)],
    );
}

/** @return array<string, string> */
function nibwp_wcpro_product_export_row(WC_Product $product): array
{
    $categories = [];
    foreach ((array) $product->get_category_ids() as $term_id) {
        $term = get_term((int) $term_id, 'product_cat');
        if ($term instanceof WP_Term) {
            $categories[] = (string) $term->name;
        }
    }
    $tags = [];
    foreach ((array) $product->get_tag_ids() as $term_id) {
        $term = get_term((int) $term_id, 'product_tag');
        if ($term instanceof WP_Term) {
            $tags[] = (string) $term->name;
        }
    }
    $images = [];
    foreach (array_merge([(int) $product->get_image_id()], array_map('intval', (array) $product->get_gallery_image_ids())) as $attachment_id) {
        if ($attachment_id > 0) {
            $images[] = (string) wp_get_attachment_url($attachment_id);
        }
    }

    return [
        'id'                 => (string) $product->get_id(),
        'sku'                => (string) $product->get_sku(),
        'name'               => (string) $product->get_name(),
        'type'               => (string) $product->get_type(),
        'status'             => (string) $product->get_status(),
        'featured'           => $product->get_featured() ? '1' : '0',
        'catalog_visibility' => (string) $product->get_catalog_visibility(),
        'regular_price'      => (string) $product->get_regular_price(),
        'sale_price'         => (string) $product->get_sale_price(),
        'price'              => (string) $product->get_price(),
        'tax_status'         => (string) $product->get_tax_status(),
        'tax_class'          => (string) $product->get_tax_class(),
        'manage_stock'       => $product->get_manage_stock() ? '1' : '0',
        'stock_quantity'     => (string) $product->get_stock_quantity(),
        'stock_status'       => (string) $product->get_stock_status(),
        'backorders'         => (string) $product->get_backorders(),
        'weight'             => (string) $product->get_weight(),
        'length'             => (string) $product->get_length(),
        'width'              => (string) $product->get_width(),
        'height'             => (string) $product->get_height(),
        'categories'         => implode(', ', $categories),
        'tags'               => implode(', ', $tags),
        'images'             => implode(', ', $images),
        'short_description'  => (string) $product->get_short_description(),
        'description'        => (string) $product->get_description(),
        'total_sales'        => (string) $product->get_total_sales(),
        'permalink'          => (string) get_permalink($product->get_id()),
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_export_orders(array $input): array|WP_Error
{
    // WooCommerce gates its own Orders screens on this primitive rather than on
    // a per-order meta capability, and with HPOS there is no post to map one
    // against, so this is the honest equivalent of "could open Orders here".
    $denied = nibwp_wcpro_require_cap('edit_shop_orders', 'Exporting orders');
    if ($denied !== null) {
        return $denied;
    }
    $denied = nibwp_wcpro_require_function('wc_get_orders', 'Exporting orders');
    if ($denied !== null) {
        return $denied;
    }

    $filter = (array) ($input['filter'] ?? []);
    $limit = (int) ($filter['limit'] ?? 1000);
    $args = [
        'limit'  => $limit > 0 ? min($limit, 5000) : 1000,
        'offset' => max(0, (int) ($filter['offset'] ?? 0)),
        'orderby' => 'date',
        'order'  => 'DESC',
        'return' => 'objects',
    ];
    if (!empty($filter['status'])) {
        $args['status'] = is_array($filter['status']) ? $filter['status'] : [(string) $filter['status']];
    }
    if (!empty($filter['customer_id'])) {
        $args['customer_id'] = (int) $filter['customer_id'];
    }
    $from = nibwp_wcpro_date($filter['date_from'] ?? '', 'filter.date_from');
    if (is_wp_error($from)) {
        return $from;
    }
    $to = nibwp_wcpro_date($filter['date_to'] ?? '', 'filter.date_to');
    if (is_wp_error($to)) {
        return $to;
    }
    if ($from !== '' || $to !== '') {
        $args['date_created'] = ($from !== '' ? $from : '') . '...' . ($to !== '' ? $to : gmdate('Y-m-d'));
    }

    $columns = nibwp_wcpro_export_columns($input, [
        'id', 'number', 'status', 'date_created', 'date_paid', 'currency',
        'total', 'subtotal', 'total_tax', 'shipping_total', 'discount_total',
        'payment_method_title', 'customer_id', 'billing_email', 'billing_name',
        'billing_country', 'shipping_country', 'item_count', 'items',
    ]);

    $rows = [];
    foreach ((array) wc_get_orders($args) as $order) {
        if (!$order instanceof WC_Order) {
            continue;
        }
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = sprintf('%s x%s', (string) $item->get_name(), (string) $item->get_quantity());
        }
        $record = [
            'id'             => (string) $order->get_id(),
            'number'         => (string) $order->get_order_number(),
            'status'         => (string) $order->get_status(),
            'date_created'   => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
            'date_paid'      => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d H:i:s') : '',
            'currency'       => (string) $order->get_currency(),
            'total'          => (string) $order->get_total(),
            'subtotal'       => (string) $order->get_subtotal(),
            'total_tax'      => (string) $order->get_total_tax(),
            'shipping_total' => (string) $order->get_shipping_total(),
            'discount_total' => (string) $order->get_discount_total(),
            'payment_method_title' => (string) $order->get_payment_method_title(),
            'customer_id'    => (string) $order->get_customer_id(),
            'billing_email'  => (string) $order->get_billing_email(),
            'billing_name'   => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'billing_country' => (string) $order->get_billing_country(),
            'shipping_country' => (string) $order->get_shipping_country(),
            'item_count'     => (string) $order->get_item_count(),
            'items'          => implode(' | ', $items),
        ];
        $rows[] = array_map(static fn (string $column) => $record[$column] ?? '', $columns);
    }

    $written = nibwp_wcpro_write_csv(nibwp_wcpro_export_filename($input, 'orders'), $columns, $rows);
    if (is_wp_error($written)) {
        return $written;
    }

    return nibwp_wcpro_ok('export_orders', sprintf('Exported %d order(s).', count($rows)), [
        'result' => $written + ['columns' => $columns],
        'count'  => count($rows),
        'next_steps' => ['This file holds customer names, emails and addresses. It is in the uploads directory, which is web-readable — delete it when the user has it.'],
    ]);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_export_customers(array $input): array|WP_Error
{
    // Customer records are personal data. list_users is what opens Users → All
    // Users in wp-admin; each customer is then asked about individually, so a
    // shop manager who may not edit an administrator's account does not export
    // it either.
    $denied = nibwp_wcpro_require_cap('list_users', 'Exporting customers');
    if ($denied !== null) {
        return $denied;
    }

    $filter = (array) ($input['filter'] ?? []);
    $limit = (int) ($filter['limit'] ?? 1000);
    $query = new WP_User_Query([
        'role__in' => ['customer', 'subscriber'],
        'number'   => $limit > 0 ? min($limit, 5000) : 1000,
        'offset'   => max(0, (int) ($filter['offset'] ?? 0)),
        'orderby'  => 'ID',
        'order'    => 'ASC',
        'fields'   => 'ID',
    ]);

    $columns = nibwp_wcpro_export_columns($input, [
        'id', 'email', 'username', 'first_name', 'last_name', 'registered',
        'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city',
        'billing_state', 'billing_postcode', 'billing_country', 'billing_phone',
        'shipping_address_1', 'shipping_city', 'shipping_postcode', 'shipping_country',
        'order_count', 'total_spent',
    ]);

    $rows = [];
    $skipped = 0;
    foreach ((array) $query->get_results() as $user_id) {
        $user_id = (int) $user_id;
        // The object-level question, asked per customer. WooCommerce filters
        // edit_user so a shop manager may only reach customers, and this honours
        // that rather than reimplementing it.
        if (!current_user_can('edit_user', $user_id)) {
            $skipped++;
            continue;
        }
        $customer = class_exists('WC_Customer') ? new WC_Customer($user_id) : null;
        $user = get_userdata($user_id);
        if ($customer === null || !$user) {
            $skipped++;
            continue;
        }
        $record = [
            'id'         => (string) $user_id,
            'email'      => (string) $customer->get_email(),
            'username'   => (string) $user->user_login,
            'first_name' => (string) $customer->get_first_name(),
            'last_name'  => (string) $customer->get_last_name(),
            'registered' => (string) $user->user_registered,
            'billing_company'   => (string) $customer->get_billing_company(),
            'billing_address_1' => (string) $customer->get_billing_address_1(),
            'billing_address_2' => (string) $customer->get_billing_address_2(),
            'billing_city'      => (string) $customer->get_billing_city(),
            'billing_state'     => (string) $customer->get_billing_state(),
            'billing_postcode'  => (string) $customer->get_billing_postcode(),
            'billing_country'   => (string) $customer->get_billing_country(),
            'billing_phone'     => (string) $customer->get_billing_phone(),
            'shipping_address_1' => (string) $customer->get_shipping_address_1(),
            'shipping_city'      => (string) $customer->get_shipping_city(),
            'shipping_postcode'  => (string) $customer->get_shipping_postcode(),
            'shipping_country'   => (string) $customer->get_shipping_country(),
            'order_count' => (string) $customer->get_order_count(),
            'total_spent' => (string) $customer->get_total_spent(),
        ];
        $rows[] = array_map(static fn (string $column) => $record[$column] ?? '', $columns);
    }

    $written = nibwp_wcpro_write_csv(nibwp_wcpro_export_filename($input, 'customers'), $columns, $rows);
    if (is_wp_error($written)) {
        return $written;
    }

    return nibwp_wcpro_ok(
        'export_customers',
        sprintf('Exported %d customer(s)%s.', count($rows), $skipped > 0 ? sprintf(', %d skipped as not readable by this account', $skipped) : ''),
        [
            'result' => $written + ['columns' => $columns, 'skipped' => $skipped],
            'count'  => count($rows),
            'next_steps' => ['This file is personal data in a web-readable directory. Hand it over and delete it; do not leave it in uploads.'],
        ],
    );
}

/**
 * The columns an export writes: the caller's subset, or all of them.
 *
 * @param array<int, string> $default
 * @return array<int, string>
 */
function nibwp_wcpro_export_columns(array $input, array $default): array
{
    $given = (array) ($input['columns'] ?? []);
    if ($given === []) {
        return $default;
    }
    $chosen = array_values(array_intersect($default, array_map('strval', $given)));

    return $chosen === [] ? $default : $chosen;
}

/** A dated basename, unless the caller named one. */
function nibwp_wcpro_export_filename(array $input, string $kind): string
{
    $given = nibwp_wcpro_text($input['filename'] ?? '', 120);

    return $given !== '' ? $given : sprintf('%s-%s', $kind, gmdate('Ymd-His'));
}
