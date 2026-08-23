( function ( wp, corsivoUi ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, useEffect, Fragment } = wp.element;
	const { useBlockProps } = wp.blockEditor;
	const { TextControl, SelectControl, ColorPalette } = wp.components;
	const { useSelect } = wp.data;
	const { useEntityProp } = wp.coreData;
	const { registerPlugin } = wp.plugins;
	const { __ } = wp.i18n;
	const { ColorControl } = corsivoUi;

	registerBlockType( 'corsivo-mail/content-slot', {
		edit: function () {
			const blockProps = useBlockProps( { className: 'corsivo-mail-content-slot' } );
			return el(
				'div',
				blockProps,
				el( 'span', { className: 'corsivo-mail-content-slot__label' }, __( 'Contenuto email', 'corsivo-mail' ) ),
				el(
					'p',
					{ className: 'corsivo-mail-content-slot__hint' },
					__( 'Qui viene iniettato il messaggio dinamico: il testo passato dal trigger o da wp_mail.', 'corsivo-mail' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'corsivo-mail/footer', {
		attributes: { footer_id: { type: 'integer', default: 0 } },
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: 'corsivo-mail-content-slot' } );
			const footers = useSelect( function ( s ) {
				return s( 'core' ).getEntityRecords( 'postType', 'corsivo_mail_footer', { per_page: -1, status: 'publish,draft' } );
			}, [] );
			const options = [ { label: __( '— Scegli un footer —', 'corsivo-mail' ), value: '' } ].concat(
				( footers || [] ).map( function ( f ) { return { label: ( f.title && f.title.rendered ) || '(senza titolo)', value: String( f.id ) }; } )
			);
			return el(
				'div',
				blockProps,
				el( 'span', { className: 'corsivo-mail-content-slot__label' }, __( 'Footer email', 'corsivo-mail' ) ),
				el( SelectControl, {
					label: __( 'Quale footer', 'corsivo-mail' ),
					value: String( props.attributes.footer_id || '' ),
					options: options,
					onChange: function ( v ) { props.setAttributes( { footer_id: parseInt( v, 10 ) || 0 } ); },
				} )
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'corsivo-mail/button', {
		apiVersion: 2,
		title: __( 'Bottone email', 'corsivo-mail' ),
		category: 'design',
		icon: 'button',
		attributes: {
			text: { type: 'string', default: 'Apri' },
			url: { type: 'string', default: '' },
			align: { type: 'string', default: 'left' },
		},
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: 'corsivo-mail-button-edit' } );
			const a = props.attributes;
			return el( 'div', blockProps,
				el( TextControl, { label: __( 'Testo del bottone', 'corsivo-mail' ), value: a.text, onChange: function ( v ) { props.setAttributes( { text: v } ); } } ),
				el( TextControl, { label: __( 'URL (accetta merge tag, es. {{magic_link}})', 'corsivo-mail' ), value: a.url, onChange: function ( v ) { props.setAttributes( { url: v } ); } } ),
				el( 'div', { style: { marginTop: '8px' } }, el( 'span', { style: { display: 'inline-block', background: '#0067b8', color: '#fff', padding: '10px 22px', borderRadius: '4px', fontWeight: '600' } }, a.text || 'Apri' ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	function FooterColorsPanel() {
		const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
		if ( ! Panel ) { return null; }

		const postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		const palette = useSelect( function ( s ) {
			const set = s( 'core/block-editor' ).getSettings();
			return ( set && set.colors ) || [];
		}, [] );
		const m = meta || {};

		useEffect( function () {
			if ( postType !== 'corsivo_mail_footer' ) { return; }
			const css = '.editor-styles-wrapper{' + ( m.corsivo_mail_footer_bg ? 'background:' + m.corsivo_mail_footer_bg + ';' : '' ) + ( m.corsivo_mail_footer_text ? 'color:' + m.corsivo_mail_footer_text + ';' : '' ) + '}';
			const iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
			const doc = iframe && iframe.contentDocument ? iframe.contentDocument : document;
			let style = doc.getElementById( 'corsivo-mail-footer-style' );
			if ( ! style ) { style = doc.createElement( 'style' ); style.id = 'corsivo-mail-footer-style'; ( doc.head || doc.documentElement ).appendChild( style ); }
			style.textContent = css;
		} );

		if ( postType !== 'corsivo_mail_footer' ) { return null; }

		function setFooterMeta( key, value ) { const o = {}; o[ key ] = typeof value === 'boolean' ? value : ( value || '' ); setMeta( Object.assign( {}, m, o ) ); }

		return el( Panel, { name: 'corsivo-mail-footer-options', title: __( 'Footer', 'corsivo-mail' ) },
			ColorControl ? el( ColorControl, {
				label: __( 'Sfondo', 'corsivo-mail' ),
				value: m.corsivo_mail_footer_bg || '',
				palette: palette,
				colorLabel: __( 'Scegli colore di sfondo', 'corsivo-mail' ),
				customColorLabel: __( 'Scegli colore personalizzato', 'corsivo-mail' ),
				emptyColorLabel: __( 'Nessun colore', 'corsivo-mail' ),
				clearLabel: __( 'Azzera sfondo', 'corsivo-mail' ),
				onChange: function ( v ) { setFooterMeta( 'corsivo_mail_footer_bg', v ); },
			} ) : el( Fragment, {},
				el( 'p', { style: { fontWeight: 600, margin: '0 0 6px' } }, __( 'Sfondo', 'corsivo-mail' ) ),
				el( ColorPalette, { value: m.corsivo_mail_footer_bg || '', colors: palette, onChange: function ( v ) { setFooterMeta( 'corsivo_mail_footer_bg', v ); } } )
			),
			ColorControl ? el( ColorControl, {
				label: __( 'Testo', 'corsivo-mail' ),
				value: m.corsivo_mail_footer_text || '',
				palette: palette,
				colorLabel: __( 'Scegli colore del testo', 'corsivo-mail' ),
				customColorLabel: __( 'Scegli colore personalizzato', 'corsivo-mail' ),
				emptyColorLabel: __( 'Nessun colore', 'corsivo-mail' ),
				clearLabel: __( 'Azzera colore del testo', 'corsivo-mail' ),
				onChange: function ( v ) { setFooterMeta( 'corsivo_mail_footer_text', v ); },
			} ) : el( Fragment, {},
				el( 'p', { style: { fontWeight: 600, margin: '16px 0 6px' } }, __( 'Testo', 'corsivo-mail' ) ),
				el( ColorPalette, { value: m.corsivo_mail_footer_text || '', colors: palette, onChange: function ( v ) { setFooterMeta( 'corsivo_mail_footer_text', v ); } } )
			)
		);
	}

	registerPlugin( 'corsivo-mail-footer-colors', { render: FooterColorsPanel } );
} )( window.wp, window.CorsivoMailAdminUI || {} );
