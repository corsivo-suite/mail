import { AsyncCombobox } from '@corsivo-suite/wp-admin-ui/async-combobox';
import { AsyncMultiSelect } from '@corsivo-suite/wp-admin-ui/async-multi-select';
import { ColorControl } from '@corsivo-suite/wp-admin-ui/color-control';
import { DateTimeControl } from '@corsivo-suite/wp-admin-ui/date-time-control';
import '@corsivo-suite/wp-admin-ui/styles.css';
import { parseEmailList, serializeEmailList } from './value-contracts';

window.CorsivoMailAdminUI = {
	AsyncCombobox,
	AsyncMultiSelect,
	ColorControl,
	DateTimeControl,
	parseEmailList,
	serializeEmailList,
};

require( '../../assets/editor.js' );
require( '../../assets/email-editor.js' );

delete window.CorsivoMailAdminUI;
