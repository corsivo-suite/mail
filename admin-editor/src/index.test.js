describe( 'Corsivo Mail editor entry', () => {
	it( 'keeps the package controls in the compiled entry contract', () => {
		const source = require( 'fs' ).readFileSync(
			require( 'path' ).resolve( __dirname, 'index.js' ),
			'utf8'
		);

		expect( source ).toContain(
			'@corsivo-suite/wp-admin-ui/async-combobox'
		);
		expect( source ).toContain(
			'@corsivo-suite/wp-admin-ui/async-multi-select'
		);
		expect( source ).toContain(
			'@corsivo-suite/wp-admin-ui/color-control'
		);
		expect( source ).toContain(
			'@corsivo-suite/wp-admin-ui/date-time-control'
		);
		expect( source ).toContain( "require( '../../assets/editor.js' )" );
		expect( source ).toContain(
			"require( '../../assets/email-editor.js' )"
		);
	} );
} );
