# Monks Central (WordPress plugin)

The site connector for [Monks Central](https://github.com/joychetry/monks-central),
the free desktop app for managing many WordPress sites from one place. Install
it on a site, add the site in the app and approve the connection in wp-admin.
The app can then run updates, manage plugins, themes, content, media, users and
comments, and show what changed.

Status: in development, private. This is the scaffold from
[wp-plugin-starter](https://github.com/joychetry/wp-plugin-starter); the shared
`monks-central-connector` library (OAuth 2.1 sign-in, MCP endpoint, approvals,
activity log and the management abilities) lands in `connector/` next. Classic
Monks and Classic Monks Pro bundle the same library; whichever copy has the
highest version runs, so any mix of the three plugins gives one connector and
keeps existing connections.

Requires WordPress 7.1+ and PHP 8.1+. Zero runtime dependencies.

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
  app with a DataViews modules table. Loads on that screen only.
- `tests/php/`: PHPUnit with Brain Monkey. `tests/e2e/`: Playwright on Playground.
- `bin/build-zip.sh`, `blueprint.json`, `.github/workflows/ci.yml`.

## Commands

| Command | What it does |
| --- | --- |
| `composer lint` / `lint:fix` | PHPCS (WPCS, PHPCompatibilityWP 8.1-) |
| `composer analyse` | PHPStan level 6 with WordPress stubs |
| `composer test` | PHPUnit |
| `npm run build` / `start` | wp-scripts build / watch |
| `npm run lint:js` / `lint:css` / `format` | ESLint, Stylelint, Prettier |
| `npm run test:e2e` | Playwright against a fresh Playground site on port 9500 |
| `npm run plugin-zip` | `dist/monks-central.zip` without dev files |

## Docs

`docs/STACK.md`, `docs/REUSE.md` and `docs/MODULES.md` come from the starter
and describe its stack and conventions. `CHANGELOG.md` is the starter's until
the first Monks Central release.
