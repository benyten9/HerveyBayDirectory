jQuery(function($) {

	//settings link trigger
    $('#pmcs-show-settings-link').on('click', function() {
    	$(this).toggleClass('active');
        $('#show-settings-link').trigger('click');
    });

    //code type change when creating new snippet
	$("#pmcs-code-type input[name='type']").on('change', function() {

		//set code type attribute
		var type = $(this).val();
		$("#pmcs-snippet").attr("data-code-type", type);

		//update snippet codemirror editor
		var editor = document.querySelector("#pmcs-snippet .CodeMirror").CodeMirror;
		editor.setOption("mode", PMCS.code_types[type].mime);
		editor.setOption("lint", PMCS.code_types[type].lint);

		//populate locations and visible fields for code type
		pmcsRefreshSnippetUI(type);
	});

	//update name in ui when input changes
	$("#pmcs-name").on('keyup', function(e) {
		$("#pmcs-snippet-name").text($(this).val());
	});

	//active toggle
	$('input[type="checkbox"].pmcs-active').on('change', function() {

		var action = $(this).is(':checked') ? 'activate' : 'deactivate';
		var rowClass = $(this).is(':checked') ? 'pmcs-active-snippet' : 'pmcs-inactive-snippet';
		var row = $(this).closest('tr');

		$.ajax({
	        url: PERFMATTERS.ajaxurl,
	        data: {
	        	'action' : 'perfmatters_' + action + '_snippet',
	        	'nonce' : PERFMATTERS.nonce,
	        	'file_name' : $(this).data("pmcs-file-name")
	        }
	    })
	    .done(function(r) {
	    	row.attr("class", rowClass);
	    });
  	});

  	//load method change
	$("#pmcs-method").on('change', pmcsUpdateLoadBehavior);

	//location change
	$("#pmcs-location").on('change', pmcsUpdateVisibleFields);

  	//delete snippet confirmation
    $('a.pmcs-delete').on('click', function(e) {
    	e.preventDefault();

	    if(confirm(PMCS.strings.delete_snippet)) {
	    	window.location.href = $(this).attr('href');
	    }
	    else {
	    	$(this).blur();
	    }
    });

	function pmcsRefreshSnippetUI(pmcsCodeType = null) {
		pmcsGetLocationOptions(pmcsCodeType);
		pmcsUpdateVisibleFields();
	}

  	function pmcsGetLocationOptions(pmcsCodeType = null) {

		var currentLocation = $("#pmcs-location").val();

		$("#pmcs-location").empty();

		if(!pmcsCodeType) {
			pmcsCodeType = $("#pmcs-snippet").attr("data-code-type");
		}

		$("#pmcs-location-options option").each(function(i) {
	       
			var optionCodeType = $(this).attr("data-code-type");
			var optionCodeTypeArray = optionCodeType.split(",");

			if($.inArray(pmcsCodeType, optionCodeTypeArray) == -1) {
				return;
			}

	        $(this).clone().appendTo("#pmcs-location");
	    });

		if(currentLocation) {
			$("#pmcs-location").val(currentLocation);
		}
	}

	function pmcsAttrContains($el, attr, value) {
		var raw = $el.attr(attr);

		if(typeof raw === 'undefined') {
			return true;
		}

		return $.inArray(value, raw.split(/[,\s]+/)) !== -1;
	}

	function pmcsUpdateVisibleFields() {

		var type = $("#pmcs-snippet").attr("data-code-type");
		var location = $("#pmcs-location").val() || '';

		$("#pmcs-snippet [data-code-type], #pmcs-snippet [data-location]").each(function() {
			var $el = $(this);
			var visible = pmcsAttrContains($el, 'data-code-type', type) && pmcsAttrContains($el, 'data-location', location);

			$el.toggleClass('hidden', !visible);
			$el.find(":input[name]").prop("disabled", !visible);
		});

		pmcsUpdateLoadBehavior();
	}

	//update load behavior options for selected code type
	function pmcsUpdateLoadBehavior(pmcsCodeType = null) {

		if(!pmcsCodeType) {
			pmcsCodeType = $("#pmcs-snippet").attr("data-code-type");
		}

		var method = $('#pmcs-method').val();
		var $select = $('#pmcs-behavior-' + pmcsCodeType);

		$select.find('option').each(function() {
	       
			var optionMethod = $(this).attr("data-method");

			if(optionMethod) {
				if(optionMethod !== method) {
					$(this).prop({
						'selected': false,
						'disabled': true
					});
				}
				else {
					$(this).prop({
						'disabled': false
					});
				}
			}
	    });

		//hidden fields stay disabled so they are not posted
		if($select.closest('.hidden').length) {
			$select.prop('disabled', true);
			return;
		}

		//set disabled status for select
		$select.prop('disabled', $select.find('option:not(:disabled)').length < 2);
	}

	//document ready
	$(document).ready(function() {

		//update options once on load
		pmcsRefreshSnippetUI();

		var $editorPanel = $('#pmcs-code-editor-panel');

		if(!$editorPanel.length) {
			return;
		}

		//wait for CodeMirror to mount before revealing panel.
		function revealEditorPanel() {
			var hasCodeMirror = $editorPanel.find('.CodeMirror').length > 0;
			if(hasCodeMirror) {
				$editorPanel.addClass('loaded');
				return;
			}
			window.requestAnimationFrame(revealEditorPanel);
		}

		revealEditorPanel();
	});
    
    //copy input
    $('#perfmatters-admin').on('click', '.pmcs-copy-input', function(e) {

        e.preventDefault();

        const $label = $(this);
        const value = $label.find('input').val();

        if(!value) {
            return;
        }

        const $copySpan = $label.find('span');

        clearTimeout($label.data('copyTimeout'));

        navigator.clipboard.writeText(value).then(() => {

            $copySpan.text(PMCS.strings.copied);

            $label.data('copyTimeout', setTimeout(() => {
                $copySpan.text(PMCS.strings.copy);
            }, 1200));
        });
    });
});