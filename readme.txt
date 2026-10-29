=== Monks Central ===
Contributors: goodmonks
Tags: site management, remote management, updates, mcp, oauth
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this site to the Monks Central app, so you can manage it with your other WordPress sites from one place.

== Description ==

Monks Central is a free desktop app for managing many WordPress sites from one place. This plugin is the site connector: install it, add the site in the app, and approve the connection in wp-admin. The app can then check and run updates, manage plugins and themes, posts, pages, media, users and comments, and show you what changed.

* Each app signs in with OAuth 2.1 (PKCE) as a WordPress user who approved it on a consent screen, and can only do what that user can do.
* Changes that delete or replace something wait for your approval in wp-admin, unless an administrator marked the app as their own.
* Every change is logged on the Monks Central screen, where you can revoke any app.
* Classic Monks and Classic Monks Pro include the same connector. With any mix of them installed, only one connector runs and existing connections keep working.

== Installation ==

1. Upload the plugin zip under Plugins > Add New > Upload Plugin.
2. Activate it.
3. Open Settings > Monks Central.

== Changelog ==

= 0.1.0 =
* First release.
