<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Figma_Client — thin, honest wrapper over the Figma REST API.
 *
 * Read-only. Authenticates with the user's Figma token (personal access token or
 * OAuth bearer). Every method returns a decoded array on success or a WP_Error on
 * failure, so callers branch on is_wp_error(). No conversion logic lives here.
 */
class NIBWP_Figma_Client
{
    private const BASE = 'https://api.figma.com';

    private string $token;

    public function __construct(string $token)
    {
        $this->token = trim($token);
    }

    /**
     * Pull the file key + (optional) node id out of a Figma URL.
     * Handles /file/KEY and /design/KEY, and the ?node-id=1-234 (or 1:234) param.
     *
     * @return array{key:string,node:string}|WP_Error
     */
    public static function parse_url(string $url)
    {
        $url = trim($url);
        if (!preg_match('#figma\.com/(?:file|design)/([A-Za-z0-9]+)#', $url, $m)) {
            return new WP_Error('figma_bad_url', 'Not a Figma file/design URL. Expected figma.com/design/KEY/…');
        }
        $key = $m[1];

        $node = '';
        if (preg_match('#[?&]node-id=([^&]+)#', $url, $nm)) {
            $node = urldecode($nm[1]);
            // URLs use 1-234, the API wants 1:234.
            $node = str_replace('-', ':', $node);
        }

        return ['key' => $key, 'node' => $node];
    }

    /** How long a successful read stays cached. */
    private const CACHE_TTL = 600;

    /**
     * GET a Figma REST path. Returns the decoded body array or WP_Error.
     *
     * Successful reads are cached in a transient. Figma's file endpoints are
     * rate limited far more tightly than /v1/me, and a pull followed by a
     * convert asks for the same node twice — without this, ordinary use walks
     * straight into "Rate limit exceeded".
     *
     * @return array<mixed>|WP_Error
     */
    public function get(string $path, bool $cache = true)
    {
        if ($this->token === '') {
            return new WP_Error('figma_no_token', 'No Figma token configured.');
        }

        // Scoped to the token so switching accounts cannot read a stale answer.
        $key = 'nibwp_figma_' . md5(substr($this->token, -8) . '|' . $path);
        if ($cache) {
            $hit = get_transient($key);
            if (is_array($hit)) {
                return $hit;
            }
        }

        $res = wp_remote_get(self::BASE . $path, [
            'timeout' => 30,
            'headers' => ['X-Figma-Token' => $this->token],
        ]);

        if (is_wp_error($res)) {
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        $data = json_decode($body, true);

        if ($code === 429) {
            // Figma's own quota, not ours. Hand back the wait in human terms plus
            // enough context that the limit is not mistaken for a NibWP failure.
            $retry = (int) wp_remote_retrieve_header($res, 'retry-after');
            return new WP_Error(
                'figma_rate_limited',
                $retry > 0
                    ? sprintf(
                        /* translators: %s: human readable duration, e.g. "3 days" */
                        __('Figma has temporarily paused API access for this token. It should work again in about %s.', 'nibwp'),
                        nibwp_figma_human_wait($retry)
                    )
                    : __('Figma has temporarily paused API access for this token. Try again in a few minutes.', 'nibwp'),
                [
                    'status'       => 429,
                    'retry_after'  => $retry,
                    'retry_human'  => $retry > 0 ? nibwp_figma_human_wait($retry) : '',
                    'source'       => 'figma',
                    'is_rate_limit' => true,
                ]
            );
        }
        if ($code !== 200) {
            $msg = is_array($data) && isset($data['err']) ? (string) $data['err'] : ('HTTP ' . $code);
            return new WP_Error('figma_http_' . $code, 'Figma API: ' . $msg, ['status' => $code]);
        }
        if (!is_array($data)) {
            return new WP_Error('figma_bad_json', 'Figma returned an unparseable response.');
        }

        if ($cache) {
            set_transient($key, $data, self::CACHE_TTL);
        }

        return $data;
    }

    /** Validate the token — GET /v1/me. @return array<mixed>|WP_Error */
    public function me()
    {
        return $this->get('/v1/me');
    }

    /** @return array<mixed>|WP_Error */
    public function get_file(string $key, ?int $depth = null)
    {
        $q = $depth !== null ? ('?depth=' . $depth) : '';
        return $this->get('/v1/files/' . rawurlencode($key) . $q);
    }

    /**
     * Fetch one or more node subtrees by id.
     *
     * @param array<int,string> $ids
     * @return array<mixed>|WP_Error
     */
    public function get_nodes(string $key, array $ids)
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if ($ids === []) {
            return new WP_Error('figma_no_ids', 'No node ids given.');
        }
        return $this->get('/v1/files/' . rawurlencode($key) . '/nodes?ids=' . rawurlencode(implode(',', $ids)));
    }

