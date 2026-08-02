/**
 * HBL Bulk Plan Reassign JavaScript
 */

(function ($) {
	'use strict';

	var HBLBulkPlanReassign = {

		selectedListings: {},   // { id: { id, title, planName } }
		currentPage: 1,
		totalPages: 1,
		isLoading: false,

		init: function () {
			this.bindEvents();
			this.updateSummary();
		},

		bindEvents: function () {
			var self = this;

			// Load listings button
			$('#hbl-bpr-load-listings').on('click', function () {
				self.currentPage = 1;
				self.loadListings();
			});

			// Enter key in search field
			$('#hbl-bpr-search').on('keydown', function (e) {
				if (e.key === 'Enter') {
					self.currentPage = 1;
					self.loadListings();
				}
			});

			// Select-all checkbox (current page only)
			$(document).on('change', '#hbl-bpr-select-all', function () {
				var checked = $(this).is(':checked');
				$('.hbl-bpr-listing-checkbox').each(function () {
					$(this).prop('checked', checked).trigger('change');
				});
			});

			// Individual listing checkbox
			$(document).on('change', '.hbl-bpr-listing-checkbox', function () {
				var $row = $(this).closest('tr');
				var id = parseInt($(this).val());
				var title = $row.find('.hbl-bpr-listing-title').text().trim();
				var planName = $row.find('.hbl-bpr-plan-badge').text().trim();
				var checked = $(this).is(':checked');

				$row.toggleClass('hbl-bpr-selected', checked);

				if (checked) {
					self.selectedListings[id] = { id: id, title: title, planName: planName };
				} else {
					delete self.selectedListings[id];
				}

				self.updateSelectAllState();
				self.updateSelectedCount();
				self.updateSummary();
				self.updateSelectAllPagesBanner();
			});

			// "Select all N listings" banner button
			$(document).on('click', '#hbl-bpr-select-all-pages-btn', function (e) {
				e.preventDefault();
				self.selectAllPages();
			});

			// "Clear selection" banner link
			$(document).on('click', '#hbl-bpr-clear-all-pages', function (e) {
				e.preventDefault();
				self.clearAllSelections();
			});

			// Plan card selection
			$(document).on('change', '.hbl-bpr-plan-radio', function () {
				$('.hbl-bpr-plan-card').removeClass('hbl-bpr-plan-selected');
				$(this).closest('.hbl-bpr-plan-card').addClass('hbl-bpr-plan-selected');
				self.updateSummary();
			});

			// Execute button
			$(document).on('click', '#hbl-bpr-execute', function () {
				self.executePlanChange();
			});
		},

		loadListings: function () {
			if (this.isLoading) return;

			var self = this;
			var filterPlan = $('#hbl-bpr-filter-plan').val();
			var search = $('#hbl-bpr-search').val();

			this.isLoading = true;
			this.showSpinner();

			$.ajax({
				url: hblBulkPlanReassign.ajaxUrl,
				type: 'POST',
				data: {
					action: 'hbl_get_listings_for_plan',
					nonce: hblBulkPlanReassign.nonce,
					filter_plan: filterPlan,
					search: search,
					paged: self.currentPage
				},
				success: function (response) {
					self.isLoading = false;
					if (response.success) {
						self.totalPages = response.data.total_pages || 1;
						self.currentTotal = response.data.total || 0;
						self.currentPerPage = response.data.per_page || 20;
						self.renderListings(response.data.listings, response.data.total);
						self.renderPagination(response.data.total, response.data.page, response.data.total_pages, response.data.per_page);
					} else {
						self.showMessage(
							response.data.message || hblBulkPlanReassign.strings.error,
							'info'
						);
					}
				},
				error: function () {
					self.isLoading = false;
					self.showMessage(hblBulkPlanReassign.strings.error, 'info');
				}
			});
		},

		showSpinner: function () {
			$('#hbl-bpr-listings-wrap').hide();
			$('#hbl-bpr-listings-message').hide();
			var $msg = $('#hbl-bpr-listings-message');
			$msg.removeClass().addClass('hbl-bpr-message')
				.html('<div class="hbl-bpr-spinner">' + hblBulkPlanReassign.strings.loadingListings + '</div>')
				.show();
		},

		showMessage: function (msg, type) {
			$('#hbl-bpr-listings-wrap').hide();
			var $msg = $('#hbl-bpr-listings-message');
			$msg.removeClass().addClass('hbl-bpr-message hbl-bpr-message--' + type)
				.html(msg)
				.show();
		},

		renderListings: function (listings, total) {
			var self = this;
			var $wrap = $('#hbl-bpr-listings-wrap');
			var $table = $('#hbl-bpr-listings-table');
			var $msg = $('#hbl-bpr-listings-message');

			$msg.hide();

			if (!listings || listings.length === 0) {
				$wrap.hide();
				this.showMessage(hblBulkPlanReassign.strings.noListings, 'empty');
				this.updateSelectedCount();
				this.updateSummary();
				return;
			}

			var html = '<table class="hbl-bpr-table">';
			html += '<thead><tr>';
			html += '<th></th>';
			html += '<th>' + this.escHtml('Listing') + '</th>';
			html += '<th>' + this.escHtml('Current Plan') + '</th>';
			html += '<th>' + this.escHtml('Status') + '</th>';
			html += '<th>' + this.escHtml('Author') + '</th>';
			html += '<th>' + this.escHtml('Actions') + '</th>';
			html += '</tr></thead>';
			html += '<tbody>';

			for (var i = 0; i < listings.length; i++) {
				var l = listings[i];
				var isSelected = !!self.selectedListings && !!self.selectedListings[l.id];
				var rowClass = isSelected ? ' class="hbl-bpr-selected"' : '';
				var statusClass = 'hbl-bpr-status-badge--' + self.normalizeStatusClass(l.status);
				var planClass = l.plan_id ? '' : ' hbl-bpr-plan-badge--none';

				html += '<tr' + rowClass + '>';
				html += '<td data-label=""><input type="checkbox" class="hbl-bpr-listing-checkbox" value="' + l.id + '"' + (isSelected ? ' checked' : '') + '></td>';
				html += '<td data-label="Listing"><span class="hbl-bpr-listing-title">';
				var titleUrl = l.view_link || l.edit_link;
				if (titleUrl) {
					html += '<a href="' + titleUrl + '" target="_blank">' + this.escHtml(l.title) + '</a>';
				} else {
					html += this.escHtml(l.title);
				}
				html += '</span></td>';
				html += '<td data-label="Current Plan"><span class="hbl-bpr-plan-badge' + planClass + '">' + this.escHtml(l.plan_name) + '</span></td>';
				html += '<td data-label="Status"><span class="hbl-bpr-status-badge ' + statusClass + '">' + this.escHtml(l.status) + '</span></td>';
				html += '<td data-label="Author">' + this.escHtml(l.author) + '</td>';
				html += '<td data-label="Actions" style="white-space:nowrap;">';
				if (l.view_link) {
					html += '<a href="' + l.view_link + '" target="_blank" class="button button-small">View Listing</a>';
				}
				html += '</td>';
				html += '</tr>';
			}

			html += '</tbody></table>';

			$table.html(html);
			$wrap.show();

			// Restore checked state for already-selected listings on this page
			$('.hbl-bpr-listing-checkbox').each(function () {
				var id = parseInt($(this).val());
				if (self.selectedListings[id]) {
					$(this).prop('checked', true);
					$(this).closest('tr').addClass('hbl-bpr-selected');
				}
			});

			this.updateSelectAllState();
			this.updateSelectedCount();
			this.updateSelectAllPagesBanner();
		},

		updateSelectAllPagesBanner: function () {
			var $banner = $('#hbl-bpr-select-all-pages');
			var total = this.currentTotal || 0;
			var perPage = this.currentPerPage || 20;
			var selectedCount = Object.keys(this.selectedListings).length;
			var pageCount = $('.hbl-bpr-listing-checkbox').length;
			var pageChecked = $('.hbl-bpr-listing-checkbox:checked').length;

			// Only show when there is more than one page worth of results
			if (total <= perPage || pageCount === 0) {
				$banner.hide();
				return;
			}

			var s = hblBulkPlanReassign.strings;

			if (selectedCount >= total) {
				// All listings across all pages are already selected
				var allMsg = s.allPagesSelected.replace('%d', total);
				$banner.html(
					'<strong>' + allMsg + '</strong> ' +
					'<a href="#" id="hbl-bpr-clear-all-pages">' + s.clearSelection + '</a>'
				).show();
			} else if (pageChecked === pageCount) {
				// All on this page selected — offer to select all pages
				var pageMsg = s.pageSelectedNotice.replace('%d', pageCount);
				var allPagesLink = s.selectAllPages.replace('%d', total);
				$banner.html(
					pageMsg + ' <a href="#" id="hbl-bpr-select-all-pages-btn">' + allPagesLink + '</a>'
				).show();
			} else {
				$banner.hide();
			}
		},

		selectAllPages: function () {
			var self = this;
			var filterPlan = $('#hbl-bpr-filter-plan').val();
			var search = $('#hbl-bpr-search').val();
			var $banner = $('#hbl-bpr-select-all-pages');

			$banner.html(hblBulkPlanReassign.strings.loadingAll);

			$.ajax({
				url: hblBulkPlanReassign.ajaxUrl,
				type: 'POST',
				data: {
					action: 'hbl_get_all_listing_ids_for_plan',
					nonce: hblBulkPlanReassign.nonce,
					filter_plan: filterPlan,
					search: search
				},
				success: function (response) {
					if (response.success) {
						var listings = response.data.listings;
						for (var i = 0; i < listings.length; i++) {
							var l = listings[i];
							self.selectedListings[l.id] = { id: l.id, title: l.title, planName: l.plan_name };
						}
						// Reflect on visible checkboxes
						$('.hbl-bpr-listing-checkbox').each(function () {
							var id = parseInt($(this).val());
							if (self.selectedListings[id]) {
								$(this).prop('checked', true);
								$(this).closest('tr').addClass('hbl-bpr-selected');
							}
						});
						self.updateSelectAllState();
						self.updateSelectedCount();
						self.updateSummary();
						self.updateSelectAllPagesBanner();
					} else {
						$banner.html(
							hblBulkPlanReassign.strings.error + ' ' +
							'<a href="#" id="hbl-bpr-select-all-pages-btn">' + hblBulkPlanReassign.strings.tryAgain + '</a>'
						);
					}
				},
				error: function () {
					$banner.html(
						hblBulkPlanReassign.strings.error + ' ' +
						'<a href="#" id="hbl-bpr-select-all-pages-btn">' + hblBulkPlanReassign.strings.tryAgain + '</a>'
					);
				}
			});
		},

		clearAllSelections: function () {
			this.selectedListings = {};
			$('.hbl-bpr-listing-checkbox').each(function () {
				$(this).prop('checked', false);
				$(this).closest('tr').removeClass('hbl-bpr-selected');
			});
			$('#hbl-bpr-select-all').prop('checked', false);
			this.updateSelectedCount();
			this.updateSummary();
			this.updateSelectAllPagesBanner();
		},

		renderPagination: function (total, page, totalPages, perPage) {
			var self = this;
			var $pag = $('#hbl-bpr-pagination');

			if (totalPages <= 1) {
				$pag.empty();
				return;
			}

			var html = '';
			html += '<button class="hbl-bpr-page-btn" id="hbl-bpr-prev" ' + (page <= 1 ? 'disabled' : '') + '>&laquo; Prev</button>';

			var start = Math.max(1, page - 2);
			var end = Math.min(totalPages, page + 2);

			if (start > 1) {
				html += '<button class="hbl-bpr-page-btn" data-page="1">1</button>';
				if (start > 2) html += '<span style="padding:0 4px">…</span>';
			}

			for (var p = start; p <= end; p++) {
				html += '<button class="hbl-bpr-page-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
			}

			if (end < totalPages) {
				if (end < totalPages - 1) html += '<span style="padding:0 4px">…</span>';
				html += '<button class="hbl-bpr-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>';
			}

			html += '<button class="hbl-bpr-page-btn" id="hbl-bpr-next" ' + (page >= totalPages ? 'disabled' : '') + '>Next &raquo;</button>';

			$pag.html(html);

			$pag.off('click', '.hbl-bpr-page-btn').on('click', '.hbl-bpr-page-btn', function () {
				if ($(this).is(':disabled')) return;
				var targetPage;
				if ($(this).is('#hbl-bpr-prev')) {
					targetPage = self.currentPage - 1;
				} else if ($(this).is('#hbl-bpr-next')) {
					targetPage = self.currentPage + 1;
				} else {
					targetPage = parseInt($(this).data('page'));
				}
				if (targetPage && targetPage !== self.currentPage) {
					self.currentPage = targetPage;
					self.loadListings();
				}
			});
		},

		updateSelectAllState: function () {
			var $checkboxes = $('.hbl-bpr-listing-checkbox');
			var total = $checkboxes.length;
			var checked = $checkboxes.filter(':checked').length;
			$('#hbl-bpr-select-all').prop('checked', total > 0 && total === checked);
		},

		updateSelectedCount: function () {
			var count = Object.keys(this.selectedListings).length;
			$('#hbl-bpr-selected-count').text(count);
		},

		updateSummary: function () {
			var $summary = $('#hbl-bpr-summary');
			var $btn = $('#hbl-bpr-execute');
			var selectedCount = Object.keys(this.selectedListings).length;
			var $selectedPlan = $('input[name="hbl_target_plan"]:checked');
			var planName = $selectedPlan.length ? $selectedPlan.closest('.hbl-bpr-plan-card').find('.hbl-bpr-plan-name').text().trim() : '';

			if (selectedCount === 0 || !planName) {
				$summary.removeClass('hbl-summary-active')
					.html('<p>Select listings and a target plan above to see a summary here.</p>');
				$btn.prop('disabled', true);
				return;
			}

			var html = '<p>You are about to assign the <strong>"' + this.escHtml(planName) + '"</strong> plan to ' +
				'<span class="hbl-summary-highlight">' + selectedCount + ' listing(s)</span>.</p>';

			html += '<div class="hbl-summary-warning" style="background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.3);color:#92400E;">' +
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
				'<path d="M10.29 3.86L1.82 18a2 2 0 001.73 3h16.9a2 2 0 001.73-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
				'<line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
				'<circle cx="12" cy="17" r="1" fill="currentColor"/>' +
				'</svg>' +
				'<span>This will overwrite each listing\'s current plan assignment.</span>' +
				'</div>';

			$summary.addClass('hbl-summary-active').html(html);
			$btn.prop('disabled', false);
		},

		executePlanChange: function () {
			var self = this;
			var selectedCount = Object.keys(this.selectedListings).length;
			var planId = $('input[name="hbl_target_plan"]:checked').val();

			if (selectedCount === 0) {
				alert(hblBulkPlanReassign.strings.selectListings);
				return;
			}

			if (!planId) {
				alert(hblBulkPlanReassign.strings.selectPlan);
				return;
			}

			if (!confirm(hblBulkPlanReassign.strings.confirmChange)) {
				return;
			}

			var listingIds = Object.keys(this.selectedListings).map(Number);
			var $btn = $('#hbl-bpr-execute');
			var $result = $('#hbl-bpr-result');

			$btn.prop('disabled', true).find('span').text(hblBulkPlanReassign.strings.processing);
			$result.hide();

			$.ajax({
				url: hblBulkPlanReassign.ajaxUrl,
				type: 'POST',
				data: {
					action: 'hbl_bulk_change_plan',
					nonce: hblBulkPlanReassign.nonce,
					listing_ids: listingIds,
					plan_id: planId
				},
				success: function (response) {
					$btn.prop('disabled', false).find('span').text('Apply Plan Change');

					if (response.success) {
						var msg = response.data.message;
						if (response.data.failed_count > 0) {
							msg += ' (' + response.data.failed_count + ' failed.)';
						}
						$result.removeClass('error').addClass('success')
							.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>' + msg + '</span>')
							.show();

						// Clear selection and reload the current page to reflect changes
						self.selectedListings = {};
						self.updateSelectedCount();
						self.updateSummary();
						setTimeout(function () {
							self.loadListings();
						}, 1500);

					} else {
						$result.removeClass('success').addClass('error')
							.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span>' + (response.data.message || hblBulkPlanReassign.strings.error) + '</span>')
							.show();
					}
				},
				error: function () {
					$btn.prop('disabled', false).find('span').text('Apply Plan Change');
					$result.removeClass('success').addClass('error')
						.html('<span>' + hblBulkPlanReassign.strings.error + '</span>')
						.show();
				}
			});
		},

		normalizeStatusClass: function (status) {
			if (!status) {
				return 'draft';
			}

			var normalized = String(status).toLowerCase();

			if (normalized === 'published') {
				return 'publish';
			}

			if (normalized.indexOf('pending') !== -1) {
				return 'pending';
			}

			if (normalized.indexOf('draft') !== -1) {
				return 'draft';
			}

			return 'draft';
		},

		escHtml: function (str) {
			if (!str) return '';
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}
	};

	$(document).ready(function () {
		if (typeof hblBulkPlanReassign !== 'undefined') {
			HBLBulkPlanReassign.init();
		}
	});

})(jQuery);
