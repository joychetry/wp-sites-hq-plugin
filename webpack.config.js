/**
 * wp-scripts config with explicit entries: one classic script for wp-admin.
 * Add an entry here for each new bundle; front-end script modules use the
 * second config that `--experimental-modules` returns.
 */
const [ scripts ] = require( '@wordpress/scripts/config/webpack.config' );

module.exports = [
	{ ...scripts, entry: { admin: './assets-src/admin/index.js' } },
];
