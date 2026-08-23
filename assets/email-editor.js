( function ( wp, corsivoUi ) {
	const { registerPlugin } = wp.plugins;
	const editorPkg = wp.editor || {};
	const editPostPkg = wp.editPost || {};
	const Panel = editorPkg.PluginDocumentSettingPanel || editPostPkg.PluginDocumentSettingPanel;
	const PluginSidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
	const PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem || editPostPkg.PluginSidebarMoreMenuItem;
	const { createElement: el, useState, useEffect, useRef, Fragment } = wp.element;
	const { SelectControl, ComboboxControl, TextControl, TextareaControl, ToggleControl, CheckboxControl, ColorPalette, Button, Modal, Spinner } = wp.components;
	const { useSelect, useDispatch, select } = wp.data;
	const { useEntityProp } = wp.coreData;
	const { parse } = wp.blocks;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;
	const {
		AsyncCombobox,
		AsyncMultiSelect,
		ColorControl,
		DateTimeControl,
	} = corsivoUi;

	const C = CorsivoMailEmail;

	function toOptions( map, placeholder ) {
		const out = placeholder !== undefined ? [ { label: placeholder, value: '' } ] : [];
		Object.keys( map ).forEach( function ( key ) {
			const item = map[ key ];
			out.push( { label: item && item.label ? item.label : item, value: key } );
		} );
		return out;
	}

	function parseEmailList( value ) {
		if ( typeof corsivoUi.parseEmailList === 'function' ) {
			return corsivoUi.parseEmailList( value );
		}
		return String( value || '' )
			.split( /[\r\n,;]+/ )
			.map( function ( email ) { return email.trim(); } )
			.filter( Boolean );
	}

	function serializeEmailList( values ) {
		if ( typeof corsivoUi.serializeEmailList === 'function' ) {
			return corsivoUi.serializeEmailList( values );
		}
		return ( Array.isArray( values ) ? values : [] ).join( '\n' );
	}

	function toUserOption( user ) {
		return {
			label: ( user.name || user.email || ( '#' + user.id ) ) + ' (#' + user.id + ')',
			value: String( user.id ),
			user: user,
		};
	}

	function loadUserOptions( query, context ) {
		const search = String( query || '' ).trim();
		return apiFetch( {
			path: '/corsivo/v1/mail/users' + ( search ? '?search=' + encodeURIComponent( search ) : '' ),
			signal: context && context.signal,
		} ).then( function ( users ) {
			return ( users || [] ).map( toUserOption );
		} );
	}

	function manualRecipientsControl( key, value, onChange, disabled ) {
		const label = __( 'Indirizzi email', 'corsivo-mail' );
		const help = __( 'Scrivi un indirizzo e premi Invio. Puoi anche incollare gli indirizzi esistenti.', 'corsivo-mail' );
		if ( ! AsyncMultiSelect ) {
			return el( TextareaControl, {
				key: key,
				label: __( 'Indirizzi (uno per riga o separati da virgola)', 'corsivo-mail' ),
				value: value || '',
				disabled: disabled,
				onChange: onChange,
			} );
		}

		return el( AsyncMultiSelect, {
			key: key,
			label: label,
			help: help,
			value: parseEmailList( value ),
			disabled: disabled,
			minQueryLength: 0,
			placeholder: __( 'Aggiungi un indirizzo email…', 'corsivo-mail' ),
			noOptionsMessage: __( 'Scrivi un indirizzo email e premi Invio.', 'corsivo-mail' ),
			selectedItemsLabel: __( 'Indirizzi selezionati', 'corsivo-mail' ),
			clearLabel: __( 'Azzera selezione', 'corsivo-mail' ),
			formatRemoveLabel: function ( email ) {
				return __( 'Rimuovi', 'corsivo-mail' ) + ' ' + email;
			},
			createOption: function ( inputValue ) {
				const email = String( inputValue || '' ).trim();
				return email ? { label: email, value: email } : null;
			},
			onChange: function ( nextValues ) {
				onChange( serializeEmailList( nextValues ) );
			},
		} );
	}

	function copyTag( tag ) {
		if ( navigator.clipboard ) { navigator.clipboard.writeText( tag ); }
	}

	function createOperationId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2 );
	}

	function operationStorageKey( postId ) {
		return 'corsivo-mail-batch-operation-' + postId;
	}

	function saveOperation( postId, operation ) {
		try {
			window.sessionStorage.setItem( operationStorageKey( postId ), JSON.stringify( operation ) );
		} catch {}
	}

	function loadOperation( postId ) {
		try {
			const stored = JSON.parse( window.sessionStorage.getItem( operationStorageKey( postId ) ) || 'null' );
			if ( stored && typeof stored.id === 'string' && stored.id ) {
				return { id: stored.id, completed: !! stored.completed };
			}
		} catch {}
		const operation = { id: createOperationId(), completed: false };
		saveOperation( postId, operation );
		return operation;
	}

	function chip( tag ) {
		return el( Button, {
			key: tag,
			variant: 'tertiary',
			title: __( 'Clicca per copiare', 'corsivo-mail' ),
			onClick: function () { copyTag( '{{' + tag + '}}' ); },
			style: { minHeight: 0, height: 'auto', background: '#f0f0f1', padding: '3px 7px', borderRadius: '3px', margin: '0 5px 5px 0', fontSize: '12px' },
		}, el( 'code', {}, '{{' + tag + '}}' ) );
	}

	function tagSection( title, list ) {
		return el( 'div', { style: { marginBottom: '16px' } },
			el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, title ),
			list.length ? list.map( chip ) : el( 'span', { style: { color: '#646970', fontSize: '12px' } }, '—' )
		);
	}

	function statusBadge( status ) {
		const map = { '': [ '—', '#999' ], sending: [ 'invio…', '#996300' ], sent: [ 'inviata', '#1a7f37' ], queued: [ 'programmata', '#2271b1' ], suppressed: [ 'soppressa', '#996300' ], failed: [ 'errore', '#b32d2e' ] };
		const s = map[ status ] || map[ '' ];
		return el( 'span', { style: { color: s[ 1 ], fontSize: '12px', whiteSpace: 'nowrap' } }, s[ 0 ] );
	}

	function MergeTagsModal( props ) {
		const [ metaKeys, setMetaKeys ] = useState( [] );
		useEffect( function () {
			apiFetch( { path: '/corsivo/v1/mail/user-meta-keys' } ).then( setMetaKeys ).catch( function () {} );
		}, [] );

		return el( Modal, { title: __( 'Merge tag disponibili', 'corsivo-mail' ), onRequestClose: props.onClose, style: { width: '640px', maxWidth: '95vw' } },
			el( 'p', { style: { color: '#646970', marginTop: 0 } }, __( 'Clicca un tag per copiarlo, poi incollalo nel testo o nell\'oggetto.', 'corsivo-mail' ) ),
			el( 'div', { style: { maxHeight: '60vh', overflowY: 'auto' } },
				tagSection( __( 'Generali', 'corsivo-mail' ), C.baseTags ),
				tagSection( __( 'Utente', 'corsivo-mail' ), C.userTags.concat( props.extraTags || [] ) ),
				tagSection( __( 'Meta utente', 'corsivo-mail' ), metaKeys.map( function ( k ) { return 'meta:' + k; } ) )
			)
		);
	}

	function PreviewModal( props ) {
		const [ user, setUser ] = useState( '' );
		const [ users, setUsers ] = useState( [] );
		useEffect( function () {
			if ( AsyncCombobox ) { return undefined; }
			apiFetch( { path: '/corsivo/v1/mail/users' } ).then( setUsers ).catch( function () {} );
			return undefined;
		}, [] );

		return el( Modal, { title: __( 'Anteprima email', 'corsivo-mail' ), onRequestClose: props.onClose, style: { width: '680px', maxWidth: '95vw' } },
			el( AsyncCombobox || ComboboxControl, AsyncCombobox ? {
				label: __( 'Simula come utente', 'corsivo-mail' ),
				help: __( 'Per vedere i merge tag risolti coi suoi dati. Svuota per dati di esempio.', 'corsivo-mail' ),
				value: user,
				loadOptions: loadUserOptions,
				minQueryLength: 0,
				placeholder: __( 'Cerca un utente…', 'corsivo-mail' ),
				noOptionsMessage: __( 'Nessun utente trovato.', 'corsivo-mail' ),
				loadingMessage: __( 'Ricerca utenti…', 'corsivo-mail' ),
				loadErrorMessage: __( 'Ricerca utenti non riuscita.', 'corsivo-mail' ),
				clearLabel: __( 'Azzera utente simulato', 'corsivo-mail' ),
				onChange: function ( value ) { setUser( value || '' ); },
			} : {
				label: __( 'Simula come utente', 'corsivo-mail' ),
				help: __( 'Per vedere i merge tag risolti coi suoi dati. Svuota per dati di esempio.', 'corsivo-mail' ),
				value: user,
				options: ( users || [] ).map( function ( u ) { return { label: ( u.name || ( '#' + u.id ) ) + ' (#' + u.id + ')', value: String( u.id ) }; } ),
				onChange: function ( v ) { setUser( v || '' ); },
			} ),
			el( 'iframe', { title: __( 'Anteprima email', 'corsivo-mail' ), sandbox: '', referrerPolicy: 'no-referrer', src: C.previewBase + '&post=' + props.postId + ( user ? '&user=' + user : '' ), style: { width: '100%', height: '64vh', border: '1px solid #dcdcde', marginTop: '8px' } } )
		);
	}

	function triggerFields( m, setM ) {
		const trigger = m.corsivo_mail_trigger_type || 'manual';
		const freq = m.corsivo_mail_schedule_freq || 'daily';
		const audience = m.corsivo_mail_audience_type || 'manual';
		const fields = [];

		fields.push( el( SelectControl, {
			key: 'trigger', label: __( 'Quando si invia', 'corsivo-mail' ), value: trigger,
			options: [
				{ label: __( 'Manuale (dalla finestra di invio)', 'corsivo-mail' ), value: 'manual' },
				{ label: __( 'Sostituisci un\'email di WordPress', 'corsivo-mail' ), value: 'core_email' },
				{ label: __( 'Su evento', 'corsivo-mail' ), value: 'event' },
				{ label: __( 'Programmata (a orario)', 'corsivo-mail' ), value: 'schedule' },
			],
			onChange: function ( v ) { setM( 'corsivo_mail_trigger_type', v ); },
		} ) );
		fields.push( el( SelectControl, {
			key: 'account', label: __( 'Account mittente', 'corsivo-mail' ), value: m.corsivo_mail_account_id || '',
			options: toOptions( C.accounts || {}, __( '— Account predefinito —', 'corsivo-mail' ) ),
			onChange: function ( v ) { setM( 'corsivo_mail_account_id', v ); },
		} ) );
		fields.push( el( SelectControl, {
			key: 'message-type', label: __( 'Tipo messaggio', 'corsivo-mail' ), value: m.corsivo_mail_message_type || 'transactional',
			options: [
				{ label: __( 'Transazionale', 'corsivo-mail' ), value: 'transactional' },
				{ label: __( 'Marketing / newsletter', 'corsivo-mail' ), value: 'marketing' },
			],
			help: __( 'Il link di disiscrizione viene aggiunto solo ai messaggi marketing inviati a un singolo destinatario.', 'corsivo-mail' ),
			onChange: function ( v ) { setM( 'corsivo_mail_message_type', v ); },
		} ) );

		if ( trigger === 'core_email' ) {
			fields.push( el( SelectControl, { key: 'core', label: __( 'Quale email di WordPress', 'corsivo-mail' ), help: __( 'Questa email sostituisce quella standard di WordPress.', 'corsivo-mail' ), value: m.corsivo_mail_core_email || '', options: toOptions( C.providers, __( '— Seleziona —', 'corsivo-mail' ) ), onChange: function ( v ) { setM( 'corsivo_mail_core_email', v ); } } ) );
		}
		if ( trigger === 'event' ) {
			fields.push( el( SelectControl, { key: 'event', label: __( 'Quale evento', 'corsivo-mail' ), value: m.corsivo_mail_event_id || '', options: toOptions( C.events, __( '— Seleziona —', 'corsivo-mail' ) ), onChange: function ( v ) { setM( 'corsivo_mail_event_id', v ); } } ) );
		}
		if ( trigger === 'schedule' ) {
			fields.push( el( SelectControl, { key: 'freq', label: __( 'Frequenza', 'corsivo-mail' ), value: freq, options: [ { label: __( 'Una volta', 'corsivo-mail' ), value: 'once' }, { label: __( 'Ogni giorno', 'corsivo-mail' ), value: 'daily' }, { label: __( 'Ogni settimana', 'corsivo-mail' ), value: 'weekly' }, { label: __( 'Ogni mese', 'corsivo-mail' ), value: 'monthly' } ], onChange: function ( v ) { setM( 'corsivo_mail_schedule_freq', v ); } } ) );
			if ( freq === 'once' ) {
				fields.push( el( DateTimeControl || TextControl, DateTimeControl ? {
					key: 'date',
					mode: 'date',
					label: __( 'Data', 'corsivo-mail' ),
					value: m.corsivo_mail_schedule_date || '',
					placeholder: __( 'Seleziona data', 'corsivo-mail' ),
					clearLabel: __( 'Azzera data', 'corsivo-mail' ),
					onChange: function ( v ) { setM( 'corsivo_mail_schedule_date', v ); },
				} : { key: 'date', type: 'date', label: __( 'Data', 'corsivo-mail' ), value: m.corsivo_mail_schedule_date || '', onChange: function ( v ) { setM( 'corsivo_mail_schedule_date', v ); } } ) );
			}
			if ( freq === 'weekly' ) {
				fields.push( el( SelectControl, { key: 'weekday', label: __( 'Giorno della settimana', 'corsivo-mail' ), value: String( m.corsivo_mail_schedule_day != null ? m.corsivo_mail_schedule_day : 1 ), options: [ { label: 'Lunedì', value: '1' }, { label: 'Martedì', value: '2' }, { label: 'Mercoledì', value: '3' }, { label: 'Giovedì', value: '4' }, { label: 'Venerdì', value: '5' }, { label: 'Sabato', value: '6' }, { label: 'Domenica', value: '0' } ], onChange: function ( v ) { setM( 'corsivo_mail_schedule_day', parseInt( v, 10 ) ); } } ) );
			}
			if ( freq === 'monthly' ) {
				fields.push( el( TextControl, { key: 'monthday', type: 'number', min: 1, max: 31, label: __( 'Giorno del mese', 'corsivo-mail' ), value: String( m.corsivo_mail_schedule_day != null ? m.corsivo_mail_schedule_day : 1 ), onChange: function ( v ) { setM( 'corsivo_mail_schedule_day', parseInt( v, 10 ) || 1 ); } } ) );
			}
			fields.push( el( DateTimeControl || TextControl, DateTimeControl ? {
				key: 'time',
				mode: 'time',
				label: __( 'Orario', 'corsivo-mail' ),
				value: m.corsivo_mail_schedule_time || '09:00',
				timeLabel: __( 'Orario', 'corsivo-mail' ),
				clearLabel: __( 'Azzera orario', 'corsivo-mail' ),
				onChange: function ( v ) { setM( 'corsivo_mail_schedule_time', v ); },
			} : { key: 'time', type: 'time', label: __( 'Orario', 'corsivo-mail' ), value: m.corsivo_mail_schedule_time || '09:00', onChange: function ( v ) { setM( 'corsivo_mail_schedule_time', v ); } } ) );

			fields.push( el( SelectControl, { key: 'aud', label: __( 'Destinatari', 'corsivo-mail' ), value: audience, options: toOptions( C.audiences ), onChange: function ( v ) { setM( 'corsivo_mail_audience_type', v ); } } ) );
			if ( audience === 'manual' ) {
				fields.push( manualRecipientsControl( 'emails', m.corsivo_mail_audience_emails || '', function ( v ) { setM( 'corsivo_mail_audience_emails', v ); }, false ) );
			}
			if ( audience === 'role' ) {
				fields.push( el( SelectControl, { key: 'role', label: __( 'Ruolo', 'corsivo-mail' ), value: m.corsivo_mail_audience_role || '', options: toOptions( C.roles, __( '— Seleziona —', 'corsivo-mail' ) ), onChange: function ( v ) { setM( 'corsivo_mail_audience_role', v ); } } ) );
			}
		}

		return fields;
	}

	function AutomationPanel() {
		if ( ! Panel ) { return null; }
		const postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		if ( postType !== C.postType ) { return null; }
		const m = meta || {};
		function setM( key, value ) { const o = {}; o[ key ] = value; setMeta( Object.assign( {}, m, o ) ); }

		return el( Panel, { name: 'corsivo-mail-automation', title: __( 'Automazione', 'corsivo-mail' ) },
			triggerFields( m, setM ),
			el( 'p', { style: { color: '#646970', fontSize: '12px', marginBottom: 0 } }, __( 'Salva il post per attivare il comportamento scelto.', 'corsivo-mail' ) )
		);
	}

	function SendModal( props ) {
		const { postId, onClose } = props;
		const [ meta, setMeta ] = useEntityProp( 'postType', C.postType, 'meta' );
		const title = useSelect( function ( s ) { return s( 'core/editor' ).getEditedPostAttribute( 'title' ); }, [] );
		const { editPost, savePost } = useDispatch( 'core/editor' );
		const isDirty = useSelect( function ( s ) { return s( 'core/editor' ).isEditedPostDirty(); }, [] );
		const m = meta || {};
		function setM( key, value ) { const o = {}; o[ key ] = value; setMeta( Object.assign( {}, m, o ) ); }

		const trigger = m.corsivo_mail_trigger_type || 'manual';
		const audience = m.corsivo_mail_audience_type || 'manual';

		const [ rows, setRows ] = useState( [] );
		const [ loadingRows, setLoadingRows ] = useState( false );
		const [ rowsError, setRowsError ] = useState( '' );
		const [ scheduleAt, setScheduleAt ] = useState( '' );
		const [ sending, setSending ] = useState( false );
		const [ operation, setOperation ] = useState( function () { return loadOperation( postId ); } );
		const [ addOptions, setAddOptions ] = useState( [] );
		const [ previewRow, setPreviewRow ] = useState( null );
		const contactsRequest = useRef( 0 );

		useEffect( function () {
			let active = true;
			setRows( [] );
			setRowsError( '' );
			setLoadingRows( true );
			const timer = setTimeout( function () {
				apiFetch( { path: '/corsivo/v1/mail/recipients', method: 'POST', data: {
					post: postId,
					audience_type: audience,
					audience_role: m.corsivo_mail_audience_role || '',
					audience_emails: m.corsivo_mail_audience_emails || '',
				} } )
					.then( function ( list ) { if ( active ) { setRows( ( list || [] ).map( function ( r ) { return Object.assign( {}, r, { enabled: true, status: '' } ); } ) ); } } )
					.catch( function ( error ) { if ( active ) { setRowsError( ( error && error.message ) || __( 'Destinatari non disponibili.', 'corsivo-mail' ) ); } } )
					.finally( function () { if ( active ) { setLoadingRows( false ); } } );
			}, 300 );
			return function () { active = false; clearTimeout( timer ); };
		}, [ postId, audience, m.corsivo_mail_audience_role, m.corsivo_mail_audience_emails ] );

		function patch( i, changes ) { setRows( function ( prev ) { const n = prev.slice(); n[ i ] = Object.assign( {}, n[ i ], changes ); return n; } ); }

		function sendAll() {
			if ( sending || operation.completed ) { return; }
			setSending( true );
			const recipients = rows.filter( function ( row ) { return row.enabled; } ).map( function ( row ) {
				return { email: row.email, user_id: row.user_id || 0 };
			} );
			setRows( function ( previous ) { return previous.map( function ( row ) { return row.enabled ? Object.assign( {}, row, { status: 'sending' } ) : row; } ); } );
			Promise.resolve( isDirty ? savePost() : null ).then( function () {
				if ( isDirty && ! wp.data.select( 'core/editor' ).didPostSaveRequestSucceed() ) {
					throw new Error( __( 'Salvataggio dell\'email non riuscito: nessun invio accodato.', 'corsivo-mail' ) );
				}
				return apiFetch( { path: '/corsivo/v1/mail/send-batch', method: 'POST', data: {
					post: postId,
					recipients: recipients,
					schedule_at: scheduleAt,
					operation_id: operation.id,
				} } );
			} ).then( function ( response ) {
				const statuses = {};
				const results = response.results || [];
				results.forEach( function ( result ) { statuses[ result.email.toLowerCase() ] = result.status; } );
				setRows( function ( previous ) { return previous.map( function ( row ) {
					return row.enabled ? Object.assign( {}, row, { status: statuses[ row.email.toLowerCase() ] || 'failed' } ) : row;
				} ); } );
				const completed = results.length === recipients.length && ! results.some( function ( result ) { return result.status === 'failed'; } );
				if ( completed ) {
					const completedOperation = { id: operation.id, completed: true };
					saveOperation( postId, completedOperation );
					setOperation( completedOperation );
				} else {
					wp.data.dispatch( 'core/notices' ).createNotice( 'warning', __( 'Alcuni destinatari non sono stati acquisiti. Puoi riprovare la stessa operazione senza duplicare quelli già accodati.', 'corsivo-mail' ), { type: 'snackbar' } );
				}
			} ).catch( function ( error ) {
				setRows( function ( previous ) { return previous.map( function ( row ) { return row.enabled ? Object.assign( {}, row, { status: 'failed' } ) : row; } ); } );
				wp.data.dispatch( 'core/notices' ).createNotice( 'error', ( error && error.message ) || __( 'Accodamento non riuscito.', 'corsivo-mail' ), { type: 'snackbar' } );
			} ).finally( function () { setSending( false ); } );
		}

		function startNewOperation() {
			const nextOperation = { id: createOperationId(), completed: false };
			saveOperation( postId, nextOperation );
			setOperation( nextOperation );
			setRows( function ( previous ) { return previous.map( function ( row ) { return Object.assign( {}, row, { status: '' } ); } ); } );
		}

		function searchContacts( term ) {
			const request = ++contactsRequest.current;
			if ( ! term ) { setAddOptions( [] ); return; }
			apiFetch( { path: '/corsivo/v1/mail/users?search=' + encodeURIComponent( term ) } )
				.then( function ( users ) { if ( request === contactsRequest.current ) { setAddOptions( users ); } } )
				.catch( function () { if ( request === contactsRequest.current ) { setAddOptions( [] ); } } );
		}
		function addContact( userId, option ) {
			const u = option && option.user ? option.user : addOptions.filter( function ( x ) { return String( x.id ) === String( userId ); } )[ 0 ];
			if ( ! u || rows.some( function ( r ) { return r.email.toLowerCase() === u.email.toLowerCase(); } ) ) { return; }
			setRows( rows.concat( [ { email: u.email, name: u.name, user_id: u.id, enabled: true, status: '' } ] ) );
		}

		const enabledCount = rows.filter( function ( r ) { return r.enabled; } ).length;
		const fields = [];

		if ( trigger === 'manual' ) {
			fields.push( el( SelectControl, { key: 'aud', label: __( 'Destinatari', 'corsivo-mail' ), value: audience, disabled: sending, options: toOptions( C.audiences ), onChange: function ( v ) { setM( 'corsivo_mail_audience_type', v ); } } ) );
			if ( audience === 'manual' ) {
				fields.push( manualRecipientsControl( 'emails', m.corsivo_mail_audience_emails || '', function ( v ) { setM( 'corsivo_mail_audience_emails', v ); }, sending ) );
			}
			if ( audience === 'role' ) {
				fields.push( el( SelectControl, { key: 'role', label: __( 'Ruolo', 'corsivo-mail' ), value: m.corsivo_mail_audience_role || '', disabled: sending, options: toOptions( C.roles, __( '— Seleziona —', 'corsivo-mail' ) ), onChange: function ( v ) { setM( 'corsivo_mail_audience_role', v ); } } ) );
			}
		}

		fields.push( el( TextControl, { key: 'subject', label: __( 'Oggetto', 'corsivo-mail' ), help: __( 'Aggiorna anche il titolo interno dell\'email.', 'corsivo-mail' ), value: m.corsivo_mail_subject || title || '', disabled: sending, onChange: function ( v ) { editPost( { title: v } ); setM( 'corsivo_mail_subject', v ); } } ) );

		const showList = trigger === 'schedule' || trigger === 'manual';

		return el( Fragment, {},
			el( Modal, { title: __( 'Invio email', 'corsivo-mail' ), onRequestClose: function () { if ( ! sending ) { onClose(); } }, shouldCloseOnClickOutside: ! sending, shouldCloseOnEsc: ! sending, style: { width: '820px', maxWidth: '96vw' } },
			el( 'div', { style: { display: 'flex', gap: '24px', alignItems: 'flex-start' } },
				el( 'div', { style: { width: '320px', flexShrink: 0, pointerEvents: sending ? 'none' : 'auto', opacity: sending ? 0.65 : 1 } },
					el( 'h3', { style: { marginTop: 0 } }, __( 'Impostazioni invio', 'corsivo-mail' ) ),
					fields,
					el( 'p', { style: { color: '#646970', fontSize: '12px' } }, __( 'Trigger e pianificazione si impostano nel pannello "Automazione" della sidebar del documento.', 'corsivo-mail' ) )
				),
				showList ? el( 'div', { style: { flex: 1, minWidth: 0 } },
					el( 'div', { style: { display: 'flex', gap: '12px', alignItems: 'flex-end', marginBottom: '10px', flexWrap: 'wrap' } },
						el( 'div', { style: { width: '210px' } }, el( DateTimeControl || TextControl, DateTimeControl ? {
							mode: 'datetime',
							label: __( 'Programma invio (opzionale)', 'corsivo-mail' ),
							help: __( 'Vuoto = invio immediato.', 'corsivo-mail' ),
							value: scheduleAt,
							disabled: sending,
							placeholder: __( 'Seleziona data e ora', 'corsivo-mail' ),
							timeLabel: __( 'Orario', 'corsivo-mail' ),
							clearLabel: __( 'Azzera programmazione', 'corsivo-mail' ),
							onChange: setScheduleAt,
						} : { type: 'datetime-local', label: __( 'Programma invio (opzionale)', 'corsivo-mail' ), help: __( 'Vuoto = invio immediato.', 'corsivo-mail' ), value: scheduleAt, disabled: sending, onChange: setScheduleAt } ) ),
						operation.completed ? el( Button, { variant: 'secondary', disabled: sending, onClick: startNewOperation }, __( 'Nuova operazione', 'corsivo-mail' ) ) :
						el( Button, { variant: 'primary', isBusy: sending, disabled: sending || loadingRows || ! enabledCount, onClick: sendAll }, sending ? __( 'Accodamento…', 'corsivo-mail' ) : ( ( scheduleAt ? __( 'Programma per ', 'corsivo-mail' ) : __( 'Accoda per ', 'corsivo-mail' ) ) + enabledCount ) )
					),
					el( 'p', { style: { color: '#646970', fontSize: '12px', marginTop: 0 } }, operation.completed ?
						__( 'Operazione completata. Avvia una nuova operazione solo se vuoi inviare di nuovo anche agli stessi destinatari.', 'corsivo-mail' ) :
						__( 'Questa operazione resta attiva per la sessione del browser. Se la richiesta si interrompe, riprovala: i destinatari già acquisiti non vengono duplicati.', 'corsivo-mail' ) ),
					rowsError ? el( 'p', { style: { color: '#b32d2e' } }, rowsError ) : null,
					loadingRows ? el( Spinner ) : (
						! rows.length ? el( 'p', { style: { color: '#646970' } }, __( 'Nessun destinatario per questi criteri.', 'corsivo-mail' ) ) :
						el( 'div', { style: { maxHeight: '54vh', overflowY: 'auto', border: '1px solid #f0f0f1' } },
							rows.map( function ( r, i ) {
								return el( 'div', { key: r.email + i, style: { display: 'flex', alignItems: 'center', gap: '10px', padding: '7px 10px', borderBottom: '1px solid #f0f0f1', opacity: r.enabled ? 1 : 0.45 } },
									el( CheckboxControl, { checked: r.enabled, disabled: sending, onChange: function () { patch( i, { enabled: ! r.enabled } ); }, __nextHasNoMarginBottom: true } ),
									el( 'div', { style: { flex: 1, minWidth: 0 } },
										el( 'div', { style: { fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, r.name || '—' ),
										el( 'div', { style: { fontSize: '12px', color: '#646970', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, r.email )
									),
						el( Button, { variant: 'tertiary', disabled: sending || isDirty, onClick: function () { setPreviewRow( r ); } }, __( 'Anteprima', 'corsivo-mail' ) ),
									statusBadge( r.status )
								);
							} )
						)
					),
					el( 'div', { style: { marginTop: '10px' } },
						el( AsyncCombobox || ComboboxControl, AsyncCombobox ? {
							label: __( 'Aggiungi contatto', 'corsivo-mail' ),
							help: __( 'Cerca per nome, cognome, email o altri dati.', 'corsivo-mail' ),
							value: '',
							disabled: sending,
							loadOptions: loadUserOptions,
							minQueryLength: 2,
							placeholder: __( 'Cerca un contatto…', 'corsivo-mail' ),
							noOptionsMessage: __( 'Nessun contatto trovato.', 'corsivo-mail' ),
							loadingMessage: __( 'Ricerca contatti…', 'corsivo-mail' ),
							loadErrorMessage: __( 'Ricerca contatti non riuscita.', 'corsivo-mail' ),
							minimumQueryMessage: __( 'Scrivi almeno due caratteri.', 'corsivo-mail' ),
							clearLabel: __( 'Azzera contatto', 'corsivo-mail' ),
							clearInputOnSelect: true,
							onChange: addContact,
						} : {
							label: __( 'Aggiungi contatto', 'corsivo-mail' ),
							help: __( 'Cerca per nome, cognome, email o altri dati.', 'corsivo-mail' ),
							value: '',
							disabled: sending,
							options: addOptions.map( function ( u ) { return { label: ( u.name || u.email ) + ' — ' + u.email, value: String( u.id ) }; } ),
							onFilterValueChange: searchContacts,
							onChange: addContact,
						} )
					)
				) : el( 'div', { style: { flex: 1, color: '#646970' } }, __( 'Questa email parte automaticamente (override / evento): non c\'è nulla da inviare a mano. Il comportamento si imposta nel pannello "Automazione" della sidebar.', 'corsivo-mail' ) )
			)
			),
			previewRow ? el( Modal, { title: __( 'Anteprima', 'corsivo-mail' ) + ' — ' + ( previewRow.name || previewRow.email ), onRequestClose: function () { setPreviewRow( null ); }, style: { width: '720px', maxWidth: '95vw' } },
				el( 'iframe', { title: __( 'Anteprima email', 'corsivo-mail' ), sandbox: '', referrerPolicy: 'no-referrer', src: C.previewBase + '&post=' + postId + '&recipient=1&user=' + ( previewRow.user_id || 0 ), style: { width: '100%', height: '66vh', border: '1px solid #dcdcde' } } )
			) : null
		);
	}

	function StarterPanel() {
		if ( ! Panel ) { return null; }
		const postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		const layouts = useSelect( function ( s ) { return s( 'core' ).getEntityRecords( 'postType', C.layoutType, { per_page: -1, status: 'publish,draft' } ); }, [] );
		const [ choice, setChoice ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const { resetBlocks } = useDispatch( 'core/block-editor' );
		if ( postType !== C.postType ) { return null; }

		const options = [ { label: __( '— Scegli un layout —', 'corsivo-mail' ), value: '' } ];
		( layouts || [] ).forEach( function ( l ) { options.push( { label: ( l.title && l.title.rendered ) || '(senza titolo)', value: String( l.id ) } ); } );

		function apply() {
			if ( ! choice ) { return; }
			const existing = select( 'core/block-editor' ).getBlocks();
			if ( existing.length && ! window.confirm( __( 'Sostituire il contenuto attuale con questo layout?', 'corsivo-mail' ) ) ) { return; }
			setBusy( true );
			apiFetch( { path: '/wp/v2/' + C.layoutRest + '/' + choice + '?context=edit' } )
				.then( function ( record ) { resetBlocks( parse( ( record && record.content ? record.content.raw : '' ) || '' ) ); } )
				.catch( function ( error ) {
					wp.data.dispatch( 'core/notices' ).createNotice( 'error', ( error && error.message ) || __( 'Caricamento del layout non riuscito.', 'corsivo-mail' ), { type: 'snackbar' } );
				} )
				.finally( function () { setBusy( false ); } );
		}

		return el( Panel, { name: 'corsivo-mail-starter', title: __( 'Parti da un layout', 'corsivo-mail' ) },
			el( SelectControl, { label: __( 'Layout', 'corsivo-mail' ), value: choice, options: options, onChange: setChoice } ),
			el( 'div', { style: { marginTop: '12px' } }, el( Button, { variant: 'secondary', disabled: ! choice || busy, onClick: apply }, busy ? __( 'Applico…', 'corsivo-mail' ) : __( 'Applica layout', 'corsivo-mail' ) ) )
		);
	}

	function BackgroundPanel() {
		if ( ! Panel ) { return null; }
		const postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		const palette = useSelect( function ( s ) { const set = s( 'core/block-editor' ).getSettings(); return ( set && set.colors ) || []; }, [] );
		if ( postType !== C.postType ) { return null; }
		const m = meta || {};
		const custom = !! m.corsivo_mail_custom_bg;
		function setBg( key, value ) { const o = {}; o[ key ] = typeof value === 'boolean' ? value : ( value || '' ); setMeta( Object.assign( {}, m, o ) ); }

		return el( Panel, { name: 'corsivo-mail-bg', title: __( 'Sfondo email', 'corsivo-mail' ) },
			ColorControl ? el( ColorControl, {
				label: __( 'Colore email', 'corsivo-mail' ),
				value: m.corsivo_mail_outer_bg || '',
				palette: palette,
				colorLabel: __( 'Scegli colore email', 'corsivo-mail' ),
				customColorLabel: __( 'Scegli colore personalizzato', 'corsivo-mail' ),
				emptyColorLabel: __( 'Nessun colore', 'corsivo-mail' ),
				clearLabel: __( 'Azzera colore email', 'corsivo-mail' ),
				onChange: function ( v ) { setBg( 'corsivo_mail_outer_bg', v ); },
			} ) : el( Fragment, {},
				el( 'p', { style: { fontWeight: 600, margin: '0 0 6px' } }, __( 'Colore email', 'corsivo-mail' ) ),
				el( ColorPalette, { value: m.corsivo_mail_outer_bg || '', colors: palette, onChange: function ( v ) { setBg( 'corsivo_mail_outer_bg', v ); } } )
			),
			el( 'div', { style: { marginTop: '14px' } },
				el( ToggleControl, { label: __( 'Colore contenuto diverso', 'corsivo-mail' ), help: __( 'Di default tutta l\'email è di un solo colore.', 'corsivo-mail' ), checked: custom, onChange: function ( v ) { setBg( 'corsivo_mail_custom_bg', v ); } } )
			),
			custom ? el( 'div', {}, ColorControl ? el( ColorControl, {
				label: __( 'Colore contenuto', 'corsivo-mail' ),
				value: m.corsivo_mail_content_bg || '',
				palette: palette,
				colorLabel: __( 'Scegli colore contenuto', 'corsivo-mail' ),
				customColorLabel: __( 'Scegli colore personalizzato', 'corsivo-mail' ),
				emptyColorLabel: __( 'Nessun colore', 'corsivo-mail' ),
				clearLabel: __( 'Azzera colore contenuto', 'corsivo-mail' ),
				onChange: function ( v ) { setBg( 'corsivo_mail_content_bg', v ); },
			} ) : el( Fragment, {},
				el( 'p', { style: { fontWeight: 600, margin: '8px 0 6px' } }, __( 'Colore contenuto', 'corsivo-mail' ) ),
				el( ColorPalette, { value: m.corsivo_mail_content_bg || '', colors: palette, onChange: function ( v ) { setBg( 'corsivo_mail_content_bg', v ); } } )
			) ) : null
		);
	}

	function TriggerPanel() {
		if ( ! PluginSidebar ) { return null; }
		const postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		const postId = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostId(); }, [] );
		const [ meta ] = useEntityProp( 'postType', postType, 'meta' );
		const [ showTags, setShowTags ] = useState( false );
		const [ showPreview, setShowPreview ] = useState( false );
		const [ showSend, setShowSend ] = useState( false );
		const [ sendingTest, setSendingTest ] = useState( false );
		const isDirty = useSelect( function ( s ) { return s( 'core/editor' ).isEditedPostDirty(); }, [] );

		if ( postType !== C.postType ) { return null; }

		const m = meta || {};
		let extraTags = [];
		if ( m.corsivo_mail_trigger_type === 'core_email' && C.providers[ m.corsivo_mail_core_email ] ) { extraTags = C.providers[ m.corsivo_mail_core_email ].merge_tags; }
		if ( m.corsivo_mail_trigger_type === 'event' && C.events[ m.corsivo_mail_event_id ] ) { extraTags = C.events[ m.corsivo_mail_event_id ].merge_tags; }

		const btn = { style: { width: '100%', justifyContent: 'center', marginBottom: '8px' } };

		function sendTest() {
			setSendingTest( true );
			apiFetch( { path: '/corsivo/v1/mail/send-test', method: 'POST', data: { post: postId, user: 0 } } )
				.then( function ( r ) { wp.data.dispatch( 'core/notices' ).createNotice( r.suppressed ? 'warning' : 'success', r.suppressed ? __( 'Invio soppresso dalle impostazioni globali.', 'corsivo-mail' ) : __( 'Email di test inviata a ', 'corsivo-mail' ) + r.to, { type: 'snackbar' } ); } )
				.catch( function ( e ) { wp.data.dispatch( 'core/notices' ).createNotice( 'error', ( e && e.message ) || __( 'Invio test fallito.', 'corsivo-mail' ), { type: 'snackbar' } ); } )
				.finally( function () { setSendingTest( false ); } );
		}

		return el(
			Fragment, {},
			el( PluginSidebarMoreMenuItem, { target: 'corsivo-mail-trigger', icon: 'email-alt' }, __( 'Invio & trigger', 'corsivo-mail' ) ),
			el( PluginSidebar, { name: 'corsivo-mail-trigger', title: __( 'Invio & trigger', 'corsivo-mail' ), icon: 'email-alt' },
				el( 'div', { style: { padding: '16px' } },
					el( 'p', { style: { color: '#646970', marginTop: 0 } }, __( 'Componi l\'email coi blocchi. Le impostazioni di invio sono in "Apri invio email".', 'corsivo-mail' ) ),
					isDirty ? el( 'p', { style: { color: '#b32d2e' } }, __( 'Salva le modifiche prima di anteprima, test o invio.', 'corsivo-mail' ) ) : null,
					el( Button, Object.assign( { variant: 'secondary', onClick: function () { setShowTags( true ); } }, btn ), __( 'Merge tag', 'corsivo-mail' ) ),
					el( Button, Object.assign( { variant: 'secondary', disabled: isDirty, onClick: function () { setShowPreview( true ); } }, btn ), __( 'Anteprima email', 'corsivo-mail' ) ),
					el( Button, Object.assign( { variant: 'secondary', isBusy: sendingTest, disabled: sendingTest || isDirty, onClick: sendTest }, btn ), __( 'Invia come test', 'corsivo-mail' ) ),
					el( Button, Object.assign( { variant: 'primary', disabled: isDirty, onClick: function () { setShowSend( true ); } }, btn ), __( 'Apri invio email', 'corsivo-mail' ) ),
					showTags ? el( MergeTagsModal, { extraTags: extraTags, onClose: function () { setShowTags( false ); } } ) : null,
					showPreview ? el( PreviewModal, { postId: postId, onClose: function () { setShowPreview( false ); } } ) : null,
					showSend ? el( SendModal, { postId: postId, onClose: function () { setShowSend( false ); } } ) : null
				)
			)
		);
	}

	function MergeTagsPanel() {
		if ( ! Panel ) { return null; }
		const postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		const [ showTags, setShowTags ] = useState( false );
		if ( postType !== C.layoutType && postType !== C.footerType ) { return null; }

		return el( Panel, { name: 'corsivo-mail-merge-tags', title: __( 'Merge tag', 'corsivo-mail' ) },
			el( 'p', { style: { color: '#646970', marginTop: 0, fontSize: '12px' } }, __( 'I merge tag funzionano anche qui: vengono risolti a ogni invio.', 'corsivo-mail' ) ),
			el( Button, { variant: 'secondary', style: { width: '100%', justifyContent: 'center' }, onClick: function () { setShowTags( true ); } }, __( 'Mostra i merge tag', 'corsivo-mail' ) ),
			showTags ? el( MergeTagsModal, { onClose: function () { setShowTags( false ); } } ) : null
		);
	}

	registerPlugin( 'corsivo-mail-automation', { render: AutomationPanel } );
	registerPlugin( 'corsivo-mail-merge-tags', { render: MergeTagsPanel } );
	registerPlugin( 'corsivo-mail-starter', { render: StarterPanel } );
	registerPlugin( 'corsivo-mail-trigger', { render: TriggerPanel } );
	registerPlugin( 'corsivo-mail-bg', { render: BackgroundPanel } );
} )( window.wp, window.CorsivoMailAdminUI || {} );
