# Monks Central (WordPress plugin)

The site connector for [Monks Central](https://github.com/joychetry/monks-central),
the free desktop app for managing many WordPress sites from one place. Install
it on a site, add the site in the app and approve the connection in wp-admin.
The app can then run updates, manage plugins, themes, content, media, users and
comments, and show what changed.

Requires WordPress 7.1+ and PHP 8.1+. Zero runtime dependencies.

Classic Monks and Classic Monks Pro bundle the same connector library
(`connector/`). Whichever copy has the highest version runs, so any mix of the
three plugins gives one connector and keeps existing connections.

## Install

1. Download `monks-central.zip` from the
   [latest release](https://github.com/joychetry/monks-central-wp/releases/latest).
   Download the zip asset, not GitHub's "Source code" archives: those have no
   `vendor/` or `build/` and do not run.
2. Optional: check it against `monks-central.zip.sha256` from the same release:
   `sha256sum -c monks-central.zip.sha256`.
3. In wp-admin, go to Plugins > Add New Plugin > Upload Plugin, choose the zip
   and activate it.
4. Open Settings > Monks Central and turn on Allow Monks Central to connect.
5. Add the site in the Monks Central app and approve the connection when
   wp-admin asks.

Or with WP-CLI: `wp plugin install monks-central.zip --activate`.

Updates arrive in wp-admin like any other plugin update. The plugin reads the
latest GitHub release (not drafts or pre-releases) and installs its zip only
when the SHA-256 matches the release's `monks-central.zip.sha256`.

## Local development

```sh
composer install && npm install && npm run build
npx @wp-playground/cli@latest server --mount=.:/wordpress/wp-content/plugins/monks-central \
  --blueprint=blueprint.json
```

Open http://127.0.0.1:9400. You land on Settings > Monks Central, logged in,
with `WP_DEBUG` on.

## What is inside

- `monks-central.php`: header, constants, autoloader, boot on `plugins_loaded`.
- `src/Plugin.php`: the module registry. Modules are listed in one array; only
  enabled ones register hooks.
- `src/Settings.php`: the `monks_central_settings` option (not autoloaded).
  Fields are declared once as JSON Schema; defaults, sanitizing and the REST
  schema follow.
- `src/Rest/SettingsController.php`: `GET`/`POST /monks-central/v1/settings`,
  admins only.
- `src/Admin/Page.php` + `assets-src/admin/`: Settings > Monks Central, a React
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
| `npm run plugin-zip` | `dist/monks-central.zip` and its `.sha256`, without dev files |

## Releasing

Pushing a `v*` tag (for example `v0.1.0`, matching the plugin's `Version`) runs
`.github/workflows/release.yml`: it builds the zip, writes its SHA-256 and
creates a draft release with both files and the version's `CHANGELOG.md`
section. Nothing is public until someone publishes the draft. See
`docs/release/checklist.md` before the first release.

## Docs

`docs/STACK.md`, `docs/REUSE.md` and `docs/MODULES.md` come from the starter
and describe its stack and conventions. `CHANGELOG.md` lists each release.
