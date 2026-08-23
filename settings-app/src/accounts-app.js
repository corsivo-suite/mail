import {
	Button,
	CheckboxControl,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ConfirmDialog } from '@corsivo-suite/wp-admin-ui/confirm-dialog';
import { FieldFeedback } from '@corsivo-suite/wp-admin-ui/field-feedback';
import { Repeater } from '@corsivo-suite/wp-admin-ui/repeater';
import { createAccountRow } from './collection-contracts';
import { useProgressiveMount } from './progressive-mount';

function accountLabel( account, index ) {
	return (
		account.label ||
		account.from_email ||
		__( 'Account', 'corsivo-mail' ) + ' ' + ( index + 1 )
	);
}

function AccountFields( {
	account,
	index,
	itemId,
	onChange,
	presets,
	imapEnabled,
	imapTestUrl,
	restNonce,
	isDefault,
	onDefaultChange,
} ) {
	const [ testState, setTestState ] = useState( {
		message: '',
		tone: 'help',
	} );
	const [ testing, setTesting ] = useState( false );
	const mounted = useRef( true );
	const testRequest = useRef( 0 );
	const name = ( field ) => `accounts[${ index }][${ field }]`;
	const id = ( field ) => `corsivo-mail-account-${ itemId }-${ field }`;
	const isNew = ! account.id;
	const providerOptions = Object.entries( presets ).map(
		( [ value, preset ] ) => ( { label: preset.label, value } )
	);

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
			testRequest.current += 1;
		};
	}, [] );

	useEffect( () => {
		testRequest.current += 1;
		setTesting( false );
		setTestState( { message: '', tone: 'help' } );
	}, [
		account.imap_host,
		account.imap_port,
		account.imap_secure,
		account.imap_user,
		account.imap_pass,
		account.smtp_user,
	] );

	const changeField = ( field, value ) => {
		onChange( { ...account, [ field ]: value } );
	};

	const changeProvider = ( provider ) => {
		const preset = presets[ provider ];
		if ( ! preset || provider === 'custom' ) {
			changeField( 'provider', provider );
			return;
		}

		onChange( {
			...account,
			provider,
			smtp_host: preset.host,
			smtp_port: preset.port,
			smtp_secure: preset.secure,
			smtp_user: preset.user,
			imap_host: preset.imap_host,
			imap_port: preset.imap_port,
			imap_secure: preset.imap_secure,
			imap_user: preset.imap_user,
		} );
	};

	const testImap = async () => {
		const request = ++testRequest.current;
		setTesting( true );
		setTestState( {
			message: __( 'Connessione…', 'corsivo-mail' ),
			tone: 'loading',
		} );

		try {
			const response = await window.fetch( imapTestUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce,
				},
				body: JSON.stringify( {
					account: account.id,
					host: account.imap_host,
					port: account.imap_port,
					secure: account.imap_secure,
					user: account.imap_user || account.smtp_user,
					pass: account.imap_pass,
				} ),
			} );
			const responseBody = await response.json();
			if ( ! mounted.current || request !== testRequest.current ) {
				return;
			}
			setTestState(
				response.ok
					? {
							message:
								__( 'Connesso', 'corsivo-mail' ) +
								' — ' +
								responseBody.exists +
								' ' +
								__( 'messaggi in inbox', 'corsivo-mail' ),
							tone: 'success',
					  }
					: {
							message:
								responseBody.message ||
								__(
									'Connessione IMAP fallita.',
									'corsivo-mail'
								),
							tone: 'error',
					  }
			);
		} catch ( error ) {
			if ( mounted.current && request === testRequest.current ) {
				setTestState( {
					message:
						error.message ||
						__( 'Errore di rete.', 'corsivo-mail' ),
					tone: 'error',
				} );
			}
		} finally {
			if ( mounted.current && request === testRequest.current ) {
				setTesting( false );
			}
		}
	};

	return (
		<div className="corsivo-mail-settings__account-fields">
			<input
				type="hidden"
				name={ name( 'id' ) }
				value={ account.id || '' }
			/>
			<div className="corsivo-mail-settings__field-grid">
				<div className="corsivo-mail-settings__field is-wide">
					<SelectControl
						id={ id( 'provider' ) }
						label={ __( 'Provider', 'corsivo-mail' ) }
						name={ name( 'provider' ) }
						options={ providerOptions }
						value={ account.provider || 'custom' }
						onChange={ changeProvider }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<FieldFeedback tone="help">
						{ presets[ account.provider ]?.note || '' }
					</FieldFeedback>
				</div>
				<TextControl
					id={ id( 'label' ) }
					label={ __( 'Etichetta', 'corsivo-mail' ) }
					name={ name( 'label' ) }
					value={ account.label || '' }
					onChange={ ( value ) => changeField( 'label', value ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>

			<fieldset className="corsivo-mail-settings__section">
				<legend>{ __( 'Mittente', 'corsivo-mail' ) }</legend>
				<div className="corsivo-mail-settings__field-grid">
					<TextControl
						id={ id( 'from_name' ) }
						label={ __( 'Nome', 'corsivo-mail' ) }
						name={ name( 'from_name' ) }
						value={ account.from_name || '' }
						onChange={ ( value ) =>
							changeField( 'from_name', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						id={ id( 'from_email' ) }
						label={ __( 'Email', 'corsivo-mail' ) }
						name={ name( 'from_email' ) }
						type="email"
						value={ account.from_email || '' }
						onChange={ ( value ) =>
							changeField( 'from_email', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
			</fieldset>

			<fieldset className="corsivo-mail-settings__section">
				<legend>{ __( 'Server SMTP', 'corsivo-mail' ) }</legend>
				<div className="corsivo-mail-settings__server-grid">
					<TextControl
						id={ id( 'smtp_host' ) }
						label={ __( 'Host', 'corsivo-mail' ) }
						name={ name( 'smtp_host' ) }
						value={ account.smtp_host || '' }
						onChange={ ( value ) =>
							changeField( 'smtp_host', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						id={ id( 'smtp_port' ) }
						label={ __( 'Porta', 'corsivo-mail' ) }
						name={ name( 'smtp_port' ) }
						type="number"
						min="1"
						max="65535"
						value={ account.smtp_port || 587 }
						onChange={ ( value ) =>
							changeField( 'smtp_port', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						id={ id( 'smtp_secure' ) }
						label={ __( 'Sicurezza', 'corsivo-mail' ) }
						name={ name( 'smtp_secure' ) }
						options={ [
							{ label: 'STARTTLS', value: 'tls' },
							{ label: 'SSL', value: 'ssl' },
							{
								label: __( 'Nessuna', 'corsivo-mail' ),
								value: 'none',
							},
						] }
						value={ account.smtp_secure || 'tls' }
						onChange={ ( value ) =>
							changeField( 'smtp_secure', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<CheckboxControl
					id={ id( 'smtp_auth' ) }
					label={ __( 'Autenticazione richiesta', 'corsivo-mail' ) }
					name={ name( 'smtp_auth' ) }
					checked={ Boolean( account.smtp_auth ) }
					onChange={ ( value ) => changeField( 'smtp_auth', value ) }
					__nextHasNoMarginBottom
				/>
				<div className="corsivo-mail-settings__field-grid">
					<TextControl
						id={ id( 'smtp_user' ) }
						label={ __( 'Username SMTP', 'corsivo-mail' ) }
						name={ name( 'smtp_user' ) }
						autoComplete="off"
						value={ account.smtp_user || '' }
						onChange={ ( value ) =>
							changeField( 'smtp_user', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						id={ id( 'smtp_pass' ) }
						label={ __( 'Password SMTP', 'corsivo-mail' ) }
						name={ name( 'smtp_pass' ) }
						type="password"
						autoComplete="new-password"
						placeholder={
							isNew
								? __( 'Password', 'corsivo-mail' )
								: __(
										'Lascia vuoto per non cambiare',
										'corsivo-mail'
								  )
						}
						value={ account.smtp_pass || '' }
						onChange={ ( value ) =>
							changeField( 'smtp_pass', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
			</fieldset>

			<fieldset className="corsivo-mail-settings__section">
				<legend>{ __( 'IMAP (ricezione)', 'corsivo-mail' ) }</legend>
				<div className="corsivo-mail-settings__server-grid">
					<TextControl
						id={ id( 'imap_host' ) }
						label={ __( 'Host', 'corsivo-mail' ) }
						help={ __(
							'Vuoto disattiva la ricezione per questo account.',
							'corsivo-mail'
						) }
						name={ name( 'imap_host' ) }
						value={ account.imap_host || '' }
						onChange={ ( value ) =>
							changeField( 'imap_host', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						id={ id( 'imap_port' ) }
						label={ __( 'Porta', 'corsivo-mail' ) }
						name={ name( 'imap_port' ) }
						type="number"
						min="1"
						max="65535"
						value={ account.imap_port || 993 }
						onChange={ ( value ) =>
							changeField( 'imap_port', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						id={ id( 'imap_secure' ) }
						label={ __( 'Sicurezza', 'corsivo-mail' ) }
						name={ name( 'imap_secure' ) }
						options={ [
							{ label: 'SSL', value: 'ssl' },
							{ label: 'STARTTLS', value: 'tls' },
							{
								label: __( 'Nessuna', 'corsivo-mail' ),
								value: 'none',
							},
						] }
						value={ account.imap_secure || 'ssl' }
						onChange={ ( value ) =>
							changeField( 'imap_secure', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<div className="corsivo-mail-settings__field-grid">
					<TextControl
						id={ id( 'imap_user' ) }
						label={ __( 'Username IMAP', 'corsivo-mail' ) }
						help={ __(
							'Vuoto usa lo username SMTP.',
							'corsivo-mail'
						) }
						name={ name( 'imap_user' ) }
						autoComplete="off"
						value={ account.imap_user || '' }
						onChange={ ( value ) =>
							changeField( 'imap_user', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						id={ id( 'imap_pass' ) }
						label={ __( 'Password IMAP', 'corsivo-mail' ) }
						help={ __(
							'Vuoto usa la password SMTP.',
							'corsivo-mail'
						) }
						name={ name( 'imap_pass' ) }
						type="password"
						autoComplete="new-password"
						placeholder={
							isNew
								? __( 'Password IMAP', 'corsivo-mail' )
								: __(
										'Lascia vuoto per non cambiare',
										'corsivo-mail'
								  )
						}
						value={ account.imap_pass || '' }
						onChange={ ( value ) =>
							changeField( 'imap_pass', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				{ ! isNew && (
					<CheckboxControl
						id={ id( 'clear_imap_pass' ) }
						label={ __(
							'Rimuovi la password IMAP dedicata e usa quella SMTP',
							'corsivo-mail'
						) }
						name={ name( 'clear_imap_pass' ) }
						checked={ Boolean( account.clear_imap_pass ) }
						onChange={ ( value ) =>
							changeField( 'clear_imap_pass', value )
						}
						__nextHasNoMarginBottom
					/>
				) }
				{ ! isNew && imapEnabled && (
					<div className="corsivo-mail-settings__imap-test">
						<Button
							variant="secondary"
							isBusy={ testing }
							disabled={ testing }
							onClick={ testImap }
						>
							{ testing
								? __( 'Connessione…', 'corsivo-mail' )
								: __( 'Testa IMAP', 'corsivo-mail' ) }
						</Button>
						<FieldFeedback isLive tone={ testState.tone }>
							{ testState.message }
						</FieldFeedback>
					</div>
				) }
				{ ! isNew && ! imapEnabled && (
					<FieldFeedback tone="help">
						{ __(
							'Attiva il modulo “Ricezione (IMAP)” nel tab Moduli per testare la connessione.',
							'corsivo-mail'
						) }
					</FieldFeedback>
				) }
			</fieldset>

			<RadioControl
				id={ id( 'default' ) }
				label={ __( 'Account predefinito', 'corsivo-mail' ) }
				hideLabelFromVision
				name="default_account"
				options={ [
					{
						label: __(
							'Usa come account predefinito',
							'corsivo-mail'
						),
						value: String( index ),
					},
				] }
				selected={ isDefault ? String( index ) : '' }
				onChange={ onDefaultChange }
			/>
		</div>
	);
}

export function AccountsApp( {
	accounts: initialAccounts = [],
	blankAccount = {},
	presets = {},
	nonce = '',
	restNonce = '',
	imapTestUrl = '',
	imapEnabled = false,
} ) {
	const [ formState, setFormState ] = useState( () => {
		const accounts = initialAccounts.map( ( account ) => ( {
			...account,
			smtp_pass: '',
			imap_pass: '',
			clear_imap_pass: false,
		} ) );
		return {
			accounts,
			defaultAccount:
				accounts.find( ( account ) => account.is_default ) ||
				accounts[ 0 ] ||
				null,
		};
	} );
	const [ pendingRemoval, setPendingRemoval ] = useState( null );

	useProgressiveMount(
		'corsivo-mail-accounts-app',
		'corsivo-mail-accounts-fallback'
	);

	const handleAccountsChange = ( nextAccounts, change ) => {
		setFormState( ( currentState ) => {
			let defaultAccount = currentState.defaultAccount;
			if (
				change.type === 'update' &&
				defaultAccount === change.previousItem
			) {
				defaultAccount = change.item;
			}
			if ( change.type === 'remove' && defaultAccount === change.item ) {
				defaultAccount = nextAccounts[ 0 ] || null;
			}
			if ( change.type === 'add' && ! defaultAccount ) {
				defaultAccount = change.item;
			}
			return { accounts: nextAccounts, defaultAccount };
		} );
	};

	const setDefaultAccount = ( account ) => {
		setFormState( ( currentState ) => ( {
			...currentState,
			defaultAccount: account,
		} ) );
	};

	const confirmRemoval = () => {
		if ( pendingRemoval ) {
			pendingRemoval.onRemove();
			setPendingRemoval( null );
		}
	};

	return (
		<form
			method="post"
			className="corsivo-mail-settings-app corsivo-mail-settings-app--accounts"
		>
			<input type="hidden" name="_wpnonce" value={ nonce } />
			<input
				type="hidden"
				name="corsivo_mail_action"
				value="save_accounts"
			/>
			<FieldFeedback tone="help">
				{ __(
					'Ogni account è un’identità mittente SMTP e, opzionalmente, una casella IMAP. Le password sono cifrate nel database.',
					'corsivo-mail'
				) }
			</FieldFeedback>
			<Repeater
				items={ formState.accounts }
				onChange={ handleAccountsChange }
				itemIdentity="transient"
				identityResetKey="corsivo-mail-accounts"
				createItem={ () => createAccountRow( blankAccount ) }
				getItemLabel={ accountLabel }
				collapsible={ false }
				addLabel={ __( 'Aggiungi account', 'corsivo-mail' ) }
				emptyState={ __(
					'Nessun account configurato.',
					'corsivo-mail'
				) }
				itemsLabel={ __( 'Account SMTP', 'corsivo-mail' ) }
				formatHandleLabel={ ( label ) =>
					__( 'Riordina', 'corsivo-mail' ) + ' ' + label
				}
				formatExpandLabel={ ( label ) =>
					__( 'Apri', 'corsivo-mail' ) + ' ' + label
				}
				formatCollapseLabel={ ( label ) =>
					__( 'Chiudi', 'corsivo-mail' ) + ' ' + label
				}
				formatRemoveLabel={ ( label ) =>
					__( 'Rimuovi', 'corsivo-mail' ) + ' ' + label
				}
				screenReaderInstructions={ __(
					'Premi Spazio o Invio per prendere un account, usa le frecce per spostarlo e premi di nuovo per rilasciarlo.',
					'corsivo-mail'
				) }
				renderItemHeader={ ( { item, index } ) => (
					<span>
						{ accountLabel( item, index ) }
						{ formState.defaultAccount === item && (
							<span className="corsivo-mail-settings__default-badge">
								{ __( 'Predefinito', 'corsivo-mail' ) }
							</span>
						) }
					</span>
				) }
				renderItemActions={ ( context ) => (
					<Button
						className="corsivo-mail-settings__remove"
						icon="trash"
						label={
							__( 'Rimuovi', 'corsivo-mail' ) +
							' ' +
							accountLabel( context.item, context.index )
						}
						isDestructive
						showTooltip
						onClick={ () => setPendingRemoval( context ) }
					/>
				) }
				renderItem={ ( context ) => (
					<AccountFields
						account={ context.item }
						index={ context.index }
						itemId={ context.itemId }
						onChange={ context.onChange }
						presets={ presets }
						imapEnabled={ imapEnabled }
						imapTestUrl={ imapTestUrl }
						restNonce={ restNonce }
						isDefault={ formState.defaultAccount === context.item }
						onDefaultChange={ () =>
							setDefaultAccount( context.item )
						}
					/>
				) }
			/>
			<div className="corsivo-mail-settings__submit">
				<Button type="submit" variant="primary">
					{ __( 'Salva account', 'corsivo-mail' ) }
				</Button>
			</div>
			<ConfirmDialog
				isOpen={ Boolean( pendingRemoval ) }
				title={ __( 'Rimuovi account', 'corsivo-mail' ) }
				content={ __(
					'Rimuovere questo account? Il salvataggio verrà bloccato se è usato da automazioni o email in coda.',
					'corsivo-mail'
				) }
				confirmLabel={ __( 'Rimuovi', 'corsivo-mail' ) }
				cancelLabel={ __( 'Annulla', 'corsivo-mail' ) }
				isDestructive
				onCancel={ () => setPendingRemoval( null ) }
				onConfirm={ confirmRemoval }
				labels={ {
					busy: __( 'Rimozione…', 'corsivo-mail' ),
					error: __( 'Rimozione non riuscita.', 'corsivo-mail' ),
				} }
			/>
		</form>
	);
}
