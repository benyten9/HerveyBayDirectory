/**
 * HBL Events Admin JavaScript
 */
(function($) {
    'use strict';

    // Delete event handler
    $(document).on('click', '.hbl-delete-event', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var eventId = $button.data('event-id');
        var $row = $button.closest('tr');
        var eventTitle = $row.find('.hbl-event-title a').text();

        // Create confirmation modal
        var confirmHtml = `
            <div class="hbl-delete-confirm">
                <div class="hbl-delete-confirm-box">
                    <h3>Delete Event?</h3>
                    <p>Are you sure you want to delete "<strong>${eventTitle}</strong>"? This action cannot be undone.</p>
                    <div class="button-group">
                        <button type="button" class="button hbl-confirm-cancel">Cancel</button>
                        <button type="button" class="button button-primary hbl-confirm-delete" style="background: #d63638; border-color: #d63638;">Delete</button>
                    </div>
                </div>
            </div>
        `;

        var $modal = $(confirmHtml).appendTo('body');

        // Cancel handler
        $modal.on('click', '.hbl-confirm-cancel, .hbl-delete-confirm', function(e) {
            if (e.target === this) {
                $modal.remove();
            }
        });

        // Delete handler
        $modal.on('click', '.hbl-confirm-delete', function() {
            var $deleteBtn = $(this);
            $deleteBtn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: hblEventsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hbl_admin_delete_event',
                    nonce: hblEventsAdmin.nonce,
                    event_id: eventId
                },
                success: function(response) {
                    if (response.success) {
                        $modal.remove();
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            
                            // Check if table is empty
                            if ($('.hbl-events-table tbody tr').length === 0) {
                                var emptyHtml = `
                                    <tr>
                                        <td colspan="9" class="hbl-no-events">
                                            <div class="hbl-no-events-message">
                                                <span class="dashicons dashicons-calendar"></span>
                                                <p>No events found.</p>
                                            </div>
                                        </td>
                                    </tr>
                                `;
                                $('.hbl-events-table tbody').html(emptyHtml);
                            }

                            // Update stats
                            updateStats();
                        });
                    } else {
                        alert(response.data.message || 'Failed to delete event');
                        $modal.remove();
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $modal.remove();
                }
            });
        });
    });

    // Update stats after deletion
    function updateStats() {
        // Recalculate total from visible rows
        var totalEvents = $('.hbl-events-table tbody tr.hbl-event-row').length;
        var upcomingEvents = $('.hbl-events-table tbody tr.is-upcoming').length;
        var freeEvents = $('.hbl-events-table tbody .hbl-badge-free').length;
        var paidEvents = $('.hbl-events-table tbody .hbl-badge-paid').length - freeEvents; // Subtract because free is also counted

        // Update stat cards
        $('.hbl-stat-total .hbl-stat-number').text(totalEvents);
        $('.hbl-stat-upcoming .hbl-stat-number').text(upcomingEvents);
        // Note: Free/Paid counts are per-page only, full reload needed for accurate counts
    }

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // ESC to close modals
        if (e.keyCode === 27) {
            $('.hbl-delete-confirm').remove();
        }
    });

    // Initialize on page load
    $(document).ready(function() {
        // Animate chart bars only (keep stats static for accuracy)
        $('.hbl-chart-bar-fill').each(function() {
            var $this = $(this);
            var width = $this.css('width');
            $this.css('width', '0');
            setTimeout(function() {
                $this.css('width', width);
            }, 500);
        });
    });

    // =====================
    // Bulk Actions
    // =====================

    // Select all checkboxes
    $(document).on('change', '.hbl-select-all', function() {
        var isChecked = $(this).prop('checked');
        $('.hbl-event-checkbox').prop('checked', isChecked);
        $('.hbl-select-all').prop('checked', isChecked);
        updateSelectedCount();
    });

    // Individual checkbox change
    $(document).on('change', '.hbl-event-checkbox', function() {
        var totalCheckboxes = $('.hbl-event-checkbox').length;
        var checkedCheckboxes = $('.hbl-event-checkbox:checked').length;
        
        // Update select all checkbox
        $('.hbl-select-all').prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
        updateSelectedCount();
    });

    // Update selected count display
    function updateSelectedCount() {
        var count = $('.hbl-event-checkbox:checked').length;
        $('.hbl-selected-count-number').text(count);
        
        // Toggle visibility of selected count
        if (count > 0) {
            $('.hbl-selected-count').addClass('has-selection');
        } else {
            $('.hbl-selected-count').removeClass('has-selection');
        }
    }

    // Get selected event IDs
    function getSelectedEventIds() {
        var ids = [];
        $('.hbl-event-checkbox:checked').each(function() {
            ids.push($(this).val());
        });
        return ids;
    }

    // Apply bulk action
    $(document).on('click', '.hbl-apply-bulk-action', function() {
        var position = $(this).data('position');
        var action = $('#bulk-action-selector-' + position).val();
        var eventIds = getSelectedEventIds();

        if (action === '-1') {
            alert('Please select a bulk action.');
            return;
        }

        if (eventIds.length === 0) {
            alert('Please select at least one event.');
            return;
        }

        if (action === 'delete') {
            handleBulkDelete(eventIds);
        } else if (['publish', 'pending', 'draft'].includes(action)) {
            handleBulkStatusUpdate(eventIds, action);
        }
    });

    // Handle bulk delete
    function handleBulkDelete(eventIds) {
        var confirmHtml = `
            <div class="hbl-delete-confirm hbl-bulk-confirm">
                <div class="hbl-delete-confirm-box">
                    <h3>Delete ${eventIds.length} Event${eventIds.length > 1 ? 's' : ''}?</h3>
                    <p>Are you sure you want to delete <strong>${eventIds.length}</strong> selected event${eventIds.length > 1 ? 's' : ''}? This action cannot be undone.</p>
                    <div class="button-group">
                        <button type="button" class="button hbl-confirm-cancel">Cancel</button>
                        <button type="button" class="button button-primary hbl-confirm-bulk-delete" style="background: #d63638; border-color: #d63638;">Delete All</button>
                    </div>
                </div>
            </div>
        `;

        var $modal = $(confirmHtml).appendTo('body');

        // Cancel handler
        $modal.on('click', '.hbl-confirm-cancel, .hbl-bulk-confirm', function(e) {
            if (e.target === this) {
                $modal.remove();
            }
        });

        // Delete handler
        $modal.on('click', '.hbl-confirm-bulk-delete', function() {
            var $deleteBtn = $(this);
            $deleteBtn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: hblEventsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hbl_admin_bulk_delete_events',
                    nonce: hblEventsAdmin.nonce,
                    event_ids: eventIds
                },
                success: function(response) {
                    if (response.success) {
                        $modal.remove();
                        
                        // Remove deleted rows
                        eventIds.forEach(function(id) {
                            $('tr[data-event-id="' + id + '"]').fadeOut(300, function() {
                                $(this).remove();
                            });
                        });

                        // Show success notice
                        showNotice('success', response.data.message);

                        // Reset checkboxes
                        setTimeout(function() {
                            $('.hbl-select-all').prop('checked', false);
                            updateSelectedCount();
                            
                            // Check if table is empty
                            if ($('.hbl-events-table tbody tr.hbl-event-row').length === 0) {
                                location.reload();
                            }
                        }, 350);

                        // Update stats
                        updateStats();
                    } else {
                        alert(response.data.message || 'Failed to delete events');
                        $modal.remove();
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $modal.remove();
                }
            });
        });
    }

    // Handle bulk status update
    function handleBulkStatusUpdate(eventIds, status) {
        var statusLabels = {
            'publish': 'Published',
            'pending': 'Pending',
            'draft': 'Draft'
        };

        var confirmHtml = `
            <div class="hbl-delete-confirm hbl-bulk-confirm">
                <div class="hbl-delete-confirm-box">
                    <h3>Update ${eventIds.length} Event${eventIds.length > 1 ? 's' : ''}?</h3>
                    <p>Are you sure you want to set <strong>${eventIds.length}</strong> selected event${eventIds.length > 1 ? 's' : ''} to <strong>${statusLabels[status]}</strong>?</p>
                    <div class="button-group">
                        <button type="button" class="button hbl-confirm-cancel">Cancel</button>
                        <button type="button" class="button button-primary hbl-confirm-bulk-update">Update All</button>
                    </div>
                </div>
            </div>
        `;

        var $modal = $(confirmHtml).appendTo('body');

        // Cancel handler
        $modal.on('click', '.hbl-confirm-cancel, .hbl-bulk-confirm', function(e) {
            if (e.target === this) {
                $modal.remove();
            }
        });

        // Update handler
        $modal.on('click', '.hbl-confirm-bulk-update', function() {
            var $updateBtn = $(this);
            $updateBtn.prop('disabled', true).text('Updating...');

            $.ajax({
                url: hblEventsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hbl_admin_bulk_update_events',
                    nonce: hblEventsAdmin.nonce,
                    event_ids: eventIds,
                    status: status
                },
                success: function(response) {
                    if (response.success) {
                        $modal.remove();
                        
                        // Show success notice and reload
                        showNotice('success', response.data.message);

                        // Reload page after a short delay to show updated data
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        alert(response.data.message || 'Failed to update events');
                        $modal.remove();
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $modal.remove();
                }
            });
        });
    }

    // Show admin notice
    function showNotice(type, message) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var noticeHtml = `
            <div class="notice ${noticeClass} is-dismissible hbl-admin-notice">
                <p>${message}</p>
                <button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
            </div>
        `;
        
        $('.hbl-events-admin h1').after(noticeHtml);
        
        // Auto dismiss after 5 seconds
        setTimeout(function() {
            $('.hbl-admin-notice').fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Notice dismiss handler
    $(document).on('click', '.hbl-admin-notice .notice-dismiss', function() {
        $(this).closest('.notice').fadeOut(300, function() {
            $(this).remove();
        });
    });

    // =====================
    // Dynamic Filters (AJAX)
    // =====================

    var filterXhr = null;
    var searchTimer = null;

    function getFilterParams( paged ) {
        var $form = $('.hbl-filter-form');
        return {
            action:           'hbl_admin_filter_events',
            nonce:            hblEventsAdmin.nonce,
            s:                $form.find('[name="s"]').val() || '',
            category_id:      $form.find('[name="category_id"]').val() || '',
            event_cost:       $form.find('[name="event_cost"]').val() || '',
            event_frequency:  $form.find('[name="event_frequency"]').val() || '',
            organiser_type:   $form.find('[name="organiser_type"]').val() || '',
            author_id:        $form.find('[name="author_id"]').val() || '',
            paged:            paged || 1
        };
    }

    function doFilterRequest( paged ) {
        if ( filterXhr ) {
            filterXhr.abort();
        }

        var $wrap    = $('#hbl-table-wrap');
        var $loading = $('#hbl-table-loading');
        var $tbody   = $('#hbl-events-tbody');

        $loading.show();
        $tbody.css('opacity', 0.4);

        filterXhr = $.ajax({
            url:  hblEventsAdmin.ajaxUrl,
            type: 'POST',
            data: getFilterParams( paged ),
            success: function( response ) {
                if ( ! response.success ) return;

                $tbody.html( response.data.rows );
                $tbody.css('opacity', 1);

                // Pagination
                var $pager = $('#hbl-events-pagination');
                if ( response.data.pagination ) {
                    $pager.html( response.data.pagination ).show();
                } else {
                    $pager.html('').hide();
                }

                // Results count
                var total = parseInt( response.data.total, 10 );
                var label = total === 1 ? '1 event' : total + ' events';
                $('#hbl-results-count').text( label );

                // Reset select-all checkbox
                $('.hbl-select-all').prop('checked', false);
                updateSelectedCount();
            },
            error: function( xhr ) {
                if ( xhr.statusText !== 'abort' ) {
                    $tbody.css('opacity', 1);
                }
            },
            complete: function() {
                $loading.hide();
                filterXhr = null;
            }
        });
    }

    // Select/dropdown filters — fire immediately on change
    $(document).on('change', '.hbl-filter-form select', function() {
        doFilterRequest(1);
    });

    // Search input — debounce 350ms
    $(document).on('input', '.hbl-filter-form input[name="s"]', function() {
        clearTimeout( searchTimer );
        searchTimer = setTimeout(function() {
            doFilterRequest(1);
        }, 350);
    });

    // Prevent normal form submit (filters are now handled via AJAX)
    $(document).on('submit', '.hbl-filter-form', function(e) {
        e.preventDefault();
        doFilterRequest(1);
    });

    // Reset button
    $(document).on('click', '.hbl-filter-form a.button', function(e) {
        e.preventDefault();
        var $form = $('.hbl-filter-form');
        $form.find('input[name="s"]').val('');
        $form.find('select').val('');
        doFilterRequest(1);
    });

    // Pagination clicks — AJAX instead of page reload
    $(document).on('click', '#hbl-events-pagination .page-numbers', function(e) {
        e.preventDefault();
        var href   = $(this).attr('href') || '';
        var match  = href.match(/[?&]paged=(\d+)/);
        var paged  = match ? parseInt( match[1], 10 ) : 1;
        doFilterRequest( paged );
        // Scroll to table top smoothly
        $('html, body').animate({ scrollTop: $('#hbl-table-wrap').offset().top - 40 }, 200);
    });

    // Filter form enhancement (legacy: search input hint)

    // =====================
    // Category Management
    // =====================

    // Color picker preview
    $(document).on('input', 'input[type="color"]', function() {
        var color = $(this).val();
        $(this).siblings('.hbl-color-value').text(color);
    });

    // Add category form submission
    $('#hbl-add-category-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var originalText = $submitBtn.html();
        
        $submitBtn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Adding...');
        
        $.ajax({
            url: hblEventsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hbl_admin_add_category',
                nonce: hblEventsAdmin.nonce,
                name: $form.find('#category_name').val(),
                slug: $form.find('#category_slug').val(),
                parent: $form.find('#category_parent').val(),
                description: $form.find('#category_description').val(),
                color: $form.find('#category_color').val(),
                icon: $form.find('#category_icon').val()
            },
            success: function(response) {
                if (response.success) {
                    // Reload page to show new category
                    window.location.reload();
                } else {
                    alert(response.data.message || 'Failed to add category');
                    $submitBtn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $submitBtn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Edit category - open modal
    $(document).on('click', '.hbl-edit-category', function() {
        var $button = $(this);
        var $modal = $('#hbl-edit-category-modal');
        
        // Populate form
        $modal.find('#edit_category_id').val($button.data('id'));
        $modal.find('#edit_category_name').val($button.data('name'));
        $modal.find('#edit_category_slug').val($button.data('slug'));
        $modal.find('#edit_category_parent').val($button.data('parent'));
        $modal.find('#edit_category_description').val($button.data('description'));
        $modal.find('#edit_category_color').val($button.data('color'));
        $modal.find('#edit_category_color').siblings('.hbl-color-value').text($button.data('color'));
        $modal.find('#edit_category_icon').val($button.data('icon'));
        
        // Show modal
        $modal.show();
    });

    // Close modal
    $(document).on('click', '.hbl-modal-close, .hbl-modal-cancel, .hbl-modal-overlay', function() {
        $('#hbl-edit-category-modal').hide();
    });

    // Edit category form submission
    $('#hbl-edit-category-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var originalText = $submitBtn.html();
        
        $submitBtn.prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: hblEventsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hbl_admin_edit_category',
                nonce: hblEventsAdmin.nonce,
                term_id: $form.find('#edit_category_id').val(),
                name: $form.find('#edit_category_name').val(),
                slug: $form.find('#edit_category_slug').val(),
                parent: $form.find('#edit_category_parent').val(),
                description: $form.find('#edit_category_description').val(),
                color: $form.find('#edit_category_color').val(),
                icon: $form.find('#edit_category_icon').val()
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data.message || 'Failed to update category');
                    $submitBtn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $submitBtn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Delete category
    $(document).on('click', '.hbl-delete-category', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var categoryId = $button.data('id');
        var categoryName = $button.data('name');
        var $row = $button.closest('tr');

        var confirmHtml = `
            <div class="hbl-delete-confirm">
                <div class="hbl-delete-confirm-box">
                    <h3>Delete Category?</h3>
                    <p>Are you sure you want to delete "<strong>${categoryName}</strong>"? Events in this category will become uncategorized.</p>
                    <div class="button-group">
                        <button type="button" class="button hbl-confirm-cancel">Cancel</button>
                        <button type="button" class="button button-primary hbl-confirm-delete-cat" style="background: #d63638; border-color: #d63638;">Delete</button>
                    </div>
                </div>
            </div>
        `;

        var $modal = $(confirmHtml).appendTo('body');

        $modal.on('click', '.hbl-confirm-cancel, .hbl-delete-confirm', function(e) {
            if (e.target === this) {
                $modal.remove();
            }
        });

        $modal.on('click', '.hbl-confirm-delete-cat', function() {
            var $deleteBtn = $(this);
            $deleteBtn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: hblEventsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hbl_admin_delete_category',
                    nonce: hblEventsAdmin.nonce,
                    term_id: categoryId
                },
                success: function(response) {
                    if (response.success) {
                        $modal.remove();
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            
                            // Update count
                            var $countSpan = $('.hbl-categories-list-card h2 .hbl-count');
                            var currentCount = parseInt($countSpan.text().replace(/[()]/g, ''), 10);
                            $countSpan.text('(' + Math.max(0, currentCount - 1) + ')');
                            
                            // Show empty state if no categories
                            if ($('.hbl-categories-table tbody tr').length === 0) {
                                $('.hbl-categories-table').replaceWith(`
                                    <div class="hbl-no-categories">
                                        <span class="dashicons dashicons-category"></span>
                                        <p>No event categories found. Create your first category using the form.</p>
                                    </div>
                                `);
                            }
                        });
                    } else {
                        alert(response.data.message || 'Failed to delete category');
                        $modal.remove();
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $modal.remove();
                }
            });
        });
    });

    // ESC to close modals
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) {
            $('.hbl-delete-confirm').remove();
            $('#hbl-edit-category-modal').hide();
            $('#hbl-edit-tag-modal').hide();
        }
    });

    // =========================================================
    // EVENT TAGS
    // =========================================================

    // Add new tag
    $('#hbl-add-tag-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text(hblEventsAdmin.saving || 'Saving...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'hbl_admin_add_tag',
                nonce: hblEventsAdmin.nonce,
                name: $form.find('#tag_name').val(),
                slug: $form.find('#tag_slug').val(),
                description: $form.find('#tag_description').val()
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error creating tag');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add Tag');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add Tag');
            }
        });
    });

    // Edit tag - open modal
    $(document).on('click', '.hbl-edit-tag', function() {
        var $button = $(this);
        var $modal = $('#hbl-edit-tag-modal');

        $modal.find('#edit_tag_id').val($button.data('id'));
        $modal.find('#edit_tag_name').val($button.data('name'));
        $modal.find('#edit_tag_slug').val($button.data('slug'));
        $modal.find('#edit_tag_description').val($button.data('description'));

        $modal.show();
    });

    // Close tag modal
    $(document).on('click', '#hbl-edit-tag-modal .hbl-modal-close, #hbl-edit-tag-modal .hbl-modal-cancel, #hbl-edit-tag-modal .hbl-modal-overlay', function() {
        $('#hbl-edit-tag-modal').hide();
    });

    // Submit edit tag
    $('#hbl-edit-tag-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text(hblEventsAdmin.saving || 'Saving...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'hbl_admin_edit_tag',
                nonce: hblEventsAdmin.nonce,
                term_id: $form.find('#edit_tag_id').val(),
                name: $form.find('#edit_tag_name').val(),
                slug: $form.find('#edit_tag_slug').val(),
                description: $form.find('#edit_tag_description').val()
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error updating tag');
                    $btn.prop('disabled', false).text('Update Tag');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $btn.prop('disabled', false).text('Update Tag');
            }
        });
    });

    // Delete tag
    $(document).on('click', '.hbl-delete-tag', function() {
        var $button = $(this);
        var tagId = $button.data('id');
        var tagName = $button.data('name');

        var $confirm = $('<div class="hbl-delete-confirm"><p>Delete tag <strong>' + tagName + '</strong>?</p><button class="button button-primary hbl-confirm-delete-tag" data-id="' + tagId + '">Delete</button> <button class="button hbl-cancel-delete">Cancel</button></div>');
        $button.closest('td').append($confirm);
    });

    $(document).on('click', '.hbl-confirm-delete-tag', function() {
        var tagId = $(this).data('id');
        var $row = $(this).closest('tr');
        var $confirm = $(this).closest('.hbl-delete-confirm');
        $confirm.remove();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'hbl_admin_delete_tag',
                nonce: hblEventsAdmin.nonce,
                term_id: tagId
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert(response.data.message || 'Error deleting tag');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            }
        });
    });

})(jQuery);