    /**
     * Render node ids to images. Returns { images: { id: url } }.
     *
     * @param array<int,string> $ids
     * @return array<mixed>|WP_Error
     */
    public function get_images(string $key, array $ids, int $scale = 2, string $format = 'png')
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if ($ids === []) {
            return new WP_Error('figma_no_ids', 'No node ids given.');
        }
        $q = '?ids=' . rawurlencode(implode(',', $ids))
            . '&scale=' . $scale
            . '&format=' . rawurlencode($format);
        // Rendered-image URLs are short-lived S3 links — caching them would hand
        // back dead links later, so this one always goes to Figma.
        return $this->get('/v1/images/' . rawurlencode($key) . $q, false);
    }

    /**
     * Image FILLS map — the actual bitmap behind each imageRef used as a fill.
     * Returns { imageRef: url }. (Different from get_images, which RENDERS nodes.)
     *
     * @return array<string,string>|WP_Error
     */
    public function get_image_fills(string $key)
    {
        $res = $this->get('/v1/files/' . rawurlencode($key) . '/images');
        if (is_wp_error($res)) {
            return $res;
        }
        $map = $res['meta']['images'] ?? [];
        return is_array($map) ? array_map('strval', $map) : [];
    }

    /**
     * Pull the team / project id out of a Figma workspace URL.
     * Handles figma.com/files/team/<id>/… and figma.com/files/project/<id>/…
     *
     * @return array{type:string,id:string}|WP_Error
     */
    public static function parse_workspace_url(string $url)
    {
        $url = trim($url);
        if (preg_match('#figma\.com/files/team/(\d+)#', $url, $m)) {
            return ['type' => 'team', 'id' => $m[1]];
        }
        if (preg_match('#figma\.com/files/project/(\d+)#', $url, $m)) {
            return ['type' => 'project', 'id' => $m[1]];
        }
        return new WP_Error(
            'figma_bad_workspace_url',
            'Not a Figma team or project URL. Open the team (or a project) in Figma and copy the address bar — it looks like figma.com/files/team/123456789/Name.'
        );
    }

    /**
     * Projects inside a team. Figma exposes no "list my teams" endpoint, so the
     * team id has to come from a URL the user supplies.
     *
     * @return array<mixed>|WP_Error
     */
    public function get_team_projects(string $team_id)
    {
        return $this->get('/v1/teams/' . rawurlencode($team_id) . '/projects');
    }

    /** Files inside a project. @return array<mixed>|WP_Error */
    public function get_project_files(string $project_id)
    {
        return $this->get('/v1/projects/' . rawurlencode($project_id) . '/files');
    }

    /** Local Variables (Enterprise-gated; 403 on other plans). @return array<mixed>|WP_Error */
    public function get_variables(string $key)
    {
        return $this->get('/v1/files/' . rawurlencode($key) . '/variables/local');
    }

    /** Published styles. @return array<mixed>|WP_Error */
    public function get_styles(string $key)
    {
        return $this->get('/v1/files/' . rawurlencode($key) . '/styles');
    }
}
