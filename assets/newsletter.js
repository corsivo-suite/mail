( function () {
	document.querySelectorAll( '.corsivo-mail-newsletter-optin' ).forEach( function ( wrap ) {
		const checkbox = wrap.querySelector( 'input[type="checkbox"]' );
		const status = wrap.querySelector( '.corsivo-mail-newsletter-status' );
		if ( ! checkbox ) {
			return;
		}

		checkbox.addEventListener( 'change', function () {
			const previous = ! checkbox.checked;
			const requested = checkbox.checked;
			checkbox.disabled = true;
			status.textContent = '…';
			fetch( wrap.dataset.rest, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wrap.dataset.nonce },
				body: JSON.stringify( { subscribed: requested } ),
			} ).then( function ( r ) {
				return r.json().catch( function () { return {}; } ).then( function ( data ) {
					if ( ! r.ok ) { throw new Error( data.message || 'Errore' ); }
					return data;
				} );
			} )
				.then( function ( d ) {
					checkbox.checked = !! d.subscribed;
					status.textContent = d.subscribed ? 'Iscritto' : 'Disiscritto';
				} )
				.catch( function () { checkbox.checked = previous; status.textContent = 'Errore'; } )
				.finally( function () { checkbox.disabled = false; } );
		} );
	} );
} )();
