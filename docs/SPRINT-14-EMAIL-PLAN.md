# Sprint 14 — Branded confirmation emails

**Status:** plan locked. Ready to implement.
**Target release:** v2.1.21.0
**Estimated effort:** 4 days (HTML + .ics + Cal-disable handover stretches the original 2-3 day plain-text estimate).
**Branch convention:** `claude/sprint-14-email-confirmations` (separate from this plan branch).

---

## 1. Executive summary

Plugin starts sending its own branded HTML+plain-text confirmation
email to the customer immediately after a booking is created (any of:
main SPA Cal flow, Additional Forms direct preset, Additional Forms
project work-days preset). Each email carries a generated `.ics`
calendar attachment so the customer keeps the calendar-invite UX they
got from Cal.com. Owner also receives a separate notification ("New
booking from Jane") at a configurable address.

Cal.com's own customer-confirmation email gets disabled (manually, on
the Cal-side event-type settings — see §10) so the customer doesn't
receive duplicates. The plugin becomes the single source of customer
communication for booking confirmations.

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

## 3. Technical architecture

### 3.1 Trigger point

A single new action `do_action( 'handik_booking_confirmed', $context )`
fires from each of three booking-creation sites. Notifications_Service
subscribes once. Owners can hook the action themselves later for Slack
/ SMS / etc. without forking — same extensibility pattern as the
existing `do_action( 'handik_booking_app_send_sms_code', ... )` at
`includes/services/class-auth-service.php:705`.

**Three trigger sites:**

| Site | Where to fire | Idempotency guard |
|---|---|---|
| Main SPA Cal flow | `Bookings_Service::upsert_from_cal()` after `set_cal_booking()` succeeds, only on first transition into a "confirmed" state. | Check `confirmation_email_sent_at` on the `bookings` row. |
| Direct booking | `Direct_Booking_Service::capture_booking()` inside the existing OPENED → BOOKED branch (already idempotent). | Check `confirmation_email_sent_at` on the `direct_booking_requests` row. |
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

### 3.2 Idempotency

New DB column on three tables:

```sql
confirmation_email_sent_at DATETIME NULL DEFAULT NULL
```

Tables:
- `wp_handik_bookings`
- `wp_handik_direct_booking_requests`
- `wp_handik_project_scheduling_requests`

