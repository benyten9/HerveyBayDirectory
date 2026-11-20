<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — the uploads directory, and CSV in and out of it.
 *
 * Every path this skill accepts is relative to wp_upload_dir()['basedir'] and
 * nothing else. An absolute path, a Windows drive letter, a URL and a `../`
 * escape are all refused before a handle is opened, because an import that reads
 * an arbitrary path is a way to read wp-config.php over MCP, and an export that
 * writes one is a way to put a .php file on the server.
 *
 * The resolution is done with realpath() against a realpath()ed base, so a
 * symlink inside uploads that points out of it is caught too — string-prefix
 * checks on the unresolved path are not enough.
 */

require_once __DIR__ . '/guard.php';

/** Where the skill is allowed to read and write, resolved. */
function nibwp_wcpro_uploads_basedir(): string|WP_Error
{
    // $create_dir false: asking where uploads live must not create this month's
    // folder as a side effect.
    $uploads = wp_upload_dir(null, false);
    $basedir = is_array($uploads) && is_string($uploads['basedir'] ?? null) ? (string) $uploads['basedir'] : '';
    if ($basedir === '') {
        return nibwp_wcpro_err('no_uploads_dir', 'WordPress could not resolve the uploads directory, so no file can be read or written.', 500);
    }

    $real = realpath($basedir);

    return nibwp_wcpro_normalize_path($real === false ? $basedir : $real);
}

/** Forward slashes, no trailing slash. Windows and Linux answer the same way. */
function nibwp_wcpro_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;

    return rtrim($path, '/');
}

/** Is $path the directory $base, or inside it? */
function nibwp_wcpro_path_within(string $path, string $base): bool
{
    $path = nibwp_wcpro_normalize_path($path);
    $base = nibwp_wcpro_normalize_path($base);

    if ($base === '' || $path === '') {
        return false;
    }

    // Case-insensitive on Windows, where the same directory has many spellings.
    if (DIRECTORY_SEPARATOR === '\\') {
        return strcasecmp($path, $base) === 0 || stripos($path, $base . '/') === 0;
    }

    return $path === $base || str_starts_with($path, $base . '/');
}

/**
 * Resolve a caller-supplied path to an absolute one inside the uploads
 * directory, or refuse.
 *
 * $must_exist false is for a file about to be written: the parent directory is
 * resolved instead, and the filename is appended afterwards, so a target that
 * does not exist yet can still be proved to land inside the base.
 *
 * @param array<int, string> $allowed_extensions lowercase, no dot; [] allows any but .php
 */
function nibwp_wcpro_uploads_path(mixed $relative, bool $must_exist = true, array $allowed_extensions = ['csv', 'txt']): string|WP_Error
{
    $base = nibwp_wcpro_uploads_basedir();
    if (is_wp_error($base)) {
        return $base;
    }

    $raw = str_replace('\\', '/', trim((string) ($relative ?? '')));
    if ($raw === '') {
        return nibwp_wcpro_err('missing_path', 'Provide a file path relative to the uploads directory, e.g. "imports/products.csv".', 422);
    }
    if (str_contains($raw, "\0")) {
        return nibwp_wcpro_forbidden('The path contains a null byte and will not be opened.');
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $raw)) {
        return nibwp_wcpro_forbidden('A URL is not a local path. Give a path relative to the uploads directory.');
    }
    if (str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:/#', $raw)) {
        return nibwp_wcpro_forbidden('Absolute paths are refused. Give a path relative to the uploads directory, e.g. "imports/products.csv".');
    }

    $extension = strtolower((string) pathinfo($raw, PATHINFO_EXTENSION));
    if ($extension === 'php' || str_contains(strtolower($raw), '.php.')) {
        return nibwp_wcpro_forbidden('A PHP file is never read or written here.');
    }
    if ($allowed_extensions !== [] && !in_array($extension, $allowed_extensions, true)) {
        return nibwp_wcpro_err(
            'bad_extension',
            sprintf('Only %s files are handled here, got ".%s".', implode('/', $allowed_extensions), $extension),
            422,
        );
    }

    $candidate = $base . '/' . ltrim($raw, '/');

    if ($must_exist) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return nibwp_wcpro_err('file_not_found', sprintf('No file at uploads/%s.', ltrim($raw, '/')), 404);
        }
        $resolved = nibwp_wcpro_normalize_path($resolved);
    } else {
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            return nibwp_wcpro_err('directory_not_found', sprintf('The folder uploads/%s does not exist.', ltrim(dirname($raw), '/')), 404);
        }
        $resolved = nibwp_wcpro_normalize_path($parent) . '/' . basename($candidate);
    }

    // The check that matters. Everything above is a shortcut; this is the wall.
    if (!nibwp_wcpro_path_within($resolved, $base)) {
        return nibwp_wcpro_forbidden(sprintf('"%s" resolves outside the uploads directory, so it will not be opened.', $raw));
    }
    if ($must_exist && !is_file($resolved)) {
        return nibwp_wcpro_err('not_a_file', sprintf('uploads/%s is not a file.', ltrim($raw, '/')), 422);
    }

    return $resolved;
}

