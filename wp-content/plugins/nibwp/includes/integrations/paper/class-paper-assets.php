<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Paper_Assets — an image the agent exported out of Paper → the Media Library.
 *
 * Two shapes arrive in practice: a URL the agent can serve, and a data: URI for a
 * small export. Both end as a real attachment, deduped by the hash of the source
 * so re-ingesting a design does not fill the library with copies of one picture.
 *
 * Alt text is a first-class argument rather than an afterthought. An image with no
 * alt is an accessibility defect, and the agent is the only party in this exchange
 * that has seen the design and can describe it — so it is asked for, and its
 * absence is reported back as a warning the caller can act on.
 */
class NIBWP_Paper_Assets
{
    /** A data: URI that decodes to more than this is not a design asset. */
    private const MAX_DATA_BYTES = 8388608;

    /** @var array<string, string> extension by mime, for the data: branch */
    private const DATA_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function attach(mixed $raw_url, mixed $raw_alt, mixed $raw_filename)
    {
        $url = is_scalar($raw_url) ? trim((string) $raw_url) : '';
        if ($url === '') {
            return nibwp_paper_err('missing_url', __('Give the image as url — either an http(s) URL or a data: image URI.', 'nibwp'), 422);
        }
        if (strlen($url) > self::MAX_DATA_BYTES) {
            return nibwp_paper_err('asset_too_large', __('That image is larger than this site accepts in one call. Export it smaller, or host it and send the URL.', 'nibwp'), 413);
        }

        $alt = is_scalar($raw_alt) ? trim(wp_strip_all_tags((string) $raw_alt)) : '';
        $alt = $alt === '' ? '' : mb_substr($alt, 0, 300);

        $filename = self::clean_filename($raw_filename);

        $is_data = stripos($url, 'data:') === 0;
        if (!$is_data) {
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                // An image reference this site may not fetch: a file: path, a
                // javascript: URI, or a bare path that would resolve against our
                // own filesystem. None of them is an export.
                return nibwp_paper_err(
                    'bad_url',
                    __('That is not an image this site may fetch. Use http(s) or a data: image URI.', 'nibwp'),
                    422
                );
            }
        }

        $hash = sha1($url);
        $existing = self::existing($hash);
        if ($existing > 0) {
            self::set_alt($existing, $alt);

            return self::row($existing, $alt, true, $hash);
        }

        $attachment_id = $is_data
            ? self::from_data_uri($url, $filename, $hash)
            : self::from_remote($url, $filename, $hash);

        if ($attachment_id instanceof WP_Error) {
            return $attachment_id;
        }

        self::set_alt($attachment_id, $alt);
        update_post_meta($attachment_id, '_nibwp_paper_source_key', $hash);

        return self::row($attachment_id, $alt, false, $hash);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(int $attachment_id, string $alt, bool $reused, string $hash): array
    {
        $out = [
            'ok' => true,
            'attachment_id' => $attachment_id,
            'url' => (string) wp_get_attachment_url($attachment_id),
            'alt' => $alt,
            'reused' => $reused,
            // The hash of the source, not the source. Enough to recognise the same
            // export again, and it carries no credential and no path.
            'source_key' => substr($hash, 0, 12),
        ];

        if ($alt === '') {
            $out['warnings'] = [__('No alt text was given. Describe the image and call attach_asset again with alt — an image with no alt is a defect, not a style choice.', 'nibwp')];
        }

        return $out;
    }

