# Reusing the starter

How to start a plugin from this starter, work on it day to day, and keep it in
sync with the starter.

## Start a new plugin

```sh
git clone https://github.com/joychetry/wp-plugin-starter.git acme-tools
cd acme-tools
git remote rename origin starter        # keep the starter as a remote for updates
bin/rename.sh acme-tools "Acme Tools"   # optional 3rd arg: prefix (default: initials, or the slug without hyphens if initials are under 3 characters)
composer install
npm install
npm run format                          # Prettier rewraps lines whose names changed length
git add -A && git commit -m "Rename starter to Acme Tools"
git remote add origin <your project repo URL>
git push -u origin main
```

`rename.sh` rewrites the slug and text domain (`wp-plugin-starter`), the name
(`WP Plugin Starter`), the namespace (`WpPluginStarter`), the constants
(`WPS_`), the prefix (`wps_`, `wps-`, `wps/` for REST and Interactivity
namespaces) and renames `wp-plugin-starter.php` to `<slug>.php`. It leaves
`docs/` and `CHANGELOG.md` alone because they describe the starter. Lockfiles
only get the package name, and the Composer lock hash is refreshed. Prefixes
must be 3+ characters (WPCS rejects shorter ones).

Then, by hand:

1. **Header.** In `<slug>.php`: Description, Plugin URI, Author. Same in
   `readme.txt` and `composer.json` (`name` is `joychetry/<slug>`).
2. **Example module.** Keep it as a reference until your first module works,
   then delete `src/Modules/Example/`, `assets-src/frontend/example.*`, its
   entries in `Plugin::MODULES`, `Settings::fields()`, `webpack.config.js`,
   `assets-src/admin/settings.js` and `tests/e2e/plugin.spec.js`.
3. **Changelog.** Start your own `CHANGELOG.md` at 0.1.0.

## Local development

Run `composer install` and `npm run build` (or `npm start` to rebuild on save)
first: the plugin needs `vendor/autoload.php` and `build/`.

### WordPress Playground CLI (no local server needed)

From the plugin folder:

```sh
npx @wp-playground/cli@latest server \
  --mount=.:/wordpress/wp-content/plugins/wp-plugin-starter \
  --blueprint=blueprint.json
```

Open http://127.0.0.1:9400. The blueprint uses the latest WordPress and PHP
8.3, turns on `WP_DEBUG`, activates the plugin, logs you in, adds three posts
in one category for the example module, and opens the plugin's settings
screen. Files are mounted live: PHP edits show on reload, JS and CSS after
`npm start` rebuilds. If you get a login screen, the user is `admin` /
`password`. The site is thrown away when the process stops. Add `--port=9500`
if 9400 is taken.

### WordPress Studio

