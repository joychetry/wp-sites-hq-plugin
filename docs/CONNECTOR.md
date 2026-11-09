# The connector for host plugins

`connector/` is the library that WP Sites HQ, Classic Monks and Classic Monks Pro
each bundle. This page is the contract between the connector and the plugin
that bundles it (the host).

## Names

Since connector 2.0.0 every global symbol the connector declares uses the
`wpshq_` prefix: functions `wpshq_*`, classes `WPSHQ_*` (`WPSHQ_MCP_Server`,
`WPSHQ_MCP_OAuth`, `WPSHQ_MCP_Approvals`, `WPSHQ_AI_Activity_Log`), constants
`WPSHQ_*` and hooks `wpshq_*`. Before 2.0.0 they were `cm_*` and `CM_*`.
`docs/connector-prefix-map.json` lists every old and new name.

The loader and the host API keep their names, which already carry a prefix:
`monks_central_connector_*()`, `Monks_Central_Connector`,
`Monks_Central_Connector_UI`, `MONKS_CENTRAL_CONNECTOR_VERSION` and
`MONKS_CENTRAL_CONNECTOR_DIR`, and the `monks_central_*` hooks.

Stored and wire names did not change, so connections survive the update:
options (`cm_mcp_enabled`, ...), transients, user meta (`cm_mcp_scopes`, ...),
tables, the REST namespace `classic-monks/v1`, ability names
(`classic-monks/*`), OAuth storage, the cron hook `cm_ai_chunk_expire`, nonce
actions, admin page slugs, script and style handles, element ids, `WP_Error`
codes, the ability meta keys `cm_undo_callback`, `cm_preview_callback` and
`cm_always_approve`, and the `CM_MCP_DISABLE_AUTH_FALLBACK` constant a site can
set in `wp-config.php`.

Host code that calls connector classes or functions must use the new names.
Only the copy with the highest version runs, so a host whose own code still
uses `CM_*` names breaks (missing class) as soon as another plugin bundles
connector 2.0.0 or later. Guard calls with `class_exists()` and move to the new
names when you sync.

## Hooks the connector fires for hosts

| Hook | Type | Was | What for |
| --- | --- | --- | --- |
| `wpshq_ai_log_reads` | filter | `cm_ai_log_reads` | Return true to log read-only tool calls too. |
| `wpshq_ai_operation_logged` | action | `cm_ai_operation_logged` | `( $id, $ability, $status )` after an activity row is written. |
| `wpshq_mcp_skills` | filter | `cm_mcp_skills` | Skills served as MCP prompts and resources. |
| `wpshq_theme_sandbox_check_url` | filter | `cm_theme_sandbox_check_url` | URL the theme activation check loads. |
| `wpshq_media_replaced` | action | new in 2.0.0 | `( $attachment_id )` after a file was replaced or restored. |
| `wpshq_svg_sanitizer` | filter | new in 2.0.0 | Callable that cleans SVG markup. |

The connector fires only the new names. A host that still hooks a `cm_*` name
gets no calls.

## Calls into the host

The connector never calls host code by name, with one exception kept for
hosts from before 2.0.0 (below). A host registers callbacks instead:

- **After media is replaced** (`replace-media`, `restore-media`): hook
  `wpshq_media_replaced` to flush page caches and bust cached file URLs.

  ```php
  add_action( 'wpshq_media_replaced', function ( $attachment_id ) {
  	acme_flush_page_caches( $attachment_id );
  } );
  ```

- **SVG uploads and imports**: return a callable from `wpshq_svg_sanitizer`.
  It gets the SVG markup and returns clean markup or a `WP_Error`. Without one,
  SVG files are refused (SVG can carry scripts).

  ```php
  add_filter( 'wpshq_svg_sanitizer', function () {
  	return 'acme_sanitize_svg';
  } );
  ```

Fallbacks for hosts from before 2.0.0 (Classic Monks Pro until it syncs): when
nothing hooks `wpshq_media_replaced`, the connector calls
`cm_flush_external_caches( $id )` and
`CM_Media_Replace::get_instance()->update_recently_replaced( $id )` if they
exist, and the default of `wpshq_svg_sanitizer` is `cm_sanitize_svg` if it
exists. Remove them once every host registers the hooks.

## Renaming again

`bin/connector-prefix.php` did the rename with PHP's tokenizer: it changes
symbols, callback strings, `class_exists()` names and the hook argument of the
hook functions, and leaves stored names alone. To rename a copy or host code
from the old names:

```sh
php bin/connector-prefix.php apply docs/connector-prefix-map.json <path>...
```

It prints every change. Lines marked `REVIEW` need a look by hand.
