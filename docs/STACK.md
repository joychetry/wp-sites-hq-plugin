# Stack decisions

Everything this starter decided, and why. Source research: "Best stack for
building a WordPress plugin (Oct 2026), compared with Classic Monks"
(2026-10-12). Where that note and this file disagree, this file wins: the PHP
floor moved from 7.4 to 8.1 for new plugins, and PHPStan starts at level 6
because there is no legacy code to baseline.

## Summary

Do not use a framework. Stay close to WordPress itself: modern PHP with a
Composer autoloader, the JS packages wp-admin already ships, the Interactivity
API on the front end, the REST API for every server call, and PHPStan, PHPCS,
PHPUnit and Playground to keep it honest. A Laravel-in-WordPress setup like
Acorn costs too much on every request for plugins that each do one job.

## Layer by layer

| Layer | Pick | Why |
| --- | --- | --- |
| PHP | 8.1+, tested on 8.1 and 8.3 in CI | Enums, readonly and promoted properties, `match`, first-class callables. WordPress 7.0 dropped 7.2 and 7.3; 8.1 is a safe floor for new plugins on managed hosting. |
| Code structure | Namespaces + Composer PSR-4 autoloader | Classes load only when first used. No `require_once` lists to maintain. Static analysis can follow every call. |
| Framework | None. `src/Plugin.php` is the module registry (about 100 lines) | A module is one class with `is_enabled()` and `register()`. A switched-off module adds no hooks and loads none of its classes. |
| PHP runtime deps | None | Two plugins bundling different versions of the same library is the classic WordPress fatal. See "If you ever need a runtime dependency". |
| Settings | One option, `wps_settings`, `autoload` off | One read per request, cached in a static. Not autoloaded, so it never weighs on `alloptions` for pages that do not use the plugin. |
| Server calls | REST API, a `permission_callback` on every route. No admin-ajax | Schemas, argument validation, cookie nonce handled by core (`wp_rest` via `apiFetch`), testable, cacheable, and the same shape the Abilities API uses. |
| Admin UI | React + `@wordpress/components` + DataViews / DataForm | React and components already ship in wp-admin; dependency-extraction turns them into externals, so the bundle only carries our code and DataViews. DataViews powers core list screens since 7.0. |
| Front-end JS | Interactivity API (script modules) | Server-rendered markup with directives, about 13KB shared with core blocks, loaded as a module only on pages that render the feature. Vanilla script modules for anything tiny. |
| CSS | Plain CSS, custom properties, scoped by prefix | Every rule sits under a `.wps-` class. Themes restyle through custom properties instead of overriding selectors. |
| AI | Abilities API (`wp_register_ability`) + MCP Adapter | Core since 6.9. One registration makes a feature callable from REST and, through the MCP Adapter, from AI clients. |
| Build | `@wordpress/scripts` | Zero config, generates `*.asset.php` with dependencies and a content hash version, builds script modules with `--experimental-modules`. |
| Static analysis | PHPStan + `szepeviktor/phpstan-wordpress`, level 6 | Catches wrong types and dead code before runtime. Level 6 requires typed signatures, which new code should have anyway. |
| Coding standards | PHPCS + WPCS (WordPress-Extra, WordPress-Docs) + PHPCompatibilityWP `8.1-`; ESLint, Prettier and Stylelint via wp-scripts | Escaping, sanitizing, nonce and prefix rules are checked, not remembered. |
| Tests | PHPUnit with Brain Monkey (no WordPress install), Playwright e2e on WordPress Playground | Unit tests run in under a second. e2e boots a throwaway site from `blueprint.json` in about 30 seconds, no Docker. |
| Release checks | Plugin Check (`wordpress/plugin-check-action`) on the built zip | The same checks wordpress.org runs, on what actually ships. |
| CI | GitHub Actions: PHP lint, PHPCS, PHPStan, PHPUnit (8.1 + 8.3), JS lint + build, e2e, zip + Plugin Check | Every push. The zip is an artifact of every run. |
| Local dev | WordPress Playground CLI or WordPress Studio | No Docker, no database server. `wp-env` works too if you already use it. |