/** The public URL of a file already proved to be inside the uploads directory. */
function nibwp_wcpro_uploads_url(string $absolute): string
{
    $base = nibwp_wcpro_uploads_basedir();
    if (is_wp_error($base)) {
        return '';
    }
    $uploads = wp_upload_dir(null, false);
    $baseurl = is_array($uploads) ? (string) ($uploads['baseurl'] ?? '') : '';
    $relative = ltrim(substr(nibwp_wcpro_normalize_path($absolute), strlen($base)), '/');

    return $baseurl === '' ? '' : rtrim($baseurl, '/') . '/' . $relative;
}

/**
 * The folder this skill writes exports into. One place, created on demand, so an
 * export never needs a caller-supplied write path at all.
 */
function nibwp_wcpro_export_dir(): string|WP_Error
{
    $base = nibwp_wcpro_uploads_basedir();
    if (is_wp_error($base)) {
        return $base;
    }
    $dir = $base . '/nibwp-wcpro';
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return nibwp_wcpro_err('mkdir_failed', sprintf('Could not create %s — check the uploads directory is writable.', $dir), 500);
    }

    return $dir;
}

/**
 * Read a slice of a CSV.
 *
 * $offset counts data rows, not file bytes, so a resumed batch asks for the same
 * thing a human would: "the next 200 rows". The header is always read, whatever
 * the offset, because a row means nothing without it.
 *
 * @return array{header: array<int, string>, rows: array<int, array<string, string>>, total: int, offset: int, next_offset: int|null}|WP_Error
 */
function nibwp_wcpro_read_csv(string $path, int $offset = 0, int $limit = 0, string $delimiter = ','): array|WP_Error
{
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return nibwp_wcpro_err('unreadable', sprintf('Could not open %s for reading.', basename($path)), 500);
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if (!is_array($header) || $header === [null]) {
        fclose($handle);
        return nibwp_wcpro_err('empty_csv', 'The file has no header row, so its columns cannot be named.', 422);
    }

    // A UTF-8 BOM on the first cell makes the first column name unmatchable, and
    // spreadsheets add one without saying so.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
    $header = array_map(static fn ($cell) => trim((string) $cell), $header);
    $width = count($header);

    $rows = [];
    $index = 0;
    $total = 0;
    while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($cells === [null]) {
            continue; // A blank line is not a row.
        }
        $total++;
        $index++;
        if ($index <= $offset) {
            continue;
        }
        if ($limit > 0 && count($rows) >= $limit) {
            // Keep counting so `total` is the truth, but stop collecting.
            continue;
        }
        $cells = array_slice(array_pad($cells, $width, ''), 0, $width);
        $row = [];
        foreach ($header as $position => $name) {
            $row[$name] = (string) ($cells[$position] ?? '');
        }
        $row['__line'] = (string) ($index + 1); // +1 for the header, so it matches the spreadsheet.
        $rows[] = $row;
    }
    fclose($handle);

    $consumed = $offset + count($rows);

    return [
        'header'      => $header,
        'rows'        => $rows,
        'total'       => $total,
        'offset'      => $offset,
        'next_offset' => $consumed < $total ? $consumed : null,
    ];
}

/**
 * Write a CSV into the skill's export folder and return where it landed.
 *
 * The filename is sanitised and the extension forced, so a caller cannot name
 * the file into something the web server executes.
 *
 * @param array<int, string>              $header
 * @param array<int, array<int, mixed>>   $rows
 * @return array{path: string, url: string, relative: string, rows: int}|WP_Error
 */
function nibwp_wcpro_write_csv(string $basename, array $header, array $rows): array|WP_Error
{
    $dir = nibwp_wcpro_export_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }

    $name = sanitize_file_name(pathinfo($basename, PATHINFO_FILENAME));
    if ($name === '') {
        $name = 'export';
    }
    $path = $dir . '/' . $name . '.csv';

    // Proved again, against the same wall, now that the final name is known.
    if (!nibwp_wcpro_path_within($path, (string) nibwp_wcpro_uploads_basedir())) {
        return nibwp_wcpro_forbidden('The export target resolves outside the uploads directory.');
    }

    $handle = @fopen($path, 'w');
    if ($handle === false) {
        return nibwp_wcpro_err('unwritable', sprintf('Could not open %s for writing.', basename($path)), 500);
    }

    // A BOM, so a spreadsheet opening this reads accented names correctly rather
    // than as mojibake the user then reports as our bug.
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $header);
    $written = 0;
    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn ($cell) => is_scalar($cell) || $cell === null ? (string) $cell : wp_json_encode($cell), (array) $row));
        $written++;
    }
    fclose($handle);

    $base = nibwp_wcpro_uploads_basedir();

    return [
        'path'     => $path,
        'url'      => nibwp_wcpro_uploads_url($path),
        'relative' => is_wp_error($base) ? basename($path) : ltrim(substr(nibwp_wcpro_normalize_path($path), strlen($base)), '/'),
        'rows'     => $written,
    ];
}
