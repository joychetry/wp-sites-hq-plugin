# Writing a module

A module is one feature that can be switched on and off. The registry in
`src/Plugin.php` asks each module `is_enabled()` on `plugins_loaded` and calls
`register()` only when it returns true. A disabled module adds no hooks, and
because every other class is loaded by hooks, none of its code is loaded
either.

`src/Modules/Example/` is the reference. It adds a "Show related posts" button
under single posts:

| File | Job |
| --- | --- |
| `Module.php` | Implements `Contracts\Module`: id, label, description, `is_enabled()`, `register()` (hooks only). |
| `RelatedPosts.php` | The one query, shared by the REST route and the ability, plus the shared permission rule. |
| `Rest.php` | `GET /wps/v1/related/<id>`. |
| `Ability.php` | `wp-plugin-starter/related-posts` for the Abilities API and MCP. |
| `Frontend.php` | Server-rendered Interactivity API markup, enqueued only where it renders. |
| `assets-src/frontend/example.js` + `.css` | The Interactivity store and scoped CSS, built to `build/example.*`. |

## Step by step

Say the new module is "Reading time".

### 1. The module class

Copy `src/Modules/Example/Module.php` to `src/Modules/ReadingTime/Module.php`:

```php
namespace WpPluginStarter\Modules\ReadingTime;

use WpPluginStarter\Contracts\Module as ModuleContract;
use WpPluginStarter\Settings;

final class Module implements ModuleContract {

	public function id(): string {
		return 'reading_time'; // Stored in settings. Never change it after release.
	}

	public function label(): string {
		return __( 'Reading time', 'wp-plugin-starter' );
	}

	public function description(): string {
		return __( 'Shows an estimated reading time above posts.', 'wp-plugin-starter' );
	}

	public function is_enabled( Settings $settings ): bool {
		return $settings->module_enabled( $this->id() );
	}

	public function register(): void {
		add_filter( 'the_content', array( Frontend::class, 'render' ) );
	}
}
```

Rules for `register()`:

- Hooks only. No queries, no option reads beyond `Settings`, no output.
- Pass class-string callables (`array( Frontend::class, 'render' )`) so the
  class is autoloaded only when the hook fires.
- `is_enabled()` must stay cheap. It can also check context, for example
  return false when a required plugin is missing.

### 2. Register it

Add the class to `Plugin::MODULES` in `src/Plugin.php`:

```php
public const MODULES = array(
	Modules\Example\Module::class,
	Modules\ReadingTime\Module::class,
);
```

It now shows up in the Modules table under Settings > WP Plugin Starter, off by
default. To ship it on, add its id to the `modules` default in
`Settings::fields()`:

```php
'default' => array( 'example' => true, 'reading_time' => true ),
```

### 3. Settings (if any)

Add fields to `Settings::fields()` as JSON Schema. Prefix the key with the
module id:

```php
'reading_time_wpm' => array(
	'type'        => 'integer',
	'description' => 'Words per minute.',
	'default'     => 200,
	'minimum'     => 100,
	'maximum'     => 400,
),
```

That one entry gives you the default, sanitizing and clamping on save, and the
REST schema. Read it with `( new Settings() )->get_int( 'reading_time_wpm' )`
(the option is read once per request and cached). Add the matching DataForm
field in `assets-src/admin/settings.js`:

```js
{ id: 'reading_time_wpm', label: __( 'Words per minute', 'wp-plugin-starter' ), type: 'integer', isValid: { min: 100, max: 400 } },
```

and its id to `form.fields`.

### 4. A REST route (if the browser needs data)

Follow `Example/Rest.php`:

- Register on `rest_api_init` from `register()`.
- Namespace `wps/v1`, typed `args`.
- A real `permission_callback`. Public data: check the object is public (see
  `RelatedPosts::is_public()`). Private data: `current_user_can()` with the
  narrowest capability.
- Return plain arrays; let core encode them.

Logged-in requests from wp-admin go through `apiFetch`, which sends the
`wp_rest` nonce. Public front-end requests use plain `fetch()` and need no
nonce.

### 5. An ability (if AI clients should use it)

Follow `Example/Ability.php`:

- Register on `wp_abilities_api_init`, inside
  `if ( function_exists( 'wp_register_ability' ) )`.
- Name `wp-plugin-starter/<kebab-name>`, category `Plugin::ABILITY_CATEGORY`.
- `input_schema` and `output_schema` (core validates input before
  `execute_callback` runs).
- `permission_callback` with the same rule as the REST route. Share the logic
  in one class, like `RelatedPosts`.
- `meta.annotations.readonly` for abilities that change nothing,
  `meta.show_in_rest` to list it under `/wp-abilities/v1/abilities`.

### 6. Front-end interactivity (if any)

Follow `Example/Frontend.php` and `assets-src/frontend/example.js`:

1. Create `assets-src/frontend/reading-time.js` with
   `store( 'wps/reading-time', { ... } )`, and import its CSS from it.
2. Add an entry to the `modules` config in `webpack.config.js`:
   `'reading-time': './assets-src/frontend/reading-time.js'`.
3. In PHP, decide once whether this request shows the feature (an `applies()`
   method). Use it both in `wp_enqueue_scripts` (enqueue the module with
   `wp_enqueue_script_module()` and the CSS with `wp_enqueue_style()` plus
   `path` data so core inlines it) and in the render callback.
4. Render the markup in PHP with `data-wp-interactive`, `data-wp-context`
   (`wp_interactivity_data_wp_context()`) and directives, then run it through
   `wp_interactivity_process_directives()` so the first paint already matches
   the initial state.
5. Pass URLs and translated strings with `wp_interactivity_state()`, not
   hard-coded in JS.
6. Scope CSS under `.wps-<name>` and expose tweakable values as custom
   properties.

A tiny behaviour with no state (a toggle, a copy button) can be a plain script
module without the Interactivity API.

### 7. Admin UI (if the module needs its own screen)

Most modules only need settings fields. For a full screen, copy
`src/Admin/Page.php`: add the menu page, enqueue only when `$hook_suffix`
matches, mount a React app built from a new entry in the `scripts` config of
`webpack.config.js`. Lists use DataViews, forms use DataForm, everything saves
through your REST routes.

### 8. Tests

- **Unit** (`tests/php/`): logic that does not need a database, with Brain
  Monkey stubbing WordPress functions. Extend `Tests\TestCase`.
- **e2e** (`tests/e2e/plugin.spec.js`): one test that loads the page where the
  feature renders, uses it, and checks for PHP errors (the `visit()` helper
  does that). Add sample content in `blueprint.json` if the feature needs it.

### 9. Clean up on uninstall

Anything the module stores outside `wps_settings` (options, transients,
tables, cron events) gets deleted in `uninstall.php`.

## Checklist

- [ ] `Module.php` with a stable id, added to `Plugin::MODULES`
- [ ] `register()` only adds hooks with class-string callables
- [ ] Every REST route and ability has a real permission check
- [ ] Assets load only where the feature renders
- [ ] Output escaped (`esc_html`, `esc_attr`, `esc_url`), input sanitized
- [ ] Settings fields in `Settings::fields()` and `settings.js`
- [ ] Unit test for any logic, e2e test for the visible feature
- [ ] `uninstall.php` cleans up
- [ ] `composer lint`, `composer analyse`, `composer test`, `npm run lint:js`,
      `npm run build`, `npm run test:e2e` pass