**Atomic check-and-set in Notifications_Service:**

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
- Cal webhook retries (webhook arrives twice for the same booking)
- "Run pending migrations" reprocess accidents
- Project schedule per-day vs per-schedule debate (we lock per-schedule)
- Capture race vs webhook (admin booking flow's known F1 race)

### 3.3 HTML + plain-text via multipart/alternative

```
$headers = array(
    'From: ' . $from_name . ' <' . $from_address . '>',
    'Reply-To: ' . $reply_to,
    'Content-Type: text/html; charset=UTF-8',
);
add_filter( 'wp_mail_content_type', fn() => 'text/html', PHP_INT_MAX );
try {
    $sent = wp_mail( $to, $subject, $html_body, $headers, $attachments );
} finally {
    remove_filter( 'wp_mail_content_type', $closure, PHP_INT_MAX );
}
```

Plain-text alternative is achieved by hooking `phpmailer_init` and
calling `$phpmailer->AltBody = $plain_text_version;` exactly like
WP's own `wp_mail` documentation recommends. We register the hook
inside the send method, fire `wp_mail`, then remove the hook (try/
finally) so we don't leak it to other plugins.

### 3.4 Template engine

Reuse the `{{placeholder}}` substitution loop already in
`Auth_Service::send_message()` (at `class-auth-service.php:687-690`).
Extract it to a shared helper:

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

**Owner-notification extras:**

| Token | Meaning |
|---|---|
| `{{customer_phone}}` | Tel link content |
| `{{customer_email}}` | Mailto link content |
| `{{open_request_admin_link}}` | Direct link to the admin booking detail |
| `{{source_label}}` | "Main SPA" / "Direct booking form" / "Project work-days" |

### 3.5 .ics generation

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
upload_dir → `tmp/` (cleaned by WP cron), pass path to `wp_mail` as
the 5th arg. Add Content-Type filter so the attachment lands as
`text/calendar; method=REQUEST` instead of `application/octet-stream`
(some clients won't render the calendar invite otherwise).

### 3.6 Failure handling

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

**No automatic retry in v1.** Booking already happened; customer can
still see it on the contractor's calendar (Cal still tracks it
internally — we only disabled the email). Owner sees the error on
System info and can resend manually from the admin booking detail.

### 3.7 System info surfacing

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

Displayed on the System info page above the existing migration-error
callout.

### 3.8 Test email button

New admin-post handler `handik_send_test_email`. Notifications tab
gets:

```html
<button type="submit" name="handik_action" value="send_test_email">
    Send test email to me
</button>
```

Builds a fake `$context` with sample data ("Jane Doe", three demo
tasks, fake Cal URL, generated .ics for tomorrow at 2 PM) and sends
to the current admin's email via the same path production uses.
Operator gets a toast back: "Sent — check {admin_email}."

---

## 4. Sprint breakdown

Single sprint, four days, four logical commits:

### Day 1 — Foundations (DB + service skeleton + action wiring)

**New files:**
- `includes/db/migrations/class-migration-142.php` — adds `confirmation_email_sent_at DATETIME NULL` to three tables; bumps `HANDIK_BOOKING_APP_DB_VERSION` to `1.4.2`.
- `includes/services/class-notifications-service.php` — service skeleton: ctor takes settings + logger + bookings + job_requests + contacts + addresses + direct + project; subscribes to `handik_booking_confirmed`; idempotency check; routes to `send_customer_confirmation()` and `send_owner_notification()` stubs.

**Modified files:**
- `handik-booking-app.php` — bump `HANDIK_BOOKING_APP_DB_VERSION` to `1.4.2`.
- `includes/db/class-migrations.php` — register `1.4.2`.
- `includes/class-loader.php` + `includes/class-plugin.php` — DI the new service.
- `includes/services/class-bookings-service.php` — fire action.
- `includes/forms/class-direct-booking-service.php` — fire action.
- `includes/forms/class-project-schedule-service.php` — fire action.

**Commit:** `Sprint 14 day 1 — Notifications_Service skeleton + DB 1.4.2 idempotency column`.

### Day 2 — Templates + admin UI

**New files:**
- `includes/services/class-ics-builder.php` — VCALENDAR / VEVENT generator.

**Modified files:**
- `includes/admin/class-admin-helpers.php` — extract `render_template` helper from `Auth_Service::send_message`.
- `includes/services/class-auth-service.php` — refactor `send_message` to use the shared helper.
- `includes/admin/class-admin-settings.php` — add 8 new fields on `render_notifications_tab`:
    - `customer_confirmations_enabled` (checkbox, default `false`)
    - `customer_confirmation_subject` (text, default copy)
    - `customer_confirmation_body_html` (textarea, default copy)
    - `customer_confirmation_body_text` (textarea, default copy)
    - `customer_confirmation_reply_to` (text, defaults to `email_from_address`)
    - `owner_notification_enabled` (checkbox, default `false`)
    - `owner_notification_address` (text, defaults to `email_from_address`)
    - `owner_notification_subject` (text, default copy)
    - `owner_notification_body` (textarea, default copy — plain text only for owner; sent to ourselves)
    - **"Send test email"** button at the bottom
- `includes/class-settings.php` — add defaults + sanitization rules for the 9 keys.

**Default copy** (English; matches the existing magic-link tone):

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

**Commit:** `Sprint 14 day 2 — settings keys + admin UI + test-email handler`.

### Day 3 — .ics + multipart wire-up

**Modified files:**
- `includes/services/class-notifications-service.php` — implement:
    - `send_customer_confirmation( $context )` — renders HTML + plain-text from settings, builds .ics via `Ics_Builder`, hooks `wp_mail_content_type` + `phpmailer_init`, calls `wp_mail` with attachment, removes hooks.
    - `send_owner_notification( $context )` — plain-text only.
    - Internal `actually_send()` writes `confirmation_email_sent_at` (atomic) before render to claim ownership of the send slot.
    - Failure rollback path (clear `confirmation_email_sent_at` on `wp_mail` returning false).
- `includes/services/class-ics-builder.php` — finish single + multi VEVENT support, line-folding, escaping.
- `includes/admin/class-admin-system.php` — surface `LAST_EMAIL_ERROR_OPTION` callout.

**Commit:** `Sprint 14 day 3 — .ics generator + multipart HTML/text wire + failure surface`.

### Day 4 — Test + ship

- End-to-end test on all 3 booking paths: main SPA Cal, direct preset, project work-days.
- Verify Outlook + Gmail rendering of the HTML template (no broken styling, clickable links).
- Verify .ics imports cleanly into Apple Calendar / Google Calendar / Outlook.
- Verify "Send test email" button works.
- Verify owner-notification toggle off/on independently of customer-confirmation toggle.
- Verify idempotency: trigger the action twice for the same booking — only one email sends.
- Verify failure rollback: temporarily break SMTP, confirm `confirmation_email_sent_at` clears.
- Lint sweep (`php -l`, `node -c`, PHPStan touched files).
- Update `readme.txt` with **prominent** instructions for the Cal-side disable step (§10).
- Bump version to **2.1.21.0**.
- Commit + push.

**Commit:** `2.1.21.0: Branded confirmation emails + .ics — Sprint 14 release`.

---

## 5. Settings keys reference

| Key | Default | Surface |
|---|---|---|
| `customer_confirmations_enabled` | `false` | Notifications tab |
| `customer_confirmation_subject` | `Booking confirmed — {{booking_when_long}}` | Notifications tab |
| `customer_confirmation_body_html` | (default HTML body) | Notifications tab |
| `customer_confirmation_body_text` | (default plain-text body) | Notifications tab |
| `customer_confirmation_reply_to` | empty → `email_from_address` | Notifications tab |
| `owner_notification_enabled` | `false` | Notifications tab |
| `owner_notification_address` | empty → `email_from_address` | Notifications tab |
| `owner_notification_subject` | `New booking — {{customer_name}} on {{booking_when}}` | Notifications tab |
| `owner_notification_body` | (default plain-text body) | Notifications tab |

All keys reuse existing `Settings::update()` allow-list pattern; sanitization via `sanitize_text_field` for subjects + addresses, `wp_kses_post` for HTML body, `sanitize_textarea_field` for plain-text body.

---

## 6. Files inventory

### New files

| Path | Purpose |
|---|---|
| `includes/services/class-notifications-service.php` | Action subscriber + send pipeline |
| `includes/services/class-ics-builder.php` | RFC 5545 minimal-subset VCALENDAR builder |
| `includes/db/migrations/class-migration-142.php` | DB 1.4.2 — adds `confirmation_email_sent_at` column to three tables |

### Modified files

| Path | What changes |
|---|---|
| `handik-booking-app.php` | Bump `HANDIK_BOOKING_APP_DB_VERSION` 1.4.1 → 1.4.2; bump plugin version 2.1.20.1 → 2.1.21.0 |
| `package.json` | Bump version |
| `readme.txt` | Changelog entry; **prominent Cal-side disable instructions** |
| `includes/class-loader.php` | Register new service files |
| `includes/class-plugin.php` | DI new service |
| `includes/db/class-migrations.php` | Register `'1.4.2'` |
| `includes/services/class-bookings-service.php` | Fire `do_action('handik_booking_confirmed', ...)` after `set_cal_booking()` |
| `includes/forms/class-direct-booking-service.php` | Fire action inside OPENED→BOOKED branch of `capture_booking()` |
| `includes/forms/class-project-schedule-service.php` | Fire action once per `confirm_schedule()` |
| `includes/admin/class-admin-helpers.php` | New `render_template($tpl, $vars)` shared helper |
| `includes/services/class-auth-service.php` | Refactor `send_message()` to use shared helper |
| `includes/admin/class-admin-settings.php` | 9 new fields on Notifications tab + Send Test button |
| `includes/class-settings.php` | Defaults + sanitization for 9 new keys |
| `includes/admin/class-admin-system.php` | `LAST_EMAIL_ERROR_OPTION` callout |
| `includes/class-admin.php` | Wire `handik_send_test_email` admin-post handler |

---

## 7. Cal.com disable instructions (release-note copy)

> ### Before enabling our confirmation emails (one-time setup)
>
> The plugin can now send a branded booking-confirmation email with a
> calendar (.ics) attachment, replacing the email Cal.com sends today.
> To avoid customers receiving two confirmation emails (Cal's + ours),
> disable Cal's email **before** flipping our toggle on:
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
> Then in WordPress: **Handik Booking → App Setup → Notifications →
> "Send our own confirmation emails"** (toggle on) → **Save**.
>
> Test with the **"Send test email"** button before booking real
> customers.

---

## 8. Risks + open mitigations

| Risk | Mitigation |
|---|---|
| Owner forgets to disable Cal's email; customers get duplicates | Toggle defaults OFF on upgrade. Release notes lead with the Cal-disable step. Send-test button shows what the customer will get. |
| HTML rendering differs across Outlook / Gmail / Apple Mail | Default template stays simple — single-column, system fonts, no CSS classes (only inline styles), no media queries. Outlook quirks list: avoid `display: flex`, avoid CSS variables, prefer `<table>` for layouts. |
| .ics imports into Google Calendar but the time is wrong | DTSTART/DTEND emitted in floating-time with a TZID block referencing IANA timezone names. Test on Google + Apple + Outlook. |
| Spam-folder placement | RFC 2822 multipart/alternative gives both HTML + plain-text. From-address is on the site's own domain (which the owner already configured for the magic-link email). DKIM/SPF is the owner's responsibility — flagged in readme. |
| Customer emails sent from a no-reply alias get bounced | Reply-To setting routes replies to whatever the owner picks. Default `email_from_address` covers the simplest case; owners with `noreply@` can route Reply-To to `bookings@`. |
| Idempotency column race on simultaneous webhook + capture | The `UPDATE … WHERE id=… AND confirmation_email_sent_at IS NULL` is atomic; whichever path lands first wins. The other fires zero affected_rows and bails. |
| Existing installs upgrade through 2.1.21.0 with toggles OFF and never enable | Acceptable — no behavioural change. Cal still sends emails. The feature is opt-in. |
| Plain-text fallback rendering breaks on long lines | RFC 5322 says lines should be ≤998 octets; we wrap at 78 with `wordwrap()`. The .ics builder folds at 75 per RFC 5545. |
| `wp_mail` filter stack interferes (other plugins) | All filter hooks (content-type, phpmailer_init) wrap in try/finally so we always remove them after our `wp_mail` returns. |

---

## 9. Out of scope (deferred to v2)

- **Cancellation notice** — when admin marks booking cancelled.
- **Reschedule notice** — when Cal-webhook reports time change. (Cal still sends its own reschedule email by default; consider parity later.)
- **N-hour reminders** — would need a cron event scheduling the email at booking-time.
- **Per-form-type subject / body overrides** — one template per source for v1, owner can edit globally.
- **Email preview in admin** — Send-test covers the use case at lower cost than an inline rendered preview.
- **Owner-side digest / daily roll-up** — every booking immediately notifies in v1; a digest mode is bigger scope.
- **SMS confirmations** — outside the scope of this feature.

---

## 10. Open follow-ups (after Sprint 14 ships)

1. **Document SPF / DKIM** in the readme for the email_from domain so deliverability is the owner's first-class concern.
2. **Add a "Cancellation email" toggle** in the same Notifications tab (deferred, see §9).
3. **Consider Action Scheduler** if the v2 reminder feature lands — WP cron is unreliable on low-traffic sites.

---

## 11. Effort + sequencing

- **Sprint 14:** 4 working days, single branch, 4 commits.
- **Pre-sprint check:** confirm Cal.com offers a per-event-type "disable confirmation email" without an enterprise tier. (Spot-checked; their workflow system supports it on free tier.)
- **Post-sprint check (1 day, separate task):** send a real booking through main SPA + direct + project, verify both customer + owner emails land, .ics imports cleanly. Document any deliverability surprises.

Total path-to-production from green-light: **4 days dev + 1 day live-test = 1 week**.
