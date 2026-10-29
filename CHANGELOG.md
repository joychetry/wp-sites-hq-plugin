# Changelog

All notable changes to this starter. Versions follow semver.

## 0.1.0 - 2026-10-18

- Plugin bootstrap for WordPress 6.9+ and PHP 8.1+: header, constants, Composer
  PSR-4 autoloader, boot on `plugins_loaded`. Zero runtime dependencies.
- Module registry (`src/Plugin.php`): modules listed in one array; only enabled
  ones register hooks. Shared Abilities API category `wp-plugin-starter`.
- Settings in one non-autoloaded option, `wps_settings`, declared once as JSON
  Schema: defaults, sanitizing, clamping and the REST schema come from it.
- `GET`/`POST /wps/v1/settings`, `manage_options` only.
- Admin screen under Settings: React app with a DataViews modules table
  (enable toggles) and a DataForm settings form, loaded on that screen only.
- Example module, "Show related posts" under single posts: a public REST route
  for publicly viewable posts, the `wp-plugin-starter/related-posts` ability,
  and an Interactivity API store with server-rendered markup. 292 bytes of JS
  and 270 of CSS gzipped, only on single posts; the CSS is inlined by core.
- Quality: PHPCS (WPCS, PHPCompatibilityWP 8.1-), PHPStan level 6 with
  WordPress stubs, PHPUnit with Brain Monkey, Playwright e2e on WordPress
  Playground, ESLint, Prettier and Stylelint via wp-scripts.
- GitHub Actions: PHP 8.1 and 8.3 lint, analyse and test; JS lint and build;
  e2e on Playground; release zip with Plugin Check, uploaded as an artifact.
- `bin/rename.sh` for new plugins, `bin/build-zip.sh` (`npm run plugin-zip`)
  with `.distignore`, Playground `blueprint.json`.
- Docs: `docs/STACK.md`, `docs/REUSE.md`, `docs/MODULES.md`, `AGENTS.md`
  (identical `CLAUDE.md`).
