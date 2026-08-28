<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// =============================================================================
// Ability: nibwp/seo-image-optimize
// =============================================================================

wp_register_ability('nibwp/seo-image-optimize', [
    'label' => __('SEO – Image Optimize', domain: 'nibwp'),
    'description' => __(
        'Audit and fix image SEO issues such as missing alt text, titles, dimensions, lazy loading, and non-descriptive filenames.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['audit', 'fix'],
                'description' => 'Whether to audit (read-only scan) or fix (auto-repair) image SEO issues.',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Optional: limit scan to images within a specific post.',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of images to process per batch. Default 50.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total_images' => ['type' => 'integer'],
            'issues' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'attachment_id' => ['type' => 'integer'],
                        'file' => ['type' => 'string'],
                        'issue_type' => ['type' => 'string'],
                        'current_value' => ['type' => 'string'],
                        'suggested_fix' => ['type' => 'string'],
                    ],
                ],
            ],
            'fixed_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_seo_image_optimize',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Audit or fix image SEO across your site.',
                '',
                'ACTION = "audit" (read-only):',
                '- Scans attachment images for: missing alt text, missing title attribute,',
                '  oversized dimensions (>2560px), missing width/height in post content,',
                '  non-descriptive filenames (IMG_1234.jpg, DSC_*, Screenshot_*),',
                '  missing loading="lazy" attribute in post content.',
                '',
                'ACTION = "fix":',
                '- Auto-generates alt text from post title + cleaned filename.',
                '- Adds missing width/height from actual image file metadata.',
                '- Adds loading="lazy" to images in post content.',
                '- Does NOT rename files (too destructive).',
                '',
                'Use post_id to limit to a single post, or omit for site-wide scan.',
                'per_page controls batch size (default 50, max 200).',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_image_optimize(array $input): array|WP_Error
{
    $action = $input['action'] ?? 'audit';
    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
    $per_page = min(max((int) ($input['per_page'] ?? 50), 1), 200);

    $issues = [];
    $fixed_count = 0;

    // Query attachments.
    $args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => $per_page,
    ];

    if ($post_id > 0) {
        $args['post_parent'] = $post_id;
    }

    $query = new WP_Query($args);
    $total_images = $query->found_posts;

    $non_descriptive_patterns = [
        '/^IMG[_-]\d+/i',
        '/^DSC[_-]\d+/i',
        '/^Screenshot[_-]/i',
        '/^Photo[_-]\d+/i',
        '/^image\d+/i',
        '/^P\d{6,}/i',
    ];

    foreach ($query->posts as $attachment) {
        $file_path = get_attached_file($attachment->ID);
        $filename = $file_path ? basename($file_path) : '';
        $filename_no_ext = pathinfo($filename, PATHINFO_FILENAME);
        $alt_text = (string) get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
        $title = $attachment->post_title;
        $metadata = wp_get_attachment_metadata($attachment->ID);

        // Check: missing alt text.
        if (empty(trim($alt_text))) {
            $parent_title = '';
            if ($attachment->post_parent > 0) {
                $parent = get_post($attachment->post_parent);
                $parent_title = $parent ? $parent->post_title : '';
            }
            $suggested = trim($parent_title . ' - ' . str_replace(['-', '_'], ' ', $filename_no_ext));
            $suggested = preg_replace('/\s+/', ' ', $suggested);
            $issues[] = [
                'attachment_id' => $attachment->ID,
                'file' => $filename,
                'issue_type' => 'missing_alt_text',
                'current_value' => '',
                'suggested_fix' => $suggested,
            ];

            if ($action === 'fix') {
                update_post_meta($attachment->ID, '_wp_attachment_image_alt', sanitize_text_field($suggested));
                $fixed_count++;
            }
        }

        // Check: non-descriptive filename.
        foreach ($non_descriptive_patterns as $pattern) {
            if (preg_match($pattern, $filename_no_ext)) {
                $issues[] = [
                    'attachment_id' => $attachment->ID,
                    'file' => $filename,
                    'issue_type' => 'non_descriptive_filename',
                    'current_value' => $filename,
                    'suggested_fix' => 'Rename the file to something descriptive before re-uploading.',
                ];
                break;
            }
        }

        // Check: oversized dimensions.
        if (is_array($metadata) && !empty($metadata['width']) && !empty($metadata['height'])) {
            if ((int) $metadata['width'] > 2560 || (int) $metadata['height'] > 2560) {
                $issues[] = [
                    'attachment_id' => $attachment->ID,
                    'file' => $filename,
                    'issue_type' => 'oversized_dimensions',
                    'current_value' => $metadata['width'] . 'x' . $metadata['height'],
                    'suggested_fix' => 'Resize to max 2560px on the longest side.',
                ];
            }
        }

        // Check: missing title (same as filename slug).
        if (empty(trim($title)) || $title === $filename_no_ext) {
            $cleaned_title = ucwords(str_replace(['-', '_'], ' ', $filename_no_ext));
            $issues[] = [
                'attachment_id' => $attachment->ID,
                'file' => $filename,
                'issue_type' => 'missing_title',
                'current_value' => $title,
                'suggested_fix' => $cleaned_title,
            ];

            if ($action === 'fix' && $title === $filename_no_ext) {
                wp_update_post([
                    'ID' => $attachment->ID,
                    'post_title' => sanitize_text_field($cleaned_title),
                ]);
                $fixed_count++;
            }
        }
    }

    // Scan post content for images missing width/height and lazy loading.
    if ($post_id > 0) {
        $post = get_post($post_id);
        if ($post && !empty($post->post_content)) {
            $content = $post->post_content;
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $images = $doc->getElementsByTagName('img');
            $content_modified = false;

            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                $short_src = basename($src);

                // Check missing width/height.
                if (!$img->hasAttribute('width') || !$img->hasAttribute('height')) {
                    $attachment_id_for_img = attachment_url_to_postid($src);
                    $meta = $attachment_id_for_img ? wp_get_attachment_metadata($attachment_id_for_img) : null;
                    $suggested_dims = 'Could not determine dimensions.';
                    if (is_array($meta) && !empty($meta['width'])) {
                        $suggested_dims = $meta['width'] . 'x' . $meta['height'];
                        if ($action === 'fix') {
                            $img->setAttribute('width', (string) $meta['width']);
                            $img->setAttribute('height', (string) $meta['height']);
                            $content_modified = true;
                        }
                    }
                    $issues[] = [
                        'attachment_id' => $attachment_id_for_img ?: 0,
                        'file' => $short_src,
                        'issue_type' => 'missing_dimensions_in_content',
                        'current_value' => '',
                        'suggested_fix' => 'Add width/height: ' . $suggested_dims,
                    ];
                    if ($action === 'fix' && $attachment_id_for_img) {
                        $fixed_count++;
                    }
                }

                // Check missing lazy loading.
                if (!$img->hasAttribute('loading')) {
                    $issues[] = [
                        'attachment_id' => 0,
                        'file' => $short_src,
                        'issue_type' => 'missing_lazy_loading',
                        'current_value' => '',
                        'suggested_fix' => 'Add loading="lazy" attribute.',
                    ];
                    if ($action === 'fix') {
                        $img->setAttribute('loading', 'lazy');
                        $content_modified = true;
                        $fixed_count++;
                    }
                }
            }

            if ($action === 'fix' && $content_modified) {
                $body = $doc->getElementsByTagName('body')->item(0);
                $new_content = '';
                if ($body) {
                    foreach ($body->childNodes as $child) {
                        $new_content .= $doc->saveHTML($child);
                    }
                }
                if (!empty($new_content)) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_content' => $new_content,
                    ]);
                }
            }
        }
    }

    return [
        'total_images' => $total_images,
        'issues' => $issues,
        'fixed_count' => $fixed_count,
    ];
}

