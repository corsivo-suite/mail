( function () {
	const config = window.CorsivoMailSettingsUI || {};

	function requestRemoval( message, onConfirm ) {
		const dialog = document.createElement( 'dialog' );
		dialog.className = 'corsivo-mail-settings__fallback-dialog';
		dialog.setAttribute( 'aria-label', 'Conferma rimozione' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'role', 'dialog' );

		const title = document.createElement( 'h2' );
		title.textContent = 'Conferma rimozione';
		const description = document.createElement( 'p' );
		description.textContent = message;
		const actions = document.createElement( 'div' );
		actions.className = 'corsivo-mail-settings__fallback-dialog-actions';
		const cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = 'Annulla';
		const confirm = document.createElement( 'button' );
		confirm.type = 'button';
		confirm.className = 'button button-secondary button-link-delete';
		confirm.textContent = 'Rimuovi';

		actions.append( cancel, confirm );
		dialog.append( title, description, actions );
		document.body.appendChild( dialog );

		function close() {
			if ( typeof dialog.close === 'function' ) {
				dialog.close();
			} else {
				dialog.remove();
			}
		}

		cancel.addEventListener( 'click', close );
		confirm.addEventListener( 'click', function () {
			onConfirm();
			close();
		} );
		dialog.addEventListener( 'close', function () { dialog.remove(); } );

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
		cancel.focus();
	}

	function initAccounts() {
		const container = document.getElementById( 'corsivo-mail-accounts' );
		const template = document.getElementById( 'corsivo-mail-account-template' );
		const addButton = document.getElementById( 'corsivo-mail-add-account' );
		const accountConfig = config.accounts || {};
		if ( ! container || ! template || ! addButton ) {
			return;
		}

		let nextIndex = container.querySelectorAll( '.corsivo-mail-account-card' ).length;

		function initializeCard( card ) {
			const provider = card.querySelector( '.ms-provider' );
			const note = card.querySelector( '.ms-provider-note' );
			const title = card.querySelector( '.ms-card-title' );
			const label = card.querySelector( '.ms-label' );

			function setField( selector, value ) {
				const field = card.querySelector( selector );
				if ( field && value !== undefined ) {
					field.value = value;
				}
			}

			function showNote() {
				const preset = accountConfig.presets && accountConfig.presets[ provider.value ];
				note.textContent = preset && preset.note ? preset.note : '';
			}

			function applyPreset() {
				const preset = accountConfig.presets && accountConfig.presets[ provider.value ];
				if ( preset && provider.value !== 'custom' ) {
					setField( '.ms-host', preset.host );
					setField( '.ms-port', preset.port );
					setField( '.ms-secure', preset.secure );
					setField( '.ms-user', preset.user );
					setField( '.ms-imap-host', preset.imap_host );
					setField( '.ms-imap-port', preset.imap_port );
					setField( '.ms-imap-secure', preset.imap_secure );
					setField( '.ms-imap-user', preset.imap_user );
				}
				showNote();
			}

			provider.addEventListener( 'change', applyPreset );
			label.addEventListener( 'input', function () {
				title.textContent = label.value || 'Nuovo account';
			} );
			card.querySelector( '.ms-remove' ).addEventListener( 'click', function ( event ) {
				event.preventDefault();
				requestRemoval(
					'Rimuovere questo account? Il salvataggio verrà bloccato se è usato da automazioni o email in coda.',
					function () { card.remove(); }
				);
			} );

			const imapTest = card.querySelector( '.ms-imap-test' );
			if ( imapTest ) {
				imapTest.addEventListener( 'click', function () {
					if ( imapTest.disabled ) {
						return;
					}
					const result = card.querySelector( '.ms-imap-result' );
					imapTest.disabled = true;
					result.className = 'ms-imap-result is-loading';
					result.textContent = 'Connessione…';
					const payload = {
						account: imapTest.dataset.account,
						host: card.querySelector( '.ms-imap-host' ).value,
						port: card.querySelector( '.ms-imap-port' ).value,
						secure: card.querySelector( '.ms-imap-secure' ).value,
						user: card.querySelector( '.ms-imap-user' ).value || card.querySelector( '.ms-user' ).value,
						pass: card.querySelector( '.ms-imap-pass' ).value,
					};

					fetch( accountConfig.imapTestUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': accountConfig.restNonce },
						body: JSON.stringify( payload ),
					} ).then( function ( response ) {
						return response.json().then( function ( responseBody ) {
							return { ok: response.ok, body: responseBody };
						} );
					} ).then( function ( response ) {
						result.className = 'ms-imap-result ' + ( response.ok ? 'is-success' : 'is-error' );
						result.textContent = response.ok
							? 'Connesso — ' + response.body.exists + ' messaggi in inbox'
							: ( response.body.message || 'Errore' );
					} ).catch( function () {
						result.className = 'ms-imap-result is-error';
						result.textContent = 'Errore di rete';
					} ).finally( function () {
						imapTest.disabled = false;
					} );
				} );
			}

			showNote();
		}

		container.querySelectorAll( '.corsivo-mail-account-card' ).forEach( initializeCard );
		addButton.addEventListener( 'click', function () {
			const wrapper = document.createElement( 'div' );
			wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) ).trim();
			const card = wrapper.firstElementChild;
			container.appendChild( card );
			initializeCard( card );
			card.querySelector( '.ms-label' ).focus();
		} );
	}

	function initGroups() {
		const container = document.getElementById( 'corsivo-mail-groups' );
		const template = document.getElementById( 'corsivo-mail-group-template' );
		const addButton = document.getElementById( 'corsivo-mail-add-group' );
		if ( ! container || ! template || ! addButton ) {
			return;
		}

		let nextIndex = container.querySelectorAll( '.corsivo-mail-group-card' ).length;

		function initializeCard( card ) {
			const rule = card.querySelector( '.ms-group-rule' );
			const value = card.querySelector( '.ms-group-value' );

			function toggleValue() {
				value.hidden = [ 'role', 'meta', 'manual' ].indexOf( rule.value ) === -1;
			}

			card.querySelector( '.ms-group-remove' ).addEventListener( 'click', function () {
				requestRemoval( 'Rimuovere questo gruppo?', function () { card.remove(); } );
			} );
			rule.addEventListener( 'change', toggleValue );
			toggleValue();
		}

		container.querySelectorAll( '.corsivo-mail-group-card' ).forEach( initializeCard );
		addButton.addEventListener( 'click', function () {
			const wrapper = document.createElement( 'div' );
			wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) ).trim();
			const card = wrapper.firstElementChild;
			container.appendChild( card );
			initializeCard( card );
			card.querySelector( 'input[type="text"]' ).focus();
		} );
	}

	initAccounts();
	initGroups();
} )();
