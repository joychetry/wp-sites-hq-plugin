# The connector for host plugins

`connector/` is the library that WP Sites HQ, Classic Monks and Classic Monks Pro
each bundle. This page is the contract between the connector and the plugin
that bundles it (the host).

## Names

Every global symbol the connector declares uses the `wpshq_` prefix:
functions `wpshq_*`, classes `WPSHQ_*` (`WPSHQ_MCP_Server`, `WPSHQ_MCP_OAuth`,
`WPSHQ_MCP_Approvals`, `WPSHQ_AI_Activity_Log`), constants `WPSHQ_*` and hooks
`wpshq_*`. That includes the loader and the host API: `wpshq_connector_register()`
and the other `wpshq_connector_*()` functions, `WPSHQ_Connector`,
`WPSHQ_Connector_UI`, `WPSHQ_CONNECTOR_VERSION`, `WPSHQ_CONNECTOR_DIR`, and the
hooks hosts add to (`wpshq_register_abilities`, `wpshq_load_abilities`,
`wpshq_toolsets`, `wpshq_hq_status` and the rest). `docs/connector-prefix-map.json` lists the names development builds used and
their current names, for host code that needs renaming. No old name is kept as
an alias.

Stored and wire names keep their `cm_` / `classic-monks` names, which the
connector shares with Classic Monks:
options (`cm_mcp_enabled`, ...), transients, user meta (`cm_mcp_scopes`, ...),
tables, the REST namespace `classic-monks/v1`, ability names
(`classic-monks/*`), OAuth storage, the cron hook `cm_ai_chunk_expire`, nonce
actions, admin page slugs, script and style handles, element ids, `WP_Error`
codes, the ability meta keys `cm_undo_callback`, `cm_preview_callback` and
`cm_always_approve`, and the `CM_MCP_DISABLE_AUTH_FALLBACK` constant a site can
set in `wp-config.php`.

Host code that calls connector classes or functions must use the current names.
Development builds before 1.0.0 used other loader names, so such a copy does
not see 1.x copies and boots too, which fails with a fatal error (the connector
classes are declared twice). Every plugin that bundles the connector on a site
must bundle 1.0.0 or later.

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

The connector never calls host code by name. A host registers callbacks instead:

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

## Renaming again

`bin/connector-prefix.php` did the rename with PHP's tokenizer: it changes
symbols, callback strings, `class_exists()` names and the hook argument of the
hook functions, and leaves stored names alone. To rename a copy or host code
from the old names:

```sh
php bin/connector-prefix.php apply docs/connector-prefix-map.json <path>...
```

It prints every change. Lines marked `REVIEW` need a look by hand.