// =============================================================================
// Ability: nibwp/seo-schema-markup
// =============================================================================

wp_register_ability('nibwp/seo-schema-markup', [
    'label' => __('SEO – Schema Markup', domain: 'nibwp'),
    'description' => __(
        'Generate, get, or set structured data (JSON-LD) schema markup for posts.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['generate', 'get', 'set'],
                'description' => 'Action to perform.',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post ID.',
            ],
            'schema_type' => [
                'type' => 'string',
                'enum' => ['Article', 'Product', 'LocalBusiness', 'FAQPage', 'HowTo', 'Recipe', 'Event', 'Organization', 'BreadcrumbList', 'Review'],
                'description' => 'Schema.org type to generate.',
            ],
            'schema_data' => [
                'type' => 'object',
                'description' => 'Custom schema data object for the "set" action.',
            ],
        ],
        'required' => ['action', 'post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'schema_type' => ['type' => 'string'],
            'schema' => ['type' => 'object', 'description' => 'The JSON-LD schema object.'],
        ],
    ],
    'execute_callback' => 'nibwp_seo_schema_markup',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Generate, retrieve, or save JSON-LD structured data for a post.',
                '',
                'ACTION = "generate":',
                '- Requires schema_type. Auto-generates JSON-LD from post data.',
                '- Article: headline, author, datePublished, dateModified, image, publisher.',
                '- Product: name, description, price & availability from WooCommerce if available.',
                '- FAQPage: extracts Q&A pairs from h2/h3 headings followed by paragraphs.',
                '- HowTo: extracts steps from ordered lists or h3 headings.',
                '- BreadcrumbList: builds from post ancestors / categories.',
                '- LocalBusiness, Organization: pulls from site settings.',
                '- Recipe, Event, Review: basic structure from post content and meta.',
                '',
                'ACTION = "get":',
                '- Returns existing schema stored in _nibwp_schema post meta.',
                '',
                'ACTION = "set":',
                '- Requires schema_data. Saves the provided schema object to post meta.',
                '- Use this after reviewing/editing a generated schema.',
                '',
                'The returned schema is ready to embed as JSON-LD in <script> tags.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_schema_markup(array $input): array|WP_Error
{
    $action = $input['action'] ?? '';
    $post_id = (int) ($input['post_id'] ?? 0);

    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id must be a positive integer.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    if ($action === 'get') {
        $existing = get_post_meta($post_id, '_nibwp_schema', true);
        return [
            'post_id' => $post_id,
            'schema_type' => is_array($existing) ? ($existing['@type'] ?? 'unknown') : 'none',
            'schema' => is_array($existing) ? $existing : [],
        ];
    }

    if ($action === 'set') {
        if (empty($input['schema_data']) || !is_array($input['schema_data'])) {
            return new WP_Error('invalid_schema_data', 'schema_data must be a non-empty object.');
        }
        update_post_meta($post_id, '_nibwp_schema', $input['schema_data']);
        return [
            'post_id' => $post_id,
            'schema_type' => $input['schema_data']['@type'] ?? 'custom',
            'schema' => $input['schema_data'],
        ];
    }

    if ($action === 'generate') {
        $schema_type = $input['schema_type'] ?? '';
        if (empty($schema_type)) {
            return new WP_Error('missing_schema_type', 'schema_type is required for the generate action.');
        }

        $schema = nibwp_seo_generate_schema($post, $schema_type);
        if (is_wp_error($schema)) {
            return $schema;
        }

        update_post_meta($post_id, '_nibwp_schema', $schema);

        return [
            'post_id' => $post_id,
            'schema_type' => $schema_type,
            'schema' => $schema,
        ];
    }

    return new WP_Error('invalid_action', 'action must be one of: generate, get, set.');
}

