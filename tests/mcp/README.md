# WP Sites HQ connector test suites

Bash suites that drive the MCP endpoint and the OAuth server over HTTP (curl) and check state with WP-CLI. Ported from Classic Monks Free (`tests/mcp/`), which ported them from Pro. They run against this plugin, which bundles the shared connector (`connector/`). Nothing secret is committed; `bin/build-zip.sh` leaves this folder out of the zip.

Each suite sources `lib.sh`, which mints a throwaway MCP connection for user 1 (an Application Password tagged with Pro's MCP `app_id`, which the connector still accepts so a site moved from Pro keeps it) plus a plain Application Password, and revokes both on exit. Suites that need a real OAuth grant use `oauth-lib.sh` (consent form included) and sign in as admin/admin (`CM_ADMIN_USER` / `CM_ADMIN_PASS`).

## Run

`ci.sh` downloads WordPress (`CM_WP_VERSION`, default 7.1.3), installs the SQLite drop-in, copies this plugin in (run `composer install` and `npm run build` first), turns the connector on, serves the site with PHP's built-in server on `127.0.0.1:${CM_PORT:-8889}` and runs the suites. Needs PHP with pdo_sqlite, WP-CLI, curl, unzip, jq, openssl, python3 and rsync.

```bash
bash tests/mcp/ci.sh                 # all suites on a fresh temp site
bash tests/mcp/ci.sh smoke oauth     # selected suites
CM_WOO=1 CM_ELEMENTOR=1 CM_BRICKS_ZIP=/path/to/bricks.zip bash tests/mcp/ci.sh   # with WooCommerce, Elementor and Bricks
CM_WP_VERSION=7.0.7 bash tests/mcp/ci.sh   # below 7.1: runs legacy.sh
```

`CM_WOO=1` installs WooCommerce, `CM_ELEMENTOR=1` Elementor, and `CM_BRICKS_ZIP` installs and activates the Bricks theme from a local zip (Bricks is licensed: keep the zip outside the repo). `content.sh` then also creates, edits and reads their post types (`product`, `elementor_library`, `bricks_template`) and WooCommerce product categories.

Bricks is a paid theme, so without `CM_BRICKS_ZIP` `content.sh` prints `SKIP bricks_template`. Run the Bricks checks locally.

Against another site, set `CM_SITE` (URL), `CM_SITE_ROOT` (WordPress root) and `CM_WP` (WP-CLI command, e.g. `studio wp`), and run a suite with `bash tests/mcp/<suite>.sh`.

## Suites

| Suite | Covers |
|---|---|
| `annotations.sh` | Every `classic-monks/*` ability's readonly/destructive/idempotent annotations against `annotations.snapshot` (same values as Pro). `UPDATE=1` rewrites the snapshot. |
| `smoke.sh` | Endpoint basics, credential confinement (REST, XML-RPC), `X-CM-Authorization` fallback. |
| `protocol.sh` | MCP 2026-07-28 and legacy versions, URL elicitation for approvals, JSON-RPC batches. |
| `approvals.sh`, `pending-approvals.sh` | Approval gate, drift, trusted connections, approve-similar, always-ask tools, `list-pending-approvals`. |
| `trust.sh` | Scopes, the activity log and redaction, the error contract. |
| `site.sh`, `updates.sh`, `snapshot.sh` | Tool groups, plugin/theme/core tools, `list-updates`, `update-translations`, `get-site-snapshot`. |
| `wrappers.sh`, `wrappers2.sh`, `content.sh` | Comments, terms, media, posts and any post type, capabilities. |
| `m6.sh` | Monks Central content tools: terms for any taxonomy, custom fields, dates, Elementor and Bricks pages kept, `list-post-types`, revisions, untrash, chunked `upload-media`. Adds a test post type through a mu-plugin and removes it. |
| `m7.sh` | Monks Central user tools: `send-password-reset` (mail caught by a mu-plugin), the roles `get-users` offers and filters by, and `delete-user` handing content to another user. Removes its test users and role. |
| `m10.sh` | Monks Central safe update helpers: `get-http-status` (with the fatal error scrape), `get-error-log-since`, `set-maintenance-mode` and `update-plugin`'s safe mode rolling back an update that fatals. Installs a fixture plugin, offers it updates from zips in uploads through a mu-plugin and removes them all, and any `.maintenance`, at the end. |
| `m8.sh` | Monks Central `install-zip`: a small plugin zip and theme zip sent in chunks (retries, wrong offsets, non-zips, an installed copy without overwrite), replaced and activated, file changes off, abandoned uploads cleaned up. Removes the plugin, the theme, its test user and mu-plugin, and switches the previous theme back on. |
| `media2.sh` | Monks Central media manager: `get-media` details (author, file name, usage, allowed types, upload limit, month and id filters), `upload-media` for any allowed type, `update-media`, `replace-media` (same ID and URL, sizes made again, one backup, type rules) and `restore-replaced-media`. Undo runs each ability's undo callback on the logged data. Images are made with PHP's GD; uploads and backups are deleted at the end. |
| `acf.sh` | Monks Central `get-acf-fields` and `update-acf-fields`: installs ACF free from wordpress.org, then Secure Custom Fields for the PRO types (repeater, gallery, flexible content); reads, saves, ACF validation as plain messages, refusals and undo. The field group comes from `acf-fixture.php`, which also sets up a Studio site (`studio wp eval-file tests/mcp/acf-fixture.php "Monks test fields" <post ID>`). Both plugins, the groups and the posts are deleted at the end. |
| `oauth.sh`, `oauth-grace.sh`, `revoke.sh`, `operator.sh`, `login-link.sh` | Discovery, DCR, CIMD, PKCE, refresh rotation with the 60-second replay grace, revoke, operator grants, one-time login links. |
| `hq-status.sh` | `get-hq-status` (plugin, connector version and hosts), the WordPress 7.1 header and readme. |
| `admin-page.sh` | Settings › WP Sites HQ and its REST routes: the switch, connections, approval requests, activity and undo; editors are refused. |
| `loader.sh` | Version-wins loader: a fixture plugin bundles a newer copy (9.9.9), which runs while the bundled copy sleeps; host versions, the `monks_central_register_abilities` hook, grants kept when either plugin is deactivated. |
| `standdown.sh` | With a Classic Monks build that carries its own connector (simulated), the shared connector stands down. |
| `deactivate.sh`, `uninstall.sh` | Deactivation and uninstall revoke every connection. They run last; use a throwaway site. |
| `legacy.sh` | WordPress below 7.1: the connector stays off and cannot be switched on. |
