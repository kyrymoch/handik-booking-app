# Handik Booking App — Architecture

Detailed module map for contributors. Pair with [README.md](README.md) (developer entry point) and [readme.txt](readme.txt) (WordPress release notes).

---

## 1. Top-level layers

```
┌──────────────────────────────────────────────────────────────────┐
│ Frontend (customer-facing)                                       │
│ ───────────────────────────────────────────────────────────────  │
│  Main SPA            Additional Forms          Embeddings        │
│  [handik_booking_    [handik_direct_booking_   shortcode +       │
│   app] shortcode      form] +                  Elementor widget  │
│  /  Elementor         [handik_project_day_     for either flow   │
│   widget              form]                                      │
│                                                                  │
│  AI-assistant         Phone-OTP + direct                         │
│  flow with ChatKit    Cal embed (single                          │
│  + Cal embed          slot) OR multi-day                         │
│                       project schedule                           │
└──────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ Backend services (PHP, WordPress plugin)                         │
│ ───────────────────────────────────────────────────────────────  │
│  REST API  ◄──────► Booking lifecycle  ◄──────► Cal.com API v2   │
│            ▲                                                     │
│            │                                                     │
│            ▼                                                     │
│  CRM           Notifications        Background                   │
│  (contacts +   (customer +          (wp_cron for                 │
│  addresses +   owner emails,        async email,                 │
│  requests +    .ics calendar        cleanup of                   │
│  bookings)     invites)             abandoned drafts)            │
└──────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ Admin (WordPress dashboard)                                      │
│ ───────────────────────────────────────────────────────────────  │
│  Top-level "Handik Booking" menu:                                │
│  Dashboard · Bookings · People & Requests · Additional Forms ·   │
│  Settings · Logs · System · Changelog                            │
└──────────────────────────────────────────────────────────────────┘
                                │
                                ▼
                  Cal.com → Apple / Google / Outlook Calendar
                  (via standard .ics invite + cancel + reschedule
                   email mechanism — no direct integration needed)
```

---

## 2. Plugin entry point + DI

The plugin's lifecycle controller is `Handik_Booking_App_Plugin` (`includes/class-plugin.php`). It boots a single instance, constructs every service eagerly, and wires dependencies via constructor injection.

Boot order:
1. Loader (autoload of all `class-*.php`)
2. Settings + Logger
3. CRM services (contacts, addresses, job_requests, bookings, messages)
4. Cascade-delete service (depends on the four CRM services above)
5. Auth + routing
6. Cal Service (legacy) + Photo analysis + ChatKit
7. Cal API service + booking presets + direct + project + forms router + forms REST
8. Webhook service (depends on direct + project)
9. Notifications service
10. App controller + REST API + Frontend + Admin + Asset registrar

Each public property on `$plugin` is a service instance used both by REST handlers and by other services. See [class-plugin.php](includes/class-plugin.php) for the full wire-up.

---

## 3. REST API catalog

Namespace: `handik-booking-app/v1`. Routes live in two files:

* `includes/class-rest-api.php` — main SPA endpoints, admin endpoints, webhook
* `includes/forms/class-forms-rest-api.php` — Additional Forms endpoints

### Public (no auth, nonce-checked for state-changing)

```
GET   /app/bootstrap                  Boot the SPA, hand back state + i18n
POST  /app/draft                      Save a draft (auto on field change)
POST  /app/upload                     Upload a photo attachment

POST  /auth/request-code              Send magic-link / one-time code
POST  /auth/verify                    Verify code, mint session token
POST  /phone-verify/start             Twilio Verify OTP — send
POST  /phone-verify/check             Twilio Verify OTP — verify
POST  /phone-verify/restore           Resume a verified-phone session
POST  /phone-verify/bind-contact      Tie a phone to a contact row
POST  /contacts/lookup                Look up a returning customer by phone

POST  /chatkit-session                Mint an OpenAI ChatKit session token
POST  /chatkit-thread                 Fetch / persist the chat thread
POST  /messages/record                Mirror a chat message to handik_messages
POST  /photo-analysis                 Trigger vision-model analysis of uploads
POST  /request-photo-context          Hand photo analysis back to the assistant
POST  /request-pricing-context        Hand catalog/pricing back to the assistant
POST  /assistant-result               Persist the structured assistant output

POST  /booking-url                    Build the Cal.com booking URL
POST  /booking-status                 Poll local booking status
POST  /booking-capture                Capture a successful Cal embed booking
POST  /booking-complete               Mark a booking complete (post-visit)
POST  /client-log                     Client-side error / debug log

POST  /cal-webhook                    Cal.com webhook receiver
                                      (HMAC-signed, BOOKING_CREATED /
                                       CANCELLED / RESCHEDULED / etc.)
```

