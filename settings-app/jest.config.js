const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	transformIgnorePatterns: [
		'node_modules/(?!@corsivo-suite/wp-admin-ui/)',
	],
};
