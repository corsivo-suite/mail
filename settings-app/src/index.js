import { createRoot, render } from '@wordpress/element';
import '@corsivo-suite/wp-admin-ui/styles.css';
import '../../assets/settings.css';
import { AccountsApp } from './accounts-app';
import { GroupsApp } from './groups-app';

const config = window.CorsivoMailSettingsUI || {};
const mounts = {
	accounts: {
		component: AccountsApp,
		id: 'corsivo-mail-accounts-app',
		props: config.accounts,
	},
	groups: {
		component: GroupsApp,
		id: 'corsivo-mail-groups-app',
		props: config.groups,
	},
};
const currentMount = mounts[ config.tab ];

if ( currentMount ) {
	const node = document.getElementById( currentMount.id );
	if ( node ) {
		const app = <currentMount.component { ...currentMount.props } />;
		if ( createRoot ) {
			createRoot( node ).render( app );
		} else {
			render( app, node );
		}
	}
}