### Additional Forms

```
GET   /forms/preset/{slug}            Public preset config (Cal event type slug,
                                      duration, form_title, etc.)

POST  /forms/direct/submit            Direct form — submit contact + address,
                                      receive Cal embed URL + capture token
POST  /forms/direct/{id}/capture      Direct form — capture the Cal booking
                                      after the customer picks a slot

POST  /forms/project/open             Project form — open a scheduling
                                      request (after phone verify)
GET   /forms/project/{id}/slots       Project form — available slots
POST  /forms/project/{id}/select      Project form — save the customer's
                                      N-day selection
POST  /forms/project/{id}/confirm     Project form — create N Cal bookings
                                      sequentially, mirror to handik_bookings
```

### Admin

All gated behind `manage_options` capability check via `admin_permission()`. Destructive routes additionally require `MANAGE_DELETE` capability via `admin_delete_permission()`.

```
PATCH /admin/booking/{id}/notes       Add / edit admin notes on a booking
PATCH /admin/booking/{id}/status      Mark cancelled / completed / etc.
                                      (cancellation auto-propagates to Cal.com)
POST  /admin/booking/{id}/fetch-chat  Backfill chat transcript from OpenAI
                                      ChatKit thread storage
POST  /admin/booking/{id}/reschedule  Reschedule via Cal.com (datetime-local
                                      input, optional reason, mirrors to local)

POST  /admin/contact                  Create a new contact
PATCH /admin/contact/{id}             Update a contact
DEL   /admin/contact/{id}             Hard-delete contact (cascade)
GET   /admin/contact/search           Lookup-as-you-type for "add booking" flow

DEL   /admin/job-request/{id}         Hard-delete a draft / completed request

POST  /admin/bookings/pull-from-cal   Backfill bookings via Cal.com API
                                      (status=upcoming, skips cancelled)
POST  /admin/job-requests/bulk-delete Bulk-delete drafts focus list
POST  /admin/bookings/bulk-delete     Bulk-delete bookings (auto-cancels each
                                      on Cal.com first, then drops locally)
POST  /admin/contacts/bulk-delete     Bulk-delete contacts + cascade

DEL   /admin/booking/{id}             Single-row danger-zone delete
                                      (auto-cancels on Cal.com, then cascades)
POST  /admin/booking/new              Admin-side "add booking" submit

GET   /admin/address/{id}             Lookup address
PATCH /admin/address/{id}             Update address
POST  /admin/address/{id}/primary     Set primary address for contact
POST  /admin/catalog                  Edit service catalog
POST  /admin/transients/clear         Drop cached transients
POST  /admin/migrations/run           Manually run pending migrations
GET   /admin/export/{table}           CSV export
```

---

## 4. Main SPA flow (`[handik_booking_app]`)

Single-page wizard rendered by `Handik_Booking_App_Frontend_App` + `assets/booking-app.js`. Steps (`state.step`):

```
welcome → client_type → returning_verify → task_selection
   → address_details → photos → assistant → success
   (unsafe step diverts off the happy path on safety flags)
```

Key responsibilities per layer:

* `class-app-controller.php` — REST-layer plumbing for `/app/*`
* `class-app-state.php` — server-side state shape, persistence
* `class-app-schema.php` — field-level validation rules
* `class-frontend-app.php` — server-side render of the shell HTML
* `class-shortcode.php` + `class-widget-registry.php` — embedding glue

`assets/handik-chatkit-bridge.js` is loaded only on the `assistant` step; it owns the `<openai-chatkit>` web component lifecycle, mirrors user messages to `/messages/record`, and resolves the structured assistant result to advance the wizard.

### ChatKit / assistant step

1. SPA reaches the `assistant` step → mints a ChatKit session via `/chatkit-session`
2. Bridge boots `<openai-chatkit>` with the returned token
3. As the conversation progresses:
   * `composer.submit` → record user text via `/messages/record`
   * `chatkit.message` → record assistant text + look for the structured result
