jQuery(document).ready(function ($) {

    // AI Provider card selection
    $(".seopress-ai-providers input[type='radio']").on("change focus", function (e) {
        $(".seopress-ai-provider").removeClass("active");
        $(this).closest(".seopress-ai-provider").addClass("active");
    });

    SEOPRESSMedia = {
        generateImageMeta: function (postId, lang = '', fields = '') {
            if (!postId) return;
            const field = document.querySelector('#seopress-ai-generate-image-meta');
            const button = field.querySelector('button.seopress-ai-generate-image-meta');
            const spinner = field.querySelector('.spinner');
            const errors = field.querySelector('.seopress-error');

            spinner.style.visibility = 'visible';
            button.setAttribute('disabled', true);
            errors.style.display = 'none';

            var data = {
                action: "seopress_ai_generate_seo_meta",
                post_id: postId,
                meta: 'image_meta',
                lang: lang,
                _ajax_nonce: seopressAjaxAIMetaSEO.seopress_nonce,
            };
            if (fields) {
                data.fields = fields;
            }

            $.ajax({
                method: "POST",
                url: seopressAjaxAIMetaSEO.seopress_ai_generate_seo_meta,
                data: data,
                success: function (response) {
                    spinner.style.visibility = 'hidden';
                    button.removeAttribute('disabled');
                    if (response.success) {
                        // Update alt text field
                        const altTextarea = document.querySelector('#attachment-details-two-column-alt-text, #attachment-details-alt-text, #attachment_alt');
                        if (altTextarea && response.data.alt_text) {
                            altTextarea.value = response.data.alt_text;
                            // Trigger change event for media modal to detect the update
                            altTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                        }

                        // Update caption field
                        const captionTextarea = document.querySelector('#attachment-details-two-column-caption, #attachment-details-caption, #attachment_caption');
                        if (captionTextarea && response.data.caption) {
                            captionTextarea.value = response.data.caption;
                            captionTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                        }

                        // Update description field
                        const descTextarea = document.querySelector('#attachment-details-two-column-description, #attachment-details-description, #attachment_content');
                        if (descTextarea && response.data.description) {
                            descTextarea.value = response.data.description;
                            descTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    } else {
                        errors.textContent = response.data.message;
                        errors.style.display = 'block';
                    }
                }
            });
        },

        // Generate a single field (alt_text, caption, or description)
        // btnElement is the button that was clicked (passed directly to avoid DOM query ambiguity)
        generateSingleField: function (postId, lang, fieldName, btnElement) {
            if (!postId || !fieldName) return;

            // Field name to DOM selector mapping
            var fieldSelectors = {
                'alt_text': '#attachment-details-two-column-alt-text, #attachment-details-alt-text, #attachment_alt',
                'caption': '#attachment-details-two-column-caption, #attachment-details-caption, #attachment_caption',
                'description': '#attachment-details-two-column-description, #attachment-details-description, #attachment_content'
            };

            // Show loading state on the clicked element
            var btn = btnElement || document.querySelector('.seopress-ai-field-btn[data-field="' + fieldName + '"]');
            if (btn && btn.tagName !== 'A') {
                btn.setAttribute('disabled', true);
                var spinner = btn.querySelector('.spinner');
                if (spinner) spinner.style.visibility = 'visible';
                var svg = btn.querySelector('svg');
                if (svg) svg.style.display = 'none';
            }
            // For <a> links, the click handler already set "Generating..."

            $.ajax({
                method: "POST",
                url: seopressAjaxAIMetaSEO.seopress_ai_generate_seo_meta,
                data: {
                    action: "seopress_ai_generate_seo_meta",
                    post_id: postId,
                    meta: 'image_meta',
                    fields: fieldName,
                    lang: lang || '',
                    _ajax_nonce: seopressAjaxAIMetaSEO.seopress_nonce,
                },
                success: function (response) {
                    if (response.success && response.data[fieldName]) {
                        var textarea = document.querySelector(fieldSelectors[fieldName]);
                        if (textarea) {
                            textarea.value = response.data[fieldName];
                            textarea.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                        SEOPRESSMedia._resetFieldBtn(btn, 'success');
                    } else {
                        SEOPRESSMedia._resetFieldBtn(btn, 'error');
                    }
                },
                error: function () {
                    SEOPRESSMedia._resetFieldBtn(btn, 'error');
                }
            });
        },

        // Reset a per-field button after AJAX completes
        _resetFieldBtn: function (btn, status) {
            if (!btn) return;
            if (btn.tagName === 'A') {
                btn.style.pointerEvents = '';
                if (status === 'error') {
                    btn.textContent = 'Failed';
                    btn.style.color = '#d63638';
                    setTimeout(function () {
                        btn.textContent = btn._originalText || 'Generate with AI';
                        btn.style.color = '';
                    }, 2000);
                } else {
                    btn.textContent = btn._originalText || 'Generate with AI';
                }
            } else {
                btn.removeAttribute('disabled');
                var spinner = btn.querySelector('.spinner');
                if (spinner) spinner.style.visibility = 'hidden';
                var svg = btn.querySelector('svg');
                if (svg) svg.style.display = '';
            }
        },

        // Keep legacy function for backward compatibility
        generateAltText: function (postId, lang = '') {
            this.generateImageMeta(postId, lang);
        },

        // SVG icon for per-field AI buttons
        fieldButtonSvg: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;transition:transform 0.2s ease-in-out;"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>',

        // Create a per-field AI button element
        createFieldButton: function (fieldName, postId, lang) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'seopress-ai-field-btn';
            button.setAttribute('data-field', fieldName);
            button.title = 'Generate with AI';
            button.style.cssText = 'border:none;background:transparent;padding:2px 4px;cursor:pointer;vertical-align:middle;margin-left:4px;line-height:1;';
            button.innerHTML = this.fieldButtonSvg + '<span class="spinner" style="visibility:hidden;float:none;margin:0 0 0 2px;width:14px;height:14px;"></span>';
            button.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                SEOPRESSMedia.generateSingleField(postId, lang, fieldName, this);
            });
            button.addEventListener('mouseover', function () {
                this.style.backgroundColor = '#f0f0f1';
                this.style.borderRadius = '4px';
                var svg = this.querySelector('svg');
                if (svg) svg.style.transform = 'rotate(-15deg) scale(1.1)';
            });
            button.addEventListener('mouseout', function () {
                this.style.backgroundColor = 'transparent';
                this.style.borderRadius = '0';
                var svg = this.querySelector('svg');
                if (svg) svg.style.transform = 'rotate(0deg) scale(1)';
            });
            return button;
        },

        // Inject per-field AI buttons on attachment edit page (post.php?post=X&action=edit)
        injectFieldButtons: function () {
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') !== 'edit' || !urlParams.get('post')) return;

            var postId = urlParams.get('post');
            var lang = '';

            // Try to get language from existing "generate all" button
            var existingBtn = document.querySelector('button.seopress-ai-generate-image-meta');
            if (existingBtn) {
                var onclickAttr = existingBtn.getAttribute('onclick') || '';
                var langMatch = onclickAttr.match(/generateImageMeta\(\d+,\s*'([^']*)'\)/);
                if (langMatch) {
                    lang = langMatch[1];
                }
            }

            // Labels on the attachment edit page
            var labelSelectors = {
                'alt_text': 'label[for="attachment_alt"]',
                'caption': 'label[for="attachment_caption"]',
                'description': 'label[for="attachment_content"]'
            };

            Object.keys(labelSelectors).forEach(function (fieldName) {
                var label = document.querySelector(labelSelectors[fieldName]);
                if (!label) return;
                if (label.querySelector('.seopress-ai-field-btn')) return;

                var link = document.createElement('a');
                link.href = '#';
                link.className = 'seopress-ai-field-btn';
                link.setAttribute('data-field', fieldName);
                link.textContent = 'Generate with AI';
                link._originalText = 'Generate with AI';
                link.style.cssText = 'font-size:12px;font-weight:normal;margin-left:6px;';
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    link.textContent = 'Generating...';
                    link.style.pointerEvents = 'none';
                    SEOPRESSMedia.generateSingleField(postId, lang, fieldName, link);
                });
                label.appendChild(link);
            });
        },

        // Inject per-field AI buttons in the media modal (upload.php?item=X)
        // Places buttons at the top-right corner of each textarea for cleaner layout
        injectModalFieldButtons: function (container) {
            if (!container) return;

            // Get post ID from the modal's existing "generate all" button
            var existingBtn = container.querySelector('button.seopress-ai-generate-image-meta');
            var postId = null;
            var lang = '';
            if (existingBtn) {
                var onclickAttr = existingBtn.getAttribute('onclick') || '';
                var match = onclickAttr.match(/generateImageMeta\((\d+),\s*'([^']*)'\)/);
                if (match) {
                    postId = match[1];
                    lang = match[2];
                }
            }

            if (!postId) return;

            // Textarea selectors for the modal (two-column and single-column variants)
            var textareaSelectors = {
                'alt_text': [
                    '#attachment-details-two-column-alt-text',
                    '#attachment-details-alt-text'
                ],
                'caption': [
                    '#attachment-details-two-column-caption',
                    '#attachment-details-caption'
                ],
                'description': [
                    '#attachment-details-two-column-description',
                    '#attachment-details-description'
                ]
            };

            // Add a "Generate with AI" link below each field label in the modal.
            // Shows "Generating..." while loading.
            var labelSelectors = {
                'alt_text': [
                    'label[for="attachment-details-two-column-alt-text"]',
                    'label[for="attachment-details-alt-text"]'
                ],
                'caption': [
                    'label[for="attachment-details-two-column-caption"]',
                    'label[for="attachment-details-caption"]'
                ],
                'description': [
                    'label[for="attachment-details-two-column-description"]',
                    'label[for="attachment-details-description"]'
                ]
            };

            Object.keys(labelSelectors).forEach(function (fieldName) {
                var label = null;
                labelSelectors[fieldName].some(function (sel) {
                    label = container.querySelector(sel);
                    return label;
                });
                if (!label) return;
                if (label.querySelector('.seopress-ai-field-btn')) return;

                var br = document.createElement('br');
                var link = document.createElement('a');
                link.href = '#';
                link.className = 'seopress-ai-field-btn';
                link.setAttribute('data-field', fieldName);
                link.textContent = 'Generate with AI';
                link.style.cssText = 'font-size:12px;font-weight:normal;';
                link._originalText = 'Generate with AI';
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    link.textContent = 'Generating...';
                    link.style.pointerEvents = 'none';
                    SEOPRESSMedia.generateSingleField(postId, lang, fieldName, link);
                });
                label.appendChild(br);
                label.appendChild(link);
            });
        }
    }

    // Inject per-field buttons on attachment edit page
    SEOPRESSMedia.injectFieldButtons();

    // Observe DOM for media modal rendering and inject buttons when it appears
    var modalObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                // Look for the attachment details container in the modal
                var details = node.querySelector ? node.querySelector('.attachment-details') : null;
                if (details) {
                    // Small delay to let the modal fully render including the SEOPress "generate all" button
                    setTimeout(function () {
                        SEOPRESSMedia.injectModalFieldButtons(details);
                    }, 200);
                }
                // Also check if the node itself is attachment-details
                if (node.classList && node.classList.contains('attachment-details')) {
                    setTimeout(function () {
                        SEOPRESSMedia.injectModalFieldButtons(node);
                    }, 200);
                }
            });
        });
    });
    modalObserver.observe(document.body, { childList: true, subtree: true });

    // The message may relay the raw error body of the AI provider's HTTP
    // response: insert it as text, never as HTML.
    var showErrorLog = function (message) {
        var $notice = $("<div class='seopress-notice is-error'></div>").append($("<p></p>").text(message));
        $("#seopress_ai_generate_seo_meta_log").empty().append($notice).show();
    };

    // Generate title and meta with AI from SEO metabox
    $('.seopress_ai_generate_seo_meta').on("click", function () {
        var $button = $(this);

        // Swap the wand icon for a spinner while generating, then restore it.
        // The button also stays disabled during the request.
        var $icon = $button.find('svg');
        var $spinner = $('<span class="spinner is-active" style="float:none;margin:0;width:18px;height:18px;background-size:18px 18px;display:inline-block;vertical-align:middle;"></span>');
        var restoreButton = function () {
            $spinner.remove();
            $icon.css("display", "");
            $button.removeAttr("disabled");
        };

        $button.attr("disabled", "disabled");
        $icon.css("display", "none");
        $spinner.appendTo($button);
        $("#seopress_ai_generate_seo_meta_log").hide();

        // Post ID
        var post_id;
        if (typeof $("#seopress-tabs").attr("data_id") !== "undefined") {
            post_id = $("#seopress-tabs").attr("data_id");
        } else if (typeof $("#seopress_content_analysis .wrap-seopress-analysis").attr("data_id") !== "undefined") {
            post_id = $("#seopress_content_analysis .wrap-seopress-analysis").attr("data_id");
        }

        // Locale
        var lang = 'en_US';
        if (typeof $button.attr("data-lang") !== "undefined") {
            lang = $button.attr("data-lang");
        }

        // Meta to generate
        var meta = '';
        if (typeof $button.attr("data_meta") !== "undefined") {
            meta = $button.attr("data_meta");
        }

        // On a term edit screen the same metabox form is reused, but the object is a
        // taxonomy term, not a post. Route to the term endpoint, which builds its own
        // context (term name, description, related post titles).
        var origin = $("#seopress-tabs").attr("data_origin");
        var requestData;
        if (origin === "term") {
            requestData = {
                action: "seopress_ai_generate_term_seo_meta",
                term_id: post_id,
                taxonomy: $("#seopress-tabs").attr("data_tax"),
                lang: lang,
                meta: meta,
                _ajax_nonce: seopressAjaxAIMetaSEO.seopress_nonce,
            };
        } else {
            requestData = {
                action: "seopress_ai_generate_seo_meta",
                post_id: post_id,
                lang: lang,
                meta: meta,
                _ajax_nonce: seopressAjaxAIMetaSEO.seopress_nonce,
            };
        }

        $.ajax({
            method: "POST",
            url: seopressAjaxAIMetaSEO.seopress_ai_generate_seo_meta,
            data: requestData,
            success: function (data) {
                restoreButton();
                if (data.success === true) {
                    if (meta === 'title') {
                        $("#seopress_titles_title_meta").val(data.data.title);
                        $("#seopress_titles_title_meta").trigger("keyup");
                    }
                    if (meta === 'desc') {
                        $("#seopress_titles_desc_meta").val(data.data.desc);
                        $("#seopress_titles_desc_meta").trigger("keyup");
                    }
                    if (meta === 'fb_title') {
                        $("#seopress_social_fb_title_meta").val(data.data.fb_title);
                        $("#seopress_social_fb_title_meta").trigger("keyup");
                    }
                    if (meta === 'fb_desc') {
                        $("#seopress_social_fb_desc_meta").val(data.data.fb_desc);
                        $("#seopress_social_fb_desc_meta").trigger("keyup");
                    }
                    if (meta === 'twitter_title') {
                        $("#seopress_social_twitter_title_meta").val(data.data.twitter_title);
                        $("#seopress_social_twitter_title_meta").trigger("keyup");
                    }
                    if (meta === 'twitter_desc') {
                        $("#seopress_social_twitter_desc_meta").val(data.data.twitter_desc);
                        $("#seopress_social_twitter_desc_meta").trigger("keyup");
                    }
                    if (data.data.message !== 'Success') {
                        showErrorLog(data.data.message);
                    }
                } else {
                    showErrorLog(data.data.message);
                }
            },
            error: function () {
                restoreButton();
            }
        });
    });

    //Check AI license key status
    $('#seopress-open-ai-check-license, #seopress-deepseek-check-license, #seopress-gemini-check-license, #seopress-mistral-check-license, #seopress-claude-check-license').on("click", function () {
        var $button = $(this);
        var $spinner = $button.siblings('.spinner');

        // Get the correct log div based on button ID
        var logId = '';
        if ($button.attr('id') === 'seopress-open-ai-check-license') {
            logId = 'seopress-open-ai-check-license-log';
        } else if ($button.attr('id') === 'seopress-deepseek-check-license') {
            logId = 'seopress-deepseek-check-license-log';
        } else if ($button.attr('id') === 'seopress-gemini-check-license') {
            logId = 'seopress-gemini-check-license-log';
        } else if ($button.attr('id') === 'seopress-mistral-check-license') {
            logId = 'seopress-mistral-check-license-log';
        } else if ($button.attr('id') === 'seopress-claude-check-license') {
            logId = 'seopress-claude-check-license-log';
        }
        var $log = $('#' + logId);

        // Debug: Check if log div is found
        if ($log.length === 0) {
            alert(seopressAjaxAICheckLicense.i18n.log_div_not_found + ' ' + logId);
            return;
        }

        $button.attr("disabled", "disabled");
        $spinner.css("visibility", "visible");
        $spinner.css("float", "none");

        // Determine provider and get appropriate data
        var provider = '';
        var apiKey = '';
        var apiModel = '';

        if ($button.attr('id') === 'seopress-open-ai-check-license') {
            provider = 'openai';
            apiKey = $('#seopress_ai_openai_api_key').val();
            apiModel = $('#seopress_ai_openai_model').val();
        } else if ($button.attr('id') === 'seopress-deepseek-check-license') {
            provider = 'deepseek';
            apiKey = $('#seopress_ai_deepseek_api_key').val();
            apiModel = $('#seopress_ai_deepseek_model').val();
        } else if ($button.attr('id') === 'seopress-gemini-check-license') {
            provider = 'gemini';
            apiKey = $('#seopress_ai_gemini_api_key').val();
            apiModel = $('#seopress_ai_gemini_model').val();
        } else if ($button.attr('id') === 'seopress-mistral-check-license') {
            provider = 'mistral';
            apiKey = $('#seopress_ai_mistral_api_key').val();
            apiModel = $('#seopress_ai_mistral_model').val();
        } else if ($button.attr('id') === 'seopress-claude-check-license') {
            provider = 'claude';
            apiKey = $('#seopress_ai_claude_api_key').val();
            apiModel = $('#seopress_ai_claude_model').val();
        }



        $.ajax({
            method: "POST",
            url: seopressAjaxAICheckLicense.seopress_ai_check_license_key,
            data: {
                action: "seopress_ai_check_license_key",
                seopress_ai_api_key: apiKey,
                seopress_ai_model: apiModel,
                seopress_ai_provider: provider,
                _ajax_nonce: seopressAjaxAICheckLicense.seopress_nonce,
            },
            success: function (data) {
                $spinner.css("visibility", "hidden");
                $button.removeAttr("disabled");
                $log.show();
                
                if (data.success === true && data.data) {
                    if (data.data.code === 'success') {
                        $log.html("<div class='seopress-notice is-success'><p>" + data.data.message + "</p></div>");
                    } else {
                        $log.html("<div class='seopress-notice is-error'><p>" + data.data.message + "</p></div>");
                    }
                } else {
                    $log.html("<div class='seopress-notice is-error'><p>" + seopressAjaxAICheckLicense.i18n.connection_error + "</p></div>");
                }
            },
            error: function (xhr, status, error) {
                $spinner.css("visibility", "hidden");
                $button.removeAttr("disabled");
                $log.show();
                $log.html("<div class='seopress-notice is-error'><p>" + seopressAjaxAICheckLicense.i18n.network_error + "</p></div>");
            }
        });
    });

    //AI bulk actions from post types
    $('body').on('click', '#doaction, #doaction2', async function (e) {
        var action = $('select[name="action"], select[name="action2"]').val();

        const validActions = ['seopress_ai_title', 'seopress_ai_desc', 'seopress_ai_alt_text', 'seopress_ai_alt_text_missing', 'seopress_ai_alt_only', 'seopress_ai_caption_only', 'seopress_ai_description_only']
        if (!validActions.includes(action)) {
            return
        }

        e.preventDefault();


        var postIds = [];
        var postIdsFailed = [];
        $('.wp-list-table tbody input[type="checkbox"]:checked').each(function () {
            postIds.push($(this).val());
        });

        if (postIds.length === 0) {
            return
        }

        $("#doaction, #doaction2").attr("disabled", "disabled");
        $("#doaction, #doaction2").parent().append('<div class="spinner" style="visibility:visible"></div>');


        let ajaxAction
        if (action === 'seopress_ai_desc') {
            ajaxAction = 'seopress_bulk_action_ai_desc'
        } else if (action === 'seopress_ai_title') {
            ajaxAction = 'seopress_bulk_action_ai_title'
        } else if (action === 'seopress_ai_alt_text') {
            ajaxAction = 'seopress_bulk_action_ai_alt_text'
        } else if (action === 'seopress_ai_alt_text_missing') {
            ajaxAction = 'seopress_bulk_action_ai_alt_text_missing'
        } else if (action === 'seopress_ai_alt_only') {
            ajaxAction = 'seopress_bulk_action_ai_alt_only'
        } else if (action === 'seopress_ai_caption_only') {
            ajaxAction = 'seopress_bulk_action_ai_caption_only'
        } else if (action === 'seopress_ai_description_only') {
            ajaxAction = 'seopress_bulk_action_ai_description_only'
        }

        const currentUrl = new URL(window.location.href);
        let lang = currentUrl.searchParams.get('lang');
        if (lang === 'all') {
            lang = null
        }

        // A failed request (rejected security token, 403, 500, timeout, network
        // error) rejects the awaited promise. Without handling it, this async
        // handler aborts silently and the spinner added above keeps turning
        // forever, with no feedback at all. So every failure is handled below.
        const i18n = (typeof seopressAjaxAIMetaSEO !== 'undefined' && seopressAjaxAIMetaSEO.i18n) ? seopressAjaxAIMetaSEO.i18n : {};

        const removeBulkSpinner = function () {
            $('#doaction, #doaction2').parent().find('.spinner').remove();
        };

        const showBulkError = function (message) {
            removeBulkSpinner();

            const $message = $('<p></p>')
                .css({ display: 'flex', 'align-items': 'center', gap: '12px', 'flex-wrap': 'wrap', margin: '0.5em 0' })
                .append($('<span></span>').text(message));

            // Link to the AI tab, where the failed generation is logged
            // (Provider / status code / response body), so the notice is
            // actionable instead of just stating the failure.
            const logsUrl = (typeof seopressAjaxAIMetaSEO !== 'undefined') ? seopressAjaxAIMetaSEO.ai_logs_url : '';
            if (logsUrl) {
                $message.append(
                    $('<a class="button"></a>')
                        .attr('href', logsUrl)
                        .text(i18n.check_logs || 'Check out logs')
                );
            }

            const $notice = $('<div class="notice notice-error is-dismissible"></div>').append($message);

            // Place the notice in WordPress's standard slot, right below the
            // page title, so core styles and aligns it like any admin notice,
            // instead of prepending it above the <h1> where it looked
            // misaligned and detached from the screen.
            const $headerEnd = $('.wrap > hr.wp-header-end').first();
            if ($headerEnd.length) {
                $headerEnd.after($notice);
            } else {
                $('.wrap > h1').first().after($notice);
            }
        };

        let aborted = false;

        // The reason the server gave for the first failure ("Could not fetch
        // the image", an API status code, ...). It is what support needs, and
        // it beats the generic message shown when nothing could be generated.
        let failureReason = '';

        for (const postId of postIds) {
            let response;

            try {
                response = await $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: ajaxAction,
                        lang: lang,
                        post_id: postId,
                        _ajax_nonce: $('#_wpnonce').val(),
                    },
                });
            } catch (xhr) {
                // A rejected security token makes every remaining request fail
                // the same way, so stop here and tell the user what to do.
                if (xhr && xhr.status === 403) {
                    showBulkError(i18n.bulk_token_expired || 'Your security token has expired. Please reload the page and run the bulk action again.');
                    aborted = true;
                    break;
                }

                postIdsFailed.push(postId);
                continue;
            }

            // An error payload (wp_send_json_error) has no generated field, so
            // count it as failed instead of reading undefined properties. It
            // carries the reason the generation failed: keep it.
            if (!response || !response.success || !response.data) {
                if (!failureReason && response && response.data && response.data.message) {
                    failureReason = response.data.message;
                }
                postIdsFailed.push(postId);
                continue;
            }

            switch (ajaxAction) {
                case 'seopress_bulk_action_ai_desc':
                    if (response.data.desc === "") {
                        postIdsFailed.push(postId);
                    }
                    break;
                case 'seopress_bulk_action_ai_title':
                    if (response.data.title === "") {
                        postIdsFailed.push(postId);
                    }
                    break;
                case 'seopress_bulk_action_ai_alt_text':
                case 'seopress_bulk_action_ai_alt_text_missing':
                    // Check if alt_text was generated (the main field)
                    if (!response.data || response.data.alt_text === "") {
                        postIdsFailed.push(postId);
                    }
                    break;
                case 'seopress_bulk_action_ai_alt_only':
                    if (!response.data || response.data.alt_text === "") {
                        postIdsFailed.push(postId);
                    }
                    break;
                case 'seopress_bulk_action_ai_caption_only':
                    if (!response.data || response.data.caption === "") {
                        postIdsFailed.push(postId);
                    }
                    break;
                case 'seopress_bulk_action_ai_description_only':
                    if (!response.data || response.data.description === "") {
                        postIdsFailed.push(postId);
                    }
                    break;
            }
        }

        if (aborted) {
            return;
        }

        // Nothing could be generated at all: reloading would hide the reason,
        // so surface an explicit error instead. When the server said why, show
        // that rather than the generic sentence.
        if (postIds.length > 0 && postIdsFailed.length === postIds.length) {
            if (failureReason) {
                const template = i18n.bulk_request_error_reason || 'The bulk AI generation could not be completed: %s';
                // Replacement passed as a function: a provider message
                // containing "$&" or "$'" would otherwise be read as a
                // substitution pattern.
                showBulkError(template.replace('%s', function () { return failureReason; }));
            } else {
                showBulkError(i18n.bulk_request_error || 'The bulk AI generation could not be completed. Please try again.');
            }
            return;
        }

        removeBulkSpinner();

        currentUrl.searchParams.set('bulk_ai_posts', postIds.length - postIdsFailed.length);
        currentUrl.searchParams.set('bulk_ai_posts_failed', postIdsFailed.length);

        window.history.replaceState({}, '', currentUrl);
        window.location.reload();

    });
})
