<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEO Pro — payload validator.
 *
 * Runs an agent-built SEO payload (meta fields and/or a schema object) through
 * the hard quality rules the playbook enforces. Returns a structured pass/fail
 * map so the agent patches + resubmits until it conforms — mirrors
 * etchwp-pro/lib/validator.php. Called unconditionally at the persist gate.
 *
 * Payload (any subset): title, description, canonical, focus_keyword,
 * noindex, nofollow, schema (object or array of @type objects), post_id.
 * Ctx: title_max(60), title_min(30), desc_max(160), desc_min(50),
 * is_front(bool), existing_titles({normalized=>post_id}), existing_descs(same).
 */

/** Required top-level properties per schema.org @type the skill generates. */
function nibwp_seo_pro_schema_required(): array
{
    return [
        'Article'      => ['headline', 'image', 'datePublished', 'author'],
        'BlogPosting'  => ['headline', 'image', 'datePublished', 'author'],
        'NewsArticle'  => ['headline', 'image', 'datePublished', 'author'],
        'Product'      => ['name', 'image', 'offers'],
        'FAQPage'      => ['mainEntity'],
        'HowTo'        => ['name', 'step'],
        'Recipe'       => ['name', 'image', 'recipeIngredient'],
        'Event'        => ['name', 'startDate', 'location'],
        'LocalBusiness'=> ['name', 'address'],
        'Organization' => ['name', 'url'],
        'BreadcrumbList' => ['itemListElement'],
        'Review'       => ['itemReviewed', 'reviewRating', 'author'],
        'Person'       => ['name'],
        'WebSite'      => ['name', 'url'],
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $ctx
 * @return array{passed:bool,failed:array<int,array{id:string,msg:string,path:string,fix_hint:string}>,warnings:array<int,array<string,string>>}
 */
function nibwp_seo_pro_validate(array $payload, array $ctx = []): array
{
    $failed = [];
    $warnings = [];

    $title_max = (int) ($ctx['title_max'] ?? 60);
    $title_min = (int) ($ctx['title_min'] ?? 30);
    $desc_max  = (int) ($ctx['desc_max'] ?? 160);
    $desc_min  = (int) ($ctx['desc_min'] ?? 50);
    $post_id   = (int) ($ctx['post_id'] ?? ($payload['post_id'] ?? 0));

    // Title.
    if (array_key_exists('title', $payload) && $payload['title'] !== '') {
        $title = (string) $payload['title'];
        $len = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($len > $title_max) {
            $failed[] = ['id' => 'title_too_long', 'msg' => sprintf('SEO title is %d chars; max is %d (it will be truncated in SERPs).', $len, $title_max), 'path' => 'title', 'fix_hint' => sprintf('Rewrite the title to %d characters or fewer while keeping the primary keyword near the front.', $title_max)];
        } elseif ($len < $title_min) {
            $warnings[] = ['id' => 'title_short', 'msg' => sprintf('SEO title is only %d chars; aim for %d-%d.', $len, $title_min, $title_max), 'path' => 'title'];
        }
        // Cannibalization: exact duplicate of another post's title.
        $existing = (array) ($ctx['existing_titles'] ?? []);
        $key = nibwp_seo_pro_norm_key($title);
        if (isset($existing[$key]) && (int) $existing[$key] !== $post_id) {
            $failed[] = ['id' => 'duplicate_title', 'msg' => sprintf('This SEO title is identical to post #%d (keyword cannibalization). Make it unique.', (int) $existing[$key]), 'path' => 'title', 'fix_hint' => 'Differentiate the title — target a distinct angle/keyword, or consolidate the two pages with a canonical.'];
        }
    }

    // Description.
    if (array_key_exists('description', $payload) && $payload['description'] !== '') {
        $desc = (string) $payload['description'];
        $len = function_exists('mb_strlen') ? mb_strlen($desc) : strlen($desc);
        if ($len > $desc_max) {
            $failed[] = ['id' => 'desc_too_long', 'msg' => sprintf('Meta description is %d chars; max is %d.', $len, $desc_max), 'path' => 'description', 'fix_hint' => sprintf('Trim the description to %d characters or fewer; lead with the value proposition + keyword.', $desc_max)];
        } elseif ($len < $desc_min) {
            $warnings[] = ['id' => 'desc_short', 'msg' => sprintf('Meta description is only %d chars; aim for %d-%d.', $len, $desc_min, $desc_max), 'path' => 'description'];
        }
    }

    // Canonical must be a valid URL.
    if (!empty($payload['canonical'])) {
        $url = (string) $payload['canonical'];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $failed[] = ['id' => 'bad_canonical', 'msg' => sprintf('Canonical "%s" is not a valid absolute URL.', $url), 'path' => 'canonical', 'fix_hint' => 'Use an absolute URL including https:// (e.g. https://example.com/page/).'];
        }
    }

    // Robots sanity — never noindex the front page.
    if (!empty($payload['noindex']) && !empty($ctx['is_front'])) {
        $failed[] = ['id' => 'noindex_homepage', 'msg' => 'Refusing to noindex the site front page — this would remove the homepage from search.', 'path' => 'noindex', 'fix_hint' => 'Do not set noindex on the front page. If intentional, set it manually in the SEO plugin.'];
    }

    // Focus keyword should appear in the title (warning).
    if (!empty($payload['focus_keyword']) && !empty($payload['title'])) {
        if (stripos((string) $payload['title'], (string) $payload['focus_keyword']) === false) {
            $warnings[] = ['id' => 'keyword_not_in_title', 'msg' => sprintf('Focus keyword "%s" does not appear in the SEO title.', (string) $payload['focus_keyword']), 'path' => 'title'];
        }
    }

    // Schema validation.
    if (array_key_exists('schema', $payload) && $payload['schema'] !== null && $payload['schema'] !== '') {
        $failed = array_merge($failed, nibwp_seo_pro_validate_schema($payload['schema']));
    }

    // Ensure every failed entry carries a fix_hint.
    $failed = array_map(static function (array $f): array {
        if (empty($f['fix_hint'])) { $f['fix_hint'] = 'See msg.'; }
        return $f;
    }, $failed);

    return ['passed' => count($failed) === 0, 'failed' => array_values($failed), 'warnings' => array_values($warnings)];
}

/** Normalize a string for duplicate comparison. */
function nibwp_seo_pro_norm_key(string $s): string
{
    $s = strtolower(trim($s));
    return preg_replace('/\s+/', ' ', $s) ?? $s;
}

/**
 * Validate a schema payload (single object or array of objects) against the
 * required-field map.
 *
 * @param mixed $schema
 * @return array<int,array{id:string,msg:string,path:string,fix_hint:string}>
 */
function nibwp_seo_pro_validate_schema($schema): array
{
    if (is_string($schema)) {
        $decoded = json_decode($schema, true);
        $schema = is_array($decoded) ? $decoded : null;
        if ($schema === null) {
            return [['id' => 'schema_not_json', 'msg' => 'Schema is a string but not valid JSON.', 'path' => 'schema', 'fix_hint' => 'Pass schema as a JSON object/array, or a valid JSON-LD string.']];
        }
    }
    if (!is_array($schema)) {
        return [['id' => 'schema_invalid', 'msg' => 'Schema must be an object or an array of objects.', 'path' => 'schema', 'fix_hint' => 'Provide a JSON-LD object with an @type.']];
    }

    // Normalize to a list of schema nodes.
    $nodes = isset($schema['@type']) ? [$schema] : array_values($schema);
    $required = nibwp_seo_pro_schema_required();
    $issues = [];

    foreach ($nodes as $i => $node) {
        if (!is_array($node)) { continue; }
        $type = $node['@type'] ?? '';
        $type = is_array($type) ? (string) ($type[0] ?? '') : (string) $type;
        if ($type === '') {
            $issues[] = ['id' => 'schema_no_type', 'msg' => sprintf('Schema node [%d] has no @type.', $i), 'path' => "schema[$i].@type", 'fix_hint' => 'Add an @type from schema.org (e.g. Article, Product, FAQPage).'];
            continue;
        }
        if (!isset($required[$type])) {
            // Unknown/unsupported type — warn-level, allow (don't block valid niche types).
            continue;
        }
        foreach ($required[$type] as $field) {
            if (!array_key_exists($field, $node) || $node[$field] === '' || $node[$field] === []) {
                $issues[] = ['id' => 'schema_missing_field', 'msg' => sprintf('%s schema is missing required field "%s".', $type, $field), 'path' => "schema[$i].$field", 'fix_hint' => sprintf('Add the "%s" property — it is required by schema.org / Google for %s rich results.', $field, $type)];
            }
        }
        // Light nested checks.
        if ($type === 'Product' && isset($node['offers']) && is_array($node['offers'])) {
            $offer = isset($node['offers']['@type']) ? $node['offers'] : ($node['offers'][0] ?? []);
            foreach (['price', 'priceCurrency'] as $f) {
                if (is_array($offer) && !array_key_exists($f, $offer)) {
                    $issues[] = ['id' => 'schema_missing_field', 'msg' => sprintf('Product offers is missing "%s".', $f), 'path' => "schema[$i].offers.$f", 'fix_hint' => 'Add price + priceCurrency to offers for Product rich results.'];
                }
            }
        }
        if ($type === 'FAQPage' && isset($node['mainEntity']) && is_array($node['mainEntity'])) {
            foreach ($node['mainEntity'] as $qi => $q) {
                if (!is_array($q)) { continue; }
                if (empty($q['name']) || empty($q['acceptedAnswer'])) {
                    $issues[] = ['id' => 'schema_missing_field', 'msg' => sprintf('FAQ item [%d] needs both a "name" (question) and "acceptedAnswer".', $qi), 'path' => "schema[$i].mainEntity[$qi]", 'fix_hint' => 'Each FAQ entry needs {"@type":"Question","name":"…","acceptedAnswer":{"@type":"Answer","text":"…"}}.'];
                }
            }
        }
    }
    return $issues;
}
