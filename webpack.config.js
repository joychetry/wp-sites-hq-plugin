/**
 * wp-scripts config with explicit entries: one classic script for wp-admin and
 * one script module for the front end. Add an entry here for each new bundle.
 */
const [
	scripts,
	modules,
] = require( '@wordpress/scripts/config/webpack.config' );

module.exports = [
	{ ...scripts, entry: { admin: './assets-src/admin/index.js' } },
	{ ...modules, entry: { example: './assets-src/frontend/example.js' } },
];
