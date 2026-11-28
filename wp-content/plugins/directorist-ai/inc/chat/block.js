(function (wp) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	wp.blocks.registerBlockType('directorist-ai/chat', {
		title: __('Directorist AI Chat', 'directorist-ai'),
		icon: 'format-chat',
		category: 'widgets',
		attributes: {
			preset_id: { type: 'string', default: '' },
			directory_id: { type: 'string', default: '' },
			mode: { type: 'string', default: 'inline' }
		},
		edit: function (props) {
			return el(wp.element.Fragment, {},
				el(wp.blockEditor.InspectorControls, {},
					el(wp.components.PanelBody, { title: __('Chat placement', 'directorist-ai') },
						el(wp.components.TextControl, { label: __('Preset ID', 'directorist-ai'), value: props.attributes.preset_id, onChange: function (value) { props.setAttributes({ preset_id: value }); } }),
						el(wp.components.TextControl, { label: __('Directory ID', 'directorist-ai'), value: props.attributes.directory_id, onChange: function (value) { props.setAttributes({ directory_id: value }); } }),
						el(wp.components.SelectControl, { label: __('Display', 'directorist-ai'), value: props.attributes.mode, options: [{ label: __('Inline', 'directorist-ai'), value: 'inline' }, { label: __('Launcher', 'directorist-ai'), value: 'launcher' }], onChange: function (value) { props.setAttributes({ mode: value }); } })
					)
				),
				el('div', { className: 'directorist-ai-pro-block-preview' },
					el('strong', {}, __('Directorist AI Chat', 'directorist-ai')),
					el('p', {}, __('Sample preview: How can I help you find a listing?', 'directorist-ai'))
				)
			);
		},
		save: function () { return null; }
	});
})(window.wp);
