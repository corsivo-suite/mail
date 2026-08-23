import {
	createAccountRow,
	createGroupRow,
	removeCollectionItem,
	reorderCollectionItems,
	updateCollectionItem,
} from './collection-contracts';

describe( 'settings collection contracts', () => {
	it( 'adds exact account and group POST rows without UI identities', () => {
		const account = createAccountRow( {
			provider: 'custom',
			smtp_port: 587,
			imap_port: 993,
		} );
		const group = createGroupRow();

		expect( account ).toEqual( {
			provider: 'custom',
			smtp_port: 587,
			imap_port: 993,
			id: '',
			is_default: false,
			smtp_pass: '',
			imap_pass: '',
			clear_imap_pass: false,
		} );
		expect( group ).toEqual( {
			id: '',
			name: '',
			rule: 'all_users',
			value: '',
		} );
		expect( account ).not.toHaveProperty( '_id' );
		expect( group ).not.toHaveProperty( '_id' );
	} );

	it( 'updates and removes one row while preserving untouched references', () => {
		const first = { id: 'first', label: 'First' };
		const second = { id: 'second', label: 'Second' };
		const updated = updateCollectionItem( [ first, second ], 1, {
			label: 'Updated',
		} );
		const removed = removeCollectionItem( updated, 1 );

		expect( updated[ 0 ] ).toBe( first );
		expect( updated[ 1 ] ).not.toBe( second );
		expect( updated[ 1 ].id ).toBe( 'second' );
		expect( removed ).toEqual( [ first ] );
		expect( removed[ 0 ] ).toBe( first );
	} );

	it( 'reorders rows without cloning or changing persisted IDs', () => {
		const first = { id: 'first' };
		const second = { id: 'second' };
		const third = { id: '' };
		const reordered = reorderCollectionItems(
			[ first, second, third ],
			0,
			2
		);

		expect( reordered ).toEqual( [ second, third, first ] );
		expect( reordered[ 0 ] ).toBe( second );
		expect( reordered[ 1 ] ).toBe( third );
		expect( reordered[ 2 ] ).toBe( first );
	} );
} );
