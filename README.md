# Monks Central

A lean, framework-free starter for WordPress plugins. Namespaced PHP with a
Composer autoloader and a small module registry, one non-autoloaded option,
REST endpoints, a React admin screen built from what wp-admin already ships,
an Interactivity API front end and Abilities API registration. PHPStan, PHPCS,
PHPUnit, Playwright on WordPress Playground and Plugin Check run in CI.

Requires WordPress 7.1+ and PHP 8.1+. Zero runtime dependencies.

## Quick start

```sh
git clone https://github.com/joychetry/monks-central.git acme-tools
cd acme-tools
bin/rename.sh acme-tools "Acme Tools"
composer install && npm install && npm run format && npm run build
npx @wp-playground/cli@latest server --mount=.:/wordpress/wp-content/plugins/acme-tools \
  --blueprint=blueprint.json
```

Open http://127.0.0.1:9400. You land on Settings > Acme Tools, logged in, with
`WP_DEBUG` on.

## What is inside

- `monks-central.php`: header, constants, autoloader, boot on `plugins_loaded`.
- `src/Plugin.php`: the module registry. Modules are listed in one array; only
  enabled ones register hooks.
- `src/Settings.php`: the `monks_central_settings` option (not autoloaded). Fields are
  declared once as JSON Schema; defaults, sanitizing and the REST schema follow.
- `src/Rest/SettingsController.php`: `GET`/`POST /monks-central/v1/settings`, admins only.
- `src/Admin/Page.php` + `assets-src/admin/`: Settings > Monks Central, a
  React app with a DataViews modules table and a DataForm settings form. Loads
  on that screen only.
- `src/Modules/Example/`: a complete module. "Show related posts" under single
  posts: hooks, a public REST route, an ability, and an Interactivity API store
  in `assets-src/frontend/` (292 bytes of JS, 270 of CSS gzipped, only on single
  posts).
- `tests/php/`: PHPUnit with Brain Monkey. `tests/e2e/`: Playwright on Playground.
- `bin/rename.sh`, `bin/build-zip.sh`, `blueprint.json`, `.github/workflows/ci.yml`.

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

- [docs/STACK.md](docs/STACK.md): every decision with the why, what to avoid,
  the Classic Monks comparison, performance rules.
- [docs/REUSE.md](docs/REUSE.md): new plugins, local dev, where things go,
  checks, pulling starter updates, releases.
- [docs/MODULES.md](docs/MODULES.md): writing a module, step by step.
- [CHANGELOG.md](CHANGELOG.md)