4. Structured result lands → `/assistant-result` persists service_family / rate_family / duration_bucket / booking_type
5. Routing rules pick the right Cal event slug → SPA advances to the booking step
6. Customer picks a Cal slot in the embed → `/booking-capture` persists the cal_booking_id locally
7. Cal webhook arrives → confirms / cancels / reschedules the booking row

---

## 5. Additional Forms

Module under `includes/forms/`. Two presets ship by default:

### Direct booking form (`[handik_direct_booking_form preset_slug=...]`)

`Handik_Booking_App_Direct_Booking_Service` + `assets/booking-forms.js`.

```
phone → otp → details (contact + address) → cal embed → success
```

Cal embed picks the booking slot; `/forms/direct/{id}/capture` persists `cal_booking_id` + `cal_booking_uid` and mirrors to `handik_bookings` via `Bookings_Service::upsert_from_direct_capture`.

### Project work-days form (`[handik_project_day_form preset_slug=...]`)

`Handik_Booking_App_Project_Schedule_Service`.

```
phone → otp → details → pick-days → review-days → success
```

Multi-day version. Customer picks N work days from available slots; `/forms/project/{id}/confirm` creates N Cal.com bookings sequentially (one per day) via `Cal_Api_Service::create_booking`. Each successful Cal booking is mirrored to `handik_bookings` via `Bookings_Service::upsert_from_project` (one row per work day, keyed by `project_work_day_id`).

If any day fails mid-loop, `rollback_after_failure` cancels the days created so far on Cal and rolls the schedule back to `SELECTED` so the customer can pick a new set.

After `confirm` succeeds, the customer-confirmation email is dispatched asynchronously via `wp_schedule_single_event` → `dispatch_for_project` → wp_mail with a multi-event `.ics` attachment.

---

## 6. Cal.com lifecycle

### Creation

* Main SPA: customer picks a slot in the embed → Cal sends a `BOOKING_CREATED` webhook → `Webhook_Service::dispatch_to_standard` matches by `cal_booking_id` or metadata → `Bookings_Service::upsert_from_cal` writes the row.
* Direct form: same flow, mirrored via `upsert_from_direct_capture` immediately on the leading-edge `bookingSuccessful` event; the trailing webhook is idempotent on the `cal_booking_id` UNIQUE key.
* Project form: server-side `Cal_Api_Service::create_booking` (one POST per day) → leading-edge mirror via `upsert_from_project` → trailing webhook idempotent.
* External: customer abandons our form, books directly on Cal.com → `dispatch_to_standard` falls through to the new "external booking" path (since 2.1.24.0) → `Bookings_Service::upsert_external_booking` writes a row with `external_contact_id` (matched by attendee email/phone) or fully external if no contact match.

### Cancellation (2.1.27.0+)

Single source of truth: the plugin. Operator clicks "Cancelled" or "Delete" on a booking in the admin → `REST_API::cancel_on_cal_for_booking` resolves the Cal UID and POSTs to `/v2/bookings/{uid}/cancel` → Cal sends a `METHOD:CANCEL` `.ics` invite to the attendee → Apple / Google / Outlook Calendar marks the event cancelled.

UID resolution priority (most-authoritative first):
1. `project_work_days.cal_booking_uid`
2. `direct_booking_requests.cal_booking_uid`
3. `raw_webhook_json` parsed for `uid` / `bookingUid`
4. `handik_bookings.cal_booking_id` if non-numeric

Cal failures are logged but never block the local action — the operator's cleanup always succeeds. Bulk delete applies the same flow per row with a shared reason.

### Reschedule (2.1.28.0+)

Same shape: operator picks a new datetime in the admin reschedule modal → `Cal_Api_Service::reschedule_booking($uid, $new_start_iso, $reason)` POSTs to `/v2/bookings/{uid}/reschedule` → Cal sends an updated `.ics` invite → Apple / Google / Outlook Calendar moves the event in place (same event, new times). The new start + end + raw payload are mirrored into the local `handik_bookings` row; any stale `admin_status_override = 'cancelled'` is cleared so the booking shows back up as live.

### Webhook idempotency

Every webhook handler is idempotent on `cal_booking_id` UNIQUE. Retries (Cal sends up to 3 deliveries on non-2xx) collapse into UPDATE rather than INSERT. Signature verification via `Webhook_Service::verify_signature` (HMAC-SHA256 with the `cal_webhook_secret` setting).

