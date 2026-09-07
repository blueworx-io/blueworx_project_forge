# UI kit — BlueWorx plugin admin

A click-through recreation of what a BlueWorx WordPress plugin looks like inside wp-admin.
The sample plugin is a generic "Example Plugin"; all names, records and figures are invented.

**Open `index.html`.** Use the submenu under the Example Plugin item in the WordPress menu to move
between screens.

| File | What it is |
|---|---|
| `PluginAdminApp.jsx` | Screen routing; the only stateful shell. |
| `AdminChrome.jsx` | WordPress admin bar + left menu with the plugin's submenu expanded. |
| `DashboardScreen.jsx` | Overview: notice, stat tiles, recent orders table, setup checklist. |
| `MembersScreen.jsx` | Records list: tabs, search, bulk selection, row actions, detail modal, empty state. |
| `SettingsScreen.jsx` | Section nav + panels + sticky save bar; five sections of real controls. |
| `ToolsScreen.jsx` | Licence, import with progress, export, danger zone with typed confirmation. |
| `wp-chrome.css` | WordPress host chrome only. A real plugin never ships this — WordPress draws it. |

## What it demonstrates

- Every screen is `.bw-admin.bw-page` inside `#wpcontent`, edge-to-edge, with WordPress's own
  `.wrap` padding dropped — the pattern the Example Plugin plugin already uses in production.
- One `PageHeader` per screen, panels in `.bw-panels`, and the save bar pinned by `margin-top:auto`.
- Plugin screens use the BlueWorx indigo accent; the surrounding WordPress chrome keeps its own
  colours. The two never blend.

## Interactions that work

Submenu navigation · tab filtering · member search · bulk select · member detail modal ·
settings section nav · dirty-state save bar (Save disabled until something changes) ·
CSV import progress · delete confirmation modal.
