# Local development

```bash
./scripts/dev.sh
```

Starts a wp-now instance running WP 7.0-RC2 + PHP 8.1 with the plugin installed and activated and the admin UI asset watcher. Ctrl-C tears everything down.

## Prerequisites

- `wp-now` — `npm i -g @wp-now/wp-now`
- Node 20+, PHP 8.1+, `zip`

## Iterating

- **Admin UI / plugin source:** bind-mounted, edits are live on refresh.