## Avoid, and why

- **Acorn / Laravel (Roots).** Boots a container and service providers on every
  request. Good for agency sites built on Sage, wrong for a plugin that should
  cost nothing when idle.
- **jQuery.** Not needed in 2026. wp-admin ships React; the front end has the
  Interactivity API and plain DOM APIs. Adding jQuery to the front end costs
  about 30KB on every page that loads it.
- **Tailwind.** A second token system next to theme.json and the admin design
  tokens, plus a build step for CSS. Plain CSS with custom properties is enough
  for plugin UI.
- **Vite.** Fast, but wp-scripts already produces the `*.asset.php` files
  WordPress needs. With Vite you maintain that glue yourself.
- **WPPB (WordPress Plugin Boilerplate).** A dated folder layout with a loader
  class, no autoloader, no tests, no tooling. It is a template, not a framework,
  and this starter replaces it.
- **admin-ajax.** No schema, no argument validation, every handler checks its
  own nonce and capability by hand, and every request boots the full admin.

## Compared with Classic Monks

Measured from the Classic Monks repo at `5e3a0081` (2026-10-12).

**What it gets right, and this starter keeps:**

- **Conditional module loading.** Features load only when switched on. Here:
  `Plugin::MODULES` plus `is_enabled()`; a disabled module adds no hooks and
  loads no classes.
- **Its own options storage.** Classic Monks reads one custom table in a single
  bulk query. Here: one non-autoloaded option with a static cache (a custom
  table only when settings get large, see below).
- **Admin-only asset loading.** Classic Monks loads admin assets only on its own
  pages. Here: `Admin\Page::enqueue()` checks the hook suffix.
- **Abilities API early.** Classic Monks has hundreds of abilities. Here: every
  module can register abilities in its own `Ability.php`.

**What it lacks, and how this starter fixes it:**

| Gap in Classic Monks | Fix here |
| --- | --- |
| No tests | PHPUnit with Brain Monkey for logic, Playwright on Playground for the real thing. |
| No static analysis | PHPStan level 6 with WordPress stubs, and PHPCS with WPCS, both in CI. |
| No CI | GitHub Actions on every push, including the release zip and Plugin Check. |
| No autoloader (687 `require_once`, ~1,900 global functions) | Namespaces and Composer PSR-4. No global functions. |
| admin-ajax heavy (396 actions vs 16 REST routes) | REST only. Every route has a `permission_callback`. |
| Hand-minified assets (terser/cleancss per file, easy to ship stale `.min` files) | `@wordpress/scripts` builds everything; `build/` is never committed; the zip script always rebuilds. |

## PHP floor

New plugins: **PHP 8.1**. Declared in the plugin header, `readme.txt`,
`composer.json` (`config.platform.php` pins the lock file to 8.1) and checked by
PHPCompatibilityWP with `testVersion 8.1-`.

If a plugin must run on cheap shared hosting that is still on 7.4: lower the
header and readme to 7.4, set `testVersion` to `7.4-` and
`config.platform.php` to `7.4.0`, and let PHPCS point out every 8.x feature
(promoted properties, `match`, union types, `mixed`, readonly). WordPress 7.0's
own floor is 7.4.

## Build: @wordpress/scripts now, @wordpress/build later

`@wordpress/build` (esbuild, no config, generates the PHP asset registration)
is what Gutenberg builds itself with, but it is not ready for every plugin
layout yet. **Revisit in early 2027.** Until then wp-scripts with an explicit
`webpack.config.js`: one classic script entry for wp-admin, one script module
entry per front-end feature.

### DataViews bundle size