Create a site in [Studio](https://developer.wordpress.com/studio/), then
symlink the plugin into it:

```sh
ln -s "$PWD" ~/Studio/<site>/wp-content/plugins/wp-plugin-starter
```

Activate it in wp-admin. Studio keeps the site between runs, which suits
longer manual testing.

### wp-env (optional)

If you already use Docker: `npx @wordpress/env start` with a `.wp-env.json`
of `{ "plugins": [ "." ] }`. Not included because Playground covers the same
need without Docker.

In any local WordPress, set `WP_DEBUG`, `WP_DEBUG_LOG` and
`SCRIPT_DEBUG` to `true` and install Query Monitor to see queries, hooks and
enqueued assets per page.

## Day-to-day: where things go

| You want to add | Put it in | Notes |
| --- | --- | --- |
| A feature | `src/Modules/<Name>/Module.php` | Implements `Contracts\Module`. Add the class to `Plugin::MODULES`. Add its id to the `modules` default in `Settings::fields()` if it should be on by default. See [MODULES.md](MODULES.md). |
| A REST route | `src/Modules/<Name>/Rest.php` | `register_rest_route()` on `rest_api_init`, namespace `wps/v1`. Always a real `permission_callback` (never `__return_true` for anything private), `args` with types. |
| An ability (AI) | `src/Modules/<Name>/Ability.php` | `wp_register_ability( 'wp-plugin-starter/<name>', ... )` on `wp_abilities_api_init`, category `Plugin::ABILITY_CATEGORY`, input and output schema, same permission rule as the REST route. |
| An admin screen | `src/Admin/` + `assets-src/admin/` | Enqueue only when `$hook_suffix` matches your page. Components from `@wordpress/components`, lists with DataViews, forms with DataForm. Save through REST with `apiFetch`. |
| Front-end interactivity | `assets-src/frontend/<name>.js` (+ `.css`) | Interactivity API store as a script module. Add an entry to the `modules` config in `webpack.config.js`. Enqueue with `wp_enqueue_script_module()` only where the markup renders. Server state via `wp_interactivity_state()`. |
| A settings field | `Settings::fields()` | JSON Schema: `type`, `default`, `minimum`/`maximum` or `maxLength`. Sanitizing, defaults and the REST schema follow from it. Add a DataForm field in `assets-src/admin/settings.js`. |
| A custom table | `src/Modules/<Name>/Schema.php` | `dbDelta()` on activation, a schema version option, drop the table in `uninstall.php`. Only when an option no longer fits (see STACK.md). |
| Data to delete on uninstall | `uninstall.php` | Every option, table, transient and cron hook the plugin creates. |

Prefix everything: namespace `WpPluginStarter`, hooks and options `wps_`,
handles and CSS classes `wps-`, constants `WPS_`, REST namespace `wps/v1`.

## Running checks locally

```sh
composer lint          # PHPCS: WPCS + PHPCompatibilityWP 8.1-
composer analyse       # PHPStan level 6
composer test          # PHPUnit (Brain Monkey, no WordPress needed)
npm run lint:js        # ESLint + Prettier
npm run lint:css       # Stylelint
npm run build
npm run test:e2e       # Playwright on a fresh Playground site, port 9500
grep -rn $'\u2014' --exclude-dir={node_modules,vendor,build,dist,.git} .   # no em dashes
```

`composer lint:fix` and `npm run format` fix most style issues. The first
`npm run test:e2e` may ask you to run `npx playwright install chromium`.

## Pulling improvements from the starter

```sh
git fetch starter
git log --oneline HEAD..starter/main          # what changed upstream
git merge starter/main                        # take everything, or
git cherry-pick <sha>                         # take one fix
```

Because `rename.sh` changed names everywhere, expect conflicts on lines that
contain the slug, prefix, namespace or constants, and in the renamed main file.
Keep your side for the name, take the starter's side for the logic. Running
`bin/rename.sh` again after a merge is safe: it only rewrites leftover starter
identifiers. Lockfile conflicts: take yours, then `composer update --lock` or
`npm install`.

To keep merges cheap:

- Put project code in new modules instead of editing `Plugin.php`,
  `Settings.php` or the admin app shell. The only shared lines you should
  need to touch are `Plugin::MODULES`, `Settings::fields()` and
  `webpack.config.js` entries.
- Do not reformat starter files.

## Release

1. Bump the version in **three places**: `Version:` and `WPS_VERSION` in the
   main plugin file, `Stable tag:` in `readme.txt` (also `version` in
   `package.json` if you track it).
2. Add a `CHANGELOG.md` entry and a `== Changelog ==` line in `readme.txt`.
3. Run every check above.
4. `npm run plugin-zip` builds `dist/wp-plugin-starter.zip`: production JS,
   `vendor/` with only the autoloader, nothing listed in `.distignore`. Check
   with `unzip -l dist/wp-plugin-starter.zip`.
5. Plugin Check: CI runs `wordpress/plugin-check-action` on the zip contents.
   Locally, install the Plugin Check plugin on a test site, upload the zip,
   and run Tools > Plugin Check with every category ticked. (Its WP-CLI
   command does not work inside Playground: PHPCS cannot read stdin there.)
   On the unrenamed starter it warns that "plugin" in the name and slug is a
   restricted term for wordpress.org; pick a name without "plugin" or
   "WordPress" if you publish there.
6. Tag the release in git and attach the zip.

## Proposing an improvement back to the starter

If a fix or feature is not project-specific:

1. Make the change on a branch of the starter (not the derived plugin), using
   the starter's names (`wp-plugin-starter`, `wps_`, `WpPluginStarter`).
2. Keep it generic: no client code or copy.
3. Run the checks above, add a `CHANGELOG.md` line, open a PR on the starter.
4. Once merged, pull it into derived plugins with `git fetch starter` and
   `git cherry-pick`.