    /** Reuse a previous sideload of the same source. */
    private static function existing(string $hash): int
    {
        $found = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_nibwp_paper_source_key',
            'meta_value' => $hash,
        ]);

        return $found === [] ? 0 : (int) $found[0];
    }

    private static function set_alt(int $attachment_id, string $alt): void
    {
        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }
    }

    /**
     * @return int|WP_Error
     */
    private static function from_remote(string $url, string $filename, string $hash)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return nibwp_paper_err(
                'download_failed',
                sprintf(
                    /* translators: %s: the error WordPress reported */
                    __('That image could not be fetched: %s', 'nibwp'),
                    $tmp->get_error_message()
                ),
                502
            );
        }

        if ($filename === '') {
            $basename = basename((string) wp_parse_url($url, PHP_URL_PATH));
            $filename = self::clean_filename($basename);
        }
        if ($filename === '') {
            $filename = 'paper-' . substr($hash, 0, 10) . '.png';
        }

        $id = media_handle_sideload(['name' => $filename, 'tmp_name' => $tmp], 0);
        if (is_wp_error($id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            return nibwp_paper_err(
                'sideload_failed',
                sprintf(
                    /* translators: %s: the error WordPress reported */
                    __('That image could not be added to the Media Library: %s', 'nibwp'),
                    $id->get_error_message()
                ),
                422
            );
        }

        return (int) $id;
    }

    /**
     * A data: image URI → an attachment.
     *
     * Decoded with strict base64 and checked against the declared type before it
     * goes anywhere near the uploads directory, and written through wp_upload_bits
     * so WordPress applies its own filename and mime rules rather than ours.
     *
     * @return int|WP_Error
     */
    private static function from_data_uri(string $url, string $filename, string $hash)
    {
        if (preg_match('#^data:([a-z]+/[a-z0-9.+-]+);base64,(.+)$#i', $url, $matches) !== 1) {
            return nibwp_paper_err('bad_data_uri', __('That data: URI is not a base64 image.', 'nibwp'), 422);
        }

        $mime = strtolower($matches[1]);
        if (!isset(self::DATA_EXTENSIONS[$mime])) {
            // SVG is excluded on purpose: it is a document that can carry script,
            // and WordPress does not allow it by default either.
            return nibwp_paper_err(
                'unsupported_type',
                sprintf(
                    /* translators: %s: the mime type that was refused */
                    __('"%s" is not an image type this site accepts inline. Use PNG, JPEG, GIF, WebP or AVIF.', 'nibwp'),
                    $mime
                ),
                422
            );
        }

        $decoded = base64_decode((string) preg_replace('/\s+/', '', $matches[2]), true);
        if ($decoded === false || $decoded === '') {
            return nibwp_paper_err('bad_data_uri', __('That data: URI does not decode.', 'nibwp'), 422);
        }
        if (strlen($decoded) > self::MAX_DATA_BYTES) {
            return nibwp_paper_err('asset_too_large', __('That inline image is too large. Host it and send the URL instead.', 'nibwp'), 413);
        }

        $extension = self::DATA_EXTENSIONS[$mime];
        if ($filename === '') {
            $filename = 'paper-' . substr($hash, 0, 10) . '.' . $extension;
        }
        if (!str_ends_with(strtolower($filename), '.' . $extension)) {
            $filename .= '.' . $extension;
        }

        $written = wp_upload_bits($filename, null, $decoded);
        if (!is_array($written) || ($written['error'] ?? false)) {
            return nibwp_paper_err(
                'write_failed',
                sprintf(
                    /* translators: %s: the error WordPress reported */
                    __('That image could not be written to the uploads directory: %s', 'nibwp'),
                    is_array($written) ? (string) $written['error'] : __('unknown error', 'nibwp')
                ),
                500
            );
        }

        $path = (string) ($written['file'] ?? '');
        // The decoded bytes must actually be the image type they claimed. A file
        // that is not what it says it is does not belong in the Media Library.
        $checked = wp_check_filetype_and_ext($path, $filename, null);
        if (($checked['type'] ?? '') === false || ($checked['type'] ?? '') === '') {
            wp_delete_file($path);

            return nibwp_paper_err('unsupported_type', __('Those bytes are not the image type the data: URI claimed.', 'nibwp'), 422);
        }

        $id = wp_insert_attachment([
            'post_mime_type' => (string) $checked['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $path, 0, true);

        if (is_wp_error($id)) {
            wp_delete_file($path);

            return $id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata((int) $id, wp_generate_attachment_metadata((int) $id, $path));

        return (int) $id;
    }

    private static function clean_filename(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $name = sanitize_file_name(trim((string) $raw));
        $name = (string) preg_replace('/\.(php[0-9]?|phtml|phar|js|html?|svg)$/i', '', $name);

        return mb_substr($name, 0, 100);
    }
}
