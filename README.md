# Handik Booking App

WordPress plugin that owns the entire Handik booking experience: a customer-facing booking SPA + standalone "Additional Forms" + Cal.com sync + a full admin operations dashboard.

> **Looking for plugin features, install steps, or the changelog?** See [`readme.txt`](readme.txt) — that's the WordPress-format release-facing doc.
>
> **Working on the code?** Keep reading.

---

## Quick start (development)

```bash
# 1. Clone into a WordPress site's plugins directory
git clone <repo-url> wp-content/plugins/handik-booking-app

# 2. Install lint tooling (JS/CSS only — no build step for prod)
cd wp-content/plugins/handik-booking-app
npm install

# 3. Activate the plugin via wp-admin → Plugins
#    Migrations run automatically on first admin page load.

# 4. Configure credentials at Handik Booking → Settings:
#    - OpenAI (API key + workflow id) for the AI assistant
#    - Cal.com (API key + webhook secret) for booking sync
#    - Google Maps (API key) for address autocomplete
#    - Twilio Verify (SID + auth) for phone OTP on Additional Forms
#    - Email From-address for customer / owner notifications
```

The plugin ships zero build artifacts — JS and CSS are loaded directly from `assets/`. `npm` is only for ESLint + Prettier.

---

## Repository layout

```
handik-booking-app.php           Plugin bootstrap (version + DB version constants)
README.md                        This file — developer entry point
readme.txt                       WordPress release-facing doc + changelog
ARCHITECTURE.md                  Module map, REST catalog, DB schema, flows
RELEASE_CHECKLIST.md             Release process

assets/
  booking-app.js / .css          Main SPA (assistant flow)
  booking-forms.js / .css        Additional Forms SPA (direct + project)
  booking-app-admin.js / .css    Admin pages (Bookings, People, etc.)
  handik-chatkit-bridge.js       <openai-chatkit> web-component bridge

includes/
  class-plugin.php               Service container — single source of truth for DI
  class-loader.php               Static autoloader (every class-*.php manually listed)
  class-rest-api.php             Main SPA + admin REST endpoints
  class-frontend-app.php         Server-side render of the SPA shell
  class-admin.php                Top-level menu + i18n + asset enqueue for admin

  admin/                         Admin page renderers (Dashboard, Bookings, etc.)
  app/                           Main-SPA app controller, state, schema
  db/                            Migration runner + versioned migration classes
  forms/                         Additional Forms module (REST, services, router)
  services/                      Domain services (CRM, Cal, ChatKit, Notifications)

views/                           Server-side PHP templates
widgets/                         Elementor widget registration
```

The plugin has **no** Composer dependencies. Every PHP class autoloads through `class-loader.php`. Adding a new class? Append it to the `$files` array there.

---

## Booking flows (high level)

Three customer-facing entry points:

* **`[handik_booking_app]`** — main SPA with the OpenAI ChatKit assistant. Customer chats through their job → assistant produces a structured result → routing picks the right Cal event slug → Cal embed for slot selection.
* **`[handik_direct_booking_form preset_slug="...]`** — Additional Forms direct preset. Phone OTP → contact + address → Cal embed → done.
* **`[handik_project_day_form preset_slug="...]`** — Additional Forms project preset. Phone OTP → contact + address → pick N work days → server creates N Cal bookings sequentially.

Every customer-facing booking ends up as a row in the unified `handik_bookings` table, surfaced in the admin Bookings list regardless of origin.

For everything else (REST endpoints, ChatKit / assistant flow, Cal lifecycle, notifications, DB schema, admin areas, migrations) — see [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## Conventions

* **Versioning.** Three places must stay in lockstep: `handik-booking-app.php` (`Version:` header + `HANDIK_BOOKING_APP_VERSION` constant), `package.json` (`version`), and `readme.txt` (`Stable tag`). The [release checklist](RELEASE_CHECKLIST.md) lists the order.
* **Branches.** Feature branches are `claude/<short-feature-name>`. Hotfix branches keep the same convention. PR → review → merge into `main`. `main` is always shippable.
* **Commits.** Conventional one-line subject + multi-paragraph body explaining WHY (not WHAT — the diff already shows that). When a release commit lands, the subject is `X.Y.Z: <one-line description>`.
* **Comments in code.** Header docblock on every class + method explains purpose + non-obvious design decisions. Inline comments explain "why this is here" not "what this does". When a fix references a previous bug, prefix the comment with the version that introduced the fix (e.g. `// 2.1.26.6 P0 fix:`).
* **Migrations.** Each schema change ships a new versioned migration class. Bump `HANDIK_BOOKING_APP_DB_VERSION` in the plugin bootstrap; register the class in `includes/db/class-migrations.php`; add to the loader's `$files` array. Migrations must be idempotent — re-running must not throw on duplicate column / index errors.
* **REST endpoints.** Public routes use the `route()` helper (rate-limited via the same wrapper); admin routes go through `register_rest_route` directly with an `admin_permission` / `admin_delete_permission` permission callback.
* **JS.** No bundler, no transpilation. Plain ES2015+ that runs in modern browsers (the public form supports iOS Safari 14+). Admin JS is jQuery-light (uses `window.fetch` directly).
* **CSS.** No preprocessor. `assets/booking-app.css` is the shared front-end stylesheet (the Forms SPA inherits it).

---

## Common tasks

### Add a new REST endpoint

1. Decide the route shape + permission (`admin_permission` for admin, `__return_true` + nonce for public).
2. Register in `includes/class-rest-api.php::register_routes()` (or `includes/forms/class-forms-rest-api.php` for Additional Forms).
3. Implement the handler method on the same class.
4. Add to the catalog in [`ARCHITECTURE.md`](ARCHITECTURE.md#3-rest-api-catalog) so the next contributor sees it.

### Add a schema column

1. Write a new `class-migration-XYZ.php` in `includes/db/migrations/` (copy a recent one for the idempotent-ALTER pattern).
2. Register it in `includes/db/class-migrations.php`'s `$map`.
3. Add the file to the `$files` array in `includes/class-loader.php`.
4. Bump `HANDIK_BOOKING_APP_DB_VERSION` in `handik-booking-app.php`.
5. Update services that read/write the new column.
6. Document in the migration history table in [`ARCHITECTURE.md`](ARCHITECTURE.md#9-db-schema--migrations).

### Cut a release

See [`RELEASE_CHECKLIST.md`](RELEASE_CHECKLIST.md).

---

## Testing

The plugin doesn't ship automated tests. QA is manual via the admin tools:

* **Logs** (`Handik Booking → Logs`) — server-side errors, Cal webhook attempts, email dispatch results
* **System info** (`Handik Booking → System`) — DB version, last migration run, plugin update status
* **Bookings list** — every booking source lands here; missing rows usually mean a webhook delivery was dropped (use the "Pull from Cal.com" button to backfill)

When debugging a customer-side issue, the SPA's `/client-log` endpoint mirrors browser console errors into the same Logs view.
