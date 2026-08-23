( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const { __ } = wp.i18n;

	registerBlockType( 'corsivo-mail/newsletter', {
		edit: function ( props ) {
			const values = props.attributes.values || {};
			const blockProps = useBlockProps( { className: 'corsivo-mail-newsletter-optin' } );
			function setValue( key, value ) {
				const next = Object.assign( {}, values );
				next[ key ] = value;
				props.setAttributes( { values: next } );
			}
			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Iscrizione newsletter', 'corsivo-mail' ), initialOpen: true },
						el( TextControl, { label: __( 'Etichetta', 'corsivo-mail' ), value: values.label || '', onChange: function ( value ) { setValue( 'label', value ); } } ),
						el( TextControl, { label: __( 'Nota privacy', 'corsivo-mail' ), value: values.privacy_text || '', onChange: function ( value ) { setValue( 'privacy_text', value ); } } ),
						el( TextControl, { label: __( 'Link informativa privacy', 'corsivo-mail' ), value: values.privacy_url || '', onChange: function ( value ) { setValue( 'privacy_url', value ); } } )
					)
				),
				el( 'div', blockProps,
					el( 'label', {},
						el( 'input', { type: 'checkbox', disabled: true } ),
						' ' + ( values.label || __( 'Voglio ricevere la newsletter', 'corsivo-mail' ) )
					),
					values.privacy_text ? el( 'small', { style: { display: 'block', marginTop: '4px' } }, values.privacy_text ) : null
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
