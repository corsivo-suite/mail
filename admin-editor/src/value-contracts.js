export function parseEmailList( value ) {
	return String( value || '' )
		.split( /[\r\n,;]+/ )
		.map( ( email ) => email.trim() )
		.filter( Boolean );
}

export function serializeEmailList( values ) {
	return ( Array.isArray( values ) ? values : [] ).join( '\n' );
}