function nibwp_seo_generate_schema(WP_Post $post, string $type): array|WP_Error
{
    $author = get_userdata($post->post_author);
    $author_name = $author ? $author->display_name : 'Unknown';
    $thumbnail_url = (string) get_the_post_thumbnail_url($post->ID, 'full');
    $site_name = get_bloginfo('name');
    $site_url = home_url('/');
    $permalink = get_permalink($post);

    $base = [
        '@context' => 'https://schema.org',
        '@type' => $type,
    ];

    switch ($type) {
        case 'Article':
            return array_merge($base, [
                'headline' => get_the_title($post),
                'author' => [
                    '@type' => 'Person',
                    'name' => $author_name,
                ],
                'datePublished' => get_the_date('c', $post),
                'dateModified' => get_the_modified_date('c', $post),
                'image' => $thumbnail_url ?: null,
                'url' => $permalink,
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $site_name,
                    'url' => $site_url,
                ],
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $permalink,
                ],
            ]);

        case 'Product':
            $schema = array_merge($base, [
                'name' => get_the_title($post),
                'description' => wp_trim_words(wp_strip_all_tags($post->post_content), 50),
                'image' => $thumbnail_url ?: null,
                'url' => $permalink,
            ]);

            // WooCommerce integration.
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($post->ID);
                if ($product) {
                    $schema['sku'] = $product->get_sku();
                    $schema['offers'] = [
                        '@type' => 'Offer',
                        'price' => $product->get_price(),
                        'priceCurrency' => get_woocommerce_currency(),
                        'availability' => $product->is_in_stock()
                            ? 'https://schema.org/InStock'
                            : 'https://schema.org/OutOfStock',
                        'url' => $permalink,
                    ];
                }
            }
            return $schema;

        case 'FAQPage':
            $faq_entries = [];
            $content = $post->post_content;
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($doc);

            // Extract h2/h3 headings as questions, following paragraphs as answers.
            $headings = $xpath->query('//h2|//h3');
            if ($headings) {
                foreach ($headings as $heading) {
                    $question = trim($heading->textContent);
                    $answer_parts = [];
                    $sibling = $heading->nextSibling;
                    while ($sibling) {
                        if ($sibling->nodeType === XML_ELEMENT_NODE) {
                            $tag = strtolower($sibling->nodeName);
                            if ($tag === 'h2' || $tag === 'h3') {
                                break;
                            }
                            $answer_parts[] = trim($sibling->textContent);
                        }
                        $sibling = $sibling->nextSibling;
                    }
                    $answer = implode(' ', array_filter($answer_parts));
                    if (!empty($question) && !empty($answer)) {
                        $faq_entries[] = [
                            '@type' => 'Question',
                            'name' => $question,
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => $answer,
                            ],
                        ];
                    }
                }
            }

            $base['mainEntity'] = $faq_entries;
            return $base;

        case 'HowTo':
            $steps = [];
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $post->post_content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($doc);

            // Try ordered lists first.
            $list_items = $xpath->query('//ol/li');
            if ($list_items && $list_items->length > 0) {
                $step_num = 1;
                foreach ($list_items as $li) {
                    $steps[] = [
                        '@type' => 'HowToStep',
                        'position' => $step_num++,
                        'text' => trim($li->textContent),
                    ];
                }
            } else {
                // Fall back to h3 headings as steps.
                $headings = $xpath->query('//h3');
                if ($headings) {
                    $step_num = 1;
                    foreach ($headings as $heading) {
                        $text_parts = [];
                        $sibling = $heading->nextSibling;
                        while ($sibling) {
                            if ($sibling->nodeType === XML_ELEMENT_NODE) {
                                if (strtolower($sibling->nodeName) === 'h3') {
                                    break;
                                }
                                $text_parts[] = trim($sibling->textContent);
                            }
                            $sibling = $sibling->nextSibling;
                        }
                        $steps[] = [
                            '@type' => 'HowToStep',
                            'position' => $step_num++,
                            'name' => trim($heading->textContent),
                            'text' => implode(' ', array_filter($text_parts)),
                        ];
                    }
                }
            }

            return array_merge($base, [
                'name' => get_the_title($post),
                'description' => wp_trim_words(wp_strip_all_tags($post->post_content), 30),
                'step' => $steps,
            ]);

        case 'BreadcrumbList':
            $items = [];
            $position = 1;

            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => 'Home',
                'item' => $site_url,
            ];

            $categories = get_the_category($post->ID);
            if (!empty($categories)) {
                $cat = $categories[0];
                $ancestors = get_ancestors($cat->term_id, 'category');
                $ancestor_cats = array_reverse($ancestors);
                foreach ($ancestor_cats as $ancestor_id) {
                    $ancestor_cat = get_term($ancestor_id, 'category');
                    if ($ancestor_cat && !is_wp_error($ancestor_cat)) {
                        $items[] = [
                            '@type' => 'ListItem',
                            'position' => $position++,
                            'name' => $ancestor_cat->name,
                            'item' => get_term_link($ancestor_cat),
                        ];
                    }
                }
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $cat->name,
                    'item' => get_term_link($cat),
                ];
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => get_the_title($post),
                'item' => $permalink,
            ];

            $base['itemListElement'] = $items;
            return $base;

        case 'LocalBusiness':
            return array_merge($base, [
                'name' => $site_name,
                'url' => $site_url,
                'description' => get_bloginfo('description'),
            ]);

        case 'Organization':
            $logo_id = get_theme_mod('custom_logo');
            $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
            return array_merge($base, [
                'name' => $site_name,
                'url' => $site_url,
                'description' => get_bloginfo('description'),
                'logo' => $logo_url ?: null,
            ]);

        case 'Recipe':
            return array_merge($base, [
                'name' => get_the_title($post),
                'description' => wp_trim_words(wp_strip_all_tags($post->post_content), 50),
                'author' => ['@type' => 'Person', 'name' => $author_name],
                'datePublished' => get_the_date('c', $post),
                'image' => $thumbnail_url ?: null,
            ]);

        case 'Event':
            return array_merge($base, [
                'name' => get_the_title($post),
                'description' => wp_trim_words(wp_strip_all_tags($post->post_content), 50),
                'url' => $permalink,
                'image' => $thumbnail_url ?: null,
                'organizer' => ['@type' => 'Organization', 'name' => $site_name],
            ]);

        case 'Review':
            return array_merge($base, [
                'name' => get_the_title($post),
                'author' => ['@type' => 'Person', 'name' => $author_name],
                'datePublished' => get_the_date('c', $post),
                'reviewBody' => wp_trim_words(wp_strip_all_tags($post->post_content), 100),
            ]);

        default:
            return new WP_Error('unsupported_schema_type', sprintf('Schema type "%s" is not supported.', $type));
    }
}

