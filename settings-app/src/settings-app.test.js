import {
	cleanup,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { AccountsApp } from './accounts-app';
import { GroupsApp } from './groups-app';

jest.mock( '@wordpress/components', () =>
	jest.requireActual( './wordpress-components.mock' )
);

const blankAccount = {
	id: '',
	label: '',
	provider: 'custom',
	from_name: '',
	from_email: '',
	smtp_host: '',
	smtp_port: 587,
	smtp_secure: 'tls',
	smtp_auth: true,
	smtp_user: '',
	smtp_pass: '',
	imap_host: '',
	imap_port: 993,
	imap_secure: 'ssl',
	imap_user: '',
	imap_pass: '',
	is_default: false,
};
const presets = {
	custom: {
		label: 'Personalizzato',
		host: '',
		port: 587,
		secure: 'tls',
		user: '',
		imap_host: '',
		imap_port: 993,
		imap_secure: 'ssl',
		imap_user: '',
		note: '',
	},
	gmail: {
		label: 'Gmail',
		host: 'smtp.gmail.com',
		port: 587,
		secure: 'tls',
		user: '',
		imap_host: 'imap.gmail.com',
		imap_port: 993,
		imap_secure: 'ssl',
		imap_user: '',
		note: 'Password per le app.',
	},
};

function prepareProgressiveMount( appId, fallbackId ) {
	document.body.innerHTML = `<div id="${ appId }" hidden></div><div id="${ fallbackId }"></div>`;
	return document.getElementById( appId );
}

function mockVerticalRows() {
	return jest
		.spyOn( globalThis.HTMLElement.prototype, 'getBoundingClientRect' )
		.mockImplementation( function () {
			const row = this.closest?.( '[data-item-id]' );
			const rows = [ ...document.querySelectorAll( '[data-item-id]' ) ];
			const top = Math.max( 0, rows.indexOf( row ) ) * 48;
			return {
				bottom: top + 40,
				height: 40,
				left: 0,
				right: 760,
				top,
				width: 760,
				x: 0,
				y: top,
				toJSON: () => ( {} ),
			};
		} );
}

afterEach( () => {
	cleanup();
	jest.restoreAllMocks();
	document.body.innerHTML = '';
} );

describe( 'settings apps', () => {
	it( 'keeps account POST fields, blank passwords and default identity through update and reorder', async () => {
		const first = {
			...blankAccount,
			id: 'first',
			label: 'First',
			from_email: 'first@example.test',
			smtp_pass: 'encrypted-secret',
			imap_pass: 'encrypted-imap-secret',
			is_default: true,
		};
		const second = {
			...blankAccount,
			id: 'second',
			label: 'Second',
			from_email: 'second@example.test',
		};
		const node = prepareProgressiveMount(
			'corsivo-mail-accounts-app',
			'corsivo-mail-accounts-fallback'
		);
		mockVerticalRows();

		render(
			<AccountsApp
				accounts={ [ first, second ] }
				blankAccount={ blankAccount }
				presets={ presets }
				nonce="settings-nonce"
			/>,
			{ container: node }
		);

		expect( node.hidden ).toBe( false );
		expect(
			document.getElementById( 'corsivo-mail-accounts-fallback' ).hidden
		).toBe( true );
		expect(
			node.querySelector( 'input[name="accounts[0][id]"]' ).value
		).toBe( 'first' );
		expect(
			node.querySelector( 'input[name="accounts[0][smtp_pass]"]' ).value
		).toBe( '' );
		expect(
			node.querySelector( 'input[name="accounts[0][imap_pass]"]' ).value
		).toBe( '' );

		fireEvent.change(
			node.querySelector( 'input[name="accounts[0][label]"]' ),
			{ target: { value: 'First updated' } }
		);

		const firstHandle = screen.getByRole( 'button', {
			name: 'Riordina First updated',
		} );
		firstHandle.focus();
		fireEvent.keyDown( firstHandle, { key: ' ', code: 'Space' } );
		await waitFor( () =>
			expect( firstHandle.getAttribute( 'aria-pressed' ) ).toBe( 'true' )
		);
		fireEvent.keyDown( document, {
			key: 'ArrowDown',
			code: 'ArrowDown',
		} );
		fireEvent.keyDown( document, { key: ' ', code: 'Space' } );

		await waitFor( () => {
			expect(
				node.querySelector( 'input[name="accounts[0][id]"]' ).value
			).toBe( 'second' );
		} );
		expect(
			node.querySelector( 'input[name="accounts[1][id]"]' ).value
		).toBe( 'first' );
		expect(
			node.querySelector( 'input[name="default_account"]:checked' ).value
		).toBe( '1' );
	} );

	it( 'adds and confirms removal without persisting transient identity fields', () => {
		const node = prepareProgressiveMount(
			'corsivo-mail-groups-app',
			'corsivo-mail-groups-fallback'
		);
		render(
			<GroupsApp
				groups={ [
					{
						id: 'first',
						name: 'First',
						rule: 'role',
						value: 'editor',
					},
				] }
				rules={ {
					all_users: 'Tutti',
					role: 'Ruolo',
				} }
				nonce="groups-nonce"
			/>,
			{ container: node }
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Aggiungi gruppo' } )
		);
		expect(
			node.querySelector( 'input[name="groups[1][id]"]' ).value
		).toBe( '' );
		expect( node.querySelector( 'input[name*="_id"]' ) ).toBeNull();

		const removeButtons = screen.getAllByRole( 'button', {
			name: 'Rimuovi First',
		} );
		fireEvent.click(
			removeButtons.find( ( button ) =>
				button.classList.contains( 'corsivo-mail-settings__remove' )
			)
		);
		const dialog = screen.getByRole( 'dialog' );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Rimuovi' } )
		);

		expect(
			node.querySelector( 'input[name="groups[0][id]"]' ).value
		).toBe( '' );
		expect( screen.queryByText( 'First' ) ).toBeNull();
	} );
} );
