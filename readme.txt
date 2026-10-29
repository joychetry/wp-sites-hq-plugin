=== Monks Central ===
Contributors: goodmonks
Tags: site management, remote management, updates, mcp, oauth
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this site to the Monks Central app, so you can manage it with your other WordPress sites from one place.

== Description ==

Monks Central is a free desktop app for managing many WordPress sites from one place. This plugin is the site connector: install it, add the site in the app, and approve the connection in wp-admin. The app can then check and run updates, manage plugins and themes, posts, pages, media, users and comments, and show you what changed.

* Each app signs in with OAuth 2.1 (PKCE) as a WordPress user who approved it on a consent screen, and can only do what that user can do.
* You choose which tool groups each app gets. Changes that delete or replace something wait for your approval in wp-admin, unless an administrator marked the app as their own.
* Every change is logged on the Monks Central screen. Many changes have an Undo there, and you can revoke any app.
* AI agents can use the same tools through the site's MCP endpoint, with their own connection and the same approvals.
* Classic Monks and Classic Monks Pro include the same connector. With any mix of them installed, only one connector runs and existing connections keep working.

== Installation ==

1. Download `monks-central.zip` from the latest release at https://github.com/joychetry/monks-central-wp/releases.
2. In wp-admin, go to Plugins > Add New Plugin > Upload Plugin, choose the zip and activate it.
3. Open Settings > Monks Central and turn on Allow Monks Central to connect.
4. In the Monks Central app, add the site and approve the connection when wp-admin asks.

== Frequently Asked Questions ==

= Does it need the Monks Central app? =

Yes. The plugin has no features of its own beyond the connection: it lets the app (and AI agents you allow) manage the site.

= Which WordPress and PHP versions does it need? =

WordPress 7.1 or newer and PHP 8.1 or newer. The connector relies on the Abilities API that WordPress 7.1 brings.

= Can I use it with Classic Monks or Classic Monks Pro? =

Yes. All three bundle the same connector. Whichever copy is newest runs and the others stay asleep, so the site never runs two connectors, and connections survive adding, removing or switching plugins.

= What can a connected app do? =

Only what the WordPress user who approved it can do, and only in the tool groups you ticked on the consent screen. Deleting or replacing something waits for your approval unless an administrator marked the app as their own.

= How do I disconnect an app? =

Open Settings > Monks Central and revoke it under Connected apps. Turning off Allow Monks Central to connect stops every connection at once.

= What happens when I deactivate or delete the plugin? =

Deactivating it revokes every connection, unless Classic Monks or Classic Monks Pro is still active and keeps the connector running. Deleting it removes its settings, and removes the connector's data only when no other plugin still needs it.

= How does it update? =

From the GitHub releases of this plugin, like any other plugin update in wp-admin. The update is installed only when the zip's SHA-256 matches the checksum published with the release.

== External services ==

This plugin checks GitHub for updates. It sends nothing else anywhere on its own.

* What: a request to the GitHub API (api.github.com) for the latest release of joychetry/monks-central-wp, and, when you update, the download of the release zip and its SHA-256 checksum from github.com.
* When: during WordPress's regular plugin update checks, at most once every 12 hours, and when you install an update.
* What is sent: a standard HTTP request. WordPress adds your site's address to the User-Agent header of every request it makes. No other site data is sent.
* GitHub's terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* GitHub's privacy statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

Other requests happen only when a connected app or you ask for them, and only to the address that task needs: verifying a plugin's files fetches its checksums from downloads.wordpress.org, importing an image fetches the image link the app gives, an app signing in has its client details read from its own address, and health checks load this site's own pages. Plugin, theme and core installs and updates go through WordPress itself.

== Changelog ==

= 0.1.3 - 2026-10-29 =
* No PHP warnings when WordPress loads the plugin twice, for example right after an update.

= 0.1.2 - 2026-10-29 =
* Updates: Check again now looks past the cached answer and asks GitHub again.
* Updates: the version that is already installed is no longer offered as an update.
* Updates: release notes no longer break off in the middle of a sentence.
* Consent page: the buttons are styled, the 'name not verified' label is readable, and the card intros have room around them.

= 0.1.1 =
* Connector 1.1.1: MCP credentials can no longer post unfiltered HTML.
* Connector 1.1.1: an agent account is removed when its last connection is revoked, and its content goes to the administrator who revoked it.

= 0.1.0 =
* First release: the Monks Central site connector (connector 1.1.0) with OAuth 2.1 sign-in, the MCP endpoint, approvals, the activity log with Undo, login links, and tools for plugins, themes, updates, content, media, custom fields, users, comments and site snapshots.
* Settings > Monks Central: connected apps and their permissions, changes waiting for approval, and activity.
* Its own consent page for apps signing in.
* Updates from GitHub releases, installed only when the SHA-256 checksum matches.
* Runs alongside Classic Monks and Classic Monks Pro: one connector runs, connections are kept.
