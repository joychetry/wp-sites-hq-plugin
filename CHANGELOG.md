# Changelog

All notable changes to the WP Sites HQ plugin. Versions follow semver. The
connector library in `connector/` has its own version, named in each entry.

## Unreleased

- Connector 3.0.0 (breaking for host plugins): the loader and host API use the
  `wpshq_` / `WPSHQ_` names too: `wpshq_connector_register()` (and
  `_copies`, `_pick`, `_boot`, `_other_hosts`, `_uninstall`), `WPSHQ_Connector`,
  `WPSHQ_Connector_UI`, `WPSHQ_CONNECTOR_VERSION`, `WPSHQ_CONNECTOR_DIR`, and
  the hooks `wpshq_register_abilities`, `wpshq_load_abilities`,
  `wpshq_toolsets`, `wpshq_default_toolsets`, `wpshq_scope_descriptions`,
  `wpshq_instructions`, `wpshq_tool_enabled`, `wpshq_error_hints`,
  `wpshq_hq_status`, `wpshq_hq_modules` and `wpshq_activity_caller`. No old
  name is kept. Every plugin that bundles the connector on a site must bundle
  3.0.0: an older copy has a loader of its own and the two copies clash.
  `docs/connector-prefix-map.json` lists every old and new name, and
  `bin/connector-prefix.php apply` renames host code.
- Connector 3.0.0: removed what kept older Classic Monks builds working: the
  stand-down for builds that carry their own connector (and the `legacy` field
  of `WPSHQ_Connector::state()`), the calls to Pro's media and SVG functions
  by name (hook `wpshq_media_replaced` and `wpshq_svg_sanitizer`), and the
  one-time rename of the app's old grant names.
- The plugin's own names are WP Sites HQ throughout: namespace `WPSitesHQ`,
  constants `WPSHQ_VERSION`, `WPSHQ_FILE`, `WPSHQ_DIR` and `WPSHQ_URL`, the
  option `wpshq_settings`, the update cache `wpshq_release`, the REST
  namespace `wp-sites-hq/v1`, the settings page `options-general.php?page=wp-sites-hq`,
  the ability category `wp-sites-hq`, script, style and CSS names
  `wp-sites-hq-*` and the `window.wpshqAdmin` object. Settings saved under the
  old option are not carried over.
- Releases carry only `wp-sites-hq.zip` and its SHA-256: the 0.1.4 bridge zip
  is no longer attached.
- Connector 2.0.0: every connector function, class, constant and hook now uses
  the `wpshq_` / `WPSHQ_` prefix (was `cm_` / `CM_`), so it no longer clashes
  with other plugins' names. Options, tables, user meta, REST routes, ability
  names and the cron hook keep their names, so connections stay. Breaking for
  host plugins: the hooks `cm_ai_log_reads`, `cm_ai_operation_logged`,
  `cm_mcp_skills` and `cm_theme_sandbox_check_url` are now `wpshq_*`, and code
  that calls `CM_MCP_Server`, `CM_AI_Activity_Log` and the other classes must
  use the new names. See `docs/CONNECTOR.md` and `docs/connector-prefix-map.json`.
- Connector 2.0.0: the connector calls the host through two hooks,
  `wpshq_media_replaced` (after media is replaced or restored) and
  `wpshq_svg_sanitizer`, instead of Classic Monks function and class names.
- Connector 2.0.0: the login link page prints its auto-submit script with
  `wp_print_inline_script_tag()`, so Content Security Policy plugins can add
  their nonce.

## 0.2.1 - 2026-11-02

- Connector 1.2.1: dates from the read tools (posts, pages, users, comments,
  media) now use the standard RFC 3339 format, so strict AI clients no longer
  reject them.

## 0.2.0 - 2026-10-31

Renamed WP Sites HQ; one click moves the plugin folder.

- The plugin is WP Sites HQ, in folder `wp-sites-hq` with main file
  `wp-sites-hq.php` and text domain `wp-sites-hq`. Settings, connections and
  every stored name stay as they were.
- Sites on Monks Central 0.1.x: the update installs the 0.1.4 bridge (it reads
  0.1.4, although the Updates screen says 0.2.0). Its "Move this site" button
  installs WP Sites HQ, activates it and deactivates Monks Central (old). Then
  delete Monks Central (old). To move by hand, activate WP Sites HQ before
  deleting Monks Central (old), or the site's connections are removed.
- Connector 1.2.0: every visible string says WP Sites HQ. Grants named
  "Monks Central" and "Monks Central (AI agents)" are renamed once to
  "WP Sites HQ" and "WP Sites HQ (AI agents)". Activity rows keep their names.
- With both folders active, deactivating either one keeps connections.
- Updates come from `joychetry/wp-sites-hq-plugin`, asset `wp-sites-hq.zip`.
  Each release also carries the bridge as `monks-central.zip`.

## 0.1.3 - 2026-10-29

- No PHP warnings when WordPress loads the plugin twice, for example right
  after an update.

## 0.1.2 - 2026-10-29

- Updates: Check again now looks past the cached answer and asks GitHub again.
- Updates: the version that is already installed is no longer offered as an
  update.
- Updates: release notes no longer break off in the middle of a sentence.
- Consent page: the buttons are styled, the 'name not verified' label is
  readable, and the card intros have room around them.

## 0.1.1 - 2026-10-29

- Connector 1.1.1. MCP credentials can no longer post unfiltered HTML, even for
  administrators, so content an agent writes cannot carry script that approves
  its own requests.
- Connector 1.1.1. Revoking the last connection of an agent account the host
  plugin created deletes that account and gives its content to the
  administrator revoking it. Connection rows say so beforehand (`agent_user`).
- Tests: the pending-approval count no longer fails when a request left over
  from an earlier suite expires during the test.

## 0.1.0 - 2026-10-29

First release: the site connector for the app.

- Connector 1.1.0, shared with Classic Monks and Classic Monks Pro. Each plugin
  registers its bundled copy and the highest version runs, so any mix of the
  three gives one connector. It keeps the existing tables and option names, so
  connections survive adding, removing or switching plugins.
- OAuth 2.1 sign-in with PKCE and a consent page per app: tool groups, access
  level, and an administrator's "this is my app" (operator) flag.
- MCP endpoint for the app and for AI agents, with their own connection.
- Approvals: changes that delete or replace something wait in wp-admin unless
  the app is the operator's.
- Activity log with Undo, login links, and tools for plugins, themes, core and
  translation updates, posts and pages, media (upload, import, replace and
  restore), ACF fields, terms, users, comments, revisions, checksums, error log
  and site snapshots.
- The settings screen: allow the app to connect, connected apps with their
  permissions, Waiting for your approval, and Activity. Built with React and
  DataViews, loaded on that screen only.
- Filters and abilities for a host plugin's own UI: tool groups, hints, status
  and permissions.
- Updates from the latest GitHub release, installed only when the zip's SHA-256
  matches the published checksum. `bin/build-zip.sh --wporg` leaves the updater
  out for a future WordPress.org build.
- Uninstall removes the plugin's settings, and the connector's data only when no
  other plugin still needs it. Deactivating the last copy revokes every
  connection.
- Requires WordPress 7.1 and PHP 8.1. Zero runtime dependencies.
- Built on [wp-plugin-starter](https://github.com/joychetry/wp-plugin-starter)
  0.1.0.