---

## 7. Notifications pipeline

`Handik_Booking_App_Notifications_Service` owns customer + owner emails.

```
Booking action               → dispatch_for_* method  → do_action(...)
─────────────────────────────────────────────────────────────────
create (main SPA Cal flow)   → dispatch_for_cal       → handik_booking_confirmed
create (direct form)         → dispatch_for_direct    → handik_booking_confirmed
create (project form)        → dispatch_for_project   → handik_booking_confirmed
                               (async via wp_cron)

cancel (any)                 → dispatch_for_*_cancel  → handik_booking_cancelled
reschedule (any)             → dispatch_for_*_reschedule → handik_booking_rescheduled
```

Action handlers (`handle_booking_*`) are wrapped in `try { ... } catch ( \Throwable $e )` so a fatal in template rendering / `.ics` building / `wp_mail` SMTP can't fatal the booking-flow request. Throwables are logged with file + line + slim context for forensics.

### Idempotency

Each handler claims an idempotency stamp on `confirmation_email_sent_at` (`handik_bookings`, `handik_direct_booking_requests`, `handik_project_scheduling_requests` — all gained the column in migration 1.5.1). Concurrent firings (Cal webhook retry + capture-endpoint leading edge) collapse to a single email. wp_mail failure rolls the stamp back so a manual retry can re-fire.

### `.ics` invite builder

`Handik_Booking_App_Ics_Builder::build_multi` produces an N-event VCALENDAR for project flows (one VEVENT per work day) or a single-event VCALENDAR for everything else. Each VEVENT includes ORGANIZER + ATTENDEE properties so calendar apps recognize the invite as cancellable / reschedulable later.

Temp file path: `wp_tempnam( ..., sys_get_temp_dir() )` with a just-in-time `require_once ABSPATH . 'wp-admin/includes/file.php'` (admin-only function, not auto-loaded in REST contexts — caused a 2.1.26.5 fatal that 2.1.26.6 fixed).

---

## 8. Admin areas

Top-level `Handik Booking` menu (`includes/class-admin.php`). Page renderers live in `includes/admin/`:

| Slug                                 | Renderer                                      | What                                                                  |
|--------------------------------------|-----------------------------------------------|-----------------------------------------------------------------------|
| `handik-booking-app`                 | `Admin_Dashboard`                             | counts + Action-needed chips (Drafts, Ready-not-booked, Unsafe, Errors today) |
| `handik-booking-app-bookings`        | `Admin_Bookings`                              | unified Bookings list (cards + table), detail view, add booking flow, pull-from-Cal, bulk delete |
| `handik-booking-app-crm`             | `Admin_People`                                | People & Requests list, person detail, addresses, requests focus lists, bulk delete |
| `handik-booking-app-forms`           | `Admin_Additional_Forms`                      | Direct Submissions + Project Schedules sub-screens                    |
| `handik-booking-app-app-settings`    | `Admin_Settings`                              | Tabs: app, integrations, notifications, appearance, etc.              |
| `handik-booking-app-operations`      | `Admin_Logs` + `Admin_System`                 | Logs, system info, migration status, transients clear                 |
| `handik-booking-app-integrations`    | `Admin_Integrations`                          | Service catalog editor (booking types + pricing buckets)              |

### Bulk-action pattern

A generic `initBulkMode()` in `booking-app-admin.js` opts any container with `data-handik-bulk-section` into bulk mode. Triggered by `[data-handik-bulk-toggle][data-handik-bulk-target="..."]`. Bookings + Contacts both opt in. Duplicate checkboxes between cards/table views are kept in sync on every change event. Destructive actions require a typed-confirm modal ("DELETE N") and optionally collect a Cal cancellation reason.

---

## 9. DB schema + migrations

DB version stored in `wp_options.handik_booking_app_db_version`. Migrations are versioned classes in `includes/db/migrations/`; the runner (`Handik_Booking_App_Migrations::migrate`) is locked via an atomic `add_option` so parallel boots can't double-run.

### Migration history

