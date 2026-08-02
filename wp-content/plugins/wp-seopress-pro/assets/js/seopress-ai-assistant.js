/**
 * SEOPress AI Assistant - Classic Editor & Settings Integration
 *
 * Vanilla JavaScript implementation for non-Gutenberg contexts.
 *
 * @package SEOPress PRO
 * @since 9.6.0
 */

( function( $ ) {
	'use strict';

	// Exit if config not available.
	if ( typeof seopressAIAssistant === 'undefined' ) {
		return;
	}

	var config = seopressAIAssistant;
	var i18n = config.i18n || {};
	var storageKey = 'seopress_ai_chat_' + ( config.context || 'settings' );
	var messages = [];
	var isLoading = false;
	var isPanelOpen = false;
	var includeContext = false;
	var seoData = null;

	/**
	 * Quick action prompts.
	 */
	var QUICK_ACTIONS = [
		{
			label: i18n.quickOutline || 'Write an article outline',
			prompt: 'Based on the current post content and target keywords, please write a detailed article outline that would help improve SEO performance.'
		},
		{
			label: i18n.quickTitles || 'Suggest titles',
			prompt: 'Based on the current post content and target keywords, please suggest 5 SEO-optimized title variations.'
		},
		{
			label: i18n.quickAnalyze || 'Analyze my post',
			prompt: 'Please analyze the current post for SEO and provide specific recommendations to improve its ranking potential.'
		}
	];

	/**
	 * Initialize the AI Assistant.
	 */
	function init() {
		// Load messages from sessionStorage.
		loadMessages();

		// Create the slide-out panel.
		createPanel();

		// Bind trigger events.
		bindTriggers();

		// Fetch SEO data for classic editor.
		if ( config.context === 'classic-editor' && config.postId ) {
			fetchSeoData();
		}
	}

	/**
	 * Fetch SEO data (target keywords and content analysis).
	 */
	function fetchSeoData() {
		if ( ! config.postId ) {
			return;
		}

		// Fetch target keywords and content analysis in parallel.
		Promise.all( [
			wp.apiFetch( { path: '/seopress/v1/posts/' + config.postId + '/target-keywords' } ).catch( function() { return null; } ),
			wp.apiFetch( { path: '/seopress/v1/posts/' + config.postId + '/content-analysis' } ).catch( function() { return null; } )
		] ).then( function( results ) {
			seoData = {
				targetKeywords: results[0] && results[0].keywords ? results[0].keywords : [],
				analysisResults: results[1] && results[1].analysis ? results[1].analysis : []
			};
		} ).catch( function() {
			seoData = null;
		} );
	}

	/**
	 * Load messages from sessionStorage.
	 */
	function loadMessages() {
		try {
			var stored = sessionStorage.getItem( storageKey );
			if ( stored ) {
				messages = JSON.parse( stored );
			}
		} catch ( e ) {
			messages = [];
		}
	}

	/**
	 * Save messages to sessionStorage.
	 */
	function saveMessages() {
		try {
			sessionStorage.setItem( storageKey, JSON.stringify( messages ) );
		} catch ( e ) {
			// Ignore storage errors.
		}
	}

	/**
	 * Create the slide-out panel HTML.
	 */
	function createPanel() {
		var html = '<div id="seopress-ai-assistant-panel" class="seopress-ai-assistant-panel">' +
			'<div class="seopress-ai-assistant-panel__header">' +
				'<h3>' + escapeHtml( i18n.title || 'AI Assistant' ) + '</h3>' +
				'<div class="seopress-ai-assistant-panel__actions">' +
					'<button type="button" class="seopress-ai-assistant-panel__clear btn btnTertiary" disabled>' +
						escapeHtml( i18n.clear || 'Clear' ) +
					'</button>' +
					'<button type="button" class="seopress-ai-assistant-panel__close">' +
						'<span class="dashicons dashicons-no-alt"></span>' +
						'<span class="screen-reader-text">' + escapeHtml( i18n.close || 'Close' ) + '</span>' +
					'</button>' +
				'</div>' +
			'</div>';

		// Add context toggle for classic editor.
		if ( config.context === 'classic-editor' && config.postId ) {
			html += '<div class="seopress-ai-assistant-panel__context">' +
				'<label>' +
					'<input type="checkbox" class="seopress-ai-assistant-context-toggle" />' +
					' ' + escapeHtml( i18n.includeContext || 'Include post context' ) +
				'</label>' +
			'</div>';
		}

		html += '<div class="seopress-ai-assistant-panel__messages">' +
				getWelcomeHtml() +
			'</div>' +
			'<form class="seopress-ai-assistant-panel__input">' +
				'<textarea placeholder="' + escapeHtml( i18n.placeholder || 'Ask about SEO...' ) + '" rows="1"></textarea>' +
				'<button type="submit" class="btn btnPrimary" disabled>' + escapeHtml( i18n.send || 'Send' ) + '</button>' +
			'</form>' +
		'</div>' +
		'<div id="seopress-ai-assistant-overlay" class="seopress-ai-assistant-overlay"></div>';

		$( 'body' ).append( html );

		// Bind panel events.
		bindPanelEvents();

		// Render existing messages.
		renderMessages();
	}

	/**
	 * Bind panel events.
	 */
	function bindPanelEvents() {
		var $panel = $( '#seopress-ai-assistant-panel' );
		var $overlay = $( '#seopress-ai-assistant-overlay' );
		var $textarea = $panel.find( 'textarea' );
		var $submitBtn = $panel.find( 'form button[type="submit"]' );
		var $clearBtn = $panel.find( '.seopress-ai-assistant-panel__clear' );
		var $closeBtn = $panel.find( '.seopress-ai-assistant-panel__close' );
		var $contextToggle = $panel.find( '.seopress-ai-assistant-context-toggle' );

		// Close panel.
		$closeBtn.on( 'click', closePanel );
		$overlay.on( 'click', closePanel );

		// Enable/disable submit button based on textarea content.
		$textarea.on( 'input', function() {
			$submitBtn.prop( 'disabled', ! $( this ).val().trim() || isLoading );
			autoResizeTextarea( this );
		} );

		// Submit on Enter (without Shift).
		$textarea.on( 'keydown', function( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				if ( $( this ).val().trim() && ! isLoading ) {
					$panel.find( 'form' ).trigger( 'submit' );
				}
			}
		} );

		// Form submit.
		$panel.find( 'form' ).on( 'submit', function( e ) {
			e.preventDefault();
			var content = $textarea.val().trim();
			if ( content && ! isLoading ) {
				sendMessage( content );
				$textarea.val( '' ).trigger( 'input' );
			}
		} );

		// Clear chat.
		$clearBtn.on( 'click', function() {
			messages = [];
			saveMessages();
			renderMessages();
			$( this ).prop( 'disabled', true );
		} );

		// Context toggle.
		$contextToggle.on( 'change', function() {
			includeContext = $( this ).is( ':checked' );
		} );

		// Escape key to close.
		$( document ).on( 'keydown', function( e ) {
			if ( e.key === 'Escape' && isPanelOpen ) {
				closePanel();
			}
		} );
	}

	/**
	 * Bind trigger button events.
	 */
	function bindTriggers() {
		// Bind to trigger class elements (buttons, header button).
		$( document ).on( 'click', '.seopress-ai-assistant-trigger', function( e ) {
			e.preventDefault();
			e.stopPropagation();
			togglePanel();
		} );

		// Specifically bind to admin bar item (Classic Editor).
		$( document ).on( 'click', '#wp-admin-bar-seopress-ai-assistant, #wp-admin-bar-seopress-ai-assistant > a', function( e ) {
			e.preventDefault();
			e.stopPropagation();
			togglePanel();
		} );
	}

	/**
	 * Toggle panel open/closed.
	 */
	function togglePanel() {
		// Ensure panel exists.
		if ( $( '#seopress-ai-assistant-panel' ).length === 0 ) {
			createPanel();
		}

		if ( isPanelOpen ) {
			closePanel();
		} else {
			openPanel();
		}
	}

	/**
	 * Open the panel.
	 */
	function openPanel() {
		$( '#seopress-ai-assistant-panel' ).addClass( 'is-open' );
		$( '#seopress-ai-assistant-overlay' ).addClass( 'is-visible' );
		$( 'body' ).addClass( 'seopress-ai-assistant-panel-open' );
		isPanelOpen = true;

		// Focus textarea.
		setTimeout( function() {
			$( '#seopress-ai-assistant-panel textarea' ).focus();
		}, 300 );
	}

	/**
	 * Close the panel.
	 */
	function closePanel() {
		$( '#seopress-ai-assistant-panel' ).removeClass( 'is-open' );
		$( '#seopress-ai-assistant-overlay' ).removeClass( 'is-visible' );
		$( 'body' ).removeClass( 'seopress-ai-assistant-panel-open' );
		isPanelOpen = false;
	}

	/**
	 * Get welcome screen HTML.
	 *
	 * @return {string} Welcome HTML.
	 */
	function getWelcomeHtml() {
		var html = '<div class="seopress-ai-assistant-panel__welcome">' +
			'<p>' + escapeHtml( i18n.welcome || 'Hi! Ask me anything about SEO.' ) + '</p>';

		// Add quick action buttons for classic editor.
		if ( config.context === 'classic-editor' && config.postId ) {
			html += '<div class="seopress-ai-assistant-quick-actions">' +
				'<p class="seopress-ai-assistant-quick-actions__title">' + escapeHtml( i18n.quickActions || 'Quick actions:' ) + '</p>' +
				'<div class="seopress-ai-assistant-quick-actions__buttons">';

			QUICK_ACTIONS.forEach( function( action, index ) {
				html += '<button type="button" class="seopress-ai-assistant-quick-action btn btnSecondary" data-action-index="' + index + '">' +
					escapeHtml( action.label ) +
				'</button>';
			} );

			html += '</div></div>';
		}

		html += '</div>';
		return html;
	}

	/**
	 * Render messages in the panel.
	 */
	function renderMessages() {
		var $messagesContainer = $( '#seopress-ai-assistant-panel .seopress-ai-assistant-panel__messages' );
		var $clearBtn = $( '#seopress-ai-assistant-panel .seopress-ai-assistant-panel__clear' );

		$messagesContainer.empty();

		if ( messages.length === 0 ) {
			$messagesContainer.html( getWelcomeHtml() );
			$clearBtn.prop( 'disabled', true );

			// Bind quick action buttons.
			$messagesContainer.find( '.seopress-ai-assistant-quick-action' ).on( 'click', function() {
				var index = $( this ).data( 'action-index' );
				if ( QUICK_ACTIONS[ index ] ) {
					// Enable context for quick actions.
					includeContext = true;
					$( '.seopress-ai-assistant-context-toggle' ).prop( 'checked', true );
					sendMessage( QUICK_ACTIONS[ index ].prompt );
				}
			} );
		} else {
			messages.forEach( function( msg ) {
				$messagesContainer.append( createMessageHtml( msg ) );
			} );
			$clearBtn.prop( 'disabled', false );

			// Bind copy buttons.
			bindCopyButtons();
		}

		// Scroll to bottom.
		$messagesContainer.scrollTop( $messagesContainer[0].scrollHeight );
	}

	/**
	 * Bind copy button events.
	 */
	function bindCopyButtons() {
		$( '.seopress-ai-assistant-copy-btn' ).off( 'click' ).on( 'click', function() {
			var $btn = $( this );
			var content = $btn.data( 'content' );

			copyToClipboard( content ).then( function() {
				$btn.find( '.dashicons' ).removeClass( 'dashicons-clipboard' ).addClass( 'dashicons-yes' );
				setTimeout( function() {
					$btn.find( '.dashicons' ).removeClass( 'dashicons-yes' ).addClass( 'dashicons-clipboard' );
				}, 2000 );
			} );
		} );
	}

	/**
	 * Copy text to clipboard.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise} Promise that resolves when copied.
	 */
	function copyToClipboard( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		// Fallback for older browsers.
		return new Promise( function( resolve ) {
			var textArea = document.createElement( 'textarea' );
			textArea.value = text;
			document.body.appendChild( textArea );
			textArea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textArea );
			resolve();
		} );
	}

	/**
	 * Create HTML for a single message.
	 *
	 * @param {Object} msg Message object.
	 * @return {string} Message HTML.
	 */
	function createMessageHtml( msg ) {
		var isUser = msg.role === 'user';
		var roleLabel = isUser ? ( i18n.you || 'You' ) : ( i18n.assistant || 'AI Assistant' );
		var iconClass = isUser ? 'dashicons-admin-users' : 'dashicons-format-chat';
		var errorClass = msg.isError ? ' seopress-ai-assistant-message--error' : '';

		var copyBtn = '';
		if ( ! isUser && ! msg.isError ) {
			// Escape content for data attribute.
			var escapedContent = msg.content.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
			copyBtn = '<button type="button" class="seopress-ai-assistant-copy-btn" data-content="' + escapedContent + '" title="' + escapeHtml( i18n.copy || 'Copy' ) + '">' +
				'<span class="dashicons dashicons-clipboard"></span>' +
			'</button>';
		}

		return '<div class="seopress-ai-assistant-message seopress-ai-assistant-message--' + ( isUser ? 'user' : 'assistant' ) + errorClass + '">' +
			'<div class="seopress-ai-assistant-message__avatar">' +
				'<span class="dashicons ' + iconClass + '"></span>' +
			'</div>' +
			'<div class="seopress-ai-assistant-message__content">' +
				'<div class="seopress-ai-assistant-message__header">' +
					'<div class="seopress-ai-assistant-message__role">' + escapeHtml( roleLabel ) + '</div>' +
					copyBtn +
				'</div>' +
				'<div class="seopress-ai-assistant-message__text">' + formatContent( msg.content ) + '</div>' +
			'</div>' +
		'</div>';
	}

	/**
	 * Send a message to the AI.
	 *
	 * @param {string} content Message content.
	 */
	function sendMessage( content ) {
		if ( isLoading ) {
			return;
		}

		var userMessage = { role: 'user', content: content };
		messages.push( userMessage );
		saveMessages();
		renderMessages();

		isLoading = true;
		updateUILoading( true );

		// Show loading indicator.
		var $messagesContainer = $( '#seopress-ai-assistant-panel .seopress-ai-assistant-panel__messages' );
		$messagesContainer.append(
			'<div class="seopress-ai-assistant-loading">' +
				'<span class="spinner is-active"></span>' +
				'<span>' + escapeHtml( i18n.thinking || 'Thinking...' ) + '</span>' +
			'</div>'
		);
		$messagesContainer.scrollTop( $messagesContainer[0].scrollHeight );

		// Prepare request data.
		var requestData = {
			messages: messages,
			post_context: getPostContext()
		};

		// Make API request.
		wp.apiFetch( {
			path: '/seopress/v1/ai/chat',
			method: 'POST',
			data: requestData
		} ).then( function( response ) {
			messages.push( {
				role: 'assistant',
				content: response.content
			} );
			saveMessages();
		} ).catch( function( error ) {
			var errorMsg = error.message || ( i18n.error || 'Sorry, an error occurred. Please try again.' );
			messages.push( {
				role: 'assistant',
				content: errorMsg,
				isError: true
			} );
			saveMessages();
		} ).finally( function() {
			isLoading = false;
			updateUILoading( false );
			$( '.seopress-ai-assistant-loading' ).remove();
			renderMessages();
		} );
	}

	/**
	 * Get the post language.
	 *
	 * @return {string} Language code.
	 */
	function getPostLanguage() {
		// Try WPML.
		if ( window.icl_lang ) {
			return window.icl_lang;
		}
		// Fall back to document language.
		return document.documentElement.lang || 'en';
	}

	/**
	 * Get post context for Classic Editor.
	 *
	 * @return {Object|null} Post context or null.
	 */
	function getPostContext() {
		if ( ! includeContext || config.context !== 'classic-editor' ) {
			return null;
		}

		var title = $( '#title' ).val() || '';
		var content = '';

		// Try to get content from TinyMCE if available.
		if ( typeof tinyMCE !== 'undefined' && tinyMCE.get( 'content' ) ) {
			content = tinyMCE.get( 'content' ).getContent( { format: 'text' } ) || '';
		} else {
			content = $( '#content' ).val() || '';
		}

		var excerpt = $( '#excerpt' ).val() || '';

		var postContext = {
			title: title,
			excerpt: excerpt,
			content: content.substring( 0, 1500 ),
			url: $( '#sample-permalink a' ).attr( 'href' ) || window.location.href,
			language: getPostLanguage()
		};

		// Add SEO data if available.
		if ( seoData ) {
			if ( seoData.targetKeywords && seoData.targetKeywords.length > 0 ) {
				postContext.target_keywords = seoData.targetKeywords;
			}
			if ( seoData.analysisResults && seoData.analysisResults.length > 0 ) {
				postContext.analysis_results = seoData.analysisResults;
			}
		}

		return postContext;
	}

	/**
	 * Update UI loading state.
	 *
	 * @param {boolean} loading Whether loading.
	 */
	function updateUILoading( loading ) {
		var $panel = $( '#seopress-ai-assistant-panel' );
		var $textarea = $panel.find( 'textarea' );
		var $submitBtn = $panel.find( 'form button[type="submit"]' );

		$textarea.prop( 'disabled', loading );
		$submitBtn.prop( 'disabled', loading || ! $textarea.val().trim() );
	}

	/**
	 * Auto-resize textarea.
	 *
	 * @param {HTMLElement} textarea Textarea element.
	 */
	function autoResizeTextarea( textarea ) {
		textarea.style.height = 'auto';
		textarea.style.height = Math.min( textarea.scrollHeight, 120 ) + 'px';
	}

	/**
	 * Format message content with markdown rendering.
	 *
	 * @param {string} text Text content.
	 * @return {string} Formatted HTML.
	 */
	function formatContent( text ) {
		if ( ! text ) {
			return '';
		}

		// Split into lines for processing.
		var lines = text.split( '\n' );
		var html = '';
		var inList = false;
		var listType = '';

		lines.forEach( function( line, index ) {
			var trimmedLine = line.trim();

			// Headers - apply inline formatting too.
			if ( trimmedLine.match( /^#### (.+)$/ ) ) {
				if ( inList ) {
					html += '</' + listType + '>';
					inList = false;
				}
				html += '<h4>' + formatInline( escapeHtml( trimmedLine.replace( /^#### /, '' ) ) ) + '</h4>';
			} else if ( trimmedLine.match( /^### (.+)$/ ) ) {
				if ( inList ) {
					html += '</' + listType + '>';
					inList = false;
				}
				html += '<h3>' + formatInline( escapeHtml( trimmedLine.replace( /^### /, '' ) ) ) + '</h3>';
			} else if ( trimmedLine.match( /^## (.+)$/ ) ) {
				if ( inList ) {
					html += '</' + listType + '>';
					inList = false;
				}
				html += '<h2>' + formatInline( escapeHtml( trimmedLine.replace( /^## /, '' ) ) ) + '</h2>';
			}
			// Unordered list items.
			else if ( trimmedLine.match( /^[-*] (.+)$/ ) ) {
				if ( ! inList || listType !== 'ul' ) {
					if ( inList ) {
						html += '</' + listType + '>';
					}
					html += '<ul>';
					inList = true;
					listType = 'ul';
				}
				var listContent = trimmedLine.replace( /^[-*] /, '' );
				html += '<li>' + formatInline( escapeHtml( listContent ) ) + '</li>';
			}
			// Ordered list items.
			else if ( trimmedLine.match( /^\d+\. (.+)$/ ) ) {
				if ( ! inList || listType !== 'ol' ) {
					if ( inList ) {
						html += '</' + listType + '>';
					}
					html += '<ol>';
					inList = true;
					listType = 'ol';
				}
				var olContent = trimmedLine.replace( /^\d+\. /, '' );
				html += '<li>' + formatInline( escapeHtml( olContent ) ) + '</li>';
			}
			// Empty line.
			else if ( trimmedLine === '' ) {
				if ( inList ) {
					html += '</' + listType + '>';
					inList = false;
				}
			}
			// Regular paragraph.
			else {
				if ( inList ) {
					html += '</' + listType + '>';
					inList = false;
				}
				html += '<p>' + formatInline( escapeHtml( trimmedLine ) ) + '</p>';
			}
		} );

		// Close any remaining list.
		if ( inList ) {
			html += '</' + listType + '>';
		}

		return html;
	}

	/**
	 * Format inline markdown elements.
	 *
	 * @param {string} text Escaped text.
	 * @return {string} Formatted text.
	 */
	function formatInline( text ) {
		return text
			.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
			.replace( /\*(.+?)\*/g, '<em>$1</em>' )
			.replace( /`(.+?)`/g, '<code>$1</code>' );
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} text Text to escape.
	 * @return {string} Escaped text.
	 */
	function escapeHtml( text ) {
		if ( ! text ) {
			return '';
		}
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	// Initialize when document is ready.
	$( document ).ready( init );

} )( jQuery );
