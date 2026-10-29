# Release checklist

Everything for the first release is ready on `main`. Nothing is tagged,
released or public. These decisions are Joy's; nothing below happens until
Joy says so.

## Joy decides

1. **Make `joychetry/monks-central-wp` public?**
   - Needed for the GitHub route. The plugin's updater and the app's
     "Install Monks Central for me" read `releases/latest` without a token,
     and GitHub answers 404 for a private repo. While private, sites see no
     updates and the app says there is no release yet.
   - A scan of the whole history on 2026-10-29 found no zip (so not the
     licensed Bricks zip), keys or tokens. The only token-like strings are the
     fake ones the redaction tests use. Going public also publishes `docs/`
     and `tests/`.
2. **Version number.** Decided: **0.1.0** (Joy, 2026-10-29), in the plugin
   header, the `MONKS_CENTRAL_VERSION` constant, readme.txt `Stable tag` and
   changelog, `package.json` and its lock, the PHP test bootstraps and the
   `CHANGELOG.md` heading. The 0.1.0 development build on mc-c has the same
   number, so its updater does not offer this release; install the zip there
   by hand. For another number, change those places together; the release
   workflow refuses a tag that does not match the header and the Stable tag.
3. **Release date.** `CHANGELOG.md` says `0.1.0 - 2026-10-29`. Change it if
   the tag goes out on another day.
4. **GitHub Actions.** The release workflow needs Actions, which were blocked
   by billing during the scaffold step. If they still are, build locally and
   make the draft by hand (below).
5. **WordPress.org, later.** When Classic Monks Free's review allows it:
   - Submit the zip from `bin/build-zip.sh --wporg` (no updater; wp.org
     forbids self-updaters). Plugin Check on it: 0 errors, 1 warning
     (`auto_update_plugin` in the connector's set-plugin-auto-update ability,
     which turns WordPress's own auto-updates on or off).
   - readme.txt for that build drops the GitHub part of "External services"
     and the GitHub download step under Installation.
   - `Contributors: goodmonks` must be the wp.org account that submits it,
     and the slug `monks-central` must be free.
   - Sites that installed the GitHub build keep its updater; decide whether a
     later GitHub release should hand them over to wp.org updates.

Related branches that are not merged: the app's `feat/plugin-connector`
(Install Monks Central for me, plugin detection) and Classic Monks' and Classic
Monks Free's `feat/monks-central` (connector 1.1.0). The plugin works on its
own without them; merging them is a separate decision.

## Releasing, once Joy says yes

1. Run the checks: `composer lint`, `composer analyse`, `composer test`,
   `npm run lint:js`, `npm run lint:css`, `npm run build`, `npm run test:e2e`,
   `bash tests/mcp/ci.sh`, then `bin/build-zip.sh` and
   `bash tests/release/fresh-site.sh <monks-central app checkout>`.
2. Check the release date in `CHANGELOG.md`; if it changes, commit and push.
3. Tag and push: `git tag v0.1.0 && git push origin v0.1.0`.
4. `.github/workflows/release.yml` checks the tag against the version, runs
   Plugin Check on the wp.org build, builds `monks-central.zip` and
   `monks-central.zip.sha256`, and creates a **draft** release with both and
   the CHANGELOG section as notes.
5. Download both assets from the draft and run `sha256sum -c
   monks-central.zip.sha256`. Install the zip on a test site.
6. Publish the draft as a normal release (not a pre-release). Only then do
   sites and the app see it: the updater and the app ignore drafts and
   pre-releases.

Without Actions, steps 3 and 4 by hand: `bin/build-zip.sh`, then
`gh release create v0.1.0 dist/monks-central.zip dist/monks-central.zip.sha256
--draft --verify-tag --title "Monks Central 0.1.0" --notes-file <the 0.1.0
section of CHANGELOG.md>`.
