# Sprint 14 — Branded confirmation emails

**Status:** plan locked. Ready to implement.
**Base version:** 2.1.20.2 / DB 1.5.0 (current `main`).
**Target releases:** v2.1.21.0 (Sprint 14a, customer side) → live verification → v2.1.21.1 (Sprint 14b, owner side + ops polish).
**Estimated effort:** ~1 working week — 2-3 days dev (14a) + 1-2 days verification + 1-2 days dev (14b).
**Branch convention:** `claude/sprint-14a-email-customer` for the first release; `claude/sprint-14b-email-owner` for the second.

---

## 1. Executive summary

Plugin starts sending its own branded HTML+plain-text confirmation
email to the customer immediately after a booking is created (any of:
main SPA Cal flow, Additional Forms direct preset, Additional Forms
project work-days preset). Each email carries a generated `.ics`
calendar attachment so the customer keeps the calendar-invite UX
they got from Cal.com.

The owner also receives a separate notification ("New booking from
Jane") at a configurable address — but that's split off into
**Sprint 14b** so we can ship the customer-facing piece first,
verify deliverability + Cal-disable handover live, then layer the
owner side on top.

Cal.com's own customer-confirmation email gets disabled (manually,
on the Cal-side event-type settings — see §10) so the customer
doesn't receive duplicates. The plugin becomes the single source of
customer communication for booking confirmations.

