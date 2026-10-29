/**
 * Playwright e2e against a throwaway WordPress Playground site built from
 * blueprint.json. Port 9500 so the suite can run next to a dev server on 9400.
 */
const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

const baseURL = 'http://127.0.0.1:9500';

module.exports = defineConfig( {
	testDir: '.',
	timeout: 60_000,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI
		? [ [ 'list' ], [ 'html', { open: 'never' } ] ]
		: 'list',
	use: { baseURL, trace: 'retain-on-failure' },
	webServer: {
		command:
			'npx wp-playground-cli server --port=9500 --mount=.:/wordpress/wp-content/plugins/wp-plugin-starter --blueprint=blueprint.json',
		cwd: path.resolve( __dirname, '../..' ),
		// Playground answers 502 while booting and can hang requests sent then,
		// so wait for its ready line instead of polling a URL. Always a fresh site.
		wait: { stdout: /Ready!/ },
		timeout: 300_000,
	},
} );
