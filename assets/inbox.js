( function ( wp, corsivoUi ) {
	const { createElement: el, useEffect, useRef, useState, Fragment } = wp.element;
	const { Button, Modal, SelectControl, Spinner, TextControl } = wp.components;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;

	function formatDate( date ) {
		return date ? date.replace( 'T', ' ' ).slice( 0, 16 ) : '';
	}

	function formatSize( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1024 * 1024 ) {
			return Math.round( bytes / 1024 ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	function attachmentUrl( account, uid, index ) {
		const config = window.CorsivoMailInbox || {};
		return ( config.attachmentBase || '' ) + '&account=' + encodeURIComponent( account ) + '&uid=' + encodeURIComponent( uid ) + '&index=' + encodeURIComponent( index );
	}

	function getErrorMessage( error, fallback ) {
		return error && error.message ? error.message : fallback;
	}

	function FallbackConfirmDialog( props ) {
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		useEffect( function () {
			if ( ! props.isOpen ) {
				setBusy( false );
				setError( '' );
			}
		}, [ props.isOpen ] );

		if ( ! props.isOpen ) {
			return null;
		}

		function cancel() {
			if ( ! busy ) {
				props.onCancel();
			}
		}

		function confirm() {
			if ( busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			Promise.resolve()
				.then( props.onConfirm )
				.catch( function ( caughtError ) {
					setError( getErrorMessage( caughtError, props.labels.error ) );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		return el( Modal, {
			className: 'corsivo-mail-inbox__confirm-dialog',
			title: props.title,
			isDismissible: ! busy,
			onRequestClose: cancel,
			shouldCloseOnClickOutside: ! busy,
			shouldCloseOnEsc: ! busy,
		},
			el( 'div', { className: 'corsivo-mail-inbox__confirm-content' },
				el( 'p', {}, props.content ),
				error ? el( 'p', { className: 'corsivo-mail-inbox__dialog-error', role: 'alert' }, error ) : null
			),
			el( 'div', { className: 'corsivo-mail-inbox__confirm-actions' },
				el( Button, { variant: 'tertiary', disabled: busy, onClick: cancel }, props.cancelLabel ),
				el( Button, { variant: 'secondary', isDestructive: true, isBusy: busy, disabled: busy, onClick: confirm }, busy ? props.labels.busy : props.confirmLabel )
			)
		);
	}

	const ConfirmDialog = corsivoUi.ConfirmDialog || FallbackConfirmDialog;

	function RichText( props ) {
		function runCommand( name, value ) {
			document.execCommand( name, false, value || null );
			if ( props.inputRef.current ) {
				props.inputRef.current.focus();
			}
		}

		const preventToolbarBlur = function ( event ) {
			event.preventDefault();
		};

		return el( 'div', { className: 'corsivo-mail-inbox__rich-text' },
			el( 'div', { className: 'corsivo-mail-inbox__rich-text-toolbar' },
				el( Button, { className: 'corsivo-mail-inbox__rich-text-button', variant: 'tertiary', onMouseDown: preventToolbarBlur, onClick: function () { runCommand( 'bold' ); } }, el( 'strong', {}, 'B' ) ),
				el( Button, { className: 'corsivo-mail-inbox__rich-text-button', variant: 'tertiary', onMouseDown: preventToolbarBlur, onClick: function () { runCommand( 'italic' ); } }, el( 'em', {}, 'I' ) ),
				el( Button, { className: 'corsivo-mail-inbox__rich-text-button', variant: 'tertiary', onMouseDown: preventToolbarBlur, onClick: function () {
					const url = window.prompt( __( 'URL del link', 'corsivo-mail' ) );
					if ( url ) {
						runCommand( 'createLink', url );
					}
				} }, __( 'Link', 'corsivo-mail' ) ),
				el( Button, { className: 'corsivo-mail-inbox__rich-text-button', variant: 'tertiary', onMouseDown: preventToolbarBlur, onClick: function () { runCommand( 'insertUnorderedList' ); } }, '• ' + __( 'Lista', 'corsivo-mail' ) )
			),
			el( 'div', {
				className: 'corsivo-mail-inbox__rich-text-input',
				ref: props.inputRef,
				contentEditable: true,
				role: 'textbox',
				'aria-label': __( 'Corpo del messaggio', 'corsivo-mail' ),
				'aria-multiline': 'true',
				suppressContentEditableWarning: true,
			} )
		);
	}

	function Compose( props ) {
		const { account, initial, onClose, onSent } = props;
		const [ to, setTo ] = useState( initial.to || '' );
		const [ subject, setSubject ] = useState( initial.subject || '' );
		const [ sending, setSending ] = useState( false );
		const [ error, setError ] = useState( '' );
		const bodyRef = useRef( null );
		const mounted = useRef( true );
		const sendRequest = useRef( 0 );

		useEffect( function () {
			mounted.current = true;
			return function () {
				mounted.current = false;
				sendRequest.current++;
			};
		}, [] );

		function send() {
			const request = ++sendRequest.current;
			setSending( true );
			setError( '' );
			apiFetch( {
				path: '/corsivo/v1/mail/reply',
				method: 'POST',
				data: {
					account: account,
					to: to,
					subject: subject,
					body: bodyRef.current ? bodyRef.current.innerHTML : '',
					in_reply_to: initial.in_reply_to || '',
					references: initial.references || '',
				},
			} ).then( function () {
				if ( mounted.current && request === sendRequest.current ) {
					onSent();
				}
			} ).catch( function ( caughtError ) {
				if ( mounted.current && request === sendRequest.current ) {
					setError( getErrorMessage( caughtError, __( 'Invio fallito', 'corsivo-mail' ) ) );
				}
			} ).finally( function () {
				if ( mounted.current && request === sendRequest.current ) {
					setSending( false );
				}
			} );
		}

		return el( 'div', { className: 'corsivo-mail-inbox__compose' },
			el( 'div', { className: 'corsivo-mail-inbox__compose-header' },
				el( 'strong', {}, initial.in_reply_to ? __( 'Rispondi', 'corsivo-mail' ) : __( 'Nuovo messaggio', 'corsivo-mail' ) ),
				el( Button, { variant: 'tertiary', onClick: onClose }, __( 'Chiudi', 'corsivo-mail' ) )
			),
			el( TextControl, { label: __( 'A', 'corsivo-mail' ), value: to, onChange: setTo, placeholder: 'destinatario@dominio.it' } ),
			el( TextControl, { label: __( 'Oggetto', 'corsivo-mail' ), value: subject, onChange: setSubject } ),
			error ? el( 'p', { className: 'corsivo-mail-inbox__inline-error', role: 'alert' }, error ) : null,
			el( 'div', { className: 'corsivo-mail-inbox__compose-body' }, el( RichText, { inputRef: bodyRef } ) ),
			el( 'div', { className: 'corsivo-mail-inbox__compose-actions' },
				el( Button, { variant: 'primary', isBusy: sending, disabled: sending || ! to, onClick: send }, sending ? __( 'Invio…', 'corsivo-mail' ) : __( 'Invia', 'corsivo-mail' ) )
			)
		);
	}

	function App() {
		const [ accounts, setAccounts ] = useState( [] );
		const [ account, setAccount ] = useState( '' );
		const [ messages, setMessages ] = useState( [] );
		const [ selected, setSelected ] = useState( null );
		const [ body, setBody ] = useState( null );
		const [ bodyError, setBodyError ] = useState( '' );
		const [ loadingList, setLoadingList ] = useState( false );
		const [ loadingBody, setLoadingBody ] = useState( false );
		const [ syncing, setSyncing ] = useState( false );
		const [ compose, setCompose ] = useState( null );
		const [ notice, setNotice ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ search, setSearch ] = useState( '' );
		const [ activeSearch, setActiveSearch ] = useState( '' );
		const [ total, setTotal ] = useState( 0 );
		const [ loadingMore, setLoadingMore ] = useState( false );
		const [ deleteTarget, setDeleteTarget ] = useState( null );
		const mounted = useRef( true );
		const currentAccount = useRef( '' );
		const activeSearchRef = useRef( '' );
		const accountsRequest = useRef( 0 );
		const listRequest = useRef( 0 );
		const bodyRequest = useRef( 0 );
		const deleteRequest = useRef( 0 );
		const syncRequest = useRef( 0 );

		useEffect( function () {
			mounted.current = true;
			const request = ++accountsRequest.current;
			apiFetch( { path: '/corsivo/v1/mail/imap-accounts' } ).then( function ( list ) {
				if ( ! mounted.current || request !== accountsRequest.current ) {
					return;
				}
				const nextAccounts = Array.isArray( list ) ? list : [];
				setAccounts( nextAccounts );
				if ( nextAccounts.length && ! currentAccount.current ) {
					setAccount( nextAccounts[ 0 ].id );
				}
			} ).catch( function ( caughtError ) {
				if ( mounted.current && request === accountsRequest.current ) {
					setError( getErrorMessage( caughtError, __( 'Caselle IMAP non disponibili.', 'corsivo-mail' ) ) );
				}
			} );

			return function () {
				mounted.current = false;
				accountsRequest.current++;
				listRequest.current++;
				bodyRequest.current++;
				deleteRequest.current++;
				syncRequest.current++;
			};
		}, [] );

		useEffect( function () {
			currentAccount.current = account;
			activeSearchRef.current = '';
			listRequest.current++;
			bodyRequest.current++;
			deleteRequest.current++;
			syncRequest.current++;
			setSelected( null );
			setBody( null );
			setBodyError( '' );
			setCompose( null );
			setDeleteTarget( null );
			setMessages( [] );
			setTotal( 0 );
			setLoadingList( false );
			setLoadingMore( false );
			setLoadingBody( false );
			setSyncing( false );
			setSearch( '' );
			setActiveSearch( '' );
			setError( '' );
			if ( account ) {
				loadList( account, { search: '' } );
			}
		}, [ account ] );

		function loadList( accountId, options ) {
			const resolvedOptions = options || {};
			const term = resolvedOptions.search !== undefined ? resolvedOptions.search : activeSearchRef.current;
			const offset = resolvedOptions.offset || 0;
			const append = offset > 0;
			const request = ++listRequest.current;
			if ( append ) {
				setLoadingMore( true );
			} else {
				setLoadingList( true );
				setLoadingMore( false );
			}
			setError( '' );
			apiFetch( { path: '/corsivo/v1/mail/inbox?account=' + encodeURIComponent( accountId ) + '&offset=' + offset + '&search=' + encodeURIComponent( term ) } )
				.then( function ( response ) {
					if ( ! mounted.current || request !== listRequest.current || accountId !== currentAccount.current ) {
						return;
					}
					setTotal( response.total || 0 );
					setMessages( function ( previousMessages ) {
						return append ? previousMessages.concat( response.messages || [] ) : ( response.messages || [] );
					} );
				} )
				.catch( function ( caughtError ) {
					if ( mounted.current && request === listRequest.current && accountId === currentAccount.current ) {
						setError( getErrorMessage( caughtError, __( 'Caricamento della posta fallito.', 'corsivo-mail' ) ) );
					}
				} )
				.finally( function () {
					if ( mounted.current && request === listRequest.current && accountId === currentAccount.current ) {
						setLoadingList( false );
						setLoadingMore( false );
					}
				} );
		}

		function changeAccount( accountId ) {
			currentAccount.current = accountId;
			listRequest.current++;
			bodyRequest.current++;
			deleteRequest.current++;
			syncRequest.current++;
			setSelected( null );
			setBody( null );
			setBodyError( '' );
			setCompose( null );
			setDeleteTarget( null );
			setAccount( accountId );
		}

		function runSearch( term ) {
			activeSearchRef.current = term;
			setActiveSearch( term );
			setSelected( null );
			setBody( null );
			setBodyError( '' );
			bodyRequest.current++;
			loadList( account, { search: term } );
		}

		function sync() {
			const accountId = account;
			const request = ++syncRequest.current;
			setSyncing( true );
			setError( '' );
			apiFetch( { path: '/corsivo/v1/mail/inbox-sync', method: 'POST', data: { account: accountId } } )
				.then( function () {
					if ( mounted.current && request === syncRequest.current && accountId === currentAccount.current ) {
						loadList( accountId );
					}
				} )
				.catch( function ( caughtError ) {
					if ( mounted.current && request === syncRequest.current && accountId === currentAccount.current ) {
						setError( getErrorMessage( caughtError, __( 'Sincronizzazione fallita.', 'corsivo-mail' ) ) );
					}
				} )
				.finally( function () {
					if ( mounted.current && request === syncRequest.current && accountId === currentAccount.current ) {
						setSyncing( false );
					}
				} );
		}

		function openMessage( message ) {
			const accountId = account;
			const request = ++bodyRequest.current;
			setCompose( null );
			setSelected( message );
			setBody( null );
			setBodyError( '' );
			setLoadingBody( true );
			apiFetch( { path: '/corsivo/v1/mail/inbox-body?account=' + encodeURIComponent( accountId ) + '&uid=' + encodeURIComponent( message.uid ) } )
				.then( function ( messageBody ) {
					if ( ! mounted.current || request !== bodyRequest.current || accountId !== currentAccount.current ) {
						return;
					}
					setBody( messageBody );
					setMessages( function ( previousMessages ) {
						return previousMessages.map( function ( currentMessage ) {
							return currentMessage.uid === message.uid ? Object.assign( {}, currentMessage, { seen: 1 } ) : currentMessage;
						} );
					} );
				} )
				.catch( function ( caughtError ) {
					if ( mounted.current && request === bodyRequest.current && accountId === currentAccount.current ) {
						setBodyError( getErrorMessage( caughtError, __( 'Caricamento del messaggio fallito.', 'corsivo-mail' ) ) );
					}
				} )
				.finally( function () {
					if ( mounted.current && request === bodyRequest.current && accountId === currentAccount.current ) {
						setLoadingBody( false );
					}
				} );
		}

		function reply() {
			if ( ! selected ) {
				return;
			}
			const subject = selected.subject && selected.subject.indexOf( 'Re:' ) === 0 ? selected.subject : 'Re: ' + ( selected.subject || '' );
			setCompose( { to: selected.from_email, subject: subject, in_reply_to: selected.message_id || '', references: selected.refs || '' } );
		}

		function requestDelete() {
			if ( selected && ! syncing ) {
				setDeleteTarget( { account: account, uid: selected.uid } );
			}
		}

		function removeMessage() {
			if ( ! deleteTarget ) {
				return Promise.resolve();
			}

			const target = deleteTarget;
			const request = ++deleteRequest.current;
			syncRequest.current++;
			listRequest.current++;
			setSyncing( false );
			setLoadingList( false );
			setLoadingMore( false );
			setError( '' );

			return apiFetch( { path: '/corsivo/v1/mail/inbox-delete', method: 'POST', data: { account: target.account, uid: target.uid } } )
				.then( function () {
					if ( ! mounted.current || request !== deleteRequest.current || target.account !== currentAccount.current ) {
						return;
					}
					setMessages( function ( previousMessages ) {
						return previousMessages.filter( function ( message ) { return message.uid !== target.uid; } );
					} );
					setTotal( function ( previousTotal ) { return Math.max( 0, previousTotal - 1 ); } );
					setSelected( null );
					setBody( null );
					setBodyError( '' );
					setDeleteTarget( null );
					setNotice( __( 'Messaggio eliminato.', 'corsivo-mail' ) );
				} )
				.catch( function ( caughtError ) {
					if ( ! mounted.current || request !== deleteRequest.current || target.account !== currentAccount.current ) {
						return;
					}
					throw caughtError;
				} );
		}

		const rail = el( 'div', { className: 'corsivo-mail-inbox__rail' },
			el( Button, { className: 'corsivo-mail-inbox__rail-action corsivo-mail-inbox__compose-trigger', variant: 'primary', onClick: function () {
				bodyRequest.current++;
				setSelected( null );
				setBody( null );
				setBodyError( '' );
				setLoadingBody( false );
				setCompose( { to: '', subject: '' } );
			} }, __( 'Scrivi', 'corsivo-mail' ) ),
			el( Button, { className: 'corsivo-mail-inbox__rail-link' + ( compose ? '' : ' is-active' ), variant: 'tertiary', onClick: function () { setCompose( null ); } }, __( 'Posta in arrivo', 'corsivo-mail' ) ),
			el( 'div', { className: 'corsivo-mail-inbox__account' },
				el( SelectControl, {
					label: __( 'Casella', 'corsivo-mail' ),
					value: account,
					options: accounts.length ? accounts.map( function ( currentAccountOption ) { return { label: currentAccountOption.label, value: currentAccountOption.id }; } ) : [ { label: __( 'Nessuna casella IMAP', 'corsivo-mail' ), value: '' } ],
					onChange: changeAccount,
				} )
			),
			el( Button, { className: 'corsivo-mail-inbox__rail-action', variant: 'secondary', isBusy: syncing, disabled: ! account || syncing, onClick: sync }, syncing ? __( 'Sincronizzo…', 'corsivo-mail' ) : __( 'Sincronizza', 'corsivo-mail' ) )
		);

		const searchBar = el( 'div', { className: 'corsivo-mail-inbox__search' },
			el( 'div', { className: 'corsivo-mail-inbox__search-field' }, el( TextControl, {
				label: __( 'Cerca', 'corsivo-mail' ),
				hideLabelFromVision: true,
				placeholder: __( 'Mittente o oggetto…', 'corsivo-mail' ),
				value: search,
				onChange: setSearch,
				onKeyDown: function ( event ) {
					if ( event.key === 'Enter' ) {
						runSearch( search );
					}
				},
				__nextHasNoMarginBottom: true,
			} ) ),
			el( Button, { variant: 'secondary', onClick: function () { runSearch( search ); } }, __( 'Cerca', 'corsivo-mail' ) ),
			activeSearch ? el( Button, { variant: 'tertiary', label: __( 'Cancella ricerca', 'corsivo-mail' ), onClick: function () { setSearch( '' ); runSearch( '' ); } }, __( 'Cancella', 'corsivo-mail' ) ) : null
		);

		const middle = compose ? null : el( 'div', { className: 'corsivo-mail-inbox__message-list' },
			searchBar,
			el( 'div', { className: 'corsivo-mail-inbox__message-list-content' },
				loadingList ? el( 'div', { className: 'corsivo-mail-inbox__loading' }, el( Spinner ) ) :
				( messages.length ? el( Fragment, {},
					messages.map( function ( message ) {
						const active = selected && selected.uid === message.uid;
						const unread = message.seen !== '1' && message.seen !== 1;
						return el( 'button', {
							className: 'corsivo-mail-inbox__message' + ( active ? ' is-active' : '' ) + ( unread ? ' is-unread' : '' ),
							key: message.uid,
							type: 'button',
							onClick: function () { openMessage( message ); },
						},
							el( 'div', { className: 'corsivo-mail-inbox__message-meta' },
								el( 'span', { className: 'corsivo-mail-inbox__message-sender' }, message.from_name || message.from_email ),
								el( 'span', {}, formatDate( message.mail_date ) )
							),
							el( 'div', { className: 'corsivo-mail-inbox__message-subject' }, message.subject )
						);
					} ),
					messages.length < total ? el( 'div', { className: 'corsivo-mail-inbox__load-more' },
						el( Button, { variant: 'secondary', isBusy: loadingMore, disabled: loadingMore, onClick: function () { loadList( account, { offset: messages.length } ); } },
							__( 'Carica altri', 'corsivo-mail' ) + ' (' + messages.length + '/' + total + ')'
						)
					) : null
				) : el( 'p', { className: 'corsivo-mail-inbox__empty-list' }, activeSearch ? __( 'Nessun risultato per questa ricerca.', 'corsivo-mail' ) : __( 'Nessun messaggio. Premi Sincronizza.', 'corsivo-mail' ) ) )
			)
		);

		const pane = el( 'div', { className: 'corsivo-mail-inbox__pane' },
			compose ? el( Compose, { account: account, initial: compose, onClose: function () { setCompose( null ); }, onSent: function () { setCompose( null ); setNotice( __( 'Email inviata.', 'corsivo-mail' ) ); } } ) :
			( loadingBody ? el( 'div', { className: 'corsivo-mail-inbox__loading' }, el( Spinner ) ) :
			( selected ? el( 'div', { className: 'corsivo-mail-inbox__message-view' },
				el( 'div', { className: 'corsivo-mail-inbox__message-header' },
					el( 'div', { className: 'corsivo-mail-inbox__message-heading' },
						el( 'h2', {}, selected.subject ),
						el( 'div', { className: 'corsivo-mail-inbox__message-address' }, ( selected.from_name || '' ) + ' <' + selected.from_email + '> — ' + formatDate( selected.mail_date ) ),
						selected.to_field ? el( 'div', { className: 'corsivo-mail-inbox__message-address' }, __( 'A: ', 'corsivo-mail' ) + selected.to_field ) : null
					),
					el( 'div', { className: 'corsivo-mail-inbox__message-actions' },
						el( Button, { variant: 'primary', onClick: reply }, __( 'Rispondi', 'corsivo-mail' ) ),
						el( Button, { variant: 'secondary', isDestructive: true, disabled: syncing, onClick: requestDelete }, __( 'Elimina', 'corsivo-mail' ) )
					)
				),
				body && body.attachments && body.attachments.length ? el( 'div', { className: 'corsivo-mail-inbox__attachments' },
					body.attachments.map( function ( attachment ) {
						return el( 'a', {
							className: 'corsivo-mail-inbox__attachment',
							key: attachment.index,
							href: attachmentUrl( account, selected.uid, attachment.index ),
						}, attachment.filename + ' (' + formatSize( attachment.size ) + ')' );
					} )
				) : null,
				bodyError ? el( 'p', { className: 'corsivo-mail-inbox__body-error', role: 'alert' }, bodyError ) : null,
				body ? el( 'iframe', { className: 'corsivo-mail-inbox__body-frame', title: __( 'Contenuto del messaggio', 'corsivo-mail' ), sandbox: '', referrerPolicy: 'no-referrer', srcDoc: body.html || '' } ) : null
			) : el( 'p', { className: 'corsivo-mail-inbox__empty-pane' }, __( 'Seleziona un messaggio o premi Scrivi.', 'corsivo-mail' ) ) ) )
		);

		return el( 'div', { className: 'corsivo-mail-inbox' },
			notice ? el( 'div', { className: 'notice notice-success corsivo-mail-inbox__notice', role: 'status', 'aria-live': 'polite' }, el( 'p', {}, notice + ' ', el( Button, { variant: 'link', onClick: function () { setNotice( '' ); } }, __( 'Chiudi', 'corsivo-mail' ) ) ) ) : null,
			error ? el( 'div', { className: 'notice notice-error corsivo-mail-inbox__notice', role: 'alert' }, el( 'p', {}, error ) ) : null,
			el( 'div', { className: 'corsivo-mail-inbox__layout' }, rail, middle, pane ),
			el( ConfirmDialog, {
				isOpen: Boolean( deleteTarget ),
				title: __( 'Elimina messaggio', 'corsivo-mail' ),
				content: __( 'Eliminare questo messaggio anche dal server?', 'corsivo-mail' ),
				confirmLabel: __( 'Elimina', 'corsivo-mail' ),
				cancelLabel: __( 'Annulla', 'corsivo-mail' ),
				isDestructive: true,
				onCancel: function () { setDeleteTarget( null ); },
				onConfirm: removeMessage,
				labels: {
					busy: __( 'Eliminazione…', 'corsivo-mail' ),
					error: __( 'Eliminazione fallita.', 'corsivo-mail' ),
				},
			} )
		);
	}

	const node = document.getElementById( 'corsivo-mail-inbox-app' );
	if ( node ) {
		if ( wp.element.createRoot ) {
			wp.element.createRoot( node ).render( el( App ) );
		} else {
			wp.element.render( el( App ), node );
		}
	}
} )( window.wp, window.CorsivoMailInboxUI || {} );
