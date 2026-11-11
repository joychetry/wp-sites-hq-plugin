# Release checklist

Releases are made by the maintainer. Nothing below happens without their go-ahead.

## Every release

Each release carries two assets: `wp-sites-hq.zip` (folder `wp-sites-hq`) and
its `.sha256`, which WP Sites HQ's own updater, the app and the website
download. Releases stay non-draft and non-pre-release once published, so
`releases/latest` points at them.

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
   Plugin Check on the wp.org build, builds the zip with its SHA-256 and
   creates a **draft** release with the two files and the CHANGELOG section
   as notes.
5. Download the assets from the draft and run `sha256sum -c` on the
   `.sha256` file.
6. Upgrade test: on a site with the previous release and an app grant,
   Dashboard > Updates offers the new version and the grant still works after
   the update. Repeat on a network-activated multisite. The draft is not visible to
   sites, so point a test site at it: a mu-plugin that answers
   `api.github.com/repos/joychetry/*/releases/latest` from a local copy, as
   `tests/release/fresh-site.sh` does.
7. Publish the draft as a normal release (not a pre-release). Only then do
   sites and the app see it.

Rollback: turn the release back into a draft, so `releases/latest` is the
previous release again.

## WordPress.org, later (D11)

- Submit the zip from `bin/build-zip.sh --wporg` (no updater; wp.org forbids
  self-updaters).
- The slug `wp-sites-hq` starts with "wp": Plugin Check warns, and the review
  team may ask why. Point the reviewer at wpsiteshq.com.
- readme.txt for that build drops the GitHub part of "External services" and
  the GitHub download step under Installation.
