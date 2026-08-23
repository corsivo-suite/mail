describe( 'Corsivo Mail inbox entry', () => {
	it( 'keeps the private UI bridge isolated to the compiled entry', () => {
		const source = require( 'fs' ).readFileSync(
			require( 'path' ).resolve( __dirname, 'index.js' ),
			'utf8'
		);

		expect( source ).toContain(
			'@corsivo-suite/wp-admin-ui/confirm-dialog'
		);
		expect( source ).toContain( '../../assets/inbox.js' );
		expect( source ).toContain( 'delete window.CorsivoMailInboxUI' );
	} );
} );
