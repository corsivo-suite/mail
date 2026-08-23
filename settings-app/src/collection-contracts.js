export function createAccountRow( blankAccount ) {
	return {
		...blankAccount,
		id: '',
		is_default: false,
		smtp_pass: '',
		imap_pass: '',
		clear_imap_pass: false,
	};
}

export function createGroupRow() {
	return {
		id: '',
		name: '',
		rule: 'all_users',
		value: '',
	};
}

export function updateCollectionItem( items, index, values ) {
	return items.map( ( item, itemIndex ) =>
		itemIndex === index ? { ...item, ...values } : item
	);
}

export function removeCollectionItem( items, index ) {
	return items.filter( ( item, itemIndex ) => itemIndex !== index );
}

export function reorderCollectionItems( items, oldIndex, newIndex ) {
	const nextItems = [ ...items ];
	const [ movedItem ] = nextItems.splice( oldIndex, 1 );
	nextItems.splice( newIndex, 0, movedItem );
	return nextItems;
}