// =============================================================================
// Ability: nibwp/seo-broken-links
// =============================================================================

wp_register_ability('nibwp/seo-broken-links', [
    'label' => __('SEO – Broken Links', domain: 'nibwp'),
    'description' => __(
        'Find broken internal and external links in post content.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Optional: check links in a specific post only.',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of posts to scan. Default 20.',
            ],
            'check_external' => [
                'type' => 'boolean',
                'description' => 'Whether to check external links too. Default false (slow).',
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total_links' => ['type' => 'integer'],
            'broken' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'post_title' => ['type' => 'string'],
                        'link_url' => ['type' => 'string'],
                        'status_code' => ['type' => 'integer'],
                        'link_text' => ['type' => 'string'],
                        'anchor_context' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_seo_broken_links',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Find broken links in post content.',
                '',
                'Parses all <a href="..."> links from post_content and checks each with wp_remote_head().',
                '',
                'INTERNAL links (same domain) are always checked.',
                'EXTERNAL links are only checked when check_external=true (slow due to HTTP requests).',
                '',
                'Use post_id to limit to a single post. Otherwise scans the latest published posts.',
                'per_page controls how many posts to scan (default 20).',
                '',
                'Returns each broken link with: post_id, post_title, the URL, HTTP status code,',
                'the anchor text, and surrounding context.',
                '',
                'A link is "broken" if it returns 404, 410, 500+, or the request fails entirely.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_broken_links(array $input): array|WP_Error
{
    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $check_external = (bool) ($input['check_external'] ?? false);

    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

    $args = [
        'post_type' => 'any',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
    ];
    if ($post_id > 0) {
        $args['p'] = $post_id;
        $args['posts_per_page'] = 1;
    }

    $query = new WP_Query($args);
    $total_links = 0;
    $broken = [];

    foreach ($query->posts as $post) {
        if (empty($post->post_content)) {
            continue;
        }

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $post->post_content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $links = $doc->getElementsByTagName('a');

        foreach ($links as $link) {
            $href = trim($link->getAttribute('href'));
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            // Make relative URLs absolute.
            if (str_starts_with($href, '/')) {
                $href = home_url($href);
            }

            $parsed = wp_parse_url($href);
            if (empty($parsed['host'])) {
                continue;
            }

            $is_internal = strtolower($parsed['host']) === strtolower($site_host);

            if (!$is_internal && !$check_external) {
                continue;
            }

            $total_links++;
            $link_text = trim($link->textContent);

            $response = wp_remote_head($href, [
                'timeout' => 10,
                'redirection' => 3,
                'user-agent' => 'NIBWP Link Checker/1.0 (WordPress)',
                'sslverify' => false,
            ]);

            $status_code = 0;
            if (is_wp_error($response)) {
                $status_code = 0;
            } else {
                $status_code = (int) wp_remote_retrieve_response_code($response);
            }

            $is_broken = $status_code === 0 || $status_code === 404 || $status_code === 410 || $status_code >= 500;

            if ($is_broken) {
                // Extract surrounding context.
                $parent_node = $link->parentNode;
                $context = $parent_node ? mb_substr(trim($parent_node->textContent), 0, 200) : '';

                $broken[] = [
                    'post_id' => $post->ID,
                    'post_title' => get_the_title($post),
                    'link_url' => $href,
                    'status_code' => $status_code,
                    'link_text' => $link_text,
                    'anchor_context' => $context,
                ];
            }
        }
    }

    return [
        'total_links' => $total_links,
        'broken' => $broken,
    ];
}

// =============================================================================
// Ability: nibwp/seo-redirect-manager
// =============================================================================

wp_register_ability('nibwp/seo-redirect-manager', [
    'label' => __('SEO – Redirect Manager', domain: 'nibwp'),
    'description' => __(
        'Manage 301/302/307 redirects stored in WordPress options.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'create', 'delete', 'test'],
                'description' => 'Action to perform.',
            ],
            'redirect_data' => [
                'type' => 'object',
                'description' => 'Redirect data for "create": {source, target, type}.',
                'properties' => [
                    'source' => ['type' => 'string', 'description' => 'Source URL path (e.g. /old-page).'],
                    'target' => ['type' => 'string', 'description' => 'Target URL (e.g. /new-page or full URL).'],
                    'type' => ['type' => 'integer', 'enum' => [301, 302, 307], 'description' => 'HTTP redirect status code.'],
                ],
            ],
            'redirect_id' => [
                'type' => 'integer',
                'description' => 'Redirect ID for "delete" or "test" actions.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'redirects' => ['type' => 'array'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_seo_redirect_manager',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage URL redirects without needing a separate redirect plugin.',
                '',
                'ACTION = "list": Returns all configured redirects.',
                '',
                'ACTION = "create":',
                '- Requires redirect_data with: source (path like /old-page), target (path or full URL), type (301/302/307).',
                '- 301 = permanent redirect (SEO-friendly, passes link equity).',
                '- 302 = temporary redirect.',
                '- 307 = temporary redirect (preserves HTTP method).',
                '- Source must start with /. Duplicate sources are rejected.',
                '',
                'ACTION = "delete": Requires redirect_id (the array index from list).',
                '',
                'ACTION = "test": Requires redirect_id. Tests if the redirect is working by checking the source URL.',
                '',
                'Redirects are stored in the nibwp_redirects option. They must be',
                'applied via a template_redirect hook (handled by NIBWP core).',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_redirect_manager(array $input): array|WP_Error
{
    $action = $input['action'] ?? '';
    $redirects = get_option('nibwp_redirects', []);
    if (!is_array($redirects)) {
        $redirects = [];
    }

    switch ($action) {
        case 'list':
            $list = [];
            foreach ($redirects as $id => $redirect) {
                $list[] = array_merge(['id' => $id], $redirect);
            }
            return ['redirects' => $list, 'message' => sprintf('%d redirect(s) configured.', count($list))];

        case 'create':
            $data = $input['redirect_data'] ?? [];
            if (empty($data['source']) || empty($data['target'])) {
                return new WP_Error('missing_fields', 'redirect_data must include source and target.');
            }

            $source = sanitize_text_field($data['source']);
            $target = sanitize_text_field($data['target']);
            $type = in_array((int) ($data['type'] ?? 301), [301, 302, 307], true) ? (int) $data['type'] : 301;

            if (!str_starts_with($source, '/')) {
                return new WP_Error('invalid_source', 'Source must start with / (e.g. /old-page).');
            }

            // Check for duplicate sources.
            foreach ($redirects as $existing) {
                if ($existing['source'] === $source) {
                    return new WP_Error('duplicate_source', sprintf('A redirect for "%s" already exists.', $source));
                }
            }

            $new_redirect = [
                'source' => $source,
                'target' => $target,
                'type' => $type,
                'created' => current_time('mysql'),
                'hits' => 0,
            ];

            $redirects[] = $new_redirect;
            update_option('nibwp_redirects', $redirects, false);

            $new_id = array_key_last($redirects);
            return [
                'redirects' => [array_merge(['id' => $new_id], $new_redirect)],
                'message' => sprintf('Redirect created: %s -> %s (%d).', $source, $target, $type),
            ];

        case 'delete':
            $redirect_id = (int) ($input['redirect_id'] ?? -1);
            if (!isset($redirects[$redirect_id])) {
                return new WP_Error('not_found', sprintf('Redirect ID %d not found.', $redirect_id));
            }

            $removed = $redirects[$redirect_id];
            unset($redirects[$redirect_id]);
            $redirects = array_values($redirects);
            update_option('nibwp_redirects', $redirects, false);

            return [
                'redirects' => [],
                'message' => sprintf('Deleted redirect: %s -> %s.', $removed['source'], $removed['target']),
            ];

        case 'test':
            $redirect_id = (int) ($input['redirect_id'] ?? -1);
            if (!isset($redirects[$redirect_id])) {
                return new WP_Error('not_found', sprintf('Redirect ID %d not found.', $redirect_id));
            }

            $redirect = $redirects[$redirect_id];
            $test_url = home_url($redirect['source']);

            $response = wp_remote_head($test_url, [
                'timeout' => 10,
                'redirection' => 0,
                'sslverify' => false,
            ]);

            if (is_wp_error($response)) {
                return [
                    'redirects' => [array_merge(['id' => $redirect_id], $redirect)],
                    'message' => 'Test failed: ' . $response->get_error_message(),
                ];
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');

            return [
                'redirects' => [array_merge(['id' => $redirect_id], $redirect)],
                'message' => sprintf(
                    'Test result: HTTP %d, Location: %s (expected %d to %s).',
                    $status,
                    $location ?: '(none)',
                    $redirect['type'],
                    $redirect['target']
                ),
            ];

        default:
            return new WP_Error('invalid_action', 'action must be one of: list, create, delete, test.');
    }
}

// =============================================================================
// Ability: nibwp/seo-meta-bulk-generate
// =============================================================================

wp_register_ability('nibwp/seo-meta-bulk-generate', [
    'label' => __('SEO – Bulk Generate Meta', domain: 'nibwp'),
    'description' => __(
        'Bulk generate SEO titles and meta descriptions from templates.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type to process. Default "post".',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of posts to process. Default 20.',
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Overwrite existing meta? Default false.',
            ],
            'template_title' => [
                'type' => 'string',
                'description' => 'Template for SEO title. E.g. "{{title}} | {{site_name}}".',
            ],
            'template_desc' => [
                'type' => 'string',
                'description' => 'Template for meta description. E.g. "{{excerpt_150}}".',
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'processed_count' => ['type' => 'integer'],
            'skipped_count' => ['type' => 'integer'],
            'updated' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'seo_title' => ['type' => 'string'],
                        'seo_description' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_seo_meta_bulk_generate',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Bulk generate SEO titles and meta descriptions for posts using templates.',
                '',
                'TEMPLATE VARIABLES:',
                '- {{title}} - Post title.',
                '- {{excerpt_150}} - First 150 chars of excerpt/content.',
                '- {{excerpt_200}} - First 200 chars of excerpt/content.',
                '- {{site_name}} - Site name from Settings > General.',
                '- {{category}} - First category name.',
                '- {{author}} - Author display name.',
                '- {{year}} - Current year.',
                '',
                'EXAMPLE TEMPLATES:',
                '- Title: "{{title}} | {{site_name}}"',
                '- Description: "{{excerpt_150}}"',
                '',
                'If template_title is empty, titles are not updated.',
                'If template_desc is empty, descriptions are not updated.',
                '',
                'Set overwrite=true to replace existing SEO meta. By default, posts that already',
                'have SEO title/description are skipped.',
                '',
                'Auto-detects active SEO plugin (Yoast, Rank Math, AIOSEO) and writes to the',
                'correct meta keys. Falls back to custom meta _nibwp_seo_title / _nibwp_seo_description.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_meta_bulk_generate(array $input): array|WP_Error
{
    $post_type = sanitize_text_field($input['post_type'] ?? 'post');
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $overwrite = (bool) ($input['overwrite'] ?? false);
    $template_title = $input['template_title'] ?? '';
    $template_desc = $input['template_desc'] ?? '';

    if (empty($template_title) && empty($template_desc)) {
        return new WP_Error('no_templates', 'At least one of template_title or template_desc must be provided.');
    }

    // Detect SEO plugin meta keys.
    $title_key = '_nibwp_seo_title';
    $desc_key = '_nibwp_seo_description';

    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
        $title_key = '_yoast_wpseo_title';
        $desc_key = '_yoast_wpseo_metadesc';
    } elseif (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        $title_key = 'rank_math_title';
        $desc_key = 'rank_math_description';
    } elseif (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) {
        $title_key = '_aioseo_title';
        $desc_key = '_aioseo_description';
    }

    $query = new WP_Query([
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $processed = 0;
    $skipped = 0;
    $updated = [];

    foreach ($query->posts as $post) {
        $existing_title = (string) get_post_meta($post->ID, $title_key, true);
        $existing_desc = (string) get_post_meta($post->ID, $desc_key, true);

        if (!$overwrite && !empty($existing_title) && !empty($existing_desc)) {
            $skipped++;
            continue;
        }

        // Build template variables.
        $author = get_userdata($post->post_author);
        $categories = get_the_category($post->ID);
        $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_strip_all_tags($post->post_content);

        $vars = [
            '{{title}}' => get_the_title($post),
            '{{excerpt_150}}' => mb_substr(trim($excerpt), 0, 150),
            '{{excerpt_200}}' => mb_substr(trim($excerpt), 0, 200),
            '{{site_name}}' => get_bloginfo('name'),
            '{{category}}' => !empty($categories) ? $categories[0]->name : '',
            '{{author}}' => $author ? $author->display_name : '',
            '{{year}}' => date('Y'),
        ];

        $seo_title = '';
        $seo_desc = '';

        if (!empty($template_title) && ($overwrite || empty($existing_title))) {
            $seo_title = str_replace(array_keys($vars), array_values($vars), $template_title);
            update_post_meta($post->ID, $title_key, sanitize_text_field($seo_title));
        }

        if (!empty($template_desc) && ($overwrite || empty($existing_desc))) {
            $seo_desc = str_replace(array_keys($vars), array_values($vars), $template_desc);
            update_post_meta($post->ID, $desc_key, sanitize_text_field($seo_desc));
        }

        $processed++;
        $updated[] = [
            'post_id' => $post->ID,
            'title' => get_the_title($post),
            'seo_title' => $seo_title,
            'seo_description' => $seo_desc,
        ];
    }

    return [
        'processed_count' => $processed,
        'skipped_count' => $skipped,
        'updated' => $updated,
    ];
}

// =============================================================================
// Ability: nibwp/seo-internal-linking
// =============================================================================

wp_register_ability('nibwp/seo-internal-linking', [
    'label' => __('SEO – Internal Linking Suggestions', domain: 'nibwp'),
    'description' => __(
        'Analyze a post and suggest internal linking opportunities to other content.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post to analyze for linking opportunities.',
            ],
            'max_suggestions' => [
                'type' => 'integer',
                'description' => 'Maximum number of suggestions to return. Default 10.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'post_title' => ['type' => 'string'],
            'existing_internal_links' => ['type' => 'integer'],
            'suggestions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'target_post_id' => ['type' => 'integer'],
                        'target_title' => ['type' => 'string'],
                        'target_url' => ['type' => 'string'],
                        'matching_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'suggested_anchor_text' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_seo_internal_linking',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Analyze a post and find internal linking opportunities.',
                '',
                'HOW IT WORKS:',
                '1. Extracts meaningful keywords/phrases from the post content.',
                '2. Finds other published posts whose titles or content match those keywords.',
                '3. Filters out posts that are already linked from the source post.',
                '4. Returns suggestions ranked by keyword relevance.',
                '',
                'Each suggestion includes:',
                '- target_post_id, target_title, target_url: the post to link to.',
                '- matching_keywords: which keywords from the source post match the target.',
                '- suggested_anchor_text: recommended anchor text to use.',
                '',
                'Use max_suggestions to limit results (default 10).',
                '',
                'This is read-only: it does not modify any content.',
                'You can use the suggestions to manually add links or automate with wp-update-post.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_internal_linking(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $max_suggestions = min(max((int) ($input['max_suggestions'] ?? 10), 1), 50);

    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id must be a positive integer.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    $content = wp_strip_all_tags($post->post_content);
    $title = get_the_title($post);

    // Extract existing internal links.
    $existing_links = [];
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $post->post_content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $links = $doc->getElementsByTagName('a');
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $parsed = wp_parse_url($href);
        if (!empty($parsed['host']) && strtolower($parsed['host']) === strtolower($site_host)) {
            $linked_id = url_to_postid($href);
            if ($linked_id > 0) {
                $existing_links[] = $linked_id;
            }
        }
    }
    $existing_links[] = $post_id; // Exclude self.

    // Extract keywords: use title words + common phrases from content.
    $stop_words = array_flip([
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
        'by', 'from', 'is', 'it', 'as', 'are', 'was', 'were', 'be', 'been', 'being', 'have',
        'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might',
        'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'we', 'they', 'my', 'your',
        'his', 'her', 'its', 'our', 'their', 'not', 'no', 'so', 'if', 'can', 'all', 'each',
        'which', 'when', 'what', 'how', 'who', 'where', 'why', 'than', 'then', 'just', 'also',
        'more', 'very', 'about', 'up', 'out', 'into', 'over', 'after', 'before', 'between',
    ]);

    $words = preg_split('/[\s,.;:!?\-\/\(\)\[\]"\']+/', strtolower($title . ' ' . $content));
    $words = array_filter($words, function ($w) use ($stop_words) {
        return mb_strlen($w) >= 3 && !isset($stop_words[$w]) && !is_numeric($w);
    });

    // Count word frequency.
    $freq = array_count_values($words);
    arsort($freq);

    // Take top keywords (those appearing more than once, up to 30).
    $keywords = [];
    foreach ($freq as $word => $count) {
        if ($count >= 2 && count($keywords) < 30) {
            $keywords[] = $word;
        }
    }
    // Always include title words.
    $title_words = preg_split('/[\s,.;:!?\-\/\(\)\[\]"\']+/', strtolower($title));
    $title_words = array_filter($title_words, function ($w) use ($stop_words) {
        return mb_strlen($w) >= 3 && !isset($stop_words[$w]);
    });
    foreach ($title_words as $tw) {
        if (!in_array($tw, $keywords, true)) {
            $keywords[] = $tw;
        }
    }

    if (empty($keywords)) {
        return [
            'post_id' => $post_id,
            'post_title' => $title,
            'existing_internal_links' => count($existing_links) - 1,
            'suggestions' => [],
        ];
    }

    // Search for other posts matching these keywords.
    $candidates = [];

    foreach (array_chunk($keywords, 5) as $keyword_chunk) {
        $search_term = implode(' ', $keyword_chunk);
        $search_query = new WP_Query([
            'post_type' => 'any',
            'post_status' => 'publish',
            's' => $search_term,
            'posts_per_page' => 20,
            'post__not_in' => $existing_links,
        ]);

        foreach ($search_query->posts as $candidate) {
            if (isset($candidates[$candidate->ID])) {
                continue;
            }

            $candidate_title_lower = strtolower(get_the_title($candidate));
            $candidate_content_lower = strtolower(wp_strip_all_tags($candidate->post_content));
            $matching_kw = [];

            foreach ($keywords as $kw) {
                if (str_contains($candidate_title_lower, $kw) || substr_count($candidate_content_lower, $kw) >= 2) {
                    $matching_kw[] = $kw;
                }
            }

            if (!empty($matching_kw)) {
                $candidates[$candidate->ID] = [
                    'target_post_id' => $candidate->ID,
                    'target_title' => get_the_title($candidate),
                    'target_url' => get_permalink($candidate),
                    'matching_keywords' => array_values(array_unique($matching_kw)),
                    'suggested_anchor_text' => get_the_title($candidate),
                    '_score' => count($matching_kw),
                ];
            }
        }
    }

    // Sort by score descending.
    usort($candidates, function ($a, $b) {
        return $b['_score'] <=> $a['_score'];
    });

    // Remove internal score and limit.
    $suggestions = [];
    foreach (array_slice($candidates, 0, $max_suggestions) as $c) {
        unset($c['_score']);
        $suggestions[] = $c;
    }

    return [
        'post_id' => $post_id,
        'post_title' => $title,
        'existing_internal_links' => count($existing_links) - 1,
        'suggestions' => $suggestions,
    ];
}
