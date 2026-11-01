# Contributing to the WP Sites HQ plugin

Thanks for helping. Bug reports, fixes and docs are all welcome. For a bigger
change, open an issue first so we can agree on the shape before you spend time
on it. Security problems: report them privately through GitHub's
"Report a vulnerability" on the Security tab, not in an issue.

Read `docs/STACK.md` before larger changes and `docs/MODULES.md` before adding
a feature. Set up as in the README's "Local development".

## Before you open a pull request

Run the fast checks for what you touched; they take seconds:

```sh
php -l <changed PHP files>
composer lint            # PHPCS (WPCS, PHPCompatibilityWP)
composer test            # PHPUnit
npm run lint:js && npm run lint:css   # if you touched assets-src/
grep -rn $'—' src connector templates assets-src   # no em dashes
```

CI (`.github/workflows/ci.yml`) runs the full suites on every pull request:
`php -l` on every file, PHPCS, PHPStan, PHPUnit, the JS and CSS linters, the
build, Playwright on a fresh Playground site, and the release zip with Plugin
Check. To run them locally too: `composer analyse`, `npm run build` and
`npm run test:e2e`.

## Rules

Never:

- Add a framework (Acorn/Laravel, WPPB-style loaders), jQuery, Tailwind, Vite
  or any front-end runtime library.
- Add a runtime Composer dependency. If one is unavoidable, prefix it with
  Strauss (see `docs/STACK.md`) and log it in `CHANGELOG.md`.
- Use admin-ajax. Server calls are REST routes.
- Register a REST route or ability without a real `permission_callback`.
- Enqueue assets globally. Admin assets load on the plugin's own screen;
  front-end assets load only where their markup renders.
- Autoload options. Settings live in `monks_central_settings` (autoload off).
- Commit `build/`, `vendor/`, `node_modules/` or `dist/`.
- Use em dashes in copy, comments or docs.

Always:

- WordPress 7.1+, PHP 8.1+. Namespaced classes under `src/` (PSR-4), no global
  functions.
- New features are modules: `src/Modules/<Name>/Module.php` implementing
  `Contracts\Module`, listed in `Plugin::MODULES`. `register()` only adds hooks,
  with class-string callables so classes load when the hook fires.
- Settings fields are declared once in `Settings::fields()` (JSON Schema) and
  get a matching control on the admin screen.
- Admin UI: React, `@wordpress/components`, DataViews for lists, DataForm for
  forms, `apiFetch` to REST. Front end: Interactivity API script modules with
  server-rendered markup (`wp_interactivity_process_directives()`).
- Abilities: register on `wp_abilities_api_init` behind
  `function_exists( 'wp_register_ability' )`, same permission rule as the REST
  route.
- Escape output (`esc_html`, `esc_attr`, `esc_url`), sanitize input, check
  capabilities. REST nonces come from core via `apiFetch`.
- Plain CSS scoped under a `.monks-central-` class, tweakables as custom properties.
- Prefix: namespace `MonksCentral`, hooks and options `monks_central_`, handles
  and CSS classes `monks-central-`, constants `MONKS_CENTRAL_`, REST namespace
  `monks-central/v1`, text domain `wp-sites-hq` (the `monks_central` names stay
  so sites keep their data).
- Delete everything the plugin stores in `uninstall.php`.
- One logical change per commit, with a clear message; tests in the same commit.

## Licence

The plugin is GPL-2.0-or-later. By sending a contribution you agree that it is
released under the same licence.