| Version | Class                  | Purpose                                                              |
|---------|------------------------|----------------------------------------------------------------------|
| 1.0.0   | Migration_100          | Initial schema (contacts, addresses, job_requests, bookings, login_tokens) |
| 1.1.0   | Migration_110          | Service catalog table                                                |
| 1.2.0   | Migration_120          | Booking-level pricing fields                                         |
| 1.3.0   | Migration_130          | `handik_messages` table for chat transcript persistence; admin override fields on bookings |
| 1.4.0   | Migration_140          | Additional Forms — `direct_booking_requests`, `project_scheduling_requests`, `project_work_days` |
| 1.4.1   | Migration_141          | Project token + selection columns                                    |
| 1.5.0   | Migration_150          | `handik_bookings.job_request_id` becomes NULL-able; add `direct_request_id` for direct form bookings to surface in the unified list |
| 1.5.1   | Migration_151          | `confirmation_email_sent_at` on bookings + direct + project tables for notification idempotency |
| 1.5.2   | Migration_152          | `last_status_emailed` for cancel/reschedule email dedup              |
| 1.6.0   | Migration_160          | `handik_bookings.project_work_day_id` so project rows surface in the unified Bookings list |
| 1.6.1   | Migration_161          | `handik_bookings.external_contact_id` for Cal-only bookings the customer made outside our form |

### Key tables

`handik_contacts` — customer rows. Stripped phone + lower-cased email for match.

`handik_addresses` — N per contact. `is_primary` marks the default. Apple-Maps icon in admin opens this in the native app.

`handik_job_requests` — main-SPA drafts and completed requests. Holds `cal_booking_id` + `cal_booking_url` for the legacy Cal flow. `chat_thread_id` for ChatKit conversation persistence.

`handik_bookings` — **unified bookings list** (since 1.5.0 / 1.6.x). One row per Cal booking. Mutually-exclusive FK columns:
* `job_request_id` → main SPA
* `direct_request_id` → direct form
* `project_work_day_id` → project form
* `external_contact_id` → external Cal-only booking with a matched contact
* all NULL → external booking with no local source (attendee in `raw_webhook_json`)

`handik_direct_booking_requests` — direct form draft + capture metadata.

`handik_project_scheduling_requests` — project form state machine (`draft → selecting_days → days_selected → creating_bookings → confirmed`, with `partial_failed` / `rolled_back` failure exits).

`handik_project_work_days` — N rows per project, each with its own `cal_booking_uid` and slot times.

`handik_messages` — chat transcript (since 1.3.0). One row per user/assistant message. Can be backfilled from OpenAI ChatKit thread storage via the admin "Load chat from OpenAI" button.

`handik_login_tokens` — magic-link one-time-codes.

---

## 10. Background work

* `wp_cron` event `handik_booking_app_dispatch_project_email` → async project-form customer email so the customer's "You're all set" screen doesn't block on wp_mail.
* `wp_cron` event `handik_booking_app_form_gc_abandoned` → daily cleanup of project schedules stuck in `SELECTING` / `DRAFT` for >7 days.
* WordPress's built-in option-based locking for `Handik_Booking_App_Migrations` so parallel boots can't double-run migrations.

---

## 11. Settings + integrations

`Handik_Booking_App_Settings` (with the legacy `wp_options` row) holds:

* OpenAI: `openai_api_key`, `openai_workflow_id`, `openai_api_base`, `openai_project_id`, `openai_organization_id`
* Cal.com: `cal_api_key`, `cal_api_base`, `cal_api_timezone`, `cal_webhook_secret`
* Google Maps: `google_maps_api_key`, `google_maps_country`
* Twilio Verify: `twilio_*`
* Email: `email_from_name`, `email_from_address`, `email_customer_confirmations_enabled`, owner notifications, .ics attachment, brand logo URL
* Plugin updater: `updater_github_*` (repo, token for private releases)
* Operator: `operator_first_name` (default "Alex" — used in placeholders)
* Branded email templates: subject + HTML + text + reply-to for customer-confirmation, owner-notification, cancellation, reschedule

Admin settings UI lives in `Admin_Settings` (tabs). Defaults are sensible enough to ship without configuration; integrations stay off until the operator wires them.

---

## 12. Where to read more

* [README.md](README.md) — quick developer entry point
* [readme.txt](readme.txt) — WordPress-format release notes + changelog
* [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) — release process
* Source — every service class has a multi-paragraph header docblock that explains its responsibilities and design decisions; start with the file you suspect and read the docblock before the methods.
