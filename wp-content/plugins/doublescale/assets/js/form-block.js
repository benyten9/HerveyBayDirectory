/**
 * "DoubleScale Form" block: pick a form, see it in the editor, render it on the site.
 *
 * Plain script (no build step); the form itself is drawn by the server render.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var components = wp.components;
	var blockEditor = wp.blockEditor;
	var ServerSideRender = wp.serverSideRender;
	var settings = window.doublescaleFormBlock || {};

	function useForms() {
		var state = useState( null );
		var forms = state[ 0 ];
		var setForms = state[ 1 ];
		useEffect( function () {
			wp.apiFetch( { path: '/doublescale/v1/forms?form_type=doublescale&per_page=200&orderby=name&order=asc' } )
				.then( function ( response ) {
					setForms( ( response && response.data ) || [] );
				} )
				.catch( function () {
					setForms( [] );
				} );
		}, [] );
		return forms;
	}

	function Edit( props ) {
		var formId = props.attributes.formId;
		var forms = useForms();
		var blockProps = blockEditor.useBlockProps();

		var options = [ { label: __( 'Choose a form…', 'doublescale' ), value: 0 } ].concat(
			( forms || [] ).map( function ( form ) {
				var label = form.name + ( form.status !== 'active' ? ' (' + __( 'draft', 'doublescale' ) + ')' : '' );
				return { label: label, value: form.id };
			} )
		);

		var picker = el( components.SelectControl, {
			label: __( 'Form', 'doublescale' ),
			value: formId,
			options: options,
			onChange: function ( value ) {
				props.setAttributes( { formId: parseInt( value, 10 ) || 0 } );
			},
			__nextHasNoMarginBottom: true,
		} );

		var inspector = el(
			blockEditor.InspectorControls,
			null,
			el( components.PanelBody, { title: __( 'Form', 'doublescale' ) }, picker )
		);

		if ( ! formId ) {
			return el(
				'div',
				blockProps,
				inspector,
				el(
					components.Placeholder,
					{
						icon: 'feedback',
						label: __( 'DoubleScale Form', 'doublescale' ),
						instructions: forms && forms.length === 0
							? __( 'You have no forms yet. Build one under DoubleScale → Forms.', 'doublescale' )
							: __( 'Choose the form to show here.', 'doublescale' ),
					},
					forms === null ? el( components.Spinner ) : picker,
					forms && forms.length === 0 && settings.formsUrl
						? el( components.Button, { variant: 'secondary', href: settings.formsUrl, target: '_blank' }, __( 'Build a form', 'doublescale' ) )
						: null
				)
			);
		}

		return el(
			'div',
			blockProps,
			inspector,
			el( ServerSideRender, { block: 'doublescale/form', attributes: { formId: formId } } )
		);
	}

	wp.blocks.registerBlockType( 'doublescale/form', {
		apiVersion: 3,
		title: __( 'DoubleScale Form', 'doublescale' ),
		description: __( 'Show a form built with DoubleScale. Answers go straight to your CRM.', 'doublescale' ),
		category: 'widgets',
		icon: 'feedback',
		keywords: [ 'form', 'contact', 'lead', 'doublescale' ],
		attributes: { formId: { type: 'number', default: 0 } },
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