A master toggle defaults to **OFF** on upgrade so the existing flow
(Cal sends, we don't) keeps working until the owner has flipped both
sides — turn off Cal's email AND turn on ours — at the same time.

---

## 2. Locked-in decisions (owner answers)

| # | Question | Decision |
|---|---|---|
| 1 | Cal's confirmation email | **Disabled** (manually in Cal admin). Our email is the only one; we ship a `.ics` attachment so the customer still gets a calendar invite. |
| 2 | Project schedule emails | **One email** listing all confirmed days, with one `.ics` attachment containing one `VEVENT` per day. |
| 3 | Owner-notification recipient | **Separate setting** (`owner_notification_address`). Defaults to `email_from_address` so a fresh install works without configuration. |
| 4 | Reply-To target | **Admin choice** (`customer_confirmation_reply_to` text input on Notifications tab). Defaults to `email_from_address`. |
| 5 | Master toggle default on upgrade | **OFF.** Owner manually disables Cal's email + enables ours together. Release notes spell out the order. |
| 6 | HTML in v1 vs v2 | **HTML in v1** with plain-text alternative (RFC 2822 multipart/alternative). Without Cal's email the customer sees ours as their only confirmation — it has to look professional. |
| 7 | Photos in email | **Not included.** Avoids Gmail 25 MB attachment cap and keeps templates simple. |

---

## 3. Sprint structure

Two sprints with a verification gate between them. Total wall time
~1 working week including the live test.

```
Sprint 14a (2-3 days)    →    Live verification (1-2 days)    →    Sprint 14b (1-2 days)
v2.1.21.0                      Real bookings on staging /              v2.1.21.1
Customer email + .ics          one quiet day in production             Owner notification + ops
```

### Sprint 14a — Customer-facing email + .ics (v2.1.21.0)

**Scope:**
- Migration 1.5.1 (idempotency column).
- `Notifications_Service` skeleton subscribing to a new
  `do_action('handik_booking_confirmed', $context)` action.
- Action wired from all three booking-creation sites
  (main SPA Cal, direct, project).
- Idempotent check-and-set on `confirmation_email_sent_at`.
- HTML + plain-text customer confirmation via
  multipart/alternative.
- `.ics` attachment via new `Ics_Builder` (single VEVENT for
  Cal/direct, multi-VEVENT for project).
- Master toggle (`customer_confirmations_enabled`, default OFF).
- Customer settings keys: subject / HTML body / text body /
  Reply-To.
- Cal-disable instructions in the readme entry.
- "Send test email" button on the Notifications tab so the owner
  can preview with sample data without booking a real visit.

**Out of 14a (deferred to 14b):**
- Owner notification ("new booking from Jane") email.
- `LAST_EMAIL_ERROR_OPTION` callout on the System info page.
- Per-toggle owner UI fields.

**Deliverable:** v2.1.21.0 on `main`. Customer receives our
branded email + .ics; owner does NOT yet get their own
notification.

### Verification gate (between 14a and 14b)

Owner runs through this checklist on a staging install (or one
quiet day in production) before approving 14b:

- [ ] Migration 1.5.1 ran without errors (System info → DB
      schema version shows 1.5.1).
- [ ] Cal-side disable performed: opened each event type → Workflows
      → removed/changed default `New Event Booking` → Save.
- [ ] "Send test email" button delivers to admin's inbox.
- [ ] HTML renders correctly in: Gmail web, Apple Mail, Outlook
      (desktop or web — at least one).
- [ ] `.ics` attachment imports cleanly into Apple Calendar AND
      Google Calendar (right time, right title, right location).
- [ ] Plain-text fallback shows correctly on a mail client that
      blocks HTML (or in the "Show original" view of Gmail).
- [ ] Real test booking through main SPA → customer email arrives,
      no Cal.com email arrives (because we disabled it).
- [ ] Real test booking through public direct preset → same.
- [ ] Real test booking through admin "+ Add booking" → same.
- [ ] Idempotency: Cal webhook retry doesn't fire a second email
      (check `confirmation_email_sent_at` stays at first send).
- [ ] `wp_mail` failure case: temporarily break SMTP (wrong host),
      confirm graceful degradation — booking still records, error
      goes to PHP error log, `confirmation_email_sent_at` rolls
      back so a manual retry can re-fire.

**If verification fails:** patch as 2.1.21.0.x and re-test before
moving to 14b.

### Sprint 14b — Owner notification + ops polish (v2.1.21.1)

**Scope:**
- Owner notification toggle (`owner_notification_enabled`, default
  OFF).
- Owner notification address picker (`owner_notification_address`,
  default = `email_from_address`).
- Owner notification subject / body settings.
- Plain-text-only owner email (we're emailing ourselves; HTML is
  overkill). Includes phone tel: link, mailto: link, and direct
  link to the admin booking-detail page.
- `LAST_EMAIL_ERROR_OPTION` surface on System info (mirroring
  Sprint 7's `LAST_ERROR_OPTION` migration callout).
- Owner-notification path through the same idempotency column —
  one combined email-sent timestamp per booking.

**Deliverable:** v2.1.21.1 on `main`.

---

## 4. Technical architecture

### 4.1 Trigger point

A single new action `do_action( 'handik_booking_confirmed',
$context )` fires from each of three booking-creation sites.
`Notifications_Service` subscribes once. Owners can hook the
action themselves later for Slack / SMS / etc. without forking
— same extensibility pattern as the existing
`do_action( 'handik_booking_app_send_sms_code', ... )` at
`includes/services/class-auth-service.php:705`.

**Three trigger sites:**

| Site | Where to fire | Idempotency guard |
|---|---|---|
| Main SPA Cal flow | `Bookings_Service::upsert_from_cal()` after `set_cal_booking()` succeeds, only on first transition into a "confirmed" state. | Check `confirmation_email_sent_at` on the `bookings` row. |
| Direct booking | `Direct_Booking_Service::capture_booking()` inside the existing OPENED → BOOKED branch (already idempotent) AND the parallel mirror path that Sprint 13.5 added (`Bookings_Service::upsert_from_direct_capture` reached from both `capture_booking` and `Webhook_Service::dispatch_direct`). Fire ONCE per real booking — easy because the action is downstream of the idempotency UPDATE. | Check `confirmation_email_sent_at` on the `direct_booking_requests` row (single source of truth — `bookings` mirror is downstream). |
| Project work-days | `Project_Schedule_Service::confirm_schedule()` after all days are persisted; one action per **schedule** (not per day). | Check `confirmation_email_sent_at` on the `project_scheduling_requests` row. |

**Context shape:**

```php
array(
    'source'        => 'cal' | 'direct' | 'project',
    'contact'       => array( 'id', 'full_name', 'phone', 'email' ),
    'address'       => array( 'address_full', 'address_unit' ) | null,
    'tasks'         => array( ['label' => 'Plumbing', 'rate_label' => '$X/hr'], ... ) | array,
    'when'          => array( 'start_iso', 'end_iso', 'timezone' )
                       | array( 'days' => array( ['start_iso','end_iso','day_index'], ... ) ), // project
    'booking_url'   => string, // Cal-side reschedule/cancel link
    'restart_url'   => string, // public booking page for "book again"
    'request_id'    => int,
    'booking_id'    => int | null, // null for direct/project until webhook reconciles
    'cal_booking_uid' => string,
)
```

### 4.2 Idempotency

New DB column on three tables (Migration 1.5.1, extends Sprint
13.5's 1.5.0):

```sql
confirmation_email_sent_at DATETIME NULL DEFAULT NULL
```

Tables:
- `wp_handik_bookings`
- `wp_handik_direct_booking_requests`
- `wp_handik_project_scheduling_requests`

**Atomic check-and-set in `Notifications_Service`:**

```php
$updated = $wpdb->query( $wpdb->prepare(
    "UPDATE {$table} SET confirmation_email_sent_at = UTC_TIMESTAMP()
     WHERE id = %d AND confirmation_email_sent_at IS NULL",
    $row_id
) );
if ( 0 === (int) $updated ) {
    return; // somebody else already sent it
}
$this->actually_send( $context );
```

This handles:
- Cal webhook retries (webhook arrives twice for the same booking).
- "Run pending migrations" reprocess accidents.
- Project schedule per-day vs per-schedule debate (we lock per-
  schedule).
- Sprint 13.5's new dual capture + webhook upsert path on the
  direct flow — both paths fire the action; whichever runs first
  wins, the other gets zero affected_rows.

### 4.3 HTML + plain-text via multipart/alternative

```php
$headers = array(
    'From: ' . $from_name . ' <' . $from_address . '>',
    'Reply-To: ' . $reply_to,
    'Content-Type: text/html; charset=UTF-8',
);
add_filter( 'wp_mail_content_type', $html_content_type, PHP_INT_MAX );
add_action( 'phpmailer_init', $altbody_setter, PHP_INT_MAX );
try {
    $sent = wp_mail( $to, $subject, $html_body, $headers, $attachments );
} finally {
    remove_filter( 'wp_mail_content_type', $html_content_type, PHP_INT_MAX );
    remove_action( 'phpmailer_init', $altbody_setter, PHP_INT_MAX );
}
```

Plain-text alternative is achieved by hooking `phpmailer_init`
and calling `$phpmailer->AltBody = $plain_text_version;` exactly
like WP's own `wp_mail` documentation recommends. Hooks register
inside the send method, fire `wp_mail`, then are removed
(try/finally) so they don't leak to other plugins.

### 4.4 Template engine

Reuse the `{{placeholder}}` substitution loop already in
`Auth_Service::send_message()` (at
`class-auth-service.php:687-690`). Extract it to a shared helper:

```php
Handik_Booking_App_Admin_Helpers::render_template( $tpl, $vars )
```

**Placeholders v1:**

| Token | Meaning |
|---|---|
| `{{customer_name}}` | Contact's full name |
| `{{booking_when}}` | Formatted in site timezone |
| `{{booking_when_long}}` | "Monday, January 15, 2026 at 2:00 PM ET" |
| `{{address}}` | Single-line address, optional unit |
| `{{tasks_list_html}}` | `<ul><li>Plumbing</li>…</ul>` |
| `{{tasks_list_text}}` | `- Plumbing\n- Electrical\n…` |
| `{{cal_url}}` | Cal-side reschedule/cancel link |
| `{{restart_url}}` | Public booking page |
| `{{site_name}}` | `get_bloginfo('name')` |
| `{{from_name}}` | Owner-configured `email_from_name` |
| `{{site_url}}` | `home_url()` |
| `{{operator_first_name}}` | From settings (defaults "Alex") — for sign-off line |

**Project schedule extras:**

| Token | Meaning |
|---|---|
| `{{days_list_html}}` | `<ol><li>Mon Jan 15 · 9 AM – 5 PM</li>…</ol>` |
| `{{days_list_text}}` | Plain-text equivalent |
| `{{days_count}}` | `3` |

**Owner-notification extras** (Sprint 14b only):

| Token | Meaning |
|---|---|
| `{{customer_phone}}` | Tel link content |
| `{{customer_email}}` | Mailto link content |
| `{{open_request_admin_link}}` | Direct link to the admin booking detail |
| `{{source_label}}` | "Main SPA" / "Direct booking form" / "Project work-days" |

### 4.5 .ics generation

New helper class `Handik_Booking_App_Ics_Builder`:

```php
class Handik_Booking_App_Ics_Builder {
    public static function build_single( array $event ) : string;   // returns .ics string
    public static function build_multi( array $events ) : string;   // for project schedules
    protected static function format_dtstamp( string $iso ) : string;
    protected static function fold_line( string $line ) : string;   // 75-char fold
    protected static function escape_text( string $s ) : string;    // commas, semicolons
}
```

**Event shape:**

```php
array(
    'uid'           => 'handik-booking-{request_id}-{day_index}@{site_host}',
    'summary'       => 'Handik visit · 2 hours',
    'description'   => 'Tasks: …\nAddress: …\nReschedule: {cal_url}',
    'location'      => '123 Main St, Cambridge MA 02139',
    'dtstart_iso'   => '2026-01-15T14:00:00-05:00',
    'dtend_iso'     => '2026-01-15T16:00:00-05:00',
    'organizer_name'  => 'Handik',
    'organizer_email' => 'bookings@handik.pro',
    'attendee_name'   => 'Jane Smith',
    'attendee_email'  => 'jane@example.com',
)
```

**Output format** (RFC 5545 minimal subset, line-folded at 75 chars):

```
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Handik Booking App//EN
METHOD:REQUEST
BEGIN:VEVENT
UID:handik-booking-1234@handik.pro
DTSTAMP:20260108T200000Z
DTSTART:20260115T140000-0500
DTEND:20260115T160000-0500
SUMMARY:Handik visit · Plumbing
DESCRIPTION:Tasks: Plumbing\\nAddress: 123 Main St\\n…
LOCATION:123 Main St\, Cambridge MA 02139
ORGANIZER;CN=Handik:mailto:bookings@handik.pro
ATTENDEE;CN=Jane Smith;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:jane@example.com
END:VEVENT
END:VCALENDAR
```

For project schedules: multiple `BEGIN:VEVENT…END:VEVENT` blocks
inside one `VCALENDAR`. Each gets a unique UID.

**Attachment plumbing:** write the .ics to a temp file via WP's
upload_dir → `tmp/` (cleaned by WP cron), pass path to `wp_mail`
as the 5th arg. Add Content-Type filter so the attachment lands
as `text/calendar; method=REQUEST` instead of
`application/octet-stream` (some clients won't render the
calendar invite otherwise).

### 4.6 Failure handling

```php
if ( false === $sent ) {
    update_option( 'handik_booking_app_last_email_error', array(
        'time'    => time(),
        'message' => 'wp_mail returned false',
        'context' => array( 'request_id' => …, 'source' => …, 'to' => … ),
    ), false );
    if ( $logger ) {
        $logger->error( 'Notifications: wp_mail failed.', $context );
    }
    // Roll back the idempotency stamp so a manual retry can re-fire.
    $wpdb->update( $table,
        array( 'confirmation_email_sent_at' => null ),
        array( 'id' => $row_id )
    );
}
```

**No automatic retry in v1.** Booking already happened; customer
can still see it on the contractor's calendar (Cal still tracks it
internally — we only disabled the email). Owner sees the error on
System info (Sprint 14b ships the surface) and can resend manually
from the admin booking detail.

### 4.7 System info surfacing (Sprint 14b)

Mirror Sprint 7's `LAST_ERROR_OPTION` pattern:

```php
$last_email_error = get_option( 'handik_booking_app_last_email_error', null );
if ( $last_email_error ) {
    echo '<div class="handik-admin-callout handik-admin-callout--error">';
    echo '<strong>Last email error:</strong> ' . esc_html( $last_email_error['message'] );
    echo '<br><small>' . esc_html( wp_date( 'Y-m-d H:i', $last_email_error['time'] ) ) . '</small>';
    echo '</div>';
}
```

Displayed on the System info page above the existing migration-
error callout.

### 4.8 Test email button (Sprint 14a)

New admin-post handler `handik_send_test_email`. Notifications tab
gets:

```html
<button type="submit" name="handik_action" value="send_test_email">
    Send test email to me
</button>
```

Builds a fake `$context` with sample data ("Jane Doe", three demo
tasks, fake Cal URL, generated .ics for tomorrow at 2 PM) and
sends to the current admin's email via the same path production
uses. Operator gets a toast back: "Sent — check {admin_email}."

---

## 5. Sprint 14a — file-by-file (v2.1.21.0)

### New files

| Path | Purpose |
|---|---|
| `includes/services/class-notifications-service.php` | Action subscriber + send pipeline (customer path only in 14a). |
| `includes/services/class-ics-builder.php` | RFC 5545 minimal-subset VCALENDAR builder. |
| `includes/db/migrations/class-migration-151.php` | DB 1.5.1 — adds `confirmation_email_sent_at` column to three tables. Extends Sprint 13.5's 1.5.0 migration. |

### Modified files

| Path | What changes |
|---|---|
| `handik-booking-app.php` | `HANDIK_BOOKING_APP_DB_VERSION` 1.5.0 → 1.5.1; plugin version 2.1.20.2 → 2.1.21.0 |
| `package.json` | Bump version |
| `readme.txt` | Changelog entry; **prominent Cal-side disable instructions** |
| `includes/class-loader.php` | Register new service files + new migration class |
| `includes/class-plugin.php` | DI new service |
| `includes/db/class-migrations.php` | Register `'1.5.1'` |
| `includes/services/class-bookings-service.php` | Fire `do_action('handik_booking_confirmed', ...)` after `set_cal_booking()` succeeds |
| `includes/forms/class-direct-booking-service.php` | Fire action inside OPENED→BOOKED branch of `capture_booking()` |
| `includes/forms/class-project-schedule-service.php` | Fire action once per `confirm_schedule()` |
| `includes/admin/class-admin-helpers.php` | New `render_template($tpl, $vars)` shared helper |
| `includes/services/class-auth-service.php` | Refactor `send_message()` to use shared helper |
| `includes/admin/class-admin-settings.php` | Customer email fields on Notifications tab + Send Test button |
| `includes/class-settings.php` | Defaults + sanitization for customer keys |
| `includes/class-admin.php` | Wire `handik_send_test_email` admin-post handler |

---

## 6. Sprint 14b — file-by-file (v2.1.21.1)

### Modified files only (no new files in 14b — extends the 14a service)

| Path | What changes |
|---|---|
| `handik-booking-app.php` | Plugin version 2.1.21.0 → 2.1.21.1 |
| `package.json` | Bump version |
| `readme.txt` | Changelog entry |
| `includes/services/class-notifications-service.php` | Implement `send_owner_notification()` path; subscribe owner branch to the same `handik_booking_confirmed` action |
| `includes/admin/class-admin-settings.php` | Add owner email fields on Notifications tab |
| `includes/class-settings.php` | Defaults + sanitization for owner keys |
| `includes/admin/class-admin-system.php` | `LAST_EMAIL_ERROR_OPTION` callout |

---

## 7. Settings keys reference

### Customer keys (Sprint 14a)

| Key | Default | Surface |
|---|---|---|
| `customer_confirmations_enabled` | `false` | Notifications tab |
| `customer_confirmation_subject` | `Booking confirmed — {{booking_when_long}}` | Notifications tab |
| `customer_confirmation_body_html` | (default HTML body) | Notifications tab |
| `customer_confirmation_body_text` | (default plain-text body) | Notifications tab |
| `customer_confirmation_reply_to` | empty → `email_from_address` | Notifications tab |

### Owner keys (Sprint 14b)

| Key | Default | Surface |
|---|---|---|
| `owner_notification_enabled` | `false` | Notifications tab |
| `owner_notification_address` | empty → `email_from_address` | Notifications tab |
| `owner_notification_subject` | `New booking — {{customer_name}} on {{booking_when}}` | Notifications tab |
| `owner_notification_body` | (default plain-text body) | Notifications tab |

All keys reuse existing `Settings::update()` allow-list pattern;
sanitization via `sanitize_text_field` for subjects + addresses,
`wp_kses_post` for HTML body, `sanitize_textarea_field` for plain-
text body.

---

## 8. Default copy

> **Customer subject:** `Booking confirmed — {{booking_when_long}}`
>
> **Customer body (HTML), excerpt:**
> ```html
> <p>Hi {{customer_name}},</p>
> <p>Your visit is confirmed for <strong>{{booking_when_long}}</strong>.</p>
> <p><strong>Where:</strong> {{address}}</p>
> <p><strong>What we'll be doing:</strong></p>
> {{tasks_list_html}}
> <p>Calendar invite attached. If you need to reschedule or cancel, use the link Cal.com sent you, or just reply to this email — {{operator_first_name}} reads them.</p>
> <p>— {{from_name}}</p>
> ```
>
> **Owner subject:** `New booking — {{customer_name}} on {{booking_when}}`
>
> **Owner body:**
> ```
> {{customer_name}} just booked.
>
> When:    {{booking_when_long}}
> Phone:   {{customer_phone}}
> Email:   {{customer_email}}
> Address: {{address}}
> Tasks:   {{tasks_list_text}}
> Source:  {{source_label}}
>
> Open in admin: {{open_request_admin_link}}
> ```

---

## 9. Day-by-day breakdown

### Sprint 14a

**Day 1 — Foundations**

- Write Migration 1.5.1.
- Bump `HANDIK_BOOKING_APP_DB_VERSION` to `'1.5.1'`.
- Create `Notifications_Service` skeleton with constructor +
  `register_hooks` + idempotency check stub.
- Wire `do_action('handik_booking_confirmed', ...)` in three sites
  with the right `$context`.
- Commit: `Sprint 14a day 1 — Notifications_Service skeleton + DB 1.5.1 idempotency column`.

**Day 2 — Templates + .ics**

- Extract `render_template` helper from `Auth_Service::send_message`;
  refactor that method to use it.
- Implement `Ics_Builder` (single + multi VEVENT, line-folding, escape).
- Implement `Notifications_Service::send_customer_confirmation()` —
  multipart/alternative, .ics attachment, hook lifecycle.
- Default HTML + plain-text bodies as constants on
  `Notifications_Service`.
- Commit: `Sprint 14a day 2 — render_template helper + Ics_Builder + customer send path`.

**Day 3 — Admin UI + ship**

- Add 5 customer settings keys + Send Test button on Notifications tab.
- Wire `handik_send_test_email` admin-post handler.
- Update readme with prominent Cal-disable instructions (§10).
- Bump version to **2.1.21.0**.
- Lint sweep (`php -l`, `node -c`, PHPStan touched files).
- Commit + push.

**Final 14a commit:** `2.1.21.0: Branded customer confirmation email + .ics — Sprint 14a release`.

### Verification gate (1-2 days)

Owner runs the §3 checklist. If anything fails, patch as
2.1.21.0.x on a `claude/sprint-14a-fix-N` branch and re-test.

### Sprint 14b

**Day 1 — Owner notification path**

- Add 4 owner settings keys + Notifications tab fields.
- Implement `Notifications_Service::send_owner_notification()` —
  plain-text only, same `{{placeholder}}` pipeline.
- Subscribe owner-side to the existing `handik_booking_confirmed`
  action; share the idempotency column with the customer side
  (one timestamp covers both — if either email fails, both retry).

**Day 2 — Ops polish + ship**

- `LAST_EMAIL_ERROR_OPTION` surface on System info (above the
  migration-error callout).
- Ship.
- Bump version to **2.1.21.1**.
- Lint sweep.
- Commit + push.

**Final 14b commit:** `2.1.21.1: Owner notification email + email error surface — Sprint 14b release`.

---

## 10. Cal.com disable instructions (release-note copy for 14a)

> ### Before enabling our confirmation emails (one-time setup)
>
> The plugin can now send a branded booking-confirmation email
> with a calendar (.ics) attachment, replacing the email Cal.com
> sends today. To avoid customers receiving two confirmation
> emails (Cal's + ours), disable Cal's email **before** flipping
> our toggle on:
>
> 1. Open your Cal.com dashboard → **Event Types** → click each
>    booking type used by Handik.
> 2. Go to the **Workflows** tab.
> 3. Find the default `New Event Booking` workflow.
> 4. Either delete it, or change its action from `Send Email` to
>    something else.
> 5. Save.
> 6. Repeat for every event type that Handik routes to.
>
> Then in WordPress: **Handik Booking → App Setup → Notifications
> → "Send our own confirmation emails"** (toggle on) → **Save**.
>
> Test with the **"Send test email"** button before booking real
> customers.

---

## 11. Risks + mitigations

| Risk | Mitigation |
|---|---|
| Owner forgets to disable Cal's email; customers get duplicates | Toggle defaults OFF on upgrade. Release notes lead with the Cal-disable step. Send-test button shows what the customer will get. The 14a → verification → 14b split gives the owner a hard pause to confirm Cal-side disable BEFORE adding owner-notification noise on top. |
| HTML rendering differs across Outlook / Gmail / Apple Mail | Default template stays simple — single-column, system fonts, no CSS classes (only inline styles), no media queries. Outlook quirks list: avoid `display: flex`, avoid CSS variables, prefer `<table>` for layouts. |
| .ics imports into Google Calendar but the time is wrong | DTSTART/DTEND emitted in floating-time with a TZID block referencing IANA timezone names. Test on Google + Apple + Outlook in the verification gate. |
| Spam-folder placement | RFC 2822 multipart/alternative gives both HTML + plain-text. From-address is on the site's own domain (which the owner already configured for the magic-link email). DKIM/SPF is the owner's responsibility — flagged in readme. |
| Customer emails sent from a no-reply alias get bounced | Reply-To setting routes replies to whatever the owner picks. Default `email_from_address` covers the simplest case; owners with `noreply@` can route Reply-To to `bookings@`. |
| Idempotency column race on simultaneous webhook + capture | The `UPDATE … WHERE id=… AND confirmation_email_sent_at IS NULL` is atomic; whichever path lands first wins. The other fires zero affected_rows and bails. Verified compatible with Sprint 13.5's dual-path mirror into `handik_bookings`. |
| Existing installs upgrade through 2.1.21.0 with toggles OFF and never enable | Acceptable — no behavioural change. Cal still sends emails. The feature is opt-in. |
| Plain-text fallback rendering breaks on long lines | RFC 5322 says lines should be ≤998 octets; we wrap at 78 with `wordwrap()`. The .ics builder folds at 75 per RFC 5545. |
| `wp_mail` filter stack interferes (other plugins) | All filter hooks (content-type, phpmailer_init) wrap in try/finally so we always remove them after our `wp_mail` returns. |

---

## 12. Out of scope (deferred to v2)

- **Cancellation notice** — when admin marks booking cancelled.
- **Reschedule notice** — when Cal-webhook reports time change.
  (Cal still sends its own reschedule email by default; consider
  parity later.)
- **N-hour reminders** — would need a cron event scheduling the
  email at booking-time.
- **Per-form-type subject / body overrides** — one template per
  source for v1, owner can edit globally.
- **Email preview in admin** — Send-test covers the use case at
  lower cost than an inline rendered preview.
- **Owner-side digest / daily roll-up** — every booking
  immediately notifies in v1; a digest mode is bigger scope.
- **SMS confirmations** — outside the scope of this feature.

---

## 13. Open follow-ups (after Sprint 14b ships)

1. **Document SPF / DKIM** in the readme for the email_from
   domain so deliverability is the owner's first-class concern.
2. **Add a "Cancellation email" toggle** in the same Notifications
   tab (deferred, see §12).
3. **Consider Action Scheduler** if the v2 reminder feature lands
   — WP cron is unreliable on low-traffic sites.
4. **Add an admin "Resend confirmation"** button on the booking
   detail page so the operator can fix typos / address changes
   without manual SMTP work.

---

## 14. Effort + sequencing summary

```
                                    ←── Sprint 14a ──→ ←── verify ──→ ←── 14b ──→
                                    Day 1   Day 2   Day 3   Day 4-5    Day 6   Day 7
Migration 1.5.1                     ●
Notifications_Service skeleton      ●
Action wiring (3 sites)             ●
HTML + plain-text customer email           ●
.ics builder                                ●
Customer settings UI                                ●
Send Test button                                    ●
Ship 2.1.21.0                                        ●
Live test (real bookings)                                       ●●
Owner notification path                                                   ●
LAST_EMAIL_ERROR_OPTION surface                                                 ●
Ship 2.1.21.1                                                                   ●
```

**Total path-to-production: ~1 working week** (5 dev days +
verification gate + ship gates).

---

## 15. Relevant files referenced

- `/home/user/handik-booking-app/handik-booking-app.php` (version + DB version constants)
- `/home/user/handik-booking-app/includes/services/class-bookings-service.php` (Cal upsert site + Sprint 13.5's `upsert_from_direct_capture`)
- `/home/user/handik-booking-app/includes/forms/class-direct-booking-service.php` (`capture_booking` site)
- `/home/user/handik-booking-app/includes/forms/class-project-schedule-service.php` (`confirm_schedule`)
- `/home/user/handik-booking-app/includes/services/class-auth-service.php:651-706` (`send_message` — only existing `wp_mail` + template pattern to copy)
- `/home/user/handik-booking-app/includes/admin/class-admin-settings.php` (`render_notifications_tab`)
- `/home/user/handik-booking-app/includes/db/migrations/class-migration-150.php` (Sprint 13.5 migration — pattern to follow for 1.5.1)
- `/home/user/handik-booking-app/includes/services/class-webhook-service.php` (Cal upsert + dispatch_direct paths)
