import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ConfirmDialog } from '@corsivo-suite/wp-admin-ui/confirm-dialog';
import { FieldFeedback } from '@corsivo-suite/wp-admin-ui/field-feedback';
import { Repeater } from '@corsivo-suite/wp-admin-ui/repeater';
import { createGroupRow } from './collection-contracts';
import { useProgressiveMount } from './progressive-mount';

const rulesWithValue = new Set( [ 'role', 'meta', 'manual' ] );

function groupLabel( group, index ) {
	return group.name || __( 'Gruppo', 'corsivo-mail' ) + ' ' + ( index + 1 );
}

function ruleHelp( rule ) {
	const messages = {
		role: __( 'Inserisci lo slug del ruolo WordPress.', 'corsivo-mail' ),
		meta: __( 'Usa il formato chiave=valore.', 'corsivo-mail' ),
		manual: __(
			'Separa gli indirizzi con virgole, punti e virgola o spazi.',
			'corsivo-mail'
		),
	};
	return messages[ rule ] || '';
}

function GroupFields( { group, index, itemId, onChange, rules } ) {
	const name = ( field ) => `groups[${ index }][${ field }]`;
	const id = ( field ) => `corsivo-mail-group-${ itemId }-${ field }`;
	const changeField = ( field, value ) => {
		onChange( { ...group, [ field ]: value } );
	};
	const needsValue = rulesWithValue.has( group.rule );

	return (
		<div className="corsivo-mail-settings__group-fields">
			<input
				type="hidden"
				name={ name( 'id' ) }
				value={ group.id || '' }
			/>
			<div className="corsivo-mail-settings__group-grid">
				<TextControl
					id={ id( 'name' ) }
					label={ __( 'Nome gruppo', 'corsivo-mail' ) }
					name={ name( 'name' ) }
					value={ group.name || '' }
					onChange={ ( value ) => changeField( 'name', value ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					id={ id( 'rule' ) }
					label={ __( 'Regola', 'corsivo-mail' ) }
					name={ name( 'rule' ) }
					options={ Object.entries( rules ).map(
						( [ value, label ] ) => ( { label, value } )
					) }
					value={ group.rule || 'all_users' }
					onChange={ ( value ) => changeField( 'rule', value ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			{ needsValue ? (
				<TextControl
					id={ id( 'value' ) }
					label={ __( 'Valore', 'corsivo-mail' ) }
					name={ name( 'value' ) }
					value={ group.value || '' }
					onChange={ ( value ) => changeField( 'value', value ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) : (
				<input
					type="hidden"
					name={ name( 'value' ) }
					value={ group.value || '' }
				/>
			) }
			<FieldFeedback tone="help">
				{ ruleHelp( group.rule ) }
			</FieldFeedback>
		</div>
	);
}

export function GroupsApp( {
	groups: initialGroups = [],
	rules = {},
	nonce = '',
} ) {
	const [ groups, setGroups ] = useState( initialGroups );
	const [ pendingRemoval, setPendingRemoval ] = useState( null );

	useProgressiveMount(
		'corsivo-mail-groups-app',
		'corsivo-mail-groups-fallback'
	);

	const confirmRemoval = () => {
		if ( pendingRemoval ) {
			pendingRemoval.onRemove();
			setPendingRemoval( null );
		}
	};

	return (
		<form
			method="post"
			className="corsivo-mail-settings-app corsivo-mail-settings-app--groups"
		>
			<input type="hidden" name="_wpnonce" value={ nonce } />
			<input type="hidden" name="corsivo_mail_groups_action" value="1" />
			<FieldFeedback tone="help">
				{ __(
					'Definisci gruppi riutilizzabili come destinatari delle email programmate.',
					'corsivo-mail'
				) }
			</FieldFeedback>
			<Repeater
				items={ groups }
				onChange={ setGroups }
				itemIdentity="transient"
				identityResetKey="corsivo-mail-groups"
				createItem={ createGroupRow }
				getItemLabel={ groupLabel }
				collapsible={ false }
				addLabel={ __( 'Aggiungi gruppo', 'corsivo-mail' ) }
				emptyState={ __(
					'Nessun gruppo configurato.',
					'corsivo-mail'
				) }
				itemsLabel={ __( 'Gruppi destinatari', 'corsivo-mail' ) }
				formatHandleLabel={ ( label ) =>
					__( 'Riordina', 'corsivo-mail' ) + ' ' + label
				}
				formatRemoveLabel={ ( label ) =>
					__( 'Rimuovi', 'corsivo-mail' ) + ' ' + label
				}
				screenReaderInstructions={ __(
					'Premi Spazio o Invio per prendere un gruppo, usa le frecce per spostarlo e premi di nuovo per rilasciarlo.',
					'corsivo-mail'
				) }
				renderItemHeader={ ( { item, index } ) =>
					groupLabel( item, index )
				}
				renderItemActions={ ( context ) => (
					<Button
						className="corsivo-mail-settings__remove"
						icon="trash"
						label={
							__( 'Rimuovi', 'corsivo-mail' ) +
							' ' +
							groupLabel( context.item, context.index )
						}
						isDestructive
						showTooltip
						onClick={ () => setPendingRemoval( context ) }
					/>
				) }
				renderItem={ ( context ) => (
					<GroupFields
						group={ context.item }
						index={ context.index }
						itemId={ context.itemId }
						onChange={ context.onChange }
						rules={ rules }
					/>
				) }
			/>
			<div className="corsivo-mail-settings__submit">
				<Button type="submit" variant="primary">
					{ __( 'Salva gruppi', 'corsivo-mail' ) }
				</Button>
			</div>
			<ConfirmDialog
				isOpen={ Boolean( pendingRemoval ) }
				title={ __( 'Rimuovi gruppo', 'corsivo-mail' ) }
				content={ __( 'Rimuovere questo gruppo?', 'corsivo-mail' ) }
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
