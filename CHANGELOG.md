# Changelog

All notable changes to the WP Sites HQ plugin (Monks Central before 0.2.0). Versions follow semver. The
connector library in `connector/` has its own version, named in each entry.

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

First release: the site connector for the Monks Central app.

- Connector 1.1.0, shared with Classic Monks and Classic Monks Pro. Each plugin
  registers its bundled copy and the highest version runs, so any mix of the
  three gives one connector. It keeps the existing tables and option names, so
  connections survive adding, removing or switching plugins.
- OAuth 2.1 sign-in with PKCE and a consent page per app: tool groups, access
  level, and an administrator's "this is my Monks Central app" (operator) flag.
- MCP endpoint for the app and for AI agents, with their own connection.
- Approvals: changes that delete or replace something wait in wp-admin unless
  the app is the operator's.
- Activity log with Undo, login links, and tools for plugins, themes, core and
  translation updates, posts and pages, media (upload, import, replace and
  restore), ACF fields, terms, users, comments, revisions, checksums, error log
  and site snapshots.
- Settings > Monks Central: Allow Monks Central to connect, connected apps with their
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
