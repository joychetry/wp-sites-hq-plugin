# Changelog

All notable changes to the Monks Central plugin. Versions follow semver. The
connector library in `connector/` has its own version, named in each entry.

## 1.0.0 - Unreleased

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
