# WP Sites HQ (WordPress plugin)

The site connector for [WP Sites HQ](https://wpsiteshq.com),
the free desktop app for managing many WordPress sites from one place. Install
it on a site, add the site in the app and approve the connection in wp-admin.
The app can then run updates, manage plugins, themes, content, media, users and
comments, and show what changed.

Requires WordPress 7.1+ and PHP 8.1+. Zero runtime dependencies.

Classic Monks and Classic Monks Pro bundle the same connector library
(`connector/`). Whichever copy has the highest version runs, so any mix of the
three plugins gives one connector and keeps existing connections.

## Install

1. Download `wp-sites-hq.zip` from the
   [latest release](https://github.com/joychetry/wp-sites-hq-plugin/releases/latest).
   Download the zip asset, not GitHub's "Source code" archives: those have no
   `vendor/` or `build/` and do not run.
2. Optional: check it against `wp-sites-hq.zip.sha256` from the same release:
   `sha256sum -c wp-sites-hq.zip.sha256`.
3. In wp-admin, go to Plugins > Add New Plugin > Upload Plugin, choose the zip
   and activate it.
4. Open Settings > WP Sites HQ and turn on Allow WP Sites HQ to connect.
5. Add the site in the WP Sites HQ app and approve the connection when
   wp-admin asks.

Or with WP-CLI: `wp plugin install wp-sites-hq.zip --activate`.

Updates arrive in wp-admin like any other plugin update. The plugin reads the
latest GitHub release (not drafts or pre-releases) and installs its zip only
when the SHA-256 matches the release's `wp-sites-hq.zip.sha256`.

## Moving from Monks Central

This plugin was called Monks Central and lived in the folder `monks-central`.
WordPress cannot move a plugin to a new folder with an update, so each release
also carries the 0.1.4 bridge as `monks-central.zip` (built from tag
`v0.1.4-bridge`, branch `bridge/0.1.x`). Sites on Monks Central 0.1.x update to
the bridge, which shows "Move this site": one click installs WP Sites HQ,
activates it and deactivates Monks Central (old). Connections and settings stay.

By hand: install `wp-sites-hq.zip`, activate WP Sites HQ, then deactivate and
delete Monks Central (old). Activate WP Sites HQ first: deleting the old plugin
while WP Sites HQ is not active removes the site's connections.

Kept from Monks Central so sites need no data migration: the settings option
`monks_central_settings`, the `MONKS_CENTRAL_*` constants, the `MonksCentral`
namespace, the settings page slug `monks-central`, the REST namespaces and
every connector option, table and ability name.

## Local development

```sh
composer install && npm install && npm run build
npx @wp-playground/cli@latest server --mount=.:/wordpress/wp-content/plugins/wp-sites-hq \
  --blueprint=blueprint.json
```

Open http://127.0.0.1:9400. You land on Settings > WP Sites HQ, logged in,
with `WP_DEBUG` on.

## What is inside

- `wp-sites-hq.php`: header, constants, autoloader, boot on `plugins_loaded`.
- `src/Plugin.php`: the module registry. Modules are listed in one array; only
  enabled ones register hooks.
- `src/Settings.php`: the `monks_central_settings` option (not autoloaded).
  Fields are declared once as JSON Schema; defaults, sanitizing and the REST
  schema follow.
- `src/Rest/SettingsController.php`: `GET`/`POST /monks-central/v1/settings`,
  admins only.
- `src/Admin/Page.php` + `assets-src/admin/`: Settings > WP Sites HQ, a React
  app (connected apps, approvals, activity). Loads on that screen only.
- `src/Rest/ConnectorController.php`, `src/Connector/Ui.php`,
  `templates/consent.php`: this plugin's side of the connector's UI interface.
- `src/Updater.php`: updates from GitHub releases, SHA-256 checked. Left out of
  the wp.org build (`bin/build-zip.sh --wporg`).
- `connector/`: the shared `monks-central-connector` library, with its own
  version (`connector/version.php`) and suites in `connector/tests/`.
- `tests/php/`: PHPUnit with Brain Monkey. `tests/e2e/`: Playwright on Playground.
  `tests/mcp/ci.sh`: the connector suites on a throwaway site. `tests/mix/`: every
  mix with Classic Monks and Classic Monks Pro.
- `bin/build-zip.sh`, `blueprint.json`, `.github/workflows/ci.yml` and
  `release.yml`.

## Commands

| Command | What it does |
| --- | --- |
| `composer lint` / `lint:fix` | PHPCS (WPCS, PHPCompatibilityWP 8.1-) |
| `composer analyse` | PHPStan level 6 with WordPress stubs |
| `composer test` | PHPUnit |
| `npm run build` / `start` | wp-scripts build / watch |
| `npm run lint:js` / `lint:css` / `format` | ESLint, Stylelint, Prettier |
| `npm run test:e2e` | Playwright against a fresh Playground site on port 9500 |
| `npm run plugin-zip` | `dist/wp-sites-hq.zip` and its `.sha256`, without dev files |

## Releasing

Pushing a `v*` tag (for example `v0.2.0`, matching the plugin's `Version`) runs
`.github/workflows/release.yml`: it builds `wp-sites-hq.zip` and the bridge
`monks-central.zip` (from tag `v0.1.4-bridge`), writes a SHA-256 for each and
creates a draft release with the four files and the version's `CHANGELOG.md`
section. Nothing is public until someone publishes the draft. See
`docs/release/checklist.md`.

## Docs

`docs/STACK.md`, `docs/REUSE.md` and `docs/MODULES.md` come from the starter
and describe its stack and conventions. `CHANGELOG.md` lists each release.