Core does not register DataViews as a shared script (dependency-extraction
lists it as bundled), so `build/admin.js` carries it: about 850KB minified,
200KB gzipped with DataViews 20, mostly `@wordpress/ui`, Base UI and the date
pickers. It loads only on the plugin's own admin screen. Import from
`@wordpress/dataviews` (tree-shaken), not `@wordpress/dataviews/wp` (a prebuilt
2MB file). If core starts shipping DataViews as a script, drop it from the
bundle.

## Performance rules

1. **Load nothing until needed.** `boot()` runs on `plugins_loaded`, reads one
   option and registers hooks. All work happens inside hook callbacks, and
   classes autoload on first use.
2. **Non-autoloaded option.** `update_option( ..., false )`. Read once per
   request, cached in a static.
3. **Enqueue only where used.** Admin assets on the plugin's screen only.
   Front-end modules and CSS only on pages that render the feature; small CSS
   gets `path` data so core inlines it.
4. **Zero runtime dependencies.** `vendor/` in the zip holds only the Composer
   autoloader. Nothing to conflict with other plugins.
5. **No queries you do not need.** `no_found_rows`, `fields => ids`, skip meta
   and term cache priming when unused, and no `post__not_in` (fetch one extra
   and filter in PHP).

The example module costs 292 bytes of JS and 270 bytes of CSS (gzipped) on a
single post, nothing anywhere else.

## Settings: one option or a custom table

Start with the single option. Move to a custom table when settings become many
(hundreds of keys, per-user or per-item rows, or values you need to query by).
Create the table with `dbDelta()` on activation, store a schema version option,
and keep `Settings` as the only class that knows where values live, so callers
do not change.

## If you ever need a runtime dependency

Avoid it first. If it is unavoidable, prefix it with
[Strauss](https://github.com/BrianHenryIE/strauss) so it cannot clash with
another plugin's copy:

1. `composer require vendor/package` and `composer require --dev brianhenryie/strauss`.
2. In `composer.json`, add `extra.strauss` with `namespace_prefix`
   `WpPluginStarter\\Vendor\\` and `classmap_prefix` `WpPluginStarter_`, and
   target directory `vendor-prefixed`.
3. Run Strauss in `post-install-cmd` and `post-update-cmd`, require
   `vendor-prefixed/autoload.php` from the main file, and add
   `vendor-prefixed` to the zip.
4. Log the dependency, its size and the reason in `CHANGELOG.md`.

## Translations

No `languages/` folder and no `load_plugin_textdomain()`. Since WordPress 4.6
translations from translate.wordpress.org load just in time, and since 6.7
loading a text domain before `init` is flagged, so there is nothing to call.
For a private plugin with bundled translations: add `languages/`, generate the
template with `wp i18n make-pot . languages/wp-plugin-starter.pot`, add
`Domain Path: /languages` to the header, and call
`load_plugin_textdomain( 'wp-plugin-starter', false, dirname( plugin_basename( WPS_FILE ) ) . '/languages' )`
on `init`. JS strings use `wp_set_script_translations()`, already wired for the
admin app.

## AI: Abilities API and MCP Adapter

Each module registers its abilities on `wp_abilities_api_init` in its own
`Ability.php`, guarded by `function_exists( 'wp_register_ability' )`. The plugin
registers one category, `wp-plugin-starter`, on
`wp_abilities_api_categories_init`. Abilities with `show_in_rest` are listed
under `/wp-abilities/v1/abilities`.

To expose abilities to AI clients (Claude, Cursor, VS Code), install the
[MCP Adapter](https://github.com/WordPress/mcp-adapter) on the site. It serves
registered abilities as MCP tools. Reuse the same permission rule as the REST
route, write a description an agent can act on, and mark read-only abilities
with the `readonly` annotation.

## Open questions

- `@wordpress/build` readiness for non-block admin plugins. Re-check in Q1 2027.
- Whether core ships DataViews as a shared script, which would cut the admin
  bundle to a few KB.
- Playground's auto-login did not fire on GitHub runners; the e2e suite logs in
  by hand when it lands on `wp-login.php`.
