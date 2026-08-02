/**
 * HBL Bulk Category Reassign JavaScript
 */

(function($) {
	'use strict';

	var HBLBulkReassign = {
		selectedCategories: [],
		totalSelectedListings: 0,

		init: function() {
			this.bindEvents();
			this.updateSummary();
		},

		bindEvents: function() {
			var self = this;

			// Select all categories checkbox
			$('#hbl-select-all-categories').on('change', function() {
				var isChecked = $(this).is(':checked');
				$('.hbl-source-category-checkbox').each(function() {
					$(this).prop('checked', isChecked).trigger('change');
				});
			});

			// Individual category checkbox change
			$(document).on('change', '.hbl-source-category-checkbox', function() {
				var $card = $(this).closest('.hbl-category-card');
				var categoryId = parseInt($card.data('category-id'));
				var listingCount = parseInt($card.data('listing-count')) || 0;
				var isChecked = $(this).is(':checked');

				$card.toggleClass('selected', isChecked);

				if (isChecked) {
					if (self.selectedCategories.indexOf(categoryId) === -1) {
						self.selectedCategories.push(categoryId);
						self.totalSelectedListings += listingCount;
					}
				} else {
					var index = self.selectedCategories.indexOf(categoryId);
					if (index > -1) {
						self.selectedCategories.splice(index, 1);
						self.totalSelectedListings -= listingCount;
					}
				}

				self.updateSelectAllState();
				self.updateSelectedCount();
				self.updateSummary();
			});

			// Note: Clicking on category card is handled by the label element
			// which naturally toggles the checkbox

			// Target category change
			$('#hbl-target-category').on('change', function() {
				self.updateSummary();
			});

			// Reassign mode change
			$('input[name="hbl-reassign-mode"]').on('change', function() {
				self.updateSummary();
			});

			// Execute reassign
			$('#hbl-execute-reassign').on('click', function() {
				self.executeReassign();
			});
		},

		updateSelectAllState: function() {
			var $checkboxes = $('.hbl-source-category-checkbox');
			var totalCategories = $checkboxes.length;
			var checkedCategories = $checkboxes.filter(':checked').length;

			$('#hbl-select-all-categories').prop('checked', totalCategories > 0 && totalCategories === checkedCategories);
		},

		updateSelectedCount: function() {
			$('#hbl-selected-cat-count').text(this.selectedCategories.length);
			$('#hbl-selected-listing-count').text(this.totalSelectedListings);
		},

		updateSummary: function() {
			var $summary = $('#hbl-summary');
			var $executeBtn = $('#hbl-execute-reassign');
			var targetCategory = $('#hbl-target-category option:selected');
			var targetCategoryId = parseInt(targetCategory.val()) || 0;
			var mode = $('input[name="hbl-reassign-mode"]:checked').val();

			// Check if target is in selected source categories
			var targetInSource = this.selectedCategories.indexOf(targetCategoryId) > -1;

			if (this.selectedCategories.length === 0 || !targetCategory.val()) {
				$summary.removeClass('hbl-summary-active');
				$summary.html('<p>Select source categories and a target category to see a summary here.</p>');
				$executeBtn.prop('disabled', true);
				return;
			}

			// Get selected category names
			var sourceNames = [];
			$('.hbl-category-card.selected').each(function() {
				sourceNames.push($(this).find('.hbl-category-name').text());
			});

			var modeText = mode === 'replace' 
				? 'replacing all existing categories' 
				: 'adding to existing categories';

			var html = '<p>You are about to move <span class="hbl-summary-highlight">' + this.totalSelectedListings + ' listing(s)</span> ' +
				'from <span class="hbl-summary-highlight">' + sourceNames.join(', ') + '</span> ' +
				'to <span class="hbl-summary-highlight">"' + targetCategory.text().split('(')[0].trim() + '"</span>, ' +
				modeText + '.</p>';

			if (mode === 'replace') {
				html += '<div class="hbl-summary-warning">' +
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
					'<path d="M10.29 3.86L1.82 18C1.64 18.3 1.55 18.64 1.55 19C1.55 19.36 1.64 19.7 1.82 20C2 20.3 2.26 20.55 2.57 20.72C2.88 20.89 3.23 20.98 3.59 21H20.41C20.77 21 21.12 20.91 21.43 20.74C21.74 20.57 22 20.32 22.18 20.02C22.36 19.72 22.45 19.38 22.45 19.02C22.45 18.66 22.36 18.32 22.18 18.02L13.71 3.86C13.53 3.56 13.27 3.31 12.96 3.14C12.65 2.97 12.3 2.88 11.94 2.88C11.58 2.88 11.23 2.97 10.92 3.14C10.61 3.31 10.35 3.56 10.17 3.86H10.29Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
					'<path d="M12 9V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
					'<circle cx="12" cy="17" r="1" fill="currentColor"/>' +
					'</svg>' +
					'<span>This will remove all current category assignments from these listings. They will only belong to the target category.</span>' +
					'</div>';
			}

			$summary.addClass('hbl-summary-active').html(html);
			
			// Enable button if valid selection
			$executeBtn.prop('disabled', targetInSource);
			
			if (targetInSource) {
				$summary.append('<div class="hbl-summary-warning" style="background: rgba(239, 68, 68, 0.1); color: #991B1B;">' +
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
					'<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>' +
					'<path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
					'</svg>' +
					'<span>Target category cannot be one of the selected source categories. Please choose a different target.</span>' +
					'</div>');
			}
		},

		executeReassign: function() {
			var self = this;
			var targetCategory = $('#hbl-target-category').val();
			var mode = $('input[name="hbl-reassign-mode"]:checked').val();

			if (this.selectedCategories.length === 0) {
				alert(hblBulkReassign.strings.selectSource);
				return;
			}

			if (!targetCategory) {
				alert(hblBulkReassign.strings.selectTarget);
				return;
			}

			if (this.selectedCategories.indexOf(parseInt(targetCategory)) > -1) {
				alert(hblBulkReassign.strings.sameCategory);
				return;
			}

			if (!confirm(hblBulkReassign.strings.confirmReassign)) {
				return;
			}

			var $executeBtn = $('#hbl-execute-reassign');
			var $result = $('#hbl-result');

			$executeBtn.prop('disabled', true).find('span').text(hblBulkReassign.strings.processing);
			$result.hide();

			$.ajax({
				url: hblBulkReassign.ajaxUrl,
				type: 'POST',
				data: {
					action: 'hbl_bulk_reassign_categories',
					nonce: hblBulkReassign.nonce,
					source_categories: self.selectedCategories,
					target_category: targetCategory,
					mode: mode
				},
				success: function(response) {
					if (response.success) {
						$result.removeClass('error').addClass('success').html(
							'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
							'<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
							'<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
							'</svg>' +
							'<span>' + response.data.message + '</span>'
						).show();
						
						// Reload page after 2 seconds to show updated counts
						setTimeout(function() {
							window.location.reload();
						}, 2000);
					} else {
						$result.removeClass('success').addClass('error').html(
							'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
							'<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>' +
							'<path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
							'</svg>' +
							'<span>' + (response.data.message || hblBulkReassign.strings.error) + '</span>'
						).show();
						$executeBtn.prop('disabled', false).find('span').text('Reassign Categories');
					}
				},
				error: function() {
					$result.removeClass('success').addClass('error').html(
						'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
						'<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>' +
						'<path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
						'</svg>' +
						'<span>' + hblBulkReassign.strings.error + '</span>'
					).show();
					$executeBtn.prop('disabled', false).find('span').text('Reassign Categories');
				}
			});
		}
	};

	$(document).ready(function() {
		if ($('.hbl-bulk-reassign-wrap').length) {
			HBLBulkReassign.init();
		}
	});

})(jQuery);
