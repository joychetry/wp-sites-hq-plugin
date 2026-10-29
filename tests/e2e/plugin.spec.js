/**
 * Smoke tests: the plugin activates, its screen renders, the front end loads
 * none of its assets, and no page prints a PHP error (the blueprint turns
 * WP_DEBUG on).
 */
const { test, expect } = require( '@playwright/test' );

const PHP_ERROR =
	/<b>(Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:/;

async function visit( page, path ) {
	let response = await page.goto( path );
	// Playground's auto-login does not always fire (it did not on CI), so log in by hand.
	if ( page.url().includes( '/wp-login.php' ) ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL(
			( url ) => ! url.pathname.endsWith( '/wp-login.php' )
		);
		response = await page.goto( path );
	}
	expect( response.status() ).toBeLessThan( 400 );
	expect( await page.content() ).not.toMatch( PHP_ERROR );
}

test( 'plugin is active', async ( { page } ) => {
	await visit( page, '/wp-admin/plugins.php' );
	await expect( page.locator( 'tr[data-slug="monks-central"]' ) ).toHaveClass(
		/\bactive\b/
	);
} );

test( 'admin screen renders', async ( { page } ) => {
	await visit( page, '/wp-admin/options-general.php?page=monks-central' );
	await expect(
		page.getByRole( 'heading', { name: 'Modules' } )
	).toBeVisible();
} );

test( 'front page has no plugin assets', async ( { page } ) => {
	await visit( page, '/' );
	expect( await page.content() ).not.toContain( '/plugins/monks-central/' );
} );
