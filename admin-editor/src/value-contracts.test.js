import { parseEmailList, serializeEmailList } from './value-contracts';

describe( 'email audience value contracts', () => {
	it( 'reads the existing newline, comma and semicolon formats', () => {
		expect(
			parseEmailList(
				'first@example.test\nsecond@example.test, third@example.test; fourth@example.test'
			)
		).toEqual( [
			'first@example.test',
			'second@example.test',
			'third@example.test',
			'fourth@example.test',
		] );
	} );

	it( 'writes the legacy string shape without UI identities', () => {
		expect(
			serializeEmailList( [
				'first@example.test',
				'second@example.test',
			] )
		).toBe( 'first@example.test\nsecond@example.test' );
		expect( serializeEmailList( null ) ).toBe( '' );
	} );
} );
