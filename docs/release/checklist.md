# Release checklist

Releases are Joy's call. Nothing below happens until Joy says so.

## Every release (0.2.0 and later, until 1.0)

Each release carries four assets:

| Asset | What | Who downloads it |
| --- | --- | --- |
| `wp-sites-hq.zip` + `.sha256` | WP Sites HQ, folder `wp-sites-hq` | WP Sites HQ's own updater, app 0.4.0, the website |
| `monks-central.zip` + `.sha256` | The 0.1.4 bridge, built from tag `v0.1.4-bridge` | Monks Central 0.1.x updaters, app 0.3.x |

Rules that keep old installs working:

1. Never create a repo named `monks-central` or `monks-central-wp` under
   `joychetry`: GitHub's redirects to `wp-sites-hq-plugin` would stop.
2. Releases stay non-draft and non-pre-release once published, so
   `releases/latest` points at them.
3. Keep attaching the bridge as exactly `monks-central.zip` until 1.0.

## Steps

1. Run the checks: `composer lint`, `composer analyse`, `composer test`,
   `npm run lint:js`, `npm run lint:css`, `npm run build`, `npm run test:e2e`,
   `bash tests/mcp/ci.sh`, then `bin/build-zip.sh` and
   `bash tests/release/fresh-site.sh <app checkout>`.
2. Check the version and date in `wp-sites-hq.php` (header and
   `MONKS_CENTRAL_VERSION`), `readme.txt` (Stable tag and changelog),
   `package.json` and its lock, the PHP test bootstraps and `CHANGELOG.md`.
   The release workflow refuses a tag that does not match the header and the
   Stable tag.
3. Tag and push: `git tag v0.2.0 && git push origin v0.2.0`.
4. `.github/workflows/release.yml` checks the tag against the version, runs
   Plugin Check on the wp.org build, builds both zips with their SHA-256 and
   creates a **draft** release with the four files and the CHANGELOG section
   as notes.
5. Download the assets from the draft and run `sha256sum -c` on both
   `.sha256` files.
6. Upgrade test (first release after the rename, and whenever the bridge
   changes), on a site with Monks Central 0.1.3 and an app grant: Dashboard >
   Updates offers the new version, the update installs the bridge (reads
   0.1.4), "Move this site" leaves WP Sites HQ active and Monks Central (old)
   inactive, the grant still works from the app, deleting Monks Central (old)
   keeps the grant and `monks_central_settings`, and the site's Connections
   list shows WP Sites HQ. Repeat on a network-activated multisite and on a
   `DISALLOW_FILE_MODS` site (manual steps). The draft is not visible to
   sites, so point a test site at it: a mu-plugin that answers
   `api.github.com/repos/joychetry/*/releases/latest` from a local copy, as
   `tests/release/fresh-site.sh` does.
7. Publish the draft as a normal release (not a pre-release). Only then do
   sites and the app see it.

Rollback: turn the release back into a draft, so `releases/latest` is the
previous release again. Sites on the bridge then get "WP Sites HQ is not
available yet" from the move button.

## The bridge

The bridge lives on branch `bridge/0.1.x` (from `v0.1.3`) and is tagged
`v0.1.4-bridge`. It has no release workflow of its own. To change it, open a
PR into `bridge/0.1.x`, then move the tag and update `BRIDGE_TAG` in
`release.yml` if the tag name changes.

## WordPress.org, later (D11)

- Submit the zip from `bin/build-zip.sh --wporg` (no updater; wp.org forbids
  self-updaters).
- The slug `wp-sites-hq` starts with "wp": Plugin Check warns, and the review
  team may ask why. Point the reviewer at wpsiteshq.com.
- readme.txt for that build drops the GitHub part of "External services" and
  the GitHub download step under Installation.
