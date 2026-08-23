import { ConfirmDialog } from '@corsivo-suite/wp-admin-ui/confirm-dialog';
import '@corsivo-suite/wp-admin-ui/styles.css';
import '../../assets/inbox.css';

window.CorsivoMailInboxUI = {
	ConfirmDialog,
};

require( '../../assets/inbox.js' );

delete window.CorsivoMailInboxUI;
